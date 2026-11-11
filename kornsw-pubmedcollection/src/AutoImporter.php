<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection;

use RuntimeException;
use Throwable;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Level 1 of a collection: polls PubMed every few days (WP-Cron) for publications that were newly added
 * to PubMed and match the auto import query, adds them to the list and mails a review notice.
 * Publications that are already listed or excluded are never imported again.
 */
final class AutoImporter {

  /** WP-Cron hook, runs twice a day and imports the collections that are due. */
  public const CRON_HOOK = 'kornsw_pmc_auto_import';

  /** The search window overlaps the previous poll, because PubMed indexes records with some delay. */
  private const OVERLAP_DAYS = 7;

  private const MAX_RESULTS = 500;

  /** A still-failing import triggers a reminder mail after this interval. */
  private const ALARM_REPEAT_SECONDS = 7 * DAY_IN_SECONDS;

  private CollectionRepository $collections;

  private ItemStore $itemStore;

  private EutilsClient $client;

  private AlarmNotifier $notifier;

  private Settings $settings;

  private RefreshLog $log;

  public function __construct(CollectionRepository $collections, ItemStore $itemStore, EutilsClient $client, AlarmNotifier $notifier, Settings $settings, RefreshLog $log) {
    $this->collections = $collections;
    $this->itemStore = $itemStore;
    $this->client = $client;
    $this->notifier = $notifier;
    $this->settings = $settings;
    $this->log = $log;
  }

  /**
   * Cron callback.
   */
  public function runDueImports(): void {
    $intervalSeconds = $this->settings->getAutoImportIntervalDays() * DAY_IN_SECONDS;
    foreach ($this->collections->all() as $collection) {
      if (!$collection['autoImportEnabled'] || $collection['autoImportQuery'] === '') {
        continue;
      }
      $state = $this->itemStore->load($collection['id'])['autoImport'];
      // A failed run does not advance lastPollAt, so it is retried with the next cron run
      if ((int) $state['lastPollAt'] + $intervalSeconds <= time()) {
        $this->import($collection);
      }
    }
  }

  /**
   * Imports the new publications of one collection now.
   *
   * @param array<string, mixed> $collection
   * @return array{success: bool, imported: int, message: string}
   */
  public function import(array $collection): array {
    $collectionId = (string) $collection['id'];
    $query = (string) $collection['autoImportQuery'];
    $startedAt = microtime(true);
    $now = time();
    $state = $this->itemStore->load($collectionId)['autoImport'];

    // Many chunked requests, possibly split after NCBI timeouts; the default limit of 30 s is not enough
    if (function_exists('set_time_limit')) {
      set_time_limit(600);
    }

    try {
      if ($query === '') {
        throw new RuntimeException('Keine Auto-Import-Query hinterlegt.');
      }
      $since = (int) $state['lastPollAt'];
      if ($since <= 0) {
        $since = $now;
      }
      $pmids = $this->client->searchPmids($query, self::MAX_RESULTS, [
        'datetype' => 'edat',
        'mindate' => gmdate('Y/m/d', $since - self::OVERLAP_DAYS * DAY_IN_SECONDS),
        'maxdate' => '3000'
      ]);

      $data = $this->itemStore->load($collectionId);
      $newPmids = array_values(array_diff($pmids, array_keys($data['items']), array_keys($data['excluded'])));
      $publications = $this->client->fetchSummaries($newPmids, CollectionRepository::preferredAuthorNames($collection, $query));

      // Re-read inside addPublications: the list may have been edited while PubMed was queried
      $currentData = $this->itemStore->load($collectionId);
      $publications = array_values(array_filter($publications, function (array $publication) use ($currentData): bool {
        return !isset($currentData['excluded'][(string) $publication['pmid']]);
      }));
      $added = $this->itemStore->addPublications($collectionId, $publications, ItemStore::ORIGIN_AUTO);

      $message = count($pmids) . ' neu erfasste Treffer seit ' . gmdate('d.m.Y', $since - self::OVERLAP_DAYS * DAY_IN_SECONDS) . ', davon ' . count($added) . ' importiert.';
      $skippedPmids = $this->client->getSkippedPmids();
      if (count($skippedPmids) > 0) {
        $message .= ' ' . count($skippedPmids) . ' PMID(s) wegen PubMed-Fehlern übersprungen (beim nächsten Lauf erneut): ' . implode(', ', array_keys($skippedPmids)) . '.';
      }

      $alarmWasSent = (int) $state['alarmSentAt'] > 0;
      if (count($skippedPmids) === 0) {
        $state['lastPollAt'] = $now;
      }
      $state['lastSuccessAt'] = $now;
      $state['lastResult'] = $message;
      $state['failingSince'] = 0;
      $state['lastError'] = '';
      $state['alarmSentAt'] = 0;
      $this->itemStore->saveAutoImportState($collectionId, $state);

      if (count($added) > 0) {
        $message .= ' Review-Mail ' . $this->describeMailResult($this->notifier->sendImportNotice($collection, $added)) . '.';
      }
      if ($alarmWasSent) {
        $message .= ' Störung behoben, Entwarnungs-Mail ' . $this->describeMailResult($this->notifier->sendRecovery($collection)) . '.';
      }
      $this->log->add($collectionId, RefreshLog::LEVEL_OK, $message, microtime(true) - $startedAt);

      return ['success' => true, 'imported' => count($added), 'message' => $message];
    } catch (Throwable $exception) {
      // Also unexpected errors (e.g. an oddly shaped API answer): never crash the cron job
      $errorMessage = $exception->getMessage();
      if (!$exception instanceof RuntimeException) {
        $errorMessage = get_class($exception) . ': ' . $errorMessage . ' in ' . basename($exception->getFile()) . ':' . $exception->getLine();
      }
      if ((int) $state['failingSince'] === 0) {
        $state['failingSince'] = $now;
      }
      $state['lastError'] = $errorMessage;
      error_log('kornsw-pubmedcollection [' . $collectionId . ']: ' . $errorMessage);

      $logMessage = $errorMessage;
      if ((int) $state['alarmSentAt'] + self::ALARM_REPEAT_SECONDS <= $now) {
        $logMessage .= ' | Alarm-Mail ' . $this->describeMailResult($this->notifier->sendAlarm($collection, $state)) . '.';
        $state['alarmSentAt'] = $now;
      }
      $this->itemStore->saveAutoImportState($collectionId, $state);
      $this->log->add($collectionId, RefreshLog::LEVEL_ERROR, $logMessage, microtime(true) - $startedAt);

      return ['success' => false, 'imported' => 0, 'message' => $errorMessage];
    }
  }

  private function describeMailResult(bool $wasSent): string {
    if ($wasSent) {
      return 'versendet';
    }
    return 'konnte NICHT versendet werden (wp_mail)';
  }

}
