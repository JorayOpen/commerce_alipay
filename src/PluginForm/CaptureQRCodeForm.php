<?php

namespace Drupal\commerce_alipay\PluginForm;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Omnipay\Omnipay;

class CaptureQRCodeForm extends PaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['barcode'] = [
      '#type' => 'number',
      '#title' => t('Barcode'),
      '#size' => 18
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValue($form['#parents']);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $app_id = $payment_gateway_plugin->getConfiguration()['app_id'];
    $private_key = $payment_gateway_plugin->getConfiguration()['private_key'];
    $public_key = $payment_gateway_plugin->getConfiguration()['public_key'];

    /** @var \Omnipay\Alipay\AopF2FGateway $gateway */
    $gateway = Omnipay::create('Alipay_AopF2F');
    if ($payment_gateway_plugin->getMode() == 'test') {
      $gateway->sandbox(); // set to use sandbox endpoint
    }
    $gateway->setAppId($app_id);
    $gateway->setSignType('RSA2');
    $gateway->setPrivateKey($private_key);
    $gateway->setAlipayPublicKey($public_key);

    /** @var \Omnipay\Alipay\Requests\AopTradePayRequest $request */
    $request = $gateway->capture();
    $request->setBizContent([
      'out_trade_no' => (string) $payment->getOrderId() . 'test',
      'scene'        => 'bar_code',
      'auth_code'    => $values['barcode'],  //购买者手机上的付款二维码
      'subject'      => \Drupal::config('system.site')->get('name') . t(' Order: ') . $payment->getOrderId(),
      'total_amount' => (float) $payment->getAmount()->getNumber(),
    ]);

    try {
      /** @var \Omnipay\Alipay\Responses\AopTradePayResponse $response */
      $response = $request->send();

      // TODO: need to handle AopTradeCancelResponse

      if($response->isPaid()){
         // Payment is successful
        $result = $response->getAlipayResponse();
        $form_state->setTemporary($result);

      } else {
         // Payment is not successful
        \Drupal::logger('commerce_alipay')->error(print_r($response->getData(), TRUE));
        $form_state->setError($form['barcode'], t('Commerce Alipay cannot process your payment: ') . $response->getMessage());
      }
    } catch (\Exception $e) {
       // Payment is not successful
      \Drupal::logger('commerce_alipay')->error($e->getMessage());
      $form_state->setError($form['barcode'], t('Commerce Alipay is having problem to connect to Alipay servers: ') . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $result = $form_state->getTemporary();
    if ($result['code'] == '10000') {
      /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
      $payment = $this->entity;
      /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
      $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
      $payment_gateway_plugin->createPayment($result);
    }
  }

}
