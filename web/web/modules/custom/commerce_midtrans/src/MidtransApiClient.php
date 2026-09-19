<?php

namespace Drupal\commerce_midtrans;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Client HTTP untuk Midtrans Snap API & Core API.
 *
 * @see https://docs.midtrans.com/reference/quick-start-1
 */
class MidtransApiClient implements MidtransApiClientInterface {

  /**
   * Environment variable server key (sandbox / default).
   */
  public const ENV_SERVER_KEY = 'MIDTRANS_SERVER_KEY';

  /**
   * Environment variable client key (sandbox / default).
   */
  public const ENV_CLIENT_KEY = 'MIDTRANS_CLIENT_KEY';

  /**
   * Environment variable server key production.
   */
  public const ENV_SERVER_KEY_LIVE = 'MIDTRANS_SERVER_KEY_LIVE';

  /**
   * Environment variable client key production.
   */
  public const ENV_CLIENT_KEY_LIVE = 'MIDTRANS_CLIENT_KEY_LIVE';

  /**
   * Environment variable mode aktivasi (sandbox|production).
   */
  public const ENV_MODE = 'MIDTRANS_MODE';

  /**
   * Mode sandbox.
   */
  public const MODE_SANDBOX = 'sandbox';

  /**
   * Mode production.
   */
  public const MODE_PRODUCTION = 'production';

  /**
   * URL default Core API sandbox.
   */
  public const SANDBOX_API_URL = 'https://api.sandbox.midtrans.com';

  /**
   * URL default Core API production.
   */
  public const PRODUCTION_API_URL = 'https://api.midtrans.com';

  /**
   * URL default Snap API sandbox.
   */
  public const SANDBOX_SNAP_URL = 'https://app.sandbox.midtrans.com/snap/v1';

  /**
   * URL default Snap API production.
   */
  public const PRODUCTION_SNAP_URL = 'https://app.midtrans.com/snap/v1';

  /**
   * URL default snap.js sandbox.
   */
  public const SANDBOX_SNAP_JS_URL = 'https://app.sandbox.midtrans.com/snap/snap.js';

  /**
   * URL default snap.js production.
   */
  public const PRODUCTION_SNAP_JS_URL = 'https://app.midtrans.com/snap/snap.js';

  /**
   * Constructs a new MidtransApiClient object.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger channel commerce_midtrans.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {
  }

  /**
   * Menormalkan nama mode menjadi "sandbox"/"production".
   *
   * @param string|null $mode
   *   Nilai mode: test/sandbox/dev, live/production, atau NULL.
   *
   * @return string
   *   "sandbox" atau "production".
   */
  public static function normalizeMode(?string $mode): string {
    $mode = strtolower(trim((string) $mode));
    if (in_array($mode, ['production', 'prod', 'live', 'liven'], TRUE)) {
      return self::MODE_PRODUCTION;
    }
    return self::MODE_SANDBOX;
  }

  /**
   * Membuat signature notifikasi Midtrans.
   *
   * SHA512(order_id + status_code + gross_amount + ServerKey).
   *
   * @param string $order_id
   *   order_id dari Midtrans.
   * @param string $status_code
   *   status_code dari notifikasi.
   * @param string $gross_amount
   *   gross_amount dari notifikasi (string apa adanya).
   * @param string $server_key
   *   Server key.
   *
   * @return string
   *   Hash SHA512.
   */
  public static function buildSignature(string $order_id, string $status_code, string $gross_amount, string $server_key): string {
    return hash('sha512', $order_id . $status_code . $gross_amount . $server_key);
  }

