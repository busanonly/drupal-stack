<?php

namespace Drupal\commerce_ongkir;

use Drupal\commerce_shipping\Entity\ShipmentInterface;

/**
 * Menentukan kode kecamatan tujuan untuk sebuah shipment.
 */
interface DistrictResolverInterface {

  /**
   * Mencari kode kecamatan (district code) tujuan.
   *
   * Strategi ditentukan konfigurasi plugin shipping method:
   * - profile_field: field pada profile pengiriman (fallback: billing profile).
   * - order_field: field pada order.
   * - address_dependent_locality: dependent_locality pada alamat pengiriman.
   * - map: peta manual (postal code / locality / dependent_locality).
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment
   *   Shipment.
   * @param array $configuration
   *   Konfigurasi plugin shipping method.
   *
   * @return string|null
   *   Kode kecamatan 6 digit, atau NULL bila tidak ditemukan.
   */
  public function resolve(ShipmentInterface $shipment, array $configuration): ?string;

}
