<?php
/**
 * TC_Import_Format_Detector — format detection and share-URL fixups (#2322).
 *
 * Pure static utility class. No WordPress dependencies except the
 * translation function. (Wording note: test-issue-133 scans this file with
 * an i18n-call regex — writing the double-underscore function name with
 * parens in a comment makes that scan misfire across lines.)
 *
 * Detects file format from:
 *   1. Explicit MIME type (when provided by PHP $_FILES)
 *   2. File extension (lowercased)
 *   3. Byte-sniffing for common magic bytes
 *
 * Also normalises Google Sheets / OneDrive / Dropbox share links to a
 * direct-download URL suitable for wp_remote_get().
 *
 * @package GravityTables
 * @since 8.0.45 (#2322)
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH') && !defined('TC_PHPUNIT_SHIM')) {
    exit;
}
// @codeCoverageIgnoreEnd

class TC_Import_Format_Detector {

    /**
     * Supported import formats and their canonical reader keys.
     */
    const SUPPORTED_FORMATS = ['csv', 'xlsx', 'xls', 'ods', 'html', 'json', 'zip'];

    /**
     * MIME -> canonical format map.
     */
    const MIME_MAP = [
        'text/csv'                                                                            => 'csv',
        'text/plain'                                                                          => 'csv', // .csv sometimes reported as text/plain
        'application/csv'                                                                     => 'csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'                  => 'xlsx',
        'application/vnd.ms-excel'                                                            => 'xls',
        'application/vnd.oasis.opendocument.spreadsheet'                                     => 'ods',
        'text/html'                                                                           => 'html',
        'application/xhtml+xml'                                                               => 'html',
        'application/json'                                                                    => 'json',
        'text/json'                                                                           => 'json',
        'application/zip'                                                                     => 'zip',
        'application/x-zip-compressed'                                                       => 'zip',
        'application/octet-stream'                                                            => null,  // fall through to extension / magic
    ];

    /**
     * Extension -> canonical format map.
     */
    const EXTENSION_MAP = [
        'csv'  => 'csv',
        'tsv'  => 'csv',  // treat TSV as CSV variant; parser handles delimiter detection
        'txt'  => 'csv',
        'xlsx' => 'xlsx',
        'xls'  => 'xls',
        'ods'  => 'ods',
        'htm'  => 'html',
        'html' => 'html',
        'json' => 'json',
        'zip'  => 'zip',
    ];

    /**
     * Detect format from a filename and optional MIME type.
     *
     * @param string      $filename   Original filename (e.g. "data.xlsx").
     * @param string|null $mime_type  PHP-reported MIME (e.g. from $_FILES[*]['type']).
     * @return string|null  Canonical format key or null if unrecognised.
     */
    public static function from_filename_and_mime(string $filename, ?string $mime_type = null): ?string {
        // 1. MIME check (unless it's octet-stream or text/plain — too ambiguous).
        if ($mime_type !== null) {
            $mime_lower = strtolower(trim($mime_type));
            if (isset(self::MIME_MAP[$mime_lower]) && self::MIME_MAP[$mime_lower] !== null) {
                // For text/plain, prefer the extension because .xlsx reported as
                // application/octet-stream on some browsers. But for unambiguous
                // MIMEs, trust the MIME.
                if ($mime_lower !== 'text/plain') {
                    return self::MIME_MAP[$mime_lower];
                }
            }
        }

        // 2. Extension.
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (isset(self::EXTENSION_MAP[$ext])) {
            return self::EXTENSION_MAP[$ext];
        }

        return null;
    }

    /**
     * Detect format by sniffing the first bytes of a file.
     *
     * Useful when filename/MIME are not available (e.g. URL body).
     *
     * @param string $bytes  Raw bytes (at least 8 bytes for reliable detection).
     * @return string|null   Canonical format key or null if unrecognised.
     */
    public static function from_magic_bytes(string $bytes): ?string {
        if (strlen($bytes) < 4) {
            return null;
        }

        // ZIP / XLSX / XLSM / ODS are all ZIP-based: PK\x03\x04
        if (substr($bytes, 0, 4) === "PK\x03\x04") {
            // We can't distinguish XLSX from ODS at the magic-byte level without
            // inspecting the ZIP contents. Return 'xlsx' as the best guess; the
            // parser will try IOFactory::identify() for a better answer.
            return 'xlsx';
        }

        // Legacy XLS: Compound Document File Format D0 CF 11 E0 A1 B1 1A E1
        if (substr($bytes, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            return 'xls';
        }

        // JSON: starts with { or [ (after optional whitespace/BOM)
        $trimmed = ltrim($bytes, "\xef\xbb\xbf \t\n\r");
        if (isset($trimmed[0]) && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            return 'json';
        }

        // HTML: starts with < (DOCTYPE, <html, etc.)
        if (isset($trimmed[0]) && $trimmed[0] === '<') {
            return 'html';
        }

        // ODS: also ZIP-based — caught by PK magic above; IOFactory identifies it.
        // CSV: fallback.
        return 'csv';
    }

    /**
     * Detect format combining filename, MIME, and magic-byte sniff.
     *
     * @param string      $filename
     * @param string|null $mime_type
     * @param string|null $first_bytes  Optional: first 8+ bytes of the file body.
     * @return string  Canonical format key; defaults to 'csv'.
     */
    public static function detect(string $filename, ?string $mime_type = null, ?string $first_bytes = null): string {
        $format = self::from_filename_and_mime($filename, $mime_type);
        if ($format !== null) {
            return $format;
        }
        if ($first_bytes !== null) {
            $sniffed = self::from_magic_bytes($first_bytes);
            if ($sniffed !== null) {
                return $sniffed;
            }
        }
        return 'csv';
    }

    // -------------------------------------------------------------------------
    // Share-URL fixups
    // -------------------------------------------------------------------------

    /**
     * Normalise a share URL to a direct-download URL.
     *
     * Supports:
     *   - Google Sheets share link  → CSV export URL
     *   - Google Drive file link    → direct download URL
     *   - OneDrive share link       → direct download URL
     *   - Dropbox share link        → direct download URL (dl=1)
     *
     * Returns the original URL unchanged if no fixup rule matches.
     *
     * @param string $url  Input URL (may be a share link).
     * @return string      Direct-download URL.
     */
    public static function fix_share_url(string $url): string {
        // Google Sheets: https://docs.google.com/spreadsheets/d/{ID}/edit?...
        //             → https://docs.google.com/spreadsheets/d/{ID}/export?format=csv
        if (preg_match('#^https://docs\.google\.com/spreadsheets/d/([^/]+)#i', $url, $m)) {
            return 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=csv';
        }

        // Google Drive file: https://drive.google.com/file/d/{ID}/view
        //                  → https://drive.google.com/uc?export=download&id={ID}
        if (preg_match('#^https://drive\.google\.com/file/d/([^/?]+)#i', $url, $m)) {
            return 'https://drive.google.com/uc?export=download&id=' . $m[1];
        }

        // Google Drive open: https://drive.google.com/open?id={ID}
        //                  → https://drive.google.com/uc?export=download&id={ID}
        if (preg_match('#^https://drive\.google\.com/open\?.*id=([^&]+)#i', $url, $m)) {
            return 'https://drive.google.com/uc?export=download&id=' . $m[1];
        }

        // OneDrive share: https://1drv.ms/... or https://onedrive.live.com/...
        //               → append ?download=1 (or convert resid share to download)
        if (preg_match('#^https://(1drv\.ms|onedrive\.live\.com)/#i', $url)) {
            // Convert ?resid=...&authkey=... share pages to download URL.
            if (strpos($url, 'resid=') !== false) {
                // Rewrite onedrive.live.com/view... to /download...
                $url = preg_replace('#/view\.aspx#i', '/download.aspx', $url);
            }
            // If dl=1 or download=1 not already present, append.
            if (strpos($url, 'download=1') === false && strpos($url, 'dl=1') === false) {
                $separator = strpos($url, '?') !== false ? '&' : '?';
                $url .= $separator . 'download=1';
            }
            return $url;
        }

        // Dropbox: https://www.dropbox.com/...?dl=0  → ?dl=1
        //          also handles ?rlkey=... variants
        if (preg_match('#^https://www\.dropbox\.com/#i', $url)) {
            // Replace dl=0 with dl=1
            if (strpos($url, 'dl=0') !== false) {
                return str_replace('dl=0', 'dl=1', $url);
            }
            // No dl param — add it
            if (strpos($url, 'dl=') === false) {
                $separator = strpos($url, '?') !== false ? '&' : '?';
                return $url . $separator . 'dl=1';
            }
            return $url;
        }

        return $url;
    }

    /**
     * Check if a URL looks like a share link that needs fixup.
     *
     * @param string $url
     * @return bool
     */
    public static function is_share_url(string $url): bool {
        return self::fix_share_url($url) !== $url;
    }

    /**
     * Validate MIME type against allowed list for import.
     *
     * @param string $mime_type
     * @return bool
     */
    public static function is_allowed_mime(string $mime_type): bool {
        $mime_lower = strtolower(trim($mime_type));
        return array_key_exists($mime_lower, self::MIME_MAP);
    }
}
