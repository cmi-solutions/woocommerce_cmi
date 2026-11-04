<?php
defined('ABSPATH') || exit;
/**
 * Rules that protect CMI back-office operations against order-ID collisions.
 *
 * CMI's Void, PostAuth and Credit requests identify a transaction by OrderId only.
 * When the merchant's CMI account is also used by another system (e.g. an older
 * website), an OrderId may already belong to that system's transaction. Before any
 * operation, the plugin therefore checks with the read-only ORDERSTATUS / ORDERHISTORY
 * queries that the transaction exists and is this order's own payment.
 *
 * Kept free of WordPress calls so it can be unit tested.
 */
final class Cmi_Transaction_Guard
{
	public const OWNED = 'owned';
	public const FOREIGN = 'foreign';
	public const UNVERIFIED = 'unverified';

	/**
	 * Allowed difference between CMI's and the shop's clocks when comparing dates. Wide enough
	 * for a time zone difference, because CMI's documentation doesn't state its time zone.
	 * Another site's old transactions are normally far older than this.
	 */
	private const CLOCK_MARGIN_SECONDS = 7200;

	/** CMI back-office times are Moroccan local time. */
	private const CMI_TIMEZONE = 'Africa/Casablanca';

	private const PREFIX_MAX_LENGTH = 20;
	private const OID_MAX_LENGTH = 64;

	// ── Order-ID prefix ─────────────────────────────────────────────────

	/** Random prefix for a new install, e.g. "WC7F3A-". */
	public static function generate_oid_prefix(): string
	{
		return 'WC' . strtoupper(bin2hex(random_bytes(2))) . '-';
	}

	/** Keep only the characters CMI accepts in an OrderId; max 20 characters. */
	public static function sanitize_oid_prefix(string $prefix): string
	{
		$clean = (string) preg_replace('/[^a-zA-Z0-9_-]/', '', $prefix);
		return substr($clean, 0, self::PREFIX_MAX_LENGTH);
	}

	/** Prefix + base, trimming the base (never the prefix) to CMI's 64-character limit. */
	public static function apply_oid_prefix(string $prefix, string $base): string
	{
		$prefix = self::sanitize_oid_prefix($prefix);
		return $prefix . substr($base, 0, self::OID_MAX_LENGTH - strlen($prefix));
	}

	/** The number WooCommerce shows: the CMI identifier for orders sent to CMI, otherwise unchanged. */
	public static function order_number_to_display(string $number, string $payment_method, string $cmi_oid): string
	{
		return ($payment_method === 'cmi' && $cmi_oid !== '') ? $cmi_oid : $number;
	}

	/** CMI rule: the Store Key (hash key) must not contain the word "SKS", in any letter case. */
	public static function is_valid_store_key(string $key): bool
	{
		return stripos($key, 'SKS') === false;
	}

	// ── CMI response helpers ────────────────────────────────────────────

