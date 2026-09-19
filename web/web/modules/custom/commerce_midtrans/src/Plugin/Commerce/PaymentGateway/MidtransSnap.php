<?php

namespace Drupal\commerce_midtrans\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_midtrans\MidtransApiClient;
use Drupal\commerce_midtrans\MidtransApiClientInterface;
use Drupal\commerce_midtrans\MidtransApiException;
use Drupal\commerce_midtrans\PluginForm\MidtransSnap\PaymentOffsiteForm;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsVoidsInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Payment gateway Midtrans Snap (on-site popup via snap.js atau redirect).
 *
 * Alur off-site Commerce:
 * 1. Checkout menampilkan form "offsite-payment" (popup Snap.js / redirect).
 * 2. Transaksi Snap dibuat lewat Snap API (token + redirect_url).
 * 3. Pembeli membayar; Midtrans mengirim HTTP notification ke
 *    /payment/notify/{gateway} dan mengarahkan pembeli kembali ke
 *    /checkout/{order}/{step}/return.
 * 4. Payment Commerce dibuat/di-update oleh onNotify()/onReturn().
 *
 * @see https://docs.midtrans.com/reference/quick-start-1
 */
#[CommercePaymentGateway(
  id: 'midtrans_snap',
  label: new TranslatableMarkup('Midtrans (Snap)'),
  display_label: new TranslatableMarkup('Midtrans'),
  payment_type: 'payment_manual',
  payment_method_types: ['credit_card'],
  credit_card_types: ['visa', 'mastercard', 'jcb', 'amex'],
  requires_billing_information: TRUE,
  forms: [
    'offsite-payment' => PaymentOffsiteForm::class,
  ],
)]
class MidtransSnap extends OffsitePaymentGatewayBase implements SupportsVoidsInterface, SupportsRefundsInterface {

  /**
   * Client Midtrans (di-inject lewat create()).
   *
   * @var \Drupal\commerce_midtrans\MidtransApiClientInterface|null
   */
  protected ?MidtransApiClientInterface $apiClient = NULL;

  /**
   * Logger channel commerce_midtrans.
   *
   * @var \Psr\Log\LoggerInterface|null
   */
  protected ?LoggerInterface $logger = NULL;

  /**
   * Constructs a new MidtransSnap object.
   *
   * @param array $configuration
   *   Konfigurasi plugin.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Definisi plugin.
   * @param \Drupal\commerce_midtrans\MidtransApiClientInterface|null $api_client
   *   Client Midtrans (opsional, diisi oleh create()).
   * @param \Psr\Log\LoggerInterface|null $logger
   *   Logger channel commerce_midtrans.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ?MidtransApiClientInterface $api_client = NULL, ?LoggerInterface $logger = NULL) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->apiClient = $api_client;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $instance */
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->apiClient = $container->get('commerce_midtrans.api_client');
    $instance->logger = $container->get('logger.channel.commerce_midtrans');

