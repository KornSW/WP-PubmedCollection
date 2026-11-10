<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Small ring buffer of the latest refresh attempts, shown on the diagnostics page.
 */
final class RefreshLog {

  private const OPTION_NAME = 'kornsw_pmc_log';

  private const MAX_ENTRIES = 50;

  public const LEVEL_OK = 'ok';

  public const LEVEL_ERROR = 'error';

  public const LEVEL_INFO = 'info';

  public function add(string $collectionId, string $level, string $message, float $durationSeconds = 0.0): void {
    $entries = $this->all();
    array_unshift($entries, [
      'time' => time(),
      'collection' => $collectionId,
      'level' => $level,
      'trigger' => $this->describeTrigger(),
      'duration' => round($durationSeconds, 1),
      'message' => $message
    ]);
    update_option(self::OPTION_NAME, array_slice($entries, 0, self::MAX_ENTRIES), false);
  }

  /**
   * @return array<int, array{time: int, collection: string, level: string, trigger: string, duration: float, message: string}> newest first
   */
  public function all(): array {
    $entries = get_option(self::OPTION_NAME, []);
    if (!is_array($entries)) {
      return [];
    }
    return array_values(array_filter($entries, 'is_array'));
  }

  public function clear(): void {
    delete_option(self::OPTION_NAME);
  }

  private function describeTrigger(): string {
    if (wp_doing_cron()) {
      return 'Hintergrund (WP-Cron)';
    }
    if (is_admin()) {
      return 'Verwaltung';
    }
    return 'Seitenaufruf';
  }

}
