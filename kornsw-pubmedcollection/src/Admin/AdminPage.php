<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection\Admin;

use KornSW\PubmedCollection\AlarmNotifier;
use KornSW\PubmedCollection\CollectionRepository;
use KornSW\PubmedCollection\Formatting;
use KornSW\PubmedCollection\PublicationCache;
use KornSW\PubmedCollection\PublicationService;
use KornSW\PubmedCollection\RefreshLog;
use KornSW\PubmedCollection\Settings;
use RuntimeException;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Admin page "Beiträge > PubMed (KornSW)": manage collections (search filter, external link, exclusions)
 * and the optional NCBI API key.
 */
final class AdminPage {

  private const PAGE_SLUG = 'kornsw-pubmedcollection';

  private const CAPABILITY = 'manage_options';

  private const NOTICES = [
    'saved' => ['success', 'Collection gespeichert.'],
    'refreshed' => ['success', 'Collection gespeichert und neu von PubMed abgerufen.'],
    'refresh_failed' => ['error', 'Collection gespeichert, aber der Abruf von PubMed ist fehlgeschlagen (Details siehe unten).'],
    'deleted' => ['success', 'Collection gelöscht.'],
    'settings_saved' => ['success', 'Einstellungen gespeichert.'],
    'api_key_removed' => ['success', 'API-Key entfernt.'],
    'api_key_failed' => ['error', 'Der API-Key konnte nicht gespeichert werden (PHP-Erweiterung OpenSSL fehlt?).'],
    'invalid_id' => ['error', 'Ungültige ID: erlaubt sind 1-64 Zeichen aus Buchstaben, Ziffern, "-" und "_".'],
    'duplicate_id' => ['error', 'Es gibt bereits eine Collection mit dieser ID.'],
    'empty_term' => ['error', 'Der Suchfilter darf nicht leer sein.'],
    'not_found' => ['error', 'Die Collection wurde nicht gefunden.'],
    'test_mail_sent' => ['success', 'Test-Mail wurde an WordPress (wp_mail) übergeben - bitte Posteingang und Spam-Ordner prüfen.'],
    'test_mail_failed' => ['error', 'Test-Mail konnte NICHT versendet werden: wp_mail() meldet einen Fehler. Der Server kann vermutlich keine Mails verschicken (SMTP-Plugin nötig?).'],
    'log_cleared' => ['success', 'Protokoll geleert.']
  ];

  private CollectionRepository $collections;

  private Settings $settings;

  private PublicationService $publications;

  private PublicationCache $cache;

  private RefreshLog $log;

  private AlarmNotifier $notifier;

  private ConnectionDiagnostics $diagnostics;

  private string $hookSuffix = '';

  public function __construct(
    CollectionRepository $collections,
    Settings $settings,
    PublicationService $publications,
    PublicationCache $cache,
    RefreshLog $log,
    AlarmNotifier $notifier,
    ConnectionDiagnostics $diagnostics
  ) {
    $this->collections = $collections;
    $this->settings = $settings;
    $this->publications = $publications;
    $this->cache = $cache;
    $this->log = $log;
    $this->notifier = $notifier;
    $this->diagnostics = $diagnostics;
  }

  public function register(): void {
    add_action('admin_menu', [$this, 'addMenu']);
    add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    add_action('admin_post_kornsw_pmc_save_collection', [$this, 'handleSaveCollection']);
    add_action('admin_post_kornsw_pmc_delete_collection', [$this, 'handleDeleteCollection']);
    add_action('admin_post_kornsw_pmc_save_settings', [$this, 'handleSaveSettings']);
    add_action('admin_post_kornsw_pmc_test_mail', [$this, 'handleTestMail']);
    add_action('admin_post_kornsw_pmc_clear_log', [$this, 'handleClearLog']);
  }

  public function addMenu(): void {
    $hookSuffix = add_submenu_page('edit.php', 'PubMed (KornSW)', 'PubMed (KornSW)', self::CAPABILITY, self::PAGE_SLUG, [$this, 'render']);
    if (is_string($hookSuffix)) {
      $this->hookSuffix = $hookSuffix;
    }
  }

