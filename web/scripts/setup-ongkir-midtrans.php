<?php

/**
 * @file
 * Setup ongkir + Midtrans untuk toko Drupal Commerce:
 *
 *   1. Menambah field "weight" (physical, gram) pada product variation type
 *      `produk` + mengisi berat dari field_berat produk. WAJIB: Commerce hanya
 *      menganggap order bisa dikirim (shippable) bila purchased entity punya
 *      field `weight` (\Drupal\commerce_shipping\ShippingOrderManager::isShippable()).
 *   2. Membuat shipping method ongkir dengan origin Kecamatan Cempaka Putih,
 *      Jakarta Pusat (317105 — kelurahan Rawasari termasuk di dalamnya) via API
 *      Cek Ongkir v2 (api.co.id).
 *   3. Membuat payment gateway Midtrans Snap (mode test/sandbox).
 *   4. Melengkapi checkout flow "default" dengan pane pengiriman + pembayaran.
 *
 * Idempotent — aman dijalankan berulang. Jalankan dari root proyek Drupal:
 *   docker compose exec --user 1000:1000 drupal vendor/bin/drush php:script scripts/setup-ongkir-midtrans.php
 */

use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_shipping\Entity\ShippingMethod;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$etm = \Drupal::entityTypeManager();
$store = reset($etm->getStorage('commerce_store')->loadMultiple());
if (!$store) {
  print "ERROR: belum ada commerce_store.\n";
  return;
}
print 'store: ' . $store->id() . ' (' . $store->getName() . ")\n\n";

// =========================================== 1) Field berat pada variasi produk.
$weight_field = 'weight';
$variation_type = 'produk';
if (!FieldStorageConfig::loadByName('commerce_product_variation', $weight_field)) {
  FieldStorageConfig::create([
    'field_name' => $weight_field,
    'entity_type' => 'commerce_product_variation',
    'type' => 'physical_measurement',
    'cardinality' => 1,
    'translatable' => FALSE,
    'settings' => ['measurement_type' => 'weight', 'unit' => 'g'],
  ])->save();
  print "field storage dibuat: commerce_product_variation.$weight_field (physical, gram)\n";
}
if (!FieldConfig::loadByName('commerce_product_variation', $variation_type, $weight_field)) {
  FieldConfig::create([
    'field_name' => $weight_field,
    'entity_type' => 'commerce_product_variation',
    'bundle' => $variation_type,
    'label' => 'Berat (gram)',
    'description' => 'Berat satuan untuk perhitungan ongkir (gram).',
    'required' => FALSE,
    'settings' => [],
  ])->save();
  print "field dibuat: $variation_type.$weight_field\n";
}
$form_display = EntityFormDisplay::load("commerce_product_variation.$variation_type.default");
if ($form_display && !$form_display->getComponent($weight_field)) {
  $form_display->setComponent($weight_field, [
    'type' => 'physical_measurement_default',
    'weight' => 20,
    'settings' => [],
  ])->save();
  print "form display variasi diperbarui (widget berat)\n";
}
$view_display = EntityViewDisplay::load("commerce_product_variation.$variation_type.default");
if ($view_display && !$view_display->getComponent($weight_field)) {
  $view_display->setComponent($weight_field, [
    'type' => 'physical_measurement_default',
    'weight' => 20,
    'label' => 'above',
    'settings' => [],
  ])->save();
  print "view display variasi diperbarui\n";
}

/**
 * Mengubah teks berat (mis. "± 150 gr") menjadi gram.
 */
$parse_grams = static function (string $raw): ?int {
  $raw = trim($raw);
  if ($raw === '' || !preg_match('/[0-9]/', $raw)) {
    return NULL;
  }
  $normalized = str_replace(',', '.', $raw);
  if (!preg_match('/([0-9]+(?:\.[0-9]+)?)/', $normalized, $matches)) {
    return NULL;
  }
  $number = (float) $matches[1];
  if (preg_match('/(kg|kilo|kilogram)/i', $normalized)) {
    return (int) round($number * 1000);
  }
  // Default Indonesia: gram.
  return (int) round($number);
};

