<?php

namespace Drupal\commerce_ongkir;

/**
 * Interface untuk client API Cek Ongkir v2 api.co.id.
 *
 * Dokumentasi: https://docs.api.co.id/products/indonesia-courier-rates/
 *
 * - Endpoint tarif : GET /courier/v2/rates (berbasis kode kecamatan).
 * - Endpoint lokasi: GET /courier/v1/locations/{provinces,cities,districts}.
 * - Endpoint kurir : GET /courier/v1/couriers.
 * - Autentikasi    : header x-api-co-id (wajib).
 */
interface OngkirApiClientInterface {

  /**
   * Mengecek tarif ongkir (GET /courier/v2/rates).
   *
   * @param string $origin_district_code
   *   Kode kecamatan asal, contoh "317405".
   * @param string $destination_district_code
   *   Kode kecamatan tujuan, contoh "317305".
   * @param float $weight_kg
   *   Berat paket dalam kilogram (> 0).
   * @param array $options
   *   Opsi tambahan: item_value (int), insurance (bool), length, width,
   *   height (int, cm).
   * @param int $cache_ttl
   *   TTL cache dalam detik (0 = tanpa cache).
   *
   * @return array
   *   Array berisi "quote_id" dan "rates" (daftar tarif per kurir & layanan).
   *
   * @throws \Drupal\commerce_ongkir\OngkirApiException
   *   Bila API key kosong atau API mengembalikan error.
   */
  public function getRates(string $origin_district_code, string $destination_district_code, float $weight_kg, array $options = [], int $cache_ttl = 0): array;

  /**
   * Daftar provinsi (GET /courier/v1/locations/provinces).
   *
   * @return array
   *   Daftar array dengan key "code" dan "name".
   *
   * @throws \Drupal\commerce_ongkir\OngkirApiException
   *   Bila API key kosong atau API mengembalikan error.
   */
  public function getProvinces(): array;

  /**
   * Daftar kota/kabupaten dalam provinsi.
   *
   * @param string $province_code
   *   Kode provinsi, contoh "31".
   *
   * @return array
   *   Daftar array dengan key "code" dan "name".
   *
   * @throws \Drupal\commerce_ongkir\OngkirApiException
   *   Bila API key kosong atau API mengembalikan error.
   */
  public function getCities(string $province_code): array;

  /**
   * Daftar kecamatan dalam kota/kabupaten.
   *
   * @param string $city_code
   *   Kode kota/kabupaten, contoh "3174".
   *
   * @return array
   *   Daftar array dengan key "code" dan "name". Key "code" inilah yang
   *   dipakai sebagai origin/destination_district_code.
   *
   * @throws \Drupal\commerce_ongkir\OngkirApiException
   *   Bila API key kosong atau API mengembalikan error.
   */
  public function getDistricts(string $city_code): array;

  /**
   * Daftar kurir yang tersedia (GET /courier/v1/couriers).
   *
   * @return array
   *   Daftar array dengan key "code", "name", "logo", dan "cod".
   *
   * @throws \Drupal\commerce_ongkir\OngkirApiException
   *   Bila API key kosong atau API mengembalikan error.
   */
  public function getCouriers(): array;

  /**
   * Mengambil API key yang aktif dipakai.
   *
   * Urutan pencarian: environment variable API_CO_ID_KEY (dari web/.env),
   * lalu $settings['commerce_ongkir_api_key'] di settings.php, lalu konfigurasi
   * tersimpan (admin/commerce/config/ongkir).
   *
   * @return string
   *   API key, atau string kosong bila belum ada.
   */
  public function getApiKey(): string;

  /**
   * Mengambil asal API key yang aktif (untuk pesan di form konfigurasi).
   *
   * @return string
   *   Salah satu dari "environment", "settings.php", "config", atau "none".
   */
  public function getApiKeySource(): string;

  /**
   * Mengecek apakah API key sudah tersedia.
   *
   * @return bool
   *   TRUE bila API key tersedia.
   */
  public function isConfigured(): bool;

}
