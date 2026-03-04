<?php

if (! defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Stancer extends WC_Payment_Gateway
{
    private const SUPPORTED_CURRENCIES = [
        'EUR',
        'AUD',
        'CAD',
        'CHF',
        'DKK',
        'GBP',
        'NOK',
        'PLN',
        'SEK',
        'USD',
    ];

    /** @var string */
    public $test_mode = 'yes';

    /** @var string */
    public $test_public_key = '';

    /** @var string */
    public $test_secret_key = '';

    /** @var string */
    public $live_public_key = '';

    /** @var string */
    public $live_secret_key = '';

    /** @var string */
    public $webhook_signing_secret = '';

    public function __construct()
    {
        $this->id                 = 'stancer';
        $this->icon               = '';
        $this->has_fields         = false;
        $this->method_title       = __('Stancer', 'woocommerce-stancer-gateway');
        $this->method_description = __('Accept card payments through Stancer.', 'woocommerce-stancer-gateway');
        $this->supports           = [
            'products',
            'refunds',
        ];

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled         = (string) $this->get_option('enabled', 'no');
        $this->title           = (string) $this->get_option('title', __('Credit card', 'woocommerce-stancer-gateway'));
        $this->description     = (string) $this->get_option('description', __('Pay securely by credit card.', 'woocommerce-stancer-gateway'));
        $this->test_mode       = (string) $this->get_option('test_mode', 'yes');
        $this->test_public_key = (string) $this->get_option('test_public_key', '');
        $this->test_secret_key = (string) $this->get_option('test_secret_key', '');
        $this->live_public_key = (string) $this->get_option('live_public_key', '');
        $this->live_secret_key = (string) $this->get_option('live_secret_key', '');
        $this->webhook_signing_secret = (string) $this->get_option('webhook_signing_secret', '');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_api_wc_gateway_stancer', [$this, 'handle_return_callback']);
        add_action('woocommerce_api_wc_gateway_stancer_webhook', [$this, 'handle_webhook_callback']);
    }

    public function init_form_fields(): void
    {
        $webhook_url = WC()->api_request_url('wc_gateway_stancer_webhook');

        $this->form_fields = [
            'enabled' => [
                'title'   => __('Enable/Disable', 'woocommerce-stancer-gateway'),
                'type'    => 'checkbox',
                'label'   => __('Enable Stancer Gateway', 'woocommerce-stancer-gateway'),
                'default' => 'no',
            ],
            'title' => [
                'title'       => __('Title', 'woocommerce-stancer-gateway'),
                'type'        => 'text',
                'description' => __('Payment method title shown to customers at checkout.', 'woocommerce-stancer-gateway'),
                'default'     => __('Credit card', 'woocommerce-stancer-gateway'),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => __('Description', 'woocommerce-stancer-gateway'),
                'type'        => 'textarea',
                'description' => __('Payment method description shown to customers at checkout.', 'woocommerce-stancer-gateway'),
                'default'     => __('Pay securely by credit card.', 'woocommerce-stancer-gateway'),
                'desc_tip'    => true,
            ],
            'test_mode' => [
                'title'   => __('Test mode', 'woocommerce-stancer-gateway'),
                'type'    => 'checkbox',
                'label'   => __('Enable Stancer sandbox mode', 'woocommerce-stancer-gateway'),
                'default' => 'yes',
            ],
            'test_public_key' => [
                'title'       => __('Test public key', 'woocommerce-stancer-gateway'),
                'type'        => 'text',
                'description' => __('Your Stancer test public key.', 'woocommerce-stancer-gateway'),
                'default'     => '',
            ],
            'test_secret_key' => [
                'title'       => __('Test secret key', 'woocommerce-stancer-gateway'),
                'type'        => 'password',
                'description' => __('Your Stancer test secret key.', 'woocommerce-stancer-gateway'),
                'default'     => '',
            ],
            'live_public_key' => [
                'title'       => __('Live public key', 'woocommerce-stancer-gateway'),
                'type'        => 'text',
                'description' => __('Your Stancer live public key.', 'woocommerce-stancer-gateway'),
                'default'     => '',
            ],
            'live_secret_key' => [
                'title'       => __('Live secret key', 'woocommerce-stancer-gateway'),
                'type'        => 'password',
                'description' => __('Your Stancer live secret key.', 'woocommerce-stancer-gateway'),
                'default'     => '',
            ],
            'webhook_signing_secret' => [
                'title'       => __('Webhook signing secret', 'woocommerce-stancer-gateway'),
                'type'        => 'password',
                'description' => __('Secret used to verify Stancer webhook signatures (recommended).', 'woocommerce-stancer-gateway'),
                'default'     => '',
            ],
            'webhook_url_info' => [
                'title'       => __('Webhook URL', 'woocommerce-stancer-gateway'),
                'type'        => 'title',
                'description' => sprintf(
                    /* translators: %s: webhook URL */
                    __('Set this URL in your Stancer dashboard webhook configuration: %s', 'woocommerce-stancer-gateway'),
                    '<code>' . esc_html($webhook_url) . '</code>'
                ),
            ],
        ];
    }

    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);

        if (! $order instanceof WC_Order) {
            wc_add_notice(__('Unable to process payment for this order.', 'woocommerce-stancer-gateway'), 'error');

            return ['result' => 'failure'];
        }

        if (! $this->is_available()) {
            wc_add_notice(__('Stancer payment method is not available for this order.', 'woocommerce-stancer-gateway'), 'error');

            return ['result' => 'failure'];
        }

        $secret_key = $this->is_test_mode_enabled() ? $this->test_secret_key : $this->live_secret_key;

        if ($secret_key === '') {
            wc_add_notice(__('Stancer API key is missing. Please contact the store administrator.', 'woocommerce-stancer-gateway'), 'error');

            return ['result' => 'failure'];
        }

        $currency = strtoupper((string) $order->get_currency());

        if (! in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            wc_add_notice(__('Stancer does not support this order currency.', 'woocommerce-stancer-gateway'), 'error');

            return ['result' => 'failure'];
        }

        $client  = new WC_Stancer_Api_Client($secret_key);
        $payload = $this->build_payment_intent_payload($order);
        $result  = $client->create_payment_intent($payload);

        if (is_wp_error($result)) {
            $order->add_order_note(
                sprintf(
                    /* translators: %s: error message */
                    __('Stancer payment intent creation failed: %s', 'woocommerce-stancer-gateway'),
                    $result->get_error_message()
                )
            );
            wc_add_notice(__('Payment could not be initialized. Please try again.', 'woocommerce-stancer-gateway'), 'error');

            return ['result' => 'failure'];
        }

        $redirect_url = isset($result['url']) ? esc_url_raw((string) $result['url']) : '';
        $intent_id    = isset($result['id']) ? sanitize_text_field((string) $result['id']) : '';

        if ($redirect_url === '' || $intent_id === '') {
            $order->add_order_note(__('Stancer response was missing required fields (id/url).', 'woocommerce-stancer-gateway'));
            wc_add_notice(__('Payment provider returned an invalid response. Please try again.', 'woocommerce-stancer-gateway'), 'error');

            return ['result' => 'failure'];
        }

        $order->update_meta_data('_stancer_payment_intent_id', $intent_id);
        $order->update_meta_data('_stancer_payment_intent_url', $redirect_url);
        $order->update_meta_data('_stancer_mode', $this->is_test_mode_enabled() ? 'test' : 'live');
        $order->save();

        $order->update_status(
            'on-hold',
            sprintf(
                /* translators: %s: payment intent id */
                __('Awaiting Stancer payment confirmation. Intent: %s', 'woocommerce-stancer-gateway'),
                $intent_id
            )
        );

        wc_maybe_reduce_stock_levels($order_id);
        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $redirect_url,
        ];
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);

        if (! $order instanceof WC_Order) {
            return new WP_Error(
                'stancer_refund_order_not_found',
                __('Order not found for refund.', 'woocommerce-stancer-gateway')
            );
        }

        $intent_id = (string) $order->get_meta('_stancer_payment_intent_id', true);

        if ($intent_id === '') {
            return new WP_Error(
                'stancer_refund_missing_intent',
                __('Stancer payment intent ID is missing for this order.', 'woocommerce-stancer-gateway')
            );
        }

        $mode   = (string) $order->get_meta('_stancer_mode', true);
        $secret = $this->get_secret_key_for_mode($mode);

        if ($secret === '') {
            return new WP_Error(
                'stancer_refund_missing_key',
                __('Stancer API key is missing for the order mode.', 'woocommerce-stancer-gateway')
            );
        }

        $payload = [];

        if ($amount !== null) {
            $minor_amount = $this->to_minor_units((float) $amount);

            if ($minor_amount <= 0) {
                return new WP_Error(
                    'stancer_refund_invalid_amount',
                    __('Refund amount must be greater than zero.', 'woocommerce-stancer-gateway')
                );
            }

            $payload['amount'] = $minor_amount;
        }

        $client = new WC_Stancer_Api_Client($secret);
        $refund = $client->refund_payment_intent($intent_id, $payload);

        if (is_wp_error($refund)) {
            $order->add_order_note(
                sprintf(
                    /* translators: %s: error message */
                    __('Stancer refund failed: %s', 'woocommerce-stancer-gateway'),
                    $refund->get_error_message()
                )
            );

            return new WP_Error(
                'stancer_refund_failed',
                __('Stancer refund request failed.', 'woocommerce-stancer-gateway'),
                $refund->get_error_data()
            );
        }

        $refund_id     = isset($refund['id']) ? sanitize_text_field((string) $refund['id']) : '';
        $refund_status = isset($refund['status']) ? sanitize_key((string) $refund['status']) : 'unknown';
        $refund_amount = isset($refund['amount']) ? (int) $refund['amount'] : 0;

        if ($refund_id === '') {
            return new WP_Error(
                'stancer_refund_invalid_response',
                __('Stancer refund response is missing an ID.', 'woocommerce-stancer-gateway')
            );
        }

        $existing_ids = $order->get_meta('_stancer_refund_ids', true);
        $existing_ids = is_array($existing_ids) ? $existing_ids : [];
        $existing_ids[] = $refund_id;
        $existing_ids = array_values(array_unique($existing_ids));

        $order->update_meta_data('_stancer_refund_ids', $existing_ids);
        $order->save();

        $order->add_order_note(
            sprintf(
                /* translators: 1: refund id 2: refund status 3: amount in minor units */
                __('Stancer refund created. ID: %1$s, status: %2$s, amount: %3$d (minor units).', 'woocommerce-stancer-gateway'),
                $refund_id,
                $refund_status,
                $refund_amount
            )
        );

        if (is_string($reason) && $reason !== '') {
            $order->add_order_note(
                sprintf(
                    /* translators: %s: refund reason */
                    __('WooCommerce refund reason: %s', 'woocommerce-stancer-gateway'),
                    sanitize_text_field($reason)
                )
            );
        }

        return true;
    }

    public function is_test_mode_enabled(): bool
    {
        return $this->test_mode === 'yes';
    }

    public function is_available(): bool
    {
        if (! parent::is_available()) {
            return false;
        }

        $currency = get_woocommerce_currency();

        return in_array(strtoupper($currency), self::SUPPORTED_CURRENCIES, true);
    }

    private function build_payment_intent_payload(WC_Order $order): array
    {
        $amount = wc_format_decimal((string) $order->get_total(), wc_get_price_decimals());
        $amount = (int) round((float) $amount * (10 ** wc_get_price_decimals()));

        $description = sprintf(
            /* translators: %d: order id */
            __('Order #%d', 'woocommerce-stancer-gateway'),
            $order->get_id()
        );

        return [
            'amount'          => $amount,
            'currency'        => strtolower((string) $order->get_currency()),
            'description'     => substr($description, 0, 64),
            'order_id'        => (string) $order->get_id(),
            'methods_allowed' => ['card'],
            'return_url'      => $this->build_return_callback_url($order),
            'metadata'        => [
                'wc_order_id'     => (string) $order->get_id(),
                'wc_order_number' => (string) $order->get_order_number(),
                'site_url'        => home_url('/'),
            ],
            'capture'         => true,
        ];
    }

    public function handle_return_callback(): void
    {
        $order_id  = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;
        $order_key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';

        if ($order_id <= 0 || $order_key === '') {
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $order = wc_get_order($order_id);

        if (! $order instanceof WC_Order || $order->get_order_key() !== $order_key) {
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $intent_id = (string) $order->get_meta('_stancer_payment_intent_id', true);
        $mode      = (string) $order->get_meta('_stancer_mode', true);
        $secret    = $this->get_secret_key_for_mode($mode);

        if ($intent_id !== '' && $secret !== '') {
            $client = new WC_Stancer_Api_Client($secret);
            $intent = $client->get_payment_intent($intent_id);

            if (! is_wp_error($intent)) {
                $status = isset($intent['status']) ? sanitize_key((string) $intent['status']) : '';
                $this->sync_order_status_from_intent($order, $status, $intent_id);
            } else {
                $order->add_order_note(
                    sprintf(
                        /* translators: %s: error message */
                        __('Unable to fetch Stancer payment status: %s', 'woocommerce-stancer-gateway'),
                        $intent->get_error_message()
                    )
                );
            }
        }

        wp_safe_redirect($this->get_return_url($order));
        exit;
    }

    public function handle_webhook_callback(): void
    {
        if (strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'POST') {
            status_header(405);
            echo wp_json_encode(['message' => 'Method Not Allowed']);
            exit;
        }

        $raw_body = file_get_contents('php://input');
        $payload  = json_decode((string) $raw_body, true);

        if (! is_array($payload)) {
            status_header(400);
            echo wp_json_encode(['message' => 'Invalid JSON payload']);
            exit;
        }

        if (! $this->is_valid_webhook_signature((string) $raw_body)) {
            status_header(401);
            echo wp_json_encode(['message' => 'Invalid webhook signature']);
            exit;
        }

        $intent_id = $this->extract_intent_id_from_payload($payload);
        $order_id  = $this->extract_order_id_from_payload($payload);
        $order     = null;

        if ($order_id > 0) {
            $order = wc_get_order($order_id);
        }

        if (! $order instanceof WC_Order && $intent_id !== '') {
            $order = $this->find_order_by_intent_id($intent_id);
        }

        if (! $order instanceof WC_Order) {
            status_header(202);
            echo wp_json_encode(['message' => 'Webhook accepted but order was not found']);
            exit;
        }

        if ($intent_id === '') {
            $intent_id = (string) $order->get_meta('_stancer_payment_intent_id', true);
        }

        if ($intent_id === '') {
            status_header(202);
            echo wp_json_encode(['message' => 'Webhook accepted but payment intent was not found']);
            exit;
        }

        $mode   = (string) $order->get_meta('_stancer_mode', true);
        $secret = $this->get_secret_key_for_mode($mode);

        if ($secret === '') {
            status_header(500);
            echo wp_json_encode(['message' => 'Gateway API key is missing']);
            exit;
        }

        $client = new WC_Stancer_Api_Client($secret);
        $intent = $client->get_payment_intent($intent_id);

        if (is_wp_error($intent)) {
            $order->add_order_note(
                sprintf(
                    /* translators: %s: error message */
                    __('Webhook error while fetching Stancer payment intent: %s', 'woocommerce-stancer-gateway'),
                    $intent->get_error_message()
                )
            );
            status_header(502);
            echo wp_json_encode(['message' => 'Unable to fetch payment intent']);
            exit;
        }

        $status = isset($intent['status']) ? sanitize_key((string) $intent['status']) : '';
        $this->sync_order_status_from_intent($order, $status, $intent_id);

        status_header(200);
        echo wp_json_encode(['message' => 'Webhook processed']);
        exit;
    }

    private function build_return_callback_url(WC_Order $order): string
    {
        $base_url = WC()->api_request_url('wc_gateway_stancer');

        return add_query_arg(
            [
                'order_id' => $order->get_id(),
                'key'      => $order->get_order_key(),
            ],
            $base_url
        );
    }

    private function get_secret_key_for_mode(string $mode): string
    {
        if ($mode === 'live') {
            return $this->live_secret_key;
        }

        return $this->test_secret_key;
    }

    private function to_minor_units(float $amount): int
    {
        $precision = (int) wc_get_price_decimals();

        return (int) round($amount * (10 ** $precision));
    }

    private function extract_intent_id_from_payload(array $payload): string
    {
        $candidates = [
            $payload['id'] ?? '',
            $payload['payment_intent'] ?? '',
            $payload['paymentIntent'] ?? '',
            $payload['data']['id'] ?? '',
            $payload['data']['payment_intent'] ?? '',
            $payload['data']['paymentIntent'] ?? '',
            $payload['object']['id'] ?? '',
            $payload['object']['payment_intent'] ?? '',
            $payload['object']['paymentIntent'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);

            if (preg_match('/^pi_[a-zA-Z0-9]{24}$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return '';
    }

    private function extract_order_id_from_payload(array $payload): int
    {
        $candidates = [
            $payload['order_id'] ?? 0,
            $payload['orderId'] ?? 0,
            $payload['metadata']['wc_order_id'] ?? 0,
            $payload['data']['order_id'] ?? 0,
            $payload['data']['orderId'] ?? 0,
            $payload['data']['metadata']['wc_order_id'] ?? 0,
            $payload['object']['order_id'] ?? 0,
            $payload['object']['orderId'] ?? 0,
            $payload['object']['metadata']['wc_order_id'] ?? 0,
        ];

        foreach ($candidates as $candidate) {
            $order_id = absint($candidate);

            if ($order_id > 0) {
                return $order_id;
            }
        }

        return 0;
    }

    private function find_order_by_intent_id(string $intent_id)
    {
        $orders = wc_get_orders(
            [
                'limit'      => 1,
                'return'     => 'objects',
                'meta_key'   => '_stancer_payment_intent_id',
                'meta_value' => $intent_id,
            ]
        );

        if (empty($orders) || ! $orders[0] instanceof WC_Order) {
            return null;
        }

        return $orders[0];
    }

    private function is_valid_webhook_signature(string $raw_body): bool
    {
        $secret = trim($this->webhook_signing_secret);

        if ($secret === '') {
            return true;
        }

        $signature_header = $this->get_request_header('X-Stancer-Signature');

        if ($signature_header === '') {
            $signature_header = $this->get_request_header('Stancer-Signature');
        }

        if ($signature_header === '') {
            return false;
        }

        $computed  = hash_hmac('sha256', $raw_body, $secret);
        $signatures = [];

        foreach (explode(',', $signature_header) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (strpos($part, '=') !== false) {
                $values = explode('=', $part, 2);
                $part   = trim((string) $values[1]);
            }

            $signatures[] = strtolower($part);
        }

        foreach ($signatures as $signature) {
            if (hash_equals($computed, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function get_request_header(string $name): string
    {
        $server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        if (isset($_SERVER[$server_key])) {
            return sanitize_text_field(wp_unslash($_SERVER[$server_key]));
        }

        return '';
    }

    private function sync_order_status_from_intent(WC_Order $order, string $status, string $intent_id): void
    {
        if ($status === 'captured') {
            if (! $order->is_paid()) {
                $order->payment_complete($intent_id);
            }
            $order->add_order_note(
                sprintf(
                    /* translators: %s: payment intent id */
                    __('Stancer payment captured successfully. Intent: %s', 'woocommerce-stancer-gateway'),
                    $intent_id
                )
            );

            return;
        }

        if ($status === 'authorized' || $status === 'processing') {
            $order->update_status(
                'on-hold',
                sprintf(
                    /* translators: 1: payment status 2: payment intent id */
                    __('Stancer payment status: %1$s. Intent: %2$s', 'woocommerce-stancer-gateway'),
                    $status,
                    $intent_id
                )
            );

            return;
        }

        if ($status === 'canceled') {
            $order->update_status(
                'cancelled',
                sprintf(
                    /* translators: %s: payment intent id */
                    __('Stancer payment was canceled. Intent: %s', 'woocommerce-stancer-gateway'),
                    $intent_id
                )
            );
            wc_maybe_increase_stock_levels($order->get_id());

            return;
        }

        if ($status === 'unpaid') {
            $order->update_status(
                'failed',
                sprintf(
                    /* translators: %s: payment intent id */
                    __('Stancer payment failed. Intent: %s', 'woocommerce-stancer-gateway'),
                    $intent_id
                )
            );
            wc_maybe_increase_stock_levels($order->get_id());

            return;
        }

        if ($status === 'require_payment_method' || $status === 'require_authentication' || $status === 'require_authorization') {
            $order->add_order_note(
                sprintf(
                    /* translators: 1: payment status 2: payment intent id */
                    __('Stancer payment requires customer action (%1$s). Intent: %2$s', 'woocommerce-stancer-gateway'),
                    $status,
                    $intent_id
                )
            );
        }
    }
}
