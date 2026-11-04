<?php
defined('ABSPATH') || exit;
/**
 * MAD conversion rules for CMI payments.
 *
 * CMI always charges in MAD. When an order is in another currency, the MAD amount
 * and the rate used are recorded on the order at payment time, and refunds are
 * calculated from those recorded values so they always match what CMI charged.
 *
 * Rates are expressed as "MAD per 1 unit of the order currency" (e.g. 10.8 for EUR).
 */
final class Cmi_Currency
{
	public const MAD = 'MAD';

	/** Amounts below this are treated as zero (rounding tolerance). */
	private const EPSILON = 0.005;

	/**
	 * Parse a rate typed by the merchant ("10,8", "10.8", " 10.80 ").
	 *
	 * @return float The rate, or 0.0 when it is not a positive number.
	 */
	public static function parse_rate($raw): float
	{
		$value = str_replace(array(',', ' '), array('.', ''), trim((string) $raw));
		if ($value === '' || !is_numeric($value)) {
			return 0.0;
		}
		$rate = (float) $value;
		return ($rate > 0 && is_finite($rate)) ? $rate : 0.0;
	}

	/**
	 * MAD per 1 unit of a currency, from CURCY's saved settings (option woo_multi_currency_params).
	 *
	 * CURCY keeps parallel lists: 'currency', 'currency_rate' and 'currency_rate_fee'. Its
	 * effective rate is rate + fee, which is what it used to calculate the customer's price.
	 *
	 * @return float The rate, or 0.0 when the currency or MAD has no valid rate.
	 */
	public static function rate_from_curcy_params(array $params, string $currency): float
	{
		$currencies = (array) ($params['currency'] ?? array());
		$rates = (array) ($params['currency_rate'] ?? array());
		$fees = (array) ($params['currency_rate_fee'] ?? array());

		$from = array_search($currency, $currencies, true);
		$mad = array_search(self::MAD, $currencies, true);
		if ($from === false || $mad === false) {
			return 0.0;
		}
		$from_rate = (float) ($rates[$from] ?? 0) + (float) ($fees[$from] ?? 0);
		$mad_rate = (float) ($rates[$mad] ?? 0) + (float) ($fees[$mad] ?? 0);
		return ($from_rate > 0 && $mad_rate > 0) ? $mad_rate / $from_rate : 0.0;
	}

	/**
	 * MAD per 1 unit of a currency, from a map of rates per currency code (all relative to
	 * the same default currency). The format of WCML's get_exchange_rates().
	 *
	 * @return float The rate, or 0.0 when the currency or MAD has no valid rate.
	 */
	public static function rate_from_map(array $rates, string $currency): float
	{
		$from_rate = (float) ($rates[$currency] ?? 0);
		$mad_rate = (float) ($rates[self::MAD] ?? 0);
		return ($from_rate > 0 && $mad_rate > 0) ? $mad_rate / $from_rate : 0.0;
	}

	/**
	 * MAD per 1 unit of a currency, from a plugin's conversion of $amount into MAD.
	 *
	 * An amount returned unchanged means the plugin has no rate (e.g. MAD isn't set up), not
	 * that 1 unit is worth 1 MAD: using it would undercharge. The real rate of 1 is only
	 * valid when converting from MAD itself.
	 *
	 * @param mixed $converted The plugin's result.
	 * @return float The rate, or 0.0 when there is no usable conversion.
	 */
	public static function rate_from_conversion(float $amount, $converted, string $currency): float
	{
		if ($amount <= 0 || !is_numeric($converted) || (float) $converted <= 0) {
			return 0.0;
		}
		if ($currency !== self::MAD && abs((float) $converted - $amount) < 0.000001) {
			return 0.0;
		}
		return (float) $converted / $amount;
	}

	public static function to_mad(float $amount, float $mad_per_unit): float
	{
		return round($amount * $mad_per_unit, 2);
	}

	public static function from_mad(float $amount_mad, float $mad_per_unit): float
	{
		return $mad_per_unit > 0 ? round($amount_mad / $mad_per_unit, 2) : 0.0;
	}

	/**
	 * Split a refund entered in MAD into the MAD amount sent to CMI and the
	 * order-currency amount recorded in WooCommerce.
	 *
	 * A refund that reaches the remaining MAD balance uses the exact remaining
	 * amounts on both sides, so no rounding leftover (e.g. 0.01) remains.
	 *
	 * @return array{mad: float, order: float, full: bool}
	 */
	public static function refund_from_mad(float $refund_mad, float $remaining_mad, float $remaining_order, float $mad_per_unit): array
	{
		if ($refund_mad >= $remaining_mad - self::EPSILON) {
			return array('mad' => round($remaining_mad, 2), 'order' => round($remaining_order, 2), 'full' => true);
		}
		return array(
			'mad'   => round($refund_mad, 2),
			'order' => min(round($remaining_order, 2), self::from_mad($refund_mad, $mad_per_unit)),
			'full'  => false,
		);
	}

	/**
	 * Same split for a refund entered in the order currency (WooCommerce's own Refund button).
	 *
	 * @return array{mad: float, order: float, full: bool}
	 */
	public static function refund_from_order_currency(float $refund_order, float $remaining_order, float $remaining_mad, float $mad_per_unit): array
	{
		if ($refund_order >= $remaining_order - self::EPSILON) {
			return array('mad' => round($remaining_mad, 2), 'order' => round($remaining_order, 2), 'full' => true);
		}
		return array(
			'mad'   => min(round($remaining_mad, 2), self::to_mad($refund_order, $mad_per_unit)),
			'order' => round($refund_order, 2),
			'full'  => false,
		);
	}
}
