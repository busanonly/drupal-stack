<?php

namespace Drupal\commerce_ongkir\Form;

use Drupal\commerce_ongkir\DistrictResolver;
use Drupal\commerce_ongkir\OngkirApiClientInterface;
use Drupal\commerce_ongkir\OngkirApiException;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form konfigurasi integrasi Cek Ongkir (api.co.id).
 */
class SettingsForm extends ConfigFormBase {

  /**
   * Client API ongkir.
   *
   * @var \Drupal\commerce_ongkir\OngkirApiClientInterface
   */
  protected OngkirApiClientInterface $apiClient;

  /**
   * Cache bin commerce_ongkir.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected CacheBackendInterface $cache;

  /**
   * Constructs a new SettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   Typed config manager.
   * @param \Drupal\commerce_ongkir\OngkirApiClientInterface $api_client
   *   Client API ongkir.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   Cache bin commerce_ongkir.
   */
  public function __construct(ConfigFactoryInterface $config_factory, TypedConfigManagerInterface $typed_config_manager, OngkirApiClientInterface $api_client, CacheBackendInterface $cache) {
    parent::__construct($config_factory, $typed_config_manager);

    $this->apiClient = $api_client;
    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('commerce_ongkir.api_client'),
      $container->get('cache.commerce_ongkir'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'commerce_ongkir_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['commerce_ongkir.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('commerce_ongkir.settings');

    $source_labels = [
      'environment' => $this->t('environment variable <code>API_CO_ID_KEY</code> (web/.env)'),
      'settings.php' => $this->t('$settings["commerce_ongkir_api_key"] pada settings.php'),
      'config' => $this->t('kolom API key di bawah (tersimpan di config)'),
      'none' => $this->t('belum tersedia'),
    ];
    $source = $this->apiClient->getApiKeySource();

    $form['info'] = [
      '#type' => 'item',
      '#markup' => $this->t('Endpoint tarif: <code>GET /courier/v2/rates</code> (kode kecamatan). Dokumentasi: <a href="@url" target="_blank">docs.api.co.id</a>. Biaya Rp 5 per panggilan sukses.', [
        '@url' => 'https://docs.api.co.id/products/indonesia-courier-rates/',
      ]),
    ];
    $form['key_status'] = [
      '#type' => 'item',
      '#markup' => $this->t('Sumber API key yang sedang dipakai: @source.', ['@source' => $source_labels[$source]]),
    ];

    $form['api_base_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base URL API'),
      '#description' => $this->t('Default <code>https://use.api.co.id</code>. Path endpoint ditambahkan otomatis (<code>/courier/v2/rates</code>, <code>/courier/v1/locations/*</code>).'),
      '#default_value' => $config->get('api_base_url') ?: 'https://use.api.co.id',
      '#required' => TRUE,
    ];
    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key (fallback)'),
      '#description' => $this->t('Disarankan mengisi <code>API_CO_ID_KEY</code> di <code>web/.env</code> (variabel ini dipakai lebih dulu). Nilai di sini hanya dipakai bila env &amp; settings.php kosong.'),
      '#default_value' => $config->get('api_key'),
      '#attributes' => ['autocomplete' => 'off'],
    ];
    $form['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout request (detik)'),
      '#default_value' => (int) ($config->get('timeout') ?: 15),
      '#min' => 1,
      '#max' => 120,
      '#required' => TRUE,
    ];
    $form['cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache TTL data lokasi &amp; kurir (detik)'),
      '#description' => $this->t('Endpoint lokasi/kurir gratis, tapi di-cache agar halaman tetap cepat. Default 86400 (24 jam).'),
      '#default_value' => (int) ($config->get('cache_ttl') ?: 86400),
      '#min' => 0,
      '#required' => TRUE,
    ];
    $form['origin_district_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Kode kecamatan asal default (opsional)'),
      '#description' => $this->t('Dipakai sebagai nilai awal saat membuat shipping method baru, contoh <code>317405</code>.'),
      '#default_value' => $config->get('origin_district_code'),
      '#size' => 16,
      '#maxlength' => 16,
    ];
    $form['log_requests'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Catat detail request API ke log (debug)'),
      '#default_value' => (bool) $config->get('log_requests'),
    ];

    $form = parent::buildForm($form, $form_state);
    $form['actions']['test'] = [
      '#type' => 'submit',
      '#value' => $this->t('Simpan &amp; tes koneksi'),
      '#submit' => ['::submitForm', '::testConnection'],
      '#weight' => 20,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $base_url = trim((string) $form_state->getValue('api_base_url'));
    if ($base_url !== '' && !preg_match('#^https?://#i', $base_url)) {
      $form_state->setErrorByName('api_base_url', $this->t('Base URL harus diawali http:// atau https://.'));
    }

    $origin = trim((string) $form_state->getValue('origin_district_code'));
    if ($origin !== '' && DistrictResolver::normalizeCode($origin) === NULL) {
      $form_state->setErrorByName('origin_district_code', $this->t('Kode kecamatan asal harus berupa angka minimal 6 digit, contoh 317405.'));
    }

    $timeout = $form_state->getValue('timeout');
    if (!is_numeric($timeout) || (int) $timeout < 1) {
      $form_state->setErrorByName('timeout', $this->t('Timeout harus angka minimal 1 detik.'));
    }

    $cache_ttl = $form_state->getValue('cache_ttl');
    if (!is_numeric($cache_ttl) || (int) $cache_ttl < 0) {
      $form_state->setErrorByName('cache_ttl', $this->t('Cache TTL tidak boleh negatif.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('commerce_ongkir.settings')
      ->set('api_base_url', rtrim(trim((string) $form_state->getValue('api_base_url')), '/'))
      ->set('api_key', trim((string) $form_state->getValue('api_key')))
      ->set('timeout', (int) $form_state->getValue('timeout'))
      ->set('cache_ttl', (int) $form_state->getValue('cache_ttl'))
      ->set('origin_district_code', trim((string) $form_state->getValue('origin_district_code')))
      ->set('log_requests', (bool) $form_state->getValue('log_requests'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Tombol "Simpan & tes koneksi": uji endpoint /courier/v1/couriers.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function testConnection(array &$form, FormStateInterface $form_state) {
    // Baca konfigurasi yang baru disimpan, dan buang cache agar benar-benar
    // memanggil API.
    $this->configFactory->reset();
    $this->cache->deleteAll();

    try {
      $codes = [];
      foreach ($this->apiClient->getCouriers() as $courier) {
        if (!empty($courier['code'])) {
          $codes[] = $courier['code'];
        }
      }
      $this->messenger()->addStatus($this->t('Koneksi API Cek Ongkir berhasil. @count kurir tersedia: @list.', [
        '@count' => count($codes),
        '@list' => implode(', ', $codes),
      ]));
    }
    catch (OngkirApiException $e) {
      $this->messenger()->addError($this->t('Koneksi API Cek Ongkir gagal: @message', ['@message' => $e->getMessage()]));
    }
  }

}
