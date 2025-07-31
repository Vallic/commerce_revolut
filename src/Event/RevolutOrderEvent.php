<?php

namespace Drupal\commerce_revolut\Event;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Component\EventDispatcher\Event;

/**
 * Event that is fired before sending payload to Revolut.
 */
class RevolutOrderEvent extends Event {

  /**
   * Constructs a new RevolutOrderEvent object.
   */
  public function __construct(protected OrderInterface $order, protected array $payload) {}

  /**
   * Get the order payload.
   */
  public function getPayload(): array {
    return $this->payload;
  }

  /**
   * Set the new payload.
   */
  public function setPayload(array $payload): self {
    $this->payload = $payload;
    return $this;
  }

  /**
   * Get the order.
   */
  public function getOrder(): OrderInterface {
    return $this->order;
  }

}
