# Blank Theme (`blank_theme`)

Theme kosongan **Drupal 11 tanpa base theme** (`base theme: false`), dibuat dari
`scripts/theme-template/` lewat `scripts/new-theme.sh`. Semua markup dirender oleh
template di folder ini — tidak ada CSS/markup bawaan yang diwarisi.

## Mengaktifkan

```bash
# UI:  /admin/appearance  → "Install and set as default"
# Drush:
drush theme:enable blank_theme -y
drush config:set system.theme default blank_theme -y
drush cr
```

## Struktur file

```
blank_theme/
├── blank_theme.info.yml         # metadata, region, library global
├── blank_theme.libraries.yml    # definisi CSS/JS (library `base`, `header`, `hero`)
├── blank_theme.theme            # hook PHP (preprocess header/hero, theme settings)
├── logo.svg                     # logo bawaan (maskot beruang, blok Site branding)
├── css/
│   ├── base.css                 # variabel desain (palet) + layout dasar
│   ├── header.css               # gaya bar atas (brand, menu, pencarian, CTA)
│   └── hero.css                 # gaya hero halaman depan (foto boneka)
├── images/
│   ├── hero-boneka.webp         # foto hero yang dipakai (1276×734, ~127 KB)
│   └── hero-boneka.png          # gambar sumber (hanya untuk membuat ulang .webp)
├── js/header.js                 # tombol menu untuk layar kecil (< 1200px)
├── config/
│   ├── schema/blank_theme.schema.yml     # schema config blank_theme.settings
│   └── install/blank_theme.settings.yml  # nilai bawaan pengaturan header + hero
└── templates/
    ├── html.html.twig                     # <html>/<head>/<body>
    ├── page.html.twig                     # region + title + tabs + messages
    ├── header.html.twig                   # bar atas (brand + menu + search + CTA)
    ├── hero.html.twig                     # hero halaman depan (di bawah header)
    └── block--system-branding-block.html.twig  # wordmark + tagline
```

## Header situs

Bar atas (putih, sticky) berisi 4 bagian: **brand** · **menu** · **pencarian** ·
**tombol CTA**. Markup-nya di `templates/header.html.twig`, gaya di
`css/header.css`, interaksi di `js/header.js`.

| Bagian | Sumber |
|---|---|
| Brand (logo + nama + tagline) | region **header** → blok *Site branding* (`block--system-branding-block.html.twig`). Nama situs dipecah: kata pertama hitam, sisanya warna aksen (pink) → "SUPPLIER" + "NONEKA". Logo dari `logo.svg` (dapat diganti di Appearance > Settings → Logo image). |
| Menu | region **primary_menu** → blok *Main navigation* (menu **Main**). Bila region kosong, dipakai daftar tautan dari pengaturan theme (`header_nav_links`). |
| Pencarian | region **header_search** (opsional). Bila kosong, dipakai form bawaan theme: `GET` ke `header_search_action` (default `/search/node`, butuh modul core *Search*) dengan parameter `keys`. |
| CTA "Hubungi Kami" | Pengaturan theme (`header_cta_label`, `header_cta_url`, `header_whatsapp`). Bila `header_whatsapp` diisi (format internasional tanpa `+`), tautan otomatis menjadi `https://wa.me/<nomor>`. |

### Pengaturan theme (Appearance > Settings)

Disimpan di config `blank_theme.settings`, diubah lewat
`blank_theme_form_system_theme_settings_alter()` (lihat `blank_theme.theme`),
dengan nilai bawaan di `config/install/blank_theme.settings.yml`:

| Key | Fungsi |
|---|---|
| `header_tagline` | tagline di bawah nama situs (dipakai bila *Slogan* di Site information kosong) |
| `header_nav_links` | menu bawaan theme: satu baris `Label\|path` per tautan (fallback bila region Primary menu kosong) |
| `header_search_enabled` | tampilkan form pencarian bawaan |
| `header_search_placeholder` | placeholder kotak pencarian (default: `Cari boneka...`) |
| `header_search_action` | tujuan submit form pencarian (default: `/search/node`) |
| `header_cta_enabled` | tampilkan tombol CTA |
| `header_cta_label` | label tombol CTA (default: `Hubungi Kami`) |
| `header_cta_url` | tujuan tombol CTA bila nomor WhatsApp kosong |
| `header_whatsapp` | nomor WhatsApp (internasional, tanpa `+`) → tautan `wa.me` |
| `header_sticky` | header menempel di atas saat scroll |

### Layout blok yang dipakai

Bar atas hanya memuat brand, jadi blok berikut diatur di **Block layout**
(`/admin/structure/block`) saat header dipasang:

