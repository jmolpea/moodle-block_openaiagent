<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * PDF text extraction utilities.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * PDF text extraction helper.
 *
 * Prefers Smalot\PdfParser when bundled (vendor/autoload.php present),
 * otherwise falls back to a best-effort pure-PHP extractor.
 */
class pdf_text {
    /**
     * Best-effort decode for PDF string bytes.
     *
     * Many PDFs embed UTF-16BE strings (often starting with BOM FEFF).
     *
     * @param string $s Raw PDF string bytes.
     * @return string UTF-8 decoded string.
     */
    private static function maybe_decode_pdf_string(string $s): string {
        if ($s === '') {
            return '';
        }

        $b0 = ord($s[0]);
        $b1 = (strlen($s) > 1) ? ord($s[1]) : null;
        if ($b1 !== null) {
            if ($b0 === 0xFE && $b1 === 0xFF) {
                $payload = substr($s, 2);
                $out = @mb_convert_encoding($payload, 'UTF-8', 'UTF-16BE');
                return ($out !== false) ? $out : $s;
            }
            if ($b0 === 0xFF && $b1 === 0xFE) {
                $payload = substr($s, 2);
                $out = @mb_convert_encoding($payload, 'UTF-8', 'UTF-16LE');
                return ($out !== false) ? $out : $s;
            }
        }

        // Heuristic: NUL bytes usually indicate UTF-16BE/LE without BOM.
        if (strpos($s, "\x00") !== false) {
            $out = @mb_convert_encoding($s, 'UTF-8', 'UTF-16BE');
            if ($out !== false && $out !== '') {
                return $out;
            }
            $out = @mb_convert_encoding($s, 'UTF-8', 'UTF-16LE');
            if ($out !== false && $out !== '') {
                return $out;
            }
        }

        return $s;
    }

    /**
     * Normalize extracted text so empty detection is robust.
     *
     * Strips NBSP, zero-width characters and control characters that would
     * otherwise make a text look non-empty when it carries no real content.
     *
     * @param string $text Raw extracted text.
     * @return string Normalized text.
     */
    public static function normalize_text(string $text): string {
        if ($text === '') {
            return '';
        }

        // Some PDF extractors (including Smalot\PdfParser on certain font
        // encodings) emit bytes that are not valid UTF-8. PCRE with the /u flag
        // returns null on malformed input, which would silently collapse
        // otherwise-good text to empty and fall through to a weaker extractor,
        // so scrub to valid UTF-8 before any Unicode-aware processing.
        if (!mb_check_encoding($text, 'UTF-8')) {
            $scrubbed = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if ($scrubbed === false) {
                $scrubbed = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            }
            $text = (string)$scrubbed;
            if ($text === '') {
                return '';
            }
        }

        $text = preg_replace("/\r\n?/u", "\n", $text);
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/[\x{FEFF}\x{200B}-\x{200D}\x{2060}]/u', '', $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim((string)$text);
    }

    /**
     * Decide if text is effectively empty by counting real letters/numbers.
     *
     * @param string $text     Text to inspect.
     * @param int    $minalnum Minimum number of alphanumeric characters required.
     * @return bool True if the text carries fewer than $minalnum alphanumeric chars.
     */
    public static function is_effectively_empty(string $text, int $minalnum = 10): bool {
        $norm = self::normalize_text($text);
        if ($norm === '') {
            return true;
        }
        if (preg_match_all('/[\p{L}\p{N}]/u', $norm, $m)) {
            return count($m[0]) < $minalnum;
        }
        return (strlen($norm) < $minalnum);
    }

