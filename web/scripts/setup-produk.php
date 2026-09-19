<?php

/**
 * @file
 * Menyiapkan tipe produk Commerce "produk" untuk toko boneka.
 *
 * Skrip ini menyiapkan seluruh hal yang biasa diklik manual di UI Commerce,
 * supaya bisa diulang & didokumentasikan dalam repo (image ini tidak punya
 * drush pm/config — lihat catatan di README.md). Semua langkah idempotent:
 * aman dijalankan berkali-kali, nilai yang sudah ada akan diperbarui.
 *
 *   1. Memasang modul Commerce + modul core pendukung (image, options, text).
 *   2. Mengimpor mata uang IDR dan membuat toko default (wajib untuk keranjang).
 *   3. Membuat atribut "Ukuran" (tampil sebagai tombol radio/pill) + nilainya.
 *   4. Membuat product variation type `produk` + field "Stok tersedia".
 *   5. Membuat product type `produk` + field yang dipakai halaman produk
 *      (badge, rating, jumlah ulasan, terjual, galeri, spesifikasi, ulasan).
 *   6. Mengatur display: view `full` (halaman produk), form produk, dan form
 *      "Add to cart" (tombol ukuran + jumlah + tombol keranjang).
 *   7. Dengan --with-demo: membuat gambar galeri dari foto boneka theme dan
 *      satu produk contoh lengkap dengan 4 variasi ukuran seperti desain.
 *
 * Pakai (dari folder web/):
 *   docker compose exec drupal php scripts/setup-produk.php --with-demo
 *   docker compose exec drupal php scripts/setup-produk.php
 *
 * Hasil: halaman produk di /product/<id> (canonical Commerce), admin di
 * /admin/commerce/products. Theme yang merender: blank_theme
 * (templates/commerce-product--produk--full.html.twig).
 */

declare(strict_types=1);

use Drupal\commerce_price\Price;
use Drupal\Core\DrupalKernel;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Symfony\Component\HttpFoundation\Request;

// ---------------------------------------------------------------------
// 1. Bootstrap Drupal (pola sama dengan scripts/check-theme.php).
// ---------------------------------------------------------------------
$project = NULL;
foreach ([__DIR__, getcwd()] as $start) {
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
  fwrite(STDERR, "FATAL: root proyek Drupal tidak ditemukan dari " . __DIR__ . "\n");
  exit(2);
}

$root = $project . '/web';

// CWD wajib di docroot: sebagian core (InfoParser/ExtensionList) memakai path
// relatif terhadap direktori kerja, bukan hanya terhadap $root.
chdir($root);

$autoloader = require $project . '/vendor/autoload.php';

// SCRIPT_FILENAME diisi supaya DrupalKernel bisa menemukan sites/default
// walau skrip dijalankan dari CLI.
$request = Request::createFromGlobals();
$request->server->set('SCRIPT_FILENAME', $root . '/index.php');
$request->server->set('SCRIPT_NAME', '/index.php');
$kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod', TRUE, $root);
$kernel->boot();

// Setara request HTTP: memuat semua file modul (.module), fungsi lama seperti
// module_config_sort(), stream wrapper, dan memasang request di request_stack.
// Tanpa ini hook seperti entity_entity_bundle_field_info() tidak terdefinisi
// sehingga ModuleInstaller/Views gagal.
$kernel->preHandle($request);

$demo = in_array('--with-demo', $argv, TRUE);

/**
 * Judul langkah.
 */
function langkah(string $pesan): void {
  echo "\n=== $pesan\n";
}

/**
 * Baris hasil.
 */
function hasil(string $pesan): void {
  echo "  - $pesan\n";
}


/**
 * Simpan (buat/perbarui) field storage + field instance. Idempotent.
 *
 * @param array $storage_values
 *   Nilai FieldStorageConfig (wajib: field_name, entity_type, type).
 * @param array $field_values
 *   Nilai FieldConfig (wajib: bundle; label, required, settings, dst).
 */
