<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection;

use RuntimeException;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Plugin-wide settings. The optional NCBI API key is stored encrypted (key derived from the WordPress salts).
 */
final class Settings {

  private const OPTION_NAME = 'kornsw_pmc_settings';

  private const CIPHER = 'aes-256-cbc';

  public const DEFAULT_AUTO_IMPORT_INTERVAL_DAYS = 3;

  public const MAX_AUTO_IMPORT_INTERVAL_DAYS = 60;

  /**
   * The auto import asks PubMed for new publications every this many days.
   */
  public function getAutoImportIntervalDays(): int {
    $intervalDays = $this->readSetting('autoImportIntervalDays');
    if ($intervalDays === '') {
      return self::DEFAULT_AUTO_IMPORT_INTERVAL_DAYS;
    }
    return $this->clampIntervalDays((int) $intervalDays);
  }

  public function saveAutoImportIntervalDays(int $intervalDays): void {
    $this->writeSetting('autoImportIntervalDays', (string) $this->clampIntervalDays($intervalDays));
  }

  private function clampIntervalDays(int $intervalDays): int {
    return max(1, min(self::MAX_AUTO_IMPORT_INTERVAL_DAYS, $intervalDays));
  }

  /**
   * Workaround for servers with a broken IPv6 route: connect to NCBI via IPv4 only.
   */
  public function getForceIpv4(): bool {
    return $this->readSetting('forceIpv4') === '1';
  }

  public function saveForceIpv4(bool $forceIpv4): void {
    $value = '';
    if ($forceIpv4) {
      $value = '1';
    }
    $this->writeSetting('forceIpv4', $value);
  }

  public function hasApiKey(): bool {
    return $this->readSetting('apiKeyEncrypted') !== '';
  }

  /**
   * Returns the decrypted API key, or '' if none is stored or it cannot be decrypted (e.g. after the salts changed).
   */
  public function getApiKey(): string {
    $encrypted = $this->readSetting('apiKeyEncrypted');
    if ($encrypted === '' || !function_exists('openssl_decrypt')) {
      return '';
    }
    $raw = base64_decode($encrypted, true);
    $ivLength = (int) openssl_cipher_iv_length(self::CIPHER);
    if ($raw === false || strlen($raw) <= $ivLength) {
      return '';
    }
    $plainText = openssl_decrypt(substr($raw, $ivLength), self::CIPHER, $this->encryptionKey(), OPENSSL_RAW_DATA, substr($raw, 0, $ivLength));
    if ($plainText === false) {
      return '';
    }
    return $plainText;
  }

  /**
   * @throws RuntimeException if encryption is not available.
   */
  public function saveApiKey(string $apiKey): void {
    if (!function_exists('openssl_encrypt')) {
      throw new RuntimeException('Die PHP-Erweiterung OpenSSL fehlt, der API-Key kann nicht verschlüsselt gespeichert werden.');
    }
    $ivLength = (int) openssl_cipher_iv_length(self::CIPHER);
    $initializationVector = random_bytes($ivLength);
    $cipherText = openssl_encrypt($apiKey, self::CIPHER, $this->encryptionKey(), OPENSSL_RAW_DATA, $initializationVector);
    if ($cipherText === false) {
      throw new RuntimeException('Der API-Key konnte nicht verschlüsselt werden.');
    }
    $this->writeSetting('apiKeyEncrypted', base64_encode($initializationVector . $cipherText));
  }

  public function removeApiKey(): void {
    $this->writeSetting('apiKeyEncrypted', '');
  }

  private function encryptionKey(): string {
    return hash('sha256', wp_salt('auth') . 'kornsw-pubmedcollection', true);
  }

  private function readSetting(string $name): string {
    $settings = get_option(self::OPTION_NAME, []);
    if (is_array($settings) && isset($settings[$name])) {
      return (string) $settings[$name];
    }
    return '';
  }

  private function writeSetting(string $name, string $value): void {
    $settings = get_option(self::OPTION_NAME, []);
    if (!is_array($settings)) {
      $settings = [];
    }
    $settings[$name] = $value;
    update_option(self::OPTION_NAME, $settings, false);
  }

}
