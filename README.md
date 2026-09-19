# Drupal 11 + MariaDB di Docker

Stack Drupal 11 (PHP-FPM + nginx) dan MariaDB 10.11 yang berjalan di **dua folder
terpisah** tetapi berbagi **satu network Docker** (`<project>-network`), sehingga
container Drupal bisa mengakses database lewat `DB_HOST=<project>_db`.

Nilai di tabel berikut adalah **contoh untuk project bernama `drupal`**. Untuk
project baru, semuanya dibuat otomatis (nama container, network, subnet, IP, port,
password) — lihat bagian **0**.

| Item | Nilai (contoh: `PROJECT_NAME=drupal`) |
|---|---|
| Network | `drupal-network` (bridge, subnet `172.22.0.0/24`, dibuat manual / `external`) |
| Database | MariaDB `10.11` — container `drupal_db` — IP `172.22.0.3` — host port `3306` |
| Drupal | PHP-FPM — container `drupal_php` — IP `172.22.0.4` |
| Web server | nginx:alpine — container `drupal_nginx` — IP `172.22.0.5` — host port **8089** |
| Docroot | `/var/www/html/web` (root proyek Drupal di-mount ke `/var/www/html`) |
| Drupal core | `11.4.7` (`drupal/recommended-project`, dibuat dengan Composer) |
| PHP | `8.3-fpm-bookworm` (Drupal 11 butuh PHP >= 8.3) |
| User container | `www-data` di-remap ke uid/gid **1000** = user `www` di host |

---

## 0. Pakai sebagai template untuk project web baru

Repo ini adalah **starter/template**: satu perintah menyiapkan project baru
lengkap dengan nama container, network, subnet, IP statis, port, dan password
yang **unik** (tidak bentrok dengan stack lain di host yang sama).

```bash
# 1) ambil template (clone / "Use this template" di GitHub)
git clone https://github.com/busanonly/drupal-stack.git /home/projects/proyek2
cd /home/projects/proyek2

# 2) bootstrap: buat .env (acak) + network, lalu build/start + composer install + settings
make new NAME=proyek2

# port bisa dipaksa bila perlu:
make new NAME=proyek2 WEB_PORT=8090 DB_PORT=3307
```

Yang dihasilkan `make new` (= `init` + `up` + `deps`):

| Langkah | Isi |
|---|---|
| `init` | menjalankan `scripts/init-project.sh` → `database/.env` + `web/.env` terisi `PROJECT_NAME`, `<project>_db`/`<project>_php`/`<project>_nginx`, `<project>-network`, subnet bebas (172.23+), IP `.3/.4/.5`, port bebas (8089+/3306+), password & salt acak; lalu network docker dibuat |
| `up` | build image `proyek2/php:dev` + nyalakan MariaDB, php-fpm, nginx |
| `deps` | `composer install` (vendor + scaffold docroot) dan `make settings` (settings.php dari `.env`) |

Setelah itu buka `http://<IP-server>:<NGINX_PORT>/core/install.php` dan jalankan
installer (language → Standard → admin).

> Butuh `docker`, `openssl` (opsional), dan `ss`/`netstat` di host untuk
> auto-deteksi port bebas. Kalau script dijalankan sebagai root pada folder milik
> user lain, tambahkan `git config --global --add safe.directory <folder>`.

Perintah lain: `make init NAME=...` (hanya siapkan .env + network),
`make deps` (composer install + settings), `make up/down/ps/logs`.
Ingin .env manual? `cp database/.env.example database/.env` lalu sesuaikan —
tapi jalur yang disarankan adalah `make init`.


---

## 1. Struktur folder

```
drupal/
├── Makefile                     # orkestrasi: init/new + network + database + web
├── README.md
├── scripts/
│   └── init-project.sh          # bootstrap project baru (nama/port/IP/kredensial otomatis)
├── database/                    # stack database (MariaDB)
│   ├── .env                     # konfigurasi database (password, port, IP) — jangan di-commit
│   ├── .env.example             # template nilai .env
│   ├── docker-compose.yml       # service: mariadb
│   ├── Makefile                 # make up / dump / dbshell ...
│   ├── data/                    # data MariaDB (bind mount, jangan di-commit)
│   └── backup/                  # hasil `make dump`
└── web/                         # stack aplikasi (Drupal) + root proyek Composer
    ├── .env                     # konfigurasi web + kredensial DB — jangan di-commit
    ├── .env.example
    ├── Dockerfile               # php:8.3-fpm + ekstensi + composer
    ├── docker-compose.yml       # service: drupal (php-fpm) + nginx
    ├── Makefile                 # make up / shell / composer / drush / theme / settings
    ├── config/
    │   ├── php.ini              # php.ini (memory_limit, opcache, TZ)
    │   └── nginx/default.conf   # vhost Drupal
    ├── scripts/                 # tools dalam proyek Drupal:
    │   ├── gen-settings.sh      #   settings.php dari .env
    │   ├── new-theme.sh         #   generator theme kosongan
    │   ├── check-theme.php      #   validator theme
    │   └── theme-template/      #   template theme (token __THEME__)
    ├── composer.json            # drupal/recommended-project
    ├── composer.lock
    ├── vendor/                  # dependency Composer (dibuat `make deps`)
    └── web/                     # ← DOCROOT Drupal (index.php, core/, sites/, modules/)
```

