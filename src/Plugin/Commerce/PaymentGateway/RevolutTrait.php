<?php

namespace Drupal\commerce_revolut\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_price\Price;
use Drupal\commerce_revolut\Event\RevolutEvents;
use Drupal\commerce_revolut\Event\RevolutOrderEvent;
use Drupal\commerce_revolut\Exception\RevolutException;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactory;
use Drupal\Core\Logger\LoggerChannelTrait;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Holds logic for all revolut available methods.
 */
trait RevolutTrait {

  use LoggerChannelTrait;

  protected ClientInterface $httpClient;

  protected EventDispatcherInterface $eventDispatcher;

  protected ModuleExtensionList $moduleExtensionList;

  protected KeyValueExpirableFactory $keyValueExpirableFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->httpClient = $container->get('http_client');
    $instance->minorUnitsConverter = $container->get('commerce_price.minor_units_converter');
    $instance->eventDispatcher = $container->get('event_dispatcher');
    $instance->moduleExtensionList = $container->get('extension.list.module');
    $instance->keyValueExpirableFactory = $container->get('keyvalue.expirable');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getPublicKey(): string {
    return $this->configuration['public_key'];
  }

  /**
   * {@inheritdoc}
   */
  public function getSecretKey(): string {
    return $this->configuration['secret_key'];
  }

  /**
   * {@inheritdoc}
   */
  public function getRevolutMode(): string {
    return $this->getMode() === 'live' ? 'production' : 'sandbox';
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'public_key' => '',
      'secret_key' => '',
      'logging' => FALSE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['public_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Public Key'),
      '#default_value' => $this->configuration['public_key'],
      '#required' => TRUE,
    ];

