<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection\Admin;

use KornSW\PubmedCollection\AlarmNotifier;
use KornSW\PubmedCollection\AutoImporter;
use KornSW\PubmedCollection\CollectionRepository;
use KornSW\PubmedCollection\Formatting;
use KornSW\PubmedCollection\ItemStore;
use KornSW\PubmedCollection\PubmedQuery;
use KornSW\PubmedCollection\RefreshLog;
use KornSW\PubmedCollection\Settings;
use RuntimeException;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Admin page "Beiträge > PubMed (KornSW)": collections with the tabs list / excluded / manual add / settings,
 * plugin settings and the diagnostics page.
 */
final class AdminPage {

  private const PAGE_SLUG = 'kornsw-pubmedcollection';

  private const CAPABILITY = 'manage_options';

  private const TABS = [
    'list' => 'Liste',
    'excluded' => 'Ausgeschlossen',
    'add' => 'Manuell hinzufügen',
    'settings' => 'Einstellungen'
  ];

  private const NOTICES = [
    'saved' => ['success', 'Collection gespeichert.'],
    'deleted' => ['success', 'Collection gelöscht.'],
    'items_excluded' => ['success', 'Die ausgewählten Einträge wurden ausgeschlossen (Tab "Ausgeschlossen").'],
    'items_restored' => ['success', 'Die ausgewählten Einträge sind wieder in der Liste.'],
    'nothing_selected' => ['warning', 'Es war kein Eintrag ausgewählt.'],
    'import_done' => ['success', 'Auto-Import ausgeführt - Ergebnis siehe unten.'],
    'import_failed' => ['error', 'Auto-Import fehlgeschlagen - Details siehe unten.'],
    'settings_saved' => ['success', 'Einstellungen gespeichert.'],
    'api_key_removed' => ['success', 'API-Key entfernt.'],
    'api_key_failed' => ['error', 'Der API-Key konnte nicht gespeichert werden (PHP-Erweiterung OpenSSL fehlt?).'],
    'invalid_id' => ['error', 'Ungültige ID: erlaubt sind 1-64 Zeichen aus Buchstaben, Ziffern, "-" und "_".'],
    'duplicate_id' => ['error', 'Es gibt bereits eine Collection mit dieser ID.'],
    'empty_query' => ['error', 'Für den Auto-Import wird eine Query benötigt (oder den Auto-Import deaktivieren).'],
    'not_found' => ['error', 'Die Collection wurde nicht gefunden.'],
    'test_mail_sent' => ['success', 'Test-Mail wurde an WordPress (wp_mail) übergeben - bitte Posteingang und Spam-Ordner prüfen.'],
    'test_mail_failed' => ['error', 'Test-Mail konnte NICHT versendet werden: wp_mail() meldet einen Fehler. Der Server kann vermutlich keine Mails verschicken (SMTP-Plugin nötig?).'],
    'log_cleared' => ['success', 'Protokoll geleert.']
  ];

  private CollectionRepository $collections;

  private ItemStore $itemStore;

  private Settings $settings;

  private AutoImporter $autoImporter;

  private RefreshLog $log;

  private AlarmNotifier $notifier;

  private ConnectionDiagnostics $diagnostics;

  private string $hookSuffix = '';

  public function __construct(
    CollectionRepository $collections,
    ItemStore $itemStore,
    Settings $settings,
    AutoImporter $autoImporter,
    RefreshLog $log,
    AlarmNotifier $notifier,
    ConnectionDiagnostics $diagnostics
  ) {
    $this->collections = $collections;
    $this->itemStore = $itemStore;
    $this->settings = $settings;
    $this->autoImporter = $autoImporter;
    $this->log = $log;
    $this->notifier = $notifier;
    $this->diagnostics = $diagnostics;
  }

