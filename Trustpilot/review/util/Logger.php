<?php

namespace Trustpilot\Review;

class Logger
{
    public function trustpilot_error_log($message) {

        $logger = wc_get_logger();
        $logger->error($message, array('source' => 'trustpilot-reviews'));
        $key = trustpilot_get_settings(TRUSTPILOT_GENERAL_CONFIGURATION)->key;
        $trustpilot_api = new TrustpilotHttpClient(TRUSTPILOT_API_URL);
        $data = array(
            'platform' => 'Wordpress-Woocommerce',
            'version'  => TRUSTPILOT_REVIEWS_VERSION,
            'key'      => $key,
            'message'  => $message,
        );
        $trustpilot_api->postLog($data);
    }
}