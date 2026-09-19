<?php

namespace Drupal\commerce_midtrans;

/**
 * Interface client Midtrans (Snap API + Core API).
 *
 * Dokumentasi:
 * - Quick start : https://docs.midtrans.com/reference/quick-start-1
 * - Snap API    : POST {snap_base_url}/transactions → token + redirect_url
 * - Core API    : GET/POST {api_base_url}/v2/{order_id}/{status,cancel,refund}
 * - Notifikasi  : signature_key = SHA512(order_id + status_code + gross_amount + ServerKey)
 *
 * Sandbox:
 * - Core API : https://api.sandbox.midtrans.com
 * - Snap API : https://app.sandbox.midtrans.com/snap/v1
 * - Snap.js  : https://app.sandbox.midtrans.com/snap/snap.js
 *
 * Production:
 * - Core API : https://api.midtrans.com
 * - Snap API : https://app.midtrans.com/snap/v1
 * - Snap.js  : https://app.midtrans.com/snap/snap.js
 */
interface MidtransApiClientInterface {

  /**
   * Mode default (dari env MIDTRANS_MODE / config).
   *
   * @return string
   *   "sandbox" atau "production".
   */
  public function getDefaultMode(): string;

  /**
   * Server key untuk mode tertentu.
   *
   * Urutan: env (MIDTRANS_SERVER_KEY[_LIVE]) → settings.php → config.
   *
   * @param string|null $mode
   *   "sandbox"/"production"/"test"/"live" (NULL = mode gateway).
   *
   * @return string
   *   Server key, atau string kosong.
   */
  public function getServerKey(?string $mode = NULL): string;

  /**
   * Client key (dipakai Snap.js di browser).
   *
   * @param string|null $mode
   *   Mode (NULL = mode gateway).
   *
   * @return string
   *   Client key, atau string kosong.
   */
  public function getClientKey(?string $mode = NULL): string;

  /**
   * Asal kredensial yang aktif (untuk pesan di form konfigurasi).
   *
   * @param string|null $mode
   *   Mode (NULL = mode gateway).
   *
   * @return string
   *   "environment", "environment-live", "settings.php", "config", atau "none".
   */
  public function getCredentialSource(?string $mode = NULL): string;

  /**
   * Base URL Core API. @see \Drupal\commerce_midtrans\MidtransApiClientInterface */
  public function getApiBaseUrl(?string $mode = NULL): string;

  /**
   * Base URL Snap API (tanpa trailing slash). @see \Drupal\commerce_midtrans\MidtransApiClientInterface */
  public function getSnapBaseUrl(?string $mode = NULL): string;

  /**
   * URL snap.js untuk mode tertentu. @see \Drupal\commerce_midtrans\MidtransApiClientInterface */
  public function getSnapJsUrl(?string $mode = NULL): string;

  /**
   * Apakah server key untuk mode tersebut tersedia.
   *
   * @param string|null $mode
   *   Mode (NULL = mode gateway).
   *
   * @return bool
   *   TRUE bila server key tersedia.
   */
  public function isConfigured(?string $mode = NULL): bool;

  /**
   * Membuat transaksi Snap (mendapat token + redirect_url).
   *
   * @param array $params
   *   Payload Snap (transaction_details, item_details, customer_details, callbacks, dll).
   * @param string|null $mode
   *   Mode (NULL = mode gateway).
   *
   * @return array
   *   Response Snap: token, redirect_url.
   *
   * @throws \Drupal\commerce_midtrans\MidtransApiException
   *   Bila kredensial kosong atau API menolak.
   */
  public function createTransaction(array $params, ?string $mode = NULL): array;

  /**
   * Mengambil status transaksi (Core API GET /v2/{order_id}/status).
   *
   * @param string $midtrans_order_id
   *   order_id yang dikirim ke Midtrans.
   * @param string|null $mode
   *   Mode (NULL = mode gateway).
   *
   * @return array
   *   Data status transaksi (transaction_status, fraud_status, gross_amount, dll).
   *
   * @throws \Drupal\commerce_midtrans\MidtransApiException
   *   Bila kredensial kosong, transaksi tidak ditemukan, atau API menolak.
   */
  public function getTransactionStatus(string $midtrans_order_id, ?string $mode = NULL): array;

  /**
   * Membatalkan transaksi (Core API POST /v2/{order_id}/cancel).
   *
   * @param string $midtrans_order_id
   *   order_id yang dikirim ke Midtrans.
   * @param string|null $mode
   *   Mode (NULL = mode gateway).
   *
   * @return array
   *   Response Midtrans.
   *
   * @throws \Drupal\commerce_midtrans\MidtransApiException
   *   Bila pembatalan gagal.
   */
  public function cancelTransaction(string $midtrans_order_id, ?string $mode = NULL): array;

  /**
   * Refund transaksi (Core API POST /v2/{order_id}/refund).
   *
   * @param string $midtrans_order_id
   *   order_id yang dikirim ke Midtrans.
   * @param array $params
   *   Parameter refund (amount, reason).
   * @param string|null $mode
   *   Mode (NULL = mode gateway).
   *
   * @return array
   *   Response Midtrans.
   *
   * @throws \Drupal\commerce_midtrans\MidtransApiException
   *   Bila refund gagal.
   */
  public function refundTransaction(string $midtrans_order_id, array $params = [], ?string $mode = NULL): array;

  /**
   * Menguji kredensial server key dengan memanggil Core API.
   *
   * @param string|null $mode
   *   Mode (NULL = mode gateway).
   *
   * @return array
   *   ['ok' => bool, 'message' => string, 'status_code' => int].
   */
  public function testCredentials(?string $mode = NULL): array;

}
