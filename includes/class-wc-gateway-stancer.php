<?php

if (! defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Stancer extends WC_Payment_Gateway
{
    private const MAX_WEBHOOK_BODY_BYTES = 262144;
    private const WEBHOOK_REPLAY_TTL_SECONDS = 600;
    private const WEBHOOK_DEFAULT_TTL_SECONDS = 300;
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
        add_action('admin_notices', [$this, 'maybe_display_security_notice']);
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

    public function process_admin_options(): bool
    {
        $result = parent::process_admin_options();

        $this->init_settings();
        $this->enabled                = (string) $this->get_option('enabled', 'no');
        $this->test_mode              = (string) $this->get_option('test_mode', 'yes');
        $this->test_secret_key        = trim((string) $this->get_option('test_secret_key', ''));
        $this->live_secret_key        = trim((string) $this->get_option('live_secret_key', ''));
        $this->webhook_signing_secret = trim((string) $this->get_option('webhook_signing_secret', ''));

        // Persist trimmed secrets to avoid hidden-space misconfigurations.
        $this->update_option('test_secret_key', $this->test_secret_key);
        $this->update_option('live_secret_key', $this->live_secret_key);
        $this->update_option('webhook_signing_secret', $this->webhook_signing_secret);

        return $result;
    }

    public function maybe_display_security_notice(): void
    {
        if (! is_admin() || ! current_user_can('manage_woocommerce')) {
            return;
        }

        if (! $this->is_live_mode() || ! $this->is_enabled()) {
            return;
        }

        if ($this->is_webhook_secret_required_for_live_mode() && trim($this->webhook_signing_secret) === '') {
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__('WooCommerce Stancer Gateway is running in live mode without a webhook signing secret. Webhooks will be rejected until a secret is configured.', 'woocommerce-stancer-gateway');
            echo '</p></div>';
        }
    }

    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);

        if (! $order instanceof WC_Order) {
            WC_Stancer_Logger::log('error', 'Payment initialization failed: order not found.', ['order_id' => (int) $order_id]);
            wc_add_notice(__('Unable to process payment for this order.', 'woocommerce-stancer-gateway'), 'error');

            return ['result' => 'failure'];
        }

        WC_Stancer_Logger::log(
            'info',
            'Starting Stancer payment initialization.',
            [
                'order_id' => (int) $order->get_id(),
                'mode'     => $this->is_test_mode_enabled() ? 'test' : 'live',
            ]
        );

        if (! $this->is_available()) {
            WC_Stancer_Logger::log('warning', 'Payment initialization blocked: gateway not available.', ['order_id' => (int) $order->get_id()]);
            wc_add_notice(__('Stancer payment method is not available for this order.', 'woocommerce-stancer-gateway'), 'error');

            return ['result' => 'failure'];
        }

        $secret_key = $this->is_test_mode_enabled() ? $this->test_secret_key : $this->live_secret_key;

        if ($secret_key === '') {
            WC_Stancer_Logger::log('error', 'Payment initialization failed: missing API key.', ['order_id' => (int) $order->get_id()]);
            wc_add_notice(__('Stancer API key is missing. Please contact the store administrator.', 'woocommerce-stancer-gateway'), 'error');

            return ['result' => 'failure'];
        }

        $currency = strtoupper((string) $order->get_currency());

        if (! in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            WC_Stancer_Logger::log('warning', 'Payment initialization failed: unsupported currency.', ['order_id' => (int) $order->get_id(), 'currency' => $currency]);
            wc_add_notice(__('Stancer does not support this order currency.', 'woocommerce-stancer-gateway'), 'error');

            return ['result' => 'failure'];
        }

        $client  = new WC_Stancer_Api_Client($secret_key);
        $payload = $this->build_payment_intent_payload($order);
        $result  = $client->create_payment_intent($payload);

        if (is_wp_error($result)) {
            WC_Stancer_Logger::log(
                'error',
                'Payment intent creation failed.',
                [
                    'order_id' => (int) $order->get_id(),
                    'error_code' => (string) $result->get_error_code(),
                ]
            );
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
            WC_Stancer_Logger::log(
                'error',
                'Payment intent creation failed: missing id/url in response.',
                ['order_id' => (int) $order->get_id()]
            );
            $order->add_order_note(__('Stancer response was missing required fields (id/url).', 'woocommerce-stancer-gateway'));
            wc_add_notice(__('Payment provider returned an invalid response. Please try again.', 'woocommerce-stancer-gateway'), 'error');

            return ['result' => 'failure'];
        }

        if (! $this->is_allowed_redirect_url($redirect_url)) {
            WC_Stancer_Logger::log(
                'error',
                'Payment intent creation failed: untrusted redirect URL.',
                ['order_id' => (int) $order->get_id(), 'reason' => 'untrusted_redirect']
            );
            wc_add_notice(__('Payment provider returned an invalid redirect URL. Please try again.', 'woocommerce-stancer-gateway'), 'error');

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

        WC_Stancer_Logger::log(
            'info',
            'Payment intent created successfully.',
            [
                'order_id'  => (int) $order->get_id(),
                'mode'      => $this->is_test_mode_enabled() ? 'test' : 'live',
                'intent_id' => $intent_id,
            ]
        );

        return [
            'result'   => 'success',
            'redirect' => $redirect_url,
        ];
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);

        if (! $order instanceof WC_Order) {
            WC_Stancer_Logger::log('error', 'Refund failed: order not found.', ['order_id' => (int) $order_id]);
            return new WP_Error(
                'stancer_refund_order_not_found',
                __('Order not found for refund.', 'woocommerce-stancer-gateway')
            );
        }

        WC_Stancer_Logger::log(
            'info',
            'Starting refund request.',
            [
                'order_id' => (int) $order->get_id(),
                'amount'   => $amount,
            ]
        );

        $intent_id = (string) $order->get_meta('_stancer_payment_intent_id', true);

        if ($intent_id === '') {
            WC_Stancer_Logger::log('error', 'Refund failed: missing payment intent ID.', ['order_id' => (int) $order->get_id()]);
            return new WP_Error(
                'stancer_refund_missing_intent',
                __('Stancer payment intent ID is missing for this order.', 'woocommerce-stancer-gateway')
            );
        }

        $mode   = (string) $order->get_meta('_stancer_mode', true);
        $secret = $this->get_secret_key_for_mode($mode);

        if ($secret === '') {
            WC_Stancer_Logger::log('error', 'Refund failed: missing API key.', ['order_id' => (int) $order->get_id(), 'mode' => $mode]);
            return new WP_Error(
                'stancer_refund_missing_key',
                __('Stancer API key is missing for the order mode.', 'woocommerce-stancer-gateway')
            );
        }

        $payload = [];

        if ($amount !== null) {
            $minor_amount = $this->to_minor_units((float) $amount);

            if ($minor_amount <= 0) {
                WC_Stancer_Logger::log('warning', 'Refund rejected: invalid amount.', ['order_id' => (int) $order->get_id(), 'amount' => $amount]);
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
            WC_Stancer_Logger::log(
                'error',
                'Refund API request failed.',
                [
                    'order_id'  => (int) $order->get_id(),
                    'intent_id' => $intent_id,
                    'error'     => $refund->get_error_message(),
                ]
            );
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
            WC_Stancer_Logger::log('error', 'Refund API response invalid: missing refund ID.', ['order_id' => (int) $order->get_id(), 'intent_id' => $intent_id]);
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

        WC_Stancer_Logger::log(
            'info',
            'Refund request created successfully.',
            [
                'order_id'      => (int) $order->get_id(),
                'intent_id'     => $intent_id,
                'refund_id'     => $refund_id,
                'refund_status' => $refund_status,
            ]
        );

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
            WC_Stancer_Logger::log('warning', 'Return callback rejected: missing order parameters.');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $order = wc_get_order($order_id);

        if (! $order instanceof WC_Order || $order->get_order_key() !== $order_key) {
            WC_Stancer_Logger::log('warning', 'Return callback rejected: order key mismatch.', ['order_id' => $order_id]);
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
                WC_Stancer_Logger::log('info', 'Return callback synchronized payment status.', ['order_id' => (int) $order->get_id(), 'intent_id' => $intent_id, 'status' => $status]);
            } else {
                WC_Stancer_Logger::log('error', 'Return callback failed to fetch payment intent.', ['order_id' => (int) $order->get_id(), 'intent_id' => $intent_id, 'error_code' => (string) $intent->get_error_code()]);
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
            WC_Stancer_Logger::log('warning', 'Webhook rejected: invalid HTTP method.', ['method' => (string) ($_SERVER['REQUEST_METHOD'] ?? '')]);
            $this->respond_json(405, 'Method not allowed');
        }

        $content_type = strtolower($this->get_request_header('Content-Type'));
        $raw_body     = file_get_contents('php://input');

        if (strlen((string) $raw_body) > self::MAX_WEBHOOK_BODY_BYTES) {
            WC_Stancer_Logger::log('warning', 'Webhook rejected: payload too large.', ['reason' => 'payload_too_large']);
            $this->respond_json(413, 'Payload too large');
        }

        $payload  = json_decode((string) $raw_body, true);

        if (! is_array($payload)) {
            WC_Stancer_Logger::log('warning', 'Webhook rejected: invalid JSON payload.');
            $this->respond_json(400, 'Invalid payload');
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

        $mode    = $this->detect_webhook_mode($payload, $order);
        $is_live = $this->is_live_mode_for_webhook($payload, $order);
        $secret  = trim((string) $this->webhook_signing_secret);

        if ($content_type === '') {
            WC_Stancer_Logger::log('warning', 'Webhook content type is missing.', ['mode' => $mode, 'reason' => 'missing_content_type']);

            if ($is_live) {
                $this->respond_json(415, 'Unsupported content type');
            }
        } elseif (strpos($content_type, 'application/json') === false) {
            WC_Stancer_Logger::log('warning', 'Webhook content type is not JSON.', ['mode' => $mode, 'reason' => 'invalid_content_type']);

            if ($is_live) {
                $this->respond_json(415, 'Unsupported content type');
            }
        }

        if ($secret === '') {
            if ($is_live && $this->is_webhook_secret_required_for_live_mode()) {
                WC_Stancer_Logger::log('error', 'Webhook rejected: missing signing secret in live mode.', ['mode' => $mode, 'reason' => 'missing_secret']);
                $this->respond_json(503, 'Webhook temporarily unavailable');
            }

            WC_Stancer_Logger::log('warning', 'Webhook accepted without signature verification in test mode.', ['mode' => $mode, 'reason' => 'missing_secret_test']);
        } else {
            $validation = $this->validate_webhook_request((string) $raw_body, $is_live);

            if (! $validation['ok']) {
                WC_Stancer_Logger::log(
                    'warning',
                    'Webhook rejected: signature validation failed.',
                    ['mode' => $mode, 'reason' => (string) $validation['reason']]
                );
                $this->respond_json(401, 'Invalid webhook signature');
            }

            $fingerprint = hash('sha256', (string) $validation['signature'] . '.' . (string) $raw_body);

            if ($this->is_replayed_webhook($fingerprint)) {
                WC_Stancer_Logger::log('warning', 'Webhook rejected: replay detected.', ['mode' => $mode, 'reason' => 'replay_detected']);
                $this->respond_json(409, 'Webhook already processed');
            }

            $this->remember_webhook_fingerprint($fingerprint);
        }

        if (! $order instanceof WC_Order) {
            WC_Stancer_Logger::log('warning', 'Webhook accepted without matching order.', ['intent_id' => $intent_id, 'order_id' => $order_id]);
            $this->respond_json(202, 'Accepted');
        }

        if ($intent_id === '') {
            $intent_id = (string) $order->get_meta('_stancer_payment_intent_id', true);
        }

        if ($intent_id === '') {
            WC_Stancer_Logger::log('warning', 'Webhook accepted but payment intent ID missing on order.', ['order_id' => (int) $order->get_id()]);
            $this->respond_json(202, 'Accepted');
        }

        $api_secret = $this->get_secret_key_for_mode($mode);

        if ($api_secret === '') {
            WC_Stancer_Logger::log('error', 'Webhook processing failed: missing API key.', ['order_id' => (int) $order->get_id(), 'mode' => $mode]);
            $this->respond_json(500, 'Webhook processing failed');
        }

        $client = new WC_Stancer_Api_Client($api_secret);
        $intent = $client->get_payment_intent($intent_id);

        if (is_wp_error($intent)) {
            WC_Stancer_Logger::log(
                'error',
                'Webhook failed while fetching payment intent.',
                [
                    'order_id'   => (int) $order->get_id(),
                    'intent_id'  => $intent_id,
                    'error_code' => (string) $intent->get_error_code(),
                ]
            );
            $order->add_order_note(
                sprintf(
                    /* translators: %s: error message */
                    __('Webhook error while fetching Stancer payment intent: %s', 'woocommerce-stancer-gateway'),
                    $intent->get_error_message()
                )
            );
            $this->respond_json(502, 'Upstream provider error');
        }

        $status = isset($intent['status']) ? sanitize_key((string) $intent['status']) : '';
        $this->sync_order_status_from_intent($order, $status, $intent_id);
        WC_Stancer_Logger::log('info', 'Webhook synchronized payment status.', ['order_id' => (int) $order->get_id(), 'intent_id' => $intent_id, 'status' => $status]);
        $this->respond_json(200, 'Processed');
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

    private function is_enabled(): bool
    {
        return $this->enabled === 'yes';
    }

    private function is_live_mode(): bool
    {
        return $this->test_mode !== 'yes';
    }

    private function is_webhook_secret_required_for_live_mode(): bool
    {
        return (bool) apply_filters('wc_stancer_require_webhook_secret_live', true);
    }

    private function get_webhook_ttl_seconds(): int
    {
        $ttl = (int) apply_filters('wc_stancer_webhook_ttl_seconds', self::WEBHOOK_DEFAULT_TTL_SECONDS);

        if ($ttl <= 0) {
            return self::WEBHOOK_DEFAULT_TTL_SECONDS;
        }

        return $ttl;
    }

    private function detect_webhook_mode(array $payload, $order): string
    {
        if ($order instanceof WC_Order) {
            $mode = (string) $order->get_meta('_stancer_mode', true);

            if ($mode === 'live' || $mode === 'test') {
                return $mode;
            }
        }

        return $this->is_test_mode_enabled() ? 'test' : 'live';
    }

    private function is_live_mode_for_webhook(array $payload, $order): bool
    {
        return $this->detect_webhook_mode($payload, $order) === 'live';
    }

    private function is_allowed_redirect_url(string $url): bool
    {
        $parts = wp_parse_url($url);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        if (strtolower((string) $parts['scheme']) !== 'https') {
            return false;
        }

        $allowed_hosts = apply_filters('wc_stancer_allowed_redirect_hosts', ['stancer.com']);
        $allowed_hosts = is_array($allowed_hosts) ? $allowed_hosts : ['stancer.com'];

        $host = strtolower((string) $parts['host']);

        foreach ($allowed_hosts as $allowed_host) {
            if (! is_string($allowed_host) || $allowed_host === '') {
                continue;
            }

            $allowed_host = strtolower(trim($allowed_host));

            if ($host === $allowed_host || str_ends_with($host, '.' . $allowed_host)) {
                return true;
            }
        }

        return false;
    }

    private function to_minor_units(float $amount): int
    {
        $precision = (int) wc_get_price_decimals();

        return (int) round($amount * (10 ** $precision));
    }

    private function mask_identifier(string $id): string
    {
        $id = trim($id);

        if ($id === '') {
            return '';
        }

        $prefix_pos = strpos($id, '_');

        if ($prefix_pos !== false && strlen($id) > ($prefix_pos + 1 + 7)) {
            return substr($id, 0, $prefix_pos + 1) . '***' . substr($id, -4);
        }

        if (strlen($id) <= 6) {
            return '***';
        }

        return substr($id, 0, 2) . '***' . substr($id, -2);
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

    private function validate_webhook_request(string $raw_body, bool $is_live): array
    {
        $secret = trim((string) $this->webhook_signing_secret);

        $signature_header = $this->get_request_header('X-Stancer-Signature');

        if ($signature_header === '') {
            $signature_header = $this->get_request_header('Stancer-Signature');
        }

        if ($signature_header === '') {
            return [
                'ok'        => false,
                'reason'    => 'missing_signature_header',
                'timestamp' => null,
                'signature' => '',
            ];
        }

        $parsed = $this->parse_signature_header($signature_header);

        if ($parsed['signature'] === '') {
            return [
                'ok'        => false,
                'reason'    => 'missing_signature_value',
                'timestamp' => $parsed['timestamp'],
                'signature' => '',
            ];
        }

        $signature = strtolower((string) $parsed['signature']);
        $timestamp = $parsed['timestamp'];

        if ($timestamp !== null) {
            $ttl = $this->get_webhook_ttl_seconds();

            if ($is_live && abs(time() - $timestamp) > $ttl) {
                return [
                    'ok'        => false,
                    'reason'    => 'expired_timestamp',
                    'timestamp' => $timestamp,
                    'signature' => $signature,
                ];
            }

            $signed_payload = $timestamp . '.' . $raw_body;
            $computed       = hash_hmac('sha256', $signed_payload, $secret);

            if (hash_equals($computed, $signature)) {
                return [
                    'ok'        => true,
                    'reason'    => 'ok',
                    'timestamp' => $timestamp,
                    'signature' => $signature,
                ];
            }

            return [
                'ok'        => false,
                'reason'    => 'invalid_signature',
                'timestamp' => $timestamp,
                'signature' => $signature,
            ];
        }

        if ($is_live) {
            return [
                'ok'        => false,
                'reason'    => 'missing_timestamp',
                'timestamp' => null,
                'signature' => $signature,
            ];
        }

        // Legacy fallback for test mode when timestamp is absent.
        $legacy = hash_hmac('sha256', $raw_body, $secret);

        return [
            'ok'        => hash_equals($legacy, $signature),
            'reason'    => hash_equals($legacy, $signature) ? 'ok_legacy_test' : 'invalid_legacy_signature',
            'timestamp' => null,
            'signature' => $signature,
        ];
    }

    private function parse_signature_header(string $header): array
    {
        $signature = '';
        $timestamp = null;

        foreach (explode(',', $header) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (strpos($part, '=') === false) {
                if ($signature === '' && preg_match('/^[a-f0-9]{64}$/i', $part) === 1) {
                    $signature = strtolower($part);
                }

                continue;
            }

            [$key, $value] = explode('=', $part, 2);
            $key   = strtolower(trim((string) $key));
            $value = trim((string) $value);

            if ($key === 'v1' && preg_match('/^[a-f0-9]{64}$/i', $value) === 1) {
                $signature = strtolower($value);
            }

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            }
        }

        return [
            'signature' => $signature,
            'timestamp' => $timestamp,
        ];
    }

    private function is_replayed_webhook(string $fingerprint): bool
    {
        if ($fingerprint === '') {
            return false;
        }

        return get_transient('wc_stancer_replay_' . $fingerprint) !== false;
    }

    private function remember_webhook_fingerprint(string $fingerprint): void
    {
        if ($fingerprint === '') {
            return;
        }

        set_transient('wc_stancer_replay_' . $fingerprint, 1, self::WEBHOOK_REPLAY_TTL_SECONDS);
    }

    private function respond_json(int $status_code, string $message): void
    {
        status_header($status_code);
        header('Content-Type: application/json; charset=' . get_bloginfo('charset'));
        echo wp_json_encode(['message' => $message]);
        exit;
    }

    private function get_request_header(string $name): string
    {
        $server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        if (isset($_SERVER[$server_key])) {
            return sanitize_text_field(wp_unslash($_SERVER[$server_key]));
        }

        if ($name === 'Content-Type' && isset($_SERVER['CONTENT_TYPE'])) {
            return sanitize_text_field(wp_unslash($_SERVER['CONTENT_TYPE']));
        }

        return '';
    }

    private function sync_order_status_from_intent(WC_Order $order, string $status, string $intent_id): void
    {
        $current_status = (string) $order->get_status();
        $stock_guard_key = '_stancer_stock_restored_for_' . sanitize_key($intent_id);

        if ($status === 'captured') {
            if (! $order->is_paid()) {
                $order->payment_complete($intent_id);
            } else {
                WC_Stancer_Logger::log('debug', 'Captured status ignored because order is already paid.', ['order_id' => (int) $order->get_id(), 'intent_id' => $intent_id, 'status' => $status]);
                return;
            }
            $order->add_order_note(
                sprintf(
                    /* translators: %s: payment intent id */
                    __('Stancer payment captured successfully. Intent: %s', 'woocommerce-stancer-gateway'),
                    $intent_id
                )
            );
            WC_Stancer_Logger::log('info', 'Order marked as paid from Stancer status.', ['order_id' => (int) $order->get_id(), 'intent_id' => $intent_id, 'status' => $status]);

            return;
        }

        if ($status === 'authorized' || $status === 'processing') {
            if ($current_status === 'on-hold') {
                WC_Stancer_Logger::log('debug', 'On-hold status already set for order.', ['order_id' => (int) $order->get_id(), 'intent_id' => $intent_id, 'status' => $status]);
                return;
            }

            $order->update_status(
                'on-hold',
                sprintf(
                    /* translators: 1: payment status 2: payment intent id */
                    __('Stancer payment status: %1$s. Intent: %2$s', 'woocommerce-stancer-gateway'),
                    $status,
                    $intent_id
                )
            );
            WC_Stancer_Logger::log('info', 'Order moved to on-hold from Stancer status.', ['order_id' => (int) $order->get_id(), 'intent_id' => $intent_id, 'status' => $status]);

            return;
        }

        if ($status === 'canceled') {
            if ($current_status === 'cancelled') {
                WC_Stancer_Logger::log('debug', 'Cancelled status already set for order.', ['order_id' => (int) $order->get_id(), 'intent_id' => $intent_id, 'status' => $status]);
                return;
            }

            $order->update_status(
                'cancelled',
                sprintf(
                    /* translators: %s: payment intent id */
                    __('Stancer payment was canceled. Intent: %s', 'woocommerce-stancer-gateway'),
                    $intent_id
                )
            );

            if (! $order->get_meta($stock_guard_key, true)) {
                wc_maybe_increase_stock_levels($order->get_id());
                $order->update_meta_data($stock_guard_key, 'yes');
                $order->save();
            }
            WC_Stancer_Logger::log('warning', 'Order cancelled from Stancer status.', ['order_id' => (int) $order->get_id(), 'intent_id' => $intent_id, 'status' => $status]);

            return;
        }

        if ($status === 'unpaid') {
            if ($current_status === 'failed') {
                WC_Stancer_Logger::log('debug', 'Failed status already set for order.', ['order_id' => (int) $order->get_id(), 'intent_id' => $intent_id, 'status' => $status]);
                return;
            }

            $order->update_status(
                'failed',
                sprintf(
                    /* translators: %s: payment intent id */
                    __('Stancer payment failed. Intent: %s', 'woocommerce-stancer-gateway'),
                    $intent_id
                )
            );

            if (! $order->get_meta($stock_guard_key, true)) {
                wc_maybe_increase_stock_levels($order->get_id());
                $order->update_meta_data($stock_guard_key, 'yes');
                $order->save();
            }
            WC_Stancer_Logger::log('warning', 'Order marked failed from Stancer status.', ['order_id' => (int) $order->get_id(), 'intent_id' => $intent_id, 'status' => $status]);

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
            WC_Stancer_Logger::log('notice', 'Payment requires customer action.', ['order_id' => (int) $order->get_id(), 'intent_id' => $intent_id, 'status' => $status]);
        }
    }
}
