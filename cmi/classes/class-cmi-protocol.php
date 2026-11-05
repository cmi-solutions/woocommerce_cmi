<?php
defined('ABSPATH') || exit;
/**
 * CMI message formats: signatures, API requests, cleaned values.
 *
 * Kept free of WordPress calls so the provided tests run this exact code instead of a copy.
 */
final class Cmi_Protocol
{
	/**
	 * Signature of the payment request sent to CMI (hash "ver3"), like CMI's PHP example:
	 * fields in natural order, values trimmed and HTML-decoded, "hash" and "encoding" left out.
	 */
	public static function request_hash(array $data, string $store_key): string
	{
		return self::hash($data, $store_key, static function ($value): string {
			return trim(html_entity_decode(html_entity_decode((string) $value)));
		});
	}

	/**
	 * Signature CMI puts on a callback or customer return, like CMI's callback example:
	 * one trailing line break removed, HTML entities decoded, nothing trimmed.
	 */
	public static function callback_hash(array $data, string $store_key): string
	{
		return self::hash($data, $store_key, static function ($value): string {
			return html_entity_decode((string) preg_replace("/\n$/", '', (string) $value), ENT_QUOTES, 'UTF-8');
		});
	}

	private static function hash(array $data, string $store_key, callable $prepare): string
	{
		$parameters = array_keys($data);
		natcasesort($parameters);
		$hash_value = '';
		foreach ($parameters as $parameter) {
			if (in_array(strtolower((string) $parameter), array('hash', 'encoding'), true)) {
				continue;
			}
			$hash_value .= self::escape_hash_value($prepare($data[$parameter])) . '|';
		}
		$hash_value .= self::escape_hash_value($store_key);
		return base64_encode(pack('H*', hash('sha512', $hash_value)));
	}

	private static function escape_hash_value(string $value): string
	{
		return str_replace('|', '\\|', str_replace('\\', '\\\\', $value));
	}

	/**
	 * Remove accents and keep only [a-zA-Z0-9_ -], like CMI's PHP example (str_without_accents).
	 * Text with no Latin letter, such as Arabic, comes out empty.
	 */
	public static function without_accents(string $str): string
	{
		$str = htmlentities($str, ENT_NOQUOTES, 'UTF-8');
		$str = (string) preg_replace('#&([A-Za-z])(?:acute|cedil|caron|circ|grave|orn|ring|slash|th|tilde|uml);#', '\1', $str);
		$str = (string) preg_replace('#&([A-Za-z]{2})(?:lig);#', '\1', $str);
		$str = (string) preg_replace('#&[^;]+;#', '', $str);
		return (string) preg_replace('/[^a-zA-Z0-9_ -]/s', '', $str);
	}

	/** An order identifier CMI accepts: letters, digits, "-" and "_", 64 characters at most. */
	public static function sanitize_oid(string $raw_oid): string
	{
		$clean = function_exists('remove_accents') ? remove_accents($raw_oid) : self::without_accents($raw_oid);
		return substr((string) preg_replace('/[^a-zA-Z0-9_-]/', '', $clean), 0, 64);
	}

	/** CMI identifier for an order number, or the internal ID when nothing usable is left. */
	public static function oid_for_number(string $order_number, int $internal_id): string
	{
		$oid = self::sanitize_oid($order_number);
		return $oid !== '' ? $oid : (string) $internal_id;
	}

	/**
	 * XML request for CMI's API, every value escaped.
	 *
	 * @param string      $type     PostAuth, Void, Credit, ORDERSTATUS or ORDERHISTORY.
	 * @param string|null $total    Amount in MAD ("150.00"): partial PostAuth, Credit (required).
	 * @param string|null $currency ISO number of the currency, for Credit ("504").
	 * @return string|null The XML, or null for an unknown type.
	 */
	public static function xml_request(string $type, string $username, string $password, string $client_id, string $order_id, ?string $total = null, ?string $currency = null): ?string
	{
		$e = static function (string $value): string {
			return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
		};
		$head = '<?xml version="1.0" encoding="UTF-8"?><CC5Request>'
			. '<Name>' . $e($username) . '</Name>'
			. '<Password>' . $e($password) . '</Password>'
			. '<ClientId>' . $e($client_id) . '</ClientId>';
		$oid = '<OrderId>' . $e($order_id) . '</OrderId>';

		switch ($type) {
			case 'Void':
				return $head . '<Type>Void</Type>' . $oid . '</CC5Request>';
			case 'PostAuth':
				return $head . '<Type>PostAuth</Type>' . $oid
					. ($total !== null ? '<Total>' . $e($total) . '</Total>' : '')
					. '</CC5Request>';
			case 'Credit':
				return $head . '<Type>Credit</Type>'
					. '<Total>' . $e((string) $total) . '</Total>'
					. '<Currency>' . $e((string) $currency) . '</Currency>'
					. $oid . '</CC5Request>';
			case 'ORDERSTATUS':
			case 'ORDERHISTORY':
				return $head . $oid . '<Extra><' . $type . '>QUERY</' . $type . '></Extra></CC5Request>';
		}
		return null;
	}
}
