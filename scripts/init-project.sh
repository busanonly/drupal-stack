#!/bin/sh
# =====================================================================
#  Bootstrap project baru dari template drupal-stack (dijalankan di HOST).
#
#  Yang dilakukan:
#    1. membuat database/.env dan web/.env dari *.env.example, dengan nama
#       project, nama container/network/image, subnet, IP statis, port, dan
#       password + DRUPAL_HASH_SALT acak (unik per project → tidak bentrok);
#    2. membuat docker network (nama & subnet dari .env) bila belum ada.
#
#  Pemakaian:
#     sh scripts/init-project.sh <nama_project> [opsi]
#
#  Opsi:
#     --web-port N    port web di host      (default: otomatis mulai 8089)
#     --db-port N     port MariaDB di host  (default: otomatis mulai 3306)
#     --subnet CIDR   subnet network        (default: otomatis 172.23-172.31)
#     --force         timpa .env yang sudah ada
#     -h, --help      tampilkan bantuan
#
#  Contoh:
#     sh scripts/init-project.sh toko_online
#     sh scripts/init-project.sh blog --web-port 8090 --db-port 3307
# =====================================================================

set -e

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(dirname "$SCRIPT_DIR")
DB_ENV="$ROOT/database/.env"
WEB_ENV="$ROOT/web/.env"
DB_SAMPLE="$ROOT/database/.env.example"
WEB_SAMPLE="$ROOT/web/.env.example"

usage() {
  cat <<'EOF'
Pakai: sh scripts/init-project.sh <nama_project> [opsi]

Opsi:
  --web-port N    port web di host      (default: otomatis mulai 8089)
  --db-port N     port MariaDB di host  (default: otomatis mulai 3306)
  --subnet CIDR   subnet network        (default: otomatis 172.23.0.0/24 dst)
  --force         timpa .env yang sudah ada
  -h, --help      tampilkan bantuan ini

Catatan: nama project memakai huruf kecil, angka, dan '_' (diawali huruf).
Setelah init selesai jalankan `make up` lalu `make deps` (atau `make new`).
EOF
}

die() {
  echo "ERROR: $*" >&2
  exit 1
}

info() {
  printf '%s\n' "$*"
}

# Angka acak hex ($1 = jumlah byte).
rand_hex() {
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -hex "$1"
  else
    od -An -N "$1" -tx1 /dev/urandom | tr -d ' \n'
  fi
}

# Port $1 sedang listen di host?
port_used() {
  if command -v ss >/dev/null 2>&1; then
    ss -ltn 2>/dev/null | awk 'NR > 1 {print $4}' | grep -qE "[:.]$1$"
  elif command -v netstat >/dev/null 2>&1; then
    netstat -ltn 2>/dev/null | awk 'NR > 2 {print $4}' | grep -qE "[:.]$1$"
  else
    return 1
  fi
}

# Cari port bebas mulai $1.
free_port() {
  _p=$1
  _n=0
  while [ "$_n" -lt 200 ]; do
    if ! port_used "$_p"; then
      printf '%s' "$_p"
      return 0
    fi
    _p=$((_p + 1))
    _n=$((_n + 1))
  done
  return 1
}

# Daftar subnet yang sudah dipakai network docker.
used_subnets() {
  for _id in $(docker network ls -q 2>/dev/null); do
    docker network inspect "$_id" --format '{{range .IPAM.Config}}{{.Subnet}} {{end}}' 2>/dev/null || true
  done
}

# Cari subnet /24 bebas (172.23.0.0/24 … 172.31.0.0/24).
free_subnet() {
  _i=23
  while [ "$_i" -le 31 ]; do
    _cand="172.$_i.0.0/24"
    if ! used_subnets | tr ' ' '\n' | grep -qx "$_cand"; then
      printf '%s' "$_cand"
      return 0
    fi
    _i=$((_i + 1))
  done
  return 1
}

# 172.23.0.0/24 -> 172.23.0
subnet_prefix() {
  printf '%s' "$1" | sed -E 's#^([0-9]+\.[0-9]+\.[0-9]+)\.[0-9]+/[0-9]+$#\1#'
}

# Ganti nilai key $2 menjadi $3 pada file $1.
set_env_value() {
  _f=$1
  _k=$2
  _v=$3
  grep -q "^$_k=" "$_f" || die "key '$_k' tidak ditemukan di $_f"
  _esc=$(printf '%s' "$_v" | sed -e 's/[&|]/\\&/g')
  _tmp="$_f.tmp.$$"
  sed "s|^$_k=.*|$_k=$_esc|" "$_f" > "$_tmp"
  mv "$_tmp" "$_f"
}

# ---------------------------------------------------------------- argumen
NAME=""
WEB_PORT=""
DB_PORT=""
SUBNET=""
FORCE=""

while [ $# -gt 0 ]; do
  case "$1" in
    --web-port)
      [ -n "$2" ] || die "--web-port butuh nilai"
      WEB_PORT=$2
      shift 2
      ;;
    --db-port)
      [ -n "$2" ] || die "--db-port butuh nilai"
      DB_PORT=$2
      shift 2
      ;;
    --subnet)
      [ -n "$2" ] || die "--subnet butuh nilai"
      SUBNET=$2
      shift 2
      ;;
    --force)
      FORCE=1
      shift
      ;;
    -h | --help)
      usage
      exit 0
      ;;
    -*)
      die "opsi tidak dikenal: $1 (lihat --help)"
      ;;
    *)
      if [ -z "$NAME" ]; then
        NAME=$1
        shift
      else
        die "argumen berlebih: $1"
      fi
      ;;
  esac
done

if [ -z "$NAME" ]; then
  usage >&2
  exit 1
fi

