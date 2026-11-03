<?php
/**
 * Translation tool for the CMI plugin (not shipped in the plugin zip).
 *
 *   php tools/i18n.php pot     Extract every text from the plugin into languages/cmi.pot
 *   php tools/i18n.php mo      Compile languages/cmi-fr_FR.po into languages/cmi-fr_FR.mo
 *   php tools/i18n.php check   Check the French file is complete and safe; exits 1 on a problem
 */

const CMI_I18N_PLUGIN_DIR = __DIR__ . '/../cmi';
const CMI_I18N_LANG_DIR = CMI_I18N_PLUGIN_DIR . '/languages';
const CMI_I18N_DOMAIN = 'cmi';

/** Translation functions: name => [context argument position or null, has plural]. */
const CMI_I18N_FUNCTIONS = array(
	'__'           => array(null, false),
	'_e'           => array(null, false),
	'esc_html__'   => array(null, false),
	'esc_html_e'   => array(null, false),
	'esc_attr__'   => array(null, false),
	'esc_attr_e'   => array(null, false),
	'_x'           => array(1, false),
	'_n_noop'      => array(null, true),
);

function cmi_i18n_php_files(): array
{
	$files = array();
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CMI_I18N_PLUGIN_DIR, FilesystemIterator::SKIP_DOTS));
	foreach ($iterator as $file) {
		if ($file->getExtension() === 'php') {
			$files[] = $file->getPathname();
		}
	}
	sort($files);
	return $files;
}

function cmi_i18n_literal(string $token): string
{
	$quote = $token[0];
	$body = substr($token, 1, -1);
	return $quote === "'" ? str_replace(array("\\\\", "\\'"), array("\\", "'"), $body) : stripcslashes($body);
}

/**
 * Read the call's arguments as strings; an argument that isn't a plain string becomes null.
 *
 * @return array<int, ?string>
 */
