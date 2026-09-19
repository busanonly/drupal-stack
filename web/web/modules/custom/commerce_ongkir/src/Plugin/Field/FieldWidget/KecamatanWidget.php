<?php

namespace Drupal\commerce_ongkir\Plugin\Field\FieldWidget;

use Drupal\commerce_ongkir\DistrictResolver;
use Drupal\commerce_ongkir\OngkirApiClientInterface;
use Drupal\commerce_ongkir\OngkirApiException;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Widget provinsi → kota/kabupaten → kecamatan (kode kecamatan API ongkir).
 *
 * Nilai yang disimpan adalah kode kecamatan (district code) 6 digit, mis.
 * "317405", yang dipakai sebagai destination_district_code pada endpoint
 * GET /courier/v2/rates.
 */
#[FieldWidget(
  id: 'ongkir_kecamatan',
  label: new TranslatableMarkup('Ongkir: provinsi / kota / kecamatan'),
  description: new TranslatableMarkup('Tiga dropdown bertingkat (data dari API Cek Ongkir v2) yang menyimpan kode kecamatan.'),
  field_types: ['string'],
)]
class KecamatanWidget extends WidgetBase implements ContainerFactoryPluginInterface {

  /**
   * Client API ongkir.
   *
   * @var \Drupal\commerce_ongkir\OngkirApiClientInterface
   */
  protected OngkirApiClientInterface $apiClient;

  /**
   * Logger channel commerce_ongkir.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a new KecamatanWidget object.
   *
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Definisi plugin.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   Definisi field.
   * @param array $settings
   *   Widget settings.
   * @param array $third_party_settings
   *   Third party settings.
   * @param \Drupal\commerce_ongkir\OngkirApiClientInterface $api_client
   *   Client API ongkir.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger channel commerce_ongkir.
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, OngkirApiClientInterface $api_client, LoggerInterface $logger) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);

    $this->apiClient = $api_client;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('commerce_ongkir.api_client'),
      $container->get('logger.channel.commerce_ongkir'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $raw_value = (string) ($items[$delta]->value ?? '');
    $district_code = DistrictResolver::normalizeCode($raw_value) ?? '';

    $parents = array_merge($element['#field_parents'] ?? [], [$this->fieldDefinition->getName(), $delta]);
    $province_code = (string) ($form_state->getValue(array_merge($parents, ['province'])) ?? '');
    $city_code = (string) ($form_state->getValue(array_merge($parents, ['city'])) ?? '');
    $province_code = $province_code !== '' ? $province_code : substr($district_code, 0, 2);
    $city_code = $city_code !== '' ? $city_code : substr($district_code, 0, 4);

    $wrapper_id = Html::getUniqueId('ongkir-kecamatan');
    $element += [
      '#type' => 'container',
      '#prefix' => '<div id="' . $wrapper_id . '">',
      '#suffix' => '</div>',
      '#wrapper_id' => $wrapper_id,
      '#after_build' => [[static::class, 'clearValues']],
    ];

    try {
      $provinces = $this->apiClient->toOptions($this->apiClient->getProvinces());
      $cities = $province_code !== '' ? $this->apiClient->toOptions($this->apiClient->getCities($province_code)) : [];
      $districts = $city_code !== '' ? $this->apiClient->toOptions($this->apiClient->getDistricts($city_code)) : [];
    }
    catch (OngkirApiException $e) {
      $this->logger->warning('Ongkir widget kecamatan: @message', ['@message' => $e->getMessage()]);
      // Fallback: isi kode kecamatan manual bila API tidak dapat dihubungi,
      // supaya proses checkout tidak terhenti.
      $element['value'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Kode kecamatan (manual)'),
        '#description' => $this->t('Daftar provinsi/kota/kecamatan tidak dapat dimuat: @message Isi kode kecamatan 6 digit, contoh 317405.', ['@message' => $e->getMessage()]),
        '#default_value' => $district_code,
        '#size' => 12,
        '#maxlength' => 16,
      ];
      return $element;
    }

    // Nilai tersimpan tetap ditampilkan walau tidak ada di daftar API.
    if ($province_code !== '' && !isset($provinces[$province_code])) {
      $provinces[$province_code] = $province_code;
    }
    if ($city_code !== '' && !isset($cities[$city_code])) {
      $cities[$city_code] = $city_code;
    }
    if ($district_code !== '' && !isset($districts[$district_code])) {
      $districts[$district_code] = $district_code;
    }

    $ajax = [
      'callback' => [static::class, 'ajaxRefresh'],
      'wrapper' => $wrapper_id,
    ];

    $element['province'] = [
      '#type' => 'select',
      '#title' => $this->t('Provinsi'),
      '#options' => $provinces,
      '#empty_option' => $this->t('- Pilih provinsi -'),
      '#default_value' => $province_code,
      '#ajax' => $ajax,
    ];
    $element['city'] = [
      '#type' => 'select',
      '#title' => $this->t('Kota / kabupaten'),
      '#options' => $cities,
      '#empty_option' => $this->t('- Pilih kota/kabupaten -'),
      '#default_value' => $city_code,
      '#ajax' => $ajax,
    ];
    $element['value'] = [
      '#type' => 'select',
      '#title' => $this->t('Kecamatan'),
      '#options' => $districts,
      '#empty_option' => $this->t('- Pilih kecamatan -'),
      '#default_value' => $district_code,
    ];

    return $element;
  }

  /**
   * Ajax callback: mengembalikan elemen widget untuk dirender ulang.
   *
   * @param array $form
   *   Form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return array
   *   Elemen widget.
   */
  public static function ajaxRefresh(array $form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $parents = $triggering_element['#array_parents'];
    // Buang key elemen pemicu (province / city).
    array_pop($parents);
    $element = NestedArray::getValue($form, $parents);
    return is_array($element) ? $element : [];
  }

  /**
   * Mengosongkan pilihan turunan ketika provinsi/kota berubah.
   *
   * Dijalankan sebagai #after_build supaya nilai lama dibuang sebelum validasi
   * (mencegah error "illegal choice" karena daftar option berubah).
   *
   * @param array $element
   *   Elemen widget.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return array
   *   Elemen widget.
   */
  public static function clearValues(array $element, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    if (!$triggering_element) {
      return $element;
    }

    $keys = [
      'province' => ['city', 'value'],
      'city' => ['value'],
    ];
    $triggering_element_name = end($triggering_element['#parents']);
    if (!isset($keys[$triggering_element_name]) || !isset($element[$triggering_element_name])) {
      return $element;
    }

    $input = &$form_state->getUserInput();
    foreach ($keys[$triggering_element_name] as $key) {
      if (isset($element[$key])) {
        $parents = array_merge($element['#parents'], [$key]);
        NestedArray::setValue($input, $parents, '');
        $element[$key]['#value'] = '';
      }
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $new_values = [];
    foreach ($values as $delta => $value) {
      if (is_array($value)) {
        $new_values[$delta] = isset($value['value']) ? (string) $value['value'] : '';
      }
      else {
        $new_values[$delta] = (string) $value;
      }
    }
    return $new_values;
  }

}
