<?php

namespace Drupal\viabill_payments\Helper;

/**
 * A main class for communication with the ViaBill gateway.
 */
class ViaBillGateway {
  /**
   * The current API protocol version.
   */
  private const API_PROTOCOL = '3.0';

  /**
   * Whether are test transactions or live payments.
   *
   * @var bool
   */
  protected $testMode;

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
  protected $apiKey;

  /**
   * The helper utility object.
   *
   * @var ViabillHelper
   */
  public $helper;

  /**
   * ViaBill constructor.
   */
  public function __construct() {
    $this->helper = new ViaBillHelper();

    $this->testMode = $this->helper->getTestMode();
    $this->apiKey = $this->helper->getApiKey();
    $this->apiSecret = $this->helper->getSecretKey();
  }

  /**
   * Login merchant into their ViaBill account.
   *
   * @param array $data
   *   The login data.
   * @param array $headers
   *   The request method header (optional)
   * @param bool $verbose
   *   Whether you want a detailed output, or just the important information.
   *
   * @return array|bool
   *   Returns an array with the ViaBill account essential parameters
   */
  public function loginViabillUser(array $data = [], array $headers = [], bool $verbose = FALSE) {
    $response_str = $this->getRequestData($data, $headers, $verbose, 'login');

    if (empty($response_str)) {
      $return['error'] = "login returned an empty response!";
    }
    else {
      $response = json_decode($response_str, TRUE);
      if (isset($response['errors'])) {
        $return['error'] = $response['errors'][0]['error'];
      }
      else {
        // Do some sanity check
        // ...
        foreach ($response as $key => $value) {
          $return[$key] = $response[$key];
        }
      }
    }

    return $return;
  }

  /**
   * Register and create a new ViaBill account for the merchant.
   *
   * @param array $data
   *   The register data.
   * @param array $headers
   *   The request method header (optional)
   * @param bool $verbose
   *   Whether you want a detailed output, or just the important information.
   *
   * @return array|bool
   *   Returns an array with the ViaBill account essential parameters
   */
  public function registerViabillUser(array $data = [], array $headers = [], bool $verbose = FALSE) {
    $response_str = $this->getRequestData($data, $headers, $verbose, 'registration');

    if (empty($response_str)) {
      $return['error'] = "registration returned an empty response!";
    }
    else {
      $response = json_decode($response_str, TRUE);
      if (isset($response['errors'])) {
        $return['error'] = $response['errors'][0]['error'];
      }
      else {
        // Do some sanity check
        // ...
        foreach ($response as $key => $value) {
          $return[$key] = $response[$key];
        }
      }
    }

    return $return;
  }

  /**
   * Send the checkout request to the ViaBill gateway.
   */
  public function checkout(array $data = [], array $headers = [], $shop = NULL) : array {
    $redirect_url = NULL;

    $data['protocol'] = self::API_PROTOCOL;

    $response = $this->getRequestData($data, [], TRUE, 'checkout', FALSE);

    if (empty($response)) {
      // This should mever happen.
      $message = 'The checkout request to the ViaBill payment gateway could not be completed.';
      $status = 400;
      return [
        'redirect_url' => $redirect_url,
        'status' => $status,
        'message' => $message,
        'input_data' => $data,
      ];
    }
    else {
      $status = intval($response['status']);
      if (($status == 301) || ($status == 302)) {
        $redirect_url = $response['response']['headers']['Location'][0];
      }

      if (empty($redirect_url)) {
        return [
          'error' => 'Request already made',
        ];
      }

      $message = $this->getApiEndPointMessage($status, 'checkout');

      return [
        'redirect_url' => $redirect_url,
        'status' => $status,
        'message' => $message,
        'input_data' => $data,
      ];

    }
  }

  /**
   * Send the capture payment request to the ViaBill Payment gateway.
   *
   * @param array $data
   *   The capture data.
   * @param array $headers
   *   The request method header (optional)
   * @param bool $verbose
   *   Whether you want a detailed output, or just the important information.
   *
   * @return array|bool
   *   Returns an array with the outcome of the operation
   */
  public function captureTransaction(array $data = [], array $headers = [], bool $verbose = FALSE) {
    return $this->getRequestDataTransaction($data, $headers, $verbose, 'capture_transaction');
  }

