<?php
/**
* Plugin Name: AllSecure Open Woo
* Plugin URI: https://help.allsecure.xyz
* Author: AllSecure 
* Description: WooCommerce Plugin for accepting payments through AllSecure OPEN Platform.
* Version:     1.7.1
* Tested up to: 5.3.8
* WC requires at least: 3.0
* WC tested up to: 4.9.1
* @package AllSecure Open Woo
*/

include_once( dirname( __FILE__ ) . '/includes/allsecure_additional.php' );
add_action('plugins_loaded', 'init_woocommerce_allsecure', 0);
define( 'ALLSECURE_VERSION', '1.7.1' );
/**
 * Init payment gateway
 */
function init_woocommerce_allsecure() {
	load_plugin_textdomain( 'allsecure_woo', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/');
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) { return; }
	class woocommerce_allsecure extends WC_Payment_Gateway {
		
		/**
		 * Main function
		 */
		public function __construct() {
			global $woocommerce;
			
			$this->id				= 'allsecure';
			$this->method_title 	= __( 'AllSecure Credit Cards', 'allsecure_woo' );
			$this->method_description = __( 'AllSecure Credit Cards payments', 'allsecure_woo' );
			$icon_html			= $icon_html = $this->get_icon();
			$this->icon			= $icon_html;
			$this->screen 		= plugins_url( 'screen.png', __FILE__ );
			$this->has_fields 	= false;
			$this->init_form_fields(); // Load the form fields.
			$this->init_settings(); // Load the settings.
			
			/* Define user set variables */
			$this->title			= __( 'Credit Cards', 'allsecure_woo' );  
			$this->description		= $this->settings['description'];
			$this->operation		= $this->settings['operation_mode'];
			$this->supports			= array( 'refunds' );
			if($this->operation		== 'live'){
				$this->allsecure_url	= "https://eu-prod.oppwa.com";
				$this->ACCESS_TOKEN		= $this->settings['access_token'];
				$this->ENTITY_ID		= $this->settings['entity_id'];
			}
			else {
				$this->allsecure_url	= "https://eu-test.oppwa.com";
				$this->ACCESS_TOKEN		= $this->settings['test_access_token'];
				$this->ENTITY_ID		= $this->settings['test_entity_id'];
				$this->forceResultCode	= $this->settings['force_code'];
			}
			$this->paymentType		= $this->settings['payment_type'];
			$this->bannerType		= $this->settings['banner_type'];
			$this->merchantName		= $this->settings['merchant_name'];
			$this->merchantEmail	= $this->settings['merchant_email'];
			$this->shopURL			= $this->settings['shop_url'];
			$this->allsecureID		= $this->settings['allsecure_id'];
			$this->version_tracker	= $this->settings['version_tracker'];
			if ($this->settings['card_supported'] !== NULL) {
				$this->cards = implode(' ', $this->settings['card_supported']);
			}
			$this->cards_supported	= $this->get_option('card_supported');
			$this->merchantBank	= $this->settings['merchant_bank'];
			$this->woocommerce_version 	= $woocommerce->version;
			$this->return_url   	= add_query_arg( 'wc-api', 'allsecure_payment', home_url( '/' ) ) ;

			/* Actions */
			add_action( 'init', array($this, 'allsecure_process') );
			add_action( 'woocommerce_api_allsecure_payment', array( $this, 'allsecure_process' ) );
			add_action( 'woocommerce_receipt_allsecure', array($this, 'receipt_page') );
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_order_refunded', array($this, 'action_woocommerce_order_refunded'), 10, 2 );
			add_action( 'woocommerce_order_action_allsecure_capture', array( $this, 'capture_payment' ) );
			add_action( 'woocommerce_order_action_allsecure_reverse', array( $this, 'reverse_payment' ) );
			/* add_action to parse values to thankyou*/
			add_action( 'woocommerce_thankyou', array( $this, 'report_payment' ) );
			add_action( 'woocommerce_thankyou', array( $this,'parse_value_allsecure_success_page') );
			/* add_action to parse values when error */
			add_action( 'woocommerce_before_checkout_form', array( $this,'parse_value_allsecure_error'), 10 );
			/* Lets check for SSL */
			add_action( 'admin_notices', array( $this, 'do_ssl_check' ) );
			wp_enqueue_style( 'allsecure_style', plugin_dir_url( __FILE__ ) . 'assets/css/allsecure-style.css', array(), null );
		}
		/* Woocommerce Admin Panel Option Manage AllSecure Settings here. */
		public function admin_options() {
			echo '<h2>'. __('AllSecure Payment Gateway', 'allsecure_woo') .' </h2>';
			echo '<p>'. __('AllSecure Configuration Settings', 'allsecure_woo') .'</p>';
			echo '<table class="form-table">';
			$this->generate_settings_html();
			echo '</table>';
			wc_enqueue_js( "jQuery( function( $ ) {
				var allsecure_test_fields = '#woocommerce_allsecure_test_entity_id, #woocommerce_allsecure_test_access_token, #woocommerce_allsecure_force_code'; var allsecure_live_fields = '#woocommerce_allsecure_entity_id, #woocommerce_allsecure_access_token'; $( '#woocommerce_allsecure_operation_mode' ).change(function(){ $( allsecure_test_fields + ',' + allsecure_live_fields ).closest( 'tr' ).hide(); if ( 'live' === $( this ).val() ) { $( '#woocommerce_allsecure_live_credentials, #woocommerce_allsecure_live_credentials + p' ).show(); $( '#woocommerce_allsecure_test_credentials, #woocommerce_allsecure_test_credentials + p' ).hide(); $( allsecure_live_fields ).closest( 'tr' ).show();} else { $( '#woocommerce_allsecure_live_credentials, #woocommerce_allsecure_live_credentials + p' ).hide(); $( '#woocommerce_allsecure_test_credentials, #woocommerce_allsecure_test_credentials + p' ).show(); $( allsecure_test_fields ).closest( 'tr' ).show(); } }).change();});" );
		}
		/* Initialise AllSecure Woo Plugin Settings Form Fields */
		function init_form_fields() {
			$this->form_fields = array(
				'enabled' => array(
					'title'	=> __( 'Enable/Disable', 'allsecure_woo' ),
					'type' 	=> 'checkbox',
					'label' => __( 'Enable AllSecure', 'allsecure_woo' ),
					'default' => 'yes'
				),
				'operation_mode' => array(
					'title' => __('Operation Mode', 'allsecure_woo'),
					'default' => 'test',
					'description' => __('You can switch between different environments, by selecting the corresponding operation mode', 'allsecure_woo'),
					'type' => 'select',
					'class' => 'allsecure_mode',
					'options' => array(
						'test' => __('Test Mode', 'allsecure_woo'),
						'live' => __('Live Mode', 'allsecure_woo'),
					)
				),
				'description' => array(
					'title' => __( 'Description', 'allsecure_woo' ),
					'type' => 'text',
					'description' => __( 'This controls the description which the user sees during checkout', 'allsecure_woo' ),
					'default' => __('Pay via AllSecure', 'allsecure_woo'),
					'desc_tip'    => true
				),
				'test_credentials' => array(
					'title'       => __('API Test Credentials', 'allsecure_woo' ),
					'type'        => 'title',
					'description' => __('Enter your AllSecure Test API Credentials to process transactions via AllSecure. You can get your AllSecure Test Credentials via <a href="mailto:support@allsecpay.com">AllSecure Support</a>', 'allsecure_woo' ),
				),
				'test_entity_id' => array(
					'title' => __('Test Entity ID', 'allsecure_woo' ),
					'type' => 'text',
					'description' => __('Please enter your AllSecure Test Entity ID. This is needed in order to take the payment', 'allsecure_woo' ),
					'default' => '',
					'desc_tip'    => true
				),
				'test_access_token' => array(
					'title' => __('Test Access Token', 'allsecure_woo' ),
					'type' => 'text',
					'description' => __('Please enter your AllSecure Test Access Token. This is needed in order to take the payment', 'allsecure_woo'),
					'default' => '',
					'desc_tip'    => true
				),
				'force_code' => array(
					'title' => __('Force Result Code', 'allsecure_woo' ),
					'type' => 'text',
					'description' => __('Simulate any result code', 'allsecure_woo' ),
					'default' => '',
					'desc_tip'    => true
				),
				'live_credentials' => array(
					'title'       => __('API LIVE Credentials', 'allsecure_woo' ),
					'type'        => 'title',
					'description' => __('Enter your AllSecure Live API Credentials to process transactions via AllSecure. You can get your AllSecure Live Credentials via <a href="mailto:support@allsecpay.com">AllSecure Support</a>', 'allsecure_woo' ), 
				),
				'entity_id' => array(
					'title' => __('Entity ID', 'allsecure_woo' ),
					'type' => 'text',
					'description' => __('Please enter your AllSecure Entity ID. This is needed in order to the take payment', 'allsecure_woo' ),
					'default' => '',
					'desc_tip'    => true
				),
				'access_token' => array(
					'title' => __('Access Token', 'allsecure_woo' ),
					'type' => 'text',
					'description' => __('Please enter your AllSecure Access Token. This is needed in order to take the payment', 'allsecure_woo' ),
					'default' => '',
					'desc_tip'    => true
				),
				'hr' => array(
					'title' => __( '<hr>', 'allsecure_woo' ),
					'type' => 'title', 
				),
				'merchant_name' => array(
					'title' => __('Merchant Info', 'allsecure_woo' ),
					'type' => 'text',
					'description' => __('Please enter your Merchant Info to be displayed near the payment form', 'allsecure_woo' ),
					'default' => '',
					'desc_tip'    => true
				),
				'merchant_email' => array(
					'title' => __('Merchant Email', 'allsecure_woo' ),
					'type' => 'text',
					'description' => __( 'Please enter your Merchant Email', 'allsecure_woo' ),
					'default' => '',
					'desc_tip'    => true
				),
				'shop_url' => array(
					'title' => __( 'Shop URL', 'allsecure_woo' ),
					'type' => 'text',
					'description' => __( 'Please enter your Shop URL', 'allsecure_woo' ),
					'default' => '',
					'desc_tip'    => true
				),
				'allsecure_id' => array(
					'title' => __( 'Allsecure ID', 'allsecure_woo' ),
					'type' => 'text',
					'description' => __( 'Please enter your ID as displayed on your invoice from AllSecure', 'allsecure_woo' ),
					'default' => '',
					'desc_tip'    => true
				),
				'version_tracker' => array(
					'title'	=> __( 'Version Tracker', 'allsecure_woo' ),
					'type' 	=> 'checkbox',
					'label' => __( 'Enable Version Tracker', 'allsecure_woo' ),
					'description' => __( 'When enabled, you accept to share your IP, email address, etc with us', 'allsecure_woo' ),
					'default' => 'yes',
					'desc_tip'    => true
				),
				'banner_type' => array(
					'title' => __('Banner Type', 'allsecure_woo'),
					'default' => 'none',
					'description' => __('Choose type of banner you wish to use', 'allsecure_woo'),
					'type' => 'select',
					'options' => array(
						'none' => __('No Banner', 'allsecure_woo'),
						'light' => __('Light Background', 'allsecure_woo'),
						'dark' => __('Dark Background', 'allsecure_woo'),
					),
					'desc_tip'    => true
				),
							
				'hr2' => array(
					'title' => __( '<hr>', 'allsecure_woo' ),
					'type' => 'title',
				),
				'payment_type' => array(
					'title' => __('Payment Type', 'allsecure_woo'),
					'default' => 'PA',
					'description' => __('Choose type of Payment you wish to use', 'allsecure_woo'),
					'type' => 'select',
					'options' => array(
						'PA' => __('Preauthorization (PA)', 'allsecure_woo'),
						'DB' => __('Debit (DB)', 'allsecure_woo'),
					),
					'desc_tip'    => true
				),
				
				'card_supported' => array(
					'title' => __('Accepted Cards', 'allsecure_woo'),
					'default' => array(
						'VISA',
						'MASTER',
						'MAESTRO'
					),
					'description' => __( 'Contact support at <a href="support@allsecpay.com">support@allsecpay.com</a> if you want to accept AMEX transactions', 'allsecure_woo' ),
					'css'   => 'height: 100%;',
					'type' => 'multiselect',
					'options' => array(
						'VISA' => __('VISA', 'allsecure_woo'),
						'MASTER' => __('MASTER', 'allsecure_woo'),
						'MAESTRO' => __('MAESTRO', 'allsecure_woo'),
						'AMEX' => __('AMEX', 'allsecure_woo'),
						'DINERS' => __('DINERS', 'allsecure_woo'),
						'JCB'  => __('JCB', 'allsecure_woo'),
					)
				),
				'merchant_bank' => array(
					'title' => __('Acquiring Partner', 'allsecure_woo'),
					'default' => 'ucbs',
					'description' => __('Acquirer where holding Merchant Account', 'allsecure_woo'),
					'type' => 'select',
					'options' => array(
						'ucbs' => __('UniCredit Bank Serbia', 'allsecure_woo'),
						'wcrd' => __('WireCard Bank', 'allsecure_woo'),
						'payv' => __('Payvision', 'allsecure_woo'),
					),
					'desc_tip'    => true
				),
			);
		}
		/* Custom Credit Card Icons on a checkout page */
		public function get_icon() {
			$icon_html = $this->allsecure_get_icon();
			return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id );
		}
		
		function allsecure_get_icon() {
			$icon_html = ''; 
					
			if ( isset( $this->cards_supported ) && '' !== $this->cards_supported ) {
				foreach ( $this->cards_supported as $card ) {
					$icons = plugins_url(). '/allsecure_woo/assets/images/general/' .strtolower( $card ) . '.svg';
					$icon_html .= '<img src="' . $icons . '" alt="' . strtolower( $card ) . '" title="' . strtolower( $card ) . '" style="height:30px; margin:5px 0px 5px 10px; vertical-align: middle; float: none; display: inline; text-align: right;" />';
				}
			}
			return $icon_html;
		}
		
		/* Adding AllSecure Payment Button in checkout page. */
		function payment_fields() {
			if ($this->description) echo wpautop(wptexturize($this->description));
		}
		/* Creating AllSecure Payment Form. */
		public function generate_allsecure_payment_form( $order_id ){
			global $woocommerce;
			$order = new WC_Order( $order_id );
			/* Required Order Details */
			$amount 	= $order->get_total();
			$currency 	= get_woocommerce_currency();
			$billingcity = $order->get_billing_city();
			$billingcountry = $order->get_billing_country();
			$billingstreet1 = $order->get_billing_address_1();
			$billingpostcode = $order->get_billing_postcode();
			$customeremail = $order->get_billing_email();
			$url = $this->allsecure_url."/v1/checkouts";
			$data = "entityId=".$this->ENTITY_ID 
			. "&amount=".$amount 
			. "&currency=".$currency 
			. "&merchantTransactionId=". $order_id 
			. "&billing.city=". $billingcity
			. "&billing.country=". $billingcountry
			. "&billing.street1=". $billingstreet1
			. "&billing.postcode=". $billingpostcode
			. "&customer.email=". $customeremail
			. "&customParameters[MERCHANT_name]=".$this->merchantName 
			. "&customParameters[MERCHANT_email]=".$this->merchantEmail 
			. "&customParameters[MERCHANT_shopurl]=".$this->shopURL 
			. "&customParameters[MERCHANT_pluginID]=".$this->allsecureID 
			. "&customParameters[MERCHANT_pg]=asbpgw"
			. "&paymentType=".$this->paymentType ;
			if($this->operation == 'test'){
				$data .= "&customParameters[forceResultCode]=".$this->forceResultCode ;
			}
			$head_data = "Bearer ". $this->ACCESS_TOKEN;
			$gtwresponse = wp_remote_post(
				$url,
				array(
					'headers' => array('Authorization' => $head_data),
					'body' => $data,
					'sslverify' => 'true', // this should be set to true in production
					'timeout' => 100,
				)
			);
			if( !is_wp_error( $gtwresponse ) ) {
				$status = json_decode($gtwresponse['body']);
				echo '<script>console.log('. json_encode($status) .');</script>';
				if(isset($status->id)){
					$lang = strtolower(substr(get_bloginfo('language'), 0, 2 ));
					echo '<script src="'.$this->allsecure_url.'/v1/paymentWidgets.js?checkoutId='.$status->id.'"></script>';
					echo '<script src="https://code.jquery.com/jquery-2.1.4.min.js" type="text/javascript"></script>';
					echo '<script type="text/javascript">
						var wpwlOptions = { 
						style: "plain",'. PHP_EOL;
						if($lang == 'sr'){
							echo 'labels: {
									cardHolder: "Korisnik kartice",
									cvv: "Zaštitni kod",
									brand: " ", 
									expiryDate: "Datum isteka", 
									cardNumber: "Broj kartice", 
									cvvHint:"Tri broja na poleđini vaše kartice." , 
									cvvHintAmex:"Četiri broja na poleđini vaše kartice." 
								},
								errorMessages: { 
									cardHolderError: "'. __('Cardholder not valid', 'allsecure_woo' ).'", 
									cardNumberError: "Pogrešan broj kartice", 
									cvvError: "Pogrešan CVV", 
									expiryMonthError: "Pogrešan datum", 
									expiryYearError: "Pogrešan datum"
								},';
						} else {
							echo 'locale: "' . $lang .'",'. PHP_EOL;
						}
					echo 'showCVVHint: true,
						brandDetection: true,
						showPlaceholders: true,
						autofocus : "card.number",
						showLabels: false,
						onReady: function() { 
						$(".wpwl-group-cvv").after( $(".wpwl-group-cardHolder").detach());';
						if($lang == 'sr'){
							echo '$(".wpwl-button-pay").html("Plati");'. PHP_EOL;
						}
						echo 'var BannerHtml = "<div id=\"banner\"><div id=\"d1\"><img border=\"0\" src=\"' . plugins_url() .'/allsecure_woo/assets/images/general/3dmcsc.svg\" alt=\"MasterCard SecureCode\"></div><div id=\"d2\"><img border=\"0\" src=\"' . plugins_url() .'/allsecure_woo/assets/images/general/3dvbv.svg\" alt=\"VerifiedByVISA\"></div><div id=\"d3\"><img border=\"0\" src=\"' .plugins_url(). '/allsecure_woo/assets/images/general/3dasb.svg\" alt=\"Secure Payment\"></div></div>";
						$("form.wpwl-form-card").find(".wpwl-group-submit").after(BannerHtml);
						$(".wpwl-group-cardNumber").after( $(".wpwl-group-cardHolder").detach());
						var visa = $(".wpwl-brand:first").clone().removeAttr("class").attr("class", "wpwl-brand-card wpwl-brand-custom wpwl-brand-VISA");
						var master = $(visa).clone().removeClass("wpwl-brand-VISA").addClass("wpwl-brand-MASTER");
						var maestro = $(visa).clone().removeClass("wpwl-brand-VISA").addClass("wpwl-brand-MAESTRO");
						var amex = $(visa).clone().removeClass("wpwl-brand-VISA").addClass("wpwl-brand-AMEX");
						var diners = $(visa).clone().removeClass("wpwl-brand-VISA").addClass("wpwl-brand-DINERS");
						var jcb = $(visa).clone().removeClass("wpwl-brand-VISA").addClass("wpwl-brand-JCB");
						$(".wpwl-brand:first")';
						if (strpos($this->cards, 'VISA') !== false) { echo '.after($(visa))';}
						if (strpos($this->cards, 'MASTER') !== false) { echo '.after($(master))';}
						if (strpos($this->cards, 'MAESTRO') !== false) { echo '.after($(maestro))';}
						if (strpos($this->cards, 'AMEX') !== false) { echo '.after($(amex))';}
						if (strpos($this->cards, 'DINERS') !== false) { echo '.after($(diners))';}
						if (strpos($this->cards, 'JCB') !== false) { echo '.after($(jcb))';}
						echo ';'.PHP_EOL;
						echo 'var buttonCancel = "<button type=\"button\" class=\"wpwl-button wpwl-button-cancel\" onclick=\"history.go(0)\">'.
						__('Cancel', 'allsecure_woo' ). '</button>"
						$( "form.wpwl-form" ).find( ".wpwl-button" ).before( buttonCancel );
						},
						onChangeBrand: function(e){
							$(".wpwl-brand-custom").css("opacity", "0.2");
							$(".wpwl-brand-" + e).css("opacity", "5"); 
						},
						onBeforeSubmitCard: function(){
							if ($(".wpwl-control-cardHolder").val()==""){
								$(".wpwl-control-cardHolder").addClass("wpwl-has-error");
								$(".wpwl-wrapper-cardHolder").append("<div class=\"wpwl-hint wpwl-hint-cardHolderError\">'.
								__('Cardholder not valid', 'allsecure_woo' ) . '</div>");
							return false; }
						return true;}
					} </script>';
					echo '<div id="allsecure_merchant_info"><b>'.__('Merchant', 'allsecure_woo' ).': </b>'. $this->merchantName.'</div>';
					if ($this->operation == 'test') echo '<div class="testmode">' . __( 'This is the TEST MODE. No money will be charged', 'allsecure_woo' ) . '</div>';
					echo '<div id="allsecure_payment_container">';
					echo '<form action="'.$this->return_url.'" class="paymentWidgets">'. $this->cards .'</form>';
					echo '</div>';
				}
				else {
					wc_add_notice( __( 'Configuration error', 'allsecure_woo' ) .': '.$status->result->code, 'error' );
					wp_safe_redirect( wc_get_page_permalink( 'cart' ) );
				}
			}
		}
		/* Updating the Payment Status and redirect to Success/Fail Page */
		public function allsecure_process(){
			global $woocommerce;
			global $wpdb;
			if(isset($_GET['resourcePath'])) {
				$url = $this->allsecure_url.$_GET['resourcePath'];
				$url .= "?entityId=". $this->ENTITY_ID;
				$head_data = "Bearer ". $this->ACCESS_TOKEN;
				$gtwresponse = wp_remote_post(
					$url,
					array (
						'method' => 'GET',
						'headers' => array( 'Authorization' => $head_data ),
						'sslverify' => true,
						'timeout'	=> 45 
					)
				); 
				if( !is_wp_error( $gtwresponse ) ) {
					$merchant_info = $this->allsecure_get_general_merchant_info();
					$tracking_url = 'https://api.allsecure.xyz/tracker';
					if ( $this->version_tracker == "yes") {
						wp_remote_post( $tracking_url, array( 'body' => $merchant_info,'timeout' => 100,));
					}
					$status = json_decode($gtwresponse['body']);
					$success_code = array('000.000.000', '000.000.100', '000.100.110', '000.100.111', '000.100.112', '000.300.000');
					$order = new WC_Order($status->merchantTransactionId );
					if(in_array($status->result->code, $success_code)){
						$order->payment_complete( $status->id );
						$order->add_order_note(sprintf(__('AllSecure Transaction Successful. The Transaction ID was %s and Payment Status %s. Payment type was %s. Autorizacion bank code: %s', 'allsecure_woo'), $status->id, $status->result->description, $status->paymentType, $status->resultDetails->ConnectorTxID3 ));
						$message = sprintf(__('Transaction Successful. The status message <b>%s</b>', 'allsecure_woo'), $status->result->description );
						$bank_code = $status->resultDetails;
						$astrxId = $status->id;
						update_post_meta( $order->id, 'bank_code', $bank_code );
						update_post_meta( $order->id, 'AS-TrxId', $astrxId );
						WC()->cart->empty_cart();
						/* Add content to the WC emails. */
						$query = parse_url( $this->get_return_url( $order ), PHP_URL_QUERY);
						if ($query) {
							$url = $this->get_return_url( $order ) . '&astrxId=' . $astrxId ;
						} 
						else {
							$url = $this->get_return_url( $order ) . '?astrxId=' . $astrxId ;
						}
						wp_redirect($url);
						if(in_array($status->paymentType, array('DB') )){
							$order->update_status('wc-accepted');
							if (version_compare(WOOCOMMERCE_VERSION, "2.6") <= 0) {
								$order->reduce_order_stock();
							}else {
								wc_reduce_stock_levels($orderid);
							}
						}
						else {
							$order->update_status('wc-preauth');
						}
					exit;
					}
					else {
						include_once( dirname( __FILE__ ) . '/includes/error_list.php' );
						$resp_code = $status->result->code ;
						$resp_code_translated = array_key_exists($resp_code, $errorMessages) ? $errorMessages[$resp_code] :  $status->result->description ;
						$order->add_order_note(sprintf(__('AllSecure Transaction Failed. The Transaction Status %s', 'allsecure_woo'), $status->result->description ));
						$astrxId = $status->id;
						$query = parse_url( wc_get_checkout_url( $order ), PHP_URL_QUERY);
						if ($query) {
							$url = wc_get_checkout_url($order) . '&astrxId=' . $astrxId ;
						} 
						else {
							$url = wc_get_checkout_url($order) . '?astrxId=' . $astrxId ;
						}
						wp_redirect($url);
						exit;
					}
				}
			}
		}
		/* Process the payment and return the result */
		function process_payment( $order_id ) {
			$order = new WC_Order( $order_id );
			if($this->woocommerce_version >= 2.1){
				$redirect = $order->get_checkout_payment_url( true );
				}
			else{
				$redirect = add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(get_option('woocommerce_pay_page_id'))));
			}
			return array(
				'result' 	=> 'success',
				'redirect'	=> $redirect
			);
		}
		/* Capture the payment and return the result */
		function capture_payment( $order_id ) {
			global $woocommerce;
			$order = wc_get_order( $order_id );
			$order_data = $order->get_data();
			$order_trx_id_allsecure = $order_data['transaction_id'];
			$amount 	= $order->get_total();
			$currency 	= get_woocommerce_currency();
			$response = json_decode($this->capture_request($order_trx_id_allsecure, $amount, $currency));
			$success_code = array('000.000.000', '000.000.100', '000.100.110', '000.100.111', '000.100.112', '000.300.000');
			if(in_array($response->result->code, $success_code)){
				$order->add_order_note(sprintf(__('AllSecure Capture Processed Successful. The Capture ID was %s and Request Status => %s', 'allsecure_woo'), $response->id, $response->result->description ));
				$order->update_status('wc-accepted');
				return true;
			}
			else {
				$order->add_order_note(sprintf(__('AllSecure Capture Request Failed. The Capture Status => %s. Code is == %s', 'allsecure_woo'), $response->result->description, $response->result->code ));
				return false;
			}
			return false;
		}
		function capture_request($order_trx_id_allsecure, $amount, $currency) {
			$url = $this->allsecure_url."/v1/payments/".$order_trx_id_allsecure;
			$data = "entityId=".$this->ENTITY_ID .
			"&amount=".$amount .
			"&currency=".$currency .
			"&paymentType=CP";
			$head_data = "Bearer ". $this->ACCESS_TOKEN;
			$gtwresponse = wp_remote_post(
				$url,
				array(
					'headers' => array('Authorization' => $head_data),
					'body' => $data,
					'sslverify' => 'true', // this should be set to true in production
					'timeout' => 100,
				)
			);
			if( !is_wp_error( $gtwresponse ) ) {
				return $gtwresponse['body'];
			}
		echo 'Error in communication';
		}
		/* Reverse the payment and return the result */
		function reverse_payment( $order_id ) {
			global $woocommerce;
			$order = wc_get_order( $order_id );
			$order_data = $order->get_data();
			$order_trx_id_allsecure = $order_data['transaction_id'];
			$amount 	= $order->get_total();
			$currency 	= get_woocommerce_currency();
			$response = json_decode($this->reverse_request($order_trx_id_allsecure, $amount, $currency));
			$success_code = array('000.000.000', '000.000.100', '000.100.110', '000.100.111', '000.100.112', '000.300.000');
			if(in_array($response->result->code, $success_code)){
				$order->add_order_note(sprintf(__('AllSecure Reversal Processed Successful. The Reversal ID was: %s and Request Status: %s', 'allsecure_woo'), $response->id, $response->result->description ));
				$order->update_status('wc-reversed');
				return true;
			}
			else {
				$order->add_order_note(sprintf(__('AllSecure Reversal Request Failed. The Reversal Status: %s. Code is: %s', 'allsecure_woo'), $response->result->description, $response->result->code ));
				return false;
			}
			return false;
		}
		function reverse_request($order_trx_id_allsecure, $amount, $currency) {
			$url = $this->allsecure_url."/v1/payments/".$order_trx_id_allsecure;
			$data = "entityId=".$this->ENTITY_ID .
			"&amount=".$amount .
			"&currency=".$currency .
			"&paymentType=RV";
			$head_data = "Bearer ". $this->ACCESS_TOKEN;
			$gtwresponse = wp_remote_post(
				$url,
				array(
					'headers' => array('Authorization' => $head_data),
					'body' => $data,
					'sslverify' => 'true', // this should be set to true in production
					'timeout' => 100,
				)
			);
			if( !is_wp_error( $gtwresponse ) ) {
				return $gtwresponse['body'];
			}
			echo 'Error in communication';
		}
		/* receipt_page */
		function receipt_page( $order ) {
			$this->generate_allsecure_payment_form( $order );
		}
		/* Refund the payment and return the result */
		function process_refund( $order_id, $amount = null, $reason = ''  ) {
			global $woocommerce;
			$order = wc_get_order( $order_id );
			$order_data = $order->get_data();
			$order_trx_id_allsecure = $order_data['transaction_id']; 
			$amount 	= $order->get_total();
			$currency 	= get_woocommerce_currency();
			$response = json_decode($this->refund_request($order_trx_id_allsecure, $amount, $currency));
			$success_code = array('000.000.000', '000.000.100', '000.100.110', '000.100.111', '000.100.112', '000.300.000');
			if(in_array($response->result->code, $success_code)){
				$order->add_order_note(sprintf(__('AllSecure Refund Processed Successful. The Refund ID: %s and Request Status: %s', 'allsecure_woo'), $response->id, $response->result->description ));
				$order->update_status('wc-refunded');
				return true;
			}
			else {
				$order->add_order_note(sprintf(__('AllSecure Refund Request Failed. The Refund Status: %s', 'allsecure_woo'), $response->result->description ));
				return false;
			}
			return false;
		}
		function refund_request($order_trx_id_allsecure, $amount, $currency) {
			$url = $this->allsecure_url."/v1/payments/".$order_trx_id_allsecure;
			$data = "entityId=".$this->ENTITY_ID .
			"&amount=".$amount .
			"&currency=".$currency .
			"&paymentType=RF";
			$head_data = "Bearer ". $this->ACCESS_TOKEN;
			$gtwresponse = wp_remote_post(
				$url,
				array(
					'headers' => array('Authorization' => $head_data),
					'body' => $data,
					'sslverify' => 'true', // this should be set to true in production
					'timeout' => 100,
				)
			);
			if( !is_wp_error( $gtwresponse ) ) {
				return $gtwresponse['body'];
			}
			echo 'Error in communication';
		}
		// Custom function not required by the Gateway
		public function do_ssl_check() {
			if( $this->enabled == "yes" ) {
				if( get_option( 'woocommerce_force_ssl_checkout' ) == "no" ) {
					echo "<div class=\"error\"><p>". sprintf( __('<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>', 'allsecure_woo') , $this->method_title, admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) ."</p></div>";
				}
			}
		}
		/**
		 * Get general merchants info for version tracker
		 ** @return array
		 */
		protected function allsecure_get_general_merchant_info() {
			$merchant['transaction_mode'] = $this->settings['operation_mode'];
			$merchant['ip_address'] = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : ''; // input var okay.
			$merchant['shop_version'] = WC()->version;
			$merchant['plugin_version'] = constant( 'ALLSECURE_VERSION' );
			$merchant['merchant_id'] = $this->settings['allsecure_id'];
			$merchant['merchant_name'] = $this->settings['merchant_name'];
			$merchant['merchant_entity'] = $this->settings['test_entity_id'];
			$merchant['shop_system'] = 'WOOCOMMERCE';
			$merchant['email'] = $this->settings['merchant_email'];
			$merchant['shop_url'] = $this->settings['shop_url'];
			return $merchant;
		}
		
		public function get_selected_cards (){
			return $this->cards;
		}
		public function get_selected_banner (){
			return $this->bannerType;
		}
		public function get_merchant_bank (){
			return $this->merchantBank;
		}
		// gateway transaction details on declined trx
		function parse_value_allsecure_error($order_id){
			if ( isset($_REQUEST['astrxId']) ) {
				$astrxId = $_REQUEST['astrxId'];
				$gwresponse = json_decode($this->report_payment($order_id));
				include_once( dirname( __FILE__ ) . '/includes/error_list.php' );
				$resp_code = $gwresponse->result->code ;
				$resp_code_translated = array_key_exists($resp_code, $errorMessages) ? $errorMessages[$resp_code] :  $gwresponse->result->description ;
				echo "<div class='woocommerce'><ul class='woocommerce-error' role='alert'>
				<li>" . sprintf(__('Transaction Unsuccessful. The status message <b>%s</b>', 'allsecure_woo'), $resp_code_translated ) ." * </li>
				</ul>
				</div>";
			}
		}
		// gateway transaction details on a thankyou page
		function parse_value_allsecure_success_page($order_id){
			if ( isset($_REQUEST['astrxId']) ) {
				$astrxId = $_REQUEST['astrxId'];
				$gwresponse = json_decode($this->report_payment($order_id));
				echo "<div class='woocommerce-order'>
				<h2>". __('Transaction details', 'allsecure_woo').": </h2>
				<ul class='woocommerce-order-overview woocommerce-thankyou-order-details order_details'>
					<li class='woocommerce-order-overview__email email'>" . __('Transaction Codes', 'allsecure_woo' );
						if ($this->merchantBank == 'ucbs') {
							if ( isset($gwresponse->resultDetails->ConnectorTxID3) ) {
								echo('<strong>'. $gwresponse->resultDetails->ConnectorTxID3.'</strong>');
							}
						} else if ($this->merchantBank == 'wcrd') {
							if ( isset($gwresponse->resultDetails->ConnectorTxID1) ) {
								echo('<strong>'. $gwresponse->resultDetails->ConnectorTxID1.'</strong>');
							}
						} else if ($this->merchantBank == 'payv') {
							if ( isset($gwresponse->resultDetails->ConnectorTxID1) ) {
								echo('<strong>'. $gwresponse->resultDetails->ConnectorTxID1.'</strong>');
							}
						} else {
							if ( isset($gwresponse->resultDetails->ConnectorTxID1) ) {
								echo('<strong>'. $gwresponse->resultDetails->ConnectorTxID1.'</strong>');
							}
							if ( isset($gwresponse->resultDetails->ConnectorTxID2) ) {
								echo('<strong>'. $gwresponse->resultDetails->ConnectorTxID2.'</strong>');
							}
							if ( isset($gwresponse->resultDetails->ConnectorTxID3) ) {
								echo('<strong>'. $gwresponse->resultDetails->ConnectorTxID3.'</strong>');
							}
						}
				
				echo "</li>
						<li class='woocommerce-order-overview__email email'>". __('Card Type', 'allsecure_woo' ) .
						"<strong>". $gwresponse->paymentBrand ." *** ".$gwresponse->card->last4Digits."</strong>
						</li>
						<li class='woocommerce-order-overview__email email'>" . __('Payment Type', 'allsecure_woo' ) .
						"<strong>".$gwresponse->paymentType."</strong>
						</li>";
						
						/* adapt timestamp to shop timezone */
						$shopTimezone = wc_timezone_string();
						$responseTimestamp = new DateTime($gwresponse->timestamp, new DateTimeZone('UTC'));
						$responseTimestamp->setTimezone(new DateTimeZone($shopTimezone));
						echo "<li class='woocommerce-order-overview__email email'>"
						.  __('Transaction Time', 'allsecure_woo' ) .
						"<strong>".$responseTimestamp ->format('d-m-Y H:i:s O')."</strong>
						</li>
					</ul>
				</div>";
			}
		}
		function report_payment($order_id) {
			$url = $this->allsecure_url."/v1/query/";
			$url .= $_REQUEST['astrxId'];
			$url .= "?entityId=".$this->ENTITY_ID ;
			$head_data = "Bearer ". $this->ACCESS_TOKEN;
			$gtwresponse = wp_remote_post(
				$url,
				array(
					'method' => 'GET',
					'headers' => array('Authorization' => $head_data),
					'sslverify' => 'true', // this should be set to true in production
					'timeout' => 100,
				)
			);
			if( !is_wp_error( $gtwresponse ) ) {
				return $gtwresponse['body'];
			}
		}
	}
	
	/* Add the gateway to WooCommerce */
	function add_allsecure_gateway( $methods ) {
		$methods[] = 'woocommerce_allsecure'; return $methods;
	}
	add_filter('woocommerce_payment_gateways', 'add_allsecure_gateway' );

	/* Add the gateway footer to WooCommerce */
	function allsecurefooter() {
		$selected_allsecure = new woocommerce_allsecure;
		$selectedBanner = $selected_allsecure->get_selected_banner();
		$selectedCards = $selected_allsecure->get_selected_cards();
		$selectedBank = $selected_allsecure->get_merchant_bank();
		if (strpos($selectedCards, 'VISA') !== false) { $visa =  '<img src="' . plugin_dir_url( __FILE__ ) . 'assets/images/'.$selectedBanner.'/visa.svg">';} else $visa = '';
		if (strpos($selectedCards, 'MASTER') !== false) { $master = '<img src="' . plugin_dir_url( __FILE__ ) . 'assets/images/'.$selectedBanner.'/master.svg">';} else $master = '';
		if (strpos($selectedCards, 'MAESTRO') !== false) { $maestro = '<img src="' . plugin_dir_url( __FILE__ ) . 'assets/images/'.$selectedBanner.'/maestro.svg">';} else $maestro = '';
		if (strpos($selectedCards, 'AMEX') !== false) {$amex = '<img src="' . plugin_dir_url( __FILE__ ) . 'assets/images/'.$selectedBanner.'/amex.svg">';} else $amex = '';
		if (strpos($selectedCards, 'DINERS') !== false) {$diners = '<img src="' . plugin_dir_url( __FILE__ ) . 'assets/images/'.$selectedBanner.'/diners.svg">';} else $diners = '';
		if (strpos($selectedCards, 'JCB') !== false) {$jcb = '<img src="' . plugin_dir_url( __FILE__ ) . 'assets/images/'.$selectedBanner.'/jcb.svg">';} else $jcb = '';
		$allsecure  = '<a href="https://www.allsecure.rs" target="_new"><img src="' . plugin_dir_url( __FILE__ ) . 'assets/images/'.$selectedBanner.'/allsecure.svg"></a>';
		if ($selectedBank == 'ucbs') {
			$bankUrl = 'https://www.unicreditbank.rs/rs/pi.html'; 
		} else 
			$bankUrl = '#';
		$bank = '<a href="'.$bankUrl.'" target="_new" ><img src="' . plugin_dir_url( __FILE__ ) . 'assets/images/'.$selectedBanner.'/'.$selectedBank.'.svg"></a>';
		$vbv = '<img src="' . plugin_dir_url( __FILE__ ) . 'assets/images/'.$selectedBanner.'/visa_secure.svg">';
		$mcsc = '<img src="' . plugin_dir_url( __FILE__ ) . 'assets/images/'.$selectedBanner.'/mc_idcheck.svg">';
		$allsecure_cards = $visa.''.$master.''.$maestro.''.$diners.''.$amex.''.$jcb ;
		if ($selectedBanner !== 'none') {
			$allsecure_banner = '
				<div id="allsecure_banner">
					<div id="allsecure">'.$allsecure.'</div>
					<div id="allsecure_threeds">'.$vbv.' '.$mcsc.'</div>
					<div id="allsecure_bank">'.$bank.'</div>
					<div id="allsecure_cards">'.$allsecure_cards.'</div>
				</div>';
			echo  $allsecure_banner;
		}
	}
	add_filter('wp_footer', 'allsecurefooter'); 
}
