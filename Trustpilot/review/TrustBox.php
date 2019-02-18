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

    public function load_trustbox(){
        $settings = trustpilot_get_settings(TRUSTPILOT_TRUSTBOX_CONFIGURATION);
        if ($settings->trustboxes) {
            $current_url = rtrim($this->getCurrentUrl(), '/');
            $current_url_trustboxes = $this->getAvailableTrustboxesByPage($settings, $current_url);

            if (is_product()) {
                $current_url_trustboxes = array_merge((array) $this->getAvailableTrustboxesByPage($settings, 'product'), (array) $current_url_trustboxes);
            } else if (is_product_category()) {
                $current_url_trustboxes = array_merge((array) $this->getAvailableTrustboxesByPage($settings, 'category'),(array) $current_url_trustboxes);
            } else if (is_front_page()) {
                $current_url_trustboxes = array_merge((array) $this->getAvailableTrustboxesByPage($settings, 'landing'),(array) $current_url_trustboxes);
            }
            if (count($current_url_trustboxes) > 0) {
                $settings->trustboxes = $current_url_trustboxes;
                $this->load_trustboxes($settings);
            }
        }
    }

    function getAvailableTrustboxesByPage($settings, $page, $includeSku = false) {
        $data = [];
        foreach ($settings->trustboxes as $trustbox) {
            if (rtrim($trustbox->page, '/') == $page && $trustbox->enabled == 'enabled') {
                if (is_product()) {
                    $product = wc_get_product( get_the_id() );
                    $trustbox->sku = trustpilot_get_inventory_attribute('sku', $product);
                    $trustbox->name = $product->get_name();
                }
                array_push($data, $trustbox);
            }
        }
        return $data;
    }

    function load_trustboxes($settings){
        wp_register_script('trustbox', plugins_url('assets/js/trustBoxScript.js', __FILE__));
        wp_localize_script('trustbox', 'trustbox_settings', array('data' => $settings));
        wp_enqueue_script ('trustbox');
    }

    private function getCurrentUrl(){
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https:' : 'http:';
        return $protocol . '//' . $_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
    }
}
