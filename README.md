# Drupal 11 + MariaDB di Docker

Stack Drupal 11 (PHP-FPM + nginx) dan MariaDB 10.11 yang berjalan di **dua folder
terpisah** tetapi berbagi **satu network Docker** (`drupal-network`), sehingga
container Drupal bisa mengakses database lewat `DB_HOST=mariadb_drupal`.

| Item | Nilai |
|---|---|
| Network | `drupal-network` (bridge, subnet `172.22.0.0/24`, dibuat manual / `external`) |
| Database | MariaDB `10.11` — container `mariadb_drupal` — IP `172.22.0.3` — host port `3306` |
| Drupal | PHP-FPM — container `drupal_php` — IP `172.22.0.4` |
| Web server | nginx:alpine — container `drupal_nginx` — IP `172.22.0.5` — host port **8089** |
| Docroot | `/var/www/html/web` (root proyek Drupal di-mount ke `/var/www/html`) |
| Drupal core | `11.4.7` (`drupal/recommended-project`, dibuat dengan Composer) |
| PHP | `8.3-fpm-bookworm` (Drupal 11 butuh PHP >= 8.3) |
| User container | `www-data` di-remap ke uid/gid **1000** = user `www` di host |

---

## 1. Struktur folder

```
drupal/
├── Makefile                     # orkestrasi: network + database + web
├── README.md
├── database/                    # stack database (MariaDB)
│   ├── .env                     # konfigurasi database (password, port, IP)
│   ├── .env.example
│   ├── docker-compose.yml       # service: mariadb
│   ├── Makefile                 # make up / dump / dbshell ...
│   ├── data/                    # data MariaDB (bind mount, jangan di-commit)
│   └── backup/                  # hasil `make dump`
└── web/                         # stack aplikasi (Drupal) + root proyek Composer
    ├── .env                     # konfigurasi web + kredensial DB
    ├── .env.example
    ├── Dockerfile               # php:8.3-fpm + ekstensi + composer
    ├── docker-compose.yml       # service: drupal (php-fpm) + nginx
    ├── Makefile                 # make up / shell / composer / drush ...
    ├── config/
    │   ├── php.ini              # php.ini (memory_limit, opcache, TZ)
    │   └── nginx/default.conf   # vhost Drupal
    ├── composer.json            # drupal/recommended-project
    ├── composer.lock
    ├── vendor/                  # dependency Composer
    └── web/                     # ← DOCROOT Drupal (index.php, core/, sites/, modules/)
```

> `web/` adalah root proyek Composer, sedangkan docroot ada di `web/web/`.
> nginx diarahkan ke `/var/www/html/web` dan `web/autoload.php` tetap menemukan
> `vendor/` karena **seluruh root proyek** yang di-mount ke `/var/www/html`.

---

## 2. Menjalankan (pertama kali)

```bash
cd /home/projects/drupal

# 1) buat network drupal (idempoten, aman diulang)
make network
#    atau manual:
#    docker network create --subnet 172.22.0.0/24 drupal-network

# 2) siapkan .env di masing-masing folder (kalau belum ada)
make env
nano database/.env      # ubah MARIADB_ROOT_PASSWORD & MARIADB_PASSWORD
nano web/.env           # DB_* HARUS sama dengan database/.env, ubah DRUPAL_HASH_SALT
#    salt acak: openssl rand -hex 32

# 3) nyalakan database + web
make up

# 4) buka installer Drupal
#    http://<IP-server>:8089
```

Saat installer Drupal meminta koneksi database (hanya bila `settings.php` belum
dibuat — lihat catatan di bawah), isi:

| Field | Nilai |
|---|---|
| Database name | `drupal` (`DB_NAME` di `.env`) |
| Database username | `drupal` (`DB_USER` di `.env`) |
| Database password | `drupal_password_anda` (`DB_PASSWORD` di `.env`) |
| Advanced options → Host | `mariadb_drupal` (nama container MariaDB) |
| Port | `3306` (port internal container, **bukan** host mapping) |

> **Catatan**: `web/sites/default/settings.php` sudah dibuat dari `.env`
> (`make settings`), sehingga halaman pertama installer adalah **“Choose language”**
> dan langkah database dilewati (koneksi diverifikasi otomatis). Sisa langkah:
> pilih profil (Standard), isi nama situs + akun admin, selesai.

### Bila repo ini baru di-clone

Yang **tidak** ikut di-commit (memang sengaja): `.env`, `web/vendor/`,
`web/web/core/`, docroot hasil scaffold (`web/index.php`, `.htaccess`, `robots.txt`, …),
`web/sites/default/settings.php`, dan `database/data/`. Setelah `make up`:

```bash
cd /home/projects/drupal/web
make composer CMD="install --no-interaction"   # dependency + scaffold docroot (web/)
make settings                                  # settings.php dari .env
```

Alternatif langsung di host (tanpa container): `cd web && composer install`,
lalu `sh scripts/gen-settings.sh web`.

---

## 3. Perintah yang sering dipakai

Dari root `drupal/`:

| Perintah | Fungsi |
|---|---|
| `make network` | buat network `drupal-network` bila belum ada |
| `make env` | buat `.env` di `database/` & `web/` dari `.env.example` |
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
  Cache dist Composer disimpan di `web/.composer-cache/` (git-ignored) supaya
  instalasi ulang cepat.
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

