<?php
/**
 * cmi Payment Gateway
 *
 * Provides a cmi Payment Gateway.
 *
 * @class 		woocommerce_cmi
 * @package		WooCommerce
 * @category	Payment Gateways
 * @author		CMI - CMI
 *
 *
 * Table Of Contents
 *
 * __construct() 
 * init_form_fields()
 * setup_constants() 
 * plugin_url()
 * is_valid_for_use()
 * admin_options()
 * payment_fields()
 * generate_cmi_form()
 * process_payment()
 * receipt_page()
 * check_CMI_request_is_valid()
 * check_CMI_response()
 * successful_request()
 * check_amounts()
 */















class WC_Gateway_cmi extends WC_Payment_Gateway
{

	public $version = '2.0.0';
	protected string $debug_email;
	protected string $plugin_url;
	protected array $available_countries;
	protected array $available_currencies;
	protected array $currency_details;
	protected array $data_to_send;
	protected string $merchant_id;
	protected string $actionslk;
	protected string $SLKSecretkey;
	protected string $confirmation_mode;
	protected string $postauth_status;
	protected string $postauth_custom_status;
	protected string $passwordapi;
	protected string $apigateway;
	protected string $usernameapi;
	protected string $exchange_rates;
	protected string $response_url;
	protected string $return_url;
	protected string $cancel_url;
	protected string $payment_page_language;
	protected string $order_id_format;

	private const PAYMENT_STATUS_INITIATED = Cmi_Payment_State::INITIATED;
	private const PAYMENT_STATUS_FAILED = Cmi_Payment_State::FAILED;
	private const PAYMENT_STATUS_CLIENT_CANCELLED = Cmi_Payment_State::CLIENT_CANCELLED;
	private const PAYMENT_STATUS_VOIDED = Cmi_Payment_State::VOIDED;
	private const PAYMENT_STATUS_PRE_CONFIRMATION_REQUIRED = Cmi_Payment_State::PRE_CONFIRMATION_REQUIRED;
	private const PAYMENT_STATUS_CONFIRMED = Cmi_Payment_State::CONFIRMED;
	private const PAYMENT_STATUS_REFUNDED = Cmi_Payment_State::REFUNDED;
	private const PAYMENT_STATUS_META_KEY = '_cmi_payment_status';