  public function enqueueAssets(string $hookSuffix): void {
    if ($hookSuffix !== $this->hookSuffix) {
      return;
    }
    wp_enqueue_style('kornsw-pmc-admin', KORNSW_PMC_URL . 'assets/admin.css', [], KORNSW_PMC_VERSION);
    wp_enqueue_script('kornsw-pmc-admin', KORNSW_PMC_URL . 'assets/admin.js', [], KORNSW_PMC_VERSION, true);
  }

  /**
   * Page callback: overview or edit view.
   */
  public function render(): void {
    if (!current_user_can(self::CAPABILITY)) {
      wp_die('Keine Berechtigung.');
    }

    echo '<div class="wrap kornsw-pmc">';
    echo '<h1>PubMed (KornSW)</h1>';
    $this->renderNotice();

    $view = sanitize_key(wp_unslash($_GET['view'] ?? ''));
    if ($view === 'edit') {
      $collectionId = sanitize_text_field(wp_unslash($_GET['collection'] ?? ''));
      if ($collectionId === '') {
        $this->renderEditView(null);
      } else {
        $collection = $this->collections->find($collectionId);
        if ($collection === null) {
          $this->renderNoticeBox('error', self::NOTICES['not_found'][1]);
          $this->renderOverview();
        } else {
          $this->renderEditView($collection);
        }
      }
    } elseif ($view === 'diagnostics') {
      $this->renderDiagnosticsView();
    } else {
      $this->renderOverview();
    }

    echo '</div>';
  }

  /**
   * Environment, on-demand connection tests, mail test and the refresh log.
   */
  private function renderDiagnosticsView(): void {
    $runTests = isset($_GET['run']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'kornsw_pmc_diagnostics') !== false;
    $runUrl = wp_nonce_url($this->pageUrl(['view' => 'diagnostics', 'run' => '1']), 'kornsw_pmc_diagnostics');
    $recipient = $this->notifier->getRecipient();
    if (is_array($recipient)) {
      $recipient = implode(', ', $recipient);
    }
    ?>
    <p><a href="<?php echo esc_url($this->pageUrl([])); ?>">&larr; Zurück zur Übersicht</a></p>
    <h2>Diagnose</h2>

    <h3>Umgebung</h3>
    <?php $this->renderDiagnosticsTable($this->diagnostics->describeEnvironment()); ?>

    <h3>Verbindung zu NCBI</h3>
    <p>
      <a class="button button-primary" href="<?php echo esc_url($runUrl); ?>">Verbindungstests ausführen</a>
      <span class="description">Prüft DNS, TCP, HTTPS (über WordPress sowie getrennt über IPv4 / IPv6) und Vergleichsziele. Dauert bei Zeitüberschreitungen bis zu einer Minute.</span>
    </p>
    <?php if ($runTests) { ?>
      <?php $this->renderDiagnosticsTable($this->diagnostics->runConnectionTests()); ?>
    <?php } ?>

    <h3>Alarm-Mail</h3>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="kornsw_pmc_test_mail">
      <?php wp_nonce_field('kornsw_pmc_test_mail'); ?>
      <p>
        Empfänger: <code><?php echo esc_html((string) $recipient); ?></code>
        <button type="submit" class="button">Test-Mail senden</button>
      </p>
    </form>

    <h3>Abruf-Protokoll (letzte 50 Einträge)</h3>
    <?php $this->renderLogTable(); ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="kornsw_pmc_clear_log">
      <?php wp_nonce_field('kornsw_pmc_clear_log'); ?>
      <p><button type="submit" class="button button-link-delete">Protokoll leeren</button></p>
    </form>
    <?php
  }

  /**
   * @param array<int, array{label: string, status: string, details: string}> $rows
   */
  private function renderDiagnosticsTable(array $rows): void {
    ?>
    <table class="widefat striped kornsw-pmc-diagnostics">
      <tbody>
        <?php foreach ($rows as $row) { ?>
          <tr class="status-<?php echo esc_attr($row['status']); ?>">
            <th scope="row"><?php echo esc_html($this->describeStatusSymbol($row['status']) . ' ' . $row['label']); ?></th>
            <td><pre><?php echo esc_html($row['details']); ?></pre></td>
          </tr>
        <?php } ?>
      </tbody>
    </table>
    <?php
  }

