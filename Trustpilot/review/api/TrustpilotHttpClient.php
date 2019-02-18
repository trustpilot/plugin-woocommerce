<?php

namespace Trustpilot\Review;

class TrustpilotHttpClient
{
    public function __construct($apiUrl)
    {
        $this->apiUrl = $apiUrl;
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
        return array(
            'code' => (int) wp_remote_retrieve_response_code($res),
            'data' => json_decode(wp_remote_retrieve_body($res))
        );
    }

    public function buildUrl($key, $endpoint)
    {
        return $this->apiUrl . $key . $endpoint;
    }

    public function postLog($data) {
        try {
            return $this->post($this->apiUrl . 'log', $data);
        } catch (Exception $e) {
            return false;
        }
    }

    public function postInvitation($key, $data = array())
    {
        return $this->post($this->buildUrl($key, '/invitation'), $data);
    }

    public function postBatchInvitations($key, $data = array())
    {
        return $this->post($this->buildUrl($key, '/batchinvitations'), $data);
    }
}
