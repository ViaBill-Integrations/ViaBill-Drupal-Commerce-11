<?php

namespace Drupal\viabill_payments\PluginForm;

use Drupal\commerce_payment\PluginForm\PaymentGatewayFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_price\Price;

/**
 * Class for generating the capture button and functionality.
 */
class CapturePaymentForm extends PaymentGatewayFormBase {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    // Remove this line that's causing the error (line 15):
    // $form = parent::buildConfigurationForm($form, $form_state);.

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */    
    $payment = $form_state->get('payment') ?: $this->entity;
    $amount = $payment->getAmount();

    $form['amount'] = [
      '#type' => 'commerce_price',
      '#title' => $this->t('Amount'),
      '#default_value' => $amount->toArray(),
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValue($form['#parents']);
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */    
    $payment = $form_state->get('payment') ?: $this->entity;
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $amount = Price::fromArray($values['amount']);
    $payment_gateway_plugin->capturePayment($payment, $amount);
  }

}
