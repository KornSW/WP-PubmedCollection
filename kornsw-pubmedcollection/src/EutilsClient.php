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

  private const BASE_URL = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/';

  private const TIMEOUT_SECONDS = 15;

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
   * Loads the summaries (title, journal, date, authors) of the given PMIDs.
   *
   * @param string[] $pmids
   * @return array<int, array{pmid: string, title: string, source: string, pubDate: string, sortPubDate: string, authors: string[]}>
   * @throws RuntimeException if the API is not reachable or answers with an error.
   */
  public function fetchSummaries(array $pmids): array {
    if (count($pmids) === 0) {
      return [];
    }

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
      $publications[] = [
        'pmid' => $uid,
        'title' => $this->toPlainText($summary['title'] ?? ''),
        'source' => $this->toPlainText($summary['source'] ?? ''),
        'pubDate' => $this->toPlainText($summary['pubdate'] ?? ''),
        'sortPubDate' => (string) ($summary['sortpubdate'] ?? ''),
        'authors' => $this->extractAuthorNames($summary['authors'] ?? [])
      ];
    }

    return $publications;
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

    $response = wp_remote_post(self::BASE_URL . $endpoint, [
      'timeout' => self::TIMEOUT_SECONDS,
      'body' => $parameters
    ]);

    if (is_wp_error($response)) {
      throw new RuntimeException($endpoint . ': ' . $response->get_error_message());
    }

    $statusCode = (int) wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    if ($statusCode !== 200) {
      // NCBI explains errors like an invalid API key in the body; never include the request (it contains the key)
      throw new RuntimeException($endpoint . ': HTTP ' . $statusCode . ' ' . substr(trim(wp_strip_all_tags($body)), 0, 200));
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
