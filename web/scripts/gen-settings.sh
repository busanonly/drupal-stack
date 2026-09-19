#!/bin/sh
# =====================================================================
#  Membuat web/sites/default/settings.php dari default.settings.php Drupal,
#  lalu menambahkan nilai dari environment container (yang diisi .env lewat
#  docker-compose.yml) sehingga kredensial database TIDAK perlu diisi ulang
#  di form installer Drupal.
#
#  Pemakaian (dari dalam container php-fpm):
#     sh scripts/gen-settings.sh                 # docroot default: web
#     FORCE=1 sh scripts/gen-settings.sh         # timpa settings.php yang ada
#     sh scripts/gen-settings.sh path/docroot    # docroot lain
#
#  Dari host:
#     cd web && make settings          # / make settings FORCE=1
# =====================================================================
set -e

DOCROOT="${1:-web}"
SETTINGS="${DOCROOT}/sites/default/settings.php"
DEFAULT="${DOCROOT}/sites/default/default.settings.php"

if [ ! -f "$DEFAULT" ]; then
  echo "ERROR: $DEFAULT tidak ditemukan (jalankan composer install dulu)." >&2
  exit 1
fi

if [ -f "$SETTINGS" ] && [ "$FORCE" != "1" ]; then
  echo "$SETTINGS sudah ada — dilewati (pakai FORCE=1 untuk menimpa)."
  exit 0
fi

cp "$DEFAULT" "$SETTINGS"

cat >> "$SETTINGS" <<'PHP'

// =====================================================================
//  Nilai dari .env container (docker compose) — ditambahkan otomatis oleh
//  scripts/gen-settings.sh. Ubah nilainya di web/.env, bukan di sini.
//  `driver` wajib ada, kalau tidak Drupal melempar DriverNotSpecifiedException.
// =====================================================================
$databases['default']['default'] = [
  'database' => getenv('DB_NAME') ?: 'drupal',
  'username' => getenv('DB_USER') ?: 'drupal',
  'password' => getenv('DB_PASSWORD') ?: '',
  'host' => getenv('DB_HOST') ?: 'mariadb_drupal',
  'port' => getenv('DB_PORT') ?: '3306',
  'driver' => 'mysql',
  'prefix' => '',
  'collation' => 'utf8mb4_unicode_ci',
];

$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT') ?: '';
$settings['config_sync_directory'] = '../config/sync';
PHP

chmod 644 "$SETTINGS" 2>/dev/null || true
echo "$SETTINGS dibuat dari .env (DB_HOST=$(getenv_echo() { echo "${DB_HOST:-mariadb_drupal}"; }; getenv_echo))."
