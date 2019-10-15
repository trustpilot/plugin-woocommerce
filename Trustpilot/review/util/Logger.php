<?php

namespace Trustpilot\Review;

class Logger
{
    public function error($e, $description = '', $optional = array()) {
        $errorObject = array(
            'platform' => 'WordPress-WooCommerce',
            'version'  => TRUSTPILOT_PLUGIN_VERSION,
            'error' => $e->getMessage(),
            'method' => Logger::getMethodName($e),
            'description' => $description,
            'variables' => $optional,
            'trace' => $e->getTraceAsString(),
        );

        $trustpilot_api = new TrustpilotHttpClient(TRUSTPILOT_API_URL);
        $trustpilot_api->postLog($errorObject);

         // Don't log stack trace locally
        $localErrorObject = $errorObject;
        unset($localErrorObject['trace']);
        
        $logger = wc_get_logger();
        $logger->error(json_encode($localErrorObject), array('source' => 'trustpilot-reviews'));
    }

    private function getMethodName($e) {
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