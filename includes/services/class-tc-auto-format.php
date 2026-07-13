<?php
/**
 * Rich auto-formatting engine (#2132).
 *
 * Pure value→display logic: classify a raw scalar and render it as safe display
 * HTML. Used by the table renderer to deliver the "beautiful table" promise - 
 * ISO dates, large numbers, and URLs render formatted by default, with an
 * explicit per-column type override (incl. currency + status badge).
 *
 * Heuristics are deliberately conservative so real data isn't mangled:
 *  - numbers only get thousands separators when the integer part is >= 5 digits
 *    OR there's a fractional part (so 4-digit years / short IDs stay literal);
 *  - dates require an ISO-ish YYYY-MM-DD prefix;
 *  - URLs require an explicit http(s) scheme.
 *
 * @since 8.0.15
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// @codeCoverageIgnoreEnd

class TC_Auto_Format {

	/** Classify a raw scalar for display: 'url' | 'date' | 'number' | 'text'. */
	public static function detect_type( $value ): string {
		$v = trim( (string) $value );
		if ( $v === '' ) {
			return 'text';
		}
		if ( preg_match( '#^https?://\S+$#i', $v ) ) {
			return 'url';
		}
		if ( preg_match( '#^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?#', $v ) ) {
			return 'date';
		}
		if ( preg_match( '#^-?\d+(\.\d+)?$#', $v ) ) {
			return 'number';
		}
		return 'text';
	}

	/**
	 * Render a raw value as safe display HTML.
	 *
	 * @param mixed  $value Raw cell value.
	 * @param string $type  'auto' (detect) | 'url' | 'date' | 'number' | 'currency' | 'badge' | 'text'.
	 * @param array  $opts  Per-type options (date_format, symbol, decimals).
	 */
	public static function format_cell( $value, string $type = 'auto', array $opts = array() ): string {
		$raw = (string) $value;
		if ( $type === 'auto' ) {
			$type = self::detect_type( $raw );
		}

		switch ( $type ) {
			case 'url':
				return self::format_url( $raw );
			case 'date':
				return self::format_date( $raw, $opts );
			case 'number':
				return self::format_number( $raw );
			case 'currency':
				return self::format_currency( $raw, $opts );
			case 'badge':
				return self::format_badge( $raw );
			case 'text':
			default:
				return esc_html( $raw );
		}
	}

	private static function format_url( string $v ): string {
		$label = $v;
		// Shorten long URLs to host + truncated path for readability.
		if ( strlen( $v ) > 50 ) {
			$host  = (string) parse_url( $v, PHP_URL_HOST );
			$label = $host !== '' ? $host : substr( $v, 0, 50 ) . '…';
		}
		return '<a href="' . esc_url( $v ) . '" target="_blank" rel="noopener noreferrer nofollow">'
			. esc_html( $label ) . '</a>';
	}

	private static function format_date( string $v, array $opts ): string {
		$format = isset( $opts['date_format'] ) && $opts['date_format'] !== '' ? (string) $opts['date_format'] : 'M j, Y';
		$ts     = strtotime( $v );
		if ( $ts === false ) {
			return esc_html( $v );
		}
		// Prefer WP's localized formatter when available; fall back to date().
		if ( function_exists( 'date_i18n' ) ) {
			return esc_html( date_i18n( $format, $ts ) );
		}
		return esc_html( date( $format, $ts ) );
	}

	/** Thousands separators only when the number is genuinely large or fractional. */
	private static function format_number( string $v ): string {
		if ( ! preg_match( '#^-?\d+(\.\d+)?$#', trim( $v ) ) ) {
			return esc_html( $v );
		}
		$has_fraction = strpos( $v, '.' ) !== false;
		$int_part     = ltrim( explode( '.', ltrim( $v, '-' ) )[0], '0' );
		$int_digits   = strlen( $int_part );

		if ( ! $has_fraction && $int_digits < 5 ) {
			return esc_html( $v ); // leave years / short ids alone
		}

		$decimals = $has_fraction ? strlen( explode( '.', $v )[1] ) : 0;
		return esc_html( number_format( (float) $v, $decimals ) );
	}

	private static function format_currency( string $v, array $opts ): string {
		$symbol   = isset( $opts['symbol'] ) ? (string) $opts['symbol'] : '$';
		$decimals = isset( $opts['decimals'] ) ? (int) $opts['decimals'] : 2;
		if ( ! is_numeric( trim( $v ) ) ) {
			return esc_html( $v );
		}
		return esc_html( $symbol . number_format( (float) $v, $decimals ) );
	}

	private static function format_badge( string $v ): string {
		$slug = preg_replace( '#[^a-z0-9]+#', '-', strtolower( trim( $v ) ) );
		$slug = trim( (string) $slug, '-' );
		return '<span class="gt-badge gt-badge-' . esc_attr( $slug ) . '">' . esc_html( $v ) . '</span>';
	}
}
