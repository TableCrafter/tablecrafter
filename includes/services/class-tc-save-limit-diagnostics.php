<?php
/**
 * TC_Save_Limit_Diagnostics
 *
 * Issue #530 - surfaces the actual PHP ini directive responsible for
 * a silent save failure on very large tables. Instead of "could not
 * save", users get an actionable message like "the request body
 * (50 MB) exceeded post_max_size (8M) - ask your host to raise it".
 *
 * Pure, dependency-free helper. No globals. Each detector accepts
 * its inputs explicitly so the same code can be exercised in unit
 * tests with synthetic fixtures.
 *
 * Two detectors are wired into `TC_Ajax::save_table()`:
 *
 *   1. detect_post_max_size_truncation - PHP silently empties $_POST
 *      when the request body exceeds post_max_size, but the
 *      Content-Length header still reflects the real size. We
 *      compare the two and translate the mismatch into a named
 *      error envelope.
 *
 *   2. detect_max_input_vars_truncation - PHP silently truncates
 *      $_POST when the number of input variables exceeds
 *      max_input_vars (default 1000 on many shared hosts). A table
 *      with 30+ columns × 35+ rows blows past the default limit.
 *      We compare the recursive POST var count against the limit;
 *      hitting or exceeding it indicates probable truncation.
 *
 * `current_limits()` returns a snapshot of the four canonical PHP
 * directives (post_max_size, max_input_vars, memory_limit,
 * max_execution_time) so support tickets carry the user's actual
 * limits without a separate round-trip to the phpinfo screen.
 *
 * @since 4.7.12
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Save_Limit_Diagnostics {

    /**
     * Detect $_POST truncation caused by post_max_size.
     *
     * @param int          $content_length  Bytes from $_SERVER['CONTENT_LENGTH'].
     * @param array        $post_array      The parsed $_POST array.
     * @param string|null  $post_max_size_ini  Raw ini value (e.g. "8M") for inclusion in the message.
     * @return array{code:string,message:string,details:array}|null
     */
    public static function detect_post_max_size_truncation(int $content_length, array $post_array, ?string $post_max_size_ini): ?array {
        if ($content_length <= 0) {
            return null;
        }
        if (!empty($post_array)) {
            return null;
        }
        return [
            'code'    => 'post_too_large',
            'message' => sprintf(
                __('The table is too large to save. The request body (%s) exceeds your server\'s post_max_size limit (%s). Ask your host to increase post_max_size, or reduce the number of columns/rows.', 'tc-data-tables'),
                size_format($content_length),
                $post_max_size_ini ?? 'unknown'
            ),
            'details' => [
                'directive'      => 'post_max_size',
                'content_length' => $content_length,
                'limit'          => $post_max_size_ini,
            ],
        ];
    }

    /**
     * Detect $_POST truncation caused by max_input_vars.
     *
     * Counts POST variables recursively (so a table with 30 columns ×
     * 35 rows of column-config arrays is counted at full depth) and
     * compares against the configured limit. Hitting or exceeding the
     * limit is treated as probable truncation - when PHP truncates,
     * the count saturates at exactly the limit, so equality is the
     * tell-tale.
     *
     * @param array     $post_array       The parsed $_POST array.
     * @param int|null  $max_input_vars   Configured limit, or null when unknown.
     * @return array{code:string,message:string,details:array}|null
     */
    public static function detect_max_input_vars_truncation(array $post_array, ?int $max_input_vars): ?array {
        if ($max_input_vars === null || $max_input_vars <= 0) {
            return null;
        }
        $count = count($post_array, COUNT_RECURSIVE);
        if ($count < $max_input_vars) {
            return null;
        }
        return [
            'code'    => 'max_input_vars_exceeded',
            'message' => sprintf(
                __('The table has more form variables (%d) than your server\'s max_input_vars limit (%d). PHP silently truncates additional fields, so saving in this state would lose data. Ask your host to raise max_input_vars to at least %d, or reduce the number of columns/rows.', 'tc-data-tables'),
                $count,
                $max_input_vars,
                max($count + 500, $max_input_vars * 2)
            ),
            'details' => [
                'directive' => 'max_input_vars',
                'var_count' => $count,
                'limit'     => $max_input_vars,
            ],
        ];
    }

    /**
     * Snapshot of the four canonical PHP directives that affect the
     * save path. Returned as a name-keyed array so the values can be
     * spliced into error envelopes verbatim - no parsing needed by
     * the caller.
     *
     * @return array<string,string|int|null>
     */
    public static function current_limits(): array {
        return [
            'post_max_size'      => ini_get('post_max_size') ?: null,
            'max_input_vars'     => self::ini_get_int('max_input_vars'),
            'memory_limit'       => ini_get('memory_limit') ?: null,
            'max_execution_time' => self::ini_get_int('max_execution_time'),
        ];
    }

    private static function ini_get_int(string $key): ?int {
        $raw = ini_get($key);
        if ($raw === false || $raw === '') {
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }
        return (int) $raw;
    }
}
