<?php declare(strict_types = 0);

namespace Modules\EpClients\Lib;

use RuntimeException;

/**
 * A ZIP file in and out, in plain PHP: an .xlsx is a ZIP, and the zip extension is not on every
 * Zabbix frontend (the test VM's PHP 8.3 lacks it), so this needs only zlib, which Zabbix needs anyway.
 *
 * Writing: stored names, deflate (gzdeflate, raw), crc32(), a central directory and its end record.
 * Reading: the end record, then the central directory — its sizes, not the local headers', because
 * Excel, LibreOffice and openpyxl may set bit 3 and put the sizes after the data. Refused, each with
 * a sentence: ZIP64, encryption, a method other than stored or deflate, a name that climbs out of
 * its folder, more than MAX_ENTRIES entries, and more than $maxBytes unpacked in all (a zip bomb).
 * Pure: tested in test/xlsx.test.php.
 */
class Zip {

	public const MAX_ENTRIES = 2000;
	public const MAX_BYTES = 50 * 1024 * 1024;

	/** name => bytes, as one ZIP file. */
	public static function build(array $entries, ?int $time = null): string {
		$time = $time ?? time();
		$dosTime = (int) ((date('H', $time) << 11) | (date('i', $time) << 5) | intdiv((int) date('s', $time), 2));
		$dosDate = (int) (((date('Y', $time) - 1980) << 9) | (date('n', $time) << 5) | date('j', $time));
		$out = '';
		$central = '';
		foreach ($entries as $name => $data) {
			$name = (string) $name;
			$data = (string) $data;
			$crc = crc32($data);
			$packed = gzdeflate($data, 6);
			$head = pack('vvvvvVVVvv', 20, 0x0800, 8, $dosTime, $dosDate, $crc, strlen($packed), strlen($data), strlen($name), 0);
			$offset = strlen($out);
			$out .= "PK\x03\x04".$head.$name.$packed;
			$central .= "PK\x01\x02".pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, 8, $dosTime, $dosDate, $crc, strlen($packed), strlen($data),
				strlen($name), 0, 0, 0, 0, 0, $offset).$name;
		}
		return $out.$central."PK\x05\x06".pack('vvvvVVv', 0, 0, count($entries), count($entries), strlen($central), strlen($out), 0);
	}

	/**
	 * A ZIP file as name => bytes. Throws RuntimeException with a sentence for the operator when the
	 * file is not a ZIP this reads, or is one it refuses.
	 */
	public static function read(string $zip, int $maxBytes = self::MAX_BYTES, int $maxEntries = self::MAX_ENTRIES): array {
		$len = strlen($zip);
		if ($len < 22 || substr($zip, 0, 2) !== 'PK') {
			throw new RuntimeException(_('The file is not an Excel workbook (.xlsx): it is not a ZIP file.'));
		}
		// The end record is the last 22 bytes, unless a comment (at most 65535 bytes) follows it.
		$eocd = strrpos(substr($zip, max(0, $len - 22 - 65535)), "PK\x05\x06");
		if ($eocd === false) {
			throw new RuntimeException(_('The file is not a complete ZIP: its end record is missing. Save it again from Excel.'));
		}
		$eocd += max(0, $len - 22 - 65535);
		$e = unpack('vdisk/vcdisk/ventries/vtotal/Vsize/Voffset/vcomment', substr($zip, $eocd + 4, 18));
		if ($e['total'] === 0xFFFF || $e['size'] === 0xFFFFFFFF || $e['offset'] === 0xFFFFFFFF
				|| ($eocd >= 20 && substr($zip, $eocd - 20, 4) === "PK\x06\x07")) {
			throw new RuntimeException(_('The workbook is a ZIP64 file, which is not supported: no client list needs one. Save it again from Excel.'));
		}
		if ($e['disk'] !== 0 || $e['cdisk'] !== 0 || $e['entries'] !== $e['total']) {
			throw new RuntimeException(_('The workbook is split over several files, which is not supported.'));
		}
		if ($e['total'] > $maxEntries) {
			throw new RuntimeException(_s('The workbook holds %1$s files; at most %2$s are read.', $e['total'], $maxEntries));
		}
		if ($e['offset'] + $e['size'] > $eocd) {
			throw new RuntimeException(_('The workbook\'s ZIP directory points outside the file: it is damaged.'));
		}

		$entries = [];
		$p = $e['offset'];
		$total = 0;
		for ($i = 0; $i < $e['total']; $i++) {
			if ($p + 46 > $eocd || substr($zip, $p, 4) !== "PK\x01\x02") {
				throw new RuntimeException(_('The workbook\'s ZIP directory is damaged.'));
			}
			$c = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen/vclen/vdisk/vint/Vext/Voffset', substr($zip, $p + 4, 42));
			$name = substr($zip, $p + 46, $c['nlen']);
			$p += 46 + $c['nlen'] + $c['xlen'] + $c['clen'];
			if ($c['flags'] & 0x0001) {
				throw new RuntimeException(_('The workbook is password-protected (encrypted). Save a copy without a password and import that.'));
			}
			if ($c['csize'] === 0xFFFFFFFF || $c['usize'] === 0xFFFFFFFF || $c['offset'] === 0xFFFFFFFF) {
				throw new RuntimeException(_('The workbook is a ZIP64 file, which is not supported: no client list needs one. Save it again from Excel.'));
			}
			if (!self::safeName($name)) {
				throw new RuntimeException(_s('The workbook holds a file named "%1$s", which climbs out of its folder. Refused.', $name));
			}
			if (substr($name, -1) === '/') {
				continue;
			}
			if (!in_array($c['method'], [0, 8], true)) {
				throw new RuntimeException(_s('The workbook packs "%1$s" in a way this does not read (method %2$s). Save it again from Excel.', $name, $c['method']));
			}
			$total += $c['usize'];
			if ($total > $maxBytes) {
				throw new RuntimeException(_s('The workbook unpacks to more than %1$s MB, far more than a client list. Refused.', intdiv($maxBytes, 1024 * 1024)));
			}
			$entries[$name] = $c;
		}

		$out = [];
		foreach ($entries as $name => $c) {
			$lh = $c['offset'];
			if ($lh + 30 > $len || substr($zip, $lh, 4) !== "PK\x03\x04") {
				throw new RuntimeException(_s('The workbook\'s "%1$s" is damaged.', $name));
			}
			$l = unpack('vnlen/vxlen', substr($zip, $lh + 26, 4));
			$start = $lh + 30 + $l['nlen'] + $l['xlen'];
			if ($start + $c['csize'] > $len) {
				throw new RuntimeException(_s('The workbook\'s "%1$s" is cut short.', $name));
			}
			$raw = substr($zip, $start, $c['csize']);
			if ($c['method'] === 0) {
				$data = $raw;
			}
			elseif ($c['usize'] === 0) {
				$data = '';
			}
			else {
				$data = self::inflate($raw, $c['usize'], $name);
			}
			if (strlen($data) !== $c['usize'] || (crc32($data) & 0xFFFFFFFF) !== ($c['crc'] & 0xFFFFFFFF)) {
				throw new RuntimeException(_s('The workbook\'s "%1$s" is damaged (its check sum does not match).', $name));
			}
			$out[$name] = $data;
		}
		return $out;
	}

	/** No absolute path, no drive, no backslash, no "..", no NUL. */
	public static function safeName(string $name): bool {
		if ($name === '' || strpos($name, "\0") !== false || strpos($name, '\\') !== false || $name[0] === '/' || preg_match('/^[A-Za-z]:/', $name)) {
			return false;
		}
		return !in_array('..', explode('/', $name), true);
	}

	/** Raw deflate, never more than $size bytes out: a stream that claims less than it holds is refused. */
	private static function inflate(string $raw, int $size, string $name): string {
		set_error_handler(function () use ($name) {
			throw new RuntimeException(_s('The workbook\'s "%1$s" is damaged, or unpacks to more than it says.', $name));
		});
		try {
			$data = gzinflate($raw, $size);
		}
		finally {
			restore_error_handler();
		}
		if ($data === false) {
			throw new RuntimeException(_s('The workbook\'s "%1$s" is damaged, or unpacks to more than it says.', $name));
		}
		return $data;
	}
}