	public function __construct()
	{
		$this->id = 'cmi';
		$this->method_title = __('cmi', 'CMI - CMI');
		$this->icon = $this->plugin_url() . '/assets/images/cb.png';
		$this->has_fields = true;
		$this->supports = array('products', 'refunds');
		$this->debug_email = get_option('admin_email');

		// Setup available countries.
		$this->available_countries = array('FR');

		// Setup available currency codes.
		$this->available_currencies = array('MAD');

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();
		$this->migrate_legacy_settings();
		$this->init_settings();

		// Setup constants.
		$this->setup_constants();

		// Setup default merchant data.
		$this->merchant_id = $this->get_config_value('client_id', 'merchant_id');
		$this->actionslk = $this->get_config_value('gateway_url', 'actionslk');
		$this->SLKSecretkey = $this->get_config_value('store_key', 'SLKSecretkey');
		$this->title = (string) $this->get_option('title', __('CMI', 'woocommerce'));
		$this->confirmation_mode = $this->normalise_confirmation_mode((string) $this->get_option('confirmation_mode', 'automatic'));
		$this->postauth_status = $this->get_postauth_order_status();
		$this->postauth_custom_status = self::sanitize_custom_status_slug((string) $this->get_option('postauth_custom_status', ''));
		$this->payment_page_language = (string) $this->get_option('payment_page_language', 'auto');
		$this->order_id_format = (string) $this->get_option('order_id_format', 'number');
		$this->apigateway = $this->get_config_value('api_gateway_url', 'apigateway');
		$this->passwordapi = $this->get_config_value('api_password', 'passwordapi');
		$this->usernameapi = $this->get_config_value('api_username', 'usernameapi');
		$this->exchange_rates = (string) $this->get_option('exchange_rates', '');

		$this->currency_details = get_option(
			'woocommerce_cmi_currencies',
			array(
				array(
					'currency_code' => $this->get_option('currency_code'),
					'currency_rate' => $this->get_option('currency_rate'),
				),
			)
		);



		$this->response_url = add_query_arg('wc-api', 'WC_Gateway_cmi', home_url('/'));
		$this->return_url = add_query_arg('wc-api', 'WC_Gateway_cmi_return', home_url('/'));
		$this->cancel_url = add_query_arg('wc-api', 'WC_Gateway_cmi_cancel', home_url('/'));

		add_action('woocommerce_api_wc_gateway_cmi', array($this, 'check_CMI_response'));
		add_action('woocommerce_api_wc_gateway_cmi_return', array($this, 'handle_payment_return'));
		add_action('woocommerce_api_wc_gateway_cmi_cancel', array($this, 'handle_customer_cancellation'));
		/* 1.0.1 */
		add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));
		/* 1.0 */
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'save_currency_details'));
		add_action('woocommerce_receipt_cmi', array($this, 'receipt_page'));
		add_action('admin_enqueue_scripts', array($this, 'wpse_load_plugin_css'));
		add_action('woocommerce_thankyou_cmi', array($this, 'render_payment_result'));
	}
	/**
	 * Initialise Gateway Settings Form Fields
	 *
	 * @since 1.0.0
	 */
	function init_form_fields()
	{
		$this->form_fields = array(

			// ── Section A: General Settings ──────────────────────────────────
			'section_general' => array(
				'title' => __('General Settings', 'woocommerce'),
				'type' => 'title',
				'description' => __('Basic payment gateway configuration. These settings control how CMI appears to your customers at checkout.', 'woocommerce'),
			),
			'enabled' => array(
				'title' => __('Enable CMI Payment', 'woocommerce'),
				'label' => __('Enable CMI as a checkout payment method', 'woocommerce'),
				'type' => 'checkbox',
				'description' => __('When enabled, customers will see CMI as a payment option during checkout.', 'woocommerce'),
				'default' => 'yes',
			),
			'title' => array(
				'title' => __('Payment Title', 'woocommerce'),
				'type' => 'text',
				'description' => __('The name customers see for this payment method at checkout (e.g. "Pay by Card", "CMI").', 'woocommerce'),
				'default' => __('CMI', 'woocommerce'),
			),
			'description' => array(
				'title' => __('Payment Description', 'woocommerce'),
				'type' => 'text',
				'description' => __('A short description shown below the payment title at checkout (e.g. "Secure payment via CMI").', 'woocommerce'),
				'default' => '',
			),

			// ── Section B: Payment Setup ─────────────────────────────────────
			'section_payment_setup' => array(
				'title' => __('Payment Setup', 'woocommerce'),
				'type' => 'title',
				'description' => __('Choose your CMI kit type and configure how payments are processed.', 'woocommerce'),
			),
			'kit_type' => array(
				'title' => __('CMI Kit Type', 'woocommerce'),
				'type' => 'select',
				'description' => __('<strong>Standard Kit</strong> — Redirects the customer to the CMI hosted payment page. Transaction management (confirmation, void, refund) must be done from the CMI back office.<br><strong>Complete Kit (with API)</strong> — Same as Standard Kit, plus a <strong>CMI Actions</strong> column in the WooCommerce orders list allowing you to <strong>Confirm</strong>, <strong>Void</strong>, and <strong>Refund</strong> transactions directly from your shop.', 'woocommerce'),
				'default' => 'complete',
				'options' => array(
					'standard' => __('Standard Kit — Hosted Payment Page only', 'woocommerce'),
					'complete' => __('Complete Kit — With API Management (Confirm, Void, Refund)', 'woocommerce'),
				),
			),
			'confirmation_mode' => array(
				'title' => __('Payment Confirmation', 'woocommerce'),
				'type' => 'select',
				'description' => __('<strong>Automatic</strong> — The payment is captured immediately when the customer completes checkout.<br><strong>Manual</strong> — The payment is only authorized (pre-authorized). You must manually confirm it from the orders list to capture the funds if you have the Complete Kit (API), or via the CMI back office if you only have the Standard Kit.', 'woocommerce'),
				'default' => 'automatic',
				'options' => array(
					'automatic' => __('Automatic — Capture payment immediately', 'woocommerce'),
					'manual' => __('Manual — Pre-authorize only (confirm later)', 'woocommerce'),
				),
			),
			'postauth_status' => array(
				'title' => __('Order Status After PostAuth', 'woocommerce'),
				'type' => 'select',
				'description' => __('Choose the WooCommerce order status applied after a successful payment capture (PostAuth / Confirmation). Select an existing status or choose "Custom" to enter your own.', 'woocommerce'),
				'default' => 'completed',
				'options' => $this->get_available_order_status_options(),
			),
			'postauth_custom_status' => array(
				'title' => __('Custom Order Status Slug', 'woocommerce'),
				'type' => 'text',
				'description' => __('Enter a short status slug (<strong>max 17 characters</strong>, letters, numbers, and hyphens only). The <code>wc-</code> prefix is added automatically. Example: <code>cmi-confirmed</code>, <code>prep-deliv</code>.', 'woocommerce'),
				'default' => '',
				'custom_attributes' => array(
					'maxlength' => '17',
					'placeholder' => 'e.g. cmi-confirmed',
				),
			),
			'payment_page_language' => array(
				'title' => __('Payment Page Language', 'woocommerce'),
				'type' => 'select',
				'description' => __('The language displayed on the CMI hosted payment page. "Auto" uses your store\'s locale.', 'woocommerce'),
				'default' => 'auto',
				'options' => array(
					'auto' => __('Auto — Use store locale', 'woocommerce'),
					'fr' => __('French', 'woocommerce'),
					'en' => __('English', 'woocommerce'),
					'ar' => __('Arabic', 'woocommerce'),
				),
			),
			'order_id_format' => array(
				'title' => __('Order Identifier Format', 'woocommerce'),
				'type' => 'select',
				'description' => __('Choose how the order identifier is sent to CMI.<br><strong>WooCommerce Order Number</strong> — Sends the formatted order number, supporting sequential or custom order number plugins (e.g. <code>CMD-2026-0001</code>).<br><strong>WordPress Order ID</strong> — Sends the raw internal numeric database ID (e.g. <code>123</code>).<br><em>Note: Identifiers are automatically sanitized to meet CMI specifications (alphanumeric, hyphens, and underscores only; max 64 characters; special characters and spaces are stripped).</em>', 'woocommerce'),
				'default' => 'number',
				'options' => array(
					'number' => __('WooCommerce Order Number (Sequential / Alphanumeric)', 'woocommerce'),
					'id' => __('WordPress Order ID (Standard numeric ID)', 'woocommerce'),
				),
			),

			// ── Section C: Gateway Connection ────────────────────────────────
			'section_gateway_connection' => array(
				'title' => __('Gateway Connection', 'woocommerce'),
				'type' => 'title',
				'description' => __('Enter the credentials provided by CMI. These are required for your shop to connect to the CMI payment gateway.', 'woocommerce'),
			),
			'client_id' => array(
				'title' => __('Client ID *', 'woocommerce'),
				'type' => 'text',
				'description' => __('Your merchant Client ID provided by CMI (e.g. 600XXXXXX).', 'woocommerce'),
				'default' => '',
				'custom_attributes' => array('required' => 'required'),
			),
			'store_key' => array(
				'title' => __('Store Key (Hash Key) *', 'woocommerce'),
				'type' => 'password',
				'description' => __('The secret hash key configured in your CMI Merchant Center. Used to secure communication between your shop and CMI.', 'woocommerce'),
				'default' => '',
				'custom_attributes' => array('required' => 'required'),
			),
			'gateway_url' => array(
				'title' => __('Payment Page URL *', 'woocommerce'),
				'type' => 'text',
				'description' => __('The CMI hosted payment page URL (e.g. https://payment.cmi.co.ma/fim/est3Dgate).', 'woocommerce'),
				'default' => '',
				'custom_attributes' => array('required' => 'required'),
			),

			// ── Section D: API Management Credentials ────────────────────────
			'section_api_credentials' => array(
				'title' => __('API Management Credentials', 'woocommerce'),
				'type' => 'title',
				'description' => __('These credentials are provided by CMI and are required for <strong>Confirmation</strong> (PostAuth), <strong>Void</strong>, and <strong>Refund</strong> (Credit) operations. Only needed when using the Complete Kit.', 'woocommerce'),
			),
			'api_gateway_url' => array(
				'title' => __('CMI API URL *', 'woocommerce'),
				'type' => 'text',
				'description' => __('The XML API endpoint URL (e.g. https://payment.cmi.co.ma/fim/api). This is NOT the same as the Payment Page URL above.', 'woocommerce'),
				'default' => '',
			),
			'api_username' => array(
				'title' => __('API Username *', 'woocommerce'),
				'type' => 'text',
				'description' => __('The API username provided by CMI for back-office operations.', 'woocommerce'),
				'default' => '',
			),
			'api_password' => array(
				'title' => __('API Password *', 'woocommerce'),
				'type' => 'password',
				'description' => __('The API password provided by CMI for back-office operations.', 'woocommerce'),
				'default' => '',
			),

			// ── Section E: Currency & Exchange Rates ─────────────────────────
			'section_currency' => array(
				'title' => __('Currency & Exchange Rates', 'woocommerce'),
				'type' => 'title',
				'description' => __('Payments are processed in MAD (Moroccan Dirham) on your standard CMI merchant account.', 'woocommerce'),
			),
			'exchange_rates' => array(
				'title' => __('Exchange Rate Mode', 'woocommerce'),
				'type' => 'select',
				'class' => 'wc-enhanced-select',
				'description' => __(
					'If your WooCommerce store currency is not MAD, choose how to convert order amounts to MAD before sending them to CMI.<br>'
					. '<strong>Manual</strong> — You enter and maintain exchange rates yourself in the table below.<br>'
					. '<strong>External Multi-Currency Plugin</strong> — Rates are retrieved automatically from a compatible multi-currency plugin '
					. '(WOOCS / FOX, WPML + WCML, CURCY / VillaTheme, Aelia Currency Switcher) or via the <code>woocommerce_cmi_exchange_rate</code> filter hook.<br>'
					. '<em>⚠️ In both cases, you are fully responsible for the accuracy of the exchange rates applied.</em>',
					'woocommerce'
				),
				'default' => '',
				'options' => array(
					'' => __('— Not needed (store currency is MAD)', 'woocommerce'),
					'manual' => __('Manual — Enter exchange rates manually', 'woocommerce'),
					'plugin' => __('External Multi-Currency Plugin — Automatic rates', 'woocommerce'),
				),
			),
			'currency_details' => array(
				'type' => 'currency_details',
			),
		);
	} // End init_form_fields()

	/** Keep existing stores working while presenting the renamed settings. */
	private function migrate_legacy_settings(): void
	{
		$settings = get_option('woocommerce_cmi_settings', array());
		$map = array(
			'client_id' => 'merchant_id',
			'store_key' => 'SLKSecretkey',
			'gateway_url' => 'actionslk',
			'api_gateway_url' => 'apigateway',
			'api_username' => 'usernameapi',
			'api_password' => 'passwordapi',
		);
		$changed = false;
		foreach ($map as $new_key => $legacy_key) {
			if (empty($settings[$new_key]) && !empty($settings[$legacy_key])) {
				$settings[$new_key] = $settings[$legacy_key];
				$changed = true;
			}
		}
		if (isset($settings['confirmation_mode']) && in_array($settings['confirmation_mode'], array('yes', 'no'), true)) {
			$settings['confirmation_mode'] = $settings['confirmation_mode'] === 'yes' ? 'automatic' : 'manual';
			$changed = true;
		}
		// Migrate legacy 'automatic' exchange rate mode (dead API) → 'plugin'
		if (isset($settings['exchange_rates']) && $settings['exchange_rates'] === 'automatic') {
			$settings['exchange_rates'] = 'plugin';
			$changed = true;
		}
		// Defaults for new fields — existing installs keep working
		$new_defaults = array(
			'kit_type' => 'complete',
			'offshore_account' => 'no',
			'offshore_currency' => 'EUR',
		);
		foreach ($new_defaults as $key => $default) {
			if (!isset($settings[$key]) || $settings[$key] === '') {
				$settings[$key] = $default;
				$changed = true;
			}
		}
		if ($changed) {
			update_option('woocommerce_cmi_settings', $settings);
		}
	}

	private function get_config_value(string $key, string $legacy_key): string
	{
		$value = $this->get_option($key, '');
		if ($value === '' || $value === null) {
			$value = $this->settings[$legacy_key] ?? '';
		}
		return (string) $value;
	}

	private function normalise_confirmation_mode(string $mode): string
	{
		return $mode === 'manual' || $mode === 'no' ? 'manual' : 'automatic';
	}

	/** Map ISO 4217 alpha currency code to its numeric code for CMI. Standard inshore account is always 504 (MAD). */
	private function get_currency_code(): string
	{
		return '504';
	}

	private function get_payment_language(): string
	{
		if (in_array($this->payment_page_language, array('fr', 'en', 'ar'), true)) {
			return $this->payment_page_language;
		}
		$locale = get_locale();
		return strpos($locale, 'ar') === 0 ? 'ar' : (strpos($locale, 'fr') === 0 ? 'fr' : 'en');
	}

	private function get_order_payment_status(WC_Order $order): string
	{
		return (string) $order->get_meta(self::PAYMENT_STATUS_META_KEY, true);
	}

	private function set_order_payment_status(WC_Order $order, string $status, array $transaction = array()): void
	{
		$order->update_meta_data(self::PAYMENT_STATUS_META_KEY, $status);
		$order->update_meta_data('_cmi_transaction_data', $transaction);
		$order->update_meta_data('_cmi_transaction_updated_at', gmdate('c'));
		$order->save();
	}

	private function payment_status_label(string $status): string
	{
		$labels = array(
			self::PAYMENT_STATUS_INITIATED => __('INITIATED', 'woocommerce'),
			self::PAYMENT_STATUS_FAILED => __('FAILED', 'woocommerce'),
			self::PAYMENT_STATUS_CLIENT_CANCELLED => __('CANCELLED', 'woocommerce'),
			self::PAYMENT_STATUS_VOIDED => __('VOIDED', 'woocommerce'),
			self::PAYMENT_STATUS_PRE_CONFIRMATION_REQUIRED => __('PRE_CONFIRMATION_REQUIRED', 'woocommerce'),
			self::PAYMENT_STATUS_CONFIRMED => __('SUCCESS', 'woocommerce'),
			self::PAYMENT_STATUS_REFUNDED => __('REFUNDED', 'woocommerce'),
		);
		return $labels[$status] ?? __('PENDING', 'woocommerce');
	}
	/**			  
	 * Generate currency details html.
	 *
	 * @return string
	 */
	public function generate_currency_details_html()
	{

		ob_start();

		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e('Currency details:', 'woocommerce'); ?></th>
			<td class="forminp" id="cmi_currencies">
				<div class="wc_input_table_wrapper">
					<table class="widefat wc_input_table sortable" cellspacing="0">
						<thead>
							<tr>
								<th class="sort">&nbsp;</th>
								<th><?php esc_html_e('Currency code', 'woocommerce'); ?></th>
								<th><?php esc_html_e('Currency rate', 'woocommerce'); ?></th>
							</tr>
						</thead>
						<tbody class="currencies">
							<?php
							$i = -1;
							$wc_currencies = get_woocommerce_currencies();
							$selctHtml = '';
							if ($this->currency_details) {
								foreach ($this->currency_details as $currency) {
									foreach ($wc_currencies as $k => $wc_currency) {
										$selctHtml .= '<option ' . selected($currency['currency_code'], $k, 0) . 'value="' . esc_attr($k) . '" >' . $k . '-' . esc_html($wc_currency) . ' (' . get_woocommerce_currency_symbol($k) . ')</option>';
									}
									$i++;

									echo '<tr class="currency">
										<td class="sort"></td>';
									echo '<td><select name="cmi_currency_code[' . esc_attr($i) . ']"class="vi-ui select2">';
									echo $selctHtml;
									echo '</select></td>';
									echo '<td><input type="text" value="' . esc_attr($currency['currency_rate']) . '" name="cmi_currency_rate[' . esc_attr($i) . ']" /></td>
									</tr>';
								}
							}
							?>
						</tbody>
						<tfoot>
							<tr>
								<th colspan="7"><a href="#"
										class="add button"><?php esc_html_e('+ Add currency', 'woocommerce'); ?></a> <a
										href="#"
										class="remove_rows button"><?php esc_html_e('Remove selected currency(s)', 'woocommerce'); ?></a>
								</th>
							</tr>
						</tfoot>
					</table>
				</div>
				<?php
				$wc_currencies = get_woocommerce_currencies();
				$currOptions = '';
				foreach ($wc_currencies as $k => $wc_currency) {
					$currOptions .= '<option ' . selected($currency, $k) . 'value="' . esc_attr($k) . '" >' . $k . '-' . esc_html($wc_currency) . ' (' . get_woocommerce_currency_symbol($k) . ')</option>';
				}
				?>
				<script type="text/javascript">
					jQuery(function () {
						jQuery('#cmi_currencies').on('click', 'a.add', function () {

							var size = jQuery('#cmi_currencies').find('tbody .currency').length;

							jQuery('<tr class="currency">\
											<td class="sort"></td>\
											<td><select name="cmi_currency_code[' + size + ']"class="vi-ui select2"><?php echo $currOptions; ?></select></td>\
											<td><input type="text" name="cmi_currency_rate[' + size + ']" /></td>\
										</tr>').appendTo('#cmi_currencies table tbody');

							return false;
						});
					});
				</script>
			</td>
		</tr>
		<?php
		return ob_get_clean();

	}
	/**									  
	 * Save currency details table.
	 */
	public function save_currency_details()
	{

		$currencies = array();

		if (isset($_POST['cmi_currency_code']) && isset($_POST['cmi_currency_rate'])) {

			$currency_codes = wc_clean(wp_unslash($_POST['cmi_currency_code']));
			$currency_rates = wc_clean(wp_unslash($_POST['cmi_currency_rate']));

			foreach ($currency_codes as $i => $code) {
				if (!isset($currency_codes[$i])) {
					continue;
				}

				$currencies[] = array(
					'currency_code' => $currency_codes[$i],
					'currency_rate' => $currency_rates[$i],
				);
			}
		}

		update_option('woocommerce_cmi_currencies', $currencies);
	}

	/**
	 * Get the plugin URL
	 *
	 * @since 1.0.0
	 */
	function plugin_url()
	{
		if (isset($this->plugin_url))
			return $this->plugin_url;

		return $this->plugin_url = plugins_url('', dirname(__DIR__) . '/gateway-cmi.php');
	} // End plugin_url()

	/**
	 * is_valid_for_use()
	 *
	 * Check if this gateway is enabled and available.
	 *
	 * @since 1.0.0
	 */
	function is_valid_for_use()
	{
		return true;
	}

	/**
	 * Check if gateway is available at checkout.
	 */
	public function is_available()
	{
		if ($this->enabled !== 'yes') {
			return false;
		}
		if (empty($this->merchant_id)) {
			return false;
		}
		return parent::is_available();
	}

	/**
	 * Admin Panel Options
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 *
	 * @since 1.0.0
	 */
	public function admin_options()
	{
		?>
		<style>
			.cmi-admin-header {
				background: #fff;
				border: 1px solid #ccd0d4;
				border-left: 4px solid #0073aa;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
				padding: 16px 20px;
				margin: 15px 0 20px;
				border-radius: 4px;
			}
			.cmi-admin-header h2 {
				margin: 0 0 6px 0;
				font-size: 18px;
				color: #1d2327;
				display: flex;
				align-items: center;
				gap: 8px;
			}
			.cmi-admin-header p {
				margin: 0;
				color: #50575e;
				font-size: 13px;
			}
			.form-table th.titledesc {
				font-weight: 600;
				color: #1d2327;
				width: 240px;
			}
			.form-table td.forminp input[type="text"],
			.form-table td.forminp input[type="password"],
			.form-table td.forminp select {
				min-width: 320px;
				max-width: 100%;
			}
		</style>

		<div class="cmi-admin-header">
			<h2>
				<span class="dashicons dashicons-shield-alt" style="color:#0073aa; font-size:22px; width:22px; height:22px;"></span>
				<?php esc_html_e('CMI Payment Gateway Configuration', 'woocommerce'); ?>
			</h2>
			<p><?php esc_html_e('Configure your Moroccan and International card payment processing via CMI. Fields with (*) are mandatory.', 'woocommerce'); ?></p>
		</div>

		<table class="form-table">
			<?php
			// Generate the HTML for the settings form natively (clean semantic <tr> elements)
			$this->generate_settings_html();
			?>
		</table>

		<!-- Plugin mode info box (inserted dynamically by JS) -->
		<div id="cmi_plugin_exchange_info" style="display:none; margin-top:10px;">
			<div style="background:#f0f6fc; border:1px solid #c3daf5; border-left:4px solid #2271b1; padding:12px 16px; border-radius:4px; max-width:600px;">
				<p style="margin:0 0 8px; font-weight:600; color:#1d2327;">
					<?php esc_html_e('🔌 External Plugin Mode Active', 'woocommerce'); ?>
				</p>
				<p style="margin:0 0 6px; color:#50575e; font-size:13px;">
					<?php esc_html_e('Exchange rates will be retrieved automatically from your multi-currency plugin. The following plugins are auto-detected:', 'woocommerce'); ?>
				</p>
				<ul style="margin:4px 0 8px 18px; color:#50575e; font-size:13px;">
					<li><strong>WOOCS / FOX</strong> — <?php esc_html_e('Currency Switcher for WooCommerce', 'woocommerce'); ?></li>
					<li><strong>WPML + WCML</strong> — <?php esc_html_e('WooCommerce Multilingual & Multicurrency', 'woocommerce'); ?></li>
					<li><strong>CURCY</strong> — <?php esc_html_e('VillaTheme Multi Currency for WooCommerce', 'woocommerce'); ?></li>
					<li><strong>Aelia</strong> — <?php esc_html_e('Aelia Currency Switcher', 'woocommerce'); ?></li>
				</ul>
				<p style="margin:0 0 6px; color:#50575e; font-size:13px;">
					<?php esc_html_e('For custom integrations, use the filter hooks:', 'woocommerce'); ?>
					<code>woocommerce_cmi_exchange_rate</code> <?php esc_html_e('or', 'woocommerce'); ?>
					<code>woocommerce_cmi_order_amount_mad</code>
				</p>
				<p style="margin:8px 0 0; padding-top:8px; border-top:1px solid #c3daf5; color:#b32d2e; font-size:12px; font-style:italic;">
					<?php esc_html_e('⚠️ You are fully responsible for ensuring that your multi-currency plugin is properly configured and that the exchange rates are accurate.', 'woocommerce'); ?>
				</p>
			</div>
		</div>

		<script type="text/javascript">
		jQuery(function($) {
			function cmiToggleFields() {
				// Kit type toggle: Complete Kit shows API credentials, Standard Kit hides them
				var isCompleteKit = $('#woocommerce_cmi_kit_type').val() === 'complete';
				$('#woocommerce_cmi_api_gateway_url').closest('tr').toggle(isCompleteKit);
				$('#woocommerce_cmi_api_username').closest('tr').toggle(isCompleteKit);
				$('#woocommerce_cmi_api_password').closest('tr').toggle(isCompleteKit);

				// Hide or show the API credentials section title and description paragraph
				var $apiSectionTitle = $('#woocommerce_cmi_section_api_credentials');
				$apiSectionTitle.toggle(isCompleteKit);
				$apiSectionTitle.next('p').toggle(isCompleteKit);

				$('h3, th').filter(function() {
					return $(this).text().indexOf('API Management Credentials') !== -1;
				}).each(function() {
					$(this).toggle(isCompleteKit);
					$(this).next('p').toggle(isCompleteKit);
				});

				// Exchange rates: show manual table OR plugin info, depending on mode
				var exchangeMode = $('#woocommerce_cmi_exchange_rates').val();
				var isManual = (exchangeMode === 'manual');
				var isPlugin = (exchangeMode === 'plugin');
				$('#cmi_currencies').closest('tr').toggle(isManual);

				// Show/hide plugin exchange info box
				var $pluginInfo = $('#cmi_plugin_exchange_info');
				if (isPlugin) {
					// Move it after the exchange_rates select row and show it
					var $exchangeRow = $('#woocommerce_cmi_exchange_rates').closest('tr');
					$pluginInfo.detach().insertAfter($exchangeRow.find('td.forminp .description, td.forminp p.description').last());
					$pluginInfo.show();
				} else {
					$pluginInfo.hide();
				}

				// PostAuth Status: only show custom status slug input when 'custom' is selected
				var isCustomStatus = $('#woocommerce_cmi_postauth_status').val() === 'custom';
				$('#woocommerce_cmi_postauth_custom_status').closest('tr').toggle(isCustomStatus);
			}

			// Block user from exceeding 17 characters in real-time and sanitize on input
			$('#woocommerce_cmi_postauth_custom_status').attr('maxlength', '17').on('input', function() {
				var val = $(this).val();
				val = val.replace(/^wc-/, '').replace(/[\s_]+/g, '-').toLowerCase().replace(/[^a-z0-9-]/g, '');
				if (val.length > 17) {
					val = val.substring(0, 17);
				}
				$(this).val(val);
			});

			// Listen for changes
			$('#woocommerce_cmi_kit_type, #woocommerce_cmi_exchange_rates, #woocommerce_cmi_postauth_status').on('change', cmiToggleFields);

			// Run on page load
			cmiToggleFields();
		});
		</script>
		<?php
	} // End admin_options()
	/**
	 * Retrieve the exchange rate from an external multi-currency plugin.
	 *
	 * Auto-detects: WOOCS/FOX, WPML+WCML, CURCY/VillaTheme, Aelia Currency Switcher.
	 * Falls back to the `woocommerce_cmi_exchange_rate` filter hook for custom integrations.
	 *
	 * @param string   $store_currency The WooCommerce store currency (e.g. 'EUR').
	 * @param WC_Order $order          The order being processed.
	 * @return float|false The exchange rate (1 unit of store_currency = X MAD), or false if unavailable.
	 */
	private function get_plugin_exchange_rate(string $store_currency, $order = null)
	{
		// 1. WOOCS / FOX — Currency Switcher for WooCommerce
		if (class_exists('WOOCS') || isset($GLOBALS['WOOCS'])) {
			$woocs = isset($GLOBALS['WOOCS']) ? $GLOBALS['WOOCS'] : null;
			if ($woocs && method_exists($woocs, 'get_currencies')) {
				$currencies = $woocs->get_currencies();
				// WOOCS stores rates relative to the base currency
				$base_to_mad = 0;
				$base_to_store = 0;
				if (isset($currencies['MAD']['rate'])) {
					$base_to_mad = floatval($currencies['MAD']['rate']);
				}
				if (isset($currencies[$store_currency]['rate'])) {
					$base_to_store = floatval($currencies[$store_currency]['rate']);
				}
				if ($base_to_mad > 0 && $base_to_store > 0) {
					return $base_to_mad / $base_to_store;
				}
			}
		}

		// 2. WPML + WCML — WooCommerce Multilingual & Multicurrency
		if (has_filter('wcml_exchange_rate')) {
			$rate = apply_filters('wcml_exchange_rate', null, $store_currency);
			if ($rate && floatval($rate) > 0) {
				// wcml_exchange_rate returns rate relative to the default currency
				// We need: 1 store_currency = X MAD
				$rate_to_mad = apply_filters('wcml_exchange_rate', null, 'MAD');
				if ($rate_to_mad && floatval($rate_to_mad) > 0) {
					return floatval($rate_to_mad) / floatval($rate);
				}
			}
		}

		// 3. CURCY / VillaTheme — Multi Currency for WooCommerce
		if (class_exists('WOOMULTI_CURRENCY_F') || class_exists('WOOMULTI_CURRENCY')) {
			$settings = get_option('woo_multi_currency_params', array());
			if (!empty($settings)) {
				$currencies_list = isset($settings['currency']) ? $settings['currency'] : array();
				$rates_list = isset($settings['rate']) ? $settings['rate'] : array();
				$store_idx = array_search($store_currency, $currencies_list);
				$mad_idx = array_search('MAD', $currencies_list);
				if ($store_idx !== false && $mad_idx !== false) {
					$store_rate = floatval($rates_list[$store_idx]);
					$mad_rate = floatval($rates_list[$mad_idx]);
					if ($store_rate > 0 && $mad_rate > 0) {
						return $mad_rate / $store_rate;
					}
				}
			}
		}

		// 4. Aelia Currency Switcher
		if (has_filter('wc_aelia_cs_convert')) {
			// Convert 1 unit of store currency to MAD
			$converted = apply_filters('wc_aelia_cs_convert', 1, $store_currency, 'MAD');
			if ($converted && floatval($converted) > 0) {
				return floatval($converted);
			}
		}

		// 5. Generic filter hook — allows any custom plugin or snippet to provide a rate
		// Usage: add_filter('woocommerce_cmi_exchange_rate', function($rate, $from, $to, $order) { return $my_rate; }, 10, 4);
		$custom_rate = apply_filters('woocommerce_cmi_exchange_rate', false, $store_currency, 'MAD', $order);
		if ($custom_rate && floatval($custom_rate) > 0) {
			return floatval($custom_rate);
		}

		return false;
	}
	/**
	 * There are no payment fields for cmi, but we want to show the description if set.
	 *
	 * @since 1.0.0
	 */
	function payment_fields()
	{
		if (isset($this->settings['description']) && ('' != $this->settings['description'])) {
			echo wpautop(wptexturize($this->settings['description']));
		}
	} // End payment_fields()

	/**
	 * Generate the cmi button link.
	 *
	 * @since 1.0.0
	 */
	public function generate_cmi_form($order_id)
	{
		$order = wc_get_order($order_id);
		if (!$order) {
			return '';
		}

		if (!in_array($this->get_order_payment_status($order), array(self::PAYMENT_STATUS_PRE_CONFIRMATION_REQUIRED, self::PAYMENT_STATUS_CONFIRMED), true)) {
			$this->set_order_payment_status($order, self::PAYMENT_STATUS_INITIATED);
		}
		$totalAmountTx = $order->get_total();
		$lang = $this->get_payment_language();
		$msg_redirect = '';
		if ($lang === 'ar') {
			$msg_redirect = 'شكرا لطلبك. ستتم إعادة توجيهك إلى CMI لإجراء الدفع.';
		} elseif ($lang === 'fr') {
			$msg_redirect = 'Merci pour votre commande. vous serez redirigé vers CMI pour effectuer le paiement.';
		} else {
			$msg_redirect = 'Thank you for your order. you will be redirected to CMI to make the payment.';
		}

		$customer_cancel_url = add_query_arg(array(
			'order_id' => $order->get_id(),
			'key' => $order->get_order_key(),
		), $this->cancel_url);
		$cmi_oid = $this->get_cmi_oid_for_order($order);
		$order->update_meta_data('_cmi_oid', $cmi_oid);
		$order->save_meta_data();
		$data = array(
			'clientid' => $this->merchant_id,
			'lang' => $lang,
			'rnd' => microtime(),
			'storetype' => '3d_pay_hosting',
			'hashAlgorithm' => "ver3",
			'tranType' => "PreAuth",
			'email' => $order->get_billing_email(),
			'BillToName' => $this->str_without_accents(trim($order->get_billing_first_name() . " " . $order->get_billing_last_name())),
			'BillToStreet1' => $this->str_without_accents(trim($order->get_billing_address_1())),
			'BillToCity' => $this->str_without_accents(trim($order->get_billing_city())),
			'BillToStateProv' => $this->str_without_accents(trim($order->get_billing_state())),
			'BillToCountry' => $this->str_without_accents(trim($order->get_billing_country())),
			'BillToPostalCode' => $this->str_without_accents(trim($order->get_billing_postcode())),
			'BillToTelVoice' => $this->str_without_accents(trim($order->get_billing_phone())),
			'oid' => $cmi_oid,
			'refreshtime' => "5",
			'instalment' => "",
			'amount' => $totalAmountTx,
			'shopurl' => $customer_cancel_url,
			'currency' => $this->get_currency_code(),
			'failUrl' => $this->return_url,
			'okUrl' => $this->return_url,
			'callbackUrl' => $this->response_url,
			'AutoRedirect' => 'true',
			'encoding' => "UTF-8",
		);
		$current_currency = get_woocommerce_currency();
		if ($current_currency != "MAD") {
			if ($this->exchange_rates === "manual") {
				// var_dump($this->currency_details); exit;

				if (isset($this->currency_details) && $this->currency_details) {
					$cuRate = array();
					foreach ($this->currency_details as $currency) {
						if ($currency['currency_code'] == $current_currency) {
							$cuRate[$current_currency] = $currency['currency_rate'];
						}
					}
					if (!empty($cuRate)) {
						$data['amount'] = number_format(floatval(($totalAmountTx * 1) / $cuRate[$current_currency]), 2, '.', '');
						$data['amountCur'] = $totalAmountTx;
						$data['symbolCur'] = $current_currency;
					}
				}
			} else if ($this->exchange_rates === "plugin") {
				// Allow a filter to override the final MAD amount directly
				$overridden_amount = apply_filters('woocommerce_cmi_order_amount_mad', false, $order, $current_currency);
				if ($overridden_amount !== false && floatval($overridden_amount) > 0) {
					$data['amount'] = number_format(floatval($overridden_amount), 2, '.', '');
					$data['amountCur'] = $totalAmountTx;
					$data['symbolCur'] = $current_currency;
				} else {
					// Auto-detect exchange rate from multi-currency plugin
					$pluginRate = $this->get_plugin_exchange_rate($current_currency, $order);
					if ($pluginRate && $pluginRate > 0) {
						$data['amount'] = number_format(floatval($totalAmountTx * $pluginRate), 2, '.', '');
						$data['amountCur'] = $totalAmountTx;
						$data['symbolCur'] = $current_currency;
					} else {
						// No rate found — log warning and send amount as-is (will likely fail at CMI)
						if (function_exists('wc_get_logger')) {
							wc_get_logger()->warning(
								sprintf(
									'CMI Gateway: Exchange rate mode is "plugin" but no rate found for %s → MAD. '
									. 'Ensure a compatible multi-currency plugin is active or add a filter on "woocommerce_cmi_exchange_rate".',
									$current_currency
								),
								array('source' => 'cmi-payment-gateway')
							);
						}
					}
				}
			}
		}
		$postParams = array();
		foreach ($data as $key => $value) {
			array_push($postParams, $key);
		}

		natcasesort($postParams);

		$hashval = "";
		foreach ($postParams as $param) {
			$paramValue = trim(html_entity_decode(html_entity_decode($data[$param])));
			$escapedParamValue = str_replace("|", "\\|", str_replace("\\", "\\\\", $paramValue));

			$lowerParam = strtolower($param);
			if ($lowerParam != "hash" && $lowerParam != "encoding") {
				$hashval = $hashval . $escapedParamValue . "|";
			}
		}

		$escapedStoreKey = str_replace("|", "\\|", str_replace("\\", "\\\\", $this->SLKSecretkey));
		$hashval = $hashval . $escapedStoreKey;

		$calculatedHashValue = hash('sha512', $hashval);
		$hash = base64_encode(pack('H*', $calculatedHashValue));

		$data['hash'] = $hash;

		$this->data_to_send = $data;
		$cmi_args_array = array();

		foreach ($this->data_to_send as $key => $value) {
			$cmi_args_array[] = '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr(trim((string) $value)) . '" />';
		}
		// exit;
		wc_enqueue_js('
			$.blockUI({
					message: "' . esc_js(__($msg_redirect, 'woocommerce')) . '",
					baseZ: 99999,
					overlayCSS:
					{
						background: "#fff",
						opacity: 0.6
					},
					css: {
						padding:        "20px",
						zindex:         "9999999",
						textAlign:      "center",
						color:          "#555",
						border:         "3px solid #aaa",
						backgroundColor:"#fff",
						cursor:         "wait",
						lineHeight:		"24px",
					}
				});						jQuery("input[name=trp-form-language]").remove();
			jQuery("#submit_cmi_payment_form").click();
		');
		return '<form action="' . esc_url($this->actionslk) . '" method="post" id="cmi_payment_form" target="_top">
				' . implode('', $cmi_args_array) . '
				<!-- Button Fallback -->
				<div class="payment_buttons">
					<input type="submit" class="button alt" id="submit_cmi_payment_form" value="' . esc_attr__('Pay via CMI', 'woocommerce') . '" /> <a class="button cancel" href="' . esc_url($customer_cancel_url) . '">' . esc_html__('Cancel order', 'woocommerce') . '</a>
				</div>
				<script type="text/javascript">
					jQuery(".payment_buttons").hide();					
				</script>
			</form>';

	} // End generate_cmi_form()

	/**
	 * Process the payment and return the result.
	 *
	 * @since 1.0.0
	 */
	function process_payment($order_id)
	{

		$order = wc_get_order($order_id);
		if (!$order) {
			return array('result' => 'failure');
		}

		// If an attempt was already initiated and CMI API is configured, verify before redirecting
		if ($this->has_api_configuration() && (string) $order->get_meta('_cmi_oid') !== '') {
			$sync = $this->sync_order_with_cmi_api($order);
			if (($sync['status'] ?? '') === 'APPROVED') {
				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url($order),
				);
			}
		}

		return array(
			'result' => 'success',
			'redirect' => $order->get_checkout_payment_url(true)
		);

	}

	/**
	 * Reciept page.
	 *
	 * Display text and a button to direct the user to cmi.
	 *
	 * @since 1.0.0
	 */
	function receipt_page($order)
	{
		$msg_redirect2 = '';
		$lang = get_locale();
		if (strpos($lang, "ar") !== false) {
			$msg_redirect2 = 'شكرًا لك على طلبك ، الرجاء النقر فوق الزر أدناه للدفع باستخدام CMI.';
		} else if (strpos($lang, "fr") !== false) {
			$msg_redirect2 = 'Merci pour votre commande, s\'il vous plaît cliquez sur le bouton ci-dessous pour payer avec CMI.';
		} else {
			$msg_redirect2 = 'Thank you for your order, please click the button below to pay with CMI.';
		}
		echo '<p>' . esc_html(__($msg_redirect2, 'woothemes')) . '</p>';

		echo $this->generate_cmi_form($order);
	} // End receipt_page()

	/**
	 * Check cmi CMI validity.
	 *
	 * @param array $data
	 * @since 1.0.0
	 */
	private function get_request_data(): array
	{
		$raw = !empty($_POST) ? $_POST : $_GET;
		$data = array();
		foreach ($raw as $key => $value) {
			if (is_scalar($value)) {
				$data[(string) $key] = wp_unslash((string) $value);
			}
		}
		return $data;
	}

	private function validate_callback(array $data)
	{
		$raw_oid = isset($data['oid']) ? sanitize_text_field((string) $data['oid']) : '';
		$order = $this->find_order_by_cmi_oid($raw_oid);
		if (!$order || empty($data['HASH'])) {
			return new WP_Error('cmi_invalid_callback', __('CMI callback does not identify a valid order.', 'woocommerce'));
		}

		$parameters = array_keys($data);
		natcasesort($parameters);
		$hash_value = '';
		foreach ($parameters as $parameter) {
			if (in_array(strtolower($parameter), array('hash', 'encoding'), true)) {
				continue;
			}
			$value = html_entity_decode(preg_replace("/\n$/", '', $data[$parameter]), ENT_QUOTES, 'UTF-8');
			$hash_value .= str_replace('|', '\\|', str_replace('\\', '\\\\', $value)) . '|';
		}
		$hash_value .= str_replace('|', '\\|', str_replace('\\', '\\\\', $this->SLKSecretkey));
		$expected_hash = base64_encode(pack('H*', hash('sha512', $hash_value)));

		if (!hash_equals($expected_hash, (string) $data['HASH'])) {
			wc_get_logger()->warning('CMI callback rejected because its hash signature is invalid.', array(
				'source' => 'cmi-security',
				'order_id' => $order->get_id(),
				'cmi_oid' => $raw_oid,
				'client_ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown',
			));
			return new WP_Error('cmi_invalid_signature', __('CMI callback signature is invalid.', 'woocommerce'));
		}

		return $order;
	}

	private function transaction_data(array $data): array
	{
		$allowed = array('Response', 'ProcReturnCode', 'ErrMsg', 'ReturnOid', 'TransId', 'HostRefNum', 'AuthCode', 'acqStan', 'mdStatus', 'txstatus', 'paymentType');
		$transaction = array();
		foreach ($allowed as $key) {
			if (isset($data[$key])) {
				$transaction[$key] = sanitize_text_field($data[$key]);
			}
		}
		return $transaction;
	}

	private function is_approved_callback(array $data): bool
	{
		return isset($data['ProcReturnCode']) && (string) $data['ProcReturnCode'] === '00';
	}

	private function has_api_configuration(): bool
	{
		return $this->apigateway !== '' && $this->usernameapi !== '' && $this->passwordapi !== '';
	}

	private function process_callback(WC_Order $order, array $data, bool $is_server_callback = true): string
	{
		$transaction = $this->transaction_data($data);
		if ($this->get_order_payment_status($order) === self::PAYMENT_STATUS_CONFIRMED || $order->is_paid()) {
			return 'APPROVED';
		}
		if (!$this->is_approved_callback($data)) {
			$this->set_order_payment_status($order, self::PAYMENT_STATUS_FAILED, $transaction);
			$order->update_status('failed', sprintf(__('CMI payment failed: %s (ProcReturnCode: %s)', 'woocommerce'), $transaction['ErrMsg'] ?? __('Transaction declined.', 'woocommerce'), $data['ProcReturnCode'] ?? 'N/A'));
			return 'APPROVED';
		}

		if (Cmi_Payment_State::from_callback((string) $data['ProcReturnCode'], $this->confirmation_mode) === self::PAYMENT_STATUS_PRE_CONFIRMATION_REQUIRED || !$is_server_callback) {
			$this->set_order_payment_status($order, self::PAYMENT_STATUS_PRE_CONFIRMATION_REQUIRED, $transaction);
			if ($order->get_status() !== 'on-hold') {
				$order->update_status('on-hold', __('CMI pre-authorization received; manual confirmation is required.', 'woocommerce'));
			}
			return 'APPROVED';
		}

		if ($this->has_api_configuration()) {
			$this->set_order_payment_status($order, self::PAYMENT_STATUS_PRE_CONFIRMATION_REQUIRED, $transaction);
			$order->update_status('on-hold', __('CMI pre-authorization received; confirming through the CMI API.', 'woocommerce'));
			$result = $this->confirm_transaction($order);
			if (is_wp_error($result)) {
				$order->add_order_note(sprintf(__('CMI automatic API confirmation failed: %s', 'woocommerce'), $result->get_error_message()));
			}
			return 'APPROVED';
		}

		$target_status = $this->get_postauth_order_status();
		$this->set_order_payment_status($order, self::PAYMENT_STATUS_CONFIRMED, $transaction);
		$order->payment_complete($transaction['TransId'] ?? '');
		$order->update_status($target_status, sprintf(__('CMI payment confirmed and captured (status set to %s).', 'woocommerce'), $target_status));
		return 'ACTION=POSTAUTH';
	}

	function check_CMI_response()
	{
		$data = $this->get_request_data();
		$order = $this->validate_callback($data);
		if (is_wp_error($order)) {
			status_header(400);
			echo 'INVALID HASH';
			exit;
		}
		echo $this->process_callback($order, $data);
		exit;
	}

	function handle_payment_return()
	{
		$data = $this->get_request_data();
		$order = $this->validate_callback($data);
		if (is_wp_error($order)) {
			wp_die(esc_html($order->get_error_message()), esc_html__('CMI payment result', 'woocommerce'), array('response' => 400));
		}
		$this->process_callback($order, $data, false);
		wp_safe_redirect($this->get_return_url($order));
		exit;
	}

	function handle_customer_cancellation()
	{
		$order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
		$key = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
		$order = $order_id ? wc_get_order($order_id) : false;
		if (!$order || !hash_equals($order->get_order_key(), $key)) {
			wp_die(esc_html__('Invalid CMI cancellation request.', 'woocommerce'), '', array('response' => 400));
		}
		if (!$order->is_paid()) {
			// If API is configured, verify whether CMI recorded an active pre-authorization
			if ($this->has_api_configuration() && (string) $order->get_meta('_cmi_oid') !== '') {
				$check = $this->sync_order_with_cmi_api($order);
				if (($check['status'] ?? '') === 'APPROVED') {
					// An active pre-authorization exists on CMI! Void it automatically to release customer funds
					$void_result = $this->cancel_transaction($order);
					if (is_wp_error($void_result)) {
						$order->add_order_note(sprintf(__('Customer cancelled order, but automatic CMI Void failed: %s. Please void manually in CMI back-office.', 'woocommerce'), $void_result->get_error_message()));
					} else {
						$order->add_order_note(__('Customer cancelled order. Pre-authorization in CMI was automatically voided via CMI API.', 'woocommerce'));
					}
					wp_safe_redirect($this->get_return_url($order));
					exit;
				}
			}

			$this->set_order_payment_status($order, self::PAYMENT_STATUS_CLIENT_CANCELLED);
			$order->update_status('cancelled', __('Payment was cancelled by the customer on the CMI payment page.', 'woocommerce'));
		}
		wp_safe_redirect($this->get_return_url($order));
		exit;
	}

	public static function init_admin_hooks(): void
	{
		static $initialized = false;
		if ($initialized) {
			return;
		}
		$initialized = true;

		// Custom Column for WooCommerce Orders List
		// HPOS mode: use WooCommerce-specific hooks only (NOT the WordPress screen-level
		// manage_woocommerce_page_wc-orders_* hooks, which also fire and would cause duplicates)
		add_filter('woocommerce_shop_order_list_table_columns', array(__CLASS__, 'add_cmi_order_columns'), 20);
		add_action('woocommerce_shop_order_list_table_custom_column', array(__CLASS__, 'render_cmi_order_column'), 10, 2);

		// Legacy CPT mode (pre-HPOS)
		add_filter('manage_edit-shop_order_columns', array(__CLASS__, 'add_cmi_order_columns'), 20);
		add_action('manage_shop_order_posts_custom_column', array(__CLASS__, 'render_cmi_order_column'), 10, 2);

		// Row Actions & Order Edit Actions
		add_filter('woocommerce_admin_order_actions', array(__CLASS__, 'add_admin_order_list_actions'), 10, 2);
		add_filter('woocommerce_order_actions', array(__CLASS__, 'add_order_actions'), 10, 2);
		add_action('woocommerce_order_action_cmi_confirm_transaction', array(__CLASS__, 'confirm_order_action_handler'));
		add_action('woocommerce_order_action_cmi_cancel_transaction', array(__CLASS__, 'cancel_order_action_handler'));
		add_action('woocommerce_order_action_cmi_refund_transaction', array(__CLASS__, 'refund_order_action_handler'));
		add_action('woocommerce_order_action_cmi_check_status', array(__CLASS__, 'check_status_order_action_handler'));

		// Admin Post Action Handlers
		add_action('admin_post_cmi_admin_confirm_order', array(__CLASS__, 'handle_admin_list_confirm'));
		add_action('admin_post_cmi_admin_cancel_order', array(__CLASS__, 'handle_admin_list_cancel'));
		add_action('admin_post_cmi_admin_refund_order', array(__CLASS__, 'handle_admin_list_refund'));
		add_action('admin_post_cmi_admin_check_status', array(__CLASS__, 'handle_admin_list_check_status'));

		// Notices & CSS & Modal
		add_action('admin_notices', array(__CLASS__, 'display_admin_order_action_notices'));
		add_action('admin_head', array(__CLASS__, 'render_admin_order_action_css'));
		add_action('admin_footer', array(__CLASS__, 'render_admin_refund_modal'));
		add_action('admin_init', array(__CLASS__, 'repair_orphaned_custom_status_orders'));
	}

	public static function add_cmi_order_columns(array $columns): array
	{
		$settings = get_option('woocommerce_cmi_settings', array());
		$kit_type = $settings['kit_type'] ?? 'complete';
		if ($kit_type !== 'complete') {
			return $columns;
		}

		$new_columns = array();
		foreach ($columns as $key => $name) {
			$new_columns[$key] = $name;
			if ($key === 'order_status' || $key === 'status') {
				$new_columns['cmi_actions'] = __('CMI Actions', 'woocommerce');
			}
		}
		if (!isset($new_columns['cmi_actions'])) {
			$new_columns['cmi_actions'] = __('CMI Actions', 'woocommerce');
		}
		return $new_columns;
	}

	public static function render_cmi_order_column($column, $order_or_id): void
	{
		if ($column !== 'cmi_actions') {
			return;
		}

		$order = is_numeric($order_or_id) ? wc_get_order($order_or_id) : $order_or_id;
		if (!$order || !($order instanceof WC_Order) || $order->get_payment_method() !== 'cmi') {
			echo '<span class="na" style="color:#aaa;">&ndash;</span>';
			return;
		}

		$order_id = $order->get_id();
		$status = (string) $order->get_meta(self::PAYMENT_STATUS_META_KEY, true);
		$order_status = $order->get_status();
		$gateway = self::get_gateway_instance();
		$has_api = $gateway->has_api_configuration();
		$cmi_oid = (string) $order->get_meta('_cmi_oid');

		$check_url = wp_nonce_url(admin_url('admin-post.php?action=cmi_admin_check_status&order_id=' . $order_id), 'cmi_admin_order_action_' . $order_id);
		$check_btn = ($has_api && $cmi_oid !== '')
			? '<a href="' . esc_url($check_url) . '" class="button button-small" style="background:#2271b1; color:#fff; border-color:#135e96; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:3px;" title="' . esc_attr__('Query payment status from CMI XML API', 'woocommerce') . '">&#8635; ' . esc_html__('Check CMI', 'woocommerce') . '</a>'
			: '';

		$custom_target = $gateway->get_postauth_order_status();

		echo '<div class="cmi-column-actions" style="display:flex; gap:6px; align-items:center; flex-wrap:wrap; padding:2px 0;">';

		// 1. PRE Transactions (Pre-authorized -> can be Confirmed or Voided)
		if (self::can_confirm_order($order)) {
			$confirm_url = wp_nonce_url(admin_url('admin-post.php?action=cmi_admin_confirm_order&order_id=' . $order_id), 'cmi_admin_order_action_' . $order_id);
			$cancel_url = wp_nonce_url(admin_url('admin-post.php?action=cmi_admin_cancel_order&order_id=' . $order_id), 'cmi_admin_order_action_' . $order_id);

			echo '<a href="' . esc_url($confirm_url) . '" class="button button-small" style="background:#008a20; color:#fff; border-color:#007017; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:3px;" title="' . esc_attr__('Confirm and capture this PRE transaction via CMI API (PostAuth)', 'woocommerce') . '">&#10003; ' . esc_html__('Confirm', 'woocommerce') . '</a>';
			echo '<a href="' . esc_url($cancel_url) . '" class="button button-small cmi-void-btn" data-order-id="' . esc_attr($order_id) . '" data-url="' . esc_url($cancel_url) . '" style="background:#d63638; color:#fff; border-color:#b32d2e; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:3px;" onclick="return cmiOpenVoidModal(this, event);" title="' . esc_attr__('Void and cancel this PRE transaction via CMI API (Void)', 'woocommerce') . '">&#10005; ' . esc_html__('Void', 'woocommerce') . '</a>';
			if ($check_btn) {
				echo $check_btn;
			}
		}
		// 2. POST Transactions (Confirmed / Captured / Paid / Partially Refunded)
		elseif ($status === self::PAYMENT_STATUS_CONFIRMED || $status === self::PAYMENT_STATUS_REFUNDED || $order->is_paid() || in_array($order_status, array('processing', 'completed', 'confirmed', 'refunded', $custom_target), true)) {
			$total_order = (float) $order->get_total();
			$total_refunded = (float) $order->get_total_refunded();
			$refundable = max(0, $total_order - $total_refunded);

			if ($refundable > 0.001) {
				$refund_url = wp_nonce_url(admin_url('admin-post.php?action=cmi_admin_refund_order&order_id=' . $order_id), 'cmi_admin_order_action_' . $order_id);
				$btn_label = $total_refunded > 0.001
					? sprintf(__('Refund (%s MAD)', 'woocommerce'), number_format($refundable, 2))
					: __('Refund', 'woocommerce');

				if ($total_refunded > 0.001) {
					echo '<div style="display:flex; flex-direction:column; gap:4px; align-items:flex-start;">';
					echo '<span style="font-size:11px; color:#b5831e; font-weight:600; line-height:1.2;">' . sprintf(__('Refunded: %s MAD', 'woocommerce'), number_format($total_refunded, 2)) . '</span>';
					echo '<a href="' . esc_url($refund_url) . '" class="button button-small cmi-refund-btn" data-order-id="' . esc_attr($order_id) . '" data-total="' . esc_attr(number_format($total_order, 2, '.', '')) . '" data-refunded="' . esc_attr(number_format($total_refunded, 2, '.', '')) . '" data-refundable="' . esc_attr(number_format($refundable, 2, '.', '')) . '" style="background:#f0b849; color:#000; border-color:#d49b28; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:3px;" onclick="return cmiOpenRefundModal(this, event);" title="' . sprintf(esc_attr__('Refund remaining balance (%s MAD) via CMI API (Credit)', 'woocommerce'), number_format($refundable, 2)) . '">&#8634; ' . esc_html($btn_label) . '</a>';
					echo '</div>';
				} else {
					echo '<a href="' . esc_url($refund_url) . '" class="button button-small cmi-refund-btn" data-order-id="' . esc_attr($order_id) . '" data-total="' . esc_attr(number_format($total_order, 2, '.', '')) . '" data-refunded="0.00" data-refundable="' . esc_attr(number_format($refundable, 2, '.', '')) . '" style="background:#f0b849; color:#000; border-color:#d49b28; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:3px;" onclick="return cmiOpenRefundModal(this, event);" title="' . esc_attr__('Refund this transaction (Full or Partial) via CMI API (Credit)', 'woocommerce') . '">&#8634; ' . esc_html__('Refund', 'woocommerce') . '</a>';
				}
			} else {
				echo '<span style="color:#777; font-size:12px; font-weight:600;">' . sprintf(esc_html__('Refunded (%s MAD)', 'woocommerce'), number_format($total_order, 2)) . '</span>';
			}
		}
		// 3. Pending / Initiated
		elseif ($order_status === 'pending' || $status === self::PAYMENT_STATUS_INITIATED) {
			if ($check_btn) {
				echo $check_btn;
			} else {
				echo '<span style="color:#e67e22; font-size:12px; font-weight:600;">' . esc_html__('Pending', 'woocommerce') . '</span>';
			}
		}
		// 4. Voided via API (admin voided a PRE transaction)
		elseif ($status === self::PAYMENT_STATUS_VOIDED) {
			echo '<span style="color:#7b2cf5; font-size:12px; font-weight:600;">' . esc_html__('Voided', 'woocommerce') . '</span>';
			if ($check_btn) {
				echo $check_btn;
			}
		}
		// 5. Client cancelled (user cancelled on payment page)
		elseif ($status === self::PAYMENT_STATUS_CLIENT_CANCELLED || $order_status === 'cancelled') {
			echo '<span style="color:#888; font-size:12px; font-weight:600;">' . esc_html__('Cancelled', 'woocommerce') . '</span>';
			if ($check_btn) {
				echo $check_btn;
			}
		}
		// 6. Failed
		elseif ($status === self::PAYMENT_STATUS_FAILED || $order_status === 'failed') {
			echo '<span style="color:#d63638; font-size:12px; font-weight:600;">' . esc_html__('Failed', 'woocommerce') . '</span>';
			if ($check_btn) {
				echo $check_btn;
			}
		}
		else {
			if ($check_btn) {
				echo $check_btn;
			} else {
				echo '<span class="na" style="color:#aaa;">&ndash;</span>';
			}
		}

		echo '</div>';
	}

	public static function get_gateway_instance(): self
	{
		static $instance = null;
		if (null === $instance) {
			if (function_exists('WC') && WC()->payment_gateways) {
				$gateways = WC()->payment_gateways->payment_gateways();
				if (!empty($gateways['cmi']) && $gateways['cmi'] instanceof self) {
					$instance = $gateways['cmi'];
					return $instance;
				}
			}
			$instance = new self();
		}
		return $instance;
	}

	public function get_available_order_status_options(): array
	{
		return array(
			'processing' => __('Processing', 'woocommerce'),
			'confirmed'  => __('Confirmed', 'woocommerce'),
			'on-hold'    => __('On-hold', 'woocommerce'),
			'completed'  => __('Completed', 'woocommerce'),
			'custom'     => __('Custom (specify below)', 'woocommerce'),
		);
	}

	/**
	 * Sanitize and validate a custom order status slug.
	 *
	 * WordPress limits post_status to 20 characters. Since WooCommerce prepends
	 * 'wc-' (3 chars), the custom status slug must NOT exceed 17 characters.
	 *
	 * @param string $raw_slug The raw custom status input.
	 * @return string A clean, valid slug (max 17 characters).
	 */
	public static function sanitize_custom_status_slug(string $raw_slug): string
	{
		$slug = trim($raw_slug);
		if (str_starts_with($slug, 'wc-')) {
			$slug = substr($slug, 3);
		}
		// Convert spaces and underscores to hyphens
		$slug = (string) preg_replace('/[\s_]+/', '-', $slug);
		// Lowercase and strip any characters not alphanumeric or hyphen
		$slug = sanitize_key($slug);
		// Remove leading/trailing hyphens
		$slug = trim($slug, '-');
		// WordPress post_status column is varchar(20). With 'wc-' prefix, max slug length is 17.
		if (strlen($slug) > 17) {
			$slug = substr($slug, 0, 17);
			$slug = rtrim($slug, '-');
		}
		return $slug;
	}

	/**
	 * Get the human-readable display label for the custom order status.
	 *
	 * @param string|null $slug Optional custom slug.
	 * @return string Formatted display label.
	 */
	public static function get_postauth_custom_status_label(?string $slug = null): string
	{
		$settings = get_option('woocommerce_cmi_settings', array());
		$clean_slug = $slug ?? self::sanitize_custom_status_slug((string) ($settings['postauth_custom_status'] ?? ''));
		if ($clean_slug === '' || $clean_slug === 'confirmed') {
			return _x('Confirmed', 'Order status', 'woocommerce');
		}
		return ucwords(str_replace(array('-', '_'), ' ', $clean_slug));
	}

	public function validate_postauth_custom_status_field(string $key, $value): string
	{
		return self::sanitize_custom_status_slug((string) $value);
	}

	public function get_postauth_order_status(): string
	{
		$status = (string) $this->get_option('postauth_status', 'completed');
		if ($status === 'custom') {
			$custom = self::sanitize_custom_status_slug((string) $this->get_option('postauth_custom_status', ''));
			return $custom !== '' ? $custom : 'confirmed';
		}
		return $status !== '' ? $status : 'completed';
	}

	public static function register_shop_order_post_statuses(array $order_statuses): array
	{
		$order_statuses['wc-confirmed'] = array(
			'label'                     => _x('Confirmed', 'Order status', 'woocommerce'),
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop('Confirmed <span class="count">(%s)</span>', 'Confirmed <span class="count">(%s)</span>', 'woocommerce'),
		);

		$settings = get_option('woocommerce_cmi_settings', array());
		$custom_slug = self::sanitize_custom_status_slug((string) ($settings['postauth_custom_status'] ?? ''));
		if ($custom_slug !== '' && $custom_slug !== 'confirmed') {
			$label = self::get_postauth_custom_status_label($custom_slug);
			$order_statuses['wc-' . $custom_slug] = array(
				'label'                     => $label,
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => $label . ' <span class="count">(%s)</span>',
			);
		}

		// Also register fallback for any legacy truncated status so existing orders display
		if (!empty($settings['postauth_custom_status'])) {
			$raw_sanitized = sanitize_key((string) $settings['postauth_custom_status']);
			if (strlen($raw_sanitized) > 17) {
				$truncated_slug = substr($raw_sanitized, 0, 17);
				if ($truncated_slug !== '' && !isset($order_statuses['wc-' . $truncated_slug])) {
					$order_statuses['wc-' . $truncated_slug] = array(
						'label'                     => self::get_postauth_custom_status_label($truncated_slug),
						'public'                    => false,
						'exclude_from_search'       => false,
						'show_in_admin_all_list'    => true,
						'show_in_admin_status_list' => true,
						'label_count'               => self::get_postauth_custom_status_label($truncated_slug) . ' <span class="count">(%s)</span>',
					);
				}
			}
		}

		return $order_statuses;
	}

	public static function add_paid_order_statuses(array $statuses): array
	{
		$statuses[] = 'confirmed';
		$settings = get_option('woocommerce_cmi_settings', array());
		$custom_slug = self::sanitize_custom_status_slug((string) ($settings['postauth_custom_status'] ?? ''));
		if ($custom_slug !== '') {
			$statuses[] = $custom_slug;
		}

		// Legacy truncated fallback
		if (!empty($settings['postauth_custom_status'])) {
			$raw_sanitized = sanitize_key((string) $settings['postauth_custom_status']);
			if (strlen($raw_sanitized) > 17) {
				$truncated_slug = substr($raw_sanitized, 0, 17);
				if ($truncated_slug !== '') {
					$statuses[] = $truncated_slug;
				}
			}
		}

		return array_unique($statuses);
	}

	public static function register_confirmed_order_status(): void
	{
		register_post_status('wc-confirmed', array(
			'label'                     => _x('Confirmed', 'Order status', 'woocommerce'),
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop('Confirmed <span class="count">(%s)</span>', 'Confirmed <span class="count">(%s)</span>', 'woocommerce'),
		));

		$settings = get_option('woocommerce_cmi_settings', array());
		$custom_slug = self::sanitize_custom_status_slug((string) ($settings['postauth_custom_status'] ?? ''));
		if ($custom_slug !== '' && $custom_slug !== 'confirmed') {
			$label = self::get_postauth_custom_status_label($custom_slug);
			register_post_status('wc-' . $custom_slug, array(
				'label'                     => $label,
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => $label . ' <span class="count">(%s)</span>',
			));
		}

		// Legacy truncated fallback registration so existing orders are recognized
		if (!empty($settings['postauth_custom_status'])) {
			$raw_sanitized = sanitize_key((string) $settings['postauth_custom_status']);
			if (strlen($raw_sanitized) > 17) {
				$truncated_slug = substr($raw_sanitized, 0, 17);
				if ($truncated_slug !== '' && !isset($GLOBALS['wp_post_statuses']['wc-' . $truncated_slug])) {
					register_post_status('wc-' . $truncated_slug, array(
						'label'                     => self::get_postauth_custom_status_label($truncated_slug),
						'public'                    => false,
						'exclude_from_search'       => false,
						'show_in_admin_all_list'    => true,
						'show_in_admin_status_list' => true,
						'label_count'               => self::get_postauth_custom_status_label($truncated_slug) . ' <span class="count">(%s)</span>',
					));
				}
			}
		}
	}

	public static function add_confirmed_to_order_statuses(array $order_statuses): array
	{
		$new_order_statuses = array();
		foreach ($order_statuses as $key => $status) {
			$new_order_statuses[$key] = $status;
			if ('wc-processing' === $key) {
				$new_order_statuses['wc-confirmed'] = _x('Confirmed', 'Order status', 'woocommerce');
			}
		}
		if (!isset($new_order_statuses['wc-confirmed'])) {
			$new_order_statuses['wc-confirmed'] = _x('Confirmed', 'Order status', 'woocommerce');
		}

		$settings = get_option('woocommerce_cmi_settings', array());
		$custom_slug = self::sanitize_custom_status_slug((string) ($settings['postauth_custom_status'] ?? ''));
		if ($custom_slug !== '' && !isset($new_order_statuses['wc-' . $custom_slug])) {
			$new_order_statuses['wc-' . $custom_slug] = self::get_postauth_custom_status_label($custom_slug);
		}

		// Legacy truncated fallback
		if (!empty($settings['postauth_custom_status'])) {
			$raw_sanitized = sanitize_key((string) $settings['postauth_custom_status']);
			if (strlen($raw_sanitized) > 17) {
				$truncated_slug = substr($raw_sanitized, 0, 17);
				if ($truncated_slug !== '' && !isset($new_order_statuses['wc-' . $truncated_slug])) {
					$new_order_statuses['wc-' . $truncated_slug] = self::get_postauth_custom_status_label($truncated_slug);
				}
			}
		}

		return $new_order_statuses;
	}

	/**
	 * Automatically repair any orders that got saved with a truncated or unregistered status.
	 *
	 * When a merchant entered a long description in the slug field (e.g.
	 * "Payment is confirmed (waiting for delivry)"), the status exceeded varchar(20)
	 * and was truncated by MySQL to 'wc-paymentisconfirme' or 'paymentisconfirmedwa',
	 * making the order invisible in WooCommerce lists. This helper automatically
	 * detects and updates those orders to the valid clean slug.
	 */
	public static function repair_orphaned_custom_status_orders(): void
	{
		global $wpdb;
		if (!isset($wpdb) || !is_object($wpdb)) {
			return;
		}

		$settings = get_option('woocommerce_cmi_settings', array());
		$target_slug = self::sanitize_custom_status_slug((string) ($settings['postauth_custom_status'] ?? ''));
		if ($target_slug === '') {
			$target_slug = 'confirmed';
		}

		// 1. Repair Legacy CPT (wp_posts)
		if (isset($wpdb->posts)) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts}
					 SET post_status = %s
					 WHERE post_type = 'shop_order'
					   AND (post_status LIKE 'wc-paymentisconfirm%' OR post_status = 'paymentisconfirmedwa')",
					'wc-' . $target_slug
				)
			);
		}

		// 2. Repair HPOS (wp_wc_orders) if the table exists
		$hpos_table = $wpdb->prefix . 'wc_orders';
		$table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $hpos_table));
		if ($table_exists === $hpos_table) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$hpos_table}
					 SET status = %s
					 WHERE status LIKE 'paymentisconfirm%' OR status LIKE 'wc-paymentisconfirm%'",
					$target_slug
				)
			);
		}
	}

	public static function can_confirm_order(WC_Order $order): bool
	{
		if ($order->get_payment_method() !== 'cmi') {
			return false;
		}
		$custom_target = self::get_gateway_instance()->get_postauth_order_status();
		if (in_array($order->get_status(), array('processing', 'completed', 'confirmed', 'refunded', 'cancelled', 'failed', $custom_target), true)) {
			return false;
		}
		$status = (string) $order->get_meta(self::PAYMENT_STATUS_META_KEY, true);
		if ($status === self::PAYMENT_STATUS_CONFIRMED || $status === self::PAYMENT_STATUS_FAILED || $status === self::PAYMENT_STATUS_CLIENT_CANCELLED || $status === self::PAYMENT_STATUS_VOIDED) {
			return false;
		}
		if (Cmi_Payment_State::can_confirm($status)) {
			return true;
		}
		// Only orders placed on-hold or pending with pre-authorization are eligible
		if ($order->has_status(array('on-hold', 'pending'))) {
			return true;
		}
		return false;
	}

	public static function add_order_actions(array $actions, WC_Order $order): array
	{
		$settings = get_option('woocommerce_cmi_settings', array());
		if (($settings['kit_type'] ?? 'complete') !== 'complete') {
			return $actions;
		}
		if ($order->get_payment_method() === 'cmi') {
			$custom_target = self::get_gateway_instance()->get_postauth_order_status();
			$actions['cmi_check_status'] = __('Query status via CMI API', 'woocommerce');
			if (self::can_confirm_order($order)) {
				$actions['cmi_confirm_transaction'] = __('Confirm CMI PRE transaction', 'woocommerce');
				$actions['cmi_cancel_transaction'] = __('Void CMI PRE transaction', 'woocommerce');
			} elseif ($order->is_paid() || in_array($order->get_status(), array('processing', 'completed', 'confirmed', $custom_target), true) || ((float) $order->get_total() - (float) $order->get_total_refunded()) > 0.001) {
				$actions['cmi_refund_transaction'] = __('Refund via CMI API', 'woocommerce');
			}
		}
		return $actions;
	}

	public static function add_admin_order_list_actions(array $actions, WC_Order $order): array
	{
		$settings = get_option('woocommerce_cmi_settings', array());
		if (($settings['kit_type'] ?? 'complete') !== 'complete') {
			return $actions;
		}
		if (self::can_confirm_order($order)) {
			$order_id = $order->get_id();
			$actions['cmi_confirm'] = array(
				'url'    => wp_nonce_url(admin_url('admin-post.php?action=cmi_admin_confirm_order&order_id=' . $order_id), 'cmi_admin_order_action_' . $order_id),
				'name'   => __('Confirm CMI transaction', 'woocommerce'),
				'action' => 'cmi_confirm',
			);
			$actions['cmi_cancel'] = array(
				'url'    => wp_nonce_url(admin_url('admin-post.php?action=cmi_admin_cancel_order&order_id=' . $order_id), 'cmi_admin_order_action_' . $order_id),
				'name'   => __('Void CMI transaction', 'woocommerce'),
				'action' => 'cmi_cancel',
			);
		}
		return $actions;
	}

	public static function render_admin_order_action_css(): void
	{
		?>
		<style>
			.wc-action-button-cmi_confirm::after,
			a.cmi_confirm::after {
				font-family: Dashicons, sans-serif !important;
				content: "\f147" !important; /* dashicons-yes-alt */
				color: #46b450 !important;
			}
			.wc-action-button-cmi_cancel::after,
			a.cmi_cancel::after {
				font-family: Dashicons, sans-serif !important;
				content: "\f335" !important; /* dashicons-no-alt */
				color: #dc3232 !important;
			}
			.wc-action-button-cmi_confirm:hover,
			.wc-action-button-cmi_cancel:hover {
				border-color: #555 !important;
			}
			table.wp-list-table th#cmi_actions,
			table.wp-list-table td.cmi_actions {
				width: 155px;
				text-align: left;
			}
			.order-status.status-confirmed,
			mark.order-status.status-confirmed {
				background: #c6e1c6 !important;
				color: #5b841b !important;
			}
			<?php
			$settings = get_option('woocommerce_cmi_settings', array());
			$custom_slug = self::sanitize_custom_status_slug((string) ($settings['postauth_custom_status'] ?? ''));
			if ($custom_slug !== '' && $custom_slug !== 'confirmed') {
				?>
				.order-status.status-<?php echo esc_attr($custom_slug); ?>,
				mark.order-status.status-<?php echo esc_attr($custom_slug); ?> {
					background: #c6e1c6 !important;
					color: #5b841b !important;
				}
				<?php
			}
			?>
		</style>
		<?php
	}

	public static function render_admin_refund_modal(): void
	{
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ($screen && !in_array($screen->id, array('woocommerce_page_wc-orders', 'edit-shop_order', 'shop_order'), true)) {
			return;
		}
		?>
		<!-- CMI Action Modals Backdrop -->
		<div id="cmi-action-modal-backdrop" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.55); z-index:99998; backdrop-filter:blur(2px);" onclick="cmiCloseAllModals()"></div>

		<!-- 1. CMI Void / Cancellation Modal Box -->
		<div id="cmi-void-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); width:480px; max-width:92%; background:#fff; border-radius:8px; box-shadow:0 10px 35px rgba(0,0,0,0.25); z-index:99999; overflow:hidden; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,'Helvetica Neue',sans-serif; border:1px solid #ccd0d4;">
			<!-- Header -->
			<div style="background:#fcf0f1; padding:14px 18px; border-bottom:1px solid #f1c5c5; display:flex; justify-content:space-between; align-items:center;">
				<h3 style="margin:0; font-size:15px; font-weight:600; color:#b32d2e; display:flex; align-items:center; gap:6px;">
					<span style="color:#d63638; font-size:18px;">&#10005;</span>
					<span><?php esc_html_e('Annulation CMI (Void)', 'woocommerce'); ?> &mdash; Order #<span id="cmi-void-modal-order-id"></span></span>
				</h3>
				<button type="button" onclick="cmiCloseVoidModal()" style="background:none; border:none; font-size:22px; line-height:1; cursor:pointer; color:#646970; padding:0 4px;" title="<?php esc_attr_e('Close', 'woocommerce'); ?>">&times;</button>
			</div>

			<!-- Body -->
			<div style="padding:18px 20px;">
				<div style="background:#fcf0f1; border:1px solid #f5c6cb; border-left:4px solid #d63638; padding:12px 14px; margin-bottom:16px; border-radius:4px; font-size:13px; color:#721c24; display:flex; gap:10px; align-items:flex-start;">
					<span class="dashicons dashicons-warning" style="font-size:20px; width:20px; height:20px; color:#d63638; flex-shrink:0; margin-top:2px;"></span>
					<div>
						<strong style="display:block; margin-bottom:4px; font-size:13px; color:#b32d2e;"><?php esc_html_e('⚠️ Attention : Cette action est irréversible !', 'woocommerce'); ?></strong>
						<ul style="margin:0 0 0 16px; padding:0; list-style-type:disc; font-size:12px; line-height:1.5;">
							<li><?php esc_html_e('L\'autorisation de paiement CMI sera définitivement annulée auprès de la banque du client.', 'woocommerce'); ?></li>
							<li><?php esc_html_e('Les fonds réservés sur la carte du client seront immédiatement libérés.', 'woocommerce'); ?></li>
							<li><?php esc_html_e('Cette commande ne pourra plus jamais être confirmée ni débitée ultérieurement.', 'woocommerce'); ?></li>
						</ul>
					</div>
				</div>

				<p style="margin:0; font-size:13px; color:#1d2327;">
					<?php esc_html_e('Êtes-vous certain de vouloir annuler définitivement cette pré-autorisation CMI ?', 'woocommerce'); ?>
				</p>
			</div>

			<!-- Footer -->
			<div style="background:#f6f7f7; padding:12px 18px; border-top:1px solid #dcdcde; display:flex; justify-content:flex-end; gap:8px;">
				<button type="button" class="button" onclick="cmiCloseVoidModal()"><?php esc_html_e('Conserver la commande', 'woocommerce'); ?></button>
				<button type="button" class="button button-primary" id="cmi-void-modal-submit-btn" onclick="cmiSubmitModalVoid()" style="background:#d63638; border-color:#b32d2e; color:#fff; font-weight:600;">
					<?php esc_html_e('Confirmer l\'annulation (Irréversible)', 'woocommerce'); ?>
				</button>
			</div>
		</div>

		<!-- 2. CMI Refund Modal Box -->
		<div id="cmi-refund-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); width:480px; max-width:92%; background:#fff; border-radius:8px; box-shadow:0 10px 35px rgba(0,0,0,0.25); z-index:99999; overflow:hidden; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,'Helvetica Neue',sans-serif; border:1px solid #ccd0d4;">
			<!-- Header -->
			<div style="background:#f6f7f7; padding:14px 18px; border-bottom:1px solid #dcdcde; display:flex; justify-content:space-between; align-items:center;">
				<h3 style="margin:0; font-size:15px; font-weight:600; color:#1d2327; display:flex; align-items:center; gap:6px;">
					<span style="color:#d49b28; font-size:18px;">&#8634;</span>
					<span><?php esc_html_e('CMI API Refund', 'woocommerce'); ?> &mdash; Order #<span id="cmi-modal-order-id"></span></span>
				</h3>
				<button type="button" onclick="cmiCloseRefundModal()" style="background:none; border:none; font-size:22px; line-height:1; cursor:pointer; color:#646970; padding:0 4px;" title="<?php esc_attr_e('Close', 'woocommerce'); ?>">&times;</button>
			</div>

			<!-- Body -->
			<div style="padding:18px 20px;">
				<!-- Irreversible warning banner -->
				<div style="background:#fff8e5; border:1px solid #f0c33c; border-left:4px solid #dba617; padding:10px 14px; margin-bottom:14px; border-radius:4px; font-size:12px; color:#614200; display:flex; gap:10px; align-items:flex-start;">
					<span class="dashicons dashicons-warning" style="font-size:18px; width:18px; height:18px; color:#dba617; flex-shrink:0; margin-top:1px;"></span>
					<div>
						<strong style="display:block; margin-bottom:2px; color:#8c5b00;"><?php esc_html_e('⚠️ Attention : Action irréversible', 'woocommerce'); ?></strong>
						<span><?php esc_html_e('Le remboursement via l\'API CMI est immédiat et définitif. Les fonds seront retournés au client et cette action ne peut pas être annulée.', 'woocommerce'); ?></span>
					</div>
				</div>

				<div style="background:#f0f6fc; border-left:4px solid #72aee6; padding:10px 14px; margin-bottom:16px; border-radius:2px; font-size:13px; color:#1d2327;">
					<div style="display:flex; justify-content:space-between; margin-bottom:4px;">
						<span><?php esc_html_e('Total Order Amount:', 'woocommerce'); ?></span>
						<span id="cmi-modal-total-display" style="font-weight:600;">0.00 MAD</span>
					</div>
					<div style="display:flex; justify-content:space-between; margin-bottom:4px;">
						<span><?php esc_html_e('Already Refunded:', 'woocommerce'); ?></span>
						<span id="cmi-modal-refunded-display" style="color:#b5831e; font-weight:600;">0.00 MAD</span>
					</div>
					<div style="display:flex; justify-content:space-between;">
						<span><?php esc_html_e('Available Refundable Balance:', 'woocommerce'); ?></span>
						<span id="cmi-modal-refundable-display" style="color:#008a20; font-weight:700;">0.00 MAD</span>
					</div>
				</div>

				<div style="margin-bottom:14px;">
					<label style="display:flex; align-items:center; gap:8px; font-weight:600; cursor:pointer; margin-bottom:8px; font-size:13px; color:#1d2327;">
						<input type="radio" name="cmi_refund_type_choice" id="cmi_refund_type_full" value="full" checked onchange="cmiToggleRefundType()">
						<span><span id="cmi-modal-full-label-text"><?php esc_html_e('Refund Remaining Balance', 'woocommerce'); ?></span> (<span id="cmi-modal-full-amt">0.00</span> MAD)</span>
					</label>
					<label style="display:flex; align-items:center; gap:8px; font-weight:600; cursor:pointer; font-size:13px; color:#1d2327;">
						<input type="radio" name="cmi_refund_type_choice" id="cmi_refund_type_partial" value="partial" onchange="cmiToggleRefundType()">
						<span><?php esc_html_e('Custom Partial Amount', 'woocommerce'); ?></span>
					</label>
				</div>

				<div id="cmi-modal-partial-wrapper" style="display:none; margin-left:24px; margin-bottom:14px; padding:10px; background:#f9f9f9; border:1px solid #e2e4e7; border-radius:4px;">
					<label for="cmi-modal-partial-input" style="display:block; font-size:12px; color:#50575e; margin-bottom:5px; font-weight:600;">
						<?php esc_html_e('Enter partial amount to refund (MAD):', 'woocommerce'); ?>
					</label>
					<div style="display:flex; align-items:center; gap:6px;">
						<input type="number" id="cmi-modal-partial-input" step="0.01" min="0.01" style="width:130px; padding:4px 8px; font-weight:600; font-size:14px;" oninput="cmiValidateModalInput()">
						<span style="font-weight:600; color:#50575e;">MAD</span>
					</div>
				</div>

				<div style="margin-top:12px;">
					<label for="cmi-modal-reason-input" style="display:block; font-size:12px; color:#50575e; margin-bottom:4px; font-weight:600;">
						<?php esc_html_e('Refund Reason (Optional):', 'woocommerce'); ?>
					</label>
					<input type="text" id="cmi-modal-reason-input" placeholder="<?php esc_attr_e('e.g. Customer request / Item returned', 'woocommerce'); ?>" style="width:100%; padding:5px 8px; font-size:13px;">
				</div>

				<div id="cmi-modal-error-msg" style="display:none; margin-top:14px; padding:8px 12px; background:#fcf0f1; border-left:4px solid #d63638; color:#d63638; font-size:12px; font-weight:600; border-radius:2px;"></div>
			</div>

			<!-- Footer -->
			<div style="background:#f6f7f7; padding:12px 18px; border-top:1px solid #dcdcde; display:flex; justify-content:flex-end; gap:8px;">
				<button type="button" class="button" onclick="cmiCloseRefundModal()"><?php esc_html_e('Cancel', 'woocommerce'); ?></button>
				<button type="button" class="button button-primary" id="cmi-modal-submit-btn" onclick="cmiSubmitModalRefund()" style="background:#d49b28; border-color:#b5831e; color:#000; font-weight:600;">
					<?php esc_html_e('Confirmer le remboursement (Irréversible)', 'woocommerce'); ?>
				</button>
			</div>
		</div>

		<script type="text/javascript">
			var cmiRefundState = {
				orderId: 0,
				total: 0,
				refunded: 0,
				refundable: 0,
				baseUrl: ''
			};

			var cmiVoidState = {
				orderId: 0,
				url: ''
			};

			function cmiCloseAllModals() {
				cmiCloseRefundModal();
				cmiCloseVoidModal();
			}

			// --- Void Modal Logic ---
			function cmiOpenVoidModal(btn, event) {
				if (event) event.preventDefault();
				var orderId = btn.getAttribute('data-order-id');
				var url = btn.getAttribute('data-url') || btn.getAttribute('href');
				return cmiOpenVoidModalManual(url, orderId);
			}

			function cmiOpenVoidModalManual(url, orderId) {
				cmiVoidState.orderId = orderId;
				cmiVoidState.url = url;

				document.getElementById('cmi-void-modal-order-id').innerText = orderId;
				var submitBtn = document.getElementById('cmi-void-modal-submit-btn');
				submitBtn.disabled = false;
				submitBtn.innerText = '<?php echo esc_js(__('Confirmer l\'annulation (Irréversible)', 'woocommerce')); ?>';

				document.getElementById('cmi-action-modal-backdrop').style.display = 'block';
				document.getElementById('cmi-void-modal').style.display = 'block';
				return false;
			}

			function cmiCloseVoidModal() {
				document.getElementById('cmi-void-modal').style.display = 'none';
				if (document.getElementById('cmi-refund-modal').style.display !== 'block') {
					document.getElementById('cmi-action-modal-backdrop').style.display = 'none';
				}
			}

			function cmiSubmitModalVoid() {
				if (!cmiVoidState.url) {
					return;
				}
				var submitBtn = document.getElementById('cmi-void-modal-submit-btn');
				submitBtn.disabled = true;
				submitBtn.innerText = '<?php echo esc_js(__('Annulation en cours via CMI API...', 'woocommerce')); ?>';
				window.location.href = cmiVoidState.url;
			}

			// --- Refund Modal Logic ---
			function cmiOpenRefundModal(btn, event) {
				if (event) event.preventDefault();
				cmiRefundState.orderId = btn.getAttribute('data-order-id');
				cmiRefundState.total = parseFloat(btn.getAttribute('data-total') || '0');
				cmiRefundState.refunded = parseFloat(btn.getAttribute('data-refunded') || '0');
				cmiRefundState.refundable = parseFloat(btn.getAttribute('data-refundable') || '0');
				cmiRefundState.baseUrl = btn.getAttribute('href');

				document.getElementById('cmi-modal-order-id').innerText = cmiRefundState.orderId;
				document.getElementById('cmi-modal-total-display').innerText = cmiRefundState.total.toFixed(2) + ' MAD';
				document.getElementById('cmi-modal-refunded-display').innerText = cmiRefundState.refunded.toFixed(2) + ' MAD';
				document.getElementById('cmi-modal-refundable-display').innerText = cmiRefundState.refundable.toFixed(2) + ' MAD';
				document.getElementById('cmi-modal-full-amt').innerText = cmiRefundState.refundable.toFixed(2);
				document.getElementById('cmi-modal-partial-input').value = cmiRefundState.refundable.toFixed(2);
				document.getElementById('cmi-modal-partial-input').max = cmiRefundState.refundable;
				document.getElementById('cmi-modal-reason-input').value = '';
				document.getElementById('cmi_refund_type_full').checked = true;
				document.getElementById('cmi-modal-partial-wrapper').style.display = 'none';
				document.getElementById('cmi-modal-error-msg').style.display = 'none';
				document.getElementById('cmi-modal-error-msg').innerText = '';
				document.getElementById('cmi-modal-submit-btn').disabled = false;
				document.getElementById('cmi-modal-submit-btn').innerText = '<?php echo esc_js(__('Confirmer le remboursement (Irréversible)', 'woocommerce')); ?>';

				var fullLabelText = cmiRefundState.refunded > 0.001
					? '<?php echo esc_js(__('Rembourser le solde restant', 'woocommerce')); ?>'
					: '<?php echo esc_js(__('Remboursement total', 'woocommerce')); ?>';
				var labelEl = document.getElementById('cmi-modal-full-label-text');
				if (labelEl) {
					labelEl.innerText = fullLabelText;
				}

				document.getElementById('cmi-action-modal-backdrop').style.display = 'block';
				document.getElementById('cmi-refund-modal').style.display = 'block';
				return false;
			}

			function cmiCloseRefundModal() {
				document.getElementById('cmi-refund-modal').style.display = 'none';
				if (document.getElementById('cmi-void-modal').style.display !== 'block') {
					document.getElementById('cmi-action-modal-backdrop').style.display = 'none';
				}
			}

			function cmiToggleRefundType() {
				var isPartial = document.getElementById('cmi_refund_type_partial').checked;
				var wrapper = document.getElementById('cmi-modal-partial-wrapper');
				wrapper.style.display = isPartial ? 'block' : 'none';
				if (isPartial) {
					document.getElementById('cmi-modal-partial-input').focus();
				}
				cmiValidateModalInput();
			}

			function cmiValidateModalInput() {
				var errorEl = document.getElementById('cmi-modal-error-msg');
				var submitBtn = document.getElementById('cmi-modal-submit-btn');
				var isPartial = document.getElementById('cmi_refund_type_partial').checked;

				if (!isPartial) {
					errorEl.style.display = 'none';
					submitBtn.disabled = false;
					return true;
				}

				var inputVal = parseFloat(document.getElementById('cmi-modal-partial-input').value);
				if (isNaN(inputVal) || inputVal <= 0) {
					errorEl.innerText = '<?php echo esc_js(__('Please enter a valid positive number for the refund amount.', 'woocommerce')); ?>';
					errorEl.style.display = 'block';
					submitBtn.disabled = true;
					return false;
				}

				if (inputVal > cmiRefundState.refundable + 0.001) {
					errorEl.innerText = '<?php echo esc_js(__('Refund amount cannot exceed the available balance of ', 'woocommerce')); ?>' + cmiRefundState.refundable.toFixed(2) + ' MAD.';
					errorEl.style.display = 'block';
					submitBtn.disabled = true;
					return false;
				}

				errorEl.style.display = 'none';
				submitBtn.disabled = false;
				return true;
			}

			function cmiSubmitModalRefund() {
				if (!cmiValidateModalInput()) {
					return;
				}
				var isPartial = document.getElementById('cmi_refund_type_partial').checked;
				var refundAmount = isPartial ? parseFloat(document.getElementById('cmi-modal-partial-input').value) : cmiRefundState.refundable;
				var reason = document.getElementById('cmi-modal-reason-input').value.trim();

				var submitBtn = document.getElementById('cmi-modal-submit-btn');
				submitBtn.disabled = true;
				submitBtn.innerText = '<?php echo esc_js(__('Traitement du remboursement via CMI API...', 'woocommerce')); ?>';

				var targetUrl = cmiRefundState.baseUrl +
					'&refund_amount=' + encodeURIComponent(refundAmount.toFixed(2)) +
					(reason ? '&refund_reason=' + encodeURIComponent(reason) : '');

				window.location.href = targetUrl;
			}

			// --- Global Event Attachments ---
			jQuery(function($) {
				// Intercept WooCommerce native order list action icons for Void
				$(document).on('click', '.wc-action-button-cmi_cancel, a.cmi_cancel', function(e) {
					e.preventDefault();
					var href = $(this).attr('href');
					var match = href ? href.match(/order_id=(\d+)/) : null;
					var orderId = match ? match[1] : '';
					cmiOpenVoidModalManual(href, orderId);
					return false;
				});

				// Intercept order actions dropdown on single order edit screen
				$('button.wc-reload, button[name="save"], input#publish').closest('form').on('submit', function(e) {
					var actionVal = $('select[name="wc_order_action"]').val();
					if (actionVal === 'cmi_cancel_transaction') {
						if (!confirm('<?php echo esc_js(__('⚠️ Attention : L\'annulation (Void) CMI est irréversible. L\'autorisation sera définitivement annulée auprès de la banque et ne pourra plus jamais être débitée. Confirmer ?', 'woocommerce')); ?>')) {
							e.preventDefault();
							return false;
						}
					} else if (actionVal === 'cmi_refund_transaction') {
						if (!confirm('<?php echo esc_js(__('⚠️ Attention : Le remboursement via l\'API CMI est irréversible. Les fonds seront immédiatement et définitivement retournés au client. Confirmer ?', 'woocommerce')); ?>')) {
							e.preventDefault();
							return false;
						}
					}
				});
			});
		</script>
		<?php
	}

	/**
	 * Build a safe redirect URL back to the WooCommerce orders list.
	 *
	 * Strips stale status / order_status query parameters from the referer so that
	 * the merchant always lands on the unfiltered "All" orders view after a
	 * status-changing API operation (Confirm, Void, Refund, Query). Without this,
	 * the order appears to "disappear" because the old filter no longer matches
	 * the order's new status.
	 *
	 * Falls back to the correct admin URL depending on whether HPOS (Custom Order
	 * Tables) or legacy CPT storage is active.
	 *
	 * @return string Safe redirect URL.
	 */
	private static function get_admin_orders_redirect_url(): string
	{
		$referer = wp_get_referer();

		if ($referer) {
			// Remove status filters so we land on "All" orders, where the order
			// will always be visible regardless of its new status.
			$referer = remove_query_arg(array('status', 'order_status', 'post_status', 'paged', 's'), $referer);
			return $referer;
		}

		// No referer available — build a fallback URL that works with both
		// HPOS (admin.php?page=wc-orders) and legacy CPT (edit.php?post_type=shop_order).
		if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
		) {
			return admin_url('admin.php?page=wc-orders');
		}

		return admin_url('edit.php?post_type=shop_order');
	}

	public static function handle_admin_list_confirm(): void
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('You do not have permission to manage WooCommerce orders.', 'woocommerce'));
		}
		$order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
		check_admin_referer('cmi_admin_order_action_' . $order_id);

		$order = $order_id ? wc_get_order($order_id) : false;
		if (!$order) {
			wp_die(esc_html__('Invalid order ID.', 'woocommerce'));
		}

		$gateway = self::get_gateway_instance();
		$result = $gateway->confirm_transaction($order);
		if (is_wp_error($result)) {
			set_transient('cmi_admin_notice_' . get_current_user_id(), array(
				'type'    => 'error',
				'message' => sprintf(__('CMI confirmation failed for order #%d: %s', 'woocommerce'), $order_id, $result->get_error_message()),
			), 45);
		} else {
			set_transient('cmi_admin_notice_' . get_current_user_id(), array(
				'type'    => 'success',
				'message' => sprintf(__('Order #%d confirmed successfully via CMI API.', 'woocommerce'), $order_id),
			), 45);
		}

		wp_safe_redirect(self::get_admin_orders_redirect_url());
		exit;
	}

	public static function handle_admin_list_cancel(): void
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('You do not have permission to manage WooCommerce orders.', 'woocommerce'));
		}
		$order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
		check_admin_referer('cmi_admin_order_action_' . $order_id);

		$order = $order_id ? wc_get_order($order_id) : false;
		if (!$order) {
			wp_die(esc_html__('Invalid order ID.', 'woocommerce'));
		}

		$gateway = self::get_gateway_instance();
		$result = $gateway->cancel_transaction($order);
		if (is_wp_error($result)) {
			set_transient('cmi_admin_notice_' . get_current_user_id(), array(
				'type'    => 'error',
				'message' => sprintf(__('CMI void/cancellation failed for order #%d: %s', 'woocommerce'), $order_id, $result->get_error_message()),
			), 45);
		} else {
			set_transient('cmi_admin_notice_' . get_current_user_id(), array(
				'type'    => 'success',
				'message' => sprintf(__('Order #%d voided and cancelled successfully via CMI API.', 'woocommerce'), $order_id),
			), 45);
		}

		wp_safe_redirect(self::get_admin_orders_redirect_url());
		exit;
	}

	public static function handle_admin_list_refund(): void
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('You do not have permission to manage WooCommerce orders.', 'woocommerce'));
		}
		$order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
		check_admin_referer('cmi_admin_order_action_' . $order_id);

		$order = $order_id ? wc_get_order($order_id) : false;
		if (!$order) {
			wp_die(esc_html__('Invalid order ID.', 'woocommerce'));
		}

		$refund_amount = isset($_GET['refund_amount']) && $_GET['refund_amount'] !== '' ? (float) wc_clean(wp_unslash($_GET['refund_amount'])) : null;
		$reason = isset($_GET['refund_reason']) ? sanitize_text_field(wp_unslash($_GET['refund_reason'])) : '';

		$gateway = self::get_gateway_instance();
		$result = $gateway->refund_transaction($order, $refund_amount, $reason, false);
		if (is_wp_error($result)) {
			set_transient('cmi_admin_notice_' . get_current_user_id(), array(
				'type'    => 'error',
				'message' => sprintf(__('CMI refund failed for order #%d: %s', 'woocommerce'), $order_id, $result->get_error_message()),
			), 45);
		} else {
			// Reload order from DB to reflect the newly recorded refund
			$order = wc_get_order($order_id);
			$total_order = (float) $order->get_total();
			$total_refunded = (float) $order->get_total_refunded();
			$is_full = round($total_refunded, 2) >= round($total_order, 2) - 0.001;

			$msg = !$is_full
				? sprintf(__('Partial refund of %s MAD for order #%d processed successfully via CMI API. (Remaining refundable: %s MAD)', 'woocommerce'), number_format($refund_amount, 2), $order_id, number_format(max(0, $total_order - $total_refunded), 2))
				: sprintf(__('Order #%d fully refunded successfully via CMI API (%s MAD).', 'woocommerce'), $order_id, number_format($total_order, 2));

			set_transient('cmi_admin_notice_' . get_current_user_id(), array(
				'type'    => 'success',
				'message' => $msg,
			), 45);
		}

		wp_safe_redirect(self::get_admin_orders_redirect_url());
		exit;
	}

	public static function display_admin_order_action_notices(): void
	{
		$transient_key = 'cmi_admin_notice_' . get_current_user_id();
		$notice = get_transient($transient_key);
		if ($notice && is_array($notice)) {
			delete_transient($transient_key);
			$class = $notice['type'] === 'success' ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p><strong>' . esc_html($notice['message']) . '</strong></p></div>';
		}
	}

	public static function check_status_order_action_handler(WC_Order $order): void
	{
		$gateway = self::get_gateway_instance();
		if ($order->get_payment_method() !== $gateway->id) {
			return;
		}
		$result = $gateway->sync_order_with_cmi_api($order);
		$order->add_order_note(sprintf(__('CMI Status Check result: [%s] %s', 'woocommerce'), $result['status'], $result['message']));
	}

	public static function handle_admin_list_check_status(): void
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('You do not have permission to manage WooCommerce orders.', 'woocommerce'));
		}
		$order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
		check_admin_referer('cmi_admin_order_action_' . $order_id);

		$order = $order_id ? wc_get_order($order_id) : false;
		if (!$order) {
			wp_die(esc_html__('Invalid order ID.', 'woocommerce'));
		}

		$gateway = self::get_gateway_instance();
		$result = $gateway->sync_order_with_cmi_api($order);

		$is_success = in_array($result['status'], array('APPROVED', 'PRE_AUTHORIZED', 'REFUNDED', 'VOIDED'), true);
		$status_label = $result['cmi_status'] ?? $result['status'];
		set_transient('cmi_admin_notice_' . get_current_user_id(), array(
			'type'    => $is_success ? 'success' : 'error',
			'message' => sprintf(__('CMI status for order #%d: [%s] %s', 'woocommerce'), $order_id, $status_label, $result['message']),
		), 45);

		wp_safe_redirect(self::get_admin_orders_redirect_url());
		exit;
	}

	public static function confirm_order_action_handler(WC_Order $order): void
	{
		$gateway = self::get_gateway_instance();
		if ($order->get_payment_method() !== $gateway->id) {
			return;
		}
		$result = $gateway->confirm_transaction($order);
		if (is_wp_error($result)) {
			$order->add_order_note(sprintf(__('CMI confirmation failed: %s', 'woocommerce'), $result->get_error_message()));
		}
	}

	public static function cancel_order_action_handler(WC_Order $order): void
	{
		$gateway = self::get_gateway_instance();
		if ($order->get_payment_method() !== $gateway->id) {
			return;
		}
		$result = $gateway->cancel_transaction($order);
		if (is_wp_error($result)) {
			$order->add_order_note(sprintf(__('CMI void/cancellation failed: %s', 'woocommerce'), $result->get_error_message()));
		}
	}

	public static function refund_order_action_handler(WC_Order $order): void
	{
		$gateway = self::get_gateway_instance();
		if ($order->get_payment_method() !== $gateway->id) {
			return;
		}
		$result = $gateway->refund_transaction($order);
		if (is_wp_error($result)) {
			$order->add_order_note(sprintf(__('CMI refund failed: %s', 'woocommerce'), $result->get_error_message()));
		}
	}

	public function process_refund($order_id, $amount = null, $reason = '')
	{
		$order = wc_get_order($order_id);
		if (!$order) {
			return new WP_Error('cmi_refund_error', __('Order not found.', 'woocommerce'));
		}
		return $this->refund_transaction($order, $amount, $reason, true);
	}

	/**
	 * Refund a transaction through the configured CMI XML API (Type=Credit).
	 *
	 * @param WC_Order   $order          The WooCommerce order object.
	 * @param float|null $amount         Amount to refund (MAD).
	 * @param string     $reason         Refund reason.
	 * @param bool       $from_wc_native Whether called from WC's native process_refund hook.
	 * @return true|WP_Error
	 */
	public function refund_transaction(WC_Order $order, $amount = null, $reason = '', bool $from_wc_native = false)
	{
		if ($order->get_payment_method() !== $this->id) {
			return new WP_Error('cmi_wrong_gateway', __('This order was not paid with CMI.', 'woocommerce'));
		}
		if ($this->apigateway === '' || $this->usernameapi === '' || $this->passwordapi === '') {
			return new WP_Error('cmi_api_not_configured', __('Configure the CMI API URL, username, and password before issuing refunds.', 'woocommerce'));
		}

		$total_order = (float) $order->get_total();
		$current_refunded = (float) $order->get_total_refunded();

		// When called from WC native refund flow, the refund line item is already added to $order
		$max_refundable = $from_wc_native
			? $total_order
			: max(0, $total_order - $current_refunded);

		$refund_amount = $amount !== null ? (float) $amount : $max_refundable;
		if ($refund_amount <= 0) {
			return new WP_Error('cmi_invalid_refund_amount', __('Invalid refund amount.', 'woocommerce'));
		}

		if (!$from_wc_native && $refund_amount > $max_refundable + 0.001) {
			return new WP_Error(
				'cmi_refund_amount_exceeded',
				sprintf(
					__('Refund amount (%s MAD) exceeds remaining balance (%s MAD).', 'woocommerce'),
					number_format($refund_amount, 2),
					number_format($max_refundable, 2)
				)
			);
		}

		$lock_key = 'cmi_confirmation_lock_' . get_current_blog_id() . '_' . $order->get_id();
		$existing_lock = get_option($lock_key);
		if ($existing_lock !== false && (time() - (int) $existing_lock) > 300) {
			delete_option($lock_key);
		}
		if (!add_option($lock_key, (string) time(), '', false)) {
			return new WP_Error('cmi_action_in_progress', __('An operation is already in progress for this order.', 'woocommerce'));
		}

		try {
			$response = $this->credit_request($order, $refund_amount);
			if (is_wp_error($response)) {
				return $response;
			}
			if (($response['Response'] ?? '') !== 'Approved') {
				return new WP_Error('cmi_refund_rejected', $response['ErrMsg'] ?? __('CMI rejected the refund request.', 'woocommerce'));
			}

			// If called from CMI modal or custom action, create official WC_Order_Refund in WooCommerce
			if (!$from_wc_native) {
				wc_create_refund(array(
					'amount'         => $refund_amount,
					'reason'         => $reason ? $reason : __('Refund via CMI API', 'woocommerce'),
					'order_id'       => $order->get_id(),
					'refund_payment' => false,
				));
				// Re-read order to fetch updated refund line items from database
				$order = wc_get_order($order->get_id());
			}

			$total_refunded_now = (float) $order->get_total_refunded();
			$is_full_refund = round($total_refunded_now, 2) >= round($total_order, 2) - 0.001;

			if ($is_full_refund) {
				$this->set_order_payment_status($order, self::PAYMENT_STATUS_REFUNDED, $response);
				$order->update_status(
					'refunded',
					sprintf(
						__('Order fully refunded via CMI API: %s MAD (TransId: %s). Reason: %s', 'woocommerce'),
						number_format($refund_amount, 2),
						$response['TransId'] ?? 'N/A',
						$reason ? $reason : __('N/A', 'woocommerce')
					)
				);
			} else {
				$remaining = max(0, $total_order - $total_refunded_now);
				$order->add_order_note(
					sprintf(
						__('Partial refund processed via CMI API: %s MAD (TransId: %s). Total refunded: %s / %s MAD (Remaining: %s MAD). Reason: %s', 'woocommerce'),
						number_format($refund_amount, 2),
						$response['TransId'] ?? 'N/A',
						number_format($total_refunded_now, 2),
						number_format($total_order, 2),
						number_format($remaining, 2),
						$reason ? $reason : __('N/A', 'woocommerce')
					)
				);
				$order->update_meta_data('_cmi_last_partial_refund_trans_id', $response['TransId'] ?? '');
				$order->update_meta_data('_cmi_total_refunded_api', $total_refunded_now);
				$order->save();
			}

			return true;
		} finally {
			delete_option($lock_key);
		}
	}

	/**
	 * Cancel / Void an authorized CMI transaction through the configured XML API.
	 */
	public function cancel_transaction(WC_Order $order)
	{
		if ($order->get_payment_method() !== $this->id) {
			return new WP_Error('cmi_wrong_gateway', __('This order was not paid with CMI.', 'woocommerce'));
		}
		if ($this->get_order_payment_status($order) === self::PAYMENT_STATUS_VOIDED || $this->get_order_payment_status($order) === self::PAYMENT_STATUS_CLIENT_CANCELLED || $order->get_status() === 'cancelled') {
			return array('Response' => 'Approved', 'already_cancelled' => true);
		}
		if (!Cmi_Payment_State::can_confirm($this->get_order_payment_status($order)) && !in_array($order->get_status(), array('on-hold', 'pending'), true)) {
			return new WP_Error('cmi_not_cancellable', __('Only a CMI PRE transaction can be voided. Confirmed POST transactions must be refunded.', 'woocommerce'));
		}
		if ($this->apigateway === '' || $this->usernameapi === '' || $this->passwordapi === '') {
			return new WP_Error('cmi_api_not_configured', __('Configure the CMI API URL, username, and password before cancelling PRE transactions.', 'woocommerce'));
		}

		$lock_key = 'cmi_confirmation_lock_' . get_current_blog_id() . '_' . $order->get_id();
		$existing_lock = get_option($lock_key);
		if ($existing_lock !== false && (time() - (int) $existing_lock) > 300) {
			delete_option($lock_key);
		}
		if (!add_option($lock_key, (string) time(), '', false)) {
			return new WP_Error('cmi_action_in_progress', __('An operation is already in progress for this order.', 'woocommerce'));
		}

		try {
			$response = $this->void_auth_request($order);
			if (is_wp_error($response)) {
				return $response;
			}
			if (($response['Response'] ?? '') !== 'Approved') {
				return new WP_Error('cmi_cancellation_rejected', $response['ErrMsg'] ?? __('CMI rejected the void request.', 'woocommerce'));
			}

			$this->set_order_payment_status($order, self::PAYMENT_STATUS_VOIDED, $response);
			$order->update_status('cancelled', sprintf(__('CMI PRE transaction voided via CMI API (TransId: %s).', 'woocommerce'), $response['TransId'] ?? 'N/A'));
			return $response;
		} finally {
			delete_option($lock_key);
		}
	}

	/**
	 * Confirm an authorized CMI transaction through the configured XML API.
	 */
	public function confirm_transaction(WC_Order $order, ?float $amount = null)
	{
		if ($order->get_payment_method() !== $this->id) {
			return new WP_Error('cmi_wrong_gateway', __('This order was not paid with CMI.', 'woocommerce'));
		}
		$custom_target = $this->get_postauth_order_status();
		if ($this->get_order_payment_status($order) === self::PAYMENT_STATUS_CONFIRMED || $order->is_paid() || in_array($order->get_status(), array('processing', 'completed', 'confirmed', $custom_target), true)) {
			return array('Response' => 'Approved', 'already_confirmed' => true);
		}
		if (!Cmi_Payment_State::can_confirm($this->get_order_payment_status($order)) && !in_array($order->get_status(), array('on-hold', 'pending'), true)) {
			return new WP_Error('cmi_not_pre_authorized', __('Only a CMI PRE transaction can be confirmed.', 'woocommerce'));
		}
		if ($this->apigateway === '' || $this->usernameapi === '' || $this->passwordapi === '') {
			return new WP_Error('cmi_api_not_configured', __('Configure the CMI API URL, username, and password before confirming PRE transactions.', 'woocommerce'));
		}

		$lock_key = 'cmi_confirmation_lock_' . get_current_blog_id() . '_' . $order->get_id();
		$existing_lock = get_option($lock_key);
		if ($existing_lock !== false && (time() - (int) $existing_lock) > 300) {
			delete_option($lock_key);
		}
		if (!add_option($lock_key, (string) time(), '', false)) {
			return new WP_Error('cmi_confirmation_in_progress', __('A confirmation attempt is already in progress for this order.', 'woocommerce'));
		}

		try {
			$response = $this->post_auth_request($order, $amount);
			if (is_wp_error($response)) {
				return $response;
			}
			if (($response['Response'] ?? '') !== 'Approved') {
				$this->set_order_payment_status($order, self::PAYMENT_STATUS_PRE_CONFIRMATION_REQUIRED, $response);
				return new WP_Error('cmi_confirmation_rejected', $response['ErrMsg'] ?? __('CMI rejected the confirmation request.', 'woocommerce'));
			}

			$target_status = $this->get_postauth_order_status();
			$this->set_order_payment_status($order, self::PAYMENT_STATUS_CONFIRMED, $response);
			$order->payment_complete($response['TransId'] ?? '');
			$order->update_status($target_status, sprintf(__('CMI PRE transaction confirmed and captured through the CMI API (status set to %s).', 'woocommerce'), $target_status));
			return $response;
		} finally {
			delete_option($lock_key);
		}
	}

	private function post_auth_request(WC_Order $order, ?float $amount = null)
	{
		return $this->send_cmi_xml_api_request('PostAuth', $order, $amount);
	}

	private function void_auth_request(WC_Order $order)
	{
		return $this->send_cmi_xml_api_request('Void', $order);
	}

	private function credit_request(WC_Order $order, float $amount)
	{
		return $this->send_cmi_xml_api_request('Credit', $order, $amount);
	}

	public function query_order_status(WC_Order $order)
	{
		return $this->send_cmi_xml_api_request('ORDERSTATUS', $order);
	}

	/**
	 * Actively synchronize a WooCommerce order with CMI via ORDERSTATUS XML API.
	 *
	 * Interprets CMI transaction state:
	 *  - 'APPROVED' (ProcReturnCode '00'): Pre-authorization succeeded on CMI. Updates order to on-hold or processing.
	 *  - 'DECLINED' (ProcReturnCode != '00'): Card or bank rejection.
	 *  - 'NOT_FOUND': No CMI transaction registered (customer canceled/abandoned before entering card).
	 *
	 * @param WC_Order $order
	 * @return array State descriptor ['status' => 'APPROVED'|'DECLINED'|'NOT_FOUND'|'ERROR', 'message' => string]
	 */
	public function sync_order_with_cmi_api(WC_Order $order): array
	{
		if ($order->get_payment_method() !== $this->id) {
			return array('status' => 'ERROR', 'message' => __('Order was not placed using CMI.', 'woocommerce'));
		}

		if (!$this->has_api_configuration()) {
			return array('status' => 'ERROR', 'message' => __('CMI XML API credentials not configured.', 'woocommerce'));
		}

		$response = $this->query_order_status($order);
		if (is_wp_error($response)) {
			return array('status' => 'ERROR', 'message' => $response->get_error_message());
		}

		$cmi_response   = strtolower(trim($response['Response'] ?? ''));
		$proc_code      = trim($response['ProcReturnCode'] ?? '');
		$err_msg        = trim($response['ErrMsg'] ?? '');
		$trans_id       = trim($response['TransId'] ?? '');
		$auth_code      = trim($response['AuthCode'] ?? '');
		$trans_stat     = strtoupper(trim($response['TRANS_STAT'] ?? ''));
		$charge_type_cd = strtoupper(trim($response['CHARGE_TYPE_CD'] ?? ''));

		if ($trans_id !== '') {
			$order->update_meta_data('_cmi_trans_id', $trans_id);
		}
		if ($auth_code !== '') {
			$order->update_meta_data('_cmi_auth_code', $auth_code);
		}
		if ($trans_stat !== '') {
			$order->update_meta_data('_cmi_trans_stat', $trans_stat);
		}
		if ($charge_type_cd !== '') {
			$order->update_meta_data('_cmi_charge_type', $charge_type_cd);
		}
		if (!empty($response['CAPTURE_AMT'])) {
			$order->update_meta_data('_cmi_capture_amt', $response['CAPTURE_AMT']);
		}
		if (!empty($response['PAN'])) {
			$order->update_meta_data('_cmi_pan', $response['PAN']);
		}
		$order->update_meta_data('_cmi_synced_via_api_at', current_time('mysql'));
		$order->save_meta_data();

		$current_status = $order->get_status();
		$target_status  = $this->get_postauth_order_status();

		// -----------------------------------------------------------------
		// CMI SPEC CASE 1: VOIDED / CANCELLED (TRANS_STAT == 'V', CHARGE_TYPE_CD == 'S')
		// Description: Cancelled transaction. Shipment possible: NO.
		// -----------------------------------------------------------------
		if ($trans_stat === 'V') {
			$this->set_order_payment_status($order, self::PAYMENT_STATUS_VOIDED, $response);
			if ($current_status !== 'cancelled') {
				$order->update_status('cancelled', sprintf(
					__('CMI Status Check: Transaction is VOIDED / Cancelled on CMI (TransId: %s, AuthCode: %s).', 'woocommerce'),
					$trans_id ?: 'N/A',
					$auth_code ?: 'N/A'
				));
			} else {
				$order->add_order_note(sprintf(__('CMI Status Check: Transaction confirmed as VOIDED on CMI (TransId: %s).', 'woocommerce'), $trans_id ?: 'N/A'));
			}
			return array(
				'status'     => 'VOIDED',
				'cmi_status' => 'Voided',
				'trans_stat' => 'V',
				'message'    => sprintf(__('Transaction voided on CMI (TransId: %s).', 'woocommerce'), $trans_id ?: 'N/A'),
			);
		}

		// -----------------------------------------------------------------
		// CMI SPEC CASE 2: REFUNDED / CREDITED (CHARGE_TYPE_CD == 'C' with TRANS_STAT 'C' or 'S')
		// Description: Payment refunded. Shipment possible: NO.
		// -----------------------------------------------------------------
		if ($charge_type_cd === 'C' && in_array($trans_stat, array('C', 'S'), true)) {
			$this->set_order_payment_status($order, self::PAYMENT_STATUS_REFUNDED, $response);
			if ($current_status !== 'refunded') {
				$order->update_status('refunded', sprintf(
					__('CMI Status Check: Transaction was refunded / credited on CMI (TransId: %s, Amount: %s MAD).', 'woocommerce'),
					$trans_id ?: 'N/A',
					$response['CAPTURE_AMT'] ?? ''
				));
			} else {
				$order->add_order_note(sprintf(__('CMI Status Check: Transaction confirmed as REFUNDED on CMI (TransId: %s).', 'woocommerce'), $trans_id ?: 'N/A'));
			}
			return array(
				'status'     => 'REFUNDED',
				'cmi_status' => 'Credited',
				'trans_stat' => $trans_stat,
				'message'    => sprintf(__('Payment refunded on CMI (TransId: %s).', 'woocommerce'), $trans_id ?: 'N/A'),
			);
		}

		// -----------------------------------------------------------------
		// CMI SPEC CASE 3: PRE-AUTHORIZATION APPROVED (TRANS_STAT == 'A', CHARGE_TYPE_CD == 'S')
		// Description: Successful transaction (preauthorization). Shipment possible: NO.
		// Order must remain on-hold awaiting manual PostAuth capture.
		// -----------------------------------------------------------------
		if ($trans_stat === 'A') {
			$this->set_order_payment_status($order, self::PAYMENT_STATUS_PRE_CONFIRMATION_REQUIRED, $response);
			if ($current_status !== 'on-hold') {
				$order->update_status('on-hold', sprintf(
					__('CMI Status Check: Pre-authorization verified on CMI (Status: Approved / Pre-auth, AuthCode: %s, TransId: %s). Order is on-hold awaiting capture. Shipment not possible yet.', 'woocommerce'),
					$auth_code ?: 'N/A',
					$trans_id ?: 'N/A'
				));
			} else {
				$order->add_order_note(sprintf(
					__('CMI Status Check: Pre-authorization confirmed on CMI (Status: Approved / Pre-auth, AuthCode: %s, TransId: %s). Order remains on-hold awaiting capture.', 'woocommerce'),
					$auth_code ?: 'N/A',
					$trans_id ?: 'N/A'
				));
			}
			return array(
				'status'     => 'PRE_AUTHORIZED',
				'cmi_status' => 'Approved (preauthorization)',
				'trans_stat' => 'A',
				'message'    => sprintf(__('Pre-authorized on CMI (AuthCode: %s, TransId: %s). Awaiting capture; shipment not possible yet.', 'woocommerce'), $auth_code ?: 'N/A', $trans_id ?: 'N/A'),
			);
		}

		// -----------------------------------------------------------------
		// CMI SPEC CASE 4: CAPTURED / SETTLED (TRANS_STAT == 'C' or 'S', CHARGE_TYPE_CD == 'S')
		// Description: Successful transaction / Settled transaction. Shipment possible: YES.
		// -----------------------------------------------------------------
		if (in_array($trans_stat, array('C', 'S'), true) || ($trans_stat === '' && ($proc_code === '00' || $cmi_response === 'approved'))) {
			$cmi_stat_label = ($trans_stat === 'S') ? 'Deposited / Settled' : 'Approved / Captured';

			$this->set_order_payment_status($order, self::PAYMENT_STATUS_CONFIRMED, $response);

			// If order is already in a completed/processing/custom state, PRESERVE its status:
			if ($order->is_paid() || in_array($current_status, array('completed', 'confirmed', 'processing', $target_status), true)) {
				$order->add_order_note(sprintf(
					__('CMI Status Check: Payment confirmed as captured on CMI (%s, AuthCode: %s, TransId: %s). Order status preserved (currently "%s").', 'woocommerce'),
					$cmi_stat_label,
					$auth_code ?: 'N/A',
					$trans_id ?: 'N/A',
					$current_status
				));
			} else {
				// Order was pending or on-hold: complete payment and set to target status
				$order->payment_complete($trans_id);
				if ($target_status !== $order->get_status()) {
					$order->update_status($target_status, sprintf(
						__('CMI Status Check: Payment captured on CMI (%s, TransId: %s, AuthCode: %s, status set to %s).', 'woocommerce'),
						$cmi_stat_label,
						$trans_id ?: 'N/A',
						$auth_code ?: 'N/A',
						$target_status
					));
				}
			}

			return array(
				'status'     => 'APPROVED',
				'cmi_status' => $cmi_stat_label,
				'trans_stat' => $trans_stat ?: 'C',
				'message'    => sprintf(__('Captured on CMI (%s, AuthCode: %s, TransId: %s). Shipment possible.', 'woocommerce'), $cmi_stat_label, $auth_code ?: 'N/A', $trans_id ?: 'N/A'),
			);
		}

		// -----------------------------------------------------------------
		// CMI SPEC CASE 5: DECLINED (TRANS_STAT == 'D' or ProcReturnCode != '00')
		// Description: Unsuccessful transaction. Shipment possible: NO.
		// -----------------------------------------------------------------
		if ($trans_stat === 'D' || ($proc_code !== '' && $proc_code !== '00')) {
			$this->set_order_payment_status($order, self::PAYMENT_STATUS_FAILED, $response);
			if ($order->has_status('pending')) {
				$order->update_status('failed', sprintf(
					__('CMI Status Check: Declined by bank (ProcReturnCode: %s, %s).', 'woocommerce'),
					$proc_code,
					$err_msg ?: __('Transaction declined.', 'woocommerce')
				));
			} else {
				$order->add_order_note(sprintf(
					__('CMI Status Check: Transaction declined on CMI (ProcReturnCode: %s, %s).', 'woocommerce'),
					$proc_code,
					$err_msg ?: __('Transaction declined.', 'woocommerce')
				));
			}
			return array(
				'status'     => 'DECLINED',
				'cmi_status' => 'Declined',
				'trans_stat' => 'D',
				'message'    => sprintf(__('Declined on CMI: %s (Code: %s).', 'woocommerce'), $err_msg ?: __('Declined', 'woocommerce'), $proc_code),
			);
		}

		// -----------------------------------------------------------------
		// CMI SPEC CASE 6: TRANSIENT / PENDING BANK CONFIRMATION (PN, NW, R)
		// -----------------------------------------------------------------
		if ($trans_stat === 'PN') {
			$order->add_order_note(__('CMI Status Check: Transaction pending confirmation by bank (PN). Transient state.', 'woocommerce'));
			return array(
				'status'     => 'PENDING_BANK',
				'cmi_status' => 'Pending Bank Confirmation',
				'trans_stat' => 'PN',
				'message'    => __('Pending bank confirmation on CMI. Transient state.', 'woocommerce'),
			);
		}
		if ($trans_stat === 'NW') {
			$order->add_order_note(__('CMI Status Check: Transaction initiated but not finalized (NW / FirstCommit).', 'woocommerce'));
			return array(
				'status'     => 'FIRST_COMMIT',
				'cmi_status' => 'FirstCommit',
				'trans_stat' => 'NW',
				'message'    => __('Transaction in progress on CMI (not finalized).', 'woocommerce'),
			);
		}
		if ($trans_stat === 'R') {
			$order->add_order_note(__('CMI Status Check: Reversal required on CMI (R).', 'woocommerce'));
			return array(
				'status'     => 'REVERSAL',
				'cmi_status' => 'Reversal Required',
				'trans_stat' => 'R',
				'message'    => __('Reversal required on CMI.', 'woocommerce'),
			);
		}

		// -----------------------------------------------------------------
		// CMI SPEC CASE 7: NOT FOUND ON CMI (Customer canceled early / abandoned)
		// -----------------------------------------------------------------
		if (stripos($err_msg, 'not found') !== false || ($cmi_response === 'error' && empty($proc_code))) {
			$order->add_order_note(sprintf(__('CMI Status Check: No transaction record found on CMI (%s).', 'woocommerce'), $err_msg ?: 'Not found'));
			return array(
				'status'     => 'NOT_FOUND',
				'cmi_status' => 'Not Found',
				'message'    => __('No transaction record found on CMI (Customer likely abandoned the checkout).', 'woocommerce'),
			);
		}

		return array(
			'status'  => 'UNKNOWN',
			'message' => $err_msg ?: sprintf(__('Unexpected CMI response: %s', 'woocommerce'), $cmi_response),
		);
	}

	private function send_cmi_xml_api_request(string $type, WC_Order $order, ?float $amount = null)
	{
		if ($this->apigateway === '' || $this->usernameapi === '' || $this->passwordapi === '') {
			return new WP_Error('cmi_api_not_configured', __('Configure the CMI API URL, username, and password in WooCommerce settings before performing API operations.', 'woocommerce'));
		}

		$escape = static function (string $value): string {
			return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
		};

		$name = $escape($this->usernameapi);
		$password = $escape($this->passwordapi);
		$client_id = $escape($this->merchant_id);
		$cmi_oid = (string) $order->get_meta('_cmi_oid');
		if ($cmi_oid === '') {
			$cmi_oid = $this->get_cmi_oid_for_order($order);
		}
		$order_id = $escape($cmi_oid);

		if ($type === 'Void') {
			// CMI Void specification: Name, Password, ClientId, Type=Void, OrderId
			$xml = '<?xml version="1.0" encoding="UTF-8"?>' .
				'<CC5Request>' .
				'<Name>' . $name . '</Name>' .
				'<Password>' . $password . '</Password>' .
				'<ClientId>' . $client_id . '</ClientId>' .
				'<Type>Void</Type>' .
				'<OrderId>' . $order_id . '</OrderId>' .
				'</CC5Request>';
		} elseif ($type === 'PostAuth') {
			// CMI PostAuth specification: Name, Password, ClientId, Type=PostAuth, OrderId, (optional Total for partial)
			$xml = '<?xml version="1.0" encoding="UTF-8"?>' .
				'<CC5Request>' .
				'<Name>' . $name . '</Name>' .
				'<Password>' . $password . '</Password>' .
				'<ClientId>' . $client_id . '</ClientId>' .
				'<Type>PostAuth</Type>' .
				'<OrderId>' . $order_id . '</OrderId>';

			if ($amount !== null && $amount < (float) $order->get_total()) {
				$xml .= '<Total>' . $escape(number_format($amount, 2, '.', '')) . '</Total>';
			}

			$xml .= '</CC5Request>';
		} elseif ($type === 'Credit') {
			// CMI Credit specification: Name, Password, ClientId, Type=Credit, Total, Currency, OrderId
			$refund_amount = $amount !== null ? $amount : (float) $order->get_total();
			$xml = '<?xml version="1.0" encoding="UTF-8"?>' .
				'<CC5Request>' .
				'<Name>' . $name . '</Name>' .
				'<Password>' . $password . '</Password>' .
				'<ClientId>' . $client_id . '</ClientId>' .
				'<Type>Credit</Type>' .
				'<Total>' . $escape(number_format($refund_amount, 2, '.', '')) . '</Total>' .
				'<Currency>' . $escape($this->get_currency_code()) . '</Currency>' .
				'<OrderId>' . $order_id . '</OrderId>' .
				'</CC5Request>';
		} elseif ($type === 'ORDERSTATUS') {
			// CMI Order Status specification: Name, Password, ClientId, OrderId, Extra ORDERSTATUS QUERY
			$xml = '<?xml version="1.0" encoding="UTF-8"?>' .
				'<CC5Request>' .
				'<Name>' . $name . '</Name>' .
				'<Password>' . $password . '</Password>' .
				'<ClientId>' . $client_id . '</ClientId>' .
				'<OrderId>' . $order_id . '</OrderId>' .
				'<Extra><ORDERSTATUS>QUERY</ORDERSTATUS></Extra>' .
				'</CC5Request>';
		} else {
			return new WP_Error('cmi_api_unknown_type', __('Unknown CMI API request type.', 'woocommerce'));
		}

		$api_url = trim($this->apigateway);

		wc_get_logger()->info(sprintf('CMI XML API Outbound [%s] Order #%d (OID: %s) -> %s', $type, $order->get_id(), $cmi_oid, $api_url), array(
			'source'   => 'cmi-api',
			'type'     => $type,
			'order_id' => $order->get_id(),
			'cmi_oid'  => $cmi_oid,
		));

		$response = wp_remote_post($api_url, array(
			'body'      => $xml,
			'headers'   => array(
				'Content-Type' => 'text/xml; charset=UTF-8',
				'Accept'       => 'text/xml, application/xml, */*',
			),
			'timeout'   => 45,
			'sslverify' => true,
		));

		if (is_wp_error($response)) {
			wc_get_logger()->error('CMI API Request Error: ' . $response->get_error_message(), array('source' => 'cmi-api'));
			return new WP_Error('cmi_api_request_failed', $response->get_error_message());
		}

		$http_code = wp_remote_retrieve_response_code($response);
		$body = trim(wp_remote_retrieve_body($response));

		wc_get_logger()->info(sprintf('CMI XML API Response HTTP %d: %s', $http_code, substr($body, 0, 500)), array('source' => 'cmi-api'));

		if ($http_code < 200 || $http_code >= 300) {
			return new WP_Error('cmi_api_http_error', sprintf(__('CMI API returned HTTP error %d.', 'woocommerce'), $http_code));
		}

		return $this->parse_cmi_xml_response($body);
	}

	private function parse_cmi_xml_response(string $body)
	{
		$body = trim($body);
		if ($body === '') {
			return new WP_Error('cmi_api_empty_response', __('CMI API returned an empty response. Check your API Gateway URL in settings.', 'woocommerce'));
		}

		// Detect if response is HTML (happens when gateway URL points to payment page instead of API endpoint)
		if (stripos($body, '<html') !== false || stripos($body, '<!doctype') !== false || stripos($body, '<head') !== false) {
			return new WP_Error(
				'cmi_api_html_response',
				__('CMI API returned an HTML page instead of XML. Make sure the "CMI API URL" in plugin settings is set to the XML API endpoint (e.g. https://testpayment.cmi.co.ma/fim/api) and NOT the hosted payment page (which ends with est3Dgate).', 'woocommerce')
			);
		}

		$fields = array('Response', 'ProcReturnCode', 'ErrMsg', 'OrderId', 'TransId', 'HostRefNum', 'AuthCode');
		$extra_fields = array('TRANS_STAT', 'CHARGE_TYPE_CD', 'AUTH_CODE', 'TRANS_ID', 'HOST_REF_NUM', 'CAPTURE_AMT', 'ORIG_TRANS_AMT', 'CAPTURE_DTTM', 'AUTH_DTTM', 'PAN', 'ORDERSTATUS');
		$result = array();

		// Primary XML parser
		$previous = libxml_use_internal_errors(true);
		$xml_response = simplexml_load_string($body);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($xml_response !== false) {
			foreach ($fields as $field) {
				$result[$field] = isset($xml_response->{$field}) ? sanitize_text_field((string) $xml_response->{$field}) : '';
			}

			// Extract from <Extra> node (present in CMI ORDERSTATUS responses)
			if (isset($xml_response->Extra)) {
				$extra = $xml_response->Extra;
				foreach ($extra_fields as $ef) {
					$result[$ef] = isset($extra->{$ef}) ? sanitize_text_field((string) $extra->{$ef}) : '';
				}
			}

			// Map Extra aliases to root if empty
			if (empty($result['AuthCode']) && !empty($result['AUTH_CODE'])) {
				$result['AuthCode'] = $result['AUTH_CODE'];
			}
			if (empty($result['TransId']) && !empty($result['TRANS_ID'])) {
				$result['TransId'] = $result['TRANS_ID'];
			}
			if (empty($result['HostRefNum']) && !empty($result['HOST_REF_NUM'])) {
				$result['HostRefNum'] = $result['HOST_REF_NUM'];
			}

			// Parse inline values from <ORDERSTATUS> text block if present
			if (!empty($result['ORDERSTATUS'])) {
				if (empty($result['TRANS_STAT']) && preg_match('/TRANS_STAT:([A-Za-z0-9_]+)/i', $result['ORDERSTATUS'], $m)) {
					$result['TRANS_STAT'] = strtoupper(trim($m[1]));
				}
				if (empty($result['CHARGE_TYPE_CD']) && preg_match('/CHARGE_TYPE_CD:([A-Za-z0-9_]+)/i', $result['ORDERSTATUS'], $m)) {
					$result['CHARGE_TYPE_CD'] = strtoupper(trim($m[1]));
				}
				if (empty($result['CAPTURE_AMT']) && preg_match('/CAPTURE_AMT:([0-9.]+)/i', $result['ORDERSTATUS'], $m)) {
					$result['CAPTURE_AMT'] = trim($m[1]);
				}
			}

			if (!empty($result['Response']) || !empty($result['ProcReturnCode'])) {
				return $result;
			}
		}

		// Robust regex fallback parser for responses with non-standard declarations or whitespace
		$matched_any = false;
		foreach (array_merge($fields, $extra_fields) as $field) {
			if (preg_match('/<' . $field . '[^>]*>(.*?)<\/' . $field . '>/is', $body, $matches)) {
				$result[$field] = sanitize_text_field(trim($matches[1]));
				$matched_any = true;
			} else {
				if (!isset($result[$field])) {
					$result[$field] = '';
				}
			}
		}

		if (empty($result['AuthCode']) && !empty($result['AUTH_CODE'])) {
			$result['AuthCode'] = $result['AUTH_CODE'];
		}
		if (empty($result['TransId']) && !empty($result['TRANS_ID'])) {
			$result['TransId'] = $result['TRANS_ID'];
		}
		if (empty($result['TRANS_STAT']) && !empty($result['ORDERSTATUS']) && preg_match('/TRANS_STAT:([A-Za-z0-9_]+)/i', $result['ORDERSTATUS'], $m)) {
			$result['TRANS_STAT'] = strtoupper(trim($m[1]));
		}
		if (empty($result['CHARGE_TYPE_CD']) && !empty($result['ORDERSTATUS']) && preg_match('/CHARGE_TYPE_CD:([A-Za-z0-9_]+)/i', $result['ORDERSTATUS'], $m)) {
			$result['CHARGE_TYPE_CD'] = strtoupper(trim($m[1]));
		}

		if ($matched_any && (!empty($result['Response']) || !empty($result['ProcReturnCode']))) {
			return $result;
		}

		return new WP_Error('cmi_api_invalid_response', sprintf(__('CMI API returned an unexpected response: %s', 'woocommerce'), esc_html(substr(strip_tags($body), 0, 150))));
	}

	public function render_payment_result($order_id): void
	{
		static $rendered = array();
		if (!empty($rendered[$order_id])) {
			return;
		}
		$rendered[$order_id] = true;

		$order = wc_get_order($order_id);
		if (!$order || $order->get_payment_method() !== $this->id) {
			return;
		}
		$status = $this->get_order_payment_status($order);
		if ($status === '') {
			return;
		}
		$message = $status === self::PAYMENT_STATUS_CONFIRMED
			? __('Your CMI payment was confirmed.', 'woocommerce')
			: ($status === self::PAYMENT_STATUS_PRE_CONFIRMATION_REQUIRED
				? __('Your payment was authorized and awaits merchant confirmation.', 'woocommerce')
				: ($status === self::PAYMENT_STATUS_VOIDED
					? __('The CMI transaction was voided by the merchant.', 'woocommerce')
					: ($status === self::PAYMENT_STATUS_CLIENT_CANCELLED
						? __('You cancelled the payment.', 'woocommerce')
						: __('The CMI payment was not completed.', 'woocommerce'))));
		echo '<section class="woocommerce-cmi-payment-result"><h2>' . esc_html__('CMI payment result', 'woocommerce') . '</h2><p><strong>' . esc_html($this->payment_status_label($status)) . '</strong> — ' . esc_html($message) . '</p></section>';
	}

	/**
	 * Setup constants.
	 *
	 * Setup common values and messages used by the cmi gateway.
	 *
	 * @since 1.0.0
	 */
	function setup_constants()
	{
		if (!defined('BPI_SOFTWARE_NAME')) {
			define('BPI_SOFTWARE_NAME', 'WooCommerce');
		}
		if (!defined('BPI_SOFTWARE_VER')) {
			define('BPI_SOFTWARE_VER', WC()->version);
		}
		if (!defined('BPI_MODULE_NAME')) {
			define('BPI_MODULE_NAME', 'WooCommerce-cmi-Free');
		}
		if (!defined('BPI_MODULE_VER')) {
			define('BPI_MODULE_VER', $this->version);
		}

		// Features
		// - PHP
		$pfFeatures = 'PHP ' . phpversion() . ';';

		// - cURL
		if (in_array('curl', get_loaded_extensions())) {
			// define( 'BPI_CURL', '' );
			if (!defined('BPI_CURL')) {
				define('BPI_CURL', '');
			}
			$pfVersion = curl_version();
			$pfFeatures .= ' curl ' . $pfVersion['version'] . ';';
		} else
			$pfFeatures .= ' nocurl;';

		// Create user agrent
		// define( 'BPI_USER_AGENT', BPI_SOFTWARE_NAME .'/'. BPI_SOFTWARE_VER .' ('. trim( $pfFeatures ) .') '. BPI_MODULE_NAME .'/'. BPI_MODULE_VER );
		if (!defined('BPI_USER_AGENT')) {
			define('BPI_USER_AGENT', BPI_SOFTWARE_NAME . '/' . BPI_SOFTWARE_VER . ' (' . trim($pfFeatures) . ') ' . BPI_MODULE_NAME . '/' . BPI_MODULE_VER);
		}

		// General Defines
		if (!defined('BPI_TIMEOUT')) {
			define('BPI_TIMEOUT', 15);
		}
		if (!defined('BPI_EPSILON')) {
			define('BPI_EPSILON', 0.01);
		}

		// Messages
		// Error
		if (!defined('BPI_ERR_AMOUNT_MISMATCH')) {
			define('BPI_ERR_AMOUNT_MISMATCH', __('Amount mismatch', 'woothemes'));
		}
		if (!defined('BPI_ERR_BAD_ACCESS')) {
			define('BPI_ERR_BAD_ACCESS', __('Bad access of page', 'woothemes'));
		}
		if (!defined('BPI_ERR_INVALID_SIGNATURE')) {
			define('BPI_ERR_INVALID_SIGNATURE', __('Security signature mismatch', 'woothemes'));
		}

	} // End setup_constants()



	/**
	 * check_amounts()
	 *
	 * Checks if received amount is equal to sent amount
	 *
	 * @param $amount1 Float 1st amount for comparison
	 * @param $amount2 Float 2nd amount for comparison
	 * @since 1.0.0
	 */
	function check_amounts($amount1, $amount2)
	{
		if (number_format($amount1) !== number_format($amount2))
			return false;
		return true;
	} // End check_amounts()

	/*
	 * cmi_get_currency_exchange_rates_url_response.
	 *
	 */
	function wpse_load_plugin_css()
	{

		wp_enqueue_style('style1', $this->plugin_url() . '/assets/css/font-awesome.min.css');
		wp_enqueue_style('style2', $this->plugin_url() . '/assets/css/style.css');
	}
	function str_without_accents($str, $charset = 'utf-8')
	{
		$str = htmlentities($str, ENT_NOQUOTES, $charset);
		$str = preg_replace('#&([A-za-z])(?:acute|cedil|caron|circ|grave|orn|ring|slash|th|tilde|uml);#', '\1', $str);
		$str = preg_replace('#&([A-za-z]{2})(?:lig);#', '\1', $str);
		$str = preg_replace('#&[^;]+;#', '', $str);
		$str = preg_replace('/[^a-zA-Z0-9_ -]/s', '', $str);
		return $str;
	}

	/**
	 * Sanitize an order identifier for CMI payment gateway.
	 *
	 * CMI supports alphanumeric values up to 64 characters.
	 * Strips accents, spaces, slashes, and any special characters,
	 * keeping only [a-zA-Z0-9_-].
	 *
	 * @param string $raw_oid
	 * @return string
	 */
	public function sanitize_cmi_oid(string $raw_oid): string
	{
		if (function_exists('remove_accents')) {
			$clean = remove_accents($raw_oid);
		} else {
			$clean = $this->str_without_accents($raw_oid);
		}
		// Strip all whitespace and characters that are NOT alphanumeric, hyphen, or underscore
		$clean = preg_replace('/[^a-zA-Z0-9_-]/', '', $clean);
		// Enforce CMI maximum length of 64 characters
		return substr((string) $clean, 0, 64);
	}

	/**
	 * Get the sanitized order identifier to send to CMI.
	 *
	 * Depending on the 'order_id_format' setting:
	 * - 'number': uses $order->get_order_number(), sanitizes it, falls back to order ID if empty.
	 * - 'id': uses $order->get_id().
	 *
	 * Allows custom override via 'woocommerce_cmi_order_id' filter.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	public function get_cmi_oid_for_order(WC_Order $order): string
	{
		$format = !empty($this->order_id_format) ? $this->order_id_format : 'number';

		if ($format === 'id') {
			$raw_oid = (string) $order->get_id();
		} else {
			$raw_oid = (string) $order->get_order_number();
		}

		$sanitized = $this->sanitize_cmi_oid($raw_oid);

		// Fallback to internal order ID if sanitized order number is empty
		if ($sanitized === '') {
			$sanitized = (string) $order->get_id();
		}

		$filtered = apply_filters('woocommerce_cmi_order_id', $sanitized, $order, $format);
		$final_oid = $this->sanitize_cmi_oid((string) $filtered);

		return $final_oid !== '' ? $final_oid : (string) $order->get_id();
	}

	/**
	 * Resolve a WC_Order from a CMI oid parameter received in callbacks/redirects.
	 *
	 * Handles standard numeric WordPress order IDs, sequential alphanumeric order numbers,
	 * and orders tracked by '_cmi_oid' meta key.
	 *
	 * @param string $raw_oid
	 * @return WC_Order|false
	 */
	public function find_order_by_cmi_oid(string $raw_oid)
	{
		$raw_oid = trim($raw_oid);
		if ($raw_oid === '') {
			return false;
		}

		// 1. If purely numeric, try standard lookup first
		if (ctype_digit($raw_oid)) {
			$order = wc_get_order((int) $raw_oid);
			if ($order instanceof WC_Order) {
				return $order;
			}
		}

		// 2. Lookup by '_cmi_oid' post/order meta (exact match saved during checkout)
		$orders = wc_get_orders(array(
			'limit'        => 1,
			'meta_key'     => '_cmi_oid',
			'meta_value'   => $raw_oid,
			'meta_compare' => '=',
			'return'       => 'objects',
		));
		if (!empty($orders)) {
			$order = reset($orders);
			if ($order instanceof WC_Order) {
				return $order;
			}
		}

		// 3. Check third-party sequential order numbers plugin helper (e.g. WooCommerce Sequential Order Numbers)
		if (function_exists('wc_seq_order_number_to_order_id')) {
			$order_id = wc_seq_order_number_to_order_id($raw_oid);
			if ($order_id) {
				$order = wc_get_order($order_id);
				if ($order instanceof WC_Order) {
					return $order;
				}
			}
		}

		// 4. Fallback lookup by common sequential plugins meta keys (_order_number, _order_number_formatted)
		foreach (array('_order_number', '_order_number_formatted') as $meta_key) {
			$orders = wc_get_orders(array(
				'limit'        => 1,
				'meta_key'     => $meta_key,
				'meta_value'   => $raw_oid,
				'meta_compare' => '=',
				'return'       => 'objects',
			));
			if (!empty($orders)) {
				$order = reset($orders);
				if ($order instanceof WC_Order) {
					return $order;
				}
			}
		}

		return false;
	}

} // End Class
