<?php
/**
 * Embeddable public tables (#2133).
 *
 * Built-in virality (Typeform / Calendly model): any table can be embedded on
 * an external site via an <iframe>, and the free embed carries a subtle
 * "Made with TableCrafter" attribution link — every embed is an ad. Pro removes
 * the attribution.
 *
 * This class holds the pure pieces (iframe snippet, attribution markup, request
 * detection). TC_Embed_Renderer (wired on template_redirect in the plugin)
 * emits the bare public page for `?tc_embed=<id>` requests.
 *
 * @since 8.0.16
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// @codeCoverageIgnoreEnd

class TC_Embed {

	/** Query var that flags an embed view and carries the table id. */
	const QUERY_VAR = 'tc_embed';

	/** Marketing URL the attribution links to. */
	const ATTRIBUTION_URL = 'https://tablecrafter.com';

	/**
	 * Build the <iframe> embed snippet for a table.
	 *
	 * @param mixed  $table_id Table id (cast to int — no raw passthrough).
	 * @param string $site_url Site base URL (defaults to home_url()).
	 */
	public static function embed_code( $table_id, string $site_url = '' ): string {
		$id  = (int) $table_id;
		$base = $site_url !== '' ? $site_url : ( function_exists( 'home_url' ) ? home_url( '/' ) : '/' );
		$src  = add_query_arg_simple( $base, self::QUERY_VAR, (string) $id );

		return '<iframe src="' . esc_url( $src ) . '"'
			. ' width="100%" height="600" frameborder="0" loading="lazy"'
			. ' style="border:1px solid #e5e7eb;border-radius:8px;"'
			. ' title="TableCrafter table"></iframe>';
	}

	/**
	 * Attribution markup. Shown on Free; empty string on Pro (removable).
	 */
	public static function attribution_html( bool $is_pro ): string {
		if ( $is_pro ) {
			return '';
		}
		return '<div class="gt-embed-attribution" style="text-align:center;font-size:12px;padding:6px 0;color:#6b7280;">'
			. esc_html__( 'Made with', 'tc-data-tables' ) . ' '
			. '<a href="' . esc_url( self::ATTRIBUTION_URL ) . '" target="_blank" rel="noopener">TableCrafter</a>'
			. '</div>';
	}

	/**
	 * Resolve the embed table id from a request query (e.g. $_GET).
	 *
	 * @return int Table id, or 0 when the request is not an embed view.
	 */
	public static function embed_request_id( array $query ): int {
		if ( ! isset( $query[ self::QUERY_VAR ] ) ) {
			return 0;
		}
		$raw = $query[ self::QUERY_VAR ];
		// Only an all-digits value is a valid id; "abc" / "5xx" are rejected.
		if ( ! is_string( $raw ) && ! is_int( $raw ) ) {
			return 0;
		}
		$raw = (string) $raw;
		return ctype_digit( $raw ) ? (int) $raw : 0;
	}
}

// @codeCoverageIgnoreStart -- declaration-guard wrapper; file is loaded by the bootstrap before coverage instrumentation starts.
if ( ! function_exists( 'tc_embed_template_redirect' ) ) {
// @codeCoverageIgnoreEnd
	/**
	 * template_redirect handler — render the bare public embed page for
	 * `?tc_embed=<id>` and exit. Calls wp_head()/wp_footer() so the table's
	 * registered styles/scripts load, then appends the (Free-only) attribution.
	 *
	 * @codeCoverageIgnore Integration path (needs full WP); pure logic is tested.
	 */
	function tc_embed_template_redirect() {
		$id = TC_Embed::embed_request_id( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id <= 0 ) {
			return;
		}

		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		$is_pro = function_exists( 'gt_is_premium' ) && gt_is_premium();

		header( 'Content-Type: text/html; charset=utf-8' );
		// X-Frame-Options is intentionally NOT set here: this view is meant to be
		// iframed cross-origin. The page only renders a read-only table.
		echo '<!doctype html><html ' . ( function_exists( 'get_language_attributes' ) ? get_language_attributes() : '' ) . '><head>';
		echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
		echo '<title>' . esc_html__( 'TableCrafter table', 'tc-data-tables' ) . '</title>';
		if ( function_exists( 'wp_head' ) ) {
			wp_head(); // fires wp_enqueue_scripts → registers the table's styles
		}
		echo '</head><body class="tc-embed-body" style="margin:0;padding:12px;background:#fff;">';
		echo do_shortcode( '[tablecrafter id="' . $id . '"]' );
		echo TC_Embed::attribution_html( $is_pro );
		if ( function_exists( 'wp_footer' ) ) {
			wp_footer(); // prints footer-enqueued scripts (gt-external-interactive.js)
		}
		echo '</body></html>';
		exit;
	}
}

// @codeCoverageIgnoreStart -- declaration-guard wrapper; file is loaded by the bootstrap before coverage instrumentation starts.
if ( ! function_exists( 'add_query_arg_simple' ) ) {
// @codeCoverageIgnoreEnd
	/**
	 * Minimal query-arg appender so embed_code() works before WP loads (and in
	 * the standalone tests). Mirrors add_query_arg() for the single-arg case.
	 */
	function add_query_arg_simple( string $url, string $key, string $value ): string {
		$sep = ( strpos( $url, '?' ) !== false ) ? '&' : '?';
		return $url . $sep . rawurlencode( $key ) . '=' . rawurlencode( $value );
	}
}