  private function renderLogTable(): void {
    $entries = $this->log->all();
    if (count($entries) === 0) {
      echo '<p><em>Noch keine Einträge.</em></p>';
      return;
    }
    ?>
    <table class="widefat striped kornsw-pmc-diagnostics">
      <thead>
        <tr>
          <th>Zeit</th>
          <th>Collection</th>
          <th>Auslöser</th>
          <th>Dauer</th>
          <th>Meldung</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($entries as $entry) { ?>
          <tr class="status-<?php echo esc_attr((string) $entry['level']); ?>">
            <td><?php echo esc_html($this->describeStatusSymbol((string) $entry['level']) . ' ' . wp_date('d.m.Y H:i:s', (int) $entry['time'])); ?></td>
            <td><?php echo esc_html((string) $entry['collection']); ?></td>
            <td><?php echo esc_html((string) $entry['trigger']); ?></td>
            <td><?php echo esc_html(number_format((float) $entry['duration'], 1, ',', '') . ' s'); ?></td>
            <td><?php echo esc_html((string) $entry['message']); ?></td>
          </tr>
        <?php } ?>
      </tbody>
    </table>
    <?php
  }

  private function describeStatusSymbol(string $status): string {
    if ($status === ConnectionDiagnostics::STATUS_OK) {
      return '✔';
    }
    if ($status === ConnectionDiagnostics::STATUS_ERROR) {
      return '✘';
    }
    return '•';
  }

  public function handleTestMail(): void {
    $this->assertCapability();
    check_admin_referer('kornsw_pmc_test_mail');

    $notice = 'test_mail_failed';
    if ($this->notifier->sendTest()) {
      $notice = 'test_mail_sent';
    }
    $this->redirect(['view' => 'diagnostics'], $notice);
  }

  public function handleClearLog(): void {
    $this->assertCapability();
    check_admin_referer('kornsw_pmc_clear_log');

    $this->log->clear();
    $this->redirect(['view' => 'diagnostics'], 'log_cleared');
  }

  private function renderOverview(): void {
    $collections = $this->collections->all();
    ?>
    <p>
      Collections werden per Shortcode eingebunden: <code>[include_pubmed collection="&lt;ID&gt;"]</code>
      (optional <code>highlightAuthor="Korn S"</code>).
      <a class="page-title-action" href="<?php echo esc_url($this->pageUrl(['view' => 'edit'])); ?>">Neue Collection anlegen</a>
      <a class="page-title-action" href="<?php echo esc_url($this->pageUrl(['view' => 'diagnostics'])); ?>">Diagnose &amp; Protokoll</a>
    </p>
    <table class="widefat striped">
      <thead>
        <tr>
          <th>ID</th>
          <th>Shortcode</th>
          <th>Suchfilter</th>
          <th>Externer Link</th>
          <th>Ausgeschlossen</th>
          <th>Stand</th>
        </tr>
      </thead>
      <tbody>
        <?php if (count($collections) === 0) { ?>
          <tr><td colspan="6">Noch keine Collections angelegt.</td></tr>
        <?php } ?>
        <?php foreach ($collections as $collection) { ?>
          <?php $editUrl = $this->pageUrl(['view' => 'edit', 'collection' => $collection['id']]); ?>
          <tr>
            <td><strong><a href="<?php echo esc_url($editUrl); ?>"><?php echo esc_html($collection['id']); ?></a></strong></td>
            <td><code>[include_pubmed collection="<?php echo esc_html($collection['id']); ?>"]</code></td>
            <td><code><?php echo esc_html($collection['term']); ?></code></td>
            <td>
              <?php if ($collection['externalUrl'] === '') { ?>
                <em>PubMed-Suche</em>
              <?php } else { ?>
                <a href="<?php echo esc_url($collection['externalUrl']); ?>" target="_blank" rel="noopener"><?php echo esc_html($collection['externalUrl']); ?></a>
              <?php } ?>
            </td>
            <td><?php echo esc_html((string) count($collection['excludedPmids'])); ?></td>
            <td><?php echo $this->describeEntryStatus($this->publications->peekEntry($collection)); ?></td>
          </tr>
        <?php } ?>
      </tbody>
    </table>

    <h2>Einstellungen</h2>
    <?php $this->renderSettingsForm(); ?>
    <?php
  }