function cmi_i18n_call_args(array $tokens, int $open): array
{
	$args = array();
	$current = '';
	$is_literal = true;
	$depth = 0;
	for ($i = $open; $i < count($tokens); $i++) {
		$t = $tokens[$i];
		$text = is_array($t) ? $t[1] : $t;
		if ($text === '(' || $text === '[') {
			$depth++;
			if ($depth === 1) {
				continue;
			}
		} elseif ($text === ')' || $text === ']') {
			$depth--;
			if ($depth === 0) {
				$args[] = $is_literal ? $current : null;
				return $args;
			}
		}
		if ($depth === 1 && $text === ',') {
			$args[] = $is_literal ? $current : null;
			$current = '';
			$is_literal = true;
			continue;
		}
		if (is_array($t) && in_array($t[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
			continue;
		}
		if ($depth === 1 && is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
			$current .= cmi_i18n_literal($t[1]);
		} elseif ($depth === 1 && $text === '.') {
			continue;
		} else {
			$is_literal = false;
		}
	}
	return $args;
}

/** @return array{entries: array, problems: string[]} */
function cmi_i18n_extract(): array
{
	$entries = array();
	$problems = array();
	foreach (cmi_i18n_php_files() as $path) {
		$relative = str_replace('\\', '/', substr($path, strlen(CMI_I18N_PLUGIN_DIR) + 1));
		$tokens = token_get_all((string) file_get_contents($path));
		foreach ($tokens as $i => $t) {
			if (!is_array($t) || $t[0] !== T_STRING || !isset(CMI_I18N_FUNCTIONS[$t[1]])) {
				continue;
			}
			$open = $i + 1;
			while (isset($tokens[$open]) && is_array($tokens[$open]) && $tokens[$open][0] === T_WHITESPACE) {
				$open++;
			}
			if (($tokens[$open] ?? '') !== '(') {
				continue;
			}
			$prev = $tokens[$i - 1] ?? '';
			if (is_array($prev) && $prev[0] === T_FUNCTION) {
				continue;
			}
			[$context_at, $has_plural] = CMI_I18N_FUNCTIONS[$t[1]];
			$args = cmi_i18n_call_args($tokens, $open);
			$where = $relative . ':' . $t[2];
			if (($args[0] ?? null) === null) {
				$problems[] = "$where: {$t[1]}() needs a plain text, not a variable";
				continue;
			}
			$domain_at = $has_plural ? 2 : ($context_at !== null ? 2 : 1);
			if (($args[$domain_at] ?? null) !== CMI_I18N_DOMAIN) {
				$problems[] = "$where: text domain must be 'cmi': " . substr($args[0], 0, 60);
			}
			$context = $context_at !== null ? (string) ($args[$context_at] ?? '') : '';
			$key = $context . "\x04" . $args[0];
			$entries[$key] ??= array(
				'context' => $context,
				'id'      => $args[0],
				'plural'  => $has_plural ? (string) ($args[1] ?? '') : '',
				'refs'    => array(),
			);
			$entries[$key]['refs'][] = $where;
		}
	}
	// The plugin description shown on the Plugins page comes from the file header
	if (preg_match('/^\s*Description:\s*(.+)$/m', (string) file_get_contents(CMI_I18N_PLUGIN_DIR . '/gateway-cmi.php'), $m)) {
		$entries["\x04" . trim($m[1])] = array('context' => '', 'id' => trim($m[1]), 'plural' => '', 'refs' => array('gateway-cmi.php:5'));
	}
	return array('entries' => $entries, 'problems' => $problems);
}

function cmi_i18n_po_quote(string $text): string
{
	return '"' . addcslashes($text, "\\\"\n\t\r") . '"';
}

function cmi_i18n_write_pot(array $entries): void
{
	$out = "msgid \"\"\nmsgstr \"\"\n"
		. "\"Project-Id-Version: WooCommerce CMI Gateway\\n\"\n"
		. "\"MIME-Version: 1.0\\n\"\n"
		. "\"Content-Type: text/plain; charset=UTF-8\\n\"\n"
		. "\"Content-Transfer-Encoding: 8bit\\n\"\n"
		. "\"X-Domain: cmi\\n\"\n\n";
	foreach ($entries as $e) {
		$out .= '#: ' . implode(' ', $e['refs']) . "\n";
		if ($e['context'] !== '') {
			$out .= 'msgctxt ' . cmi_i18n_po_quote($e['context']) . "\n";
		}
		$out .= 'msgid ' . cmi_i18n_po_quote($e['id']) . "\n";
		if ($e['plural'] !== '') {
			$out .= 'msgid_plural ' . cmi_i18n_po_quote($e['plural']) . "\n";
			$out .= "msgstr[0] \"\"\nmsgstr[1] \"\"\n\n";
		} else {
			$out .= "msgstr \"\"\n\n";
		}
	}
	if (!is_dir(CMI_I18N_LANG_DIR)) {
		mkdir(CMI_I18N_LANG_DIR, 0777, true);
	}
	file_put_contents(CMI_I18N_LANG_DIR . '/cmi.pot', $out);
}

/** @return array<string, array{context: string, id: string, plural: string, strings: string[]}> */
function cmi_i18n_read_po(string $path): array
{
	$entries = array();
	$current = null;
	$field = null;
	$flush = static function () use (&$entries, &$current) {
		if ($current !== null) {
			$entries[$current['context'] . "\x04" . $current['id']] = $current;
		}
	};
	foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
		$line = trim($line);
		if ($line === '' || $line[0] === '#') {
			continue;
		}
		if (preg_match('/^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+(".*")$/', $line, $m)) {
			$value = stripcslashes(substr($m[3], 1, -1));
			if ($m[1] === 'msgctxt' || ($m[1] === 'msgid' && $field !== 'msgctxt')) {
				$flush();
				$current = array('context' => '', 'id' => '', 'plural' => '', 'strings' => array());
			}
			if ($m[1] === 'msgctxt') {
				$current['context'] = $value;
				$field = 'msgctxt';
			} elseif ($m[1] === 'msgid') {
				$current['id'] = $value;
				$field = 'msgid';
			} elseif ($m[1] === 'msgid_plural') {
				$current['plural'] = $value;
				$field = 'msgid_plural';
			} else {
				$index = (int) ($m[2] ?? 0);
				$current['strings'][$index] = $value;
				$field = 'msgstr' . $index;
			}
		} elseif ($line[0] === '"') {
			$value = stripcslashes(substr($line, 1, -1));
			if ($field === 'msgctxt') {
				$current['context'] .= $value;
			} elseif ($field === 'msgid') {
				$current['id'] .= $value;
			} elseif ($field === 'msgid_plural') {
				$current['plural'] .= $value;
			} else {
				$current['strings'][(int) substr((string) $field, 6)] .= $value;
			}
		}
	}
	$flush();
	return $entries;
}

function cmi_i18n_write_mo(array $po, string $path): void
{
	$map = array();
	foreach ($po as $e) {
		$key = ($e['context'] !== '' ? $e['context'] . "\x04" : '') . $e['id'];
		if ($e['plural'] !== '') {
			$key .= "\0" . $e['plural'];
		}
		$value = implode("\0", $e['strings']);
		if ($key === '' || trim($value, "\0") !== '') {
			$map[$key] = $value;
		}
	}
	ksort($map, SORT_STRING);
	$count = count($map);
	$ids_offset = 28;
	$strs_offset = $ids_offset + 8 * $count;
	$data_offset = $strs_offset + 8 * $count;
	$ids = '';
	$strs = '';
	$id_table = '';
	$str_table = '';
	foreach ($map as $key => $value) {
		$id_table .= pack('VV', strlen((string) $key), $data_offset + strlen($ids));
		$ids .= $key . "\0";
	}
	$strs_base = $data_offset + strlen($ids);
	foreach ($map as $value) {
		$str_table .= pack('VV', strlen($value), $strs_base + strlen($strs));
		$strs .= $value . "\0";
	}
	$header = pack('VVVVVVV', 0x950412de, 0, $count, $ids_offset, $strs_offset, 0, $data_offset);
	file_put_contents($path, $header . $id_table . $str_table . $ids . $strs);
}

function cmi_i18n_placeholders(string $text): array
{
	preg_match_all('/%(?:\d+\$)?[sdf]/', $text, $m);
	$normal = array();
	$next = 1;
	foreach ($m[0] as $p) {
		$normal[] = preg_match('/^%(\d+)\$(.)$/', $p, $n) ? $n[1] . $n[2] : ($next . substr($p, -1));
		$next++;
	}
	sort($normal);
	return $normal;
}

function cmi_i18n_tags(string $text): array
{
	preg_match_all('/<\/?[a-z][a-z0-9]*\b/i', $text, $m);
	$tags = array_map('strtolower', $m[0]);
	sort($tags);
	return $tags;
}

/** @return string[] Problems found in the French file. */
function cmi_i18n_check(array $entries, array $po): array
{
	$problems = array();
	foreach ($entries as $key => $e) {
		$short = substr($e['id'], 0, 70);
		if (!isset($po[$key])) {
			$problems[] = "Missing French translation: $short";
			continue;
		}
		foreach ($po[$key]['strings'] as $string) {
			if (trim($string) === '') {
				$problems[] = "Empty French translation: $short";
				continue;
			}
			if (cmi_i18n_placeholders($e['id']) !== cmi_i18n_placeholders($string)) {
				$problems[] = "Placeholders differ: $short";
			}
			if (cmi_i18n_tags($e['id']) !== cmi_i18n_tags($string)) {
				$problems[] = "HTML tags differ: $short";
			}
		}
	}
	foreach ($po as $key => $e) {
		if ($e['id'] !== '' && !isset($entries[$key])) {
			$problems[] = 'Translation for a text no longer in the plugin: ' . substr($e['id'], 0, 70);
		}
	}
	return $problems;
}

$command = $argv[1] ?? '';
$extracted = cmi_i18n_extract();
$po_path = CMI_I18N_LANG_DIR . '/cmi-fr_FR.po';

if ($command === 'pot') {
	cmi_i18n_write_pot($extracted['entries']);
	echo count($extracted['entries']) . " texts written to languages/cmi.pot\n";
	foreach ($extracted['problems'] as $problem) {
		echo "PROBLEM $problem\n";
	}
	exit($extracted['problems'] ? 1 : 0);
}
if ($command === 'mo') {
	cmi_i18n_write_mo(cmi_i18n_read_po($po_path), CMI_I18N_LANG_DIR . '/cmi-fr_FR.mo');
	echo "languages/cmi-fr_FR.mo written\n";
	exit(0);
}
if ($command === 'check') {
	$problems = array_merge($extracted['problems'], cmi_i18n_check($extracted['entries'], cmi_i18n_read_po($po_path)));
	foreach ($problems as $problem) {
		echo "PROBLEM $problem\n";
	}
	echo $problems ? count($problems) . " problem(s)\n" : count($extracted['entries']) . " texts: French translation complete\n";
	exit($problems ? 1 : 0);
}
echo "Usage: php tools/i18n.php pot|mo|check\n";
exit(2);
