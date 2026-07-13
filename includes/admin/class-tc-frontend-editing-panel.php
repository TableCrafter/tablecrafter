<?php
/**
 * Renders the frontend-editing toggle panel in the table builder admin UI.
 *
 * Surfaces the enable_frontend_editing toggle at the top level of the builder
 * (rather than in a sub-sub-tab), includes a help blurb, shows an inline
 * warning when no roles are configured, and provides a quick-link status badge
 * for the builder summary panel.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Frontend_Editing_Panel {

    /**
     * Render the full frontend-editing settings panel.
     *
     * @param array $settings Current table settings array. Expected keys:
     *   - enable_frontend_editing (bool)
     *   - user_roles_can_edit     (string[])
     */
    public static function render(array $settings): void {
        $enabled       = !empty($settings['enable_frontend_editing']);
        $roles         = $settings['user_roles_can_edit'] ?? [];
        $no_roles      = $enabled && empty($roles);
        $settings_url  = admin_url('admin.php?page=gravity-tables&tab=settings#frontend-editing');
        ?>
        <div class="gt-panel gt-frontend-editing-panel" id="gt-frontend-editing-panel">
            <h3 class="gt-panel__title"><?php esc_html_e('Frontend Editing', 'tc-data-tables'); ?></h3>

            <label class="gt-toggle-label">
                <input
                    type="checkbox"
                    name="gt_settings[enable_frontend_editing]"
                    id="gt_enable_frontend_editing"
                    value="1"
                    <?php checked($enabled); ?>
                />
                <?php esc_html_e('Enable frontend editing for this table', 'tc-data-tables'); ?>
            </label>

            <p class="gt-help-text">
                <?php esc_html_e(
                    'Frontend editing lets logged-in users edit, add, and delete table rows directly from the front end of your site - no admin access required. Access is controlled by role and capability settings below.',
                    'tc-data-tables'
                ); ?>
            </p>

            <?php if ($no_roles) : ?>
            <div class="notice notice-warning gt-inline-notice" role="alert">
                <p>
                    <strong><?php esc_html_e('No roles are configured for frontend editing.', 'tc-data-tables'); ?></strong>
                    <?php printf(
                        /* translators: %s: link to settings page */
                        esc_html__('Visitors will see the table but the edit controls will be hidden. Please configure which roles can edit on the %s.', 'tc-data-tables'),
                        '<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings page', 'tc-data-tables') . '</a>'
                    ); ?>
                </p>
            </div>
            <?php endif; ?>

            <div class="gt-roles-summary" <?php echo $enabled ? '' : 'style="display:none;"'; ?>>
                <?php if (!empty($roles)) : ?>
                <p class="gt-roles-list">
                    <?php
                    $label = esc_html__('Roles that can edit:', 'tc-data-tables');
                    $list  = implode(', ', array_map('esc_html', $roles));
                    echo wp_kses_post("<strong>{$label}</strong> {$list}");
                    ?>
                    &mdash; <a href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('Configure', 'tc-data-tables'); ?></a>
                </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Return an HTML string for use in the builder summary/header panel.
     * Displays "Frontend editing: ON (configure)" or "Frontend editing: OFF (configure)".
     *
     * @param array $settings Current table settings array.
     * @return string
     */
    public static function render_summary_status(array $settings): string {
        $enabled      = !empty($settings['enable_frontend_editing']);
        $settings_url = admin_url('admin.php?page=gravity-tables&tab=settings#frontend-editing');

        $state_label = $enabled
            ? '<span class="gt-status gt-status--on">' . esc_html__('ON', 'tc-data-tables') . '</span>'
            : '<span class="gt-status gt-status--off">' . esc_html__('OFF', 'tc-data-tables') . '</span>';

        $configure = '<a href="' . esc_url($settings_url) . '" class="gt-summary-configure-link">'
            . esc_html__('configure', 'tc-data-tables') . '</a>';

        return sprintf(
            '<span class="gt-summary-item gt-summary-frontend-editing">%s: %s (%s)</span>',
            esc_html__('Frontend editing', 'tc-data-tables'),
            $state_label,
            $configure
        );
    }

    /**
     * Whether at least one role is configured for editing.
     *
     * @param array $settings
     * @return bool
     */
    private static function roles_configured(array $settings): bool {
        return !empty($settings['user_roles_can_edit']);
    }
}
