<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Helpers around PubMed query strings.
 */
final class PubmedQuery {

  public static function buildSearchUrl(string $term): string {
    return 'https://pubmed.ncbi.nlm.nih.gov/?term=' . rawurlencode($term) . '&sort=pubdate';
  }

  /**
   * Finds the author names of a query (e.g. "Korn S" in 'Korn S[au] AND Mainz[ad]'), so they survive
   * the shortening of long author lists.
   *
   * @return string[]
   */
  public static function extractAuthorNames(string $term): array {
    $names = [];
    if (preg_match_all('/([^\[\]()]+)\[(au|auth|author|1au|fau|lastau|full author name)\]/iu', $term, $matches, PREG_SET_ORDER) === false) {
      return $names;
    }
    foreach ($matches as $match) {
      // The match may start with the rest of the previous expression, e.g. ' AND Korn S'
      $parts = preg_split('/\s+(?:AND|OR|NOT)\s+/', ' ' . $match[1] . ' ');
      if (!is_array($parts)) {
        continue;
      }
      $name = trim((string) end($parts), " \t\"");
      if ($name === '') {
        continue;
      }
      $names[] = $name;
      // Author lists of esummary only carry "Korn S", never the full name "Korn Stephanie"
      $tag = strtolower($match[2]);
      if ($tag === 'fau' || $tag === 'full author name') {
        $names[] = self::toInitialsForm($name);
      }
    }
    return array_values(array_unique($names));
  }

  /**
   * Splits a comma separated list of names (e.g. the highlight setting).
   *
   * @return string[]
   */
  public static function splitNames(string $names): array {
    $result = [];
    foreach (explode(',', $names) as $name) {
      $name = trim($name);
      if ($name !== '') {
        $result[] = $name;
      }
    }
    return $result;
  }

  /**
   * "Korn Stephanie" -> "Korn S" (last name + first initial, as used in esummary author lists).
   */
  private static function toInitialsForm(string $fullName): string {
    $parts = preg_split('/\s+/', trim($fullName));
    if (!is_array($parts) || count($parts) < 2) {
      return $fullName;
    }
    return $parts[0] . ' ' . mb_substr($parts[1], 0, 1);
  }

  /**
   * @param array<mixed> $values
   * @return string[]
   */
  public static function filterPmids(array $values): array {
    $pmids = [];
    foreach ($values as $value) {
      $value = trim((string) $value);
      if ($value !== '' && ctype_digit($value)) {
        $pmids[] = $value;
      }
    }
    return array_values(array_unique($pmids));
  }

}
