<?php

namespace Drupal\commerce_midtrans;

/**
 * Exception untuk error Midtrans API.
 */
class MidtransApiException extends \RuntimeException {

  /**
   * Kode HTTP Midtrans (0 bila error koneksi).
   *
   * @var int
   */
  protected int $statusCode = 0;

  /**
   * Constructs a new MidtransApiException.
   *
   * @param string $message
   *   Pesan error.
   * @param int $status_code
   *   Kode HTTP Midtrans (0 bila bukan error HTTP).
   * @param \Throwable|null $previous
   *   Exception sebelumnya.
   */
  public function __construct(string $message, int $status_code = 0, ?\Throwable $previous = NULL) {
    parent::__construct($message, $status_code, $previous);
    $this->statusCode = $status_code;
  }

  /**
   * Kode HTTP Midtrans.
   *
   * @return int
   *   Kode HTTP, atau 0 bila bukan error HTTP.
   */
  public function getStatusCode(): int {
    return $this->statusCode;
  }

}