  /**
   * Send the refund payment request to the ViaBill gateway.
   */
  public function refundTransaction(array $data = [], array $headers = [], bool $verbose = FALSE) {
    return $this->getRequestDataTransaction($data, $headers, $verbose, 'refund_transaction');
  }

  /**
   * Send the cancel payment request to the ViaBill gateway.
   */
  public function cancelTransaction(array $data = [], array $headers = [], bool $verbose = FALSE) {
    return $this->getRequestDataTransaction($data, $headers, $verbose, 'cancel_transaction');
  }

  /**
   * Return the myViaBill URL.
   *
   * @param array $data
   *   The MyViaBill data.
   * @param array $headers
   *   The request method header (optional)
   * @param bool $verbose
   *   Whether you want a detailed output, or just the important information.
   *
   * @return array|bool
   *   Returns an array with the outcome of the operation
   */
  public function myViabill(array $data = [], array $headers = [], bool $verbose = FALSE) {
    $return = [
      'error' => NULL,
      'url' => NULL,
    ];

    $response_str = $this->getRequestData($data, $headers, $verbose, 'myviabill');

    if (empty($response_str)) {
      $return['error'] = "myViabill returned an empty response!";
      return FALSE;
    }
    else {
      $response = json_decode($response_str, TRUE);
      if (isset($response['errors'])) {
        $return['error'] = $response['errors'][0]['error'];
      }
      elseif (isset($response['url'])) {
        $return['url'] = $response['url'];
      }
    }

    return $return;
  }

  /**
   * Returns an array with notifications from the ViaBill server (if any)
   *
   * @param array $data
   *   The notifications data.
   * @param array $headers
   *   The request method header (optional)
   * @param bool $verbose
   *   Whether you want a detailed output, or just the important information.
   *
   * @return array|bool
   *   Returns an array with the outcome of the operation
   */
  public function notifications(array $data = [], array $headers = [], bool $verbose = FALSE) {
    $return = [
      'error' => NULL,
      'messages' => NULL,
    ];

    $response_str = $this->getRequestData($data, $headers, $verbose, 'notifications');

    if (empty($response_str)) {
      $return['error'] = "notifications returned an empty response!";
      return FALSE;
    }
    else {
      $response = json_decode($response_str, TRUE);
      if (isset($response['errors'])) {
        $return['error'] = $response['errors'][0]['error'];
      }
      elseif (isset($response['messages'])) {
        $return['messages'] = $response['messages'];
      }
    }

    return $return;
  }

  /**
   * Utility function to request data from the ViaBill Gateway.
   *
   * @param array $data
   *   The request data.
   * @param array $headers
   *   The request method header (optional)
   * @param bool $verbose
   *   Whether you want a detailed output, or just the important information.
   * @param string $type
   *   Type can be either POST or GET.
   * @param bool $force
   *   Whether or not to force the request.
   *
   * @return array|bool
   *   Returns an array with the outcome of the operation
   */
  private function getRequestData(
    array $data = [],
    array $headers = [],
    bool $verbose = FALSE,
    string $type = '',
    bool $force = FALSE,
  ) {
    $request = $this->getEndPointData($type, $data);

    if ($request) {
      if ($type == 'checkout') {
        $response = ViaBillOutgoingRequests::requestWithoutRedirect($request['endpoint'], $request['method'], $request['data'], $this->testMode);
      }
      else {
        $response = ViaBillOutgoingRequests::request($request['endpoint'], $request['method'], $request['data'], $this->testMode, $headers, FALSE);
      }

      if ($response === FALSE) {
        return FALSE;
      }

      return $verbose ? $response : $response['response']['body'];
    }
    return FALSE;
  }