| Blok | Region |
|---|---|
| Site branding | `header` |
| Main navigation | `primary_menu` |
| Page title | `page_title` (judul halaman, dirender tepat di atas konten) |
| Breadcrumbs / Main page content / Status messages | `breadcrumb` / `content` / `highlighted` |
| Help | `help` |
| Powered by Drupal, User account menu, Local tasks | tidak dipakai (nonaktif) — login tetap bisa lewat `/user/login` |

`page_title` adalah region tambahan di `blank_theme.info.yml` supaya judul
halaman tidak ikut tampil di bar atas.

### Warna & logo

- Palet: variabel di `css/base.css` (`--color-accent: #e55374`, `--color-text`,
  `--color-text-muted`, `--color-border`, `--header-max-width`). Ubah di satu
  tempat untuk menyesuaikan seluruh header.
- Logo: ganti di *Appearance > Settings > Logo image* (atau timpa `logo.svg`).
- Font memakai system font stack; untuk font brand, tambahkan `@font-face` /
  `<link>` Google Fonts lalu ubah `--font-base`.

### Perilaku responsif

- ≥ 1200px: brand, menu, pencarian, dan CTA sejajar dalam satu baris
  (lebar maksimum `--header-max-width`, 1320px).
- < 1200px: menu diganti tombol hamburger; panel menurun di bawah bar berisi
  menu + pencarian + CTA. Panel menutup otomatis saat link diklik, klik di luar,
  tombol `Esc`, atau saat viewport melebar ke desktop.

### Catatan saat mengubah CSS/template

- Drupal meng-agregasi CSS/JS dan meng-cache halaman untuk pengunjung anonim,
  jadi setelah mengubah `css/*.css`, template, atau `*.libraries.yml` lakukan
  rebuild cache (`drush cr`, atau UI: Configuration > Development > Performance
  > Clear all caches) supaya perubahan langsung terlihat.
- Tautan menu (`/produk`, `/tentang-kami`, `/cara-order`, `/kontak`) baru
  mengarah ke halaman yang ada setelah halaman tersebut dibuat; sebelum itu
  tautannya 404 — ubah di Structure > Menus > Main navigation bila perlu.
- Form pencarian baru berfungsi setelah modul core **Search** dipasang
  (Extend → Search), atau arahkan `header_search_action` ke path View pencarian.

## Hero halaman depan

Bagian lebar-penuh tepat di bawah header: label kecil, judul dua baris, paragraf
pembuka, dua tombol, daftar keunggulan, plus dekorasi tulisan tangan dan bulatan
pink di sisi kanan. Markup: `templates/hero.html.twig` (dirujuk dari
`templates/page.html.twig`), gaya: `css/hero.css`, foto: `images/hero-boneka.webp`.

Hero **tidak** memakai region — isinya diambil dari pengaturan theme, jadi tidak
dipindah lewat Block layout. Hero hanya dirender bila aktif (lihat
`blank_theme_hero()` di `blank_theme.theme`), dan CSS-nya dimuat per-halaman lewat
`attach_library('blank_theme/hero')` sehingga tidak ikut terunduh di halaman lain.

| Bagian | Sumber |
|---|---|
| Label kecil (tampil kapital) | `hero_eyebrow` |
| Judul baris 1 (gelap) | `hero_title` |
| Judul baris 2 (warna aksen) | `hero_title_accent` |
| Paragraf pembuka | `hero_lead` |
| Tombol utama | `hero_cta_label` + `hero_cta_url` |
| Tombol kedua (WhatsApp/kontak) | `hero_secondary_label` + `hero_secondary_url`; otomatis `https://wa.me/...` bila `header_whatsapp` diisi |
| Daftar keunggulan | `hero_features`, satu baris `Label\|ikon` per item |
| Tulisan tangan di kanan | `hero_note`, satu baris teks per baris |
| Bulatan pink | `hero_badge`, satu baris teks per baris |
| Foto | `hero_image` (kosong = `images/hero-boneka.webp`) |

### Pengaturan theme (Appearance > Settings → Hero halaman depan)

| Key | Fungsi |
|---|---|
| `hero_enabled` | tampilkan hero |
| `hero_front_only` | hero hanya di halaman depan (`<front>`); lepas untuk semua halaman |
| `hero_hide_page_title` | sembunyikan blok *Page title* di halaman depan (hero sudah membawa `<h1>`) |
| `hero_eyebrow`, `hero_title`, `hero_title_accent`, `hero_lead` | teks utama hero |
| `hero_cta_label`, `hero_cta_url` | tombol utama (label dikosongkan = tombol disembunyikan) |
| `hero_secondary_label`, `hero_secondary_url` | tombol kedua |
| `hero_features` | daftar keunggulan: `Label\|ikon` per baris; ikon yang tersedia `shield`, `truck`, `paw` (map `hero_icons` di `templates/hero.html.twig`) |
| `hero_note`, `hero_badge` | dekorasi sisi kanan |
| `hero_image` | path internal (mis. `/sites/default/files/hero.jpg`) atau URL penuh foto pengganti |

