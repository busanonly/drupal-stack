<?php

namespace Drupal\commerce_ongkir;

use Drupal\address\AddressInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\profile\Entity\ProfileInterface;

/**
 * Implementasi default DistrictResolverInterface.
 */
class DistrictResolver implements DistrictResolverInterface {

  /**
   * Constructs a new DistrictResolver object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(ShipmentInterface $shipment, array $configuration): ?string {
    $source = (string) ($configuration['district_source'] ?? 'profile_field');
    $field_name = (string) ($configuration['district_field'] ?? '');
    $code = NULL;

    switch ($source) {
      case 'order_field':
        $code = $this->readFieldValue($shipment->getOrder(), $field_name);
        break;

      case 'address_dependent_locality':
        $code = $this->readAddressValue($shipment, 'dependent_locality');
        break;

      case 'map':
        $code = $this->resolveFromMap($shipment, $configuration);
        break;

      case 'profile_field':
      default:
        $code = $this->readFieldValue($shipment->getShippingProfile(), $field_name);
        if ($code === NULL) {
          $order = $shipment->getOrder();
          $code = $order instanceof OrderInterface
            ? $this->readFieldValue($order->getBillingProfile(), $field_name)
            : NULL;
        }
        break;
    }

    $normalized = self::normalizeCode($code);
    // Fallback 1: peta manual yang dikonfigurasi.
    if ($normalized === NULL && $source !== 'map') {
      $normalized = $this->resolveFromMap($shipment, $configuration);
    }
    // Fallback 2: dependent_locality pada alamat (bila berisi kode).
    if ($normalized === NULL) {
      $normalized = self::normalizeCode($this->readAddressValue($shipment, 'dependent_locality'));
    }

    return $normalized;
  }

  /**
   * Menormalkan kode kecamatan menjadi 6 digit.
   *
   * Kode desa (10 digit, BPS) diterima juga: 6 digit pertamanya adalah kode
   * kecamatan.
   *
   * @param mixed $value
   *   Nilai mentah.
   *
   * @return string|null
   *   Kode 6 digit, atau NULL bila tidak valid.
   */
  public static function normalizeCode($value): ?string {
    if (!is_string($value) && !is_numeric($value)) {
      return NULL;
    }
    $value = trim((string) $value);
    if ($value === '' || !ctype_digit($value) || strlen($value) < 6) {
      return NULL;
    }
    return substr($value, 0, 6);
  }

  /**
   * Membaca nilai field dari sebuah entity.
   *
   * @param mixed $entity
   *   Entity (boleh NULL).
   * @param string $field_name
   *   Nama field.
   *
   * @return string|null
   *   Nilai field, atau NULL.
   */
  protected function readFieldValue($entity, string $field_name): ?string {
    if (!$entity instanceof EntityInterface || $field_name === '' || !$entity->hasField($field_name)) {
      return NULL;
    }
    $items = $entity->get($field_name);
    if ($items->isEmpty()) {
      return NULL;
    }
    $item = $items->first();
    if ($item === NULL) {
      return NULL;
    }
    if (isset($item->value)) {
      return (string) $item->value;
    }
    if (isset($item->target_id)) {
      return (string) $item->target_id;
    }
    return NULL;
  }

  /**
   * Membaca properti alamat pengiriman (fallback: alamat billing).
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment
   *   Shipment.
   * @param string $property
   *   Properti alamat: postal_code, locality, dependent_locality,
   *   administrative_area, atau country_code.
   *
   * @return string|null
   *   Nilai properti, atau NULL.
   */
  protected function readAddressValue(ShipmentInterface $shipment, string $property): ?string {
    $address = $this->getAddress($shipment->getShippingProfile());
    if ($address === NULL) {
      $order = $shipment->getOrder();
      $address = $order instanceof OrderInterface
        ? $this->getAddress($order->getBillingProfile())
        : NULL;
    }
    if ($address === NULL) {
      return NULL;
    }

    $value = match ($property) {
      'dependent_locality' => $address->getDependentLocality(),
      'locality' => $address->getLocality(),
      'administrative_area' => $address->getAdministrativeArea(),
      'postal_code' => $address->getPostalCode(),
      'country_code' => $address->getCountryCode(),
      default => NULL,
    };

    return $value === NULL || $value === '' ? NULL : (string) $value;
  }

  /**
   * Mengambil item alamat dari profile.
   *
   * @param mixed $profile
   *   Profile (boleh NULL).
   *
   * @return \Drupal\address\AddressInterface|null
   *   Item alamat, atau NULL.
   */
  protected function getAddress($profile): ?AddressInterface {
    if (!$profile instanceof ProfileInterface || !$profile->hasField('address')) {
      return NULL;
    }
    if ($profile->get('address')->isEmpty()) {
      return NULL;
    }
    $address = $profile->get('address')->first();
    return $address instanceof AddressInterface ? $address : NULL;
  }

  /**
   * Mencari kode kecamatan lewat peta manual (district_map).
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment
   *   Shipment.
   * @param array $configuration
   *   Konfigurasi plugin.
   *
   * @return string|null
   *   Kode kecamatan, atau NULL.
   */
  protected function resolveFromMap(ShipmentInterface $shipment, array $configuration): ?string {
    $map = static::parseMap((string) ($configuration['district_map'] ?? ''));
    if (!$map) {
      return NULL;
    }
    $key_type = (string) ($configuration['map_key'] ?? 'postal_code');
    $value = $this->readAddressValue($shipment, $key_type);
    if ($value === NULL) {
      return NULL;
    }

    $candidates = [
      strtolower(trim($value)),
      strtolower(trim((string) preg_replace('/^(kec(amatan)?|kel(urahan)?|kota|kab(upaten)?)[ .]+/i', '', $value))),
    ];
    foreach (array_unique($candidates) as $candidate) {
      if ($candidate !== '' && isset($map[$candidate])) {
        return $map[$candidate];
      }
    }

    return NULL;
  }

  /**
   * Mengurai peta manual menjadi array [key => kode kecamatan].
   *
   * Format: satu pasangan per baris "key=kode", baris diawali "#" diabaikan.
   *
   * @param string $raw
   *   Teks peta.
   *
   * @return array
   *   Array keyed by lowercase key.
   */
  public static function parseMap(string $raw): array {
    $map = [];
    foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
      }
      [$key, $value] = explode('=', $line, 2);
      $key = strtolower(trim($key));
      $value = trim($value);
      if ($key === '' || $value === '') {
        continue;
      }
      $map[$key] = $value;
    }
    return $map;
  }

}
