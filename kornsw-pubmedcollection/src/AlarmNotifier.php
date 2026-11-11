<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Mails to the site administrator: review notice for auto-imported publications, alarm when the
 * auto import fails, recovery, and a test mail.
 */
final class AlarmNotifier {

  /**
   * @param array<string, mixed> $collection
   * @param array<int, array<string, mixed>> $publications the newly imported publications
   * @return bool whether wp_mail() accepted the mail
   */
  public function sendImportNotice(array $collection, array $publications): bool {
    $collectionId = (string) $collection['id'];
    $lines = [
      'Der Auto-Import hat ' . count($publications) . ' neue Publikation(en) in die Collection "' . $collectionId . '" übernommen.',
      'Sie sind ab sofort auf der Website sichtbar. Bitte prüfen und unpassende Einträge ausschließen:',
      ''
    ];
    foreach ($publications as $publication) {
      $lines[] = '- ' . (string) $publication['title'];
      $lines[] = '  ' . Formatting::citation($publication) . ' | PMID ' . (string) $publication['pmid'];
      $lines[] = '  Prüfen: ' . $this->buildReviewUrl($collectionId, (string) $publication['pmid']);
      $lines[] = '';
    }
    $lines[] = 'Auto-Import-Query: ' . (string) $collection['autoImportQuery'];

    return $this->send(count($publications) . ' neue Publikation(en) in "' . $collectionId . '" - bitte prüfen', $lines);
  }

  /**
   * @param array<string, mixed> $collection
   * @param array<string, mixed> $autoImportState
   * @return bool whether wp_mail() accepted the mail
   */
  public function sendAlarm(array $collection, array $autoImportState): bool {
    $lines = [
      'Der Auto-Import des Plugins "PubMed-Collection" konnte PubMed nicht abfragen.',
      'Die Liste auf der Website ist davon nicht betroffen, es kommen nur keine neuen Einträge hinzu.',
      '',
      'Website:        ' . home_url('/'),
      'Collection:     ' . (string) $collection['id'],
      'Query:          ' . (string) $collection['autoImportQuery'],
      'Fehler:         ' . (string) $autoImportState['lastError'],
      'Gestört seit:   ' . Formatting::timestamp((int) $autoImportState['failingSince']),
      '',
      'Das Plugin versucht es automatisch erneut und meldet sich, sobald es wieder funktioniert.',
      'Diagnose: ' . admin_url('edit.php?page=kornsw-pubmedcollection&view=diagnostics')
    ];

    return $this->send('PubMed-Auto-Import gestört', $lines);
  }

  /**
   * @param array<string, mixed> $collection
   * @return bool whether wp_mail() accepted the mail
   */
  public function sendRecovery(array $collection): bool {
    $lines = [
      'Der Auto-Import von PubMed funktioniert wieder.',
      '',
      'Website:        ' . home_url('/'),
      'Collection:     ' . (string) $collection['id']
    ];

    return $this->send('PubMed-Auto-Import wieder in Ordnung', $lines);
  }

  /**
   * Test mail from the diagnostics page, to verify that mails can be delivered at all.
   */
  public function sendTest(): bool {
    return $this->send('Test-Mail', [
      'Dies ist eine Test-Mail des Plugins "PubMed-Collection".',
      'Wenn sie ankommt, erreichen Sie auch die Review- und Alarm-Mails.',
      '',
      'Website:        ' . home_url('/')
    ]);
  }

  /**
   * Recipient(s) of the mails; defaults to the site administrator address.
   *
   * @return string|string[]
   */
  public function getRecipient() {
    /**
     * Allows changing the recipient(s).
     */
    return apply_filters('kornsw_pmc_alarm_recipient', get_option('admin_email'));
  }

  /**
   * Link into the admin page that selects the publication in the list.
   */
  private function buildReviewUrl(string $collectionId, string $pmid): string {
    return add_query_arg([
      'page' => 'kornsw-pubmedcollection',
      'view' => 'edit',
      'collection' => $collectionId,
      'tab' => 'list',
      'pmid' => $pmid
    ], admin_url('edit.php'));
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

}
