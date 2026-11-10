<?php

declare(strict_types=1);

namespace KornSW\PubmedCollection\Admin;

use KornSW\PubmedCollection\EutilsClient;
use KornSW\PubmedCollection\PublicationService;
use KornSW\PubmedCollection\Settings;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Checks why the web server cannot reach the NCBI API (for installations without shell access):
 * environment, DNS, raw TCP per address, HTTPS via WordPress and via cURL forced to IPv4 / IPv6.
 * Uses cURL directly where WordPress offers no control (IP version, verbose log).
 */
final class ConnectionDiagnostics {

  public const STATUS_OK = 'ok';

  public const STATUS_ERROR = 'error';

  public const STATUS_INFO = 'info';

  private const TIMEOUT_SECONDS = 10;

  private const TEST_PATH = '/entrez/eutils/einfo.fcgi?retmode=json';

  private const MAX_VERBOSE_LENGTH = 4000;

  private Settings $settings;

  public function __construct(Settings $settings) {
    $this->settings = $settings;
  }

  /**
   * @return array<int, array{label: string, status: string, details: string}>
   */
  public function describeEnvironment(): array {
    $rows = [];
    $rows[] = $this->row('PHP', self::STATUS_INFO, PHP_VERSION . ' (' . PHP_OS . ')');
    $rows[] = $this->row('WordPress', self::STATUS_INFO, (string) get_bloginfo('version'));

    if (function_exists('curl_version')) {
      $curlVersion = curl_version();
      $supportsIpv6 = ((int) $curlVersion['features'] & CURL_VERSION_IPV6) !== 0;
      $rows[] = $this->row('cURL', self::STATUS_INFO, 'Version ' . $curlVersion['version'] . ', ' . $curlVersion['ssl_version'] . ', IPv6-Unterstützung: ' . $this->yesNo($supportsIpv6));
    } else {
      $rows[] = $this->row('cURL', self::STATUS_ERROR, 'PHP-Erweiterung cURL fehlt - WordPress nutzt dann Streams, einige Tests entfallen.');
    }

    if (defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL) {
      $accessibleHosts = '(keine)';
      if (defined('WP_ACCESSIBLE_HOSTS')) {
        $accessibleHosts = (string) WP_ACCESSIBLE_HOSTS;
      }
      $rows[] = $this->row('WP_HTTP_BLOCK_EXTERNAL', self::STATUS_ERROR, 'aktiv - externe Anfragen sind gesperrt. Erlaubte Hosts (WP_ACCESSIBLE_HOSTS): ' . $accessibleHosts);
    } else {
      $rows[] = $this->row('WP_HTTP_BLOCK_EXTERNAL', self::STATUS_OK, 'nicht aktiv');
    }

    if (defined('WP_PROXY_HOST')) {
      $proxyPort = '';
      if (defined('WP_PROXY_PORT')) {
        $proxyPort = ':' . (string) WP_PROXY_PORT;
      }
      $rows[] = $this->row('Proxy', self::STATUS_INFO, (string) WP_PROXY_HOST . $proxyPort);
    } else {
      $rows[] = $this->row('Proxy', self::STATUS_INFO, 'keiner konfiguriert');
    }

    $serverAddress = '(unbekannt)';
    if (isset($_SERVER['SERVER_ADDR'])) {
      $serverAddress = sanitize_text_field(wp_unslash($_SERVER['SERVER_ADDR']));
    }
    $rows[] = $this->row('Server-Adresse (lokal)', self::STATUS_INFO, $serverAddress);

    if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
      $rows[] = $this->row('WP-Cron', self::STATUS_INFO, 'DISABLE_WP_CRON ist gesetzt - Hintergrund-Abrufe laufen nur, wenn ein System-Cron wp-cron.php aufruft.');
    } else {
      $rows[] = $this->row('WP-Cron', self::STATUS_OK, 'aktiv (läuft bei Seitenaufrufen mit)');
    }
    $rows[] = $this->row('Eingeplante Hintergrund-Abrufe', self::STATUS_INFO, $this->describeScheduledRefreshes());

