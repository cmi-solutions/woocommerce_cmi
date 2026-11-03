<?php
/**
 * Runs only when the plugin is deleted from the Plugins page (not when it is deactivated).
 *
 * Removes the CMI connection credentials, so they don't stay in the database of a site that
 * no longer uses the plugin. Every other setting and all order data (CMI order IDs, MAD
 * amounts, payment states, notes) are kept: they are the shop's payment history.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

/** Remove the credentials from the CMI settings of the current site. */
function woocommerce_cmi_remove_credentials(): void
{
	$settings = get_option('woocommerce_cmi_settings');
	if (!is_array($settings)) {
		return;
	}
	$credentials = array(
		'client_id', 'store_key', 'gateway_url', 'api_gateway_url', 'api_username', 'api_password',
		// Names used by older versions of the plugin
		'merchant_id', 'SLKSecretkey', 'actionslk', 'apigateway', 'usernameapi', 'passwordapi',
	);
	foreach ($credentials as $key) {
		unset($settings[$key]);
	}
	update_option('woocommerce_cmi_settings', $settings);
}

if (is_multisite()) {
	foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $site_id) {
		switch_to_blog($site_id);
		woocommerce_cmi_remove_credentials();
		restore_current_blog();
	}
} else {
	woocommerce_cmi_remove_credentials();
}
