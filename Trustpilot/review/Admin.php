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
 * @subpackage Admin
 */
class Admin {

    /**
     * Instance of this class.
     */
    protected static $instance = null;

    /**
     * Plugin basename.
     */
    protected $plugin_basename = null;

    /**
     * Return an instance of this class.
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
     * Initialize the plugin by loading admin scripts & styles and adding a
     * settings page and menu.
     */
    private function __construct() {
        $plugin = Plugin::get_instance();
        $this->plugin_slug = $plugin->get_plugin_name();
        $this->version = $plugin->get_plugin_version();
        $this->plugin_basename = plugin_basename( plugin_dir_path( realpath( dirname( __FILE__ ) ) ) . $this->plugin_slug . '.php' );
    }

    /**
     * Handle WP actions and filters.
     */
    private function do_hooks() {
        // Load admin style sheet and JavaScript.
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_action( 'wp_ajax_handle_past_orders', array( $this, 'trustpilot_handle_past_orders_callback' ) );
        add_action( 'wp_ajax_handle_save_changes', array( $this, 'trustpilot_save_changes' ) );
        add_action( 'wp_ajax_update_trustpilot_plugin', array( $this, 'wc_update_trustpilot_plugin' ) );
        add_action( 'wp_ajax_reload_trustpilot_settings', array( $this, 'wc_reload_trustpilot_settings' ) );

        // Add the options page and menu item.
        add_action( 'admin_menu', array( $this, 'trustpilot_menu' ) );
        // Include Http client
        include(plugin_dir_path( __FILE__ ) . 'api/TrustpilotHttpClient.php');
        include(plugin_dir_path( __FILE__ ) . 'util/Logger.php');
    }

    public function trustpilot_save_changes() {
        if (isset($_POST['settings'])) {
            $settings = sanitize_text_field($_POST['settings']);
            update_option('trustpilot_settings', $settings);
            echo $settings;
        }
        if (isset($_POST['pageUrls'])) {
            $pageUrls = sanitize_text_field($_POST['pageUrls']);
            update_option('trustpilot_page_urls', $pageUrls);
            echo $pageUrls;
        }
        if (isset($_POST['customTrustBoxes'])) {
            $customTrustBoxes = sanitize_text_field($_POST['customTrustBoxes']);
            update_option('trustpilot_custom_TrustBoxes', $customTrustBoxes);
            echo $customTrustBoxes;
        }
        die();
    }

    // If your change affects the size of this function, please update make_marketplace_build.sh with the new value
    public function wc_update_trustpilot_plugin() {
        if (is_admin()) {
            $plugins = array(
                array('name' => 'Trustpilot-reviews', 'path' => TRUSTPILOT_PLUGIN_URL, 'install' => 'trustpilot/wc-trustpilot.php')
            );

            $updater = Updater::get_instance();
            $updater->update($plugins);
        }
        die();
    }

    public function wc_reload_trustpilot_settings() {
        if (is_admin()) {
            $info = new \stdClass();
            $info->pluginVersion = TRUSTPILOT_PLUGIN_VERSION;
            $info->basis = 'plugin';
            echo json_encode($info);
        }
        die();
    }

    public function trustpilot_handle_past_orders_callback() {
        if (isset($_POST['sync'])) {
            $period = absint($_POST['sync']);
            $this->trustpilot_sync_past_orders($period);
            $response_json = $this->trustpilot_get_past_orders_info();
            echo $response_json;
            die();
        } else if ((isset($_POST['resync']))) {
            $this->trustpilot_resync_failed_orders();
            $response_json = $this->trustpilot_get_past_orders_info();
            echo $response_json;
            die();
        } else if ((isset($_POST['issynced']))) {
            $response_json = $this->trustpilot_get_past_orders_info();
            echo $response_json;
            die();
        } else if ((isset($_POST['showPastOrdersInitial']))) {
            $value = filter_var($_POST['showPastOrdersInitial'], FILTER_VALIDATE_BOOLEAN);
            update_option('show_past_orders_initial', var_export($value, true));
            die();
        }

        echo json_encode(array('error' => 'unsupported command received.'));
        die();
    }

    private function trustpilot_get_past_orders_info() {
        $orders = PastOrders::get_instance();
        $info = $orders->get_past_orders_info();
        $info['basis'] = 'plugin';
        return json_encode($info);
    }

    private function trustpilot_sync_past_orders($period) {
        $orders = PastOrders::get_instance();
        $orders->sync($period);
    }

    private function trustpilot_resync_failed_orders() {
        $orders = PastOrders::get_instance();
        $orders->resync();
    }

    /**
     * Register and enqueue admin-specific style sheet.
     */
    public function enqueue_admin_styles($hook) {
        if ($hook == 'toplevel_page_woocommerce-trustpilot-settings-page') {
            wp_enqueue_style( 'trustpilotSettingsStylesheet', plugins_url('/assets/css/trustpilot.css', __FILE__));
        }
        wp_enqueue_style('trustpilotSideLogoStylesheet', plugins_url('/assets/css/trustpilot.css', __FILE__));
    }

    /**
     * Register and enqueue admin-specific javascript
     */
    public function enqueue_admin_scripts($hook) {
        if ( 'toplevel_page_woocommerce-trustpilot-settings-page' != $hook ) {
            return;
        }
        wp_enqueue_script( 'boot_js', plugins_url('/assets/js/integrationScript.js', __FILE__ ));
        wp_localize_script('boot_js', 'trustpilot_integration_settings', array(
            'TRUSTPILOT_INTEGRATION_APP_URL' => $this->get_integration_app_url(),
        ));
    }

