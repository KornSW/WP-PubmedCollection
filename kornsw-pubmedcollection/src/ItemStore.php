<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * The locally maintained content of a collection: the listed publications, the excluded ones and the
 * state of the auto import. This is curated user data (not a cache) and is never wiped automatically.
 */
final class ItemStore {

  private const OPTION_PREFIX = 'kornsw_pmc_items_';

  public const ORIGIN_AUTO = 'auto';

  public const ORIGIN_MANUAL = 'manual';

  public const ORIGIN_MIGRATION = 'migration';

  /**
   * @return array{items: array<string, array<string, mixed>>, excluded: array<string, array<string, mixed>>, autoImport: array<string, mixed>}
   */
  public function load(string $collectionId): array {
    $data = get_option($this->optionName($collectionId), null);
    if (!is_array($data)) {
      $data = [];
    }
    $autoImport = [];
    if (isset($data['autoImport']) && is_array($data['autoImport'])) {
      $autoImport = $data['autoImport'];
    }
    return [
      'items' => $this->readRecords($data, 'items'),
      'excluded' => $this->readRecords($data, 'excluded'),
      'autoImport' => array_merge([
        'lastPollAt' => 0,
        'lastSuccessAt' => 0,
        'lastResult' => '',
        'failingSince' => 0,
        'lastError' => '',
        'alarmSentAt' => 0
      ], $autoImport)
    ];
  }

  /**
   * @param array<string, mixed> $data
   */
  public function save(string $collectionId, array $data): void {
    update_option($this->optionName($collectionId), $data, false);
  }

  /**
   * Adds publications to the list (a previously excluded publication is moved back).
   *
   * @param array<int, array<string, mixed>> $publications
   * @return array<int, array<string, mixed>> the publications that were not listed before
   */
  public function addPublications(string $collectionId, array $publications, string $origin): array {
    $data = $this->load($collectionId);
    $added = [];
    foreach ($publications as $publication) {
      $pmid = (string) $publication['pmid'];
      if (isset($data['items'][$pmid])) {
        continue;
      }
      unset($data['excluded'][$pmid]);
      $publication['origin'] = $origin;
      $publication['addedAt'] = time();
      $data['items'][$pmid] = $publication;
      $added[] = $publication;
    }
    $this->save($collectionId, $data);
    return $added;
  }

  /**
   * Moves listed publications to the excluded list.
   *
   * @param string[] $pmids
   */
  public function exclude(string $collectionId, array $pmids): int {
    $data = $this->load($collectionId);
    $count = 0;
    foreach ($pmids as $pmid) {
      if (!isset($data['items'][$pmid])) {
        continue;
      }
      $record = $data['items'][$pmid];
      $record['excludedAt'] = time();
      $data['excluded'][$pmid] = $record;
      unset($data['items'][$pmid]);
      $count++;
    }
    $this->save($collectionId, $data);
    return $count;
  }

  /**
   * Moves excluded publications back to the list.
   *
   * @param string[] $pmids
   */
  public function restore(string $collectionId, array $pmids): int {
    $data = $this->load($collectionId);
    $count = 0;
    foreach ($pmids as $pmid) {
      if (!isset($data['excluded'][$pmid])) {
        continue;
      }
      $record = $data['excluded'][$pmid];
      unset($record['excludedAt']);
      $data['items'][$pmid] = $record;
      unset($data['excluded'][$pmid]);
      $count++;
    }
    $this->save($collectionId, $data);
    return $count;
  }

  /**
   * @param array<string, mixed> $autoImportState
   */
  public function saveAutoImportState(string $collectionId, array $autoImportState): void {
    $data = $this->load($collectionId);
    $data['autoImport'] = $autoImportState;
    $this->save($collectionId, $data);
  }

  public function delete(string $collectionId): void {
    delete_option($this->optionName($collectionId));
  }

  public function rename(string $oldCollectionId, string $newCollectionId): void {
    $data = get_option($this->optionName($oldCollectionId), null);
    if (is_array($data)) {
      update_option($this->optionName($newCollectionId), $data, false);
    }
    delete_option($this->optionName($oldCollectionId));
  }

  /**
   * @param array<string, array<string, mixed>> $records
   * @return array<int, array<string, mixed>>
   */
  public static function sortNewestFirst(array $records): array {
    $sorted = array_values($records);
    usort($sorted, function (array $left, array $right): int {
      // sortpubdate has the format "2023/11/16 00:00" and therefore sorts correctly as a string
      $byDate = strcmp((string) ($right['sortPubDate'] ?? ''), (string) ($left['sortPubDate'] ?? ''));
      if ($byDate !== 0) {
        return $byDate;
      }
      return (int) $right['pmid'] <=> (int) $left['pmid'];
    });
    return $sorted;
  }

  /**
   * @param array<string, mixed> $data
   * @return array<string, array<string, mixed>>
   */
  private function readRecords(array $data, string $key): array {
    $records = [];
    if (!isset($data[$key]) || !is_array($data[$key])) {
      return $records;
    }
    foreach ($data[$key] as $record) {
      if (is_array($record) && isset($record['pmid'])) {
        $records[(string) $record['pmid']] = $record;
      }
    }
    return $records;
  }

  private function optionName(string $collectionId): string {
    return self::OPTION_PREFIX . md5($collectionId);
  }

}
