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

  /** esummary needs ~5 s for 20 ids when consortium papers (thousands of authors) are among them. */
  private const SUMMARY_CHUNK_SIZE = 20;

  private const MAX_LISTED_AUTHORS = 20;

  private Settings $settings;

  public function __construct(Settings $settings) {
    $this->settings = $settings;
  }

  /**
   * Runs a PubMed search and returns the matching PMIDs, newest publications first.
   *
   * @return string[]
   * @throws RuntimeException if the API is not reachable or answers with an error.
   */
  public function searchPmids(string $term, int $maxResults): array {
    $response = $this->requestJson('esearch.fcgi', [
      'db' => 'pubmed',
      'term' => $term,
      'retmax' => (string) $maxResults,
      'sort' => 'pub_date'
    ]);

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

    return array_map('strval', $searchResult['idlist']);
  }

  /**
   * Loads the summaries (title, journal, date, authors) of the given PMIDs in small chunks: consortium papers
   * carry thousands of authors (up to ~300 KB each), so large requests run into NCBI timeouts / HTTP 500.
   *
   * @param string[] $pmids
   * @param string[] $preferredAuthorNames authors (e.g. from the search filter) that are kept when shortening author lists
   * @return array<int, array{pmid: string, title: string, source: string, pubDate: string, sortPubDate: string, authors: string[], authorCount: int}>
   * @throws RuntimeException if the API is not reachable or answers with an error.
   */
  public function fetchSummaries(array $pmids, array $preferredAuthorNames): array {
    $publications = [];
    $chunks = array_chunk($pmids, self::SUMMARY_CHUNK_SIZE);
    foreach ($chunks as $chunkIndex => $chunk) {
      if ($chunkIndex > 0) {
        $this->pauseForRateLimit();
      }
      try {
        $chunkPublications = $this->fetchSummaryChunk($chunk, $preferredAuthorNames);
      } catch (RuntimeException $firstException) {
        // NCBI answers sporadically with timeouts or HTTP 5xx; one retry per chunk smooths that out
        sleep(2);
        try {
          $chunkPublications = $this->fetchSummaryChunk($chunk, $preferredAuthorNames);
        } catch (RuntimeException $retryException) {
          throw new RuntimeException(
            'Chunk ' . ($chunkIndex + 1) . '/' . count($chunks) . ' (PMIDs ' . reset($chunk) . '-' . end($chunk) . '), auch nach Wiederholung: '
              . $retryException->getMessage() . ' | 1. Versuch: ' . $firstException->getMessage(),
            0,
            $retryException
          );
        }
      }
      $publications = array_merge($publications, $chunkPublications);
    }
    return $publications;
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
      throw new RuntimeException($endpoint . ' (nach ' . $elapsed . $ipVersionHint . '): ' . $response->get_error_message());
    }

    $statusCode = (int) wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    if ($statusCode !== 200) {
      // NCBI explains errors like an invalid API key in the body; never include the request (it contains the key)
      throw new RuntimeException($endpoint . ' (nach ' . $elapsed . '): HTTP ' . $statusCode . ' ' . substr(trim(wp_strip_all_tags($body)), 0, 200));
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
