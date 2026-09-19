<?php

namespace Drupal\commerce_ongkir\Plugin\Commerce\ShippingMethod;

use Drupal\commerce_ongkir\DistrictResolverInterface;
use Drupal\commerce_ongkir\OngkirApiClientInterface;
use Drupal\commerce_ongkir\OngkirApiException;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Attribute\CommerceShippingMethod;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\PackageTypeManagerInterface;
use Drupal\commerce_shipping\Plugin\Commerce\ShippingMethod\ShippingMethodBase;
use Drupal\commerce_shipping\ShippingRate;
use Drupal\commerce_shipping\ShippingService;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\physical\Weight;
use Drupal\physical\WeightUnit;
use Drupal\state_machine\WorkflowManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shipping method yang mengambil tarif dari API Cek Ongkir v2 api.co.id.
 *
 * Endpoint: GET https://use.api.co.id/courier/v2/rates
 * Dokumentasi: https://docs.api.co.id/products/indonesia-courier-rates/
 */
#[CommerceShippingMethod(
  id: 'ongkir',
  label: new TranslatableMarkup('Ongkir (API.co.id)'),
  services: ['ongkir' => 'Ongkir (semua kurir)'],
)]
class Ongkir extends ShippingMethodBase {

  /**
   * Client API Cek Ongkir.
   *
   * @var \Drupal\commerce_ongkir\OngkirApiClientInterface
   */
  protected OngkirApiClientInterface $apiClient;

  /**
   * Penentu kode kecamatan tujuan.
   *
   * @var \Drupal\commerce_ongkir\DistrictResolverInterface
   */
  protected DistrictResolverInterface $districtResolver;