printf '%s' "$NAME" | grep -Eq '^[a-z][a-z0-9_]*$' \
  || die "nama project '$NAME' tidak valid (huruf kecil, angka, '_', diawali huruf)."

[ -f "$DB_SAMPLE" ] && [ -f "$WEB_SAMPLE" ] \
  || die "file .env.example tidak ditemukan — jalankan dari repo drupal-stack."

command -v docker >/dev/null 2>&1 || die "docker tidak ditemukan di PATH."

if [ -f "$DB_ENV" ] && [ "$FORCE" != "1" ]; then
  die "$DB_ENV sudah ada. Pakai --force untuk menimpa (password DB ikut berubah!)."
fi

# --- port host ---
if [ -n "$WEB_PORT" ]; then
  if port_used "$WEB_PORT"; then
    die "port web $WEB_PORT sedang dipakai proses lain."
  fi
else
  WEB_PORT=$(free_port 8089) || die "tidak menemukan port web bebas."
fi
if [ -n "$DB_PORT" ]; then
  if port_used "$DB_PORT"; then
    die "port DB $DB_PORT sedang dipakai proses lain."
  fi
else
  DB_PORT=$(free_port 3306) || die "tidak menemukan port DB bebas."
fi

# --- network & subnet ---
NETWORK_NAME="${NAME}-network"
EXISTING_SUBNET=""
if docker network inspect "$NETWORK_NAME" >/dev/null 2>&1; then
  EXISTING_SUBNET=$(docker network inspect "$NETWORK_NAME" --format '{{range .IPAM.Config}}{{.Subnet}}{{end}}')
  info "network '$NETWORK_NAME' sudah ada (subnet $EXISTING_SUBNET) — dipakai ulang."
fi

if [ -n "$EXISTING_SUBNET" ]; then
  SUBNET="$EXISTING_SUBNET"
elif [ -n "$SUBNET" ]; then
  if used_subnets | tr ' ' '\n' | grep -qx "$SUBNET"; then
    die "subnet $SUBNET sudah dipakai network docker lain."
  fi
else
  SUBNET=$(free_subnet) || die "tidak menemukan subnet bebas (172.23-172.31)."
fi

PREFIX=$(subnet_prefix "$SUBNET")
[ -n "$PREFIX" ] || die "subnet '$SUBNET' tidak valid (contoh: 172.23.0.0/24)."
DB_IP="$PREFIX.3"
PHP_IP="$PREFIX.4"
NGINX_IP="$PREFIX.5"

# --- kredensial acak ---
ROOT_PW=$(rand_hex 12)
DB_PW=$(rand_hex 12)
SALT=$(rand_hex 32)

# --- tulis .env ---
cp "$DB_SAMPLE" "$DB_ENV"
cp "$WEB_SAMPLE" "$WEB_ENV"

set_env_value "$DB_ENV" PROJECT_NAME "$NAME"
set_env_value "$DB_ENV" COMPOSE_PROJECT_NAME "${NAME}_database"
set_env_value "$DB_ENV" CONTAINER_NAME "${NAME}_db"
set_env_value "$DB_ENV" NETWORK_NAME "$NETWORK_NAME"
set_env_value "$DB_ENV" NETWORK_SUBNET "$SUBNET"
set_env_value "$DB_ENV" MARIADB_IP "$DB_IP"
set_env_value "$DB_ENV" MARIADB_PORT "$DB_PORT"
set_env_value "$DB_ENV" MARIADB_ROOT_PASSWORD "$ROOT_PW"
set_env_value "$DB_ENV" MARIADB_PASSWORD "$DB_PW"

set_env_value "$WEB_ENV" PROJECT_NAME "$NAME"
set_env_value "$WEB_ENV" COMPOSE_PROJECT_NAME "${NAME}_web"
set_env_value "$WEB_ENV" IMAGE_NAME "${NAME}/php"
set_env_value "$WEB_ENV" DRUPAL_CONTAINER "${NAME}_php"
set_env_value "$WEB_ENV" NGINX_CONTAINER "${NAME}_nginx"
set_env_value "$WEB_ENV" NETWORK_NAME "$NETWORK_NAME"
set_env_value "$WEB_ENV" NETWORK_SUBNET "$SUBNET"
set_env_value "$WEB_ENV" DRUPAL_IP "$PHP_IP"
set_env_value "$WEB_ENV" NGINX_IP "$NGINX_IP"
set_env_value "$WEB_ENV" NGINX_PORT "$WEB_PORT"
set_env_value "$WEB_ENV" DB_HOST "${NAME}_db"
set_env_value "$WEB_ENV" DB_PASSWORD "$DB_PW"
set_env_value "$WEB_ENV" DRUPAL_HASH_SALT "$SALT"

# --- network docker ---
if docker network inspect "$NETWORK_NAME" >/dev/null 2>&1; then
  info "network '$NETWORK_NAME' siap (dipakai ulang)."
else
  docker network create --subnet "$SUBNET" "$NETWORK_NAME" >/dev/null
  info "network '$NETWORK_NAME' dibuat (subnet $SUBNET)."
fi

cat <<EOF

Selesai! Konfigurasi project '$NAME':
  database  : container ${NAME}_db     → host port $DB_PORT   (IP $DB_IP)
  drupal    : container ${NAME}_php    → IP $PHP_IP
  web/nginx : container ${NAME}_nginx  → http://<IP-server>:$WEB_PORT   (IP $NGINX_IP)
  network   : $NETWORK_NAME ($SUBNET)
  .env      : database/.env & web/.env (password & salt acak sudah diisi)

Langkah berikutnya:
  make up      # build image + nyalakan MariaDB, php-fpm, nginx
  make deps    # composer install + settings.php  (wajib untuk clone baru)

atau sekaligus:  make new NAME=$NAME
EOF
