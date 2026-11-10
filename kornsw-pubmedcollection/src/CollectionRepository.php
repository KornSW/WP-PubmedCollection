<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Stores the collections configured in the admin UI. A collection is addressed by an internal id
 * (freely chosen, may equal the id of a PubMed collection so that existing shortcodes keep working).
 */
final class CollectionRepository {

  private const OPTION_NAME = 'kornsw_pmc_collections';

  public const DEFAULT_MAX_RESULTS = 200;

  public const MAX_RESULTS_LIMIT = 500;

  /**
   * @return array<string, array{id: string, term: string, externalUrl: string, maxResults: int, excludedPmids: string[]}>
   */
  public function all(): array {
    $stored = get_option(self::OPTION_NAME, []);
    $collections = [];
    if (!is_array($stored)) {
      return $collections;
    }
    foreach ($stored as $collection) {
      if (is_array($collection) && isset($collection['id'])) {
        $normalized = $this->normalize($collection);
        $collections[$normalized['id']] = $normalized;
      }
    }
    ksort($collections, SORT_NATURAL | SORT_FLAG_CASE);
    return $collections;
  }

  /**
   * @return array{id: string, term: string, externalUrl: string, maxResults: int, excludedPmids: string[]}|null
   */
  public function find(string $id): ?array {
    $collections = $this->all();
    if (isset($collections[$id])) {
      return $collections[$id];
    }
    return null;
  }

  /**
   * @return array{id: string, term: string, externalUrl: string, maxResults: int, excludedPmids: string[]}
   */
  public function createEmpty(): array {
    return $this->normalize([]);
  }

  /**
   * Inserts or updates a collection. A changed id (rename) replaces the entry stored under $previousId.
   *
   * @param array<string, mixed> $collection
   */
  public function save(array $collection, string $previousId): void {
    $collections = $this->all();
    if ($previousId !== '') {
      unset($collections[$previousId]);
    }
    $normalized = $this->normalize($collection);
    $collections[$normalized['id']] = $normalized;
    update_option(self::OPTION_NAME, array_values($collections));
  }

  public function delete(string $id): void {
    $collections = $this->all();
    unset($collections[$id]);
    update_option(self::OPTION_NAME, array_values($collections));
  }

  public static function isValidId(string $id): bool {
    return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1;
  }

  /**
   * The link shown above the list: the configured external url, otherwise the PubMed search for the filter.
   *
   * @param array<string, mixed> $collection
   */
  public static function resolveExternalUrl(array $collection): string {
    if ((string) $collection['externalUrl'] !== '') {
      return (string) $collection['externalUrl'];
    }
    return self::buildSearchUrl((string) $collection['term']);
  }

  public static function buildSearchUrl(string $term): string {
    return 'https://pubmed.ncbi.nlm.nih.gov/?term=' . rawurlencode($term) . '&sort=pubdate';
  }

  /**
   * @param array<string, mixed> $collection
   * @return array{id: string, term: string, externalUrl: string, maxResults: int, excludedPmids: string[]}
   */
  private function normalize(array $collection): array {
    $maxResults = self::DEFAULT_MAX_RESULTS;
    if (isset($collection['maxResults'])) {
      $maxResults = max(1, min(self::MAX_RESULTS_LIMIT, (int) $collection['maxResults']));
    }

    $excludedPmids = [];
    if (isset($collection['excludedPmids']) && is_array($collection['excludedPmids'])) {
      foreach ($collection['excludedPmids'] as $pmid) {
        $pmid = (string) $pmid;
        if (ctype_digit($pmid)) {
          $excludedPmids[] = $pmid;
        }
      }
    }

    return [
      'id' => (string) ($collection['id'] ?? ''),
      'term' => (string) ($collection['term'] ?? ''),
      'externalUrl' => (string) ($collection['externalUrl'] ?? ''),
      'maxResults' => $maxResults,
      'excludedPmids' => array_values(array_unique($excludedPmids))
    ];
  }

}
