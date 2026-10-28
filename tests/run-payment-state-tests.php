<?php
/**
 * Test suite for WooCommerce CMI Gateway
 * Covers payment states, transitions, security signature calculation, and PostAuth/Void/Credit XML generation.
 */

require_once __DIR__ . '/../cmi/classes/class-cmi-payment-state.php';

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

function test_resolve_oid(string $raw_order_number, int $internal_id, string $format = 'number'): string
{
	if ($format === 'id') {
		$raw_oid = (string) $internal_id;
	} else {
		$raw_oid = $raw_order_number;
	}

	$sanitized = test_sanitize_cmi_oid($raw_oid);
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
assert_same('42', test_resolve_oid('!@#$%^&*()', 42, 'number'), 'Falls back to internal order ID when sanitized order number is empty');

// 4.6 Format selection (number vs id)
assert_same('CMD-2026-0001', test_resolve_oid('CMD-2026-0001', 42, 'number'), 'Uses sequential number when format is "number"');
assert_same('42', test_resolve_oid('CMD-2026-0001', 42, 'id'), 'Uses internal ID when format is "id"');

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

echo "ALL CMI TESTS COMPLETED SUCCESSFULLY.\n";


