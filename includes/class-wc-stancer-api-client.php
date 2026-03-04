<?php

if (! defined('ABSPATH')) {
    exit;
}

class WC_Stancer_Api_Client
{
    private const API_BASE_URL = 'https://api.stancer.com';
    private const TIMEOUT_SECONDS = 30;

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
            'timeout'     => self::TIMEOUT_SECONDS,
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
            $error_code = 'stancer_network_error';
            $wp_code    = (string) $response->get_error_code();

            if (strpos($wp_code, 'timeout') !== false || strpos((string) $response->get_error_message(), 'timed out') !== false) {
                $error_code = 'stancer_timeout';
            }

            return new WP_Error(
                $error_code,
                __('Unable to communicate with Stancer API.', 'woocommerce-stancer-gateway'),
                [
                    'error_code' => $error_code,
                    'wp_code'    => $wp_code,
                ]
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($body !== '' && $data === null && json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error(
                'stancer_invalid_json',
                __('Stancer API returned an invalid response format.', 'woocommerce-stancer-gateway'),
                [
                    'status'     => $code,
                    'error_code' => 'stancer_invalid_json',
                ]
            );
        }

        if ($code < 200 || $code >= 300) {
            $error_code = ($code >= 400 && $code < 500) ? 'stancer_http_4xx' : 'stancer_http_5xx';

            return new WP_Error(
                $error_code,
                __('Stancer API request failed.', 'woocommerce-stancer-gateway'),
                [
                    'status'     => $code,
                    'error_code' => $error_code,
                ]
            );
        }

        return $data;
    }
}
