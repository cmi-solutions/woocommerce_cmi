<?php

/**
 * Utitlity class for load supported classes after woocommerce loaded
 *
 * @link       https://www.cmi.lk
 * @since      2.0.0
 *
 * @package    Cmi
 * @subpackage Cmi/gateway
 */


/**
 * Custom function to declare compatibility with cart_checkout_blocks feature 
 */
function ph_declare_cart_checkout_blocks_compatibility()
{
    // Check if the required class exists
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        // Declare compatibility for 'cart_checkout_blocks'
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', plugin_dir_path(__FILE__). 'gateway-cmi.php', true);
    }
}
// Hook the custom function to the 'before_woocommerce_init' action
add_action('before_woocommerce_init', 'ph_declare_cart_checkout_blocks_compatibility');


/**
 * Custom function to register a payment method type.
 */
function ph_register_order_approval_payment_method_type()
{
    // Check if the required class exists
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    // Include the custom Blocks Checkout class
    require_once plugin_dir_path(__FILE__) . 'class-cmi-woocommerce-block-checkout.php';

    // Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
            $payment_method_registry->register(new WC_Cmi_Blocks);
        }
    );
}

// Hook the custom function to the 'woocommerce_blocks_loaded' action
add_action('woocommerce_blocks_loaded', 'ph_register_order_approval_payment_method_type');