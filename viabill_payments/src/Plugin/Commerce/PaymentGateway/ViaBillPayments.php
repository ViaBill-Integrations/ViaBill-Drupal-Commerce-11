<?php

namespace Drupal\viabill_payments\Plugin\Commerce\PaymentGateway;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\viabill_payments\Helper\ViaBillHelper;
use Drupal\viabill_payments\Helper\ViaBillGateway;
use Drupal\viabill_payments\Helper\ViaBillConstants;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsVoidsInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\commerce_price\MinorUnitsConverterInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Url;

/**
 * The main class for the payment module.
 *
 * @CommercePaymentGateway(
 *   id = "viabill_payments",
 *   label = @Translation("ViaBill Payments"),
 *   display_label = @Translation("ViaBill Payments"),
 *   forms = {
 *     "offsite-payment" = "Drupal\viabill_payments\PluginForm\ViaBillPaymentsForm",
 *     "capture-payment" = "Drupal\viabill_payments\PluginForm\CapturePaymentForm",
 *   },
 *   payment_type = "payment_default",
 * )
 */
class ViaBillPayments extends OffsitePaymentGatewayBase implements SupportsRefundsInterface, SupportsVoidsInterface {

  /**
   * The Drupal configuration object.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a ViaBillPayments object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    // Do NOT assign any custom services here!
  }

  /**
   * Dependency injection factory (required for Drupal Commerce 3.x+ / Drupal 11).
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    // Create instance as above:
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    // Assign your custom services here:
    $instance->configFactory = $container->get('config.factory');
    // ... assign any other needed services to $instance here
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'api_key' => '',
      'api_secret' => '',
      'viabill_pricetag' => '',
      'transaction_type' => ViaBillConstants::TRANSACTION_TYPE_AUTHORIZE_ONLY,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Call ViaBill API.
    $helper = new ViaBillHelper();
    $gateway = new ViaBillGateway();

    // Current Theme.
    $pricetag_twig_snippet = "{% if viabill_pricetag %} {{ viabill_pricetag }} {% endif %}";
    $theme = \Drupal::theme()->getActiveTheme()->getName();

    // Instead of forcing the user to type in the API Key/Secret again,
    // fetch them from 'viabill_payments.settings'.
    $storedConfig = $this->configFactory->get('viabill_payments.settings');
    $apiKey = $storedConfig->get('api_key') ?? '';
    $apiSecret = $storedConfig->get('api_secret') ?? '';
    $priceTagScript = $storedConfig->get('viabill_pricetag') ?? '';
    $email = $storedConfig->get('viabill_email') ?? '';

    // If they're not set, instruct user to go to the login form first:
    if (empty($apiKey) || empty($apiSecret)) {
      $form['notice'] = [
        '#markup' => Markup::create('<div style="background-color: #c8eafd;border: 1px solid #afd9db;padding: 15px;border-radius: 4px;margin: 10px 0;color: #043685;">' . $this->t(
          'Please <a href=":login_link">log in</a> or <a href=":register_link">register</a> with ViaBill to retrieve your API credentials before configuring the payment gateway.',
          [
            ':login_link' => Url::fromRoute('viabill_payments.login_form', [], ['absolute' => TRUE])->toString(),
            ':register_link' => Url::fromRoute('viabill_payments.register_form', [], ['absolute' => TRUE])->toString(),
          ]
        ) . '</div>'),
      ];

      return $form;
    }
    else {
      $viabill_link = NULL;
      $my_data = [
        'key' => $apiKey,
      ];
      $return = $gateway->myViabill($my_data);
      if ($return) {
        $viabill_link = $return['url'];
      }

      $notifications = NULL;
      $return = $gateway->notifications($my_data);
      if ($return) {
        if (isset($return['error'])) {
          $message = $return['error'];
          if (!empty($message)) {
            $helper->log("ViaBill Notification error: $message", "error");
          }
        }
        elseif (isset($return['messages'])) {
          $messages = $return['messages'];
          if (empty($messages)) {
            // Do nothing.
          }
          elseif (is_array($messages)) {
            if (count($messages) == 1) {
              $form['notifications'] = [
                '#markup' => Markup::create('<div style="background-color: #fff3cd;border: 1px solid #ffeeba;padding: 15px;border-radius: 4px;margin: 10px 0;color: #856404;">' . $messages[0] . '</div>'),
              ];
            }
            else {
              $form['notifications'] = [
                '#markup' => Markup::create('<div style="background-color: #fff3cd;border: 1px solid #ffeeba;padding: 15px;border-radius: 4px;margin: 10px 0;color: #856404;"><ul><li>' . implode('</li><li>', $messages) . '</li></ul></div>'),
              ];
            }
          }
          elseif (is_string($messages)) {
            $form['notifications'] = [
              '#markup' => Markup::create('<div style="background-color: #fff3cd;border: 1px solid #ffeeba;padding: 15px;border-radius: 4px;margin: 10px 0;color: #856404;">' . $messages . '</div>'),
            ];
          }
        }
      }

      $form['notice'] = [
        '#markup' => Markup::create('<div style="background-color: #c8eafd;border: 1px solid #afd9db;padding: 15px;border-radius: 4px;margin: 10px 0;color: #043685;">' . $this->t(
          'You are currently logged in as <strong>:email</strong>. Not you? <a href=":login_link">log in</a> or <a href=":register_link">register</a> with ViaBill to retrieve your new API credentials.<br/>
          You can visit your ViaBill account <a href=":viabill_link" target="_blank">here</a>',
          [
            ':email' => $email,
            ':login_link' => Url::fromRoute('viabill_payments.login_form', [], ['absolute' => TRUE])->toString(),
            ':register_link' => Url::fromRoute('viabill_payments.register_form', [], ['absolute' => TRUE])->toString(),
            ':viabill_link' => $viabill_link,
          ]
        ) . '</div>'),
      ];
    }

    // Group 1: Payment Preferences.
    $form['payment_preferences'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('ViaBill Payments Preferences'),
      '#description' => $this->t('Configure how ViaBill payments are processed.'),
    ];

    $form['payment_preferences']['transaction_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Transaction Type'),
      '#options' => [
        ViaBillConstants::TRANSACTION_TYPE_AUTHORIZE_ONLY => $this->t('Authorize Only'),
        ViaBillConstants::TRANSACTION_TYPE_AUTHORIZE_CAPTURE => $this->t('Authorize and Capture'),
      ],
      '#default_value' => $this->configuration['transaction_type'],
    ];

    // Group 2: App Credentials.
    $form['credentials'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('ViaBill Payments Credentials'),
      '#description' => $this->t('API credentials for connecting to the ViaBill payment service.'),
    ];

    $form['credentials']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#default_value' => $this->configuration['api_key'],
      '#disabled' => TRUE,
      '#maxlength' => 2048,
    ];

    $form['credentials']['api_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Secret'),
      '#default_value' => $this->configuration['api_secret'],
      '#disabled' => TRUE,
    ];

    // Group 3: PriceTags.
    $form['pricetags'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('PriceTags'),
      '#description' => $this->t('Configuration for ViaBill price tag display.'),
    ];

    $form['pricetags']['pricetag_intro'] = [
      '#markup' => Markup::create('<div style="background-color: #fff3cd;border: 1px solid #ffeeba;padding: 15px;border-radius: 4px;margin: 10px 0;color: #856404;">' .
        $this->t("ViaBill's PriceTag is the key to your success as a ViaBill partner.") .
        $this->t("The PriceTag immediately shows your customers how much they have to pay per month if they choose to pay with ViaBill. This way you maintain the customers' interest and avoid losing customers who would otherwise have abandoned the purchase.") . '</div>'
      ),
    ];

    $form['pricetags']['pricetag_country'] = [
      '#type' => 'select',
      '#title' => $this->t('PriceTag Country'),
      '#options' => [
        ViaBillConstants::PRICETAG_COUNTRY_AUTODETECT => $this->t('Auto-Detect'),
        ViaBillConstants::PRICETAG_COUNTRY_DENMARK => $this->t('Denmark'),
        ViaBillConstants::PRICETAG_COUNTRY_SPAIN => $this->t('Spain'),
      ],
      '#default_value' => $this->configuration['pricetag_country'] ?? ViaBillConstants::PRICETAG_COUNTRY_AUTODETECT,
    ];

    $form['pricetags']['pricetag_language'] = [
      '#type' => 'select',
      '#title' => $this->t('PriceTag Language'),
      '#options' => [
        ViaBillConstants::PRICETAG_LANGUAGE_AUTODETECT => $this->t('Auto-Detect'),
        ViaBillConstants::PRICETAG_LANGUAGE_DENMARK => $this->t('Danish'),
        ViaBillConstants::PRICETAG_LANGUAGE_SPAIN => $this->t('Spanish'),
      ],
      '#default_value' => $this->configuration['pricetag_language'] ?? ViaBillConstants::PRICETAG_LANGUAGE_AUTODETECT,
    ];

    $form['pricetags']['pricetag_main_separator'] = [
      '#markup' => Markup::create('<hr/>'),
    ];

    $template_paths = [
      // Core file (not to modify, but to check existence).
      'core' => DRUPAL_ROOT . '/modules/contrib/commerce/modules/product/templates/commerce-product.html.twig',
      // Theme override location.
      'theme' => DRUPAL_ROOT . "/themes/custom/$theme/templates/commerce/product/commerce-product.html.twig",
    ];

    // Check if the files exist and update the display accordingly.
    $template_file_core = 'modules/contrib/commerce/modules/product/templates/<strong>commerce-product.html.twig</strong>';
    if (file_exists($template_paths['core'])) {
      $template_file_core = '<em>' . DRUPAL_ROOT . '</em>/modules/contrib/commerce/modules/product/templates/<strong>commerce-product.html.twig</strong>';
    }

    $template_file_theme = "themes/custom/$theme/templates/commerce/product/<strong>commerce-product.html.twig</strong>";
    if (file_exists($template_paths['theme'])) {
      $template_file_theme = '<em>' . DRUPAL_ROOT . "</em>/themes/custom/$theme/templates/commerce/product/<strong>commerce-product.html.twig</strong>";
    }

    $form['pricetags']['product_pricetag'] = [
      '#markup' => Markup::create(
        '<label class="form-item__label">' . $this->t('Product PriceTag') . '</label>
        <div style="padding: 10px; background-color:rgb(231, 231, 231); border: 1px solid rgb(155, 155, 155);padding: 15px;border-radius: 4px;margin: 10px 0;">' . $pricetag_twig_snippet . '</div>
        <div class="fieldset__description">
        <p>' . $this->t('To insert the pricetag for the product page, edit on the following twig files and insert the code near the total price:') . '</p>
        <ul><li><p>' . $this->t('Option #1. Original file location (core module):') . '</p>
        <p>' . $template_file_core . '</p></li>
        <li><p>' . $this->t('Option #2. Theme override location (where you should place your custom version):') . '</p>
        <p>' . $template_file_theme . '</p></li></ul>
        <p>' . $this->t('If none of the previous options work, try to locate the appropriate file in the templates folder.') . '</p>
        </div>'),
    ];

    $form['pricetags']['product_pricetag_alignment'] = [
      '#type' => 'select',
      '#title' => $this->t('Product PriceTag Alignment'),
      '#options' => [
        ViaBillConstants::PRICETAG_ALIGNMENT_DEFAULT => $this->t('Default'),
        ViaBillConstants::PRICETAG_ALIGNMENT_CENTER => $this->t('Center'),
        ViaBillConstants::PRICETAG_ALIGNMENT_RIGHT => $this->t('Right'),
      ],
      '#default_value' => $this->configuration['product_pricetag_alignment'] ?? ViaBillConstants::PRICETAG_ALIGNMENT_DEFAULT,
    ];

    $form['pricetags']['product_pricetag_width'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Product PriceTag Width'),
      '#default_value' => $this->configuration['product_pricetag_width'] ?? '280px',
    ];

    $form['pricetags']['product_pricetag_dynamic_price'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Product PriceTag Dynamic Price Selector'),
      '#default_value' => $this->configuration['product_pricetag_dynamic_price'] ?? '',
    ];

    $form['pricetags']['product_pricetag_price_trigger'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Product PriceTag Dynamic Price Trigger'),
      '#default_value' => $this->configuration['product_pricetag_price_trigger'] ?? '',
    ];

    $form['pricetags']['product_pricetag_auto'] = [
      '#type' => 'select',
      '#title' => $this->t('Product PriceTag Apply Automatically'),
      '#options' => [
        ViaBillConstants::NO => $this->t('No'),
        ViaBillConstants::YES => $this->t('Yes'),
      ],
      '#default_value' => $this->configuration['product_pricetag_auto'] ?? ViaBillConstants::YES,
    ];

    // See if the pricetag is already applied into the twig files.
    $twig_files_tip = $this->checkPriceTagPresenceAndActions('product', $pricetag_twig_snippet, $template_paths);
    if (!empty($twig_files_tip)) {
      $form['pricetags']['product_pricetag_tip'] = [
        '#markup' => Markup::create($twig_files_tip),
      ];
    }

    $template_paths = [
      // Core file (not to modify, but to check existence).
      DRUPAL_ROOT . '/modules/contrib/commerce/modules/order/templates/commerce-order-total-summary.html.twig',
      // Theme override location.
      DRUPAL_ROOT . "/themes/custom/$theme/templates/commerce/commerce-order-total-summary.html.twig",
    ];

    $template_file_core = '/modules/contrib/commerce/modules/order/templates/<strong>commerce-order-total-summary.html.twig</strong>';
    if (file_exists($template_paths[0])) {
      $template_file_core = '<em>' . DRUPAL_ROOT . '</em>/modules/contrib/commerce/modules/order/templates/<strong>commerce-order-total-summary.html.twig</strong>';
    }

    $template_file_theme = '<em>your-theme-folder</em>/templates/commerce/<strong>commerce-order-total-summary.html.twig</strong>';
    if (file_exists($template_paths[1])) {
      $template_file_theme = '<em>' . DRUPAL_ROOT . '</em>/themes/custom/' . $theme . '/templates/commerce/<strong>commerce-order-total-summary.html.twig</strong>';
    }

    $form['pricetags']['cart_pricetag'] = [
      '#markup' => Markup::create(
        '<hr/>
        <label class="form-item__label">' . $this->t('Cart PriceTag') . '</label>
        <div style="padding: 10px; background-color:rgb(231, 231, 231); border: 1px solid rgb(155, 155, 155);padding: 15px;border-radius: 4px;margin: 10px 0;">' . $pricetag_twig_snippet . '</div>
        <div class="fieldset__description">
        <p>' . $this->t('To insert the pricetag for the cart page, edit on the following twig files and insert the code near the total price:') . '</p>
        <ul><li><p>' . $this->t('Option #1. Original file location (core module):') . '</p>
        <p>' . $template_file_core . '</p></li>
        <li><p>' . $this->t('Option #2. Theme override location (where you should place your custom version):') . '</p>
        <p>' . $template_file_theme . '</p></li></ul>
        <p>' . $this->t('If none of the previous options work, try to locate the appropriate file in the templates folder.') . '</p>
        </div>'),
    ];

    $form['pricetags']['cart_pricetag_alignment'] = [
      '#type' => 'select',
      '#title' => $this->t('Cart PriceTag Alignment'),
      '#options' => [
        ViaBillConstants::PRICETAG_ALIGNMENT_DEFAULT => $this->t('Default'),
        ViaBillConstants::PRICETAG_ALIGNMENT_CENTER => $this->t('Center'),
        ViaBillConstants::PRICETAG_ALIGNMENT_RIGHT => $this->t('Right'),
      ],
      '#default_value' => $this->configuration['cart_pricetag_alignment'] ?? ViaBillConstants::PRICETAG_ALIGNMENT_DEFAULT,
    ];

    $form['pricetags']['cart_pricetag_width'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cart PriceTag Width'),
      '#default_value' => $this->configuration['cart_pricetag_width'] ?? '280px',
    ];

    $form['pricetags']['cart_pricetag_auto'] = [
      '#type' => 'select',
      '#title' => $this->t('Cart PriceTag Apply Automatically'),
      '#options' => [
        ViaBillConstants::NO => $this->t('No'),
        ViaBillConstants::YES => $this->t('Yes'),
      ],
      '#default_value' => $this->configuration['cart_pricetag_auto'] ?? ViaBillConstants::YES,
    ];

    // See if the pricetag is already applied into the twig files.
    $twig_files_tip = $this->checkPriceTagPresenceAndActions('cart', $pricetag_twig_snippet, $template_paths);
    if (!empty($twig_files_tip)) {
      $form['pricetags']['cart_pricetag_tip'] = [
        '#markup' => Markup::create($twig_files_tip),
      ];
    }

    $template_paths = [
      // Core file (not to modify, but to check existence).
      DRUPAL_ROOT . '/modules/contrib/commerce/modules/checkout/templates/commerce-checkout-order-summary.html.twig',
      // Theme override location.
      DRUPAL_ROOT . "/themes/custom/$theme/templates/commerce/checkout/commerce-checkout-order-summary.html.twig",
    ];

    $template_file_core = '/modules/contrib/commerce/modules/checkout/templates/<strong>commerce-checkout-order-summary.html.twig</strong>';
    if (file_exists($template_paths[0])) {
      $template_file_core = '<em>' . DRUPAL_ROOT . '</em>/modules/contrib/commerce/modules/checkout/templates/<strong>commerce-checkout-order-summary.html.twig</strong>';
    }

    $template_file_theme = '<em>your-theme-folder</em>/templates/commerce/checkout/<strong>commerce-checkout-order-summary.html.twig</strong>';
    if (file_exists($template_paths[1])) {
      $template_file_theme = '<em>' . DRUPAL_ROOT . '</em>/themes/custom/' . $theme . '/templates/commerce/checkout/<strong>commerce-checkout-order-summary.html.twig</strong>';
    }

    $form['pricetags']['checkout_pricetag'] = [
      '#markup' => Markup::create(
        '<hr/>
        <label class="form-item__label">' . $this->t('Checkout PriceTag') . '</label>
        <div style="padding: 10px; background-color:rgb(231, 231, 231); border: 1px solid rgb(155, 155, 155);padding: 15px;border-radius: 4px;margin: 10px 0;">' . $pricetag_twig_snippet . '</div>
        <div class="fieldset__description">
        <ul><li><p>' . $this->t('To insert the pricetag for the checkout page, edit on the following twig files and insert the code near the total price:') . '</p>
        <p>' . $this->t('Option #1. Original file location (core module):') . '</p>
        <p>' . $template_file_core . '</p></li>
        <li><p>' . $this->t('Option #2. Theme override location (where you should place your custom version):') . '</p>
        <p>' . $template_file_theme . '</p></li></ul>
        <p>' . $this->t('If none of the previous options work, try to locate the appropriate file in the templates folder.') . '</p>
        </div>'),
    ];

    $form['pricetags']['checkout_pricetag_alignment'] = [
      '#type' => 'select',
      '#title' => $this->t('Checkout PriceTag Alignment'),
      '#options' => [
        ViaBillConstants::PRICETAG_ALIGNMENT_DEFAULT => $this->t('Default'),
        ViaBillConstants::PRICETAG_ALIGNMENT_CENTER => $this->t('Center'),
        ViaBillConstants::PRICETAG_ALIGNMENT_RIGHT => $this->t('Right'),
      ],
      '#default_value' => $this->configuration['checkout_pricetag_alignment'] ?? ViaBillConstants::PRICETAG_ALIGNMENT_DEFAULT,
    ];

    $form['pricetags']['checkout_pricetag_width'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Checkout PriceTag Width'),
      '#default_value' => $this->configuration['checkout_pricetag_width'] ?? '280px',
    ];

    $form['pricetags']['checkout_pricetag_auto'] = [
      '#type' => 'select',
      '#title' => $this->t('Checkout PriceTag Apply Automatically'),
      '#options' => [
        ViaBillConstants::NO => $this->t('No'),
        ViaBillConstants::YES => $this->t('Yes'),
      ],
      '#default_value' => $this->configuration['checkout_pricetag_auto'] ?? ViaBillConstants::NO,
      '#disabled' => TRUE,
    ];

    // See if the pricetag is already applied into the twig files.
    $twig_files_tip = $this->checkPriceTagPresenceAndActions('checkout', $pricetag_twig_snippet, $template_paths);
    if (!empty($twig_files_tip)) {
      $form['pricetags']['checkout_pricetag_tip'] = [
        '#markup' => Markup::create($twig_files_tip),
      ];
    }

    $form['pricetags']['viabill_pricetag_separator'] = [
      '#markup' => Markup::create('<hr/>'),
    ];

    $form['pricetags']['viabill_pricetag'] = [
      '#type' => 'textarea',
      '#title' => $this->t('PriceTag Script'),
      '#default_value' => $this->configuration['viabill_pricetag'],
      '#rows' => 3,
      '#disabled' => TRUE,
    ];

    $form['pricetags']['viabill_pricetag_custom_css'] = [
      '#type' => 'textarea',
      '#title' => $this->t('PriceTag Custom CSS'),
      '#default_value' => $this->configuration['viabill_pricetag_custom_css'] ?? '',
      '#rows' => 3,
      '#description' => $this->t('Optional CSS statement, to further customize the appearance of the PriceTags.'),
    ];

    return $form;
  }

  /**
   * Check the presense of price tags in twig template files.
   */
  public function checkPriceTagPresenceAndActions($page, $pricetag_twig_snippet, $template_paths) {
    $tip = '';
    $present = FALSE;
    $suggested = FALSE;

    foreach ($template_paths as $filepath) {
      if (file_exists($filepath)) {
        $file_contents = file_get_contents($filepath);
        if (strpos($file_contents, 'viabill_pricetag') !== FALSE) {
          $tip = $this->t('PriceTag :pricetag may be already inserted in file :filepath', [
            ':pricetag' => $pricetag_twig_snippet,
            ':filepath' => $filepath,
          ]);
          $present = TRUE;
        }
      }
    }

    if (!$present) {
      switch ($page) {
        case 'cart':
          $insert_after = '';
          $filepath = $template_paths[0];

          if (file_exists($filepath)) {
            $file_contents = file_get_contents($filepath);
            $insert_mark = '{% if totals.total %}';
            $pos = strpos($file_contents, $insert_mark);
            if ($pos) {
              $line_start = NULL;
              $line_end = NULL;

              $lines = explode("\n", $file_contents);
              foreach ($lines as $line_ord => $line) {
                if (strpos($line, $insert_mark) !== FALSE) {
                  $line_start = $line_ord + 1;
                  $insert_after .= $line . "\n";
                }
                elseif (strpos($line, 'endif')) {
                  if (isset($line_start) && !isset($line_end)) {
                    $line_end = $line_ord + 1;
                    $insert_after .= $line . "\n";
                  }
                }
                else {
                  if (isset($line_start) && !isset($line_end)) {
                    $insert_after .= $line . "\n";
                  }
                }
              }
            }
          }

          if (!empty($insert_after)) {
            $tip = $this->t('PriceTag :pricetag can be inserted into the file :filepath around :line, after the following HTML code:<br/><pre>:insert_after</pre>', [
              ':pricetag' => $pricetag_twig_snippet,
              ':filepath' => $filepath,
              ':line' => $line_end,
              ':insert_after' => $insert_after,
            ]);
            $suggested = TRUE;
          }

          break;

        case 'checkout':
          $insert_after = '';
          $filepath = $template_paths[0];

          if (file_exists($filepath)) {
            $file_contents = file_get_contents($filepath);
            $insert_mark = '{% block totals %}';
            $pos = strpos($file_contents, $insert_mark);
            if ($pos) {
              $line_start = NULL;
              $line_end = NULL;

              $lines = explode("\n", $file_contents);
              foreach ($lines as $line_ord => $line) {
                if (strpos($line, $insert_mark) !== FALSE) {
                  $line_start = $line_ord + 1;
                  $insert_after .= $line . "\n";
                }
                elseif (strpos($line, '{% endblock %}')) {
                  if (isset($line_start) && !isset($line_end)) {
                    $line_end = $line_ord + 1;
                    $insert_after .= $line . "\n";
                  }
                }
                else {
                  if (isset($line_start) && !isset($line_end)) {
                    $insert_after .= $line . "\n";
                  }
                }
              }
            }
          }

          if (!empty($insert_after)) {
            $tip = $this->t('PriceTag :pricetag can be inserted into the file :filepath around :line, after the following HTML code:<br/><pre>:insert_after</pre>', [
              ':pricetag' => $pricetag_twig_snippet,
              ':filepath' => $filepath,
              ':line' => $line_end,
              ':insert_after' => $insert_after,
            ]);
            $suggested = TRUE;
          }

          break;
      }
    }

    if (!empty($tip)) {
      if ($present) {
        $tip = '<div style="padding: 10px; background-color:rgb(162, 192, 159); border: 1px solid rgb(103, 129, 112);padding: 15px;border-radius: 4px;margin: 10px 0;">' . $tip . '</div>';
      }
      elseif ($suggested) {
        $tip = '<div style="padding: 10px; background-color:rgb(192, 172, 159); border: 1px solid rgb(129, 110, 103);padding: 15px;border-radius: 4px;margin: 10px 0;">' . $tip . '</div>';
      }
    }

    return $tip;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);

