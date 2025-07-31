<?php

declare(strict_types=1);

namespace Drupal\commerce_revolut\Exception;

/**
 * Handling Revolut specific errors.
 */
final class RevolutException extends \Exception {

  /**
   * Constructor.
   */
  public function __construct(
    string $message = '',
    int $code = 0,
    protected array $revolutErrorResponse = [],
    ?\Throwable $previousException = NULL,
  ) {
    parent::__construct($message, $code, $previousException);
  }

  /**
   * Get Revolut error code.
   */
  public function getRevolutCode(): string {
    return $this->revolutErrorResponse['code'] ?? (string) $this->code;
  }

  /**
   * Get a Revolut error message.
   */
  public function getRevolutMessage(): string {
    return $this->revolutErrorResponse['message'] ?? $this->message;
  }

  /**
   * Format message with code and error.
   */
  public function getRevolutError(): string {
    return sprintf('Error code: %s. Error message: %s', $this->getRevolutCode(), $this->getRevolutMessage());
  }

  /**
   * Get timestamp for an error.
   */
  public function getTimestamp(): ?int {
    return $this->revolutErrorResponse['timestamp'] ?? NULL;
  }

}
