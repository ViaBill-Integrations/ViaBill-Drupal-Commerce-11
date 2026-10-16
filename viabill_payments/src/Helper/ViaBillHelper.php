<?php

namespace Drupal\viabill_payments\Helper;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\Url;

/**
 * A helper class for various ViaBill utility methods.
 */
class ViaBillHelper { 

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

    $gateway_entity = self::loadViaBillGateway();
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

  /**
   * Load the ViaBill payment gateway entity.
   *
   * Prefers the gateway with the machine name 'viabill_payments'; otherwise
   * uses the first gateway that runs the viabill_payments plugin, so the
   * credentials are found whatever the gateway was named.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentGatewayInterface|null
   *   The gateway entity, or NULL if none exists.
   */
  public static function loadViaBillGateway() {
    $storage = \Drupal::entityTypeManager()->getStorage('commerce_payment_gateway');

    $gateway = $storage->load('viabill_payments');
    if ($gateway instanceof PaymentGatewayInterface && $gateway->getPluginId() === 'viabill_payments') {
      return $gateway;
    }

    $gateways = $storage->loadByProperties(['plugin' => 'viabill_payments']);
    $gateway = reset($gateways);

    return $gateway instanceof PaymentGatewayInterface ? $gateway : NULL;
  }

  /**
   * Get the API secret stored outside of the payment gateway.
   *
   * It is kept in the State API (not in configuration), so it is never part
   * of a configuration export.
   *
   * @return string
   *   The stored API secret, or an empty string.
   */
  public static function getStoredSecret() {
    return (string) \Drupal::state()->get(ViaBillConstants::STATE_API_SECRET, '');
  }

  /**
   * Store the API secret outside of the payment gateway (State API).
   *
   * @param string $secret
   *   The API secret.
   */
  public static function setStoredSecret($secret) {
    \Drupal::state()->set(ViaBillConstants::STATE_API_SECRET, (string) $secret);
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
   * Build the order number sent to ViaBill for a new checkout attempt.
   *
   * The value is based on the order number the merchant sees in the orders
   * list, so customer support can match the two. Commerce normally assigns
   * that number when the order is placed (after the payment), and keeps a
   * number that is already set, so it is reserved here before the redirect.
   *
   * ViaBill refuses an order number it has already seen, so a retry of the
   * same order gets a "-2", "-3", ... suffix.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order being paid. It is changed but not saved.
   * @param string $prefix
   *   The optional prefix configured on the payment gateway.
   *
   * @return string
   *   The order number for the checkout request, e.g. "VIV-1000" or
   *   "VIV-1000-2".
   */
  public function buildCheckoutOrderNumber(OrderInterface $order, $prefix = '') {
    $order_number = (string) $order->getOrderNumber();
    if ($order_number === '') {
      $order_type = \Drupal::entityTypeManager()->getStorage('commerce_order_type')->load($order->bundle());
      $number_pattern = $order_type ? $order_type->getNumberPattern() : NULL;
      $order_number = $number_pattern ? (string) $number_pattern->getPlugin()->generate($order) : (string) $order->id();
      $order->setOrderNumber($order_number);
    }

    $attempt = (int) $order->getData('viabill_checkout_attempts', 0) + 1;
    $order->setData('viabill_checkout_attempts', $attempt);

    $checkout_order_number = trim((string) $prefix) . $order_number . ($attempt > 1 ? '-' . $attempt : '');
    $order->setData('viabill_order_number', $checkout_order_number);

    return $checkout_order_number;
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
      return ViaBillConstants::TEST_MODE_OFF;
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
   * Get the installed module version, as declared in viabill_payments.info.yml.
   *
   * @return string
   *   The module version, or an empty string if it is not declared.
   */
  public static function getModuleVersion() {
    $info = \Drupal::service('extension.list.module')->getExtensionInfo('viabill_payments');
    return isset($info['version']) ? (string) $info['version'] : '';
  }

  /**
   * Build the ViaBill online signup form URL for this shop and module version.
   *
   * @return string
   *   The signup URL.
   */
  public static function getSignupUrl() {
    $shop_url = Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();

    $params = [
      'utm_source' => 'drupal',
      'utm_medium' => 'ecommerce_extension',
      'utm_campaign' => 'merchant_signup',
      'utm_content' => 'settings_page',
      'shop_url' => rtrim($shop_url, '/'),
      'integration_version' => self::getModuleVersion(),
    ];

    return 'https://portal.viabill.com/signup?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
  }

  /**
   * Build the note pointing merchants without credentials to the signup form.
   *
   * @return array
   *   A render array, to be shown below the account credentials fields.
   */
  public static function buildSignupNote() {
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['messages', 'messages--status', 'viabill-signup-note'],
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'strong',
        '#value' => t('No ViaBill account credentials yet?'),
      ],
      'text' => [
        '#plain_text' => ' ' . t('Fill in the ViaBill online signup form to get your API key, API secret and PriceTag script.') . ' ',
      ],
      'link' => [
        '#type' => 'link',
        '#title' => t('Go to the ViaBill signup form'),
        '#url' => Url::fromUri(self::getSignupUrl(), [
          'attributes' => [
            'target' => '_blank',
            'rel' => 'noopener noreferrer',
          ],
        ]),
      ],
    ];
  }

