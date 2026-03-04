<?php
/**
 * Plugin Name: WooCommerce Stancer Gateway
 * Plugin URI: https://github.com/your-org/woocommerce-stancer-gateway
 * Description: Add Stancer as a payment gateway for WooCommerce.
 * Version: 0.1.0
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Author: Your Name
 * Author URI: https://example.com
 * Text Domain: woocommerce-stancer-gateway
 * Domain Path: /languages
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (! defined('ABSPATH')) {
    exit;
}

define('WC_STANCER_GATEWAY_VERSION', '0.1.0');
define('WC_STANCER_GATEWAY_PLUGIN_FILE', __FILE__);
define('WC_STANCER_GATEWAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_STANCER_GATEWAY_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WC_STANCER_GATEWAY_PLUGIN_DIR . 'includes/class-wc-stancer-gateway-bootstrap.php';

WC_Stancer_Gateway_Bootstrap::init();