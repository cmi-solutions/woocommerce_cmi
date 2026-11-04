<?php
/*
	Plugin Name: WooCommerce CMI Gateway
	Plugin URI: https://www.cmi.co.ma/
	Description: Accept card payments through CMI's secure hosted payment page.
	Version: 2.0.0
	Author: cmi - CMI
	Author URI: https://www.cmi.co.ma/
	Text Domain: cmi
	Domain Path: /languages
	License: GPL-2.0-or-later
	License URI: https://www.gnu.org/licenses/gpl-2.0.html
	Requires at least: 6.7
	Tested up to: 7.1.4
	Requires PHP: 8.3
	WC requires at least: 9.0
	WC tested up to: 11.0.1
*/

defined('ABSPATH') || exit;


/**
 * Load the translations. WordPress provides the language (the site's, or the admin's own
 * profile language). All French variants (fr_FR, fr_CA, fr_BE...) use the French file;
 * every other language shows the plugin's English texts.
 */
function woocommerce_cmi_load_textdomain()
{
	$locale = determine_locale();
	if (strpos($locale, 'fr') === 0) {
		load_textdomain('cmi', plugin_dir_path(__FILE__) . 'languages/cmi-fr_FR.mo', $locale);
	}
}
add_action('init', 'woocommerce_cmi_load_textdomain', 0);

// PHP version guard — prevent fatal errors on unsupported PHP versions.
if (version_compare(PHP_VERSION, '8.3', '<')) {
	add_action('admin_notices', function () {
		echo '<div class="error"><p><strong>WooCommerce CMI Gateway</strong> ' . esc_html(sprintf(__('requires PHP 8.3 or higher. You are running PHP %s.', 'cmi'), PHP_VERSION)) . '</p></div>';
	});
	return;
}

/**
 * Plugin updates
 */

add_action('plugins_loaded', 'woocommerce_cmi_init', 0);

/**
 * Initialize the gateway.
 *
 * @since 1.0.0
 */
function woocommerce_cmi_init()
{

	if (!class_exists('WC_Payment_Gateway')) {
		add_action('admin_notices', function () {
			echo '<div class="error"><p><strong>WooCommerce CMI Gateway</strong> ' . esc_html__('requires WooCommerce to be active.', 'cmi') . '</p></div>';
		});
		return;
	}

	require_once plugin_dir_path(__FILE__) . 'classes/class-cmi-payment-state.php';
	require_once plugin_dir_path(__FILE__) . 'classes/class-cmi-currency.php';
	require_once plugin_dir_path(__FILE__) . 'classes/class-cmi-transaction-guard.php';
	require_once plugin_dir_path(__FILE__) . 'classes/cmi.class.php';

	add_filter('woocommerce_payment_gateways', 'woocommerce_cmi_add_gateway');

	// Register admin order list actions & hooks independently of gateway instantiation
	WC_Gateway_cmi::init_admin_hooks();

	// Register custom 'confirmed' and custom order statuses in WooCommerce (HPOS & CPT)
	add_filter('woocommerce_register_shop_order_post_statuses', array('WC_Gateway_cmi', 'register_shop_order_post_statuses'));
	add_filter('wc_order_statuses', array('WC_Gateway_cmi', 'add_confirmed_to_order_statuses'));
	add_filter('woocommerce_order_is_paid_statuses', array('WC_Gateway_cmi', 'add_paid_order_statuses'));
	add_filter('woocommerce_reports_order_statuses', array('WC_Gateway_cmi', 'add_paid_order_statuses'));
	add_action('init', array('WC_Gateway_cmi', 'register_confirmed_order_status'));

	// Orders paid with CMI show the same number in WooCommerce and in the CMI back office
	add_filter('woocommerce_order_number', array('WC_Gateway_cmi', 'filter_order_number'), 99, 2);

} // End woocommerce_cmi_init()

/**
 * Add the gateway to WooCommerce
 *
 * @since 1.0.0
 */
function woocommerce_cmi_add_gateway($methods)
{
	$methods[] = 'WC_Gateway_cmi';
	return $methods;
} // End woocommerce_cmi_add_gateway()

// Include the CMI Block loadder
require plugin_dir_path(__FILE__) . 'block/class-cmi-block-loader.php';