function produk_field(array $storage_values, array $field_values): void {
  $skip = [
    'id', 'uuid', 'langcode', 'status', 'dependencies',
    'field_name', 'entity_type', 'bundle', 'field_storage',
  ];
  $storage = FieldStorageConfig::loadByName($storage_values['entity_type'], $storage_values['field_name']);
  if (!$storage) {
    $storage = FieldStorageConfig::create($storage_values);
  }
  else {
    foreach ($storage_values as $key => $value) {
      if (!in_array($key, $skip, TRUE)) {
        $storage->set($key, $value);
      }
    }
  }
  $storage->save();

  $field_values['field_storage'] = $storage;
  $field = FieldConfig::loadByName($storage_values['entity_type'], $field_values['bundle'], $storage_values['field_name']);
  if (!$field) {
    $field = FieldConfig::create($field_values);
  }
  else {
    foreach ($field_values as $key => $value) {
      if (!in_array($key, $skip, TRUE)) {
        $field->set($key, $value);
      }
    }
  }
  $field->save();
}

/**
 * Ambil entity display (view/form) untuk bundle + mode tertentu.
 *
 * commerce_get_entity_display() hanya menangani mode 'default', sedangkan
 * halaman produk memakai view mode 'full'.
 */
function produk_display(string $entity_type, string $bundle, string $context, string $mode = 'default') {
  $storage = \Drupal::entityTypeManager()->getStorage('entity_' . $context . '_display');
  $id = implode('.', [$entity_type, $bundle, $mode]);
  $display = $storage->load($id);
  if (!$display) {
    $display = $storage->create([
      'targetEntityType' => $entity_type,
      'bundle' => $bundle,
      'mode' => $mode,
      'status' => TRUE,
    ]);
  }
  return $display;
}

// ---------------------------------------------------------------------
// 2. Modul Commerce.
// ---------------------------------------------------------------------
langkah('Modul Commerce');
$modul = [
  'commerce',
  'commerce_product',
  'commerce_price',
  'commerce_store',
  'commerce_order',
  'commerce_cart',
  'commerce_checkout',
  'commerce_payment',
  'commerce_log',
  'commerce_number_pattern',
  'image',
  'options',
  'text',
];
$belum = array_values(array_filter($modul, fn(string $m): bool => !\Drupal::moduleHandler()->moduleExists($m)));
if ($belum) {
  \Drupal::service('module_installer')->install($belum, TRUE);
  // Container perlu di-refresh agar entity type dari modul baru dikenal.
  \Drupal::service('kernel')->rebuildContainer();
  hasil('dipasang: ' . implode(', ', $belum));
}
else {
  hasil('semua modul sudah terpasang');
}

// Commerce menaruh sebagian config di folder config/optional (mis. order item
// type "default" + form "Add to cart") yang hanya dipasang bila dependensinya
// sudah ada. Karena modul dipasang serentak, sebagian terlewat → pasang di sini.
\Drupal::service('config.installer')->installOptionalConfig();
hasil('config optional Commerce diperiksa/dipasang');

// Pemasangan modul Commerce pernah gagal di tengah pada instalasi ini (cache
// container belum konsisten), sehingga sebagian config bawaan modul tidak
// pernah terpasang — mis. commerce_order_type "default" yang dibutuhkan
// keranjang. Pasang ulang config default modul-modul Commerce; langkah
// berikutnya (field & display) dijalankan setelah ini agar tidak tertimpa.
$modul_config = [
  'commerce',
  'commerce_product',
  'commerce_price',
  'commerce_store',
  'commerce_order',
  'commerce_cart',
  'commerce_checkout',
  'commerce_payment',
  'commerce_number_pattern',
  'commerce_log',
  'profile',
];
foreach ($modul_config as $nama_modul) {
  \Drupal::service('config.installer')->installDefaultConfig('module', $nama_modul);
}
hasil('config default modul dipasang ulang: ' . implode(', ', $modul_config));

