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
class Updater {

    /**
     * Instance of this class.
     */
    protected static $instance = null;

    /**
     * Return an instance of this class.
     */
    public static function get_instance() {
        if (null == self::$instance) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public function update($plugins)
    {
        $args = array(
            'path' => ABSPATH.'wp-content/plugins/',
            'trustpilot_preserve_zip' => false,
        );

        foreach ($plugins as $plugin) {
            $this->download_plugin($plugin['path'], $args['path'] . $plugin['name'] . '.zip');
            $this->unpack_plugin($args, $args['path'] . $plugin['name'] . '.zip');
            $this->activate_plugin($plugin['install']);
        }
    }

    protected function download_plugin($url, $path) {
        $response = wp_remote_get($url);
        $data = $response['body'];

        if (file_put_contents($path, $data))
            return true;
        else
            return false;
    }

    protected function unpack_plugin($args, $target) {
        WP_Filesystem();
        $destination_path = $args['path'];
        $unzipfile = unzip_file($target, $destination_path);

        if (is_wp_error($unzipfile)) {
            // TODO: log as info message
        }

        if ($args['trustpilot_preserve_zip'] === false) {
            unlink($target);
        }
    }

    protected function activate_plugin($installer) {
        $current = get_option('active_plugins');
        $plugin = plugin_basename(trim($installer));

        if (!in_array($plugin, $current)) {
            $current[] = $plugin;
            sort($current);
            do_action('activate_plugin', trim($plugin));
            update_option('active_plugins', $current);
            do_action('activate_'.trim($plugin));
            do_action('activated_plugin', trim($plugin));
            return true;
        } else {
            return false;
        }
    }
}
