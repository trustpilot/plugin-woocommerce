<?php

namespace Trustpilot\Review;

class TrustpilotLogger
{
    public static function error($e, $description = '', $optional = array()) {
        $errorObject = array(
            'platform' => 'WordPress-WooCommerce',
            'version'  => TRUSTPILOT_PLUGIN_VERSION,
            'error' => $e->getMessage(),
            'method' => TrustpilotLogger::getMethodName($e),
            'description' => $description,
            'variables' => $optional,
            'trace' => $e->getTraceAsString(),
        );

        $trustpilot_api = new TrustpilotHttpClient(TRUSTPILOT_API_URL);
        $trustpilot_api->postLog($errorObject);

         // Don't log stack trace locally
        $localErrorObject = $errorObject;
        unset($localErrorObject['trace']);

        if ( is_plugin_active('woocommerce/woocommerce.php') ) {
            if (function_exists('wc_get_logger')) {
                $logger = wc_get_logger();
                $logger->error(json_encode($localErrorObject), array('source' => 'trustpilot-reviews'));
            }
        } else {
            error_log(json_encode($localErrorObject));
        }
    }

    private static function getMethodName($e) {
        $trace = $e->getTrace();
        if (array_key_exists(0, $trace)) {
            $firstNode = $trace[0];
            if (array_key_exists('function', $firstNode)) {
                return $firstNode['function'];
            }
        }
        return '';
    }
}