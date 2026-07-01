<?php
/**
 * Cloud storage live data source: OneDrive / SharePoint / Google Drive.
 *
 * Fetches a remote CSV or XLSX file, caches the result via WordPress transients,
 * falls back to a stale copy when the remote is unreachable, and optionally
 * schedules a WP-Cron job for periodic auto-refresh.
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Cloud_Storage {

    private static $instance = null;

    const CRON_HOOK      = 'gt_cloud_storage_refresh';
    const CACHE_PREFIX   = 'gt_cloud_';
    const STALE_PREFIX   = 'gt_cloud_stale_';
    const FETCH_TIMEOUT  = 30;

    /** Recognised refresh intervals mapped to WP-Cron schedule names. */
    const INTERVALS = [
        '15min'  => 'gt_15min',
        'hourly' => 'hourly',
        'twicedaily' => 'twicedaily',
        'daily'  => 'daily',
        'manual' => '',
    ];

    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( self::CRON_HOOK, [ $this, 'handle_cron_refresh' ], 10, 2 );
        add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return true if $url is a supported cloud storage share link.
     */
    public function is_supported_url( string $url ): bool {
        $patterns = [
            'onedrive.live.com',
            '1drv.ms',
            'sharepoint.com',
            'drive.google.com',
            'docs.google.com',
        ];
        foreach ( $patterns as $pattern ) {
            if ( strpos( $url, $pattern ) !== false ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fetch the raw content of a remote file URL.
     *
     * @return string|false File contents or false on failure.
     */
    public function fetch_remote_file( string $url ) {
        $direct_url = $this->resolve_download_url( $url );

        // #1638 — validate the RESOLVED url against the shared SSRF gate
        // (every other outbound fetcher in the plugin does this). Blocks
        // loopback / RFC1918 / link-local / cloud-metadata targets and
        // non-http(s) schemes. resolve_download_url() can rewrite the host,
        // so validate the final url, not the input.
        if ( function_exists( 'gt_validate_outbound_url' ) && ! gt_validate_outbound_url( $direct_url ) ) {
            error_log( 'TC_Cloud_Storage: blocked outbound URL (SSRF guard): ' . $direct_url );
            return false;
        }

        $response   = wp_remote_get( $direct_url, [
            'timeout'    => self::FETCH_TIMEOUT,
            'user-agent' => 'Gravity-Tables/' . TC_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'TC_Cloud_Storage fetch error: ' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            error_log( "TC_Cloud_Storage: unexpected HTTP {$code} for {$direct_url}" );
            return false;
        }

        return wp_remote_retrieve_body( $response );
    }

    /**
     * Return cached data for a table or false when no live cache exists.
     *
     * @param int $table_id
     * @return string|false
     */
    public function get_cached_data( int $table_id ) {
        return get_transient( self::CACHE_PREFIX . $table_id );
    }

    /**
     * Fetch fresh data for a table, cache it, update the stale fallback, and
     * return the raw file contents.
     *
     * @param int   $table_id
     * @param array $settings  Table settings; expects 'cloud_source_url' and optionally 'cloud_refresh_interval'.
     * @return string|false
     */
    public function refresh_now( int $table_id, array $settings ) {
        $url = $settings['cloud_source_url'] ?? '';
        if ( empty( $url ) ) {
            return false;
        }

        $data = $this->fetch_remote_file( $url );

        if ( $data === false ) {
            // Remote unreachable — surface an admin notice and return stale data.
            set_transient( 'gt_cloud_fetch_error_' . $table_id, true, HOUR_IN_SECONDS );
            return $this->get_stale_data( $table_id );
        }

        $ttl = $this->interval_to_seconds( $settings['cloud_refresh_interval'] ?? 'hourly' );
        set_transient( self::CACHE_PREFIX . $table_id, $data, $ttl );

        // Update persistent stale copy (no TTL — survives even after primary transient expires).
        update_option( self::STALE_PREFIX . $table_id, $data, false );

        return $data;
    }

    /**
     * Schedule (or reschedule) a WP-Cron auto-refresh for the given table.
     *
     * @param int    $table_id
     * @param string $interval  One of the keys from self::INTERVALS.
     */
    public function schedule_refresh( int $table_id, string $interval ): void {
        if ( $interval === 'manual' || empty( $interval ) ) {
            $this->unschedule_refresh( $table_id );
            return;
        }

        $schedule = self::INTERVALS[ $interval ] ?? 'hourly';
        $args     = [ $table_id ];

        if ( ! wp_next_scheduled( self::CRON_HOOK, $args ) ) {
            wp_schedule_event( time(), $schedule, self::CRON_HOOK, $args );
        }
    }

    /**
     * Remove any scheduled cron refresh for a table.
     */
    public function unschedule_refresh( int $table_id ): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK, [ $table_id ] );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK, [ $table_id ] );
        }
    }

    // -------------------------------------------------------------------------
    // WP-Cron handler
    // -------------------------------------------------------------------------

    public function handle_cron_refresh( int $table_id ): void {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT settings FROM {$wpdb->prefix}gravity_tables WHERE id = %d AND status = 'active'",
                $table_id
            )
        );

        if ( ! $row ) {
            $this->unschedule_refresh( $table_id );
            return;
        }

        $settings = json_decode( $row->settings, true ) ?: [];
        $this->refresh_now( $table_id, $settings );
    }

    // -------------------------------------------------------------------------
    // Cron schedules
    // -------------------------------------------------------------------------

    public function add_cron_schedules( array $schedules ): array {
        if ( ! isset( $schedules['gt_15min'] ) ) {
            $schedules['gt_15min'] = [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 15 Minutes', 'tc-data-tables' ),
            ];
        }
        return $schedules;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Convert a OneDrive share link or Google Drive share URL to a direct download URL.
     */
    private function resolve_download_url( string $url ): string {
        // Google Drive: convert share links to direct CSV export URLs.
        if ( preg_match( '#drive\.google\.com/file/d/([^/]+)#', $url, $m ) ) {
            return 'https://drive.google.com/uc?export=download&id=' . $m[1];
        }

        // Google Sheets published-to-web CSV URL — pass through as-is.
        if ( strpos( $url, 'docs.google.com/spreadsheets' ) !== false ) {
            return $url;
        }

        // OneDrive/SharePoint: request the raw download by appending download=1 or &download=1.
        if ( strpos( $url, 'onedrive.live.com' ) !== false ||
             strpos( $url, '1drv.ms' ) !== false ||
             strpos( $url, 'sharepoint.com' ) !== false ) {
            $separator = strpos( $url, '?' ) !== false ? '&' : '?';
            return $url . $separator . 'download=1';
        }

        return $url;
    }

    /**
     * Return the stale fallback data stored in a long-lived option.
     *
     * @param int $table_id
     * @return string|false
     */
    private function get_stale_data( int $table_id ) {
        return get_option( self::STALE_PREFIX . $table_id, false );
    }

    // -------------------------------------------------------------------------
    // OAuth / authentication helpers (Microsoft OneDrive, #396)
    // -------------------------------------------------------------------------

    /**
     * Authenticate with a cloud storage provider using stored OAuth credentials.
     *
     * For OneDrive/SharePoint, credentials should include:
     *   [ 'provider' => 'onedrive', 'client_id' => '...', 'tenant_id' => '...' ]
     *
     * Returns a bearer token string on success, or WP_Error on failure.
     *
     * @param array $credentials Provider-specific credentials array.
     * @return string|WP_Error   Bearer token or error.
     */
    public function authenticate( array $credentials ) {
        $provider = strtolower( $credentials['provider'] ?? '' );

        if ( $provider === 'onedrive' || $provider === 'sharepoint' || $provider === 'microsoft' ) {
            // Retrieve a stored token; routes through get_token() so the
            // encryption-at-rest envelope (#1076 finding #1) is decrypted
            // and legacy plaintext is auto-migrated on the next save.
            $token = $this->get_token( 'onedrive', (string) ( $credentials['client_id'] ?? '' ) );
            if ( $token !== '' ) {
                return $token;
            }
            return new \WP_Error(
                'gt_oauth_required',
                __( 'OneDrive authentication required. Please re-authorise the connection in plugin settings.', 'tc-data-tables' )
            );
        }

        // Google Drive: tokens are stored during the OAuth callback flow.
        if ( $provider === 'google' || $provider === 'googledrive' ) {
            // Legacy: 'gt_google_access_token' (no client_id keying). Read raw
            // and route through gt_decrypt_secret so the legacy plaintext
            // path keeps working; encrypted values after #1076 also decrypt.
            $stored = get_option( 'gt_google_access_token', '' );
            $token = function_exists( 'gt_decrypt_secret' ) && is_string( $stored ) && $stored !== ''
                ? gt_decrypt_secret( $stored, 'gt_cloud_tokens' )
                : (string) $stored;
            if ( $token !== '' ) {
                return $token;
            }
            return new \WP_Error(
                'gt_oauth_required',
                __( 'Google Drive authentication required. Please re-authorise the connection in plugin settings.', 'tc-data-tables' )
            );
        }

        return new \WP_Error( 'gt_unknown_provider', sprintf(
            /* translators: %s: provider name */
            __( 'Unknown cloud storage provider: %s', 'tc-data-tables' ),
            esc_html( $provider )
        ) );
    }

    /**
     * Store a refreshed OAuth access token for later use.
     *
     * Tokens are encrypted at rest via gt_encrypt_secret() (#1076 finding #1).
     * The shared helper uses AES-256-CBC keyed off AUTH_KEY + a service-
     * specific salt, mirroring the gold-standard TC_Airtable_Credential_Service
     * pattern. The autoload=false flag is preserved so the encrypted blob
     * doesn't ship on every page load.
     *
     * Backward-compat: existing customer installations (pre-v5.2.2) have
     * plaintext tokens already written to wp_options. Those keep working
     * via get_token()'s auto-migration path until the OAuth refresh fires
     * and re-saves through this method.
     *
     * @param string $provider  Provider key ('onedrive', 'google', etc.).
     * @param string $client_id OAuth client ID (used to key the token).
     * @param string $token     Bearer token string (plaintext).
     * @param int    $expires   Unix timestamp when the token expires (0 = no expiry).
     */
    public function store_token( string $provider, string $client_id, string $token, int $expires = 0 ): void {
        $option_key = self::token_option_key( $provider, $client_id );
        $envelope   = function_exists( 'gt_encrypt_secret' )
            ? gt_encrypt_secret( $token, 'gt_cloud_tokens' )
            // @codeCoverageIgnoreStart
            : $token; // openssl unavailable — degrade to plaintext rather than fail-closed silently
            // @codeCoverageIgnoreEnd
        update_option( $option_key, $envelope, false );
        if ( $expires > 0 ) {
            update_option( $option_key . '_expires', $expires, false );
        }
    }

    /**
     * Retrieve a previously-stored OAuth access token in plaintext.
     *
     * Handles both the post-#1076 encrypted envelope (decrypt path) and
     * the pre-#1076 legacy plaintext (passthrough — see gt_decrypt_secret()'s
     * sentinel detection). Returns '' when no token is stored or when an
     * encrypted envelope fails to decrypt (fail-closed).
     */
    public function get_token( string $provider, string $client_id ): string {
        $option_key = self::token_option_key( $provider, $client_id );
        $stored = get_option( $option_key, '' );
        if ( ! is_string( $stored ) || $stored === '' ) {
            return '';
        }
        return function_exists( 'gt_decrypt_secret' )
            ? gt_decrypt_secret( $stored, 'gt_cloud_tokens' )
            : $stored;
    }

    /**
     * Canonical option key for a cloud-storage OAuth token.
     */
    private static function token_option_key( string $provider, string $client_id ): string {
        return 'gt_' . $provider . '_access_token_' . md5( $client_id );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Convert an interval key to a TTL in seconds.
     */
    private function interval_to_seconds( string $interval ): int {
        $map = [
            '15min'     => 15 * MINUTE_IN_SECONDS,
            'hourly'    => HOUR_IN_SECONDS,
            'twicedaily'=> 12 * HOUR_IN_SECONDS,
            'daily'     => DAY_IN_SECONDS,
        ];
        return $map[ $interval ] ?? HOUR_IN_SECONDS;
    }
}
