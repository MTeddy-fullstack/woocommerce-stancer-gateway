<?php

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (! function_exists('sanitize_key')) {
    function sanitize_key($key)
    {
        $key = strtolower((string) $key);

        return preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field($value)
    {
        return trim((string) $value);
    }
}

if (! function_exists('current_time')) {
    function current_time($type, $gmt = 0)
    {
        return '2026-01-01 00:00:00';
    }
}

if (! function_exists('get_option')) {
    function get_option($key, $default = false)
    {
        return $GLOBALS['wc_stancer_test_options'][$key] ?? $default;
    }
}

if (! function_exists('update_option')) {
    function update_option($key, $value, $autoload = null)
    {
        $GLOBALS['wc_stancer_test_options'][$key] = $value;

        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option($key)
    {
        unset($GLOBALS['wc_stancer_test_options'][$key]);

        return true;
    }
}

require_once dirname(__DIR__) . '/includes/class-wc-stancer-logger.php';