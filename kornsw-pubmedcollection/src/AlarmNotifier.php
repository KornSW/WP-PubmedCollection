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
   * @return bool whether wp_mail() accepted the mail
   */
  public function sendAlarm(array $collection, array $cacheEntry): bool {
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

    return $this->send('PubMed-Abruf gestört', $lines);
  }

  /**
   * @param array<string, mixed> $collection
   * @param array<string, mixed> $cacheEntry
   * @return bool whether wp_mail() accepted the mail
   */
  public function sendRecovery(array $collection, array $cacheEntry): bool {
    $lines = [
      'Der Abruf der Publikationsliste von PubMed funktioniert wieder.',
      '',
      'Website:        ' . home_url('/'),
      'Collection:     ' . (string) $collection['id'],
      'Suchfilter:     ' . (string) $collection['term'],
      'Treffer:        ' . count($cacheEntry['publications'])
    ];

    return $this->send('PubMed-Abruf wieder in Ordnung', $lines);
  }

  /**
   * Test mail from the diagnostics page, to verify that alarms can be delivered at all.
   */
  public function sendTest(): bool {
    return $this->send('Test-Mail', [
      'Dies ist eine Test-Mail des Plugins "PubMed-Collection".',
      'Wenn sie ankommt, erreichen Sie auch die Alarm-Mails bei PubMed-Störungen.',
      '',
      'Website:        ' . home_url('/')
    ]);
  }

  /**
   * Recipient(s) of alarm mails; defaults to the site administrator address.
   *
   * @return string|string[]
   */
  public function getRecipient() {
    /**
     * Allows changing the alarm recipient(s).
     */
    return apply_filters('kornsw_pmc_alarm_recipient', get_option('admin_email'));
  }

  /**
   * @param string[] $lines
   */
  private function send(string $subject, array $lines): bool {
    $recipient = $this->getRecipient();
    if (empty($recipient)) {
      error_log('kornsw-pubmedcollection: keine Empfängeradresse für Mail "' . $subject . '".');
      return false;
    }

    $fullSubject = '[' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . '] ' . $subject;
    $wasSent = wp_mail($recipient, $fullSubject, implode("\n", $lines));
    if (!$wasSent) {
      error_log('kornsw-pubmedcollection: Mail "' . $subject . '" konnte nicht versendet werden.');
    }
    return $wasSent;
  }

  private function describeCurrentPage(): string {
    if (wp_doing_cron()) {
      return '(Hintergrund-Abruf)';
    }
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
