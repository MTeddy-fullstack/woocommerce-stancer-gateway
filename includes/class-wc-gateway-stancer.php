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

    public function __construct()
    {
        $this->id                 = 'stancer';
        $this->icon               = '';
        $this->has_fields         = false;
        $this->method_title       = __('Stancer', 'woocommerce-stancer-gateway');
        $this->method_description = __('Accept card payments through Stancer.', 'woocommerce-stancer-gateway');
        $this->supports           = [
            'products',
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

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_api_wc_gateway_stancer', [$this, 'handle_return_callback']);
    }

    public function init_form_fields(): void
    {
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
        return new WP_Error(
            'stancer_refund_not_implemented',
            __('Refunds are not implemented yet.', 'woocommerce-stancer-gateway')
        );
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
