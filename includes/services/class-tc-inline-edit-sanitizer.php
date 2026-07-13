<?php
/**
 * Sanitiser for inline-edit AJAX payloads going into wp_gf_entry_meta.
 *
 * Issue #507 - wpDataTables shipped a fix for inline-editing on dropdown / select
 * columns silently dropping the selected value. The original sanitize path here
 * returned arrays untouched from `sanitize_field_value()`, which `$wpdb->update()`
 * then coerced to the literal string `"Array"`. This service is the single source
 * of truth for turning any inline-edit payload into a `string` safe to store, and
 * for restoring it back on read.
 *
 * Behaviour:
 *  - Empty / null / empty array → ''
 *  - Scalar → sanitize_text_field( (string) $value )
 *  - Array  → JSON-encode the array of sanitised scalars (multi-select payload)
 *             so multi-value selections survive instead of collapsing to "Array".
 *  - Object / nested array → '' (cannot be safely stored as a single meta_value)
 *  - HTML and inline event handlers stripped via sanitize_text_field()
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Inline_Edit_Sanitizer {

    /**
     * Turn an inline-edit payload into a string safe for `meta_value` storage.
     *
     * @param mixed $value
     * @return string
     */
    public static function prepare_for_storage( $value ): string {
        // Null / empty string / empty array: clear the cell.
        if ( $value === null || $value === '' || ( is_array( $value ) && count( $value ) === 0 ) ) {
            return '';
        }

        // Booleans: "1" / "" parity with WP form handling.
        if ( is_bool( $value ) ) {
            return $value ? '1' : '';
        }

        // Multi-select / multi-checkbox payload.
        if ( is_array( $value ) ) {
            $clean = [];
            foreach ( $value as $entry ) {
                // Drop nested arrays / objects - they cannot be stored as a single string.
                if ( is_array( $entry ) || is_object( $entry ) ) {
                    continue;
                }
                if ( $entry === null || $entry === '' ) {
                    continue;
                }
                $sanitised = self::sanitise_scalar( $entry );
                if ( $sanitised === '' ) {
                    // @codeCoverageIgnoreStart
                    continue;
                    // @codeCoverageIgnoreEnd
                }
                $clean[] = $sanitised;
            }
            if ( count( $clean ) === 0 ) {
                // @codeCoverageIgnoreStart
                return '';
                // @codeCoverageIgnoreEnd
            }
            $encoded = wp_json_encode( $clean );
            return is_string( $encoded ) ? $encoded : '';
        }

        // Object / resource - cannot be stored.
        if ( is_object( $value ) || is_resource( $value ) ) {
            return '';
        }

        // Scalar.
        return self::sanitise_scalar( $value );
    }

    /**
     * Reverse `prepare_for_storage()` on a meta_value pulled from the DB.
     *
     * @param string $stored
     * @return string|array  Array if the stored value is a JSON array; otherwise the original string.
     */
    public static function restore_from_storage( string $stored ) {
        if ( $stored === '' ) {
            return '';
        }
        // Heuristic: JSON arrays start with '[' and end with ']'. Anything else is treated
        // as legacy raw text and returned as-is, so this method is safe to apply to every
        // dropdown read regardless of when the row was written.
        if ( $stored[0] !== '[' ) {
            return $stored;
        }
        $decoded = json_decode( $stored, true );
        if ( ! is_array( $decoded ) ) {
            // @codeCoverageIgnoreStart
            return $stored;
            // @codeCoverageIgnoreEnd
        }
        return $decoded;
    }

    /**
     * Sanitise a single scalar payload.
     *
     * Plain scalar (no `<` character): pass through `sanitize_text_field()` - 
     * preserves `&`, quotes, accented characters, hyphens, underscores; strips
     * raw HTML and inline event handlers. This is the existing behaviour for
     * dropdown / select / checkbox / numeric values.
     *
     * Rich-text payload (any `<` character): route through `wp_kses()` with
     * the same allowlist `TC_Sanitization_Service::sanitize_cell_html()` uses,
     * which permits `<a href|target|rel|title>` so hyperlinks survive an
     * inline-edit re-save round-trip (#532). `<script>`, inline event
     * handlers, and `javascript:` schemes are still stripped.
     */
    private static function sanitise_scalar( $value ): string {
        $value = (string) $value;
        if ( strpos( $value, '<' ) === false ) {
            return sanitize_text_field( $value );
        }
        return wp_kses( $value, self::cell_html_allowlist() );
    }

    /**
     * The allowlist for inline-edit rich-text payloads.
     *
     * Mirrors `TC_Sanitization_Service::sanitize_cell_html()` so the inline-edit
     * AJAX path and the bulk-save path apply identical rules - preventing the
     * silent-strip-on-update bug reported as #532. Kept as a private method
     * (not a property) so it stays a single source of truth on each call and
     * cannot drift via mutation.
     */
    private static function cell_html_allowlist(): array {
        return [
            'a'          => [ 'href' => true, 'rel' => true, 'target' => true, 'title' => true ],
            'br'         => [],
            'strong'     => [],
            'b'          => [],
            'em'         => [],
            'i'          => [],
            'u'          => [],
            's'          => [],
            'del'        => [],
            'ins'        => [],
            'p'          => [ 'class' => true, 'style' => true ],
            'span'       => [ 'class' => true, 'style' => true ],
            'ul'         => [ 'class' => true, 'style' => true ],
            'ol'         => [ 'class' => true, 'style' => true, 'start' => true, 'type' => true ],
            'li'         => [ 'class' => true, 'style' => true, 'value' => true ],
            'blockquote' => [ 'class' => true, 'cite' => true ],
            'pre'        => [ 'class' => true ],
            'code'       => [ 'class' => true ],
        ];
    }
}