// ---------------------------------------------------------------------
// 3. Mata uang IDR + toko default.
// ---------------------------------------------------------------------
langkah('Mata uang & toko');
$currency_storage = \Drupal::entityTypeManager()->getStorage('commerce_currency');
if (!$currency_storage->load('IDR')) {
  \Drupal::service('commerce_price.currency_importer')->import('IDR');
  hasil('mata uang IDR diimpor');
}
else {
  hasil('mata uang IDR sudah ada');
}

// Simbol rupiah dipakai halaman produk & keranjang ("Rp 65.000"); importer
// Commerce mengisi simbol IDR dengan kode mata uang sehingga tampil "IDR65.000".
$idr = $currency_storage->load('IDR');
if ($idr && trim((string) $idr->symbol) !== 'Rp') {
  $idr->setSymbol('Rp');
  $idr->save();
  hasil('simbol mata uang IDR diatur ke "Rp"');
}

$store_storage = \Drupal::entityTypeManager()->getStorage('commerce_store');
$stores = array_values($store_storage->loadMultiple());
// Toko terkecil dipakai sebagai toko utama (stabil walau skrip diulang).
usort($stores, fn($a, $b): int => $a->id() <=> $b->id());
$store = $stores ? reset($stores) : NULL;
if ($store && !$store->isDefault()) {
  $store->setDefault(TRUE);
  $store->save();
}
if (!$store) {
  $store = $store_storage->create([
    'type' => 'online',
    'name' => 'Toko Boneka',
    'mail' => 'toko@example.com',
    'default_currency' => 'IDR',
    'billing_countries' => ['ID'],
    'is_default' => TRUE,
    'address' => [
      'country_code' => 'ID',
      'administrative_area' => 'JK',
      'locality' => 'Jakarta',
      'postal_code' => '10110',
      'address_line1' => 'Jl. Contoh No. 1',
      'given_name' => 'Admin',
      'family_name' => 'Toko',
    ],
  ]);
  $store->save();
  hasil('toko default dibuat: ' . $store->label());
  $stores = [$store];
}
else {
  hasil('toko dipakai: ' . $store->label() . ' (' . $store->id() . ')');
}

// Bersihkan toko duplikat (mis. sisa skrip yang sempat gagal di tengah jalan)
// selama belum ada produk yang memakainya.
foreach (array_slice($stores, 1) as $extra) {
  $dipakai = \Drupal::entityQuery('commerce_product')
    ->condition('stores', $extra->id())
    ->accessCheck(FALSE)
    ->count()
    ->execute();
  if ((int) $dipakai === 0) {
    $extra->delete();
    hasil('toko duplikat dihapus: ' . $extra->label() . ' (' . $extra->id() . ')');
  }
}

// ---------------------------------------------------------------------
// 4. Atribut "Ukuran" + nilainya.
// ---------------------------------------------------------------------
langkah('Atribut ukuran');
$attribute_storage = \Drupal::entityTypeManager()->getStorage('commerce_product_attribute');
$attribute = $attribute_storage->load('ukuran');
if (!$attribute) {
  $attribute = $attribute_storage->create([
    'id' => 'ukuran',
    'label' => 'Ukuran',
    // radios = pilihan bulat; di theme (css/produk.css) diubah jadi pill.
    'elementType' => 'radios',
  ]);
  $attribute->save();
}
hasil('atribut: ' . $attribute->label() . ' (' . $attribute->getElementType() . ')');

$value_storage = \Drupal::entityTypeManager()->getStorage('commerce_product_attribute_value');
$ukuran = ['20 cm' => 0, '30 cm' => 1, '40 cm' => 2, '60 cm' => 3];
$nilai_ukuran = [];
foreach ($ukuran as $nama => $weight) {
  $ada = $value_storage->loadByProperties(['attribute' => 'ukuran', 'name' => $nama]);
  $value = $ada ? reset($ada) : $value_storage->create([
    'attribute' => 'ukuran',
    'name' => $nama,
  ]);
  $value->set('weight', $weight);
  $value->save();
  $nilai_ukuran[$nama] = $value;
}
hasil('nilai ukuran: ' . implode(', ', array_keys($nilai_ukuran)));

