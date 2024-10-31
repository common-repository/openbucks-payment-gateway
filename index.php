<?php
/*
Plugin Name: Openbucks Payment Gateway
Plugin URI:
Description: Openbucks Gift Card payment gateway.
Version: 1.1.4
Author: Openbucks&reg;
Author URI: http://www.openbucks.com

Copyright: © 2019 Openbucks
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

defined('ABSPATH') || exit;

add_action('plugins_loaded', 'woocommerce_openbucks_init', 0);

function openbucks_missing_wc_notice() {
    /* translators: 1. URL link. */
    echo '<div class="error"><p><strong>' . sprintf(esc_html__('Openbucks@reg; requires WooCommerce to be installed and active. You can download %s here.', 'openbucks-gateway-woocommerce'), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>') . '</strong></p></div>';
}

function woocommerce_openbucks_init() {

    load_plugin_textdomain('openbucks-gateway-woocommerce', false, plugin_basename(dirname(__FILE__)) . '/languages');

    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'openbucks_missing_wc_notice');
        return;
    }

    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    define('WC_OPENBUCKS_SANDBOX_URL', 'https://demo-pay.openbucks.com/pwgc_uri_v3.php');
    define('WC_OPENBUCKS_PRODUCTION_URL', 'https://pay.openbucks.com/pwgc_uri_v3.php');

    /**
     * Gateway class
     */
    class WC_Openbucks_Gateway extends WC_Payment_Gateway {

        /**
         * Whether or not logging is enabled
         *
         * @var bool
         */
        private static $log_enabled = false;

        /**
         * Logger instance
         *
         * @var WC_Logger
         */
        private static $log = false;

        /**
         * Private clone method to prevent cloning of the instance
         *
         * @return void
         */
        private function __clone() {}

        /**
         * Private unserialize method to prevent unserializing of the instance.
         *
         * @return void
         */
        private function __wakeup() {}

        public function __construct(){
            // Go wild in here
            $this->id           = 'openbucks';
            $this->method_title = __('Openbucks&reg;', 'openbucks-gateway-woocommerce');
            $this->has_fields   = true;

            $this->init_form_fields();
            $this->init_settings();

            $this->title              = __('Cash and Gift Cards', 'openbucks-gateway-woocommerce'); //$this->settings['title'];
            $this->description        = __('Securely pay with cash using gift cards from CVS/Pharmacy or Dollar General (<a href="https://www.openbucks.com/consumer/ecommerce.html" target="_blank">learn more</a>).', 'openbucks-gateway-woocommerce'); //$this->settings['description'];
            $this->public_key         = $this->settings['public_key'];
            $this->private_key        = $this->settings['private_key'];
            $this->sandbox            = $this->settings['sandbox'] == 'yes';
            $this->advanced           = $this->settings['advanced'] == 'yes';
            $this->sub_property_id    = $this->getSubProperty('id');
            $this->sub_property_name  = $this->getSubProperty('name');
            $this->sub_property_url   = $this->getSubProperty('url');
            $this->icon               = apply_filters( 'woocommerce_gateway_icon', plugins_url($this->settings['icon'] == 'yes' ? 'assets/button-300x120.png' : 'assets/button-100x40.png' , __FILE__));

            $this->init_form_fields();
            $this->init_settings();

            if ($this->private_key && $this->public_key) {
                $this->method_description = __('Accept payments with Gift Cards via Openbucks&reg;');
            } else {
                $this->method_description = sprintf(__('Start accepting payments with Gift Cards via Openbucks&reg;. <a href="%1$s" target="_blank">Sign up</a> to become Openbucks&reg; merchant and get your Openbucks&reg; keys.', 'openbucks-gateway-woocommerce'), 'https://www.openbucks.com/contact.html');
            }

            self::$log_enabled  = $this->settings['debug'] == 'yes';

            $this->payment_url = $this->sandbox ? WC_OPENBUCKS_SANDBOX_URL : WC_OPENBUCKS_PRODUCTION_URL;
            $this->notify_url = home_url('/wc-api/WC_Openbucks_Gateway') ;

            $this->msg['message'] = "";
            $this->msg['class']   = "";

            add_action('woocommerce_api_wc_openbucks_gateway', array($this, 'check_openbucks_response'));
            add_action('valid-openbucks-request', array($this, 'successful_request'));
            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            } else {
                add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
            }
            add_action('woocommerce_receipt_openbucks', array($this, 'receipt_page'));
            add_action('woocommerce_thankyou_openbucks', array($this, 'thankyou_page'));
        }

        /**
         * Logging method.
         *
         * @param string $message Log message.
         * @param string $level Optional. Default 'info'. Possible values:
         *                      emergency|alert|critical|error|warning|notice|info|debug.
         */
        public static function log($message, $level = 'info') {
            if (self::$log_enabled) {
                if (empty(self::$log)) {
                    self::$log = wc_get_logger();
                }
                self::$log->log($level,$message, array('source' => 'openbucks'));
            }
        }

        function getSubProperty($what) {
            return $this->advanced && isset($this->settings["sub_property_{$what}"]) ? $this->settings["sub_property_{$what}"] : '';
        }

        function init_form_fields(){
            $this->form_fields = array_merge(array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'openbucks-gateway-woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable Openbucks® Payment Module.', 'openbucks-gateway-woocommerce'),
                    'default' => 'no'),

                 'sandbox' => array(
                    'title' => __('Use Sandbox?', 'openbucks-gateway-woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Use Sandbox Openbucks® Payment.', 'openbucks-gateway-woocommerce'),
                    'default' => 'no'),

                // 'title' => array(
                //     'title' => __('Title:', 'openbucks-gateway-woocommerce'),
                //     'type'=> 'test',
                //     'description' => __('This controls the title which the user sees during checkout.', 'openbucks-gateway-woocommerce'),
                //     'default' => __('Cash and Gift Cards', 'openbucks-gateway-woocommerce'),
                //     ),
                // 'description' => array(
                //     'title' => __('Description:', 'openbucks-gateway-woocommerce'),
                //     'type' => 'textarea',
                //     'description' => __('This controls the description which the user sees during checkout.', 'openbucks-gateway-woocommerce'),
                //     'default' => __('Securely pay with cash using gift cards from CVS/Pharmacy or Dollar General (<a href="https://www.openbucks.com/consumer/ecommerce.html" target="_blank">learn more</a>).', 'openbucks-gateway-woocommerce'),
                //     ),
                'public_key' => array(
                    'title' => __('Public Key', 'openbucks-gateway-woocommerce'),
                    'type' => 'text',
                    'description' =>  __('Given to Merchant by Openbucks®', 'openbucks-gateway-woocommerce'),
                    'desc_tip' => true,
                    ),
                'private_key' => array(
                    'title' => __('Private Key', 'openbucks-gateway-woocommerce'),
                    'type' => 'text',
                    'description' =>  __('Given to Merchant by Openbucks®', 'openbucks-gateway-woocommerce'),
                    'desc_tip' => true,
                    ),
                 'advanced' => array(
                    'title' => __('Advanced', 'openbucks-gateway-woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Advanced setup', 'openbucks-gateway-woocommerce'),
                    'description' =>  __('Use this only if instructed by Openbucks®', 'openbucks-gateway-woocommerce'),
                    'desc_tip' => true,
                    'default' => 'no'),
                 'icon' => array(
                    'title' => __('Large icon', 'openbucks-gateway-woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Use large checkout icon', 'openbucks-gateway-woocommerce'),
                    'description' =>  __('Use hi-res large 300x120 cards icon in checkout (standard 100x40)', 'openbucks-gateway-woocommerce'),
                    'desc_tip' => true,
                    'default' => 'no'),
            ), $this->advanced ? array(
                // 'sub_property_id' => array(
                //     'title' => __('Domain name (optional)', 'openbucks-gateway-woocommerce'),
                //     'type' => 'text',
                //     'description' =>  __('Domain name of the site', 'openbucks-gateway-woocommerce'),
                //     'default' => ''
                //     ),
                // 'sub_property_name' => array(
                //     'title' => __('Merchant Name (optional)', 'openbucks-gateway-woocommerce'),
                //     'type' => 'text',
                //     'description' =>  __('Given to Merchant by Openbucks®', 'openbucks-gateway-woocommerce'),
                //     'default' => ''
                //     ),
                'sub_property_url' => array(
                    'title' => __('Postback URL (optional)', 'openbucks-gateway-woocommerce'),
                    'type' => 'text',
                    'description' =>  __('Define this only when instructed by Openbucks®', 'openbucks-gateway-woocommerce'),
                    'default' => ''
                    ),
                // 'preselect_card' => array(
                //     'title' => __('Pre-select card (optional)', 'openbucks-gateway-woocommerce'),
                //     'type' => 'text',
                //     'description' =>  __('Given to Merchant by Openbucks®', 'openbucks-gateway-woocommerce'),
                //     'default' => ''
                //     ),
                // 'force_card' => array(
                //     'title' => __('Force card (optional)', 'openbucks-gateway-woocommerce'),
                //     'type' => 'text',
                //     'description' =>  __('Given to Merchant by Openbucks®', 'openbucks-gateway-woocommerce'),
                //     'default' => ''
                //     ),
            ) : array(), array(
                 'debug' => array(
                    'title' => __('Enable Debug Messages?', 'openbucks-gateway-woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable Debug Messages?', 'openbucks-gateway-woocommerce'),
                    'description' =>  __('Check WooCommerce logs for additional information', 'openbucks-gateway-woocommerce'),
                    'desc_tip' => true,
                    'default' => 'no'),
                )
            );
        }

        /**
         * Admin Panel Options
         **/

        public function admin_options() {
            echo '<h3>'.__('Openbucks&reg; Payment Gateway', 'openbucks-gateway-woocommerce').'</h3>';
            echo '<table class="form-table">';
            $this->generate_settings_html();
            echo '</table>';

        }

        static function wc_lt_30() {
            return version_compare(WC_VERSION, '3.0', '<');
        }

        function create_payment($order) {
            if (!$this->public_key || !$this->private_key) {
                throw new Exception(__('Openbucks&reg; Payment Gateway is not set up properly', 'openbucks-gateway-woocommerce'));
            }

            $currency = self::wc_lt_30() ? $order->get_order_currency() : $order->get_currency();
            $order_id = self::wc_lt_30() ? $order->id : $order->get_id();
            $token = date("Y-m-d H:i:s") . uniqid('', true);
            $amount = $order->get_total();
            $customer_email = self::wc_lt_30() ? $order->billing_email : $order->get_billing_email();
            $anonymous_id = $customer_email ? md5($customer_email) : md5(time());
            $customer_id = self::wc_lt_30() ? $order->customer_user : $order->get_customer_id();
            $merchant_tracking_id = $order_id . "_" . time();

            $post_data = array(
                'req_token' => $token,
                'req_public_key' => $this->public_key,
                'req_merchant_tracking_id' => $merchant_tracking_id,
                'req_item_description' => __('Payment for the order #', 'openbucks-gateway-woocommerce') . $order_id,
                'req_currency_code' => self::wc_lt_30() ? $order->get_order_currency() : $order->get_currency(),
                'req_amount' => $amount,
                'req_customer_anonymous_id' => $anonymous_id,
                'req_success_url' => $order->get_checkout_order_received_url(),
                'req_cancel_url' => $order->get_checkout_payment_url(),
            );

            if ($customer_email) {
                $post_data['req_customer_info_email'] = $customer_email;
            }

            if ($this->preselect_card) {
                $post_data['req_select_card'] = $this->preselect_card;
            }

            if ($this->force_card) {
                $post_data['req_force_cards'] = $this->force_card;
            }

            $domain = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME'];
            $protocol = isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] === 1) || isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ? 'https' : 'http';
            // $port = ($protocol == 'https' && $_SERVER['SERVER_PORT'] == 443) || ($protocol == 'http' && $_SERVER['SERVER_PORT'] == 80) ? '' : ":{$_SERVER['SERVER_PORT']}";

            if ($this->sub_property_id) {
                $post_data['req_sub_property_id'] = $this->sub_property_id;
            } else {
                $post_data['req_sub_property_id'] = $_SERVER['SERVER_NAME'];
            }

            if ($this->sub_property_name) {
                $post_data['req_sub_property_name'] = $this->sub_property_name;
            } else {
                $post_data['req_sub_property_name'] = 'WooCommerce';
            }

            if ($this->sub_property_url) {
                $post_data['req_sub_property_url'] = $this->sub_property_url;
            } else {
                $post_data['req_sub_property_url'] = "{$protocol}://{$domain}/?wc-api=WC_Openbucks_Gateway";
            }

            $product_ids = array();
            $items = $order->get_items();
            foreach ($items as $item) {
                $product_ids[] = $item['product_id'];
            }
            $post_data['req_product_id'] = implode('+', $product_ids);

            if (count($items) === 1) {
                $item = reset($items);
                $product = $item->get_product();
                $post_data['req_item_description'] = __('Payment for ', 'openbucks-gateway-woocommerce') . $product->get_name();
            }

            $billing_address = $order->get_address('billing');
            if (array_reduce($billing_address, function($res, $item) {
                return $res || !!$item;
            }, false)) {
                $post_data['req_customer_info_billing'] = json_encode($billing_address);
            }
            $shipping_address = $order->get_address('shipping');
            if (array_reduce($shipping_address, function($res, $item) {
                return $res || !!$item;
            }, false)) {
                $post_data['req_customer_info_shipping'] = json_encode($shipping_address);
            }

            $post_data['req_hash'] = hash("sha256", $token.$this->private_key.$merchant_tracking_id.$amount.$currency.$post_data['req_force_cards']);

            $headers = apply_filters(
                'woocommerce_stripe_request_headers',
                array(
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept-Encoding' => 'application/json',
                    'User-Agent' => 'Openbucks WooCommerce Gateway (https://woocommerce.com/products/openbucks)',
                    'Referer' => isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null
                )
            );

            $response = wp_safe_remote_post(
                $this->payment_url,
                array(
                    'method'  => 'POST',
                    'headers' => $headers,
                    'body'    => http_build_query($post_data),
                    'timeout' => 70,
                )
            );

            if (is_wp_error($response) || empty($response['body'])) {
                throw new Exception(__( 'There was a problem connecting to the Openbucks&reg; endpoint.', 'openbucks-gateway-woocommerce' ));
            }

            $parsed = json_decode($response['body'], true);

            if ($parsed['errorCode'] != 0) {
                throw new Exception($parsed['errorDescription'], $parsed['errorCode']);
            }

            return $parsed;
        }

        function send_failed_order_email($order_id) {
            $emails = WC()->mailer()->get_emails();
            if (!empty($emails) && !empty($order_id)) {
                $emails['WC_Email_Failed_Order']->trigger($order_id);
            }
        }

        function process_payment($order_id){
            try {
                $order = wc_get_order($order_id);

                $response = $this->create_payment($order);

                $this->log("Successfully inited payment for order #$order_id");

                return array(
                    'result'   => 'success',
                    'redirect' => esc_url_raw($response['redirectUrl']),
                );
            } catch (Exception $e) {
                wc_add_notice($e->getMessage(), 'error');

                $statuses = array('pending', 'failed');

                if ($order->has_status($statuses)) {
                    $this->send_failed_order_email($order_id);
                }

                $this->log("Failed to init payment for order #$order_id", 'error');

                return array(
                    'result'   => 'fail',
                    'redirect' => '',
                );
            }
        }

        function get_ip_address() {
            foreach (array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR') as $key){
                if (array_key_exists($key, $_SERVER) === true){
                    foreach (explode(',', $_SERVER[$key]) as $ip){
                        $ip = trim($ip); // just to be safe

                        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                            return $ip;
                        }
                    }
                }
            }
        }

        function getXMLErrors($errors) {
            $result = "";
            foreach ($errors as $error) {
                $result .= "\n";
                switch ($error->level) {
                    case LIBXML_ERR_WARNING:
                        $result .= "Warning {$error->code}: ";
                        break;
                     case LIBXML_ERR_ERROR:
                        $result .= "Error {$error->code}: ";
                        break;
                    case LIBXML_ERR_FATAL:
                        $result .= "Fatal Error {$error->code}: ";
                        break;
                }
                $result .= trim($error->message);
            }
            libxml_clear_errors();
            return $result;
        }

        function getUniqueSubNode(DOMXPath $xpath, $nodePath, $startNode = null){
            if ($startNode == null){
                $nodes = $xpath->query($nodePath);
            } else {
                $nodes = $xpath->query($nodePath, $startNode);
            }
            if ($nodes->length == 1){
                return $nodes->item(0);
            } else {
                return null;
            }
        }

        function check_openbucks_response() {
            global $woocommerce;

            $msg['class']   = 'error';
            $msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";

            $post_payload = file_get_contents('php://input');

            try {
                $remote_ip = $this->get_ip_address();

                if (!$remote_ip || !in_array($remote_ip, array('52.8.74.160', '52.8.84.218', '52.8.39.2', '52.8.194.58'))) {
                    throw new Exception("Unknown IP address: " . $remote_ip);
                    ;
                }

                // Request must be over SSL
                // if(!isset($_SERVER['HTTPS']) || !$_SERVER['HTTPS']=='on'){
                //     throw new Exception("Request must be over SSL");
                // }

                // Post request expected
                if ($_SERVER['REQUEST_METHOD'] != "POST") {
                    throw new Exception("An HTTP POST was expected");
                }

                // Request must contain data
                if(!strlen($post_payload)) {
                    throw new Exception("HTTP POST XML data is empty.");
                }

                // Request must be shorter than 10K
                if(strlen($post_payload)>10240){
                    throw new Exception("HTTP POST XML data is abnormal (too long).");
                }

                $libxml_error_state = libxml_use_internal_errors(true);

                $xml_response = new DomDocument('1.0', 'UTF-8');
                // Does the posted XML parse OK?
                if(!@$xml_response->loadXML($post_payload)){
                    throw new Exception("XML error: " . $this->getXMLErrors(libxml_get_errors()));
                }

                // Does it validate with the schema?
                $xsd = wp_remote_get("http://cdn.openbucks.com/xsd/pwgc-data-model-v2.xsd");
                if (is_array($xsd) && !is_wp_error($xsd)) {
                    if(!@$xml_response->schemaValidateSource($xsd['body'])) {
                        throw new Exception("XML validation error: " . $this->getXMLErrors(libxml_get_errors()));
                    }
                } else {
                    throw new Exception("Failed to fetch XSD");
                }

                $xpath_response = new DOMXPath($xml_response);
                $xpath_response->registerNamespace("pw", "http://openbucks.com/xsd/pwgc/2.0");

                // We are expecting a response, not a request
                $xml_rsp_response = $this->getUniqueSubNode($xpath_response,"/pw:pwgc/pw:response", null);
                if($xml_rsp_response==null){
                    throw new Exception("A response was expected.");
                }

                // No longer need these
                $xml_rsp_response = null;
                $xpath_response = null;
                $xml_response = null;

                // Use a SimpleXMLElement instead now that we know everything's fine
                $xml_response = new SimpleXMLElement($post_payload);
                $xml_errors = @libxml_get_errors();
                if(count($xml_errors)>0){
                    throw new Exception("XML error: " . getXMLErrors($xml_errors));
                }

                if ($xml_response === false) {
                    throw new Exception("Failed to load XML");
                }

                if (count($xml_response->response->children()) == 0) {
                    throw new Exception("A response was expected.");
                }

                // Extract the <requestID> <error> <payment> XML elements
                list($request_id_element, $error_element, $payload) = $xml_response->response->children();

                // We expect a payment response
                if ($payload->getName() != "payment"){
                    throw new Exception("A payment response was expected.");
                }

                $hash = hash("sha256", $this->public_key . ":" . $payload->transaction->pwgcTrackingID . ":" . $this->private_key);
                if ($hash != $payload->transaction->pwgcHash) {
                    throw new Exception("The authenticity of the Openbucks payment server could not be established.");
                }

                // Check that the public key in the repsonse is yours
                if($this->public_key != $payload->merchantData->publicKey) {
                    throw new Exception("Could not validate public key.");
                }

                $transaction_id = (string) $payload->transaction->transactionID;
                if (is_null($transaction_id)) {
                    throw new Exception("Could not retrieve transaction id from postback");
                }

                $merchant_tranking_id = explode('_', (string)$payload->merchantData->merchantTrackingID);
                $order_id = reset($merchant_tranking_id);
                $amount = floatval($payload->amount->amountValue);
                $currency_code = (string)$payload->amount->currencyCode;

                $order = new WC_Order($order_id);

                if ($order->get_total() != $amount) {
                    throw new Exception("Wrong payment amount: " . $amount);
                }

                $currency = self::wc_lt_30() ? $order->get_order_currency() : $order->get_currency();
                if ($currency != $currency_code) {
                    throw new Exception("Wrong payment currency: " . $currency_code);
                }

                if (intval($error_element->errorCode) != 0) {
                    throw new Exception("Error Processing Request: " . (string) $error_element->errorDescription);
                }

                $msg['message'] = "Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be shipping your order to you soon.";
                $msg['class'] = 'success';
                $order->add_order_note(__('Openbucks payment completed with tnx #', 'openbucks-gateway-woocommerce') . $transaction_id);
                $order->payment_complete();
                $woocommerce->cart->empty_cart();
                $this->log("Payment for order #$order_id complete");
            } catch (Exception $e) {
                $msg['class'] = 'error';
                $msg['message'] = "Thank you for shopping with us. However, the transaction has been declined. " . $e->getMessage();
                $order->add_order_note( __('Payment error: ' . $e->getMessage(), 'openbucks-gateway-woocommerce') );
                $this->log("Payment for order #$order_id failed: {$e->getMessage()}", 'error');
            }

            if (function_exists('wc_add_notice')) {
                wc_add_notice($msg['message'], $msg['class']);

            } else {
                if ($msg['class'] == 'success') {
                    $woocommerce->add_message($msg['message']);
                } else {
                    $woocommerce->add_error($msg['message']);
                }
                $woocommerce->set_messages();
            }
            exit;
        }

    }

    /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_openbucks_gateway($methods) {
        $methods[] = 'WC_Openbucks_Gateway';

        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_openbucks_gateway' );
}
?>
