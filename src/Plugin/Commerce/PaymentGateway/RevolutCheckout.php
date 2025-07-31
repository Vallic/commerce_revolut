<?php

namespace Drupal\commerce_revolut\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_payment\PluginForm\PaymentMethodEditForm;
use Drupal\commerce_revolut\PluginForm\Revolut\PaymentMethodAddForm;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides the Revolut credit card on-site payment gateway.
 */
#[CommercePaymentGateway(
  id: "revolut_checkout",
  label: new TranslatableMarkup("Revolut Checkout"),
  display_label: new TranslatableMarkup("Credit card"),
  forms: [
    "add-payment-method" => PaymentMethodAddForm::class,
    "edit-payment-method" => PaymentMethodEditForm::class,
  ],
  payment_method_types: ["credit_card"],
  credit_card_types: [
    "amex", "mastercard", "visa",
  ],
  requires_billing_information: FALSE,
  libraries: ["commerce_revolut/checkout"],
)]
class RevolutCheckout extends OnsitePaymentGatewayBase implements RevolutCheckoutInterface {

  use RevolutTrait;

}