  /**
   * Mask API credentials in text that is logged or shown.
   *
   * Request URLs, request data and ViaBill error responses can all contain
   * the API key, the secret or a signature, so they are masked here before
   * anything reaches the log. The secret and signatures are removed
   * completely; an API key keeps its first and last characters (see
   * ::maskApiKey()), so support can tell which key was used.
   *
   * @param string $text
   *   The text to clean.
   *
   * @return string
   *   The text with every credential masked.
   */
  public static function redact($text) {
    $text = (string) $text;
    $mask = '[REDACTED]';

    // The configured credentials themselves, wherever they appear.
    $keys = [];
    $secrets = [];
    try {
      $keys[] = (string) \Drupal::config('viabill_payments.settings')->get('api_key');
      $secrets[] = self::getStoredSecret();
      $gateways = \Drupal::entityTypeManager()->getStorage('commerce_payment_gateway')->loadByProperties(['plugin' => 'viabill_payments']);
      foreach ($gateways as $gateway) {
        $configuration = $gateway->getPlugin()->getConfiguration();
        $keys[] = (string) ($configuration['api_key'] ?? '');
        $secrets[] = (string) ($configuration['api_secret'] ?? '');
      }
    }
    catch (\Throwable $e) {
      // Masking must never stop a message from being logged; the patterns
      // below still apply.
    }
    $long_enough = fn($value) => strlen($value) >= 8;
    foreach (array_unique(array_filter($secrets, $long_enough)) as $value) {
      $text = str_replace($value, $mask, $text);
    }
    foreach (array_unique(array_filter($keys, $long_enough)) as $value) {
      $text = str_replace($value, self::maskApiKey($value), $text);
    }

    $key_fields = 'key|apikey|api_key|apiKey';
    $secret_fields = 'secret|api_secret|apiSecret|signature|sha256check';

    // URL query strings and form data, e.g. "key=...&signature=...".
    $text = preg_replace_callback('/\b(' . $key_fields . ')=([^&\s"\'`<>]+)/i', fn($m) => $m[1] . '=' . self::maskApiKey($m[2]), $text);
    $text = preg_replace('/\b(' . $secret_fields . ')=[^&\s"\'`<>]+/i', '$1=' . $mask, $text);

    // JSON fields, e.g. "apikey":"...".
    $text = preg_replace_callback('/"(' . $key_fields . ')"\s*:\s*"([^"]*)"/i', fn($m) => '"' . $m[1] . '":"' . self::maskApiKey($m[2]) . '"', $text);
    $text = preg_replace('/"(' . $secret_fields . ')"\s*:\s*"[^"]*"/i', '"$1":"' . $mask . '"', $text);

    // ViaBill API keys are JWTs; also catch them when they are truncated.
    $text = preg_replace_callback('/eyJ[A-Za-z0-9_\-]{6,}(\.[A-Za-z0-9_\-]*){0,2}/', fn($m) => self::maskApiKey($m[0]), $text);

    return $text;
  }

  /**
   * Mask an API key, keeping only its first and last four characters.
   *
   * @param string $key
   *   The API key.
   *
   * @return string
   *   The masked key, e.g. "eyJh****B1A8". Short values are fully masked.
   */
  public static function maskApiKey($key) {
    $key = (string) $key;

    // Already masked.
    if ($key === '[REDACTED]' || strpos($key, '****') !== FALSE) {
      return $key;
    }

    // Too short to show anything without revealing most of it.
    if (strlen($key) < 16) {
      return '[REDACTED]';
    }

    return substr($key, 0, 4) . '****' . substr($key, -4);
  }

  /**
   * Log the message, using Drupal's built-in logging functionality.
   *
   * Credentials are always masked first, see ::redact().
   */
  public function log($message, $level = 'info') {
    $context = ['@message' => self::redact($message)];

    switch ($level) {
      case 'info':
        \Drupal::logger('viabill_payments')->info('@message', $context);
        break;

      case 'error':
        \Drupal::logger('viabill_payments')->error('@message', $context);
        break;

      case 'warning':
        \Drupal::logger('viabill_payments')->warning('@message', $context);
        break;
    }
  }

}