    $this->configuration['api_key'] = $values['credentials']['api_key'];
    $this->configuration['api_secret'] = $values['credentials']['api_secret'];
    $this->configuration['viabill_pricetag'] = $values['pricetags']['viabill_pricetag'];
    $this->configuration['pricetag_country'] = $values['pricetags']['pricetag_country'];
    $this->configuration['pricetag_language'] = $values['pricetags']['pricetag_language'];
    $this->configuration['product_pricetag_alignment'] = $values['pricetags']['product_pricetag_alignment'];
    $this->configuration['product_pricetag_width'] = $values['pricetags']['product_pricetag_width'];
    $this->configuration['product_pricetag_auto'] = $values['pricetags']['product_pricetag_auto'];
    $this->configuration['cart_pricetag_alignment'] = $values['pricetags']['cart_pricetag_alignment'];
    $this->configuration['cart_pricetag_width'] = $values['pricetags']['cart_pricetag_width'];
    $this->configuration['cart_pricetag_auto'] = $values['pricetags']['cart_pricetag_auto'];
    $this->configuration['checkout_pricetag_alignment'] = $values['pricetags']['checkout_pricetag_alignment'];
    $this->configuration['checkout_pricetag_width'] = $values['pricetags']['checkout_pricetag_width'];
    $this->configuration['checkout_pricetag_auto'] = $values['pricetags']['checkout_pricetag_auto'];
    $this->configuration['viabill_pricetag_custom_css'] = $values['pricetags']['viabill_pricetag_custom_css'];
    $this->configuration['transaction_type'] = $values['payment_preferences']['transaction_type'];