// ---------------------------------------------------------------------
// 5. Product variation type `produk` + field stok.
// ---------------------------------------------------------------------
langkah('Variation type produk');
$vt_storage = \Drupal::entityTypeManager()->getStorage('commerce_product_variation_type');
if (!$vt_storage->load('produk')) {
  $vt_storage->create([
    'id' => 'produk',
    'label' => 'Produk',
    // Order item type bawaan Commerce (harga, keranjang, checkout).
    'orderItemType' => 'default',
    // Judul variasi dibuat otomatis dari judul produk + nilai atribut.
    'generateTitle' => TRUE,
  ])->save();
  hasil('variation type dibuat');
}
else {
  hasil('variation type sudah ada');
}

// Field atribut pada variation type (dibuat Commerce; aman dipanggil ulang).
\Drupal::service('commerce_product.attribute_field_manager')->createField($attribute, 'produk');
hasil('field attribute_ukuran disiapkan');

// Stok tersedia per variasi (Commerce tidak punya field stok bawaan).
produk_field([
  'field_name' => 'field_stok',
  'entity_type' => 'commerce_product_variation',
  'type' => 'integer',
  'cardinality' => 1,
], [
  'bundle' => 'produk',
  'label' => 'Stok tersedia',
  'description' => 'Dipakai halaman produk untuk tulisan "Stok tersedia: N pcs".',
  'required' => FALSE,
]);

$vt_form = produk_display('commerce_product_variation', 'produk', 'form');
$vt_form->setComponent('field_stok', ['type' => 'number', 'weight' => 5]);
$vt_form->save();

$vt_view = produk_display('commerce_product_variation', 'produk', 'view');
$vt_view->setComponent('field_stok', [
  'type' => 'number_integer',
  'label' => 'inline',
  'weight' => 5,
]);
$vt_view->save();
hasil('field_stok + display variasi diatur');



// ---------------------------------------------------------------------
// 6. Product type `produk` + field-field halaman produk.
// ---------------------------------------------------------------------
langkah('Product type produk');
$pt_storage = \Drupal::entityTypeManager()->getStorage('commerce_product_type');
if (!$pt_storage->load('produk')) {
  $pt_storage->create([
    'id' => 'produk',
    'label' => 'Produk',
    'description' => 'Produk fisik toko boneka. Halaman produk dirender templates/commerce-product--produk--full.html.twig.',
    'variationTypes' => ['produk'],
    'multipleVariations' => TRUE,
    'injectVariationFields' => TRUE,
  ])->save();
  hasil('product type dibuat');
}
else {
  hasil('product type sudah ada');
}

// body: summary dipakai sebagai deskripsi singkat di bawah judul produk.
produk_field([
  'field_name' => 'body',
  'entity_type' => 'commerce_product',
  'type' => 'text_with_summary',
], [
  'bundle' => 'produk',
  'label' => 'Deskripsi produk',
  'required' => FALSE,
  'settings' => [
    'display_summary' => TRUE,
    'required_summary' => FALSE,
  ],
]);

