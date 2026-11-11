<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Stores the configuration of the collections. A collection is addressed by an internal id
 * (freely chosen, may equal the id of a PubMed collection so that existing shortcodes keep working).
 * The content (listed / excluded publications) lives in the ItemStore.
 */
final class CollectionRepository {

  public const OPTION_NAME = 'kornsw_pmc_collections';

  /** Highlight default of collections created before the setting existed (was hard-coded in the shortcode). */
  private const LEGACY_HIGHLIGHT_AUTHOR = 'Korn S';

  /**
   * @return array<string, array{id: string, autoImportQuery: string, autoImportEnabled: bool, externalUrl: string, highlightAuthor: string}>
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
   * @return array{id: string, autoImportQuery: string, autoImportEnabled: bool, externalUrl: string, highlightAuthor: string}|null
   */
  public function find(string $id): ?array {
    $collections = $this->all();
    if (isset($collections[$id])) {
      return $collections[$id];
    }
    return null;
  }

  /**
   * @return array{id: string, autoImportQuery: string, autoImportEnabled: bool, externalUrl: string, highlightAuthor: string}
   */
  public function createEmpty(): array {
    return $this->normalize(['autoImportEnabled' => true, 'highlightAuthor' => '']);
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
   * The link shown above the list: the configured external url, otherwise the PubMed search of the auto import query.
   *
   * @param array<string, mixed> $collection
   */
  public static function resolveExternalUrl(array $collection): string {
    if ((string) $collection['externalUrl'] !== '') {
      return (string) $collection['externalUrl'];
    }
    if ((string) $collection['autoImportQuery'] !== '') {
      return PubmedQuery::buildSearchUrl((string) $collection['autoImportQuery']);
    }
    return 'https://pubmed.ncbi.nlm.nih.gov/';
  }

  /**
   * @param array<string, mixed> $collection
   * @return array{id: string, autoImportQuery: string, autoImportEnabled: bool, externalUrl: string, highlightAuthor: string}
   */
  private function normalize(array $collection): array {
    return [
      'id' => (string) ($collection['id'] ?? ''),
      'autoImportQuery' => (string) ($collection['autoImportQuery'] ?? ''),
      'autoImportEnabled' => (bool) ($collection['autoImportEnabled'] ?? false),
      'externalUrl' => (string) ($collection['externalUrl'] ?? ''),
      // Collections from before this setting existed keep the former hard-coded default
      'highlightAuthor' => (string) ($collection['highlightAuthor'] ?? self::LEGACY_HIGHLIGHT_AUTHOR)
    ];
  }

  /**
   * Names highlighted in the author lists of the collection.
   *
   * @param array<string, mixed> $collection
   * @return string[]
   */
  public static function highlightNames(array $collection): array {
    return PubmedQuery::splitNames((string) ($collection['highlightAuthor'] ?? ''));
  }

  /**
   * Authors that must survive the shortening of long author lists: the ones of the query and the highlighted ones.
   *
   * @param array<string, mixed> $collection
   * @return string[]
   */
  public static function preferredAuthorNames(array $collection, string $query): array {
    return array_values(array_unique(array_merge(PubmedQuery::extractAuthorNames($query), self::highlightNames($collection))));
  }

}
