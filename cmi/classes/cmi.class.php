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











function utf8entities($source)
{
	//    array used to figure what number to decrement from character order value 
//    according to number of characters used to map unicode to ascii by utf-8
	$decrement[4] = 240;
	$decrement[3] = 224;
	$decrement[2] = 192;
	$decrement[1] = 0;

	//    the number of bits to shift each charNum by
	$shift[1][0] = 0;
	$shift[2][0] = 6;
	$shift[2][1] = 0;
	$shift[3][0] = 12;
	$shift[3][1] = 6;
	$shift[3][2] = 0;
	$shift[4][0] = 18;
	$shift[4][1] = 12;
	$shift[4][2] = 6;
	$shift[4][3] = 0;

	$pos = 0;
	$len = strlen($source);
	$encodedString = '';
	while ($pos < $len) {
		$charPos = substr($source, $pos, 1);
		$asciiPos = ord($charPos);
		if ($asciiPos < 128) {
			$encodedString .= htmlentities($charPos);
			$pos++;
			continue;
		}

		$i = 1;
		if (($asciiPos >= 240) && ($asciiPos <= 255))  // 4 chars representing one unicode character
			$i = 4;
		else if (($asciiPos >= 224) && ($asciiPos <= 239))  // 3 chars representing one unicode character
			$i = 3;
		else if (($asciiPos >= 192) && ($asciiPos <= 223))  // 2 chars representing one unicode character
			$i = 2;
		else  // 1 char (lower ascii)
			$i = 1;
		$thisLetter = substr($source, $pos, $i);
		$pos += $i;

		//       process the string representing the letter to a unicode entity
		$thisLen = strlen($thisLetter);
		$thisPos = 0;
		$decimalCode = 0;
		while ($thisPos < $thisLen) {
			$thisCharOrd = ord(substr($thisLetter, $thisPos, 1));
			if ($thisPos == 0) {
				$charNum = intval($thisCharOrd - $decrement[$thisLen]);
				$decimalCode += ($charNum << $shift[$thisLen][$thisPos]);
			} else {
				$charNum = intval($thisCharOrd - 128);
				$decimalCode += ($charNum << $shift[$thisLen][$thisPos]);
			}

			$thisPos++;
		}

		$encodedLetter = '&#' . str_pad($decimalCode, ($thisLen == 1) ? 3 : 5, '0', STR_PAD_LEFT) . ';';
		$encodedString .= $encodedLetter;
	}

	return $encodedString;
}



class WC_Gateway_cmi extends WC_Payment_Gateway
{

	public $version = '1.8.1';
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
	protected string $redirection_mode;
	protected string $cardholder_authentication;
	// protected string $okFailRequest;
	protected string $callback;
	protected string $passwordapi;
	protected string $apigateway;
	protected string $usernameapi;
	protected string $exchange_rates;
	protected string $response_url;

