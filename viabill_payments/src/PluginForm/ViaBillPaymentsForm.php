<?php

namespace Drupal\viabill_payments\PluginForm;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\viabill_payments\Helper\ViaBillHelper;
use Drupal\viabill_payments\Helper\ViaBillGateway;
use Drupal\Core\Url;
use Drupal\commerce_payment\Exception\PaymentGatewayException;

/**
 * Class responsible for building the payment request.
 */
class ViaBillPaymentsForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // Initialize API client.
    $gateway = new ViaBillGateway();
    $helper = new ViaBillHelper();

    $form = parent::buildConfigurationForm($form, $form_state);

    try {
      /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */      
      $payment = $form_state->get('payment') ?: $this->entity;
      $order = $payment->getOrder();

      if (!$order instanceof OrderInterface) {
        throw new PaymentGatewayException('Invalid order object');
      }

      $gateway_plugin = $this->plugin;
      $configuration = $gateway_plugin->getConfiguration();
      $mode = $gateway_plugin->getMode();

      // Validate required configuration.
      if (empty($configuration['api_key']) || empty($configuration['api_secret'])) {
        throw new PaymentGatewayException('Missing ViaBill API credentials');
      }

      // Generate transaction ID and store in payment.
      $transaction_id = $helper->formatTransactionId($order->id());
      // $payment->setRemoteId($transaction_id);
      // $payment->save();

      // Prepare order data.
      $order_total = $order->getTotalPrice();
      $order_amount = $helper->formatAmount($order_total->getNumber());
      $order_currency = $order_total->getCurrencyCode();

      // Build URLs with proper validation.
      $gateway_plugin = $this->plugin;
      $payment_gateway_id = $gateway_plugin->getPluginDefinition()['id'];

      $route_params = [
        'commerce_payment_gateway' => $payment_gateway_id,
        'commerce_order' => $order->id(),
        'step' => 'payment',
      ];

      $url_options = ['absolute' => TRUE, 'https' => TRUE];

      $success_url = Url::fromRoute('commerce_payment.checkout.return', $route_params, $url_options)->toString();
      $cancel_url = Url::fromRoute('commerce_payment.checkout.cancel', $route_params, $url_options)->toString();
      $callback_url = Url::fromRoute('viabill_payments.callback', $route_params, $url_options)->toString();

      // Build customer information.
      $customer_info = $this->buildCustomerData($order);
      $cart_info = $this->buildCartData($order);

      $order_id = $order->id();
      $api_key = $configuration['api_key'];
      $secret = $configuration['api_secret'];

      $signature_data = [
        $api_key,
        $order_amount,
        $order_currency,
        $transaction_id,
        $order_id,
        $success_url,
        $cancel_url,
        $secret,
      ];
      $md5check = md5(implode('#', $signature_data));

      // Prepare API request payload.
      $request_data = [
        'order_number' => $order_id,
        'apikey' => $api_key,
        'secret' => $secret,
        'transaction' => $transaction_id,
        'amount' => $order_amount,
        'currency' => $order_currency,
        'success_url' => $success_url,
        'cancel_url' => $cancel_url,
        'callback_url' => $callback_url,
        'test' => $helper->getFormattedTestMode(),
        'customParams' => $customer_info, // not json_encode(...)
        // 'cartParams' => (empty($cart_info)) ? '' : json_encode($cart_info),
        'md5check' => $md5check,
        'tbyb' => $helper->getFormattedTbyb(),
        'platform' => $helper->getViaBillApiPlatform(),
      ];

      $order->setData('viabill_transaction_id', $transaction_id);

      // Set order to pending status using state machine transitions.
      $state_transitions = $order->getState()->getTransitions();
      foreach ($state_transitions as $transition) {
        if ($transition->getToState()->getId() === 'pending') {
          $order->getState()->applyTransition($transition);
          break;
        }
      }

      $order->save();

      $response = $gateway->checkout($request_data);

      if (empty($response['redirect_url'])) {
        throw new PaymentGatewayException('Invalid gateway response: Missing redirect URL');
      }

      $redirect_url = $response['redirect_url'];

      return $this->buildRedirectForm(
        $form,
        $form_state,
        $redirect_url,
        [],
        self::REDIRECT_GET
      );

    }
    catch (\Exception $e) {
      \Drupal::logger('viabill_payments')->error('Error processing payment:' . $e->getMessage());
      throw new PaymentGatewayException('Error processing payment: ' . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildRedirectForm(array $form, FormStateInterface $form_state, $redirect_url, array $data = [], $redirect_method = self::REDIRECT_GET) {

    // Add a container for our redirect message and potential manual button.
    $form['redirect_container'] = [
      '#type' => 'container',
      '#weight' => 50,
    ];

    // Add a message for users.
    $form['redirect_container']['message'] = [
      '#markup' => $this->t('Please wait while you are redirected to the payment server...'),
    ];

    // Add the JavaScript to redirect using window.location.href.
    $form['#attached']['html_head'][] = [
      [
        '#type' => 'html_tag',
        '#tag' => 'script',
        '#value' => 'window.location.href = "' . $redirect_url . '";',
      ],
      'payment_redirect_script',
    ];

    // Add a fallback link in case JavaScript is disabled.
    $form['redirect_container']['fallback'] = [
      '#markup' => '<p>' . $this->t('If you are not automatically redirected, please <a href="@url">click here</a>.', ['@url' => $redirect_url]) . '</p>',
    ];

    return $form;
  }

  /**
   * Builds structured customer data from order.
   */
  protected function buildCustomerData(OrderInterface $order) {
    $data = [
      'email' => '',
      'phoneNumber' => '',
      'firstName' => '',
      'lastName' => '',
      'fullName' => '',
      'address' => '',
      'city' => '',
      'postalCode' => '',
      'country' => '',
    ];

    $customer = $order->getCustomer();
    $billing_profile = $order->getBillingProfile();
    if ($billing_profile && $billing_profile->hasField('address') && !$billing_profile->get('address')->isEmpty()) {
      $address = $billing_profile->get('address')->first();
      if ($address) {
        $data['email'] = $order->getEmail();
        $data['firstName'] = $address->getGivenName();
        $data['lastName'] = $address->getFamilyName();
        $data['address'] = $address->getAddressLine1() . ", " . $address->getAddressLine2();
        $data['city'] = $address->getLocality();
        $data['postalCode'] = $address->getPostalCode();
        $data['country'] = $address->getCountryCode();
      }
      $phone = $billing_profile->hasField('field_phone');
      if ($phone) {
        $data['phoneNumber'] = $phone;
      }
    }

    /*
    return array_filter($data, function($value) {
    return !empty($value);
    });
     */

    return $data;
  }

  /**
   * Helper function to compare two addresses.
   */
  protected function addressesAreEqual($address1, $address2) {
    if (!$address1 || !$address2) {
      return FALSE;
    }

    return $address1->getGivenName() == $address2->getGivenName() &&
           $address1->getFamilyName() == $address2->getFamilyName() &&
           $address1->getAddressLine1() == $address2->getAddressLine1() &&
           $address1->getLocality() == $address2->getLocality() &&
           $address1->getPostalCode() == $address2->getPostalCode() &&
           $address1->getCountryCode() == $address2->getCountryCode();
  }

  /**
   * Builds structured cart data from order items.
   */
  protected function buildCartData(OrderInterface $order) {
    $items = [];
    $order_total_adjustments = $order->getAdjustments();
    $tax_total = 0;
    $shipping_total = 0;
    $discount_total = 0;

    // Calculate adjustment totals.
    foreach ($order_total_adjustments as $adjustment) {
      if ($adjustment->getType() == 'tax') {
        $tax_total += $adjustment->getAmount()->getNumber();
      }
      if ($adjustment->getType() == 'shipping') {
        $shipping_total += $adjustment->getAmount()->getNumber();
      }
      if ($adjustment->getType() == 'promotion') {
        $discount_total += abs($adjustment->getAmount()->getNumber());
      }
    }

    // Build items array.
    foreach ($order->getItems() as $item) {
      /** @var \Drupal\commerce_order\Entity\OrderItemInterface $item */
      $unit_price = $item->getUnitPrice();
      $adjustments = $item->getAdjustments();
      $item_tax = 0;

      // Calculate item tax.
      foreach ($adjustments as $adjustment) {
        if ($adjustment->getType() == 'tax') {
          $item_tax += $adjustment->getAmount()->getNumber();
        }
      }

      $items[] = [
        'id' => $item->getPurchasedEntity() ? $item->getPurchasedEntity()->id() : $item->id(),
        'name' => $item->getTitle(),
        'quantity' => (int) $item->getQuantity(),
        'unit_price' => $unit_price->getNumber(),
        'tax' => $item_tax,
        'subtotal' => $item->getTotalPrice()->getNumber(),
        'sku' => $item->getPurchasedEntity() ? $item->getPurchasedEntity()->getSku() : '',
      ];
    }

    // Prepare complete cart data.
    $data = [
      'date_created' => \Drupal::service('date.formatter')->format($order->getCreatedTime(), 'custom', 'Y-m-d H:i:s'),
      'subtotal' => $order->getSubtotalPrice()->getNumber(),
      'tax' => $tax_total,
      'shipping' => $shipping_total,
      'discount' => $discount_total,
      'total' => $order->getTotalPrice()->getNumber(),
      'currency' => $order->getTotalPrice()->getCurrencyCode(),
      'quantity' => $this->calculateTotalItems($order),
      'products' => $items,
      'order_id' => $order->id(),
    ];

    return $data;
  }

  /**
   * Calculate the total number of items in the order.
   */
  protected function calculateTotalItems(OrderInterface $order) {
    $quantity = 0;
    foreach ($order->getItems() as $item) {
      $quantity += (int) $item->getQuantity();
    }
    return $quantity;
  }

}
