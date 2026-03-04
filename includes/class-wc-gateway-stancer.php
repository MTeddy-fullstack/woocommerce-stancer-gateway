<?php

if (! defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Stancer extends WC_Payment_Gateway
{
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

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
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

        wc_add_notice(__('Stancer payment flow is not implemented yet.', 'woocommerce-stancer-gateway'), 'notice');

        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url($order),
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
}