    $rows[] = $this->row('Nur IPv4 erzwingen', self::STATUS_INFO, $this->yesNo($this->settings->getForceIpv4()));
    $rows[] = $this->row('NCBI-API-Key', self::STATUS_INFO, $this->yesNo($this->settings->hasApiKey()));

    return $rows;
  }

  /**
   * Runs all network checks (takes up to about a minute if everything times out).
   *
   * @return array<int, array{label: string, status: string, details: string}>
   */
  public function runConnectionTests(): array {
    if (function_exists('set_time_limit')) {
      set_time_limit(180);
    }

    $rows = [];
    $host = EutilsClient::HOST;

    $ipv4Addresses = $this->resolveIpv4($host);
    $ipv6Addresses = $this->resolveIpv6($host);
    $rows[] = $this->row('DNS IPv4 (A)', $this->statusFor(count($ipv4Addresses) > 0), $this->listOrNone($ipv4Addresses));
    $rows[] = $this->row('DNS IPv6 (AAAA)', self::STATUS_INFO, $this->listOrNone($ipv6Addresses));

    foreach (array_slice($ipv4Addresses, 0, 2) as $address) {
      $rows[] = $this->testTcp('TCP ' . $address . ':443', 'tcp://' . $address . ':443');
    }
    foreach (array_slice($ipv6Addresses, 0, 2) as $address) {
      $rows[] = $this->testTcp('TCP [' . $address . ']:443', 'tcp://[' . $address . ']:443');
    }

    $rows[] = $this->testWordPressRequest('HTTPS über WordPress (wie das Plugin)', 'https://' . $host . self::TEST_PATH);

    if (function_exists('curl_init')) {
      $rows[] = $this->testCurl('HTTPS über cURL, nur IPv4', 'https://' . $host . self::TEST_PATH, CURL_IPRESOLVE_V4);
      $rows[] = $this->testCurl('HTTPS über cURL, nur IPv6', 'https://' . $host . self::TEST_PATH, CURL_IPRESOLVE_V6);
    }

    $rows[] = $this->testWordPressRequest('Vergleich: www.ncbi.nlm.nih.gov', 'https://www.ncbi.nlm.nih.gov/');
    $rows[] = $this->testWordPressRequest('Vergleich: api.wordpress.org', 'https://api.wordpress.org/core/version-check/1.7/');

    $rows[] = $this->row('Einschätzung', self::STATUS_INFO, $this->conclude($rows));
    return $rows;
  }

  /**
   * @param array<int, array{label: string, status: string, details: string}> $rows
   */
  private function conclude(array $rows): string {
    $statusByLabel = [];
    foreach ($rows as $row) {
      $statusByLabel[$row['label']] = $row['status'];
    }
    $viaWordPress = ($statusByLabel['HTTPS über WordPress (wie das Plugin)'] ?? '') === self::STATUS_OK;
    $viaIpv4 = ($statusByLabel['HTTPS über cURL, nur IPv4'] ?? '') === self::STATUS_OK;
    $viaIpv6 = ($statusByLabel['HTTPS über cURL, nur IPv6'] ?? '') === self::STATUS_OK;
    $ncbiWebsite = ($statusByLabel['Vergleich: www.ncbi.nlm.nih.gov'] ?? '') === self::STATUS_OK;
    $wordPressOrg = ($statusByLabel['Vergleich: api.wordpress.org'] ?? '') === self::STATUS_OK;

    if ($viaWordPress) {
      return 'Die NCBI-API ist im Moment erreichbar. Ein früherer Fehler war vermutlich vorübergehend - siehe Abruf-Protokoll.';
    }
    if ($viaIpv4 && !$viaIpv6) {
      return 'Über IPv4 klappt es, über IPv6 nicht: Die IPv6-Anbindung des Servers ist gestört. Abhilfe: unter Einstellungen "Nur IPv4" aktivieren.';
    }
    if ($viaIpv4) {
      return 'cURL direkt erreicht die API, die Anfrage über WordPress aber nicht: Ein anderes Plugin oder eine Konfiguration (Proxy, Filter auf pre_http_request / http_request_args) greift in WordPress-Anfragen ein.';
    }
    if (!$wordPressOrg) {
      return 'Auch api.wordpress.org ist nicht erreichbar: Der Server hat generell keinen ausgehenden Internetzugang (Firewall des Hosters, Proxy nötig?).';
    }
    if (!$ncbiWebsite) {
      return 'Das übrige Internet ist erreichbar, aber kein NCBI-Server: Vermutlich sperrt NCBI die IP-Adresse des Servers, oder der Hoster blockiert NCBI. Ansprechpartner: Hoster bzw. NCBI-Support (info@ncbi.nlm.nih.gov) mit der öffentlichen IP des Servers.';
    }
    return 'Die NCBI-Website ist erreichbar, nur die API (eutils) nicht: Vermutlich eine gezielte Sperre der Server-IP für die E-utilities (z. B. nach zu vielen Anfragen) - löst sich meist nach einiger Zeit, sonst NCBI-Support kontaktieren.';
  }

  /**
   * @return string[]
   */
  private function resolveIpv4(string $host): array {
    $addresses = gethostbynamel($host);
    if (!is_array($addresses)) {
      return [];
    }
    return $addresses;
  }

  /**
   * @return string[]
   */
  private function resolveIpv6(string $host): array {
    if (!function_exists('dns_get_record')) {
      return [];
    }
    $records = dns_get_record($host, DNS_AAAA);
    $addresses = [];
    if (!is_array($records)) {
      return $addresses;
    }
    foreach ($records as $record) {
      if (isset($record['ipv6'])) {
        $addresses[] = (string) $record['ipv6'];
      }
    }
    return $addresses;
  }

  /**
   * @return array{label: string, status: string, details: string}
   */
  private function testTcp(string $label, string $target): array {
    $errorNumber = 0;
    $errorMessage = '';
    $warning = '';
    // stream_socket_client() reports details as a PHP warning; capture it instead of printing it
    set_error_handler(function (int $severity, string $message) use (&$warning): bool {
      $warning = $message;
      return true;
    });
    $startedAt = microtime(true);
    try {
      $socket = stream_socket_client($target, $errorNumber, $errorMessage, self::TIMEOUT_SECONDS);
    } finally {
      restore_error_handler();
    }
    $elapsed = $this->formatSeconds(microtime(true) - $startedAt);

    if (is_resource($socket)) {
      fclose($socket);
      return $this->row($label, self::STATUS_OK, 'Verbindung hergestellt nach ' . $elapsed);
    }
    $details = 'fehlgeschlagen nach ' . $elapsed . ': [' . $errorNumber . '] ' . $errorMessage;
    if ($warning !== '') {
      $details .= "\n" . $warning;
    }
    return $this->row($label, self::STATUS_ERROR, $details);
  }

  /**
   * @return array{label: string, status: string, details: string}
   */
  private function testWordPressRequest(string $label, string $url): array {
    $startedAt = microtime(true);
    $response = wp_remote_get($url, ['timeout' => self::TIMEOUT_SECONDS]);
    $elapsed = $this->formatSeconds(microtime(true) - $startedAt);

    if (is_wp_error($response)) {
      return $this->row($label, self::STATUS_ERROR, 'fehlgeschlagen nach ' . $elapsed . ': ' . $response->get_error_message());
    }
    $statusCode = (int) wp_remote_retrieve_response_code($response);
    $details = 'HTTP ' . $statusCode . ' nach ' . $elapsed;
    if ($statusCode >= 200 && $statusCode < 400) {
      return $this->row($label, self::STATUS_OK, $details);
    }
    $details .= "\n" . substr(trim(wp_strip_all_tags(wp_remote_retrieve_body($response))), 0, 300);
    return $this->row($label, self::STATUS_ERROR, $details);
  }

  /**
   * @return array{label: string, status: string, details: string}
   */
  private function testCurl(string $label, string $url, int $ipResolve): array {
    $handle = curl_init($url);
    $verboseStream = fopen('php://temp', 'w+');
    curl_setopt_array($handle, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
      CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
      CURLOPT_IPRESOLVE => $ipResolve,
      CURLOPT_USERAGENT => 'kornsw-pubmedcollection diagnostics',
      CURLOPT_VERBOSE => true,
      CURLOPT_STDERR => $verboseStream
    ]);
    $certificateBundle = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
    if (is_readable($certificateBundle)) {
      curl_setopt($handle, CURLOPT_CAINFO, $certificateBundle);
    }

    $body = curl_exec($handle);
    $errorNumber = curl_errno($handle);
    $errorMessage = curl_error($handle);
    $info = curl_getinfo($handle);
    curl_close($handle);

    $verboseLog = '';
    if (is_resource($verboseStream)) {
      rewind($verboseStream);
      $verboseLog = (string) stream_get_contents($verboseStream);
      fclose($verboseStream);
    }

    $primaryIp = (string) ($info['primary_ip'] ?? '');
    if ($primaryIp === '') {
      $primaryIp = '-';
    }
    $summary = 'IP ' . $primaryIp . ', Verbindung ' . $this->formatSeconds((float) $info['connect_time'])
      . ', gesamt ' . $this->formatSeconds((float) $info['total_time']);
    $verboseExcerpt = "\n\n--- cURL-Protokoll ---\n" . substr(trim($verboseLog), 0, self::MAX_VERBOSE_LENGTH);

    if ($errorNumber !== 0 || $body === false) {
      return $this->row($label, self::STATUS_ERROR, 'cURL-Fehler ' . $errorNumber . ': ' . $errorMessage . "\n" . $summary . $verboseExcerpt);
    }
    $statusCode = (int) $info['http_code'];
    $status = $this->statusFor($statusCode === 200);
    return $this->row($label, $status, 'HTTP ' . $statusCode . ', ' . $summary . $verboseExcerpt);
  }

  private function describeScheduledRefreshes(): string {
    $cronEvents = _get_cron_array();
    $descriptions = [];
    if (!is_array($cronEvents)) {
      return 'keine';
    }
    foreach ($cronEvents as $timestamp => $hooks) {
      if (!isset($hooks[PublicationService::REFRESH_HOOK])) {
        continue;
      }
      foreach ($hooks[PublicationService::REFRESH_HOOK] as $event) {
        $collectionId = (string) ($event['args'][0] ?? '?');
        $descriptions[] = $collectionId . ' (fällig ' . wp_date('d.m.Y H:i:s', (int) $timestamp) . ')';
      }
    }
    if (count($descriptions) === 0) {
      return 'keine';
    }
    return implode(', ', $descriptions);
  }

  /**
   * @return array{label: string, status: string, details: string}
   */
  private function row(string $label, string $status, string $details): array {
    return ['label' => $label, 'status' => $status, 'details' => $details];
  }

  private function statusFor(bool $isOk): string {
    if ($isOk) {
      return self::STATUS_OK;
    }
    return self::STATUS_ERROR;
  }

  /**
   * @param string[] $values
   */
  private function listOrNone(array $values): string {
    if (count($values) === 0) {
      return '(keine)';
    }
    return implode(', ', $values);
  }

  private function yesNo(bool $value): string {
    if ($value) {
      return 'ja';
    }
    return 'nein';
  }

  private function formatSeconds(float $seconds): string {
    return number_format($seconds, 2, ',', '') . ' s';
  }

}