$filled = 0;
foreach ($etm->getStorage('commerce_product_variation')->loadMultiple() as $variation) {
  if (!$variation->hasField($weight_field) || !$variation->get($weight_field)->isEmpty()) {
    continue;
  }
  $grams = NULL;
  $product = $variation->getProduct();
  if ($product && $product->hasField('field_berat')) {
    $grams = $parse_grams((string) $product->get('field_berat')->value);
  }
  $variation->set($weight_field, [
    'number' => (string) ($grams ?? 500),
    'unit' => 'g',
  ]);
  $variation->save();
  $filled++;
  print '  berat variasi ' . $variation->id() . ' (' . $variation->label() . ') = ' . ($grams ?? 500) . " g\n";
}
print "berat variasi diisi: $filled\n\n";
// ================================================= 2) Shipping method ongkir.
$origin = '317105'; // Kecamatan Cempaka Putih, Jakarta Pusat (Rawasari).
$method_name = 'Ongkir (Cempaka Putih, Jakarta Pusat)';
$method_config = [
  'origin_district_code' => $origin,
  'courier_filter' => '',
  'service_filter' => '',
  'insurance' => FALSE,
  'item_value_mode' => 'declared_value',
  'district_source' => 'profile_field',
  'district_field' => 'field_kecamatan',
  'map_key' => 'postal_code',
  'district_map' => '',
  'weight_field' => 'field_berat',
  'weight_unit' => 'auto',
  'default_weight' => '1',
  'markup_percent' => '0',
  'markup_flat' => '0',
  'max_rates' => 0,
  'rate_label_pattern' => '[courier] - [service]',
  'cache_ttl' => 300,
  'default_package_type' => 'custom_box',
  'services' => ['ongkir'],
  'workflow' => 'shipment_default',
];
$method_storage = $etm->getStorage('commerce_shipping_method');
$method = NULL;
foreach ($method_storage->loadMultiple() as $candidate) {
  $item = $candidate->get('plugin')->first();
  if ($item && $item->target_plugin_id === 'ongkir') {
    $method = $candidate;
    break;
  }
}
if ($method) {
  $changed = FALSE;
  if ($method->getName() !== $method_name) {
    $method->setName($method_name);
    $changed = TRUE;
  }
  $current = $method->get('plugin')->first()->target_plugin_configuration ?? [];
  if (($current['origin_district_code'] ?? NULL) !== $origin) {
    $method->set('plugin', [
      'target_plugin_id' => 'ongkir',
      'target_plugin_configuration' => $method_config,
    ]);
    $changed = TRUE;
  }
  if (!$method->getStoreIds()) {
    $method->setStoreIds([$store->id()]);
    $changed = TRUE;
  }
  if (!$method->isPublished()) {
    $method->setPublished();
    $changed = TRUE;
  }
  if ($changed) {
    $method->save();
  }
  print 'shipping method ongkir: id=' . $method->id() . ' name="' . $method->getName() . '" stores=' . implode(',', $method->getStoreIds()) . ($changed ? ' (diperbarui)' : ' (sudah sesuai)') . "\n";
}
else {
  $method = ShippingMethod::create([
    'name' => $method_name,
    'status' => TRUE,
    'stores' => [$store->id()],
    'plugin' => [
      'target_plugin_id' => 'ongkir',
      'target_plugin_configuration' => $method_config,
    ],
    'weight' => 0,
  ]);
  $method->save();
  print 'shipping method ongkir dibuat: id=' . $method->id() . ' name="' . $method->getName() . '" origin=' . $origin . "\n";
}

// ================================================== 3) Payment Midtrans Snap.
$gateway_id = 'midtrans';
$gateway = $etm->getStorage('commerce_payment_gateway')->load($gateway_id);
if ($gateway) {
  print 'payment gateway: id=' . $gateway->id() . ' plugin=' . $gateway->getPluginId() . ' status=' . (int) $gateway->status() . ' mode=' . $gateway->getPlugin()->getMode() . " (sudah ada)\n";
}
else {
  $gateway = PaymentGateway::create([
    'id' => $gateway_id,
    'label' => 'Midtrans (Snap)',
    'status' => TRUE,
    'weight' => 0,
    'plugin' => 'midtrans_snap',
    'configuration' => [
      'display_label' => 'Midtrans',
      'mode' => 'test',
      'payment_method_types' => ['credit_card'],
      'collect_billing_information' => TRUE,
      'ui_mode' => 'popup',
      'auto_open' => TRUE,
      'order_id_prefix' => 'MID-',
      'enabled_payments' => '',
      'expiry_duration' => 24,
      'send_item_details' => TRUE,
      'token_ttl' => 60,
    ],
  ]);
  $gateway->save();
  print "payment gateway dibuat: midtrans (plugin=midtrans_snap, mode=test, popup snap.js)\n";
}

// ================================================= 4) Checkout flow default.
$flow_name = 'commerce_checkout.commerce_checkout_flow.default';
$config = \Drupal::configFactory()->getEditable($flow_name);
$panes = $config->get('configuration.panes') ?: [];
$desired = [
  'shipping_information' => ['require_shipping_profile' => TRUE, 'auto_recalculate' => TRUE, 'step' => 'order_information', 'weight' => 2],
  'payment_information' => ['step' => 'payment', 'weight' => 0],
  'payment_process' => ['step' => 'payment', 'weight' => 1],
  'contact_information' => ['step' => 'order_information', 'weight' => 1],
  'billing_information' => ['step' => 'order_information', 'weight' => 3],
];
$changed = FALSE;
foreach ($desired as $pane_id => $pane_config) {
  $current = $panes[$pane_id] ?? [];
  if ($current != ($pane_config + $current)) {
    $panes[$pane_id] = $pane_config + $current;
    $changed = TRUE;
  }
}
if ($changed) {
  $config->set('configuration.panes', $panes)->save();
  print "checkout flow default diperbarui (shipping_information, payment_information, payment_process)\n";
}
else {
  print "checkout flow default sudah lengkap\n";
}

\Drupal::service('router.builder')->rebuild();
drupal_flush_all_caches();
print "\ncache dibersihkan.\n";
print "--- pane flow default ---\n";
ksort($panes);
foreach ($panes as $pane_id => $pane_config) {
  print '  ' . str_pad($pane_id, 24) . ' step=' . str_pad((string) ($pane_config['step'] ?? '-'), 18) . ' weight=' . ($pane_config['weight'] ?? '-') . "\n";
}