  /**
   * Cache bin commerce_ongkir.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected CacheBackendInterface $cache;

  /**
   * Time service.
   *
   * @var \Drupal\Core\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * Logger channel commerce_ongkir.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a new Ongkir object.
   *
   * @param array $configuration
   *   Konfigurasi plugin.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Definisi plugin.
   * @param \Drupal\commerce_shipping\PackageTypeManagerInterface $package_type_manager
   *   Package type manager.
   * @param \Drupal\state_machine\WorkflowManagerInterface $workflow_manager
   *   Workflow manager.
   * @param \Drupal\commerce_ongkir\OngkirApiClientInterface $api_client
   *   Client API ongkir.
   * @param \Drupal\commerce_ongkir\DistrictResolverInterface $district_resolver
   *   Resolver kecamatan.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   Cache bin commerce_ongkir.
   * @param \Drupal\Core\Datetime\TimeInterface $time
   *   Time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger channel commerce_ongkir.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, PackageTypeManagerInterface $package_type_manager, WorkflowManagerInterface $workflow_manager, OngkirApiClientInterface $api_client, DistrictResolverInterface $district_resolver, CacheBackendInterface $cache, TimeInterface $time, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $package_type_manager, $workflow_manager);

    $this->apiClient = $api_client;
    $this->districtResolver = $district_resolver;
    $this->cache = $cache;
    $this->time = $time;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.commerce_package_type'),
      $container->get('plugin.manager.workflow'),
      $container->get('commerce_ongkir.api_client'),
      $container->get('commerce_ongkir.district_resolver'),
      $container->get('cache.commerce_ongkir'),
      $container->get('datetime.time'),
      $container->get('logger.channel.commerce_ongkir'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'origin_district_code' => '',
      'courier_filter' => '',
      'service_filter' => '',
      'insurance' => FALSE,
      'item_value_mode' => 'declared_value',
      'district_source' => 'profile_field',
      'district_field' => 'field_kecamatan',
      'map_key' => 'postal_code',
      'district_map' => '',
      'weight_field' => 'field_berat',
      'weight_unit' => 'auto',
      'default_weight' => '1',
      'markup_percent' => '0',
      'markup_flat' => '0',
      'max_rates' => 0,
      'rate_label_pattern' => '[courier] - [service]',
      'cache_ttl' => 300,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $courier_hint = '';
    if ($this->apiClient->isConfigured()) {
      try {
        $codes = [];
        foreach ($this->apiClient->getCouriers() as $courier) {
          if (!empty($courier['code'])) {
            $codes[] = $courier['code'];
          }
        }
        if ($codes) {
          $courier_hint = ' ' . $this->t('Kurir yang tersedia: @list.', ['@list' => implode(', ', $codes)]);
        }
      }
      catch (OngkirApiException $e) {
        $courier_hint = ' ' . $this->t('Daftar kurir belum bisa diambil: @message', ['@message' => $e->getMessage()]);
      }
    }

    $form['ongkir_api'] = [
      '#type' => 'details',
      '#title' => $this->t('API Cek Ongkir v2'),
      '#open' => TRUE,
    ];
    $form['ongkir_api']['origin_district_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Kode kecamatan asal (origin_district_code)'),
      '#description' => $this->t('Kode kecamatan gudang/toko, contoh <code>317405</code>. Daftar kode diambil dari <code>GET /courier/v1/locations/provinces</code> → <code>/cities?province=</code> → <code>/districts?city=</code>.'),
      '#default_value' => $this->configuration['origin_district_code'],
      '#required' => TRUE,
      '#size' => 16,
      '#maxlength' => 16,
    ];
    $form['ongkir_api']['courier_filter'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Filter kurir'),
      '#description' => $this->t('Daftar kode kurir yang ditampilkan, dipisah koma (contoh: <code>jne,jnt,sicepat</code>). Kosongkan untuk semua kurir.') . $courier_hint,
      '#default_value' => $this->configuration['courier_filter'],
    ];
    $form['ongkir_api']['service_filter'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Filter layanan'),
      '#description' => $this->t('Daftar kode layanan (service_code) yang ditampilkan, dipisah koma (contoh: <code>reg,reg1</code>). Kosongkan untuk semua layanan.'),
      '#default_value' => $this->configuration['service_filter'],
    ];
    $form['ongkir_api']['insurance'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hitung biaya asuransi per kurir'),
      '#default_value' => !empty($this->configuration['insurance']),
    ];
    $form['ongkir_api']['item_value_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Nilai barang (item_value)'),
      '#options' => [
        'declared_value' => $this->t('Kirim total nilai barang dari shipment'),
        'none' => $this->t('Jangan kirim nilai barang'),
      ],
      '#default_value' => $this->configuration['item_value_mode'],
    ];
    $form['ongkir_api']['max_rates'] = [
      '#type' => 'number',
      '#title' => $this->t('Maksimum tarif yang ditampilkan'),
      '#description' => $this->t('0 = tampilkan semua tarif. Tarif termurah yang dipakai bila dibatasi.'),
      '#default_value' => $this->configuration['max_rates'],
      '#min' => 0,
    ];
    $form['ongkir_api']['cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache TTL tarif (detik)'),
      '#description' => $this->t('Cache mencegah pemanggilan API berulang (Rp 5 per panggilan sukses). 0 = tanpa cache.'),
      '#default_value' => $this->configuration['cache_ttl'],
      '#min' => 0,
    ];

    $form['ongkir_destination'] = [
      '#type' => 'details',
      '#title' => $this->t('Kode kecamatan tujuan'),
      '#open' => TRUE,
    ];
    $form['ongkir_destination']['district_source'] = [
      '#type' => 'select',
      '#title' => $this->t('Sumber kode kecamatan tujuan'),
      '#options' => [
        'profile_field' => $this->t('Field pada profile pengiriman (mis. field_kecamatan)'),
        'order_field' => $this->t('Field pada order'),
        'address_dependent_locality' => $this->t('Sub-field dependent_locality pada alamat'),
        'map' => $this->t('Peta manual (postal code / nama kota)'),
      ],
      '#default_value' => $this->configuration['district_source'],
      '#required' => TRUE,
    ];
    $form['ongkir_destination']['district_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nama field kode kecamatan'),
      '#description' => $this->t('Dipakai untuk sumber "field pada profile/order", contoh <code>field_kecamatan</code>. Field dibuat otomatis oleh modul ini pada profile customer.'),
      '#default_value' => $this->configuration['district_field'],
      '#size' => 40,
      '#maxlength' => 64,
    ];
    $form['ongkir_destination']['map_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Kunci peta manual'),
      '#options' => [
        'postal_code' => $this->t('Kode pos alamat'),
        'locality' => $this->t('Kota/kabupaten alamat (locality)'),
        'dependent_locality' => $this->t('dependent_locality alamat'),
      ],
      '#default_value' => $this->configuration['map_key'],
    ];
    $form['ongkir_destination']['district_map'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Peta manual'),
      '#description' => $this->t('Satu pasangan per baris: <code>kunci=kode_kecamatan</code>. Contoh: <code>12140=317405</code> atau <code>jakarta selatan=317305</code>. Hanya dipakai bila sumber = "Peta manual" atau sebagai fallback.'),
      '#default_value' => $this->configuration['district_map'],
      '#rows' => 5,
    ];

    $form['ongkir_weight'] = [
      '#type' => 'details',
      '#title' => $this->t('Berat paket'),
      '#open' => FALSE,
    ];
    $form['ongkir_weight']['weight_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Field berat pada produk'),
      '#description' => $this->t('Dipakai bila berat shipment belum dihitung Commerce (field <code>weight</code> kosong), contoh <code>field_berat</code>. Nilai boleh berisi teks, mis. "500 gram" atau "1,5 kg".'),
      '#default_value' => $this->configuration['weight_field'],
      '#size' => 40,
      '#maxlength' => 64,
    ];
    $form['ongkir_weight']['weight_unit'] = [
      '#type' => 'select',
      '#title' => $this->t('Satuan field berat'),
      '#options' => [
        'auto' => $this->t('Deteksi otomatis dari teks (kg/gram)'),
        'kg' => $this->t('Kilogram'),
        'g' => $this->t('Gram'),
      ],
      '#default_value' => $this->configuration['weight_unit'],
    ];
    $form['ongkir_weight']['default_weight'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Berat default per unit (kg)'),
      '#description' => $this->t('Dipakai bila berat produk tidak diketahui. Total berat minimal 0,1 kg sesuai aturan API.'),
      '#default_value' => $this->configuration['default_weight'],
      '#size' => 10,
    ];

    $form['ongkir_price'] = [
      '#type' => 'details',
      '#title' => $this->t('Label dan markup tarif'),
      '#open' => FALSE,
    ];
    $form['ongkir_price']['rate_label_pattern'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pola label tarif'),
      '#description' => $this->t('Token tersedia: <code>[courier]</code>, <code>[service]</code>, <code>[etd]</code>, <code>[code]</code>.'),
      '#default_value' => $this->configuration['rate_label_pattern'],
    ];
    $form['ongkir_price']['markup_percent'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Markup persen (%)'),
      '#default_value' => $this->configuration['markup_percent'],
      '#size' => 10,
    ];
    $form['ongkir_price']['markup_flat'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Markup nominal (Rp)'),
      '#default_value' => $this->configuration['markup_flat'],
      '#size' => 15,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    if ($form_state->getErrors()) {
      return;
    }
    $values = $form_state->getValue($form['#parents']);

    $origin = trim((string) $values['ongkir_api']['origin_district_code']);
    if (\Drupal\commerce_ongkir\DistrictResolver::normalizeCode($origin) === NULL) {
      $form_state->setError($form['ongkir_api']['origin_district_code'], $this->t('Kode kecamatan asal harus berupa angka minimal 6 digit, contoh 317405.'));
    }
    foreach (['markup_percent', 'markup_flat'] as $key) {
      $value = trim((string) $values['ongkir_price'][$key]);
      if ($value !== '' && !is_numeric($value)) {
        $form_state->setError($form['ongkir_price'][$key], $this->t('Nilai markup harus berupa angka.'));
      }
    }
    $default_weight = trim((string) $values['ongkir_weight']['default_weight']);
    if ($default_weight !== '' && (!is_numeric($default_weight) || (float) $default_weight < 0)) {
      $form_state->setError($form['ongkir_weight']['default_weight'], $this->t('Berat default harus berupa angka >= 0.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if ($form_state->getErrors()) {
      return;
    }
    $values = $form_state->getValue($form['#parents']);

    $this->configuration['origin_district_code'] = trim((string) $values['ongkir_api']['origin_district_code']);
    $this->configuration['courier_filter'] = trim((string) $values['ongkir_api']['courier_filter']);
    $this->configuration['service_filter'] = trim((string) $values['ongkir_api']['service_filter']);
    $this->configuration['insurance'] = (bool) $values['ongkir_api']['insurance'];
    $this->configuration['item_value_mode'] = (string) $values['ongkir_api']['item_value_mode'];
    $this->configuration['max_rates'] = (int) $values['ongkir_api']['max_rates'];
    $this->configuration['cache_ttl'] = (int) $values['ongkir_api']['cache_ttl'];

    $this->configuration['district_source'] = (string) $values['ongkir_destination']['district_source'];
    $this->configuration['district_field'] = trim((string) $values['ongkir_destination']['district_field']);
    $this->configuration['map_key'] = (string) $values['ongkir_destination']['map_key'];
    $this->configuration['district_map'] = (string) $values['ongkir_destination']['district_map'];

    $this->configuration['weight_field'] = trim((string) $values['ongkir_weight']['weight_field']);
    $this->configuration['weight_unit'] = (string) $values['ongkir_weight']['weight_unit'];
    $this->configuration['default_weight'] = (string) $values['ongkir_weight']['default_weight'];

    $this->configuration['rate_label_pattern'] = (string) $values['ongkir_price']['rate_label_pattern'];
    $this->configuration['markup_percent'] = (string) $values['ongkir_price']['markup_percent'];
    $this->configuration['markup_flat'] = (string) $values['ongkir_price']['markup_flat'];
  }

  /**
   * {@inheritdoc}
   */
  public function calculateRates(ShipmentInterface $shipment) {
    $origin = \Drupal\commerce_ongkir\DistrictResolver::normalizeCode($this->configuration['origin_district_code']);
    if ($origin === NULL) {
      throw new OngkirApiException('Kode kecamatan asal (origin_district_code) shipping method ini belum diisi dengan benar (contoh: 317405).');
    }

    $destination = $this->districtResolver->resolve($shipment, $this->configuration);
    if ($destination === NULL) {
      $this->logger->info('Ongkir: kode kecamatan tujuan belum tersedia untuk shipment @id (sumber: @source).', [
        '@id' => $shipment->id() ?? '-',
        '@source' => (string) $this->configuration['district_source'],
      ]);
      return [];
    }

    $weight = $this->getWeightInKilograms($shipment);
    $options = $this->buildApiOptions($shipment);
    $currency_code = $this->getCurrencyCode($shipment);
    $cache_ttl = (int) $this->configuration['cache_ttl'];

    $cid = 'commerce_ongkir:rates:' . md5(json_encode([
      'method' => $this->parentEntity ? $this->parentEntity->id() : $this->pluginId,
      'origin' => $origin,
      'destination' => $destination,
      'weight' => $weight,
      'options' => $options,
      'currency' => $currency_code,
    ]) ?: '');

    $data = NULL;
    if ($cache_ttl > 0 && ($cache = $this->cache->get($cid))) {
      $data = $cache->data;
    }
    if (!is_array($data)) {
      $data = $this->apiClient->getRates($origin, $destination, $weight, $options, 0);
      if ($cache_ttl > 0) {
        $this->cache->set($cid, $data, $this->time->getRequestTime() + $cache_ttl);
      }
    }

    $rates = [];
    foreach ($data['rates'] ?? [] as $rate) {
      if (!is_array($rate)) {
        continue;
      }
      $shipping_rate = $this->buildRate($rate, $data, $currency_code);
      if ($shipping_rate !== NULL) {
        $rates[] = $shipping_rate;
      }
    }

    $max_rates = (int) $this->configuration['max_rates'];
    if ($max_rates > 0 && count($rates) > $max_rates) {
      usort($rates, function (ShippingRate $a, ShippingRate $b) {
        return $a->getAmount()->compareTo($b->getAmount());
      });
      $rates = array_slice($rates, 0, $max_rates);
    }

    return $rates;
  }

