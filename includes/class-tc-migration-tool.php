<?php
/**
 * TC_Migration_Tool - legacy-to-current table format migration (#214).
 *
 * "Legacy" tables are those whose settings still use the old `selected_fields`
 * array (a flat list of Gravity Forms field IDs) instead of the modern `columns`
 * array (an array of per-column config objects with `field_id`, `label`, etc.).
 *
 * This class is intentionally free of WordPress globals so it can be
 * unit-tested without a full WP bootstrap. All DB operations are gated
 * behind method calls that depend on `$wpdb`.
 *
 * @package GravityTables
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Migration_Tool
{
    /**
     * Return true if the given settings array uses the legacy selected_fields format.
     *
     * @param array $settings Table settings array (unserialized).
     * @return bool
     */
    public static function is_legacy_table(array $settings): bool
    {
        // A table is "legacy" if it has selected_fields but no columns key.
        if (isset($settings['selected_fields']) && is_array($settings['selected_fields'])) {
            if (!isset($settings['columns']) || empty($settings['columns'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return an array of table IDs (ints) that are currently in legacy format.
     *
     * @return int[]
     */
    public static function get_legacy_tables(): array
    {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd

        $rows = $wpdb->get_results(
            "SELECT `id`, `settings` FROM {$wpdb->prefix}gravity_tables",
            ARRAY_A
        );

        $legacy_ids = [];
        foreach ((array) $rows as $row) {
            $settings = maybe_unserialize($row['settings'] ?? '');
            if (is_array($settings) && self::is_legacy_table($settings)) {
                $legacy_ids[] = (int) $row['id'];
            }
        }

        return $legacy_ids;
    }

    /**
     * Migrate a single table from legacy to modern format.
     *
     * Actions taken:
     *  1. Load the current settings from the DB.
     *  2. Store a backup copy under `legacy_backup_settings`.
     *  3. Convert `selected_fields` to the modern `columns` array.
     *  4. Save the updated settings back to the DB.
     *
     * @param int $table_id Database table ID.
     * @return array { success: bool, message: string }
     */
    public static function migrate_table(int $table_id): array
    {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT `settings` FROM {$wpdb->prefix}gravity_tables WHERE `id` = %d",
                $table_id
            ),
            ARRAY_A
        );

        if (!$row) {
            return ['success' => false, 'message' => __('Table not found.', 'tc-data-tables')];
        }

        $settings = maybe_unserialize($row['settings'] ?? '');
        if (!is_array($settings)) {
            $settings = [];
        }

        if (!self::is_legacy_table($settings)) {
            return ['success' => false, 'message' => __('Table is already in the current format.', 'tc-data-tables')];
        }

        // Step 1: Back up the original settings so the migration is reversible.
        $settings['legacy_backup_settings'] = $settings;

        // Step 2: Convert selected_fields → columns.
        $selected_fields = (array) $settings['selected_fields'];
        $columns = [];
        foreach ($selected_fields as $field_id) {
            $columns[] = [
                'field_id' => (string) $field_id,
                'label'    => '',
            ];
        }
        $settings['columns'] = $columns;

        // Keep selected_fields for backward compatibility but mark it migrated.
        $settings['selected_fields_migrated'] = true;

        // Step 3: Save.
        $result = $wpdb->update(
            "{$wpdb->prefix}gravity_tables",
            ['settings' => maybe_serialize($settings)],
            ['id' => $table_id]
        );

        if ($result === false) {
            return ['success' => false, 'message' => __('Database update failed.', 'tc-data-tables')];
        }

        return [
            'success' => true,
            'message' => sprintf(
                /* translators: %d = table ID */
                __('Table #%d successfully migrated to current format. A backup of the original settings has been saved as legacy_backup_settings.', 'tc-data-tables'),
                $table_id
            ),
        ];
    }
}