// Field tambahan. Label field dipakai apa adanya sebagai baris tabel
// "Spesifikasi", jadi labelnya sengaja pendek.
$field_produk = [
  'field_badge' => [
    'type' => 'list_string',
    'label' => 'Badge',
    'storage' => [
      'settings' => [
        'allowed_values' => [
          'Best Seller' => 'Best Seller',
          'Terlaris' => 'Terlaris',
          'Baru' => 'Baru',
          'Promo' => 'Promo',
        ],
      ],
    ],
  ],
  'field_rating' => [
    'type' => 'decimal',
    'label' => 'Rating',
    'storage' => ['settings' => ['precision' => 3, 'scale' => 1]],
  ],
  'field_jumlah_ulasan' => [
    'type' => 'integer',
    'label' => 'Jumlah ulasan',
  ],
  'field_terjual' => [
    'type' => 'integer',
    'label' => 'Terjual',
  ],
  'field_galeri' => [
    'type' => 'image',
    'label' => 'Galeri foto',
    'cardinality' => -1,
    'storage' => [
      'settings' => [
        'file_directory' => 'produk',
        'file_extensions' => 'png gif jpg jpeg webp',
        'alt_field' => TRUE,
        'alt_field_required' => FALSE,
        'title_field' => FALSE,
      ],
    ],
  ],
  'field_keunggulan' => [
    'type' => 'string',
    'label' => 'Poin unggulan',
    'cardinality' => -1,
    'storage' => ['settings' => ['max_length' => 255]],
  ],
  'field_ulasan' => [
    'type' => 'text_long',
    'label' => 'Ulasan pembeli',
    'cardinality' => -1,
    'description' => 'Satu item = satu ulasan (teks bebas, mis. "Rina - lembut banget").',
  ],
  'field_bahan' => [
    'type' => 'string',
    'label' => 'Bahan',
    'storage' => ['settings' => ['max_length' => 255]],
  ],
  'field_warna' => [
    'type' => 'string',
    'label' => 'Warna',
    'storage' => ['settings' => ['max_length' => 255]],
  ],
  'field_berat' => [
    'type' => 'string',
    'label' => 'Berat',
    'storage' => ['settings' => ['max_length' => 255]],
  ],
  'field_perawatan' => [
    'type' => 'string',
    'label' => 'Perawatan',
    'storage' => ['settings' => ['max_length' => 255]],
  ],
  'field_custom' => [
    'type' => 'string',
    'label' => 'Custom',
    'storage' => ['settings' => ['max_length' => 255]],
  ],
  'field_minimum_order' => [
    'type' => 'integer',
    'label' => 'Minimum order',
  ],
];

foreach ($field_produk as $name => $def) {
  produk_field(
    [
      'field_name' => $name,
      'entity_type' => 'commerce_product',
      'type' => $def['type'],
      'cardinality' => $def['cardinality'] ?? 1,
    ] + ($def['storage'] ?? []),
    [
      'bundle' => 'produk',
      'label' => $def['label'],
      'description' => $def['description'] ?? '',
      'required' => FALSE,
    ]
  );
}
hasil('field produk disiapkan: ' . implode(', ', array_keys($field_produk)));


// ---------------------------------------------------------------------
// 7. Display: halaman produk (view mode `full`), form produk, add to cart.
// ---------------------------------------------------------------------
langkah('Display produk & form add to cart');

// View mode `full` = halaman produk (canonical Commerce memakai 'full').
// Config entity view mode ini wajib ada; sebagian instalasi belum punya.
$mode_storage = \Drupal::entityTypeManager()->getStorage('entity_view_mode');
if (!$mode_storage->load('commerce_product.full')) {
  $mode_storage->create([
    'id' => 'commerce_product.full',
    'label' => 'Full',
    'targetEntityType' => 'commerce_product',
    'status' => TRUE,
  ])->save();
  hasil('view mode commerce_product.full dibuat');
}

