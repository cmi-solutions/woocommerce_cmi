<?php
/*
	Plugin Name: WooCommerce CMI Gateway
	Plugin URI: http://www.cmi.co.ma/
	Description: Une plateforme de paiement sécurisée.
	Version: 1.8.1
	Author: cmi - CMI
	Author URI: http://www.cmi.co.ma/
	Requires at least: 3.5
	Tested up to: 5.8.2
*/



/**
 * Required functions
 */
if (!function_exists('woothemes_queue_update'))
	require_once('woo-includes/woo-functions.php');

/**
 * Plugin updates
 */

load_plugin_textdomain('wc_cmi', false, trailingslashit(dirname(plugin_basename(__FILE__))));

add_action('plugins_loaded', 'woocommerce_cmi_init', 0);

/**
 * Initialize the gateway.
 *
 * @since 1.0.0
 */
function woocommerce_cmi_init()
{

	if (!class_exists('WC_Payment_Gateway'))
		return;

	require_once(plugin_basename('classes/cmi.class.php'));

	add_filter('woocommerce_payment_gateways', 'woocommerce_cmi_add_gateway');

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