<?php
/**
 * Resolves dynamic placeholder tokens in cell content and column defaults.
 *
 * Tokens are replaced server-side on every render so they always reflect the
 * current date/time/user rather than a stored snapshot.
 *
 * Single-brace tokens (legacy):
 *   {current_date} - site date (WP date_format option, WP timezone)
 *   {current_time} - site time (WP time_format option, WP timezone)
 *   {current_datetime} - date + time combined
 *   {current_user} - display name of the logged-in user (empty for guests)
 *   {site_name} - WordPress blogname option
 *   {site_url} - WordPress home URL
 *
 * Double-brace formula tokens (issue #316):
 *   {{TODAY}} - current date in site's date_format
 *   {{NOW}} - current date+time in site's date_format + time_format
 *   {{TODAY|d/m/Y}} - current date in a custom PHP date() format string
 *   {{NOW|H:i}} - current time in a custom PHP date() format string
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Placeholder_Service {

    /**
     * Replace all supported placeholder tokens in $text with their current values.
     *
     * @param string $text Input string potentially containing tokens.
     * @return string Text with all recognised tokens replaced.
     */
    public static function resolve( string $text ): string {
        if ( strpos( $text, '{' ) === false ) {
            return $text;
        }

        $tz          = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
        $now         = new DateTimeImmutable( 'now', $tz );
        $date_format = function_exists( 'get_option' ) ? get_option( 'date_format', 'Y-m-d' ) : 'Y-m-d';
        $time_format = function_exists( 'get_option' ) ? get_option( 'time_format', 'H:i' ) : 'H:i';

        // Resolve double-brace formula tokens first ({{TODAY|format}}, {{NOW|format}}).
        $text = self::resolve_formula_tokens( $text, $now, $date_format, $time_format );

        // Legacy single-brace tokens.
        $replacements = [
            '{current_date}'     => esc_html( $now->format( $date_format ) ),
            '{current_time}'     => esc_html( $now->format( $time_format ) ),
            '{current_datetime}' => esc_html( $now->format( $date_format . ' ' . $time_format ) ),
            '{current_user}'     => self::current_user_display_name(),
            '{site_name}'        => esc_html( function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : '' ),
            '{site_url}'         => esc_url( function_exists( 'home_url' ) ? home_url() : '' ),
        ];

        return str_replace( array_keys( $replacements ), array_values( $replacements ), $text );
    }

    /**
     * Resolve {{TODAY}}, {{NOW}}, {{TODAY|format}}, {{NOW|format}} tokens.
     *
     * Format strings follow PHP date() syntax.  An invalid format string is
     * treated as an unrecognised token (left as-is) and a notice is logged
     * via error_log when WP_DEBUG is active.
     */
    private static function resolve_formula_tokens(
        string $text,
        DateTimeImmutable $now,
        string $date_format,
        string $time_format
    ): string {
        return preg_replace_callback(
            '/\{\{(TODAY|NOW)(?:\|([^}]*))?\}\}/',
            static function ( array $m ) use ( $now, $date_format, $time_format ): string {
                $token      = $m[1];
                $custom_fmt = isset( $m[2] ) ? trim( $m[2] ) : '';

                if ( $custom_fmt !== '' ) {
                    $fmt = $custom_fmt;
                } elseif ( $token === 'TODAY' ) {
                    $fmt = $date_format;
                } else {
                    $fmt = $date_format . ' ' . $time_format;
                }

                $result = $now->format( $fmt );

                if ( $result === false || $result === '' ) {
                    // @codeCoverageIgnoreStart
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( "Gravity Tables: invalid date format string in token {{$token}|{$custom_fmt}}" );
                    // @codeCoverageIgnoreEnd
                    }
                    // @codeCoverageIgnoreStart
                    return "{{$token}" . ( $custom_fmt !== '' ? "|{$custom_fmt}" : '' ) . '}}';
                    // @codeCoverageIgnoreEnd
                }

                return esc_html( $result );
            },
            $text
        ) ?? $text;
    }

    private static function current_user_display_name(): string {
        // @codeCoverageIgnoreStart
        if ( ! function_exists( 'wp_get_current_user' ) ) {
            return '';
        }
        // @codeCoverageIgnoreEnd
        $user = wp_get_current_user();
        return esc_html( $user->exists() ? $user->display_name : '' );
    }
}
