<?php

namespace Drupal\commerce_alipay\PluginForm;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_alipay\Plugin\Commerce\PaymentGateway\CustomerScanQRCodePay;
use Omnipay\Omnipay;
use Com\Tecnick\Barcode\Barcode;
use Drupal\Core\Render\Markup;

/**
 * @link https://github.com/lokielse/omnipay-alipay/wiki/Aop-Face-To-Face-Gateway
 */
class QRCodePaymentForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $app_id = $payment_gateway_plugin->getConfiguration()['app_id'];
    $private_key = $payment_gateway_plugin->getConfiguration()['private_key'];
    $public_key = $payment_gateway_plugin->getConfiguration()['public_key'];
    $order = $payment->getOrder();

    /** @var \Omnipay\Alipay\AopF2FGateway $gateway */
    $gateway = Omnipay::create('Alipay_AopF2F');
    if ($payment_gateway_plugin->getMode() == 'test') {
      $gateway->sandbox(); // set to use sandbox endpoint
    }
    $gateway->setAppId($app_id);
    $gateway->setSignType('RSA2');
    $gateway->setPrivateKey($private_key);
    $gateway->setAlipayPublicKey($public_key);
    $gateway->setNotifyUrl($payment_gateway_plugin->getNotifyUrl()->toString());

    $request = $gateway->purchase();
    /** @var \Drupal\commerce_price\Price $price */
    $price = $payment->getAmount();
    $request->setBizContent([
      'subject'      => \Drupal::config('system.site')->get('name') . t(' Order: ') . $order->id(),
      'out_trade_no' => strval($order->id()),
      'total_amount' => (float) $price->getNumber()
    ]);

    /** @var \Omnipay\Alipay\Responses\AopTradePreCreateResponse $response */
    $response = $request->send();

    if ($response->getAlipayResponse('code') == '10000'){  // Success
      // 获取收款二维码内容
      $code_url = $response->getQrCode();
    } else { // For any reason, we cannot get a preorder made by WeChat service
      $form['commerce_message'] = [
        '#markup' => '<div class="checkout-help">' . t('Alipay QR-Code is not avaiable at the moment. Message from Alipay service: ' . $response->getAlipayResponse('sub_code') . ' ' .$response->getAlipayResponse('sub_msg')),
        '#weight' => -10,
      ];

      return $form;
    }

    $barcode = new Barcode();
    // generate a barcode
    $bobj = $barcode->getBarcodeObj(
      'QRCODE,H',                     // barcode type and additional comma-separated parameters
      $code_url,          // data string to encode
      -4,                             // bar height (use absolute or negative value as multiplication factor)
      -4,                             // bar width (use absolute or negative value as multiplication factor)
      'black',                        // foreground color
      array(-2, -2, -2, -2)           // padding (use absolute or negative values as multiplication factors)
    )->setBackgroundColor('white'); // background color

    $form['commerce_message'] = [
      '#markup' => '<div class="checkout-help">' . t('Please scan the QR-Code below to complete the payment on your mobile Alipay App.') ,
      '#weight' => -10,
    ];

    $form['qrcode'] = [
      '#markup' => Markup::create($bobj->getHtmlDiv()),
    ];

    return $form;
  }

}