  /**
   * Membuat ShippingRate dari satu item response API.
   *
   * @param array $rate
   *   Satu item pada data.rates.
   * @param array $data
   *   Seluruh data response (untuk quote_id).
   * @param string $currency_code
   *   Kode mata uang order.
   *
   * @return \Drupal\commerce_shipping\ShippingRate|null
   *   ShippingRate, atau NULL bila tidak dipakai.
   */
  protected function buildRate(array $rate, array $data, string $currency_code): ?ShippingRate {
    $courier_code = strtolower(trim((string) ($rate['courier_code'] ?? '')));
    $service_code = strtolower(trim((string) ($rate['service_code'] ?? '')));
    if ($courier_code === '' || $service_code === '') {
      return NULL;
    }
    if ($this->getFilter('courier_filter') && !in_array($courier_code, $this->getFilter('courier_filter'), TRUE)) {
      return NULL;
    }
    if ($this->getFilter('service_filter') && !in_array($service_code, $this->getFilter('service_filter'), TRUE)) {
      return NULL;
    }

    $amount = $this->calculateAmount($rate);
    if ($amount === NULL) {
      return NULL;
    }

    $rate += [
      'courier_name' => strtoupper($courier_code),
      'service_name' => strtoupper($service_code),
      'etd' => $rate['etd'] ?? NULL,
      'est' => $rate['est'] ?? NULL,
      'insurance_fee' => $rate['insurance_fee'] ?? NULL,
      'normal_price' => $rate['normal_price'] ?? NULL,
      'handling_fee' => $rate['handling_fee'] ?? 0,
      'is_cheapest' => !empty($rate['is_cheapest']),
      'is_fastest' => !empty($rate['is_fastest']),
    ];

    $service_id = $courier_code . '.' . $service_code;
    $this->services[$service_id] = new ShippingService($service_id, $this->buildLabel($rate));

    return new ShippingRate([
      'shipping_method_id' => $this->parentEntity->id(),
      'service' => $this->services[$service_id],
      'amount' => new Price(number_format($amount, 2, '.', ''), $currency_code),
      'description' => $this->buildDescription($rate),
      'data' => [
        'ongkir_quote_id' => $data['quote_id'] ?? NULL,
        'courier_code' => $courier_code,
        'courier_name' => $rate['courier_name'],
        'service_code' => $service_code,
        'service_name' => $rate['service_name'],
        'etd' => $rate['etd'],
        'est' => $rate['est'],
        'is_cheapest' => $rate['is_cheapest'],
        'is_fastest' => $rate['is_fastest'],
        'normal_price' => $rate['normal_price'] !== NULL ? (int) $rate['normal_price'] : NULL,
        'handling_fee' => (int) $rate['handling_fee'],
        'insurance_fee' => $rate['insurance_fee'] !== NULL ? (int) $rate['insurance_fee'] : NULL,
      ],
    ]);
  }