	/** Whether an ORDERSTATUS response describes an actual transaction. */
	public static function has_transaction(array $status): bool
	{
		foreach (array('TRANS_STAT', 'TRANS_ID', 'TransId', 'ORIG_TRANS_AMT') as $key) {
			if (trim((string) ($status[$key] ?? '')) !== '') {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an ORDERSTATUS response says no transaction exists for the OrderId.
	 *
	 * A customer who cancels on the CMI payment page leaves no transaction; CMI's reply
	 * wording varies ("not found", "No record found", ProcReturnCode 99...), so any reply
	 * without transaction data counts as not found. Such a reply cannot be another
	 * system's transaction either.
	 */
	public static function is_not_found(array $status): bool
	{
		$err_msg = (string) ($status['ErrMsg'] ?? '');
		$response = strtolower(trim((string) ($status['Response'] ?? '')));
		$proc_code = trim((string) ($status['ProcReturnCode'] ?? ''));
		if (stripos($err_msg, 'not found') !== false || stripos($err_msg, 'no record') !== false || ($response === 'error' && $proc_code === '')) {
			return true;
		}
		return !self::has_transaction($status);
	}

	/** MAD amount in centimes, the unit of ORIG_TRANS_AMT / CAPTURE_AMT. */
	public static function to_minor_units(float $amount): int
	{
		return (int) round($amount * 100);
	}

	/** Parse a CMI date ("2017-05-20 14:18:00.427") to a Unix timestamp. */
	public static function parse_cmi_datetime(string $value): ?int
	{
		$value = trim($value);
		if ($value === '') {
			return null;
		}
		try {
			$date = new DateTimeImmutable(preg_replace('/\.\d+$/', '', $value), new DateTimeZone(self::CMI_TIMEZONE));
			return $date->getTimestamp();
		} catch (Exception $e) {
			return null;
		}
	}

	/**
	 * Split ORDERHISTORY lines (TRX1..TRXn) into tokens.
	 * Each line starts with CHARGE_TYPE_CD, TRANS_STAT, ORIG_TRANS_AMT, CAPTURE_AMT.
	 *
	 * @param string[] $lines
	 * @return array<int, string[]>
	 */
	public static function history_tokens(array $lines): array
	{
		$rows = array();
		foreach ($lines as $line) {
			$tokens = preg_split('/\s+/', trim((string) $line), -1, PREG_SPLIT_NO_EMPTY);
			if ($tokens) {
				$rows[] = $tokens;
			}
		}
		return $rows;
	}

	/**
	 * Captured and refunded totals (centimes) from ORDERHISTORY lines.
	 *
	 * @return array{captured: int, refunded: int}
	 */
	public static function history_totals(array $lines): array
	{
		$captured = 0;
		$refunded = 0;
		foreach (self::history_tokens($lines) as $tokens) {
			if (count($tokens) < 4 || !in_array(strtoupper($tokens[1]), array('C', 'S'), true) || !ctype_digit($tokens[3])) {
				continue;
			}
			if (strtoupper($tokens[0]) === 'S') {
				$captured += (int) $tokens[3];
			} elseif (strtoupper($tokens[0]) === 'C') {
				$refunded += (int) $tokens[3];
			}
		}
		return array('captured' => $captured, 'refunded' => $refunded);
	}

	// ── Ownership ──────────────────────────────────────────────────────

	/**
	 * Decide whether the CMI transaction behind an OrderId is this order's own payment.
	 *
	 * @param array    $status  Parsed ORDERSTATUS response.
	 * @param string[] $history ORDERHISTORY lines (may be empty when not fetched).
	 * @param array    $ours    ids (string[], TransId / HostRefNum values from our signed callback
	 *                          and our own API requests), amount_minor (?int, MAD centimes sent),
	 *                          sent_at (?int, when first sent to CMI).
	 */
	public static function ownership(array $status, array $history, array $ours): string
	{
		$own_ids = array_values(array_filter(array_map('strval', (array) ($ours['ids'] ?? array()))));

		// 1. Identifiers we received ourselves: the transaction carries one of them.
		if ($own_ids) {
			$cmi_ids = array(
				(string) ($status['TransId'] ?? ''),
				(string) ($status['TRANS_ID'] ?? ''),
				(string) ($status['HostRefNum'] ?? ''),
				(string) ($status['HOST_REF_NUM'] ?? ''),
			);
			foreach (self::history_tokens($history) as $tokens) {
				$cmi_ids = array_merge($cmi_ids, $tokens);
			}
			if (array_intersect($own_ids, $cmi_ids)) {
				return self::OWNED;
			}
		}

		// 2. No identifier matches. That alone doesn't prove it is foreign: each operation
		// gets a new TransId, the PreAuth's is no longer listed after a PostAuth, and CMI
		// itself captures when answered ACTION=POSTAUTH. Compare the amount and date of the
		// payment transaction (CHARGE_TYPE_CD S), from ORDERSTATUS or, after a refund, the
		// history. Another system's earlier transaction fails this.
		$amount_minor = $ours['amount_minor'] ?? null;
		if ($amount_minor === null) {
			return $own_ids ? self::FOREIGN : self::UNVERIFIED;
		}
		$candidates = array();
		if (strtoupper(trim((string) ($status['CHARGE_TYPE_CD'] ?? ''))) !== 'C') {
			$candidates[] = array((string) ($status['ORIG_TRANS_AMT'] ?? ''), (string) ($status['AUTH_DTTM'] ?? ''));
		}
		foreach (self::history_tokens($history) as $tokens) {
			if (count($tokens) >= 6 && strtoupper($tokens[0]) === 'S') {
				$candidates[] = array($tokens[2], $tokens[4] . ' ' . $tokens[5]);
			}
		}
		$sent_at = $ours['sent_at'] ?? null;
		foreach ($candidates as list($cmi_amount, $auth_dttm)) {
			$cmi_amount = trim($cmi_amount);
			if ($cmi_amount === '' || !ctype_digit($cmi_amount) || (int) $cmi_amount !== (int) $amount_minor) {
				continue;
			}
			$auth_time = self::parse_cmi_datetime($auth_dttm);
			if ($sent_at !== null && $auth_time !== null && $auth_time < (int) $sent_at - self::CLOCK_MARGIN_SECONDS) {
				continue;
			}
			return self::OWNED;
		}
		return self::FOREIGN;
	}

	// ── Action eligibility ─────────────────────────────────────────────

	/**
	 * Why CMI's current transaction state does not allow an action ('' when allowed).
	 *
	 * @param string $action confirm | void | refund
	 * @return string '' | not_preauthorized | already_captured | not_captured | voided | unknown_state
	 */
	public static function action_block_reason(string $action, string $trans_stat, string $charge_type_cd): string
	{
		$trans_stat = strtoupper(trim($trans_stat));
		$charge_type_cd = strtoupper(trim($charge_type_cd));
		if ($trans_stat === '') {
			return 'unknown_state';
		}
		if ($trans_stat === 'V') {
			return 'voided';
		}
		$captured = in_array($trans_stat, array('C', 'S'), true);

		if ($action === 'refund') {
			// After a partial refund the last transaction is a Credit (CHARGE_TYPE_CD C): still refundable.
			return $captured ? '' : 'not_captured';
		}
		if ($trans_stat === 'A') {
			return '';
		}
		return ($captured && $charge_type_cd !== 'C') ? 'already_captured' : 'not_preauthorized';
	}
}
