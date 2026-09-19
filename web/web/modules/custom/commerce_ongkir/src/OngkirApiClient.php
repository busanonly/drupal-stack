<?php

namespace Drupal\commerce_ongkir;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Client HTTP untuk API Cek Ongkir v2 api.co.id.
 */
class OngkirApiClient implements OngkirApiClientInterface {

  /**
   * Endpoint tarif v2 (berbasis kode kecamatan).
   */
  public const RATES_PATH = '/courier/v2/rates';

  /**
   * Endpoint daftar provinsi.
   */
  public const PROVINCES_PATH = '/courier/v1/locations/provinces';

  /**
   * Endpoint daftar kota/kabupaten.
   */
  public const CITIES_PATH = '/courier/v1/locations/cities';

  /**
   * Endpoint daftar kecamatan.
   */
  public const DISTRICTS_PATH = '/courier/v1/locations/districts';

  /**
   * Endpoint daftar kurir.
   */
  public const COURIERS_PATH = '/courier/v1/couriers';

  /**
   * Nama header API key yang diwajibkan API.
   */
  public const API_KEY_HEADER = 'x-api-co-id';

  /**
   * Nama environment variable (diisi dari web/.env lewat docker compose).
   */
  public const API_KEY_ENV = 'API_CO_ID_KEY';

  /**
   * Base URL default.
   */
  public const DEFAULT_BASE_URL = 'https://use.api.co.id';

  /**
   * Constructs a new OngkirApiClient object.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   Cache bin commerce_ongkir.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger channel commerce_ongkir.
   * @param \Drupal\Core\Datetime\TimeInterface $time
   *   Time service.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected CacheBackendInterface $cache,
    protected LoggerInterface $logger,
    protected TimeInterface $time,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function isConfigured(): bool {
    return $this->getApiKey() !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function getApiKey(): string {
    $key = getenv(self::API_KEY_ENV);
    if (is_string($key) && trim($key) !== '') {
      return trim($key);
    }

    $key = Settings::get('commerce_ongkir_api_key');
    if (is_string($key) && trim($key) !== '') {
      return trim($key);
    }

    return trim((string) $this->getSettings()->get('api_key'));
  }

  /**
   * {@inheritdoc}
   */
  public function getApiKeySource(): string {
    $env = getenv(self::API_KEY_ENV);
    if (is_string($env) && trim($env) !== '') {
      return 'environment';
    }
    $settings = Settings::get('commerce_ongkir_api_key');
    if (is_string($settings) && trim($settings) !== '') {
      return 'settings.php';
    }
    if (trim((string) $this->getSettings()->get('api_key')) !== '') {
      return 'config';
    }
    return 'none';
  }

  /**
   * {@inheritdoc}
   */
  public function getRates(string $origin_district_code, string $destination_district_code, float $weight_kg, array $options = [], int $cache_ttl = 0): array {
    $query = [
      'origin_district_code' => $origin_district_code,
      'destination_district_code' => $destination_district_code,
      'weight' => $this->formatNumber($weight_kg),
    ];
    // Opsi tambahan sesuai dokumentasi: item_value, insurance, length, width,
    // height.
    foreach (['item_value', 'insurance', 'length', 'width', 'height'] as $key) {
      if (!array_key_exists($key, $options) || $options[$key] === '' || $options[$key] === NULL) {
        continue;
      }
      $query[$key] = $key === 'insurance'
        ? ($options[$key] ? 'true' : 'false')
        : $options[$key];
    }

    $cache_key = $cache_ttl > 0 ? 'rates:' . md5(json_encode($query) ?: '') : NULL;
    return $this->request(self::RATES_PATH, $query, $cache_key, $cache_ttl);
  }

  /**
   * {@inheritdoc}
   */
  public function getProvinces(): array {
    return $this->request(self::PROVINCES_PATH, [], 'locations:provinces', $this->getLocationCacheTtl());
  }

  /**
   * {@inheritdoc}
   */
  public function getCities(string $province_code): array {
    if ($province_code === '') {
      return [];
    }
    return $this->request(self::CITIES_PATH, ['province' => $province_code], 'locations:cities:' . $province_code, $this->getLocationCacheTtl());
  }

  /**
   * {@inheritdoc}
   */
  public function getDistricts(string $city_code): array {
    if ($city_code === '') {
      return [];
    }
    return $this->request(self::DISTRICTS_PATH, ['city' => $city_code], 'locations:districts:' . $city_code, $this->getLocationCacheTtl());
  }

  /**
   * {@inheritdoc}
   */
  public function getCouriers(): array {
    return $this->request(self::COURIERS_PATH, [], 'couriers', $this->getLocationCacheTtl());
  }

  /**
   * Mengubah daftar lokasi/kurir menjadi array [code => name].
   *
   * @param array $items
   *   Data mentah dari API.
   * @param string $name_suffix
   *   Teks tambahan setelah nama (opsional).
   *
   * @return array
   *   Array keyed by code.
   */
  public function toOptions(array $items, string $name_suffix = ''): array {
    $options = [];
    foreach ($items as $item) {
      if (!is_array($item) || empty($item['code'])) {
        continue;
      }
      $options[(string) $item['code']] = (string) ($item['name'] ?? $item['code']) . $name_suffix;
    }
    return $options;
  }

