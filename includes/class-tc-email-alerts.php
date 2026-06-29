<?php
/**
 * TC_Email_Alerts — value-threshold notification emails + scheduled exports (Pro).
 *
 * Rules are stored in table settings as:
 *   email_alert_rules: [
 *     { field_id, operator, threshold, recipient, attach_csv }
 *   ]
 *
 * Scheduled exports are stored in table settings as:
 *   scheduled_exports: [
 *     { recipient, recurrence, table_id }
 *   ]
 * where recurrence is 'daily' | 'weekly' | 'monthly'.
 *
 * Supported operators: >  <  =  >=  <=  contains
 *
 * @package GravityTables
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TC_Email_Alerts {

    const ALLOWED_OPERATORS = [ '>', '<', '=', '>=', '<=', 'contains' ];
    const ALLOWED_RECURRENCES = [ 'daily', 'weekly', 'monthly' ];

    /**
     * Sanitize an array of raw alert rules.
     *
     * @param  array $raw  Unsanitized rules from POST data.
     * @return array       Valid rules only.
     */
    public static function sanitize_rules( array $raw ): array {
        $clean = [];
        foreach ( $raw as $rule ) {
            $field_id   = sanitize_text_field( $rule['field_id']   ?? '' );
            $operator   = sanitize_text_field( $rule['operator']    ?? '' );
            $threshold  = sanitize_text_field( $rule['threshold']   ?? '' );
            $recipient  = sanitize_email(      $rule['recipient']   ?? '' );
            $attach_csv = ! empty( $rule['attach_csv'] );

            if ( '' === $field_id )                                           { continue; }
            if ( '' === $threshold )                                           { continue; }
            if ( ! in_array( $operator, self::ALLOWED_OPERATORS, true ) )     { continue; }
            if ( ! is_email( $recipient ) )                                    { continue; }

            $clean[] = compact( 'field_id', 'operator', 'threshold', 'recipient', 'attach_csv' );
        }
        return $clean;
    }

    /**
     * Evaluate whether a single value satisfies an operator + threshold pair.
     *
     * @param  string $value      The current field value.
     * @param  string $operator   One of ALLOWED_OPERATORS.
     * @param  string $threshold  The configured comparison value.
     * @return bool
     */
    public static function evaluate_rule( string $value, string $operator, string $threshold ): bool {
        if ( 'contains' === $operator ) {
            return str_contains( $value, $threshold );
        }

        // For numeric operators cast both sides; fall back to string comparison.
        $v = is_numeric( $value )     ? (float) $value     : $value;
        $t = is_numeric( $threshold ) ? (float) $threshold : $threshold;

        switch ( $operator ) {
            case '>':  return $v >  $t;
            case '<':  return $v <  $t;
            case '=':  return $v == $t;
            case '>=': return $v >= $t;
            case '<=': return $v <= $t;
        }
        return false;
    }

    /**
     * Check rules for a single field change and send alerts where warranted.
     *
     * Only fires when:
     *  - The new value satisfies the rule (threshold crossed).
     *  - The old value did NOT already satisfy it (avoids re-firing on unchanged crosses).
     *  - The value actually changed.
     *
     * @param  array  $rules       Sanitized rules array from table settings.
     * @param  string $field_id    The changed field's ID.
     * @param  string $old_value   Value before the write.
     * @param  string $new_value   Value after the write.
     * @param  int    $table_id    Table record ID (for the email subject/body).
     * @param  string $field_label Human-readable field label.
     */
    public static function fire_alerts( array $rules, string $field_id, string $old_value, string $new_value, int $table_id, string $field_label ): void {
        if ( ! gt_is_premium() ) {
            return;
        }

        if ( $old_value === $new_value ) {
            return;
        }

        $table_title = '';
        $post        = get_post( $table_id );
        if ( $post ) {
            $table_title = $post->post_title;
        }

        foreach ( $rules as $rule ) {
            if ( $rule['field_id'] !== $field_id ) {
                continue;
            }

            $new_passes = self::evaluate_rule( $new_value, $rule['operator'], $rule['threshold'] );
            $old_passes = self::evaluate_rule( $old_value, $rule['operator'], $rule['threshold'] );

            // Only alert on the crossing moment (old did not pass, new does).
            if ( ! $new_passes || $old_passes ) {
                continue;
            }

            $attach_csv = ! empty( $rule['attach_csv'] );
            self::send_alert( $rule['recipient'], $field_label, $old_value, $new_value, $rule['operator'], $rule['threshold'], $table_title, $table_id, $attach_csv );
        }
    }

    /**
     * Build a temporary CSV file from table rows and return its path.
     * Caller is responsible for deleting the file after wp_mail() is called.
     *
     * @param  int   $table_id  Table record ID.
     * @return string|false     Absolute path to the temp CSV, or false on failure.
     */
    public static function build_csv_attachment( int $table_id ) {
        if ( ! class_exists( 'TC_Ajax' ) ) {
            return false;
        }

        // Fetch rows via the same path as the frontend AJAX export.
        $rows = apply_filters( 'gt_export_rows', [], $table_id, [] );

        if ( empty( $rows ) || ! is_array( $rows ) ) {
            return false;
        }

        $tmp_path = get_temp_dir() . 'gt-export-' . $table_id . '-' . wp_generate_password( 8, false ) . '.csv';

        $fh = @fopen( $tmp_path, 'w' );
        if ( ! $fh ) {
            return false;
        }

        // Header row from first entry keys.
        fputcsv( $fh, array_keys( (array) $rows[0] ) );
        foreach ( $rows as $row ) {
            fputcsv( $fh, array_values( (array) $row ) );
        }
        fclose( $fh );

        return $tmp_path;
    }

    /**
     * Send a single threshold-crossed email, optionally with a CSV attachment.
     *
     * @param string $to           Recipient email address.
     * @param string $field_label  Human-readable field label.
     * @param string $old_value    Value before the write.
     * @param string $new_value    Value after the write.
     * @param string $operator     Comparison operator.
     * @param string $threshold    Configured threshold value.
     * @param string $table_title  Table display name.
     * @param int    $table_id     Table record ID.
     * @param bool   $attach_csv   Whether to attach a CSV export of the table.
     */
    private static function send_alert( string $to, string $field_label, string $old_value, string $new_value, string $operator, string $threshold, string $table_title, int $table_id, bool $attach_csv = false ): void {
        $subject = sprintf(
            '[TableCrafter Alert] Field "%s" crossed threshold on table "%s"',
            $field_label,
            $table_title
        );

        $edit_url = admin_url( 'admin.php?page=gravity-tables&action=edit&table_id=' . $table_id );

        $message = sprintf(
            "A value threshold has been crossed.\n\nTable: %s\nField: %s\n\nOld value: %s\nNew value: %s\nCondition: %s %s\n\nEdit table: %s",
            $table_title,
            $field_label,
            $old_value,
            $new_value,
            $operator,
            $threshold,
            $edit_url
        );

        $attachments = [];
        $tmp_file    = null;

        if ( $attach_csv ) {
            $tmp_file = self::build_csv_attachment( $table_id );
            if ( $tmp_file ) {
                $attachments[] = $tmp_file;
            }
        }

        wp_mail( $to, $subject, $message, [], $attachments );

        // Clean up the temp file after mail is dispatched.
        if ( $tmp_file && file_exists( $tmp_file ) ) {
            @unlink( $tmp_file );
        }
    }

    // ── Scheduled export delivery (#1917) ─────────────────────────────────────

    /**
     * Register the WP cron hook and custom schedule on plugin init.
     * Called from the main plugin bootstrap.
     */
    public static function register_scheduled_export_hooks(): void {
        add_filter( 'cron_schedules', [ __CLASS__, 'add_monthly_schedule' ] );
        add_action( 'gt_scheduled_export', [ __CLASS__, 'send_scheduled_export' ], 10, 2 );
    }

    /**
     * Add a 'monthly' cron schedule (WP ships daily and weekly, not monthly).
     */
    public static function add_monthly_schedule( array $schedules ): array {
        if ( ! isset( $schedules['monthly'] ) ) {
            $schedules['monthly'] = [
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => __( 'Once a month', 'tc-data-tables' ),
            ];
        }
        return $schedules;
    }

    /**
     * Schedule a recurring export delivery for a table.
     *
     * @param int    $table_id    Table record ID.
     * @param string $recipient   Email address to deliver to.
     * @param string $recurrence  'daily' | 'weekly' | 'monthly'
     */
    public static function schedule_export( int $table_id, string $recipient, string $recurrence ): void {
        if ( ! gt_is_premium() ) {
            return;
        }
        if ( ! in_array( $recurrence, self::ALLOWED_RECURRENCES, true ) ) {
            return;
        }
        if ( ! is_email( $recipient ) ) {
            return;
        }

        $hook_args = [ $table_id, $recipient ];

        if ( ! wp_next_scheduled( 'gt_scheduled_export', $hook_args ) ) {
            wp_schedule_event( time(), $recurrence, 'gt_scheduled_export', $hook_args );
        }
    }

    /**
     * Cancel a previously scheduled export for a table + recipient pair.
     *
     * @param int    $table_id   Table record ID.
     * @param string $recipient  Email address.
     */
    public static function unschedule_export( int $table_id, string $recipient ): void {
        $hook_args = [ $table_id, $recipient ];
        $timestamp = wp_next_scheduled( 'gt_scheduled_export', $hook_args );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'gt_scheduled_export', $hook_args );
        }
    }

    /**
     * WP cron callback — build and send the scheduled CSV export.
     *
     * @param int    $table_id   Table record ID.
     * @param string $recipient  Recipient email address.
     */
    public static function send_scheduled_export( int $table_id, string $recipient ): void {
        if ( ! gt_is_premium() ) {
            return;
        }
        if ( ! is_email( $recipient ) ) {
            return;
        }

        $table_title = '';
        $post        = get_post( $table_id );
        if ( $post ) {
            $table_title = $post->post_title;
        }

        $subject = sprintf(
            '[TableCrafter Export] Scheduled export for table "%s"',
            $table_title
        );

        $message = sprintf(
            "Attached is your scheduled export of the TableCrafter table \"%s\".\n\nThis is an automated delivery. To change the schedule, visit the table settings in your WordPress admin.",
            $table_title
        );

        $attachments = [];
        $tmp_file    = self::build_csv_attachment( $table_id );
        if ( $tmp_file ) {
            $attachments[] = $tmp_file;
        }

        wp_mail( $recipient, $subject, $message, [], $attachments );

        if ( $tmp_file && file_exists( $tmp_file ) ) {
            @unlink( $tmp_file );
        }
    }
}
