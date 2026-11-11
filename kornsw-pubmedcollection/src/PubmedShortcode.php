<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection;

use Throwable;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Shortcode [include_pubmed collection="<internal id>" highlightAuthor="..."].
 * Renders the locally maintained list of the collection (configured under "Beiträge > PubMed (KornSW)");
 * a page view never queries PubMed.
 */
final class PubmedShortcode {

  private CollectionRepository $collections;

  private ItemStore $itemStore;

  public function __construct(CollectionRepository $collections, ItemStore $itemStore) {
    $this->collections = $collections;
    $this->itemStore = $itemStore;
  }

  /**
   * Shortcode callback. Whatever goes wrong, the page itself must never break.
   *
   * @param array<string, string>|string $attributes
   */
  public function render($attributes): string {
    try {
      return $this->renderCollection($attributes);
    } catch (Throwable $exception) {
      error_log('kornsw-pubmedcollection: shortcode failed: ' . $exception->getMessage());
      return $this->renderEditorHint('Interner Fehler beim Anzeigen der Liste: ' . $exception->getMessage());
    }
  }

  /**
   * @param array<string, string>|string $attributes
   */
  private function renderCollection($attributes): string {
    // shortcode_atts() matches keys case-sensitively, but WordPress lower-cases the given attribute names
    $attributes = array_change_key_case((array) $attributes, CASE_LOWER);
    $hasHighlightAttribute = isset($attributes['highlightauthor']);
    $attributes = shortcode_atts([
      'collection' => '',
      'highlightauthor' => ''
    ], $attributes, 'include_pubmed');

    $collectionId = trim((string) $attributes['collection']);
    $collection = null;
    if ($collectionId !== '') {
      $collection = $this->collections->find($collectionId);
    }
    if ($collection === null) {
      return $this->renderEditorHint('Die Collection "' . $collectionId . '" ist nicht angelegt. Bitte unter "Beiträge > PubMed (KornSW)" eine Collection mit dieser ID anlegen.');
    }

    // The shortcode attribute overrides the setting of the collection
    $highlightNames = CollectionRepository::highlightNames($collection);
    if ($hasHighlightAttribute) {
      $highlightNames = PubmedQuery::splitNames((string) $attributes['highlightauthor']);
    }

    $publications = ItemStore::sortNewestFirst($this->itemStore->load($collectionId)['items']);
    return $this->renderList($publications, CollectionRepository::resolveExternalUrl($collection), $highlightNames);
  }

  /**
   * @param array<int, array<string, mixed>> $publications
   * @param string[] $highlightNames
   */
  private function renderList(array $publications, string $externalUrl, array $highlightNames): string {
    // One pass with strtr(), so a replacement is never replaced again (and longer names win)
    $highlightReplacements = [];
    foreach ($highlightNames as $highlightName) {
      $escapedName = esc_html($highlightName);
      $highlightReplacements[$escapedName] = '<span style="color: blue"><b>' . $escapedName . '</b></span>';
    }

    $output = '<!-- OUTPUT OF PUBMED INCLUDE ' . esc_html(KORNSW_PMC_VERSION) . ' -->';
    if (count($publications) === 0) {
      return $output . $this->renderEditorHint('Die Collection enthält noch keine Einträge.');
    }

    $output .= '*Auszug aus dem <b>PubMed</b>-Register<br>(<a href="' . esc_url($externalUrl) . '" target="_blank" rel="noopener">https://pubmed.ncbi.nlm.nih.gov</a>):<br><br><hr>';

    foreach ($publications as $publication) {
      $link = 'https://pubmed.ncbi.nlm.nih.gov/' . rawurlencode((string) $publication['pmid']) . '/';

      $authors = 'N/A';
      if (count($publication['authors']) > 0) {
        $authors = esc_html(Formatting::authorList($publication));
        if (count($highlightReplacements) > 0) {
          $authors = strtr($authors, $highlightReplacements);
        }
      }

      $output .= '<br><a href="' . esc_url($link) . '" target="_blank" rel="noopener"><b>' . esc_html((string) $publication['title']) . '</b></a>'
        . '<br><span style="font-size: 11pt">' . esc_html(Formatting::citation($publication)) . '</span>'
        . '<br><span style="font-size: 8pt; display: inline-block;"><b>Authors:</b> ' . $authors . '</span><br>';
    }

    return $output;
  }

  /**
   * Visible to logged-in editors only.
   */
  private function renderEditorHint(string $message): string {
    if (!current_user_can('edit_posts')) {
      return '';
    }
    return '<p style="border: 1px solid #d63638; padding: 0.5em; color: #d63638; font-size: 9pt;">'
      . '<b>Hinweis (nur für Redakteure sichtbar):</b> ' . esc_html($message) . '</p>';
  }

}