  /**
   * Mengirim request GET ke API dan mengembalikan bagian "data".
   *
   * @param string $path
   *   Path endpoint, contoh "/courier/v2/rates".
   * @param array $query
   *   Query string.
   * @param string|null $cache_key
   *   Key cache (NULL = jangan cache).
   * @param int $cache_ttl
   *   TTL cache dalam detik.
   *
   * @return array
   *   Isi key "data" dari response API.
   *
   * @throws \Drupal\commerce_ongkir\OngkirApiException
   *   Bila API key kosong atau API mengembalikan error.
   */
  protected function request(string $path, array $query, ?string $cache_key, int $cache_ttl): array {
    $api_key = $this->getApiKey();
    if ($api_key === '') {
      throw new OngkirApiException('API key Cek Ongkir belum diisi. Isi API_CO_ID_KEY di web/.env, atau simpan pada /admin/commerce/config/ongkir.');
    }

    $cid = $cache_key !== NULL ? 'commerce_ongkir:' . $cache_key : NULL;
    if ($cid !== NULL && ($cache = $this->cache->get($cid))) {
      return is_array($cache->data) ? $cache->data : [];
    }

    $config = $this->getSettings();
    $base_url = rtrim((string) $config->get('api_base_url'), '/');
    if ($base_url === '') {
      $base_url = self::DEFAULT_BASE_URL;
    }
    $timeout = (float) ($config->get('timeout') ?: 15);

    if ($config->get('log_requests')) {
      $this->logger->debug('Ongkir request: GET @url @query', [
        '@url' => $base_url . $path,
        '@query' => json_encode($query),
      ]);
    }

    try {
      $response = $this->httpClient->request('GET', $base_url . $path, [
        'headers' => [
          self::API_KEY_HEADER => $api_key,
          'Accept' => 'application/json',
        ],
        'query' => $query,
        'timeout' => $timeout,
        'connect_timeout' => 10,
      ]);
    }
    catch (RequestException $e) {
      $response = $e->getResponse();
      $status_code = $response ? $response->getStatusCode() : 0;
      $body = $response ? (string) $response->getBody() : '';
      $message = $this->extractMessage($body) ?: $e->getMessage();
      // 402 = saldo kurang, 401 = API key salah, 429 = rate limit.
      $hint = match ($status_code) {
        401 => ' Periksa API_CO_ID_KEY di web/.env.',
        402 => ' Saldo akun api.co.id tidak mencukupi.',
        429 => ' Terlalu banyak request, coba lagi nanti.',
        default => '',
      };
      throw new OngkirApiException(sprintf('API Cek Ongkir gagal (HTTP %s): %s.%s', $status_code ?: 'n/a', $message, $hint), $status_code, $e);
    }
    catch (GuzzleException $e) {
      throw new OngkirApiException('Tidak dapat menghubungi API Cek Ongkir: ' . $e->getMessage(), 0, $e);
    }

    $payload = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($payload)) {
      throw new OngkirApiException('Response API Cek Ongkir bukan JSON yang valid.');
    }
    if (empty($payload['is_success'])) {
      $message = $this->extractMessage((string) $response->getBody()) ?: 'unknown error';
      $this->logger->error('Ongkir API error: @message', ['@message' => $message]);
      throw new OngkirApiException('API Cek Ongkir menolak permintaan: ' . $message);
    }

    $data = $payload['data'] ?? [];
    $data = is_array($data) ? $data : [];
    if ($cid !== NULL && $cache_ttl > 0) {
      $this->cache->set($cid, $data, $this->time->getRequestTime() + $cache_ttl);
    }

    return $data;
  }

  /**
   * Mengambil pesan error dari body response.
   *
   * @param string $body
   *   Body response.
   *
   * @return string
   *   Pesan, atau string kosong.
   */
  protected function extractMessage(string $body): string {
    $decoded = json_decode($body, TRUE);
    if (is_array($decoded) && !empty($decoded['message'])) {
      return (string) $decoded['message'];
    }
    return '';
  }

  /**
   * TTL cache untuk endpoint lokasi/kurir (gratis).
   *
   * @return int
   *   TTL dalam detik.
   */
  protected function getLocationCacheTtl(): int {
    return (int) ($this->getSettings()->get('cache_ttl') ?: 86400);
  }

  /**
   * Config objek commerce_ongkir.settings.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   Config objek.
   */
  protected function getSettings() {
    return $this->configFactory->get('commerce_ongkir.settings');
  }

  /**
   * Format angka menjadi string desimal dengan titik sebagai pemisah.
   *
   * @param float $number
   *   Angka.
   *
   * @return string
   *   String angka.
   */
  protected function formatNumber(float $number): string {
    $number = rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    return $number === '' ? '0' : $number;
  }

}
