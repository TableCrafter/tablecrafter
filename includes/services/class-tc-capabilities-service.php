<?php
/**
 * Role-based access control for Gravity Tables management.
 *
 * Registers four custom WP capabilities that integrate with the standard
 * WP capabilities API, making them manageable by third-party role plugins
 * such as User Role Editor.
 *
 * Only users with manage_options (administrators) may change which roles hold
 * these capabilities.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Capabilities_Service {

    const CAPABILITIES = [
        'view_gravity_tables',
        'edit_gravity_tables',
        'create_gravity_tables',
        'delete_gravity_tables',
        // #613 phase 2 slice 12 (v4.207.0) - gate for the bulk "Push to
        // source" flow. Auto-granted to administrators on activation; admins
        // can grant to other roles via the existing capability admin UI.
        'push_rows_to_source',
        // #1069 slice 32 (v5.2.4) - gate for the CSV/Excel/JSON export
        // pipeline. The pre-fix gate was current_user_can('read'), which
        // is granted to subscribers by default - every logged-in user
        // could export any form's entries. Dedicated capability,
        // auto-granted to administrators on activation, manageable
        // through the same role-grant UI as the rest of the GT caps.
        'export_gravity_tables',
        // #2242 - TC_External_DB::execute_query() has gated on this cap
        // since #2003, but it was never in this list, so NOTHING granted
        // it: every external-DB query (frontend render included) came back
        // permission_denied for every user, admins included. Registering
        // it here self-heals existing installs via
        // ensure_capabilities_for_version() on the next version bump.
        'gravity_tables_view_external',
    ];

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Add all custom capabilities to the administrator role.
     *
     * Called on plugin activation and as a one-time setup. Safe to call
     * multiple times - add_cap() is a no-op when the cap already exists.
     */
    public static function register_capabilities(): void {
        // Always grant to administrator first - this is the canary role
        // is_repair_needed() checks. (#554)
        $admin_role = get_role('administrator');
        if ($admin_role) {
            foreach (self::CAPABILITIES as $cap) {
                $admin_role->add_cap($cap, true);
            }
        }

        // Also grant to any other roles configured under
        // gt_settings.user_roles_can_edit (#554) so admins who set
        // editor-role access in settings don't have to manually
        // re-grant after activation / upgrade.
        $settings = get_option('gt_settings', []);
        $extra_roles = is_array($settings) && !empty($settings['user_roles_can_edit'])
            ? (array) $settings['user_roles_can_edit']
            : [];
        foreach ($extra_roles as $role_name) {
            $role_name = sanitize_key((string) $role_name);
            if ($role_name === '' || $role_name === 'administrator') {
                continue;
            }
            $role = get_role($role_name);
            if (!$role) {
                continue;
            }
            foreach (self::CAPABILITIES as $cap) {
                $role->add_cap($cap, true);
            }
        }
    }

    /**
     * Re-apply capabilities iff the stored plugin version is older
     * than the current plugin version. Returns true when caps were
     * re-applied, false otherwise.
     *
     * Plugin updates do NOT fire `register_activation_hook`, so a
     * site upgrading from a version that pre-dates the activation
     * hook would otherwise be stuck without the caps. The bootstrap
     * calls this on `plugins_loaded` to self-heal. (#554)
     */
    public static function ensure_capabilities_for_version(string $current_version, string $expected_version): bool {
        if ($current_version !== '' && version_compare($current_version, $expected_version, '>=')) {
            return false;
        }
        self::register_capabilities();
        return true;
    }

    /**
     * Canary check: returns true when the administrator role lacks
     * the `view_gravity_tables` capability - a strong indicator that
     * registration was skipped or got deregistered.
     *
     * Surface this in an admin notice and offer a one-click "Repair
     * capabilities" button. (#554)
     */
    public static function is_repair_needed(): bool {
        $admin_role = get_role('administrator');
        if (!$admin_role) {
            return false;  // can't repair what doesn't exist
        }
        $caps = isset($admin_role->capabilities) ? (array) $admin_role->capabilities : [];
        if (isset($admin_role->caps)) {
            // Test-double / minimal role objects expose `caps` directly.
            $caps = (array) $admin_role->caps;
        }
        return empty($caps['view_gravity_tables']);
    }

    /**
     * Remove all custom capabilities from every role (called on uninstall).
     */
    public static function deregister_capabilities(): void {
        global $wp_roles;
        if (!isset($wp_roles)) {
            $wp_roles = new WP_Roles();
        }
        foreach ($wp_roles->roles as $role_name => $role_data) {
            $role = get_role($role_name);
            if ($role) {
                foreach (self::CAPABILITIES as $cap) {
                    $role->remove_cap($cap);
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Grant / revoke
    // -------------------------------------------------------------------------

    /**
     * Grant a capability to a role.
     *
     * Requires the current user to have manage_options.
     *
     * @param string $role_name  WP role slug (e.g. 'editor').
     * @param string $cap        One of self::CAPABILITIES.
     * @return bool  True on success, false on permission denied or invalid cap.
     */
    public static function grant_capability(string $role_name, string $cap): bool {
        if (!current_user_can('manage_options')) {
            return false;
        }
        if (!in_array($cap, self::CAPABILITIES, true)) {
            return false;
        }
        $role = get_role(sanitize_key($role_name));
        if (!$role) {
            return false;
        }
        $role->add_cap($cap, true);
        return true;
    }

    /**
     * Revoke a capability from a role.
     *
     * Requires the current user to have manage_options.
     *
     * @param string $role_name
     * @param string $cap
     * @return bool
     */
    public static function revoke_capability(string $role_name, string $cap): bool {
        if (!current_user_can('manage_options')) {
            return false;
        }
        if (!in_array($cap, self::CAPABILITIES, true)) {
            return false;
        }
        $role = get_role(sanitize_key($role_name));
        if (!$role) {
            return false;
        }
        $role->remove_cap($cap);
        return true;
    }

    // -------------------------------------------------------------------------
    // Checking
    // -------------------------------------------------------------------------

    /**
     * Check whether the current user holds a Gravity Tables capability.
     *
     * Delegates to the standard WP current_user_can() so meta-caps and
     * role manager plugins work as expected.
     *
     * @param string $cap       One of self::CAPABILITIES.
     * @param int    $table_id  Optional table ID for future per-table scoping.
     * @return bool
     */
    public static function current_user_can(string $cap, int $table_id = 0): bool {
        if (!in_array($cap, self::CAPABILITIES, true)) {
            return false;
        }
        return (bool) current_user_can($cap);
    }

    // -------------------------------------------------------------------------
    // Querying
    // -------------------------------------------------------------------------

    /**
     * Return a list of role slugs that currently hold the given capability.
     *
     * @param string $cap
     * @return string[]
     */
    public static function get_roles_with_capability(string $cap): array {
        $matching = [];
        $all_roles = get_editable_roles();

        foreach ($all_roles as $role_slug => $role_data) {
            if (!empty($role_data['capabilities'][$cap])) {
                $matching[] = $role_slug;
            }
        }

        return $matching;
    }

    /**
     * Return all custom Gravity Tables capabilities with their human-readable labels.
     *
     * @return array<string, string>
     */
    public static function get_capability_labels(): array {
        return [
            'view_gravity_tables'   => __('View Tables', 'tc-data-tables'),
            'edit_gravity_tables'   => __('Edit Tables', 'tc-data-tables'),
            'create_gravity_tables' => __('Create Tables', 'tc-data-tables'),
            'delete_gravity_tables' => __('Delete Tables', 'tc-data-tables'),
            // #1069 slice 32 - surfaced in the role-grant UI so admins can
            // explicitly grant export rights to editor / shop-manager / etc.
            'export_gravity_tables' => __('Export Tables', 'tc-data-tables'),
        ];
    }
}
