<?php

namespace Drupal\viabill_payments\Helper;

/**
 * Class to provide API end points.
 */
class ViaBillServices {
  const ADDON_NAME = ViaBillConstants::AFFILIATE;

  // These endpoints contain references to the addon name.
  const API_END_POINTS = [
    'login'               => [
      'endpoint'        => '/api/addon/ADDON_NAME/login',
      'method'          => 'POST',
      'required_fields' => ['email', 'password'],
      'optional_fields' => [],
      'status_codes'    => [
        200 => 'messages.viabillApiMessages.successfulRequest',
        400 => 'messages.viabillApiMessages.requestError',
        500 => 'messages.viabillApiMessages.apiServerError',
      ],
      'signature'       => NULL,
    ],
    'registration'        => [
      'endpoint'        => '/api/addon/ADDON_NAME/register',
      'method'          => 'POST',
      'required_fields' => ['email', 'name', 'url', 'country'],
      'optional_fields' => ['taxId', 'affiliate', 'additionalInfo'],
      'status_codes'    => [
        200 => 'messages.viabillApiMessages.successfulRequest',
        400 => 'messages.viabillApiMessages.requestError',
        500 => 'messages.viabillApiMessages.apiServerError',
      ],
      'signature'       => NULL,
    ],
    'myviabill'           => [
      'endpoint'        => '/api/addon/ADDON_NAME/myviabill',
      'method'          => 'GET',
      'required_fields' => ['key', 'signature'],
      'optional_fields' => [],
      'status_codes'    => [
        200 => 'messages.viabillApiMessages.successfulRequest',
        400 => 'messages.viabillApiMessages.requestError',
        500 => 'messages.viabillApiMessages.apiServerError',
      ],
      'signature'       => '{key}#{secret}',
    ],
    'notifications'       => [
      'endpoint'        => '/api/addon/ADDON_NAME/notifications',
      'method'          => 'GET',
      'required_fields' => ['key', 'signature'],
      'optional_fields' => ['platform', 'platform_ver', 'module_ver'],
      'status_codes'    => [
        200 => 'messages.viabillApiMessages.successfulRequest',
        400 => 'messages.viabillApiMessages.requestError',
        500 => 'messages.viabillApiMessages.apiServerError',
      ],
      'signature'       => '{key}#{secret}',
    ],
    'checkout'            => [
      'endpoint'        => '/api/checkout-authorize/addon/ADDON_NAME',
      'method'          => 'POST',
      'required_fields' => [
        'protocol',
        'apikey',
        'transaction',
        'order_number',
        'amount',
        'currency',
        'success_url',
        'cancel_url',
        'callback_url',
        'test',
        'md5check',
      ],
      'optional_fields' => ['customParams', 'cartParams'],
      'md5check'        => '{apikey}#{amount}#{currency}#{transaction}#{order_number}#{success_url}#{cancel_url}#{secret}',
      'status_codes'    => [
        200 => 'messages.viabillApiMessages.successfulRequest',
        204 => 'messages.viabillApiMessages.noContentResponse',
        301 => 'messages.viabillApiMessages.permanentRedirect',
        302 => 'messages.viabillApiMessages.temporaryRedirect',
        400 => 'messages.viabillApiMessages.requestError',
        403 => 'messages.viabillApiMessages.debtorCreditError',
        409 => 'messages.viabillApiMessages.requestFrequencyError',
        500 => 'messages.viabillApiMessages.apiServerError',
      ],
    ],
    'capture_transaction' => [
      'endpoint'        => '/api/transaction/capture',
      'method'          => 'POST',
      'required_fields' => ['id', 'apikey', 'signature', 'amount', 'currency'],
      'optional_fields' => [],
      'status_codes'    => [
        200 => 'messages.viabillApiMessages.successfulRequest',
        204 => 'messages.viabillApiMessages.noContentResponse',
        400 => 'messages.viabillApiMessages.requestError',
        403 => 'messages.viabillApiMessages.debtorCreditError',
        409 => 'messages.viabillApiMessages.requestFrequencyError',
        500 => 'messages.viabillApiMessages.apiServerError',
      ],
      'signature'       => '{id}#{apikey}#{amount}#{currency}#{secret}',
    ],
    'cancel_transaction'  => [
      'endpoint'        => '/api/transaction/cancel',
      'method'          => 'POST',
      'required_fields' => ['id', 'apikey', 'signature'],
      'optional_fields' => [],
      'status_codes'    => [
        200 => 'messages.viabillApiMessages.successfulRequest',
        204 => 'messages.viabillApiMessages.noContentResponse',
        400 => 'messages.viabillApiMessages.requestError',
        500 => 'messages.viabillApiMessages.apiServerError',
      ],
      'signature'       => '{id}#{apikey}#{secret}',
    ],
    'refund_transaction'  => [
      'endpoint'        => '/api/transaction/refund',
      'method'          => 'POST',
      'required_fields' => ['id', 'apikey', 'signature', 'amount', 'currency'],
      'optional_fields' => [],
      'status_codes'    => [
        200 => 'messages.viabillApiMessages.successfulRequest',
        204 => 'messages.viabillApiMessages.noContentResponse',
        400 => 'messages.viabillApiMessages.requestError',
        403 => 'messages.viabillApiMessages.spxAccountInactive',
        500 => 'messages.viabillApiMessages.apiServerError',
      ],
      'signature'       => '{id}#{apikey}#{amount}#{currency}#{secret}',
    ],
    'renew_transaction'   => [
      'endpoint'        => '/api/transaction/renew',
      'method'          => 'POST',
      'required_fields' => ['id', 'apikey', 'signature'],
      'optional_fields' => [],
      'status_codes'    => [
        200 => 'messages.viabillApiMessages.successfulRequest',
        204 => 'messages.viabillApiMessages.noContentResponse',
        400 => 'messages.viabillApiMessages.requestError',
        403 => 'messages.viabillApiMessages.debtorCreditError',
        500 => 'messages.viabillApiMessages.apiServerError',
      ],
      'signature'       => '{id}#{apikey}#{secret}',
    ],
    'transaction_status'  => [
      'endpoint'        => '/api/transaction/status',
      'method'          => 'GET',
      'required_fields' => ['id', 'apikey', 'signature'],
      'optional_fields' => [],
      'status_codes'    => [
        200 => 'messages.viabillApiMessages.successfulRequest',
        204 => 'messages.viabillApiMessages.noContentResponse',
        400 => 'messages.viabillApiMessages.requestError',
        500 => 'messages.viabillApiMessages.apiServerError',
      ],
      'signature'       => '{id}#{apikey}#{secret}',
    ],
  ];

  /**
   * Get the API end point.
   */
  public static function getApiEndPoint($end_point) {
    // Check if the default ADDON name is still used.
    $addon_name = self::ADDON_NAME;

    if (isset(self::API_END_POINTS[$end_point])) {
      $end_point_settings = self::API_END_POINTS[$end_point];
      $end_point_settings['endpoint'] = str_replace(
            'ADDON_NAME',
            $addon_name,
            $end_point_settings['endpoint']
        );
      return $end_point_settings;
    }
    else {
      exit("Unknown API End Point: $end_point");
    }

    return FALSE;
  }

}