  private function renderSettingsForm(): void {
    $hasApiKey = $this->settings->hasApiKey();
    $placeholder = '';
    if ($hasApiKey) {
      $placeholder = '•••••••• (hinterlegt - leer lassen zum Beibehalten)';
      if ($this->settings->getApiKey() === '') {
        $this->renderNoticeBox('warning', 'Der hinterlegte API-Key kann nicht mehr entschlüsselt werden (wurden die WordPress-Salts geändert?). Bitte neu eingeben.');
      }
    }
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="kornsw_pmc_save_settings">
      <?php wp_nonce_field('kornsw_pmc_save_settings'); ?>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="kornsw-pmc-cache-hours">Cachedauer</label></th>
          <td>
            <input type="number" id="kornsw-pmc-cache-hours" name="cache_hours" min="1" max="<?php echo esc_attr((string) Settings::MAX_CACHE_HOURS); ?>" value="<?php echo esc_attr((string) $this->settings->getCacheHours()); ?>"> Stunden
            <p class="description">
              Gilt für alle Collections (Standard: <?php echo esc_html((string) Settings::DEFAULT_CACHE_HOURS); ?>). Ist die Liste älter, löst der nächste Seitenaufruf eine Aktualisierung im Hintergrund aus -
              Besucher sehen bis dahin den bisherigen Stand. Ist PubMed nicht erreichbar, bleibt der bisherige Stand unbegrenzt erhalten.
            </p>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="kornsw-pmc-api-key">NCBI-API-Key</label></th>
          <td>
            <input type="password" id="kornsw-pmc-api-key" name="api_key" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr($placeholder); ?>">
            <?php if ($hasApiKey) { ?>
              <label><input type="checkbox" name="remove_api_key" value="1"> API-Key entfernen</label>
            <?php } ?>
            <p class="description">
              Optional, gilt für alle Collections. Erhöht das NCBI-Limit von 3 auf 10 Anfragen pro Sekunde.
              Erhältlich im NCBI-Konto unter <a href="https://account.ncbi.nlm.nih.gov/settings/" target="_blank" rel="noopener">Account Settings &gt; API Key Management</a>.
              Wird verschlüsselt gespeichert.
            </p>
          </td>
        </tr>
        <tr>
          <th scope="row">Verbindung</th>
          <td>
            <label><input type="checkbox" name="force_ipv4" value="1"<?php checked($this->settings->getForceIpv4()); ?>> Nur IPv4 für NCBI-Verbindungen verwenden</label>
            <p class="description">Abhilfe, wenn die IPv6-Anbindung des Servers gestört ist (siehe <a href="<?php echo esc_url($this->pageUrl(['view' => 'diagnostics'])); ?>">Diagnose</a>).</p>
          </td>
        </tr>
      </table>
      <?php submit_button('Einstellungen speichern'); ?>
    </form>
    <?php
  }

