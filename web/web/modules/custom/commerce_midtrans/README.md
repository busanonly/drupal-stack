# Commerce Midtrans (Snap)

Modul custom Drupal 11 + Drupal Commerce untuk **payment gateway Midtrans Snap**
(popup Snap.js atau redirect), termasuk webhook notifikasi, void dan refund.
Kredensial dibaca dari **`web/.env`**.

| Item | Nilai |
|---|---|
| Snap API | `POST https://app.sandbox.midtrans.com/snap/v1/transactions` → `token`, `redirect_url` |
| Core API | `https://api.sandbox.midtrans.com/v2/{order_id}/{status,cancel,refund}` |
| snap.js | `https://app.sandbox.midtrans.com/snap/snap.js` (`data-client-key`) |
| Production | `https://app.midtrans.com/snap/v1`, `https://api.midtrans.com`, `https://app.midtrans.com/snap/snap.js` |
| Auth | Basic auth: `base64(ServerKey + ":")` pada header `Authorization` |
| Notifikasi | `signature_key = SHA512(order_id + status_code + gross_amount + ServerKey)` |

Dokumentasi Midtrans: <https://docs.midtrans.com/reference/quick-start-1>

## 1. Kredensial di `.env`

`web/.env` (jangan di-commit):

```dotenv
MIDTRANS_MODE=sandbox
MIDTRANS_SERVER_KEY=SB-Mid-server-xxxxxxxxxxxx
MIDTRANS_CLIENT_KEY=SB-Mid-client-xxxxxxxxxxxx
# untuk production (opsional selama masih sandbox)
MIDTRANS_SERVER_KEY_LIVE=
MIDTRANS_CLIENT_KEY_LIVE=
```

Kredensial diambil dari dashboard Midtrans → **SETTINGS → Access Keys**.
Variabel `*_LIVE` dipakai saat gateway berada pada mode **Live**; bila kosong,
mode Live memakai variabel tanpa `_LIVE`.

Setelah mengubah `.env`, restart container agar environment ter-refresh:

```bash
cd /home/projects/drupal/web && docker compose up -d
```

Urutan pembacaan kredensial:
1. environment (`MIDTRANS_SERVER_KEY[_LIVE]` dari `.env`)
2. `$settings['commerce_midtrans_server_key']` di `settings.php`
3. kolom fallback di `/admin/commerce/config/midtrans`

## 2. Konfigurasi modul

**Commerce → Configuration → Midtrans (Snap)** (`/admin/commerce/config/midtrans`):

- **Mode default** (sandbox/production) — dipakai sebagai nilai awal; bisa juga dipaksa lewat env `MIDTRANS_MODE`.
- **Server/Client key fallback** (opsional; utamakan `.env`).
- **3D Secure** untuk pembayaran kartu (default aktif).
- **Endpoint** Core API, Snap API, dan snap.js untuk sandbox & production.
- **Timeout** dan **log request (debug)**.
- Tombol **Simpan & tes kredensial** memanggil Core API: `401` = server key salah,
  `404/200` = server key valid.

## 3. Membuat payment gateway

**Commerce → Configuration → Payment → Payment gateways → Add payment gateway**
(`/admin/commerce/config/payment-gateways/add`):

1. Pilih **Midtrans (Snap)**, isi **Name** (label di checkout) — mis. "Midtrans".
2. **Mode**: `Test` (sandbox) atau `Live` (production).
3. Tab **Configure**:

   | Field | Keterangan |
   |---|---|
   | Mode tampilan | **Popup Snap.js** (pembeli tetap di checkout) atau **Redirect** ke halaman Snap |
   | Buka popup otomatis | buka jendela pembayaran saat halaman pembayaran dimuat |
   | Prefix order ID | default `MID-`; order_id ke Midtrans = `{prefix}{order_id}-{timestamp}` |
   | Channel pembayaran | opsional, koma, mis. `bank_transfer,gopay,qris` (kosong = semua channel aktif) |
   | Masa berlaku pembayaran | jam (default 24, 0 = default Midtrans) |
   | Pakai ulang token Snap | menit (default 60, mencegah transaksi baru tiap refresh) |
   | Kirim detail item | nama produk/qty/harga ke Snap |

