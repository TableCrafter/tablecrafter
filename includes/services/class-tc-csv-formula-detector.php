<?php
/**
 * TC_CSV_Formula_Detector
 *
 * Slice 1 of #525 - CSV formula detection & persistence (no evaluation
 * yet). Pure, dependency-free helper. Detects spreadsheet-style formula
 * cells in CSV input (`=SUM(A1:A2)`, `=B2*0.07`, `=TODAY()`) and
 * provides a canonical in-band storage marker so a later slice can
 * route stored formula cells through the formula engine on render.
 *
 * Design notes:
 * - Storage marker is a literal `gt_formula:` prefix. Chosen because
 *   the existing pipeline already passes cells through
 *   `sanitize_text_field()` which preserves it verbatim, and because
 *   the colon makes the marker unambiguous against any plausible CSV
 *   user input (no widely-used CSV value starts with `gt_formula:`).
 * - The Excel "Save As CSV" CSV-injection guard pattern (a single
 *   leading `'` followed by `=...`) is intentionally treated as
 *   literal text, mirroring how spreadsheet apps render it.
 * - Toggle-column normalization (#325) takes precedence over formula
 *   detection in the import path; the order is asserted in the
 *   TC_Import wiring (not in this service).
 *
 * @since 4.7.9
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_CSV_Formula_Detector {

    private const MARKER = 'gt_formula:';

    /**
     * Canonical storage marker. Use instead of hardcoding the literal
     * so downstream callers (renderer, eval router) bind to the same
     * source of truth.
     */
    public static function storage_marker(): string {
        return self::MARKER;
    }

    /**
     * True iff $value is a CSV cell that should be persisted as a
     * live formula. The contract:
     *   - Must be a string of length >= 2
     *   - Must start with `=`
     *   - Must NOT start with `'=` (Excel CSV-injection guard pattern
     * - escapes a formula as literal text)
     *
     * Whitespace-prefixed values are treated as plain text on the
     * assumption the CSV import path has already trimmed cells; this
     * keeps the contract narrow and explicit.
     */
    public static function is_formula_cell(string $value): bool {
        if (strlen($value) < 2) {
            return false;
        }
        if ($value[0] !== '=') {
            return false;
        }
        return true;
    }

    /**
     * #1636 - neutralize a cell for EXPORT so spreadsheet apps
     * (Excel/LibreOffice/Sheets) cannot interpret it as a formula or DDE
     * payload. Per OWASP, a value whose first non-space character is one
     * of `= + - @`, or which begins with a TAB/CR, is prefixed with a
     * single quote so the app treats it as literal text.
     *
     * Purely numeric values (including negatives like "-5" / "+5") are
     * returned unchanged - they cannot be a formula and prefixing them
     * would corrupt numeric exports. Non-scalars become an empty string.
     *
     * This is the OUTBOUND mirror of is_formula_cell()/wrap_for_storage(),
     * which govern the INBOUND (import → store-as-live-formula) path and
     * must not be reused here.
     */
    public static function neutralize_for_export($value): string {
        if (!is_scalar($value)) {
            return '';
        }
        $str = (string) $value;
        if ($str === '' || is_numeric($str)) {
            return $str;
        }
        $first = $str[0];
        if ($first === "\t" || $first === "\r") {
            return "'" . $str;
        }
        $lead = ltrim($str);
        if ($lead !== '' && in_array($lead[0], ['=', '+', '-', '@'], true)) {
            return "'" . $str;
        }
        return $str;
    }

    /**
     * #1636 - convenience: neutralize every cell of a CSV/export row.
     */
    public static function neutralize_row(array $row): array {
        return array_map([self::class, 'neutralize_for_export'], $row);
    }

    /**
     * Wrap a formula string with the canonical storage marker so the
     * persisted entry value can be unambiguously identified as a
     * formula on read. Idempotent - wrapping an already-wrapped value
     * returns the input unchanged.
     */
    public static function wrap_for_storage(string $formula): string {
        if (str_starts_with($formula, self::MARKER)) {
            return $formula;
        }
        return self::MARKER . $formula;
    }

    /**
     * Inverse of wrap_for_storage(). Returns the raw formula
     * (without marker) iff $stored carries the marker, else null - 
     * lets callers branch cleanly:
     *
     *   $raw = TC_CSV_Formula_Detector::unwrap_from_storage($cell);
     *   if ($raw !== null) { /* render via formula engine *\/ }
     *   else { /* render as static value *\/ }
     */
    public static function unwrap_from_storage(string $stored): ?string {
        if (!str_starts_with($stored, self::MARKER)) {
            return null;
        }
        return substr($stored, strlen(self::MARKER));
    }

    /**
     * Slice 3 of #525. Return the de-duped, upper-cased list of function
     * names referenced by the formula that the GT formula engine does
     * NOT support - so the import admin UI can warn users that an
     * imported `=SUMIFS(...)` cell will store but won't evaluate.
     *
     * Default whitelist is the union of TC_Formula_Service::SUPPORTED_FUNCTIONS
     * (`ROUND`, `ABS`, `IF`, `CONCAT`) and SUPPORTED_AGGREGATIONS
     * (`SUM`, `AVG`, `MIN`, `MAX`, `COUNT`, `COUNT_DISTINCT`).
     *
     * Sites with a custom formula plugin can extend the whitelist via the
     * `gt_csv_formula_supported_functions` filter (it receives the default
     * union and must return a flat array of upper-cased names). Callers
     * that want to audit imports independently can pass an explicit
     * `$supported` array which beats the filter.
     *
     * @param string     $formula   The cell value as imported.
     * @param array|null $supported Optional whitelist override. When null,
     *                              the default + filter is used.
     * @return string[] Upper-cased, first-seen-ordered, de-duped names.
     */
    public static function unsupported_functions(string $formula, ?array $supported = null): array {
        $info = self::analyze($formula);
        if (empty($info['functions'])) {
            return [];
        }

        if ($supported === null) {
            $default = [];
            if (class_exists('TC_Formula_Service')) {
                if (defined('TC_Formula_Service::SUPPORTED_FUNCTIONS')) {
                    $default = array_merge($default, TC_Formula_Service::SUPPORTED_FUNCTIONS);
                }
                if (defined('TC_Formula_Service::SUPPORTED_AGGREGATIONS')) {
                    $default = array_merge($default, TC_Formula_Service::SUPPORTED_AGGREGATIONS);
                }
            }
            // Filter receives the default union - sites with a custom formula
            // plugin can extend it (e.g. to allow VLOOKUP / SUMIFS / etc.).
            $supported = (array) apply_filters('gt_csv_formula_supported_functions', $default);
        }

        $supported_upper = array_map('strtoupper', array_map('strval', $supported));
        $unsupported = [];
        foreach ($info['functions'] as $name) {
            if (!in_array($name, $supported_upper, true)) {
                $unsupported[] = $name;
            }
        }
        return $unsupported;
    }

    /**
     * Slice 2 of #525. Inspect a formula string and return metadata about
     * what it references. Currently surfaces the de-duplicated, upper-cased
     * list of function names called in the formula, so a future slice can
     * compare against the engine's supported-function whitelist and surface
     * a warning when imported formulas reference unsupported functions
     * (issue #525 AC#3).
     *
     * Returns the same `['functions' => []]` shape regardless of whether
     * the input is a formula, so callers can rely on the structure without
     * branching.
     *
     * @param string $formula The cell value as imported (with or without `=` prefix).
     * @return array{functions: string[]}
     */
    public static function analyze(string $formula): array {
        $info = ['functions' => []];

        // Only formulas have functions to analyze. Non-formulas return empty.
        if (!self::is_formula_cell($formula)) {
            return $info;
        }

        // A spreadsheet function call looks like `NAME(` where NAME is one or
        // more letters, optionally followed by digits/underscores/dots (Excel
        // allows `_xlfn.SUMIFS` and similar - we keep the leading letter
        // requirement so cell refs like `A1(` aren't matched). Normalize to
        // uppercase so the unsupported-function lookup that the future
        // warning slice does is case-insensitive.
        if (preg_match_all('/([A-Za-z][A-Za-z0-9_.]*)\s*\(/', $formula, $matches)) {
            $seen = [];
            foreach ($matches[1] as $name) {
                $upper = strtoupper($name);
                if (!isset($seen[$upper])) {
                    $seen[$upper] = true;
                    $info['functions'][] = $upper;
                }
            }
        }

        return $info;
    }
}