    // Global config (accessible from hooks and elsewhere).
    $global_config = \Drupal::service('config.factory')->getEditable('viabill_payments.settings');
    $global_config
      ->set('product_pricetag_alignment', $values['pricetags']['product_pricetag_alignment'])
      ->set('product_pricetag_width', $values['pricetags']['product_pricetag_width'])
      ->set('product_pricetag_auto', $values['pricetags']['product_pricetag_auto'])
      ->set('cart_pricetag_alignment', $values['pricetags']['cart_pricetag_alignment'])
      ->set('cart_pricetag_width', $values['pricetags']['cart_pricetag_width'])
      ->set('cart_pricetag_auto', $values['pricetags']['cart_pricetag_auto'])
      ->set('checkout_pricetag_alignment', $values['pricetags']['checkout_pricetag_alignment'])
      ->set('checkout_pricetag_width', $values['pricetags']['checkout_pricetag_width'])
      ->set('checkout_pricetag_auto', $values['pricetags']['checkout_pricetag_auto'])
      ->set('viabill_pricetag_custom_css', $values['pricetags']['viabill_pricetag_custom_css'])
      ->set('pricetag_country', $values['pricetags']['pricetag_country'])
      ->set('pricetag_language', $values['pricetags']['pricetag_language'])
      ->set('transaction_type', $values['payment_preferences']['transaction_type'])
      ->save();

  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, array $payment_details) {
    $payment->setState('new');
    $payment->save();
  }

  /**
   * Build capture button, if it is applicable.
   *
   * Commerce calls buildPaymentOperations() to build the list
   * of available actions for each payment. By default,
   * OffsitePaymentGatewayBase automatically adds “Void” and “Refund”
   * operations. if it detects voidPayment() / refundPayment(),
   * but it may not do the same for capturePayment().
   * Overriding buildPaymentOperations() ensures a “Capture” action
   * is added whenever the payment is in authorization.
   *
   * {@inheritdoc}
   */
  public function buildPaymentOperations(PaymentInterface $payment) {
    // Call the parent to get the default operations (like void, refund).
    $operations = parent::buildPaymentOperations($payment);

    // If you have a capturePayment() method and the payment is "authorization",
    // add a custom "Capture" operation.
    if (method_exists($this, 'capturePayment') && $payment->getState()->value === 'authorization') {
      $operations['capture'] = [
        'title' => $this->t('Capture'),
        'page_title' => $this->t('Capture payment'),
        // Tells Commerce which plugin form to display
        // (often "capture-payment" is recognized).
        'plugin_form' => 'capture-payment',
        // Access control: only show if we want to allow capturing.
        'access' => TRUE,
        'weight' => 10,
      ];
    }

    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, ?Price $amount = NULL) {
    $this->assertPaymentState($payment, ['authorization']);

    // Get associated order.
    $order = $payment->getOrder();

    // Use the amount or default to the entire authorized amount.
    $authorized_amount = $payment->getAmount();
    $amount = $amount ?: $authorized_amount;

    // Get amount already captured (0 if first capture)
    $captured_amount = new Price(0, $authorized_amount->getCurrencyCode());

    // Calculate remaining authorized amount.
    $remaining_amount = $authorized_amount->subtract($captured_amount);

    // Validate capture amount doesn't exceed remaining authorized amount.
    if ($amount->greaterThan($remaining_amount)) {
      throw new \InvalidArgumentException(
        sprintf('Cannot capture more than the remaining authorized amount of %s', $remaining_amount->__toString())
      );
    }

    // Call ViaBill API to process the capture.
    $helper = new ViaBillHelper();
    $gateway = new ViaBillGateway();

    $transaction_id = $payment->getRemoteId();
    $api_key = $this->configuration['api_key'];
    $capture_amount = $helper->formatAmount($amount->multiply('-1')->getNumber());
    $currency = $amount->getCurrencyCode();

    $data = [
      'id' => $transaction_id,
      'apikey' => $api_key,
      'amount' => $capture_amount,
      'currency' => $currency,
    ];

    try {
      if ($gateway->captureTransaction($data)) {
        // Update the total captured amount.
        $new_captured_amount = $captured_amount->add($amount);

        // Determine if this is a full or partial capture.
        if ($new_captured_amount->equals($authorized_amount)) {
          // Full amount captured.
          $payment->setState('completed');
          $payment->setCompletedTime($this->time->getRequestTime());
          $this->messenger()->addStatus($this->t('Payment fully captured'));
        }
        else {
          // Partial amount captured.
          // Instead of partially_captured.
          $payment->setState('completed');
          $this->messenger()->addStatus(
            $this->t('Payment partially captured: @captured of @authorized', [
              '@captured' => $new_captured_amount->__toString(),
              '@authorized' => $authorized_amount->__toString(),
            ])
                  );

          // Add permanent activity log to the order
          // (requires commerce_log module)
          $log_storage = \Drupal::entityTypeManager()->getStorage('commerce_log');
          $log = $log_storage->generate($order, 'viabill_partial_capture', [
            'captured' => $new_captured_amount->__toString(),
            'authorized' => $authorized_amount->__toString(),
          ]);
          $log->save();
        }

        $payment->save();
      }
      else {
        $helper->log("captureTransaction failed for $transaction_id", 'error');
        $this->messenger()->addError($this->t('captureTransaction failed.'));
      }
    }
    catch (\Exception $e) {
      $helper->log("Capture error: " . $e->getMessage(), 'error');
      $this->messenger()->addError($this->t('Error processing capture'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    $this->assertPaymentState($payment, ['authorization']);

    // Call ViaBill API to process the void.
    $helper = new ViaBillHelper();
    $gateway = new ViaBillGateway();

    $transaction_id = $payment->getRemoteId();
    $api_key = $this->configuration['api_key'];

    $data = [
      'id' => $transaction_id,
      'apikey' => $api_key,
      'currency' => $payment->getAmount()->getCurrencyCode(),
    ];

    try {
      if ($gateway->cancelTransaction($data)) {
        $payment->setState('voided');
        $payment->save();

        $this->messenger()->addStatus($this->t('Payment voided successfully'));
      }
      else {
        $helper->log("Void failed for transaction $transaction_id", 'error');
        $this->messenger()->addError($this->t('Void operation failed'));
      }
    }
    catch (\Exception $e) {
      $helper->log("Void error: " . $e->getMessage(), 'error');
      $this->messenger()->addError($this->t('Error processing void'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, ?Price $amount = NULL) {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);

    // Call ViaBill API to process the refund.
    $helper = new ViaBillHelper();
    $gateway = new ViaBillGateway();

    // Use the amount or default to the entire payment amount.
    $amount = $amount ?: $payment->getAmount();

    $transaction_id = $payment->getRemoteId();
    $api_key = $this->configuration['api_key'];
    $refund_amount = $helper->formatAmount($amount->getNumber());
    $currency = $amount->getCurrencyCode();

    $data = [
      'id' => $transaction_id,
      'apikey' => $api_key,
      'amount' => $refund_amount,
      'currency' => $amount->getCurrencyCode(),
    ];

    try {
      if ($gateway->refundTransaction($data)) {
        // Check if this is a partial or full refund.
        $old_refunded_amount = $payment->getRefundedAmount();
        $new_refunded_amount = $old_refunded_amount->add($amount);

        if ($new_refunded_amount->lessThan($payment->getAmount())) {
          $payment->setState('partially_refunded');
        }
        else {
          $payment->setState('refunded');
        }

        $payment->setRefundedAmount($new_refunded_amount);
        $payment->save();

        $this->messenger()->addStatus($this->t('Refund processed successfully'));
      }
      else {
        $helper->log("Refund failed for transaction $transaction_id", 'error');
        $this->messenger()->addError($this->t('Refund operation failed'));
      }
    }
    catch (\Exception $e) {
      $helper->log("Refund error: " . $e->getMessage(), 'error');
      $this->messenger()->addError($this->t('Error processing refund'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    // Implement IPN validation and order completion logic.
    $gateway = new ViaBillGateway();
    $helper = new ViaBillHelper();

    // Option 1: Redirect to the order canonical page (default view)
    $redirect_url = $order->toUrl('canonical', ['absolute' => TRUE])->toString();

    // Option 2: Redirect to checkout completion page
    // $redirect_url = Url::fromRoute('commerce_checkout.completion', [
    // 'commerce_order' => $order->id(),
    // ], ['absolute' => TRUE])->toString();
    // Return new RedirectResponse($redirect_url);
  }

  /**
   * {@inheritdoc}
   */
  public function onCancel(OrderInterface $order, Request $request) {
    $gateway = new ViaBillGateway();
    $helper = new ViaBillHelper();

    // Save any changes to the order without cancelling it.
    $order->save();

    // Add a message for the customer.
    $this->messenger()->addMessage($this->t('You have canceled checkout at @gateway but may resume the checkout process here when you are ready.', [
      '@gateway' => $this->getDisplayLabel(),
    ]));

    // Redirect to cart or checkout page instead of
    // letting the system redirect back.
    $redirect_url = Url::fromRoute('commerce_cart.page');
    return new RedirectResponse($redirect_url->toString());

  }

}
