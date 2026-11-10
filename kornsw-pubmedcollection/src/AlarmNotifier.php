<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Informs the site administrator by mail when PubMed cannot be queried and when it works again.
 */
final class AlarmNotifier {

  /**
   * @param array<string, mixed> $collection
   * @param array<string, mixed> $cacheEntry
   */
  public function sendAlarm(array $collection, array $cacheEntry): void {
    $cachedCount = count($cacheEntry['publications']);
    $lines = [
      'Das Plugin "PubMed-Collection" konnte die Publikationsliste nicht von PubMed abrufen.',
      '',
      'Website:        ' . home_url('/'),
      'Seite:          ' . $this->describeCurrentPage(),
      'Collection:     ' . (string) $collection['id'],
      'Suchfilter:     ' . (string) $collection['term'],
      'Fehler:         ' . (string) $cacheEntry['lastError'],
      'Gestört seit:   ' . Formatting::timestamp((int) $cacheEntry['failingSince']),
      ''
    ];
    if ($cachedCount > 0) {
      $lines[] = 'Besucher sehen weiterhin die zwischengespeicherte Liste (' . $cachedCount . ' Einträge, Stand '
        . Formatting::timestamp((int) $cacheEntry['fetchedAt']) . ').';
    } else {
      $lines[] = 'Es gibt keine zwischengespeicherten Daten - die Liste ist für Besucher derzeit leer!';
    }
    $lines[] = '';
    $lines[] = 'Das Plugin versucht den Abruf automatisch erneut und meldet sich, sobald es wieder funktioniert.';
    $lines[] = 'Verwaltung: ' . admin_url('edit.php?page=kornsw-pubmedcollection');

    $this->send('PubMed-Abruf gestört', $lines);
  }

  /**
   * @param array<string, mixed> $collection
   * @param array<string, mixed> $cacheEntry
   */
  public function sendRecovery(array $collection, array $cacheEntry): void {
    $lines = [
      'Der Abruf der Publikationsliste von PubMed funktioniert wieder.',
      '',
      'Website:        ' . home_url('/'),
      'Collection:     ' . (string) $collection['id'],
      'Suchfilter:     ' . (string) $collection['term'],
      'Treffer:        ' . count($cacheEntry['publications'])
    ];

    $this->send('PubMed-Abruf wieder in Ordnung', $lines);
  }

  /**
   * @param string[] $lines
   */
  private function send(string $subject, array $lines): void {
    /**
     * Allows changing the alarm recipient(s); defaults to the site administrator address.
     */
    $recipient = apply_filters('kornsw_pmc_alarm_recipient', get_option('admin_email'));
    if (empty($recipient)) {
      return;
    }

    $fullSubject = '[' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . '] ' . $subject;
    if (!wp_mail($recipient, $fullSubject, implode("\n", $lines))) {
      error_log('kornsw-pubmedcollection: Alarm-Mail "' . $subject . '" konnte nicht versendet werden.');
    }
  }

  private function describeCurrentPage(): string {
    if (is_admin()) {
      return '(Verwaltung)';
    }
    $permalink = get_permalink();
    if (is_string($permalink) && $permalink !== '') {
      return $permalink;
    }
    return '(unbekannt)';
  }

}