  /**
   * @param array<string, mixed>|null $collection null = new collection
   */
  private function renderEditView(?array $collection): void {
    $isNew = $collection === null;
    if ($collection === null) {
      $collection = $this->collections->createEmpty();
    }
    $title = 'Collection bearbeiten: ' . $collection['id'];
    if ($isNew) {
      $title = 'Neue Collection';
    }
    ?>
    <p><a href="<?php echo esc_url($this->pageUrl([])); ?>">&larr; Zurück zur Übersicht</a></p>
    <h2><?php echo esc_html($title); ?></h2>

    <form id="kornsw-pmc-collection-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="kornsw_pmc_save_collection">
      <input type="hidden" name="original_id" value="<?php echo esc_attr($collection['id']); ?>">
      <input type="hidden" name="exclusions_submitted" value="0">
      <input type="hidden" name="exclusions_json" value="">
      <?php wp_nonce_field('kornsw_pmc_save_collection'); ?>

      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="kornsw-pmc-id">ID</label></th>
          <td>
            <input type="text" id="kornsw-pmc-id" name="collection_id" class="regular-text" required maxlength="64" pattern="[A-Za-z0-9_\-]+" value="<?php echo esc_attr($collection['id']); ?>">
            <p class="description">
              Interner Bezeichner für den Shortcode <code>[include_pubmed collection="…"]</code>. Frei wählbar (Buchstaben, Ziffern, "-" und "_").
              Wählt man die ID einer bisherigen PubMed-Collection (z. B. <code>64421242</code>), funktionieren bestehende Shortcodes unverändert weiter.
              <?php if (!$isNew) { ?><br><strong>Achtung:</strong> Eine Änderung der ID macht Shortcodes mit der alten ID wirkungslos.<?php } ?>
            </p>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="kornsw-pmc-term">Suchfilter</label></th>
          <td>
            <textarea id="kornsw-pmc-term" name="term" class="large-text code" rows="3" required><?php echo esc_textarea($collection['term']); ?></textarea>
            <?php if ($collection['term'] !== '') { ?>
              <p><a href="<?php echo esc_url(CollectionRepository::buildSearchUrl($collection['term'])); ?>" target="_blank" rel="noopener">Filter in PubMed ausprobieren &#8599;</a></p>
            <?php } ?>
            <?php $this->renderSearchLegend(); ?>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="kornsw-pmc-url">Externer Link</label></th>
          <td>
            <input type="url" id="kornsw-pmc-url" name="external_url" class="large-text" value="<?php echo esc_attr($collection['externalUrl']); ?>" placeholder="https://pubmed.ncbi.nlm.nih.gov/collections/64421242/?sort=pubdate">
            <p class="description">Optional, z. B. die URL der PubMed-Collection. Wird über der Liste verlinkt (öffnet in neuem Fenster). Leer = Link auf die PubMed-Suche mit obigem Filter.</p>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="kornsw-pmc-max">Max. Treffer</label></th>
          <td>
            <input type="number" id="kornsw-pmc-max" name="max_results" min="1" max="<?php echo esc_attr((string) CollectionRepository::MAX_RESULTS_LIMIT); ?>" value="<?php echo esc_attr((string) $collection['maxResults']); ?>">
            <p class="description">Die neuesten Treffer bis zu dieser Anzahl werden abgerufen (vor dem Ausschließen).</p>
          </td>
        </tr>
      </table>

      <p class="submit">
        <button type="submit" name="submit_action" value="save" class="button button-primary">Speichern</button>
        <button type="submit" name="submit_action" value="refresh" class="button">Speichern &amp; neu von PubMed abrufen</button>
      </p>

      <?php if (!$isNew) { ?>
        <?php $this->renderHitList($collection); ?>
      <?php } ?>
    </form>

    <?php if (!$isNew) { ?>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Collection wirklich löschen? Shortcodes mit dieser ID zeigen danach nichts mehr an.');">
        <input type="hidden" name="action" value="kornsw_pmc_delete_collection">
        <input type="hidden" name="collection_id" value="<?php echo esc_attr($collection['id']); ?>">
        <?php wp_nonce_field('kornsw_pmc_delete_collection'); ?>
        <p><button type="submit" class="button button-link-delete">Collection löschen</button></p>
      </form>
    <?php } ?>
    <?php
  }

  private function renderSearchLegend(): void {
    ?>
    <details class="kornsw-pmc-legend">
      <summary>Legende: Wie schreibt man den Suchfilter?</summary>
      <p>Der Filter ist eine normale PubMed-Suchanfrage. Feld-Kürzel stehen in eckigen Klammern direkt hinter dem Begriff:</p>
      <table>
        <tr><td><code>Korn S[au]</code></td><td>Autor: Nachname + Initialen (findet auch Namensvettern)</td></tr>
        <tr><td><code>"Mustermann Max"[au]</code></td><td>Autor mit vollem Vornamen (zuverlässig erst ab ca. 2002)</td></tr>
        <tr><td><code>Korn S[1au]</code> / <code>Korn S[lastau]</code></td><td>nur als Erst- bzw. Letztautor</td></tr>
        <tr><td><code>Mainz[ad]</code></td><td>Affiliation (Institution, Ort) der Autoren</td></tr>
        <tr><td><code>2015:3000[dp]</code></td><td>Publikationsdatum (Zeitraum von:bis)</td></tr>
        <tr><td><code>cardiology[ti]</code> / <code>stent[tiab]</code></td><td>Wort im Titel / in Titel oder Abstract</td></tr>
        <tr><td><code>"J Clin Med"[ta]</code></td><td>Journal (Kurztitel)</td></tr>
        <tr><td><code>Review[pt]</code></td><td>Publikationstyp, z. B. <code>NOT Erratum[pt]</code></td></tr>
        <tr><td><code>12345678[pmid]</code></td><td>einzelne Publikation per PMID</td></tr>
        <tr><td><code>AND</code>, <code>OR</code>, <code>NOT</code></td><td>Verknüpfungen - immer in GROSSBUCHSTABEN</td></tr>
        <tr><td><code>( … )</code>, <code>"…"</code>, <code>cardio*</code></td><td>Klammern gruppieren, Anführungszeichen für Phrasen, <code>*</code> als Platzhalter</td></tr>
      </table>
      <p>Beispiel: <code>Korn S[au] AND (Mainz[ad] OR Wiesbaden[ad]) NOT Erratum[pt]</code><br>
        Einzelne Publikationen ergänzen: <code>(Korn S[au] AND Mainz[ad]) OR 12345678[pmid] OR 23456789[pmid]</code><br>
        Unpassende Treffer lassen sich unten einzeln ausschließen.
        Vollständige Liste der Feld-Kürzel: <a href="https://pubmed.ncbi.nlm.nih.gov/help/#search-tags" target="_blank" rel="noopener">PubMed-Hilfe &#8599;</a></p>
    </details>
    <?php
  }

