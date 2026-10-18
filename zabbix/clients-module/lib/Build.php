<?php declare(strict_types = 0);

namespace Modules\EpClients\Lib;

/**
 * Which build of the ElasticPro Zabbix modules this is, for the line on ElasticPro -> Settings.
 *
 * The version is the one in this module's own manifest.json — every module carries the same
 * one. The rest comes from a BUILD file in the module's folder, which ElasticPro's
 * tools/zabbix-modules-tarball.sh writes into every module folder of a release archive: one
 * `key=value` per line, `#` lines ignored.
 *
 *   modules_version=2.1.0
 *   modules_commit=723f10156ed96353ba0fc8ee69a1aadc3496bda5
 *   modules_commit_short=723f101
 *   modules_commit_date=2026-10-18T08:49:29+05:30
 *   modules_tree=clean
 *   elasticpro_version=0.3.20
 *
 * A module copied from a plain checkout has no BUILD file, and then the line says the build is
 * not recorded. A value that is missing, "unknown" or not shaped like what it should be is left
 * out the same way — nothing here guesses. A build made from a checkout with uncommitted changes
 * (`modules_tree=modified`) says "(modified)" after the commit, because the commit alone would
 * not describe those files.
 */
class Build {

	public const FILE = 'BUILD';

	/** The module folder this file lives in. */
	public static function dir(): string {
		return dirname(__DIR__);
	}

	/** The manifest's version when it is a plain x.y.z, else null. */
	public static function version(string $dir): ?string {
		$manifest = @file_get_contents($dir.'/manifest.json');
		$version = $manifest === false ? null : (json_decode($manifest, true)['version'] ?? null);
		return is_string($version) && preg_match('/^\d+\.\d+\.\d+$/', $version) ? $version : null;
	}

	/** key => value from BUILD text. */
	public static function parse(string $text): array {
		$out = [];
		foreach (preg_split('/\r?\n/', $text) as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
				continue;
			}
			[$key, $value] = explode('=', $line, 2);
			$out[trim($key)] = trim($value);
		}
		return $out;
	}

	/** The BUILD file's fields, or null when there is none. */
	public static function read(string $dir): ?array {
		$text = @file_get_contents($dir.'/'.self::FILE);
		return $text === false ? null : self::parse($text);
	}

	/**
	 * "Zabbix modules 2.1.0 · build 723f101 · from ElasticPro 0.3.20", with the parts that are
	 * not known left out or said to be not recorded. Plain text; the page escapes it.
	 */
	public static function describe(?string $version, ?array $build): string {
		$line = $version !== null ? 'Zabbix modules '.$version : 'Zabbix modules (version not readable)';
		$short = $build['modules_commit_short'] ?? '';
		if ($short === '' && preg_match('/^[0-9a-f]{40}$/', $build['modules_commit'] ?? '')) {
			$short = substr($build['modules_commit'], 0, 7);
		}
		if ($build === null || !preg_match('/^[0-9a-f]{7,40}$/', $short)) {
			return $line.' · build not recorded';
		}
		$line .= ' · build '.$short;
		if (($build['modules_tree'] ?? '') === 'modified') {
			$line .= ' (modified)';
		}
		$from = $build['elasticpro_version'] ?? '';
		if (preg_match('/^[0-9][0-9A-Za-z.+-]{0,31}$/', $from)) {
			$line .= ' · from ElasticPro '.$from;
		}
		return $line;
	}

	/** The line for this module's folder. */
	public static function line(?string $dir = null): string {
		$dir ??= self::dir();
		return self::describe(self::version($dir), self::read($dir));
	}
}
