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
      // $container->get('logger.channel.viabill_payments')
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
    $gateway = new ViaBillGateway();

    try {
      $content_type = $request->headers->get('Content-Type');
      $content = $request->getContent();

      if (!empty($content) && $content_type === 'application/json') {
        try {
          // Instead of $request->request->all();
          $data = json_decode($content, TRUE);
        }
        catch (\Exception $e) {
          $helper->log("JSON decode error: " . $e->getMessage(), "error");
          return new Response("Missing required parameters", 400);
        }
      }

      // Extract key transaction details.
      $transaction_id = $data['transaction'] ?? NULL;
      $order_id = $data['orderNumber'] ?? NULL;
      $status = $data['status'] ?? NULL;
      $amount = $data['amount'] ?? NULL;
      $currency = $data['currency'] ?? NULL;

      // Validate required parameters.
      if (!$transaction_id || !$status || !$amount || !$currency) {
        $helper->log("ERROR: Missing required parameters in callback", "error");
        return new Response("Missing required parameters", 400);
      }

      // Validate the callback signature.
      if (!$gateway->verifyCallbackSignature($data)) {
        $helper->log("ERROR: Failed to verify callback signature.", "error");
        return new Response("Invalid signature", 400);
      }

      // Find the order associated with this transaction.
      $order = $this->findOrder($transaction_id, $order_id);
      if (!$order) {
        $helper->log("ERROR: Could not find order for transaction ID: $transaction_id", "error");
        return new Response("Order not found", 404);
      }

      // Load the payment gateway configuration.
      $payment_gateway = $this->loadPaymentGateway($order);
      if (!$payment_gateway) {
        $helper->log("ERROR: Could not load payment gateway for order ID: {$order->id()}", "error");
        return new Response("Payment gateway configuration not found", 500);
      }

      // Process the payment according to status.
      switch ($status) {
        case 'APPROVED':
          $this->processApprovedPayment($order, $payment_gateway['plugin'], $transaction_id, $amount, $currency, $payment_gateway['entity']);
          break;

        case 'CANCELLED':
        case 'REJECTED':
          $this->processFailedPayment($order, $status);
          break;

        default:
          $helper->log("ERROR: Unknown status received: $status", "error");
          return new Response("Unknown status", 400);
      }

      return new Response("OK", 200);

    }
    catch (\Exception $e) {
      $helper->log("Error processing callback: " . $e->getMessage(), "error");
      return new Response("Error processing request: " . $e->getMessage(), 500);
    }
  }

  /**
   * Find an Order by its id or the companion payment transaction id.
   */
  private function findOrder($transaction_id, $order_id = NULL) {
    // First try by order ID if available.
    if ($order_id) {
      $order_storage = $this->entityTypeManager->getStorage('commerce_order');
      $order = $order_storage->load($order_id);
      if ($order) {
        return $order;
      }
    }

    // Fall back to transaction ID lookup if order ID fails.
    return $this->findOrderByTransactionId($transaction_id);
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
    // Try to find it in metadata first.
    $order_storage = $this->entityTypeManager->getStorage('commerce_order');
    $query = $order_storage->getQuery()
      ->condition('data.viabill_transaction_id', $transaction_id)
      ->accessCheck(FALSE)
      ->range(0, 1);
    $order_ids = $query->execute();

    if (!empty($order_ids)) {
      return $order_storage->load(reset($order_ids));
    }

    // If not found, check if it's in a payment.
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payments = $payment_storage->loadByProperties(['remote_id' => $transaction_id]);

    if ($payment = reset($payments)) {
      return $payment->getOrder();
    }

    return NULL;
  }

  /**
   * Loads the payment gateway plugin for an order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayInterface|null
   *   The payment gateway plugin, or null if not found.
   */
  private function loadPaymentGateway(OrderInterface $order) {
    try {
      $payment_gateway_id = $order->get('payment_gateway')->target_id;
      if (!$payment_gateway_id) {
        return NULL;
      }

      $payment_gateway_storage = $this->entityTypeManager->getStorage('commerce_payment_gateway');
      $payment_gateway = $payment_gateway_storage->load($payment_gateway_id);

      if (!$payment_gateway) {
        return NULL;
      }

      // Return both the entity and plugin.
      return [
        'entity' => $payment_gateway,
        'plugin' => $payment_gateway->getPlugin(),
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to load payment gateway: @error', ['@error' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Processes an approved payment notification.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayInterface $payment_gateway
   *   The payment gateway.
   * @param string $transaction_id
   *   The transaction ID.
   * @param string $amount
   *   The payment amount.
   * @param string $currency
   *   The payment currency.
   * @param \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayInterface $payment_gateway_entity
   *   The gateway entity.
   */
  private function processApprovedPayment(OrderInterface $order, $payment_gateway, $transaction_id, $amount, $currency, $payment_gateway_entity) {
    $helper = new ViaBillHelper();
    $gateway = new ViaBillGateway();

    $captured_amount = 0;

    // Get transaction type from gateway configuration.
    $configuration = $payment_gateway->getConfiguration();
    $transaction_type = $configuration['transaction_type'] ?? ViaBillConstants::TRANSACTION_TYPE_AUTHORIZE_ONLY;

    // Determine if payment should be captured or authorized only.
    if ($transaction_type === ViaBillConstants::TRANSACTION_TYPE_AUTHORIZE_CAPTURE) {
      $captureData = [
        'id' => $transaction_id,
        'apikey' => $helper->getApiKey(),
          // Amount must be negative.
        'amount' => ($helper->formatAmount($amount) <= 0 ? $helper->formatAmount($amount) : (-1 * abs($helper->formatAmount($amount)))),
        'currency' => $currency,
      ];
      $capture = $gateway->captureTransaction($captureData);
      if ($capture !== TRUE) {
        $payment_state = 'authorization';
      }
      else {
        $payment_state = 'completed';
        $captured_amount = $amount;
      }
    }
    else {
      $payment_state = 'authorization';
    }

    // Create a new payment entity.
    $payment = Payment::create([
      'state' => $payment_state,
      'amount' => new Price($amount, $currency),
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
