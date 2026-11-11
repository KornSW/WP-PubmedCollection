<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection;

use RuntimeException;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Minimal client for the official NCBI E-utilities API (https://www.ncbi.nlm.nih.gov/books/NBK25497/).
 * The PubMed web pages are protected by a bot check and must not be scraped; the API is the supported way.
 */
final class EutilsClient {

  public const HOST = 'eutils.ncbi.nlm.nih.gov';

  private const BASE_URL = 'https://' . self::HOST . '/entrez/eutils/';

  private const TIMEOUT_SECONDS = 30;

  /**
   * Start size of an esummary chunk. Several consortium papers (thousands of authors) in one chunk can exceed
   * the ~20 s limit of the NCBI gateway (HTTP 500 "error forwarding request"); such chunks are split adaptively.
   */
  private const SUMMARY_CHUNK_SIZE = 10;

  /** Upper bound of transient failures per refresh, so a broken API does not cause hundreds of split requests. */
  private const MAX_TRANSIENT_FAILURES = 12;

  private const MAX_LISTED_AUTHORS = 20;

  private Settings $settings;

  private int $transientFailureCount = 0;

  /** @var array<string, string> PMID => error message of the last fetchSummaries() call */
  private array $skippedPmids = [];

  /** @var array{totalCount: int, translation: string, warnings: string[]} */
  private array $lastSearchInfo = ['totalCount' => 0, 'translation' => '', 'warnings' => []];

  public function __construct(Settings $settings) {
    $this->settings = $settings;
  }

  /**
   * How PubMed interpreted the filter of the last searchPmids() call (to debug filters that do not work as intended).
   *
   * @return array{totalCount: int, translation: string, warnings: string[]}
   */
  public function getLastSearchInfo(): array {
    return $this->lastSearchInfo;
  }

  /**
   * Runs a PubMed search and returns the matching PMIDs, newest publications first.
   *
   * @param array<string, string> $options further esearch parameters: 'retstart' (paging),
   *   'datetype' + 'mindate' + 'maxdate' (e.g. 'edat' = date the record was added to PubMed)
   * @return string[]
   * @throws RuntimeException if the API is not reachable or answers with an error.
   */
  public function searchPmids(string $term, int $maxResults, array $options = []): array {
    $parameters = [
      'db' => 'pubmed',
      'term' => $term,
      'retmax' => (string) $maxResults,
      'sort' => 'pub_date'
    ];
    foreach (['retstart', 'datetype', 'mindate', 'maxdate'] as $optionName) {
      if (isset($options[$optionName]) && $options[$optionName] !== '') {
        $parameters[$optionName] = (string) $options[$optionName];
      }
    }
    $response = $this->requestJson('esearch.fcgi', $parameters);

    if (isset($response['error'])) {
      throw new RuntimeException('esearch: ' . (string) $response['error']);
    }
    if (!isset($response['esearchresult']) || !is_array($response['esearchresult'])) {
      throw new RuntimeException('esearch: Antwort enthält kein "esearchresult".');
    }
    $searchResult = $response['esearchresult'];
    if (isset($searchResult['ERROR'])) {
      throw new RuntimeException('esearch: ' . (string) $searchResult['ERROR']);
    }
    if (!isset($searchResult['idlist']) || !is_array($searchResult['idlist'])) {
      throw new RuntimeException('esearch: Antwort enthält keine "idlist".');
    }

    $this->lastSearchInfo = [
      'totalCount' => (int) ($searchResult['count'] ?? 0),
      'translation' => (string) ($searchResult['querytranslation'] ?? ''),
      'warnings' => $this->collectSearchWarnings($searchResult)
    ];

    return array_map('strval', $searchResult['idlist']);
  }

  /**
   * PubMed silently ignores or reinterprets parts of a filter; it reports that in warninglist / errorlist.
   *
   * @param array<string, mixed> $searchResult
   * @return string[]
   */
  private function collectSearchWarnings(array $searchResult): array {
    $labels = [
      'phrasesignored' => 'Ignoriert',
      'quotedphrasesnotfound' => 'Phrase nicht gefunden',
      'outputmessages' => 'Hinweis',
      'phrasesnotfound' => 'Nicht gefunden',
      'fieldsnotfound' => 'Unbekanntes Feld'
    ];
    $warnings = [];
    foreach (['warninglist', 'errorlist'] as $listName) {
      if (!isset($searchResult[$listName]) || !is_array($searchResult[$listName])) {
        continue;
      }
      foreach ($searchResult[$listName] as $kind => $values) {
        if (!is_array($values) || count($values) === 0) {
          continue;
        }
        $label = (string) $kind;
        if (isset($labels[$kind])) {
          $label = $labels[$kind];
        }
        $warnings[] = $label . ': ' . implode(', ', array_map('strval', $values));
      }
    }
    return $warnings;
  }

  /**
   * Loads the summaries (title, journal, date, authors) of the given PMIDs in small chunks: consortium papers
   * carry thousands of authors (up to ~300 KB each), so large requests run into NCBI timeouts / HTTP 500.
   * A chunk that fails transiently is split in halves down to single PMIDs; a single PMID that still fails
   * is skipped (see getSkippedPmids()) instead of failing the whole list.
   *
   * @param string[] $pmids
   * @param string[] $preferredAuthorNames authors (e.g. from the search filter) that are kept when shortening author lists
   * @return array<int, array{pmid: string, title: string, source: string, pubDate: string, sortPubDate: string, authors: string[], authorCount: int}>
   * @throws RuntimeException if the API is not reachable, answers with an error or fails too often.
   */
  public function fetchSummaries(array $pmids, array $preferredAuthorNames): array {
    $this->transientFailureCount = 0;
    $this->skippedPmids = [];

    $publications = [];
    $chunks = array_chunk($pmids, self::SUMMARY_CHUNK_SIZE);
    foreach ($chunks as $chunkIndex => $chunk) {
      if ($chunkIndex > 0) {
        $this->pauseForRateLimit();
      }
      $publications = array_merge($publications, $this->fetchSummaryChunkAdaptively($chunk, $preferredAuthorNames));
    }
    return $publications;
  }

  /**
   * PMIDs that could not be loaded by the last fetchSummaries() call, with the reason.
   *
   * @return array<string, string>
   */
  public function getSkippedPmids(): array {
    return $this->skippedPmids;
  }

  /**
   * @param string[] $pmids
   * @param string[] $preferredAuthorNames
   * @return array<int, array<string, mixed>>
   * @throws RuntimeException
   */
  private function fetchSummaryChunkAdaptively(array $pmids, array $preferredAuthorNames): array {
    try {
      return $this->fetchSummaryChunk($pmids, $preferredAuthorNames);
    } catch (TransientApiException $exception) {
      $this->transientFailureCount++;
      if ($this->transientFailureCount > self::MAX_TRANSIENT_FAILURES) {
        throw new RuntimeException('Abbruch nach ' . self::MAX_TRANSIENT_FAILURES . ' Zeitüberschreitungen / Serverfehlern, zuletzt bei PMIDs ' . implode(',', $pmids) . ': ' . $exception->getMessage(), 0, $exception);
      }

      sleep(2);
      if (count($pmids) === 1) {
        // Last chance for a single PMID, then skip it
        try {
          return $this->fetchSummaryChunk($pmids, $preferredAuthorNames);
        } catch (TransientApiException $retryException) {
          $this->skippedPmids[(string) $pmids[0]] = $retryException->getMessage();
          return [];
        }
      }

      $splitIndex = (int) ceil(count($pmids) / 2);
      $firstHalf = $this->fetchSummaryChunkAdaptively(array_slice($pmids, 0, $splitIndex), $preferredAuthorNames);
      $this->pauseForRateLimit();
      $secondHalf = $this->fetchSummaryChunkAdaptively(array_slice($pmids, $splitIndex), $preferredAuthorNames);
      return array_merge($firstHalf, $secondHalf);
    }
  }

  /**
   * @param string[] $pmids
   * @param string[] $preferredAuthorNames
   * @return array<int, array<string, mixed>>
   * @throws RuntimeException
   */
  private function fetchSummaryChunk(array $pmids, array $preferredAuthorNames): array {
    $response = $this->requestJson('esummary.fcgi', [
      'db' => 'pubmed',
      'id' => implode(',', $pmids)
    ]);

    if (isset($response['error'])) {
      throw new RuntimeException('esummary: ' . (string) $response['error']);
    }
    if (!isset($response['result']['uids']) || !is_array($response['result']['uids'])) {
      throw new RuntimeException('esummary: Antwort enthält keine "uids".');
    }

    $publications = [];
    foreach ($response['result']['uids'] as $uid) {
      $uid = (string) $uid;
      if (!isset($response['result'][$uid]) || !is_array($response['result'][$uid])) {
        continue;
      }
      $summary = $response['result'][$uid];
      if (isset($summary['error'])) {
        continue;
      }
      $authorNames = $this->extractAuthorNames($summary['authors'] ?? []);
      $publications[] = [
        'pmid' => $uid,
        'title' => $this->toPlainText($summary['title'] ?? ''),
        'source' => $this->toPlainText($summary['source'] ?? ''),
        'pubDate' => $this->toPlainText($summary['pubdate'] ?? ''),
        'sortPubDate' => (string) ($summary['sortpubdate'] ?? ''),
        'authors' => $this->shortenAuthorList($authorNames, $preferredAuthorNames),
        'authorCount' => count($authorNames)
      ];
    }

    return $publications;
  }

  /**
   * NCBI allows 3 requests per second without and 10 with an API key.
   */
  private function pauseForRateLimit(): void {
    if ($this->settings->getApiKey() !== '') {
      usleep(120000);
    } else {
      usleep(350000);
    }
  }

  /**
   * Keeps the first authors, the preferred ones and the last author; gaps are marked with '…'.
   * Without this, consortium papers would bloat the cache to dozens of MB and the page to thousands of names.
   *
   * @param string[] $authorNames
   * @param string[] $preferredAuthorNames
   * @return string[]
   */
  private function shortenAuthorList(array $authorNames, array $preferredAuthorNames): array {
    $authorCount = count($authorNames);
    if ($authorCount <= self::MAX_LISTED_AUTHORS + 1) {
      return $authorNames;
    }

    $shortened = [];
    $previousKeptIndex = -1;
    foreach ($authorNames as $index => $authorName) {
      $isKept = $index < self::MAX_LISTED_AUTHORS || $index === $authorCount - 1 || $this->isPreferredAuthor($authorName, $preferredAuthorNames);
      if (!$isKept) {
        continue;
      }
      if ($index > $previousKeptIndex + 1) {
        $shortened[] = '…';
      }
      $shortened[] = $authorName;
      $previousKeptIndex = $index;
    }
    return $shortened;
  }

  /**
   * PubMed matches "Korn S[au]" as a prefix (also "Korn SW"), so do the same here.
   *
   * @param string[] $preferredAuthorNames
   */
  private function isPreferredAuthor(string $authorName, array $preferredAuthorNames): bool {
    foreach ($preferredAuthorNames as $preferredAuthorName) {
      if ($preferredAuthorName !== '' && stripos($authorName, $preferredAuthorName) === 0) {
        return true;
      }
    }
    return false;
  }

  /**
   * Performs a POST request (POST avoids URL length limits for long id lists) and decodes the JSON answer.
   *
   * @param array<string, string> $parameters
   * @return array<mixed>
   * @throws RuntimeException
   */
  private function requestJson(string $endpoint, array $parameters): array {
    $parameters['retmode'] = 'json';
    $parameters['tool'] = 'kornsw-pubmedcollection';
    $apiKey = $this->settings->getApiKey();
    if ($apiKey !== '') {
      $parameters['api_key'] = $apiKey;
    }

    /**
     * Allows adding further E-utilities parameters, e.g. 'email'.
     */
    $parameters = (array) apply_filters('kornsw_pmc_eutils_parameters', $parameters);

    $startedAt = microtime(true);
    $response = wp_remote_post(self::BASE_URL . $endpoint, [
      'timeout' => self::TIMEOUT_SECONDS,
      'body' => $parameters
    ]);
    $elapsed = number_format(microtime(true) - $startedAt, 1, ',', '') . ' s';

    if (is_wp_error($response)) {
      $ipVersionHint = '';
      if ($this->settings->getForceIpv4()) {
        $ipVersionHint = ', nur IPv4';
      }
      $errorMessage = $endpoint . ' (nach ' . $elapsed . $ipVersionHint . '): ' . $response->get_error_message();
      // cURL error 28 = timeout: the server is reachable, a smaller request may succeed
      if (stripos($response->get_error_message(), 'cURL error 28') !== false) {
        throw new TransientApiException($errorMessage);
      }
      throw new RuntimeException($errorMessage);
    }

    $statusCode = (int) wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    if ($statusCode !== 200) {
      // NCBI explains errors like an invalid API key in the body; never include the request (it contains the key)
      $errorMessage = $endpoint . ' (nach ' . $elapsed . '): HTTP ' . $statusCode . ' ' . substr(trim(wp_strip_all_tags($body)), 0, 200);
      if ($statusCode >= 500 || $statusCode === 429) {
        throw new TransientApiException($errorMessage);
      }
      throw new RuntimeException($errorMessage);
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
      throw new RuntimeException($endpoint . ': Antwort ist kein gültiges JSON (' . json_last_error_msg() . ').');
    }

    return $decoded;
  }

  /**
   * @param mixed $authors
   * @return string[]
   */
  private function extractAuthorNames($authors): array {
    $names = [];
    if (!is_array($authors)) {
      return $names;
    }
    foreach ($authors as $author) {
      if (is_array($author) && isset($author['name']) && $author['name'] !== '') {
        $names[] = $this->toPlainText($author['name']);
      }
    }
    return $names;
  }

  /**
   * Titles may contain markup like <i>...</i> or entities; the output is escaped later, so keep plain text only.
   *
   * @param mixed $value
   */
  private function toPlainText($value): string {
    $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(wp_strip_all_tags($text));
  }

}
