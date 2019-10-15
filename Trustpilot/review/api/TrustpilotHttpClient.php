<?php

namespace Trustpilot\Review;

class TrustpilotHttpClient
{
    private $apiUrl;
    private $trustpilotPluginStatus;
    public function __construct($apiUrl)
    {
        $this->apiUrl = $apiUrl;
        $this->trustpilotPluginStatus = new TrustpilotPluginStatus();
    }

    public function post($url, $data)
    {
        $args = array(
            'body' => json_encode($data),
            'headers' => array(
                'Content-Type' => 'application/json; charset=utf-8',
                'Origin' => get_option('siteurl')
            ),
            'data_format' => 'body',
        );
        $res = wp_remote_post($url, $args);
        $code = (int) wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res));
        if ($code > 250 && $code < 254) {
            $this->trustpilotPluginStatus->setPluginStatus($code, $body);
        }
        return array(
            'code' => $code,
            'data' => $body,
        );
    }

    public function buildUrl($key, $endpoint)
    {
        return $this->apiUrl . $key . $endpoint;
    }

    public function postLog($data) {
        try {
            return $this->post($this->apiUrl . 'log', $data);
        } catch (\Throwable $e) {
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function checkStatusAndPost($url, $data = array())
    {
        $origin = get_option('siteurl');
        $code = $this->trustpilotPluginStatus->checkPluginStatus($origin);
        if ($code > 250 && $code < 254) {
            return array(
                'code' => $code,
            );
        }
        return $this->post($url, $data);
    }

    public function postInvitation($key, $data = array())
    {
        return $this->checkStatusAndPost($this->buildUrl($key, '/invitation'), $data);
    }

    public function postBatchInvitations($key, $data = array())
    {
        return $this->checkStatusAndPost($this->buildUrl($key, '/batchinvitations'), $data);
    }
}