  /**
   * Memverifikasi signature_key pada notifikasi Midtrans.
   *
   * @param array $notification
   *   Body notifikasi (order_id, status_code, gross_amount, signature_key).
   * @param string $server_key
   *   Server key pada mode gateway.
   *
   * @return bool
   *   TRUE bila signature valid.
   */
  public static function verifySignature(array $notification, string $server_key): bool {
    if ($server_key === '') {
      return FALSE;
    }
    foreach (['order_id', 'status_code', 'gross_amount', 'signature_key'] as $key) {
      if (!isset($notification[$key]) || $notification[$key] === '') {
        return FALSE;
      }
    }
    $expected = static::buildSignature(
      (string) $notification['order_id'],
      (string) $notification['status_code'],
      (string) $notification['gross_amount'],
      $server_key
    );

    return hash_equals($expected, (string) $notification['signature_key']);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultMode(): string {
    $env = getenv(self::ENV_MODE);
    if (is_string($env) && trim($env) !== '') {
      return static::normalizeMode($env);
    }
    $config = (string) $this->getSettings()->get('default_mode');

    return static::normalizeMode($config !== '' ? $config : self::MODE_SANDBOX);
  }

  /**
   * {@inheritdoc}
   */
  public function getServerKey(?string $mode = NULL): string {
    return $this->getCredential('server_key', static::normalizeMode($mode ?? $this->getDefaultMode()));
  }

  /**
   * {@inheritdoc}
   */
  public function getClientKey(?string $mode = NULL): string {
    return $this->getCredential('client_key', static::normalizeMode($mode ?? $this->getDefaultMode()));
  }

  /**
   * {@inheritdoc}
   */
  public function getCredentialSource(?string $mode = NULL): string {
    $mode = static::normalizeMode($mode ?? $this->getDefaultMode());

    $env_names = $mode === self::MODE_PRODUCTION
      ? [self::ENV_SERVER_KEY_LIVE, self::ENV_SERVER_KEY]
      : [self::ENV_SERVER_KEY];
    foreach ($env_names as $index => $env_name) {
      $value = getenv($env_name);
      if (is_string($value) && trim($value) !== '') {
        return $index === 0 && $mode === self::MODE_PRODUCTION ? 'environment-live' : 'environment';
      }
    }

    $settings_key = Settings::get('commerce_midtrans_server_key');
    if (is_string($settings_key) && trim($settings_key) !== '') {
      return 'settings.php';
    }

    if (trim((string) $this->getSettings()->get('server_key')) !== '') {
      return 'config';
    }

    return 'none';
  }

  /**
   * {@inheritdoc}
   */
  public function getApiBaseUrl(?string $mode = NULL): string {
    $mode = static::normalizeMode($mode ?? $this->getDefaultMode());
    $sandbox = $mode === self::MODE_SANDBOX;
    $configured = (string) $this->getSettings()->get($sandbox ? 'api_base_url_sandbox' : 'api_base_url_production');
    $default = $sandbox ? self::SANDBOX_API_URL : self::PRODUCTION_API_URL;

    return rtrim($configured !== '' ? $configured : $default, '/');
  }

  /**
   * {@inheritdoc}
   */
  public function getSnapBaseUrl(?string $mode = NULL): string {
    $mode = static::normalizeMode($mode ?? $this->getDefaultMode());
    $sandbox = $mode === self::MODE_SANDBOX;
    $configured = (string) $this->getSettings()->get($sandbox ? 'snap_base_url_sandbox' : 'snap_base_url_production');
    $default = $sandbox ? self::SANDBOX_SNAP_URL : self::PRODUCTION_SNAP_URL;

    return rtrim($configured !== '' ? $configured : $default, '/');
  }

  /**
   * {@inheritdoc}
   */
  public function getSnapJsUrl(?string $mode = NULL): string {
    $mode = static::normalizeMode($mode ?? $this->getDefaultMode());
    $sandbox = $mode === self::MODE_SANDBOX;
    $configured = (string) $this->getSettings()->get($sandbox ? 'snap_js_url_sandbox' : 'snap_js_url_production');
    $default = $sandbox ? self::SANDBOX_SNAP_JS_URL : self::PRODUCTION_SNAP_JS_URL;

    return $configured !== '' ? $configured : $default;
  }

  /**
   * {@inheritdoc}
   */
  public function isConfigured(?string $mode = NULL): bool {
    return $this->getServerKey($mode) !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function createTransaction(array $params, ?string $mode = NULL): array {
    $mode = static::normalizeMode($mode ?? $this->getDefaultMode());
    $server_key = $this->assertConfigured($mode);

    $result = $this->send('POST', $this->getSnapBaseUrl($mode) . '/transactions', $server_key, $params);
    if ($result['status'] < 200 || $result['status'] >= 300) {
      throw new MidtransApiException($this->formatError($result, 'Gagal membuat transaksi Snap'), $result['status']);
    }
    if (empty($result['data']['token']) || empty($result['data']['redirect_url'])) {
      throw new MidtransApiException('Response Snap Midtrans tidak memuat token/redirect_url.');
    }

    return $result['data'];
  }

  /**
   * {@inheritdoc}
   */
  public function getTransactionStatus(string $midtrans_order_id, ?string $mode = NULL): array {
    $mode = static::normalizeMode($mode ?? $this->getDefaultMode());
    $server_key = $this->assertConfigured($mode);

    $url = $this->getApiBaseUrl($mode) . '/v2/' . rawurlencode($midtrans_order_id) . '/status';
    $result = $this->send('GET', $url, $server_key);
    if ($result['status'] === 404) {
      throw new MidtransApiException(sprintf('Transaksi Midtrans "%s" belum ada di sisi Midtrans (HTTP 404). Pembeli belum memilih metode pembayaran.', $midtrans_order_id), 404);
    }
    if ($result['status'] < 200 || $result['status'] >= 300) {
      throw new MidtransApiException($this->formatError($result, 'Gagal mengambil status transaksi Midtrans'), $result['status']);
    }

    return $result['data'];
  }

  /**
   * {@inheritdoc}
   */
  public function cancelTransaction(string $midtrans_order_id, ?string $mode = NULL): array {
    $mode = static::normalizeMode($mode ?? $this->getDefaultMode());
    $server_key = $this->assertConfigured($mode);

    $url = $this->getApiBaseUrl($mode) . '/v2/' . rawurlencode($midtrans_order_id) . '/cancel';
    $result = $this->send('POST', $url, $server_key, []);
    if ($result['status'] < 200 || $result['status'] >= 300) {
      throw new MidtransApiException($this->formatError($result, 'Gagal membatalkan transaksi Midtrans'), $result['status']);
    }

    return $result['data'];
  }

  /**
   * {@inheritdoc}
   */
  public function refundTransaction(string $midtrans_order_id, array $params = [], ?string $mode = NULL): array {
    $mode = static::normalizeMode($mode ?? $this->getDefaultMode());
    $server_key = $this->assertConfigured($mode);

    $body = [];
    if (isset($params['amount'])) {
      $body['amount'] = (int) round((float) $params['amount']);
    }
    if (!empty($params['reason'])) {
      $body['reason'] = (string) $params['reason'];
    }

    $url = $this->getApiBaseUrl($mode) . '/v2/' . rawurlencode($midtrans_order_id) . '/refund';
    $result = $this->send('POST', $url, $server_key, $body);
    if ($result['status'] < 200 || $result['status'] >= 300) {
      throw new MidtransApiException($this->formatError($result, 'Gagal melakukan refund ke Midtrans'), $result['status']);
    }

    return $result['data'];
  }

  /**
   * {@inheritdoc}
   */
  public function testCredentials(?string $mode = NULL): array {
    $mode = static::normalizeMode($mode ?? $this->getDefaultMode());
    $server_key = $this->getServerKey($mode);
    if ($server_key === '') {
      return [
        'ok' => FALSE,
        'message' => sprintf('Server key untuk mode %s belum diisi (env MIDTRANS_SERVER_KEY / MIDTRANS_SERVER_KEY_LIVE).', $mode),
        'status_code' => 0,
      ];
    }

    // Memanggil Core API dengan order_id yang tidak ada: 200/404 berarti
    // kredensial diterima, 401 berarti server key ditolak.
    $test_id = 'commerce-midtrans-connection-test-' . time();
    $url = $this->getApiBaseUrl($mode) . '/v2/' . rawurlencode($test_id) . '/status';
    try {
      $result = $this->send('GET', $url, $server_key);
    }
    catch (MidtransApiException $e) {
      return ['ok' => FALSE, 'message' => $e->getMessage(), 'status_code' => 0];
    }

    if (in_array($result['status'], [200, 404], TRUE)) {
      $message = sprintf('Server key mode %s valid (HTTP %d dari Midtrans).', $mode, $result['status']);
      if ($this->getClientKey($mode) === '') {
        $message .= ' Client key belum diisi (dibutuhkan untuk snap.js).';
      }
      return ['ok' => TRUE, 'message' => $message, 'status_code' => $result['status']];
    }
    if ($result['status'] === 401) {
      return [
        'ok' => FALSE,
        'message' => sprintf('Server key mode %s ditolak Midtrans (HTTP 401). Periksa kembali kredensial di .env.', $mode),
        'status_code' => 401,
      ];
    }

    return [
      'ok' => FALSE,
      'message' => $this->formatError($result, 'Tes kredensial Midtrans gagal'),
      'status_code' => $result['status'],
    ];
  }

  /**
   * Mengambil server/client key dari env → settings.php → config.
   *
   * @param string $type
   *   "server_key" atau "client_key".
   * @param string $mode
   *   Mode yang sudah dinormalkan (sandbox/production).
   *
   * @return string
   *   Kredensial, atau string kosong.
   */
  protected function getCredential(string $type, string $mode): string {
    $server = $type === 'server_key';
    if ($mode === self::MODE_PRODUCTION) {
      $env_names = $server
        ? [self::ENV_SERVER_KEY_LIVE, self::ENV_SERVER_KEY]
        : [self::ENV_CLIENT_KEY_LIVE, self::ENV_CLIENT_KEY];
    }
    else {
      $env_names = $server ? [self::ENV_SERVER_KEY] : [self::ENV_CLIENT_KEY];
    }

    foreach ($env_names as $env_name) {
      $value = getenv($env_name);
      if (is_string($value) && trim($value) !== '') {
        return trim($value);
      }
    }

    $settings_key = Settings::get($server ? 'commerce_midtrans_server_key' : 'commerce_midtrans_client_key');
    if (is_string($settings_key) && trim($settings_key) !== '') {
      return trim($settings_key);
    }

    return trim((string) $this->getSettings()->get($type));
  }

  /**
   * Memastikan server key tersedia untuk mode tertentu.
   *
   * @param string $mode
   *   Mode yang sudah dinormalkan.
   *
   * @return string
   *   Server key.
   *
   * @throws \Drupal\commerce_midtrans\MidtransApiException
   *   Bila server key kosong.
   */
  protected function assertConfigured(string $mode): string {
    $server_key = $this->getServerKey($mode);
    if ($server_key === '') {
      throw new MidtransApiException(sprintf(
        'Server key Midtrans mode %s belum diisi. Isi %s di web/.env atau pada /admin/commerce/config/midtrans.',
        $mode,
        $mode === self::MODE_PRODUCTION ? 'MIDTRANS_SERVER_KEY_LIVE' : 'MIDTRANS_SERVER_KEY'
      ));
    }
    return $server_key;
  }

  /**
   * Mengirim request ke Midtrans (tanpa melempar exception HTTP).
   *
   * @param string $method
   *   HTTP method.
   * @param string $url
   *   URL lengkap.
   * @param string $server_key
   *   Server key (Basic auth).
   * @param array|null $body
   *   Body JSON (NULL = tanpa body).
   *
   * @return array
   *   ['status' => int, 'data' => array, 'raw' => string].
   *
   * @throws \Drupal\commerce_midtrans\MidtransApiException
   *   Bila koneksi gagal.
   */
  protected function send(string $method, string $url, string $server_key, ?array $body = NULL): array {
    $options = [
      'headers' => [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
        'Authorization' => 'Basic ' . base64_encode($server_key . ':'),
      ],
      'timeout' => $this->getTimeout(),
      'connect_timeout' => 10,
      'http_errors' => FALSE,
    ];
    if ($body !== NULL) {
      $options['body'] = json_encode($body);
    }

    try {
      $response = $this->httpClient->request($method, $url, $options);
    }
    catch (GuzzleException $e) {
      throw new MidtransApiException('Tidak dapat menghubungi Midtrans: ' . $e->getMessage(), 0, $e);
    }

    $status = $response->getStatusCode();
    $raw = (string) $response->getBody();
    $decoded = json_decode($raw, TRUE);

    if ($this->getSettings()->get('log_requests')) {
      $this->logger->debug('Midtrans @method @url → HTTP @status: @body', [
        '@method' => $method,
        '@url' => $url,
        '@status' => $status,
        '@body' => mb_substr($raw, 0, 2000),
      ]);
    }

    return [
      'status' => $status,
      'data' => is_array($decoded) ? $decoded : [],
      'raw' => $raw,
    ];
  }

  /**
   * Menyusun pesan error dari response Midtrans.
   *
   * @param array $result
   *   Hasil send().
   * @param string $label
   *   Label error.
   *
   * @return string
   *   Pesan error.
   */
  protected function formatError(array $result, string $label): string {
    $message = (string) ($result['data']['status_message'] ?? '');
    if ($message === '') {
      $message = $result['raw'] !== '' ? mb_substr($result['raw'], 0, 300) : 'tanpa pesan';
    }
    $validation = '';
    if (!empty($result['data']['validation_messages'])) {
      $validation = ' ' . json_encode($result['data']['validation_messages']);
    }

    return sprintf('%s (HTTP %d): %s%s', $label, $result['status'], $message, $validation);
  }

  /**
   * Timeout request (detik).
   *
   * @return float
   *   Timeout.
   */
  protected function getTimeout(): float {
    return (float) ($this->getSettings()->get('timeout') ?: 20);
  }

  /**
   * Config objek commerce_midtrans.settings.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   Config objek.
   */
  protected function getSettings() {
    return $this->configFactory->get('commerce_midtrans.settings');
  }

}