  /**
   * Menghitung nominal tarif (total_price + markup).
   *
   * @param array $rate
   *   Satu item pada data.rates.
   *
   * @return float|null
   *   Nominal, atau NULL bila tarif tidak valid.
   */
  protected function calculateAmount(array $rate): ?float {
    if (isset($rate['total_price'])) {
      $base = (float) $rate['total_price'];
    }
    else {
      $base = (float) ($rate['price'] ?? 0) + (float) ($rate['handling_fee'] ?? 0) + (float) ($rate['insurance_fee'] ?? 0);
    }
    if ($base <= 0) {
      return NULL;
    }

    $percent = (float) ($this->configuration['markup_percent'] ?: 0);
    $flat = (float) ($this->configuration['markup_flat'] ?: 0);
    $amount = $base + ($base * $percent / 100) + $flat;

    return max(0.0, round($amount, 2));
  }

  /**
   * Membangun label tarif dari rate_label_pattern.
   *
   * @param array $rate
   *   Data tarif.
   *
   * @return string
   *   Label.
   */
  protected function buildLabel(array $rate): string {
    $pattern = trim((string) $this->configuration['rate_label_pattern']);
    if ($pattern === '') {
      $pattern = '[courier] - [service]';
    }
    return strtr($pattern, [
      '[courier]' => (string) $rate['courier_name'],
      '[service]' => (string) $rate['service_name'],
      '[etd]' => (string) ($rate['etd'] ?? ''),
      '[code]' => $rate['courier_code'] . '.' . $rate['service_code'],
    ]);
  }

