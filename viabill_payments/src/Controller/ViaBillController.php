<?php

namespace Drupal\viabill_payments\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\viabill_payments\Helper\ViaBillHelper;
use Drupal\viabill_payments\Helper\ViaBillGateway;
use Drupal\viabill_payments\Helper\ViaBillConstants;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles return, cancel, and notify callbacks from ViaBill.
 */
class ViaBillController extends ControllerBase {

  /**
   * Callback fields covered by the ViaBill signature.
   */
  private const SIGNED_FIELDS = ['transaction', 'orderNumber', 'amount', 'currency', 'status', 'time', 'signature'];

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new ViaBillController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    LoggerInterface $logger,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('logger.factory')->get('viabill_payments')
    );
  }

  /**
   * Handles payment notifications (callback from ViaBill).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request containing notification data.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A 200 OK response to acknowledge receipt of the notification.
   */
  public function callback(Request $request) {
    $helper = new ViaBillHelper();

    try {
      $data = $this->getCallbackData($request);

      // All signed fields must be present as plain values.
      foreach (self::SIGNED_FIELDS as $field) {
        if (!isset($data[$field]) || !is_scalar($data[$field]) || (string) $data[$field] === '') {
          $helper->log('Callback rejected: missing or invalid "' . $field . '" field.', 'error');
          return new Response('Missing required parameters', 400);
        }
        $data[$field] = (string) $data[$field];
      }

      $transaction_id = $data['transaction'];
      $status = strtoupper($data['status']);

      // Find the order and make sure it belongs to this ViaBill transaction.
      $order = $this->findOrder($transaction_id, $data['orderNumber'], $request->query->get('commerce_order'));
      if (!$order) {
        $helper->log('Callback rejected: no order matches transaction ' . $transaction_id . ' / order number ' . $data['orderNumber'] . '.', 'error');
        return new Response('Not valid', 400);
      }

      // The order must have been paid with a ViaBill gateway.
      $payment_gateway = $this->loadPaymentGateway($order);
      if (!$payment_gateway) {
        $helper->log('Callback rejected: order ' . $order->id() . ' does not use a ViaBill payment gateway.', 'error');
        return new Response('Not valid', 400);
      }

      // Verify the signature with the credentials of the order's gateway.
      $configuration = $payment_gateway['plugin']->getConfiguration();
      $api_key = (string) ($configuration['api_key'] ?? '');
      $api_secret = (string) ($configuration['api_secret'] ?? '');
      if ($api_secret === '') {
        $api_secret = ViaBillHelper::getStoredSecret();
      }

      $gateway = new ViaBillGateway();
      $gateway->setCredentials($api_key, $api_secret);

      if (!$gateway->verifyCallbackSignature($data)) {
        $helper->log('Callback rejected: invalid signature for transaction ' . $transaction_id . '.', 'error');
        return new Response('Not valid', 400);
      }

      // The approved amount and currency must be the ones of the order.
      if (!$this->isAmountMatching($order, $data['amount'], $data['currency'], $helper)) {
        $helper->log('Callback rejected: amount/currency ' . $data['amount'] . ' ' . $data['currency'] . ' does not match order ' . $order->id() . '.', 'error');
        return new Response('Not valid', 400);
      }

      $existing_payment = $this->findPayment($order, $transaction_id);

      switch ($status) {
        case 'APPROVED':
          // Idempotency: this payment has already been processed.
          if ($existing_payment) {
            return new Response('OK', 200);
          }

          // Never re-open an order that has been cancelled.
          if ($order->getState()->getId() === 'canceled') {
            $helper->log('Ignoring APPROVED callback for cancelled order ' . $order->id() . ' (transaction ' . $transaction_id . ').', 'error');
            return new Response('OK', 200);
          }

          $this->processApprovedPayment($order, $payment_gateway['plugin'], $gateway, $transaction_id, $data['amount'], $data['currency'], $payment_gateway['entity']);
          break;

        case 'CANCELLED':
        case 'REJECTED':
          // Never cancel an order whose payment has already been approved;
          // log it for manual review instead.
          if ($existing_payment && $existing_payment->getState()->getId() !== 'voided') {
            $helper->log('Ignoring ' . $status . ' callback: order ' . $order->id() . ' already has an approved ViaBill payment (transaction ' . $transaction_id . ').', 'error');
            return new Response('OK', 200);
          }

          $this->processFailedPayment($order, $status);
          break;

        default:
          $helper->log('Callback with unknown status ' . $status . ' for order ' . $order->id() . '.', 'error');
          return new Response('Unknown status', 400);
      }

      return new Response('OK', 200);

    }
    catch (\Exception $e) {
      $helper->log('Error processing callback: ' . $e->getMessage(), 'error');
      // The exception details are logged, never returned to the caller.
      return new Response('Error processing request', 500);
    }
  }

  /**
   * Read the callback data from a JSON or form-encoded request body.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return array
   *   The callback data (empty if none could be read).
   */
  private function getCallbackData(Request $request) {
    $content_type = (string) $request->headers->get('Content-Type', '');
    $content = (string) $request->getContent();

    if ($content !== '' && (stripos($content_type, 'json') !== FALSE || strpos(ltrim($content), '{') === 0)) {
      $data = json_decode($content, TRUE);
      if (is_array($data)) {
        return $data;
      }
    }

    return $request->request->all();
  }

  /**
   * Find the order of a callback and make sure it belongs to the transaction.
   *
   * @param string $transaction_id
   *   The ViaBill transaction ID.
   * @param string $order_number
   *   The order number sent by ViaBill.
   * @param mixed $order_id_hint
   *   The order ID from the callback URL, if any.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface|null
   *   The order, or NULL if no order matches both values.
   */
  private function findOrder($transaction_id, $order_number, $order_id_hint = NULL) {
    $order_storage = $this->entityTypeManager->getStorage('commerce_order');

    // The callback URL names the order; older checkouts sent the order ID
    // itself as the order number.
    $candidates = [];
    foreach ([$order_id_hint, $order_number] as $candidate) {
      if (is_scalar($candidate) && ctype_digit((string) $candidate)) {
        $candidates[(string) $candidate] = (string) $candidate;
      }
    }

    foreach ($candidates as $order_id) {
      $order = $order_storage->load($order_id);
      if ($order instanceof OrderInterface && $this->orderMatches($order, $transaction_id, $order_number)) {
        return $order;
      }
    }

    // The transaction may also be known from an existing payment.
    $by_transaction = $this->findOrderByTransactionId($transaction_id);
    if ($by_transaction && $this->orderMatchesNumber($by_transaction, $order_number)) {
      return $by_transaction;
    }

    return NULL;
  }

  /**
   * Check that an order holds this ViaBill transaction and order number.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $transaction_id
   *   The ViaBill transaction ID.
   * @param string $order_number
   *   The order number sent by ViaBill.
   *
   * @return bool
   *   TRUE if the order belongs to the callback.
   */
  private function orderMatches(OrderInterface $order, $transaction_id, $order_number) {
    $order_transaction_id = (string) $order->getData('viabill_transaction_id', '');
    if ($order_transaction_id === '' || !hash_equals($order_transaction_id, (string) $transaction_id)) {
      return FALSE;
    }
    return $this->orderMatchesNumber($order, $order_number);
  }

  /**
   * Check the order number of a callback against the one sent for the order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $order_number
   *   The order number sent by ViaBill.
   *
   * @return bool
   *   TRUE if it is the order number sent at checkout.
   */
  private function orderMatchesNumber(OrderInterface $order, $order_number) {
    // Checkouts made before the order number was stored sent the order ID.
    $expected = (string) $order->getData('viabill_order_number', $order->id());
    return hash_equals($expected, (string) $order_number);
  }

  /**
   * Finds an order by ViaBill transaction ID.
   *
   * @param string $transaction_id
   *   The ViaBill transaction ID.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface|null
   *   The order, or null if not found.
   */
  private function findOrderByTransactionId($transaction_id) {
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payments = $payment_storage->loadByProperties(['remote_id' => $transaction_id]);

    if ($payment = reset($payments)) {
      return $payment->getOrder();
    }

    return NULL;
  }

  /**
   * Find the ViaBill payment already created for a transaction, if any.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $transaction_id
   *   The ViaBill transaction ID.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface|null
   *   The payment, or NULL.
   */
  private function findPayment(OrderInterface $order, $transaction_id) {
    $payments = $this->entityTypeManager->getStorage('commerce_payment')->loadByProperties([
      'order_id' => $order->id(),
      'remote_id' => $transaction_id,
    ]);

    $payment = reset($payments);
    return $payment ?: NULL;
  }

  /**
   * Check that a callback amount and currency match the order total.
   */
  private function isAmountMatching(OrderInterface $order, $amount, $currency, ViaBillHelper $helper) {
    $total = $order->getTotalPrice();
    if (!$total || !is_numeric($amount)) {
      return FALSE;
    }

    return $helper->formatAmount($total->getNumber()) === $helper->formatAmount($amount)
      && strcasecmp($total->getCurrencyCode(), (string) $currency) === 0;
  }

  /**
   * Loads the ViaBill payment gateway of an order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array|null
   *   The gateway 'entity' and 'plugin', or NULL if the order does not use a
   *   ViaBill payment gateway.
   */
  private function loadPaymentGateway(OrderInterface $order) {
    try {
      $payment_gateway_id = $order->get('payment_gateway')->target_id;
      if (!$payment_gateway_id) {
        return NULL;
      }

      $payment_gateway_storage = $this->entityTypeManager->getStorage('commerce_payment_gateway');
      $payment_gateway = $payment_gateway_storage->load($payment_gateway_id);

      if (!$payment_gateway || $payment_gateway->getPluginId() !== 'viabill_payments') {
        return NULL;
      }

      // Return both the entity and plugin.
      return [
        'entity' => $payment_gateway,
        'plugin' => $payment_gateway->getPlugin(),
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to load payment gateway: @error', ['@error' => ViaBillHelper::redact($e->getMessage())]);
      return NULL;
    }
  }

  /**
   * Processes an approved payment notification.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayInterface $payment_gateway
   *   The payment gateway plugin.
   * @param \Drupal\viabill_payments\Helper\ViaBillGateway $gateway
   *   The ViaBill API client, using the order's gateway credentials.
   * @param string $transaction_id
   *   The transaction ID.
   * @param string $amount
   *   The payment amount.
   * @param string $currency
   *   The payment currency.
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway_entity
   *   The gateway entity.
   */
  private function processApprovedPayment(OrderInterface $order, $payment_gateway, ViaBillGateway $gateway, $transaction_id, $amount, $currency, $payment_gateway_entity) {
    $helper = new ViaBillHelper();

    // Get transaction type from gateway configuration.
    $configuration = $payment_gateway->getConfiguration();
    $transaction_type = $configuration['transaction_type'] ?? ViaBillConstants::TRANSACTION_TYPE_AUTHORIZE_ONLY;

    // Determine if payment should be captured or authorized only.
    if ($transaction_type === ViaBillConstants::TRANSACTION_TYPE_AUTHORIZE_CAPTURE) {
      $captureData = [
        'id' => $transaction_id,
        'apikey' => (string) ($configuration['api_key'] ?? ''),
        // Amount must be negative, formatted with 2 decimals (as in the
        // manual capture of ViaBillPayments::capturePayment()).
        'amount' => $helper->formatAmount(-1 * abs((float) $amount)),
        'currency' => $currency,
      ];
      $capture = $gateway->captureTransaction($captureData);
      if ($capture !== TRUE) {
        // The payment is approved (authorized) even though the automatic
        // capture failed; it can be captured manually.
        $helper->log('Automatic capture failed for transaction ' . $transaction_id . '; the payment stays authorized.', 'error');
        $payment_state = 'authorization';
      }
      else {
        $payment_state = 'completed';
      }
    }
    else {
      $payment_state = 'authorization';
    }

    // Create a new payment entity.
    $payment = Payment::create([
      'state' => $payment_state,
      'amount' => new Price($helper->formatAmount($amount), $currency),
      'payment_gateway' => $payment_gateway_entity->id(),
      'order_id' => $order->id(),
      'remote_id' => $transaction_id,
      'remote_state' => $payment_state,
      'payment_method' => NULL,
    ]);
    $payment->save();
  }

  /**
   * Processes failed payments (cancelled/rejected).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $status
   *   The payment status.
   */
  private function processFailedPayment(OrderInterface $order, $status) {
    $helper = new ViaBillHelper();
    $current_state = $order->getState()->getId();

    // Only attempt cancellation if order isn't already in a final state.
    if ($current_state != 'completed' && $current_state != 'canceled') {
      // Try to cancel.
      $this->updateOrderState($order, 'canceled');
      $helper->log("Transaction failed. Order {$order->id()} marked as cancelled. Status: $status", "warning");
    }
    else {
      // Order is already in a final state.
      $helper->log("Transaction failed, but order {$order->id()} is already in '{$current_state}' state. Status: $status", "warning");
    }
  }

  /**
   * Updates an order's state using a safe transition if available.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $desired_state
   *   The desired state.
   */
  private function updateOrderState(OrderInterface $order, $desired_state) {
    $state_transitions = $order->getState()->getTransitions();

    // Find transitions that lead to our desired state.
    foreach ($state_transitions as $transition) {
      if ($transition->getToState()->getId() === $desired_state) {
        $order->getState()->applyTransition($transition);
        $order->save();
        return;
      }
    }

    // If no direct transition is available, try to find a path
    // For now, just log the issue.
    $this->logger->warning('Could not transition order @id to state @state', [
      '@id' => $order->id(),
      '@state' => $desired_state,
    ]);
  }

}
