<?php

namespace Drupal\commerce_midtrans\Form;

use Drupal\commerce_midtrans\MidtransApiClient;
use Drupal\commerce_midtrans\MidtransApiClientInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form konfigurasi integrasi Midtrans Snap.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * Client Midtrans.
   *
   * @var \Drupal\commerce_midtrans\MidtransApiClientInterface
   */
  protected MidtransApiClientInterface $apiClient;

  /**
   * Constructs a new SettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   Typed config manager.
   * @param \Drupal\commerce_midtrans\MidtransApiClientInterface $api_client
   *   Client Midtrans.
   */
  public function __construct(ConfigFactoryInterface $config_factory, TypedConfigManagerInterface $typed_config_manager, MidtransApiClientInterface $api_client) {
    parent::__construct($config_factory, $typed_config_manager);

    $this->apiClient = $api_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('commerce_midtrans.api_client'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'commerce_midtrans_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['commerce_midtrans.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('commerce_midtrans.settings');

    $source_labels = [
      'environment' => $this->t('environment variable sandbox (<code>MIDTRANS_SERVER_KEY</code>)'),
      'environment-live' => $this->t('environment variable production (<code>MIDTRANS_SERVER_KEY_LIVE</code>)'),
      'settings.php' => $this->t('$settings["commerce_midtrans_server_key"] pada settings.php'),
      'config' => $this->t('kolom Server key di bawah (tersimpan di config)'),
      'none' => $this->t('belum tersedia'),
    ];

    $form['info'] = [
      '#type' => 'item',
      '#markup' => $this->t('Integrasi memakai Midtrans <strong>Snap</strong> (Snap API + snap.js) dan Core API untuk cek status/void/refund. Sandbox: <code>https://api.sandbox.midtrans.com</code> &amp; <code>https://app.sandbox.midtrans.com/snap/snap.js</code>. Dokumentasi: <a href="@url" target="_blank">docs.midtrans.com</a>. Jangan lupa mengisi <strong>Payment Notification URL</strong> di dashboard Midtrans (SETTINGS &gt; CONFIGURATION).', [
        '@url' => 'https://docs.midtrans.com/reference/quick-start-1',
      ]),
    ];

    $form['credentials'] = [
      '#type' => 'details',
      '#title' => $this->t('Kredensial & mode'),
      '#open' => TRUE,
    ];
    $form['credentials']['key_status'] = [
      '#type' => 'item',
      '#markup' => $this->t('Server key sandbox: @sandbox · Server key production: @live', [
        '@sandbox' => $source_labels[$this->apiClient->getCredentialSource(MidtransApiClient::MODE_SANDBOX)],
        '@live' => $source_labels[$this->apiClient->getCredentialSource(MidtransApiClient::MODE_PRODUCTION)],
      ]),
    ];
    $form['credentials']['default_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Mode default'),
      '#options' => [
        MidtransApiClient::MODE_SANDBOX => $this->t('Sandbox (testing)'),
        MidtransApiClient::MODE_PRODUCTION => $this->t('Production (live)'),
      ],
      '#default_value' => MidtransApiClient::normalizeMode((string) $config->get('default_mode')),
      '#description' => $this->t('Dipakai sebagai nilai awal; tiap payment gateway punya pengaturan mode (Test/Live) sendiri. Bisa juga dipaksa lewat env <code>MIDTRANS_MODE</code>.'),
      '#required' => TRUE,
    ];
    $form['credentials']['server_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Server key (fallback)'),
      '#description' => $this->t('Disarankan mengisi <code>MIDTRANS_SERVER_KEY</code> (sandbox) dan <code>MIDTRANS_SERVER_KEY_LIVE</code> (production) di <code>web/.env</code>. Nilai di sini hanya dipakai bila env &amp; settings.php kosong.'),
      '#default_value' => $config->get('server_key'),
      '#attributes' => ['autocomplete' => 'off'],
    ];
    $form['credentials']['client_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client key (fallback)'),
      '#description' => $this->t('Client key bersifat publik (dipakai snap.js di browser). Isi juga di <code>web/.env</code> sebagai <code>MIDTRANS_CLIENT_KEY</code> / <code>MIDTRANS_CLIENT_KEY_LIVE</code>.'),
      '#default_value' => $config->get('client_key'),
      '#attributes' => ['autocomplete' => 'off'],
    ];
    $form['credentials']['is_3ds'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Aktifkan 3D Secure untuk pembayaran kartu'),
      '#default_value' => (bool) $config->get('is_3ds'),
    ];

    $form['endpoints'] = [
      '#type' => 'details',
      '#title' => $this->t('Endpoint Midtrans'),
      '#open' => FALSE,
    ];
    $endpoint_fields = [
      'api_base_url_sandbox' => [
        'title' => $this->t('Core API base URL — sandbox'),
        'default' => MidtransApiClient::SANDBOX_API_URL,
      ],
      'api_base_url_production' => [
        'title' => $this->t('Core API base URL — production'),
        'default' => MidtransApiClient::PRODUCTION_API_URL,
      ],
      'snap_base_url_sandbox' => [
        'title' => $this->t('Snap API base URL — sandbox'),
        'default' => MidtransApiClient::SANDBOX_SNAP_URL,
      ],
      'snap_base_url_production' => [
        'title' => $this->t('Snap API base URL — production'),
        'default' => MidtransApiClient::PRODUCTION_SNAP_URL,
      ],
      'snap_js_url_sandbox' => [
        'title' => $this->t('snap.js URL — sandbox'),
        'default' => MidtransApiClient::SANDBOX_SNAP_JS_URL,
      ],
      'snap_js_url_production' => [
        'title' => $this->t('snap.js URL — production'),
        'default' => MidtransApiClient::PRODUCTION_SNAP_JS_URL,
      ],
    ];
    foreach ($endpoint_fields as $key => $definition) {
      $form['endpoints'][$key] = [
        '#type' => 'textfield',
        '#title' => $definition['title'],
        '#default_value' => $config->get($key) ?: $definition['default'],
        '#required' => TRUE,
      ];
    }

    $form['options'] = [
      '#type' => 'details',
      '#title' => $this->t('Opsi request'),
      '#open' => FALSE,
    ];
    $form['options']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout request (detik)'),
      '#default_value' => (int) ($config->get('timeout') ?: 20),
      '#min' => 1,
      '#max' => 120,
      '#required' => TRUE,
    ];
    $form['options']['log_requests'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Catat detail request/response Midtrans ke log (debug)'),
      '#default_value' => (bool) $config->get('log_requests'),
    ];

    $form = parent::buildForm($form, $form_state);
    $form['actions']['test'] = [
      '#type' => 'submit',
      '#value' => $this->t('Simpan &amp; tes kredensial'),
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

    $url_fields = [
      'api_base_url_sandbox',
      'api_base_url_production',
      'snap_base_url_sandbox',
      'snap_base_url_production',
      'snap_js_url_sandbox',
      'snap_js_url_production',
    ];
    foreach ($url_fields as $field) {
      $value = trim((string) $form_state->getValue($field));
      if ($value !== '' && !preg_match('#^https?://#i', $value)) {
        $form_state->setErrorByName($field, $this->t('URL harus diawali http:// atau https://.'));
      }
    }

    $timeout = $form_state->getValue('timeout');
    if (!is_numeric($timeout) || (int) $timeout < 1) {
      $form_state->setErrorByName('timeout', $this->t('Timeout harus angka minimal 1 detik.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('commerce_midtrans.settings')
      ->set('default_mode', MidtransApiClient::normalizeMode((string) $form_state->getValue('default_mode')))
      ->set('server_key', trim((string) $form_state->getValue('server_key')))
      ->set('client_key', trim((string) $form_state->getValue('client_key')))
      ->set('is_3ds', (bool) $form_state->getValue('is_3ds'))
      ->set('api_base_url_sandbox', rtrim(trim((string) $form_state->getValue('api_base_url_sandbox')), '/'))
      ->set('api_base_url_production', rtrim(trim((string) $form_state->getValue('api_base_url_production')), '/'))
      ->set('snap_base_url_sandbox', rtrim(trim((string) $form_state->getValue('snap_base_url_sandbox')), '/'))
      ->set('snap_base_url_production', rtrim(trim((string) $form_state->getValue('snap_base_url_production')), '/'))
      ->set('snap_js_url_sandbox', trim((string) $form_state->getValue('snap_js_url_sandbox')))
      ->set('snap_js_url_production', trim((string) $form_state->getValue('snap_js_url_production')))
      ->set('timeout', (int) $form_state->getValue('timeout'))
      ->set('log_requests', (bool) $form_state->getValue('log_requests'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Tombol "Simpan & tes kredensial": memanggil Core API Midtrans.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   */
  public function testConnection(array &$form, FormStateInterface $form_state) {
    // Baca konfigurasi yang baru disimpan.
    $this->configFactory->reset();

    $tested = 0;
    foreach ([MidtransApiClient::MODE_SANDBOX, MidtransApiClient::MODE_PRODUCTION] as $mode) {
      if ($this->apiClient->getServerKey($mode) === '') {
        continue;
      }
      $tested++;
      $result = $this->apiClient->testCredentials($mode);
      if ($result['ok']) {
        $this->messenger()->addStatus($this->t('Mode @mode: @message', [
          '@mode' => $mode,
          '@message' => $result['message'],
        ]));
      }
      else {
        $this->messenger()->addError($this->t('Mode @mode: @message', [
          '@mode' => $mode,
          '@message' => $result['message'],
        ]));
      }
    }

    if ($tested === 0) {
      $this->messenger()->addError($this->t('Belum ada server key yang bisa diuji. Isi MIDTRANS_SERVER_KEY (sandbox) dan/atau MIDTRANS_SERVER_KEY_LIVE (production) di web/.env.'));
    }
  }

}