  /**
   * Membangun deskripsi tarif (estimasi & penanda dari API).
   *
   * @param array $rate
   *   Data tarif.
   *
   * @return string
   *   Deskripsi.
   */
  protected function buildDescription(array $rate): string {
    $parts = [];
    if (!empty($rate['etd'])) {
      $parts[] = 'Estimasi ' . $rate['etd'];
    }
    elseif (!empty($rate['est']) && (int) $rate['est'] > 0) {
      $parts[] = 'Estimasi ' . (int) $rate['est'] . ' hari';
    }
    if (!empty($rate['is_cheapest'])) {
      $parts[] = 'Termurah';
    }
    if (!empty($rate['is_fastest'])) {
      $parts[] = 'Tercepat';
    }
    if (!empty($rate['insurance_fee'])) {
      $parts[] = 'Termasuk asuransi Rp ' . number_format((float) $rate['insurance_fee'], 0, ',', '.');
    }
    return implode('  •  ', $parts);
  }

  /**
   * Mengurai filter koma (courier_filter / service_filter).
   *
   * @param string $key
   *   Nama konfigurasi.
   *
   * @return array
   *   Daftar nilai lowercase.
   */
  protected function getFilter(string $key): array {
    $raw = strtolower(trim((string) ($this->configuration[$key] ?? '')));
    if ($raw === '') {
      return [];
    }
    $values = preg_split('/[,\s]+/', $raw) ?: [];
    return array_values(array_filter(array_map('trim', $values), static fn($value) => $value !== ''));
  }

