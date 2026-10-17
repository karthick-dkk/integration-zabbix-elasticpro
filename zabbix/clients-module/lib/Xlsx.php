<?php declare(strict_types = 0);

namespace Modules\EpClients\Lib;

use RuntimeException;
use XMLReader;

/**
 * An Excel workbook (.xlsx) in and out, in plain PHP on top of Zip — no zip extension, no library.
 * Only what the clients workbook needs; nothing here knows about clients (that is Workbook).
 *
 * Writing, per sheet: cells (text as shared strings, numbers, booleans, dates as serials with a
 * date format), column widths, hidden and outlined (grouped) columns, a frozen pane, merged cells,
 * list data validations, and notes — comments1.xml plus the VML drawing Excel and LibreOffice need
 * before a note shows on hover. Text is never written as a formula: a value that starts with
 * = + - @ is a string cell, styled with quotePrefix so that editing it in Excel keeps it text.
 *
 * Reading: sheet names and parts through workbook.xml and its rels, shared and inline strings,
 * numbers, booleans, dates (by the cell's number format, or t="d") as Y-m-d, cell references with
 * gaps. XML with a DOCTYPE is refused before it is parsed (no entity can be declared, so none can
 * expand or reach out), and the parser runs with LIBXML_NONET.
 * Pure: tested in test/xlsx.test.php.
 */
class Xlsx {

	public const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	public const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	private const REL_BASE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

	/** Rows read from one sheet, at most: far above any client list, and a bound on a hostile file. */
	public const MAX_ROWS = 20000;

	/** Cell styles the writer knows, by name => index in cellXfs (see styles()). */
	public const STYLES = ['normal' => 0, 'header' => 1, 'date' => 2, 'banner' => 3, 'title' => 4, 'text' => 5, 'header2' => 6, 'muted' => 7];

	/* ------------------------------------------------------------------ writing */

