<?php

namespace Drupal\commerce_revolut\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_payment\PluginForm\PaymentMethodEditForm;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_revolut\PluginForm\Revolut\PaymentMethodAddForm;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides the RevolutPay on-site payment gateway.
 */
#[CommercePaymentGateway(
  id: "revolut_pay",
  label: new TranslatableMarkup("Revolut Pay"),
  display_label: new TranslatableMarkup("Revolut Pay"),
  forms: [
    "add-payment-method" => PaymentMethodAddForm::class,
    "edit-payment-method" => PaymentMethodEditForm::class,
  ],
  payment_method_types: ["revolut"],
  credit_card_types: [
    "amex", "mastercard", "visa",
  ],
  requires_billing_information: FALSE,
  libraries: ["commerce_revolut/checkout"],
)]
class RevolutPay extends OnsitePaymentGatewayBase implements RevolutCheckoutInterface {

  use RevolutTrait;

}
