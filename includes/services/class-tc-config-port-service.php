<?php
/**
 * Table configuration export and import service.
 *
 * Exports a table's column/settings/styling config as a versioned JSON file.
 * Entry data is never included. Imported configs always create a new table;
 * existing tables are never overwritten.
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Config_Port_Service {

    const EXPORT_VERSION = 1;

    // -------------------------------------------------------------------------
    // Export
    // -------------------------------------------------------------------------

    /**
     * Build an export payload array for a single table.
     *
     * @param int $table_id  Row ID in wp_gravity_tables.
     * @return array|WP_Error  Export array on success, WP_Error if table not found.
     */
    public static function export_table( int $table_id ) {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT title, form_id, settings FROM {$wpdb->prefix}gravity_tables WHERE id = %d AND status = 'active'",
                $table_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return new WP_Error( 'gt_export_not_found', __( 'Table not found.', 'tc-data-tables' ) );
        }

        $config = json_decode( $row['settings'], true ) ?: [];

        return [
            'version'        => self::EXPORT_VERSION,
            'plugin_version' => TC_VERSION,
            'exported_at'    => gmdate( 'c' ),
            'config'         => [
                'title'   => $row['title'],
                'form_id' => (int) $row['form_id'],
                'settings'=> $config,
            ],
        ];
    }

    /**
     * Export a table configuration as a JSON string.
     *
     * @param int $table_id
     * @return string|WP_Error
     */
    public static function export_to_json( int $table_id ) {
        $payload = self::export_table( $table_id );
        if ( is_wp_error( $payload ) ) {
            return $payload;
        }
        return wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
    }

    // -------------------------------------------------------------------------
    // Import
    // -------------------------------------------------------------------------

    /**
     * Parse JSON, validate the schema, create a new table, and return the new ID.
     *
     * @param string $json      Raw JSON string from an exported file.
     * @param array  $form_map  [ source_form_id => target_form_id ] for cross-site remapping.
     * @return int|WP_Error  New table ID on success, WP_Error on failure.
     */
    public static function import_from_json( string $json, array $form_map = [] ) {
        $data = json_decode( $json, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error(
                'gt_import_invalid_json',
                __( 'The import file contains invalid JSON.', 'tc-data-tables' )
            );
        }

        $validation = self::validate_import( $data );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        $config  = $data['config'];
        $form_id = (int) $config['form_id'];

        // Remap form ID if a mapping was provided.
        if ( isset( $form_map[ $form_id ] ) ) {
            $form_id = (int) $form_map[ $form_id ];
        }

        $title    = sanitize_text_field( $config['title'] ?? __( 'Imported Table', 'tc-data-tables' ) );
        $settings = $config['settings'] ?? [];

        // Generate a shortcode slug from the title.
        $shortcode = '[tablecrafter id="__ID__"]';

        global $wpdb;

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'gravity_tables',
            [
                'title'     => $title,
                'form_id'   => $form_id,
                'settings'  => wp_json_encode( $settings ),
                'shortcode' => $shortcode,
                'status'    => 'active',
            ],
            [ '%s', '%d', '%s', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return new WP_Error(
                'gt_import_db_error',
                __( 'Database error while creating the imported table.', 'tc-data-tables' )
            );
        }

        $new_id = (int) $wpdb->insert_id;

        // Update shortcode to include the real ID.
        $wpdb->update(
            $wpdb->prefix . 'gravity_tables',
            [ 'shortcode' => '[tablecrafter id="' . $new_id . '"]' ],
            [ 'id' => $new_id ],
            [ '%s' ],
            [ '%d' ]
        );

        return $new_id;
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /**
     * Validate a decoded import payload.
     *
     * @param mixed $data  Decoded JSON (should be an array).
     * @return true|WP_Error
     */
    public static function validate_import( $data ) {
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'gt_import_not_array', __( 'Import data must be a JSON object.', 'tc-data-tables' ) );
        }

        if ( ! isset( $data['version'] ) ) {
            return new WP_Error( 'gt_import_missing_version', __( 'Import file is missing the version field.', 'tc-data-tables' ) );
        }

        if ( (int) $data['version'] > self::EXPORT_VERSION ) {
            return new WP_Error(
                'gt_import_version_too_new',
                sprintf(
                    __( 'Import file requires export version %d but this plugin only supports up to version %d. Please update the plugin.', 'tc-data-tables' ),
                    (int) $data['version'],
                    self::EXPORT_VERSION
                )
            );
        }

        if ( ! isset( $data['config'] ) || ! is_array( $data['config'] ) ) {
            return new WP_Error( 'gt_import_missing_config', __( 'Import file is missing the config section.', 'tc-data-tables' ) );
        }

        return true;
    }
}
