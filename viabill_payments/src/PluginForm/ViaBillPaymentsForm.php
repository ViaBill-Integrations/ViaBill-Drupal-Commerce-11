<?php

namespace Drupal\viabill_payments\PluginForm;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\viabill_payments\Helper\ViaBillHelper;
use Drupal\viabill_payments\Helper\ViaBillGateway;
use Drupal\viabill_payments\Helper\ViaBillConstants;
use Drupal\Core\Url;
use Drupal\Core\Render\Markup;
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

      // The secret may only be kept in the module's own (State API) copy.
      if (empty($configuration['api_secret'])) {
        $configuration['api_secret'] = ViaBillHelper::getStoredSecret();
      }

      // Validate required configuration.
      if (empty($configuration['api_key']) || empty($configuration['api_secret'])) {
        throw new PaymentGatewayException('Missing ViaBill API credentials');
      }

      // Generate transaction ID and store in payment.
      $transaction_id = $helper->formatTransactionId($order->id());      

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

      // The order number ViaBill sees, based on the merchant's order number.
      $order_id = $helper->buildCheckoutOrderNumber($order, $configuration['order_number_prefix'] ?? '');
      $api_key = $configuration['api_key'];
      $secret = $configuration['api_secret'];

      // Compute SHA256 checksum for integrity (using a combination of fields and secret)        
      $testModeEnabled = $helper->getFormattedTestMode();
      if ($testModeEnabled == ViaBillConstants::TEST_MODE_ON) {
          $signature_data = [
            $api_key,
            $order_amount,
            $order_currency,
            $transaction_id,
            $order_id,
            $success_url,
            $cancel_url,
            $secret,
            'true'
          ];
      } else {
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
      }
                  
      $checksum_input = trim(implode('#', $signature_data));	
      $sha256check = hash('sha256', $checksum_input);

      // Prepare API request payload.          
      $request_data = [       
        'protocol' => '3.1',
        'transaction' => $transaction_id,
        'amount' => $order_amount,
        'currency' => $order_currency,
        'test' => $helper->getFormattedTestMode(),
        'sha256check' => $sha256check,
        'apikey' => $api_key,
        'order_number' => $order_id,
        'success_url' => $success_url,
        'cancel_url' => $cancel_url,
        'callback_url' => $callback_url,
        // 'tbyb' => $helper->getFormattedTbyb(),
        // 'customParams' => $customer_info, // not json_encode(...)
        // 'cartParams' => (empty($cart_info)) ? '' : json_encode($cart_info),
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
        throw new PaymentGatewayException('Invalid gateway response: ' . ($response['error'] ?? $response['message'] ?? 'Missing redirect URL'));
      }

      $redirect_url = $response['redirect_url'];

      // Only ever send the customer to an HTTPS address of ViaBill.
      if (!$this->isAllowedRedirectUrl($redirect_url)) {
        throw new PaymentGatewayException('Invalid gateway response: unexpected redirect URL');
      }

      return $this->buildRedirectForm(
        $form,
        $form_state,
        $redirect_url,
        [],
        self::REDIRECT_GET
      );

    }
    catch (\Exception $e) {
      // Commerce logs this exception too, so never let credentials into it.
      $error = ViaBillHelper::redact($e->getMessage());
      \Drupal::logger('viabill_payments')->error('Error processing payment: @error', ['@error' => $error]);
      throw new PaymentGatewayException('Error processing payment: ' . $error);
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

    // Add the JavaScript to redirect using window.location.href. The URL is
    // JSON encoded (with HTML-significant characters escaped), so it can not
    // break out of the script.
    $form['#attached']['html_head'][] = [
      [
        '#type' => 'html_tag',
        '#tag' => 'script',
        '#value' => Markup::create('window.location.href = ' . json_encode((string) $redirect_url, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) . ';'),
      ],
      'payment_redirect_script',
    ];

    // Add a fallback link in case JavaScript is disabled (":url" placeholders
    // are escaped and stripped of dangerous protocols).
    $form['redirect_container']['fallback'] = [
      '#markup' => '<p>' . $this->t('If you are not automatically redirected, please <a href=":url">click here</a>.', [':url' => $redirect_url]) . '</p>',
    ];

    return $form;
  }

  /**
   * Check that a checkout redirect URL is an HTTPS address of ViaBill.
   *
   * @param string $url
   *   The redirect URL returned by the ViaBill API.
   *
   * @return bool
   *   TRUE if the customer may be sent to this URL.
   */
  protected function isAllowedRedirectUrl($url) {
    if (!is_string($url) || $url === '') {
      return FALSE;
    }

    $parts = parse_url($url);
    if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https' || empty($parts['host'])) {
      return FALSE;
    }

    $host = strtolower($parts['host']);
    $suffix = ViaBillConstants::ALLOWED_REDIRECT_HOST_SUFFIX;

    return $host === $suffix || substr($host, -strlen('.' . $suffix)) === '.' . $suffix;
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
