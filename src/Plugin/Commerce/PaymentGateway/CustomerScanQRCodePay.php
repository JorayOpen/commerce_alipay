<?php

namespace Drupal\commerce_alipay\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;
use Omnipay\Omnipay;

/**
 * Provides Alipay gateway for customer to scan QR-Code to pay.
 * @link https://doc.open.alipay.com/docs/doc.htm?treeId=194&articleId=105072&docType=1
 *
 * @CommercePaymentGateway(
 *   id = "alipay_customer_scan_qrcode_pay",
 *   label = "Alipay - Customer Scan QR-Code to Pay",
 *   display_label = "Alipay - Customer Scan QR-Code to Pay",
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_alipay\PluginForm\QRCodePaymentForm",
 *   }
 * )
 */
class CustomerScanQRCodePay extends OffsitePaymentGatewayBase implements SupportsRefundsInterface{

  const KEY_URI_PREFIX = 'private://commerce_alipay/';

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'app_id' => '',
        'public_key' => '',
        'private_key' => '',
        'public_key_uri' => '',
        'private_key_uri' => ''
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['app_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('支付宝分配给开发者的应用ID'),
      '#default_value' => $this->configuration['app_id'],
      '#required' => TRUE,
    ];

    $form['private_key'] = [
      '#type' => 'textarea',
      '#title' => $this->t('开发者应用私钥'),
      '#description' => $this->t('请填写开发者私钥去头去尾去回车，一行字符串'),
      '#default_value' => $this->configuration['private_key'],
      '#required' => TRUE,
    ];

    $form['public_key'] = [
      '#type' => 'textarea',
      '#title' => $this->t('支付宝公钥'),
      '#description' => $this->t('请填写支付宝公钥，一行字符串'),
      '#default_value' => $this->configuration['public_key'],
      '#required' => TRUE,
    ];

    $form['public_key_uri'] = [
      '#type' => 'hidden',
      '#default_value' => $this->configuration['public_key_uri']
    ];

