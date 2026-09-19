# Commerce Ongkir (API.co.id)

Modul custom Drupal 11 + Drupal Commerce untuk menghitung **ongkos kirim** dari
[API Cek Ongkir v2 api.co.id](https://docs.api.co.id/products/indonesia-courier-rates/).

- Endpoint tarif: `GET https://use.api.co.id/courier/v2/rates` (berbasis **kode kecamatan**)
- Endpoint pendukung (gratis): `/courier/v1/locations/provinces`, `/cities`, `/districts`, `/courier/v1/couriers`
- Autentikasi: header `x-api-co-id` → API key dibaca dari **`web/.env`** (`API_CO_ID_KEY`)
- Biaya API: Rp 5 per panggilan sukses (dipanggil dari server, bukan browser)

## 1. Isi API key di `.env`

`web/.env` (jangan di-commit):

```dotenv
API_CO_ID_KEY=isi_api_key_dari_dashboard_api_co_id
```

`docker compose` mengirim file `.env` ke container php-fpm (`env_file`), dan
modul membacanya dengan `getenv('API_CO_ID_KEY')` — jadi API key **tidak** masuk
database. Urutan pembacaan:

1. `API_CO_ID_KEY` (environment / `.env`)
2. `$settings['commerce_ongkir_api_key']` di `sites/default/settings.php`
3. kolom “API key (fallback)” di `/admin/commerce/config/ongkir` (tersimpan di config)

Setelah mengubah `.env`, restart container agar environment ter-refresh:

```bash
cd /home/projects/drupal/web && docker compose up -d
```

Uji koneksi: **Commerce → Configuration → Cek Ongkir (API.co.id)**
(`/admin/commerce/config/ongkir`) → tombol **Simpan & tes koneksi**.

## 2. Struktur

```
commerce_ongkir/
├── commerce_ongkir.info.yml / .services.yml / .routing.yml / .links.menu.yml / .install
├── config/install/commerce_ongkir.settings.yml    # base URL, timeout, cache TTL, API key fallback
├── config/schema/commerce_ongkir.schema.yml       # schema konfigurasi modul + plugin
└── src/
    ├── OngkirApiClient(.php|Interface)            # HTTP client semua endpoint (cached)
    ├── OngkirApiException.php                     # error API (401/402/429/…)
    ├── DistrictResolver(.php|Interface)           # penentu kode kecamatan tujuan
    ├── Form/SettingsForm.php                      # /admin/commerce/config/ongkir
    ├── Plugin/Commerce/ShippingMethod/Ongkir.php  # ★ plugin shipping method (@CommerceShippingMethod id: ongkir)
    └── Plugin/Field/FieldWidget/KecamatanWidget.php # dropdown provinsi → kota → kecamatan
```

## 3. Field kode kecamatan (tujuan)

API v2 membutuhkan **kode kecamatan** tujuan, sedangkan form alamat Commerce
tidak menyimpannya. Saat modul di-install, modul otomatis:

1. membuat field `field_kecamatan` (string) pada profile **customer**, dan
2. menempelkan widget **Ongkir: provinsi / kota / kecamatan** pada:
   - form display `profile.customer.default` dan `.shipping` → muncul di
     *Shipping information* saat checkout,
   - form display `profile.customer.billing` → **tidak** diminta (dibuat
     terpisah agar alamat penagihan tetap singkat).

Widget menyimpan **kode kecamatan 6 digit** (mis. `317405`) dan mengambil
daunnya bertingkat dari endpoint lokasi API (di-cache 24 jam). Bila API tidak
dapat dihubungi, widget otomatis mundur ke input manual kode kecamatan supaya
checkout tidak terhenti.

## 4. Membuat shipping method

1. **Commerce → Configuration → Shipping → Shipping methods → Add shipping method**
   (`/admin/commerce/config/shipping-methods/add`).
2. **Plugin**: `Ongkir (API.co.id)`; isi **Name** dan pilih **Store**.
3. Tab **Configure**:
   | Field | Isi |
   |---|---|
   | Kode kecamatan asal (`origin_district_code`) | wajib, contoh `317405` |
   | Filter kurir | opsional, koma, mis. `jne,jnt,sicepat` |
   | Filter layanan | opsional, koma, mis. `reg` |
   | Asuransi / item_value | opsional (nilai barang diambil dari shipment) |
   | Sumber kode kecamatan tujuan | `Field pada profile pengiriman` + `field_kecamatan` |
   | Berat | field berat produk `field_berat`, satuan auto, berat default 1 kg |
   | Markup | persen/`Rp` bila ingin menaikkan tarif |
   | Cache TTL tarif | default 300 detik (menghemat biaya Rp 5/panggilan) |
4. **Order type**: pada *Commerce → Configuration → Order types → Add/Edit order
   type* pastikan **Shipment type** = `default` (sudah diisi pada situs ini).
5. **Checkout flow**: order type harus memakai flow yang memuat pane
   **Shipping information** (pane inilah yang menampilkan alamat + pilihan
   kurir, dan otomatis membuat shipment).
   - Situs ini punya flow `Shipping` bawaan commerce_shipping
     (login → contact → **shipping_information** → review → completion → summary)
     yang bisa dipilih pada *Order types → Checkout flow*; atau
   - tambahkan pane `Shipping information` ke flow yang sedang dipakai
     (mis. flow `default`) lewat *Checkout flows → Manage panes*.
6. Setelah mengubah alamat di checkout, klik **Recalculate shipping** (atau biarkan
   *auto recalculate* aktif) untuk memanggil API.

Hasil perhitungan muncul sebagai daftar pilihan kurir di checkout, mis.
“JNE Express - REG  •  Estimasi 2-3 hari  •  Termurah”.

## 5. Cara kerja perhitungan

1. **Origin**: `origin_district_code` pada konfigurasi plugin.
2. **Tujuan**: `DistrictResolver` (default: field `field_kecamatan` pada profile
   pengiriman; fallback: billing profile → peta manual → `dependent_locality` alamat).
3. **Berat**: berat shipment Commerce → bila 0, hitung dari `field_berat` produk
   (teks “500 gram”/“1,5 kg” ikut dikenali) × qty → fallback berat default/unit.
   Minimal 0,1 kg (syarat API).
4. **Request** `GET /courier/v2/rates` dengan `x-api-co-id`, di-cache sesuai
   “Cache TTL tarif”.
5. **Response** dipetakan ke `ShippingRate` (satu rate per kurir + layanan,
   service id `kurir.layanan`) beserta `quote_id`, `etd`, penanda
   termurah/tercepat di deskripsi.

Contoh request manual:

```bash
curl -H "x-api-co-id: $API_CO_ID_KEY" \
  "https://use.api.co.id/courier/v2/rates?origin_district_code=317405&destination_district_code=317305&weight=1"
```

## 6. Troubleshooting

| Gejala | Penyebab / solusi |
|---|---|
| Tidak ada pilihan kurir di checkout | Cek **Watchdog** (`commerce_ongkir`): kode kecamatan tujuan kosong, kode asal salah, atau API key belum diisi. |
| `API key Cek Ongkir belum diisi` | Isi `API_CO_ID_KEY` di `web/.env` lalu `docker compose up -d`. |
| `HTTP 401` | API key salah. |
| `HTTP 402` | Saldo akun api.co.id habis (Rp 5/panggilan sukses). |
| `HTTP 429` | Rate limit — naikkan Cache TTL tarif. |
| Dropdown kecamatan jadi input manual | Endpoint lokasi gagal diakses (cek API key/koneksi), atau nilai tersimpan di luar daftar API. |

Hapus cache modul (mis. setelah mengubah daftar kurir):

```bash
cd /home/projects/drupal/web && make drush CMD="cache:rebuild"
```
