<?php

namespace Drupal\viabill_payments\Helper;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Provides static methods to send requests to the ViaBill API.
 */
class ViaBillOutgoingRequests {

  /**
   * API Test mode base URL.
   */
  private const TEST_BASE_URL = 'https://secure.viabill.com';

  /**
   * API Live mode base URL.
   */
  private const PROD_BASE_URL = 'https://secure.viabill.com';

  /**
   * Sends a GET or POST request to the ViaBill endpoints.
   *
   * @param string $endPoint
   *   The endpoint path (e.g. '/api/checkout').
   * @param string $method
   *   'GET' or 'POST' (defaults to 'GET').
   * @param array $data
   *   Query or form data.
   * @param bool $testMode
   *   TRUE to use the test base URL, FALSE for live.
   * @param array $extraHeaders
   *   Extra headers to send with the request.
   * @param bool $manual
   *   (Unused in this example, preserved from original).
   *
   * @return array|false
   *   An array containing request and response details, or FALSE on exception.
   */
  public static function request(string $endPoint, string $method = 'GET', array $data = [], bool $testMode = FALSE, array $extraHeaders = [], bool $manual = FALSE) {
    $baseUrl = $testMode ? self::TEST_BASE_URL : self::PROD_BASE_URL;
    $requestUrl = self::buildRequestUrl($baseUrl, $endPoint);

    $helper = new ViaBillHelper();

    // Merge default and extra headers.
    $headers = [
      'Accept'           => '*/*',
      'Accept-Encoding'  => 'gzip, deflate',
      'Cache-Control'    => 'no-cache',
      'Connection'       => 'keep-alive',
      'Referer'          => $requestUrl,
    ];
    $headers = array_merge($headers, $extraHeaders);

    // Use Drupal's default Guzzle client.
    $client = \Drupal::httpClient();

    // Prepare response container.
    $response = NULL;

    try {
      if (strtoupper($method) === 'GET') {
        $response = $client->request('GET', $requestUrl, [
          'headers' => $headers,
          'query'   => $data,
        ] + self::transportOptions());
      }
      elseif (strtoupper($method) === 'POST') {
        $response = $client->request('POST', $requestUrl, [
          'headers'     => $headers,
          'form_params' => $data,
        ] + self::transportOptions());
      }
      else {
        // Unsupported method from your original code viewpoint.
        // You could extend this for PUT/DELETE if needed.
        return FALSE;
      }
    }
    catch (ClientException $e) {
      // Log or handle the exception. Return false to indicate failure.
      $helper->log('ClientException: ' . $e->getMessage(), 'error');
      return FALSE;
    }
    catch (RequestException $e) {
      $helper->log('RequestException: ' . $e->getMessage(), 'error');
      return FALSE;
    }
    catch (GuzzleException $e) {
      // E.g. connection failures and timeouts (ConnectException).
      $helper->log('Request failed: ' . $e->getMessage(), 'error');
      return FALSE;
    }

    // Build the output array.
    $output = [
      'request' => [
        'url'     => $requestUrl,
        'headers' => $headers,
        'params'  => $data,
        'method'  => $method,
      ],
      'status'   => $response->getStatusCode(),
      'response' => [
        'headers' => $response->getHeaders(),
        'body'    => (string) $response->getBody(),
      ],
    ];

    // Handle potential 3XX redirect scenario.
    $status_code = $response->getStatusCode();
    if ($status_code >= 300 && $status_code < 400) {
      // If needed, set or adjust Referer for subsequent requests.
      $output['response']['headers']['Referer'] = $requestUrl;
    }

    return $output;
  }

  /**
   * Sends a POST request without following redirects.
   *
   * @param string $endPoint
   *   The endpoint path.
   * @param string $method
   *   Typically 'POST'.
   * @param array $data
   *   The form data to send.
   * @param bool $testMode
   *   Whether to use the test or live base URL.
   *
   * @return array|false
   *   The response array, or FALSE on failure.
   */
  public static function requestWithoutRedirect(string $endPoint, string $method = 'POST', array $data = [], bool $testMode = FALSE) {
    $baseUrl = $testMode ? self::TEST_BASE_URL : self::PROD_BASE_URL;
    $requestUrl = self::buildRequestUrl($baseUrl, $endPoint);

    $helper = new ViaBillHelper();

    $headers = [
      'Accept'           => '*/*',
      'Accept-Encoding'  => 'gzip, deflate',
      'Cache-Control'    => 'no-cache',
      'Connection'       => 'keep-alive',
      'Referer'          => $requestUrl,
    ];

    // Drupal's Guzzle client.
    $client = \Drupal::httpClient();

    try {
      $response = $client->request($method, $requestUrl, [
        'allow_redirects' => FALSE,
        'headers'         => $headers,
        'form_params'     => $data,
      ] + self::transportOptions());
    }
    catch (BadResponseException $e) {
      // 4XX/5XX: keep ViaBill's full response, Guzzle's message truncates it.
      $response = $e->getResponse();
      $helper->log('Checkout request rejected with HTTP ' . $response->getStatusCode() . ': ' . (string) $response->getBody(), 'error');
    }
    catch (RequestException $e) {
      $helper->log('RequestException: ' . $e->getMessage(), 'error');
      return FALSE;
    }
    catch (GuzzleException $e) {
      // E.g. connection failures and timeouts (ConnectException).
      $helper->log('Request failed: ' . $e->getMessage(), 'error');
      return FALSE;
    }

    $output = [
      'request' => [
        'url'     => $requestUrl,
        'headers' => $headers,
        'params'  => $data,
        'method'  => $method,
      ],
      'status'   => $response->getStatusCode(),
      'response' => [
        'headers' => $response->getHeaders(),
        'body'    => (string) $response->getBody(),
      ],
    ];

    // Check for 3XX redirects.
    $status_code = $response->getStatusCode();
    if ($status_code >= 300 && $status_code < 400) {
      $output['response']['headers']['Referer'] = $requestUrl;
    }

    return $output;
  }

  /**
   * Transport options for every ViaBill API request.
   *
   * The ViaBill API is called during checkout, so a request must never hang,
   * and the TLS certificate is always verified.
   *
   * @return array
   *   Guzzle request options.
   */
  protected static function transportOptions(): array {
    return [
      'connect_timeout' => 10,
      'timeout' => 30,
      'verify' => TRUE,
    ];
  }

  /**
   * Constructs a complete request URL from base URL and endpoint.
   *
   * @param string $baseUrl
   *   The base URL, e.g. https://secure.viabill.com.
   * @param string $endPoint
   *   The endpoint, e.g. /api/checkout.
   *
   * @return string
   *   The full URL, e.g. https://secure.viabill.com/api/checkout
   */
  protected static function buildRequestUrl(string $baseUrl = '', string $endPoint = ''): string {
    $baseUrl = rtrim($baseUrl, '/');
    $endPoint = ltrim($endPoint, '/');
    return $baseUrl . '/' . $endPoint;
  }

}
