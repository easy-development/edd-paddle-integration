<?php
/*
Plugin Name: Easy Digital Downloads Paddle Integration
Plugin URI: http://www.easy-development.com
Description: Easy Digital Downloads Paddle Integration
Version: 1.0.0
Author: Andrei-Robert Rusu
Author URI: http://www.easy-development.com
*/

class EDDPaddleIntegration {

  protected static $_instance;

  public $textDomain        = 'edd_paddle_integration';
  public $pluginGatewayID   = 'paddle';
  public $pluginGatewayName = 'Paddle';

  public $popupShortCodeName             = 'edd_paddle_integration_popup_shortcode';

  public $postPurchaseLinkStorage        = 'edd_paddle_integration_purchase_link';
  public $postPurchaseTypeStorage        = 'edd_paddle_integration_purchase_type';
  public $paddleAPIRoot                  = 'https://vendors.paddle.com/';
  public $paddleAPIActionGeneratePayLink = 'api/2.0/product/generate_pay_link';
  public $apiURLIdentifier               = 'edd_paddle_api';

  public $paddlePopupCheckoutSetupJS = '//paddle.s3.amazonaws.com/checkout/checkout-woocommerce.js';

  public static function instance() {
    if(self::$_instance == null)
      self::$_instance = new self();

    return self::$_instance;
  }

  public function __construct() {
    $this->_handleEDDIntegration();

    add_action('init', array($this, '_wpInitHook'));
    add_shortcode($this->popupShortCodeName, array($this, 'receiptPayShortCode'));
  }

  private function _handleEDDIntegration() {
    add_filter('edd_payment_gateways', array($this, 'gatewayRegistration'));
    add_action('edd_' . $this->pluginGatewayID . '_cc_form', array($this, 'gatewayCreditCardForm'));
    add_action('edd_gateway_' . $this->pluginGatewayID, array($this, 'gatewayProcessPayment'));
    add_filter('edd_settings_gateways', array($this, 'eddSettingsIntegration'));
  }

  public function gatewayRegistration($gateways) {
    $gateways[$this->pluginGatewayID] = array(
        'admin_label'    => $this->pluginGatewayName,
        'checkout_label' => __('Checkout using Paddle', $this->textDomain)
    );

    return $gateways;
  }

  public function gatewayCreditCardForm() {
    return;
  }

  public function gatewayProcessPayment($purchaseInformation) {
    global $edd_options;

    $paymentPrice = number_format($purchaseInformation['price'], 2);

    $payment = array(
        'price'        => $paymentPrice,
        'date'         => $purchaseInformation['date'],
        'user_email'   => $purchaseInformation['user_email'],
        'purchase_key' => $purchaseInformation['purchase_key'],
        'currency'     => edd_get_currency(),
        'downloads'    => $purchaseInformation['downloads'],
        'cart_details' => $purchaseInformation['cart_details'],
        'user_info'    => $purchaseInformation['user_info'],
        'status'       => 'pending'
    );

    $payment = edd_insert_payment($payment);

    $paddleProductTitle = $edd_options[$this->pluginGatewayID . '_paddle_product_name'];

    if(count($purchaseInformation['cart_details']) == 1)
      $paddleProductTitle = $purchaseInformation['cart_details'][0]['name'];

    $popupCheckout = (isset($edd_options[$this->pluginGatewayID . '_popup_checkout']) ? $edd_options[$this->pluginGatewayID . '_popup_checkout'] : 0);

    $paddleInformation = array(
      'title'             => $paddleProductTitle,
      'image_url'         => (isset($edd_options[$this->pluginGatewayID . '_paddle_product_image']) ? $edd_options[$this->pluginGatewayID . '_paddle_product_image'] : ''),
      'vendor_id'         => $edd_options[$this->pluginGatewayID . '_paddle_vendor_id'],
      'vendor_auth_code'  => trim($edd_options[$this->pluginGatewayID . '_paddle_api_key']),
      'customer_email'    => $purchaseInformation['user_email'],
      'is_popup'          => ($popupCheckout ? 'true' : 'false'),
      'passthrough'       => $payment,
      'prices'            => array(edd_get_currency() . ':' . $paymentPrice),
      'discountable'      => 0,
      'quantity_variable' => 0,
      'webhook_url'       => get_bloginfo('url') . '?' . build_query(array(
          $this->apiURLIdentifier => 'true',
          'payment_id'            => $payment,
          'token'                 => md5(trim($edd_options[$this->pluginGatewayID . '_paddle_api_key']))
      ))
    );

    if($popupCheckout == true) {
      $paddleInformation['parent_url'] = get_permalink( $edd_options['success_page'] );
    }

    $apiCallResponse = wp_remote_post($this->paddleAPIRoot . $this->paddleAPIActionGeneratePayLink, array(
        'method'      => 'POST',
        'timeout'     => 45,
        'httpversion' => '1.1',
        'blocking'    => true,
        'body'        => $paddleInformation,
        'sslverify'   => false
    ));


    if (is_wp_error($apiCallResponse)) {
      edd_set_error('api_fail', __('Something went wrong. Unable to get API response.', $this->textDomain));
      error_log('Paddle error. Unable to get API response. Method: ' . __METHOD__ . ' Error message: ' . $apiCallResponse->get_error_message());
    } else {
      $oApiResponse = json_decode($apiCallResponse['body']);
      if ($oApiResponse && $oApiResponse->success === true) {
        edd_empty_cart();

        update_post_meta($payment, $this->postPurchaseLinkStorage, $oApiResponse->response->url);
        update_post_meta($payment, $this->postPurchaseTypeStorage, $popupCheckout ? 'popup' : 'redirect');

        if($popupCheckout == false) {
          wp_redirect($oApiResponse->response->url,302);
          exit;
        } else {
          wp_redirect($paddleInformation['parent_url'], 302);
        }
      } else {
        edd_set_error('api_fail', __('Something went wrong. Check if Paddle account is properly integrated.', $this->textDomain));
        if (is_object($oApiResponse)) {
          error_log('Paddle error. Error response from API. Method: ' . __METHOD__ . ' Errors: ' . print_r($oApiResponse->error, true));
        } else {
          error_log('Paddle error. Error response from API. Method: ' . __METHOD__ . ' Response: ' . print_r($apiCallResponse, true));
        }
      }
    }
  }

