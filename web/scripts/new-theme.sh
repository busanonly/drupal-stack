#!/bin/sh
# =====================================================================
#  Membuat theme kosongan Drupal 11 (tanpa base theme) dari template
#  theme-template/ (berisi token __THEME__ dan __THEME_LABEL__).
#
#  Pemakaian:
#     sh new-theme.sh <machine_name> ["Label Theme"] [folder_tujuan]
#
#  Contoh:
#     # di dalam proyek Drupal (mis. dari <proyek>/scripts/):
#     sh new-theme.sh blog_theme "Blog Theme"
#     # dari mana saja, dengan folder tujuan eksplisit:
#     sh new-theme.sh blog_theme "Blog Theme" /path/proyek/web/themes/custom
#     # timpa theme yang sudah ada:
#     FORCE=1 sh new-theme.sh blog_theme
#
#  Bila folder_tujuan tidak diberikan, script mencari root proyek Drupal
#  (folder yang punya web/core/lib/Drupal.php) dari posisi script.
# =====================================================================
set -e

# Mencari root proyek Drupal dari sebuah folder (naik maksimum 6 level).
find_project_root() {
  _dir=$1
  _i=0
  while [ "$_i" -lt 6 ]; do
    if [ -f "$_dir/web/core/lib/Drupal.php" ]; then
      printf '%s' "$_dir"
      return 0
    fi
    _parent=$(dirname "$_dir")
    [ "$_parent" = "$_dir" ] && break
    _dir=$_parent
    _i=$((_i + 1))
  done
  return 1
}

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
SRC="$SCRIPT_DIR/theme-template"

NAME="$1"
LABEL="$2"
THEMES_DIR="$3"
[ -n "$LABEL" ] || LABEL=$(printf '%s' "$NAME" | tr '_' ' ')

if [ -z "$NAME" ]; then
  echo "Pakai: sh $0 <machine_name> [\"Label Theme\"] [folder_tujuan]" >&2
  echo "Contoh: sh $0 blog_theme \"Blog Theme\"" >&2
  exit 1
fi

# machine name: diawali huruf kecil, sisanya huruf kecil/angka/underscore
if ! printf '%s' "$NAME" | grep -Eq '^[a-z][a-z0-9_]*$'; then
  echo "ERROR: nama '$NAME' tidak valid — pakai huruf kecil, angka, dan '_' (diawali huruf)." >&2
  exit 1
fi

case "$NAME" in
  core | system | claro | olivero | stark | stable9 | default_admin | starterkit_theme)
    echo "ERROR: '$NAME' adalah nama theme core — pakai nama lain." >&2
    exit 1
    ;;
esac

if [ ! -d "$SRC" ]; then
  echo "ERROR: template tidak ditemukan di $SRC" >&2
  exit 1
fi

# Tentukan folder themes/custom tujuan.
if [ -z "$THEMES_DIR" ]; then
  if PROJECT_ROOT=$(find_project_root "$SCRIPT_DIR"); then
    THEMES_DIR="$PROJECT_ROOT/web/themes/custom"
  else
    echo "ERROR: folder tujuan tidak diberikan dan proyek Drupal tidak terdeteksi." >&2
    echo "Pakai: sh $0 <machine_name> [\"Label Theme\"] <folder>/web/themes/custom" >&2
    exit 1
  fi
fi

DST="$THEMES_DIR/$NAME"
if [ -e "$DST" ] && [ "$FORCE" != "1" ]; then
  echo "$DST sudah ada — dilewati (pakai FORCE=1 untuk menimpa)."
  exit 0
fi

mkdir -p "$DST"
find "$SRC" -type f | while IFS= read -r file; do
  rel=${file#"$SRC"/}
  out_rel=$(printf '%s' "$rel" | sed "s|__THEME__|$NAME|g")
  out="$DST/$out_rel"
  mkdir -p "$(dirname "$out")"
  # __THEME_LABEL__ = label tampil, __THEME__ = machine name
  sed -e "s|__THEME_LABEL__|$LABEL|g" -e "s|__THEME__|$NAME|g" "$file" > "$out"
done

echo "Theme kosongan dibuat: $DST"
echo "  machine name : $NAME"
echo "  label        : $LABEL"
echo ""
echo "Aktifkan:"
echo "  UI    : /admin/appearance  (Install and set as default)"
echo "  Drush : drush theme:enable $NAME -y && drush config:set system.theme default $NAME -y"