  /**
   * Menghitung berat paket dalam kilogram.
   *
   * Urutan: berat shipment (hasil Commerce) → field berat produk → berat
   * default per unit.
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment
   *   Shipment.
   *
   * @return float
   *   Berat dalam kg (minimum 0,1).
   */
  protected function getWeightInKilograms(ShipmentInterface $shipment): float {
    $weight = $shipment->getWeight();
    if ($weight instanceof Weight && !$weight->isZero()) {
      $kg = (float) $weight->convert(WeightUnit::KILOGRAM)->getNumber();
      if ($kg > 0) {
        return $this->normalizeWeight($kg);
      }
    }

    $default_unit = (float) ($this->configuration['default_weight'] ?: 0);
    if ($default_unit <= 0) {
      $default_unit = 1.0;
    }

    $order = $shipment->getOrder();
    $total = 0.0;
    foreach ($shipment->getItems() as $item) {
      $quantity = (float) $item->getQuantity();
      $unit = 0.0;
      if ($order instanceof OrderInterface) {
        $order_item = $this->getOrderItemById($order, (int) $item->getOrderItemId());
        if ($order_item !== NULL) {
          $unit = $this->getUnitWeightInKilograms($order_item);
        }
      }
      if ($unit <= 0) {
        $unit = $default_unit;
      }
      $total += $unit * $quantity;
    }
    if ($total <= 0) {
      $total = $default_unit;
    }

    return $this->normalizeWeight($total);
  }

  /**
   * Mengambil order item berdasarkan ID.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   Order.
   * @param int $order_item_id
   *   ID order item.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface|null
   *   Order item, atau NULL.
   */
  protected function getOrderItemById(OrderInterface $order, int $order_item_id) {
    foreach ($order->getItems() as $order_item) {
      if ((int) $order_item->id() === $order_item_id) {
        return $order_item;
      }
    }
    return NULL;
  }

