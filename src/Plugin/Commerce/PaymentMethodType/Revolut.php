<?php

namespace Drupal\commerce_revolut\Plugin\Commerce\PaymentMethodType;

use Drupal\commerce_payment\Attribute\CommercePaymentMethodType;
use Drupal\commerce_payment\CreditCard as CreditCardHelper;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentMethodType\PaymentMethodTypeBase;
use Drupal\commerce_revolut\Plugin\Commerce\PaymentGateway\RevolutInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\entity\BundleFieldDefinition;

/**
 * Provides the Revolut payment method type.
 */
#[CommercePaymentMethodType(
  id: "revolut",
  label: new TranslatableMarkup('Revolut Pay'),
)]
class Revolut extends PaymentMethodTypeBase {

  /**
   * {@inheritdoc}
   */
  public function buildLabel(PaymentMethodInterface $payment_method): string {
    if ($payment_method->get('revolut_payment_type')->isEmpty()) {
      return $payment_method->getPaymentGateway()?->getPlugin()->getDisplayLabel();
    }
    $payment_type = $payment_method->get('revolut_payment_type')->value;

    $name = RevolutInterface::REVOLUT_PAYMENT_METHODS[$payment_type];
    if ($payment_type !== 'revolut_pay_account' && !$payment_method->get('revolut_card_number')->isEmpty()) {
      $name = sprintf('%s ending in %s', $name, $payment_method->get('revolut_card_number')->value);
    }
    return $name;
  }

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions(): array {
    $fields = parent::buildFieldDefinitions();

    $fields['revolut_payment_type'] = BundleFieldDefinition::create('list_string')
      ->setLabel($this->t('Revolut method type'))
      ->setDescription($this->t('The revolut payment type.'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values_function', RevolutInterface::REVOLUT_PAYMENT_METHODS);

    $fields['revolut_card_type'] = BundleFieldDefinition::create('list_string')
      ->setLabel(t('Revolut Card type'))
      ->setDescription(t('The credit card type.'))
      ->setRequired(FALSE)
      ->setSetting('allowed_values_function', [
        CreditCardHelper::class,
        'getTypeLabels',
      ]);

    $fields['revolut_card_number'] = BundleFieldDefinition::create('string')
      ->setLabel(t('Revolut Card number'))
      ->setDescription(t('The last few digits of the credit card number'))
      ->setRequired(FALSE);

    $fields['revolut_card_exp_month'] = BundleFieldDefinition::create('integer')
      ->setLabel(t('Revolut Card expiration month'))
      ->setDescription(t('The credit card expiration month.'))
      ->setSetting('size', 'tiny');

    $fields['revolut_card_exp_year'] = BundleFieldDefinition::create('integer')
      ->setLabel(t('Revolut Card expiration year'))
      ->setDescription(t('The credit card expiration year.'))
      ->setSetting('size', 'small');

    return $fields;
  }

}
