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

use Trustpilot\Review\TrustpilotLogger;

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
        if ( is_plugin_active('woocommerce/woocommerce.php') ) {
            add_action( 'woocommerce_order_status_changed', array( $this, 'trustpilot_orderStatusChange' ));
            add_action( 'woocommerce_thankyou', array( $this, 'trustpilot_thankYouPageLoaded' ));
        }
    }

    /**
	 * WooCommerce order status change. Backend side
	 */
    public function trustpilot_orderStatusChange($order_id) {
        $order = wc_get_order($order_id);
        $order_status = $order->get_status();
        $general_settings = trustpilot_get_settings(TRUSTPILOT_GENERAL_CONFIGURATION);
        $key = apply_filter('trustpilot_order_status_changed_settings_key', $general_settings->key, $order_id);
        $trustpilot_api = new TrustpilotHttpClient(TRUSTPILOT_API_URL);
        $hook = 'woocommerce_order_status_changed';

        if (!empty($key)) {
            try {
                $invitation = $this->trustpilot_get_invitation($order, $hook, WITHOUT_PRODUCT_DATA);
                if (in_array($order_status, $general_settings->mappedInvitationTrigger) && trustpilot_compatible()) {
                    $response = $trustpilot_api->postInvitation($key, $invitation);

                    if ($response['code'] == 202) {
                        $invitation = $this->trustpilot_get_invitation($order, $hook, WITH_PRODUCT_DATA);
                        $response = $trustpilot_api->postInvitation($key, $invitation);
                    }

                    $this->handle_single_response($response, $invitation);
                } else {
                    $invitation['payloadType'] = 'OrderStatusUpdate';
                    $trustpilot_api->postInvitation($key, $invitation);
                }
            } catch (\Throwable $e) {
                $message = 'Unable to send invitation for order id: ' . $order_id;
                TrustpilotLogger::error($e, $message, array(
                    'key' => $key,
                    'orderId' => $order_id,
                    'orderStatus' => $order_status,
                    'hook' => $hook,
                ));
            } catch (\Exception $e) {
                $message = 'Unable to send invitation for order id: ' . $order_id;
                TrustpilotLogger::error($e, $message, array(
                    'key' => $key,
                    'orderId' => $order_id,
                    'orderStatus' => $order_status,
                    'hook' => $hook,
                ));
            }
        }
    }

    /**
	 * WooCommerce order confirmed. Frontend side
	 */
    public function trustpilot_thankYouPageLoaded($order_id) {
        $pluginStatus = new TrustpilotPluginStatus();
        $code = $pluginStatus->checkPluginStatus(get_option('siteurl'));
        if ($code > 250 && $code < 254) {
            return;
        }

        $general_settings = trustpilot_get_settings(TRUSTPILOT_GENERAL_CONFIGURATION);
        $invitation = $this->trustpilot_get_invitation_by_order_id($order_id, 'woocommerce_thankyou');

        if (!is_null($invitation)) {
            try {
                /**
                 * ROI data
                 */
                $order = wc_get_order($order_id);
                $invitation['totalCost'] = $order->get_total();
                $invitation['currency'] = $order->get_currency();
            } catch (\Throwable $e) {
                $message = 'Unable to collect ROI data on frontend checkout for order id: ' . $order_id;
                TrustpilotLogger::error($e, $message, array(
                    'orderId' => $order_id,
                ));
            } catch (\Exception $e) {
                $message = 'Unable to collect ROI data on frontend checkout for order id: ' . $order_id;
                TrustpilotLogger::error($e, $message, array(
                    'orderId' => $order_id,
                ));
            }

            if (!in_array('trustpilotOrderConfirmed', $general_settings->mappedInvitationTrigger)) {
                $invitation['payloadType'] = 'OrderStatusUpdate';
            }

            wp_register_script('tp-invitation', plugins_url('assets/js/thankYouScript.min.js', __FILE__));
            wp_localize_script('tp-invitation', 'trustpilot_order_data', array(TRUSTPILOT_ORDER_DATA => $invitation));
            wp_enqueue_script ('tp-invitation');
        }
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
        } catch (\Throwable $e) {
            $message = 'Unable to update past orders for order id: ' . $order['referenceId'];
            TrustpilotLogger::error($e, $message, array(
                'referenceId' => $order['referenceId'],
                'responseCode' => $response['code'],
            ));
        } catch (\Exception $e) {
            $message = 'Unable to update past orders for order id: ' . $order['referenceId'];
            TrustpilotLogger::error($e, $message, array(
                'referenceId' => $order['referenceId'],
                'responseCode' => $response['code'],
            ));
        }
    }

    /**
	 * Get order details
	 */
    public function trustpilot_get_invitation_by_order_id($order_id, $hook, $collect_product_data = WITH_PRODUCT_DATA) {
        $invitation = null;
        try {
            $order = wc_get_order($order_id);
            $invitation = $this->trustpilot_get_invitation($order, $hook, $collect_product_data);
        } catch (\Throwable $e) {
            $message = 'Unable to get invitation by order id: ' . $order_id;
            TrustpilotLogger::error($e, $message, array(
                'orderId' => $order_id,
                'hook' => $hook,
            ));
        } catch (\Exception $e) {
            $message = 'Unable to get invitation by order id: ' . $order_id;
            TrustpilotLogger::error($e, $message, array(
                'orderId' => $order_id,
                'hook' => $hook,
            ));
        }
        return $invitation;
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
            $invitation['templateParams'] = array(is_multisite() ? get_blog_details()->blog_id : 1);
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
            return $invitation;
        } else {
            throw new \Exception('Failed to collect invitation data for order');
        }
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

                    $product_data['brand'] = $_product->get_attribute('brand');

                    $product_data['sku'] = trustpilot_get_inventory_attribute('sku', $_product);
                    $product_data['gtin'] = trustpilot_get_inventory_attribute('gtin', $_product);
                    $product_data['mpn'] = trustpilot_get_inventory_attribute('mpn', $_product);
                    $product_data['productId'] = trustpilot_get_inventory_attribute('id', $_product);

                    $product_data['price'] = $_product->get_price();
                    $product_data['currency'] = get_woocommerce_currency();
                    $product_data['categories'] = $this->get_product_categories($_product);
                    $product_data['description'] = $this->get_product_description($_product);
                    $product_data['images'] = $this->get_product_image_urls($_product);
                    $product_data['videos'] = null;
                    $product_data['tags'] = $this->get_product_tags($_product);
                    $product_data['manufacturer'] = $_product->get_attribute('manufacturer');
                    $product_data['meta'] = null;
                }
                array_push($products, $product_data);
            }
        } catch (\Throwable $e) {
            $message = 'Unable to get products.';
            TrustpilotLogger::error($e, $message);
        } catch (\Exception $e) {
            $message = 'Unable to get products.';
            TrustpilotLogger::error($e, $message);
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
        if (function_exists('wc_get_orders')) {
            return wc_get_orders($args);
        } else {
           return trustpilot_legacy_get_all_wc_orders($args);
        }
    }

    /**
     * get product category names as an array
     */
    public function get_product_categories($product) {
        $category_names = array();
        $categories = wp_get_post_terms($product->get_id(), 'product_cat');
        foreach($categories as $category) {
            array_push($category_names, $category->name);
        }
        return $category_names;
    }

    /**
     * get all product images as an array of urls
     */
    public function get_product_image_urls($product) {
        $image_urls = array();
        $product_id = null;
        if (method_exists($product, 'get_id')) {
            $product_id = $product->get_id();
        } else {
            $product_id = $product->id;
        }

        $url = $this->trustpilot_get_product_image_url($product_id);
        if ($url) {
            array_push($image_urls, $url);
        }

        $attachment_ids = null;

        if (method_exists($product, 'get_gallery_image_ids')) {
            $attachment_ids = $product->get_gallery_image_ids();
        } else {
            $attachment_ids = $product->get_gallery_attachment_ids();
        }

        foreach($attachment_ids as $attachment_id) {
            $url = wp_get_attachment_url($attachment_id);
            if ($url) {
                array_push($image_urls, $url);
            }
        }

        if ($product->get_type() === 'variation') {
            $parent = wc_get_product($product->get_parent_id());
            if (method_exists($parent, 'get_gallery_image_ids')) {
                $attachment_ids = $parent->get_gallery_image_ids();
            } else {
                $attachment_ids = $parent->get_gallery_attachment_ids();
            }
            foreach($attachment_ids as $attachment_id) {
                $url = wp_get_attachment_url($attachment_id);
                if ($url) {
                    array_push($image_urls, $url);
                }
            }
        }
        return $image_urls;
    }

    /**
     * get product tags as an array
     */
    public function get_product_tags($product) {
        $tags = array();
        $terms = wp_get_post_terms($product->get_id(), 'product_tag');
        foreach($terms as $tag) {
            array_push($tags, $tag->name);
        }
        return $tags;
    }

    public function get_product_description($product) {
        if (method_exists($product, 'get_description')) {
            $description = $product->get_description();
            if (empty($description)) {
                if ($product->get_type() === 'variation') {
                    $parent = wc_get_product($product->get_parent_id());
                    $description = $parent->get_description() ? $parent->get_description() : $parent->get_short_description();
                } else {
                    $description = $product->get_short_description();
                }
            }
            return strip_shortcodes(wp_strip_all_tags($description));
        }

        return null;
    }
}
