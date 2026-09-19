<?php

/**
 * @file
 * Validasi theme tanpa perlu situs ter-install.
 *
 * Memeriksa: YAML valid, key wajib info.yml, sintaks struktur Twig,
 * semua region dirender di page.html.twig, serta pengenalan theme oleh
 * Drupal sendiri (InfoParser + ExtensionDiscovery).
 *
 * Pemakaian (dari dalam container):
 *     php scripts/check-theme.php namatheme
 * Dari host:
 *     cd web && make theme-check NAME=namatheme
 */

declare(strict_types=1);

use Drupal\Component\FileCache\FileCacheFactory;
use Drupal\Core\Extension\ExtensionDiscovery;
use Drupal\Core\Extension\InfoParser;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Source;

$project = NULL;
$candidates = [__DIR__, getcwd()];
foreach ($candidates as $start) {
  $dir = $start;
  for ($i = 0; $i < 8; $i++) {
    if (is_file("$dir/web/core/lib/Drupal.php") && is_file("$dir/vendor/autoload.php")) {
      $project = $dir;
      break 2;
    }
    $parent = dirname($dir);
    if ($parent === $dir) {
      break;
    }
    $dir = $parent;
  }
}
if ($project === NULL) {
  fwrite(STDERR, "FATAL: root proyek Drupal tidak ditemukan dari " . __DIR__ . " atau " . getcwd() . "\n");
  fwrite(STDERR, "Jalankan script ini dari dalam proyek Drupal (yang punya ./web dan ./vendor).\n");
  exit(2);
}
$root = $project . '/web';
require $project . '/vendor/autoload.php';

$theme = $argv[1] ?? '';
if ($theme === '') {
  fwrite(STDERR, "Pakai: php scripts/check-theme.php <machine_name>\n");
  exit(2);
}

$dir = "$root/themes/custom/$theme";
$ok = [];
$errors = [];

if (!is_dir($dir)) {
  fwrite(STDERR, "FATAL: $dir tidak ada\n");
  exit(2);
}

// 1) YAML valid?
foreach (["$theme.info.yml", "$theme.libraries.yml"] as $file) {
  if (!is_file("$dir/$file")) {
    continue;
  }
  try {
    $data = Yaml::parseFile("$dir/$file");
    $ok[] = "YAML valid: $file (" . count((array) $data) . ' key)';
  }
  catch (Throwable $e) {
    $errors[] = "YAML GAGAL $file: " . $e->getMessage();
  }
}

// 2) key wajib di info.yml
$info = Yaml::parseFile("$dir/$theme.info.yml");
foreach (['name', 'type', 'core_version_requirement'] as $key) {
  empty($info[$key])
    ? $errors[] = "info.yml: key '$key' kosong"
    : $ok[] = "info.yml $key = " . var_export($info[$key], TRUE);
}
// 'base theme' wajib ada; false = tanpa base theme (lihat ThemeExtensionList).
array_key_exists('base theme', $info)
  ? $ok[] = 'base theme = ' . var_export($info['base theme'], TRUE)
  : $errors[] = "info.yml: 'base theme' wajib dideklarasikan (pakai false untuk theme kosongan)";
$regions = array_keys($info['regions'] ?? []);
$ok[] = 'regions (' . count($regions) . '): ' . implode(', ', $regions);
$ok[] = 'libraries: ' . ($info['libraries'] ? implode(', ', $info['libraries']) : '(tidak ada)');
if (isset($info['libraries'][0]) && !file_exists("$dir/$theme.libraries.yml")) {
  $errors[] = 'info.yml memuat library tapi file ' . $theme . '.libraries.yml tidak ada';
}

// 3) sintaks struktur Twig.
//    `t`, `safe_join`, dst adalah filter/fungsi milik Drupal (bukan Twig polos),
//    didaftarkan sebagai dummy agar parsing bisa memvalidasi struktur.
$twig = new Environment(new ArrayLoader([]));
foreach ([
  't', 'safe_join', 'clean_class', 'clean_id', 'without', 'render',
  'format_date', 'placeholder', 'add_class', 'set_attribute',
] as $filter) {
  $twig->addFilter(new \Twig\TwigFilter($filter, fn($value = NULL) => $value));
}
foreach (['attach_library', 'create_attribute', 'link', 'path', 'url'] as $function) {
  $twig->addFunction(new \Twig\TwigFunction($function, fn(...$args) => NULL));
}
$twig_files = glob("$dir/templates/*.html.twig") ?: [];
if (!$twig_files) {
  $errors[] = 'tidak ada file twig di templates/';
}
sort($twig_files);
foreach ($twig_files as $file) {
  $rel = 'templates/' . basename($file);
  try {
    $twig->parse($twig->tokenize(new Source(file_get_contents($file), $rel)));
    $ok[] = "Twig valid (struktur): $rel";
  }
  catch (Throwable $e) {
    $errors[] = "Twig GAGAL $rel: " . $e->getMessage();
  }
}

// 4) semua region dirender di page.html.twig
$page = is_file("$dir/templates/page.html.twig") ? file_get_contents("$dir/templates/page.html.twig") : '';
$unrendered = array_filter($regions, fn($r) => !str_contains($page, "page.$r"));
$unrendered
  ? $errors[] = 'region tidak dirender di page.html.twig: ' . implode(', ', $unrendered)
  : $ok[] = 'semua region dirender di page.html.twig';

// 4b) lint PHP pada file <theme>.theme (bila ada)
$theme_php = "$dir/$theme.theme";
if (is_file($theme_php)) {
  exec('php -l ' . escapeshellarg($theme_php) . ' 2>&1', $lint_out, $lint_code);
  $lint_code === 0
    ? $ok[] = 'PHP lint OK: ' . $theme . '.theme'
    : $errors[] = 'PHP lint GAGAL: ' . implode(' ', $lint_out);
}

// 5) dikenali Drupal (InfoParser + ExtensionDiscovery)
FileCacheFactory::setPrefix('theme_check');
try {
  $parsed = (new InfoParser($root))->parse("$dir/$theme.info.yml");
  $ok[] = 'Drupal InfoParser OK: ' . $parsed['name']
    . ' (type: ' . $parsed['type']
    . ', core_version_requirement: ' . var_export($info['core_version_requirement'] ?? NULL, TRUE) . ')';

  $discovery = new ExtensionDiscovery($root, TRUE);
  $themes = $discovery->scan('theme');
  if (isset($themes[$theme])) {
    $ok[] = 'ExtensionDiscovery OK: ' . $themes[$theme]->getName()
      . ' | path=' . $themes[$theme]->getPath()
      . ' | base theme=' . var_export($themes[$theme]->info['base theme'] ?? '(tanpa base theme)', TRUE);
  }
  else {
    $errors[] = "ExtensionDiscovery: theme '$theme' tidak ditemukan";
  }
}
catch (Throwable $e) {
  $errors[] = 'Drupal discovery GAGAL: ' . $e->getMessage();
}

// --- laporan ---
echo "=== VALIDASI THEME '$theme' ===\n";
foreach ($ok as $line) {
  echo "  [OK]   $line\n";
}
foreach ($errors as $line) {
  echo "  [FAIL] $line\n";
}
echo $errors
  ? "\nRINGKASAN: ADA " . count($errors) . " MASALAH\n"
  : "\nRINGKASAN: SEMUA VALIDASI LULUS\n";
exit($errors ? 1 : 0);