// Komponen di view display ini yang tersedia sebagai content.* di
// templates/commerce-product--produk--full.html.twig.
$view = produk_display('commerce_product', 'produk', 'view', 'full');
$view->setComponent('title', [
  'type' => 'string',
  'label' => 'hidden',
  'weight' => -20,
  'settings' => ['link_to_entity' => FALSE],
]);
$view->setComponent('field_badge', [
  'type' => 'list_default',
  'label' => 'hidden',
  'weight' => -19,
]);
$view->setComponent('field_rating', [
  'type' => 'number_decimal',
  'label' => 'hidden',
  'weight' => -18,
]);
$view->setComponent('field_jumlah_ulasan', [
  'type' => 'number_integer',
  'label' => 'hidden',
  'weight' => -17,
]);
$view->setComponent('field_terjual', [
  'type' => 'number_integer',
  'label' => 'hidden',
  'weight' => -16,
]);
$view->setComponent('field_galeri', [
  'type' => 'image',
  'label' => 'hidden',
  'weight' => -15,
  'settings' => ['image_style' => '', 'image_link' => ''],
]);
$view->setComponent('body', [
  'type' => 'text_default',
  'label' => 'hidden',
  'weight' => 20,
]);
$view->setComponent('variations', [
  'type' => 'commerce_add_to_cart',
  'label' => 'hidden',
  'weight' => 30,
  'settings' => ['combine' => TRUE],
]);
$view->save();
hasil('view commerce_product.produk.full diatur');

// Form produk di admin: widget untuk setiap field tambahan.
$form = produk_display('commerce_product', 'produk', 'form');
$widget = [
  'field_badge' => ['type' => 'options_select'],
  'field_rating' => ['type' => 'number'],
  'field_jumlah_ulasan' => ['type' => 'number'],
  'field_terjual' => ['type' => 'number'],
  'field_galeri' => ['type' => 'image_image'],
  'field_keunggulan' => ['type' => 'string_textfield'],
  'field_ulasan' => ['type' => 'text_textarea'],
  'field_bahan' => ['type' => 'string_textfield'],
  'field_warna' => ['type' => 'string_textfield'],
  'field_berat' => ['type' => 'string_textfield'],
  'field_perawatan' => ['type' => 'string_textfield'],
  'field_custom' => ['type' => 'string_textfield'],
  'field_minimum_order' => ['type' => 'number'],
];
$weight = 10;
foreach ($widget as $name => $component) {
  $form->setComponent($name, $component + ['weight' => $weight]);
  $weight++;
}
$form->save();
hasil('form produk diatur (' . count($widget) . ' field tambahan)');

// Form "Add to cart": tombol ukuran (widget atribut Commerce) + field jumlah.
// Form mode & display-nya adalah config *optional* di Commerce; pada instalasi
// ini belum ada sehingga dibuat di sini.
$form_mode_storage = \Drupal::entityTypeManager()->getStorage('entity_form_mode');
if (!$form_mode_storage->load('commerce_order_item.add_to_cart')) {
  $form_mode_storage->create([
    'id' => 'commerce_order_item.add_to_cart',
    'label' => 'Add to cart',
    'targetEntityType' => 'commerce_order_item',
    'status' => TRUE,
  ])->save();
  hasil('form mode commerce_order_item.add_to_cart dibuat');
}

$atc_storage = \Drupal::entityTypeManager()->getStorage('entity_form_display');
$atc = $atc_storage->load('commerce_order_item.default.add_to_cart');
if (!$atc) {
  $atc = $atc_storage->create([
    'targetEntityType' => 'commerce_order_item',
    'bundle' => 'default',
    'mode' => 'add_to_cart',
    'status' => TRUE,
  ]);
}
$atc->setComponent('purchased_entity', [
  'type' => 'commerce_product_variation_attributes',
  'weight' => 0,
]);
$atc->setComponent('quantity', [
  'type' => 'number',
  'weight' => 10,
  'settings' => ['placeholder' => ''],
]);
$atc->save();
hasil('form add_to_cart: tombol ukuran + field Jumlah (quantity) diatur');