    public function trustpilot_menu() {
        add_menu_page('Trustpilot', 'Trustpilot', 'manage_options', 'woocommerce-trustpilot-settings-page', array( $this, 'wc_display_trustpilot_admin_page' ));
    }

    public function wc_display_trustpilot_admin_page() {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) {
                echo '<h1>You don\'t have sufficient rights to modify plugin </h1><br>';
                die(__(''));
        }
        if (trustpilot_compatible()) {
            if (isset($_POST['clear_trustpilot_settings'])) {
                check_admin_referer('trustpilot_settings_form');
                $this->wc_clear_trustpilot_settings();
            }
            $this->wc_display_trustpilot_settings();
        } else {
            if (version_compare(phpversion(), '5.2.0') < 0) {
                echo '<h1>Trustpilot plugin requires PHP 5.2.0 above.</h1><br>';
            }
            if (!function_exists('curl_init')) {
                echo '<h1>Trustpilot plugin requires cURL library.</h1><br>';
            }
        }
    }

    public function get_product_identification_options() {
        $fields = ['none', 'sku', 'id'];
        $optionalFields = ['upc', 'isbn', 'brand', 'mpn', 'gtin'];
        $attrs = array_map(function ($t) { return $t->attribute_name; }, wc_get_attribute_taxonomies());

        foreach ($attrs as $attr) {
            foreach ($optionalFields as $field) {
                if ($attr == $field && !in_array($field, $fields)) {
                    array_push($fields, $field);
                }
            }
        }

        return json_encode($fields);
    }

    private function load_iframe() {
        $pageUrls = new \stdClass();
        $pageUrls->landing = trustpilot_get_page_url('landing');
        $pageUrls->category = trustpilot_get_page_url('category');
        $pageUrls->product = trustpilot_get_page_url('product');
        $urls = trustpilot_get_field('trustpilot_page_urls');
        $customTrustBoxes = json_encode(trustpilot_get_field('trustpilot_custom_TrustBoxes'));
        $pageUrls = (object) array_merge((array) $urls, (array) $pageUrls);
        $pageUrlsBase64 = base64_encode(json_encode($pageUrls));
        $integration_app_url = $this->get_integration_app_url();
        $settings = base64_encode(stripslashes(trustpilot_get_settings()));
        $past_orders_info = $this->trustpilot_get_past_orders_info();
        $sku = trustpilot_get_product_sku();
        $name = trustpilot_get_product_name();
        $version = trustpilot_get_woo_version_number();
        $startingUrl = trustpilot_get_page_url('landing');
        $productIdentificationOptions = $this->get_product_identification_options();
        return "
            <div style='display:block;'>
            <iframe
                style='display: inline-block;'
                src='" . $integration_app_url . "'
                id='configuration_iframe'
                frameborder='0'
                scrolling='no'
                width='100%'
                height='1400px'
                data-plugin-version='" . TRUSTPILOT_PLUGIN_VERSION . "'
                data-source='WooCommerce'
                data-version='WooCommerce-" . $version . "'
                data-page-urls='" . $pageUrlsBase64 . "'
                data-transfer='" . $integration_app_url . "'
                data-past-orders='" . $past_orders_info . "'
                data-settings='" . $settings . "'
                data-product-identification-options = '" . $productIdentificationOptions . "'
                data-is-from-marketplace = '" . TRUSTPILOT_IS_FROM_MARKETPLACE . "'
                onload='sendSettings(); sendPastOrdersInfo();'>
            </iframe>
            <div id='trustpilot-trustbox-preview'
                hidden='true'
                data-page-urls='" . $pageUrlsBase64 . "'
                data-custom-trustboxes='" . $customTrustBoxes . "'
                data-settings='" . $settings . "'
                data-src='" . $startingUrl . "'
                data-name='" . $name . "'
                data-sku='" . $sku . "'
                data-source='WooCommerce'>
            </div>
            <script src='" . TRUSTPILOT_TRUSTBOX_PREVIEW_URL . "' id='TrustBoxPreviewComponent'></script>
        </div>
        ";
    }

    private function get_protocol() {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https:" : "http:";
    }

    private function get_integration_app_url() {
        return $this->get_protocol() . TRUSTPILOT_INTEGRATION_APP_URL;
    }

    private function wc_display_trustpilot_settings() {
        $settings = trustpilot_get_settings();
        $settings_html =
            "<div class='wrap'>" .
                $this->load_iframe() . "
                <form method='post' id='trustpilot_settings_form' style='display: none'>
                    <table class='form-table'>" . wp_nonce_field('trustpilot_settings_form') . "
                        <fieldset>
                            <tr valign='top'>
                                <th scope='row' >
                                    <div>
                                        master_settings_field
                                    </div>
                                </th>
                                <td>
                                    <div>
                                        <input
                                            type='text'
                                            id='master_settings_field'
                                            class='master_settings_field'
                                            name='master_settings_field'
                                            value='" . htmlspecialchars(stripslashes($settings)) . "'
                                        />
                                    </div>
                                </td>
                            </tr>
                        </fieldset>
                    </table>
                    <div class='buttons-container '>
                        <input
                            type='submit'
                            name='clear_trustpilot_settings'
                            value='Clear settings'
                            class='button-primary'
                            id='clear_trustpilot_settings'
                        />
                    </div>
                </form>
            </div>";
            echo $settings_html;
    }

    private function wc_clear_trustpilot_settings() {
        update_option('trustpilot_settings', trustpilot_get_default_settings());
        update_option(TRUSTPILOT_PAST_ORDERS_FIELD, '0');
        update_option(TRUSTPILOT_FAILED_ORDERS_FIELD, '{}');
        update_option('show_past_orders_initial', 'true');
    }
}