	public function __construct()
	{
		global $woocommerce;
		$this->id = 'cmi';
		$this->method_title = __('cmi', 'CMI - CMI');
		$this->icon = $this->plugin_url() . '/assets/images/cb.png';
		$this->has_fields = true;
		$this->debug_email = get_option('admin_email');

		// Setup available countries.
		$this->available_countries = array('FR');

		// Setup available currency codes.
		$this->available_currencies = array('MAD');

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Setup constants.
		$this->setup_constants();

		// Setup default merchant data.
		$this->merchant_id = $this->settings['merchant_id'];
		$this->actionslk = $this->settings['actionslk'];
		$this->SLKSecretkey = $this->settings['SLKSecretkey'];
		$this->title = $this->settings['title'];
		if (isset($this->settings['confirmation_mode']) && $this->settings['confirmation_mode']) {
			$this->confirmation_mode = $this->settings['confirmation_mode'];
		}
		// var_dump($this->settings); 
		if (isset($this->settings['redirection_mode']) && $this->settings['redirection_mode']) {
			$this->redirection_mode = $this->settings['redirection_mode'];
		}
		if (isset($this->settings['cardholder_authentication']) && $this->settings['cardholder_authentication']) {
			$this->cardholder_authentication = $this->settings['cardholder_authentication'];
		}
		// if(isset($this->settings['okFailRequest']) && $this->settings['okFailRequest']){
		// $this->okFailRequest = $this->settings['okFailRequest'];
		// }
		if (isset($this->settings['callback']) && $this->settings['callback']) {
			$this->callback = $this->settings['callback'];
		}
		if (isset($this->settings['apigateway']) && $this->settings['apigateway']) {
			$this->apigateway = $this->settings['apigateway'];
		}
		if (isset($this->settings['passwordapi']) && $this->settings['passwordapi']) {
			$this->passwordapi = $this->settings['passwordapi'];
		}
		if (isset($this->settings['usernameapi']) && $this->settings['usernameapi']) {
			$this->usernameapi = $this->settings['usernameapi'];
		}
		if (isset($this->settings['exchange_rates']) && $this->settings['exchange_rates']) {
			$this->exchange_rates = $this->settings['exchange_rates'];
		}


		// $this->apigateway = $this->settings['apigateway'];
		// $this->passwordapi = $this->settings['passwordapi'];
		// $this->usernameapi = $this->settings['usernameapi'];
		// $this->exchange_rates = $this->settings['exchange_rates'];

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

		add_action('woocommerce_api_wc_gateway_cmi', array($this, 'check_CMI_response'));
		add_action('valid-cmi-standard-CMI-request', array($this, 'successful_request'));
		/* 1.0.1 */
		add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));
		/* 1.0 */
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'save_currency_details'));
		add_action('woocommerce_receipt_cmi', array($this, 'receipt_page'));
		add_action('admin_enqueue_scripts', array($this, 'wpse_load_plugin_css'));
		add_action('woocommerce_thankyou', array($this, 'confirmation_page'));


		// Check if the base currency supports this gateway.
		if (!$this->is_valid_for_use())
			$this->enabled = false;
	}
	function confirmation_page($order_id)
	{
		//create an order instance
		$order = wc_get_order($order_id); //<--check this line

		$paymethod = $order->payment_method_title;
		$callbackMode = $this->settings['callback'];
		$confirmationMode = $this->settings['confirmation_mode'];
		// $okFailRequest = trim($this->settings['okFailRequest']);
		if ($callbackMode == "2") {
			$orderstat = $order->get_status();
			if (($orderstat == 'pending') && ($paymethod == 'CMI')) {
				$msg = 'Paiement accepté par le CMI';
				// Payment completed
				$order->add_order_note(__($msg, 'woothemes'));
				$order->payment_complete();
				if ($confirmationMode == "yes") {
					$clientId = $this->settings['merchant_id'];
					$apigateway = $this->settings['apigateway'];
					$passwordapi = $this->settings['passwordapi'];
					$usernameapi = $this->settings['usernameapi'];
					$xml = '<?xml version="1.0" encoding="UTF-8"?>
<CC5Request>
	<Name>' . $usernameapi . '</Name>
	<Password>' . $passwordapi . '</Password>
	<ClientId>' . $clientId . '</ClientId>
	<Type>PostAuth</Type>     
	<OrderId>' . $order_id . '</OrderId>
</CC5Request>';
					$url = $apigateway;
					$ch = curl_init();
					curl_setopt($ch, CURLOPT_URL, $url);

					// For xml, change the content-type.
					curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: text/xml"));

					curl_setopt($ch, CURLOPT_POST, 1);
					curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);

					curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // ask for results to be returned

					// Send to remote and return data to caller.
					curl_exec($ch);
					curl_close($ch);
				}
			}
		}

	}
	/**
	 * Initialise Gateway Settings Form Fields
	 *
	 * @since 1.0.0
	 */
	function init_form_fields()
	{
		$this->form_fields = array(
			'enabled' => array(
				'title' => __('Enable/Disable', 'woothemes'),
				'label' => __('Enable cmi', 'woothemes'),
				'type' => 'checkbox',
				'description' => __('This controls whether or not this gateway is enabled within WooCommerce.', 'woothemes'),
				'default' => 'yes'
			),
			'title' => array(
				'title' => __('Title', 'woothemes'),
				'type' => 'text',
				'description' => __('This controls the title which the user sees during checkout.', 'woothemes'),
				'default' => __('CMI', 'woothemes')
			),
			'description' => array(
				'title' => __('Description', 'woothemes'),
				'type' => 'text',
				'description' => __('This controls the description which the user sees during checkout.', 'woothemes'),
				'default' => ''
			),
			'merchant_id' => array(
				'title' => __('ClientId', 'woothemes'),
				'type' => 'text',
				'description' => __('Identifiant marchand fourni par CMI.', 'woothemes'),
				'default' => ''
			),
			'SLKSecretkey' => array(
				'title' => __('Clé de hachage', 'woothemes'),
				'type' => 'password',
				'description' => __('Vous êtes appelés à renseigner la clé à travers votre espace back office de la plate-formeI.', 'woothemes'),
				'default' => ''
			),
			'actionslk' => array(
				'title' => __('Gateway ', 'woothemes'),
				'type' => 'text',
				'description' => __('URL de la passerelle fourni par CMI.', 'woothemes'),
				'default' => ''
			),
			'callback' => array(
				'title' => __('Callback Mode', 'woothemes'),
				'type' => 'select',
				'description' => __('Manière de mettre à jour la base de données.', 'woothemes'),
				'default' => '0',
				'options' => array(
					'0' => 'Server-to-server',
					'1' => 'Redirection automatique '
				)
			),
			'apigateway' => array(
				'title' => __('Gateway URL API ', 'woothemes'),
				'type' => 'text',
				'description' => __('Gateway URL API fourni par CMI.', 'woothemes'),
				'default' => '',
			),
			'usernameapi' => array(
				'title' => __('Username API ', 'woothemes'),
				'type' => 'text',
				'description' => __('Username API fourni par CMI.', 'woothemes'),
				'default' => '',
			),
			'passwordapi' => array(
				'title' => __('Password API ', 'woothemes'),
				'type' => 'password',
				'description' => __('Password API fourni par CMI.', 'woothemes'),
				'default' => '',
			),
			'confirmation_mode' => array(
				'title' => __('Mode de confirmation', 'woothemes'),
				'type' => 'checkbox',
				'label' => __('Confirmation automatique des transactions CMI.', 'woothemes'),
				'default' => 'yes'
			),
			'redirection_mode' => array(
				'title' => __('Mode de redirection', 'woothemes'),
				'type' => 'checkbox',
				'label' => __('Redirection automatique vers page de confirmation de paiement.', 'woothemes'),
				'default' => 'no'
			),

			'cardholder_authentication' => array(
				'title' => __('Cardholder Authentication ', 'woothemes'),
				'type' => 'select',
				'description' => __('Activer / désactiver l\'authentification du titulaire de la carte.', 'woothemes'),
				'default' => '0',
				'options' => array(
					'0' => 'Yes',
					'1' => 'No'
				)
			),
			/*'okFailRequest' => array(
							'title' => __( 'Methode de la requête OK/FAIL', 'woothemes' ),
							'type' => 'select',
							'default' => '0',
							'options' => array(
											'0' => 'POST',
											'1'   => 'GET'
										)
						),*/
			'exchange_rates' => array(
				'title' => __('Exchange rates updates', 'woothemes'),
				'id' => 'alg_currency_switcher_exchange_rate_update',
				'default' => '',
				'type' => 'select',
				'class' => 'wc-enhanced-select',
				'options' => array(
					'' => __('Choose exchange rates', 'woothemes'),
					'manual' => __('Enter Rates Manually', 'woothemes')
				),
			),
			'currency_details' => array(
				'type' => 'currency_details',
			)
		);
		wc_enqueue_js('
			jQuery( "#woocommerce_cmi_apigateway" ).parents("tr").hide();
			jQuery( "#woocommerce_cmi_usernameapi" ).parents("tr").hide();
			jQuery( "#woocommerce_cmi_passwordapi" ).parents("tr").hide();
				  jQuery("#woocommerce_cmi_apigateway").removeAttr("required");
				  jQuery("#woocommerce_cmi_usernameapi").removeAttr("required");
				  jQuery("#woocommerce_cmi_passwordapi").removeAttr("required");
			if( jQuery("select#woocommerce_cmi_callback").val() == "0"){
				  jQuery( "#woocommerce_cmi_apigateway" ).parents("tr").hide();
				  jQuery( "#woocommerce_cmi_usernameapi" ).parents("tr").hide();
					jQuery( "#woocommerce_cmi_passwordapi" ).parents("tr").hide();
				  jQuery("#woocommerce_cmi_apigateway").removeAttr("required");
				  jQuery("#woocommerce_cmi_usernameapi").removeAttr("required");
				  jQuery("#woocommerce_cmi_passwordapi").removeAttr("required");
			  } else {
				  jQuery("#woocommerce_cmi_apigateway").prop("required",true);
				  jQuery("#woocommerce_cmi_usernameapi").prop("required",true);
				  jQuery("#woocommerce_cmi_passwordapi").prop("required",true);
				  jQuery( "#woocommerce_cmi_apigateway" ).parents("tr").show();
				  jQuery( "#woocommerce_cmi_usernameapi" ).parents("tr").show();
					jQuery( "#woocommerce_cmi_passwordapi" ).parents("tr").show();
			  }
			jQuery("select#woocommerce_cmi_callback").on("change", function() {
			  if( this.value == "0"){
				  jQuery("#woocommerce_cmi_apigateway").removeAttr("required");
				  jQuery("#woocommerce_cmi_usernameapi").removeAttr("required");
				  jQuery("#woocommerce_cmi_passwordapi").removeAttr("required");
				  jQuery("#woocommerce_cmi_passwordapi").removeAttr("required");
				  jQuery( "#woocommerce_cmi_apigateway" ).parents("tr").hide();
				  jQuery( "#woocommerce_cmi_usernameapi" ).parents("tr").hide();
					jQuery( "#woocommerce_cmi_passwordapi" ).parents("tr").hide();
			  } else {
				  jQuery("#woocommerce_cmi_apigateway").prop("required",true);
				  jQuery("#woocommerce_cmi_usernameapi").prop("required",true);
				  jQuery("#woocommerce_cmi_passwordapi").prop("required",true);
				  jQuery( "#woocommerce_cmi_apigateway" ).parents("tr").show();
				  jQuery( "#woocommerce_cmi_usernameapi" ).parents("tr").show();
					jQuery( "#woocommerce_cmi_passwordapi" ).parents("tr").show();
			  }
			});
			if( jQuery("select#woocommerce_cmi_exchange_rates").val() == "manual"){
			  jQuery( "#cmi_currencies" ).parents("tr").show();
			} else {
			  jQuery( "#cmi_currencies" ).parents("tr").hide();
			}
			jQuery("select#woocommerce_cmi_exchange_rates").on("change", function() {
			  if( this.value == "manual"){
				 jQuery( "#cmi_currencies" ).parents("tr").show();
			  } else {
				  jQuery( "#cmi_currencies" ).parents("tr").hide();
			  }
			});
			jQuery( "#cmi_currencies" ).parents("form").submit(function( event ) {
			if( jQuery("select#woocommerce_cmi_exchange_rates").val() == "manual"){
				  if (!jQuery("input[name*=\'cmi_currency_rate\']").length) {
					alert("Please add a currency");
					event.preventDefault(); 
				} else {
					jQuery("input[name*=\'cmi_currency_rate\']").each(function() {
					  if( !$(this).val() ) {
						alert("Currency rate must not be empty");
						event.preventDefault(); 
					  }
					});
				}
			}
			});
			if (!jQuery("#woocommerce_cmi_toggle_SLKSecretkey").length) {
				jQuery( "#woocommerce_cmi_SLKSecretkey" ).after( \'<span toggle="#woocommerce_cmi_SLKSecretkey"  id="woocommerce_cmi_toggle_SLKSecretkey" class="fa fa-fw fa-eye-slash field-icon toggle-password"></span>\' );
			}
			if (!jQuery("#woocommerce_cmi_toggle_passwordapi").length) {
				jQuery( "#woocommerce_cmi_passwordapi" ).after( \'<span toggle="#woocommerce_cmi_passwordapi" id="woocommerce_cmi_toggle_passwordapi" class="fa fa-fw fa-eye-slash field-icon toggle-password"></span>\' );
			}
			jQuery(".toggle-password").click(function() {
				jQuery(this).toggleClass("fa-eye-slash fa-eye");
				var input = jQuery(jQuery(this).attr("toggle"));
				if (input.attr("type") == "password") {
					input.attr("type", "text");
				} else {
					input.attr("type", "password");
				}
			});
		');

	} // End init_form_fields()
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

		if (is_ssl()) {
			return $this->plugin_url = str_replace('http://', 'https://', WP_PLUGIN_URL) . "/" . plugin_basename(dirname(dirname(__FILE__)));
		} else {
			return $this->plugin_url = WP_PLUGIN_URL . "/" . plugin_basename(dirname(dirname(__FILE__)));
		}
	} // End plugin_url()

	/**
	 * is_valid_for_use()
	 *
	 * Check if this gateway is enabled and available in the base currency being traded with.
	 *
	 * @since 1.0.0
	 */
	function is_valid_for_use()
	{
		global $woocommerce;

		$is_available = false;

		$user_currency = get_option('woocommerce_currency');

		$is_available_currency = in_array($user_currency, $this->available_currencies);

		if ($this->enabled == 'yes' && $this->settings['merchant_id'] != '')
			$is_available = true;
		return $is_available;
	} // End is_valid_for_use()

	/**
	 * Admin Panel Options
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 *
	 * @since 1.0.0
	 */
	public function admin_options()
	{

		?>
		<h3><?php _e('CMI', 'CMI - CMI'); ?></h3>

		<?php
		?>
		<table class="form-table"><?php
		// Generate the HTML For the settings form.
		$this->generate_settings_html();
		?></table><!--/.form-table-->
		<?php
	} // End admin_options()
	function cmi_get_exchange_rate_free_currency_api($currency_from, $currency_to)
	{
		if (!class_exists("SoapClient")) {
			return 0;
		}
		$url = add_query_arg(array(
			'q' => $currency_from . '_' . $currency_to,
			'compact' => 'ultra',
			'apiKey' => 'REMOVED',
		), 'https://free.currconv.com/api/v7/convert');

		$json = $this->cmi_get_currency_exchange_rates_url_response($url);
		if (property_exists($json, $currency_from . '_' . $currency_to)) {
			$cur_from_to = $currency_from . '_' . $currency_to;
			return $json->$cur_from_to;
		} else {
			return 0;
		}
	}
	function cmi_get_currency_exchange_rates_url_response($url, $do_json_decode = true)
	{
		$response = '';
		if (function_exists('curl_version')) {
			$curl = curl_init($url);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($curl, CURLOPT_SSLVERSION, 'all');
			$response = curl_exec($curl);
			curl_close($curl);
		} elseif (ini_get('allow_url_fopen')) {
			$response = file_get_contents($url);
		}
		return ('' != $response ? ($do_json_decode ? json_decode($response) : $response) : false);
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

		global $woocommerce;
		$okFailRequest = array();
		$order = new WC_Order($order_id);

		$shipping_name = explode(' ', $order->shipping_method);
		$user_currency = get_option('woocommerce_currency');

		$return_url = urldecode($this->get_return_url($order));
		$cancel_url = urldecode($order->get_cancel_order_url());
		$notify_url = urldecode($this->response_url);




		$totalAmountTx = $order->order_total;
		//Verify if data come from cmi
		$dataMD5 = $this->settings['actionslk'] . $order->order_total . $this->settings['merchant_id'] . $order->id . $order->billing_email . $this->settings['SLKSecretkey'];
		$checksum = MD5(utf8entities(rawurlencode($dataMD5)));
		// Construct variables for post
		$lang = get_locale();
		$msg_redirect = '';
		if (strpos($lang, "ar") !== false) {
			$lang = "ar";
			$msg_redirect = 'شكرا لطلبك. ستتم إعادة توجيهك إلى CMI لإجراء الدفع.';
		} else if (strpos($lang, "fr") !== false) {
			$lang = "fr";
			$msg_redirect = 'Merci pour votre commande. vous serez redirigé vers CMI pour effectuer le paiement.';
		} else {
			$lang = "en";
			$msg_redirect = 'Thank you for your order. you will be redirected to CMI to make the payment.';
		}

		$cardholder_authentication = trim($this->settings['cardholder_authentication']);
		if ($cardholder_authentication == "1") {
			$storetype = "PAYHOSTING";
		} else {
			$storetype = "3DPAYHOSTING";
		}
		$redirectionMode = $this->settings['redirection_mode'];

		if ($redirectionMode == "yes")
			$redirectionMode = "1";
		else
			$redirectionMode = "2";
		$data = array(
			'clientid' => $this->settings['merchant_id'],
			'lang' => $lang,
			'rnd' => microtime(),
			'storetype' => $storetype,
			'hashAlgorithm' => "ver3",
			'tranType' => "PreAuth",
			'email' => $order->billing_email,
			'BillToName' => $this->str_without_accents(trim($order->billing_first_name . " " . $order->billing_last_name)),
			'BillToStreet1' => $this->str_without_accents(trim($order->billing_address_1)),
			'BillToCity' => $this->str_without_accents(trim($order->billing_city)),
			'BillToStateProv' => $this->str_without_accents(trim($order->billing_state)),
			'BillToCountry' => $this->str_without_accents(trim($order->billing_country)),
			'BillToPostalCode' => $this->str_without_accents(trim($order->billing_postcode)),
			'BillToTelVoice' => $this->str_without_accents(trim($order->billing_phone)),
			'oid' => $order->id,
			'refreshtime' => "5",
			'instalment' => "",
			'amount' => $totalAmountTx,
			'shopurl' => $cancel_url,
			'currency' => "504",
			'failUrl' => $cancel_url,
			'encoding' => "UTF-8"
		);
		$okFailRequest = trim((string) ($this->settings['okFailRequest'] ?? ''));
		// var_dump($okFailRequest);
		// if($okFailRequest == "1"){ 
		// $data['redirectGet'] = "true";		
		// }

		if ($this->settings['callback'] == "1") {
			$data['AutoRedirect'] = "true";
			$data['okUrl'] = $notify_url;
			$data['CallbackResponse'] = false;

		} else {
			if ($redirectionMode == "1") {
				$data['AutoRedirect'] = "true";
			} else {
				$data['AutoRedirect'] = "false";
			}
			$data['okUrl'] = $return_url;
			$data['CallbackResponse'] = true;
			$data['callbackUrl'] = $notify_url;
		}
		$current_currency = get_woocommerce_currency();
		if ($current_currency != "MAD") {
			if ($this->settings['exchange_rates'] == "manual") {
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
			} else if ($this->settings['exchange_rates'] == "automatic") {
				$currency_from = "MAD";
				$currency_to = $current_currency;
				$atmcRate = $this->cmi_get_exchange_rate_free_currency_api($currency_from, $currency_to);
				$data['amount'] = number_format(floatval(($totalAmountTx * 1) / $atmcRate), 2, '.', '');
				$data['amountCur'] = $totalAmountTx;
				$data['symbolCur'] = $current_currency;
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

		$escapedStoreKey = str_replace("|", "\\|", str_replace("\\", "\\\\", $this->settings['SLKSecretkey']));
		$hashval = $hashval . $escapedStoreKey;

		$calculatedHashValue = hash('sha512', $hashval);
		$hash = base64_encode(pack('H*', $calculatedHashValue));

		$data['hash'] = $hash;

		$this->data_to_send = $data;
		$cmi_args_array = array();

		foreach ($this->data_to_send as $key => $value) {
			$cmi_args_array[] = '<input type="hidden" name="' . $key . '" value="' . trim($value) . '" />';
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
		return '<form action="' . $this->settings['actionslk'] . '" method="post" id="cmi_payment_form" target="_top">
				' . implode('', $cmi_args_array) . '
				<!-- Button Fallback -->
				<div class="payment_buttons">
					<input type="submit" class="button alt" id="submit_cmi_payment_form" value="' . __('Payez via cmi', 'woocommerce') . '" /> <a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Annuler la commande', 'woocommerce') . '</a>
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

		$order = new WC_Order($order_id);

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
		echo '<p>' . __($msg_redirect2, 'woothemes') . '</p>';

		echo $this->generate_cmi_form($order);
	} // End receipt_page()

	/**
	 * Check cmi CMI validity.
	 *
	 * @param array $data
	 * @since 1.0.0
	 */
	function check_CMI_request_is_valid($data)
	{

		global $woocommerce;
		$post = $_POST;
		// if(isset($this->settings['okFailRequest']) && $this->settings['okFailRequest']){

		// if($this->settings['okFailRequest'] == "1"){ 
		// $data = $_GET;	
		// $post = $_GET;	
		// } else {
		// $post = $_POST;
		// }
		// }

		$pfError = false;
		$pfDone = false;

		$vendor_name = get_option('blogname');
		$vendor_url = home_url('/');

		$order_id = (int) $data['oid'];
		$order = new WC_Order($order_id);
		$order_key = $order->order_key;




		$postParams = array();

		foreach ($post as $key => $value) {
			array_push($postParams, $key);
		}
		// array_diff_key($post,['wc-api','']);
		// $post = array_diff_key($post, array('wc-api' => true));
// echo "<pre>";
// var_dump($this->okFailRequest);
// echo "</pre>";
		natcasesort($postParams);

		$hashval = "";
		foreach ($postParams as $param) {

			$paramValue = html_entity_decode(preg_replace("/\n$/", "", $post[$param]), ENT_QUOTES, 'UTF-8');

			$escapedParamValue = str_replace("|", "\\|", str_replace("\\", "\\\\", $paramValue));

			$lowerParam = strtolower($param);
			if ($lowerParam != "hash" && $lowerParam != "encoding") {
				$hashval = $hashval . $escapedParamValue . "|";
			}

		}
		$escapedStoreKey = str_replace("|", "\\|", str_replace("\\", "\\\\", $this->settings['SLKSecretkey']));
		$hashval = $hashval . $escapedStoreKey;

		// var_dump($escapedStoreKey);
// var_dump($hashval);
// exit;

		$calculatedHashValue = hash('sha512', $hashval);
		$actualHash = base64_encode(pack('H*', $calculatedHashValue));
		// var_dump($actualHash);
		// var_dump($data['HASH']);
		// exit;





		// Get data sent by cmi
		if (!$pfError && !$pfDone) {

			if ($data === false) {
				$pfError = true;
				$pfErrMsg = BPI_ERR_BAD_ACCESS;
			}
		}

		// Verify security checksum
		if (!$pfError && !$pfDone) {
			// if(isset($this->settings['okFailRequest']) && $this->settings['okFailRequest']){
			// if($this->settings['okFailRequest'] == "1"){ 
			// $data['HASH'] = $_GET['HASH'];		
			// }
			// }
			if ($actualHash != $data['HASH']) {
				$pfError = true;
				$pfErrMsg = BPI_ERR_INVALID_SIGNATURE;

				echo "INVALID HASH";
				exit;
			}
		}



		// Get internal order and verify it hasn't already been processed
		if (!$pfError && !$pfDone) {

			// Check if order has already been processed
			if ($order->status == 'completed') {
				$pfDone = true;
				echo "Approved";
				exit;
			}
		}



		// Check status and update order
		if (!$pfError && !$pfDone) {

			if ($order->order_key !== $order_key) {
				exit;
			}
		}


		return $pfError;
	} // End check_CMI_request_is_valid()

	/**
	 * Check cmi CMI response.
	 *
	 * @since 1.0.0
	 */
	function check_CMI_response()
	{
		//$_POST = stripslashes_deep( $_REQUEST );
		if (!$this->check_CMI_request_is_valid($_POST)) {
			do_action('valid-cmi-standard-CMI-request', $_POST);
		}
	} // End check_CMI_response()

	/**
	 * Successful Payment!
	 *
	 * @since 1.0.0
	 */
	function successful_request($posted)
	{
		$posted = $_POST;
		// if($this->settings['okFailRequest'] == "1"){ 
		// $posted = $_GET;
		// } 
		if ($posted['ProcReturnCode'] != "00") {
			$order->update_status('Failed', __('Payment Error  - Error: Payment Gateway Declined order.', 'wptut'));
			$order->add_order_note('Payment failed<br/>Payment Gateway Message: ' . $posted['ErrMsg']);

			return false;
			exit;
		} else {

			$order = new WC_Order($posted['oid']);



			if ($order->status !== 'completed') {
				// We are here so lets check status and do actions

				$msg = 'Paiement accepté par le CMI';

				// Payment completed
				$order->add_order_note(__($msg, 'woothemes'));
				$order->payment_complete();
				// var_dump($this->settings['confirmation_mode']);
				// var_dump($this->settings['callback']);exit;
				//wp_redirect( $this->get_return_url( $order ) );
				if ($this->settings['callback'] == "0" || $this->settings['callback'] == "2") {
					if ($this->settings['confirmation_mode'] == "yes")
						echo "ACTION=POSTAUTH";
					else
						echo "APPROVED";
				} else if ($this->settings['callback'] == "1") {
					if ($this->settings['confirmation_mode'] == "yes") {
						$xml = '<?xml version="1.0" encoding="UTF-8"?>
<CC5Request>
	<Name>' . $this->settings['usernameapi'] . '</Name>
	<Password>' . $this->settings['passwordapi'] . '</Password>
	<ClientId>' . $this->settings['merchant_id'] . '</ClientId>
	<Type>PostAuth</Type>     
	<OrderId>' . $posted["ReturnOid"] . '</OrderId>
</CC5Request>';
						$url = $this->settings['apigateway'];
						$ch = curl_init();
						curl_setopt($ch, CURLOPT_URL, $url);

						// For xml, change the content-type.
						curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: text/xml"));

						curl_setopt($ch, CURLOPT_POST, 1);
						curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);

						curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // ask for results to be returned

						// Send to remote and return data to caller.
						curl_exec($ch);
						curl_close($ch);
					}
					wp_redirect($this->get_return_url($order));
				}
				exit;
			} // End IF Statement

			exit;

		}
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
		global $woocommerce;
		if (!defined('BPI_SOFTWARE_NAME')) {
			define('BPI_SOFTWARE_NAME', 'WooCommerce');
		}
		if (!defined('BPI_SOFTWARE_VER')) {
			define('BPI_SOFTWARE_VER', $woocommerce->version);
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

} // End Class