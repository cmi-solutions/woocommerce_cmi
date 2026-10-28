<?php
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

	public static function is_terminal(string $status): bool
	{
		return in_array($status, array(self::FAILED, self::CLIENT_CANCELLED, self::VOIDED, self::REFUNDED, self::CONFIRMED), true);
	}
}
