<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection;

use KornSW\PubmedCollection\Admin\AdminPage;
use KornSW\PubmedCollection\Admin\ConnectionDiagnostics;

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
    $cache = new PublicationCache();
    $log = new RefreshLog();
    $notifier = new AlarmNotifier();
    $publications = new PublicationService(new EutilsClient($settings), $cache, $notifier, $settings, $log);

    $shortcode = new PubmedShortcode($collections, $publications);
    add_shortcode('include_pubmed', [$shortcode, 'render']);

    add_action(PublicationService::REFRESH_HOOK, function (string $collectionId) use ($collections, $publications): void {
      $collection = $collections->find($collectionId);
      if ($collection !== null) {
        $publications->refreshInBackground($collection);
      }
    });

    // Optional workaround for servers with a broken IPv6 route (see diagnostics page)
    add_action('http_api_curl', function ($handle, array $requestArguments, string $url) use ($settings): void {
      if (wp_parse_url($url, PHP_URL_HOST) === EutilsClient::HOST && $settings->getForceIpv4()) {
        curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
      }
    }, 10, 3);

    if (is_admin()) {
      $adminPage = new AdminPage($collections, $settings, $publications, $cache, $log, $notifier, new ConnectionDiagnostics($settings));
      $adminPage->register();
    }
  }

}
