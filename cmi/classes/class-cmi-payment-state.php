<?php
defined('ABSPATH') || exit;
/**
 * Provider-neutral CMI payment-state rules.
 *
 * These states are stored as order metadata. They deliberately do not replace
 * WooCommerce order statuses, which still describe fulfilment and payment in
 * the broader store workflow.
 */
final class Cmi_Payment_State
{
	public const INITIATED = 'initiated';
	public const FAILED = 'failed';
	public const CLIENT_CANCELLED = 'client_cancelled';
	public const VOIDED = 'voided';
	public const PRE_CONFIRMATION_REQUIRED = 'pre_confirmation_required';
	public const CONFIRMED = 'confirmed';
	public const REFUNDED = 'refunded';

	/** Past this age, the merchant is advised to inform the customer before capturing. */
	public const PREAUTH_WARNING_DAYS = 5;

	/** Seconds before each automatic status check of an order left pending in manual mode. */
	public const PENDING_CHECK_DELAYS = array(900, 3600, 21600);

	public static function from_callback(string $proc_return_code, string $confirmation_mode): string
	{
		if ($proc_return_code !== '00') {
			return self::FAILED;
		}
		return $confirmation_mode === 'manual' ? self::PRE_CONFIRMATION_REQUIRED : self::CONFIRMED;
	}

	public static function can_confirm(string $status): bool
	{
		return $status === self::PRE_CONFIRMATION_REQUIRED;
	}

	/**
	 * Whether to offer Confirm and Void for an order: only when it has a pre-authorization.
	 *
	 * A Pending order has no payment yet (the customer may still be paying, or gave up): it is
	 * only offered Check CMI, which moves it to On hold if CMI reports a pre-authorization.
	 * On hold without a stored payment state covers orders from before that state existed.
	 *
	 * @param string $payment_state   The order's CMI payment state ('' when not stored).
	 * @param string $order_status    WooCommerce order status, without "wc-".
	 * @param string $postauth_status Status the merchant set for captured orders.
	 */
	public static function can_offer_confirmation(string $payment_state, string $order_status, string $postauth_status): bool
	{
		if (in_array($order_status, array('processing', 'completed', 'confirmed', 'refunded', 'cancelled', 'failed', 'pending', $postauth_status), true)) {
			return false;
		}
		if (in_array($payment_state, array(self::CONFIRMED, self::FAILED, self::CLIENT_CANCELLED, self::VOIDED, self::REFUNDED), true)) {
			return false;
		}
		return $payment_state === self::PRE_CONFIRMATION_REQUIRED || $order_status === 'on-hold';
	}

	/**
	 * Whether CMI has captured the order's payment, so a refund can apply to it.
	 *
	 * A Pending, Failed or Cancelled order has no captured payment, and neither has a
	 * pre-authorization On hold (it is voided, not refunded).
	 *
	 * @param string $payment_state   The order's CMI payment state ('' when not stored).
	 * @param string $order_status    WooCommerce order status, without "wc-".
	 * @param bool   $is_paid         WC_Order::is_paid().
	 * @param string $postauth_status Status the merchant set for captured orders.
	 */
	public static function is_captured(string $payment_state, string $order_status, bool $is_paid, string $postauth_status): bool
	{
		return $payment_state === self::CONFIRMED
			|| $payment_state === self::REFUNDED
			|| $is_paid
			|| in_array($order_status, array('processing', 'completed', 'confirmed', 'refunded', $postauth_status), true);
	}

	public static function is_terminal(string $status): bool
	{
		return in_array($status, array(self::FAILED, self::CLIENT_CANCELLED, self::VOIDED, self::REFUNDED, self::CONFIRMED), true);
	}

	/**
	 * Full days elapsed since the pre-authorization if it is past the warning age, else 0.
	 *
	 * @param int $preauth_at Unix timestamp of the pre-authorization (0 when unknown).
	 */
	public static function preauth_warning_days(int $preauth_at, int $now): int
	{
		if ($preauth_at <= 0 || $now - $preauth_at <= self::PREAUTH_WARNING_DAYS * 86400) {
			return 0;
		}
		return (int) floor(($now - $preauth_at) / 86400);
	}

	/** Delay before the next automatic check, or null when all attempts have been made. */
	public static function pending_check_delay(int $checks_done): ?int
	{
		return self::PENDING_CHECK_DELAYS[$checks_done] ?? null;
	}

	/**
	 * Whether an automatic check should update a pending order from this TRANS_STAT: only for
	 * a final state of a real payment. "Not found", declined and in-progress results change
	 * nothing, because the customer may still be paying.
	 */
	public static function pending_check_applies(string $trans_stat): bool
	{
		return in_array(strtoupper(trim($trans_stat)), array('A', 'C', 'S', 'V'), true);
	}

	/**
	 * Whether the customer's Cancel link may cancel the order, given the status check result.
	 *
	 * Only when CMI confirms there is no live payment of this order. A pre-authorization,
	 * a capture or a transaction still in progress is never undone by this link, and when
	 * the status is unknown the order is left as it is.
	 *
	 * @param string $check_status Result of the CMI status check ('' when no check was possible).
	 */
	public static function customer_cancel_allowed(string $check_status): bool
	{
		return in_array($check_status, array('', 'NOT_FOUND', 'DECLINED', 'FOREIGN', 'VOIDED'), true);
	}

	/**
	 * Whether a signed CMI callback may still change an order in the given state.
	 *
	 * Callbacks can arrive late or be replayed (e.g. the failUrl POST of an earlier
	 * declined attempt). A decline must never overwrite an authorised, paid or closed
	 * transaction, and an approval must never reopen a voided or refunded one.
	 */
	public static function accepts_callback(string $current_status, bool $approved): bool
	{
		if ($approved) {
			return !in_array($current_status, array(self::VOIDED, self::REFUNDED), true);
		}
		return in_array($current_status, array('', self::INITIATED, self::FAILED), true);
	}

	/**
	 * Whether an ORDERSTATUS TRANS_STAT proves the payment was captured.
	 * Response=Approved / ProcReturnCode=00 alone only means the status query worked.
	 */
	public static function is_captured_trans_stat(string $trans_stat): bool
	{
		return in_array(strtoupper(trim($trans_stat)), array('C', 'S'), true);
	}
}
