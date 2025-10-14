<?php

namespace Drupal\viabill_payments\Helper;

use Drupal\commerce_payment\Entity\PaymentGatewayInterface;

/**
 * A helper class for various ViaBill utility methods.
 */
class ViaBillHelper {

  /**
   * The current API protocol version.
   */
  private const API_PROTOCOL = '3.0';

  /**
   * The current API protocol version.
   */
  private const API_PLATFORM = ViaBillConstants::AFFILIATE;

  /**
   * Whether are test transactions or live payments.
   *
   * @var bool
   */
  protected $testMode;

  /**
   * Whether are authorized only, or autorized and captured.
   *
   * @var string
   */
  protected $transactionType;

  /**
   * The api secret, retrived by Viabill login/register.
   *
   * @var string
   */
  protected $apiSecret;

  /**
   * The api key, retrived by Viabill login/register.
   *
   * @var string
   */
  public $apiKey;

  /**
   * TBYB is a flag for Try Before You Buy mode.
   *
   * @var string
   */
  public $tbyb;

  /**
   * PriceTag is retrieved by Viabill login/register.
   *
   * @var string
   */
  public $priceTagScript;

  /**
   * Constructs a new ViaBillHelper.
   */
  public function __construct() {    
    $this->loadDefaultValues();  

    $payment_gateway_storage = \Drupal::entityTypeManager()->getStorage('commerce_payment_gateway');
    // 'viabill_payments' must match the Payment Gateway entity ID,
    // not just the plugin ID.
    $gateway_entity = $payment_gateway_storage->load('viabill_payments');
    if (!empty($gateway_entity)) {
      if ($gateway_entity instanceof PaymentGatewayInterface) {
        $plugin = $gateway_entity->getPlugin();
        // 'test' or 'live'
        $mode = $plugin->getMode();
        if ($mode === 'test') {
          // Test mode logic.
          $this->testMode = ViaBillConstants::TEST_MODE_ON;
        }
        else {
          // Live mode logic.
          $this->testMode = ViaBillConstants::TEST_MODE_OFF;
        }

        $configuration = $plugin->getConfiguration();
        if (!empty($configuration)) {
          $this->apiKey = $configuration['api_key'] ?? '';
          $this->apiSecret = $configuration['api_secret'] ?? '';
          $this->priceTagScript = $configuration['viabill_pricetag'] ?? '';
          $this->transactionType = $configuration['transaction_type'];
        }
      }
    }
  }

  public function loadDefaultValues() {
    $this->apiKey = '';
    $this->apiSecret = '';
    $this->priceTagScript = '';
    $this->transactionType = '';
    $this->testMode = ViaBillConstants::TEST_MODE_ON;
    $this->tbyb = ViaBillConstants::TBYB_OFF;
  }

  /**
   * Method to retrieve the test mode (true or false)
   */
  public function getTestMode() {
    return $this->testMode;
  }

  /**
   * Method to retrieve the tbyb (Try Before You Buy/1 or 0)
   */
  public function getTbyb() {
    return $this->tbyb;
  }

  /**
   * Method to retrieve the transaction type (authorize only,authorize&capture)
   */
  public function getTransactionType($transaction_id = NULL) {
    return $this->transactionType;
  }

  /**
   * Method to retrieve the ViaBill apiKey.
   */
  public function getApiKey() {
    return $this->apiKey;
  }

  /**
   * Method to retrieve the ViaBill secret key.
   */
  public function getSecretKey() {
    return $this->apiSecret;
  }

  /**
   * Example helper to format a customer's address or name, etc.
   *
   * @param array $customerData
   *   An array containing the customer's data.
   *
   * @return string
   *   JSON-encoded string for the gateway, for instance.
   */
  public function buildCustomerInfoJson(array $customerData) {
    return json_encode($customerData);
  }

  /**
   * Example helper to format a customer's address or name, etc.
   *
   * @param array $cartData
   *   An array containing the cart's data.
   *
   * @return string
   *   JSON-encoded string for the gateway, for instance.
   */
  public function buildCartInfoJson(array $cartData) {
    return json_encode($cartData);
  }

  /**
   * Format the value of the payment transaction Id.
   */
  public function formatTransactionId($order_id) {
    $transaction_id = 'vb-' . $order_id . '-' . $this->generateRandomString();
    return $transaction_id;
  }

  /**
   * Format the payment amount.
   */
  public function formatAmount($amount, $return_numeric = TRUE) {
    $formatted_number = $amount;
    if (is_numeric($amount)) {
      $formatted_number = number_format($amount, 2, '.', '');
    }
    else {
      $formatted_number = '0';
    }
    return $formatted_number;
  }

  /**
   * Format the value of the TBYB parameter.
   */
  public function formatTbyb($tbyb) {
    if (empty($tbyb)) {
      return ViaBillConstants::TBYB_OFF;
    }
    elseif (($tbyb == 'true')||($tbyb == '1')||($tbyb == 1)) {
      return ViaBillConstants::TBYB_ON;
    }
    else {
      return ViaBillConstants::TBYB_OFF;
    }
  }

  /**
   * Get the TBYB value in the proper format.
   */
  public function getFormattedTbyb() {
    return $this->formatTbyb($this->getTbyb());
  }

  /**
   * Format the Test Mode value properly.
   */
  public function formatTestMode($mode) {
    if (empty($mode)) {
      return ViaBillConstants::TEST_MODE_ON;
    }
    elseif (($mode == 'test')||($mode == 'true')||($mode == '1')||($mode == 1)) {
      return ViaBillConstants::TEST_MODE_ON;
    }
    else {
      return ViaBillConstants::TEST_MODE_OFF;
    }
  }

  /**
   * Get the Test Mode value in the proper format.
   */
  public function getFormattedTestMode() {
    return $this->formatTestMode($this->getTestMode());
  }

  /**
   * Get the ViaBill platform (affiliate) for this module.
   */
  public function getViaBillApiPlatform() {
    return self::API_PLATFORM;
  }

  /**
   * Generate a random string for the payment transaction Id.
   */
  public function generateRandomString($length = 10) {
    // Define the characters you want to include in the random string.
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';

    // Loop for the number of characters needed.
    for ($i = 0; $i < $length; $i++) {
      // Use random_int for better randomness and security.
      $index = random_int(0, $charactersLength - 1);
      $randomString .= $characters[$index];
    }

    return $randomString;
  }

  /**
   * Log the message, using Drupal's built-in logging functionality.
   */
  public function log($message, $level = 'info') {
    switch ($level) {
      case 'info':
        \Drupal::logger('viabill_payments')->info($message);
        break;

      case 'error':
        \Drupal::logger('viabill_payments')->error($message);
        break;
    }
  }

}
