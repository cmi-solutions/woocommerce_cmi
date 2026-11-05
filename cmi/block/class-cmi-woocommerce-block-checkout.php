<?php
defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;


/**
 * Cmi Payment Gateway Blocks integration
 *
 * @since 1.0.0
 */
final class WC_Cmi_Blocks extends AbstractPaymentMethodType
{

    /**
     * The gateway instance.
     *
     * @var WC_Gateway_cmi
     */
    private $gateway;

    /**
     * Payment method name/id/slug.
     *
     * @var string
     */
    protected $name = 'cmi';

    /**
     * Initializes the payment method type.
     */
    public function initialize()
    {
        $this->settings = get_option('woocommerce_cmi_settings', []);
        $this->gateway  = WC_Gateway_cmi::get_gateway_instance();
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active()
    {
        return $this->gateway->is_available();
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles()
    {

        $script_path = plugins_url('assets/js/cmi-block.js', __DIR__);
        // The file's date as version: browsers load the new script after each plugin update.
        $script_file = dirname(__DIR__) . '/assets/js/cmi-block.js';
        $script_version = file_exists($script_file) ? (string) filemtime($script_file) : '2.0.0';
        $dependancies = [
            'react',
            'wp-blocks',
            'wp-element',
            'wp-components',
            'wc-blocks-registry',
            'wc-settings',
            'wp-html-entities',
            'wp-i18n',
        ];
        wp_register_script(
            'wc-cmi-blocks-integration',
            $script_path,
            $dependancies,
            $script_version,
            true
        );

        return ['wc-cmi-blocks-integration'];
    }


    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data()
    {
        return [
            'title'         => $this->gateway->title,
            'description'   => $this->gateway->description,
            'icon'         => $this->gateway->icon,
            'supports'      => array_filter($this->gateway->supports, [$this->gateway, 'supports'])
        ];
    }
}
