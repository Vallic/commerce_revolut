<?php

namespace Drupal\commerce_revolut\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_revolut\PluginForm\OffsiteRedirect\PaymentOffsiteForm;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the Revolut payment link offsite gateway.
 */
#[CommercePaymentGateway(
  id: "revolut_payment_link",
  label: new TranslatableMarkup("Revolut Payment Link"),
  display_label: new TranslatableMarkup("Revolut"),
  forms: [
    "offsite-payment" => PaymentOffsiteForm::class,
  ],
  payment_type: "payment_default",
  requires_billing_information: FALSE,
)]
class RevolutPaymentLink extends OffsitePaymentGatewayBase implements RevolutInterface {

  use RevolutTrait;

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request): void {
    $payment_gateway = $order->get('payment_gateway')->entity;
    $plugin = $payment_gateway->getPlugin();
    assert($plugin instanceof RevolutInterface);
    $revolut_order = $this->getRevolutOrder($this->getRevolutOrderId($order));
    $revolut_payments = $revolut_order['payments'] ?? NULL;

    if (!$revolut_payments) {
      throw new PaymentGatewayException('No payment found.');
    }

    switch ($revolut_order['state']) {
      case 'pending':
        throw new PaymentGatewayException('Payment failed');

      case 'processing':
        throw new PaymentGatewayException('Payment failed');
    }

    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $payment_storage->create([
      'state' => $revolut_order['state'] === 'completed' ? 'completed' : 'pending',
      'amount' => $order->getBalance(),
      'payment_gateway' => $this->parentEntity->id(),
      'order_id' => $order->id(),
      'remote_id' => $revolut_order['id'],
      'remote_state' => $revolut_order['state'],
    ]);

    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    // Nothing for now.
  }

}