  public function register(): void {
    add_action('admin_menu', [$this, 'addMenu']);
    add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    add_action('admin_post_kornsw_pmc_save_collection', [$this, 'handleSaveCollection']);
    add_action('admin_post_kornsw_pmc_delete_collection', [$this, 'handleDeleteCollection']);
    add_action('admin_post_kornsw_pmc_exclude', [$this, 'handleExclude']);
    add_action('admin_post_kornsw_pmc_restore', [$this, 'handleRestore']);
    add_action('admin_post_kornsw_pmc_import_now', [$this, 'handleImportNow']);
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
    wp_localize_script('kornsw-pmc-admin', 'kornswPmcConfig', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce(SearchController::NONCE_ACTION),
      'collectionId' => sanitize_text_field(wp_unslash($_GET['collection'] ?? '')),
      'pageSize' => SearchController::PAGE_SIZE
    ]);
  }

  /**
   * Page callback: overview, collection (tabs) or diagnostics.
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
        $this->renderCollectionView(null);
      } else {
        $collection = $this->collections->find($collectionId);
        if ($collection === null) {
          $this->renderNoticeBox('error', self::NOTICES['not_found'][1]);
          $this->renderOverview();
        } else {
          $this->renderCollectionView($collection);
        }
      }
    } elseif ($view === 'diagnostics') {
      $this->renderDiagnosticsView();
    } else {
      $this->renderOverview();
    }

    echo '</div>';
  }

  /* ------------------------------------------------------------------ overview */

  private function renderOverview(): void {
    $collections = $this->collections->all();
    ?>
    <p>
      Collections werden per Shortcode eingebunden: <code>[include_pubmed collection="&lt;ID&gt;"]</code>
      (optional <code>highlightAuthor="…"</code>, überschreibt den hervorgehobenen Autor der Collection).
      <a class="page-title-action" href="<?php echo esc_url($this->pageUrl(['view' => 'edit', 'tab' => 'settings'])); ?>">Neue Collection anlegen</a>
      <a class="page-title-action" href="<?php echo esc_url($this->pageUrl(['view' => 'diagnostics'])); ?>">Diagnose &amp; Protokoll</a>
    </p>
    <table class="widefat striped">
      <thead>
        <tr>
          <th>ID</th>
          <th>Shortcode</th>
          <th>Einträge</th>
          <th>Ausgeschlossen</th>
          <th>Auto-Import</th>
        </tr>
      </thead>
      <tbody>
        <?php if (count($collections) === 0) { ?>
          <tr><td colspan="5">Noch keine Collections angelegt.</td></tr>
        <?php } ?>
        <?php foreach ($collections as $collection) { ?>
          <?php $data = $this->itemStore->load($collection['id']); ?>
          <tr>
            <td><strong><a href="<?php echo esc_url($this->collectionUrl($collection['id'], 'list')); ?>"><?php echo esc_html($collection['id']); ?></a></strong></td>
            <td><code>[include_pubmed collection="<?php echo esc_html($collection['id']); ?>"]</code></td>
            <td><?php echo esc_html((string) count($data['items'])); ?></td>
            <td><?php echo esc_html((string) count($data['excluded'])); ?></td>
            <td><?php echo $this->describeAutoImportStatus($collection, $data['autoImport']); ?></td>
          </tr>
        <?php } ?>
      </tbody>
    </table>

    <h2>Einstellungen</h2>
    <?php
    $this->renderSettingsForm();
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
          <th scope="row"><label for="kornsw-pmc-interval">Auto-Import-Intervall</label></th>
          <td>
            alle <input type="number" id="kornsw-pmc-interval" name="auto_import_interval_days" min="1" max="<?php echo esc_attr((string) Settings::MAX_AUTO_IMPORT_INTERVAL_DAYS); ?>" value="<?php echo esc_attr((string) $this->settings->getAutoImportIntervalDays()); ?>"> Tage
            <p class="description">Gilt für alle Collections (Standard: <?php echo esc_html((string) Settings::DEFAULT_AUTO_IMPORT_INTERVAL_DAYS); ?>). Läuft im Hintergrund per WP-Cron; die Website selbst fragt PubMed nie an.</p>
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
              Optional. Erhöht das NCBI-Limit von 3 auf 10 Anfragen pro Sekunde (schnellere Suchen).
              Erhältlich im NCBI-Konto unter <a href="https://account.ncbi.nlm.nih.gov/settings/" target="_blank" rel="noopener">Account Settings &gt; API Key Management</a>. Wird verschlüsselt gespeichert.
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

  /* ------------------------------------------------------------------ collection */

  /**
   * @param array<string, mixed>|null $collection null = new collection (settings tab only)
   */
  private function renderCollectionView(?array $collection): void {
    $isNew = $collection === null;
    if ($collection === null) {
      $collection = $this->collections->createEmpty();
    }
    $data = $this->itemStore->load((string) $collection['id']);

    $activeTab = sanitize_key(wp_unslash($_GET['tab'] ?? 'list'));
    if ($isNew || !isset(self::TABS[$activeTab])) {
      $activeTab = 'settings';
      if (!$isNew) {
        $activeTab = 'list';
      }
    }

    $title = 'Collection: ' . $collection['id'];
    if ($isNew) {
      $title = 'Neue Collection';
    }
    ?>
    <p><a href="<?php echo esc_url($this->pageUrl([])); ?>">&larr; Zurück zur Übersicht</a></p>
    <h2><?php echo esc_html($title); ?></h2>
    <?php if (!$isNew) { ?>
      <p class="description">Shortcode: <code>[include_pubmed collection="<?php echo esc_html($collection['id']); ?>"]</code></p>
    <?php } ?>

    <nav class="nav-tab-wrapper">
      <?php foreach (self::TABS as $tab => $label) { ?>
        <?php
        if ($isNew && $tab !== 'settings') {
          continue;
        }
        if ($tab === 'list') {
          $label .= ' (' . count($data['items']) . ')';
        } elseif ($tab === 'excluded') {
          $label .= ' (' . count($data['excluded']) . ')';
        }
        $tabClass = 'nav-tab';
        if ($tab === $activeTab) {
          $tabClass .= ' nav-tab-active';
        }
        ?>
        <a class="<?php echo esc_attr($tabClass); ?>" href="<?php echo esc_url($this->collectionUrl((string) $collection['id'], $tab)); ?>"><?php echo esc_html($label); ?></a>
      <?php } ?>
    </nav>
    <div class="kornsw-pmc-tab">
      <?php
      if ($activeTab === 'list') {
        $this->renderItemsTab($collection, $data['items'], false);
      } elseif ($activeTab === 'excluded') {
        $this->renderItemsTab($collection, $data['excluded'], true);
      } elseif ($activeTab === 'add') {
        $this->renderAddTab($collection);
      } else {
        $this->renderSettingsTab($collection, $data['autoImport'], $isNew);
      }
      ?>
    </div>
    <?php
  }

  /**
   * The list (or the excluded list) with search box and bulk action.
   *
   * @param array<string, mixed> $collection
   * @param array<string, array<string, mixed>> $records
   */
  private function renderItemsTab(array $collection, array $records, bool $isExcludedList): void {
    $preselectedPmid = '';
    if (isset($_GET['pmid'])) {
      $preselectedPmid = implode(',', PubmedQuery::filterPmids([wp_unslash($_GET['pmid'])]));
    }
    $action = 'kornsw_pmc_exclude';
    $buttonLabel = 'Ausgewählte ausschließen';
    $buttonClass = 'button';
    $originHeader = 'Herkunft';
    $emptyText = 'Die Liste ist leer. Einträge kommen über den Auto-Import oder den Tab "Manuell hinzufügen" hinzu.';
    if ($isExcludedList) {
      $action = 'kornsw_pmc_restore';
      $buttonLabel = 'Ausgewählte zurück in die Liste';
      $buttonClass = 'button button-primary';
      $originHeader = 'Ausgeschlossen';
      $emptyText = 'Keine ausgeschlossenen Einträge.';
    }

    if (count($records) === 0) {
      echo '<p>' . esc_html($emptyText) . '</p>';
      return;
    }
    ?>
    <p class="kornsw-pmc-toolbar">
      <input type="search" class="regular-text kornsw-pmc-filter" placeholder="Suchen: PMID oder Volltext (Titel, Autoren, Journal)" value="<?php echo esc_attr($preselectedPmid); ?>">
      <span class="kornsw-pmc-filter-count"></span>
    </p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="kornsw-pmc-items-form">
      <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
      <input type="hidden" name="collection_id" value="<?php echo esc_attr((string) $collection['id']); ?>">
      <input type="hidden" name="pmids_json" value="">
      <?php wp_nonce_field($action); ?>
      <table class="kornsw-pmc-hits kornsw-pmc-items">
        <thead>
          <tr>
            <th class="col-select"><input type="checkbox" class="kornsw-pmc-select-all" title="Alle sichtbaren auswählen"></th>
            <th>Titel</th>
            <th>Autoren</th>
            <th>Journal / Datum</th>
            <th><?php echo esc_html($originHeader); ?></th>
            <th>PMID</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (ItemStore::sortNewestFirst($records) as $record) { ?>
            <?php $pmid = (string) $record['pmid']; ?>
            <tr data-pmid="<?php echo esc_attr($pmid); ?>">
              <td class="col-select"><input type="checkbox" class="kornsw-pmc-select" value="<?php echo esc_attr($pmid); ?>"></td>
              <td><a href="<?php echo esc_url('https://pubmed.ncbi.nlm.nih.gov/' . rawurlencode($pmid) . '/'); ?>" target="_blank" rel="noopener"><span class="kornsw-pmc-hl"><?php echo esc_html((string) $record['title']); ?></span></a></td>
              <td class="authors"><span class="kornsw-pmc-hl"><?php echo esc_html(Formatting::authorList($record)); ?></span></td>
              <td class="citation"><span class="kornsw-pmc-hl"><?php echo esc_html(Formatting::citation($record)); ?></span></td>
              <td class="origin">
                <?php
                if ($isExcludedList) {
                  echo esc_html(wp_date('d.m.Y', (int) ($record['excludedAt'] ?? 0)));
                } else {
                  echo esc_html(Formatting::origin($record));
                }
                ?>
              </td>
              <td class="pmid"><span class="kornsw-pmc-hl"><?php echo esc_html($pmid); ?></span></td>
            </tr>
          <?php } ?>
        </tbody>
      </table>
      <p class="submit">
        <button type="submit" class="<?php echo esc_attr($buttonClass); ?>"><?php echo esc_html($buttonLabel); ?> (<span class="kornsw-pmc-selected-count">0</span>)</button>
      </p>
    </form>
    <?php
  }

  /**
   * Live search in PubMed with three input modes; hits can be taken over into the list.
   *
   * @param array<string, mixed> $collection
   */
  private function renderAddTab(array $collection): void {
    ?>
    <div class="kornsw-pmc-search" data-default-query="<?php echo esc_attr((string) $collection['autoImportQuery']); ?>">
      <p class="kornsw-pmc-modes">
        <label><input type="radio" name="kornsw-pmc-mode" value="ids"> Konkrete PMID(s)</label>
        <label><input type="radio" name="kornsw-pmc-mode" value="builder"> Suche zusammenklicken</label>
        <label><input type="radio" name="kornsw-pmc-mode" value="raw" checked> Rohe PubMed-Query</label>
      </p>

      <div class="kornsw-pmc-mode-panel" data-mode="ids" hidden>
        <textarea class="large-text code kornsw-pmc-ids" rows="2" placeholder="PMIDs, getrennt durch Komma, Leerzeichen oder Zeilenumbruch - z. B. 42897285, 42864849"></textarea>
      </div>

      <div class="kornsw-pmc-mode-panel" data-mode="builder" hidden>
        <div class="kornsw-pmc-builder-rows"></div>
        <p><button type="button" class="button kornsw-pmc-builder-add">+ Bedingung hinzufügen</button></p>
        <p class="description">Mehrere Werte in einem Feld mit Komma trennen = ODER (z. B. Konsortium: <code>ATLAS, CMS</code>). Die Bedingungen werden von oben nach unten verknüpft.</p>
        <p>Daraus erzeugte Query: <code class="kornsw-pmc-builder-preview"></code></p>
      </div>

      <div class="kornsw-pmc-mode-panel" data-mode="raw">
        <textarea class="large-text code kornsw-pmc-raw" rows="3"><?php echo esc_textarea((string) $collection['autoImportQuery']); ?></textarea>
        <p class="description">Vorbelegt mit der Auto-Import-Query: zeigt, welche Treffer noch nicht in der Liste sind.</p>
        <?php $this->renderSearchLegend(); ?>
      </div>

      <p>
        <button type="button" class="button button-primary kornsw-pmc-search-run">Suchen</button>
        <span class="kornsw-pmc-search-status"></span>
      </p>
      <div class="kornsw-pmc-search-info" hidden></div>

      <table class="kornsw-pmc-hits kornsw-pmc-results" hidden>
        <thead>
          <tr>
            <th class="col-select"><input type="checkbox" class="kornsw-pmc-select-all" title="Alle neuen auswählen"></th>
            <th>Titel</th>
            <th>Autoren</th>
            <th>Journal / Datum</th>
            <th>Status</th>
            <th>PMID</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
      <p class="kornsw-pmc-results-actions" hidden>
        <button type="button" class="button kornsw-pmc-search-more">Weitere <?php echo esc_html((string) SearchController::PAGE_SIZE); ?> laden</button>
        <button type="button" class="button button-primary kornsw-pmc-add-selected">Ausgewählte in die Liste übernehmen (<span class="kornsw-pmc-selected-count">0</span>)</button>
        <span class="kornsw-pmc-add-status"></span>
      </p>
    </div>
    <?php
  }

  /**
   * @param array<string, mixed> $collection
   * @param array<string, mixed> $autoImportState
   */
  private function renderSettingsTab(array $collection, array $autoImportState, bool $isNew): void {
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="kornsw_pmc_save_collection">
      <input type="hidden" name="original_id" value="<?php echo esc_attr((string) $collection['id']); ?>">
      <?php wp_nonce_field('kornsw_pmc_save_collection'); ?>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="kornsw-pmc-id">ID</label></th>
          <td>
            <input type="text" id="kornsw-pmc-id" name="collection_id" class="regular-text" required maxlength="64" pattern="[A-Za-z0-9_\-]+" value="<?php echo esc_attr((string) $collection['id']); ?>">
            <p class="description">
              Interner Bezeichner für den Shortcode <code>[include_pubmed collection="…"]</code>. Frei wählbar (Buchstaben, Ziffern, "-" und "_").
              Wählt man die ID einer bisherigen PubMed-Collection (z. B. <code>64421242</code>), funktionieren bestehende Shortcodes unverändert weiter.
              <?php if (!$isNew) { ?><br><strong>Achtung:</strong> Eine Änderung der ID macht Shortcodes mit der alten ID wirkungslos.<?php } ?>
            </p>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="kornsw-pmc-query">Auto-Import</label></th>
          <td>
            <label><input type="checkbox" name="auto_import_enabled" value="1"<?php checked((bool) $collection['autoImportEnabled']); ?>> aktiv: alle <?php echo esc_html((string) $this->settings->getAutoImportIntervalDays()); ?> Tage neu bei PubMed erfasste Treffer dieser Query in die Liste übernehmen und per Mail zum Review melden</label>
            <textarea id="kornsw-pmc-query" name="auto_import_query" class="large-text code" rows="3"><?php echo esc_textarea((string) $collection['autoImportQuery']); ?></textarea>
            <?php if ((string) $collection['autoImportQuery'] !== '') { ?>
              <p><a href="<?php echo esc_url(PubmedQuery::buildSearchUrl((string) $collection['autoImportQuery'])); ?>" target="_blank" rel="noopener">Query in PubMed ausprobieren &#8599;</a></p>
            <?php } ?>
            <p class="description">Importiert werden nur Einträge, die ab jetzt neu bei PubMed erfasst werden - nie bereits gelistete oder ausgeschlossene. Ältere Treffer übernimmt man gezielt im Tab "Manuell hinzufügen".</p>
            <?php $this->renderSearchLegend(); ?>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="kornsw-pmc-url">Externer Link</label></th>
          <td>
            <input type="url" id="kornsw-pmc-url" name="external_url" class="large-text" value="<?php echo esc_attr((string) $collection['externalUrl']); ?>" placeholder="https://pubmed.ncbi.nlm.nih.gov/collections/64421242/?sort=pubdate">
            <p class="description">Optional. Wird über der Liste verlinkt (öffnet in neuem Fenster). Leer = Link auf die PubMed-Suche mit der Auto-Import-Query.</p>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="kornsw-pmc-highlight-author">Hervorgehobener Autor</label></th>
          <td>
            <input type="text" id="kornsw-pmc-highlight-author" name="highlight_author" class="regular-text" value="<?php echo esc_attr((string) $collection['highlightAuthor']); ?>" placeholder="Korn S">
            <p class="description">
              Wird in den Autorenlisten auf der Website <span style="color: blue"><b>blau und fett</b></span> dargestellt. Schreibweise wie in PubMed-Autorenlisten (Nachname + Initialen, z. B. <code>Korn S</code>);
              mehrere Namen mit Komma trennen. Leer = keine Hervorhebung. Das Shortcode-Attribut <code>highlightAuthor="…"</code> überschreibt diese Einstellung.
            </p>
          </td>
        </tr>
      </table>
      <?php submit_button('Speichern'); ?>
    </form>

    <?php if (!$isNew) { ?>
      <h3>Status Auto-Import</h3>
      <table class="widefat striped kornsw-pmc-diagnostics">
        <tbody>
          <tr><th scope="row">Zuletzt geprüft</th><td><?php echo esc_html(Formatting::timestamp((int) $autoImportState['lastPollAt'])); ?></td></tr>
          <tr><th scope="row">Letzter Erfolg</th><td><?php echo esc_html(Formatting::timestamp((int) $autoImportState['lastSuccessAt'])); ?></td></tr>
          <tr><th scope="row">Ergebnis</th><td><?php echo esc_html((string) $autoImportState['lastResult']); ?></td></tr>
          <?php if ((int) $autoImportState['failingSince'] > 0) { ?>
            <tr class="status-error"><th scope="row">Gestört seit <?php echo esc_html(Formatting::timestamp((int) $autoImportState['failingSince'])); ?></th><td><?php echo esc_html((string) $autoImportState['lastError']); ?></td></tr>
          <?php } ?>
        </tbody>
      </table>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="kornsw_pmc_import_now">
        <input type="hidden" name="collection_id" value="<?php echo esc_attr((string) $collection['id']); ?>">
        <?php wp_nonce_field('kornsw_pmc_import_now'); ?>
        <p><button type="submit" class="button">Jetzt auf neue Einträge prüfen</button></p>
      </form>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Collection samt Liste und ausgeschlossenen Einträgen wirklich löschen? Shortcodes mit dieser ID zeigen danach nichts mehr an.');">
        <input type="hidden" name="action" value="kornsw_pmc_delete_collection">
        <input type="hidden" name="collection_id" value="<?php echo esc_attr((string) $collection['id']); ?>">
        <?php wp_nonce_field('kornsw_pmc_delete_collection'); ?>
        <p><button type="submit" class="button button-link-delete">Collection löschen</button></p>
      </form>
    <?php } ?>
    <?php
  }

  private function renderSearchLegend(): void {
    ?>
    <details class="kornsw-pmc-legend">
      <summary>Legende: Wie schreibt man eine PubMed-Query?</summary>
      <p>Feld-Kürzel stehen in eckigen Klammern direkt hinter dem Begriff:</p>
      <table>
        <tr><td><code>Korn S[au]</code></td><td>Autor: Nachname + Initialen (findet auch Namensvettern und erweitert Initialen: Korn SA, Korn SH …)</td></tr>
        <tr><td><code>"Korn S"[au]</code></td><td>Autor exakt mit diesen Initialen (keine Erweiterung)</td></tr>
        <tr><td><code>"Korn Stephanie"[fau]</code></td><td>Autor mit vollem Vornamen - sehr treffsicher, fehlt aber bei manchen (v. a. älteren / deutschen) Datensätzen</td></tr>
        <tr><td><code>Korn S[1au]</code> / <code>Korn S[lastau]</code></td><td>nur als Erst- bzw. Letztautor</td></tr>
        <tr><td><code>Mainz[ad]</code></td><td>Affiliation (Institution, Ort) der Autoren</td></tr>
        <tr><td><code>ATLAS Collaboration[cn]</code></td><td>Konsortium (Corporate Author)</td></tr>
        <tr><td><code>2015:3000[dp]</code></td><td>Publikationsdatum (Zeitraum von:bis)</td></tr>
        <tr><td><code>asthma[ti]</code> / <code>asthma[tiab]</code></td><td>Wort im Titel / in Titel oder Abstract</td></tr>
        <tr><td><code>"J Clin Med"[ta]</code></td><td>Journal (Kurztitel)</td></tr>
        <tr><td><code>Review[pt]</code></td><td>Publikationstyp</td></tr>
        <tr><td><code>12345678[pmid]</code></td><td>einzelne Publikation per PMID</td></tr>
        <tr><td><code>AND</code>, <code>OR</code>, <code>NOT</code></td><td>Verknüpfungen - immer komplett in GROSSBUCHSTABEN</td></tr>
        <tr><td><code>( … )</code>, <code>"…"</code>, <code>asthm*</code></td><td>Klammern gruppieren, Anführungszeichen für Phrasen, <code>*</code> als Platzhalter</td></tr>
      </table>
      <p>Beispiel: <code>Korn S[au] AND (Mainz[ad] OR Wiesbaden[ad]) NOT ATLAS Collaboration[cn]</code> -
        Vollständige Liste: <a href="https://pubmed.ncbi.nlm.nih.gov/help/#search-tags" target="_blank" rel="noopener">PubMed-Hilfe &#8599;</a></p>
    </details>
    <?php
  }

  /**
   * @param array<string, mixed> $collection
   * @param array<string, mixed> $autoImportState
   */
  private function describeAutoImportStatus(array $collection, array $autoImportState): string {
    if (!(bool) $collection['autoImportEnabled']) {
      return '<em>aus</em>';
    }
    $status = esc_html('aktiv, zuletzt geprüft ' . Formatting::timestamp((int) $autoImportState['lastPollAt']));
    if ((int) $autoImportState['failingSince'] > 0) {
      $status .= ' <span class="kornsw-pmc-error">(gestört seit ' . esc_html(Formatting::timestamp((int) $autoImportState['failingSince'])) . ')</span>';
    }
    return $status;
  }

  /* ------------------------------------------------------------------ diagnostics */

  /**
   * Environment, on-demand connection tests, mail test and the log.
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

    <h3>Mails</h3>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="kornsw_pmc_test_mail">
      <?php wp_nonce_field('kornsw_pmc_test_mail'); ?>
      <p>
        Empfänger: <code><?php echo esc_html((string) $recipient); ?></code>
        <button type="submit" class="button">Test-Mail senden</button>
      </p>
    </form>

    <h3>Protokoll (letzte 50 Einträge)</h3>
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

  /* ------------------------------------------------------------------ request handlers */

  public function handleSaveCollection(): void {
    $this->assertCapability();
    check_admin_referer('kornsw_pmc_save_collection');

    $originalId = sanitize_text_field(wp_unslash($_POST['original_id'] ?? ''));
    $collectionId = trim(sanitize_text_field(wp_unslash($_POST['collection_id'] ?? '')));
    // Line breaks are only for readability in the textarea
    $query = trim((string) preg_replace('/\s+/', ' ', sanitize_textarea_field(wp_unslash($_POST['auto_import_query'] ?? ''))));
    $isAutoImportEnabled = ($_POST['auto_import_enabled'] ?? '') === '1';
    $externalUrl = esc_url_raw(trim(wp_unslash($_POST['external_url'] ?? '')), ['http', 'https']);
    $highlightAuthor = implode(', ', PubmedQuery::splitNames(sanitize_text_field(wp_unslash($_POST['highlight_author'] ?? ''))));

    $backToForm = ['view' => 'edit', 'collection' => $originalId, 'tab' => 'settings'];
    if (!CollectionRepository::isValidId($collectionId)) {
      $this->redirect($backToForm, 'invalid_id');
    }
    if ($isAutoImportEnabled && $query === '') {
      $this->redirect($backToForm, 'empty_query');
    }
    if ($collectionId !== $originalId && $this->collections->find($collectionId) !== null) {
      $this->redirect($backToForm, 'duplicate_id');
    }

    $this->collections->save([
      'id' => $collectionId,
      'autoImportQuery' => $query,
      'autoImportEnabled' => $isAutoImportEnabled,
      'externalUrl' => $externalUrl,
      'highlightAuthor' => $highlightAuthor
    ], $originalId);
    if ($originalId !== '' && $originalId !== $collectionId) {
      $this->itemStore->rename($originalId, $collectionId);
    }

    // The auto import starts from now on: older hits are taken over manually
    $state = $this->itemStore->load($collectionId)['autoImport'];
    if ((int) $state['lastPollAt'] === 0) {
      $state['lastPollAt'] = time();
      $this->itemStore->saveAutoImportState($collectionId, $state);
    }

    $this->redirect(['view' => 'edit', 'collection' => $collectionId, 'tab' => 'settings'], 'saved');
  }

  public function handleDeleteCollection(): void {
    $this->assertCapability();
    check_admin_referer('kornsw_pmc_delete_collection');

    $collectionId = sanitize_text_field(wp_unslash($_POST['collection_id'] ?? ''));
    $this->collections->delete($collectionId);
    $this->itemStore->delete($collectionId);

    $this->redirect([], 'deleted');
  }

  public function handleExclude(): void {
    $this->assertCapability();
    check_admin_referer('kornsw_pmc_exclude');

    $collectionId = sanitize_text_field(wp_unslash($_POST['collection_id'] ?? ''));
    $pmids = $this->readSelectedPmids();
    $notice = 'nothing_selected';
    if (count($pmids) > 0 && $this->itemStore->exclude($collectionId, $pmids) > 0) {
      $notice = 'items_excluded';
    }
    $this->redirect(['view' => 'edit', 'collection' => $collectionId, 'tab' => 'list'], $notice);
  }

  public function handleRestore(): void {
    $this->assertCapability();
    check_admin_referer('kornsw_pmc_restore');

    $collectionId = sanitize_text_field(wp_unslash($_POST['collection_id'] ?? ''));
    $pmids = $this->readSelectedPmids();
    $notice = 'nothing_selected';
    if (count($pmids) > 0 && $this->itemStore->restore($collectionId, $pmids) > 0) {
      $notice = 'items_restored';
    }
    $this->redirect(['view' => 'edit', 'collection' => $collectionId, 'tab' => 'excluded'], $notice);
  }

  public function handleImportNow(): void {
    $this->assertCapability();
    check_admin_referer('kornsw_pmc_import_now');

    $collectionId = sanitize_text_field(wp_unslash($_POST['collection_id'] ?? ''));
    $collection = $this->collections->find($collectionId);
    if ($collection === null) {
      $this->redirect([], 'not_found');
    }
    $result = $this->autoImporter->import($collection);
    $notice = 'import_failed';
    if ($result['success']) {
      $notice = 'import_done';
    }
    $this->redirect(['view' => 'edit', 'collection' => $collectionId, 'tab' => 'settings'], $notice);
  }

  public function handleSaveSettings(): void {
    $this->assertCapability();
    check_admin_referer('kornsw_pmc_save_settings');

    if (isset($_POST['auto_import_interval_days'])) {
      $this->settings->saveAutoImportIntervalDays((int) $_POST['auto_import_interval_days']);
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

  /* ------------------------------------------------------------------ helpers */

  /**
   * Bulk selections travel as one JSON field (avoids max_input_vars limits).
   *
   * @return string[]
   */
  private function readSelectedPmids(): array {
    $decoded = json_decode(wp_unslash($_POST['pmids_json'] ?? '[]'), true);
    if (!is_array($decoded)) {
      return [];
    }
    return PubmedQuery::filterPmids($decoded);
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

  private function collectionUrl(string $collectionId, string $tab): string {
    return $this->pageUrl(['view' => 'edit', 'collection' => $collectionId, 'tab' => $tab]);
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
