<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Small shared formatting helpers.
 */
final class Formatting {

  /**
   * Formats a UTC timestamp in the site's time zone, or '(nie)' for 0.
   */
  public static function timestamp(int $timestamp): string {
    if ($timestamp <= 0) {
      return '(nie)';
    }
    return wp_date('d.m.Y H:i', $timestamp);
  }

}