  /**
   * Berat satu unit purchased entity (dalam kg).
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $order_item
   *   Order item.
   *
   * @return float
   *   Berat per unit dalam kg (0 bila tidak diketahui).
   */
  protected function getUnitWeightInKilograms($order_item): float {
    if (!$order_item->hasField('purchased_entity')) {
      return 0.0;
    }
    $purchased_entity = $order_item->getPurchasedEntity();
    if ($purchased_entity === NULL) {
      return 0.0;
    }

    // Field "weight" standar Drupal physical, bila ada.
    if ($purchased_entity->hasField('weight') && !$purchased_entity->get('weight')->isEmpty()) {
      $weight_item = $purchased_entity->get('weight')->first();
      if ($weight_item !== NULL && method_exists($weight_item, 'toMeasurement')) {
        $measurement = $weight_item->toMeasurement();
        return (float) $measurement->convert(WeightUnit::KILOGRAM)->getNumber();
      }
    }

    // Field berat kustom (mis. field_berat) berisi angka atau teks.
    $field_name = trim((string) $this->configuration['weight_field']);
    if ($field_name !== '' && $purchased_entity->hasField($field_name) && !$purchased_entity->get($field_name)->isEmpty()) {
      $raw_item = $purchased_entity->get($field_name)->first();
      if ($raw_item !== NULL) {
        $value = $raw_item->value ?? $raw_item->getString();
        return $this->parseWeightValue((string) $value);
      }
    }

    return 0.0;
  }

  /**
   * Mengubah nilai teks berat menjadi kilogram.
   *
   * Contoh: "500 gram" → 0.5, "1,5 kg" → 1.5, "2" → 0.002 (asumsi gram).
   *
   * @param string $value
   *   Nilai mentah.
   *
   * @return float
   *   Berat dalam kg.
   */
  protected function parseWeightValue(string $value): float {
    $value = trim($value);
    if ($value === '' || !preg_match('/[0-9]/', $value)) {
      return 0.0;
    }
    $normalized = str_replace(',', '.', $value);
    if (!preg_match('/([0-9]+(?:\.[0-9]+)?)/', $normalized, $matches)) {
      return 0.0;
    }
    $number = (float) $matches[1];

    $unit = (string) $this->configuration['weight_unit'];
    if ($unit === 'auto') {
      if (preg_match('/(kg|kilo|kilogram)/i', $normalized)) {
        $unit = 'kg';
      }
      else {
        // Default Indonesia: gram.
        $unit = 'g';
      }
    }

    return $unit === 'kg' ? $number : $number / 1000;
  }

  /**
   * Menormalkan berat ke aturan API (> 0, minimum 0,1 kg).
   *
   * @param float $kg
   *   Berat dalam kg.
   *
   * @return float
   *   Berat yang valid.
   */
  protected function normalizeWeight(float $kg): float {
    return max(0.1, round($kg, 3));
  }

  /**
   * Menyusun opsi tambahan untuk API (item_value, insurance).
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment
   *   Shipment.
   *
   * @return array
   *   Opsi.
   */
  protected function buildApiOptions(ShipmentInterface $shipment): array {
    $options = [];
    if (!empty($this->configuration['insurance'])) {
      $options['insurance'] = TRUE;
    }
    if (($this->configuration['item_value_mode'] ?? 'declared_value') === 'declared_value') {
      $declared_value = $shipment->getTotalDeclaredValue();
      if ($declared_value instanceof Price && !$declared_value->isZero()) {
        $options['item_value'] = (int) round((float) $declared_value->getNumber());
      }
    }
    return $options;
  }

  /**
   * Kode mata uang order.
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment
   *   Shipment.
   *
   * @return string
   *   Kode mata uang.
   */
  protected function getCurrencyCode(ShipmentInterface $shipment): string {
    $order = $shipment->getOrder();
    if ($order instanceof OrderInterface && $order->getStore()) {
      return $order->getStore()->getDefaultCurrencyCode();
    }
    return 'IDR';
  }

}
