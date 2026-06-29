<?php
/**
 * AI-first support system — phase 1: thread store + admin panel (#2159).
 *
 * Phase 1 is the data + admin foundation: a thread/message store with status and
 * role attribution, plus a Pro-gated admin panel for manual Q&A threads. The AI
 * answering and human-takeover layers (phases 2-3) build on this. See
 * docs/specs/2159-ai-support.md.
 *
 * The whole surface is gated behind gt_is_premium(); the admin panel also
 * requires manage_options.
 *
 * @since 8.0.19
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// @codeCoverageIgnoreEnd

class TC_Support {

	const STATUSES = array( 'open', 'awaiting_human', 'human', 'closed' );
	const ROLES    = array( 'user', 'ai', 'human' );

	// ---- Pure logic (unit-tested) ------------------------------------------

	public static function valid_status( $status ): bool {
		return in_array( (string) $status, self::STATUSES, true );
	}

	public static function valid_role( $role ): bool {
		return in_array( (string) $role, self::ROLES, true );
	}

	/** Human-readable attribution for a message author — AI vs human stays visible. */
	public static function attribution_label( string $role ): string {
		switch ( $role ) {
			case 'ai':
				return __( 'AI assistant', 'tc-data-tables' );
			case 'human':
				return __( 'Support agent', 'tc-data-tables' );
			case 'user':
			default:
				return __( 'Customer', 'tc-data-tables' );
		}
	}

	/**
	 * Normalize a message into a stored shape, or null if invalid.
	 *
	 * @return array{role:string,body:string}|null
	 */
	public static function normalize_message( string $role, string $body ): ?array {
		if ( ! self::valid_role( $role ) ) {
			return null;
		}
		$body = function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $body ) : trim( strip_tags( $body ) );
		if ( $body === '' ) {
			return null;
		}
		return array( 'role' => $role, 'body' => $body );
	}

	/** dbDelta-compatible schema for the two support tables. */
	public static function schema_sql( string $prefix ): string {
		$threads  = $prefix . 'tc_support_threads';
		$messages = $prefix . 'tc_support_messages';
		// Note: charset/collate appended by the caller via dbDelta.
		return "CREATE TABLE {$threads} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subject VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(32) NOT NULL DEFAULT 'open',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			assignee_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		);
		CREATE TABLE {$messages} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			thread_id BIGINT UNSIGNED NOT NULL,
			role VARCHAR(16) NOT NULL DEFAULT 'user',
			body LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY thread_id (thread_id)
		);";
	}

	// ---- DB layer (integration) --------------------------------------------
	// @codeCoverageIgnoreStart

	private static function threads_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tc_support_threads';
	}

	private static function messages_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tc_support_messages';
	}

	/** Create/upgrade the support tables (called from a migration on Pro). */
	public static function install_schema(): void {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$sql     = self::schema_sql( $wpdb->prefix );
		// dbDelta wants each CREATE TABLE terminated; append charset to each.
		$statements = array_filter( array_map( 'trim', explode( ';', $sql ) ) );
		foreach ( $statements as $stmt ) {
			dbDelta( $stmt . ' ' . $charset . ';' );
		}
	}

	/** Create a thread with an opening user message. Returns thread id or 0. */
	public static function create_thread( string $subject, int $user_id, string $body ): int {
		global $wpdb;
		$msg = self::normalize_message( 'user', $body );
		if ( $msg === null ) {
			return 0;
		}
		$now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( self::threads_table(), array(
			'subject'    => function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $subject ) : $subject,
			'status'     => 'open',
			'user_id'    => $user_id,
			'created_at' => $now,
			'updated_at' => $now,
		) );
		$thread_id = (int) $wpdb->insert_id;
		if ( $thread_id > 0 ) {
			self::add_message( $thread_id, 'user', $body );
		}
		return $thread_id;
	}

	/** Append a message to a thread. Returns message id or 0. */
	public static function add_message( int $thread_id, string $role, string $body ): int {
		global $wpdb;
		$msg = self::normalize_message( $role, $body );
		if ( $msg === null || $thread_id <= 0 ) {
			return 0;
		}
		$now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( self::messages_table(), array(
			'thread_id'  => $thread_id,
			'role'       => $msg['role'],
			'body'       => $msg['body'],
			'created_at' => $now,
		) );
		$wpdb->update( self::threads_table(), array( 'updated_at' => $now ), array( 'id' => $thread_id ) );
		return (int) $wpdb->insert_id;
	}

	public static function set_status( int $thread_id, string $status ): bool {
		global $wpdb;
		if ( ! self::valid_status( $status ) || $thread_id <= 0 ) {
			return false;
		}
		return (bool) $wpdb->update( self::threads_table(), array( 'status' => $status ), array( 'id' => $thread_id ) );
	}

	public static function list_threads( int $limit = 50 ): array {
		global $wpdb;
		$t = self::threads_table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY updated_at DESC LIMIT %d", $limit ) );
	}

	public static function get_messages( int $thread_id ): array {
		global $wpdb;
		$t = self::messages_table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE thread_id = %d ORDER BY created_at ASC", $thread_id ) );
	}

	// ---- Admin panel (Pro-gated) -------------------------------------------

	public static function init(): void {
		if ( ! function_exists( 'gt_is_premium' ) || ! gt_is_premium() ) {
			return; // Pro-only feature.
		}
		// Create the tables once per schema version (cheap option check).
		if ( function_exists( 'get_option' ) && (string) get_option( 'tc_support_schema_v', '' ) !== '1' ) {
			self::install_schema();
			if ( function_exists( 'update_option' ) ) {
				update_option( 'tc_support_schema_v', '1', false );
			}
		}
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 30 );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'gravity-tables',
			__( 'Support', 'tc-data-tables' ),
			__( 'Support', 'tc-data-tables' ),
			'manage_options',
			'tablecrafter-support',
			array( __CLASS__, 'render_panel' )
		);
	}

	public static function render_panel(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$threads = self::list_threads();
		echo '<div class="wrap"><h1>' . esc_html__( 'TableCrafter Support', 'tc-data-tables' ) . '</h1>';
		echo '<p>' . esc_html__( 'AI answers and human takeover land in the next phases. For now, manage support threads here.', 'tc-data-tables' ) . '</p>';
		echo '<table class="widefat"><thead><tr><th>' . esc_html__( 'Subject', 'tc-data-tables' ) . '</th><th>' . esc_html__( 'Status', 'tc-data-tables' ) . '</th><th>' . esc_html__( 'Updated', 'tc-data-tables' ) . '</th></tr></thead><tbody>';
		if ( empty( $threads ) ) {
			echo '<tr><td colspan="3">' . esc_html__( 'No support threads yet.', 'tc-data-tables' ) . '</td></tr>';
		} else {
			foreach ( $threads as $th ) {
				echo '<tr><td>' . esc_html( (string) ( $th->subject ?? '' ) ) . '</td><td>' . esc_html( (string) ( $th->status ?? '' ) ) . '</td><td>' . esc_html( (string) ( $th->updated_at ?? '' ) ) . '</td></tr>';
			}
		}
		echo '</tbody></table></div>';
	}
	// @codeCoverageIgnoreEnd
}
