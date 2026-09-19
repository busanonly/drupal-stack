<?php

namespace Drupal\commerce_midtrans\PluginForm\MidtransSnap;

use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form pembayaran Midtrans Snap (popup Snap.js atau redirect ke Snap).
 */
class PaymentOffsiteForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    $order = $payment->getOrder();
    /** @var \Drupal\commerce_midtrans\Plugin\Commerce\PaymentGateway\MidtransSnap $plugin */
    $plugin = $payment->getPaymentGateway()->getPlugin();

    // Membuat transaksi Snap (token + redirect_url). Bila gagal, plugin
    // melempar PaymentGatewayException dan checkout kembali ke langkah
    // sebelumnya dengan pesan error.
    $transaction = $plugin->getOrCreateTransaction(
      $order,
      (string) $form['#return_url'],
      (string) $form['#cancel_url']
    );

    if ($plugin->getUiMode() === 'redirect') {
      // Mode redirect: kirim pembeli ke halaman pembayaran Snap (hosted).
      return $this->buildRedirectForm($form, $form_state, $transaction['redirect_url'], [], self::REDIRECT_GET);
    }

    // Mode popup: panggil snap.pay() di browser.
    $client_key = $plugin->getClientKey();
    if ($client_key === '') {
      throw new PaymentGatewayException('Client key Midtrans belum diisi (env MIDTRANS_CLIENT_KEY) sehingga snap.js tidak dapat dimuat.');
    }

    // snap.js diambil dari URL sesuai mode gateway (sandbox/production).
    $form['#attached']['html_head'][] = [
      [
        '#tag' => 'script',
        '#attributes' => [
          'src' => $plugin->getSnapJsUrl(),
          'data-client-key' => $client_key,
        ],
      ],
      'commerce_midtrans_snap_js',
    ];
    $form['#attached']['library'][] = 'commerce_midtrans/snap_popup';
    $form['#attached']['drupalSettings']['commerceMidtrans'] = [
      'token' => $transaction['token'],
      'returnUrl' => $transaction['return_url'],
      'cancelUrl' => $transaction['cancel_url'],
      'autoOpen' => $plugin->isAutoOpen(),
    ];

    $form['commerce_midtrans'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['commerce-midtrans-snap']],
      '#weight' => -10,
    ];
    $form['commerce_midtrans']['help'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Pembayaran diproses oleh Midtrans (kartu kredit, virtual account, e-wallet, QRIS). Jendela pembayaran akan terbuka otomatis — klik tombol di bawah bila jendela tersebut tertutup.'),
    ];
    $form['commerce_midtrans']['pay'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Bayar dengan Midtrans'),
      '#attributes' => [
        'type' => 'button',
        'class' => ['button', 'button--primary'],
        'data-commerce-midtrans-pay' => '1',
      ],
    ];
    $form['commerce_midtrans']['status'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => '',
      '#attributes' => [
        'class' => ['commerce-midtrans-status'],
        'data-commerce-midtrans-status' => '1',
        'hidden' => 'hidden',
      ],
    ];
    $form['commerce_midtrans']['reference'] = [
      '#type' => 'html_tag',
      '#tag' => 'small',
      '#value' => $this->t('Referensi Midtrans: @id', ['@id' => $transaction['midtrans_order_id']]),
      '#attributes' => ['class' => ['commerce-midtrans-reference']],
    ];

    return $form;
  }

}