    return $instance;
  }

  /**
   * Client Midtrans.
   *
   * @return \Drupal\commerce_midtrans\MidtransApiClientInterface
   *   Client.
   */
  protected function apiClient(): MidtransApiClientInterface {
    if ($this->apiClient === NULL) {
      $this->apiClient = \Drupal::service('commerce_midtrans.api_client');
    }
    return $this->apiClient;
  }

  /**
   * Logger channel.
   *
   * @return \Psr\Log\LoggerInterface
   *   Logger.
   */
  protected function logger(): LoggerInterface {
    if ($this->logger === NULL) {
      $this->logger = \Drupal::service('logger.channel.commerce_midtrans');
    }
    return $this->logger;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'ui_mode' => 'popup',
      'order_id_prefix' => 'MID-',
      'enabled_payments' => '',
      'expiry_duration' => 24,
      'send_item_details' => TRUE,
      'token_ttl' => 60,
      'auto_open' => TRUE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $mode = MidtransApiClient::normalizeMode($this->getMode());
    $client = $this->apiClient();

    $form['midtrans'] = [
      '#type' => 'details',
      '#title' => $this->t('Midtrans Snap'),
      '#open' => TRUE,
    ];
    $form['midtrans']['ui_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Mode tampilan pembayaran'),
      '#options' => [
        'popup' => $this->t('Popup Snap.js (pembeli tetap di halaman checkout)'),
        'redirect' => $this->t('Redirect ke halaman pembayaran Snap Midtrans'),
      ],
      '#default_value' => $this->configuration['ui_mode'],
      '#required' => TRUE,
    ];
    $form['midtrans']['auto_open'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Buka popup Snap otomatis saat halaman pembayaran dibuka'),
      '#default_value' => !empty($this->configuration['auto_open']),
    ];
    $form['midtrans']['order_id_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Prefix order ID Midtrans'),
      '#description' => $this->t('order_id yang dikirim ke Midtrans berbentuk <code>{prefix}{order_id}-{timestamp}</code>, mis. <code>MID-42-1712345678</code>. Notifikasi Midtrans dipetakan kembali ke order lewat pola ini.'),
      '#default_value' => $this->configuration['order_id_prefix'],
      '#required' => TRUE,
      '#size' => 20,
      '#maxlength' => 20,
    ];
    $form['midtrans']['enabled_payments'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Channel pembayaran (opsional)'),
      '#description' => $this->t('Kode channel Snap dipisah koma, mis. <code>bank_transfer,gopay,qris</code>. Kosongkan untuk semua channel yang aktif di akun Midtrans Anda.'),
      '#default_value' => $this->configuration['enabled_payments'],
    ];
    $form['midtrans']['expiry_duration'] = [
      '#type' => 'number',
      '#title' => $this->t('Masa berlaku pembayaran (jam)'),
      '#description' => $this->t('0 = gunakan default Midtrans (24 jam).'),
      '#default_value' => $this->configuration['expiry_duration'],
      '#min' => 0,
    ];
    $form['midtrans']['token_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Pakai ulang token Snap selama (menit)'),
      '#description' => $this->t('Mencegah pembuatan transaksi Snap baru saat halaman checkout di-refresh. 0 = selalu buat transaksi baru.'),
      '#default_value' => $this->configuration['token_ttl'],
      '#min' => 0,
    ];
    $form['midtrans']['send_item_details'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Kirim detail item (nama produk, qty, harga) ke Snap'),
      '#default_value' => !empty($this->configuration['send_item_details']),
    ];

    $form['midtrans']['status'] = [
      '#type' => 'item',
      '#title' => $this->t('Status koneksi'),
      '#markup' => $this->t(
        'Mode: <strong>@mode</strong> · Snap API: <code>@snap</code> · Core API: <code>@api</code> · snap.js: <code>@js</code><br />Sumber server key: <strong>@source</strong> · Client key: @client<br />Notification URL (isi di dashboard Midtrans → Settings → Configuration): <code>@notify</code>',
        [
          '@mode' => $mode,
          '@snap' => $client->getSnapBaseUrl($mode),
          '@api' => $client->getApiBaseUrl($mode),
          '@js' => $client->getSnapJsUrl($mode),
          '@source' => $client->getCredentialSource($mode),
          '@client' => $client->getClientKey($mode) !== '' ? $this->t('tersedia') : $this->t('belum diisi'),
          // parentEntity belum tersedia saat gateway baru dibuat (form add).
          '@notify' => $this->parentEntity ? $this->getNotifyUrl()->setAbsolute()->toString() : $this->t('tersedia setelah gateway disimpan'),
        ]
      ),
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
    $prefix = (string) $values['midtrans']['order_id_prefix'];
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $prefix)) {
      $form_state->setError($form['midtrans']['order_id_prefix'], $this->t('Prefix hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung.'));
    }
    foreach (['expiry_duration', 'token_ttl'] as $key) {
      if ((int) $values['midtrans'][$key] < 0) {
        $form_state->setError($form['midtrans'][$key], $this->t('Nilai tidak boleh negatif.'));
      }
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

    $this->configuration['ui_mode'] = (string) $values['midtrans']['ui_mode'];
    $this->configuration['auto_open'] = (bool) $values['midtrans']['auto_open'];
    $this->configuration['order_id_prefix'] = trim((string) $values['midtrans']['order_id_prefix']);
    $this->configuration['enabled_payments'] = trim((string) $values['midtrans']['enabled_payments']);
    $this->configuration['expiry_duration'] = (int) $values['midtrans']['expiry_duration'];
    $this->configuration['token_ttl'] = (int) $values['midtrans']['token_ttl'];
    $this->configuration['send_item_details'] = (bool) $values['midtrans']['send_item_details'];
  }

  /**
   * Mode tampilan: popup (Snap.js) atau redirect.
   *
   * @return string
   *   "popup" atau "redirect".
   */
  public function getUiMode(): string {
    return $this->configuration['ui_mode'] === 'redirect' ? 'redirect' : 'popup';
  }

  /**
   * Apakah popup Snap dibuka otomatis.
   *
   * @return bool
   *   TRUE bila auto open.
   */
  public function isAutoOpen(): bool {
    return !empty($this->configuration['auto_open']);
  }

  /**
   * URL snap.js sesuai mode gateway.
   *
   * @return string
   *   URL snap.js.
   */
  public function getSnapJsUrl(): string {
    return $this->apiClient()->getSnapJsUrl($this->getMode());
  }

  /**
   * Client key Midtrans (untuk atribut data-client-key snap.js).
   *
   * @return string
   *   Client key.
   */
  public function getClientKey(): string {
    return $this->apiClient()->getClientKey($this->getMode());
  }

  /**
   * Mode Midtrans (sandbox/production) dari mode gateway (test/live).
   *
   * @return string
   *   "sandbox" atau "production".
   */
  public function getMidtransMode(): string {
    return MidtransApiClient::normalizeMode($this->getMode());
  }

  /**
   * Membuat (atau memakai ulang) transaksi Snap untuk sebuah order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   Order.
   * @param string $return_url
   *   URL kembali setelah pembayaran sukses/pending.
   * @param string $cancel_url
   *   URL kembali setelah pembayaran gagal/dibatalkan.
   *
   * @return array
   *   ['midtrans_order_id' => string, 'token' => string, 'redirect_url' => string].
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   Bila transaksi tidak dapat dibuat.
   */
  public function getOrCreateTransaction(OrderInterface $order, string $return_url, string $cancel_url): array {
    $amount = $this->getPaymentAmount($order);
    if ($amount <= 0) {
      throw new PaymentGatewayException('Total pembayaran 0, transaksi Midtrans tidak dapat dibuat.');
    }

    $data = $order->getData('commerce_midtrans') ?: [];
    $ttl = (int) $this->configuration['token_ttl'];
    $latest = $data['midtrans_order_id'] ?? NULL;
    $reuse = $ttl > 0 && is_string($latest) && $latest !== '' && !empty($data['token'])
      && (int) ($data['created'] ?? 0) > time() - ($ttl * 60)
      && (int) ($data['amount'] ?? 0) === (int) round((float) $amount);

    $midtrans_order_id = $reuse ? (string) $latest : $this->generateOrderId($order);
    // Sertakan order_id Midtrans pada URL kembali (dipakai onReturn()).
    $return_url = $this->decorateReturnUrl($return_url, $midtrans_order_id);
    $cancel_url = $this->decorateReturnUrl($cancel_url, $midtrans_order_id);

    if ($reuse) {
      return [
        'midtrans_order_id' => $midtrans_order_id,
        'token' => (string) $data['token'],
        'redirect_url' => (string) ($data['redirect_url'] ?? ''),
        'return_url' => $return_url,
        'cancel_url' => $cancel_url,
      ];
    }

    $payload = $this->buildSnapPayload($order, $midtrans_order_id, $amount, $return_url, $cancel_url);
    try {
      $response = $this->apiClient()->createTransaction($payload, $this->getMode());
    }
    catch (MidtransApiException $e) {
      $this->logger()->error('Midtrans: gagal membuat transaksi Snap untuk order @id: @message', [
        '@id' => $order->id(),
        '@message' => $e->getMessage(),
      ]);
      throw new PaymentGatewayException($e->getMessage(), $e->getCode(), $e);
    }

    $this->recordTransaction($order, $midtrans_order_id, $amount, (string) $response['token'], (string) $response['redirect_url']);

    return [
      'midtrans_order_id' => $midtrans_order_id,
      'token' => (string) $response['token'],
      'redirect_url' => (string) $response['redirect_url'],
      'return_url' => $return_url,
      'cancel_url' => $cancel_url,
    ];
  }

  /**
   * Menambahkan order_id Midtrans sebagai query param pada URL kembali.
   *
   * @param string $url
   *   URL asal.
   * @param string $midtrans_order_id
   *   order_id Midtrans.
   *
   * @return string
   *   URL dengan query param midtrans_order_id.
   */
  protected function decorateReturnUrl(string $url, string $midtrans_order_id): string {
    $separator = str_contains($url, '?') ? '&' : '?';

    return $url . $separator . 'midtrans_order_id=' . rawurlencode($midtrans_order_id);
  }

  /**
   * order_id unik untuk Midtrans: {prefix}{order_id}-{timestamp}.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   Order.
   *
   * @return string
   *   order_id Midtrans.
   */
  protected function generateOrderId(OrderInterface $order): string {
    $prefix = trim((string) ($this->configuration['order_id_prefix'] ?? 'MID-'));
    if ($prefix === '') {
      $prefix = 'MID-';
    }
    return $prefix . $order->id() . '-' . time();
  }

  /**
   * Menyusun payload Snap.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   Order.
   * @param string $midtrans_order_id
   *   order_id Midtrans.
   * @param int|float $gross_amount
   *   Nominal tagihan.
   * @param string $return_url
   *   URL kembali (sukses/pending).
   * @param string $cancel_url
   *   URL batal (error).
   *
   * @return array
   *   Payload Snap.
   */
  protected function buildSnapPayload(OrderInterface $order, string $midtrans_order_id, $gross_amount, string $return_url, string $cancel_url): array {
    $payload = [
      'transaction_details' => [
        'order_id' => $midtrans_order_id,
        'gross_amount' => $gross_amount,
      ],
      'callbacks' => [
        'finish' => $return_url,
        'error' => $cancel_url,
        'pending' => $return_url,
      ],
      'credit_card' => [
        'secure' => (bool) $this->settings()->get('is_3ds'),
      ],
    ];

    $expiry = (int) $this->configuration['expiry_duration'];
    if ($expiry > 0) {
      $payload['expiry'] = [
        'unit' => 'hours',
        'duration' => $expiry,
      ];
    }

    $enabled_payments = $this->getEnabledPayments();
    if ($enabled_payments) {
      $payload['enabled_payments'] = $enabled_payments;
    }

    $payload['customer_details'] = $this->buildCustomerDetails($order);

    if (!empty($this->configuration['send_item_details'])) {
      $items = $this->buildItemDetails($order, $gross_amount);
      if ($items) {
        $payload['item_details'] = $items;
      }
    }

    return $payload;
  }

  /**
   * Daftar channel pembayaran dari konfigurasi.
   *
   * @return array
   *   Daftar kode channel.
   */
  protected function getEnabledPayments(): array {
    $raw = strtolower(trim((string) ($this->configuration['enabled_payments'] ?? '')));
    if ($raw === '') {
      return [];
    }
    $values = preg_split('/[,\s]+/', $raw) ?: [];
    return array_values(array_filter(array_map('trim', $values), static fn($value) => $value !== ''));
  }

  /**
   * Data customer untuk Snap (dari alamat penagihan & email order).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   Order.
   *
   * @return array
   *   customer_details.
   */
  protected function buildCustomerDetails(OrderInterface $order): array {
    $customer = [];
    $email = trim((string) $order->getEmail());
    if ($email !== '') {
      $customer['email'] = $email;
    }

    $first_name = '';
    $last_name = '';
    $billing = [];
    $profile = $order->getBillingProfile();
    if ($profile && $profile->hasField('address') && !$profile->get('address')->isEmpty()) {
      $address = $profile->get('address')->first();
      $first_name = (string) $address->getGivenName();
      $last_name = (string) $address->getFamilyName();
      $billing = array_filter([
        'first_name' => $this->sanitizeName($first_name),
        'last_name' => $this->sanitizeName($last_name),
        'address' => (string) $address->getAddressLine1(),
        'city' => (string) $address->getLocality(),
        'postal_code' => (string) $address->getPostalCode(),
      ]);
    }

    $customer['first_name'] = $this->sanitizeName($first_name);
    if ($last_name !== '') {
      $customer['last_name'] = $this->sanitizeName($last_name);
    }
    if ($billing) {
      $customer['billing_address'] = $billing;
    }

    return $customer;
  }

  /**
   * Membersihkan nama agar lolos validasi Midtrans.
   *
   * @param string $name
   *   Nama mentah.
   *
   * @return string
   *   Nama aman (maks. 50 karakter).
   */
  protected function sanitizeName(string $name): string {
    $name = preg_replace('/[^A-Za-z0-9 .\-_\/,]/', '', $name) ?? '';
    $name = trim(mb_substr($name, 0, 50));

    return $name !== '' ? $name : 'Pembeli';
  }

  /**
   * Detail item untuk Snap (harga dalam unit terkecil mata uang).
   *
   * Detail item hanya dikirim bila totalnya sama dengan gross_amount; bila ada
   * diskon (selisih negatif) detail item dilewati agar tidak ditolak Midtrans.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   Order.
   * @param int|float $gross_amount
   *   Nominal tagihan.
   *
   * @return array
   *   item_details, atau array kosong.
   */
  protected function buildItemDetails(OrderInterface $order, $gross_amount): array {
    $currency = $this->getCurrencyCode($order);
    $items = [];
    $sum = 0.0;

    foreach ($order->getItems() as $order_item) {
      $total = $order_item->getTotalPrice();
      if (!$total) {
        continue;
      }
      $line_total = (float) $total->getNumber();
      $quantity = (float) $order_item->getQuantity();
      $unit_price = (float) $order_item->getUnitPrice()->getNumber();

      // Gunakan harga satuan × qty bila tepat, jika tidak pakai total baris.
      if ($quantity >= 1 && floor($quantity) === $quantity && abs(($unit_price * $quantity) - $line_total) < 0.01) {
        $price = $this->toMoney((string) $unit_price, $currency);
        $qty = (int) $quantity;
      }
      else {
        $price = $this->toMoney((string) $line_total, $currency);
        $qty = 1;
      }
      if ($price <= 0) {
        continue;
      }
      $items[] = [
        'id' => 'item-' . $order_item->id(),
        'price' => $price,
        'quantity' => $qty,
        'name' => mb_substr(trim((string) $order_item->getTitle()), 0, 50),
      ];
      $sum += $price * $qty;
    }

    // Ongkos kirim (bila commerce_shipping aktif).
    if ($order->hasField('shipments')) {
      foreach ($order->get('shipments')->referencedEntities() as $shipment) {
        $amount = $shipment->getAdjustedAmount() ?: $shipment->getAmount();
        if (!$amount) {
          continue;
        }
        $price = $this->toMoney((string) $amount->getNumber(), $currency);
        if ($price <= 0) {
          continue;
        }
        $items[] = [
          'id' => 'shipping-' . $shipment->id(),
          'price' => $price,
          'quantity' => 1,
          'name' => 'Ongkos kirim',
        ];
        $sum += $price;
      }
    }

    $difference = (float) $gross_amount - $sum;
    if (abs($difference) < 0.01) {
      return $items;
    }
    if ($difference > 0) {
      $items[] = [
        'id' => 'adjustment',
        'price' => $this->toMoney((string) $difference, $currency),
        'quantity' => 1,
        'name' => 'Biaya lain-lain',
      ];
      return $items;
    }

    // Selisih negatif (diskon/promo): lewati detail item.
    $this->logger()->debug('Midtrans: detail item dilewati karena ada selisih negatif @diff pada order @id.', [
      '@diff' => $difference,
      '@id' => $order->id(),
    ]);
    return [];
  }

  /**
   * Kode mata uang order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   Order.
   *
   * @return string
   *   Kode mata uang.
   */
  protected function getCurrencyCode(OrderInterface $order): string {
    $store = $order->getStore();

    return $store ? strtoupper($store->getDefaultCurrencyCode()) : 'IDR';
  }

  /**
   * Mengubah nominal menjadi angka yang diterima Midtrans.
   *
   * IDR tanpa desimal (integer), mata uang lain dibulatkan 2 desimal.
   *
   * @param string $number
   *   Nominal sebagai string.
   * @param string $currency_code
   *   Kode mata uang.
   *
   * @return int|float
   *   Nominal.
   */
  protected function toMoney(string $number, string $currency_code) {
    return $currency_code === 'IDR' ? (int) round((float) $number) : round((float) $number, 2);
  }

  /**
   * Nominal tagihan order (balance) dalam format Midtrans.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   Order.
   *
   * @return int|float
   *   Nominal.
   */
  protected function getPaymentAmount(OrderInterface $order) {
    $balance = $order->getBalance();

    return $this->toMoney((string) ($balance ? $balance->getNumber() : '0'), $this->getCurrencyCode($order));
  }

  /**
   * Config objek commerce_midtrans.settings.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   Config objek.
   */
  protected function settings() {
    return \Drupal::config('commerce_midtrans.settings');
  }

  /**
   * Mencatat transaksi Snap yang baru dibuat pada data order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   Order.
   * @param string $midtrans_order_id
   *   order_id Midtrans.
   * @param int|float $amount
   *   Nominal.
   * @param string $token
   *   Snap token.
   * @param string $redirect_url
   *   Snap redirect_url.
   */
  protected function recordTransaction(OrderInterface $order, string $midtrans_order_id, $amount, string $token, string $redirect_url): void {
    $data = $order->getData('commerce_midtrans') ?: [];
    $data['midtrans_order_id'] = $midtrans_order_id;
    $data['token'] = $token;
    $data['redirect_url'] = $redirect_url;
    $data['created'] = \Drupal::time()->getRequestTime();
    $data['amount'] = (int) round((float) $amount);
    $data['status'] = 'created';

    $attempts = $data['attempts'] ?? [];
    $attempts[$midtrans_order_id] = [
      'created' => $data['created'],
      'amount' => $data['amount'],
      'status' => 'created',
      'payment_id' => $attempts[$midtrans_order_id]['payment_id'] ?? NULL,
    ];
    // Simpan maksimal 10 percobaan terakhir.
    if (count($attempts) > 10) {
      $attempts = array_slice($attempts, -10, NULL, TRUE);
    }
    $data['attempts'] = $attempts;

    $order->setData('commerce_midtrans', $data);
    $order->save();
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    $midtrans_order_id = (string) ($request->query->get('midtrans_order_id') ?? '');
    if ($midtrans_order_id === '') {
      $data = $order->getData('commerce_midtrans') ?: [];
      $midtrans_order_id = (string) ($data['midtrans_order_id'] ?? '');
    }
    if ($midtrans_order_id === '') {
      $this->logger()->warning('Midtrans onReturn: order @id tidak memiliki midtrans_order_id.', ['@id' => $order->id()]);
      return;
    }

    // Verifikasi langsung ke Midtrans API (status transaksi yang sebenarnya).
    try {
      $transaction = $this->apiClient()->getTransactionStatus($midtrans_order_id, $this->getMode());
    }
    catch (MidtransApiException $e) {
      $this->logger()->warning('Midtrans onReturn (@id): @message', [
        '@id' => $midtrans_order_id,
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addWarning($this->t('Pembayaran belum diselesaikan di Midtrans. Silakan ulangi proses pembayaran.'));
      return;
    }

    $this->applyTransactionStatus($order, $transaction);
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    $raw = (string) $request->getContent();
    $notification = json_decode($raw, TRUE);
    if (!is_array($notification) || empty($notification['order_id'])) {
      $this->logger()->warning('Midtrans onNotify: payload tidak valid: @raw', ['@raw' => mb_substr($raw, 0, 500)]);
      return new Response('Invalid payload', 400);
    }

    $mode = $this->getMidtransMode();
    if (!MidtransApiClient::verifySignature($notification, $this->apiClient()->getServerKey($mode))) {
      $this->logger()->error('Midtrans onNotify: signature_key tidak valid untuk order_id @id (mode @mode).', [
        '@id' => $notification['order_id'],
        '@mode' => $mode,
      ]);
      return new Response('Invalid signature', 401);
    }

    $order = $this->resolveOrder((string) $notification['order_id']);
    if (!$order) {
      $this->logger()->error('Midtrans onNotify: order untuk order_id @id tidak ditemukan.', ['@id' => $notification['order_id']]);
      return new Response('Order not found', 404);
    }

    $this->applyTransactionStatus($order, $notification);

    return new Response('OK', 200);
  }

  /**
   * {@inheritdoc}
   */
  public function onCancel(OrderInterface $order, Request $request) {
    // Batalkan transaksi Snap yang masih pending agar tidak bisa dibayar lagi.
    $data = $order->getData('commerce_midtrans') ?: [];
    $midtrans_order_id = (string) ($data['midtrans_order_id'] ?? '');
    if ($midtrans_order_id !== '') {
      try {
        $this->apiClient()->cancelTransaction($midtrans_order_id, $this->getMode());
      }
      catch (MidtransApiException $e) {
        $this->logger()->notice('Midtrans onCancel: @message', ['@message' => $e->getMessage()]);
      }
    }

    parent::onCancel($order, $request);
  }

  /**
   * {@inheritdoc}
   */
  public function canVoidPayment(PaymentInterface $payment) {
    return $payment->getState()->getId() === 'pending' && $this->getMidtransOrderId($payment) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    $this->assertPaymentState($payment, ['pending']);
    $midtrans_order_id = $this->getMidtransOrderId($payment);
    if ($midtrans_order_id === NULL) {
      throw new PaymentGatewayException('Order ID Midtrans tidak ditemukan untuk pembayaran ini.');
    }

    try {
      $this->apiClient()->cancelTransaction($midtrans_order_id, $this->getMode());
    }
    catch (MidtransApiException $e) {
      throw PaymentGatewayException::createForPayment($payment, $e->getMessage(), $e->getCode(), $e);
    }

    if (!$this->applyTransition($payment, 'void')) {
      throw new PaymentGatewayException('Transaksi Midtrans sudah dibatalkan, tetapi status pembayaran tidak dapat diubah (transisi "void" tidak tersedia).');
    }

    $this->logger()->notice('Midtrans: transaksi @tid dibatalkan (payment @pid).', [
      '@tid' => $midtrans_order_id,
      '@pid' => $payment->id(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function canRefundPayment(PaymentInterface $payment) {
    return $payment->getState()->getId() === 'completed'
      && !$payment->getBalance()->isZero()
      && $this->getMidtransOrderId($payment) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, ?Price $amount = NULL) {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    $amount = $amount ?: $payment->getBalance();
    $this->assertRefundAmount($payment, $amount);

    $midtrans_order_id = $this->getMidtransOrderId($payment);
    if ($midtrans_order_id === NULL) {
      throw new PaymentGatewayException('Order ID Midtrans tidak ditemukan untuk pembayaran ini.');
    }

    try {
      $this->apiClient()->refundTransaction($midtrans_order_id, [
        'amount' => $amount->getNumber(),
        'reason' => 'Refund dari Drupal Commerce',
      ], $this->getMode());
    }
    catch (MidtransApiException $e) {
      throw PaymentGatewayException::createForPayment($payment, $e->getMessage(), $e->getCode(), $e);
    }

    $payment->setRefundedAmount($payment->getRefundedAmount()->add($amount));
    $payment->save();
    if ($payment->getRefundedAmount()->greaterThanOrEqual($payment->getAmount())) {
      $this->applyTransition($payment, 'refund');
    }
    else {
      $this->applyTransition($payment, 'partially_refund');
    }

    $this->logger()->notice('Midtrans: refund @amount pada transaksi @tid (payment @pid).', [
      '@amount' => $amount->getNumber(),
      '@tid' => $midtrans_order_id,
      '@pid' => $payment->id(),
    ]);
  }

  /**
   * Menerapkan status transaksi Midtrans ke payment Commerce.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   Order.
   * @param array $transaction
   *   Data status Midtrans (notifikasi atau response GET status).
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface|null
   *   Payment yang dibuat/di-update, atau NULL.
   */
  public function applyTransactionStatus(OrderInterface $order, array $transaction): ?PaymentInterface {
    $midtrans_order_id = (string) ($transaction['order_id'] ?? '');
    $transaction_status = strtolower((string) ($transaction['transaction_status'] ?? ''));
    $fraud_status = strtolower((string) ($transaction['fraud_status'] ?? 'accept'));
    $transaction_id = (string) ($transaction['transaction_id'] ?? '');
    $gross_amount = (string) ($transaction['gross_amount'] ?? '');

    if ($midtrans_order_id === '' || $transaction_status === '') {
      $this->logger()->warning('Midtrans: data status tanpa order_id/transaction_status.');
      return NULL;
    }

    $payment = $this->loadPayment($order, $midtrans_order_id, $transaction_id);
    $target_state = $this->mapPaymentState($transaction_status, $fraud_status);

    $this->logger()->info('Midtrans: order @oid (order @id) @status/@fraud → @target', [
      '@oid' => $midtrans_order_id,
      '@id' => $order->id(),
      '@status' => $transaction_status,
      '@fraud' => $fraud_status,
      '@target' => $target_state ?? 'ignored',
    ]);

    // Refund / partial_refund (tidak mengubah state utama di sini).
    if ($target_state === NULL) {
      if ($payment && in_array($transaction_status, ['refund', 'partial_refund'], TRUE)) {
        $refunded = (string) ($transaction['refund_amount'] ?? '');
        if ($refunded !== '' && $payment->getState()->getId() === 'completed') {
          $payment->setRefundedAmount(new Price($refunded, $payment->getAmount()->getCurrencyCode()));
          $payment->save();
          if ($payment->getRefundedAmount()->greaterThanOrEqual($payment->getAmount())) {
            $this->applyTransition($payment, 'refund');
          }
          else {
            $this->applyTransition($payment, 'partially_refund');
          }
        }
        $payment->setRemoteState($transaction_status);
        $payment->save();
      }
      $this->recordStatus($order, $midtrans_order_id, $transaction_status, $payment);
      return $payment;
    }

    // Ditolak / dibatalkan / kedaluwarsa.
    if ($target_state === 'voided') {
      if ($payment && in_array($payment->getState()->getId(), ['pending', 'authorization'], TRUE)) {
        $payment->setRemoteState($transaction_status);
        $payment->save();
        $this->applyTransition($payment, 'void');
      }
      $this->recordStatus($order, $midtrans_order_id, $transaction_status, $payment);
      return $payment;
    }

    // Berhasil (completed) / menunggu pembayaran (pending).
    if (!$payment) {
      $payment = $this->createPayment($order, $transaction_id, $transaction_status, $target_state, $gross_amount);
      if (!$payment) {
        $this->recordStatus($order, $midtrans_order_id, $transaction_status, NULL);
        return NULL;
      }
    }
    elseif ($target_state === 'completed' && $payment->getState()->getId() === 'pending') {
      $payment->setRemoteState($transaction_status);
      $payment->save();
      $this->applyTransition($payment, 'receive');
    }
    else {
      $payment->setRemoteState($transaction_status);
      $payment->save();
    }

    $this->recordStatus($order, $midtrans_order_id, $transaction_status, $payment);

    return $payment;
  }

  /**
   * Membuat payment Commerce dari status Midtrans.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   Order.
   * @param string $transaction_id
   *   transaction_id Midtrans.
   * @param string $transaction_status
   *   transaction_status Midtrans.
   * @param string $state
   *   State payment Commerce (pending/completed).
   * @param string $gross_amount
   *   Nominal yang dibayar menurut Midtrans.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface|null
   *   Payment, atau NULL bila tidak dibuat.
   */
  protected function createPayment(OrderInterface $order, string $transaction_id, string $transaction_status, string $state, string $gross_amount): ?PaymentInterface {
    $balance = $order->getBalance();
    if ($balance->isZero()) {
      $this->logger()->notice('Midtrans: order @id sudah lunas, payment baru tidak dibuat (status @status).', [
        '@id' => $order->id(),
        '@status' => $transaction_status,
      ]);
      return NULL;
    }

    $amount = $gross_amount !== '' && (float) $gross_amount > 0
      ? new Price($gross_amount, $balance->getCurrencyCode())
      : $balance;

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entityTypeManager->getStorage('commerce_payment')->create([
      'state' => $state,
      'amount' => $amount,
      'payment_gateway' => $this->parentEntity->id(),
      'order_id' => $order->id(),
      'remote_id' => $transaction_id !== '' ? $transaction_id : NULL,
      'remote_state' => $transaction_status,
    ]);
    $payment->save();

    return $payment;
  }

  /**
   * Mencari payment yang terkait dengan transaksi Midtrans.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   Order.
   * @param string $midtrans_order_id
   *   order_id Midtrans.
   * @param string $transaction_id
   *   transaction_id Midtrans.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface|null
   *   Payment, atau NULL.
   */
  protected function loadPayment(OrderInterface $order, string $midtrans_order_id, string $transaction_id): ?PaymentInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_payment');

    if ($transaction_id !== '') {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('order_id', $order->id())
        ->condition('remote_id', $transaction_id)
        ->range(0, 1)
        ->execute();
      if ($ids) {
        $payment = $storage->load(reset($ids));
        if ($payment instanceof PaymentInterface) {
          return $payment;
        }
      }
    }

    $payment_id = $this->getRecordedPaymentId($order, $midtrans_order_id);
    if ($payment_id) {
      $payment = $storage->load($payment_id);
      if ($payment instanceof PaymentInterface) {
        return $payment;
      }
    }

    return NULL;
  }

  /**
   * Mengubah state/kode status Midtrans menjadi state payment Commerce.
   *
   * @param string $transaction_status
   *   transaction_status Midtrans.
   * @param string $fraud_status
   *   fraud_status Midtrans.
   *
   * @return string|null
   *   "completed", "pending", "voided", atau NULL (refund/chargeback).
   */
  protected function mapPaymentState(string $transaction_status, string $fraud_status): ?string {
    switch ($transaction_status) {
      case 'capture':
        // Kartu: challenge = menunggu review Fraud Detection System Midtrans.
        return $fraud_status === 'challenge' ? 'pending' : 'completed';

      case 'settlement':
        return 'completed';

      case 'pending':
      case 'authorize':
        return 'pending';

      case 'deny':
      case 'cancel':
      case 'expire':
      case 'failure':
        return 'voided';
    }

    return NULL;
  }

  /**
   * Menjalankan transisi state payment bila tersedia.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   Payment.
   * @param string $transition_id
   *   ID transisi (receive/void/refund/partially_refund).
   *
   * @return bool
   *   TRUE bila transisi dijalankan.
   */
  protected function applyTransition(PaymentInterface $payment, string $transition_id): bool {
    $state = $payment->getState();
    $transitions = $state->getTransitions();
    if (!isset($transitions[$transition_id])) {
      $this->logger()->warning('Midtrans: transisi @t tidak tersedia untuk payment @id (state @state).', [
        '@t' => $transition_id,
        '@id' => $payment->id(),
        '@state' => $state->getId(),
      ]);
      return FALSE;
    }

    $state->applyTransition($transitions[$transition_id]);
    $payment->save();

    return TRUE;
  }

  /**
   * Menyelesaikan order_id Midtrans menjadi order Commerce.
   *
   * @param string $midtrans_order_id
   *   order_id Midtrans.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface|null
   *   Order, atau NULL.
   */
  protected function resolveOrder(string $midtrans_order_id): ?OrderInterface {
    $prefix = trim((string) ($this->configuration['order_id_prefix'] ?? 'MID-'));
    if ($prefix === '') {
      $prefix = 'MID-';
    }
    if (!preg_match('/^' . preg_quote($prefix, '/') . '(?<order_id>\d+)-\d+$/', $midtrans_order_id, $matches)) {
      $this->logger()->warning('Midtrans: order_id @oid tidak sesuai pola {prefix}{order_id}-{timestamp}.', ['@oid' => $midtrans_order_id]);
      return NULL;
    }

    $order = $this->entityTypeManager->getStorage('commerce_order')->load((int) $matches['order_id']);

    return $order instanceof OrderInterface ? $order : NULL;
  }

  /**
   * Menyimpan status transaksi terakhir pada data order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   Order.
   * @param string $midtrans_order_id
   *   order_id Midtrans.
   * @param string $status
   *   transaction_status Midtrans.
   * @param \Drupal\commerce_payment\Entity\PaymentInterface|null $payment
   *   Payment terkait (bila ada).
   */
  protected function recordStatus(OrderInterface $order, string $midtrans_order_id, string $status, ?PaymentInterface $payment = NULL): void {
    $data = $order->getData('commerce_midtrans') ?: [];
    $attempts = $data['attempts'] ?? [];
    $attempt = $attempts[$midtrans_order_id] ?? ['created' => \Drupal::time()->getRequestTime()];
    $attempt['status'] = $status;
    $attempt['updated'] = \Drupal::time()->getRequestTime();
    if ($payment) {
      $attempt['payment_id'] = (int) $payment->id();
    }
    $attempts[$midtrans_order_id] = $attempt;

    $data['attempts'] = $attempts;
    if (($data['midtrans_order_id'] ?? NULL) === $midtrans_order_id) {
      $data['status'] = $status;
    }
    $order->setData('commerce_midtrans', $data);
    $order->save();
  }

  /**
   * Payment ID yang tercatat untuk sebuah order_id Midtrans.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   Order.
   * @param string $midtrans_order_id
   *   order_id Midtrans.
   *
   * @return int|null
   *   Payment ID, atau NULL.
   */
  protected function getRecordedPaymentId(OrderInterface $order, string $midtrans_order_id): ?int {
    $data = $order->getData('commerce_midtrans') ?: [];
    $payment_id = $data['attempts'][$midtrans_order_id]['payment_id'] ?? NULL;

    return $payment_id ? (int) $payment_id : NULL;
  }

  /**
   * Mencari order_id Midtrans untuk sebuah payment (untuk void/refund).
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   Payment.
   *
   * @return string|null
   *   order_id Midtrans, atau NULL.
   */
  protected function getMidtransOrderId(PaymentInterface $payment): ?string {
    $order = $payment->getOrder();
    if (!$order instanceof OrderInterface) {
      return NULL;
    }
    $data = $order->getData('commerce_midtrans') ?: [];
    foreach (($data['attempts'] ?? []) as $midtrans_order_id => $attempt) {
      if (!empty($attempt['payment_id']) && (int) $attempt['payment_id'] === (int) $payment->id()) {
        return (string) $midtrans_order_id;
      }
    }

    // Fallback: transaksi terakhir pada order ini.
    return !empty($data['midtrans_order_id']) ? (string) $data['midtrans_order_id'] : NULL;
  }

}