### Foto hero & cara membuat ulang

`images/hero-boneka.webp` (1276×734, ~127 KB) dibuat dari `images/hero-boneka.png`
(gambar asli: `gambar/background-hero.png`). Saat dibuat, bagian kanan atas
(papan kecil di dinding) dan dinding kosong di kiri dipotong supaya beruang
mengisi frame hero:

```bash
docker compose exec drupal php scripts/theme-hero-image.php
# sumber / kualitas lain (PNG, WebP):
docker compose exec drupal php scripts/theme-hero-image.php \
  web/themes/custom/blank_theme/images/hero-boneka.png /tmp/hero.webp 82
```

Foto ditempel di kanan dengan `object-fit: cover` (tepi kirinya memudar lewat
`mask-image`) supaya menyatu dengan latar pink. Ukuran & posisinya diatur
variabel di `css/hero.css`:

| Variabel | Fungsi |
|---|---|
| `--hero-height` | tinggi minimum hero (default `clamp(420px, 37vw, 600px)`) |
| `--hero-content` | lebar kolom teks (default `38rem`) |
| `--hero-media` / `--hero-media-max` | lebar bidang foto (default `66%`, maksimum `1150px`) |

Warna hero memakai variabel di `css/base.css`: `--color-hero-bg`,
`--color-hero-tint`, `--color-hero-heading`, `--color-hero-text`, dan
`--font-script` (font "tulisan tangan" untuk `hero_note`).

### Perilaku responsif

- **≥ 1200px**: seperti desain — teks di kiri, foto + dekorasi di kanan.
- **< 1200px**: dekorasi (`hero_note`, `hero_badge`) disembunyikan agar tidak
  menutupi foto.
- **< 1024px**: teks menumpuk di atas; foto menjadi pita di bagian bawah hero
  dengan tepi atas memudar.
- **< 640px**: tombol melebar penuh dan judul diperkecil.

### Catatan saat mengubah hero

- Tidak ada teks hero yang ditulis di template — semuanya dari pengaturan theme,
  jadi perubahan teks tidak butuh `drush cr`.
- `hero_features`, `hero_note`, dan `hero_badge` diisi satu baris per
  item/baris tampilan; mengubah jumlah baris aman tanpa menyentuh CSS.
- Foto hero adalah elemen `<img>` dengan `alt=""` (murni dekorasi) dan
  `fetchpriority="high"` karena biasanya menjadi elemen LCP halaman depan.

## Titik-titik yang biasanya diubah lebih dulu

1. **Region** — deklarasi di `blank_theme.info.yml` (`regions:`), render di
   `templates/page.html.twig`. Region yang dideklarasikan tapi tidak dirender
   tidak akan pernah tampil di Block layout.
2. **CSS/JS** — letakkan berkas di `css/` / `js/`, daftarkan sebagai library di
   `blank_theme.libraries.yml`, lalu pasang global (`libraries:` di info.yml) atau
   per-template (`{{ attach_library('blank_theme/nama_library') }}`).
3. **Override template** — tambahkan di `templates/` dan pakai pola penamaan
   Drupal, contoh:
   - `node.html.twig`, `node--article.html.twig`, `node--1.html.twig`
   - `page--front.html.twig`
   - `block--system-branding-block.html.twig`
   - `field--node--title.html.twig`
   Sumber nama variabel & contoh markup: `core/modules/*/templates/` dan
   `core/themes/starterkit_theme/templates/`.
4. **Logika PHP** — tulis di `blank_theme.theme` (di-preprocess, saran template,
   form settings). Setiap perubahan `.theme`/`.info.yml` perlu `drush cr`
   (atau UI: Configuration → Development → Performance → Clear caches).

## Tips saat membangun dari nol

- Aktifkan mode debug Twig (Settings → Development, atau `services.yml`) agar
  nama saran template tampil sebagai komentar HTML:
  ```bash
  cp web/sites/default/default.services.yml web/sites/default/services.yml
  # set: twig.config -> debug: true, auto_reload: true, cache: false
  ```
  lalu `drush cr`.
- Ingin memakai theme core sebagai basis (mis. Claro/Olivero)? Set
  `base theme: olivero` di `blank_theme.info.yml`, atau pakai generator bawaan core:
  `php core/scripts/drupal generate-theme namatheme --name="Nama Theme"`.
- Referensi resmi: <https://www.drupal.org/docs/develop/theming-drupal>
