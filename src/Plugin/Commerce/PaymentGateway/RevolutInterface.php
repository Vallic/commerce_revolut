<?php

namespace Drupal\commerce_revolut\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;

interface RevolutInterface {

  public const string REVOLUT_SANDBOX_URL = 'https://sandbox-merchant.revolut.com';

  public const string REVOLUT_PRODUCTION_URL = 'https://merchant.revolut.com';

  public const string REVOLUT_API_VERSION_DATE = '2024-09-01';

  public const int REVOLUT_KEY_VALUE_EXPIRATION = 6048000;

  public const array REVOLUT_ORDER_STATES_MAPPED = [
    'pending' => 'authorization',
    'processing' => 'authorization',
    'authorised' => 'authorization',
    'completed' => 'completed',
    'cancelled' => 'authorization_voided',
    'failed' => 'authorization_voided',
  ];

  public const array REVOLUT_PAYMENT_METHODS = [
    'apple_pay' => 'Apple Pay',
    'card' => 'Credit Card',
    'google_pay' => 'Google Pay',
    'revolut_pay_card' => 'Revolut pay',
    'revolut_pay_account' => 'Revolut Account',
  ];

  /**
   * Get the Revolut API public key set for the payment gateway.
   */
  public function getPublicKey(): string;

  /**
   * Get the Revolut API secret key set for the payment gateway.
   */
  public function getSecretKey(): string;

  /**
   * Retrieve Revolut payment gateway mode.
   */
  public function getRevolutMode(): string;

  /**
   * Create or update an order in Revolut.
   */
  public function putRevolutOrder(OrderInterface $order): array;

  /**
   * Create an order in Revolut.
   */
  public function createRevolutOrder(OrderInterface $order): array;

  /**
   * Update the details of a Revolut order.
   */
  public function updateRevolutOrder(OrderInterface $order): array;

  /**
   * Retrieve the details of a Revolut order that has been created.
   */
  public function getRevolutOrder(string $revolut_order_id): array;

  /**
   * Capture the funds of an existing, uncaptured Revolut order.
   */
  public function captureRevolutOrder(string $order_id, Price $amount): array;

  /**
   * Cancel (void) an existing uncaptured Revolut order.
   */
  public function cancelRevolutOrder(string $order_id): array;

  /**
   * Issue a refund for a completed Revolut order.
   * This operation allows for either a full or partial refund.
   */
  public function refundRevolutOrder(string $order_id, Price $amount): array;

  /**
   * Charge a customer's saved payment method.
   */
  public function payForRevolutOrder(OrderInterface $order, $initiator = 'merchant'): array;

  /**
   * Store revolut order id in key value storage.
   * Avoids writing to Order an object during checkout to avoid loss of the data.
   */
  public function setRevolutOrderId(OrderInterface $order, string $revolut_order_id): void;

  /**
   * Retrieve Revolut order id from key value storage.
   */
  public function getRevolutOrderId(OrderInterface $order): ?string;

}