> `web/` adalah root proyek Composer, sedangkan docroot ada di `web/web/`.
> nginx diarahkan ke `/var/www/html/web` dan `web/autoload.php` tetap menemukan
> `vendor/` karena **seluruh root proyek** yang di-mount ke `/var/www/html`.

---

## 2. Menjalankan (pertama kali)

```bash
cd /home/projects/drupal

# cara tercepat (disarankan): siapkan .env + network, build, start, dependency
make new NAME=drupal

# 4) buka installer Drupal
#    http://<IP-server>:8089/core/install.php
```

Bila ingin langkah terpisah:

```bash
make init NAME=drupal   # buat .env (password/salt acak) + docker network
nano database/.env      # (opsional) sesuaikan port/password
make up                 # build image + nyalakan MariaDB, php-fpm, nginx
make deps               # composer install + settings.php  (wajib untuk clone baru)
make network            # (bila .env sudah ada sendiri) buat network dari subnet .env
```

Saat installer Drupal meminta koneksi database (hanya bila `settings.php` belum
dibuat — lihat catatan di bawah), isi:

| Field | Nilai |
|---|---|
| Database name | `drupal` (`DB_NAME` di `.env`) |
| Database username | `drupal` (`DB_USER` di `.env`) |
| Database password | sesuai `DB_PASSWORD` di `web/.env` |
| Advanced options → Host | `<project>_db` — contoh `drupal_db` (`DB_HOST` di `web/.env`) |
| Port | `3306` (port internal container, **bukan** host mapping) |

> **Catatan**: `web/sites/default/settings.php` sudah dibuat dari `.env`
> (`make settings` / `make deps`), sehingga halaman pertama installer adalah
> **“Choose language”** dan langkah database dilewati (koneksi diverifikasi
> otomatis). Sisa langkah: pilih profil (Standard), isi nama situs + akun admin.

### Bila repo ini baru di-clone

Yang **tidak** ikut di-commit (memang sengaja): `.env`, `web/vendor/`,
`web/web/core/`, docroot hasil scaffold (`web/index.php`, `.htaccess`, `robots.txt`, …),
`web/sites/default/settings.php`, dan `database/data/`. Jadi setelah clone cukup:

```bash
make new NAME=nama_project   # = init + up + deps (lengkap)
```

atau kalau `.env` sudah ada: `make up` lalu `make deps`.

> Tips: `composer install` akan cepat bila cache dist sudah ada. Container sudah
> otomatis memakai `COMPOSER_CACHE_DIR=/var/www/html/.composer-cache`
> (git-ignored), jadi cukup menyalin cache dari project lama:
> `cp -r <project-lama>/web/.composer-cache web/`.

---

## 3. Perintah yang sering dipakai

Dari root `drupal/`:

| Perintah | Fungsi |
|---|---|
| `make new NAME=proyek2` | **bootstrap project baru**: init + up + deps |
| `make init NAME=proyek2` | buat `.env` (nama/port/IP/password acak) + docker network |
| `make deps` | `composer install` + `settings.php` (untuk clone/project baru) |
| `make network` | buat network (nama & subnet dari `.env`) bila belum ada |
| `make env` | buat `.env` dari `.env.example` (jalur manual) |
| `make up` | nyalakan MariaDB lalu Drupal (+build) |
| `make down` | matikan kedua stack (data DB aman) |
| `make ps` / `make health` | status & health semua container |
| `make logs` / `make logs-web` | log database / log nginx |
| `make db-check` | uji koneksi PHP Drupal → MariaDB |
| `make dump` | backup database ke `database/backup/*.sql` |
| `make shell-db` / `make shell-web` | shell container MariaDB / php-fpm |
| `make clean` | hapus container, image lokal, dan data DB |

Per-stack (lebih detail):

```bash
cd database && make help    # up, down, logs, mysql, dbshell, ping, dump, restore
cd web      && make help    # build, up, shell, composer, drush, perms, db-check
```

Contoh:

```bash
cd /home/projects/drupal/web
make composer CMD="require drush/drush"   # pasang Drush
make drush CMD="status"                   # jalankan drush
make settings                             # buat settings.php dari .env
make shell                                # masuk container php-fpm
```

---

## 4. Catatan penting

- **`.env` dipisah per folder**: `database/.env` berisi kredensial MariaDB
  (membuat database + user otomatis saat start pertama), `web/.env` berisi
  port/identitas Drupal + kredensial DB yang sama (dikirim ke container lewat
  `env_file`/`environment`). Keduanya sudah masuk `.gitignore`.
- **IP statis** agar alamat antar-container stabil walau container di-recreate.
  Slot terpakai di `drupal-network`: `.12`, `.20`, `.30` (container lain);
  `.3` (MariaDB), `.4` (php-fpm), `.5` (nginx) milik stack ini.
