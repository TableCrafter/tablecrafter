<?php
/**
 * Write-back service for Gravity Tables external database connections.
 *
 * When a table is configured to use an external MySQL database (issue #86)
 * and write-back is enabled, frontend inline edits are persisted to the
 * external database via prepared UPDATE statements.
 *
 * Security model:
 *  - Write-back is disabled by default (opt-in per table).
 *  - Every write is gated by current_user_can() before touching the DB.
 *  - All user-supplied values go through $wpdb->prepare() - no interpolation.
 *  - Conflict detection compares the current DB value to the value at load
 *    time, surfacing a warning before overwriting a stale row.
 *  - Action hooks let developers audit or reject writes.
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Writeback_Service {

    /**
     * Return true when write-back is enabled for a table.
     *
     * Defaults to false so existing tables are never affected without
     * an explicit opt-in by the administrator.
     *
     * @param array $settings Table settings array.
     * @return bool
     */
    public static function is_enabled( array $settings ): bool {
        return ! empty( $settings['writeback_enabled'] );
    }

    /**
     * Verify that the current user is allowed to trigger a write-back.
     *
     * The minimum capability is read from the table settings
     * (key: writeback_capability) and falls back to 'edit_posts'.
     *
     * @param array $settings Table settings array.
     * @return true|\WP_Error  true on success, WP_Error when the check fails.
     */
    public static function can_writeback( array $settings ): bool|\WP_Error {
        if ( ! self::is_enabled( $settings ) ) {
            return new \WP_Error(
                'writeback_disabled',
                __( 'Write-back is not enabled for this table.', 'tc-data-tables' )
            );
        }

        $capability = sanitize_key( $settings['writeback_capability'] ?? 'edit_posts' );
        if ( empty( $capability ) ) {
            $capability = 'edit_posts';
        }

        if ( ! current_user_can( $capability ) ) {
            return new \WP_Error(
                'writeback_forbidden',
                __( 'You do not have permission to edit this table.', 'tc-data-tables' )
            );
        }

        return true;
    }

    /**
     * Persist a single cell edit to the external database.
     *
     * Fires gravity_tables_writeback_before before the UPDATE.
     * If any hooked callback returns false the write is aborted.
     * Fires gravity_tables_writeback_after on success.
     *
     * @param wpdb   $db                External database connection (a \wpdb instance
     *                                  initialised with the external credentials).
     * @param string $table_name        External table name (already validated by the caller).
     * @param string $primary_key_col   Column used in the WHERE clause.
     * @param mixed  $primary_key_value Value of the primary key for the target row.
     * @param string $field_col         Column to update.
     * @param mixed  $new_value         New value (sanitised by the caller; passed via prepare()).
     * @return true|\WP_Error
     */
    public static function update_row(
        \wpdb $db,
        string $table_name,
        string $primary_key_col,
        $primary_key_value,
        string $field_col,
        $new_value
    ): bool|\WP_Error {
        $context = compact( 'table_name', 'primary_key_col', 'primary_key_value', 'field_col', 'new_value' );

        // Allow third-party code to audit or reject the write.
        $allowed = apply_filters( 'gravity_tables_writeback_before', true, $context );
        if ( $allowed === false ) {
            return new \WP_Error(
                'writeback_rejected',
                __( 'Write-back was rejected by a filter.', 'tc-data-tables' )
            );
        }

        // Escape identifiers manually (wpdb::prepare only handles values).
        $safe_table = '`' . str_replace( '`', '``', $table_name ) . '`';
        $safe_field = '`' . str_replace( '`', '``', $field_col ) . '`';
        $safe_pk    = '`' . str_replace( '`', '``', $primary_key_col ) . '`';

        // All user-supplied *values* go through prepare() - no interpolation.
        $sql    = $db->prepare(
            "UPDATE {$safe_table} SET {$safe_field} = %s WHERE {$safe_pk} = %s",
            $new_value,
            $primary_key_value
        );
        $result = $db->query( $sql );

        if ( $result === false ) {
            return new \WP_Error(
                'writeback_db_error',
                sprintf(
                    /* translators: %s: database error message */
                    __( 'External database update failed: %s', 'tc-data-tables' ),
                    $db->last_error
                )
            );
        }

        do_action( 'gravity_tables_writeback_after', $context );

        return true;
    }

    /**
     * Detect whether the external row has changed since the table was last loaded.
     *
     * Fetches the current value of $field_col for the target row and compares
     * it to $expected_value. Returns true when a conflict is detected (the
     * external DB row differs from the value the user saw at load time).
     *
     * @param wpdb   $db
     * @param string $table_name
     * @param string $primary_key_col
     * @param mixed  $primary_key_value
     * @param string $field_col
     * @param mixed  $expected_value     Value the user saw when the table was rendered.
     * @return bool  true = conflict exists; false = safe to overwrite.
     */
    public static function detect_conflict(
        \wpdb $db,
        string $table_name,
        string $primary_key_col,
        $primary_key_value,
        string $field_col,
        $expected_value
    ): bool {
        $safe_table = '`' . str_replace( '`', '``', $table_name ) . '`';
        $safe_field = '`' . str_replace( '`', '``', $field_col ) . '`';
        $safe_pk    = '`' . str_replace( '`', '``', $primary_key_col ) . '`';

        $sql     = $db->prepare(
            "SELECT {$safe_field} FROM {$safe_table} WHERE {$safe_pk} = %s LIMIT 1",
            $primary_key_value
        );
        $current = $db->get_var( $sql );

        // Treat a missing row as a conflict (row was deleted externally).
        if ( $current === null ) {
            return true;
        }

        return (string) $current !== (string) $expected_value;
    }
}
