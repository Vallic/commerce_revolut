<?php

namespace Drupal\commerce_revolut\Event;

final class RevolutEvents {

  /**
   * Allow altering the order payload before is sent to Revolut.
   *
   * @Event
   *
   * @see \Drupal\commerce_signifyd\Event\SignifydCreateCaseEvent
   */
  const string REVOLUT_ORDER_PAYLOAD = 'revolut_order_payload';

}
