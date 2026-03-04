<?php

if (! defined('ABSPATH')) {
    exit;
}

class WC_Stancer_Admin_Logs_Page
{
    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Stancer Logs', 'woocommerce-stancer-gateway'),
            __('Stancer Logs', 'woocommerce-stancer-gateway'),
            'manage_woocommerce',
            'wc-stancer-logs',
            [__CLASS__, 'render']
        );
    }

    public static function render(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        if (isset($_POST['wc_stancer_clear_logs']) && check_admin_referer('wc_stancer_clear_logs_action')) {
            WC_Stancer_Logger::clear();
            echo '<div class="notice notice-success"><p>' . esc_html__('Stancer logs have been cleared.', 'woocommerce-stancer-gateway') . '</p></div>';
        }

        $events = WC_Stancer_Logger::get_events(200);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Stancer Logs', 'woocommerce-stancer-gateway') . '</h1>';
        echo '<p>' . esc_html__('Recent Stancer gateway events recorded by this plugin.', 'woocommerce-stancer-gateway') . '</p>';
        echo '<form method="post" style="margin-bottom: 1rem;">';
        wp_nonce_field('wc_stancer_clear_logs_action');
        submit_button(__('Clear logs', 'woocommerce-stancer-gateway'), 'delete', 'wc_stancer_clear_logs', false);
        echo '</form>';

        if (empty($events)) {
            echo '<p>' . esc_html__('No log events found.', 'woocommerce-stancer-gateway') . '</p>';
            echo '</div>';

            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Time (UTC)', 'woocommerce-stancer-gateway') . '</th>';
        echo '<th>' . esc_html__('Level', 'woocommerce-stancer-gateway') . '</th>';
        echo '<th>' . esc_html__('Message', 'woocommerce-stancer-gateway') . '</th>';
        echo '<th>' . esc_html__('Context', 'woocommerce-stancer-gateway') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($events as $event) {
            $timestamp = isset($event['timestamp']) ? (string) $event['timestamp'] : '';
            $level     = isset($event['level']) ? strtoupper((string) $event['level']) : '';
            $message   = isset($event['message']) ? (string) $event['message'] : '';
            $context   = isset($event['context']) && is_array($event['context']) ? wp_json_encode($event['context']) : '';

            echo '<tr>';
            echo '<td><code>' . esc_html($timestamp) . '</code></td>';
            echo '<td>' . esc_html($level) . '</td>';
            echo '<td>' . esc_html($message) . '</td>';
            echo '<td><code style="white-space: pre-wrap; word-break: break-word;">' . esc_html((string) $context) . '</code></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }
}
