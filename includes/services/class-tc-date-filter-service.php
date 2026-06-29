<?php
/**
 * Server-side date-range filter contract for the advanced-filter date modal.
 *
 * Issue #508 — wpDataTables shipped a fix for advanced-filter date modal issues.
 * This service is the canonical, deterministic, format-aware row matcher that
 * backs the AJAX date-filter endpoint and the server-side fallback for
 * non-DataTables renderers. It does not render UI; the modal HTML / picker JS
 * lives elsewhere. The contract here is the calendar-day inclusive range check.
 *
 * Behaviours:
 *  - matches($cell, null, null) returns true (no filter, never veto).
 *  - matches('', $from, $to) returns false (empty cell can't satisfy an active filter).
 *  - matches($cell, $from, $to) is calendar-day inclusive: a cell at 23:59:59 on $to
 *    is included; a cell at 00:00:00 on $from is included.
 *  - $cell_format hint is tried first ('m/d/Y', 'd/m/Y', etc.) so MM/DD vs DD/MM are
 *    never confused when the format is known. Falls back through a list of common
 *    formats and finally to strtotime() for ISO 8601 / RFC dates.
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Date_Filter_Service {

    /**
     * Common cell-side date formats tried in order when no $cell_format is supplied.
     */
    private const FALLBACK_FORMATS = [
        'Y-m-d\TH:i:s',
        'Y-m-d H:i:s',
        'Y-m-d',
        'm/d/Y',
        'd/m/Y',
        'Y/m/d',
        'm-d-Y',
        'd-m-Y',
        'Y.m.d',
        'm.d.Y',
        'd.m.Y',
    ];

    /**
     * Decide whether a single cell's date passes the filter range.
     *
     * Calendar-day inclusive: the cell's date is compared as a calendar day, with the
     * range expanded to [from 00:00:00 .. to 23:59:59] in the supplied date's day.
     *
     * @param mixed       $cell_value   Cell content (string date / ISO timestamp / null / empty).
     * @param string|null $from         Lower bound (inclusive); ISO Y-m-d preferred.
     * @param string|null $to           Upper bound (inclusive); ISO Y-m-d preferred.
     * @param string|null $cell_format  Optional PHP date format hint for the cell value
     *                                  (e.g. 'm/d/Y' or 'd/m/Y') used to disambiguate.
     */
    public static function matches( $cell_value, ?string $from, ?string $to, ?string $cell_format = null ): bool {
        $from = ( $from === '' ) ? null : $from;
        $to   = ( $to   === '' ) ? null : $to;

        // No filter active — never veto.
        if ( $from === null && $to === null ) {
            return true;
        }

        $cell_date = self::parse_cell_date( $cell_value, $cell_format );
        if ( $cell_date === null ) {
            return false;
        }

        // Reduce to calendar day (UTC) for comparison; bounds become start-of-day and end-of-day.
        $cell_day = $cell_date->format( 'Y-m-d' );

        if ( $from !== null ) {
            $from_day = self::parse_bound_date( $from );
            if ( $from_day === null ) { return false; }
            if ( $cell_day < $from_day ) { return false; }
        }

        if ( $to !== null ) {
            $to_day = self::parse_bound_date( $to );
            if ( $to_day === null ) { return false; }
            if ( $cell_day > $to_day ) { return false; }
        }

        return true;
    }

    /**
     * Apply matches() across a dataset.
     *
     * @param array       $rows
     * @param string      $col_id       Column key whose value is the date-bearing cell.
     * @param string|null $from
     * @param string|null $to
     * @param string|null $cell_format
     * @return array  Rows where matches() returned true (preserves original keys).
     */
    public static function filter_rows( array $rows, string $col_id, ?string $from, ?string $to, ?string $cell_format = null ): array {
        $out = [];
        foreach ( $rows as $key => $row ) {
            $cell = is_array( $row ) ? ( $row[ $col_id ] ?? '' ) : '';
            if ( self::matches( $cell, $from, $to, $cell_format ) ) {
                $out[ $key ] = $row;
            }
        }
        return $out;
    }

    /**
     * Parse a cell date value into a DateTimeImmutable (UTC), trying the format hint
     * first and then a list of common fallbacks, finally a permissive strtotime().
     */
    private static function parse_cell_date( $cell_value, ?string $cell_format ): ?DateTimeImmutable {
        if ( $cell_value === null || $cell_value === '' ) {
            return null;
        }
        if ( ! is_string( $cell_value ) ) {
            $cell_value = (string) $cell_value;
        }
        $cell_value = trim( $cell_value );
        if ( $cell_value === '' ) {
            return null;
        }

        $tz = new DateTimeZone( 'UTC' );

        if ( $cell_format !== null && $cell_format !== '' ) {
            $d = DateTimeImmutable::createFromFormat( '!' . $cell_format, $cell_value, $tz );
            if ( $d instanceof DateTimeImmutable ) {
                return $d;
            }
        }

        foreach ( self::FALLBACK_FORMATS as $fmt ) {
            $d = DateTimeImmutable::createFromFormat( '!' . $fmt, $cell_value, $tz );
            if ( $d instanceof DateTimeImmutable ) {
                return $d;
            }
        }

        // Last resort — strtotime handles ISO 8601 with timezone offsets, RFC 2822, etc.
        $ts = strtotime( $cell_value );
        if ( $ts !== false ) {
            return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
        }

        return null;
    }

    /**
     * Parse a filter bound (from / to) into a Y-m-d calendar-day string.
     * Accepts ISO Y-m-d directly, otherwise tries the same fallback chain.
     */
    private static function parse_bound_date( string $bound ): ?string {
        $bound = trim( $bound );
        if ( $bound === '' ) {
            return null;
        }
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $bound ) ) {
            return $bound;
        }
        $tz = new DateTimeZone( 'UTC' );
        foreach ( self::FALLBACK_FORMATS as $fmt ) {
            $d = DateTimeImmutable::createFromFormat( '!' . $fmt, $bound, $tz );
            if ( $d instanceof DateTimeImmutable ) {
                return $d->format( 'Y-m-d' );
            }
        }
        $ts = strtotime( $bound );
        if ( $ts !== false ) {
            return gmdate( 'Y-m-d', $ts );
        }
        return null;
    }
}
