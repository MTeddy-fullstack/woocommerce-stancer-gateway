<?php

if (! defined('ABSPATH')) {
    exit;
}

class WC_Stancer_Api_Client
{
    private const API_BASE_URL = 'https://api.stancer.com';

    private string $secret_key;

    public function __construct(string $secret_key)
    {
        $this->secret_key = $secret_key;
    }

    public function create_payment(array $payload)
    {
        return $this->request('POST', '/payments', $payload);
    }

    public function create_payment_intent(array $payload)
    {
        return $this->request('POST', '/v2/payment_intents/', $payload);
    }

    public function get_payment_intent(string $intent_id)
    {
        return $this->request('GET', '/v2/payment_intents/' . rawurlencode($intent_id));
    }

    public function refund_payment_intent(string $intent_id, array $payload = [])
    {
        return $this->request('POST', '/v2/payment_intents/' . rawurlencode($intent_id) . '/refund', $payload);
    }

    private function request(string $method, string $endpoint, array $payload = [])
    {
        $args = [
            'method'      => $method,
            'timeout'     => 30,
            'headers'     => [
                'Authorization' => 'Basic ' . base64_encode($this->secret_key . ':'),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'        => ! empty($payload) ? wp_json_encode($payload) : null,
            'data_format' => 'body',
        ];

        $response = wp_remote_request(self::API_BASE_URL . $endpoint, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code < 200 || $code >= 300) {
            return new WP_Error(
                'stancer_api_error',
                __('Stancer API request failed.', 'woocommerce-stancer-gateway'),
                [
                    'status'   => $code,
                    'response' => $data,
                ]
            );
        }

        return $data;
    }
}
