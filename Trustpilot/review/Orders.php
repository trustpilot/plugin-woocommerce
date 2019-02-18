<?php
/**
 * Trustpilot-reviews
 *
 *
 * @package   Trustpilot-reviews
 * @author    Trustpilot
 * @license   AFL-3.0
 * @link      https://trustpilot.com
 * @copyright 2018 Trustpilot
 */

namespace Trustpilot\Review;

define('WITH_PRODUCT_DATA', 'WITH_PRODUCT_DATA');
define('WITHOUT_PRODUCT_DATA', 'WITHOUT_PRODUCT_DATA');

/**
 * @subpackage Orders
 */
class Orders {

	/**
	 * Instance of this class.
	 */
	protected static $instance = null;

    /**
	 * Return an instance of this class.
	 * @return    object    A single instance of this class.
	 */
	public static function get_instance() {
		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
			self::$instance->do_hooks();
        }
		return self::$instance;
	}

	/**
	 * Handle WP actions and filters.
	 */
	private function do_hooks() {
        add_action( 'woocommerce_order_status_changed', array( $this, 'trustpilot_orderStatusChange' ));
        add_action( 'woocommerce_thankyou', array( $this, 'trustpilot_thankYouPageLoaded' ));
    }

    /**
	 * WooCommerce order status change. Backend side
	 */
    public function trustpilot_orderStatusChange($order_id) {
        $order = wc_get_order($order_id);
        $order_status = $order->get_status();
        $general_settings = trustpilot_get_settings(TRUSTPILOT_GENERAL_CONFIGURATION);
        $key = $general_settings->key;
        $trustpilot_api = new TrustpilotHttpClient(TRUSTPILOT_API_URL);

        if (!empty($key)) {
            try {
                $invitation = $this->trustpilot_get_invitation($order, 'woocommerce_order_status_changed', WITHOUT_PRODUCT_DATA);
                if (in_array($order_status, $general_settings->mappedInvitationTrigger) && trustpilot_compatible()) {
                    $response = $trustpilot_api->postInvitation($key, $invitation);

                    if ($response['code'] == 202) {
                        $invitation = $this->trustpilot_get_invitation($order, 'woocommerce_order_status_changed', WITH_PRODUCT_DATA);
                        $response = $trustpilot_api->postInvitation($key, $invitation);
                    }

                    $this->handle_single_response($response, $invitation);
                } else {
                    $invitation['payloadType'] = 'OrderStatusUpdate';
                    $trustpilot_api->postInvitation($key, $invitation);
                }
            } catch (Exception $e) {
                $message = 'Unable to send invitation for order id: ' . $order_id . '. Error: ' . $e->getMessage();
                Logger::trustpilot_error_log($message);
            }
        }
    }

    /**
	 * WooCommerce order confirmed. Frontend side
	 */
    public function trustpilot_thankYouPageLoaded($order_id) {
        $general_settings = trustpilot_get_settings(TRUSTPILOT_GENERAL_CONFIGURATION);
        $invitation = $this->trustpilot_get_invitation_by_order_id($order_id, 'woocommerce_thankyou');
        if (!in_array('trustpilotOrderConfirmed', $general_settings->mappedInvitationTrigger)) {
            $invitation['payloadType'] = 'OrderStatusUpdate';
        }

        wp_register_script('tp-invitation', plugins_url('assets/js/thankYouScript.js', __FILE__));
        wp_localize_script('tp-invitation', 'trustpilot_order_data', array(TRUSTPILOT_ORDER_DATA => $invitation));
        wp_enqueue_script ('tp-invitation');
    }

    /**
	 * Updating post orders lists after automatic invitation
	 */
    private function handle_single_response($response, $order) {
        try {
            $synced_orders = trustpilot_get_field(TRUSTPILOT_PAST_ORDERS_FIELD);
            $failed_orders = trustpilot_get_field(TRUSTPILOT_FAILED_ORDERS_FIELD);

            if ($response['code'] == 201) {
                trustpilot_set_field(TRUSTPILOT_PAST_ORDERS_FIELD, $synced_orders + 1);
                if (isset($failed_orders->{$order['referenceId']})) {
                    unset($failed_orders->{$order['referenceId']});
                    trustpilot_set_field(TRUSTPILOT_FAILED_ORDERS_FIELD, $failed_orders);
                }
            } else {
                $failed_orders->{$order['referenceId']} = base64_encode('Automatic invitation sending failed');
                trustpilot_set_field(TRUSTPILOT_FAILED_ORDERS_FIELD, $failed_orders);
            }
        } catch (Exception $e) {
            $message = 'Unable to update past orders for order id: ' . $order->get_id() . '. Error: ' . $e->getMessage();
            Logger::trustpilot_error_log($message);
        }
    }

    /**
	 * Get order details
	 */
    public function trustpilot_get_invitation_by_order_id($order_id, $hook, $collect_product_data = WITH_PRODUCT_DATA) {
        $order = wc_get_order($order_id);
        return $this->trustpilot_get_invitation($order, $hook, $collect_product_data);
    }

    private function get_order_billing_first_name($order){
        if (method_exists($order, 'get_billing_first_name')) {
            return $order->get_billing_first_name();
        }
        $order_id = $this->get_order_id($order);
        return get_post_meta( $order_id, '_billing_first_name', true );
    }

    private function get_order_billing_last_name($order){
        if (method_exists($order, 'get_billing_last_name')) {
            return $order->get_billing_last_name();
        }
        $order_id = $this->get_order_id($order);
        return get_post_meta( $order_id, '_billing_last_name', true );
    }

    private function get_order_billing_email($order){
        if (method_exists($order, 'get_billing_email')) {
            return $order->get_billing_email();
        }
        $order_id = $this->get_order_id($order);
        return get_post_meta($order_id, '_billing_email', true );
    }

    /**
    * Get order details
    */
   public function trustpilot_get_invitation($order, $hook, $collect_product_data = WITH_PRODUCT_DATA) {
        $invitation = null;
        if (!is_null($this->get_order_id($order))) {
            $invitation = array();
            $billing_email = $this->get_order_billing_email($order);
            if (!empty($billing_email)) {
                $invitation['recipientEmail'] = $billing_email;
            } else {
                $customer = $order->get_user();
                $invitation['recipientEmail'] = $customer ? $customer->user_email : '';
            }
            $invitation['recipientName'] = $this->get_order_billing_first_name($order) . ' ' . $this->get_order_billing_last_name($order);
            $invitation['referenceId'] = (string)$this->get_order_id($order);
            $invitation['source'] = 'WooCommerce-' . trustpilot_get_woo_version_number();
            $invitation['pluginVersion'] = TRUSTPILOT_PLUGIN_VERSION;
            $order_status = $order->get_status();
            $invitation['hook'] = $hook;
            $invitation['orderStatusId'] = $order_status;
            $invitation['orderStatusName'] = $order_status;

            if ($collect_product_data == WITH_PRODUCT_DATA) {
                $products = $this->getProducts($order);
                $invitation['products'] = $products;
                $invitation['productSkus'] = $this->getSkus($products);
            }
        }
        return $invitation;
    }

    /**
     * Get products details
     */
    private function getProducts($order) {
        $products = array();
        try {
            foreach ($order->get_items() as $product) {

                if (wc_get_product($product['variation_id'])) {
                    $_product = wc_get_product($product['variation_id']);
                } else if (wc_get_product($product['product_id'])) {
                    $_product = wc_get_product($product['product_id']);
                }

                if (is_object($_product)){
                    $product_data = array();
                    $product_data['productUrl'] = get_permalink($product['product_id']);
                    $product_data['name'] = $product['name'];
                    $product_data['imageUrl'] = $this->trustpilot_get_product_image_url($product['product_id']);

                    if ($_product->get_attribute('brand')) {
                        $product_data['brand'] = $_product->get_attribute('brand');
                    }

                    $product_data['sku'] = trustpilot_get_inventory_attribute('sku', $_product);
                    $product_data['gtin'] = trustpilot_get_inventory_attribute('gtin', $_product);
                    $product_data['mpn'] = trustpilot_get_inventory_attribute('mpn', $_product);
                }
                array_push($products, $product_data);
            }
        } catch (Exception $e) {
            $message = 'Unable to get products. Error: ' . $e->getMessage();
            Logger::trustpilot_error_log($message);
        }
        return $products;
    }

    /**
	 * Get products skus
	 */
    private function getSkus($products) {
        $skus = array();
        foreach ($products as $product) {
            $sku = isset($product['sku']) ? $product['sku'] : '';
            array_push($skus, $sku);
        }
        return $skus;
    }

    /**
	 * get image url for each product in order
	 */
    private function trustpilot_get_product_image_url($product_id) {
        $url = wp_get_attachment_url(get_post_thumbnail_id($product_id));
        return $url ? $url : null;
    }

    private function get_order_id($order){
        if (method_exists($order, 'get_id')) {
            return $order->get_id();
        }
        return $order->post->ID;
    }

    public function get_all_wc_orders($args){
        if(function_exists('wc_get_orders')){
            return wc_get_orders($args);
        } else {
           return trustpilot_legacy_get_all_wc_orders($args);
        }
    }
}
