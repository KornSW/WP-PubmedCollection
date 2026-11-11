<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection;

use KornSW\PubmedCollection\Admin\AdminPage;
use KornSW\PubmedCollection\Admin\ConnectionDiagnostics;
use KornSW\PubmedCollection\Admin\SearchController;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Main class: wires the services and registers the hooks.
 */
final class Plugin {

  public static function boot(): void {
    $settings = new Settings();
    $collections = new CollectionRepository();
    $itemStore = new ItemStore();
    $log = new RefreshLog();
    $notifier = new AlarmNotifier();
    $client = new EutilsClient($settings);
    $autoImporter = new AutoImporter($collections, $itemStore, $client, $notifier, $settings, $log);

    $migration = new Migration($itemStore);
    $migration->run();

    $shortcode = new PubmedShortcode($collections, $itemStore);
    add_shortcode('include_pubmed', [$shortcode, 'render']);

    add_action(AutoImporter::CRON_HOOK, [$autoImporter, 'runDueImports']);
    if (wp_next_scheduled(AutoImporter::CRON_HOOK) === false) {
      wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'twicedaily', AutoImporter::CRON_HOOK);
    }

    // Optional workaround for servers with a broken IPv6 route (see diagnostics page)
    add_action('http_api_curl', function ($handle, array $requestArguments, string $url) use ($settings): void {
      if (wp_parse_url($url, PHP_URL_HOST) === EutilsClient::HOST && $settings->getForceIpv4()) {
        curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
      }
    }, 10, 3);

    if (is_admin()) {
      $adminPage = new AdminPage($collections, $itemStore, $settings, $autoImporter, $log, $notifier, new ConnectionDiagnostics($settings));
      $adminPage->register();
      $searchController = new SearchController($collections, $itemStore, $client, $log);
      $searchController->register();
    }
  }

  /**
   * Deactivation removes the cron job only; all data is kept.
   */
  public static function deactivate(): void {
    wp_clear_scheduled_hook(AutoImporter::CRON_HOOK);
  }

}
