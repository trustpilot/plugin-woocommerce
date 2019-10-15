<?php
namespace Trustpilot\Review;

class TrustpilotPluginStatus
{
    const SUCCESSFUL_STATUS = 200;

    public function checkPluginStatus($origin)
    {
        $settings = trustpilot_get_field(TRUSTPILOT_PLUGIN_STATUS);
        if (in_array(parse_url($origin, PHP_URL_HOST), $settings->blockedDomains)) {
            return $settings->pluginStatus;
        }
        return self::SUCCESSFUL_STATUS;
    }

    public function setPluginStatus($status, $blockedDomains)
    {
        $new_field = array(
            'pluginStatus' => $status,
            'blockedDomains' => $blockedDomains ?: array(),
        );
        trustpilot_set_field(TRUSTPILOT_PLUGIN_STATUS, $new_field);
    }
}
