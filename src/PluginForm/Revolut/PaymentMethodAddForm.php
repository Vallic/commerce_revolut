<?php

namespace Drupal\commerce_revolut\PluginForm\Revolut;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentMethodAddForm as BasePaymentMethodAddForm;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a payment form for RevolutCheckout and RevolutPay.
 */
class PaymentMethodAddForm extends BasePaymentMethodAddForm {

  use MessengerTrait;

  /**
   * The route matcher.
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->currentStore = $container->get('commerce_store.current_store');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->inlineFormManager = $container->get('plugin.manager.commerce_inline_form');
    $instance->logger = $container->get('logger.channel.commerce_payment');
    $instance->routeMatch = $container->get('current_route_match');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $form['payment_details'] = $this->buildCreditCardForm($form['payment_details'], $form_state);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildCreditCardForm(array $element, FormStateInterface $form_state) {
    // Alter the form with revolut specific needs.
    $element['#attributes']['class'][] = 'revolut-form';

    // Set our key to a settings array.
    /** @var \Drupal\commerce_revolut\Plugin\Commerce\PaymentGateway\RevolutCheckout $plugin */
    $plugin = $this->plugin;

    $order = $this->routeMatch->getParameter('commerce_order');

    if (!$order instanceof OrderInterface) {
      $element['markup'] = [
        '#type' => 'markup',
        '#markup' => $this->t('It is not possible to create payment method without order attached'),
      ];
      return $element;
    }

    try {
      $revolut_order = $plugin->putRevolutOrder($order);
    }
    catch (PaymentGatewayException $e) {
      $this->messenger()->addError($e->getMessage());
      return $element;
    }

    // If there is no review pane, finalize order upon submission of a checkout
    // form.
    if ($checkout_flow = $order->get('checkout_flow')->entity->getPlugin()) {
      $current_step = $this->routeMatch->getParameter('step');
      $next_step = $checkout_flow->getNextStepId($current_step);

      if ($next_step === 'payment') {
        $element['#attached']['library'][] = 'commerce_revolut/checkout';
        $element['#attached']['drupalSettings']['commerceRevolut'] = [
          'publicKey' => $plugin->getPublicKey(),
          'mode' => $plugin->getRevolutMode(),
          'order' => $revolut_order,
          'token' => $revolut_order['token'],
          'email' => $order->getEmail(),
          'billing' => $order->getBillingProfile()?->get('address')?->first()?->toArray(),
          'integration' => $plugin->getPluginId(),
        ];

        // Populated by the JS library.
        $element['revolut_payment_method_id'] = [
          '#type' => 'hidden',
          '#attributes' => [
            'id' => 'revolut-payment-method-id',
          ],
        ];

        $element['revolut-integration'] = [
          '#type' => 'item',
          '#required' => TRUE,
          '#validated' => TRUE,
          '#markup' => '<div id="revolut-integration" class="form-text"></div>',
        ];

        // To display validation errors.
        $element['payment_errors'] = [
          '#type' => 'markup',
          '#markup' => '<div id="payment-errors"></div>',
          '#weight' => -200,
        ];
      }
      else {
        // Populated by the JS library.
        $element['revolut_payment_method_id'] = [
          '#type' => 'hidden',
          '#value' => $revolut_order['id'],
          '#attributes' => [
            'id' => 'revolut-payment-method-id',
          ],
        ];
      }
    }

    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($this->entity);
    $cacheability->setCacheMaxAge(0);
    $cacheability->applyTo($element);
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateCreditCardForm(array &$element, FormStateInterface $form_state) {
    // The JS library performs its own validation.
  }

  /**
   * {@inheritdoc}
   */
  public function submitCreditCardForm(array $element, FormStateInterface $form_state) {
    if ($email = $form_state->getValue(['contact_information', 'email'])) {
      $email_parents = array_merge($element['#parents'], ['email']);
      $form_state->setValue($email_parents, $email);
    }
  }

}
