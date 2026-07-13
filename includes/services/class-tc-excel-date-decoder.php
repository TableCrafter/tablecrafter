<?php
/**
 * TC_Excel_Date_Decoder
 *
 * Issue #559 - convert Excel-epoch serial numbers (e.g. `44927`) to
 * human-readable ISO 8601 dates (`2023-01-01`). Mirrors a recurring
 * TablePress complaint where the underlying PhpSpreadsheet library
 * returns dates as numeric Excel-epoch values and naive importers
 * store the number verbatim.
 *
 * Pure helper - no PhpSpreadsheet dependency. Foundational service
 * ahead of the XLSX import path (deferred per
 * `class-tc-import.php:11`). When that import lands, every cell in a
 * date-typed column routes through this decoder.
 *
 * Excel epoch math:
 *   Day 25569 = 1970-01-01 (Unix epoch start in Excel days).
 *   Unix timestamp = (excel_serial - 25569) * 86400.
 *   Fractional day = time of day (0.5 = noon).
 *   Day 1 maps to 1899-12-31 in PhpSpreadsheet's convention (the
 *   library skips the famous Lotus 1-2-3 fictitious 1900 leap day,
 *   shifting the epoch back by one day relative to Excel itself for
 *   pre-1900-03-01 dates). We follow the PhpSpreadsheet convention.
 *
 * @since 4.7.25
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Excel_Date_Decoder {

    /**
     * True iff `$value` is a positive numeric in the shape an Excel
     * serial date takes - int / float / numeric-string with no
     * date-separator characters. Strings containing `-` or `/` are
     * treated as date strings, not serials.
     */
    public static function is_excel_serial($value): bool {
        if ($value === null || is_array($value) || is_object($value)) {
            return false;
        }
        if (is_string($value) && preg_match('#[/-]#', $value)) {
            return false;
        }
        if (!is_numeric($value)) {
            return false;
        }
        return ((float) $value) > 0;
    }

    /**
     * Convert `$value` to an ISO 8601 date string when possible.
     *   - Excel serial number → `YYYY-MM-DD` (or `YYYY-MM-DDTHH:MM:SS`
     *     when the serial includes a fractional time component).
     *   - Already-parseable date string → reformatted to `YYYY-MM-DD`.
     *   - Otherwise null.
     */
    public static function to_iso_8601($value): ?string {
        if (self::is_excel_serial($value)) {
            return self::serial_to_iso((float) $value);
        }
        if (is_string($value) && $value !== '') {
            $ts = strtotime($value);
            if ($ts !== false) {
                return gmdate('Y-m-d', $ts);
            }
        }
        return null;
    }

    /**
     * Convert `$value` to a Unix timestamp.
     *   - Excel serial → corresponding Unix timestamp (UTC).
     *   - Date string → strtotime() result.
     *   - Otherwise null.
     */
    public static function to_unix_timestamp($value): ?int {
        if (self::is_excel_serial($value)) {
            $serial = (float) $value;
            return (int) round(($serial - 25569) * 86400);
        }
        if (is_string($value) && $value !== '') {
            $ts = strtotime($value);
            if ($ts !== false) {
                return $ts;
            }
        }
        return null;
    }

    /**
     * Internal: Excel serial → ISO 8601 string. Includes time component
     * when the serial has a non-zero fractional part.
     */
    private static function serial_to_iso(float $serial): ?string {
        if ($serial < 1) {
            return null;
        }
        // The canonical formula (serial - 25569) * 86400 anchors at
        // Excel day 25569 = 1970-01-01 (Unix epoch start). For serials
        // ≥ 61 it returns the real date directly (the Lotus 1-2-3
        // fictitious leap day at serial 60 cancels out because both
        // sides count from the same anchor). For serials 1-59
        // (pre-1900-03-01) the formula naturally returns the date
        // ONE day earlier than Excel's own display, which matches
        // PhpSpreadsheet's convention - they map day 1 to 1899-12-31.
        $unix = ($serial - 25569) * 86400;
        $int_part = (int) floor($unix);
        $has_time = abs($serial - floor($serial)) > 1e-9;
        if ($has_time) {
            return gmdate('Y-m-d\TH:i:s', $int_part);
        }
        return gmdate('Y-m-d', $int_part);
    }
}