    /**
     * Extract text from a PDF file path.
     *
     * Prefers Smalot\PdfParser if available; falls back to the built-in extractor.
     *
     * @param string $filepath Absolute path to the PDF file.
     * @return string Extracted and normalized text.
     */
    public static function extract(string $filepath): string {
        vendor_loader::load();
        if (class_exists('Smalot\\PdfParser\\Parser')) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($filepath);
                $text = $pdf ? (string)$pdf->getText() : '';
                $text = self::normalize_text($text);
                if (!self::is_effectively_empty($text)) {
                    return $text;
                }
            } catch (\Throwable $e) {
                // Ignore and fall back to built-in extractor.
                unset($e);
            }
        }

        $fallback = self::extract_pdf_text($filepath);
        return self::normalize_text($fallback);
    }

    /**
     * Extract text one page at a time so downstream chunking can cite pages.
     *
     * Uses Smalot\PdfParser's per-page API when available (each element is a
     * page's normalized text, in order). Falls back to a single element with the
     * whole document when per-page extraction is unavailable or empty, so the
     * caller always gets usable text.
     *
     * @param string $filepath Absolute path to the PDF file.
     * @return string[] Page texts in order (page number = index + 1).
     */
    public static function extract_pages(string $filepath): array {
        vendor_loader::load();
        if (class_exists('Smalot\\PdfParser\\Parser')) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($filepath);
                $pages = $pdf ? $pdf->getPages() : [];
                $out = [];
                foreach ($pages as $page) {
                    $out[] = self::normalize_text((string)$page->getText());
                }
                // Only trust per-page extraction when it produced real text;
                // some PDFs yield empty getText() per page but work via getText().
                if (!self::is_effectively_empty(implode("\n", $out))) {
                    return $out;
                }
            } catch (\Throwable $e) {
                unset($e);
            }
        }

        $flat = self::extract($filepath);
        return $flat === '' ? [] : [$flat];
    }

    /**
     * Built-in PDF text extractor (pure PHP, no external binaries).
     *
     * Processes streams incrementally with a hard time budget to avoid
     * blocking cron on pathological PDFs.
     *
     * @param string $filepath Absolute path to the PDF file.
     * @return string Raw (un-normalized) extracted text.
     */
    private static function extract_pdf_text(string $filepath): string {
        if (!is_readable($filepath)) {
            return '';
        }

        $maxbytes = 25 * 1024 * 1024;
        $filesize = @filesize($filepath);
        if ($filesize !== false && $filesize > $maxbytes) {
            return '';
        }

        $data = @file_get_contents($filepath);
        if ($data === false || $data === '') {
            return '';
        }

        $t0 = microtime(true);
        $maxseconds = 25.0;

        $texts = [];
        $offset = 0;
        $processed = 0;
        $maxstreams = 250;

        while ($processed < $maxstreams) {
            if ((microtime(true) - $t0) > $maxseconds) {
                break;
            }

            if (!preg_match('/(<<.*?>>)\s*stream\r?\n/s', $data, $m, PREG_OFFSET_CAPTURE, $offset)) {
                break;
            }

            $dict = $m[1][0] ?? '';
            $streamstart = $m[0][1] + strlen($m[0][0]);

            $endpos = strpos($data, "\nendstream", $streamstart);
            if ($endpos === false) {
                $endpos = strpos($data, "\rendstream", $streamstart);
            }
            if ($endpos === false) {
                break;
            }

            $stream = substr($data, $streamstart, $endpos - $streamstart);
            $offset = $endpos + 9;
            $processed++;

            if (strlen($stream) > 6 * 1024 * 1024) {
                continue;
            }

            $filters = self::pdf_get_filters($dict);
            $decoded = $stream;

            foreach ($filters as $filter) {
                if ((microtime(true) - $t0) > $maxseconds) {
                    break 2;
                }
                $decoded = self::pdf_apply_filter($decoded, $filter, $dict);
                if ($decoded === '') {
                    break;
                }
            }
            if ($decoded === '') {
                continue;
            }

            $t = self::pdf_extract_text_from_stream($decoded);
            if ($t !== '') {
                $texts[] = $t;
            }

            if (strlen(implode("\n", $texts)) > 300000) {
                break;
            }
        }

        $out = preg_replace("/[ \t]+/u", " ", implode("\n", $texts));
        $out = preg_replace("/\r\n?/u", "\n", $out);
        return self::normalize_text($out);
    }

    /**
     * Extract the list of filter names from a PDF stream dictionary.
     *
     * @param string $dict Raw PDF stream dictionary string.
     * @return string[] List of lowercase filter names (e.g. ['flatedecode']).
     */
    private static function pdf_get_filters(string $dict): array {
        $filters = [];
        if (preg_match('/\/Filter\s*(\[(.*?)\]|\/[A-Za-z0-9]+)\b/s', $dict, $m)) {
            $raw = $m[1] ?? '';
            if (strpos($raw, '[') === 0) {
                if (preg_match_all('/\/([A-Za-z0-9]+)/', $raw, $mm)) {
                    foreach ($mm[1] as $f) {
                        $filters[] = strtolower($f);
                    }
                }
            } else {
                $filters[] = strtolower(ltrim($raw, '/'));
            }
        }
        return $filters;
    }

    /**
     * Apply a single PDF filter to a data stream.
     *
     * @param string $data   Raw stream bytes.
     * @param string $filter Lowercase filter name (e.g. 'flatedecode').
     * @param string $dict   Stream dictionary (used for predictor parameters).
     * @return string Decoded bytes, or empty string for unsupported/failed filters.
     */
    private static function pdf_apply_filter(string $data, string $filter, string $dict = ''): string {
        $filter = strtolower($filter);
        if ($filter === 'flatedecode' || $filter === 'flate') {
            $out = @gzuncompress($data);
            if ($out === false) {
                $out = @gzinflate($data);
            }
            if ($out === false) {
                $out = '';
            }

            if ($out !== '' && preg_match('/\/Predictor\s+(\d+)/', $dict, $pm)) {
                $predictor = (int)$pm[1];
                if ($predictor >= 10) {
                    $columns = 0;
                    if (preg_match('/\/Columns\s+(\d+)/', $dict, $cm)) {
                        $columns = (int)$cm[1];
                    }
                    if ($columns > 0) {
                        $out = self::png_unpredict($out, $columns);
                    }
                }
            }
            return $out;
        }

        if ($filter === 'lzwdecode' || $filter === 'lzw') {
            return $data;
        }

        if ($filter === 'asciihexdecode' || $filter === 'ahx') {
            $hex = preg_replace('/\s+/', '', $data);
            $hex = rtrim($hex, '>');
            if (strlen($hex) % 2 === 1) {
                $hex .= '0';
            }
            $bin = @hex2bin($hex);
            return $bin === false ? '' : $bin;
        }

        if ($filter === 'ascii85decode' || $filter === 'a85') {
            $s = preg_replace('/\s+/', '', $data);
            $s = preg_replace('/^<~|~>$/', '', $s);
            $out = '';
            $group = '';
            $count = 0;
            $i = 0;
            $len = strlen($s);
            while ($i < $len) {
                $c = $s[$i++];
                if ($c === 'z' && $count === 0) {
                    $out .= "\x00\x00\x00\x00";
                    continue;
                }
                if ($c < '!' || $c > 'u') {
                    continue;
                }
                $group .= $c;
                $count++;
                if ($count === 5) {
                    $acc = 0;
                    for ($j = 0; $j < 5; $j++) {
                        $acc = $acc * 85 + (ord($group[$j]) - 33);
                    }
                    $out .= pack('N', $acc);
                    $group = '';
                    $count = 0;
                }
            }
            if ($count > 1) {
                for ($j = $count; $j < 5; $j++) {
                    $group .= 'u';
                }
                $acc = 0;
                for ($j = 0; $j < 5; $j++) {
                    $acc = $acc * 85 + (ord($group[$j]) - 33);
                }
                $tmp = pack('N', $acc);
                $out .= substr($tmp, 0, $count - 1);
            }
            return $out;
        }

        return '';
    }

    /**
     * Reverse PNG predictor filtering applied during FlateDecode decompression.
     *
     * @param string $data    Predictor-encoded bytes.
     * @param int    $columns Number of bytes per row (from /Columns in stream dict).
     * @return string Decoded bytes.
     */
    private static function png_unpredict(string $data, int $columns): string {
        $out = '';
        $rowlen = $columns;
        $pos = 0;
        $prev = array_fill(0, $rowlen, 0);
        $dlen = strlen($data);
        while ($pos < $dlen) {
            $filter = ord($data[$pos]);
            $pos++;
            if ($pos + $rowlen > $dlen) {
                break;
            }
            $row = array_map('ord', str_split(substr($data, $pos, $rowlen)));
            $pos += $rowlen;
            $cur = $row;

            if ($filter === 1) {
                for ($i = 0; $i < $rowlen; $i++) {
                    $left = ($i > 0) ? $cur[$i - 1] : 0;
                    $cur[$i] = ($cur[$i] + $left) & 0xFF;
                }
            } else if ($filter === 2) {
                for ($i = 0; $i < $rowlen; $i++) {
                    $cur[$i] = ($cur[$i] + $prev[$i]) & 0xFF;
                }
            } else if ($filter === 3) {
                for ($i = 0; $i < $rowlen; $i++) {
                    $left = ($i > 0) ? $cur[$i - 1] : 0;
                    $up = $prev[$i];
                    $cur[$i] = ($cur[$i] + intdiv($left + $up, 2)) & 0xFF;
                }
            } else if ($filter === 4) {
                for ($i = 0; $i < $rowlen; $i++) {
                    $a = ($i > 0) ? $cur[$i - 1] : 0;
                    $b = $prev[$i];
                    $c = ($i > 0) ? $prev[$i - 1] : 0;
                    $p = $a + $b - $c;
                    $pa = abs($p - $a);
                    $pb = abs($p - $b);
                    $pc = abs($p - $c);
                    $pr = ($pa <= $pb && $pa <= $pc) ? $a : (($pb <= $pc) ? $b : $c);
                    $cur[$i] = ($cur[$i] + $pr) & 0xFF;
                }
            }

            foreach ($cur as $b) {
                $out .= chr($b);
            }
            $prev = $cur;
        }
        return $out;
    }

    /**
     * Extract text content from a decompressed PDF content stream.
     *
     * Handles Tj and TJ operators with both literal and hex string encodings.
     *
     * @param string $stream Decompressed PDF content stream.
     * @return string Extracted text (space-separated, not normalized).
     */
    private static function pdf_extract_text_from_stream(string $stream): string {
        $out = [];

        if (!preg_match_all('/BT(.*?)ET/s', $stream, $blocks)) {
            return '';
        }

        foreach ($blocks[1] as $b) {
            if (preg_match_all('/\((?:\\\\.|[^\\\\])*?\)\s*Tj|\[(.*?)\]\s*TJ/s', $b, $tjm)) {
                if (preg_match_all('/\(((?:\\\\.|[^\\\\])*)\)\s*Tj/s', $b, $m1)) {
                    foreach ($m1[1] as $s) {
                        $lit = self::pdf_unescape_literal($s);
                        $out[] = self::maybe_decode_pdf_string($lit);
                    }
                }

                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*Tj/s', $b, $m1h)) {
                    foreach ($m1h[1] as $hex) {
                        $bin = @hex2bin($hex);
                        if ($bin !== false) {
                            $out[] = self::maybe_decode_pdf_string($bin);
                        }
                    }
                }

                if (preg_match_all('/\[(.*?)\]\s*TJ/s', $b, $m2)) {
                    foreach ($m2[1] as $arr) {
                        if (preg_match_all('/\(((?:\\\\.|[^\\\\])*)\)|<([0-9A-Fa-f]+)>/', $arr, $m3, PREG_SET_ORDER)) {
                            foreach ($m3 as $mm) {
                                if (!empty($mm[1])) {
                                    $lit = self::pdf_unescape_literal($mm[1]);
                                    $out[] = self::maybe_decode_pdf_string($lit);
                                } else if (!empty($mm[2])) {
                                    $bin = @hex2bin($mm[2]);
                                    if ($bin !== false) {
                                        $out[] = self::maybe_decode_pdf_string($bin);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        $text = implode(" ", $out);
        $text = preg_replace("/\s+/u", " ", $text);
        return trim($text);
    }

    /**
     * Unescape a PDF literal string value.
     *
     * Converts PDF octal escapes (\ddd) and standard escape sequences to their
     * character equivalents.
     *
     * @param string $s Raw PDF literal string content (without enclosing parentheses).
     * @return string Unescaped string.
     */
    private static function pdf_unescape_literal(string $s): string {
        $s = preg_replace_callback('/\\\\([0-7]{1,3})/', function ($m) {
            return chr(octdec($m[1]) & 0xFF);
        }, $s);

        $repl = [
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\b' => "\b",
            '\\f' => "\f",
            '\\(' => '(',
            '\\)' => ')',
            '\\\\' => '\\',
        ];
        return strtr($s, $repl);
    }
}
