<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection;

use RuntimeException;
use Throwable;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Provides the (unfiltered) search result of a collection from the persistent cache and refreshes it when due.
 * If a refresh fails, the last good result is kept and served indefinitely and the admin is alarmed.
 */
final class PublicationService {

  /** WP-Cron hook of the background refresh, argument: collection id. */
  public const REFRESH_HOOK = 'kornsw_pmc_refresh_collection';

  /** Pause between two refresh attempts while PubMed is failing. */
  private const RETRY_SECONDS = HOUR_IN_SECONDS;

  /** A still-failing refresh triggers a reminder mail after this interval. */
  private const ALARM_REPEAT_SECONDS = 7 * DAY_IN_SECONDS;

  private EutilsClient $client;

  private PublicationCache $cache;

  private AlarmNotifier $notifier;

  private Settings $settings;

  private RefreshLog $log;

  public function __construct(EutilsClient $client, PublicationCache $cache, AlarmNotifier $notifier, Settings $settings, RefreshLog $log) {
    $this->client = $client;
    $this->cache = $cache;
    $this->notifier = $notifier;
    $this->settings = $settings;
    $this->log = $log;
  }

  /**
   * Returns the cache entry of the collection, refreshing it synchronously first when it is outdated (or when forced).
   * A refresh can take a minute, so this is meant for the admin page and the background job only.
   *
   * @param array<string, mixed> $collection
   * @return array<string, mixed>
   */
  public function getEntry(array $collection, bool $forceRefresh = false): array {
    $collectionId = (string) $collection['id'];
    $entry = $this->loadCurrentEntry($collection);

    if (!$forceRefresh && !$this->needsRefresh($entry)) {
      return $entry;
    }
    if (!$this->cache->tryAcquireLock($collectionId)) {
      $this->log->add($collectionId, RefreshLog::LEVEL_INFO, 'Abruf übersprungen - es läuft bereits einer.');
      return $entry;
    }
    try {
      $entry = $this->refresh($collection, $entry);
    } finally {
      $this->cache->releaseLock($collectionId);
    }

    return $entry;
  }

  /**
   * Returns the cache entry immediately (for page views) and schedules a background refresh when it is outdated.
   *
   * @param array<string, mixed> $collection
   * @return array<string, mixed>
   */
  public function getCachedEntry(array $collection): array {
    $entry = $this->loadCurrentEntry($collection);
    if ($this->needsRefresh($entry)) {
      $arguments = [(string) $collection['id']];
      if (wp_next_scheduled(self::REFRESH_HOOK, $arguments) === false) {
        $scheduled = wp_schedule_single_event(time(), self::REFRESH_HOOK, $arguments);
        if ($scheduled === true) {
          $this->log->add((string) $collection['id'], RefreshLog::LEVEL_INFO, 'Cache veraltet - Abruf im Hintergrund eingeplant.');
        } else {
          $this->log->add((string) $collection['id'], RefreshLog::LEVEL_ERROR, 'Hintergrund-Abruf konnte nicht eingeplant werden (WP-Cron).');
        }
      }
    }
    return $entry;
  }

  /**
   * Background job (WP-Cron) scheduled by getCachedEntry().
   *
   * @param array<string, mixed> $collection
   */
  public function refreshInBackground(array $collection): void {
    $this->getEntry($collection);
  }

  /**
   * @param array<string, mixed> $collection
   * @return array<string, mixed>
   */
  private function loadCurrentEntry(array $collection): array {
    $queryHash = $this->buildQueryHash($collection);
    $entry = $this->cache->load((string) $collection['id']);
    if ($entry['queryHash'] !== $queryHash) {
      // The filter was changed: the old result belongs to a different query and must not be shown anymore
      $entry = $this->cache->createEmptyEntry();
      $entry['queryHash'] = $queryHash;
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
    if ((int) $entry['fetchedAt'] + $this->settings->getCacheHours() * HOUR_IN_SECONDS > $now) {
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
    $startedAt = microtime(true);
    $entry['lastAttemptAt'] = $now;

    // Up to 25 chunked requests of several seconds each; the default limit of 30 s is not enough
    if (function_exists('set_time_limit')) {
      set_time_limit(300);
    }

    try {
      $pmids = $this->client->searchPmids((string) $collection['term'], (int) $collection['maxResults']);
      $publications = $this->client->fetchSummaries($pmids, $this->extractAuthorNamesFromTerm((string) $collection['term']));

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

      $message = count($publications) . ' Treffer geladen (' . count($pmids) . ' PMIDs).';
      if ($alarmWasSent) {
        $message .= ' Störung behoben, Entwarnungs-Mail ' . $this->describeMailResult($this->notifier->sendRecovery($collection, $entry)) . '.';
      }
      $this->log->add($collectionId, RefreshLog::LEVEL_OK, $message, microtime(true) - $startedAt);
    } catch (Throwable $exception) {
      // Also unexpected errors (e.g. an oddly shaped API answer): keep the old list and alarm instead of crashing the job
      if ((int) $entry['failingSince'] === 0) {
        $entry['failingSince'] = $now;
      }
      $errorMessage = $exception->getMessage();
      if (!$exception instanceof RuntimeException) {
        $errorMessage = get_class($exception) . ': ' . $errorMessage . ' in ' . basename($exception->getFile()) . ':' . $exception->getLine();
      }
      $entry['lastError'] = $errorMessage;
      error_log('kornsw-pubmedcollection [' . $collectionId . ']: ' . $errorMessage);

      $logMessage = $errorMessage;
      if ((int) $entry['alarmSentAt'] + self::ALARM_REPEAT_SECONDS <= $now) {
        $logMessage .= ' | Alarm-Mail ' . $this->describeMailResult($this->notifier->sendAlarm($collection, $entry)) . '.';
        $entry['alarmSentAt'] = $now;
      }
      $this->cache->save($collectionId, $entry);
      $this->log->add($collectionId, RefreshLog::LEVEL_ERROR, $logMessage, microtime(true) - $startedAt);
    }

    return $entry;
  }

  private function describeMailResult(bool $wasSent): string {
    if ($wasSent) {
      return 'versendet';
    }
    return 'konnte NICHT versendet werden (wp_mail)';
  }

  /**
   * Finds the author names of the filter (e.g. "Korn S" in 'Korn S[au] AND Mainz[ad]'), so they survive
   * the shortening of long author lists and can still be highlighted.
   *
   * @return string[]
   */
  private function extractAuthorNamesFromTerm(string $term): array {
    $names = [];
    if (preg_match_all('/([^\[\]()]+)\[(?:au|auth|author|1au|fau|lastau|full author name)\]/iu', $term, $matches) === false) {
      return $names;
    }
    foreach ($matches[1] as $match) {
      // The match may start with the rest of the previous expression, e.g. ' AND Korn S'
      $parts = preg_split('/\s+(?:AND|OR|NOT)\s+/', ' ' . $match . ' ');
      if (!is_array($parts)) {
        continue;
      }
      $name = trim((string) end($parts), " \t\"");
      if ($name !== '') {
        $names[] = $name;
      }
    }
    return array_values(array_unique($names));
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
