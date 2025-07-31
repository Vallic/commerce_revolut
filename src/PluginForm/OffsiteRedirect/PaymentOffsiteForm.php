<?php

namespace Drupal\commerce_revolut\PluginForm\OffsiteRedirect;

use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\commerce_revolut\Exception\RevolutException;
use Drupal\Core\Form\FormStateInterface;

/**
 * Implements the Payment offsite form.
 */
class PaymentOffsiteForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;

    /** @var \Drupal\commerce_revolut\Plugin\Commerce\PaymentGateway\RevolutPaymentLink $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();

    $capture = $form['#capture'] ? 'automatic' : 'manual';
    try {
      $revolut_order = $payment_gateway_plugin->createRevolutOrder($payment->getOrder(), ['capture' => $capture, 'redirect_url' => $form['#return_url']]);
    }
    catch (RevolutException $e) {
      throw new PaymentGatewayException($e->getRevolutMessage());
    }
    return $this->buildRedirectForm($form, $form_state, $revolut_order['checkout_url'], [], 'get');
  }

}
