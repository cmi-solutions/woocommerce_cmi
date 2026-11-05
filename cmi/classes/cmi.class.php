<?php
defined('ABSPATH') || exit;
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
	protected string $order_id_prefix;

	private const PAYMENT_STATUS_INITIATED = Cmi_Payment_State::INITIATED;
	private const PAYMENT_STATUS_FAILED = Cmi_Payment_State::FAILED;
	private const PAYMENT_STATUS_CLIENT_CANCELLED = Cmi_Payment_State::CLIENT_CANCELLED;
	private const PAYMENT_STATUS_VOIDED = Cmi_Payment_State::VOIDED;
	private const PAYMENT_STATUS_PRE_CONFIRMATION_REQUIRED = Cmi_Payment_State::PRE_CONFIRMATION_REQUIRED;
	private const PAYMENT_STATUS_CONFIRMED = Cmi_Payment_State::CONFIRMED;
	private const PAYMENT_STATUS_REFUNDED = Cmi_Payment_State::REFUNDED;
	private const PAYMENT_STATUS_META_KEY = '_cmi_payment_status';
	private const META_AMOUNT_MAD = '_cmi_amount_mad';
	private const META_MAD_RATE = '_cmi_mad_rate';
	private const META_REFUNDED_MAD = '_cmi_refunded_mad';
	private const STATUS_REPAIR_OPTION = 'cmi_status_repair_done';
	// Proof of which CMI transactions are this order's own: every TransId / HostRefNum
	// from its signed callback and from API operations already verified as ours
	private const META_OWN_IDS = '_cmi_own_ids';
	private const META_SENT_AT = '_cmi_sent_at';
	private const META_PENDING_CHECKS = '_cmi_pending_checks';
	private const PENDING_CHECK_HOOK = 'cmi_check_pending_order';

	/** Set while the plugin reads the order's own number, before the CMI prefix. */
	private static bool $use_raw_order_number = false;

	public function __construct()
	{
		$this->id = 'cmi';
		$this->method_title = __('CMI', 'cmi');
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
		$this->title = (string) $this->get_option('title', __('CMI', 'cmi'));
		// Read by the Checkout Blocks integration (the classic checkout uses payment_fields()).
		$this->description = (string) $this->get_option('description', '');
		$this->confirmation_mode = $this->normalise_confirmation_mode((string) $this->get_option('confirmation_mode', 'automatic'));
		$this->postauth_status = $this->get_postauth_order_status();
		$this->postauth_custom_status = self::sanitize_custom_status_slug((string) $this->get_option('postauth_custom_status', ''));
		$this->payment_page_language = (string) $this->get_option('payment_page_language', 'auto');
		$this->order_id_prefix = Cmi_Transaction_Guard::sanitize_oid_prefix((string) $this->get_option('order_id_prefix', ''));
		$this->apigateway = $this->get_config_value('api_gateway_url', 'apigateway');
		$this->passwordapi = $this->get_config_value('api_password', 'passwordapi');
		$this->usernameapi = $this->get_config_value('api_username', 'usernameapi');
		$this->exchange_rates = (string) $this->get_option('exchange_rates', '');

		// Rates saved in the table. Very old versions stored one rate in the settings instead.
		$legacy_rate = array();
		if ((string) $this->get_option('currency_code', '') !== '' && (string) $this->get_option('currency_rate', '') !== '') {
			$legacy_rate[] = array(
				'currency_code' => $this->get_option('currency_code'),
				'currency_rate' => $this->get_option('currency_rate'),
			);
		}
		$this->currency_details = (array) get_option('woocommerce_cmi_currencies', $legacy_rate);



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
				'title' => __('General Settings', 'cmi'),
				'type' => 'title',
				'description' => __('Basic payment gateway configuration. These settings control how CMI appears to your customers at checkout.', 'cmi'),
			),
			'enabled' => array(
				'title' => __('Enable CMI Payment', 'cmi'),
				'label' => __('Enable CMI as a checkout payment method', 'cmi'),
				'type' => 'checkbox',
				'description' => __('When enabled, customers will see CMI as a payment option during checkout.', 'cmi'),
				'default' => 'yes',
			),
			'title' => array(
				'title' => __('Payment Title', 'cmi'),
				'type' => 'text',
				'description' => __('The name customers see for this payment method at checkout (e.g. "Pay by Card", "CMI").', 'cmi'),
				'default' => __('CMI', 'cmi'),
			),
			'description' => array(
				'title' => __('Payment Description', 'cmi'),
				'type' => 'text',
				'description' => __('A short description shown below the payment title at checkout (e.g. "Secure payment via CMI").', 'cmi'),
				'default' => '',
			),

			// ── Section B: Payment Setup ─────────────────────────────────────
			'section_payment_setup' => array(
				'title' => __('Payment Setup', 'cmi'),
				'type' => 'title',
				'description' => __('Choose your CMI kit type and configure how payments are processed.', 'cmi'),
			),
			'kit_type' => array(
				'title' => __('CMI Kit Type', 'cmi'),
				'type' => 'select',
				'description' => __('<strong>Standard Kit</strong> — Redirects the customer to the CMI hosted payment page. Transaction management (confirmation, void, refund) must be done from the CMI back office.<br><strong>Complete Kit (with API)</strong> — Same as Standard Kit, plus a <strong>CMI Actions</strong> column in the WooCommerce orders list allowing you to <strong>Confirm</strong>, <strong>Void</strong>, and <strong>Refund</strong> transactions directly from your shop. It requires the API credentials provided by CMI (API URL, username and password).', 'cmi'),
				'default' => 'standard',
				'options' => array(
					'standard' => __('Standard Kit — Hosted Payment Page only', 'cmi'),
					'complete' => __('Complete Kit — With API Management (Confirm, Void, Refund)', 'cmi'),
				),
			),
			'confirmation_mode' => array(
				'title' => __('Payment Confirmation', 'cmi'),
				'type' => 'select',
				'description' => __('<strong>Automatic</strong> — The payment is captured immediately when the customer completes checkout via the callback.<br><strong>Manual</strong> — The payment is only authorized (pre-authorized). You must manually confirm it from the orders list to capture the funds if you have the Complete Kit (API), or via the CMI back office if you only have the Standard Kit. If the order stays pending, the plugin also checks CMI automatically (Complete Kit), and you can use Check CMI at any time.', 'cmi'),
				'default' => 'automatic',
				'options' => array(
					'automatic' => __('Automatic — Capture payment immediately', 'cmi'),
					'manual' => __('Manual — Pre-authorize only (confirm later)', 'cmi'),
				),
			),
			'postauth_status' => array(
				'title' => __('Order Status After PostAuth', 'cmi'),
				'type' => 'select',
				'description' => __('Choose the WooCommerce order status applied after a successful payment capture (PostAuth / Confirmation). Select an existing status or choose "Custom" to enter your own.', 'cmi'),
				'default' => 'completed',
				'options' => $this->get_available_order_status_options(),
			),
			'postauth_custom_status' => array(
				'title' => __('Custom Order Status Slug', 'cmi'),
				'type' => 'text',
				'description' => __('Enter a short status slug (<strong>max 17 characters</strong>, letters, numbers, and hyphens only). The <code>wc-</code> prefix is added automatically. Example: <code>cmi-confirmed</code>, <code>prep-deliv</code>.', 'cmi'),
				'default' => '',
				'custom_attributes' => array(
					'maxlength' => '17',
					'placeholder' => 'e.g. cmi-confirmed',
				),
			),
			'payment_page_language' => array(
				'title' => __('Payment Page Language', 'cmi'),
				'type' => 'select',
				'description' => __('The language displayed on the CMI hosted payment page. "Auto" uses your store\'s locale.', 'cmi'),
				'default' => 'auto',
				'options' => array(
					'auto' => __('Auto — Use store locale', 'cmi'),
					'fr' => __('French', 'cmi'),
					'en' => __('English', 'cmi'),
					'ar' => __('Arabic', 'cmi'),
				),
			),
			'auto_redirect' => array(
				'title' => __('Automatic Redirect', 'cmi'),
				'label' => __('Return customers to the shop automatically after payment', 'cmi'),
				'type' => 'checkbox',
				'description' => __('When enabled, CMI sends the customer back to your shop as soon as the payment is finished. When disabled, the customer stays on CMI\'s result page and returns to the shop themselves.', 'cmi'),
				'default' => 'yes',
			),
			'order_id_prefix' => array(
				'title' => __('Order Identifier Prefix', 'cmi'),
				'type' => 'text',
				'description' => sprintf(
					/* translators: %s: example CMI order ID */
					__('Example: order 1050 appears as %s, both in WooCommerce and in the CMI back office. The prefix keeps this shop\'s order numbers different from any other website using the same CMI account. It was generated automatically; you can replace it with something more readable, such as your shop\'s initials. Orders already sent to CMI keep their number.', 'cmi'),
					'<code id="cmi-oid-example">' . esc_html(Cmi_Transaction_Guard::apply_oid_prefix((string) (get_option('woocommerce_cmi_settings', array())['order_id_prefix'] ?? ''), '1050')) . '</code>'
				),
				'default' => '',
				'custom_attributes' => array('maxlength' => '20'),
			),

			// ── Section C: Gateway Connection ────────────────────────────────
			'section_gateway_connection' => array(
				'title' => __('Gateway Connection', 'cmi'),
				'type' => 'title',
				'description' => __('Enter the credentials provided by CMI. These are required for your shop to connect to the CMI payment gateway.', 'cmi'),
			),
			'client_id' => array(
				'title' => __('Client ID *', 'cmi'),
				'type' => 'text',
				'description' => __('Your merchant Client ID provided by CMI (e.g. 600XXXXXX).', 'cmi'),
				'default' => '',
				'custom_attributes' => array('required' => 'required'),
			),
			'store_key' => array(
				'title' => __('Store Key (Hash Key) *', 'cmi'),
				'type' => 'password',
				'description' => __('The secret hash key configured in your CMI Merchant Center. Used to secure communication between your shop and CMI. The key must not contain the word "SKS".', 'cmi'),
				'default' => '',
				'custom_attributes' => array('required' => 'required'),
			),
			'gateway_url' => array(
				'title' => __('Payment Page URL *', 'cmi'),
				'type' => 'text',
				'description' => __('The CMI hosted payment page URL (e.g. https://payment.cmi.co.ma/fim/est3Dgate).', 'cmi'),
				'default' => '',
				'custom_attributes' => array('required' => 'required'),
			),

			// ── Section D: API Management Credentials ────────────────────────
			'section_api_credentials' => array(
				'title' => __('API Management Credentials', 'cmi'),
				'type' => 'title',
				'description' => __('These credentials are provided by CMI and are required for <strong>Confirmation</strong> (PostAuth), <strong>Void</strong>, and <strong>Refund</strong> (Credit) operations. Only needed when using the Complete Kit.', 'cmi'),
			),
			'api_gateway_url' => array(
				'title' => __('CMI API URL *', 'cmi'),
				'type' => 'text',
				'description' => __('The XML API endpoint URL (e.g. https://payment.cmi.co.ma/fim/api). This is NOT the same as the Payment Page URL above.', 'cmi'),
				'default' => '',
			),
			'api_username' => array(
				'title' => __('API Username *', 'cmi'),
				'type' => 'text',
				'description' => __('The API username provided by CMI for back-office operations.', 'cmi'),
				'default' => '',
			),
			'api_password' => array(
				'title' => __('API Password *', 'cmi'),
				'type' => 'password',
				'description' => __('The API password provided by CMI for back-office operations.', 'cmi'),
				'default' => '',
			),

			// ── Section E: Currency & Exchange Rates ─────────────────────────
			'section_currency' => array(
				'title' => __('Currency & Exchange Rates', 'cmi'),
				'type' => 'title',
				'description' => __('Payments are processed in MAD (Moroccan Dirham) on your standard CMI merchant account.', 'cmi'),
			),
			'exchange_rates' => array(
				'title' => __('Exchange Rate Mode', 'cmi'),
				'type' => 'select',
				'class' => 'wc-enhanced-select',
				'description' => __(
					'If your WooCommerce store currency is not MAD, choose how to convert order amounts to MAD before sending them to CMI.<br>'
					. '<strong>Manual</strong> — You enter and maintain exchange rates yourself in the table below.<br>'
					. '<strong>External Multi-Currency Plugin</strong> — Rates are retrieved automatically from a compatible multi-currency plugin '
					. '(WOOCS / FOX, WPML + WCML, CURCY / VillaTheme, Aelia Currency Switcher).<br>'
					. '<em>⚠️ In both cases, you are fully responsible for the accuracy of the exchange rates applied.</em>',
					'cmi'
				),
				'default' => '',
				'options' => array(
					'' => __('— Not needed (store currency is MAD)', 'cmi'),
					'manual' => __('Manual — Enter exchange rates manually', 'cmi'),
					'plugin' => __('External Multi-Currency Plugin — Automatic rates', 'cmi'),
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
		// Unique order-ID prefix, so this shop's OrderIds never collide with another
		// system using the same CMI account. Only set once: an empty value chosen by
		// the merchant is kept.
		if (!array_key_exists('order_id_prefix', $settings)) {
			$settings['order_id_prefix'] = Cmi_Transaction_Guard::generate_oid_prefix();
			$changed = true;
		}
		// Defaults for new fields — existing installs keep working
		$has_api_credentials = trim((string) ($settings['api_gateway_url'] ?? '')) !== ''
			&& trim((string) ($settings['api_username'] ?? '')) !== ''
			&& trim((string) ($settings['api_password'] ?? '')) !== '';
		$new_defaults = array(
			// The Complete Kit needs the API credentials: without them, start with the Standard Kit.
			'kit_type' => $has_api_credentials ? 'complete' : 'standard',
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
			self::PAYMENT_STATUS_INITIATED => __('INITIATED', 'cmi'),
			self::PAYMENT_STATUS_FAILED => __('FAILED', 'cmi'),
			self::PAYMENT_STATUS_CLIENT_CANCELLED => __('CANCELLED', 'cmi'),
			self::PAYMENT_STATUS_VOIDED => __('VOIDED', 'cmi'),
			self::PAYMENT_STATUS_PRE_CONFIRMATION_REQUIRED => __('PRE_CONFIRMATION_REQUIRED', 'cmi'),
			self::PAYMENT_STATUS_CONFIRMED => __('SUCCESS', 'cmi'),
			self::PAYMENT_STATUS_REFUNDED => __('REFUNDED', 'cmi'),
		);
		return $labels[$status] ?? __('PENDING', 'cmi');
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
			<th scope="row" class="titledesc"><?php esc_html_e('Currency details:', 'cmi'); ?></th>
			<td class="forminp" id="cmi_currencies">
				<div class="wc_input_table_wrapper">
					<table class="widefat wc_input_table sortable" cellspacing="0">
						<thead>
							<tr>
								<th class="sort">&nbsp;</th>
								<th><?php esc_html_e('Currency code', 'cmi'); ?></th>
								<th><?php esc_html_e('Currency rate', 'cmi'); ?></th>
							</tr>
						</thead>
						<tbody class="currencies">
							<?php
							$i = -1;
							$wc_currencies = get_woocommerce_currencies();
							$selctHtml = '';
							if ($this->currency_details) {
								foreach ($this->currency_details as $currency) {
									$selctHtml = '';
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
										class="add button"><?php esc_html_e('+ Add currency', 'cmi'); ?></a> <a
										href="#"
										class="remove_rows button"><?php esc_html_e('Remove selected currency(s)', 'cmi'); ?></a>
								</th>
							</tr>
						</tfoot>
					</table>
				</div>
				<?php
				$wc_currencies = get_woocommerce_currencies();
				$currOptions = '';
				foreach ($wc_currencies as $k => $wc_currency) {
					$currOptions .= '<option value="' . esc_attr($k) . '" >' . $k . '-' . esc_html($wc_currency) . ' (' . get_woocommerce_currency_symbol($k) . ')</option>';
				}
				?>
				<script type="text/javascript">
					jQuery(function () {
						// Index for the next new row. It only ever grows: after a row is removed, the
						// number of rows can equal an index still in use, and the duplicate would be lost.
						var nextIndex = jQuery('#cmi_currencies').find('tbody .currency').length;

						jQuery('#cmi_currencies').on('click', 'a.add', function () {

							var size = nextIndex++;

							jQuery('<tr class="currency">\
											<td class="sort"></td>\
											<td><select name="cmi_currency_code[' + size + ']" class="vi-ui select2"><?php echo $currOptions; ?></select></td>\
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

			$currency_codes = (array) wc_clean(wp_unslash($_POST['cmi_currency_code']));
			$currency_rates = (array) wc_clean(wp_unslash($_POST['cmi_currency_rate']));
			$known_currencies = get_woocommerce_currencies();
			$rejected = array();

			foreach ($currency_codes as $i => $code) {
				// A row whose rate was left empty holds nothing to save: skip it silently
				// (e.g. the empty row shown on a new install, whose list starts with AED).
				if (trim((string) ($currency_rates[$i] ?? '')) === '') {
					continue;
				}
				$rate = Cmi_Currency::parse_rate($currency_rates[$i] ?? '');
				if (!isset($known_currencies[$code]) || $rate <= 0) {
					$rejected[] = $code;
					continue;
				}

				$currencies[] = array(
					'currency_code' => $code,
					'currency_rate' => (string) $rate,
				);
			}

			if ($rejected && class_exists('WC_Admin_Settings')) {
				WC_Admin_Settings::add_error(sprintf(
					__('CMI: the exchange rate for %s was not saved because it must be a number greater than 0.', 'cmi'),
					implode(', ', array_map('esc_html', $rejected))
				));
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
			.cmi-password-wrap {
				position: relative;
				display: inline-block;
				max-width: 100%;
			}
			.cmi-password-wrap input {
				padding-right: 36px !important;
			}
			.cmi-password-toggle {
				position: absolute;
				top: 50%;
				right: 4px;
				transform: translateY(-50%);
				background: none;
				border: 0;
				padding: 4px;
				cursor: pointer;
				color: #50575e;
				line-height: 1;
			}
			.cmi-password-toggle:hover,
			.cmi-password-toggle:focus {
				color: #2271b1;
			}
		</style>

		<div class="cmi-admin-header">
			<h2>
				<span class="dashicons dashicons-shield-alt" style="color:#0073aa; font-size:22px; width:22px; height:22px;"></span>
				<?php esc_html_e('CMI Payment Gateway Configuration', 'cmi'); ?>
			</h2>
			<p><?php esc_html_e('Configure your Moroccan and International card payment processing via CMI. Fields with (*) are mandatory.', 'cmi'); ?></p>
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
					<?php esc_html_e('🔌 External Plugin Mode Active', 'cmi'); ?>
				</p>
				<p style="margin:0 0 6px; color:#50575e; font-size:13px;">
					<?php esc_html_e('Exchange rates will be retrieved automatically from your multi-currency plugin. The following plugins are auto-detected:', 'cmi'); ?>
				</p>
				<ul style="margin:4px 0 8px 18px; color:#50575e; font-size:13px;">
					<li><strong>WOOCS / FOX</strong> — <?php esc_html_e('Currency Switcher for WooCommerce', 'cmi'); ?></li>
					<li><strong>WPML + WCML</strong> — <?php esc_html_e('WooCommerce Multilingual & Multicurrency', 'cmi'); ?></li>
					<li><strong>CURCY</strong> — <?php esc_html_e('VillaTheme Multi Currency for WooCommerce', 'cmi'); ?></li>
					<li><strong>Aelia</strong> — <?php esc_html_e('Aelia Currency Switcher', 'cmi'); ?></li>
				</ul>
				<p style="margin:8px 0 0; padding-top:8px; border-top:1px solid #c3daf5; color:#b32d2e; font-size:12px; font-style:italic;">
					<?php esc_html_e('⚠️ You are fully responsible for ensuring that your multi-currency plugin is properly configured and that the exchange rates are accurate.', 'cmi'); ?>
				</p>
			</div>
		</div>

		<script type="text/javascript">
		jQuery(function($) {
			// Live preview of the order ID sent to CMI while the prefix is edited
			$('#woocommerce_cmi_order_id_prefix').on('input', function() {
				var prefix = $(this).val().replace(/[^a-zA-Z0-9_-]/g, '').substring(0, 20);
				$('#cmi-oid-example').text(prefix + '1050');
			});

			// Show/hide toggle (eye icon) for the Store Key and API Password fields
			$('#woocommerce_cmi_store_key, #woocommerce_cmi_api_password').each(function() {
				var $input = $(this);
				var $toggle = $('<button type="button" class="cmi-password-toggle"><span class="dashicons dashicons-visibility"></span></button>')
					.attr('aria-label', '<?php echo esc_js(__('Show password', 'cmi')); ?>');
				$input.wrap('<span class="cmi-password-wrap"></span>').after($toggle);
				$toggle.on('click', function() {
					var show = $input.attr('type') === 'password';
					$input.attr('type', show ? 'text' : 'password');
					$toggle.find('.dashicons').toggleClass('dashicons-visibility', !show).toggleClass('dashicons-hidden', show);
					$toggle.attr('aria-label', show ? '<?php echo esc_js(__('Hide password', 'cmi')); ?>' : '<?php echo esc_js(__('Show password', 'cmi')); ?>');
				});
			});

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
	 * Rates come only from these plugins: no outside code can supply or change them.
	 *
	 * @param string $store_currency The order currency (e.g. 'EUR').
	 * @return float|false The exchange rate (1 unit of store_currency = X MAD), or false if unavailable.
	 */
	private function get_plugin_exchange_rate(string $store_currency)
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
		global $woocommerce_wpml;
		if (isset($woocommerce_wpml->multi_currency) && is_object($woocommerce_wpml->multi_currency) && method_exists($woocommerce_wpml->multi_currency, 'get_exchange_rates')) {
			// Rates per currency code, relative to the default currency (which is 1)
			$wcml_rate = Cmi_Currency::rate_from_map((array) $woocommerce_wpml->multi_currency->get_exchange_rates(), $store_currency);
			if ($wcml_rate > 0) {
				return $wcml_rate;
			}
		}

		// 3. CURCY / VillaTheme — Multi Currency for WooCommerce
		if (class_exists('WOOMULTI_CURRENCY_F') || class_exists('WOOMULTI_CURRENCY')) {
			$curcy_rate = Cmi_Currency::rate_from_curcy_params((array) get_option('woo_multi_currency_params', array()), $store_currency);
			if ($curcy_rate > 0) {
				return $curcy_rate;
			}
		}

		// 4. Aelia Currency Switcher
		if (has_filter('wc_aelia_cs_convert')) {
			// Convert a large amount: if the result is rounded, the rate stays accurate
			$aelia_amount = 1000.0;
			$aelia_rate = Cmi_Currency::rate_from_conversion($aelia_amount, apply_filters('wc_aelia_cs_convert', $aelia_amount, $store_currency, 'MAD'), $store_currency);
			if ($aelia_rate > 0) {
				return $aelia_rate;
			}
		}

		return false;
	}

	/**
	 * Calculate the MAD amount CMI must charge for an order.
	 *
	 * @return array{amount: float, rate: float}|WP_Error Rate is MAD per 1 unit of the order currency.
	 */
	private function calculate_mad_amount(WC_Order $order)
	{
		$total = (float) $order->get_total();
		$currency = $order->get_currency();
		if ($currency === Cmi_Currency::MAD) {
			return array('amount' => round($total, 2), 'rate' => 1.0);
		}

		$rate = 0.0;
		if ($this->exchange_rates === 'manual') {
			foreach ((array) $this->currency_details as $row) {
				if (($row['currency_code'] ?? '') === $currency) {
					// The manual table holds units of the order currency per 1 MAD.
					$entered = Cmi_Currency::parse_rate($row['currency_rate'] ?? '');
					$rate = $entered > 0 ? 1 / $entered : 0.0;
					break;
				}
			}
		} elseif ($this->exchange_rates === 'plugin') {
			$plugin_rate = $this->get_plugin_exchange_rate($currency);
			$rate = $plugin_rate ? (float) $plugin_rate : 0.0;
		}

		if ($rate <= 0 || !is_finite($rate)) {
			wc_get_logger()->warning(
				sprintf('CMI Gateway: no valid exchange rate for %s → MAD (mode "%s"); payment refused for order #%d.', $currency, $this->exchange_rates, $order->get_id()),
				array('source' => 'cmi-payment-gateway')
			);
			return new WP_Error('cmi_no_exchange_rate', sprintf(__('Card payment in %s is temporarily unavailable. Please contact the store.', 'cmi'), $currency));
		}

		return array('amount' => Cmi_Currency::to_mad($total, $rate), 'rate' => $rate);
	}

	/**
	 * MAD amount and rate recorded when the order was paid.
	 *
	 * @return array{amount: float, rate: float}|null Null for a non-MAD order paid before
	 *         this information was recorded: its exact MAD amount is unknown.
	 */
	private function get_order_mad_details(WC_Order $order): ?array
	{
		$amount = (float) $order->get_meta(self::META_AMOUNT_MAD);
		$rate = (float) $order->get_meta(self::META_MAD_RATE);
		if ($amount > 0 && $rate > 0) {
			return array('amount' => $amount, 'rate' => $rate);
		}
		if ($order->get_currency() === Cmi_Currency::MAD) {
			return array('amount' => round((float) $order->get_total(), 2), 'rate' => 1.0);
		}
		return null;
	}

	/**
	 * MAD refund balance of an order, used by the refund modal and the CMI column.
	 *
	 * @param float|null $refunded_order Amount already refunded in the order currency (defaults to the order's refunds).
	 * @return array{total: float, refunded: float, remaining: float, rate: float}|null
	 */
	public function get_refund_balance_mad(WC_Order $order, ?float $refunded_order = null): ?array
	{
		$details = $this->get_order_mad_details($order);
		if ($details === null) {
			return null;
		}
		$refunded_meta = $order->get_meta(self::META_REFUNDED_MAD);
		$refunded = $refunded_meta !== ''
			? (float) $refunded_meta
			// Refunds made before MAD tracking: derive them from the WooCommerce refunds.
			: Cmi_Currency::to_mad($refunded_order ?? (float) $order->get_total_refunded(), $details['rate']);
		$refunded = min($details['amount'], $refunded);
		return array(
			'total'     => $details['amount'],
			'refunded'  => round($refunded, 2),
			'remaining' => round(max(0, $details['amount'] - $refunded), 2),
			'rate'      => $details['rate'],
		);
	}

	/**
	 * Total refunded on CMI for this order, in MAD, from ORDERHISTORY.
	 *
	 * @return float|null Null when CMI's history can't be read.
	 */
	private function get_cmi_refunded_mad(WC_Order $order): ?float
	{
		$history = $this->send_cmi_xml_api_request('ORDERHISTORY', $order);
		if (is_wp_error($history)) {
			return null;
		}
		$totals = Cmi_Transaction_Guard::history_totals($history['HISTORY'] ?? array());
		return $totals['captured'] > 0 ? round($totals['refunded'] / 100, 2) : null;
	}

	/**
	 * Record in WooCommerce the refunds made directly in the CMI back office, so the order's
	 * refundable balance matches CMI. Nothing is sent to CMI.
	 *
	 * @param string[] $history ORDERHISTORY lines of this order's (verified) transaction.
	 * @return float MAD amount newly recorded (0 when WooCommerce was already up to date).
	 */
	private function record_back_office_refunds(WC_Order $order, array $history): float
	{
		$totals = Cmi_Transaction_Guard::history_totals($history);
		$balance = $this->get_refund_balance_mad($order);
		if ($totals['captured'] <= 0 || $balance === null) {
			return 0.0;
		}
		$missing_mad = round(min($totals['refunded'] / 100, $balance['total']) - $balance['refunded'], 2);
		if ($missing_mad <= 0.009) {
			return 0.0;
		}

		$remaining_order = max(0, (float) $order->get_total() - (float) $order->get_total_refunded());
		$split = Cmi_Currency::refund_from_mad($missing_mad, $balance['remaining'], $remaining_order, $balance['rate']);
		$refund = wc_create_refund(array(
			'amount'         => $split['order'],
			'reason'         => __('Refund made in the CMI back office', 'cmi'),
			'order_id'       => $order->get_id(),
			'refund_payment' => false,
		));
		if (is_wp_error($refund)) {
			$order->add_order_note(sprintf(
				__('CMI shows %1$s MAD refunded in the CMI back office, but WooCommerce could not record it: %2$s', 'cmi'),
				number_format($missing_mad, 2),
				$refund->get_error_message()
			));
			return 0.0;
		}

		$order->update_meta_data(self::META_REFUNDED_MAD, number_format($balance['refunded'] + $missing_mad, 2, '.', ''));
		$order->save_meta_data();
		$order->add_order_note(sprintf(
			__('CMI Status Check: a refund of %s MAD made in the CMI back office was recorded in WooCommerce.', 'cmi'),
			number_format($missing_mad, 2)
		));
		return $missing_mad;
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

		// Never send an unconverted amount: CMI would charge it as MAD.
		$mad = $this->calculate_mad_amount($order);
		if (is_wp_error($mad)) {
			return '<p class="woocommerce-error">' . esc_html($mad->get_error_message()) . '</p>';
		}

		if (!in_array($this->get_order_payment_status($order), array(self::PAYMENT_STATUS_PRE_CONFIRMATION_REQUIRED, self::PAYMENT_STATUS_CONFIRMED), true)) {
			$this->set_order_payment_status($order, self::PAYMENT_STATUS_INITIATED);
		}
		$totalAmountTx = $order->get_total();
		$lang = $this->get_payment_language();

		$customer_cancel_url = add_query_arg(array(
			'order_id' => $order->get_id(),
			'key' => $order->get_order_key(),
		), $this->cancel_url);
		$cmi_oid = $this->get_cmi_oid_for_order($order);
		$order->update_meta_data('_cmi_oid', $cmi_oid);
		// Freeze the MAD amount and rate of this attempt: refunds are calculated from them.
		$order->update_meta_data(self::META_AMOUNT_MAD, number_format($mad['amount'], 2, '.', ''));
		$order->update_meta_data(self::META_MAD_RATE, (string) $mad['rate']);
		// First time this order was sent: CMI transactions older than this are not ours.
		if (!$order->get_meta(self::META_SENT_AT)) {
			$order->update_meta_data(self::META_SENT_AT, (string) time());
		}
		$order->save_meta_data();
		$this->schedule_pending_check($order);
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
			'AutoRedirect' => $this->get_option('auto_redirect', 'yes') === 'no' ? 'false' : 'true',
			'encoding' => "UTF-8",
		);
		// CMI calls this after each payment. The answer decides the capture: ACTION=POSTAUTH in
		// automatic confirmation, APPROVED in manual confirmation (stays pre-authorized).
		$data['callbackUrl'] = $this->response_url;
		$order_currency = $order->get_currency();
		if ($order_currency !== Cmi_Currency::MAD) {
			$data['amount'] = number_format($mad['amount'], 2, '.', '');
			$data['amountCur'] = $totalAmountTx;
			$data['symbolCur'] = $order_currency;
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
					message: "' . esc_js(__('Thank you for your order. You will be redirected to CMI to make the payment.', 'cmi')) . '",
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
				});						
			// CMI checks the signature against every field it receives. Other plugins add hidden
			// fields to all forms (CleanTalk, TranslatePress...): remove every field this plugin
			// did not sign, then send the form without firing "submit", so no field is added back.
			var cmiForm = document.getElementById("cmi_payment_form");
			var cmiSignedFields = ' . wp_json_encode(array_keys($data)) . ';
			var cmiSendForm = function () {
				Array.prototype.slice.call(cmiForm.elements).forEach(function (field) {
					if (field.name && cmiSignedFields.indexOf(field.name) === -1) {
						field.parentNode.removeChild(field);
					}
				});
				HTMLFormElement.prototype.submit.call(cmiForm);
			};
			// Fallback button: let other submit handlers run first, then clean and send
			cmiForm.addEventListener("submit", function (event) {
				event.preventDefault();
				setTimeout(cmiSendForm, 0);
			});
			cmiSendForm();
		');
		return '<form action="' . esc_url($this->actionslk) . '" method="post" id="cmi_payment_form" target="_top">
				' . implode('', $cmi_args_array) . '
				<!-- Button Fallback -->
				<div class="payment_buttons">
					<input type="submit" class="button alt" id="submit_cmi_payment_form" value="' . esc_attr__('Pay via CMI', 'cmi') . '" /> <a class="button cancel" href="' . esc_url($customer_cancel_url) . '">' . esc_html__('Cancel order', 'cmi') . '</a>
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

		// CMI receives the name in Latin letters only (accents removed, like CMI's own PHP example).
		// A name with no Latin letter at all, such as one typed in Arabic, would reach CMI empty.
		if (trim($this->str_without_accents($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()), " -_") === '') {
			wc_add_notice(__('To pay by card with CMI, please enter your first and last name in Latin letters (for example: Mohamed Alami).', 'cmi'), 'error');
			return array('result' => 'failure');
		}

		$mad = $this->calculate_mad_amount($order);
		if (is_wp_error($mad)) {
			wc_add_notice($mad->get_error_message(), 'error');
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

		// Assign the CMI identifier now, so the payment page already shows the final order number.
		$order->update_meta_data('_cmi_oid', $this->get_cmi_oid_for_order($order));
		$order->save_meta_data();

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
		echo '<p>' . esc_html__('Thank you for your order. Please click the button below to pay with CMI.', 'cmi') . '</p>';

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
			return new WP_Error('cmi_invalid_callback', __('CMI callback does not identify a valid order.', 'cmi'));
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
			return new WP_Error('cmi_invalid_signature', __('CMI callback signature is invalid.', 'cmi'));
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

	/**
	 * Whether the API can be used: the Complete Kit is chosen and its credentials are set.
	 * Everything that calls the CMI API (actions, Check CMI, automatic checks) follows this,
	 * so a Standard Kit shop never calls it, and a Complete Kit without credentials works
	 * like the Standard Kit instead of offering actions that would fail.
	 */
	private function has_api_configuration(): bool
	{
		return $this->get_option('kit_type', 'standard') === 'complete' && $this->has_api_credentials();
	}

	private function has_api_credentials(): bool
	{
		return $this->apigateway !== '' && $this->usernameapi !== '' && $this->passwordapi !== '';
	}

	/** True when the CMI actions (column, menus, buttons) can be offered. */
	public static function api_actions_enabled(): bool
	{
		return self::get_gateway_instance()->has_api_configuration();
	}

	private function process_callback(WC_Order $order, array $data, bool $is_server_callback = true): string
	{
		$transaction = $this->transaction_data($data);
		if ($this->get_order_payment_status($order) === self::PAYMENT_STATUS_CONFIRMED || $order->is_paid()) {
			return 'APPROVED';
		}
		$current_status = $this->get_order_payment_status($order);
		if (!Cmi_Payment_State::accepts_callback($current_status, $this->is_approved_callback($data))) {
			// Late or replayed callback (e.g. an earlier declined attempt): never downgrade the current state.
			$order->add_order_note(sprintf(
				__('CMI callback ignored: it reports %1$s (ProcReturnCode: %2$s) but the CMI payment is already %3$s.', 'cmi'),
				$this->is_approved_callback($data) ? __('an approval', 'cmi') : __('a failure', 'cmi'),
				$transaction['ProcReturnCode'] ?? __('N/A', 'cmi'),
				$this->payment_status_label($current_status)
			));
			return 'APPROVED';
		}
		if ($this->is_approved_callback($data)) {
			$this->remember_own_transaction($order, $transaction);
		}
		if (!$this->is_approved_callback($data)) {
			$this->set_order_payment_status($order, self::PAYMENT_STATUS_FAILED, $transaction);
			$order->update_status('failed', sprintf(__('CMI payment failed: %s (ProcReturnCode: %s)', 'cmi'), $transaction['ErrMsg'] ?? __('Transaction declined.', 'cmi'), $data['ProcReturnCode'] ?? __('N/A', 'cmi')));
			return 'APPROVED';
		}

		if (Cmi_Payment_State::from_callback((string) $data['ProcReturnCode'], $this->confirmation_mode) === self::PAYMENT_STATUS_PRE_CONFIRMATION_REQUIRED || !$is_server_callback) {
			$this->set_order_payment_status($order, self::PAYMENT_STATUS_PRE_CONFIRMATION_REQUIRED, $transaction);
			if ($order->get_status() !== 'on-hold') {
				$order->update_status('on-hold', __('CMI pre-authorization received; manual confirmation is required.', 'cmi'));
			}
			return 'APPROVED';
		}

		// Automatic confirmation: CMI captures the payment itself when the callback answers
		// ACTION=POSTAUTH, with or without the API configured.
		$target_status = $this->get_postauth_order_status();
		$this->set_order_payment_status($order, self::PAYMENT_STATUS_CONFIRMED, $transaction);
		$order->payment_complete($transaction['TransId'] ?? '');
		$order->update_status($target_status, sprintf(__('CMI payment confirmed and captured (status set to %s).', 'cmi'), $target_status));
		return 'ACTION=POSTAUTH';
	}

	/**
	 * Record the identifiers of this order's own CMI transactions: from a signed approved
	 * callback, or from a response to an operation already verified as ours. Each CMI
	 * operation gets a new TransId, so all of them are kept.
	 */
	private function remember_own_transaction(WC_Order $order, array $transaction): void
	{
		$known = $this->get_own_transaction_ids($order);
		$ids = array_unique(array_filter(array_merge($known, array(
			(string) ($transaction['TransId'] ?? ''),
			(string) ($transaction['HostRefNum'] ?? ''),
		))));
		if (array_diff($ids, $known)) {
			$order->update_meta_data(self::META_OWN_IDS, array_values($ids));
			$order->save_meta_data();
		}
	}

	/**
	 * Days since the pre-authorization according to CMI's back office (AUTH_DTTM) if the
	 * merchant should be warned before confirming, else 0. Advisory only: when CMI can't
	 * tell, no warning is shown and confirm_transaction() runs its own checks.
	 */
	private function get_cmi_preauth_warning_days(WC_Order $order): int
	{
		if (!$this->has_api_configuration()) {
			return 0;
		}
		$status = $this->query_order_status($order);
		if (is_wp_error($status) || strtoupper(trim((string) ($status['TRANS_STAT'] ?? ''))) !== 'A') {
			return 0;
		}
		$preauth_at = Cmi_Transaction_Guard::parse_cmi_datetime((string) ($status['AUTH_DTTM'] ?? ''));
		return $preauth_at !== null ? Cmi_Payment_State::preauth_warning_days($preauth_at, time()) : 0;
	}

	/** Called when the merchant clicks Confirm, before the confirmation itself. */
	public static function ajax_preauth_age(): void
	{
		$order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
		if (!current_user_can('manage_woocommerce') || !check_ajax_referer('cmi_admin_order_action_' . $order_id, '_wpnonce', false)) {
			wp_send_json_error(null, 403);
		}
		$order = $order_id ? wc_get_order($order_id) : false;
		if (!$order instanceof WC_Order) {
			wp_send_json_error(null, 404);
		}
		wp_send_json_success(array(
			'days'         => self::get_gateway_instance()->get_cmi_preauth_warning_days($order),
			'order_number' => $order->get_order_number(),
		));
	}

	/**
	 * Identifiers known to belong to this order. Also reads what earlier versions of the
	 * plugin stored, so orders already confirmed are recognised.
	 *
	 * @return string[]
	 */
	private function get_own_transaction_ids(WC_Order $order): array
	{
		$stored = $order->get_meta(self::META_OWN_IDS);
		$last_transaction = $order->get_meta('_cmi_transaction_data');
		return array_values(array_unique(array_filter(array_map('strval', array_merge(
			is_array($stored) ? $stored : array(),
			array(
				$order->get_transaction_id(),
				is_array($last_transaction) ? ($last_transaction['TransId'] ?? '') : '',
				is_array($last_transaction) ? ($last_transaction['HostRefNum'] ?? '') : '',
			)
		)))));
	}

	function check_CMI_response()
	{
		$started = microtime(true);
		$data = $this->get_request_data();
		wc_get_logger()->info('CMI server callback received: oid ' . sanitize_text_field((string) ($data['oid'] ?? '')) . ', code ' . sanitize_text_field((string) ($data['ProcReturnCode'] ?? '')), array('source' => 'cmi-api'));
		try {
			$order = $this->validate_callback($data);
			if (is_wp_error($order)) {
				$this->discard_output();
				status_header(400);
				echo 'INVALID HASH';
				exit;
			}
			$answer = $this->process_callback($order, $data);
		} catch (Throwable $e) {
			wc_get_logger()->error(sprintf('CMI server callback failed: %s in %s on line %d', $e->getMessage(), $e->getFile(), $e->getLine()), array('source' => 'cmi-api'));
			$this->discard_output();
			status_header(500);
			exit;
		}
		wc_get_logger()->info(sprintf('CMI server callback answered %s after %.1f seconds.', $answer, microtime(true) - $started), array('source' => 'cmi-api'));
		$this->discard_output();
		echo $answer;
		exit;
	}

	function handle_payment_return()
	{
		$data = $this->get_request_data();
		wc_get_logger()->info('CMI browser return received: oid ' . sanitize_text_field((string) ($data['oid'] ?? '')) . ', code ' . sanitize_text_field((string) ($data['ProcReturnCode'] ?? '')), array('source' => 'cmi-api'));
		$order = null;
		try {
			$order = $this->validate_callback($data);
			if (is_wp_error($order)) {
				wp_die(esc_html($order->get_error_message()), esc_html__('CMI payment result', 'cmi'), array('response' => 400));
			}
			$this->process_callback($order, $data, false);
		} catch (Throwable $e) {
			wc_get_logger()->error(sprintf('CMI browser return failed: %s in %s on line %d', $e->getMessage(), $e->getFile(), $e->getLine()), array('source' => 'cmi-api'));
			// The result page still shows the saved state; the server callback updates the order.
			if (!$order instanceof WC_Order) {
				wp_die(esc_html__('Your payment result could not be displayed. Please check your order or contact the store.', 'cmi'), esc_html__('CMI payment result', 'cmi'), array('response' => 500));
			}
		}
		$url = $this->get_return_url($order);
		wc_get_logger()->info('CMI browser return: redirecting the customer to ' . $url, array('source' => 'cmi-api'));
		$this->redirect_customer($url);
	}

	function handle_customer_cancellation()
	{
		$order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
		$key = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
		$order = $order_id ? wc_get_order($order_id) : false;
		if (!$order || !hash_equals($order->get_order_key(), $key)) {
			wp_die(esc_html__('Invalid CMI cancellation request.', 'cmi'), '', array('response' => 400));
		}
		if (!$order->is_paid()) {
			// The Cancel button stays usable after paying, e.g. when the customer goes back to
			// CMI's page. It must never undo a payment: only the merchant can void or refund.
			$check_status = '';
			$can_cancel = true;
			if ($this->has_api_configuration() && (string) $order->get_meta('_cmi_oid') !== '') {
				$check = $this->sync_order_with_cmi_api($order);
				$check_status = (string) ($check['status'] ?? '');
				$can_cancel = Cmi_Payment_State::customer_cancel_allowed($check_status);
			}
			if ($can_cancel && Cmi_Payment_State::can_confirm($this->get_order_payment_status($order))) {
				$can_cancel = false;
			}

			if (!$can_cancel) {
				$order->add_order_note(sprintf(
					__('The customer clicked Cancel on the CMI payment page, but this order has a payment on CMI or its status could not be checked (%s). The order was not cancelled. If the customer wants to cancel, void or refund the payment yourself.', 'cmi'),
					$check_status !== '' ? $check_status : $this->payment_status_label($this->get_order_payment_status($order))
				));
			} elseif (!$order->has_status('cancelled')) {
				$this->set_order_payment_status($order, self::PAYMENT_STATUS_CLIENT_CANCELLED);
				$order->update_status('cancelled', __('Payment was cancelled by the customer on the CMI payment page.', 'cmi'));
			}
		}
		$this->redirect_customer($this->get_return_url($order));
	}

	/**
	 * Send the customer to the shop's result page, and never leave them on a blank page:
	 * stray output (a PHP notice, a space in another plugin's file) would block the redirect.
	 */
	private function redirect_customer(string $url): void
	{
		$this->discard_output();
		if (!headers_sent($file, $line)) {
			wp_safe_redirect($url);
			exit;
		}
		wc_get_logger()->warning(sprintf('CMI redirect blocked: output had already started in %s on line %d.', $file, $line), array('source' => 'cmi-api'));
		echo '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . esc_url($url) . '"><p><a href="' . esc_url($url) . '">' . esc_html__('Continue to your order', 'cmi') . '</a></p>';
		exit;
	}

	/**
	 * Manual mode has no CMI callback: if the customer doesn't return to the shop, the order
	 * stays pending while the money is reserved. Schedule status checks to catch that. Each
	 * new payment attempt restarts them. A scheduling problem must never block the payment.
	 */
	private function schedule_pending_check(WC_Order $order): void
	{
		if ($this->confirmation_mode !== 'manual' || !$this->has_api_configuration() || !function_exists('as_schedule_single_action')) {
			return;
		}
		try {
			$args = array($order->get_id());
			as_unschedule_action(self::PENDING_CHECK_HOOK, $args, 'cmi');
			$order->update_meta_data(self::META_PENDING_CHECKS, '0');
			$order->save_meta_data();
			as_schedule_single_action(time() + (int) Cmi_Payment_State::pending_check_delay(0), self::PENDING_CHECK_HOOK, $args, 'cmi');
		} catch (Throwable $e) {
			wc_get_logger()->error('CMI could not schedule the pending order check: ' . $e->getMessage(), array('source' => 'cmi-api', 'order_id' => $order->get_id()));
		}
	}

	/** Action Scheduler entry point. */
	public static function run_pending_check($order_id): void
	{
		$order = wc_get_order($order_id);
		if ($order instanceof WC_Order) {
			self::get_gateway_instance()->check_pending_order($order);
		}
	}

	/**
	 * Bring an order left pending in line with CMI. Read-only towards CMI: this only ever
	 * sends a status query, and never confirms, voids or refunds anything.
	 */
	public function check_pending_order(WC_Order $order): void
	{
		if ($order->get_payment_method() !== $this->id || !$order->has_status('pending') || $this->confirmation_mode !== 'manual' || !$this->has_api_configuration() || (string) $order->get_meta('_cmi_oid') === '') {
			return;
		}

		$status = $this->query_order_status($order);
		if (!is_wp_error($status) && Cmi_Payment_State::pending_check_applies((string) ($status['TRANS_STAT'] ?? ''))) {
			$result = $this->sync_order_with_cmi_api($order, $status);
			// Another site's transaction won't become ours: don't add the same note again.
			if (!$order->has_status('pending') || ($result['status'] ?? '') === 'FOREIGN') {
				return;
			}
		}

		$checks_done = (int) $order->get_meta(self::META_PENDING_CHECKS) + 1;
		$order->update_meta_data(self::META_PENDING_CHECKS, (string) $checks_done);
		$order->save_meta_data();
		$delay = Cmi_Payment_State::pending_check_delay($checks_done);
		if ($delay !== null && function_exists('as_schedule_single_action')) {
			as_schedule_single_action(time() + $delay, self::PENDING_CHECK_HOOK, array($order->get_id()), 'cmi');
		}
	}

	/** Remove buffered stray output, so a response contains only what the plugin writes. */
	private function discard_output(): void
	{
		while (ob_get_level() > 0 && @ob_end_clean()) {
			continue;
		}
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
		add_action('wp_ajax_cmi_preauth_age', array(__CLASS__, 'ajax_preauth_age'));
		add_action(self::PENDING_CHECK_HOOK, array(__CLASS__, 'run_pending_check'));

		// Notices & CSS & Modal
		add_action('admin_notices', array(__CLASS__, 'display_admin_order_action_notices'));
		add_action('admin_notices', array(__CLASS__, 'display_kit_credentials_notice'));
		add_action('admin_head', array(__CLASS__, 'render_admin_order_action_css'));
		add_action('admin_footer', array(__CLASS__, 'render_admin_refund_modal'));
		add_action('admin_init', array(__CLASS__, 'repair_orphaned_custom_status_orders'));

		// MAD amount actually charged by CMI, shown under the order totals
		add_action('woocommerce_admin_order_totals_after_total', array(__CLASS__, 'render_order_mad_total'));

		// CMI order ID: searchable in the orders list (HPOS & CPT)
		add_filter('woocommerce_shop_order_search_fields', array(__CLASS__, 'add_cmi_oid_search_field'));
		add_filter('woocommerce_order_table_search_query_meta_keys', array(__CLASS__, 'add_cmi_oid_search_field'));
	}

	/** Let merchants search the orders list by the ID shown in the CMI back office. */
	public static function add_cmi_oid_search_field($fields): array
	{
		$fields = (array) $fields;
		$fields[] = '_cmi_oid';
		return $fields;
	}

	/**
	 * Show the MAD amount charged by CMI (and the frozen rate) for orders in another currency.
	 */
	public static function render_order_mad_total($order_id): void
	{
		$order = wc_get_order($order_id);
		if (!$order || $order->get_payment_method() !== 'cmi' || $order->get_currency() === Cmi_Currency::MAD) {
			return;
		}
		$balance = self::get_gateway_instance()->get_refund_balance_mad($order);
		if ($balance === null) {
			return;
		}
		?>
		<tr>
			<td class="label"><?php esc_html_e('Charged by CMI:', 'cmi'); ?></td>
			<td width="1%"></td>
			<td class="total">
				<?php echo esc_html(number_format($balance['total'], 2) . ' MAD'); ?>
				<br><small><?php echo esc_html(sprintf('1 %s = %s MAD', $order->get_currency(), wc_format_decimal($balance['rate'], 4))); ?></small>
				<?php if ($balance['refunded'] > 0) : ?>
					<br><small><?php echo esc_html(sprintf(__('Refunded via CMI: %s MAD', 'cmi'), number_format($balance['refunded'], 2))); ?></small>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	public static function add_cmi_order_columns(array $columns): array
	{
		// Complete Kit with its API credentials only: without them the actions would fail.
		if (!self::api_actions_enabled()) {
			return $columns;
		}

		$new_columns = array();
		foreach ($columns as $key => $name) {
			$new_columns[$key] = $name;
			if ($key === 'order_status' || $key === 'status') {
				$new_columns['cmi_actions'] = __('CMI Actions', 'cmi');
			}
		}
		if (!isset($new_columns['cmi_actions'])) {
			$new_columns['cmi_actions'] = __('CMI Actions', 'cmi');
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
			? '<a href="' . esc_url($check_url) . '" class="button button-small" style="background:#2271b1; color:#fff; border-color:#135e96; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:3px;" title="' . esc_attr__('Query payment status from CMI XML API', 'cmi') . '">&#8635; ' . esc_html__('Check CMI', 'cmi') . '</a>'
			: '';

		$custom_target = $gateway->get_postauth_order_status();

		echo '<div class="cmi-column-actions" style="display:flex; gap:6px; align-items:center; flex-wrap:wrap; padding:2px 0;">';

		// 1. PRE Transactions (Pre-authorized -> can be Confirmed or Voided)
		if (self::can_confirm_order($order)) {
			$confirm_url = wp_nonce_url(admin_url('admin-post.php?action=cmi_admin_confirm_order&order_id=' . $order_id), 'cmi_admin_order_action_' . $order_id);
			$cancel_url = wp_nonce_url(admin_url('admin-post.php?action=cmi_admin_cancel_order&order_id=' . $order_id), 'cmi_admin_order_action_' . $order_id);

			echo '<a href="' . esc_url($confirm_url) . '" class="button button-small cmi-confirm-btn" style="background:#008a20; color:#fff; border-color:#007017; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:3px;" title="' . esc_attr__('Confirm and capture this PRE transaction via CMI API (PostAuth)', 'cmi') . '">&#10003; ' . esc_html__('Confirm', 'cmi') . '</a>';
			echo '<a href="' . esc_url($cancel_url) . '" class="button button-small cmi-void-btn" data-order-id="' . esc_attr($order_id) . '" data-url="' . esc_url($cancel_url) . '" style="background:#d63638; color:#fff; border-color:#b32d2e; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:3px;" onclick="return cmiOpenVoidModal(this, event);" title="' . esc_attr__('Void and cancel this PRE transaction via CMI API (Void)', 'cmi') . '">&#10005; ' . esc_html__('Void', 'cmi') . '</a>';
			if ($check_btn) {
				echo $check_btn;
			}
		}
		// 2. POST Transactions (Confirmed / Captured / Paid / Partially Refunded)
		elseif (Cmi_Payment_State::is_captured($status, $order_status, $order->is_paid(), $custom_target)) {
			// Refunds are made in MAD, the currency CMI charged.
			$balance = $gateway->get_refund_balance_mad($order);
			$total_order = $balance['total'] ?? 0.0;
			$total_refunded = $balance['refunded'] ?? 0.0;
			$refundable = $balance['remaining'] ?? 0.0;

			if ($order->get_currency() !== Cmi_Currency::MAD && $balance !== null) {
				echo '<span style="font-size:11px; color:#50575e; line-height:1.3;">' . esc_html(sprintf('%s %s ≈ %s MAD', number_format((float) $order->get_total(), 2), $order->get_currency(), number_format($total_order, 2))) . '</span>';
			}

			if ($balance === null) {
				echo '<span style="color:#777; font-size:12px; font-weight:600;" title="' . esc_attr__('The MAD amount charged by CMI was not recorded for this older order.', 'cmi') . '">' . esc_html__('Refund from CMI back office', 'cmi') . '</span>';
			} elseif ($refundable > 0.001) {
				$refund_url = wp_nonce_url(admin_url('admin-post.php?action=cmi_admin_refund_order&order_id=' . $order_id), 'cmi_admin_order_action_' . $order_id);
				$btn_label = $total_refunded > 0.001
					? sprintf(__('Refund (%s MAD)', 'cmi'), number_format($refundable, 2))
					: __('Refund', 'cmi');

				if ($total_refunded > 0.001) {
					echo '<div style="display:flex; flex-direction:column; gap:4px; align-items:flex-start;">';
					echo '<span style="font-size:11px; color:#b5831e; font-weight:600; line-height:1.2;">' . sprintf(__('Refunded: %s MAD', 'cmi'), number_format($total_refunded, 2)) . '</span>';
					echo '<a href="' . esc_url($refund_url) . '" class="button button-small cmi-refund-btn" data-order-id="' . esc_attr($order_id) . '" data-total="' . esc_attr(number_format($total_order, 2, '.', '')) . '" data-refunded="' . esc_attr(number_format($total_refunded, 2, '.', '')) . '" data-refundable="' . esc_attr(number_format($refundable, 2, '.', '')) . '" style="background:#f0b849; color:#000; border-color:#d49b28; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:3px;" onclick="return cmiOpenRefundModal(this, event);" title="' . sprintf(esc_attr__('Refund remaining balance (%s MAD) via CMI API (Credit)', 'cmi'), number_format($refundable, 2)) . '">&#8634; ' . esc_html($btn_label) . '</a>';
					echo '</div>';
				} else {
					echo '<a href="' . esc_url($refund_url) . '" class="button button-small cmi-refund-btn" data-order-id="' . esc_attr($order_id) . '" data-total="' . esc_attr(number_format($total_order, 2, '.', '')) . '" data-refunded="0.00" data-refundable="' . esc_attr(number_format($refundable, 2, '.', '')) . '" style="background:#f0b849; color:#000; border-color:#d49b28; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:3px;" onclick="return cmiOpenRefundModal(this, event);" title="' . esc_attr__('Refund this transaction (Full or Partial) via CMI API (Credit)', 'cmi') . '">&#8634; ' . esc_html__('Refund', 'cmi') . '</a>';
				}
			} else {
				echo '<span style="color:#777; font-size:12px; font-weight:600;">' . sprintf(esc_html__('Refunded (%s MAD)', 'cmi'), number_format($total_order, 2)) . '</span>';
			}
			// Refunds can also be made in the CMI back office: Check CMI brings them into WooCommerce
			if ($check_btn) {
				echo $check_btn;
			}
		}
		// 3. Pending / Initiated
		elseif ($order_status === 'pending' || $status === self::PAYMENT_STATUS_INITIATED) {
			if ($check_btn) {
				echo $check_btn;
			} else {
				echo '<span style="color:#e67e22; font-size:12px; font-weight:600;">' . esc_html__('Pending', 'cmi') . '</span>';
			}
		}
		// 4. Voided via API (admin voided a PRE transaction)
		elseif ($status === self::PAYMENT_STATUS_VOIDED) {
			echo '<span style="color:#7b2cf5; font-size:12px; font-weight:600;">' . esc_html__('Voided', 'cmi') . '</span>';
			if ($check_btn) {
				echo $check_btn;
			}
		}
		// 5. Client cancelled (user cancelled on payment page)
		elseif ($status === self::PAYMENT_STATUS_CLIENT_CANCELLED || $order_status === 'cancelled') {
			echo '<span style="color:#888; font-size:12px; font-weight:600;">' . esc_html__('Cancelled', 'cmi') . '</span>';
			if ($check_btn) {
				echo $check_btn;
			}
		}
		// 6. Failed
		elseif ($status === self::PAYMENT_STATUS_FAILED || $order_status === 'failed') {
			echo '<span style="color:#d63638; font-size:12px; font-weight:600;">' . esc_html__('Failed', 'cmi') . '</span>';
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
			'processing' => __('Processing', 'cmi'),
			'confirmed'  => __('Confirmed', 'cmi'),
			'on-hold'    => __('On-hold', 'cmi'),
			'completed'  => __('Completed', 'cmi'),
			'custom'     => __('Custom (specify below)', 'cmi'),
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
			return _x('Confirmed', 'Order status', 'cmi');
		}
		return ucwords(str_replace(array('-', '_'), ' ', $clean_slug));
	}

	public function validate_postauth_custom_status_field(string $key, $value): string
	{
		return self::sanitize_custom_status_slug((string) $value);
	}

	/** Refuse a Store Key containing "SKS" (CMI rule): keep the previous key and explain why. */
	public function validate_store_key_field(string $key, $value): string
	{
		$store_key = $this->validate_password_field($key, $value);
		if (Cmi_Transaction_Guard::is_valid_store_key($store_key)) {
			return $store_key;
		}
		if (class_exists('WC_Admin_Settings')) {
			WC_Admin_Settings::add_error(__('CMI: the Store Key was not saved because it must not contain the word "SKS". The previous key was kept.', 'cmi'));
		}
		return (string) $this->get_option($key, '');
	}

	/**
	 * The Complete Kit needs the API credentials. When one is missing, keep the Standard Kit
	 * and ask the merchant to enter them, instead of saving a Complete Kit whose actions fail.
	 */
	public function validate_kit_type_field(string $key, $value): string
	{
		$kit = $this->validate_select_field($key, $value);
		if ($kit !== 'complete') {
			return 'standard';
		}
		$posted = $this->get_post_data();
		foreach (array('api_gateway_url', 'api_username', 'api_password') as $field) {
			if (trim((string) wp_unslash($posted[$this->get_field_key($field)] ?? '')) === '') {
				if (class_exists('WC_Admin_Settings')) {
					WC_Admin_Settings::add_error(__('CMI: the Complete Kit needs the API credentials provided by CMI (API URL, API username and API password). Enter them to use the Complete Kit, or use the Standard Kit. The Standard Kit was kept.', 'cmi'));
				}
				return 'standard';
			}
		}
		return 'complete';
	}

	/** Warn on the settings page when a Complete Kit saved earlier has no API credentials. */
	public static function display_kit_credentials_notice(): void
	{
		if (($_GET['page'] ?? '') !== 'wc-settings' || ($_GET['section'] ?? '') !== 'cmi' || !current_user_can('manage_woocommerce')) {
			return;
		}
		$gateway = self::get_gateway_instance();
		if ($gateway->get_option('kit_type', 'standard') !== 'complete' || $gateway->has_api_credentials()) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__('CMI: the Complete Kit is selected but the API credentials are missing, so the plugin works like the Standard Kit. Enter the API URL, API username and API password provided by CMI, or choose the Standard Kit.', 'cmi') . '</p></div>';
	}

	public function validate_order_id_prefix_field(string $key, $value): string
	{
		return Cmi_Transaction_Guard::sanitize_oid_prefix((string) $value);
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
			'label'                     => _x('Confirmed', 'Order status', 'cmi'),
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop('Confirmed <span class="count">(%s)</span>', 'Confirmed <span class="count">(%s)</span>', 'cmi'),
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
			'label'                     => _x('Confirmed', 'Order status', 'cmi'),
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop('Confirmed <span class="count">(%s)</span>', 'Confirmed <span class="count">(%s)</span>', 'cmi'),
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
				$new_order_statuses['wc-confirmed'] = _x('Confirmed', 'Order status', 'cmi');
			}
		}
		if (!isset($new_order_statuses['wc-confirmed'])) {
			$new_order_statuses['wc-confirmed'] = _x('Confirmed', 'Order status', 'cmi');
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
		// One-time migration: slugs are now sanitized on save, so new orders can no
		// longer get a truncated status. Run once instead of on every admin request.
		if (get_option(self::STATUS_REPAIR_OPTION) || !current_user_can('manage_woocommerce')) {
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

		update_option(self::STATUS_REPAIR_OPTION, '1', false);
	}

	public static function can_confirm_order(WC_Order $order): bool
	{
		if ($order->get_payment_method() !== 'cmi') {
			return false;
		}
		return Cmi_Payment_State::can_offer_confirmation(
			(string) $order->get_meta(self::PAYMENT_STATUS_META_KEY, true),
			$order->get_status(),
			self::get_gateway_instance()->get_postauth_order_status()
		);
	}

	public static function add_order_actions(array $actions, WC_Order $order): array
	{
		if (!self::api_actions_enabled()) {
			return $actions;
		}
		if ($order->get_payment_method() === 'cmi') {
			$custom_target = self::get_gateway_instance()->get_postauth_order_status();
			$actions['cmi_check_status'] = __('Query status via CMI API', 'cmi');
			if (self::can_confirm_order($order)) {
				$actions['cmi_confirm_transaction'] = __('Confirm CMI PRE transaction', 'cmi');
				$actions['cmi_cancel_transaction'] = __('Void CMI PRE transaction', 'cmi');
			} elseif (
				Cmi_Payment_State::is_captured((string) $order->get_meta(self::PAYMENT_STATUS_META_KEY, true), $order->get_status(), $order->is_paid(), $custom_target)
				&& ((float) $order->get_total() - (float) $order->get_total_refunded()) > 0.001
			) {
				// Only a captured payment with an amount left to refund: never Pending, Failed or On hold orders.
				$actions['cmi_refund_transaction'] = __('Refund via CMI API', 'cmi');
			}
		}
		return $actions;
	}

	public static function add_admin_order_list_actions(array $actions, WC_Order $order): array
	{
		if (!self::api_actions_enabled()) {
			return $actions;
		}
		if (self::can_confirm_order($order)) {
			$order_id = $order->get_id();
			$actions['cmi_confirm'] = array(
				'url'    => wp_nonce_url(admin_url('admin-post.php?action=cmi_admin_confirm_order&order_id=' . $order_id), 'cmi_admin_order_action_' . $order_id),
				'name'   => __('Confirm CMI transaction', 'cmi'),
				'action' => 'cmi_confirm',
			);
			$actions['cmi_cancel'] = array(
				'url'    => wp_nonce_url(admin_url('admin-post.php?action=cmi_admin_cancel_order&order_id=' . $order_id), 'cmi_admin_order_action_' . $order_id),
				'name'   => __('Void CMI transaction', 'cmi'),
				'action' => 'cmi_cancel',
			);
		}
		return $actions;
	}

	public static function render_admin_order_action_css(): void
	{
		// Only the order list and order edit screens show CMI actions and statuses.
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || !in_array($screen->id, array('woocommerce_page_wc-orders', 'edit-shop_order', 'shop_order'), true)) {
			return;
		}
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
		// Order being edited (HPOS: id, legacy: post), for the warning in the order actions dropdown
		$edit_order_id = absint($_GET['id'] ?? ($_GET['post'] ?? 0));
		$edit_order = $edit_order_id ? wc_get_order($edit_order_id) : false;
		$edit_order_can_confirm = $edit_order instanceof WC_Order && self::can_confirm_order($edit_order);
		?>
		<!-- CMI Action Modals Backdrop -->
		<div id="cmi-action-modal-backdrop" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.55); z-index:99998; backdrop-filter:blur(2px);" onclick="cmiCloseAllModals()"></div>

		<!-- 1. CMI Void / Cancellation Modal Box -->
		<div id="cmi-void-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); width:480px; max-width:92%; background:#fff; border-radius:8px; box-shadow:0 10px 35px rgba(0,0,0,0.25); z-index:99999; overflow:hidden; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,'Helvetica Neue',sans-serif; border:1px solid #ccd0d4;">
			<!-- Header -->
			<div style="background:#fcf0f1; padding:14px 18px; border-bottom:1px solid #f1c5c5; display:flex; justify-content:space-between; align-items:center;">
				<h3 style="margin:0; font-size:15px; font-weight:600; color:#b32d2e; display:flex; align-items:center; gap:6px;">
					<span style="color:#d63638; font-size:18px;">&#10005;</span>
					<span><?php esc_html_e('CMI cancellation (Void)', 'cmi'); ?> &mdash; <?php esc_html_e('Order', 'cmi'); ?> #<span id="cmi-void-modal-order-id"></span></span>
				</h3>
				<button type="button" onclick="cmiCloseVoidModal()" style="background:none; border:none; font-size:22px; line-height:1; cursor:pointer; color:#646970; padding:0 4px;" title="<?php esc_attr_e('Close', 'cmi'); ?>">&times;</button>
			</div>

			<!-- Body -->
			<div style="padding:18px 20px;">
				<div style="background:#fcf0f1; border:1px solid #f5c6cb; border-left:4px solid #d63638; padding:12px 14px; margin-bottom:16px; border-radius:4px; font-size:13px; color:#721c24; display:flex; gap:10px; align-items:flex-start;">
					<span class="dashicons dashicons-warning" style="font-size:20px; width:20px; height:20px; color:#d63638; flex-shrink:0; margin-top:2px;"></span>
					<div>
						<strong style="display:block; margin-bottom:4px; font-size:13px; color:#b32d2e;"><?php esc_html_e('⚠️ Warning: this action cannot be undone!', 'cmi'); ?></strong>
						<ul style="margin:0 0 0 16px; padding:0; list-style-type:disc; font-size:12px; line-height:1.5;">
							<li><?php esc_html_e('The CMI payment authorization will be permanently cancelled with the customer\'s bank.', 'cmi'); ?></li>
							<li><?php esc_html_e('The funds held on the customer\'s card will be released immediately.', 'cmi'); ?></li>
							<li><?php esc_html_e('This order can never be confirmed or charged later.', 'cmi'); ?></li>
						</ul>
					</div>
				</div>

				<p style="margin:0; font-size:13px; color:#1d2327;">
					<?php esc_html_e('Are you sure you want to permanently cancel this CMI pre-authorization?', 'cmi'); ?>
				</p>
			</div>

			<!-- Footer -->
			<div style="background:#f6f7f7; padding:12px 18px; border-top:1px solid #dcdcde; display:flex; justify-content:flex-end; gap:8px;">
				<button type="button" class="button" onclick="cmiCloseVoidModal()"><?php esc_html_e('Keep the order', 'cmi'); ?></button>
				<button type="button" class="button button-primary" id="cmi-void-modal-submit-btn" onclick="cmiSubmitModalVoid()" style="background:#d63638; border-color:#b32d2e; color:#fff; font-weight:600;">
					<?php esc_html_e('Confirm cancellation (cannot be undone)', 'cmi'); ?>
				</button>
			</div>
		</div>

		<!-- CMI Confirm warning for an old pre-authorization -->
		<div id="cmi-confirm-warning-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); width:480px; max-width:92%; background:#fff; border-radius:8px; box-shadow:0 10px 35px rgba(0,0,0,0.25); z-index:99999; overflow:hidden; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,'Helvetica Neue',sans-serif; border:1px solid #ccd0d4;">
			<div style="background:#fff8e5; padding:14px 18px; border-bottom:1px solid #f0c33c; display:flex; justify-content:space-between; align-items:center;">
				<h3 style="margin:0; font-size:15px; font-weight:600; color:#8c5b00;">
					<?php esc_html_e('Confirm this payment?', 'cmi'); ?> &mdash; <?php esc_html_e('Order', 'cmi'); ?> #<span id="cmi-confirm-warning-order"></span>
				</h3>
				<button type="button" onclick="cmiCloseConfirmWarning()" style="background:none; border:none; font-size:22px; line-height:1; cursor:pointer; color:#646970; padding:0 4px;" title="<?php esc_attr_e('Close', 'cmi'); ?>">&times;</button>
			</div>
			<div style="padding:18px 20px; font-size:13px; color:#1d2327;">
				<div style="background:#fff8e5; border:1px solid #f0c33c; border-left:4px solid #dba617; padding:12px 14px; border-radius:4px; display:flex; gap:10px; align-items:flex-start;">
					<span class="dashicons dashicons-warning" style="font-size:20px; width:20px; height:20px; color:#dba617; flex-shrink:0; margin-top:1px;"></span>
					<div>
						<strong id="cmi-confirm-warning-age" style="display:block; margin-bottom:4px;"></strong>
						<span><?php esc_html_e('We recommend informing the customer before you confirm. A charge they no longer expect can lead to a chargeback later.', 'cmi'); ?></span>
					</div>
				</div>
			</div>
			<div style="background:#f6f7f7; padding:12px 18px; border-top:1px solid #dcdcde; display:flex; justify-content:flex-end; gap:8px;">
				<button type="button" class="button" onclick="cmiCloseConfirmWarning()"><?php esc_html_e('Go back', 'cmi'); ?></button>
				<button type="button" class="button button-primary" id="cmi-confirm-warning-submit" onclick="cmiSubmitConfirmWarning()" style="background:#008a20; border-color:#007017; color:#fff; font-weight:600;"><?php esc_html_e('Confirm anyway', 'cmi'); ?></button>
			</div>
		</div>

		<!-- 2. CMI Refund Modal Box -->
		<div id="cmi-refund-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); width:480px; max-width:92%; background:#fff; border-radius:8px; box-shadow:0 10px 35px rgba(0,0,0,0.25); z-index:99999; overflow:hidden; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,'Helvetica Neue',sans-serif; border:1px solid #ccd0d4;">
			<!-- Header -->
			<div style="background:#f6f7f7; padding:14px 18px; border-bottom:1px solid #dcdcde; display:flex; justify-content:space-between; align-items:center;">
				<h3 style="margin:0; font-size:15px; font-weight:600; color:#1d2327; display:flex; align-items:center; gap:6px;">
					<span style="color:#d49b28; font-size:18px;">&#8634;</span>
					<span><?php esc_html_e('CMI API Refund', 'cmi'); ?> &mdash; <?php esc_html_e('Order', 'cmi'); ?> #<span id="cmi-modal-order-id"></span></span>
				</h3>
				<button type="button" onclick="cmiCloseRefundModal()" style="background:none; border:none; font-size:22px; line-height:1; cursor:pointer; color:#646970; padding:0 4px;" title="<?php esc_attr_e('Close', 'cmi'); ?>">&times;</button>
			</div>

			<!-- Body -->
			<div style="padding:18px 20px;">
				<!-- Irreversible warning banner -->
				<div style="background:#fff8e5; border:1px solid #f0c33c; border-left:4px solid #dba617; padding:10px 14px; margin-bottom:14px; border-radius:4px; font-size:12px; color:#614200; display:flex; gap:10px; align-items:flex-start;">
					<span class="dashicons dashicons-warning" style="font-size:18px; width:18px; height:18px; color:#dba617; flex-shrink:0; margin-top:1px;"></span>
					<div>
						<strong style="display:block; margin-bottom:2px; color:#8c5b00;"><?php esc_html_e('⚠️ Warning: this action cannot be undone', 'cmi'); ?></strong>
						<span><?php esc_html_e('A refund through the CMI API is immediate and final. The funds will be returned to the customer and this action cannot be undone.', 'cmi'); ?></span>
					</div>
				</div>

				<div style="background:#f0f6fc; border-left:4px solid #72aee6; padding:10px 14px; margin-bottom:16px; border-radius:2px; font-size:13px; color:#1d2327;">
					<div style="display:flex; justify-content:space-between; margin-bottom:4px;">
						<span><?php esc_html_e('Total Order Amount:', 'cmi'); ?></span>
						<span id="cmi-modal-total-display" style="font-weight:600;">0.00 MAD</span>
					</div>
					<div style="display:flex; justify-content:space-between; margin-bottom:4px;">
						<span><?php esc_html_e('Already Refunded:', 'cmi'); ?></span>
						<span id="cmi-modal-refunded-display" style="color:#b5831e; font-weight:600;">0.00 MAD</span>
					</div>
					<div style="display:flex; justify-content:space-between;">
						<span><?php esc_html_e('Available Refundable Balance:', 'cmi'); ?></span>
						<span id="cmi-modal-refundable-display" style="color:#008a20; font-weight:700;">0.00 MAD</span>
					</div>
				</div>

				<div style="margin-bottom:14px;">
					<label style="display:flex; align-items:center; gap:8px; font-weight:600; cursor:pointer; margin-bottom:8px; font-size:13px; color:#1d2327;">
						<input type="radio" name="cmi_refund_type_choice" id="cmi_refund_type_full" value="full" checked onchange="cmiToggleRefundType()">
						<span><span id="cmi-modal-full-label-text"><?php esc_html_e('Refund Remaining Balance', 'cmi'); ?></span> (<span id="cmi-modal-full-amt">0.00</span> MAD)</span>
					</label>
					<label style="display:flex; align-items:center; gap:8px; font-weight:600; cursor:pointer; font-size:13px; color:#1d2327;">
						<input type="radio" name="cmi_refund_type_choice" id="cmi_refund_type_partial" value="partial" onchange="cmiToggleRefundType()">
						<span><?php esc_html_e('Custom Partial Amount', 'cmi'); ?></span>
					</label>
				</div>

				<div id="cmi-modal-partial-wrapper" style="display:none; margin-left:24px; margin-bottom:14px; padding:10px; background:#f9f9f9; border:1px solid #e2e4e7; border-radius:4px;">
					<label for="cmi-modal-partial-input" style="display:block; font-size:12px; color:#50575e; margin-bottom:5px; font-weight:600;">
						<?php esc_html_e('Enter partial amount to refund (MAD):', 'cmi'); ?>
					</label>
					<div style="display:flex; align-items:center; gap:6px;">
						<input type="number" id="cmi-modal-partial-input" step="0.01" min="0.01" style="width:130px; padding:4px 8px; font-weight:600; font-size:14px;" oninput="cmiValidateModalInput()">
						<span style="font-weight:600; color:#50575e;">MAD</span>
					</div>
				</div>

				<div style="margin-top:12px;">
					<label for="cmi-modal-reason-input" style="display:block; font-size:12px; color:#50575e; margin-bottom:4px; font-weight:600;">
						<?php esc_html_e('Refund Reason (Optional):', 'cmi'); ?>
					</label>
					<input type="text" id="cmi-modal-reason-input" placeholder="<?php esc_attr_e('e.g. Customer request / Item returned', 'cmi'); ?>" style="width:100%; padding:5px 8px; font-size:13px;">
				</div>

				<div id="cmi-modal-error-msg" style="display:none; margin-top:14px; padding:8px 12px; background:#fcf0f1; border-left:4px solid #d63638; color:#d63638; font-size:12px; font-weight:600; border-radius:2px;"></div>
			</div>

			<!-- Footer -->
			<div style="background:#f6f7f7; padding:12px 18px; border-top:1px solid #dcdcde; display:flex; justify-content:flex-end; gap:8px;">
				<button type="button" class="button" onclick="cmiCloseRefundModal()"><?php esc_html_e('Cancel', 'cmi'); ?></button>
				<button type="button" class="button button-primary" id="cmi-modal-submit-btn" onclick="cmiSubmitModalRefund()" style="background:#d49b28; border-color:#b5831e; color:#000; font-weight:600;">
					<?php esc_html_e('Confirm refund (cannot be undone)', 'cmi'); ?>
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
				cmiCloseConfirmWarning();
			}

			// --- Confirm Warning Logic (pre-authorization older than the warning age) ---
			var cmiConfirmWarningUrl = '';
			var cmiPreauthAgeText = '<?php echo esc_js(__('This payment was pre-authorized %d days ago.', 'cmi')); ?>';
			var cmiEditOrder = <?php echo $edit_order_can_confirm ? wp_json_encode(array('id' => $edit_order_id, 'nonce' => wp_create_nonce('cmi_admin_order_action_' . $edit_order_id))) : 'null'; ?>;

			// Asks CMI for the pre-authorization date. Advisory: on any failure the confirmation
			// simply continues, and is checked again on the server before anything is sent.
			function cmiCheckPreauthAge(orderId, nonce, done) {
				jQuery.post(ajaxurl, { action: 'cmi_preauth_age', order_id: orderId, _wpnonce: nonce })
					.done(function(res) {
						done(res && res.success ? (parseInt(res.data.days, 10) || 0) : 0, res && res.success ? res.data.order_number : '');
					})
					.fail(function() {
						done(0, '');
					});
			}

			function cmiOpenConfirmWarningManual(url, orderNumber, days) {
				cmiConfirmWarningUrl = url;
				document.getElementById('cmi-confirm-warning-order').innerText = orderNumber;
				document.getElementById('cmi-confirm-warning-age').innerText = cmiPreauthAgeText.replace('%d', days);
				var submitBtn = document.getElementById('cmi-confirm-warning-submit');
				submitBtn.disabled = false;
				submitBtn.innerText = '<?php echo esc_js(__('Confirm anyway', 'cmi')); ?>';
				document.getElementById('cmi-action-modal-backdrop').style.display = 'block';
				document.getElementById('cmi-confirm-warning-modal').style.display = 'block';
				return false;
			}

			function cmiCloseConfirmWarning() {
				document.getElementById('cmi-confirm-warning-modal').style.display = 'none';
				if (document.getElementById('cmi-refund-modal').style.display !== 'block' && document.getElementById('cmi-void-modal').style.display !== 'block') {
					document.getElementById('cmi-action-modal-backdrop').style.display = 'none';
				}
			}

			function cmiSubmitConfirmWarning() {
				if (!cmiConfirmWarningUrl) {
					return;
				}
				var submitBtn = document.getElementById('cmi-confirm-warning-submit');
				submitBtn.disabled = true;
				submitBtn.innerText = '<?php echo esc_js(__('Confirming via CMI API...', 'cmi')); ?>';
				window.location.href = cmiConfirmWarningUrl;
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
				submitBtn.innerText = '<?php echo esc_js(__('Confirm cancellation (cannot be undone)', 'cmi')); ?>';

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
				submitBtn.innerText = '<?php echo esc_js(__('Cancelling via the CMI API...', 'cmi')); ?>';
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
				document.getElementById('cmi-modal-submit-btn').innerText = '<?php echo esc_js(__('Confirm refund (cannot be undone)', 'cmi')); ?>';

				var fullLabelText = cmiRefundState.refunded > 0.001
					? '<?php echo esc_js(__('Refund the remaining balance', 'cmi')); ?>'
					: '<?php echo esc_js(__('Full refund', 'cmi')); ?>';
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
					errorEl.innerText = '<?php echo esc_js(__('Please enter a valid positive number for the refund amount.', 'cmi')); ?>';
					errorEl.style.display = 'block';
					submitBtn.disabled = true;
					return false;
				}

				if (inputVal > cmiRefundState.refundable + 0.001) {
					errorEl.innerText = '<?php echo esc_js(__('Refund amount cannot exceed the available balance of ', 'cmi')); ?>' + cmiRefundState.refundable.toFixed(2) + ' MAD.';
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
				submitBtn.innerText = '<?php echo esc_js(__('Processing the refund via the CMI API...', 'cmi')); ?>';

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

				// Confirm buttons (CMI column and WooCommerce's order list icons): check CMI's date first
				$(document).on('click', '.cmi-confirm-btn, .wc-action-button-cmi_confirm, a.cmi_confirm', function(e) {
					e.preventDefault();
					var $btn = $(this);
					var href = $btn.attr('href') || '';
					var orderId = href.match(/[?&]order_id=(\d+)/);
					var nonce = href.match(/[?&]_wpnonce=([^&]+)/);
					if (!orderId || !nonce) {
						window.location.href = href;
						return false;
					}
					if ($btn.data('cmiChecking')) {
						return false;
					}
					$btn.data('cmiChecking', true).css('opacity', 0.6);
					cmiCheckPreauthAge(orderId[1], decodeURIComponent(nonce[1]), function(days, orderNumber) {
						$btn.data('cmiChecking', false).css('opacity', '');
						if (days > 0) {
							cmiOpenConfirmWarningManual(href, orderNumber, days);
						} else {
							window.location.href = href;
						}
					});
					return false;
				});

				// Intercept order actions dropdown on single order edit screen
				var cmiEditConfirmChecked = false;
				$('button.wc-reload, button[name="save"], input#publish').closest('form').on('submit', function(e) {
					var actionVal = $('select[name="wc_order_action"]').val();
					if (actionVal === 'cmi_confirm_transaction' && cmiEditOrder && !cmiEditConfirmChecked) {
						// Hold the form while CMI's date is checked, then submit it the same way again.
						e.preventDefault();
						var form = this;
						var submitter = e.originalEvent && e.originalEvent.submitter;
						cmiCheckPreauthAge(cmiEditOrder.id, cmiEditOrder.nonce, function(days) {
							if (days > 0 && !confirm(cmiPreauthAgeText.replace('%d', days) + ' <?php echo esc_js(__('We recommend informing the customer before you confirm. A charge they no longer expect can lead to a chargeback later. Confirm anyway?', 'cmi')); ?>')) {
								return;
							}
							cmiEditConfirmChecked = true;
							if (submitter) {
								submitter.click();
							} else {
								form.submit();
							}
						});
						return false;
					} else if (actionVal === 'cmi_cancel_transaction') {
						if (!confirm('<?php echo esc_js(__('⚠️ Warning: a CMI cancellation (Void) cannot be undone. The authorization will be permanently cancelled with the bank and can never be charged. Confirm?', 'cmi')); ?>')) {
							e.preventDefault();
							return false;
						}
					} else if (actionVal === 'cmi_refund_transaction') {
						if (!confirm('<?php echo esc_js(__('⚠️ Warning: a refund through the CMI API cannot be undone. The funds will be returned to the customer immediately and permanently. Confirm?', 'cmi')); ?>')) {
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
			wp_die(esc_html__('You do not have permission to manage WooCommerce orders.', 'cmi'));
		}
		$order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
		check_admin_referer('cmi_admin_order_action_' . $order_id);

		$order = $order_id ? wc_get_order($order_id) : false;
		if (!$order) {
			wp_die(esc_html__('Invalid order ID.', 'cmi'));
		}

		$gateway = self::get_gateway_instance();
		$result = $gateway->confirm_transaction($order);
		if (is_wp_error($result)) {
			set_transient('cmi_admin_notice_' . get_current_user_id(), array(
				'type'    => 'error',
				'message' => sprintf(__('CMI confirmation failed for order #%d: %s', 'cmi'), $order_id, $result->get_error_message()),
			), 45);
		} else {
			set_transient('cmi_admin_notice_' . get_current_user_id(), array(
				'type'    => 'success',
				'message' => sprintf(__('Order #%d confirmed successfully via CMI API.', 'cmi'), $order_id),
			), 45);
		}

		wp_safe_redirect(self::get_admin_orders_redirect_url());
		exit;
	}

	public static function handle_admin_list_cancel(): void
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('You do not have permission to manage WooCommerce orders.', 'cmi'));
		}
		$order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
		check_admin_referer('cmi_admin_order_action_' . $order_id);

		$order = $order_id ? wc_get_order($order_id) : false;
		if (!$order) {
			wp_die(esc_html__('Invalid order ID.', 'cmi'));
		}

		$gateway = self::get_gateway_instance();
		$result = $gateway->cancel_transaction($order);
		if (is_wp_error($result)) {
			set_transient('cmi_admin_notice_' . get_current_user_id(), array(
				'type'    => 'error',
				'message' => sprintf(__('CMI void/cancellation failed for order #%d: %s', 'cmi'), $order_id, $result->get_error_message()),
			), 45);
		} else {
			set_transient('cmi_admin_notice_' . get_current_user_id(), array(
				'type'    => 'success',
				'message' => sprintf(__('Order #%d voided and cancelled successfully via CMI API.', 'cmi'), $order_id),
			), 45);
		}

		wp_safe_redirect(self::get_admin_orders_redirect_url());
		exit;
	}

	public static function handle_admin_list_refund(): void
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('You do not have permission to manage WooCommerce orders.', 'cmi'));
		}
		$order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
		check_admin_referer('cmi_admin_order_action_' . $order_id);

		$order = $order_id ? wc_get_order($order_id) : false;
		if (!$order) {
			wp_die(esc_html__('Invalid order ID.', 'cmi'));
		}

		$refund_amount = isset($_GET['refund_amount']) && $_GET['refund_amount'] !== '' ? (float) wc_clean(wp_unslash($_GET['refund_amount'])) : null;
		$reason = isset($_GET['refund_reason']) ? sanitize_text_field(wp_unslash($_GET['refund_reason'])) : '';

		$gateway = self::get_gateway_instance();
		$result = $gateway->refund_transaction($order, $refund_amount, $reason, false);
		if (is_wp_error($result)) {
			set_transient('cmi_admin_notice_' . get_current_user_id(), array(
				'type'    => 'error',
				'message' => sprintf(__('CMI refund failed for order #%d: %s', 'cmi'), $order_id, $result->get_error_message()),
			), 45);
		} else {
			// Reload order from DB to reflect the newly recorded refund
			$order = wc_get_order($order_id);
			$balance = $gateway->get_refund_balance_mad($order);
			$is_full = $balance === null || $balance['remaining'] <= 0.001;

			$msg = !$is_full
				? sprintf(__('Partial refund for order #%1$d processed successfully via CMI API. Refunded: %2$s MAD (remaining refundable: %3$s MAD).', 'cmi'), $order_id, number_format($balance['refunded'], 2), number_format($balance['remaining'], 2))
				: sprintf(__('Order #%1$d fully refunded successfully via CMI API (%2$s MAD).', 'cmi'), $order_id, number_format($balance['total'] ?? 0, 2));

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
		$order->add_order_note(sprintf(__('CMI Status Check result: [%s] %s', 'cmi'), $result['status'], $result['message']));
	}

	public static function handle_admin_list_check_status(): void
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('You do not have permission to manage WooCommerce orders.', 'cmi'));
		}
		$order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
		check_admin_referer('cmi_admin_order_action_' . $order_id);

		$order = $order_id ? wc_get_order($order_id) : false;
		if (!$order) {
			wp_die(esc_html__('Invalid order ID.', 'cmi'));
		}

		$gateway = self::get_gateway_instance();
		$result = $gateway->sync_order_with_cmi_api($order);

		$is_success = in_array($result['status'], array('APPROVED', 'PRE_AUTHORIZED', 'REFUNDED', 'PARTIALLY_REFUNDED', 'VOIDED'), true);
		$status_label = $result['cmi_status'] ?? $result['status'];
		set_transient('cmi_admin_notice_' . get_current_user_id(), array(
			'type'    => $is_success ? 'success' : 'error',
			'message' => sprintf(__('CMI status for order #%d: [%s] %s', 'cmi'), $order_id, $status_label, $result['message']),
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
			$order->add_order_note(sprintf(__('CMI confirmation failed: %s', 'cmi'), $result->get_error_message()));
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
			$order->add_order_note(sprintf(__('CMI void/cancellation failed: %s', 'cmi'), $result->get_error_message()));
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
			$order->add_order_note(sprintf(__('CMI refund failed: %s', 'cmi'), $result->get_error_message()));
		}
	}

	public function process_refund($order_id, $amount = null, $reason = '')
	{
		$order = wc_get_order($order_id);
		if (!$order) {
			return new WP_Error('cmi_refund_error', __('Order not found.', 'cmi'));
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
			return new WP_Error('cmi_wrong_gateway', __('This order was not paid with CMI.', 'cmi'));
		}
		if (!$this->has_api_configuration()) {
			return new WP_Error('cmi_api_not_configured', __('Configure the CMI API URL, username, and password before issuing refunds.', 'cmi'));
		}

		$total_order = (float) $order->get_total();
		$refunded_order = (float) $order->get_total_refunded();
		if ($from_wc_native) {
			// WooCommerce has already recorded this refund on the order before calling us.
			$refunded_order = max(0, $refunded_order - (float) $amount);
		}
		$remaining_order = max(0, $total_order - $refunded_order);

		// CMI only knows the MAD amount it charged: refunds are calculated in MAD, using
		// the amount and rate frozen at payment time (never today's rate).
		$balance = $this->get_refund_balance_mad($order, $refunded_order);
		if ($balance === null) {
			return new WP_Error('cmi_refund_rate_unknown', __('This order was paid in another currency before the plugin recorded the MAD amount charged by CMI, so the exact refund amount cannot be calculated. Please refund it from the CMI back office.', 'cmi'));
		}

		if ($from_wc_native) {
			// Native WooCommerce refund: the amount is in the order currency.
			$refund_order_amount = $amount !== null ? (float) $amount : $remaining_order;
			if ($refund_order_amount <= 0) {
				return new WP_Error('cmi_invalid_refund_amount', __('Invalid refund amount.', 'cmi'));
			}
			$split = Cmi_Currency::refund_from_order_currency($refund_order_amount, $remaining_order, $balance['remaining'], $balance['rate']);
		} else {
			// CMI modal / order action: the amount is in MAD.
			$refund_mad = $amount !== null ? (float) $amount : $balance['remaining'];
			if ($refund_mad <= 0) {
				return new WP_Error('cmi_invalid_refund_amount', __('Invalid refund amount.', 'cmi'));
			}
			if ($refund_mad > $balance['remaining'] + 0.001) {
				return new WP_Error(
					'cmi_refund_amount_exceeded',
					sprintf(
						__('Refund amount (%s MAD) exceeds remaining balance (%s MAD).', 'cmi'),
						number_format($refund_mad, 2),
						number_format($balance['remaining'], 2)
					)
				);
			}
			$split = Cmi_Currency::refund_from_mad($refund_mad, $balance['remaining'], $remaining_order, $balance['rate']);
		}
		if ($split['mad'] <= 0) {
			return new WP_Error('cmi_nothing_to_refund', __('Nothing is left to refund on this order.', 'cmi'));
		}
		$currency = $order->get_currency();
		$order_amount_label = $currency === Cmi_Currency::MAD ? '' : sprintf(' (%s %s)', number_format($split['order'], 2), $currency);

		$lock_key = 'cmi_confirmation_lock_' . get_current_blog_id() . '_' . $order->get_id();
		$existing_lock = get_option($lock_key);
		if ($existing_lock !== false && (time() - (int) $existing_lock) > 300) {
			delete_option($lock_key);
		}
		if (!add_option($lock_key, (string) time(), '', false)) {
			return new WP_Error('cmi_action_in_progress', __('An operation is already in progress for this order.', 'cmi'));
		}

		try {
			$guard = $this->guard_cmi_action($order, 'refund');
			if (is_wp_error($guard)) {
				return $guard;
			}
			// A refund made in the CMI back office isn't in WooCommerce yet: the balance would be wrong
			$cmi_refunded = $this->get_cmi_refunded_mad($order);
			if ($cmi_refunded !== null && $cmi_refunded > $balance['refunded'] + 0.009) {
				return new WP_Error('cmi_refund_out_of_sync', sprintf(
					__('CMI shows %1$s MAD already refunded on this payment, but WooCommerce has recorded only %2$s MAD: a refund was probably made in the CMI back office. Click Check CMI to update the order, then refund the remaining balance.', 'cmi'),
					number_format($cmi_refunded, 2),
					number_format($balance['refunded'], 2)
				));
			}
			$response = $this->credit_request($order, $split['mad']);
			if (is_wp_error($response)) {
				return $response;
			}
			if (($response['Response'] ?? '') !== 'Approved') {
				return new WP_Error('cmi_refund_rejected', $response['ErrMsg'] ?? __('CMI rejected the refund request.', 'cmi'));
			}

			// If called from CMI modal or custom action, create official WC_Order_Refund in WooCommerce (order currency)
			if (!$from_wc_native) {
				$wc_refund = wc_create_refund(array(
					'amount'         => $split['order'],
					'reason'         => $reason ? $reason : __('Refund via CMI API', 'cmi'),
					'order_id'       => $order->get_id(),
					'refund_payment' => false,
				));
				// Re-read order to fetch updated refund line items from database
				$order = wc_get_order($order->get_id());
				if (is_wp_error($wc_refund)) {
					$order->add_order_note(sprintf(
						__('CMI refunded %1$s MAD, but WooCommerce could not record the refund: %2$s. Please add it manually with "Refund manually".', 'cmi'),
						number_format($split['mad'], 2),
						$wc_refund->get_error_message()
					));
				}
			}

			$this->remember_own_transaction($order, $response);
			$refunded_mad_now = round($balance['refunded'] + $split['mad'], 2);
			$remaining_mad_now = round(max(0, $balance['total'] - $refunded_mad_now), 2);
			$order->update_meta_data(self::META_REFUNDED_MAD, number_format($refunded_mad_now, 2, '.', ''));
			$order->update_meta_data('_cmi_last_refund_trans_id', $response['TransId'] ?? '');
			$order->save();

			if ($split['full'] || $remaining_mad_now <= 0.001) {
				$this->set_order_payment_status($order, self::PAYMENT_STATUS_REFUNDED, $response);
				$order->update_status(
					'refunded',
					sprintf(
						__('Order fully refunded via CMI API: %1$s MAD%2$s (TransId: %3$s). Reason: %4$s', 'cmi'),
						number_format($split['mad'], 2),
						$order_amount_label,
						$response['TransId'] ?? __('N/A', 'cmi'),
						$reason ? $reason : __('N/A', 'cmi')
					)
				);
			} else {
				$order->add_order_note(
					sprintf(
						__('Partial refund processed via CMI API: %1$s MAD%2$s (TransId: %3$s). Total refunded: %4$s / %5$s MAD (Remaining: %6$s MAD). Reason: %7$s', 'cmi'),
						number_format($split['mad'], 2),
						$order_amount_label,
						$response['TransId'] ?? __('N/A', 'cmi'),
						number_format($refunded_mad_now, 2),
						number_format($balance['total'], 2),
						number_format($remaining_mad_now, 2),
						$reason ? $reason : __('N/A', 'cmi')
					)
				);
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
			return new WP_Error('cmi_wrong_gateway', __('This order was not paid with CMI.', 'cmi'));
		}
		if ($this->get_order_payment_status($order) === self::PAYMENT_STATUS_VOIDED || $this->get_order_payment_status($order) === self::PAYMENT_STATUS_CLIENT_CANCELLED || $order->get_status() === 'cancelled') {
			return array('Response' => 'Approved', 'already_cancelled' => true);
		}
		if (!Cmi_Payment_State::can_confirm($this->get_order_payment_status($order)) && !in_array($order->get_status(), array('on-hold', 'pending'), true)) {
			return new WP_Error('cmi_not_cancellable', __('Only a CMI PRE transaction can be voided. Confirmed POST transactions must be refunded.', 'cmi'));
		}
		if (!$this->has_api_configuration()) {
			return new WP_Error('cmi_api_not_configured', __('Configure the CMI API URL, username, and password before cancelling PRE transactions.', 'cmi'));
		}

		$lock_key = 'cmi_confirmation_lock_' . get_current_blog_id() . '_' . $order->get_id();
		$existing_lock = get_option($lock_key);
		if ($existing_lock !== false && (time() - (int) $existing_lock) > 300) {
			delete_option($lock_key);
		}
		if (!add_option($lock_key, (string) time(), '', false)) {
			return new WP_Error('cmi_action_in_progress', __('An operation is already in progress for this order.', 'cmi'));
		}

		try {
			$guard = $this->guard_cmi_action($order, 'void');
			if (is_wp_error($guard)) {
				return $guard;
			}
			$response = $this->void_auth_request($order);
			if (is_wp_error($response)) {
				return $response;
			}
			if (($response['Response'] ?? '') !== 'Approved') {
				return new WP_Error('cmi_cancellation_rejected', $response['ErrMsg'] ?? __('CMI rejected the void request.', 'cmi'));
			}

			$this->remember_own_transaction($order, $response);
			$this->set_order_payment_status($order, self::PAYMENT_STATUS_VOIDED, $response);
			$order->update_status('cancelled', sprintf(__('CMI PRE transaction voided via CMI API (TransId: %s).', 'cmi'), $response['TransId'] ?? __('N/A', 'cmi')));
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
			return new WP_Error('cmi_wrong_gateway', __('This order was not paid with CMI.', 'cmi'));
		}
		$custom_target = $this->get_postauth_order_status();
		if ($this->get_order_payment_status($order) === self::PAYMENT_STATUS_CONFIRMED || $order->is_paid() || in_array($order->get_status(), array('processing', 'completed', 'confirmed', $custom_target), true)) {
			return array('Response' => 'Approved', 'already_confirmed' => true);
		}
		if (!Cmi_Payment_State::can_confirm($this->get_order_payment_status($order)) && !in_array($order->get_status(), array('on-hold', 'pending'), true)) {
			return new WP_Error('cmi_not_pre_authorized', __('Only a CMI PRE transaction can be confirmed.', 'cmi'));
		}
		if (!$this->has_api_configuration()) {
			return new WP_Error('cmi_api_not_configured', __('Configure the CMI API URL, username, and password before confirming PRE transactions.', 'cmi'));
		}

		$lock_key = 'cmi_confirmation_lock_' . get_current_blog_id() . '_' . $order->get_id();
		$existing_lock = get_option($lock_key);
		if ($existing_lock !== false && (time() - (int) $existing_lock) > 300) {
			delete_option($lock_key);
		}
		if (!add_option($lock_key, (string) time(), '', false)) {
			return new WP_Error('cmi_confirmation_in_progress', __('A confirmation attempt is already in progress for this order.', 'cmi'));
		}

		try {
			$guard = $this->guard_cmi_action($order, 'confirm');
			if (is_wp_error($guard)) {
				return $guard;
			}
			$response = $this->post_auth_request($order, $amount);
			if (is_wp_error($response)) {
				return $response;
			}
			if (($response['Response'] ?? '') !== 'Approved') {
				$this->set_order_payment_status($order, self::PAYMENT_STATUS_PRE_CONFIRMATION_REQUIRED, $response);
				return new WP_Error('cmi_confirmation_rejected', $response['ErrMsg'] ?? __('CMI rejected the confirmation request.', 'cmi'));
			}

			$this->remember_own_transaction($order, $response);
			$target_status = $this->get_postauth_order_status();
			$this->set_order_payment_status($order, self::PAYMENT_STATUS_CONFIRMED, $response);
			$order->payment_complete($response['TransId'] ?? '');
			$order->update_status($target_status, sprintf(__('CMI PRE transaction confirmed and captured through the CMI API (status set to %s).', 'cmi'), $target_status));
			return $response;
		} finally {
			delete_option($lock_key);
		}
	}

	/**
	 * Read-only check that the CMI transaction behind this order's OrderId exists and is
	 * this order's own payment, run before any PostAuth / Void / Credit and status sync.
	 *
	 * Those requests identify a transaction by OrderId only. If another system using the
	 * same CMI account (e.g. the merchant's other website) already used this OrderId,
	 * acting on it would confirm, void or refund that system's transaction.
	 *
	 * @param array|null $status ORDERSTATUS response already fetched by the caller.
	 * @return array{status: array, history: string[]}|WP_Error
	 */
	private function verify_cmi_transaction(WC_Order $order, ?array $status = null)
	{
		$cmi_oid = $this->get_sent_cmi_oid($order);
		if ($status === null) {
			$status = $this->query_order_status($order);
			if (is_wp_error($status)) {
				return new WP_Error('cmi_verification_failed', sprintf(__('Could not verify the transaction with CMI, so no request was sent: %s', 'cmi'), $status->get_error_message()));
			}
		}
		if (Cmi_Transaction_Guard::is_not_found($status)) {
			return new WP_Error('cmi_order_not_found', sprintf(__('Order ID %s was not found in the CMI back office. No request was sent to CMI.', 'cmi'), $cmi_oid));
		}

		$mad_details = $this->get_order_mad_details($order);
		$sent_at = (int) $order->get_meta(self::META_SENT_AT);
		$ours = array(
			'ids'          => $this->get_own_transaction_ids($order),
			'amount_minor' => $mad_details !== null ? Cmi_Transaction_Guard::to_minor_units($mad_details['amount']) : null,
			'sent_at'      => $sent_at > 0 ? $sent_at : null,
		);

		// ORDERSTATUS only shows the last successful transaction (e.g. the PostAuth or a
		// refund), so our identifiers may only appear in the full history.
		$history = array();
		$verdict = Cmi_Transaction_Guard::ownership($status, $history, $ours);
		if ($verdict === Cmi_Transaction_Guard::FOREIGN) {
			$history_response = $this->send_cmi_xml_api_request('ORDERHISTORY', $order);
			if (is_wp_error($history_response)) {
				return new WP_Error('cmi_verification_failed', sprintf(__('Could not verify the transaction with CMI, so no request was sent: %s', 'cmi'), $history_response->get_error_message()));
			}
			$history = $history_response['HISTORY'] ?? array();
			$verdict = Cmi_Transaction_Guard::ownership($status, $history, $ours);
		}

		if ($verdict !== Cmi_Transaction_Guard::OWNED) {
			$message = $verdict === Cmi_Transaction_Guard::UNVERIFIED
				? sprintf(__('The CMI transaction for order ID %s cannot be matched to this order (no payment confirmation or amount was recorded). No request was sent to CMI; please manage it from the CMI back office.', 'cmi'), $cmi_oid)
				: sprintf(__('Order ID %s exists in the CMI back office but belongs to another transaction, not to this order\'s payment. No request was sent to CMI.', 'cmi'), $cmi_oid);
			wc_get_logger()->warning('CMI transaction ownership check failed: ' . $message, array(
				'source'   => 'cmi-security',
				'order_id' => $order->get_id(),
				'cmi_oid'  => $cmi_oid,
				'cmi_transid' => $status['TransId'] ?? '',
			));
			$order->add_order_note($message);
			return new WP_Error($verdict === Cmi_Transaction_Guard::UNVERIFIED ? 'cmi_transaction_unverified' : 'cmi_transaction_foreign', $message);
		}

		// Verified: later checks can recognise this transaction by its identifiers.
		$this->remember_own_transaction($order, $status);

		return array('status' => $status, 'history' => $history);
	}

	/**
	 * Check that CMI's current state of a verified transaction allows the action.
	 *
	 * @param string $action confirm | void | refund
	 * @return true|WP_Error
	 */
	private function check_cmi_action_state(string $action, array $status)
	{
		$reason = Cmi_Transaction_Guard::action_block_reason($action, (string) ($status['TRANS_STAT'] ?? ''), (string) ($status['CHARGE_TYPE_CD'] ?? ''));
		$messages = array(
			'not_preauthorized' => __('CMI does not show an active pre-authorization for this order, so it cannot be confirmed or voided.', 'cmi'),
			'already_captured'  => __('This payment is already captured on CMI. Use Refund instead.', 'cmi'),
			'not_captured'      => __('This payment is not captured on CMI, so it cannot be refunded. Void the pre-authorization instead.', 'cmi'),
			'voided'            => __('This transaction is already voided on CMI.', 'cmi'),
			'unknown_state'     => __('CMI did not report the transaction state, so no request was sent. Please check the order in the CMI back office.', 'cmi'),
		);
		return $reason === '' ? true : new WP_Error('cmi_action_not_allowed_' . $reason, $messages[$reason] ?? $reason);
	}

	/**
	 * Verify ownership and state before an action; WP_Error when it must not be sent.
	 */
	private function guard_cmi_action(WC_Order $order, string $action)
	{
		$verified = $this->verify_cmi_transaction($order);
		if (is_wp_error($verified)) {
			return $verified;
		}
		$state = $this->check_cmi_action_state($action, $verified['status']);
		if (!is_wp_error($state) || $state->get_error_code() === 'cmi_action_not_allowed_unknown_state') {
			return $state;
		}

		// CMI's state differs from what WooCommerce expected (e.g. the merchant confirmed or
		// voided in the CMI back office): bring the order in line, then explain the refusal.
		$status_before = $order->get_status();
		$this->sync_order_with_cmi_api($order, $verified['status']);
		if ($order->get_status() === $status_before) {
			return $state;
		}
		return new WP_Error($state->get_error_code(), $state->get_error_message() . ' ' . __('The order status was updated to match CMI.', 'cmi'));
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
	 * @param WC_Order   $order
	 * @param array|null $known_status ORDERSTATUS response already fetched by the caller.
	 * @return array State descriptor ['status' => 'APPROVED'|'DECLINED'|'NOT_FOUND'|'ERROR', 'message' => string]
	 */
	public function sync_order_with_cmi_api(WC_Order $order, ?array $known_status = null): array
	{
		if ($order->get_payment_method() !== $this->id) {
			return array('status' => 'ERROR', 'message' => __('Order was not placed using CMI.', 'cmi'));
		}

		if (!$this->has_api_configuration()) {
			return array('status' => 'ERROR', 'message' => __('CMI XML API credentials not configured.', 'cmi'));
		}

		$response = $known_status ?? $this->query_order_status($order);
		if (is_wp_error($response)) {
			return array('status' => 'ERROR', 'message' => $response->get_error_message());
		}

		// Never apply another system's transaction to this order (same OrderId reused).
		$verified = $this->verify_cmi_transaction($order, $response);
		if (is_wp_error($verified)) {
			// NOT FOUND ON CMI (Customer canceled early / abandoned)
			if ($verified->get_error_code() === 'cmi_order_not_found') {
				$order->add_order_note(sprintf(__('CMI Status Check: No transaction record found on CMI (%s).', 'cmi'), ($response['ErrMsg'] ?? '') ?: __('Not found', 'cmi')));
				return array(
					'status'     => 'NOT_FOUND',
					'cmi_status' => __('Not Found', 'cmi'),
					'message'    => __('No transaction record found on CMI (Customer likely abandoned the checkout).', 'cmi'),
				);
			}
			return array(
				'status'     => $verified->get_error_code() === 'cmi_verification_failed' ? 'ERROR' : 'FOREIGN',
				'cmi_status' => __('Not this order', 'cmi'),
				'message'    => $verified->get_error_message(),
			);
		}
		$history = $verified['history'];

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
					__('CMI Status Check: Transaction is VOIDED / Cancelled on CMI (TransId: %s, AuthCode: %s).', 'cmi'),
					$trans_id ?: __('N/A', 'cmi'),
					$auth_code ?: __('N/A', 'cmi')
				));
			} else {
				$order->add_order_note(sprintf(__('CMI Status Check: Transaction confirmed as VOIDED on CMI (TransId: %s).', 'cmi'), $trans_id ?: __('N/A', 'cmi')));
			}
			return array(
				'status'     => 'VOIDED',
				'cmi_status' => __('Voided', 'cmi'),
				'trans_stat' => 'V',
				'message'    => sprintf(__('Transaction voided on CMI (TransId: %s).', 'cmi'), $trans_id ?: __('N/A', 'cmi')),
			);
		}

		// -----------------------------------------------------------------
		// CMI SPEC CASE 2: REFUNDED / CREDITED (CHARGE_TYPE_CD == 'C' with TRANS_STAT 'C' or 'S')
		// Description: Payment refunded. Shipment possible: NO.
		// -----------------------------------------------------------------
		if ($charge_type_cd === 'C' && in_array($trans_stat, array('C', 'S'), true)) {
			// ORDERSTATUS returns the last successful transaction, so after a *partial*
			// refund it reports the Credit. Compare refunded and captured totals first.
			if (!$history) {
				$history_response = $this->send_cmi_xml_api_request('ORDERHISTORY', $order);
				$history = is_wp_error($history_response) ? array() : ($history_response['HISTORY'] ?? array());
			}
			$totals = Cmi_Transaction_Guard::history_totals($history);
			if ($totals['captured'] > 0) {
				$is_partial = $totals['refunded'] < $totals['captured'];
			} else {
				// History unavailable: rely on the refunds recorded by this plugin.
				$balance = $this->get_refund_balance_mad($order);
				$is_partial = $balance !== null && $balance['remaining'] > 0.001;
				if ($balance !== null) {
					$totals = array(
						'captured' => Cmi_Transaction_Guard::to_minor_units($balance['total']),
						'refunded' => Cmi_Transaction_Guard::to_minor_units($balance['refunded']),
					);
				}
			}
			// Refunds made in the CMI back office: record them so WooCommerce's balance matches CMI
			$this->record_back_office_refunds($order, $history);
			if ($is_partial) {
				$this->set_order_payment_status($order, self::PAYMENT_STATUS_CONFIRMED, $response);
				$order->add_order_note(sprintf(
					__('CMI Status Check: Payment partially refunded on CMI (refunded %1$s of %2$s MAD, TransId: %3$s). Order status kept.', 'cmi'),
					number_format($totals['refunded'] / 100, 2),
					number_format($totals['captured'] / 100, 2),
					$trans_id ?: __('N/A', 'cmi')
				));
				return array(
					'status'     => 'PARTIALLY_REFUNDED',
					'cmi_status' => __('Partially credited', 'cmi'),
					'trans_stat' => $trans_stat,
					'message'    => sprintf(__('Payment partially refunded on CMI (%1$s of %2$s MAD).', 'cmi'), number_format($totals['refunded'] / 100, 2), number_format($totals['captured'] / 100, 2)),
				);
			}

			$this->set_order_payment_status($order, self::PAYMENT_STATUS_REFUNDED, $response);
			if ($current_status !== 'refunded') {
				$order->update_status('refunded', sprintf(
					__('CMI Status Check: Transaction was refunded / credited on CMI (TransId: %s, Amount: %s MAD).', 'cmi'),
					$trans_id ?: __('N/A', 'cmi'),
					$response['CAPTURE_AMT'] ?? ''
				));
			} else {
				$order->add_order_note(sprintf(__('CMI Status Check: Transaction confirmed as REFUNDED on CMI (TransId: %s).', 'cmi'), $trans_id ?: __('N/A', 'cmi')));
			}
			return array(
				'status'     => 'REFUNDED',
				'cmi_status' => __('Credited', 'cmi'),
				'trans_stat' => $trans_stat,
				'message'    => sprintf(__('Payment refunded on CMI (TransId: %s).', 'cmi'), $trans_id ?: __('N/A', 'cmi')),
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
					__('CMI Status Check: Pre-authorization verified on CMI (Status: Approved / Pre-auth, AuthCode: %s, TransId: %s). Order is on-hold awaiting capture. Shipment not possible yet.', 'cmi'),
					$auth_code ?: __('N/A', 'cmi'),
					$trans_id ?: __('N/A', 'cmi')
				));
			} else {
				$order->add_order_note(sprintf(
					__('CMI Status Check: Pre-authorization confirmed on CMI (Status: Approved / Pre-auth, AuthCode: %s, TransId: %s). Order remains on-hold awaiting capture.', 'cmi'),
					$auth_code ?: __('N/A', 'cmi'),
					$trans_id ?: __('N/A', 'cmi')
				));
			}
			return array(
				'status'     => 'PRE_AUTHORIZED',
				'cmi_status' => __('Approved (preauthorization)', 'cmi'),
				'trans_stat' => 'A',
				'message'    => sprintf(__('Pre-authorized on CMI (AuthCode: %s, TransId: %s). Awaiting capture; shipment not possible yet.', 'cmi'), $auth_code ?: __('N/A', 'cmi'), $trans_id ?: __('N/A', 'cmi')),
			);
		}

		// -----------------------------------------------------------------
		// CMI SPEC CASE 4: CAPTURED / SETTLED (TRANS_STAT == 'C' or 'S', CHARGE_TYPE_CD == 'S')
		// Description: Successful transaction / Settled transaction. Shipment possible: YES.
		// -----------------------------------------------------------------
		if (Cmi_Payment_State::is_captured_trans_stat($trans_stat)) {
			$cmi_stat_label = ($trans_stat === 'S') ? __('Deposited / Settled', 'cmi') : __('Approved / Captured', 'cmi');

			$this->set_order_payment_status($order, self::PAYMENT_STATUS_CONFIRMED, $response);

			// If order is already in a completed/processing/custom state, PRESERVE its status:
			if ($order->is_paid() || in_array($current_status, array('completed', 'confirmed', 'processing', $target_status), true)) {
				$order->add_order_note(sprintf(
					__('CMI Status Check: Payment confirmed as captured on CMI (%s, AuthCode: %s, TransId: %s). Order status preserved (currently "%s").', 'cmi'),
					$cmi_stat_label,
					$auth_code ?: __('N/A', 'cmi'),
					$trans_id ?: __('N/A', 'cmi'),
					$current_status
				));
			} else {
				// Order was pending or on-hold: complete payment and set to target status
				$order->payment_complete($trans_id);
				if ($target_status !== $order->get_status()) {
					$order->update_status($target_status, sprintf(
						__('CMI Status Check: Payment captured on CMI (%s, TransId: %s, AuthCode: %s, status set to %s).', 'cmi'),
						$cmi_stat_label,
						$trans_id ?: __('N/A', 'cmi'),
						$auth_code ?: __('N/A', 'cmi'),
						$target_status
					));
				}
			}

			return array(
				'status'     => 'APPROVED',
				'cmi_status' => $cmi_stat_label,
				'trans_stat' => $trans_stat ?: 'C',
				'message'    => sprintf(__('Captured on CMI (%s, AuthCode: %s, TransId: %s). Shipment possible.', 'cmi'), $cmi_stat_label, $auth_code ?: __('N/A', 'cmi'), $trans_id ?: __('N/A', 'cmi')),
			);
		}

		// Query succeeded but CMI did not report the transaction state: change nothing.
		// "Approved / 00" here only means the ORDERSTATUS query itself worked.
		if ($trans_stat === '' && ($proc_code === '00' || $cmi_response === 'approved')) {
			$order->add_order_note(__('CMI Status Check: CMI answered but did not report the transaction state (TRANS_STAT). The order was not changed; please check it in the CMI back office.', 'cmi'));
			return array(
				'status'     => 'UNKNOWN',
				'cmi_status' => __('Unknown', 'cmi'),
				'message'    => __('CMI did not report the transaction state. No change was made; check the CMI back office.', 'cmi'),
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
					__('CMI Status Check: Declined by bank (ProcReturnCode: %s, %s).', 'cmi'),
					$proc_code,
					$err_msg ?: __('Transaction declined.', 'cmi')
				));
			} else {
				$order->add_order_note(sprintf(
					__('CMI Status Check: Transaction declined on CMI (ProcReturnCode: %s, %s).', 'cmi'),
					$proc_code,
					$err_msg ?: __('Transaction declined.', 'cmi')
				));
			}
			return array(
				'status'     => 'DECLINED',
				'cmi_status' => __('Declined', 'cmi'),
				'trans_stat' => 'D',
				'message'    => sprintf(__('Declined on CMI: %s (Code: %s).', 'cmi'), $err_msg ?: __('Declined', 'cmi'), $proc_code),
			);
		}

		// -----------------------------------------------------------------
		// CMI SPEC CASE 6: TRANSIENT / PENDING BANK CONFIRMATION (PN, NW, R)
		// -----------------------------------------------------------------
		if ($trans_stat === 'PN') {
			$order->add_order_note(__('CMI Status Check: Transaction pending confirmation by bank (PN). Transient state.', 'cmi'));
			return array(
				'status'     => 'PENDING_BANK',
				'cmi_status' => __('Pending Bank Confirmation', 'cmi'),
				'trans_stat' => 'PN',
				'message'    => __('Pending bank confirmation on CMI. Transient state.', 'cmi'),
			);
		}
		if ($trans_stat === 'NW') {
			$order->add_order_note(__('CMI Status Check: Transaction initiated but not finalized (NW / FirstCommit).', 'cmi'));
			return array(
				'status'     => 'FIRST_COMMIT',
				'cmi_status' => __('FirstCommit', 'cmi'),
				'trans_stat' => 'NW',
				'message'    => __('Transaction in progress on CMI (not finalized).', 'cmi'),
			);
		}
		if ($trans_stat === 'R') {
			$order->add_order_note(__('CMI Status Check: Reversal required on CMI (R).', 'cmi'));
			return array(
				'status'     => 'REVERSAL',
				'cmi_status' => __('Reversal Required', 'cmi'),
				'trans_stat' => 'R',
				'message'    => __('Reversal required on CMI.', 'cmi'),
			);
		}

		return array(
			'status'  => 'UNKNOWN',
			'message' => $err_msg ?: sprintf(__('Unexpected CMI response: %s', 'cmi'), $cmi_response),
		);
	}

	private function send_cmi_xml_api_request(string $type, WC_Order $order, ?float $amount = null)
	{
		if (!$this->has_api_configuration()) {
			return new WP_Error('cmi_api_not_configured', __('Configure the CMI API URL, username, and password in WooCommerce settings before performing API operations.', 'cmi'));
		}

		$escape = static function (string $value): string {
			return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
		};

		$name = $escape($this->usernameapi);
		$password = $escape($this->passwordapi);
		$client_id = $escape($this->merchant_id);
		$cmi_oid = $this->get_sent_cmi_oid($order);
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

			// $amount is in MAD, like the authorised amount it is compared with.
			$mad_details = $this->get_order_mad_details($order);
			$authorised_mad = $mad_details !== null ? $mad_details['amount'] : (float) $order->get_total();
			if ($amount !== null && $amount < $authorised_mad) {
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
		} elseif ($type === 'ORDERHISTORY') {
			// CMI Order History specification: every transaction of the OrderId, one TRXn line each
			$xml = '<?xml version="1.0" encoding="UTF-8"?>' .
				'<CC5Request>' .
				'<Name>' . $name . '</Name>' .
				'<Password>' . $password . '</Password>' .
				'<ClientId>' . $client_id . '</ClientId>' .
				'<OrderId>' . $order_id . '</OrderId>' .
				'<Extra><ORDERHISTORY>QUERY</ORDERHISTORY></Extra>' .
				'</CC5Request>';
		} else {
			return new WP_Error('cmi_api_unknown_type', __('Unknown CMI API request type.', 'cmi'));
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
			return new WP_Error('cmi_api_http_error', sprintf(__('CMI API returned HTTP error %d.', 'cmi'), $http_code));
		}

		return $this->parse_cmi_xml_response($body);
	}

	private function parse_cmi_xml_response(string $body)
	{
		$body = trim($body);
		if ($body === '') {
			return new WP_Error('cmi_api_empty_response', __('CMI API returned an empty response. Check your API Gateway URL in settings.', 'cmi'));
		}

		// Detect if response is HTML (happens when gateway URL points to payment page instead of API endpoint)
		if (stripos($body, '<html') !== false || stripos($body, '<!doctype') !== false || stripos($body, '<head') !== false) {
			return new WP_Error(
				'cmi_api_html_response',
				__('CMI API returned an HTML page instead of XML. Make sure the "CMI API URL" in plugin settings is set to the XML API endpoint (e.g. https://testpayment.cmi.co.ma/fim/api) and NOT the hosted payment page (which ends with est3Dgate).', 'cmi')
			);
		}

		$fields = array('Response', 'ProcReturnCode', 'ErrMsg', 'OrderId', 'TransId', 'HostRefNum', 'AuthCode');
		$extra_fields = array('TRANS_STAT', 'CHARGE_TYPE_CD', 'AUTH_CODE', 'TRANS_ID', 'HOST_REF_NUM', 'CAPTURE_AMT', 'ORIG_TRANS_AMT', 'CAPTURE_DTTM', 'AUTH_DTTM', 'PAN', 'ORDERSTATUS');
		$result = array();

		// ORDERHISTORY: one <TRXn> line per transaction of the OrderId
		$result['HISTORY'] = array();
		if (preg_match_all('/<TRX(\d+)>(.*?)<\/TRX\1>/is', $body, $trx_matches)) {
			foreach ($trx_matches[2] as $line) {
				$result['HISTORY'][] = sanitize_text_field(trim((string) preg_replace('/\s+/', ' ', $line)));
			}
		}

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
				if (empty($result['ORIG_TRANS_AMT']) && preg_match('/ORIG_TRANS_AMT:([0-9]+)/i', $result['ORDERSTATUS'], $m)) {
					$result['ORIG_TRANS_AMT'] = trim($m[1]);
				}
				if (empty($result['AUTH_DTTM']) && preg_match('/AUTH_DTTM:(\d{4}-\d{2}-\d{2} [0-9:.]+)/i', $result['ORDERSTATUS'], $m)) {
					$result['AUTH_DTTM'] = trim($m[1]);
				}
				if (empty($result['TRANS_ID']) && preg_match('/TRANS_ID:([A-Za-z0-9]+)/i', $result['ORDERSTATUS'], $m)) {
					$result['TRANS_ID'] = trim($m[1]);
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
		if (empty($result['HostRefNum']) && !empty($result['HOST_REF_NUM'])) {
			$result['HostRefNum'] = $result['HOST_REF_NUM'];
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

		return new WP_Error('cmi_api_invalid_response', sprintf(__('CMI API returned an unexpected response: %s', 'cmi'), esc_html(substr(strip_tags($body), 0, 150))));
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
			? __('Your CMI payment was confirmed.', 'cmi')
			: ($status === self::PAYMENT_STATUS_PRE_CONFIRMATION_REQUIRED
				? __('Your payment was authorized and awaits merchant confirmation.', 'cmi')
				: ($status === self::PAYMENT_STATUS_VOIDED
					? __('The CMI transaction was voided by the merchant.', 'cmi')
					: ($status === self::PAYMENT_STATUS_CLIENT_CANCELLED
						? __('You cancelled the payment.', 'cmi')
						: __('The CMI payment was not completed.', 'cmi'))));
		echo '<section class="woocommerce-cmi-payment-result"><h2>' . esc_html__('CMI payment result', 'cmi') . '</h2><p><strong>' . esc_html($this->payment_status_label($status)) . '</strong> — ' . esc_html($message) . '</p></section>';
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
			define('BPI_ERR_AMOUNT_MISMATCH', __('Amount mismatch', 'cmi'));
		}
		if (!defined('BPI_ERR_BAD_ACCESS')) {
			define('BPI_ERR_BAD_ACCESS', __('Bad access of page', 'cmi'));
		}
		if (!defined('BPI_ERR_INVALID_SIGNATURE')) {
			define('BPI_ERR_INVALID_SIGNATURE', __('Security signature mismatch', 'cmi'));
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
	 * Uses the shop prefix + $order->get_order_number(), sanitized; falls back to the
	 * order ID if the number is empty.
	 *
	 * Allows custom override via 'woocommerce_cmi_order_id' filter.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	public function get_cmi_oid_for_order(WC_Order $order, bool $with_prefix = true): string
	{
		// Read the number without filter_order_number(), or the prefix would be added twice.
		self::$use_raw_order_number = true;
		$raw_number = (string) $order->get_order_number();
		self::$use_raw_order_number = false;

		$sanitized = $this->sanitize_cmi_oid($raw_number);

		// Fallback to internal order ID if sanitized order number is empty
		if ($sanitized === '') {
			$sanitized = (string) $order->get_id();
		}
		if ($with_prefix) {
			$sanitized = Cmi_Transaction_Guard::apply_oid_prefix($this->order_id_prefix, $sanitized);
		}

		// Third argument kept for existing filter callbacks (the format setting no longer exists).
		$filtered = apply_filters('woocommerce_cmi_order_id', $sanitized, $order, 'number');
		$final_oid = $this->sanitize_cmi_oid((string) $filtered);

		return $final_oid !== '' ? $final_oid : (string) $order->get_id();
	}

	/**
	 * Display a CMI order under the identifier CMI knows it by, everywhere WooCommerce
	 * shows an order number (orders list, order page, emails, customer account).
	 *
	 * @param string|int $number
	 * @param WC_Order   $order
	 * @return string|int
	 */
	public static function filter_order_number($number, $order)
	{
		if (self::$use_raw_order_number || !$order instanceof WC_Order) {
			return $number;
		}
		return Cmi_Transaction_Guard::order_number_to_display((string) $number, $order->get_payment_method(), (string) $order->get_meta('_cmi_oid'));
	}

	/**
	 * The OrderId this order was actually sent to CMI with.
	 *
	 * Orders created before '_cmi_oid' was stored were sent without a prefix, using
	 * the order number or, if the shop had chosen it in the now removed
	 * 'order_id_format' setting, the order ID. Recompute their identifier the old way.
	 */
	private function get_sent_cmi_oid(WC_Order $order): string
	{
		$cmi_oid = (string) $order->get_meta('_cmi_oid');
		if ($cmi_oid !== '') {
			return $cmi_oid;
		}
		if (($this->settings['order_id_format'] ?? '') === 'id') {
			return (string) $order->get_id();
		}
		return $this->get_cmi_oid_for_order($order, false);
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

		// 1. Lookup by '_cmi_oid' post/order meta (exact match saved during checkout).
		// This must come first: with sequential order numbers, a numeric oid such as
		// "1050" can also be the internal ID of a different order.
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

		// 2. Check third-party sequential order numbers plugin helper (e.g. WooCommerce Sequential Order Numbers)
		if (function_exists('wc_seq_order_number_to_order_id')) {
			$order_id = wc_seq_order_number_to_order_id($raw_oid);
			if ($order_id) {
				$order = wc_get_order($order_id);
				if ($order instanceof WC_Order) {
					return $order;
				}
			}
		}

		// 3. Fallback lookup by common sequential plugins meta keys (_order_number, _order_number_formatted)
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

		// 4. Last resort for orders created before '_cmi_oid' was stored: a numeric oid
		// as the internal order ID. Only accept a CMI order that has no other oid recorded.
		if (ctype_digit($raw_oid)) {
			$order = wc_get_order((int) $raw_oid);
			if ($order instanceof WC_Order && $order->get_payment_method() === $this->id) {
				$stored_oid = (string) $order->get_meta('_cmi_oid');
				if ($stored_oid === '' || $stored_oid === $raw_oid) {
					return $order;
				}
			}
		}

		return false;
	}

} // End Class
