<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection;

use RuntimeException;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * The API was reachable but did not answer in time (HTTP 5xx / 429, timeout): a smaller or later request may succeed.
 * Connection errors (e.g. "could not connect") are plain RuntimeExceptions, because splitting requests does not help there.
 */
final class TransientApiException extends RuntimeException {

}
