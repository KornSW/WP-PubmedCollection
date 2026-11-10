<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection;

use KornSW\PubmedCollection\Admin\AdminPage;

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
    $publications = new PublicationService(new EutilsClient($settings), $cache, new AlarmNotifier(), $settings);

    $shortcode = new PubmedShortcode($collections, $publications);
    add_shortcode('include_pubmed', [$shortcode, 'render']);

    add_action(PublicationService::REFRESH_HOOK, function (string $collectionId) use ($collections, $publications): void {
      $collection = $collections->find($collectionId);
      if ($collection !== null) {
        $publications->refreshInBackground($collection);
      }
    });

    if (is_admin()) {
      $adminPage = new AdminPage($collections, $settings, $publications, $cache);
      $adminPage->register();
    }
  }

}
