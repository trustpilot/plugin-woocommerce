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

/**
 * @subpackage Plugin
 */
class TrustBox {
	/**
	 * The variable name is used as the text domain when internationalizing strings
	 * of text. Its value should match the Text Domain file header in the main
	 * plugin file.
	 * @var      string
	 */
    protected $plugin_name = 'Trustpilot-review';

	/**
	 * Instance of this class.
	 */
	protected static $instance = null;

    /**
	 * Setup instance attributes
	 */
	private function __construct() {
		$this->plugin_version = TRUSTPILOT_REVIEWS_VERSION;
    }

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
	 * Return the plugin slug.
	 * @return    Plugin name variable.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * Return the plugin version.
	 * @return    Plugin version variable.
	 */
	public function get_plugin_version() {
		return $this->plugin_version;
	}

    private function do_hooks() {
		add_action ( 'wp_enqueue_scripts', array( $this, 'load_trustbox' ) );
    }

    public function getPage() {
        if (is_product()) {
            return 'product';
        } else if (is_product_category()) {
            return 'category';
        } else if (is_front_page()) {
            return 'landing';
        }
    }

    public function load_trustbox(){
        $trustbox = trustpilot_get_settings(TRUSTPILOT_TRUSTBOX_CONFIGURATION);
        $settings = array(
            'page' => $this->getPage(),
            'sku' => $this->getSku(),
            'name' => $this->getName()
        );
        $trusboxes = isset($trustbox->trustboxes) ? $trustbox->trustboxes : array();
        $this->load_trustboxes($settings, $trusboxes);
    }

    public function getName() {
        if (is_product()) {
            $product = wc_get_product( get_the_id() );
            return method_exists($product, 'get_name') ? $product->get_name() : $product->get_title();
        }
        return null;
    }

    public function getSku() {
        if (is_product()) {
            $product = wc_get_product( get_the_id() );
            if ($product->is_type('variable')) {
                // make a list of product sku plus skus of all variations
                $skus = array();
                $productSku = trustpilot_get_inventory_attribute('sku', $product);
                if ($productSku) {
                    array_push($skus, $productSku);
                }
                array_push($skus, TRUSTPILOT_PRODUCT_ID_PREFIX . trustpilot_get_inventory_attribute('id', $product));
                $variation_ids = $product->get_children();
                if ($variation_ids) {
                    foreach ($variation_ids as $variation_id) {
                        $variation = wc_get_product($variation_id);
                        $sku = trustpilot_get_inventory_attribute('sku', $variation);
                        if ($sku) {
                            array_push($skus, $sku);
                        }
                        array_push($skus, TRUSTPILOT_PRODUCT_ID_PREFIX . trustpilot_get_inventory_attribute('id', $product));
                    }
                }
                return implode(',', $skus);
            } else {
                $skus = array();
                $sku = trustpilot_get_inventory_attribute('sku', $product);
                if ($sku) {
                    array_push($skus, $sku);
                }
                array_push($skus, TRUSTPILOT_PRODUCT_ID_PREFIX . trustpilot_get_inventory_attribute('id', $product));
                return $skus;
            }
        }
    }

    function load_trustboxes($settings, $trustbox){
        wp_register_script('trustbox', plugins_url('assets/js/trustBoxScript.js', __FILE__));
        wp_localize_script('trustbox', 'trustbox_settings', $settings);
        wp_localize_script('trustbox', 'trustpilot_trustbox_settings', array("trustboxes" => $trustbox));
        wp_enqueue_script ('trustbox');
    }
}
