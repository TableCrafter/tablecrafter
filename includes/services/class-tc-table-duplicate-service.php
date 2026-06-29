<?php
/**
 * #1740 — One-Click Table Duplicate.
 *
 * Pure static helper: prepares the data array for a duplicated table row.
 * The AJAX handler (class-tc-ajax.php) calls these methods and handles
 * the $wpdb->insert + redirect response.
 */
class TC_Table_Duplicate_Service {

    /**
     * Prepare the insert-data array for a cloned table.
     *
     * @param object $source_row  Row from wp_gravity_tables.
     * @return array  Ready for $wpdb->insert (title, form_id, settings).
     */
    public static function prepare_copy( object $source_row ): array {
        $title    = trim( (string) ( $source_row->title ?? '' ) );
        $form_id  = (int) ( $source_row->form_id ?? 0 );
        $raw      = (string) ( $source_row->settings ?? '' );
        $settings = json_decode( $raw, true );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }

        return [
            'title'    => $title . ' (Copy)',
            'form_id'  => $form_id,
            'settings' => wp_json_encode( $settings ),
        ];
    }

    /**
     * Gate check: is the current user allowed to duplicate tables?
     *
     * @param bool $has_manage_options  Result of current_user_can('manage_options').
     * @return bool
     */
    public static function is_allowed_to_duplicate( bool $has_manage_options ): bool {
        return $has_manage_options;
    }
}