  /**
   * Utility function to return information about the requested API endpoint.
   *
   * @param string $endPoint
   *   The end point name for which to retrieve information.
   * @param array $data
   *   The end point data (optional)
   *
   * @return array|bool
   *   Returns an array with information about the end point.
   */
  private function getEndPointData(string $endPoint = '', array $data = []) {
    $ed = ViaBillServices::getApiEndPoint($endPoint);
    if (!empty($ed)) {
      $endPoint = $ed['endpoint'];
      $method = $ed['method'];
      $requestData = [];

      foreach ($ed['required_fields'] as $field) {
        $isTest = ($field === 'test');
        // Check for signature/md5check fields.
        if (array_key_exists($field, $ed)) {
          $format = $ed[$field];
          // Parse the format to generate a signature if one is required.
          try {
            $format = $this->parseFormat($format, $data);
          }
          catch (\Exception $e) {
            $error_msg = 'Error parsing format: ' . $e->getMessage();
            $this->helper->log($error_msg, 'error');
            return FALSE;
          }
          $requestData[$field] = md5($format);
          // Process the remaining required fields.
        }
        elseif (array_key_exists($field, $data)) {
          // Make sure the test field is set to true
          // if test mode is enabled globally
          // or for this specific request.
          if ($isTest) {
            $requestData[$field] = $data[$field];
          }
          elseif ($field === 'country') {
            $requestData[$field] = ($this->validIso($data[$field]) ? strtoupper(trim($data[$field])) : $data[$field]);
          }
          else {
            $requestData[$field] = $data[$field];
          }

        }
        elseif ($field === 'protocol') {
          $requestData[$field] = self::API_PROTOCOL;
        }
        elseif ($isTest) {
          $requestData[$field] = $this->testMode;
        }
        else {
          $error_msg = 'Data is missing required field: ' . $field;
          $this->helper->log($error_msg, 'error');
          return FALSE;
        }
      }

      foreach ($ed['optional_fields'] as $field) {
        if (array_key_exists($field, $data)) {
          $requestData[$field] = $data[$field];
        }
      }
      $requestData = $this->prepareData($requestData);

      return [
        'endpoint' => $endPoint,
        'method' => $method,
        'data' => $requestData,
      ];
    }
    return FALSE;
  }

  /**
   * Utility function to verify the signature found in the callback request.
   *
   * @param array $data
   *   An associative array containing the callback data.
   * @param string $format
   *   An optional custom format to use during verification of the signature.
   * @param bool $silent
   *   If true, returns false on signature mismatch
   *   instead of throwing an exception.
   *
   * @return bool
   *   Returns true if the calculated signature matches the signature contained
   *   in the data or false if it does not match.
   */
  public function verifyCallbackSignature(array $data, string $format = '', bool $silent = TRUE): bool {
    $format = trim($format);
    // Set the default format if no optional format is specified.
    if (empty($format)) {
      $format = '{transaction}#{orderNumber}#{amount}#{currency}#{status}#{time}#{secret}';
    }

    if (!array_key_exists('signature', $data)) {
      throw new \Exception(__METHOD__ . ': Callback data is missing a "signature" key.');
    }
    if ($this->apiSecret === NULL) {
      throw new Exception('You must set the apiSecret with ' . __CLASS__ . '::apiSecret() before calling ' . __METHOD__ . '().');
    }
    // Retrieve the expected signature from the data array.
    $sig = $data['signature'];
    // Remove the expected signature from the data array.
    unset($data['signature']);
    // Parse the format and data into a populated string.
    $format = $this->parseFormat($format, $data);
    // Calculate the MD5 checksum of the string.
    $calculated = md5($format);
    if ($calculated === $sig) {
      return TRUE;
    }
    if ($silent) {
      return FALSE;
    }
    throw new \Exception(__METHOD__ . ':Expected signature [' . $sig . '] but got signature [' . $calculated . '].');
  }

  /**
   * Utility function to verify the country's ISO code.
   *
   * @param string $country
   *   A two character country code to check against the ISO codes array.
   * @param bool $silent
   *   If true, returns false if country code is not a valid ISO 3166-1
   *   alpha 2 code, instead of throwing an exception.
   *
   * @return bool
   *   Returns true if the specified country code is a valid ISO 3166-1
   *   alpha 2 code, or false if not.
   *
   * @throws Exception
   *   When specified value is not a valid ISO 3166-1 alpha 2 country code
   *   and $silent=false.
   */
  public function validIso($country = '', $silent = TRUE): bool {
    $country = strtoupper(trim($country));
    // Return false if country code is too long, or too short.
    if (strlen($country) !== 2) {
      return FALSE;
    }

    if (in_array($country, ViaBillConstants::ISO_CODES, FALSE)) {
      return TRUE;
    }
    if ($silent) {
      return FALSE;
    }
    $message = sprintf('%s: Value %s is not a valid ISO 3166-1 alpha 2 Country Code.', __METHOD__, $country);
    throw new \Exception($message);
  }