4. Pastikan order type memakai checkout flow yang memuat pane **Payment process**
   (pane inilah yang menampilkan form pembayaran).

## 4. Notification URL di dashboard Midtrans

Login ke dashboard Midtrans → **SETTINGS → CONFIGURATION** → **Payment Notification URL**:

```
https://<domain-anda>/payment/notify/<id-payment-gateway>
```

Nilai persisnya ditampilkan pada tab **Configure** gateway (baris *Notification URL*),
mis. `https://toko.example.com/payment/notify/midtrans`. Jalur ini ditangani
Commerce (`commerce_payment.notify`) → `MidtransSnap::onNotify()`.

- Notifikasi diverifikasi dengan **signature_key** (SHA512). Signature tidak valid → HTTP 401.
- `order_id` Midtrans dipetakan kembali ke order lewat pola prefix (tanpa query database tambahan).
- HTTP 200 dikembalikan setelah status diproses; `404` bila order tidak ditemukan, `400` bila payload rusak.

## 5. Alur pembayaran

1. Checkout → langkah **Payment**: modul membuat transaksi Snap (token + redirect_url)
   dan menyimpannya di `order.data.commerce_midtrans`.
2. **Popup**: `snap.pay(token, {...})`. **Redirect**: pembeli diarahkan ke halaman Snap.
3. Setelah membayar, pembeli kembali ke `/checkout/{order}/{step}/return?midtrans_order_id=...`
   (`onReturn()` memverifikasi status langsung ke Core API).
4. Midtrans juga mengirim **HTTP notification** (`onNotify()`) — sumber kebenaran utama;
   pembayaran tetap tercatat walaupun pembeli menutup browser.
5. Payment Commerce dibuat dengan state:

   | Status Midtrans | fraud_status | State payment |
   |---|---|---|
   | `capture` | `accept` | `completed` |
   | `capture` | `challenge` | `pending` |
   | `settlement` | — | `completed` |
   | `pending`, `authorize` | — | `pending` |
   | `deny`, `cancel`, `expire`, `failure` | — | `voided` (dari `pending`) |
   | `refund`, `partial_refund` | — | `refunded` / `partially_refunded` |

6. Bila payment menutup seluruh balance, Commerce otomatis menandai order **paid**
   dan **menempatkan order** (subscriber `OrderPaidSubscriber` untuk gateway off-site),
   sehingga order tidak tertinggal di checkout saat pembeli tidak kembali ke situs.

## 6. Void & refund dari admin

- **Void** (batalkan) tersedia untuk payment ber-state `pending` →
  `POST /v2/{order_id}/cancel` ke Midtrans lalu state payment menjadi `voided`.
- **Refund** tersedia untuk payment `completed` → `POST /v2/{order_id}/refund`
  (nominal diisi di form), lalu `refunded_amount` dan state
  (`partially_refunded`/`refunded`) diperbarui.
- Catatan: refund/void memerlukan fitur yang aktif di akun Midtrans Anda
  (mis. refund untuk sebagian metode pembayaran harus diaktifkan oleh Midtrans).

## 7. Troubleshooting

| Gejala | Penyebab / solusi |
|---|---|
| "Server key Midtrans mode sandbox belum diisi" | isi `MIDTRANS_SERVER_KEY` di `web/.env` lalu `docker compose up -d` |
| `401 Access denied due to unauthorized transaction` | server key salah / masih dummy |
| Pembayaran tidak muncul sebagai completed | cek **Watchdog** kanal `commerce_midtrans` dan pastikan Notification URL di dashboard Midtrans benar serta bisa diakses publik (tanpa basic auth) |
| Signature tidak valid (401) | server key gateway berbeda dengan akun Midtrans yang mengirim notifikasi (sandbox vs production) |
| Popup tidak terbuka | client key belum diisi, atau CSP/browser memblokir `app.sandbox.midtrans.com` |
| Tombol "Bayar dengan Midtrans" tidak melakukan apa pun | snap.js gagal dimuat (cek console browser, `log_requests` di konfigurasi modul) |

Log debug: aktifkan **log request** di `/admin/commerce/config/midtrans`, lalu lihat
`drush watchdog:show --type=commerce_midtrans`.
