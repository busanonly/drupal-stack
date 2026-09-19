<?php

namespace Drupal\commerce_ongkir;

/**
 * Exception yang dilempar saat API Cek Ongkir mengembalikan error.
 */
class OngkirApiException extends \RuntimeException {

  /**
   * Kode HTTP dari API (0 bila gagal di level koneksi).
   *
   * @var int
   */
  protected $statusCode = 0;

  /**
   * Constructs a new OngkirApiException.
   *
   * @param string $message
   *   Pesan error.
   * @param int $status_code
   *   Kode HTTP (0 bila bukan error HTTP).
   * @param \Throwable|null $previous
   *   Exception sebelumnya.
   */
  public function __construct(string $message, int $status_code = 0, ?\Throwable $previous = NULL) {
    parent::__construct($message, $status_code, $previous);
    $this->statusCode = $status_code;
  }

  /**
   * Mengambil kode HTTP dari API.
   *
   * @return int
   *   Kode HTTP, atau 0 bila bukan error HTTP.
   */
  public function getStatusCode(): int {
    return $this->statusCode;
  }

}