	/**
	 * A workbook from sheets, in order. A sheet:
	 *   name         its tab
	 *   rows         [rowNumber => [colNumber => cell]], both from 1; a cell is a scalar or
	 *                ['v' => scalar, 'style' => one of STYLES]. A date: ['v' => 'Y-m-d', 'style' => 'date'].
	 *   cols         [colNumber => ['width' => float, 'hidden' => bool, 'outline' => int, 'collapsed' => bool]]
	 *   freeze       the top-left cell that scrolls, e.g. 'A2' (row 1 frozen) or 'B2'
	 *   merges       ['A1:K1', …]
	 *   validations  [['sqref' => 'B2:B2000', 'list' => '"CI,DI"' or 'Clients!$A$2:$A$9', 'style' => 'stop'|'warning',
	 *                  'errorTitle', 'error', 'promptTitle', 'prompt']]
	 *   notes        ['A1' => ['title' => bold first line, 'text' => the rest]]
	 *   summaryLeft  true: an outline group's +/- sits over the column left of it
 *   heights      [rowNumber => points]
	 */
	public static function build(array $sheets): string {
		$strings = [];
		$files = [];
		$overrides = [];
		$sheetEntries = '';
		$wbRels = '';
		foreach (array_values($sheets) as $i => $sheet) {
			$n = $i + 1;
			$rels = '';
			$legacy = '';
			if (!empty($sheet['notes'])) {
				$files['xl/comments'.$n.'.xml'] = self::comments($sheet['notes']);
				$files['xl/drawings/vmlDrawing'.$n.'.vml'] = self::vml($sheet['notes'], $n);
				$overrides[] = ['/xl/comments'.$n.'.xml', 'application/vnd.openxmlformats-officedocument.spreadsheetml.comments+xml'];
				$rels = '<Relationship Id="rId1" Type="'.self::REL_BASE.'comments" Target="../comments'.$n.'.xml"/>'
					.'<Relationship Id="rId2" Type="'.self::REL_BASE.'vmlDrawing" Target="../drawings/vmlDrawing'.$n.'.vml"/>';
				$legacy = '<legacyDrawing r:id="rId2"/>';
			}
			$files['xl/worksheets/sheet'.$n.'.xml'] = self::sheetXml($sheet, $strings, $legacy);
			if ($rels !== '') {
				$files['xl/worksheets/_rels/sheet'.$n.'.xml.rels'] = self::relsXml($rels);
			}
			$overrides[] = ['/xl/worksheets/sheet'.$n.'.xml', 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml'];
			$sheetEntries .= '<sheet name="'.self::esc(self::sheetName($sheet['name'])).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
			$wbRels .= '<Relationship Id="rId'.$n.'" Type="'.self::REL_BASE.'worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
		}
		$k = count($sheets);
		$wbRels .= '<Relationship Id="rId'.($k + 1).'" Type="'.self::REL_BASE.'styles" Target="styles.xml"/>'
			.'<Relationship Id="rId'.($k + 2).'" Type="'.self::REL_BASE.'sharedStrings" Target="sharedStrings.xml"/>';
		$files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
			.'<workbook xmlns="'.self::NS_MAIN.'" xmlns:r="'.self::NS_REL.'"><workbookPr/>'
			.'<bookViews><workbookView activeTab="0"/></bookViews><sheets>'.$sheetEntries.'</sheets></workbook>';
		$files['xl/_rels/workbook.xml.rels'] = self::relsXml($wbRels);
		$files['xl/styles.xml'] = self::styles();
		$sst = '';
		foreach (array_keys($strings) as $s) {
			$sst .= '<si><t xml:space="preserve">'.self::esc((string) $s).'</t></si>';
		}
		$files['xl/sharedStrings.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
			.'<sst xmlns="'.self::NS_MAIN.'" count="'.count($strings).'" uniqueCount="'.count($strings).'">'.$sst.'</sst>';
		$files['docProps/app.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
			.'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>ElasticPro</Application></Properties>';
		$files['_rels/.rels'] = self::relsXml('<Relationship Id="rId1" Type="'.self::REL_BASE.'officeDocument" Target="xl/workbook.xml"/>'
			.'<Relationship Id="rId2" Type="'.self::REL_BASE.'extended-properties" Target="docProps/app.xml"/>');

		$types = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			.'<Default Extension="xml" ContentType="application/xml"/>'
			.'<Default Extension="vml" ContentType="application/vnd.openxmlformats-officedocument.vmlDrawing"/>'
			.'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			.'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			.'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
			.'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
		foreach ($overrides as [$part, $type]) {
			$types .= '<Override PartName="'.$part.'" ContentType="'.$type.'"/>';
		}
		$ordered = ['[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
			.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.$types.'</Types>'];
		$ordered['_rels/.rels'] = $files['_rels/.rels'];
		unset($files['_rels/.rels']);
		return Zip::build($ordered + $files);
	}

	/** Column number (from 1) => letters. */
	public static function col(int $n): string {
		$s = '';
		while ($n > 0) {
			$m = ($n - 1) % 26;
			$s = chr(65 + $m).$s;
			$n = intdiv($n - 1, 26);
		}
		return $s;
	}

	/** Letters => column number (from 1). */
	public static function colNumber(string $letters): int {
		$n = 0;
		foreach (str_split(strtoupper($letters)) as $ch) {
			$n = $n * 26 + (ord($ch) - 64);
		}
		return $n;
	}

	/** Y-m-d => Excel's day number (1900 system). */
	public static function serial(string $ymd): ?float {
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
			return null;
		}
		return (float) (gmmktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]) / 86400 + 25569);
	}

	/** Excel's day number => Y-m-d (with the time when it has one). */
	public static function fromSerial(float $serial, bool $date1904 = false): string {
		$secs = (int) round(($serial - ($date1904 ? 24107 : 25569)) * 86400);
		return $secs % 86400 === 0 ? gmdate('Y-m-d', $secs) : gmdate('Y-m-d H:i:s', $secs);
	}

