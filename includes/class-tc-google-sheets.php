<?php
/**
 * Google Sheets live sync data source for Gravity Tables.
 *
 * Supports two modes:
 *  - Public  : "Publish to the web" CSV export URL (no auth required).
 *  - Private : Google Sheets API v4 with an API key + spreadsheet ID + sheet name.
 *
 * Data is cached via WP transients and refreshed on a configurable WP-Cron schedule.
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Google_Sheets {

    private static ?TC_Google_Sheets $instance = null;

    private const CACHE_PREFIX   = 'gt_gsheets_';
    private const SYNCED_PREFIX  = 'gt_gsheets_synced_';
    private const CRON_HOOK      = 'gt_google_sheets_sync';
    private const SHEETS_API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

    /** Supported cron intervals (mapped to WP schedule names). */
    private const INTERVALS = [
        '15min'     => 'gt_15min',
        'hourly'    => 'hourly',
        'twicedaily'=> 'twicedaily',
        'daily'     => 'daily',
        'manual'    => '',
    ];

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( self::CRON_HOOK, [ $this, 'handle_cron_sync' ], 10, 2 );
    }

    // -------------------------------------------------------------------------
    // URL detection
    // -------------------------------------------------------------------------

    /**
     * Return true when $url looks like a Google Sheets URL (any variant).
     */
    public function is_sheets_url( string $url ): bool {
        return (bool) preg_match(
            '#https?://docs\.google\.com/spreadsheets#i',
            $url
        );
    }

    /**
     * Convert a standard Google Sheets share/edit URL to its CSV export equivalent.
     *
     * Example input : https://docs.google.com/spreadsheets/d/{ID}/edit#gid=0
     * Example output: https://docs.google.com/spreadsheets/d/{ID}/export?format=csv&gid=0
     */
    public function to_csv_export_url( string $url ): string {
        // Already a pub CSV URL — return as-is.
        if ( strpos( $url, '/pub?' ) !== false && strpos( $url, 'output=csv' ) !== false ) {
            return $url;
        }

        // Extract spreadsheet ID.
        if ( ! preg_match( '#/spreadsheets/d/([^/]+)#', $url, $m ) ) {
            return $url;
        }
        $id  = $m[1];
        $gid = '';
        if ( preg_match( '/[?&]gid=(\d+)/', $url, $g ) ) {
            $gid = '&gid=' . $g[1];
        } elseif ( preg_match( '/#gid=(\d+)/', $url, $g ) ) {
            $gid = '&gid=' . $g[1];
        }

        return "https://docs.google.com/spreadsheets/d/{$id}/export?format=csv{$gid}";
    }

    // -------------------------------------------------------------------------
    // Public-sheet fetch
    // -------------------------------------------------------------------------

    /**
     * Fetch a publicly-published Google Sheet as a CSV string.
     *
     * @param string $url The publish-to-web CSV URL (or any Sheets URL).
     * @return string|WP_Error CSV text on success, WP_Error on failure.
     */
    public function fetch_public_csv( string $url ) {
        $csv_url  = $this->to_csv_export_url( $url );

        // #1075 — SSRF gate. to_csv_export_url() returns the input URL
        // verbatim when its regex doesn't match (line 73), so $csv_url
        // can be arbitrary user-controlled text. Route every Sheets
        // fetch through the shared validator before wp_remote_get().
        if ( function_exists( 'gt_validate_outbound_url' ) && ! gt_validate_outbound_url( $csv_url ) ) {
            return new WP_Error(
                'gt_outbound_url_rejected',
                __( 'Google Sheets URL was rejected by the outbound-URL SSRF gate.', 'tc-data-tables' )
            );
        }

        $response = wp_remote_get( $csv_url, [
            'timeout'    => 20,
            'user-agent' => 'GravityTables/' . TC_VERSION,
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'gt_sheets_fetch_failed',
                sprintf(
                    /* translators: %s: error message */
                    __( 'Google Sheets fetch failed: %s', 'tc-data-tables' ),
                    $response->get_error_message()
                )
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new WP_Error(
                'gt_sheets_http_error',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Google Sheets returned HTTP %d. Verify the sheet is published to the web.', 'tc-data-tables' ),
                    $code
                )
            );
        }

        return wp_remote_retrieve_body( $response );
    }

    // -------------------------------------------------------------------------
    // Private-sheet fetch (API key)
    // -------------------------------------------------------------------------

    /**
     * Fetch a private Google Sheet via the Sheets API v4.
     *
     * @param string $api_key        Google Cloud API key (Sheets API must be enabled).
     * @param string $spreadsheet_id The spreadsheet ID from the URL.
     * @param string $sheet_name     Tab/sheet name (e.g. "Sheet1").
     * @return array|WP_Error        2-element array ['headers' => [], 'rows' => [[]]] or WP_Error.
     */
    public function fetch_private_sheet( string $api_key, string $spreadsheet_id, string $sheet_name = 'Sheet1' ) {
        if ( empty( $api_key ) ) {
            return new WP_Error(
                'gt_sheets_no_api_key',
                __( 'Google Sheets API key is required for private sheets.', 'tc-data-tables' )
            );
        }

        if ( empty( $spreadsheet_id ) ) {
            return new WP_Error(
                'gt_sheets_no_id',
                __( 'Google Sheets spreadsheet ID is required.', 'tc-data-tables' )
            );
        }

        $range    = rawurlencode( $sheet_name );
        $endpoint = self::SHEETS_API_BASE . "/{$spreadsheet_id}/values/{$range}?key=" . rawurlencode( $api_key );

        $response = wp_remote_get( $endpoint, [
            'timeout'    => 20,
            'user-agent' => 'GravityTables/' . TC_VERSION,
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'gt_sheets_api_failed',
                sprintf(
                    __( 'Google Sheets API request failed: %s', 'tc-data-tables' ),
                    $response->get_error_message()
                )
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );
            $msg  = $data['error']['message'] ?? sprintf( __( 'HTTP %d', 'tc-data-tables' ), $code );
            return new WP_Error( 'gt_sheets_api_error', $msg );
        }

        $body   = wp_remote_retrieve_body( $response );
        $data   = json_decode( $body, true );
        $values = $data['values'] ?? [];

        if ( empty( $values ) ) {
            return new WP_Error( 'gt_sheets_empty', __( 'The sheet returned no data.', 'tc-data-tables' ) );
        }

        $headers = array_map( 'strval', array_shift( $values ) );
        return [ 'headers' => $headers, 'rows' => $values ];
    }

    // -------------------------------------------------------------------------
    // CSV parsing
    // -------------------------------------------------------------------------

    /**
     * Parse a CSV string into headers + rows.
     *
     * Strips UTF-8 BOM (produced by Excel "Save as CSV UTF-8") before parsing.
     *
     * @param string $csv Raw CSV text.
     * @return array{headers: string[], rows: array<int, string[]>}
     */
    public function parse_csv_to_rows( string $csv ): array {
        // Strip UTF-8 BOM (\xEF\xBB\xBF) if present.
        if ( str_starts_with( $csv, "\xef\xbb\xbf" ) ) {
            $csv = substr( $csv, 3 );
        }

        $csv     = str_replace( "\r\n", "\n", $csv );
        $csv     = str_replace( "\r", "\n", $csv );
        $lines   = explode( "\n", trim( $csv ) );
        $headers = [];
        $rows    = [];

        foreach ( $lines as $i => $line ) {
            if ( trim( $line ) === '' ) {
                continue;
            }
            $cells = str_getcsv($line, ',', '"', '');
            if ( $i === 0 ) {
                $headers = array_map( 'trim', $cells );
            } else {
                $rows[] = $cells;
            }
        }

        return [ 'headers' => $headers, 'rows' => $rows ];
    }

    // -------------------------------------------------------------------------
    // Column type mapping
    // -------------------------------------------------------------------------

    /**
     * Map sheet column headers to GT column_type hints.
     *
     * Heuristic rules (case-insensitive header matching):
     *   - headers containing "date" or "time"     → "date"
     *   - headers containing "url", "link", "http" → "url"
     *   - headers containing "image", "photo", "img" → "image"
     *   - headers containing "price", "amount", "cost", "$", "€" → "number"
     *   - all others → "text"
     *
     * @param string[] $headers List of column header strings.
     * @return array<int, array{id: string, label: string, type: string}>
     */
    public function map_column_types( array $headers ): array {
        $columns = [];

        foreach ( $headers as $i => $header ) {
            $lower = strtolower( $header );
            if ( preg_match( '/date|time/', $lower ) ) {
                $type = 'date';
            } elseif ( preg_match( '/url|link|http/', $lower ) ) {
                $type = 'url';
            } elseif ( preg_match( '/image|photo|img/', $lower ) ) {
                $type = 'image';
            } elseif ( preg_match( '/price|amount|cost|\$|€|number|qty|quantity/', $lower ) ) {
                $type = 'number';
            } else {
                $type = 'text';
            }

            $columns[] = [
                'id'    => sanitize_key( $header ?: 'col_' . $i ),
                'label' => sanitize_text_field( $header ),
                'type'  => $type,
            ];
        }

        return $columns;
    }

    // -------------------------------------------------------------------------
    // Caching
    // -------------------------------------------------------------------------

    /**
     * Return cached sheet data for a table, or false if stale/absent.
     *
     * @param int $table_id
     * @return array|false
     */
    public function get_cached_data( int $table_id ) {
        return get_transient( self::CACHE_PREFIX . $table_id );
    }

    /**
     * Fetch the sheet, update the cache, and return the result.
     *
     * Falls back to stale option-stored data if the live fetch fails.
     *
     * @param int   $table_id
     * @param array $settings  Table settings (must include 'sheets_url' or 'sheets_api_key' + 'sheets_id' + 'sheets_tab').
     * @return array|WP_Error
     */
    public function refresh_table( int $table_id, array $settings ) {
        $mode = $settings['sheets_mode'] ?? 'public';

        if ( $mode === 'private' ) {
            $result = $this->fetch_private_sheet(
                $settings['sheets_api_key'] ?? '',
                $settings['sheets_id']      ?? '',
                $settings['sheets_tab']     ?? 'Sheet1'
            );
            if ( is_wp_error( $result ) ) {
                $stale = get_option( self::CACHE_PREFIX . 'stale_' . $table_id, false );
                return $stale !== false ? $stale : $result;
            }
        } else {
            $url = $settings['sheets_url'] ?? '';
            $raw = $this->fetch_public_csv( $url );
            if ( is_wp_error( $raw ) ) {
                $stale = get_option( self::CACHE_PREFIX . 'stale_' . $table_id, false );
                return $stale !== false ? $stale : $raw;
            }
            $result = $this->parse_csv_to_rows( $raw );
        }

        $ttl = $this->interval_to_seconds( $settings['sheets_interval'] ?? 'hourly' );
        set_transient( self::CACHE_PREFIX . $table_id, $result, $ttl );
        update_option( self::CACHE_PREFIX . 'stale_' . $table_id, $result, false );
        update_option( self::SYNCED_PREFIX . $table_id, time(), false );

        return $result;
    }

    /**
     * Return a human-readable "last synced" string, or null if never synced.
     */
    public function get_last_synced( int $table_id ): ?string {
        $ts = get_option( self::SYNCED_PREFIX . $table_id, null );
        if ( $ts === null ) {
            return null;
        }
        return human_time_diff( (int) $ts, time() ) . ' ' . __( 'ago', 'tc-data-tables' );
    }

    // -------------------------------------------------------------------------
    // Scheduling
    // -------------------------------------------------------------------------

    /**
     * Register or update the WP-Cron event for periodic sheet syncs.
     *
     * @param int    $table_id
     * @param string $interval One of the INTERVALS keys (e.g. 'hourly', '15min').
     */
    public function schedule_sync( int $table_id, string $interval ): void {
        $schedule = self::INTERVALS[ $interval ] ?? 'hourly';
        if ( $schedule === '' ) {
            return;
        }

        $hook = self::CRON_HOOK;
        $args = [ $table_id, $interval ];

        if ( ! wp_next_scheduled( $hook, $args ) ) {
            wp_schedule_event( time(), $schedule, $hook, $args );
        }
    }

    /**
     * Unregister the cron event for a table.
     */
    public function unschedule_sync( int $table_id, string $interval = 'hourly' ): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK, [ $table_id, $interval ] );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK, [ $table_id, $interval ] );
        }
    }

    /** WP-Cron callback. */
    public function handle_cron_sync( int $table_id, string $interval ): void {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT settings FROM {$wpdb->prefix}gravity_tables WHERE id = %d", $table_id ),
            ARRAY_A
        );
        if ( ! $row ) {
            return;
        }
        $settings = json_decode( $row['settings'], true );
        if ( empty( $settings['sheets_url'] ) && empty( $settings['sheets_id'] ) ) {
            return;
        }
        $this->refresh_table( $table_id, $settings );
    }

    // -------------------------------------------------------------------------
    // Standardised public API (#409)
    // -------------------------------------------------------------------------

    /**
     * Convert a Google Sheets share URL to its CSV export URL.
     * Returns null if the URL is not a recognisable Google Sheets URL.
     */
    public function get_csv_url( string $sheet_url ): ?string {
        if ( ! preg_match( '#https?://docs\.google\.com/spreadsheets#i', $sheet_url ) ) {
            return null;
        }
        return $this->to_csv_export_url( $sheet_url );
    }

    /**
     * Fetch and parse a Google Sheet by its share URL.
     * Returns an array of rows (each row is an associative array keyed by header),
     * or WP_Error on failure.
     *
     * @return array<int, array<string, string>>|WP_Error
     */
    public function fetch( string $sheet_url ) {
        return $this->fetch_public_csv( $sheet_url );
    }

    /**
     * Return cached sheet rows, fetching fresh data when the cache is cold.
     *
     * @param int $ttl Cache TTL in seconds (default 1 hour).
     * @return array<int, array<string, string>>|WP_Error
     */
    public function get_cached( string $sheet_url, int $ttl = 3600 ) {
        $cache_key = 'gt_gs_' . md5( $sheet_url );
        $cached    = get_transient( $cache_key );

        if ( $cached !== false ) {
            return $cached;
        }

        $data = $this->fetch( $sheet_url );

        if ( ! is_wp_error( $data ) ) {
            set_transient( $cache_key, $data, $ttl );
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function interval_to_seconds( string $interval ): int {
        return match ( $interval ) {
            '15min'      => 15 * MINUTE_IN_SECONDS,
            'twicedaily' => 12 * HOUR_IN_SECONDS,
            'daily'      => DAY_IN_SECONDS,
            default      => HOUR_IN_SECONDS,
        };
    }
}
