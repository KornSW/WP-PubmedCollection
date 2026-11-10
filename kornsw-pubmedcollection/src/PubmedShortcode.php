<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection;

use Throwable;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Shortcode [include_pubmed collection="<internal id>" highlightAuthor="..."].
 * The collection (search filter, link, exclusions) is configured under "Beiträge > PubMed (KornSW)".
 */
final class PubmedShortcode {

  private CollectionRepository $collections;

  private PublicationService $publications;

  public function __construct(CollectionRepository $collections, PublicationService $publications) {
    $this->collections = $collections;
    $this->publications = $publications;
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
    $attributes = shortcode_atts([
      'collection' => '',
      'highlightauthor' => 'Korn S'
    ], array_change_key_case((array) $attributes, CASE_LOWER), 'include_pubmed');

    $collectionId = trim((string) $attributes['collection']);
    $collection = null;
    if ($collectionId !== '') {
      $collection = $this->collections->find($collectionId);
    }
    if ($collection === null) {
      return $this->renderEditorHint('Die Collection "' . $collectionId . '" ist nicht angelegt. Bitte unter "Beiträge > PubMed (KornSW)" eine Collection mit dieser ID anlegen.');
    }

    // Never blocks the page view: an outdated list is refreshed in the background (WP-Cron)
    $entry = $this->publications->getCachedEntry($collection);

    $excludedPmids = array_flip($collection['excludedPmids']);
    $visiblePublications = [];
    foreach ($entry['publications'] as $publication) {
      if (!isset($excludedPmids[(string) $publication['pmid']])) {
        $visiblePublications[] = $publication;
      }
    }

    return $this->renderList($entry, $visiblePublications, CollectionRepository::resolveExternalUrl($collection), (string) $attributes['highlightauthor']);
  }

  /**
   * @param array<string, mixed> $entry
   * @param array<int, array<string, mixed>> $publications
   */
  private function renderList(array $entry, array $publications, string $externalUrl, string $highlightAuthor): string {
    $output = '<!-- OUTPUT OF PUBMED INCLUDE ' . esc_html(KORNSW_PMC_VERSION)
      . ' | Stand: ' . esc_html(Formatting::timestamp((int) $entry['fetchedAt'])) . ' -->';

    if ((int) $entry['failingSince'] > 0) {
      $output .= $this->renderEditorHint('PubMed ist seit ' . Formatting::timestamp((int) $entry['failingSince'])
        . ' nicht abrufbar, angezeigt wird der Stand vom ' . Formatting::timestamp((int) $entry['fetchedAt'])
        . '. Fehler: ' . (string) $entry['lastError']);
    }

    if ((int) $entry['fetchedAt'] === 0) {
      if ((int) $entry['failingSince'] > 0) {
        $output .= '<p>Die Publikationsliste ist derzeit nicht verfügbar.</p>';
      } else {
        $output .= $this->renderEditorHint('Die Liste wird gerade im Hintergrund von PubMed geladen (kann etwa eine Minute dauern) - bitte später neu laden.');
      }
      return $output;
    }

    $output .= '*Auszug aus dem <b>PubMed</b>-Register<br>(<a href="' . esc_url($externalUrl) . '" target="_blank" rel="noopener">https://pubmed.ncbi.nlm.nih.gov</a>):<br><br><hr>';

    foreach ($publications as $publication) {
      $link = 'https://pubmed.ncbi.nlm.nih.gov/' . rawurlencode((string) $publication['pmid']) . '/';

      $citation = trim((string) $publication['source'] . '. ' . (string) $publication['pubDate'], '. ');

      $authors = 'N/A';
      if (count($publication['authors']) > 0) {
        $authors = esc_html(Formatting::authorList($publication));
        if ($highlightAuthor !== '') {
          $escapedHighlight = esc_html($highlightAuthor);
          $authors = str_replace($escapedHighlight, '<span style="color: blue"><b>' . $escapedHighlight . '</b></span>', $authors);
        }
      }

      $output .= '<br><a href="' . esc_url($link) . '" target="_blank" rel="noopener"><b>' . esc_html((string) $publication['title']) . '</b></a>'
        . '<br><span style="font-size: 11pt">' . esc_html($citation) . '</span>'
        . '<br><span style="font-size: 8pt; display: inline-block;"><b>Authors:</b> ' . $authors . '</span><br>';
    }

    return $output;
  }

  /**
   * Visible to logged-in editors only, so problems are noticed without bothering visitors.
   */
  private function renderEditorHint(string $message): string {
    if (!current_user_can('edit_posts')) {
      return '';
    }
    return '<p style="border: 1px solid #d63638; padding: 0.5em; color: #d63638; font-size: 9pt;">'
      . '<b>Hinweis (nur für Redakteure sichtbar):</b> ' . esc_html($message) . '</p>';
  }

}