// ---------------------------------------------------------------------
// 8. Permission storefront (pengunjung anonim & user login).
// ---------------------------------------------------------------------
langkah('Permission pengunjung');
$permission = [
  // Melihat produk yang sudah dipublikasikan (permission per-bundle dari
  // modul contrib `entity`; nama generic-nya: "view commerce_product").
  'view produk commerce_product',
  // Bisa masuk ke halaman checkout (keranjang).
  'access checkout',
];
foreach (['anonymous', 'authenticated'] as $rid) {
  $role = \Drupal::entityTypeManager()->getStorage('user_role')->load($rid);
  if (!$role) {
    continue;
  }
  user_role_grant_permissions($rid, $permission);
  hasil('role ' . $role->label() . ': ' . implode(', ', $permission));
}
// User login boleh melihat pesanannya sendiri (halaman /user/orders).
user_role_grant_permissions('authenticated', ['view own commerce_order']);

// ---------------------------------------------------------------------
// 9. Konten contoh (--with-demo).
// ---------------------------------------------------------------------
if (!$demo) {
  langkah('Selesai');
  hasil('konten contoh dilewati (jalankan dengan --with-demo untuk membuatnya)');
  exit(0);
}

/**
 * Potongan gambar untuk galeri produk, dibuat dari foto boneka theme.
 *
 * Sumber: images/hero-boneka.png di theme blank_theme (foto yang sama dengan
 * hero). Koordinat [x, y, lebar, tinggi] diukur untuk foto itu; ganti bila
 * memakai foto lain.
 *
 * @return array
 *   File entity dengan nama file sebagai key, siap dipakai field_galeri.
 */
function produk_gambar_galeri(): array {
  $theme_path = \Drupal::service('extension.list.theme')->getPath('blank_theme');
  $sumber = DRUPAL_ROOT . '/' . $theme_path . '/images/hero-boneka.png';
  if (!is_file($sumber) || !function_exists('imagecreatefrompng')) {
    hasil('PERINGATAN: ' . $sumber . ' tidak bisa dibaca (galeri dilewati)');
    return [];
  }

  // Potongan utama (boneka utuh) + 3 detail seperti desain.
  $potongan = [
    'boneka-1.jpg' => [600, 180, 820, 760],
    'boneka-2.jpg' => [860, 230, 560, 560],
    'boneka-3.jpg' => [700, 520, 480, 480],
    'boneka-4.jpg' => [600, 640, 560, 384],
  ];

  $image = imagecreatefrompng($sumber);
  imagepalettetotruecolor($image);

  $file_system = \Drupal::service('file_system');
  // prepareDirectory() menerima argumen by reference → simpan di variabel dulu.
  $direktori = 'public://produk';
  $file_system->prepareDirectory(
    $direktori,
    FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
  );

  $files = [];
  foreach ($potongan as $nama => [$x, $y, $w, $h]) {
    $crop = imagecreatetruecolor($w, $h);
    imagecopy($crop, $image, 0, 0, $x, $y, $w, $h);
    $tmp = $file_system->tempnam('temporary://', 'produk');
    imagejpeg($crop, $tmp, 88);
    $data = (string) file_get_contents($tmp);
    @unlink($tmp);
    imagedestroy($crop);

    // writeData() menyimpan file SEKALIGUS membuat entity file-nya
    // (FileSystem::saveData() hanya menulis file di disk tanpa entity).
    $file = \Drupal::service('file.repository')->writeData($data, 'public://produk/' . $nama, FileExists::Replace);
    if ($file) {
      $files[$nama] = $file;
    }
  }
  imagedestroy($image);

  hasil('gambar galeri dibuat: ' . implode(', ', array_keys($files)));
  return $files;
}

// --- Produk contoh ---------------------------------------------------
langkah('Produk contoh');
$product_storage = \Drupal::entityTypeManager()->getStorage('commerce_product');
$judul = 'Boneka Beruang Cokelat Pita';
$ada_produk = $product_storage->loadByProperties(['type' => 'produk', 'title' => $judul]);
$product = $ada_produk ? reset($ada_produk) : $product_storage->create([
  'type' => 'produk',
  'title' => $judul,
]);