  /**
   * Compact list of all hits (including excluded ones) with exclude checkboxes and keyword highlighting.
   *
   * @param array<string, mixed> $collection
   */
  private function renderHitList(array $collection): void {
    // May query PubMed (if the cache is outdated or the filter was changed)
    $entry = $this->publications->getEntry($collection);
    $excludedPmids = array_flip($collection['excludedPmids']);
    ?>
    <h2>Suchtreffer</h2>
    <?php if ((int) $entry['failingSince'] > 0) { ?>
      <?php $this->renderNoticeBox('error', 'PubMed ist seit ' . Formatting::timestamp((int) $entry['failingSince']) . ' nicht abrufbar: ' . (string) $entry['lastError'] . ' - angezeigt wird der Stand vom ' . Formatting::timestamp((int) $entry['fetchedAt']) . '.'); ?>
      <p><a class="button" href="<?php echo esc_url($this->pageUrl(['view' => 'diagnostics'])); ?>">Ursache untersuchen: Diagnose &amp; Protokoll</a></p>
    <?php } ?>
    <p>
      <?php echo $this->describeEntryStatus($entry); ?>
      <?php if (count($entry['publications']) > 0) { ?>
        - davon <span id="kornsw-pmc-excluded-count"><?php echo esc_html((string) count(array_intersect(array_column($entry['publications'], 'pmid'), $collection['excludedPmids']))); ?></span> ausgeschlossen.
      <?php } ?>
    </p>
    <?php $this->renderSearchInfo($entry, (int) $collection['maxResults']); ?>
    <?php if (count($entry['publications']) === 0) { ?>
      <p>Keine Treffer.</p>
      <?php return; ?>
    <?php } ?>
    <p class="kornsw-pmc-toolbar">
      <input type="search" id="kornsw-pmc-highlight" class="regular-text" placeholder="Stichworte hervorheben (Titel/Autoren), z. B. Korn Mainz">
      <span id="kornsw-pmc-highlight-count"></span>
    </p>
    <table class="kornsw-pmc-hits">
      <thead>
        <tr>
          <th class="col-exclude" title="Ausschließen">Aus</th>
          <th>Titel</th>
          <th>Autoren</th>
          <th>Journal / Datum</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($entry['publications'] as $publication) { ?>
          <?php
          $pmid = (string) $publication['pmid'];
          $isExcluded = isset($excludedPmids[$pmid]);
          $rowClass = '';
          if ($isExcluded) {
            $rowClass = 'is-excluded';
          }
          ?>
          <tr class="<?php echo esc_attr($rowClass); ?>" data-pmid="<?php echo esc_attr($pmid); ?>">
            <td class="col-exclude"><input type="checkbox" class="kornsw-pmc-exclude" value="<?php echo esc_attr($pmid); ?>" title="PMID <?php echo esc_attr($pmid); ?> ausschließen"<?php checked($isExcluded); ?>></td>
            <td><a href="<?php echo esc_url('https://pubmed.ncbi.nlm.nih.gov/' . rawurlencode($pmid) . '/'); ?>" target="_blank" rel="noopener"><span class="kornsw-pmc-hl"><?php echo esc_html((string) $publication['title']); ?></span></a></td>
            <td class="authors"><span class="kornsw-pmc-hl"><?php echo esc_html(Formatting::authorList($publication)); ?></span></td>
            <td class="citation"><?php echo esc_html(trim((string) $publication['source'] . '. ' . (string) $publication['pubDate'], '. ')); ?></td>
          </tr>
        <?php } ?>
      </tbody>
    </table>
    <p class="submit">
      <button type="submit" name="submit_action" value="save" class="button button-primary">Speichern</button>
    </p>
    <?php
  }

