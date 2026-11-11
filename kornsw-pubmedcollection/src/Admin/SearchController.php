<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection\Admin;

use KornSW\PubmedCollection\CollectionRepository;
use KornSW\PubmedCollection\EutilsClient;
use KornSW\PubmedCollection\Formatting;
use KornSW\PubmedCollection\ItemStore;
use KornSW\PubmedCollection\PubmedQuery;
use KornSW\PubmedCollection\RefreshLog;
use Throwable;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * AJAX endpoints of the tab "Manuell hinzufügen": live search in PubMed (newest 30 per page) and adding
 * selected hits to the list of a collection.
 */
final class SearchController {

  public const NONCE_ACTION = 'kornsw_pmc_ajax';

  public const PAGE_SIZE = 30;

  private const CAPABILITY = 'manage_options';

  private CollectionRepository $collections;

  private ItemStore $itemStore;

  private EutilsClient $client;

  private RefreshLog $log;

  public function __construct(CollectionRepository $collections, ItemStore $itemStore, EutilsClient $client, RefreshLog $log) {
    $this->collections = $collections;
    $this->itemStore = $itemStore;
    $this->client = $client;
    $this->log = $log;
  }

  public function register(): void {
    add_action('wp_ajax_kornsw_pmc_search', [$this, 'handleSearch']);
    add_action('wp_ajax_kornsw_pmc_add', [$this, 'handleAdd']);
  }

  /**
   * Runs the query and returns one page of hits with their status in the collection.
   */
  public function handleSearch(): void {
    $collectionId = $this->authorizeAndGetCollectionId();
    $term = trim((string) preg_replace('/\s+/', ' ', sanitize_textarea_field(wp_unslash($_POST['term'] ?? ''))));
    $offset = max(0, (int) ($_POST['offset'] ?? 0));
    if ($term === '') {
      wp_send_json_error(['message' => 'Bitte einen Suchbegriff eingeben.']);
    }

    try {
      $pmids = $this->client->searchPmids($term, self::PAGE_SIZE, ['retstart' => (string) $offset]);
      $searchInfo = $this->client->getLastSearchInfo();
      $publications = $this->client->fetchSummaries($pmids, $this->preferredAuthorNames($collectionId, $term));
    } catch (Throwable $exception) {
      wp_send_json_error(['message' => 'PubMed-Abfrage fehlgeschlagen: ' . $exception->getMessage()]);
    }

    $data = $this->itemStore->load($collectionId);
    $hits = [];
    foreach ($publications as $publication) {
      $pmid = (string) $publication['pmid'];
      $status = 'new';
      if (isset($data['items'][$pmid])) {
        $status = 'listed';
      } elseif (isset($data['excluded'][$pmid])) {
        $status = 'excluded';
      }
      $hits[] = [
        'pmid' => $pmid,
        'title' => (string) $publication['title'],
        'authors' => Formatting::authorList($publication),
        'citation' => Formatting::citation($publication),
        'status' => $status
      ];
    }

    $skippedPmids = $this->client->getSkippedPmids();
    wp_send_json_success([
      'totalCount' => (int) $searchInfo['totalCount'],
      'translation' => (string) $searchInfo['translation'],
      'warnings' => $searchInfo['warnings'],
      'offset' => $offset,
      'nextOffset' => $offset + count($pmids),
      'skipped' => array_keys($skippedPmids),
      'hits' => $hits
    ]);
  }

  /**
   * Adds the selected PMIDs to the list (loads their summaries again, the client data is not trusted).
   */
  public function handleAdd(): void {
    $collectionId = $this->authorizeAndGetCollectionId();
    $decoded = json_decode(wp_unslash($_POST['pmids'] ?? '[]'), true);
    $pmids = [];
    if (is_array($decoded)) {
      $pmids = PubmedQuery::filterPmids($decoded);
    }
    if (count($pmids) === 0) {
      wp_send_json_error(['message' => 'Keine Einträge ausgewählt.']);
    }

    try {
      $publications = $this->client->fetchSummaries($pmids, $this->preferredAuthorNames($collectionId, sanitize_textarea_field(wp_unslash($_POST['term'] ?? ''))));
    } catch (Throwable $exception) {
      wp_send_json_error(['message' => 'PubMed-Abfrage fehlgeschlagen: ' . $exception->getMessage()]);
    }

    $added = $this->itemStore->addPublications($collectionId, $publications, ItemStore::ORIGIN_MANUAL);
    $addedPmids = array_map(function (array $publication): string {
      return (string) $publication['pmid'];
    }, $added);
    $this->log->add($collectionId, RefreshLog::LEVEL_OK, count($added) . ' Einträge manuell übernommen: ' . implode(', ', $addedPmids));

    $data = $this->itemStore->load($collectionId);
    wp_send_json_success([
      'addedPmids' => $addedPmids,
      'failedPmids' => array_keys($this->client->getSkippedPmids()),
      'listCount' => count($data['items']),
      'excludedCount' => count($data['excluded'])
    ]);
  }

  /**
   * @return string[]
   */
  private function preferredAuthorNames(string $collectionId, string $term): array {
    $collection = $this->collections->find($collectionId);
    if ($collection === null) {
      return PubmedQuery::extractAuthorNames($term);
    }
    return CollectionRepository::preferredAuthorNames($collection, $term);
  }

  private function authorizeAndGetCollectionId(): string {
    check_ajax_referer(self::NONCE_ACTION);
    if (!current_user_can(self::CAPABILITY)) {
      wp_send_json_error(['message' => 'Keine Berechtigung.'], 403);
    }
    $collectionId = sanitize_text_field(wp_unslash($_POST['collection'] ?? ''));
    if ($this->collections->find($collectionId) === null) {
      wp_send_json_error(['message' => 'Unbekannte Collection.']);
    }
    return $collectionId;
  }

}
