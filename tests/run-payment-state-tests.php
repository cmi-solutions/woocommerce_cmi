<?php
/**
 * Test suite for WooCommerce CMI Gateway
 * Covers payment states, transitions, security signature calculation, and PostAuth/Void/Credit XML generation.
 */

// The plugin files only run inside WordPress (ABSPATH); these tests load them on purpose.
define('ABSPATH', __DIR__ . '/');

require_once __DIR__ . '/../cmi/classes/class-cmi-payment-state.php';
require_once __DIR__ . '/../cmi/classes/class-cmi-currency.php';
require_once __DIR__ . '/../cmi/classes/class-cmi-transaction-guard.php';

function assert_same($expected, $actual, string $message): void
{
	if ($expected !== $actual) {
		throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

function assert_true($condition, string $message): void
{
	if ($condition !== true) {
		throw new RuntimeException($message . ': condition expected to be true');
	}
}

function assert_false($condition, string $message): void
{
	if ($condition !== false) {
		throw new RuntimeException($message . ': condition expected to be false');
	}
}

echo "Running CMI Gateway Test Suite...\n\n";

// --- 1. Payment State Transitions ---
echo "1. Testing Payment State Transitions:\n";

// Successful callbacks
assert_same(
	Cmi_Payment_State::CONFIRMED,
	Cmi_Payment_State::from_callback('00', 'automatic'),
	'Successful automatic callback resolves to CONFIRMED'
);
assert_same(
	Cmi_Payment_State::PRE_CONFIRMATION_REQUIRED,
	Cmi_Payment_State::from_callback('00', 'manual'),
	'Successful manual callback resolves to PRE_CONFIRMATION_REQUIRED'
);

// Failed callbacks with various CMI rejection codes
$error_codes = array('05', '51', '99', '12', '01');
foreach ($error_codes as $code) {
	assert_same(
		Cmi_Payment_State::FAILED,
		Cmi_Payment_State::from_callback($code, 'automatic'),
		"Error code {$code} with automatic mode resolves to FAILED"
	);
	assert_same(
		Cmi_Payment_State::FAILED,
		Cmi_Payment_State::from_callback($code, 'manual'),
		"Error code {$code} with manual mode resolves to FAILED"
	);
}

// State confirmation eligibility
assert_true(
	Cmi_Payment_State::can_confirm(Cmi_Payment_State::PRE_CONFIRMATION_REQUIRED),
	'PRE_CONFIRMATION_REQUIRED state can be confirmed'
);
assert_false(
	Cmi_Payment_State::can_confirm(Cmi_Payment_State::CONFIRMED),
	'CONFIRMED state cannot be confirmed again (idempotent)'
);
assert_false(
	Cmi_Payment_State::can_confirm(Cmi_Payment_State::FAILED),
	'FAILED state cannot be confirmed'
);
assert_false(
	Cmi_Payment_State::can_confirm(Cmi_Payment_State::CLIENT_CANCELLED),
	'CLIENT_CANCELLED state cannot be confirmed'
);
assert_false(
	Cmi_Payment_State::can_confirm(Cmi_Payment_State::INITIATED),
	'INITIATED state cannot be confirmed'
);

// Terminal states
assert_true(Cmi_Payment_State::is_terminal(Cmi_Payment_State::CONFIRMED), 'CONFIRMED is a terminal state');
assert_true(Cmi_Payment_State::is_terminal(Cmi_Payment_State::FAILED), 'FAILED is a terminal state');
assert_true(Cmi_Payment_State::is_terminal(Cmi_Payment_State::CLIENT_CANCELLED), 'CLIENT_CANCELLED is a terminal state');
assert_false(Cmi_Payment_State::is_terminal(Cmi_Payment_State::INITIATED), 'INITIATED is not terminal');
assert_false(Cmi_Payment_State::is_terminal(Cmi_Payment_State::PRE_CONFIRMATION_REQUIRED), 'PRE_CONFIRMATION_REQUIRED is not terminal');

echo "  -> Payment state assertions passed.\n\n";

// --- 2. CMI SHA-512 Signature Hash Calculation ---
echo "2. Testing SHA-512 Signature Hash Algorithm:\n";

function calculate_cmi_hash(array $data, string $store_key): string
{
	$parameters = array_keys($data);
	natcasesort($parameters);
	$hash_value = '';
	foreach ($parameters as $param) {
		if (in_array(strtolower($param), array('hash', 'encoding'), true)) {
			continue;
		}
		$value = html_entity_decode(preg_replace("/\n$/", '', $data[$param]), ENT_QUOTES, 'UTF-8');
		$hash_value .= str_replace('|', '\\|', str_replace('\\', '\\\\', $value)) . '|';
	}
	$hash_value .= str_replace('|', '\\|', str_replace('\\', '\\\\', $store_key));
	return base64_encode(pack('H*', hash('sha512', $hash_value)));
}

$sample_params = array(
	'clientid' => '123456789',
	'amount' => '150.00',
	'oid' => '1001',
	'rnd' => 'test_random',
	'storetype' => '3d_pay_hosting',
	'email' => 'client@test.ma',
);
$store_key = 'SecretKey123!@#';

$calculated_hash = calculate_cmi_hash($sample_params, $store_key);
assert_true(!empty($calculated_hash), 'Hash calculation produces non-empty signature');

// Verify that tampering any param changes the hash
$tampered_params = $sample_params;
$tampered_params['amount'] = '10.00';
$tampered_hash = calculate_cmi_hash($tampered_params, $store_key);
assert_true($calculated_hash !== $tampered_hash, 'Tampered amount produces different hash');

// Verify that HASH and encoding parameters are excluded from the digest
$params_with_hash = $sample_params;
$params_with_hash['HASH'] = 'some_prior_hash';
$params_with_hash['encoding'] = 'UTF-8';
assert_same(
	$calculated_hash,
	calculate_cmi_hash($params_with_hash, $store_key),
	'HASH and encoding keys are correctly excluded from digest calculation'
);

echo "  -> Signature hash assertions passed.\n\n";

// --- 3. XML Payload (PostAuth, Void, Credit, ORDERSTATUS) Escaping Security & Structure ---
echo "3. Testing Outbound XML Payload (PostAuth, Void, Credit, ORDERSTATUS) Sanitization & Spec:\n";

function build_cmi_xml_spec(string $type, string $username, string $password, string $client_id, string $order_id, ?float $amount = null): string
{
	$escape = static function (string $value): string {
		return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	};

	$name = $escape($username);
	$pwd = $escape($password);
	$cid = $escape($client_id);
	$oid = $escape($order_id);

	if ($type === 'Void') {
		return '<?xml version="1.0" encoding="UTF-8"?>' .
			'<CC5Request>' .
			'<Name>' . $name . '</Name>' .
			'<Password>' . $pwd . '</Password>' .
			'<ClientId>' . $cid . '</ClientId>' .
			'<Type>Void</Type>' .
			'<OrderId>' . $oid . '</OrderId>' .
			'</CC5Request>';
	} elseif ($type === 'PostAuth') {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' .
			'<CC5Request>' .
			'<Name>' . $name . '</Name>' .
			'<Password>' . $pwd . '</Password>' .
			'<ClientId>' . $cid . '</ClientId>' .
			'<Type>PostAuth</Type>' .
			'<OrderId>' . $oid . '</OrderId>';
		if ($amount !== null) {
			$xml .= '<Total>' . $escape(number_format($amount, 2, '.', '')) . '</Total>';
		}
		$xml .= '</CC5Request>';
		return $xml;
	} elseif ($type === 'Credit') {
		$refund_amount = $amount !== null ? $amount : 100.00;
		return '<?xml version="1.0" encoding="UTF-8"?>' .
			'<CC5Request>' .
			'<Name>' . $name . '</Name>' .
			'<Password>' . $pwd . '</Password>' .
			'<ClientId>' . $cid . '</ClientId>' .
			'<Type>Credit</Type>' .
			'<Total>' . $escape(number_format($refund_amount, 2, '.', '')) . '</Total>' .
			'<Currency>504</Currency>' .
			'<OrderId>' . $oid . '</OrderId>' .
			'</CC5Request>';
	} elseif ($type === 'ORDERSTATUS') {
		return '<?xml version="1.0" encoding="UTF-8"?>' .
			'<CC5Request>' .
			'<Name>' . $name . '</Name>' .
			'<Password>' . $pwd . '</Password>' .
			'<ClientId>' . $cid . '</ClientId>' .
			'<OrderId>' . $oid . '</OrderId>' .
			'<Extra><ORDERSTATUS>QUERY</ORDERSTATUS></Extra>' .
			'</CC5Request>';
	}
	return '';
}

$malicious_input = '1001</OrderId><InjectedTag>attack</InjectedTag><OrderId>1001';

// Test PostAuth (whole amount)
$xml_postauth = build_cmi_xml_spec('PostAuth', 'admin', 'p&ssword<123>', 'client"1"', $malicious_input);
assert_true(strpos($xml_postauth, '&lt;InjectedTag&gt;') !== false, 'XML injection in PostAuth is escaped');
$parsed_postauth = simplexml_load_string($xml_postauth);
assert_true($parsed_postauth !== false, 'PostAuth XML is well-formed SimpleXMLElement');
assert_same((string)$parsed_postauth->Type, 'PostAuth', 'Type node is PostAuth');
assert_true(!isset($parsed_postauth->Total), 'Full PostAuth omits Total tag');

// Test PostAuth (partial amount)
$xml_postauth_part = build_cmi_xml_spec('PostAuth', 'admin', 'pass', 'client1', '1001', 50.00);
$parsed_postauth_part = simplexml_load_string($xml_postauth_part);
assert_same((string)$parsed_postauth_part->Total, '50.00', 'Partial PostAuth includes Total tag');

// Test Void
$xml_void = build_cmi_xml_spec('Void', 'admin', 'p&ssword<123>', 'client"1"', $malicious_input);
assert_true(strpos($xml_void, '&lt;InjectedTag&gt;') !== false, 'XML injection in Void is escaped');
$parsed_void = simplexml_load_string($xml_void);
assert_true($parsed_void !== false, 'Void XML is well-formed SimpleXMLElement');
assert_same((string)$parsed_void->Type, 'Void', 'Type node is Void');
assert_true(!isset($parsed_void->Total), 'Void omits Total tag per CMI spec');
assert_true(!isset($parsed_void->Currency), 'Void omits Currency tag per CMI spec');

// Test Credit (Refund)
$xml_credit = build_cmi_xml_spec('Credit', 'admin', 'p&ssword<123>', 'client"1"', $malicious_input, 150.00);
assert_true(strpos($xml_credit, '&lt;InjectedTag&gt;') !== false, 'XML injection in Credit is escaped');
$parsed_credit = simplexml_load_string($xml_credit);
assert_true($parsed_credit !== false, 'Credit XML is well-formed SimpleXMLElement');
assert_same((string)$parsed_credit->Type, 'Credit', 'Type node is Credit');
assert_same((string)$parsed_credit->Currency, '504', 'Credit currency is 504 (MAD)');
assert_same((string)$parsed_credit->Total, '150.00', 'Credit Total is formatted');

// Test ORDERSTATUS
$xml_status = build_cmi_xml_spec('ORDERSTATUS', 'admin', 'pass', 'client1', '1001');
$parsed_status = simplexml_load_string($xml_status);
assert_same((string)$parsed_status->Extra->ORDERSTATUS, 'QUERY', 'ORDERSTATUS query format is correct');

echo "  -> XML payload escaping and specification assertions passed.\n\n";

// --- 4. Order ID (OID) Sanitization & Sequential Formatting ---
echo "4. Testing Order ID (OID) Sanitization & Alphanumeric CMI Spec Compliance:\n";

function test_str_without_accents($str, $charset = 'utf-8')
{
	$str = htmlentities($str, ENT_NOQUOTES, $charset);
	$str = preg_replace('#&([A-za-z])(?:acute|cedil|caron|circ|grave|orn|ring|slash|th|tilde|uml);#', '\1', $str);
	$str = preg_replace('#&([A-za-z]{2})(?:lig);#', '\1', $str);
	$str = preg_replace('#&[^;]+;#', '', $str);
	$str = preg_replace('/[^a-zA-Z0-9_ -]/s', '', $str);
	return $str;
}

function test_sanitize_cmi_oid(string $raw_oid): string
{
	$clean = test_str_without_accents($raw_oid);
	$clean = preg_replace('/[^a-zA-Z0-9_-]/', '', $clean);
	return substr((string) $clean, 0, 64);
}

function test_resolve_oid(string $raw_order_number, int $internal_id): string
{
	$sanitized = test_sanitize_cmi_oid($raw_order_number);
	if ($sanitized === '') {
		$sanitized = (string) $internal_id;
	}
	return $sanitized;
}

// 4.1 Standard alphanumeric sequential formats
assert_same('CMD-2026-0001', test_sanitize_cmi_oid('CMD-2026-0001'), 'Preserves standard hyphenated sequential IDs');
assert_same('ORD_9942', test_sanitize_cmi_oid('ORD_9942'), 'Preserves underscores in order numbers');
assert_same('WC1024', test_sanitize_cmi_oid('WC1024'), 'Preserves simple alphanumeric IDs');

// 4.2 Accents stripping
assert_same('COMMANDE-ETE-2026', test_sanitize_cmi_oid('COMMANDE-ÉTÉ-2026'), 'Strips accents correctly');

// 4.3 Special characters and spaces stripping
assert_same('CMD20260001', test_sanitize_cmi_oid('CMD / 2026 # 0001!'), 'Strips spaces, slashes, hashes, and punctuation');
assert_same('ORD-12345PRO', test_sanitize_cmi_oid('ORD - 12345 (PRO)'), 'Strips brackets and whitespace around hyphens');

// 4.4 Max 64 characters length limitation per CMI specification
$long_oid = str_repeat('A', 80);
$sanitized_long = test_sanitize_cmi_oid($long_oid);
assert_same(64, strlen($sanitized_long), 'Enforces 64-character maximum length limit');

// 4.5 Fallback when sanitized string is empty or only special characters
assert_same('', test_sanitize_cmi_oid('!@#$%^&*()'), 'Empty string when all characters are invalid');
assert_same('42', test_resolve_oid('!@#$%^&*()', 42), 'Falls back to internal order ID when sanitized order number is empty');

// 4.6 The order number is used, also with sequential number plugins
assert_same('CMD-2026-0001', test_resolve_oid('CMD-2026-0001', 42), 'Uses the sequential order number');

// 4.7 Outbound XML with sequential alphanumeric OID
$xml_postauth_seq = build_cmi_xml_spec('PostAuth', 'admin', 'pass', 'client1', 'CMD-2026-0001');
$parsed_seq = simplexml_load_string($xml_postauth_seq);
assert_same((string)$parsed_seq->OrderId, 'CMD-2026-0001', 'PostAuth XML contains alphanumeric sequential OrderId');

// 4.8 SHA-512 Hash calculation with alphanumeric OID
$hash_seq = calculate_cmi_hash(array(
	'clientid' => '123456789',
	'amount' => '250.00',
	'oid' => 'CMD-2026-0001',
	'rnd' => 'test_random',
	'storetype' => '3d_pay_hosting',
), $store_key);
assert_true(!empty($hash_seq) && strlen($hash_seq) > 40, 'Hash calculates successfully with alphanumeric OID');

echo "  -> Order ID sanitization, formatting, and XML assertions passed.\n\n";

// --- 5. CMI XML API ORDERSTATUS Response Interpretation & State Sync ---
echo "5. Testing CMI XML API ORDERSTATUS Response Interpretation & State Synchronization:\n";

function test_interpret_orderstatus_response(array $response, string $confirmation_mode = 'automatic', string $postauth_status = 'completed', string $current_order_status = 'pending'): array
{
	$cmi_response = strtolower(trim($response['Response'] ?? ''));
	$proc_code    = trim($response['ProcReturnCode'] ?? '');
	$err_msg      = trim($response['ErrMsg'] ?? '');
	$trans_id     = trim($response['TransId'] ?? '');
	$auth_code    = trim($response['AuthCode'] ?? '');

	if ($proc_code === '00' || $cmi_response === 'approved') {
		// Existing processed/paid orders keep their current status
		if (in_array($current_order_status, array('completed', 'confirmed', 'processing', 'refunded', 'cancelled', $postauth_status), true)) {
			$target_status = $current_order_status;
		} elseif ($current_order_status === 'on-hold') {
			$target_status = 'on-hold';
		} else {
			$target_status = ($confirmation_mode === 'manual') ? 'on-hold' : $postauth_status;
		}

		return array(
			'status'        => 'APPROVED',
			'target_status' => $target_status,
			'auth_code'     => $auth_code,
			'trans_id'      => $trans_id,
		);
	}

	if ($proc_code !== '' && $proc_code !== '00') {
		return array(
			'status'        => 'DECLINED',
			'target_status' => ($current_order_status === 'pending') ? 'failed' : $current_order_status,
			'proc_code'     => $proc_code,
			'err_msg'       => $err_msg,
		);
	}

	if (stripos($err_msg, 'not found') !== false || ($cmi_response === 'error' && empty($proc_code))) {
		return array(
			'status'        => 'NOT_FOUND',
			'target_status' => $current_order_status,
		);
	}

	return array(
		'status'        => 'UNKNOWN',
		'target_status' => $current_order_status,
	);
}

// 5.1 Successful Recovery from Pending in Automatic Confirmation Mode (Default: completed)
$resp_approved = array(
	'Response'       => 'Approved',
	'ProcReturnCode' => '00',
	'AuthCode'       => '849201',
	'TransId'        => '260917123456',
	'OrderId'        => 'CMD-2026-0001',
	'ErrMsg'         => '',
);
$res_auto = test_interpret_orderstatus_response($resp_approved, 'automatic', 'completed', 'pending');
assert_same('APPROVED', $res_auto['status'], 'Status resolves to APPROVED');
assert_same('completed', $res_auto['target_status'], 'Pending order recovers to configured postauth completed status');
assert_same('849201', $res_auto['auth_code'], 'Captures correct AuthCode');
assert_same('260917123456', $res_auto['trans_id'], 'Captures correct TransId');

// 5.1b Successful PostAuth with Configured 'confirmed' Status
$res_auto_confirmed = test_interpret_orderstatus_response($resp_approved, 'automatic', 'confirmed', 'pending');
assert_same('confirmed', $res_auto_confirmed['target_status'], 'Configured confirmed status targets confirmed');

// 5.1c Successful PostAuth with Custom Merchant Status (e.g. 'validated')
$res_auto_custom = test_interpret_orderstatus_response($resp_approved, 'automatic', 'validated', 'pending');
assert_same('validated', $res_auto_custom['target_status'], 'Configured custom merchant status targets custom status');

// 5.2 Status Preservation: Querying an on-hold order keeps it on-hold (does NOT change to processing)
$res_onhold = test_interpret_orderstatus_response($resp_approved, 'automatic', 'completed', 'on-hold');
assert_same('on-hold', $res_onhold['target_status'], 'Querying an on-hold order preserves on-hold status');

// 5.2b Status Preservation: Querying a completed order keeps it completed (does NOT change to processing)
$res_completed = test_interpret_orderstatus_response($resp_approved, 'automatic', 'completed', 'completed');
assert_same('completed', $res_completed['target_status'], 'Querying a completed order preserves completed status');

// 5.2c Status Preservation: Querying a custom order keeps custom status
$res_custom_preserve = test_interpret_orderstatus_response($resp_approved, 'automatic', 'cmi-confirmed', 'cmi-confirmed');
assert_same('cmi-confirmed', $res_custom_preserve['target_status'], 'Querying an order in custom status preserves custom status');

// 5.2d Successful Recovery from Pending in Manual Confirmation Mode (set to on-hold)
$res_manual = test_interpret_orderstatus_response($resp_approved, 'manual', 'completed', 'pending');
assert_same('APPROVED', $res_manual['status'], 'Manual mode status resolves to APPROVED');
assert_same('on-hold', $res_manual['target_status'], 'Manual mode recovers pending to on-hold status for capture');

// 5.3 Declined by Bank (ProcReturnCode 51 Insufficient Funds)
$resp_declined = array(
	'Response'       => 'Declined',
	'ProcReturnCode' => '51',
	'AuthCode'       => '',
	'TransId'        => '',
	'OrderId'        => 'CMD-2026-0001',
	'ErrMsg'         => 'Insufficient Funds',
);
$res_declined = test_interpret_orderstatus_response($resp_declined);
assert_same('DECLINED', $res_declined['status'], 'Resolves to DECLINED');
assert_same('failed', $res_declined['target_status'], 'Declined targets failed status');
assert_same('51', $res_declined['proc_code'], 'Captures return code 51');

// 5.4 Testing CMI Official Specification Matrix (from CMI PDF)
function test_cmi_spec_status_mapping(string $trans_stat, string $charge_type_cd = 'S'): array
{
	if ($trans_stat === 'V') {
		return array('status' => 'VOIDED', 'cmi_status' => 'Voided', 'wc_status' => 'cancelled');
	}
	if ($charge_type_cd === 'C' && in_array($trans_stat, array('C', 'S'), true)) {
		return array('status' => 'REFUNDED', 'cmi_status' => 'Credited', 'wc_status' => 'refunded');
	}
	if ($trans_stat === 'A') {
		return array('status' => 'PRE_AUTHORIZED', 'cmi_status' => 'Approved (preauthorization)', 'wc_status' => 'on-hold');
	}
	if (in_array($trans_stat, array('C', 'S'), true)) {
		$label = ($trans_stat === 'S') ? 'Deposited / Settled' : 'Approved / Captured';
		return array('status' => 'APPROVED', 'cmi_status' => $label, 'wc_status' => 'completed');
	}
	if ($trans_stat === 'D') {
		return array('status' => 'DECLINED', 'cmi_status' => 'Declined', 'wc_status' => 'failed');
	}
	if ($trans_stat === 'PN') {
		return array('status' => 'PENDING_BANK', 'cmi_status' => 'Pending Bank Confirmation', 'wc_status' => 'pending');
	}
	if ($trans_stat === 'NW') {
		return array('status' => 'FIRST_COMMIT', 'cmi_status' => 'FirstCommit', 'wc_status' => 'pending');
	}
	if ($trans_stat === 'R') {
		return array('status' => 'REVERSAL', 'cmi_status' => 'Reversal Required', 'wc_status' => 'pending');
	}
	return array('status' => 'UNKNOWN', 'cmi_status' => 'Unknown', 'wc_status' => 'pending');
}

// 1. TRANS_STAT A -> Preauthorization (Shipment NO)
$spec_a = test_cmi_spec_status_mapping('A', 'S');
assert_same('PRE_AUTHORIZED', $spec_a['status'], 'TRANS_STAT A maps to PRE_AUTHORIZED');
assert_same('on-hold', $spec_a['wc_status'], 'TRANS_STAT A maps to on-hold (shipment not possible)');

// 2. TRANS_STAT C -> Captured (Shipment YES)
$spec_c = test_cmi_spec_status_mapping('C', 'S');
assert_same('APPROVED', $spec_c['status'], 'TRANS_STAT C maps to APPROVED');

// 3. TRANS_STAT S -> Settled / Deposited (Shipment YES)
$spec_s = test_cmi_spec_status_mapping('S', 'S');
assert_same('APPROVED', $spec_s['status'], 'TRANS_STAT S maps to APPROVED');
assert_same('Deposited / Settled', $spec_s['cmi_status'], 'TRANS_STAT S label is Deposited / Settled');

// 4. TRANS_STAT V -> Voided (Cancelled)
$spec_v = test_cmi_spec_status_mapping('V', 'S');
assert_same('VOIDED', $spec_v['status'], 'TRANS_STAT V maps to VOIDED');
assert_same('cancelled', $spec_v['wc_status'], 'TRANS_STAT V maps to cancelled');

// 5. CHARGE_TYPE_CD C -> Credited (Refunded)
$spec_refund = test_cmi_spec_status_mapping('C', 'C');
assert_same('REFUNDED', $spec_refund['status'], 'CHARGE_TYPE_CD C maps to REFUNDED');
assert_same('refunded', $spec_refund['wc_status'], 'CHARGE_TYPE_CD C maps to refunded');

// 6. TRANS_STAT D -> Declined
$spec_d = test_cmi_spec_status_mapping('D', 'S');
assert_same('DECLINED', $spec_d['status'], 'TRANS_STAT D maps to DECLINED');
assert_same('failed', $spec_d['wc_status'], 'TRANS_STAT D maps to failed');

// 7. Parse User's Sample XML from CMI Back-Office
$user_sample_xml = '<CC5Response>
	<ErrMsg>Record(s) found for ORDER-16056Q4hJ17661</ErrMsg>
	<ProcReturnCode>00</ProcReturnCode>
	<Response>Approved</Response>
	<OrderId>ORDER-16056Q4hJ17661</OrderId>
	<TransId>16056Q6HA17676</TransId>
	<Extra>
		<AUTH_CODE>073085</AUTH_CODE>
		<HOST_REF_NUM>605616000032</HOST_REF_NUM>
		<CAVV_3D/>
		<AUTH_DTTM>2016-02-25 16:56:06.995</AUTH_DTTM>
		<MDSTATUS>1</MDSTATUS>
		<ORD_ID>ORDER-16056Q4hJ17661</ORD_ID>
		<TRANS_ID>16056Q6HA17676</TRANS_ID>
		<CHARGE_TYPE_CD>S</CHARGE_TYPE_CD>
		<SETTLEID/>
		<TRANS_STAT>C</TRANS_STAT>
		<CAPTURE_DTTM>2016-02-25 16:56:06.995</CAPTURE_DTTM>
		<ORIG_TRANS_AMT>9196</ORIG_TRANS_AMT>
		<CAPTURE_AMT>9196</CAPTURE_AMT>
		<XID_3D/>
		<HOSTDATE>0225-165606</HOSTDATE>
		<PROC_RET_CD>00</PROC_RET_CD>
		<ECI_3D/>
		<ORDERSTATUS>
			ORD_ID:ORDER-16056Q4hJ17661 CHARGE_TYPE_CD:S ORIG_TRANS_AMT:9196 CAPTURE_AMT:9196 TRANS_STAT:C AUTH_DTTM:2016-02-25 16:56:06.995 CAPTURE_DTTM:2016-02-25 16:56:06.995 AUTH_CODE:073085 TRANS_ID:16056Q6HA17676
		</ORDERSTATUS>
		<PAN>4000 00** **** 0010</PAN>
		<NUMCODE>0</NUMCODE>
	</Extra>
</CC5Response>';
$xml_obj = simplexml_load_string($user_sample_xml);
assert_true($xml_obj !== false, 'Sample XML is valid SimpleXML');
assert_same('C', (string)$xml_obj->Extra->TRANS_STAT, 'Parsed TRANS_STAT is C');
assert_same('S', (string)$xml_obj->Extra->CHARGE_TYPE_CD, 'Parsed CHARGE_TYPE_CD is S');
assert_same('073085', (string)$xml_obj->Extra->AUTH_CODE, 'Parsed AUTH_CODE is 073085');
assert_same('9196', (string)$xml_obj->Extra->CAPTURE_AMT, 'Parsed CAPTURE_AMT is 9196');
assert_same('4000 00** **** 0010', (string)$xml_obj->Extra->PAN, 'Parsed masked PAN is correct');

// 5.4 Order Not Found / Abandoned (Customer closed browser without entering card)
$resp_not_found = array(
	'Response'       => 'Error',
	'ProcReturnCode' => '',
	'ErrMsg'         => 'Order not found',
);
$res_not_found = test_interpret_orderstatus_response($resp_not_found);
assert_same('NOT_FOUND', $res_not_found['status'], 'Resolves to NOT_FOUND when order was abandoned');

// 5.5 Duplicate payment attempt guard simulation
function test_should_block_repayment(array $status_check): bool
{
	return ($status_check['status'] ?? '') === 'APPROVED';
}
assert_true(test_should_block_repayment($res_auto), 'Blocks duplicate payment when order is already APPROVED');
assert_false(test_should_block_repayment($res_declined), 'Allows payment retry when order was DECLINED');
assert_false(test_should_block_repayment($res_not_found), 'Allows payment when transaction was NOT_FOUND');

echo "  -> ORDERSTATUS API response interpretation assertions passed.\n\n";

// --- 6. Custom Order Status Slug Sanitization & DB varchar(20) Limit ---
echo "6. Testing Custom Order Status Slug Sanitization & WordPress varchar(20) Limit:\n";

function test_sanitize_custom_status_slug(string $raw_slug): string
{
	$slug = trim($raw_slug);
	if (str_starts_with($slug, 'wc-')) {
		$slug = substr($slug, 3);
	}
	$slug = (string) preg_replace('/[\s_]+/', '-', $slug);
	$slug = strtolower($slug);
	$slug = preg_replace('/[^a-z0-9-]/', '', $slug);
	$slug = trim($slug, '-');
	if (strlen($slug) > 17) {
		$slug = substr($slug, 0, 17);
		$slug = rtrim($slug, '-');
	}
	return $slug;
}

// 6.1 User entered long sentence with spaces and parentheses
$raw_input = 'Payment is confirmed (waiting for delivry)';
$sanitized = test_sanitize_custom_status_slug($raw_input);
assert_true(strlen('wc-' . $sanitized) <= 20, "Total post_status with wc- prefix is <= 20 chars (got " . strlen('wc-' . $sanitized) . ")");
assert_same('payment-is-confir', $sanitized, "Sanitizes sentence into safe 17-char slug");

// 6.2 Strips wc- prefix if user typed it
assert_same('prep-order', test_sanitize_custom_status_slug('wc-prep-order'), "Strips redundant wc- prefix");

// 6.3 Standard short slugs untouched
assert_same('validated', test_sanitize_custom_status_slug('validated'), "Standard short slug untouched");
assert_same('expedie', test_sanitize_custom_status_slug('expedie'), "Standard short slug untouched");

echo "  -> Custom order status slug sanitization assertions passed.\n\n";

// --- 7. Late / Replayed Callback Guard ---
echo "7. Testing Late / Replayed Callback Guard:\n";

// A decline may only change an order that has no authorised or closed transaction
assert_true(Cmi_Payment_State::accepts_callback('', false), 'Decline accepted for a new order');
assert_true(Cmi_Payment_State::accepts_callback(Cmi_Payment_State::INITIATED, false), 'Decline accepted while INITIATED');
assert_true(Cmi_Payment_State::accepts_callback(Cmi_Payment_State::FAILED, false), 'Decline accepted after an earlier decline');
assert_false(Cmi_Payment_State::accepts_callback(Cmi_Payment_State::PRE_CONFIRMATION_REQUIRED, false), 'Replayed decline cannot overwrite an active pre-authorization');
assert_false(Cmi_Payment_State::accepts_callback(Cmi_Payment_State::CONFIRMED, false), 'Replayed decline cannot overwrite a captured payment');
assert_false(Cmi_Payment_State::accepts_callback(Cmi_Payment_State::VOIDED, false), 'Decline ignored for a voided transaction');
assert_false(Cmi_Payment_State::accepts_callback(Cmi_Payment_State::CLIENT_CANCELLED, false), 'Decline ignored for a cancelled order');

// An approval may not reopen a voided or refunded transaction
assert_true(Cmi_Payment_State::accepts_callback(Cmi_Payment_State::FAILED, true), 'Approval accepted after an earlier decline (retry succeeded)');
assert_true(Cmi_Payment_State::accepts_callback(Cmi_Payment_State::PRE_CONFIRMATION_REQUIRED, true), 'Duplicate approval is accepted (idempotent)');
assert_false(Cmi_Payment_State::accepts_callback(Cmi_Payment_State::VOIDED, true), 'Replayed approval cannot reopen a voided transaction');
assert_false(Cmi_Payment_State::accepts_callback(Cmi_Payment_State::REFUNDED, true), 'Replayed approval cannot reopen a refunded transaction');

// The customer Cancel link can never undo a payment (e.g. going back to the CMI page after paying)
foreach (array("PRE_AUTHORIZED", "APPROVED", "REFUNDED", "PARTIALLY_REFUNDED", "PENDING_BANK", "FIRST_COMMIT", "REVERSAL", "UNKNOWN", "ERROR") as $status) {
	assert_false(Cmi_Payment_State::customer_cancel_allowed($status), "Customer Cancel refused when the status check says $status");
}
foreach (array("", "NOT_FOUND", "DECLINED", "FOREIGN", "VOIDED") as $status) {
	assert_true(Cmi_Payment_State::customer_cancel_allowed($status), "Customer Cancel allowed when the status check says \"$status\"");
}
echo "  -> Callback guard assertions passed.\n\n";

// --- 8. ORDERSTATUS Capture Detection ---
echo "8. Testing ORDERSTATUS Capture Detection:\n";

assert_true(Cmi_Payment_State::is_captured_trans_stat('C'), 'TRANS_STAT C is captured');
assert_true(Cmi_Payment_State::is_captured_trans_stat('S'), 'TRANS_STAT S is captured (settled)');
assert_true(Cmi_Payment_State::is_captured_trans_stat(' c '), 'TRANS_STAT is trimmed and case-insensitive');
assert_false(Cmi_Payment_State::is_captured_trans_stat(''), 'Missing TRANS_STAT is never treated as captured');
assert_false(Cmi_Payment_State::is_captured_trans_stat('A'), 'Pre-authorization (A) is not captured');
assert_false(Cmi_Payment_State::is_captured_trans_stat('V'), 'Voided (V) is not captured');

echo "  -> Capture detection assertions passed.\n\n";

// --- 9. MAD Conversion & Multi-Currency Refunds ---
echo "9. Testing MAD Conversion & Multi-Currency Refunds:\n";

// 9.1 Rate parsing (manual exchange-rate table)
assert_same(10.8, Cmi_Currency::parse_rate('10.8'), 'Parses dot decimal rate');
assert_same(10.8, Cmi_Currency::parse_rate('10,8'), 'Parses comma decimal rate');
assert_same(0.0926, Cmi_Currency::parse_rate(' 0.0926 '), 'Parses rate with surrounding spaces');
assert_same(0.0, Cmi_Currency::parse_rate(''), 'Empty rate is invalid');
assert_same(0.0, Cmi_Currency::parse_rate('0'), 'Zero rate is invalid (would divide by zero)');
assert_same(0.0, Cmi_Currency::parse_rate('-5'), 'Negative rate is invalid');
assert_same(0.0, Cmi_Currency::parse_rate('abc'), 'Non-numeric rate is invalid');

// 9.2 Conversion: 100 EUR at 10.8 MAD/EUR
assert_same(1080.0, Cmi_Currency::to_mad(100.0, 10.8), '100 EUR converts to 1080 MAD');
assert_same(100.0, Cmi_Currency::from_mad(1080.0, 10.8), '1080 MAD converts back to 100 EUR');
assert_same(0.0, Cmi_Currency::from_mad(100.0, 0.0), 'Zero rate never divides by zero');

// 9.3 Full refund entered in MAD uses the exact remaining amounts on both sides
$full = Cmi_Currency::refund_from_mad(1080.0, 1080.0, 100.0, 10.8);
assert_same(1080.0, $full['mad'], 'Full refund sends the full MAD amount to CMI');
assert_same(100.0, $full['order'], 'Full refund records the full order amount in WooCommerce');
assert_true($full['full'], 'Full refund is flagged as full');

// 9.4 Partial refund entered in MAD
$partial = Cmi_Currency::refund_from_mad(540.0, 1080.0, 100.0, 10.8);
assert_same(540.0, $partial['mad'], 'Partial refund sends the MAD amount entered');
assert_same(50.0, $partial['order'], 'Partial refund records the converted order amount');
assert_false($partial['full'], 'Partial refund is not flagged as full');

// 9.5 Rounding: refunding the remaining balance after partials leaves no 0.01 leftover
$rate = 10.83; // 99.99 EUR -> 1082.89 MAD
$total_mad = Cmi_Currency::to_mad(99.99, $rate);
$first = Cmi_Currency::refund_from_mad(333.33, $total_mad, 99.99, $rate);
$second = Cmi_Currency::refund_from_mad($total_mad - $first['mad'], $total_mad - $first['mad'], 99.99 - $first['order'], $rate);
assert_same(round($total_mad, 2), round($first['mad'] + $second['mad'], 2), 'Partial + remaining refunds add up exactly to the MAD charged');
assert_same(99.99, round($first['order'] + $second['order'], 2), 'Partial + remaining refunds add up exactly to the order total');

// 9.6 WooCommerce native refund entered in the order currency
$native = Cmi_Currency::refund_from_order_currency(25.0, 100.0, 1080.0, 10.8);
assert_same(270.0, $native['mad'], 'Native 25 EUR refund sends 270 MAD to CMI');
assert_same(25.0, $native['order'], 'Native refund keeps the order-currency amount');
$native_full = Cmi_Currency::refund_from_order_currency(75.0, 75.0, 810.0, 10.8);
assert_true($native_full['full'], 'Native refund of the remaining balance is full');
assert_same(810.0, $native_full['mad'], 'Native full refund sends exactly the remaining MAD');

// 9.7 MAD orders are unchanged (rate 1)
$mad_refund = Cmi_Currency::refund_from_mad(40.0, 150.0, 150.0, 1.0);
assert_same(40.0, $mad_refund['mad'], 'MAD order: MAD refund amount unchanged');
assert_same(40.0, $mad_refund['order'], 'MAD order: order refund amount unchanged');

// 9.8 CURCY settings, in the format of its plugin source (lists: currency, currency_rate, currency_rate_fee)
$curcy = array(
	"currency_default"  => "MAD",
	"currency"          => array("MAD", "EUR"),
	"currency_rate"     => array("1", "0.09"),
	"currency_rate_fee" => array("0", "0"),
);
assert_same(round(1 / 0.09, 6), round(Cmi_Currency::rate_from_curcy_params($curcy, "EUR"), 6), "CURCY: MAD default, EUR rate 0.09 gives 11.11 MAD per EUR");
assert_same(1000.0, Cmi_Currency::to_mad(90.0, Cmi_Currency::rate_from_curcy_params($curcy, "EUR")), "CURCY: 90 EUR converts back to 1000 MAD");
$curcy_fee = array_merge($curcy, array("currency_rate_fee" => array("0", "0.01")));
assert_same(1000.0, Cmi_Currency::to_mad(100.0, Cmi_Currency::rate_from_curcy_params($curcy_fee, "EUR")), "CURCY: the rate fee is included, as in the price shown to the customer");
$curcy_eur_default = array("currency" => array("EUR", "MAD"), "currency_rate" => array("1", "10.8"), "currency_rate_fee" => array("0", "0"));
assert_same(1080.0, Cmi_Currency::to_mad(100.0, Cmi_Currency::rate_from_curcy_params($curcy_eur_default, "EUR")), "CURCY: EUR default with MAD as second currency");
assert_same(0.0, Cmi_Currency::rate_from_curcy_params(array("currency" => array("USD", "EUR"), "currency_rate" => array("1", "0.9")), "EUR"), "CURCY: MAD not configured gives no rate");
assert_same(0.0, Cmi_Currency::rate_from_curcy_params($curcy, "USD"), "CURCY: currency not configured gives no rate");
assert_same(0.0, Cmi_Currency::rate_from_curcy_params(array("currency" => array("MAD", "EUR"), "rate" => array("1", "0.09")), "EUR"), "CURCY: rates must come from currency_rate");
assert_same(0.0, Cmi_Currency::rate_from_curcy_params(array("currency" => array("MAD", "EUR"), "currency_rate" => array("1", "0")), "EUR"), "CURCY: a zero rate gives no rate");
assert_same(0.0, Cmi_Currency::rate_from_curcy_params(array(), "EUR"), "CURCY: empty settings give no rate");
// 9.9 WCML get_exchange_rates() format: rates per code, default currency = 1
assert_same(1000.0, Cmi_Currency::to_mad(90.0, Cmi_Currency::rate_from_map(array("MAD" => 1, "EUR" => "0.09"), "EUR")), "WCML: MAD default, 90 EUR is 1000 MAD");
assert_same(1080.0, Cmi_Currency::to_mad(100.0, Cmi_Currency::rate_from_map(array("EUR" => 1, "MAD" => 10.8), "EUR")), "WCML: EUR default, 100 EUR is 1080 MAD");
assert_same(1000.0, Cmi_Currency::to_mad(100.0, Cmi_Currency::rate_from_map(array("USD" => 1, "EUR" => 0.9, "MAD" => 9), "EUR")), "WCML: neither is the default, cross rate is used");
assert_same(0.0, Cmi_Currency::rate_from_map(array("USD" => 1, "EUR" => 0.9), "EUR"), "WCML: MAD not configured gives no rate");
assert_same(0.0, Cmi_Currency::rate_from_map(array("MAD" => 1), "EUR"), "WCML: currency not configured gives no rate");

// 9.10 Conversion through a plugin (Aelia filter)
assert_same(10.8123, round(Cmi_Currency::rate_from_conversion(1000.0, "10812.30", "EUR"), 6), "Aelia: a rounded result still gives an accurate rate");
assert_same(0.0, Cmi_Currency::rate_from_conversion(1000.0, 1000.0, "EUR"), "Aelia: amount unchanged means no rate (it would charge 1 EUR as 1 MAD)");
assert_same(1.0, Cmi_Currency::rate_from_conversion(1000.0, 1000.0, "MAD"), "Aelia: MAD to MAD is a real rate of 1");
assert_same(0.0, Cmi_Currency::rate_from_conversion(1000.0, null, "EUR"), "Aelia: no result gives no rate");
assert_same(0.0, Cmi_Currency::rate_from_conversion(1000.0, 0, "EUR"), "Aelia: zero gives no rate");
assert_same(0.0, Cmi_Currency::rate_from_conversion(1000.0, "abc", "EUR"), "Aelia: a non-numeric result gives no rate");
echo "  -> MAD conversion and refund assertions passed.\n\n";

// --- 10. Order-ID Prefix ---
echo "10. Testing Per-Shop Order-ID Prefix:\n";

$generated = Cmi_Transaction_Guard::generate_oid_prefix();
assert_true((bool) preg_match('/^WC[0-9A-F]{4}-$/', $generated), 'Generated prefix looks like WC7F3A- (got ' . $generated . ')');
assert_true($generated !== Cmi_Transaction_Guard::generate_oid_prefix() || $generated !== Cmi_Transaction_Guard::generate_oid_prefix(), 'Generated prefixes are random');
assert_same('SHOP2-', Cmi_Transaction_Guard::sanitize_oid_prefix('SHOP 2-'), 'Prefix keeps only letters, digits, - and _');
assert_same(20, strlen(Cmi_Transaction_Guard::sanitize_oid_prefix(str_repeat('A', 30))), 'Prefix is limited to 20 characters');
assert_same('WC7F3A-1050', Cmi_Transaction_Guard::apply_oid_prefix('WC7F3A-', '1050'), 'Prefix is added in front of the order number');
assert_same('1050', Cmi_Transaction_Guard::apply_oid_prefix('', '1050'), 'Empty prefix keeps the plain order number');
$long_prefixed = Cmi_Transaction_Guard::apply_oid_prefix('WC7F3A-', str_repeat('9', 80));
assert_same(64, strlen($long_prefixed), 'Prefixed OrderId respects the 64-character CMI limit');
assert_true(str_starts_with($long_prefixed, 'WC7F3A-'), 'The prefix is never truncated');

// WooCommerce shows the same number as the CMI back office
assert_same('WC202610-18', Cmi_Transaction_Guard::order_number_to_display('18', 'cmi', 'WC202610-18'), 'A CMI order is displayed under its CMI identifier');
assert_same('18', Cmi_Transaction_Guard::order_number_to_display('18', 'cmi', ''), 'A CMI order not yet sent keeps its plain number');
assert_same('19', Cmi_Transaction_Guard::order_number_to_display('19', 'cod', ''), 'Orders paid another way keep their plain number');
assert_same('19', Cmi_Transaction_Guard::order_number_to_display('19', 'cod', 'WC202610-19'), 'An order switched to another payment method keeps its plain number');

// Store Key rule from CMI: must not contain the word "SKS"
assert_true(Cmi_Transaction_Guard::is_valid_store_key("Ab12!xyZ_key"), "A normal Store Key is accepted");
assert_false(Cmi_Transaction_Guard::is_valid_store_key("MySKSkey123"), "A Store Key containing SKS is refused");
assert_false(Cmi_Transaction_Guard::is_valid_store_key("SKS"), "SKS alone is refused");
assert_false(Cmi_Transaction_Guard::is_valid_store_key("abcsks9"), "sks in lower case is refused too");
assert_true(Cmi_Transaction_Guard::is_valid_store_key("S-K-S"), "Separated letters are not the word SKS");
echo "  -> Order-ID prefix assertions passed.\n\n";

// --- 11. Transaction Ownership (OrderId collision protection) ---
echo "11. Testing Transaction Ownership Before PostAuth / Void / Credit:\n";

// Identifier sequence from the CMI documentation: PreAuth 17201OMPE17205, PostAuth 17201OSAE17750, Credit 17201OUPB17934.
// Each operation gets a new TransId, and after a PostAuth the PreAuth's TransId is no longer in ORDERHISTORY.
$sent_at = Cmi_Transaction_Guard::parse_cmi_datetime('2026-10-29 10:00:00');
$ours_callback_only = array('ids' => array('17201OMPE17205'), 'amount_minor' => 10000, 'sent_at' => $sent_at);
$ours_with_postauth = array('ids' => array('17201OMPE17205', '17201OSAE17750'), 'amount_minor' => 10000, 'sent_at' => $sent_at);

// 11.1 Fresh pre-authorization: ORDERSTATUS shows our own PreAuth
$status_ours = array('Response' => 'Approved', 'ProcReturnCode' => '00', 'TransId' => '17201OMPE17205', 'TRANS_ID' => '17201OMPE17205', 'TRANS_STAT' => 'A', 'CHARGE_TYPE_CD' => 'S', 'ORIG_TRANS_AMT' => '10000', 'AUTH_DTTM' => '2026-10-29 10:01:00.000');
assert_same(Cmi_Transaction_Guard::OWNED, Cmi_Transaction_Guard::ownership($status_ours, array(), $ours_callback_only), 'Own pre-authorization is recognised directly');

// 11.2 REGRESSION: order confirmed from WooCommerce, then refunded. ORDERSTATUS shows the PostAuth's
// new TransId, which differs from the callback's; the order must not be reported as foreign.
$status_after_postauth = array('Response' => 'Approved', 'TransId' => '17201OSAE17750', 'TRANS_ID' => '17201OSAE17750', 'TRANS_STAT' => 'C', 'CHARGE_TYPE_CD' => 'S', 'ORIG_TRANS_AMT' => '10000', 'AUTH_DTTM' => '2026-10-29 10:05:00.000');
assert_same(Cmi_Transaction_Guard::OWNED, Cmi_Transaction_Guard::ownership($status_after_postauth, array(), $ours_with_postauth), 'Confirmed through the API: the saved PostAuth TransId matches');
assert_same(Cmi_Transaction_Guard::OWNED, Cmi_Transaction_Guard::ownership($status_after_postauth, array(), $ours_callback_only), 'Captured by CMI itself (ACTION=POSTAUTH): new TransId, but amount and date match');

// 11.3 After a partial refund: ORDERSTATUS shows the Credit, the history shows our PostAuth
$status_after_refund = array('Response' => 'Approved', 'TransId' => '17201OUPB17934', 'TRANS_STAT' => 'C', 'CHARGE_TYPE_CD' => 'C', 'ORIG_TRANS_AMT' => '3000');
$history_ours = array(
	'C C 3000 3000 2017-05-20 14:20:15.118 2017-05-20 14:20:15.118 720114004948 217255 00 17201OUPB17934',
	'S C 10000 10000 2017-05-20 14:18:00.427 2017-05-20 14:18:00.427 720114004948 332816 00 17201OSAE17750',
);
assert_same(Cmi_Transaction_Guard::FOREIGN, Cmi_Transaction_Guard::ownership($status_after_refund, array(), $ours_with_postauth), 'Without history the Credit alone cannot be matched');
assert_same(Cmi_Transaction_Guard::OWNED, Cmi_Transaction_Guard::ownership($status_after_refund, $history_ours, $ours_with_postauth), 'ORDERHISTORY containing our PostAuth TransId proves ownership');

// 11.4 COLLISION: the OrderId belongs to the merchant's other website
$status_foreign = array('Response' => 'Approved', 'ProcReturnCode' => '00', 'TransId' => '16056Q6HA17676', 'HostRefNum' => '605616000032', 'TRANS_STAT' => 'C', 'CHARGE_TYPE_CD' => 'S', 'ORIG_TRANS_AMT' => '9196', 'AUTH_DTTM' => '2016-02-25 16:56:06.995');
$history_foreign = array('S C 9196 9196 2016-02-25 16:56:06.995 2016-02-25 16:56:06.995 605616000032 073085 00 16056Q6HA17676');
assert_same(Cmi_Transaction_Guard::FOREIGN, Cmi_Transaction_Guard::ownership($status_foreign, $history_foreign, $ours_callback_only), 'Another system\'s transaction (other identifiers, amount and date) is refused');
assert_same(Cmi_Transaction_Guard::FOREIGN, Cmi_Transaction_Guard::ownership($status_foreign, $history_foreign, array_merge($ours_callback_only, array('amount_minor' => 9196))), 'Another system\'s earlier transaction is refused even with the same amount');
assert_same(Cmi_Transaction_Guard::FOREIGN, Cmi_Transaction_Guard::ownership($status_foreign, array(), array('ids' => array('X'), 'amount_minor' => null, 'sent_at' => null)), 'Identifiers do not match and no amount to compare: refused');

// 11.5 Callback lost (no identifier saved): amount + date decide
$ours_no_ids = array('ids' => array(), 'amount_minor' => 9196, 'sent_at' => $sent_at);
assert_same(Cmi_Transaction_Guard::FOREIGN, Cmi_Transaction_Guard::ownership($status_foreign, array(), $ours_no_ids), 'Same amount but transaction made before this order was sent: refused');
$status_recent = array_merge($status_foreign, array('AUTH_DTTM' => '2026-10-29 10:03:12.120'));
assert_same(Cmi_Transaction_Guard::OWNED, Cmi_Transaction_Guard::ownership($status_recent, array(), $ours_no_ids), 'Same amount and made after this order was sent: accepted');
$ours_other_amount = array_merge($ours_no_ids, array('amount_minor' => 15000));
assert_same(Cmi_Transaction_Guard::FOREIGN, Cmi_Transaction_Guard::ownership($status_recent, array(), $ours_other_amount), 'Different amount: refused');
assert_same(Cmi_Transaction_Guard::OWNED, Cmi_Transaction_Guard::ownership($status_foreign, array(), array_merge($ours_no_ids, array('sent_at' => null))), 'Older order without send time: amount alone decides');
assert_same(Cmi_Transaction_Guard::UNVERIFIED, Cmi_Transaction_Guard::ownership($status_recent, array(), array('ids' => array(), 'amount_minor' => null, 'sent_at' => null)), 'Nothing to compare: unverified');

// 11.5b Clock margin: no return and no callback (manual mode) leaves only amount and date
$ours_margin = array("ids" => array(), "amount_minor" => 9196, "sent_at" => $sent_at);
$one_hour_before = array_merge($status_foreign, array("AUTH_DTTM" => "2026-10-29 09:00:00.000"));
assert_same(Cmi_Transaction_Guard::OWNED, Cmi_Transaction_Guard::ownership($one_hour_before, array(), $ours_margin), "1 hour apart (e.g. CMI using another time zone): still recognised as ours");
$three_hours_before = array_merge($status_foreign, array("AUTH_DTTM" => "2026-10-29 07:00:00.000"));
assert_same(Cmi_Transaction_Guard::FOREIGN, Cmi_Transaction_Guard::ownership($three_hours_before, array(), $ours_margin), "3 hours before this order was sent: refused");
// 11.6 Callback lost and partially refunded: the payment line is found in the history
$status_last_credit = array('Response' => 'Approved', 'TransId' => 'X1', 'TRANS_STAT' => 'C', 'CHARGE_TYPE_CD' => 'C', 'ORIG_TRANS_AMT' => '3000', 'AUTH_DTTM' => '2026-10-29 11:00:00.000');
$history_recent = array(
	'C C 3000 3000 2026-10-29 11:00:00.000 2026-10-29 11:00:00.000 999 111 00 X1',
	'S C 9196 9196 2026-10-29 10:03:12.120 2026-10-29 10:05:00.000 999 222 00 X0',
);
assert_same(Cmi_Transaction_Guard::OWNED, Cmi_Transaction_Guard::ownership($status_last_credit, $history_recent, $ours_no_ids), 'After a partial refund, the payment line in history matches amount and date');

// 11.7 CMI "not found"
assert_true(Cmi_Transaction_Guard::is_not_found(array('Response' => 'Error', 'ProcReturnCode' => '', 'ErrMsg' => 'Order not found')), 'Unknown OrderId is detected as not found');
assert_false(Cmi_Transaction_Guard::is_not_found($status_ours), 'Existing OrderId is not reported as not found');

// 11.7b Customer cancelled on the CMI payment page: no transaction was created.
// CMI's wording varies; a reply without any transaction data is "not found", never "another transaction".
$cancelled_replies = array(
	array('Response' => 'Error', 'ProcReturnCode' => '99', 'ErrMsg' => 'No record found for WCFA80-22'),
	array('Response' => 'Declined', 'ProcReturnCode' => '99', 'ErrMsg' => 'Transaction not available'),
	array('Response' => 'Approved', 'ProcReturnCode' => '00', 'ErrMsg' => '', 'TRANS_STAT' => '', 'TransId' => '', 'ORIG_TRANS_AMT' => ''),
);
foreach ($cancelled_replies as $i => $reply) {
	assert_true(Cmi_Transaction_Guard::is_not_found($reply), "Reply without transaction data #{$i} is treated as not found");
}
assert_false(Cmi_Transaction_Guard::is_not_found($status_foreign), 'A real transaction (another system\'s) is not "not found"');

// 11.8 Amounts are in centimes (doc: "Without decimal separator")
assert_same(10000, Cmi_Transaction_Guard::to_minor_units(100.00), '100.00 MAD = 10000 centimes');
assert_same(108289, Cmi_Transaction_Guard::to_minor_units(1082.89), '1082.89 MAD = 108289 centimes');

echo "  -> Transaction ownership assertions passed.\n\n";

// --- 12. Action Eligibility & Partial Refund Detection ---
echo "12. Testing Action Eligibility From CMI State & Partial Refund Detection:\n";

assert_same('', Cmi_Transaction_Guard::action_block_reason('confirm', 'A', 'S'), 'Confirm allowed on a pre-authorization');
assert_same('', Cmi_Transaction_Guard::action_block_reason('void', 'A', 'S'), 'Void allowed on a pre-authorization');
assert_same('already_captured', Cmi_Transaction_Guard::action_block_reason('confirm', 'C', 'S'), 'Confirm refused when already captured');
assert_same('already_captured', Cmi_Transaction_Guard::action_block_reason('void', 'S', 'S'), 'Void refused when already settled (refund instead)');
assert_same('voided', Cmi_Transaction_Guard::action_block_reason('confirm', 'V', 'S'), 'Confirm refused on a voided transaction');
assert_same('', Cmi_Transaction_Guard::action_block_reason('refund', 'C', 'S'), 'Refund allowed when captured');
assert_same('', Cmi_Transaction_Guard::action_block_reason('refund', 'S', 'S'), 'Refund allowed when settled');
assert_same('', Cmi_Transaction_Guard::action_block_reason('refund', 'C', 'C'), 'Further refund allowed after a partial refund');
assert_same('not_captured', Cmi_Transaction_Guard::action_block_reason('refund', 'A', 'S'), 'Refund refused on a pre-authorization (void instead)');
assert_same('unknown_state', Cmi_Transaction_Guard::action_block_reason('refund', '', ''), 'Missing TRANS_STAT blocks every action');

$totals = Cmi_Transaction_Guard::history_totals($history_ours);
assert_same(10000, $totals['captured'], 'History: captured amount from the PostAuth line');
assert_same(3000, $totals['refunded'], 'History: refunded amount from the Credit line');
assert_true($totals['refunded'] < $totals['captured'], 'Partial refund is detected (30.00 of 100.00 MAD)');
$voided_refund = array_merge($history_ours, array('C V 7000 7000 2017-05-21 09:00:00.000 2017-05-21 09:00:00.000 720114004948 1 00 Z9'));
assert_same(3000, Cmi_Transaction_Guard::history_totals($voided_refund)['refunded'], 'A voided refund is not counted');
$doc_history = array('S C 200000 200000 2017-07-19 14:37:46.75 2017-07-19 14:37:46.75 720014004885 939164 00 17200OluB17066');
assert_same(200000, Cmi_Transaction_Guard::history_totals($doc_history)['captured'], 'Parses the ORDERHISTORY sample from the CMI documentation');

echo "  -> Action eligibility and partial refund assertions passed.\n\n";

// --- 13. Warning Before Confirming an Old Pre-Authorization ---
echo "13. Testing Warning Before Confirming an Old Pre-Authorization:\n";

$now = 1_800_000_000;
$day = 86400;
assert_same(0, Cmi_Payment_State::preauth_warning_days($now - $day, $now), "1 day old: no warning");
assert_same(0, Cmi_Payment_State::preauth_warning_days($now - 5 * $day, $now), "Exactly 5 days old: no warning yet");
assert_same(5, Cmi_Payment_State::preauth_warning_days($now - 5 * $day - 1, $now), "Just past 5 days: warning");
assert_same(6, Cmi_Payment_State::preauth_warning_days($now - 6 * $day - 3600, $now), "6 days old: warning shows 6");
assert_same(0, Cmi_Payment_State::preauth_warning_days(0, $now), "Unknown date: no warning");

// The date comes from CMI (AUTH_DTTM format, Moroccan local time)
$cmi_auth = Cmi_Transaction_Guard::parse_cmi_datetime("2026-10-20 09:55:00.427");
assert_same(strtotime("2026-10-20 08:55:00 UTC"), $cmi_auth, "AUTH_DTTM is read as Moroccan time (GMT+1)");
assert_same(9, Cmi_Payment_State::preauth_warning_days($cmi_auth, strtotime("2026-10-30 07:55:00 UTC")), "Pre-authorized on CMI 10 days minus 1 hour ago: warning shows 9");
assert_same(0, Cmi_Payment_State::preauth_warning_days($cmi_auth, strtotime("2026-10-23 08:55:00 UTC")), "Pre-authorized on CMI 3 days ago: no warning");
// Automatic status checks of orders left pending in manual mode
assert_same(900, Cmi_Payment_State::pending_check_delay(0), "First check after 15 minutes");
assert_same(3600, Cmi_Payment_State::pending_check_delay(1), "Second check 1 hour later");
assert_same(21600, Cmi_Payment_State::pending_check_delay(2), "Third check 6 hours later");
assert_same(null, Cmi_Payment_State::pending_check_delay(3), "No more checks after that");
foreach (array("A", "C", "S", "V", " a ") as $stat) {
	assert_true(Cmi_Payment_State::pending_check_applies($stat), "Automatic check updates the order for TRANS_STAT \"$stat\"");
}
foreach (array("", "D", "NW", "PN", "R") as $stat) {
	assert_false(Cmi_Payment_State::pending_check_applies($stat), "Automatic check leaves the order alone for TRANS_STAT \"$stat\"");
}
echo "  -> Pre-authorization warning assertions passed.\n\n";
echo "ALL CMI TESTS COMPLETED SUCCESSFULLY.\n";