- **Izin file**: `www-data` di container Drupal di-remap ke uid/gid `1000`
  (= user `www` di host) sehingga installer Drupal bisa menulis
  `sites/default/settings.php` dan `sites/default/files`. Bila ada file
  terlanjur milik root: `cd web && make perms`.
- **Data MariaDB dimiliki uid `999`** (`user mysql` di dalam image) — **jangan**
  menjalankan `chown -R 1000:1000` pada folder `drupal/` karena akan membuat
  MariaDB gagal menulis (error `errno: 13 Permission denied`). Kalau terjadi,
  perbaiki dengan `cd database && make perms`.
- **Semua tool PHP/Composer berjalan di container** (host tidak perlu PHP/Composer):
  `make composer CMD="..."` (uid 1000 agar file tetap milik `www`) dan
  `make drush CMD="..."` setelah `composer require drush/drush`.
  Cache dist Composer otomatis memakai `web/.composer-cache/` (git-ignored) —
  container menyetel `COMPOSER_CACHE_DIR` — sehingga `composer install` ulang dan
  bootstrap clone baru jauh lebih cepat (tidak mengunduh dist dari GitHub lagi).
- **`settings.php`**: `make settings` (di `web/`) membuat
  `web/sites/default/settings.php` dari `default.settings.php` lalu menambahkan
  blok yang membaca `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_HOST`, `DB_PORT`,
  dan `DRUPAL_HASH_SALT` dari environment container (isi `web/.env`) — jadi
  kredensial database tidak perlu diisi ulang di form installer. Pakai
  `make settings FORCE=1` untuk membuat ulang. Tanpa langkah ini pun installer
  Drupal tetap bisa mengisi database secara manual.
- **Password default** (`root_password_anda`, `drupal_password_anda`) hanya untuk
  lokal; ganti sebelum dipakai di server publik.
- Port host `3306` (DB) dan `8089` (web) bisa diubah di `.env` masing-masing
  folder bila bentrok dengan service lain di host.

---

## 5. Membuat theme kosongan (build dari 0)

Template theme kosongan tersedia di `web/scripts/theme-template/`; semua nama
(machine name & label) disubstitusi otomatis. Hasilnya theme Drupal 11 **tanpa
base theme** — benar-benar kosong, siap dibangun dari nol.

> Repo mandiri untuk template ini (theme siap pakai + generator + validator):
> **https://github.com/busanonly/drupal-blank-theme**

```bash
cd /home/projects/drupal/web

make theme NAME=blog_theme LABEL="Blog Theme"   # buat theme
make theme-check NAME=blog_theme                # validasi (tanpa perlu situs ter-install)
```

Hasil di `web/web/themes/custom/blog_theme/`:

```
blog_theme/
├── blog_theme.info.yml        # name, type, core_version_requirement, base theme: false,
│                              # libraries + 8 region siap pakai
├── blog_theme.libraries.yml   # library `base` → css/base.css
├── blog_theme.theme           # contoh hook PHP (dikomentari, tinggal dibuka)
├── css/base.css               # variabel CSS, reset ringan, layout dasar
├── templates/html.html.twig   # html/head/body + placeholder CSS/JS Drupal
├── templates/page.html.twig   # render semua region + title/tabs/messages
└── README.md                  # panduan membangun theme
```

Aktifkan setelah situs Drupal ter-install: `/admin/appearance` →
*Install and set as default*, atau lewat Drush:

```bash
make composer CMD="require drush/drush"          # sekali saja untuk dapat drush
make drush CMD="theme:enable blog_theme -y"
make drush CMD="config:set system.theme default blog_theme -y"
make drush CMD="cr"
```

Catatan penting:

- `base theme: false` **wajib** ditulis; tanpa key itu Drupal menolak theme
  (`InfoParserException: Missing required key ("base theme")`). Nilai `false`
  berarti theme tidak mewarisi apa pun (`stable9`/`claro`/`olivero` tidak dipakai).
- Meta `charset`, `viewport`, `Generator`, favicon, dan library `system/base`
  (berisi `.visually-hidden`, `.focusable`, dll) **sudah otomatis** ditambahkan
  Drupal, jadi tidak perlu ditulis ulang di `html.html.twig`.
- `make theme-check` memeriksa: YAML valid, key wajib `info.yml`, sintaks
  struktur Twig, semua region dirender di `page.html.twig`, `php -l` pada
  `*.theme`, serta pengenalan theme oleh Drupal (`InfoParser` +
  `ExtensionDiscovery`).
- Ingin memakai theme core sebagai basis? Ubah `base theme: olivero` (mis.) di
  `info.yml`, atau pakai generator bawaan core:
  `php core/scripts/drupal generate-theme namatheme --name="Nama Theme"`.
- Setiap perubahan `*.info.yml`, `*.libraries.yml`, atau `*.theme` perlu rebuild
  cache (UI Performance, atau `drush cr`).

---

## 6. Troubleshooting

```bash
# port terpakai
ss -ltnp | grep -E '3306|8089'

# Drupal 500 / "unexpected error"
make logs-web
docker compose -f web/docker-compose.yml logs --tail=50 drupal

# "Database is not reachable"
make db-check
make health

# reset total (menghapus data database)
make clean && make up
```

