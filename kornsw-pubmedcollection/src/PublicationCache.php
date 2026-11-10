<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Persistent cache of the last successfully loaded (unfiltered) search result per collection.
 * Stored as non-autoloaded options instead of transients, because transients may be evicted at any time
 * (e.g. by an object cache) and the last good result must survive a long PubMed outage.
 */
final class PublicationCache {

  private const OPTION_PREFIX = 'kornsw_pmc_cache_';

  private const LOCK_PREFIX = 'kornsw_pmc_lock_';

  /** Longer than a complete chunked refresh may take. */
  private const LOCK_SECONDS = 600;

  /**
   * Returns the stored entry or an empty one.
   *
   * @return array{queryHash: string, publications: array<int, array<string, mixed>>, fetchedAt: int, lastAttemptAt: int, failingSince: int, lastError: string, alarmSentAt: int}
   */
  public function load(string $collectionId): array {
    $entry = get_option($this->optionName($collectionId), null);
    if (!is_array($entry)) {
      $entry = [];
    }
    return array_merge($this->createEmptyEntry(), $entry);
  }

  /**
   * @return array{queryHash: string, publications: array<int, array<string, mixed>>, fetchedAt: int, lastAttemptAt: int, failingSince: int, lastError: string, alarmSentAt: int}
   */
  public function createEmptyEntry(): array {
    return [
      'queryHash' => '',
      'publications' => [],
      'fetchedAt' => 0,
      'lastAttemptAt' => 0,
      'failingSince' => 0,
      'lastError' => '',
      'alarmSentAt' => 0,
      'searchInfo' => ['totalCount' => 0, 'translation' => '', 'warnings' => []]
    ];
  }

  /**
   * @param array<string, mixed> $entry
   */
  public function save(string $collectionId, array $entry): void {
    update_option($this->optionName($collectionId), $entry, false);
  }

  public function delete(string $collectionId): void {
    delete_option($this->optionName($collectionId));
  }

  /**
   * Moves the entry when a collection id is renamed, so the cached list is not lost.
   */
  public function rename(string $oldCollectionId, string $newCollectionId): void {
    $entry = get_option($this->optionName($oldCollectionId), null);
    if (is_array($entry)) {
      update_option($this->optionName($newCollectionId), $entry, false);
    }
    delete_option($this->optionName($oldCollectionId));
  }

  /**
   * Prevents parallel page views from refreshing the same entry at the same time.
   * Not strictly atomic, but sufficient to avoid hammering the API.
   */
  public function tryAcquireLock(string $collectionId): bool {
    $lockName = self::LOCK_PREFIX . md5($collectionId);
    if (get_transient($lockName) !== false) {
      return false;
    }
    set_transient($lockName, 1, self::LOCK_SECONDS);
    return true;
  }

  public function releaseLock(string $collectionId): void {
    delete_transient(self::LOCK_PREFIX . md5($collectionId));
  }

  private function optionName(string $collectionId): string {
    return self::OPTION_PREFIX . md5($collectionId);
  }

}