    $form['secret_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Secret Key'),
      '#default_value' => $this->configuration['secret_key'],
      '#required' => TRUE,
    ];

    $form['logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log API calls'),
      '#default_value' => $this->configuration['logging'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['public_key'] = $values['public_key'];
      $this->configuration['secret_key'] = $values['secret_key'];
      $this->configuration['logging'] = $values['logging'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    $this->assertPaymentState($payment, ['new']);
    $payment_method = $payment->getPaymentMethod();
    assert($payment_method instanceof PaymentMethodInterface);
    $this->assertPaymentMethod($payment_method);
    $order = $payment->getOrder();
    assert($order instanceof OrderInterface);

    // We are trying to use stored payment method.
    if ($payment_method->isReusable()) {
      try {
        $this->payForRevolutOrder($order);
      }
      catch (RevolutException $e) {
        throw PaymentGatewayException::createForPayment($payment, $e->getRevolutMessage());
      }
    }

    $revolut_order_id = $this->getRevolutOrderId($order);
    try {
      $revolut_order = $this->getRevolutOrder($revolut_order_id);
    }
    catch (RevolutException $e) {
      throw PaymentGatewayException::createForPayment($payment, $e->getRevolutMessage());
    }

    $order_state = RevolutInterface::REVOLUT_ORDER_STATES_MAPPED[$revolut_order['state']];

    $payment->setState($order_state);
    $payment->setRemoteId($revolut_order['id']);
    $payment->save();

    // Depending on the type of the checkout, we may need to update
    // the payment method - if the review pane is used.
    if (!$payment_method->isReusable()) {
      $this->updatePaymentMethod($payment_method, $revolut_order, FALSE);
    }

  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details): void {
    $revolut_order_id = $payment_details['revolut_payment_method_id'] ?? NULL;
    if (empty($revolut_order_id)) {
      throw new InvalidRequestException('$payment_details must contain the revolut_payment_method_id key.');
    }

    try {
      $revolut_order = $this->getRevolutOrder($revolut_order_id);
    }
    catch (RevolutException $e) {
      throw new InvalidRequestException($e->getMessage());
    }

    $this->updatePaymentMethod($payment_method, $revolut_order);
  }

  /**
   * Create or update existing payment method.
   */
  protected function updatePaymentMethod(PaymentMethodInterface $payment_method, array $revolut_order, $new_method = TRUE): void {
    $revolut_payments = $revolut_order['payments'] ?? [];

    $remote_payment = NULL;
    $errors = [];
    foreach ($revolut_payments as $revolut_payment) {
      if (empty($revolut_payment['decline_reason'])) {
        $remote_payment = $revolut_payment;
        break;
      }

      $errors[] = $revolut_payment['decline_reason'];
    }

    // If we're trying to create payment and update payment method.
    if (!$new_method && !$remote_payment) {
      throw new InvalidRequestException('Payment failed at payment gateway. %s', implode(' ', $errors));
    }

    $integration_type = $payment_method->getType()->getPluginId();

    if ($remote_payment) {
      $revolut_payment_method = $remote_payment['payment_method'];

      if ($integration_type === 'revolut') {
        $payment_method->set('revolut_payment_type', $revolut_payment_method['type']);
      }

      $field_prefix = $integration_type === 'revolut' ? 'revolut_' : '';

      if (isset($revolut_payment_method['card_brand'], $revolut_payment_method['card_last_four'])) {
        $payment_method->set(sprintf('%scard_type', $field_prefix), $this->mapCreditCardType($revolut_payment_method['card_brand']));
        $payment_method->set(sprintf('%scard_number', $field_prefix), $revolut_payment_method['card_last_four']);

        if (!empty($revolut_payment_method['card_expiry'])) {
          [$month, $year] = explode('/', $revolut_payment_method['card_expiry']);
          $payment_method->set(sprintf('%scard_exp_month', $field_prefix), $month);
          $payment_method->set(sprintf('%scard_exp_year', $field_prefix), '20' . $year);
          $expires = CreditCard::calculateExpirationTimestamp($month, '20' . $year);
          $payment_method->setExpiresTime($expires);
        }
      }

      if ($new_method) {
        $payment_method->setReusable(FALSE);
        $payment_method->setRemoteId($revolut_order['id']);
      }
      else {
        $customer = $payment_method->getOwner();
        if (!empty($revolut_order['customer']['id'])) {
          $this->setRemoteCustomerId($customer, $revolut_order['customer']['id']);
        }
        // Store payment methods returns the stored id. If the method
        // is not reusable, no id is returned.
        $remote_id = $revolut_payment_method['id'] ?? NULL;
        $payment_method->setReusable((bool) $remote_id);
        $payment_method->setRemoteId($remote_id ?? $revolut_order['id']);
      }
    }

    else {
      $payment_method->setReusable(FALSE);
      $payment_method->setRemoteId($revolut_order['id']);
    }

    $payment_method->save();

  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method): void {
    $payment_method->delete();
  }

  /**
   * Maps the Revolut credit card type to a Commerce credit card type.
   */
  protected function mapCreditCardType(string $card_type): string {
    $map = [
      'american_express' => 'amex',
      'mastercard' => 'mastercard',
      'visa' => 'visa',
    ];
    if (!isset($map[$card_type])) {
      throw new HardDeclineException(sprintf('Unsupported credit card type "%s".', $card_type));
    }

    return $map[$card_type];
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, ?Price $amount = NULL): void {
    $this->assertPaymentState($payment, ['authorization']);
    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();

    try {
      $this->captureRevolutOrder($payment->getRemoteId(), $amount);
    }
    catch (RevolutException $e) {
      throw PaymentGatewayException::createForPayment($payment, $e->getRevolutMessage());
    }

  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, ?Price $amount = NULL): void {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $this->assertRefundAmount($payment, $amount);

    try {
      $this->refundRevolutOrder($payment->getRemoteId(), $amount);
    }
    catch (RevolutException $e) {
      throw PaymentGatewayException::createForPayment($payment, $e->getRevolutMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment): void {
    $this->assertPaymentState($payment, ['authorization']);
    try {
      $this->cancelRevolutOrder($payment->getRemoteId());
    }
    catch (RevolutException $e) {
      throw PaymentGatewayException::createForPayment($payment, $e->getRevolutMessage());
    }
  }

  /**
   * Method to process requests towards Revolut API.
   */
  protected function request(string $endpoint, string $method = 'GET', array $payload = []): array {
    $endpoint_url = sprintf('%s/api/%s', $this->getRevolutMode() === 'sandbox' ? RevolutInterface::REVOLUT_SANDBOX_URL : RevolutInterface::REVOLUT_PRODUCTION_URL, $endpoint);
    $commerce_core = $this->moduleExtensionList->getExtensionInfo('commerce');
    try {
      $request_data = [
        'headers' => [
          'Authorization' => "Bearer " . $this->getSecretKey(),
          'Revolut-Api-Version' => RevolutInterface::REVOLUT_API_VERSION_DATE,
          'Content-Type' => 'application/json',
          'User-Agent' => sprintf('Commerce Core %s for Drupal - https://www.drupal.org/project/commerce_revolut (Language=PHP/%s;)', $commerce_core[0]['version'] ?? '3', PHP_VERSION),
        ],
      ];

      if ($payload) {
        $request_data['body'] = Json::encode($payload);
        if ($this->configuration['logging']) {
          $this->getLogger('commerce_revolut')->info($request_data['body']);
        }
      }

      $response = $this->httpClient->request($method, $endpoint_url, $request_data);
    }
    catch (\Exception $exception) {
      $this->getLogger('commerce_revolut')->error($exception->getMessage());
      $data = Json::decode($exception->getResponse()->getBody()) ?? [];
      throw new RevolutException($exception->getMessage(), $exception->getCode(), $data);
    }

    $contents = $response->getBody()->getContents();

    if ($this->configuration['logging']) {
      $this->getLogger('commerce_revolut')->info($contents);
    }

    return Json::decode($contents);
  }

  /**
   * {@inheritdoc}
   */
  public function putRevolutOrder(OrderInterface $order): array {
    $revolut_id = $this->getRevolutOrderId($order);

    try {
      if (!$revolut_id) {
        $revolut_order = $this->createRevolutOrder($order);
      }
      else {
        $revolut_order = $this->getRevolutOrder($revolut_id);
        // During checkout allow only updates to pending orders.
        if ($revolut_order['state'] === 'pending') {
          $revolut_order = $this->updateRevolutOrder($order);
        }
      }
    }
    catch (RevolutException $e) {
      $this->getLogger('commerce_revolut')->error($e->getMessage());
      throw new PaymentGatewayException($e->getRevolutMessage());
    }

    return $revolut_order;
  }

  /**
   * {@inheritdoc}
   */
  public function createRevolutOrder(OrderInterface $order, $data = []): array {
    $payload = $this->buildOrderPayload($order, $data);
    $response = $this->request('orders', 'POST', $payload);
    if (isset($response['id'])) {
      $this->setRevolutOrderId($order, $response['id']);
    }
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function updateRevolutOrder(OrderInterface $order, $data = []): array {
    $payload = $this->buildOrderPayload($order, $data);
    $order_id = $this->getRevolutOrderId($order);
    return $this->request('orders/' . $order_id, 'PATCH', $payload);
  }

  /**
   * {@inheritdoc}
   */
  protected function buildOrderPayload(OrderInterface $order, $data): array {
    $total_amount = $order->getTotalPrice();
    $payload = [
      'amount' => $this->minorUnitsConverter->toMinorUnits($total_amount),
      'currency' => $total_amount->getCurrencyCode(),
      'line_items' => [],
      'merchant_order_data' => ['reference' => $order->id()],
    ];

    $customer = $order->getCustomer();

    if ($remote_id = $this->getRemoteCustomerId($customer)) {
      $payload['customer']['id'] = $remote_id;
    }

    if ($email = $order->getEmail()) {
      $payload['customer']['email'] = $email;
    }

    $payload = array_merge($payload, $data);

    foreach ($order->getItems() as $item) {
      $purchased_entity = $item->getPurchasedEntity();
      $type_of_goods = 'service';
      /** @var \Drupal\commerce_product\Entity\ProductVariationTypeInterface|null $entity_type */
      if (($entity_type = $purchased_entity?->get('type')->entity) && $entity_type->hasTrait('purchasable_entity_shippable')) {
        $type_of_goods = 'physical';
      }
      $line_item = [
        'name' => $purchased_entity ? $purchased_entity->label() : $item->label(),
        'type' => $type_of_goods,
        'quantity' => ['value' => $item->getQuantity()],
        'unit_price_amount' => $this->minorUnitsConverter->toMinorUnits($item->getUnitPrice()),
        'total_amount' => $this->minorUnitsConverter->toMinorUnits($item->getTotalPrice()),
        'external_id' => $item->id(),
      ];

      // Check if we have promotion adjustments.
      $promotion_adjustments = $item->getAdjustments(['promotion', 'bundle_saving']);
      foreach ($promotion_adjustments as $promotion_adjustment) {
        $line_item['discounts'][] = [
          'name' => $promotion_adjustment->getLabel(),
          'amount' => abs($this->minorUnitsConverter->toMinorUnits($promotion_adjustment->getAmount())),
        ];
      }

      // Check if we have promotion adjustments.
      $tax_adjustments = $item->getAdjustments(['tax']);
      foreach ($tax_adjustments as $tax_adjustment) {
        $line_item['taxes'][] = [
          'name' => $tax_adjustment->getLabel(),
          'amount' => abs($this->minorUnitsConverter->toMinorUnits($tax_adjustment->getAmount())),
        ];
      }

      $payload['line_items'][] = $line_item;
    }

    $customer_id = $this->getRemoteCustomerId($order->getCustomer());

    if (!empty($customer_id)) {
      $payload['customer_id'] = $customer_id;
    }

    /** @var \Drupal\commerce_checkout\Entity\CheckoutFlowInterface $checkout_flow */
    if ($checkout_flow = $order->get('checkout_flow')->entity) {
      $plugin = $checkout_flow->getPlugin();
      $configuration = $plugin->getConfiguration();
      if (isset($configuration['panes']['payment_process']['capture'])) {
        $payload['capture_mode'] = $configuration['panes']['payment_process']['capture'] ? 'automatic' : 'manual';
      }

    }

    // Trigger the event.
    $event = new RevolutOrderEvent($order, $payload);
    $this->eventDispatcher->dispatch($event, RevolutEvents::REVOLUT_ORDER_PAYLOAD);

    return $event->getPayload();
  }

  /**
   * {@inheritdoc}
   */
  public function getRevolutOrder(string $revolut_order_id): array {
    return $this->request('orders/' . $revolut_order_id);
  }

  /**
   * {@inheritdoc}
   */
  public function captureRevolutOrder(string $revolut_order_id, Price $amount): array {
    $payload = [
      'amount' => $this->minorUnitsConverter->toMinorUnits($amount),
    ];
    return $this->request('orders/' . $revolut_order_id . '/capture', 'POST', $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function cancelRevolutOrder(string $revolut_order_id): array {
    return $this->request('orders/' . $revolut_order_id . '/cancel', 'POST');
  }

  /**
   * {@inheritdoc}
   */
  public function refundRevolutOrder(string $revolut_order_id, Price $amount): array {
    $payload = [
      'amount' => $this->minorUnitsConverter->toMinorUnits($amount),
      'currency' => $amount->getCurrencyCode(),
    ];
    return $this->request('orders/' . $revolut_order_id . '/refund', 'PATCH', $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function payForRevolutOrder(OrderInterface $order, $initiator = 'merchant'): array {
    $payment_method = $order->get('payment_method')->entity;
    assert($payment_method instanceof PaymentMethodInterface);
    $order_id = $this->getRevolutOrderId($order);

    if (!$order_id) {
      $revolut_order = $this->createRevolutOrder($order);
      $order_id = $revolut_order['id'];
    }

    $type = 'card';

    if (($this->getPluginId() === 'revolut_pay') && in_array($payment_method->get('revolut_payment_type')->value, ['revolut_pay_card', 'revolut_pay_account'])) {
      $type = 'revolut_pay';
    }

    $payload = [
      'saved_payment_method' => [
        'id' => $payment_method->getRemoteId(),
        'type' => $type,
        'initiator' => $initiator,
      ],
    ];
    return $this->request('orders/' . $order_id . '/payments', 'POST', $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function getPaymentsByOrderId(string $order_id): array {
    return $this->request('orders/' . $order_id . '/payments');
  }

  /**
   * {@inheritdoc}
   */
  public function setRevolutOrderId(OrderInterface $order, string $revolut_order_id): void {
    $this->keyValueExpirableFactory->get('commerce_revolut')->setWithExpire($order->id(), $revolut_order_id, RevolutInterface::REVOLUT_KEY_VALUE_EXPIRATION);
  }

  /**
   * {@inheritdoc}
   */
  public function getRevolutOrderId(OrderInterface $order): ?string {
    return $this->keyValueExpirableFactory->get('commerce_revolut')->get($order->id());
  }

}