  /**
   * Shows how PubMed interpreted the filter, e.g. to see why a NOT does not take effect.
   *
   * @param array<string, mixed> $entry
   */
  private function renderSearchInfo(array $entry, int $maxResults): void {
    $searchInfo = $entry['searchInfo'] ?? [];
    if (!is_array($searchInfo) || (string) ($searchInfo['translation'] ?? '') === '') {
      return;
    }
    $totalCount = (int) ($searchInfo['totalCount'] ?? 0);
    $warnings = [];
    if (isset($searchInfo['warnings']) && is_array($searchInfo['warnings'])) {
      $warnings = $searchInfo['warnings'];
    }
    ?>
    <div class="kornsw-pmc-searchinfo">
      <p>
        <strong>So hat PubMed den Filter verstanden</strong> (<?php echo esc_html((string) $totalCount); ?> Treffer insgesamt<?php if ($totalCount > $maxResults) { ?>, geladen werden die neuesten <?php echo esc_html((string) $maxResults); ?><?php } ?>):
      </p>
      <pre><?php echo esc_html((string) $searchInfo['translation']); ?></pre>
      <?php foreach ($warnings as $warning) { ?>
        <p class="kornsw-pmc-error">&#9888; PubMed meldet: <?php echo esc_html((string) $warning); ?></p>
      <?php } ?>
      <p class="description">Verknüpfungen müssen <code>AND</code> / <code>OR</code> / <code>NOT</code> heißen (komplett groß) - gemischte Schreibweisen wie "Not" behandelt PubMed als Suchwort und verknüpft sie mit AND.</p>
    </div>
    <?php
  }

  public function handleSaveCollection(): void {
    $this->assertCapability();
    check_admin_referer('kornsw_pmc_save_collection');

    $originalId = sanitize_text_field(wp_unslash($_POST['original_id'] ?? ''));
    $collectionId = trim(sanitize_text_field(wp_unslash($_POST['collection_id'] ?? '')));
    // Line breaks are only for readability in the textarea
    $term = trim((string) preg_replace('/\s+/', ' ', sanitize_textarea_field(wp_unslash($_POST['term'] ?? ''))));
    $externalUrl = esc_url_raw(trim(wp_unslash($_POST['external_url'] ?? '')), ['http', 'https']);
    $maxResults = (int) ($_POST['max_results'] ?? CollectionRepository::DEFAULT_MAX_RESULTS);
    $submitAction = sanitize_key(wp_unslash($_POST['submit_action'] ?? 'save'));

    $backToForm = ['view' => 'edit', 'collection' => $originalId];
    if (!CollectionRepository::isValidId($collectionId)) {
      $this->redirect($backToForm, 'invalid_id');
    }
    if ($term === '') {
      $this->redirect($backToForm, 'empty_term');
    }
    if ($collectionId !== $originalId && $this->collections->find($collectionId) !== null) {
      $this->redirect($backToForm, 'duplicate_id');
    }

    $excludedPmids = [];
    if ($originalId !== '') {
      $existing = $this->collections->find($originalId);
      if ($existing !== null) {
        $excludedPmids = $existing['excludedPmids'];
      }
    }
    if (($_POST['exclusions_submitted'] ?? '') === '1') {
      $excludedPmids = $this->mergeExclusions($excludedPmids, wp_unslash($_POST['exclusions_json'] ?? ''));
    }

    $collection = [
      'id' => $collectionId,
      'term' => $term,
      'externalUrl' => $externalUrl,
      'maxResults' => $maxResults,
      'excludedPmids' => $excludedPmids
    ];
    $this->collections->save($collection, $originalId);
    if ($originalId !== '' && $originalId !== $collectionId) {
      $this->cache->rename($originalId, $collectionId);
    }

    $notice = 'saved';
    if ($submitAction === 'refresh') {
      $savedCollection = $this->collections->find($collectionId);
      if ($savedCollection !== null) {
        $entry = $this->publications->getEntry($savedCollection, true);
        $notice = 'refreshed';
        if ((int) $entry['failingSince'] > 0) {
          $notice = 'refresh_failed';
        }
      }
    }

    $this->redirect(['view' => 'edit', 'collection' => $collectionId], $notice);
  }

