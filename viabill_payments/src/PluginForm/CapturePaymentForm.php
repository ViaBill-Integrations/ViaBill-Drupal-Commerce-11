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
    
    // Get the payment gateway plugin to access helper methods.
    /** @var \Drupal\viabill_payments\Plugin\Commerce\PaymentGateway\ViaBillPayments $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    
    // Calculate remaining capturable amount.
    $authorized_amount = $payment->getAmount();
    $captured_amount = $this->getCapturedAmount($payment, $payment_gateway_plugin);
    $remaining_amount = $authorized_amount->subtract($captured_amount);

    $form['amount'] = [
      '#type' => 'commerce_price',
      '#title' => $this->t('Amount'),
      '#default_value' => $remaining_amount->toArray(),
      '#required' => TRUE,
      '#description' => $this->t('Authorized: @authorized<br/>Already captured: @captured<br/>Remaining: @remaining', [
        '@authorized' => $authorized_amount->__toString(),
        '@captured' => $captured_amount->__toString(),
        '@remaining' => $remaining_amount->__toString(),
      ]),
    ];

    return $form;
  }

  /**
   * Get the total captured amount for a payment.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment entity.
   * @param mixed $payment_gateway_plugin
   *   The payment gateway plugin.
   *
   * @return \Drupal\commerce_price\Price
   *   The total captured amount.
   */
  protected function getCapturedAmount($payment, $payment_gateway_plugin) {
    // Use reflection to access the protected method.
    $reflection = new \ReflectionClass($payment_gateway_plugin);
    $method = $reflection->getMethod('getCapturedAmount');
    $method->setAccessible(TRUE);
    return $method->invoke($payment_gateway_plugin, $payment);
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