    $form['private_key_uri'] = [
      '#type' => 'hidden',
      '#default_value' => $this->configuration['private_key_uri']
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {

    $values = $form_state->getValue($form['#parents']);
    if (!empty($values['public_key'])) {
      // Check the file directory for storing cert/key files
      $path = self::KEY_URI_PREFIX;
      $dir = file_prepare_directory($path, FILE_CREATE_DIRECTORY);
      if (!$dir) {
        $form_state->setError($form['public_key'], $this->t('Commerce Alipay Pay cannot find your private file system. Please make sure your site has private file system configured!'));
        return;
      }

      $new_uri = $values['app_id'] . md5($values['public_key']);

      if (empty($this->configuration['public_key_uri'])){
      } else {
        file_unmanaged_delete(self::KEY_URI_PREFIX . $this->configuration['public_key_uri']);
      }
      // We regenerate pem file in case the files were missing during server migration
      $updated = file_unmanaged_save_data($values['public_key'], self::KEY_URI_PREFIX . $new_uri);
      if ($updated) {
        $values['public_key_uri'] = $new_uri;
      } else {
        $form_state->setError($form['public_key'], $this->t('Commerce Alipay cannot save your public key into a file. Please make sure your site has private file system configured!'));
      }
    }

    if (!empty($values['private_key'])) {
      // Check the file directory for storing cert/key files
      $path = self::KEY_URI_PREFIX;
      $dir = file_prepare_directory($path, FILE_CREATE_DIRECTORY);
      if (!$dir) {
        $form_state->setError($form['private_key'], $this->t('Commerce Alipay cannot find your private file system. Please make sure your site has private file system configured!'));
        return;
      }

      $new_uri = $values['app_id'] . md5($values['private_key']);

      if (empty($this->configuration['private_key_uri'])) {
      } else {
        file_unmanaged_delete(self::KEY_URI_PREFIX . $this->configuration['private_key_uri']);
      }
      // We regenerate pem file in case the files were missing during server migration
      $updated = file_unmanaged_save_data($values['private_key'], self::KEY_URI_PREFIX . $new_uri);
      if ($updated) {
        $values['private_key_uri'] = $new_uri;
      } else {
        $form_state->setError($form['private_key'], $this->t('Commerce Alipay cannot save your private key into a file. Please make sure your site has private file system configured!'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['app_id'] = $values['app_id'];
      $this->configuration['public_key'] = $values['public_key'];
      $this->configuration['private_key'] = $values['private_key'];
      $this->configuration['public_key_uri'] = $values['app_id'] . md5($values['public_key']);
      $this->configuration['private_key_uri'] = $values['app_id'] . md5($values['private_key']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    if (!in_array($payment->getState()->value, ['capture_completed', 'capture_partially_refunded'])) {
      throw new \InvalidArgumentException(t('Only payments in the "capture_completed" and "capture_partially_refunded" states can be refunded.'));
    }
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    // Validate the requested amount.
    $balance = $payment->getBalance();
    if ($amount->greaterThan($balance)) {
      throw new InvalidRequestException(sprintf("Can't refund more than %s.", $balance->__toString()));
    }

    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $app_id = $payment_gateway_plugin->getConfiguration()['app_id'];
    $mch_id = $payment_gateway_plugin->getConfiguration()['mch_id'];
    $key = $payment_gateway_plugin->getConfiguration()['key'];
    $sub_appid = $payment_gateway_plugin->getConfiguration()['appid'];
    $sub_mch_id = $payment_gateway_plugin->getConfiguration()['mch_id'];

    $cert_path = drupal_realpath(self::KEY_URI_PREFIX . $payment_gateway_plugin->getConfiguration()['certpem_uri']);
    $key_path = drupal_realpath(self::KEY_URI_PREFIX . $payment_gateway_plugin->getConfiguration()['keypem_uri']);

    if (!$cert_path || !$key_path) {
      throw new \InvalidArgumentException(t('Could not load the apiclient_cert.pem or apiclient_key.pem files, which are required for WeChat Refund. Did you configure them?'));
    }
    $options = [
      // 前面的appid什么的也得保留哦
      'app_id' => $app_id,
      // ...
      // payment
      'payment' => [
        'merchant_id'        => $mch_id,
        'key'                => $key,
        'cert_path'          => $cert_path, // XXX: 绝对路径！！！！
        'key_path'           => $key_path,      // XXX: 绝对路径！！！！
        //'notify_url'         => '默认的订单回调地址',       // 你也可以在下单时单独设置来想覆盖它
        // 'device_info'     => '013467007045764',
        // 'sub_app_id'      => '',
        // 'sub_merchant_id' => '',
        // ...
      ],
    ];
    $app = new Application($options);
    $wechat_pay = $app->payment;

    $result = $wechat_pay->refund($payment->getOrderId(), $payment->getOrderId() . date("zHis"), floatval($payment->getOrder()->getTotalPrice()->getNumber()) * 100, floatval($amount->getNumber()) * 100);

    if (!$result->return_code == 'SUCCESS' || !$result->result_code == 'SUCCESS'){
      // For any reason, we cannot get a preorder made by WeChat service
      throw new \InvalidRequestException(t('Alipay Service cannot approve this request: ') . $result->err_code_des);
    }

    // Perform the refund request here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
    $remote_id = $payment->getRemoteId();
    $number = $amount->getNumber();

    $old_refunded_amount = $payment->getRefundedAmount();
    $new_refunded_amount = $old_refunded_amount->add($amount);
    if ($new_refunded_amount->lessThan($payment->getAmount())) {
      $payment->state = 'capture_partially_refunded';
    }
    else {
      $payment->state = 'capture_refunded';
    }

    $payment->setRefundedAmount($new_refunded_amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {

    $app_id = $this->getConfiguration()['app_id'];
    $public_key_path = drupal_realpath(self::KEY_URI_PREFIX . $this->getConfiguration()['public_key_uri']);
    $private_key_path = drupal_realpath(self::KEY_URI_PREFIX . $this->getConfiguration()['private_key_uri']);

    /** @var \Omnipay\Alipay\AopF2FGateway $gateway */
    $gateway = Omnipay::create('Alipay_AopF2F');
    $gateway->setAppId($app_id);
    $gateway->setSignType('RSA2');
    $gateway->setPrivateKey($private_key_path);
    $gateway->setAlipayPublicKey($public_key_path);

    $request = $gateway->completePurchase();
    $request->setParams($_POST); //Optional

    try {
      /** @var \Omnipay\Alipay\Responses\AopCompletePurchaseResponse $response */
      $response = $request->send();

      // Payment is successful
      if($response->isPaid()){

        $data = $response->getData();
        if ($this->getMode()) {
          \Drupal::logger('commerce_alipay')->notice($data->toString());
        }

        $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
        $payment = $payment_storage->create([
          'state' => 'capture_completed',
          'amount' => new Price(strval($data['total_amount']), 'CNY'),
          'payment_gateway' => $this->entityId,
          'order_id' => $data['out_trade_no'],
          'test' => $this->getMode() == 'test',
          'remote_id' => $data['trade_no'],
          'remote_state' => $data['trade_status'],
          'authorized' => REQUEST_TIME
        ]);
        $payment->save();
        die('success'); //The response should be 'success' only
      }else{
        // Payment is not successful
        die('fail');
      }
    } catch (\Exception $e) {
      // Payment is not successful
      \Drupal::logger('commerce_alipay')->error($e->getMessage());
      die('fail');
    }
  }

}
