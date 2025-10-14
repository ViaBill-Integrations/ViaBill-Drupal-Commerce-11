<?php

namespace Drupal\viabill_payments\PluginForm;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\viabill_payments\Helper\ViaBillHelper;
use Drupal\viabill_payments\Helper\ViaBillGateway;

/**
 * Provides a login form for merchants to obtain their ViaBill credentials.
 */
class ViaBillLoginForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   *
   * We store the API key and secret in a config object
   * named 'viabill_payments.settings'.
   */
  protected function getEditableConfigNames() {
    return ['viabill_payments.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'viabill_payments_login_form';
  }

  /**
   * Build the form.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    // If we already have the keys, show them
    // or let the merchant re-login if needed.
    $config = $this->config('viabill_payments.settings');
    $existing_api_key = $config->get('api_key') ?? '';
    $existing_api_secret = $config->get('api_secret') ?? '';
    $existing_viabill_script = $config->get('viabill_script') ?? '';

    $form['description'] = [
      '#markup' => $this->t('<p>Use this form to log in ViaBill. Once successful, the API key and secret are stored in Drupal configuration.</p>'),
    ];

    // Change the default "Save configuration" text to "Login".
    $form['actions']['submit']['#value'] = $this->t('Login');

    // Login Form.
    $form['email'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email'),
      '#description' => $this->t('Enter your ViaBill account email.'),
      '#required' => TRUE,
    ];

    $form['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#description' => $this->t('Enter your ViaBill account password.'),
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Validate email, password, etc. if needed.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    // Basic example checks:
    $email = $form_state->getValue('email');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $form_state->setErrorByName('email', $this->t('Please enter a valid email address.'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * On submit, call the external ViaBill service to log in or register.
   * Then store the returned API key and secret in config.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $email = $form_state->getValue('email');
    $password = $form_state->getValue('password');

    $login_data = [
      'email'    => $form_state->getValue('email'),
      'password' => $form_state->getValue('password'),
    ];

    $error_msg = NULL;
    $response = $this->login($login_data, $error_msg);

    if (empty($error_msg)) {
      $this->config('viabill_payments.settings')
        ->set('api_key', $response['key'])
        ->set('api_secret', $response['secret'])
        ->set('viabill_pricetag', $response['pricetagScript'])
        ->set('viabill_email', $email)
        ->save();

      // Also update the payment gateway plugin configuration.
      $payment_gateway_storage = \Drupal::entityTypeManager()->getStorage('commerce_payment_gateway');
      $payment_gateway = $payment_gateway_storage->load('viabill_payments');

      if ($payment_gateway) {
        $configuration = $payment_gateway->getPlugin()->getConfiguration();
        $configuration['api_key'] = $response['key'];
        $configuration['api_secret'] = $response['secret'];
        $configuration['viabill_pricetag'] = $response['pricetagScript'];

        $payment_gateway->setPluginConfiguration($configuration);
        $payment_gateway->save();
      }

      $this->messenger()->addStatus($this->t('Successfully logged in and obtained ViaBill credentials.'));
    }
    else {
      // Use a placeholder for the error message.
      $this->messenger()->addStatus($this->t('Login failed: @error', ['@error' => $error_msg]));
    }

    // Redirect to ViaBill Payments gateway configuration page.
    $form_state->setRedirectUrl(Url::fromRoute('entity.commerce_payment_gateway.edit_form', ['commerce_payment_gateway' => 'viabill_payments']));
  }

  /**
   * Login merchant (ViaBill Account)
   */
  protected function login($login_data, &$error_msg) {
    $gateway = new ViaBillGateway();
    $helper = new ViaBillHelper();

    $response = $gateway->loginViabillUser($login_data);
    if (!empty($response['error'])) {
      $error_msg = $response['error'];
      return FALSE;
    }

    return $response;
  }

}
