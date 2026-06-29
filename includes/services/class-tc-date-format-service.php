<?php
/**
 * Per-column date and time display format service.
 *
 * Formats raw Gravity Forms date/time strings for display using a per-column
 * format setting. The raw stored value is preserved for sorting and export.
 * Supports PHP date() token presets and a human-relative "3 days ago" mode.
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Date_Format_Service {

    /**
     * Available display-format presets.
     * Keys are PHP date() format strings; values are human-readable labels.
     */
    const PRESETS = [
        'm/d/Y'       => 'MM/DD/YYYY (US)',
        'd/m/Y'       => 'DD/MM/YYYY (European)',
        'Y-m-d'       => 'YYYY-MM-DD (ISO 8601)',
        'M j, Y'      => 'MMM D, YYYY (e.g. Apr 26, 2026)',
        'j M Y'       => 'D MMM YYYY (e.g. 26 Apr 2026)',
        'd/m/Y H:i'   => 'DD/MM/YYYY HH:mm',
        'Y-m-d H:i'   => 'YYYY-MM-DD HH:mm',
        'g:i A'       => 'Time only (12-hour)',
        'H:i'         => 'Time only (24-hour)',
        'relative'    => 'Relative (e.g. 3 days ago)',
    ];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return all available format presets.
     *
     * @return array  [ format_key => label ]
     */
    public static function get_presets(): array {
        return self::PRESETS;
    }

    /**
     * Return the display format string configured for a column.
     *
     * @param array $column Column config array (may include 'date_format' key).
     * @return string  PHP date() format string or 'relative'.
     */
    public static function get_format_for_column( array $column ): string {
        $format = $column['date_format'] ?? '';
        if ( empty( $format ) ) {
            // Default: use WP site date format.
            $format = function_exists( 'get_option' ) ? get_option( 'date_format', 'Y-m-d' ) : 'Y-m-d';
        }
        return $format;
    }

    /**
     * Format a raw date/time string for display.
     *
     * @param string $raw    Raw value from Gravity Forms entry (any parseable date string).
     * @param string $format PHP date() format string or 'relative'.
     * @param array  $column Column config (unused for now; available for future per-column TZ overrides).
     * @return string Formatted display string, escaped for HTML output.
     */
    public static function format_value( string $raw, string $format, array $column = [] ): string {
        if ( $raw === '' ) {
            return '';
        }

        $timestamp = strtotime( $raw );
        if ( $timestamp === false ) {
            return esc_html( $raw );
        }

        if ( $format === 'relative' ) {
            $now  = current_time( 'timestamp' );
            $diff = abs( $now - $timestamp );
            $label = human_time_diff( $timestamp, $now );
            $display = $timestamp < $now ? sprintf( __( '%s ago', 'tc-data-tables' ), $label ) : sprintf( __( 'in %s', 'tc-data-tables' ), $label );
            return esc_html( $display );
        }

        // Use wp_date() which applies WP timezone setting.
        if ( function_exists( 'wp_date' ) ) {
            $tz = function_exists( 'wp_timezone' ) ? wp_timezone() : null;
            $display = $tz ? wp_date( $format, $timestamp, $tz ) : wp_date( $format, $timestamp );
        } else {
            $display = date( $format, $timestamp );
        }

        return esc_html( $display !== false ? $display : $raw );
    }

    /**
     * Return a raw sortable value (Unix timestamp as zero-padded string) for a
     * date cell so that DataTables / SQL ORDER BY sorts chronologically regardless
     * of the configured display format.
     *
     * @param string $raw  Raw date string from the Gravity Forms entry.
     * @return string  Zero-padded timestamp, or the original $raw on parse failure.
     */
    public static function get_sort_value( string $raw ): string {
        if ( $raw === '' ) {
            return '';
        }
        $timestamp = strtotime( $raw );
        if ( $timestamp === false ) {
            return $raw;
        }
        // Return ISO 8601 (Y-m-d H:i:s) so lexicographic sort == chronological sort.
        return date( 'Y-m-d H:i:s', $timestamp );
    }

    /**
     * Convenience helper: format a cell value using the format configured for
     * the supplied column definition.
     *
     * @param string $raw
     * @param array  $column
     * @return string
     */
    public static function format_for_column( string $raw, array $column ): string {
        return self::format_value( $raw, self::get_format_for_column( $column ), $column );
    }
}
