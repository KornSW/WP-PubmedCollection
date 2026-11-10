<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection;

use RuntimeException;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Provides the (unfiltered) search result of a collection from the persistent cache and refreshes it when due.
 * If a refresh fails, the last good result is kept and served indefinitely and the admin is alarmed.
 */
final class PublicationService {

  private const CACHE_SECONDS = 12 * HOUR_IN_SECONDS;

  /** Pause between two refresh attempts while PubMed is failing. */
  private const RETRY_SECONDS = HOUR_IN_SECONDS;

  /** A still-failing refresh triggers a reminder mail after this interval. */
  private const ALARM_REPEAT_SECONDS = 7 * DAY_IN_SECONDS;

  private EutilsClient $client;

  private PublicationCache $cache;

  private AlarmNotifier $notifier;

  public function __construct(EutilsClient $client, PublicationCache $cache, AlarmNotifier $notifier) {
    $this->client = $client;
    $this->cache = $cache;
    $this->notifier = $notifier;
  }

  /**
   * Returns the cache entry of the collection, refreshing it first when it is outdated (or when forced).
   *
   * @param array<string, mixed> $collection
   * @return array<string, mixed>
   */
  public function getEntry(array $collection, bool $forceRefresh = false): array {
    $collectionId = (string) $collection['id'];
    $queryHash = $this->buildQueryHash($collection);

    $entry = $this->cache->load($collectionId);
    if ($entry['queryHash'] !== $queryHash) {
      // The filter was changed: the old result belongs to a different query and must not be shown anymore
      $entry = $this->cache->createEmptyEntry();
      $entry['queryHash'] = $queryHash;
    }

    if (($forceRefresh || $this->needsRefresh($entry)) && $this->cache->tryAcquireLock($collectionId)) {
      try {
        $entry = $this->refresh($collection, $entry);
      } finally {
        $this->cache->releaseLock($collectionId);
      }
    }

    return $entry;
  }

  /**
   * Returns the cache entry without triggering a refresh (for overviews).
   *
   * @param array<string, mixed> $collection
   * @return array<string, mixed>|null null if nothing was fetched for the current filter yet.
   */
  public function peekEntry(array $collection): ?array {
    $entry = $this->cache->load((string) $collection['id']);
    if ($entry['queryHash'] !== $this->buildQueryHash($collection)) {
      return null;
    }
    return $entry;
  }

  /**
   * @param array<string, mixed> $entry
   */
  private function needsRefresh(array $entry): bool {
    $now = time();
    if ((int) $entry['fetchedAt'] + self::CACHE_SECONDS > $now) {
      return false;
    }
    if ((int) $entry['failingSince'] > 0 && (int) $entry['lastAttemptAt'] + self::RETRY_SECONDS > $now) {
      return false;
    }
    return true;
  }

  /**
   * Loads the result from PubMed and updates the cache. On failure the previous publications stay untouched.
   *
   * @param array<string, mixed> $collection
   * @param array<string, mixed> $entry
   * @return array<string, mixed>
   */
  private function refresh(array $collection, array $entry): array {
    $collectionId = (string) $collection['id'];
    $now = time();
    $entry['lastAttemptAt'] = $now;

    try {
      $pmids = $this->client->searchPmids((string) $collection['term'], (int) $collection['maxResults']);
      $publications = $this->client->fetchSummaries($pmids);

      // An empty answer while we already know publications is far more likely a PubMed problem than reality
      if (count($publications) === 0 && count($entry['publications']) > 0) {
        throw new RuntimeException('PubMed lieferte keine Treffer, obwohl bisher ' . count($entry['publications']) . ' Einträge bekannt waren - Ergebnis wurde als unplausibel verworfen.');
      }

      usort($publications, [$this, 'compareNewestFirst']);

      $alarmWasSent = (int) $entry['alarmSentAt'] > 0;
      $entry['publications'] = $publications;
      $entry['fetchedAt'] = $now;
      $entry['failingSince'] = 0;
      $entry['lastError'] = '';
      $entry['alarmSentAt'] = 0;
      $this->cache->save($collectionId, $entry);

      if ($alarmWasSent) {
        $this->notifier->sendRecovery($collection, $entry);
      }
    } catch (RuntimeException $exception) {
      if ((int) $entry['failingSince'] === 0) {
        $entry['failingSince'] = $now;
      }
      $entry['lastError'] = $exception->getMessage();
      error_log('kornsw-pubmedcollection [' . $collectionId . ']: ' . $exception->getMessage());

      if ((int) $entry['alarmSentAt'] + self::ALARM_REPEAT_SECONDS <= $now) {
        $this->notifier->sendAlarm($collection, $entry);
        $entry['alarmSentAt'] = $now;
      }
      $this->cache->save($collectionId, $entry);
    }

    return $entry;
  }

  /**
   * Exclusions are applied on output, so they are not part of the query.
   *
   * @param array<string, mixed> $collection
   */
  private function buildQueryHash(array $collection): string {
    return md5((string) wp_json_encode([(string) $collection['term'], (int) $collection['maxResults']]));
  }

  /**
   * @param array<string, mixed> $left
   * @param array<string, mixed> $right
   */
  private function compareNewestFirst(array $left, array $right): int {
    // sortpubdate has the format "2023/11/16 00:00" and therefore sorts correctly as a string
    $byDate = strcmp((string) $right['sortPubDate'], (string) $left['sortPubDate']);
    if ($byDate !== 0) {
      return $byDate;
    }
    return (int) $right['pmid'] <=> (int) $left['pmid'];
  }

}
