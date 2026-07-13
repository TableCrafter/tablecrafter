<?php
/**
 * Safe expression evaluator for formula columns and aggregation footer rows.
 *
 * ## Formula dispatch model
 *
 * Formulas reference row values via {field:N} tokens. Two evaluation paths exist:
 *
 * 1. FAST PATH (legacy): formulas WITHOUT a leading '=' use the built-in
 *    recursive-descent parser. Supports arithmetic, ROUND, ABS, IF, CONCAT.
 *    Zero external dependencies; always available.
 *
 * 2. PHPSPREADSHEET PATH (#2321): formulas that start with '=' are evaluated
 *    by PhpOffice\PhpSpreadsheet\Calculation\Calculation after {field:N}
 *    token substitution. This unlocks the full Excel-compatible function set
 *    (UPPER, LOWER, TRIM, CONCATENATE, TEXTJOIN, IF nesting, MOD, POWER,
 *    SQRT, AND, OR, NOT, TEXT, LEN, DATE functions, …).
 *
 * ## Escape convention
 * A leading single-quote before '=' ('=…) signals "display as literal text".
 * The single-quote is consumed and the remainder (starting with '=') is
 * returned verbatim without evaluation. This mirrors TablePress behaviour.
 *
 * ## Security and trust model
 * Formulas come exclusively from admin-authored table configuration stored in
 * wp_options - visitor input NEVER reaches the evaluator. PhpSpreadsheet's
 * Calculation engine cannot execute PHP code (it is a pure formula parser).
 * Additional guards:
 *  - Formula length is capped at MAX_FORMULA_LENGTH characters before eval.
 *  - All PhpSpreadsheet evaluation is wrapped in try/catch; errors render as
 *    the safe inline token '#FORMULA?' (never a fatal or stack trace).
 *  - The singleton's calculation cache is disabled for per-row evaluation so
 *    identical formula text with different substituted values never collides.
 *  - CSV/XLSX export injection protection (TC_CSV_Formula_Detector) operates
 *    on OUTPUT values and is unaffected - computed-column results that happen
 *    to start with '=' are still neutralized before export.
 *
 * No dynamic code evaluation is used on either path - no PHP eval.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Formula_Service {

    const SUPPORTED_AGGREGATIONS = ['SUM', 'AVG', 'MIN', 'MAX', 'COUNT', 'COUNT_DISTINCT'];

    // Supported formula functions (case-insensitive)
    const SUPPORTED_FUNCTIONS = ['ROUND', 'ABS', 'IF', 'CONCAT'];

    /**
     * Maximum formula string length accepted before PhpSpreadsheet evaluation.
     * Admin-authored formulas longer than this are rejected with #FORMULA?.
     */
    const MAX_FORMULA_LENGTH = 2000;

    // ---------------------------------------------------------------------------
    // PhpSpreadsheet singleton (lazy, cache-disabled)
    // ---------------------------------------------------------------------------

    /** @var \PhpOffice\PhpSpreadsheet\Calculation\Calculation|null */
    private static $ps_calc = null;

    /**
     * Return the PhpSpreadsheet Calculation singleton with cache disabled.
     * Lazy-loaded so the class can be loaded in environments where the vendor
     * library is absent (e.g. the free build without xlsx support) without
     * fataling. Returns null if PhpSpreadsheet is not available.
     *
     * @return \PhpOffice\PhpSpreadsheet\Calculation\Calculation|null
     */
    private static function ps_calc() {
        if (self::$ps_calc !== null) {
            return self::$ps_calc;
        }
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\Calculation\\Calculation')) {
            return null;
        }
        self::$ps_calc = \PhpOffice\PhpSpreadsheet\Calculation\Calculation::getInstance();
        // Disable cache so repeated evaluations with different substituted values
        // never collide even when the formula text is identical across rows.
        self::$ps_calc->disableCalculationCache();
        return self::$ps_calc;
    }

    // ---------------------------------------------------------------------------
    // Aggregation
    // ---------------------------------------------------------------------------

    /**
     * Compute an aggregation over a flat array of cell values.
     *
     * @param string $function  One of SUPPORTED_AGGREGATIONS.
     * @param array  $values    Raw cell values (strings).
     * @return float|int|string
     */
    public static function compute_aggregation(string $function, array $values) {
        $function = strtoupper($function);
        $numeric  = array_filter(array_map(fn($v) => is_numeric($v) ? (float) $v : null, $values), fn($v) => $v !== null);

        switch ($function) {
            case 'SUM':
                return array_sum($numeric);

            case 'AVG':
                return count($numeric) > 0 ? array_sum($numeric) / count($numeric) : 0;

            case 'MIN':
                return count($numeric) > 0 ? min($numeric) : 0;

            case 'MAX':
                return count($numeric) > 0 ? max($numeric) : 0;

            case 'COUNT':
                return count($values);

            case 'COUNT_DISTINCT':
                return count(array_unique($values));

            default:
                return 0;
        }
    }

    // ---------------------------------------------------------------------------
    // Expression evaluation
    // ---------------------------------------------------------------------------

    /**
     * Evaluate a formula expression against a single row.
     *
     * Dispatch model (see class docblock for full design rationale):
     *
     *  '=…  →  literal text (single-quote escape; no evaluation)
     *  =…   →  PhpSpreadsheet Calculation (Excel-compatible, #2321)
     *  …    →  built-in recursive-descent parser (legacy fast path)
     *
     * {field:N} tokens are substituted BEFORE dispatch on both paths.
     *
     * @param string $formula   e.g. '{field:3} * {field:4}', '=UPPER({field:1})', "'=literal"
     * @param array  $row       Row values keyed by field ID string, e.g. ['3' => '10.5', '4' => '2']
     * @return string
     */
    public static function evaluate_expression(string $formula, array $row): string {
        $formula = trim($formula);

        // --- '= escape: return literal text (e.g. "'=FORMULA" → "=FORMULA") ---
        if (substr($formula, 0, 2) === "'=") {
            return substr($formula, 1);
        }

        // --- Length guard: reject overly long formulas before any evaluation ---
        if (strlen($formula) > self::MAX_FORMULA_LENGTH) {
            return '#FORMULA?';
        }

        // --- Detect PhpSpreadsheet path: formula starts with '=' ---
        $is_excel = (strlen($formula) > 0 && $formula[0] === '=');

        // Substitute {field:N} tokens in the raw formula string (both paths).
        $expr = preg_replace_callback('/\{field:(\d+)\}/', function ($m) use ($row) {
            $val = $row[$m[1]] ?? '0';
            return is_numeric($val) ? $val : '"' . addslashes($val) . '"';
        }, $formula);

        // --- PhpSpreadsheet path ---
        if ($is_excel) {
            return self::evaluate_via_phpspreadsheet($expr);
        }

        // --- Legacy fast-path (recursive-descent parser) ---
        // Strip a leading '=' that legacy formulas may have (existing behaviour,
        // kept for backward compat with any stored formulas using that style).
        $expr = ltrim($expr, '=');

        try {
            $result = self::parse($expr);
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            return '#ERR';
        // @codeCoverageIgnoreEnd
        }

        if (is_float($result) && floor($result) != $result) {
            return rtrim(rtrim(number_format($result, 10, '.', ''), '0'), '.');
        }
        return (string) $result;
    }

    /**
     * Evaluate an Excel-style formula string via PhpSpreadsheet's Calculation
     * engine. The $expr must already have {field:N} tokens substituted.
     *
     * When PhpSpreadsheet is unavailable (free build without xlsx support),
     * falls back to the built-in recursive-descent parser so that simple
     * arithmetic formulas like "=2+3" continue to work.
     *
     * Returns '#FORMULA?' on any error (parse failure, unknown function, etc.)
     * so a single bad cell never crashes the table render.
     *
     * @internal Called only from evaluate_expression().
     * @param  string $expr Formula with '=' prefix, tokens already substituted.
     * @return string
     */
    private static function evaluate_via_phpspreadsheet(string $expr): string {
        $calc = self::ps_calc();
        if ($calc === null) {
            // PhpSpreadsheet unavailable - fall back to the fast-path parser
            // for backward compat with simple arithmetic formulas using '=' prefix.
            $fallback = ltrim($expr, '=');
            try {
                $result = self::parse($fallback);
            } catch (\Throwable $e) {
                return '#ERR';
            }
            if (is_float($result) && floor($result) != $result) {
                return rtrim(rtrim(number_format($result, 10, '.', ''), '0'), '.');
            }
            return (string) $result;
        }
        try {
            $raw = $calc->calculateFormula($expr);
            // PhpSpreadsheet returns '#DIV/0!', '#VALUE!', etc. as strings for
            // formula-level errors (not exceptions). Surface them as '#FORMULA?'
            // for a uniform, safe error token in the UI.
            if (is_string($raw) && strlen($raw) > 0 && $raw[0] === '#') {
                error_log('[TC] PhpSpreadsheet formula error: ' . $raw . ' | formula: ' . $expr);
                return '#FORMULA?';
            }
            // Normalise numeric floats the same way the fast path does.
            if (is_float($raw) && floor($raw) != $raw) {
                return rtrim(rtrim(number_format($raw, 10, '.', ''), '0'), '.');
            }
            return (string) $raw;
        } catch (\Throwable $e) {
            error_log('[TC] PhpSpreadsheet evaluation threw: ' . $e->getMessage() . ' | formula: ' . $expr);
            return '#FORMULA?';
        }
    }

    // ---------------------------------------------------------------------------
    // Safe recursive-descent parser (no dynamic evaluation)
    // ---------------------------------------------------------------------------

    private static string $input  = '';
    private static int    $pos    = 0;

    /**
     * Entry point: parse and evaluate $expr, returning a scalar value.
     */
    private static function parse(string $expr) {
        self::$input = trim($expr);
        self::$pos   = 0;
        $result = self::parse_expr();
        return $result;
    }

    private static function parse_expr() {
        $left = self::parse_term();
        while (self::$pos < strlen(self::$input)) {
            // #1567: parse_factor() handles leading whitespace, but the
            // loop in parse_expr/parse_term used to raw-index the next
            // character and break on a space. "2 + 3" returned 2.
            self::skip_whitespace();
            if (self::$pos >= strlen(self::$input)) break;
            $ch = self::$input[self::$pos];
            if ($ch === '+') { self::$pos++; $left = $left + self::parse_term(); }
            elseif ($ch === '-') { self::$pos++; $left = $left - self::parse_term(); }
            else break;
        }
        return $left;
    }

    private static function parse_term() {
        $left = self::parse_factor();
        while (self::$pos < strlen(self::$input)) {
            // #1567: same fix as parse_expr -- skip whitespace before
            // sampling the next operator.
            self::skip_whitespace();
            if (self::$pos >= strlen(self::$input)) break;
            $ch = self::$input[self::$pos];
            if ($ch === '*') { self::$pos++; $left = $left * self::parse_factor(); }
            elseif ($ch === '/') {
                self::$pos++;
                $right = self::parse_factor();
                $left  = $right != 0 ? $left / $right : 0;
            }
            elseif ($ch === '%') {
                self::$pos++;
                $right = (int) self::parse_factor();
                $left  = $right != 0 ? fmod((float) $left, (float) $right) : 0;
            }
            else break;
        }
        return $left;
    }

    private static function parse_factor() {
        self::skip_whitespace();
        if (self::$pos >= strlen(self::$input)) return 0;

        $ch = self::$input[self::$pos];

        // Parenthesised sub-expression
        if ($ch === '(') {
            self::$pos++;
            $val = self::parse_expr();
            self::skip_whitespace();
            if (self::$pos < strlen(self::$input) && self::$input[self::$pos] === ')') {
                self::$pos++;
            }
            return $val;
        }

        // Unary minus
        if ($ch === '-') {
            self::$pos++;
            return -self::parse_factor();
        }

        // String literal
        if ($ch === '"' || $ch === "'") {
            return self::parse_string($ch);
        }

        // Function call or number
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)/A', substr(self::$input, self::$pos), $m)) {
            $name = strtoupper($m[1]);
            self::$pos += strlen($m[1]);
            self::skip_whitespace();
            if (self::$pos < strlen(self::$input) && self::$input[self::$pos] === '(') {
                // Function call
                self::$pos++; // consume '('
                $args = self::parse_args();
                self::skip_whitespace();
                if (self::$pos < strlen(self::$input) && self::$input[self::$pos] === ')') {
                    self::$pos++;
                }
                return self::evaluate_function($name, $args);
            }
            // Boolean-ish identifiers
            if ($name === 'TRUE')  return 1;
            if ($name === 'FALSE') return 0;
            return 0;
        }

        // Number
        if (preg_match('/^(\d+(?:\.\d+)?)/A', substr(self::$input, self::$pos), $m)) {
            self::$pos += strlen($m[1]);
            return (float) $m[1];
        }

        self::$pos++;
        return 0;
    }

    private static function parse_string(string $quote): string {
        self::$pos++; // skip opening quote
        $result = '';
        while (self::$pos < strlen(self::$input)) {
            $ch = self::$input[self::$pos];
            if ($ch === $quote) { self::$pos++; break; }
            if ($ch === '\\' && self::$pos + 1 < strlen(self::$input)) {
                self::$pos++;
                $result .= self::$input[self::$pos];
            } else {
                $result .= $ch;
            }
            self::$pos++;
        }
        return $result;
    }

    private static function parse_args(): array {
        $args = [];
        self::skip_whitespace();
        if (self::$pos < strlen(self::$input) && self::$input[self::$pos] === ')') {
            return $args;
        }
        $args[] = self::parse_expr();
        while (self::$pos < strlen(self::$input) && self::$input[self::$pos] === ',') {
            self::$pos++;
            $args[] = self::parse_expr();
        }
        return $args;
    }

    private static function skip_whitespace(): void {
        while (self::$pos < strlen(self::$input) && ctype_space(self::$input[self::$pos])) {
            self::$pos++;
        }
    }

    // ---------------------------------------------------------------------------
    // Built-in functions
    // ---------------------------------------------------------------------------

    /**
     * Evaluate a built-in formula function.
     *
     * @param string $func  Function name (upper-case).
     * @param array  $args  Evaluated arguments.
     * @return float|int|string
     */
    public static function evaluate_function(string $func, array $args) {
        switch (strtoupper($func)) {
            case 'ROUND':
                $val      = (float) ($args[0] ?? 0);
                $decimals = (int)   ($args[1] ?? 0);
                return round($val, $decimals);

            case 'ABS':
                return abs((float) ($args[0] ?? 0));

            case 'IF':
                $condition = $args[0] ?? false;
                $true_val  = $args[1] ?? '';
                $false_val = $args[2] ?? '';
                return $condition ? $true_val : $false_val;

            case 'CONCAT':
                return implode('', array_map('strval', $args));

            default:
                return 0;
        }
    }

    // ---------------------------------------------------------------------------
    // Utilities
    // ---------------------------------------------------------------------------

    /**
     * Strip anything that looks dangerous before storing a formula.
     *
     * Preserves content inside double- and single-quoted string literals byte-for-byte
     * so URLs (with `?`, `&`, `=`, `#`, `_`, `@`, etc.) survive a save → reload round
     * trip (#489). Outside quotes, only parser-meaningful characters and a small set
     * of URL-safe chars are kept; HTML-dangerous chars (`<`, `>`, `` ` ``, `\`) are
     * always stripped.
     */
    public static function sanitize_formula(string $formula): string {
        $out      = '';
        $len      = strlen($formula);
        $in_quote = false;
        $quote_ch = '';

        // Allowed outside quoted literals: digits, letters, spaces, parser operators,
        // field tokens, parens, commas, dots, and the URL-safe sub/gen-delim chars
        // that real-world formulas (e.g. CONCAT(host, "?id=", {field:1})) need to express.
        $allow_outside = '/[a-zA-Z0-9\s\{\}\:\+\-\*\/\%\(\)\.\,\?\&\=\#\_\~\@\!\$\;]/';

        for ($i = 0; $i < $len; $i++) {
            $ch = $formula[$i];

            if ($in_quote) {
                // Inside a string literal: preserve every byte except the closing quote.
                $out .= $ch;
                if ($ch === $quote_ch) {
                    $in_quote = false;
                    $quote_ch = '';
                }
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $in_quote = true;
                $quote_ch = $ch;
                $out     .= $ch;
                continue;
            }

            if (preg_match($allow_outside, $ch)) {
                $out .= $ch;
            }
        }

        return $out;
    }

    /**
     * #1598 - sanitize the builder's computed-column definitions.
     * Accepts the posted JSON string (or an array). Returns a clean
     * list of {id, label, formula}: labels text-sanitized, formulas
     * through sanitize_formula(), incomplete entries dropped, capped
     * at 20, ids reassigned sequentially as gtc_1..gtc_N (the prefix
     * guarantees no collision with numeric GF field ids or the
     * existing system column ids).
     *
     * @param mixed $raw JSON string or array of {label, formula}.
     * @return array<int,array{id:string,label:string,formula:string}>
     */
    public static function sanitize_computed_columns($raw): array {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $def) {
            if (count($out) >= 20) {
                break;
            }
            if (!is_array($def)) {
                continue;
            }
            $label = isset($def['label']) && is_scalar($def['label'])
                ? (function_exists('sanitize_text_field') ? sanitize_text_field((string) $def['label']) : trim(strip_tags((string) $def['label'])))
                : '';
            $formula = isset($def['formula']) && is_scalar($def['formula'])
                ? self::sanitize_formula(trim((string) $def['formula']))
                : '';
            if ($label === '' || $formula === '') {
                continue;
            }
            // #1621 - optional per-column number format.
            $format = isset($def['format']) && is_string($def['format']) && in_array($def['format'], ['int', '2dp'], true)
                ? $def['format']
                : '';
            $out[] = [
                'id'      => 'gtc_' . (count($out) + 1),
                'label'   => $label,
                'formula' => $formula,
                'format'  => $format,
            ];
        }
        return $out;
    }

    /**
     * #1598 - add one key per computed-column def to every row,
     * evaluating the {field:N} expression against that row. Parser
     * failures surface as evaluate_expression's '#ERR' token per
     * cell. Malformed defs are skipped.
     *
     * @param array $rows Row arrays (field_id => value).
     * @param array $defs Output of sanitize_computed_columns().
     * @return array Rows with gtc_* keys added.
     */
    /**
     * #1621 - inline-validation helper for the builder repeater.
     * Sanitizes the formula and dry-runs it against a row where every
     * {field:N} token resolves to its default ('0'). Returns
     * {valid, sanitized}; the parser's '#ERR' contract decides
     * validity, so the builder and the render path can never
     * disagree about what parses.
     *
     * @return array{valid:bool,sanitized:string}
     */
    public static function validate_formula(string $formula): array {
        $sanitized = self::sanitize_formula(trim($formula));
        if ($sanitized === '') {
            return ['valid' => false, 'sanitized' => ''];
        }
        // The recursive-descent parser is lenient with some malformed
        // tails when operands are numeric (e.g. `1 * (` evaluates
        // without throwing), so a dry-run alone under-reports. Pair a
        // numeric-row dry-run with two structural completeness checks:
        // balanced parens and no dangling trailing operator.
        $token_ids = [];
        if (preg_match_all('/\{field:(\d+)\}/', $sanitized, $m)) {
            $token_ids = array_unique($m[1]);
        }
        $numeric_row = [];
        foreach ($token_ids as $tid) {
            $numeric_row[$tid] = '1';
        }
        $balanced = substr_count($sanitized, '(') === substr_count($sanitized, ')');
        $tail_ok  = !preg_match('/[+\-*\/%(,]\s*$/', $sanitized);
        $valid = $balanced && $tail_ok
            && self::evaluate_expression($sanitized, $numeric_row) !== '#ERR';
        return ['valid' => $valid, 'sanitized' => $sanitized];
    }

    public static function augment_rows(array $rows, array $defs): array {
        $clean = [];
        foreach ($defs as $def) {
            if (is_array($def)
                && isset($def['id'], $def['formula'])
                && is_string($def['id']) && $def['id'] !== ''
                && is_string($def['formula']) && $def['formula'] !== ''
            ) {
                $clean[] = $def;
            }
        }
        if ($clean === [] || $rows === []) {
            return $rows;
        }
        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($clean as $def) {
                $value = self::evaluate_expression($def['formula'], $row);
                // #1621 - optional number format; '#ERR' and non-numeric
                // results pass through untouched.
                if (!empty($def['format']) && $value !== '#ERR' && is_numeric($value)) {
                    if ($def['format'] === 'int') {
                        $value = number_format((float) $value, 0, '.', ',');
                    } elseif ($def['format'] === '2dp') {
                        $value = number_format((float) $value, 2, '.', ',');
                    }
                }
                $row[$def['id']] = $value;
            }
        }
        unset($row);
        return $rows;
    }
}
