<?php
/**
 * Plugin Name: Linora időpontfoglaló
 * Description: Kezelési konfigurátor blokk (nem → terület → alkalmak → időpont → összegzés). Az időpontokat az Amelia kezeli, a fizetést a WooCommerce.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Author: Ideastyle
 * Text Domain: linora-booking
 */

if (!defined('ABSPATH')) {
	exit;
}

define('LNR_BOOKING_VERSION', '1.0.0');
define('LNR_BOOKING_FILE', __FILE__);
define('LNR_BOOKING_DIR', __DIR__);

require_once __DIR__ . '/includes/catalog.php';
require_once __DIR__ . '/includes/amelia.php';
require_once __DIR__ . '/includes/checkout.php';
require_once __DIR__ . '/includes/block.php';
require_once __DIR__ . '/includes/importer.php';

register_activation_hook(__FILE__, ['LNR_Amelia', 'ensure_settings']);

add_action('plugins_loaded', function () {
	// Amelia reads its settings once per request, so the WooCommerce "book multiple" switch
	// has to be in place before Amelia initialises (plugins_loaded, priority 10).
	if (wp_doing_ajax() && isset($_REQUEST['action']) && $_REQUEST['action'] === 'lnr_booking_checkout') {
		LNR_Amelia::ensure_settings();
	}
}, 1);

add_action('init', ['LNR_Booking_Block', 'register'], 20);
add_action('wp_enqueue_scripts', ['LNR_Booking_Block', 'register_assets']);

add_action('wp_ajax_lnr_booking_slots', ['LNR_Booking_Checkout', 'ajax_slots']);
add_action('wp_ajax_nopriv_lnr_booking_slots', ['LNR_Booking_Checkout', 'ajax_slots']);
add_action('wp_ajax_lnr_booking_checkout', ['LNR_Booking_Checkout', 'ajax_checkout']);
add_action('wp_ajax_nopriv_lnr_booking_checkout', ['LNR_Booking_Checkout', 'ajax_checkout']);

add_filter('amelia_before_wc_cart_filter', ['LNR_Booking_Checkout', 'tag_cart_item']);
add_filter('woocommerce_cart_item_name', ['LNR_Booking_Checkout', 'cart_item_name'], 10, 2);
add_action('woocommerce_checkout_create_order_line_item', ['LNR_Booking_Checkout', 'order_item_name'], 20, 3);
add_action('woocommerce_thankyou', ['LNR_Booking_Checkout', 'clear_client_cart'], 5);

if (is_admin()) {
	LNR_Booking_Importer::init();
}