  public function eddSettingsIntegration($settings) {
    $gateWaySettings = array(
        array(
            'id'   => $this->pluginGatewayID . '_gateway_settings',
            'name' => __('Paddle Payment Processing', $this->textDomain),
            'desc' => __('Configure the gateway settings', $this->textDomain),
            'type' => 'header'
        ),
        array(
            'id'   => $this->pluginGatewayID . '_paddle_vendor_id',
            'name' => __('Paddle Vendor ID', $this->textDomain),
            'desc' => __('', $this->textDomain),
            'type' => 'text',
            'size' => 'regular'
        ),
        array(
            'id'   => $this->pluginGatewayID . '_paddle_api_key',
            'name' => __('Paddle API Key', $this->textDomain),
            'desc' => __('', $this->textDomain),
            'type' => 'text',
            'size' => 'regular'
        ),
        array(
            'id'   => $this->pluginGatewayID . '_paddle_product_name',
            'name' => __('Default Product name', $this->textDomain),
            'desc' => __('If user orders more than 1 product, this name will be used', $this->textDomain),
            'type' => 'text',
            'size' => 'regular'
        ),
        array(
            'id'   => $this->pluginGatewayID . '_paddle_product_image',
            'name' => __('Paddle Product Image', $this->textDomain),
            'desc' => __('Image used within the paddle checkout', $this->textDomain),
            'type' => 'text',
            'size' => 'regular'
        ),
        array(
            'id'   => $this->pluginGatewayID . '_popup_checkout',
            'name' => __('Overlay Checkout', $this->textDomain),
            'desc' => __('Check this if you want to use the overlay paddle checkout, make sure you use the shortcode on the receipt page : ', $this->textDomain)
                  . '[' . $this->popupShortCodeName . ']',
            'type' => 'checkbox',
            'size' => 'regular'
        )
    );

    return array_merge($settings, $gateWaySettings);
  }

  public function receiptPayShortCode($atts) {
    global $edd_receipt_args;

    $edd_receipt_args = shortcode_atts( array(
        'error'           => __( 'Sorry, trouble retrieving payment receipt.', 'edd' ),
        'price'           => true,
        'discount'        => true,
        'products'        => true,
        'date'            => true,
        'notes'           => true,
        'payment_key'     => false,
        'payment_method'  => true,
        'payment_id'      => true
    ), $atts, 'edd_receipt' );

    $session = edd_get_purchase_session();


    if ( isset( $_GET['payment_key'] ) ) {
      $payment_key = urldecode( $_GET['payment_key'] );
    } elseif ( $edd_receipt_args['payment_key'] ) {
      $payment_key = $edd_receipt_args['payment_key'];
    } else if ( $session ) {
      $payment_key = $session['purchase_key'];
    }

    if ( ! isset( $payment_key ) )
      return '';

    $paymentID = edd_get_purchase_id_by_key( $payment_key );

    if(get_post_meta($paymentID, $this->postPurchaseTypeStorage, true) != 'popup')
      return '';

    if(edd_get_payment_completed_date($paymentID) !== false)
      return '';


    wp_enqueue_script('edd_paddle_integration', $this->paddlePopupCheckoutSetupJS, array('jquery'));

    $content = '';
    $content .= '<p>' . __('Thank you for your order, please click the button below to pay with Paddle.', 'edd-gateway-paddle-inline-checkout') . '</p>';
    $content .= '<button class="paddle_button button alt" href="' . get_post_meta($paymentID, $this->postPurchaseLinkStorage, true) . '" target="_blank">Pay Now!</button>&nbsp;';

    return $content;
  }

  public function _wpInitHook() {
    if(isset($_GET['edd_paddle_api']) || isset($_POST['edd_paddle_api']))
      $this->_processPaddleAPIWebHook();
  }

  private function _processPaddleAPIWebHook() {
    global $edd_options;

    $token      = isset($_POST['token']) ? $_POST['token'] : $_GET['token'];
    $payment_id = isset($_POST['payment_id']) ? $_POST['payment_id'] : $_GET['payment_id'];


    if($token != md5(trim($edd_options[$this->pluginGatewayID . '_paddle_api_key'])))
      exit("Error");

    edd_update_payment_status(intval($payment_id), 'publish');

    exit("Done");
  }

  private function _arrayKeyValueImplode( $glue, $separator, $array ) {
    if ( ! is_array( $array ) ) return $array;
    $string = array();
    foreach ( $array as $key => $val ) {
      if ( is_array( $val ) )
        $val = implode( ',', $val );
      $string[] = "{$key}{$glue}{$val}";

    }
    return implode( $separator, $string );
  }

}

EDDPaddleIntegration::instance();