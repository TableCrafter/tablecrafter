<?php
/**
 * Slice 2 of #500 — UI / AJAX / URL integration layer for the relative-date filter.
 *
 * The deterministic date-math service TC_Relative_Date_Filter (slice 1, v4.7.2)
 * resolves preset keys to wall-clock [start, end] DateTimeImmutable windows.
 * This integration class is the seam between that pure service and:
 *
 *   - the column header dropdown UI (label source via presets()),
 *   - the AJAX query pipeline (preset key -> calendar-day Y-m-d bounds,
 *     applied via the existing TC_Date_Filter_Service — no parallel SQL),
 *   - URL persistence as `gt_filter_<col>=<preset>`. The `gt_filter_` prefix
 *     is intentionally distinct from the `gt_col_` prefix used by
 *     TC_URL_Filter_Service (#395) so column pre-filters and relative-date
 *     filters can coexist without collision.
 *
 * The `custom` preset is a UI-only signal — to_date_range() returns null for
 * it so the column header can fall back to the existing absolute-date-range
 * picker without re-mounting.
 *
 * @package GravityTables
 * @since 4.7.6
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Relative_Date_Filter_Integration {

    const URL_PARAM_PREFIX = 'gt_filter_';

    /**
     * Mirror of TC_Relative_Date_Filter::presets() so the UI sources its
     * dropdown labels from a single canonical map. Order is preserved.
     *
     * @return array<string, string>
     */
    public static function presets(): array {
        if ( class_exists( 'TC_Relative_Date_Filter' ) ) {
            return TC_Relative_Date_Filter::presets();
        }
        // @codeCoverageIgnoreStart
        return array();
        // @codeCoverageIgnoreEnd
    }

    /**
     * `custom` is a UI signal meaning "open the absolute-date-range picker";
     * it is never a server-side filter.
     */
    public static function is_custom_preset( $preset ): bool {
        return is_string( $preset ) && $preset === 'custom';
    }

    /**
     * Build the URL parameter key for a given column id.
     *
     * Distinct from TC_URL_Filter_Service::URL_PARAM_PREFIX (`gt_col_`) so
     * the two URL-driven filter systems do not collide.
     */
    public static function build_url_param_key( string $col_id ): string {
        return self::URL_PARAM_PREFIX . $col_id;
    }

    /**
     * Resolve a preset key into inclusive Y-m-d ['from','to'] calendar-day
     * bounds, derived from TC_Relative_Date_Filter::range_for() so the
     * AJAX layer reuses the slice-1 date-math without duplication.
     *
     * Returns null for `custom` and any unknown preset key.
     *
     * @return array{from: string, to: string}|null
     */
    public static function to_date_range( string $preset, ?DateTimeImmutable $now = null, ?DateTimeZone $tz = null ): ?array {
        if ( ! class_exists( 'TC_Relative_Date_Filter' ) ) {
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }
        if ( self::is_custom_preset( $preset ) ) {
            return null;
        }
        $range = TC_Relative_Date_Filter::range_for( $preset, $now, $tz );
        if ( $range === null || ! isset( $range['start'], $range['end'] ) ) {
            return null;
        }
        return array(
            'from' => $range['start']->format( 'Y-m-d' ),
            'to'   => $range['end']->format( 'Y-m-d' ),
        );
    }

    /**
     * Parse a $_GET-shaped array into a [col_id => preset_key] map for every
     * `gt_filter_<col>=<preset>` whose value is an exact, known, non-custom
     * preset key. Unknown / empty / mangled values are silently dropped —
     * defence in depth against arbitrary query-string input.
     *
     * @param array $get
     * @return array<string, string>
     */
    public static function parse_url_filters( array $get ): array {
        $valid_keys = array_keys( self::presets() );
        if ( empty( $valid_keys ) ) {
            // @codeCoverageIgnoreStart
            return array();
            // @codeCoverageIgnoreEnd
        }
        // `custom` is never a server-side filter.
        $valid_keys = array_values( array_filter( $valid_keys, function ( $k ) {
            return $k !== 'custom';
        } ) );

        $prefix     = self::URL_PARAM_PREFIX;
        $prefix_len = strlen( $prefix );
        $out        = array();

        foreach ( $get as $key => $raw ) {
            if ( ! is_string( $key ) || strpos( $key, $prefix ) !== 0 ) {
                continue;
            }
            $col_id = sanitize_key( substr( $key, $prefix_len ) );
            if ( $col_id === '' ) {
                continue;
            }
            if ( ! is_scalar( $raw ) ) {
                continue;
            }
            // Compare against the raw scalar (cast to string) — sanitize_text_field
            // would silently strip whitespace/control chars and let a junk value
            // collide with a real preset key. We want exact-match validation.
            $value = (string) $raw;
            if ( ! in_array( $value, $valid_keys, true ) ) {
                continue;
            }
            $out[ $col_id ] = $value;
        }

        return $out;
    }

    /**
     * Filter a dataset by a relative-date preset on $col_id, reusing the
     * existing TC_Date_Filter_Service (the same code path the absolute-date-
     * range filter uses).
     *
     * `custom` and unknown presets are no-ops — rows are returned unchanged
     * so a misconfigured column never silently empties the table.
     *
     * @param array       $rows
     * @param string      $col_id       Row key whose value is the date cell.
     * @param string      $preset
     * @param string|null $cell_format  Optional PHP date format hint, forwarded
     *                                  to TC_Date_Filter_Service to disambiguate
     *                                  MM/DD vs DD/MM.
     */
    public static function apply_to_rows(
        array $rows,
        string $col_id,
        string $preset,
        ?string $cell_format = null,
        ?DateTimeImmutable $now = null,
        ?DateTimeZone $tz = null
    ): array {
        $range = self::to_date_range( $preset, $now, $tz );
        if ( $range === null ) {
            return $rows;
        }
        if ( ! class_exists( 'TC_Date_Filter_Service' ) ) {
            // @codeCoverageIgnoreStart
            return $rows;
            // @codeCoverageIgnoreEnd
        }
        return TC_Date_Filter_Service::filter_rows( $rows, $col_id, $range['from'], $range['to'], $cell_format );
    }
}