  public function handleDeleteCollection(): void {
    $this->assertCapability();
    check_admin_referer('kornsw_pmc_delete_collection');

    $collectionId = sanitize_text_field(wp_unslash($_POST['collection_id'] ?? ''));
    $this->collections->delete($collectionId);
    $this->cache->delete($collectionId);

    $this->redirect([], 'deleted');
  }

  public function handleSaveSettings(): void {
    $this->assertCapability();
    check_admin_referer('kornsw_pmc_save_settings');

    if (isset($_POST['cache_hours'])) {
      $this->settings->saveCacheHours((int) $_POST['cache_hours']);
    }
    $this->settings->saveForceIpv4(($_POST['force_ipv4'] ?? '') === '1');

    if (($_POST['remove_api_key'] ?? '') === '1') {
      $this->settings->removeApiKey();
      $this->redirect([], 'api_key_removed');
    }

    // An empty field keeps the stored key
    $apiKey = trim(sanitize_text_field(wp_unslash($_POST['api_key'] ?? '')));
    if ($apiKey !== '') {
      try {
        $this->settings->saveApiKey($apiKey);
      } catch (RuntimeException $exception) {
        $this->redirect([], 'api_key_failed');
      }
    }

    $this->redirect([], 'settings_saved');
  }

  /**
   * Applies the checkbox states of the displayed hits; exclusions of hits that are not displayed are kept.
   *
   * @param string[] $existingExclusions
   * @return string[]
   */
  private function mergeExclusions(array $existingExclusions, string $json): array {
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || !isset($decoded['displayed'], $decoded['excluded']) || !is_array($decoded['displayed']) || !is_array($decoded['excluded'])) {
      return $existingExclusions;
    }
    $displayed = $this->filterPmids($decoded['displayed']);
    $checked = $this->filterPmids($decoded['excluded']);
    return array_values(array_unique(array_merge(array_diff($existingExclusions, $displayed), $checked)));
  }

  /**
   * @param array<mixed> $values
   * @return string[]
   */
  private function filterPmids(array $values): array {
    $pmids = [];
    foreach ($values as $value) {
      $value = (string) $value;
      if (ctype_digit($value)) {
        $pmids[] = $value;
      }
    }
    return $pmids;
  }

  /**
   * @param array<string, mixed>|null $entry
   */
  private function describeEntryStatus(?array $entry): string {
    if ($entry === null || (int) $entry['fetchedAt'] === 0) {
      if ($entry !== null && (int) $entry['failingSince'] > 0) {
        return '<span class="kornsw-pmc-error">Abruf fehlgeschlagen</span>';
      }
      return '<em>noch nicht abgerufen</em>';
    }
    $status = esc_html(count($entry['publications']) . ' Treffer, Stand ' . Formatting::timestamp((int) $entry['fetchedAt']));
    if ((int) $entry['failingSince'] > 0) {
      $status .= ' <span class="kornsw-pmc-error">(PubMed gestört seit ' . esc_html(Formatting::timestamp((int) $entry['failingSince'])) . ')</span>';
    }
    return $status;
  }

  private function renderNotice(): void {
    $code = sanitize_key(wp_unslash($_GET['kornsw_pmc_notice'] ?? ''));
    if (isset(self::NOTICES[$code])) {
      $this->renderNoticeBox(self::NOTICES[$code][0], self::NOTICES[$code][1]);
    }
  }

  private function renderNoticeBox(string $type, string $message): void {
    echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($message) . '</p></div>';
  }

  private function assertCapability(): void {
    if (!current_user_can(self::CAPABILITY)) {
      wp_die('Keine Berechtigung.', '', ['response' => 403]);
    }
  }

  /**
   * @param array<string, string> $arguments
   */
  private function pageUrl(array $arguments): string {
    return add_query_arg(array_merge(['page' => self::PAGE_SLUG], $arguments), admin_url('edit.php'));
  }

  /**
   * @param array<string, string> $arguments
   * @return never
   */
  private function redirect(array $arguments, string $notice): void {
    $arguments['kornsw_pmc_notice'] = $notice;
    wp_safe_redirect($this->pageUrl($arguments));
    exit;
  }

}
