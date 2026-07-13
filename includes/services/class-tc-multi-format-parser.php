<?php
/**
 * TC_Multi_Format_Parser - parse multiple file formats into rows (#2322).
 *
 * Supports: CSV, XLSX, XLS, ODS, HTML (table extraction), JSON
 * (array-of-objects). ZIP files are extracted in memory and each contained
 * file is parsed in sequence.
 *
 * Security:
 *   - ZIP bomb guard: max 100 entries, max 50 MB decompressed total.
 *   - Path traversal: entry names are sanitized (basename only).
 *   - CSV injection: all cells passed through transform_csv_cell (handled in
 *     the import layer, not here - parser returns raw strings).
 *   - MIME + extension validation is done by TC_Import before calling this
 *     class.
 *
 * @package GravityTables
 * @since 8.0.45 (#2322)
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH') && !defined('TC_PHPUNIT_SHIM')) {
    exit;
}
// @codeCoverageIgnoreEnd

class TC_Multi_Format_Parser {

    /** Maximum ZIP entries to process (zip-bomb guard). */
    const ZIP_MAX_ENTRIES = 100;

    /** Maximum total decompressed bytes across all ZIP entries (50 MB). */
    const ZIP_MAX_DECOMPRESSED = 52428800;

    /** Maximum individual file size for any format (20 MB). */
    const MAX_FILE_SIZE = 20971520;

    /**
     * Parse raw file bytes into rows.
     *
     * Returns: array of assoc arrays (header => value), or WP_Error on failure.
     * For ZIP files, returns an array of per-file result arrays
     * (each entry is itself an array of rows).
     *
     * @param string $bytes
     * @param string $format  Canonical format from TC_Import_Format_Detector::detect().
     * @param array  $options Parsing options:
     *                        - 'rich_html' (bool) Extract XLSX rich formatting as inline HTML
     *                          (requires target column to allow HTML; default false).
     * @return array|WP_Error  Rows or WP_Error.
     */
    public static function parse(string $bytes, string $format, array $options = []) {
        if (strlen($bytes) > self::MAX_FILE_SIZE) {
            return new WP_Error(
                'tc_import_file_too_large',
                __('File exceeds the 20 MB per-file size limit.', 'tc-data-tables')
            );
        }

        switch ($format) {
            case 'csv':
                return self::parse_csv($bytes);
            case 'xlsx':
                return self::parse_spreadsheet($bytes, 'Xlsx', $options);
            case 'xls':
                return self::parse_spreadsheet($bytes, 'Xls', $options);
            case 'ods':
                return self::parse_spreadsheet($bytes, 'Ods', $options);
            case 'html':
                return self::parse_html($bytes);
            case 'json':
                return self::parse_json($bytes);
            case 'zip':
                return self::parse_zip($bytes, $options);
            default:
                return new WP_Error(
                    'tc_import_unknown_format',
                    sprintf(
                        /* translators: %s: format name */
                        __('Unknown import format: %s', 'tc-data-tables'),
                        esc_html($format)
                    )
                );
        }
    }

    // -------------------------------------------------------------------------
    // CSV
    // -------------------------------------------------------------------------

    /**
     * Parse CSV bytes into rows.
     * Strips UTF-8 BOM, handles \r\n / \n / \r line endings.
     *
     * @return array|WP_Error
     */
    public static function parse_csv(string $bytes) {
        // Strip UTF-8 BOM
        if (str_starts_with($bytes, "\xef\xbb\xbf")) {
            $bytes = substr($bytes, 3);
        }

        // Normalise line endings
        $bytes = str_replace(["\r\n", "\r"], "\n", $bytes);

        $tmp = self::write_tmp($bytes, 'csv');
        if ($tmp === false) {
            // @codeCoverageIgnoreStart
            return new WP_Error('tc_import_tmp_fail', __('Could not write temp file for CSV parsing.', 'tc-data-tables'));
            // @codeCoverageIgnoreEnd
        }

        $handle = fopen($tmp, 'r');
        if ($handle === false) {
            @unlink($tmp);
            // @codeCoverageIgnoreStart
            return new WP_Error('tc_import_csv_open_fail', __('Could not open CSV for parsing.', 'tc-data-tables'));
            // @codeCoverageIgnoreEnd
        }

        $headers = fgetcsv($handle, 0, ',', '"', '\\');
        if ($headers === false || empty($headers)) {
            fclose($handle);
            @unlink($tmp);
            return new WP_Error('tc_import_csv_empty', __('CSV file is empty or has no header row.', 'tc-data-tables'));
        }

        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (count($row) === 1 && trim($row[0]) === '') {
                continue; // skip blank lines
            }
            $assoc = [];
            foreach ($headers as $i => $header) {
                $key = trim((string) $header);
                if ($key === '') {
                    continue;
                }
                $assoc[$key] = isset($row[$i]) ? (string) $row[$i] : '';
            }
            $rows[] = $assoc;
        }

        fclose($handle);
        @unlink($tmp);
        return $rows;
    }

    // -------------------------------------------------------------------------
    // Spreadsheet (XLSX / XLS / ODS) via PhpSpreadsheet
    // -------------------------------------------------------------------------

    /**
     * Parse spreadsheet bytes using PhpSpreadsheet.
     *
     * @param string $bytes
     * @param string $reader_type  'Xlsx', 'Xls', or 'Ods'
     * @param array  $options
     * @return array|WP_Error
     */
    public static function parse_spreadsheet(string $bytes, string $reader_type, array $options = []) {
        if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            // @codeCoverageIgnoreStart
            return new WP_Error('tc_import_lib_missing', __('Spreadsheet library (PhpSpreadsheet) is not available.', 'tc-data-tables'));
            // @codeCoverageIgnoreEnd
        }

        $rich_html = !empty($options['rich_html']);

        $tmp = self::write_tmp($bytes, strtolower($reader_type));
        if ($tmp === false) {
            // @codeCoverageIgnoreStart
            return new WP_Error('tc_import_tmp_fail', __('Could not write temp file for spreadsheet parsing.', 'tc-data-tables'));
            // @codeCoverageIgnoreEnd
        }

        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($reader_type);

            if ($rich_html) {
                // Read with formatting so we can extract inline HTML.
                // We do NOT call setReadDataOnly(true) in this mode.
                $spreadsheet = $reader->load($tmp);
                $rows = self::spreadsheet_to_rows_html($spreadsheet);
            } else {
                $reader->setReadDataOnly(true);
                $spreadsheet = $reader->load($tmp);
                $matrix = $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
                $rows = self::rows_from_matrix($matrix);
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            return new WP_Error(
                'tc_import_parse_failed',
                sprintf(
                    /* translators: %s: error message */
                    __('Could not read the spreadsheet file: %s', 'tc-data-tables'),
                    $e->getMessage()
                )
            );
        }

        @unlink($tmp);
        return $rows;
    }

    /**
     * Extract spreadsheet with rich inline HTML (bold/italic/underline/color).
     * Only used when $options['rich_html'] = true.
     *
     * @param \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet
     * @return array  Rows with HTML fragments for formatted cells.
     */
    private static function spreadsheet_to_rows_html($spreadsheet): array {
        $sheet   = $spreadsheet->getActiveSheet();
        $highest = $sheet->getHighestDataRowAndColumn();
        $max_row = $highest['row'];
        $max_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highest['column']);

        if ($max_row < 1 || $max_col < 1) {
            return [];
        }

        $headers = [];
        $rows    = [];

        for ($r = 1; $r <= $max_row; $r++) {
            $row_data = [];
            for ($c = 1; $c <= $max_col; $c++) {
                $col_letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
                $cell = $sheet->getCell($col_letter . $r);
                $row_data[] = self::cell_to_html($cell);
            }

            if ($r === 1) {
                // First row: headers (strip HTML, use plain text)
                foreach ($row_data as $v) {
                    $headers[] = trim(wp_strip_all_tags($v));
                }
            } else {
                // Skip fully empty rows
                $non_empty = array_filter($row_data, fn($v) => trim(wp_strip_all_tags($v)) !== '');
                if (empty($non_empty)) {
                    continue;
                }
                $assoc = [];
                foreach ($headers as $i => $key) {
                    if ($key === '') {
                        continue;
                    }
                    $assoc[$key] = $row_data[$i] ?? '';
                }
                $rows[] = $assoc;
            }
        }

        return $rows;
    }

    /**
     * Convert a PhpSpreadsheet cell to an HTML fragment.
     * Handles bold, italic, underline, and foreground color.
     *
     * @param \PhpOffice\PhpSpreadsheet\Cell\Cell $cell
     * @return string  Inline HTML string (sanitized to text when no formatting).
     */
    private static function cell_to_html($cell): string {
        $value = (string) $cell->getFormattedValue();

        // Try to read rich-text formatting
        try {
            $font = $cell->getStyle()->getFont();
            $html  = esc_html($value);
            $color = $font->getColor()->getRGB();

            if ($font->getBold()) {
                $html = '<strong>' . $html . '</strong>';
            }
            if ($font->getItalic()) {
                $html = '<em>' . $html . '</em>';
            }
            if ($font->getUnderline() && $font->getUnderline() !== 'none') {
                $html = '<u>' . $html . '</u>';
            }
            if ($color && $color !== '000000' && $color !== 'FF000000') {
                // Strip leading FF (alpha channel) if present
                $rgb = strlen($color) === 8 ? substr($color, 2) : $color;
                $html = '<span style="color:#' . esc_attr($rgb) . '">' . $html . '</span>';
            }
            return $html;
        } catch (\Throwable $e) {
            return esc_html($value);
        }
    }

    /**
     * Convert a 2-D matrix (first row = headers) into assoc rows.
     * Mirrors TC_XLSX_Source::rows_from_matrix().
     *
     * @param array $matrix
     * @return array
     */
    public static function rows_from_matrix(array $matrix): array {
        $rows    = [];
        $headers = [];
        $have    = false;

        foreach ($matrix as $line) {
            if (!is_array($line)) {
                continue;
            }
            if (!$have) {
                $headers = array_map(fn($h) => trim((string) $h), $line);
                $have    = true;
                continue;
            }
            $non_empty = array_filter($line, fn($v) => trim((string) $v) !== '');
            if (empty($non_empty)) {
                continue;
            }
            $assoc = [];
            foreach ($headers as $i => $key) {
                if ($key === '') {
                    continue;
                }
                $assoc[$key] = isset($line[$i]) ? (string) $line[$i] : '';
            }
            $rows[] = $assoc;
        }

        return $rows;
    }

    // -------------------------------------------------------------------------
    // HTML table extraction
    // -------------------------------------------------------------------------

    /**
     * Extract rows from the first <table> found in an HTML string.
     *
     * @param string $bytes
     * @return array|WP_Error
     */
    public static function parse_html(string $bytes) {
        // Strip UTF-8 BOM
        if (str_starts_with($bytes, "\xef\xbb\xbf")) {
            $bytes = substr($bytes, 3);
        }

        // Suppress libxml parse errors for malformed HTML (expected for real-world input).
        $prev = libxml_use_internal_errors(true);
        $doc  = new DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . $bytes, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $tables = $doc->getElementsByTagName('table');
        if ($tables->length === 0) {
            return new WP_Error('tc_import_html_no_table', __('No <table> element found in the HTML file.', 'tc-data-tables'));
        }

        $table = $tables->item(0);
        $rows  = [];

        /** @var DOMNodeList<DOMElement> $trs */
        $trs     = $table->getElementsByTagName('tr');
        $headers = [];
        $have    = false;

        foreach ($trs as $tr) {
            // Collect cell text (th or td)
            $cells = [];
            foreach ($tr->childNodes as $child) {
                if ($child->nodeName === 'th' || $child->nodeName === 'td') {
                    $cells[] = trim($child->textContent ?? '');
                }
            }
            if (empty($cells)) {
                continue;
            }

            if (!$have) {
                $headers = $cells;
                $have    = true;
                continue;
            }

            // Skip fully empty rows
            $non_empty = array_filter($cells, fn($v) => $v !== '');
            if (empty($non_empty)) {
                continue;
            }

            $assoc = [];
            foreach ($headers as $i => $key) {
                if ($key === '') {
                    continue;
                }
                $assoc[$key] = $cells[$i] ?? '';
            }
            $rows[] = $assoc;
        }

        if (empty($headers)) {
            return new WP_Error('tc_import_html_empty', __('The HTML table has no header row.', 'tc-data-tables'));
        }

        return $rows;
    }

    // -------------------------------------------------------------------------
    // JSON (array-of-objects)
    // -------------------------------------------------------------------------

    /**
     * Parse a JSON array-of-objects into rows.
     *
     * Also handles:
     *   - Wrapped object: {"data": [...]} - extracts the first array value.
     *   - Single object: {...} - wrapped in array.
     *
     * @param string $bytes
     * @return array|WP_Error
     */
    public static function parse_json(string $bytes) {
        // Strip UTF-8 BOM
        if (str_starts_with($bytes, "\xef\xbb\xbf")) {
            $bytes = substr($bytes, 3);
        }

        $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR | 0);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error(
                'tc_import_json_invalid',
                sprintf(
                    /* translators: %s: JSON error message */
                    __('Invalid JSON: %s', 'tc-data-tables'),
                    json_last_error_msg()
                )
            );
        }

        // If root is an object, look for the first array value.
        if (is_array($decoded) && !array_is_list($decoded)) {
            foreach ($decoded as $v) {
                if (is_array($v) && (empty($v) || array_is_list($v))) {
                    $decoded = $v;
                    break;
                }
            }
        }

        // Single object - wrap in array
        if (is_array($decoded) && !array_is_list($decoded)) {
            $decoded = [$decoded];
        }

        if (!is_array($decoded)) {
            return new WP_Error('tc_import_json_not_array', __('JSON must be an array of objects.', 'tc-data-tables'));
        }

        if (empty($decoded)) {
            return [];
        }

        $rows = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            // Flatten to string values
            $row = [];
            foreach ($item as $key => $value) {
                $row[(string) $key] = is_scalar($value) ? (string) $value : json_encode($value);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    // -------------------------------------------------------------------------
    // ZIP recursion
    // -------------------------------------------------------------------------

    /**
     * Extract and parse all files from a ZIP archive.
     *
     * Security: zip-bomb guard (entry count + decompressed size), no path traversal.
     * Entry names are sanitized to basename only. Nested ZIPs are parsed recursively
     * (only one level deep to prevent recursive zip-bomb).
     *
     * @param string $bytes     Raw ZIP bytes.
     * @param array  $options   Parsing options (passed to child parse() calls).
     * @param bool   $is_nested True when called recursively for nested ZIPs.
     * @return array  Array of ['file' => string, 'rows' => array|WP_Error]
     */
    public static function parse_zip(string $bytes, array $options = [], bool $is_nested = false) {
        if (!class_exists('ZipArchive')) {
            return new WP_Error('tc_import_zip_unavailable', __('ZipArchive extension is not available.', 'tc-data-tables'));
        }

        $tmp = self::write_tmp($bytes, 'zip');
        if ($tmp === false) {
            // @codeCoverageIgnoreStart
            return new WP_Error('tc_import_tmp_fail', __('Could not write temp file for ZIP extraction.', 'tc-data-tables'));
            // @codeCoverageIgnoreEnd
        }

        $zip = new ZipArchive();
        $status = $zip->open($tmp);
        if ($status !== true) {
            @unlink($tmp);
            return new WP_Error('tc_import_zip_open_fail', sprintf(
                /* translators: %d: ZipArchive error code */
                __('Could not open ZIP archive (error %d).', 'tc-data-tables'),
                $status
            ));
        }

        $count = $zip->count();
        if ($count > self::ZIP_MAX_ENTRIES) {
            $zip->close();
            @unlink($tmp);
            return new WP_Error(
                'tc_import_zip_too_many_entries',
                sprintf(
                    /* translators: %d: max entries, %d: actual entries */
                    __('ZIP archive contains %2$d entries (maximum allowed: %1$d).', 'tc-data-tables'),
                    self::ZIP_MAX_ENTRIES,
                    $count
                )
            );
        }

        $results             = [];
        $total_decompressed  = 0;

        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }

            $name = basename((string) $stat['name']); // path traversal guard
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }

            // Skip hidden files / macOS metadata artifacts
            if (str_starts_with($name, '.') || str_starts_with($name, '__MACOSX')) {
                continue;
            }

            // Zip-bomb: check decompressed size
            $decompressed_size = (int) ($stat['size'] ?? 0);
            $total_decompressed += $decompressed_size;
            if ($total_decompressed > self::ZIP_MAX_DECOMPRESSED) {
                $zip->close();
                @unlink($tmp);
                return new WP_Error(
                    'tc_import_zip_too_large',
                    sprintf(
                        /* translators: %s: max size in MB */
                        __('ZIP archive decompressed size exceeds the %s limit.', 'tc-data-tables'),
                        '50 MB'
                    )
                );
            }

            $entry_bytes = $zip->getFromIndex($i);
            if ($entry_bytes === false) {
                $results[] = ['file' => $name, 'rows' => new WP_Error('tc_import_zip_entry_read_fail', sprintf(
                    /* translators: %s: filename */
                    __('Could not read ZIP entry: %s', 'tc-data-tables'),
                    $name
                ))];
                continue;
            }

            // Detect format of this ZIP entry
            $format = TC_Import_Format_Detector::detect($name, null, substr($entry_bytes, 0, 8));

            // Prevent infinite recursion: don't recurse into nested ZIPs if already nested
            if ($format === 'zip' && $is_nested) {
                $results[] = ['file' => $name, 'rows' => new WP_Error(
                    'tc_import_zip_nested_skip',
                    sprintf(
                        /* translators: %s: filename */
                        __('Nested ZIP entry skipped (only one level of ZIP nesting is supported): %s', 'tc-data-tables'),
                        $name
                    )
                )];
                continue;
            }

            if ($format === 'zip') {
                // One level of recursion allowed
                $nested = self::parse_zip($entry_bytes, $options, true);
                if (is_wp_error($nested)) {
                    $results[] = ['file' => $name, 'rows' => $nested];
                } else {
                    // Flatten nested results into parent
                    foreach ($nested as $nested_entry) {
                        $results[] = $nested_entry;
                    }
                }
            } else {
                $rows = self::parse($entry_bytes, $format, $options);
                $results[] = ['file' => $name, 'rows' => $rows];
            }
        }

        $zip->close();
        @unlink($tmp);

        return $results;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Write bytes to a temp file and return the path.
     * Returns false on failure.
     */
    private static function write_tmp(string $bytes, string $suffix): string|false {
        $tmp = function_exists('wp_tempnam')
            ? wp_tempnam('tc-import-.' . $suffix)
            : tempnam(sys_get_temp_dir(), 'tc-import-' . $suffix . '-');
        if ($tmp === false) {
            return false;
        }
        if (file_put_contents($tmp, $bytes) === false) {
            @unlink($tmp);
            return false;
        }
        return $tmp;
    }
}
