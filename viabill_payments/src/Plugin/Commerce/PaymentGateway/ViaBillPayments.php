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
use Drupal\viabill_payments\Helper\ViaBillLegalNote;
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
use Drupal\Component\Utility\Xss;

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
      'order_number_prefix' => '',
      'legal_note_language' => ViaBillLegalNote::LANGUAGE_AUTO,
      'legal_note_font_size' => ViaBillLegalNote::DEFAULT_FONT_SIZE,
      'legal_note_font_style' => ViaBillLegalNote::DEFAULT_FONT_STYLE,
      'legal_note_color' => '',
      'legal_note_align' => '',
      'legal_note_extra_css' => '',
    ] + ViaBillLegalNote::DEFAULT_MARGINS + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Helper utilities and ViaBill API client.
    $helper = new ViaBillHelper();
    $gateway = new ViaBillGateway();

    // Current Theme.
    $pricetag_twig_snippet = "{% if viabill_pricetag %} {{ viabill_pricetag }} {% endif %}";
    $theme = \Drupal::theme()->getActiveTheme()->getName();

    // Load the stored credentials. The plugin configuration is the primary
    // source; the global 'viabill_payments.settings' config acts as a
    // fallback (e.g. when the credentials were entered through the
    // standalone ViaBill Account Credentials form).
    $storedConfig = $this->configFactory->get('viabill_payments.settings');
    $apiKey = $this->configuration['api_key'] ?: ($storedConfig->get('api_key') ?? '');
    $apiSecret = $this->configuration['api_secret'] ?: ViaBillHelper::getStoredSecret();
    $priceTagScript = $this->configuration['viabill_pricetag'] ?: ($storedConfig->get('viabill_pricetag') ?? '');

    if (empty($apiKey) || empty($apiSecret)) {
      // No credentials yet: the merchant can enter them manually in the
      // "ViaBill Account Credentials" section below.
      $form['notice'] = [
        '#markup' => Markup::create('<div style="background-color: #c8eafd;border: 1px solid #afd9db;padding: 15px;border-radius: 4px;margin: 10px 0;color: #043685;">' . $this->t(
          'Your ViaBill account credentials are missing. Please enter your API key, API secret and PriceTag script in the <strong>ViaBill Account Credentials</strong> section below. You can find these values in your ViaBill merchant account. Alternatively, you can use the <a href=":account_link">ViaBill Account Credentials</a> form.',
          [
            ':account_link' => Url::fromRoute('viabill_payments.account_form', [], ['absolute' => TRUE])->toString(),
          ]
        ) . '</div>'),
      ];
    }
    else {
      $viabill_link = NULL;
      $my_data = [
        'key' => $apiKey,
      ];
      $return = $gateway->myViabill($my_data);
      if ($return && isset($return['url'])) {
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
                '#markup' => Markup::create('<div style="background-color: #fff3cd;border: 1px solid #ffeeba;padding: 15px;border-radius: 4px;margin: 10px 0;color: #856404;">' . Xss::filter((string) $messages[0]) . '</div>'),
              ];
            }
            else {
              $form['notifications'] = [
                '#markup' => Markup::create('<div style="background-color: #fff3cd;border: 1px solid #ffeeba;padding: 15px;border-radius: 4px;margin: 10px 0;color: #856404;"><ul><li>' . implode('</li><li>', array_map(function ($message) { return Xss::filter((string) $message); }, $messages)) . '</li></ul></div>'),
              ];
            }
          }
          elseif (is_string($messages)) {
            $form['notifications'] = [
              '#markup' => Markup::create('<div style="background-color: #fff3cd;border: 1px solid #ffeeba;padding: 15px;border-radius: 4px;margin: 10px 0;color: #856404;">' . Xss::filter($messages) . '</div>'),
            ];
          }
        }
      }

      if (!empty($viabill_link)) {
        $form['notice'] = [
          '#markup' => Markup::create('<div style="background-color: #c8eafd;border: 1px solid #afd9db;padding: 15px;border-radius: 4px;margin: 10px 0;color: #043685;">' . $this->t(
            'Your ViaBill account credentials are configured. You can update them in the <strong>ViaBill Account Credentials</strong> section below.<br/>
            You can visit your ViaBill account <a href=":viabill_link" target="_blank">here</a>',
            [
              ':viabill_link' => $viabill_link,
            ]
          ) . '</div>'),
        ];
      }
      else {
        $form['notice'] = [
          '#markup' => Markup::create('<div style="background-color: #c8eafd;border: 1px solid #afd9db;padding: 15px;border-radius: 4px;margin: 10px 0;color: #043685;">' . $this->t(
            'Your ViaBill account credentials are configured. You can update them in the <strong>ViaBill Account Credentials</strong> section below.'
          ) . '</div>'),
        ];
      }
    }

    // Group 0: Account Credentials (shown at the top of the settings page).
    // The merchant obtains the API key, API secret and PriceTag script
    // elsewhere (e.g. from the ViaBill merchant portal) and enters them
    // here manually.
    $form['credentials'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('ViaBill Account Credentials'),
      '#description' => $this->t('Enter the API key, API secret and PriceTag script you obtained from ViaBill. All three values are required for the payment gateway and the PriceTags to work.'),
    ];

    $form['credentials']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#description' => $this->t('Your ViaBill API key.'),
      '#default_value' => $apiKey,
      '#maxlength' => 2048,
      '#required' => TRUE,
      '#attributes' => ['autocomplete' => 'off'],
    ];

    // The API secret is never printed back to the page. If a secret is
    // already stored the field shows a masked placeholder and can be left
    // empty to keep the current value.
    $form['credentials']['api_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('API Secret'),
      '#description' => !empty($apiSecret)
        ? $this->t('An API secret is already saved (hidden for security). Leave this field empty to keep it.')
        : $this->t('Your ViaBill API secret.'),
      '#required' => empty($apiSecret),
      '#attributes' => [
        'autocomplete' => 'new-password',
        'placeholder' => !empty($apiSecret) ? '********' : '',
      ],
    ];

    $form['credentials']['viabill_pricetag'] = [
      '#type' => 'textarea',
      '#title' => $this->t('PriceTag Script'),
      '#description' => $this->t('Paste the whole PriceTag script snippet provided by ViaBill, including the opening and closing script tags.'),
      '#default_value' => $priceTagScript,
      '#rows' => 4,
      '#required' => TRUE,
    ];

    // Point merchants without credentials to the ViaBill online signup form.
    if (empty($apiKey) || empty($apiSecret) || empty($priceTagScript)) {
      $form['credentials']['signup_note'] = ViaBillHelper::buildSignupNote();
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

    $form['payment_preferences']['order_number_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Order number prefix'),
      '#description' => $this->t('Optional. Added in front of the order number sent to ViaBill, e.g. "SHOP-" sends order 1000 as "SHOP-1000". Use it when several shops share one ViaBill account, or change it after resetting the orders of the shop, so ViaBill never receives an order number it has already seen.'),
      '#default_value' => $this->configuration['order_number_prefix'] ?? '',
      '#maxlength' => 20,
      '#size' => 20,
    ];

    // Legal note (credit warning) shown next to ViaBill in the checkout. It is
    // always shown, with the default text; only its look can be changed.
    $legal_note_language = $this->configuration['legal_note_language'] ?? ViaBillLegalNote::LANGUAGE_AUTO;
    $legal_note_texts = ViaBillLegalNote::getDefaultTexts(\Drupal::languageManager()->getCurrentLanguage()->getId());
    // Name of the language select, for the read-only text to follow it.
    $legal_note_parents = array_merge($form['#parents'] ?? [], ['legal_note', 'language']);
    $legal_note_language_name = array_shift($legal_note_parents) . ($legal_note_parents ? '[' . implode('][', $legal_note_parents) . ']' : '');

    $form['legal_note'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Legal Note'),
      '#description' => $this->t('The legal note is a short information text shown to customers at checkout when ViaBill is selected as the payment method, such as a warning that borrowing money costs money.'),
    ];

    // Read only: shows the legal note in the selected language, and follows
    // the language select (js/legal-note-admin.js). Nothing is saved for it.
    $form['legal_note']['text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Text'),
      '#description' => $this->t('The legal note shown at checkout, in the language selected below.'),
      '#value' => $legal_note_texts[$legal_note_language] ?? $legal_note_texts[ViaBillLegalNote::LANGUAGE_AUTO],
      '#rows' => 2,
      '#resizable' => 'none',
      '#attributes' => [
        'readonly' => 'readonly',
        'class' => ['viabill-legal-note-text'],
        'data-viabill-legal-note-texts' => json_encode($legal_note_texts),
        'data-viabill-legal-note-language' => $legal_note_language_name,
      ],
      '#attached' => ['library' => ['viabill_payments/legal_note_admin']],
    ];

    $form['legal_note']['language'] = [
      '#type' => 'select',
      '#title' => $this->t('Language'),
      '#description' => $this->t('Language of the default legal note. "Auto" uses the language of the customer.'),
      '#options' => ViaBillLegalNote::getLanguageOptions(),
      '#default_value' => $legal_note_language,
    ];

    // Styling of the legal note, see ViaBillLegalNote::getStyle().
    $form['legal_note']['font_size'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Font size'),
      '#description' => $this->t('Font size of the legal note, e.g. 14px, 0.9em or 90%. Leave empty to use the font size of your theme.'),
      '#default_value' => $this->configuration['legal_note_font_size'] ?? ViaBillLegalNote::DEFAULT_FONT_SIZE,
      '#maxlength' => 12,
      '#size' => 12,
    ];

    $form['legal_note']['font_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Font style'),
      '#options' => ViaBillLegalNote::getFontStyleOptions(),
      '#default_value' => $this->configuration['legal_note_font_style'] ?? ViaBillLegalNote::DEFAULT_FONT_STYLE,
    ];

    // A plain text field: the HTML color input cannot be left empty.
    $form['legal_note']['color'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Text color'),
      '#description' => $this->t('Color code of the legal note text, e.g. #cc0000. Leave empty to use the text color of your theme.'),
      '#default_value' => $this->configuration['legal_note_color'] ?? '',
      '#maxlength' => 20,
      '#size' => 12,
    ];

    $form['legal_note']['align'] = [
      '#type' => 'select',
      '#title' => $this->t('Text alignment'),
      '#options' => ViaBillLegalNote::getAlignOptions(),
      '#default_value' => $this->configuration['legal_note_align'] ?? '',
    ];

    $legal_note_margins = [
      'margin_top' => [
        $this->t('Margin top'),
        $this->t('Space above the legal note, e.g. 4px or 0.5em. A negative value moves it closer to the payment method name.'),
      ],
      'margin_bottom' => [
        $this->t('Margin bottom'),
        $this->t('Space below the legal note, e.g. 4px or 0.5em.'),
      ],
      'margin_left' => [
        $this->t('Margin left'),
        $this->t('Space to the left of the legal note, e.g. 4px. A negative value moves it to the left.'),
      ],
    ];
    foreach ($legal_note_margins as $legal_note_margin => [$title, $description]) {
      $form['legal_note'][$legal_note_margin] = [
        '#type' => 'textfield',
        '#title' => $title,
        '#description' => $description,
        '#default_value' => ViaBillLegalNote::getMargin($this->configuration, 'legal_note_' . $legal_note_margin),
        '#maxlength' => 12,
        '#size' => 12,
      ];
    }

    $form['legal_note']['extra_css'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Extra CSS'),
      '#description' => $this->t('Optional CSS declarations applied to the legal note box, separated by semicolons (no selectors or braces), e.g. padding: 8px 12px; background-color: #fff8e1; border-left: 3px solid #f0b849; They are applied after the options above, so they can also override them.'),
      '#default_value' => $this->configuration['legal_note_extra_css'] ?? '',
      '#rows' => 2,
    ];

    // Group 2: App Credentials — moved to the "ViaBill Account Credentials"
    // fieldset at the top of the form (see above).

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

    // Note: the PriceTag script itself is now entered manually in the
    // "ViaBill Account Credentials" fieldset at the top of this form.

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
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);

    $api_key = trim($values['credentials']['api_key'] ?? '');
    $api_secret = trim($values['credentials']['api_secret'] ?? '');
    $pricetag = trim($values['credentials']['viabill_pricetag'] ?? '');

    if ($api_key === '') {
      $form_state->setError($form['credentials']['api_key'], $this->t('The ViaBill API key is required.'));
    }

    // An empty secret is only allowed when a secret is already stored
    // (it means "keep the current one").
    if ($api_secret === '') {
      $stored_secret = ($this->configuration['api_secret'] ?? '') ?: ViaBillHelper::getStoredSecret();
      if (empty($stored_secret)) {
        $form_state->setError($form['credentials']['api_secret'], $this->t('The ViaBill API secret is required.'));
      }
      else {
        // The submit handler may run on a plugin instance without the
        // stored secret, so hand over the one found here.
        $form_state->set('viabill_existing_api_secret', $stored_secret);
      }
    }

    if ($pricetag === '') {
      $form_state->setError($form['credentials']['viabill_pricetag'], $this->t('The ViaBill PriceTag script is required.'));
    }
    elseif (stripos($pricetag, '<script') === FALSE) {
      $form_state->setError($form['credentials']['viabill_pricetag'], $this->t('The PriceTag script does not look like a valid script snippet. Please paste the whole snippet, including the opening and closing script tags.'));
    }

    // Styling of the legal note: empty means the styling of the theme.
    $font_size = trim($values['legal_note']['font_size'] ?? '');
    if ($font_size !== '' && ViaBillLegalNote::sanitizeFontSize($font_size) === '') {
      $form_state->setError($form['legal_note']['font_size'], $this->t('The font size of the legal note is not valid. Use a value such as 14px, 0.9em or 90%.'));
    }

    $color = trim($values['legal_note']['color'] ?? '');
    if ($color !== '' && ViaBillLegalNote::sanitizeColor($color) === '') {
      $form_state->setError($form['legal_note']['color'], $this->t('The text color of the legal note is not valid. Use a color code such as #cc0000.'));
    }

    foreach (['margin_top', 'margin_bottom', 'margin_left'] as $legal_note_margin) {
      $margin = trim($values['legal_note'][$legal_note_margin] ?? '');
      if ($margin !== '' && ViaBillLegalNote::sanitizeMargin($margin) === '') {
        $form_state->setError($form['legal_note'][$legal_note_margin], $this->t('The margin of the legal note is not valid. Use a value such as 4px, -12px or 0.5em.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);

    $api_key = trim($values['credentials']['api_key'] ?? '');
    $api_secret = trim($values['credentials']['api_secret'] ?? '');
    $pricetag = trim($values['credentials']['viabill_pricetag'] ?? '');

    // An empty secret means "keep the currently stored secret".
    if ($api_secret === '') {
      $api_secret = ($this->configuration['api_secret'] ?? '') ?: (string) $form_state->get('viabill_existing_api_secret') ?: ViaBillHelper::getStoredSecret();
    }

    $this->configuration['api_key'] = $api_key;
    $this->configuration['api_secret'] = $api_secret;
    $this->configuration['viabill_pricetag'] = $pricetag;
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
    $this->configuration['order_number_prefix'] = trim($values['payment_preferences']['order_number_prefix'] ?? '');
    // The legal note is always shown with the default text, so the settings
    // of earlier versions for that are removed.
    unset($this->configuration['legal_note_enabled'], $this->configuration['legal_note_text']);
    $this->configuration['legal_note_language'] = $values['legal_note']['language'] ?? ViaBillLegalNote::LANGUAGE_AUTO;
    $this->configuration['legal_note_font_size'] = ViaBillLegalNote::sanitizeFontSize($values['legal_note']['font_size'] ?? '');
    $this->configuration['legal_note_font_style'] = $values['legal_note']['font_style'] ?? ViaBillLegalNote::DEFAULT_FONT_STYLE;
    $this->configuration['legal_note_color'] = ViaBillLegalNote::sanitizeColor($values['legal_note']['color'] ?? '');
    $this->configuration['legal_note_align'] = $values['legal_note']['align'] ?? '';
    $this->configuration['legal_note_extra_css'] = ViaBillLegalNote::sanitizeExtraCss($values['legal_note']['extra_css'] ?? '');
    foreach (['margin_top', 'margin_bottom', 'margin_left'] as $legal_note_margin) {
      $margin = ViaBillLegalNote::sanitizeMargin($values['legal_note'][$legal_note_margin] ?? '');
      $this->configuration['legal_note_' . $legal_note_margin] = $margin !== '' ? $margin : ViaBillLegalNote::getDefaultMargin('legal_note_' . $legal_note_margin);
    }

    // The module's own copy of the API secret lives in the State API, so it
    // is never part of a configuration export.
    ViaBillHelper::setStoredSecret($api_secret);

    // Global config (accessible from hooks and elsewhere). The API key and
    // PriceTag script are stored here as well because the pricetag hooks in
    // viabill_payments.module read the PriceTag script from this config.
    $global_config = \Drupal::service('config.factory')->getEditable('viabill_payments.settings');
    $global_config
      ->set('api_key', $api_key)
      ->clear('api_secret')
      ->set('viabill_pricetag', $pricetag)
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

    // If you have a capturePayment() method and the payment is "authorization" or "partially_captured",
    // add a custom "Capture" operation.
    $payment_state = $payment->getState()->value;
    if (method_exists($this, 'capturePayment') && in_array($payment_state, ['authorization', 'partially_captured'])) {
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
    $this->assertPaymentState($payment, ['authorization', 'partially_captured']);

    // Get associated order.
    $order = $payment->getOrder();

    // Use the amount or default to the entire authorized amount.
    $authorized_amount = $payment->getAmount();
    $amount = $amount ?: $authorized_amount;

    // Get amount already captured from payment metadata.
    $captured_amount = $this->getCapturedAmount($payment);

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
    // Use this gateway's own credentials.
    $gateway->setCredentials((string) $this->configuration['api_key'], (string) ($this->configuration['api_secret'] ?: ViaBillHelper::getStoredSecret()));

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

        // Store the new captured amount in payment metadata.
        $this->setCapturedAmount($payment, $new_captured_amount);

        // Determine if this is a full or partial capture.
        if ($new_captured_amount->equals($authorized_amount)) {
          // Full amount captured.
          $payment->setState('completed');
          $payment->setCompletedTime($this->time->getRequestTime());
          $this->messenger()->addStatus($this->t('Payment fully captured'));
        }
        else {
          // Partial amount captured - keep in partially_captured state.
          $payment->setState('partially_captured');
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
    // Use this gateway's own credentials.
    $gateway->setCredentials((string) $this->configuration['api_key'], (string) ($this->configuration['api_secret'] ?: ViaBillHelper::getStoredSecret()));

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
    $this->assertPaymentState($payment, ['completed', 'partially_refunded', 'partially_captured']);

    // Call ViaBill API to process the refund.
    $helper = new ViaBillHelper();
    $gateway = new ViaBillGateway();
    // Use this gateway's own credentials.
    $gateway->setCredentials((string) $this->configuration['api_key'], (string) ($this->configuration['api_secret'] ?: ViaBillHelper::getStoredSecret()));

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

        // For partially captured payments, compare against captured amount.
        // For fully captured payments, compare against total payment amount.
        $captured_amount = $this->getCapturedAmount($payment);
        $refundable_amount = $captured_amount->greaterThan(new Price('0', $captured_amount->getCurrencyCode())) 
          ? $captured_amount 
          : $payment->getAmount();

        if ($new_refunded_amount->lessThan($refundable_amount)) {
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

  /**
   * Get the total captured amount for a payment.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment entity.
   *
   * @return \Drupal\commerce_price\Price
   *   The total captured amount.
   */
  protected function getCapturedAmount(PaymentInterface $payment) {
    // Use the order's data field to store captured amount keyed by payment ID.
    $order = $payment->getOrder();
    $payment_id = $payment->id();
    
    // Get the captured amounts array from order data.
    $captured_amounts = $order->getData('viabill_captured_amounts', []);
    
    if (isset($captured_amounts[$payment_id])) {
      $captured_data = $captured_amounts[$payment_id];
      if (isset($captured_data['number']) && isset($captured_data['currency_code'])) {
        return new Price($captured_data['number'], $captured_data['currency_code']);
      }
    }
    
    // Default to 0 if no captured amount is stored.
    return new Price('0', $payment->getAmount()->getCurrencyCode());
  }

  /**
   * Set the total captured amount for a payment.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment entity.
   * @param \Drupal\commerce_price\Price $amount
   *   The total captured amount.
   */
  protected function setCapturedAmount(PaymentInterface $payment, Price $amount) {
    // Store captured amount in the order's data field keyed by payment ID.
    $order = $payment->getOrder();
    $payment_id = $payment->id();
    
    // Get existing captured amounts.
    $captured_amounts = $order->getData('viabill_captured_amounts', []);
    
    // Update the captured amount for this payment.
    $captured_amounts[$payment_id] = [
      'number' => $amount->getNumber(),
      'currency_code' => $amount->getCurrencyCode(),
    ];
    
    // Save back to order.
    $order->setData('viabill_captured_amounts', $captured_amounts);
    $order->save();
  }

}