  /**
   * Utility function to parse the signature format of a ViaBill request.
   *
   * @param string $format
   *   The format string.
   * @param array $data
   *   The data array.
   *
   * @return mixed
   *   Returns a text with the processed format string.
   *
   * @throws Exception
   */
  protected function parseFormat($format, &$data) {
    preg_match_all('/(?:\{([^\{\}#]+)\}#?)/', $format, $formatFields);
    if (empty($formatFields)) {
      throw new \Exception(__METHOD__ . ': Invalid format string - Format does not contain any fields.');
    }
    foreach ($formatFields[1] as $key) {
      if (array_key_exists($key, $data)) {
        $val = $data[$key];
        if ($key === 'country') {
          $val = ($this->validIso($val) ? strtoupper(trim($val)) : $val);
        }
        $format = str_replace('{' . $key . '}', $val, $format);
      }
      elseif ($key === 'secret') {
        if ($this->apiSecret === NULL) {
          throw new \Exception('You must set the apiSecret with ' . __CLASS__ . '::apiSecret() before calling ' . __METHOD__ . '().');
        }
        $format = str_replace('{' . $key . '}', $this->apiSecret, $format);
      }
      elseif (in_array($key, ['key', 'apikey', 'apiKey'])) {
        if ($this->apiKey === NULL) {
          throw new \Exception('You must set the apiKey with ' . __CLASS__ . '::apiSecret() before calling ' . __METHOD__ . '().');
        }
        $format = str_replace('{' . $key . '}', $this->apiKey, $format);

      }
      elseif ($key === 'protocol') {
        $format = str_replace('{' . $key . '}', self::API_PROTOCOL, $format);
      }
      elseif ($key === 'test') {
        $format = str_replace('{' . $key . '}', $this->testMode, $format);
      }
      else {
        throw new \Exception('Data is missing a required signature field; ' . $key);
      }
    }
    return trim($format);
  }

  /**
   * Utility function to prepare the request data.
   *
   * @param mixed &$input
   *   An array or string containing boolean values.
   *
   * @return mixed
   *   Works in-place, but can return the converted input to a new variable
   */
  protected function prepareData(&$input) {
    $checkVal = static function ($value) {
      if (is_bool($value)) {
        $value = ($value ? 'true' : 'false');
      }
      return $value;
    };
    if (is_array($input)) {
      foreach ($input as $key => $value) {
        if (is_array($value)) {
          $input[$key] = $this->prepareData($value);
        }
        else {
          $input[$key] = $checkVal($value);
        }
      }
    }
    else {
      $input = $checkVal($input);
    }
    return $input;
  }

  /**
   * Get the associated text message for the given end point.
   */
  public function getApiEndPointMessage($status, $endPoint) {
    $message = '';
    $ed = ViaBillServices::getApiEndPoint($endPoint);
    if (!empty($ed)) {
      if (isset($ed['status_codes'])) {
        $status_codes = $ed['status_codes'];
        if (isset($status_codes[(int) $status])) {
          $message = $status_codes[(int) $status];
        }
      }
    }
    return $message;
  }

  /**
   * Utility function to make a request to the ViaBill gateway.
   *
   * @param array $data
   *   An associative array containing the data that was sent to the ViaBill.
   * @param array $headers
   *   The request method header (optional)
   * @param bool $verbose
   *   Whether you want a detailed output, or just the important information.
   * @param string $type
   *   Type can be either POST or GET.
   *
   * @return array|bool
   *   The data with the result of the request.
   */
  private function getRequestDataTransaction(
    array $data = [],
    array $headers = [],
    bool $verbose = FALSE,
    string $type = '',
  ) {
    $force = $this->isForceRequest($data);
    $response = $this->getRequestData($data, $headers, TRUE, $type, $force);

    if ($response && !$verbose) {
      if ($this->checkResponseStatus($response)) {
        return TRUE;
      }
      return $response['response']['body'];
    }
    return $response;
  }

  /**
   * Utility function to check the HTTP status of the response.
   *
   * @param array $response
   *   The full response array after a ViaBill request.
   *
   * @return bool
   *   A boolean value for the outcome of the request
   */
  private function checkResponseStatus(array $response): bool {
    if (filter_var(
          $response['status'], FILTER_VALIDATE_INT,
          ['options' => ['min_range' => 200, 'max_range' => 299]]
      )
      ) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Utility function to check if we should force the request.
   *
   * @param array $data
   *   An associative array containing the request data.
   *
   * @return bool
   *   A boolean value indicating the outcome.
   */
  private function isForceRequest(array $data): bool {
    return !array_key_exists('apikey', $data);
  }

}
