<?php

namespace Drupal\viabill_payments\PluginForm;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\viabill_payments\Helper\ViaBillHelper;

/**
 * Provides a form for merchants to enter their ViaBill credentials manually.
 *
 * The merchant obtains the API key, API secret and PriceTag script elsewhere
 * (e.g. from the ViaBill merchant portal) and enters them here manually.
 * No remote call to the ViaBill server (register/login) is performed.
 */
class ViaBillAccountForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   *
   * We store the API key, secret and PriceTag script in a config object
   * named 'viabill_payments.settings'.
   */
  protected function getEditableConfigNames() {
    return ['viabill_payments.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'viabill_payments_account_form';
  }

  /**
   * Build the form.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    $config = $this->config('viabill_payments.settings');
    $existing_api_key = $config->get('api_key') ?? '';
    $existing_api_secret = ViaBillHelper::getStoredSecret();
    $existing_pricetag = $config->get('viabill_pricetag') ?? '';

    $form['description'] = [
      '#markup' => $this->t('<p>Enter your ViaBill API key, API secret and PriceTag script below. You can find these values in your ViaBill merchant account. Once saved, they are applied to the ViaBill payment gateway.</p>'),
    ];

    // Change the default "Save configuration" text to "Save Credentials".
    $form['actions']['submit']['#value'] = $this->t('Save Credentials');

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#description' => $this->t('Your ViaBill API key.'),
      '#default_value' => $existing_api_key,
      '#maxlength' => 2048,
      '#required' => TRUE,
      '#attributes' => ['autocomplete' => 'off'],
    ];

    // The API secret is never printed back to the page. If a secret is
    // already stored the field shows a masked placeholder and can be left
    // empty to keep the current value.
    $form['api_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('API Secret'),
      '#description' => !empty($existing_api_secret)
        ? $this->t('An API secret is already saved (hidden for security). Leave this field empty to keep it.')
        : $this->t('Your ViaBill API secret.'),
      '#required' => empty($existing_api_secret),
      '#attributes' => [
        'autocomplete' => 'new-password',
        'placeholder' => !empty($existing_api_secret) ? '********' : '',
      ],
    ];

    $form['viabill_pricetag'] = [
      '#type' => 'textarea',
      '#title' => $this->t('PriceTag Script'),
      '#description' => $this->t('Paste the whole PriceTag script snippet provided by ViaBill, including the opening and closing script tags.'),
      '#default_value' => $existing_pricetag,
      '#rows' => 4,
      '#required' => TRUE,
    ];

    // Point merchants without credentials to the ViaBill online signup form.
    if (empty($existing_api_key) || empty($existing_api_secret) || empty($existing_pricetag)) {
      $form['signup_note'] = ViaBillHelper::buildSignupNote();
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $pricetag = trim($form_state->getValue('viabill_pricetag') ?? '');
    if ($pricetag !== '' && stripos($pricetag, '<script') === FALSE) {
      $form_state->setErrorByName('viabill_pricetag', $this->t('The PriceTag script does not look like a valid script snippet. Please paste the whole snippet, including the opening and closing script tags.'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * On submit, store the manually entered API key, secret and PriceTag
   * script in config and sync them into the payment gateway configuration.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $config = $this->config('viabill_payments.settings');

    $api_key = trim($form_state->getValue('api_key'));
    $api_secret = trim($form_state->getValue('api_secret') ?? '');
    $pricetag = trim($form_state->getValue('viabill_pricetag'));

    // An empty secret means "keep the currently stored secret".
    if ($api_secret === '') {
      $api_secret = ViaBillHelper::getStoredSecret();
    }

    $config
      ->set('api_key', $api_key)
      ->clear('api_secret')
      ->set('viabill_pricetag', $pricetag)
      ->save();

    // Also update the payment gateway plugin configuration for every
    // gateway that uses the viabill_payments plugin.
    $payment_gateway_storage = \Drupal::entityTypeManager()->getStorage('commerce_payment_gateway');
    $payment_gateways = $payment_gateway_storage->loadByProperties(['plugin' => 'viabill_payments']);

    $redirect_gateway_id = NULL;
    foreach ($payment_gateways as $payment_gateway) {
      $configuration = $payment_gateway->getPlugin()->getConfiguration();
      $configuration['api_key'] = $api_key;
      $configuration['api_secret'] = $api_secret;
      $configuration['viabill_pricetag'] = $pricetag;

      $payment_gateway->setPluginConfiguration($configuration);
      $payment_gateway->save();

      if ($redirect_gateway_id === NULL) {
        $redirect_gateway_id = $payment_gateway->id();
      }
    }

    $this->messenger()->addStatus($this->t('ViaBill account credentials saved successfully.'));

    // Redirect to the ViaBill Payments gateway configuration page, if a
    // gateway entity already exists.
    if ($redirect_gateway_id !== NULL) {
      $form_state->setRedirectUrl(Url::fromRoute('entity.commerce_payment_gateway.edit_form', ['commerce_payment_gateway' => $redirect_gateway_id]));
    }
  }

}
