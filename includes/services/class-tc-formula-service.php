<?php
/**
 * Safe expression evaluator for formula columns and aggregation footer rows.
 *
 * Formulas reference row values via {field:N} tokens and support basic arithmetic
 * and a curated set of functions. No dynamic code evaluation is used — expressions are tokenized
 * and evaluated via a recursive-descent parser.
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
     * Tokens like {field:3} are replaced with the corresponding value from $row
     * (keyed by field ID as string). The resulting expression is then evaluated
     * by the safe recursive-descent parser.
     *
     * @param string $formula   e.g. '{field:3} * {field:4}' or 'ROUND({field:5}, 2)'
     * @param array  $row       Row values keyed by field ID string, e.g. ['3' => '10.5', '4' => '2']
     * @return string
     */
    public static function evaluate_expression(string $formula, array $row): string {
        // Strip leading '=' so spreadsheet-style "=SUM(...)" and "=2+3" both work.
        $formula = ltrim(trim($formula), '=');

        // Replace {field:N} tokens with row values
        $expr = preg_replace_callback('/\{field:(\d+)\}/', function ($m) use ($row) {
            $val = $row[$m[1]] ?? '0';
            return is_numeric($val) ? $val : '"' . addslashes($val) . '"';
        }, $formula);

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
            // #1621 — optional per-column number format.
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
                // #1621 — optional number format; '#ERR' and non-numeric
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
