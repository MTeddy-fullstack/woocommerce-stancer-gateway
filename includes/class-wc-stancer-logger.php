<?php

if (! defined('ABSPATH')) {
    exit;
}

class WC_Stancer_Logger
{
    private const OPTION_KEY = 'wc_stancer_gateway_events';
    private const MAX_EVENTS = 200;
    private const WC_LOG_SOURCE = 'woocommerce-stancer-gateway';
    private const ALLOWED_CONTEXT_KEYS = [
        'order_id',
        'mode',
        'status',
        'error_code',
        'intent_id',
        'refund_id',
        'reason',
        'http_status',
        'event',
        'method',
        'currency',
    ];

    public static function log(string $level, string $message, array $context = []): void
    {
        $level = self::normalize_level($level);
        $context = self::sanitize_context($context);
        $event = [
            'timestamp' => current_time('mysql', true),
            'level'     => $level,
            'message'   => $message,
            'context'   => $context,
        ];

        $events = get_option(self::OPTION_KEY, []);
        $events = is_array($events) ? $events : [];
        $events[] = $event;

        if (count($events) > self::MAX_EVENTS) {
            $events = array_slice($events, -1 * self::MAX_EVENTS);
        }

        update_option(self::OPTION_KEY, $events, false);

        if (class_exists('WC_Logger')) {
            wc_get_logger()->log($level, $message, array_merge(['source' => self::WC_LOG_SOURCE], $context));
        }
    }

    public static function get_events(int $limit = 100): array
    {
        $events = get_option(self::OPTION_KEY, []);
        $events = is_array($events) ? $events : [];

        if ($limit <= 0) {
            $limit = 1;
        }

        return array_reverse(array_slice($events, -1 * $limit));
    }

    public static function clear(): void
    {
        delete_option(self::OPTION_KEY);
    }

    private static function normalize_level(string $level): string
    {
        $allowed = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];
        $level   = strtolower(trim($level));

        if (! in_array($level, $allowed, true)) {
            return 'info';
        }

        return $level;
    }

    private static function sanitize_context(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            $key = sanitize_key((string) $key);

            if (! in_array($key, self::ALLOWED_CONTEXT_KEYS, true)) {
                continue;
            }

            if (is_scalar($value)) {
                $clean_value = sanitize_text_field((string) $value);
            } else {
                $clean_value = '';
            }

            if (in_array($key, ['intent_id', 'refund_id'], true)) {
                $clean_value = self::mask_value($clean_value);
            }

            $sanitized[$key] = $clean_value;
        }

        return $sanitized;
    }

    private static function mask_value(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $prefix_pos = strpos($value, '_');

        if ($prefix_pos !== false && strlen($value) > ($prefix_pos + 1 + 7)) {
            $prefix = substr($value, 0, $prefix_pos + 1);
            $tail   = substr($value, -4);

            return $prefix . '***' . $tail;
        }

        if (strlen($value) <= 6) {
            return '***';
        }

        return substr($value, 0, 2) . '***' . substr($value, -2);
    }
}