	/** A sheet name Excel accepts: no []:*?/\, at most 31 characters. */
	private static function sheetName(string $name): string {
		return mb_substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], '', $name), 0, 31);
	}

	/** Text safe for XML 1.0: escaped, and without the control characters XML cannot hold. */
	public static function esc(string $s): string {
		$s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
		return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	private static function relsXml(string $body): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
			.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$body.'</Relationships>';
	}

	private static function sheetXml(array $sheet, array &$strings, string $legacy): string {
		$rows = $sheet['rows'] ?? [];
		ksort($rows);
		$maxCol = 1;
		$maxRow = 1;
		$data = '';
		foreach ($rows as $r => $cells) {
			if (!$cells) {
				continue;
			}
			ksort($cells);
			$xml = '';
			foreach ($cells as $c => $cell) {
				$v = is_array($cell) ? ($cell['v'] ?? null) : $cell;
				$style = is_array($cell) ? (self::STYLES[$cell['style'] ?? 'normal'] ?? 0) : 0;
				$ref = self::col((int) $c).$r;
				if ($v === null || $v === '') {
					if ($style) {
						$xml .= '<c r="'.$ref.'" s="'.$style.'"/>';
					}
					continue;
				}
				$maxCol = max($maxCol, (int) $c);
				$maxRow = max($maxRow, (int) $r);
				if (is_bool($v)) {
					$xml .= '<c r="'.$ref.'"'.($style ? ' s="'.$style.'"' : '').' t="b"><v>'.($v ? 1 : 0).'</v></c>';
				}
				elseif (is_int($v) || is_float($v)) {
					$xml .= '<c r="'.$ref.'"'.($style ? ' s="'.$style.'"' : '').'><v>'.self::num($v).'</v></c>';
				}
				elseif ($style === self::STYLES['date'] && ($serial = self::serial((string) $v)) !== null) {
					$xml .= '<c r="'.$ref.'" s="'.$style.'"><v>'.self::num($serial).'</v></c>';
				}
				else {
					$v = (string) $v;
					// Never a formula: a string that a spreadsheet would run stays text, even once edited.
					if ($style === 0 && preg_match('/^[=+\-@]/', $v)) {
						$style = self::STYLES['text'];
					}
					if (!isset($strings[$v])) {
						$strings[$v] = count($strings);
					}
					$xml .= '<c r="'.$ref.'"'.($style ? ' s="'.$style.'"' : '').' t="s"><v>'.$strings[$v].'</v></c>';
				}
			}
			if ($xml !== '') {
				$ht = $sheet['heights'][$r] ?? null;
				$data .= '<row r="'.$r.'"'.($ht ? ' ht="'.self::num((float) $ht).'" customHeight="1"' : '').'>'.$xml.'</row>';
			}
		}
		$cols = '';
		$outline = 0;
		$colDefs = $sheet['cols'] ?? [];
		ksort($colDefs);
		foreach ($colDefs as $c => $def) {
			$maxCol = max($maxCol, (int) $c);
			$attrs = ' min="'.$c.'" max="'.$c.'" width="'.self::num((float) ($def['width'] ?? 12)).'" customWidth="1"';
			if (!empty($def['hidden'])) {
				$attrs .= ' hidden="1"';
			}
			if (!empty($def['outline'])) {
				$attrs .= ' outlineLevel="'.(int) $def['outline'].'"';
				$outline = max($outline, (int) $def['outline']);
			}
			if (!empty($def['collapsed'])) {
				$attrs .= ' collapsed="1"';
			}
			$cols .= '<col'.$attrs.'/>';
		}

		$x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
			.'<worksheet xmlns="'.self::NS_MAIN.'" xmlns:r="'.self::NS_REL.'">';
		if (!empty($sheet['summaryLeft'])) {
			$x .= '<sheetPr><outlinePr summaryBelow="1" summaryRight="0"/></sheetPr>';
		}
		$x .= '<dimension ref="A1:'.self::col($maxCol).$maxRow.'"/>';
		$x .= '<sheetViews><sheetView workbookViewId="0"'.(!empty($sheet['active']) ? ' tabSelected="1"' : '').'>';
		if (!empty($sheet['freeze']) && preg_match('/^([A-Z]+)(\d+)$/', $sheet['freeze'], $m)) {
			$xs = self::colNumber($m[1]) - 1;
			$ys = (int) $m[2] - 1;
			$pane = $xs && $ys ? 'bottomRight' : ($ys ? 'bottomLeft' : 'topRight');
			$x .= '<pane'.($xs ? ' xSplit="'.$xs.'"' : '').($ys ? ' ySplit="'.$ys.'"' : '').' topLeftCell="'.$sheet['freeze'].'" activePane="'.$pane.'" state="frozen"/>'
				.'<selection pane="'.$pane.'" activeCell="'.$sheet['freeze'].'" sqref="'.$sheet['freeze'].'"/>';
		}
		$x .= '</sheetView></sheetViews>';
		$x .= '<sheetFormatPr defaultRowHeight="15"'.($outline ? ' outlineLevelCol="'.$outline.'"' : '').'/>';
		if ($cols !== '') {
			$x .= '<cols>'.$cols.'</cols>';
		}
		$x .= '<sheetData>'.$data.'</sheetData>';
		if (!empty($sheet['merges'])) {
			$x .= '<mergeCells count="'.count($sheet['merges']).'">';
			foreach ($sheet['merges'] as $ref) {
				$x .= '<mergeCell ref="'.self::esc($ref).'"/>';
			}
			$x .= '</mergeCells>';
		}
		if (!empty($sheet['validations'])) {
			$x .= '<dataValidations count="'.count($sheet['validations']).'">';
			foreach ($sheet['validations'] as $v) {
				$x .= '<dataValidation type="list" errorStyle="'.(($v['style'] ?? 'stop') === 'warning' ? 'warning' : 'stop').'" allowBlank="1"'
					.' showInputMessage="1" showErrorMessage="1"'
					.' errorTitle="'.self::esc(mb_substr((string) ($v['errorTitle'] ?? ''), 0, 32)).'"'
					.' error="'.self::esc(mb_substr((string) ($v['error'] ?? ''), 0, 225)).'"'
					.' promptTitle="'.self::esc(mb_substr((string) ($v['promptTitle'] ?? ''), 0, 32)).'"'
					.' prompt="'.self::esc(mb_substr((string) ($v['prompt'] ?? ''), 0, 255)).'"'
					.' sqref="'.self::esc($v['sqref']).'"><formula1>'.self::esc($v['list']).'</formula1></dataValidation>';
			}
			$x .= '</dataValidations>';
		}
		$x .= '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>';
		$x .= $legacy.'</worksheet>';
		return $x;
	}

	private static function num($v): string {
		if (is_int($v)) {
			return (string) $v;
		}
		$s = rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
		return $s === '-0' || $s === '' ? '0' : $s;
	}

	private static function comments(array $notes): string {
		$list = '';
		foreach ($notes as $ref => $note) {
			$title = (string) ($note['title'] ?? '');
			$text = (string) ($note['text'] ?? '');
			$runs = '';
			if ($title !== '') {
				$runs .= '<r><rPr><b/><sz val="9"/><rFont val="Tahoma"/><family val="2"/></rPr><t xml:space="preserve">'.self::esc($title.($text !== '' ? "\n" : '')).'</t></r>';
			}
			if ($text !== '') {
				$runs .= '<r><rPr><sz val="9"/><rFont val="Tahoma"/><family val="2"/></rPr><t xml:space="preserve">'.self::esc($text).'</t></r>';
			}
			$list .= '<comment ref="'.self::esc((string) $ref).'" authorId="0"><text>'.$runs.'</text></comment>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
			.'<comments xmlns="'.self::NS_MAIN.'"><authors><author>ElasticPro</author></authors><commentList>'.$list.'</commentList></comments>';
	}

	/** The note boxes. Hidden until hovered: no x:Visible, and visibility:hidden. */
	private static function vml(array $notes, int $sheet): string {
		$x = '<xml xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">'
			.'<o:shapelayout v:ext="edit"><o:idmap v:ext="edit" data="'.$sheet.'"/></o:shapelayout>'
			.'<v:shapetype id="_x0000_t202" coordsize="21600,21600" o:spt="202" path="m,l,21600r21600,l21600,xe">'
			.'<v:stroke joinstyle="miter"/><v:path gradientshapeok="t" o:connecttype="rect"/></v:shapetype>';
		$k = 0;
		foreach ($notes as $ref => $note) {
			preg_match('/^([A-Z]+)(\d+)$/', (string) $ref, $m);
			$col = self::colNumber($m[1]) - 1;
			$row = (int) $m[2] - 1;
			$text = (string) ($note['title'] ?? '')."\n".(string) ($note['text'] ?? '');
			$lines = 0;
			foreach (explode("\n", $text) as $line) {
				$lines += max(1, (int) ceil(mb_strlen($line) / 48));
			}
			$rowsTall = min(24, max(4, (int) ceil($lines * 0.85) + 1));
			$k++;
			$x .= '<v:shape id="_x0000_s'.($sheet * 1024 + $k).'" type="#_x0000_t202"'
				.' style="position:absolute;margin-left:80pt;margin-top:2pt;width:250pt;height:'.($rowsTall * 15).'pt;z-index:'.$k.';visibility:hidden"'
				.' fillcolor="#ffffe1" o:insetmode="auto"><v:fill color2="#ffffe1"/><v:shadow on="t" color="black" obscured="t"/>'
				.'<v:path o:connecttype="none"/><v:textbox style="mso-direction-alt:auto"><div style="text-align:left"></div></v:textbox>'
				.'<x:ClientData ObjectType="Note"><x:MoveWithCells/><x:SizeWithCells/>'
				.'<x:Anchor>'.($col + 1).', 15, '.$row.', 4, '.($col + 4).', 15, '.($row + $rowsTall).', 4</x:Anchor>'
				.'<x:AutoFill>False</x:AutoFill><x:Row>'.$row.'</x:Row><x:Column>'.$col.'</x:Column></x:ClientData></v:shape>';
		}
		return $x.'</xml>';
	}

	private static function styles(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
			.'<styleSheet xmlns="'.self::NS_MAIN.'">'
			.'<numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy\-mm\-dd"/></numFmts>'
			.'<fonts count="5">'
			.'<font><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/><family val="2"/></font>'
			.'<font><b/><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/><family val="2"/></font>'
			.'<font><b/><sz val="12"/><color rgb="FF4A3B08"/><name val="Calibri"/><family val="2"/></font>'
			.'<font><b/><sz val="11"/><color rgb="FF1E6E43"/><name val="Calibri"/><family val="2"/></font>'
			.'<font><i/><sz val="10"/><color rgb="FF646A73"/><name val="Calibri"/><family val="2"/></font>'
			.'</fonts>'
			.'<fills count="5">'
			.'<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
			.'<fill><patternFill patternType="solid"><fgColor rgb="FFDBE9F6"/><bgColor indexed="64"/></patternFill></fill>'
			.'<fill><patternFill patternType="solid"><fgColor rgb="FFFFF4D6"/><bgColor indexed="64"/></patternFill></fill>'
			.'<fill><patternFill patternType="solid"><fgColor rgb="FFE4F0DE"/><bgColor indexed="64"/></patternFill></fill>'
			.'</fills>'
			.'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
			.'<border><left style="thin"><color rgb="FFC7D5E2"/></left><right style="thin"><color rgb="FFC7D5E2"/></right>'
			.'<top style="thin"><color rgb="FFC7D5E2"/></top><bottom style="thin"><color rgb="FFC7D5E2"/></bottom><diagonal/></border></borders>'
			.'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			.'<cellXfs count="8">'
			.'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
			.'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
			.'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
			.'<xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf>'
			.'<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
			.'<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" quotePrefix="1"/>'
			.'<xf numFmtId="0" fontId="1" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
			.'<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
			.'</cellXfs>'
			.'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			.'</styleSheet>';
	}

	/* ------------------------------------------------------------------ reading */

	/**
	 * A workbook's sheets, in tab order: [sheet name => [rowNumber => [colNumber => text]]].
	 * Numbers come back as their shortest text (20000, 12.5), booleans as TRUE / FALSE, dates as
	 * Y-m-d, errors as empty. Empty cells and empty rows are left out. Throws RuntimeException with
	 * a sentence for the operator.
	 */
	public static function read(string $bytes): array {
		$files = Zip::read($bytes);
		$part = function (string $path) use ($files): ?string {
			return $files[$path] ?? null;
		};
		$main = 'xl/workbook.xml';
		$rootRels = $part('_rels/.rels');
		if ($rootRels !== null) {
			foreach (self::rels($rootRels, '') as $rel) {
				if (substr($rel['type'], -strlen('/officeDocument')) === '/officeDocument') {
					$main = $rel['target'];
				}
			}
		}
		$wb = $part($main);
		if ($wb === null) {
			throw new RuntimeException(_('The file is a ZIP but not an Excel workbook: it has no workbook part. Save it as .xlsx from Excel.'));
		}
		$rels = self::rels((string) $part(self::relsPath($main)), dirname($main));
		$byId = array_column($rels, null, 'id');

		[$sheets, $date1904] = self::workbookSheets($wb);
		$shared = [];
		$dateStyles = [];
		foreach ($rels as $rel) {
			if (substr($rel['type'], -strlen('/sharedStrings')) === '/sharedStrings' && $part($rel['target']) !== null) {
				$shared = self::sharedStrings($part($rel['target']));
			}
			if (substr($rel['type'], -strlen('/styles')) === '/styles' && $part($rel['target']) !== null) {
				$dateStyles = self::dateStyles($part($rel['target']));
			}
		}
		$out = [];
		foreach ($sheets as [$name, $rid]) {
			$target = $byId[$rid]['target'] ?? null;
			if ($target === null || $part($target) === null || substr($byId[$rid]['type'], -strlen('/worksheet')) !== '/worksheet') {
				continue;
			}
			$out[$name] = self::sheetRows($part($target), $shared, $dateStyles, $date1904, $name);
		}
		return $out;
	}

	private static function relsPath(string $part): string {
		$dir = dirname($part);
		return ($dir === '.' ? '' : $dir.'/').'_rels/'.basename($part).'.rels';
	}

	/** A reader over one XML part, refusing a DOCTYPE outright and never touching the network. */
	private static function reader(string $xml, string $what): XMLReader {
		if (strncmp($xml, "\xFE\xFF", 2) === 0 || strncmp($xml, "\xFF\xFE", 2) === 0) {
			throw new RuntimeException(_s('The workbook\'s %1$s is not UTF-8 XML, which Excel always writes. Refused.', $what));
		}
		if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
			throw new RuntimeException(_s('The workbook\'s %1$s declares a DOCTYPE or an entity, which an Excel file never does. Refused.', $what));
		}
		$r = new XMLReader();
		if (!$r->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) {
			throw new RuntimeException(_s('The workbook\'s %1$s could not be read.', $what));
		}
		return $r;
	}

	/** Runs $fn over a reader, turning any XML error into one sentence. */
	private static function parse(string $xml, string $what, callable $fn) {
		$prev = libxml_use_internal_errors(true);
		try {
			$r = self::reader($xml, $what);
			$result = $fn($r);
			$errors = libxml_get_errors();
			$r->close();
			if ($errors) {
				throw new RuntimeException(_s('The workbook\'s %1$s is not valid XML (line %2$s: %3$s). Save it again from Excel.', $what,
					$errors[0]->line, trim($errors[0]->message)));
			}
			return $result;
		}
		finally {
			libxml_clear_errors();
			libxml_use_internal_errors($prev);
		}
	}

	/** [['id', 'type', 'target' (a path inside the ZIP)], …] */
	private static function rels(string $xml, string $base): array {
		if ($xml === '') {
			return [];
		}
		return self::parse($xml, _('relationships'), function (XMLReader $r) use ($base) {
			$out = [];
			while ($r->read()) {
				if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'Relationship' && $r->getAttribute('TargetMode') !== 'External') {
					$out[] = ['id' => (string) $r->getAttribute('Id'), 'type' => (string) $r->getAttribute('Type'),
						'target' => self::resolve($base, (string) $r->getAttribute('Target'))];
				}
			}
			return $out;
		});
	}

	/** A relationship target as a path inside the ZIP. */
	private static function resolve(string $base, string $target): string {
		$path = $target !== '' && $target[0] === '/' ? substr($target, 1) : (($base === '' || $base === '.') ? '' : $base.'/').$target;
		$parts = [];
		foreach (explode('/', $path) as $seg) {
			if ($seg === '..') {
				array_pop($parts);
			}
			elseif ($seg !== '.' && $seg !== '') {
				$parts[] = $seg;
			}
		}
		return implode('/', $parts);
	}

	/** [[[name, relationship id], …], date1904] */
	private static function workbookSheets(string $xml): array {
		return self::parse($xml, 'workbook.xml', function (XMLReader $r) {
			$sheets = [];
			$date1904 = false;
			while ($r->read()) {
				if ($r->nodeType !== XMLReader::ELEMENT) {
					continue;
				}
				if ($r->localName === 'workbookPr') {
					$date1904 = in_array(strtolower((string) $r->getAttribute('date1904')), ['1', 'true'], true);
				}
				elseif ($r->localName === 'sheet') {
					$rid = null;
					$name = (string) $r->getAttribute('name');
					if ($r->moveToFirstAttribute()) {
						do {
							if ($r->localName === 'id' && $r->namespaceURI !== '') {
								$rid = $r->value;
							}
						} while ($r->moveToNextAttribute());
						$r->moveToElement();
					}
					if ($rid !== null) {
						$sheets[] = [$name, $rid];
					}
				}
			}
			return [$sheets, $date1904];
		});
	}

	private static function sharedStrings(string $xml): array {
		return self::parse($xml, 'sharedStrings.xml', function (XMLReader $r) {
			$out = [];
			$cur = null;
			$phonetic = 0;
			while ($r->read()) {
				if ($r->nodeType === XMLReader::ELEMENT) {
					if ($r->localName === 'si') {
						$cur = '';
						if ($r->isEmptyElement) {
							$out[] = '';
							$cur = null;
						}
					}
					elseif ($r->localName === 'rPh' && !$r->isEmptyElement) {
						$phonetic++;
					}
					elseif ($r->localName === 't' && $cur !== null && !$phonetic && !$r->isEmptyElement) {
						$cur .= $r->readString();
					}
				}
				elseif ($r->nodeType === XMLReader::END_ELEMENT) {
					if ($r->localName === 'rPh') {
						$phonetic--;
					}
					elseif ($r->localName === 'si' && $cur !== null) {
						$out[] = $cur;
						$cur = null;
					}
				}
			}
			return $out;
		});
	}

	/** cellXfs index => true for the styles whose number format is a date. */
	private static function dateStyles(string $xml): array {
		return self::parse($xml, 'styles.xml', function (XMLReader $r) {
			$formats = [];
			$out = [];
			$inXfs = false;
			$i = 0;
			while ($r->read()) {
				if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'numFmt') {
					$formats[(int) $r->getAttribute('numFmtId')] = (string) $r->getAttribute('formatCode');
				}
				elseif ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'cellXfs') {
					$inXfs = !$r->isEmptyElement;
				}
				elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'cellXfs') {
					$inXfs = false;
				}
				elseif ($inXfs && $r->nodeType === XMLReader::ELEMENT && $r->localName === 'xf') {
					$id = (int) $r->getAttribute('numFmtId');
					if (self::isDateFormat($id, $formats[$id] ?? null)) {
						$out[$i] = true;
					}
					$i++;
				}
			}
			return $out;
		});
	}

	/** Excel's built-in date formats, or a custom one that shows a day, month or year. */
	public static function isDateFormat(int $id, ?string $code): bool {
		if (($id >= 14 && $id <= 22) || ($id >= 27 && $id <= 36) || ($id >= 45 && $id <= 47) || ($id >= 50 && $id <= 58)) {
			return true;
		}
		if ($code === null) {
			return false;
		}
		// Drop quoted text, escaped characters and [colour]/[$-locale] parts, then look for d, m or y.
		$bare = preg_replace(['/"[^"]*"/', '/\\\\./', '/\[[^\]]*\]/'], '', $code);
		return (bool) preg_match('/[dy]/i', (string) $bare) || (stripos((string) $bare, 'm') !== false && !preg_match('/[hs]/i', (string) $bare));
	}

	private static function sheetRows(string $xml, array $shared, array $dateStyles, bool $date1904, string $name): array {
		return self::parse($xml, _s('sheet "%1$s"', $name), function (XMLReader $r) use ($shared, $dateStyles, $date1904, $name) {
			$rows = [];
			$rowNum = 0;
			$colNum = 0;
			$cell = null;
			while ($r->read()) {
				if ($r->nodeType === XMLReader::ELEMENT) {
					switch ($r->localName) {
						case 'row':
							$rowNum = $r->getAttribute('r') !== null ? (int) $r->getAttribute('r') : $rowNum + 1;
							$colNum = 0;
							break;
						case 'c':
							$ref = (string) $r->getAttribute('r');
							if (preg_match('/^([A-Za-z]{1,3})(\d+)$/', $ref, $m)) {
								$colNum = self::colNumber($m[1]);
								$rowNum = (int) $m[2];
							}
							else {
								$colNum++;
							}
							$cell = ['t' => (string) $r->getAttribute('t'), 's' => (int) $r->getAttribute('s'), 'v' => null, 'is' => null];
							if ($r->isEmptyElement) {
								$cell = null;
							}
							break;
						case 'v':
							if ($cell !== null) {
								$cell['v'] = $r->readString();
							}
							break;
						case 'is':
							if ($cell !== null) {
								$cell['is'] = self::inlineText($r);
							}
							break;
					}
				}
				elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'c' && $cell !== null) {
					$v = self::value($cell, $shared, $dateStyles, $date1904);
					if ($v !== '') {
						if (!isset($rows[$rowNum]) && count($rows) >= self::MAX_ROWS) {
							throw new RuntimeException(_s('Sheet "%1$s" has more than %2$s rows filled in; this reads at most that many.', $name, self::MAX_ROWS));
						}
						$rows[$rowNum][$colNum] = $v;
					}
					$cell = null;
				}
			}
			ksort($rows);
			foreach ($rows as &$cells) {
				ksort($cells);
			}
			unset($cells);
			return $rows;
		});
	}

	/** The text of an inline string (<is>), skipping phonetic runs. */
	private static function inlineText(XMLReader $r): string {
		if ($r->isEmptyElement) {
			return '';
		}
		$depth = $r->depth;
		$out = '';
		$phonetic = 0;
		while ($r->read() && !($r->nodeType === XMLReader::END_ELEMENT && $r->depth === $depth)) {
			if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'rPh' && !$r->isEmptyElement) {
				$phonetic++;
			}
			elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'rPh') {
				$phonetic--;
			}
			elseif ($r->nodeType === XMLReader::ELEMENT && $r->localName === 't' && !$phonetic && !$r->isEmptyElement) {
				$out .= $r->readString();
			}
		}
		return $out;
	}

	private static function value(array $cell, array $shared, array $dateStyles, bool $date1904): string {
		$v = $cell['v'];
		switch ($cell['t']) {
			case 's':
				return trim((string) ($shared[(int) $v] ?? ''));
			case 'inlineStr':
				return trim((string) ($cell['is'] ?? $v ?? ''));
			case 'str':
				return trim((string) $v);
			case 'b':
				return $v === null ? '' : ((string) $v === '1' ? 'TRUE' : 'FALSE');
			case 'e':
				return '';
			case 'd':
				return preg_match('/^(\d{4}-\d{2}-\d{2})(?:T00:00(?::00(?:\.0+)?)?Z?)?$/', (string) $v, $m) ? $m[1] : trim((string) $v);
		}
		if ($v === null || trim($v) === '') {
			return '';
		}
		$v = trim($v);
		if (!is_numeric($v)) {
			return $v;
		}
		if (isset($dateStyles[$cell['s']])) {
			return self::fromSerial((float) $v, $date1904);
		}
		return self::numberText($v);
	}

	/** 20000.0 → 20000; 12.300000000000001 → 12.3 (Excel keeps 15 significant digits). */
	public static function numberText(string $v): string {
		if (preg_match('/^-?\d+$/', $v)) {
			return $v;
		}
		$f = (float) $v;
		if (floor($f) === $f && abs($f) < 1e15) {
			return (string) (int) $f;
		}
		$s = sprintf('%.15G', $f);
		if (strpos($s, 'E') === false && strpos($s, '.') !== false) {
			$s = rtrim(rtrim($s, '0'), '.');
		}
		return $s;
	}
}
