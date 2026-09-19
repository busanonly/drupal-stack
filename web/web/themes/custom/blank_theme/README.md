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
├── blank_theme.libraries.yml    # definisi CSS/JS (library `base`)
├── blank_theme.theme            # hook PHP (preprocess, suggestions, form settings)
├── css/base.css               # CSS dasar (variabel + layout)
└── templates/
    ├── html.html.twig         # <html>/<head>/<body>
    └── page.html.twig         # region + title + tabs + messages
```

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