$variation_storage = \Drupal::entityTypeManager()->getStorage('commerce_product_variation');
// nama ukuran => [harga, harga coret, stok, SKU].
$variasi = [
  '20 cm' => ['65000', '85000', 500, 'BB-CKL-20'],
  '30 cm' => ['95000', '125000', 300, 'BB-CKL-30'],
  '40 cm' => ['135000', '175000', 150, 'BB-CKL-40'],
  '60 cm' => ['195000', '245000', 60, 'BB-CKL-60'],
];
$daftar_variasi = [];
foreach ($variasi as $nama => [$harga, $coret, $stok, $sku]) {
  $ada_variasi = $variation_storage->loadByProperties(['type' => 'produk', 'sku' => $sku]);
  $variation = $ada_variasi ? reset($ada_variasi) : $variation_storage->create([
    'type' => 'produk',
    'sku' => $sku,
  ]);
  $variation->set('attribute_ukuran', $nilai_ukuran[$nama]->id());
  $variation->set('price', new Price($harga, 'IDR'));
  // list_price = harga coret; selisihnya dihitung theme jadi badge "Hemat N%".
  $variation->set('list_price', new Price($coret, 'IDR'));
  $variation->set('field_stok', $stok);
  $variation->set('status', TRUE);
  $variation->save();
  $daftar_variasi[] = $variation;
  hasil(sprintf('variasi %s: Rp %s (coret Rp %s), stok %d', $nama, number_format((float) $harga, 0, ',', '.'), number_format((float) $coret, 0, ',', '.'), $stok));
}

$product->setVariations($daftar_variasi);
$product->setStores([$store->id()]);

$files = produk_gambar_galeri();
if ($files) {
  $product->set('field_galeri', array_map(
    fn($file): array => ['target_id' => $file->id(), 'alt' => $judul],
    array_values($files)
  ));
}

$product->set('body', [
  'value' => '<p>Boneka beruang cokelat ini hadir dengan desain klasik dan pita kotak-kotak yang lucu. Terbuat dari bahan plush premium: lembut, aman, dan nyaman disentuh. Cocok untuk hadiah, dekorasi, merchandise, atau kebutuhan bisnis Anda.</p>',
  'summary' => 'Boneka beruang dengan desain klasik dan pita kotak-kotak yang lucu. Terbuat dari bahan plush premium: lembut, aman, dan nyaman disentuh. Cocok untuk hadiah, merchandise, atau kebutuhan usaha Anda.',
  'format' => 'basic_html',
]);
$product->set('field_badge', 'Best Seller');
$product->set('field_rating', '4.9');
$product->set('field_jumlah_ulasan', 128);
$product->set('field_terjual', 1200);
$product->set('field_bahan', 'Plush premium, di dalam silikon');
$product->set('field_warna', 'Cokelat (bisa custom)');
$product->set('field_berat', '± 150 gr (ukuran 30 cm)');
$product->set('field_perawatan', 'Bisa dicuci (cuci tangan disarankan)');
$product->set('field_custom', 'Tersedia (logo, pita, nama)');
$product->set('field_minimum_order', 12);
$product->set('field_keunggulan', [
  'Bahan plush premium, lembut dan aman',
  'Jahitan rapi dan kuat',
  'Tersedia dalam berbagai ukuran',
  'Bisa custom (warna pita, logo, dll)',
  'Harga khusus untuk pembelian grosir',
]);
$product->set('field_ulasan', [
  'Rina - "Bonekanya halus banget, jahitannya rapi. Sudah pesan 3 kali untuk hadiah."',
  'Dewi - "Packing aman dan sampai tepat waktu. Ukuran 40 cm paling pas untuk kado."',
]);
$product->set('status', TRUE);
$product->save();

langkah('Selesai');
hasil('produk contoh: ' . $product->label() . ' (' . count($product->getVariations()) . ' variasi ukuran)');
hasil('halaman produk: ' . $product->toUrl('canonical', ['absolute' => TRUE])->toString());
hasil('admin produk: /admin/commerce/products');


