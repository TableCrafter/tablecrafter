<?php
/**
 * TC_Bool - shared boolean coercion helper.
 *
 * Implements `rest_sanitize_boolean` semantics so all read and save
 * paths in the plugin agree on what counts as true/false, regardless
 * of whether the incoming value is a PHP bool, int, or the "false"
 * string that jQuery $.param() serialises unchecked checkboxes into.
 *
 * The problem this solves (#2307, #2308):
 *   - The builder save path posts checkbox values as strings via
 *     jQuery's $.param() serialisation: checked → "true", unchecked →
 *     "false".
 *   - PHP's truthiness check `!empty("false")` evaluates to TRUE,
 *     so every `!empty($settings['collapsible_enabled'])` style read
 *     treated a string-"false" value as enabled.
 *   - `(bool) "false"` in PHP also evaluates to TRUE (non-empty string).
 *   - `filter_var("false", FILTER_VALIDATE_BOOLEAN)` correctly returns
 *     FALSE and is the canonical fix.
 *
 * Cast table (same contract as `rest_sanitize_boolean`):
 *   false, 0, 0.0, "0", "false", ""  → false
 *   true,  1, 1.0, "1", "true"        → true
 *   null                               → false
 *   any other non-empty string         → true (filter_var behaviour)
 *
 * @since 8.0.43
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd

class TC_Bool {

    /**
     * Coerce any scalar to a PHP bool using rest_sanitize_boolean semantics.
     *
     * @param mixed $value
     * @return bool
     */
    public static function cast( $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( is_null( $value ) || $value === '' ) {
            return false;
        }
        if ( is_int( $value ) || is_float( $value ) ) {
            return (bool) $value;
        }
        // For strings: "false"/"0" → false, "true"/"1" → true.
        return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
    }
}
