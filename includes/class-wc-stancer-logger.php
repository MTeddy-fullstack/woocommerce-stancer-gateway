<?php

if (! defined('ABSPATH')) {
    exit;
}

class WC_Stancer_Logger
{
    private const OPTION_KEY = 'wc_stancer_gateway_events';
    private const MAX_EVENTS = 200;
    private const WC_LOG_SOURCE = 'woocommerce-stancer-gateway';

    public static function log(string $level, string $message, array $context = []): void
    {
        $level = self::normalize_level($level);
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
}
