<?php

if (! defined('ABSPATH')) {
    exit;
}

class WC_Stancer_Gateway_Bootstrap
{
    public static function init(): void
    {
        add_action('plugins_loaded', [__CLASS__, 'on_plugins_loaded']);
        add_action('init', [__CLASS__, 'load_textdomain']);
    }

    public static function on_plugins_loaded(): void
    {
        if (! class_exists('WooCommerce')) {
            add_action('admin_notices', [__CLASS__, 'missing_woocommerce_notice']);

            return;
        }

        require_once WC_STANCER_GATEWAY_PLUGIN_DIR . 'includes/class-wc-stancer-api-client.php';
        require_once WC_STANCER_GATEWAY_PLUGIN_DIR . 'includes/class-wc-gateway-stancer.php';

        add_filter('woocommerce_payment_gateways', [__CLASS__, 'register_gateway']);
    }

    public static function register_gateway(array $gateways): array
    {
        $gateways[] = 'WC_Gateway_Stancer';

        return $gateways;
    }

    public static function load_textdomain(): void
    {
        load_plugin_textdomain(
            'woocommerce-stancer-gateway',
            false,
            dirname(plugin_basename(WC_STANCER_GATEWAY_PLUGIN_FILE)) . '/languages/'
        );
    }

    public static function missing_woocommerce_notice(): void
    {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo esc_html__('WooCommerce Stancer Gateway requires WooCommerce to be installed and active.', 'woocommerce-stancer-gateway');
        echo '</p></div>';
    }
}
