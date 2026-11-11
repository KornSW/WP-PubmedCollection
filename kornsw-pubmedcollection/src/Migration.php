<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Converts the data of version 1.x (search filter + cached search result + exclusions) into the
 * locally maintained lists of version 2: the filter becomes the auto import query, the cached hits
 * become the list, the excluded hits the excluded list. Runs once on boot.
 */
final class Migration {

  private const SCHEMA_OPTION = 'kornsw_pmc_schema_version';

  private const CURRENT_SCHEMA = 2;

  private const LEGACY_CACHE_PREFIX = 'kornsw_pmc_cache_';

  private const LEGACY_REFRESH_HOOK = 'kornsw_pmc_refresh_collection';

  private ItemStore $itemStore;

  public function __construct(ItemStore $itemStore) {
    $this->itemStore = $itemStore;
  }

  public function run(): void {
    if ((int) get_option(self::SCHEMA_OPTION, 0) >= self::CURRENT_SCHEMA) {
      return;
    }

    // Read the raw option: the repository normalizes away the legacy fields
    $storedCollections = get_option(CollectionRepository::OPTION_NAME, []);
    if (is_array($storedCollections)) {
      $migratedCollections = [];
      foreach ($storedCollections as $collection) {
        if (!is_array($collection) || !isset($collection['id'])) {
          continue;
        }
        if (isset($collection['term']) && !isset($collection['autoImportQuery'])) {
          $collection = $this->migrateCollection($collection);
        }
        $migratedCollections[] = $collection;
      }
      update_option(CollectionRepository::OPTION_NAME, $migratedCollections);
    }

    wp_clear_scheduled_hook(self::LEGACY_REFRESH_HOOK);
    update_option(self::SCHEMA_OPTION, self::CURRENT_SCHEMA);
  }

  /**
   * @param array<string, mixed> $collection
   * @return array<string, mixed>
   */
  private function migrateCollection(array $collection): array {
    $collectionId = (string) $collection['id'];
    $legacyCacheOption = self::LEGACY_CACHE_PREFIX . md5($collectionId);

    $excludedPmids = [];
    if (isset($collection['excludedPmids']) && is_array($collection['excludedPmids'])) {
      $excludedPmids = array_flip(PubmedQuery::filterPmids($collection['excludedPmids']));
    }

    $data = $this->itemStore->load($collectionId);
    $cache = get_option($legacyCacheOption, null);
    if (is_array($cache) && isset($cache['publications']) && is_array($cache['publications'])) {
      foreach ($cache['publications'] as $publication) {
        if (!is_array($publication) || !isset($publication['pmid'])) {
          continue;
        }
        $pmid = (string) $publication['pmid'];
        $publication['origin'] = ItemStore::ORIGIN_MIGRATION;
        $publication['addedAt'] = time();
        if (isset($excludedPmids[$pmid])) {
          $publication['excludedAt'] = time();
          $data['excluded'][$pmid] = $publication;
        } else {
          $data['items'][$pmid] = $publication;
        }
      }
    }
    // The auto import continues from now on; everything older is already in the lists
    $data['autoImport']['lastPollAt'] = time();
    $this->itemStore->save($collectionId, $data);
    delete_option($legacyCacheOption);

    return [
      'id' => $collectionId,
      'autoImportQuery' => (string) $collection['term'],
      'autoImportEnabled' => true,
      'externalUrl' => (string) ($collection['externalUrl'] ?? '')
    ];
  }

}
