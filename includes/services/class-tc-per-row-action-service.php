<?php
/**
 * TC_Per_Row_Action_Service
 *
 * Issue #618 — slice 1 of N. Pure registry + render helper for the
 * per-row action button feature requested via:
 *   "Per-row action buttons — trigger email, webhook, or custom
 *    workflow from each row."
 *
 * Slice 1 (this release): registry service, normalize + visibility
 * helpers, render_buttons_html. No production caller yet.
 *
 * Slice 2 (future): templates/table.php renders the action buttons
 * inline next to each row's existing actions.
 *
 * Slice 3 (future): built-in actions (send email, post webhook).
 *
 * Developers register actions via:
 *
 *     add_filter('gt_per_row_actions', function ($actions) {
 *         $actions[] = [
 *             'id'           => 'send_email',
 *             'label'        => 'Send email',
 *             'url_template' => '/wp-admin/admin-post.php?action=gt_action_send_email&table={table}&row={row}',
 *             'capability'   => 'edit_posts', // optional
 *             'icon'         => 'email',      // optional
 *         ];
 *         return $actions;
 *     });
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Per_Row_Action_Service {

    /**
     * Get the registered actions, normalized + filtered by current
     * user's capability.
     *
     * @return array<int, array{id: string, label: string, url_template: string, capability: string, icon: string}>
     */
    public static function get_actions(): array {
        $raw = function_exists('apply_filters')
            ? apply_filters('gt_per_row_actions', [])
            // @codeCoverageIgnoreStart
            : [];
            // @codeCoverageIgnoreEnd
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $candidate) {
            if (!is_array($candidate)) continue;
            $normalized = self::normalize_action($candidate);
            if ($normalized === null) continue;
            if (!self::is_visible_for_user($normalized)) continue;
            $out[] = $normalized;
        }
        return $out;
    }

    /**
     * Normalize a candidate action. Returns the normalized array or
     * null when the candidate is missing required fields.
     */
    public static function normalize_action(array $candidate): ?array {
        $id_raw = isset($candidate['id']) ? (string) $candidate['id'] : '';
        $label  = isset($candidate['label']) ? (string) $candidate['label'] : '';
        $url    = isset($candidate['url_template']) ? (string) $candidate['url_template'] : '';
        if ($id_raw === '' || $label === '' || $url === '') {
            return null;
        }
        // Sanitize id to alphanumeric + underscore.
        $id = strtolower($id_raw);
        $id = preg_replace('/[^a-z0-9_]+/', '_', $id);
        $id = trim($id, '_');
        if ($id === '') {
            return null;
        }
        return [
            'id'           => $id,
            'label'        => $label,
            'url_template' => $url,
            'capability'   => isset($candidate['capability']) ? (string) $candidate['capability'] : '',
            'icon'         => isset($candidate['icon']) ? (string) $candidate['icon'] : '',
        ];
    }

    /**
     * Check whether the current user can see this action. Empty
     * capability = visible to anyone (including logged-out visitors).
     * Non-empty capability = delegate to current_user_can. Fail closed
     * when the WP function isn't available and a capability is required.
     */
    public static function is_visible_for_user(array $action): bool {
        $cap = isset($action['capability']) ? (string) $action['capability'] : '';
        if ($cap === '') {
            return true;
        }
        // @codeCoverageIgnoreStart
        if (!function_exists('current_user_can')) {
            return false; // fail-closed
        }
        // @codeCoverageIgnoreEnd
        return (bool) current_user_can($cap);
    }

    /**
     * Render the action buttons for a single row.
     *
     * Returns an empty string when no actions are registered (or none
     * are visible to the current user). Otherwise returns a `<span
     * class="gt-row-actions">…</span>` wrapper containing one
     * `<a class="gt-row-action gt-row-action-{id}">…</a>` per action.
     */
    public static function render_buttons_html(int $table_id, int $row_id, array $row_data): string {
        $actions = self::get_actions();
        if (empty($actions)) {
            return '';
        }
        $html = '<span class="gt-row-actions">';
        foreach ($actions as $action) {
            $url   = self::interpolate_url($action['url_template'], $table_id, $row_id);
            $label = $action['label'];
            $id    = $action['id'];
            $href  = function_exists('esc_url') ? esc_url($url) : self::escape_url_basic($url);
            $lbl   = function_exists('esc_html') ? esc_html($label) : self::escape_html_basic($label);
            $cls   = function_exists('esc_attr') ? esc_attr($id) : preg_replace('/[^a-z0-9_]/', '', $id);
            $html .= '<a class="gt-row-action gt-row-action-' . $cls . '" href="' . $href . '">' . $lbl . '</a>';
        }
        $html .= '</span>';
        return $html;
    }

    /**
     * #618 slice 3 — Built-in send_email action.
     *
     * Returns the action shape the gt_per_row_actions filter expects.
     * The url_template targets the admin-post.php handler registered
     * in tablecrafter.php (admin_post_gt_action_send_email →
     * gt_handle_send_email_action). The handler verifies nonce +
     * capability, loads the entry, and dispatches via wp_mail.
     *
     * Customers opt in by hooking the gt_send_email_enabled filter
     * (or by registering this action directly via gt_per_row_actions).
     */
    public static function register_builtin_send_email(): array {
        $url = '/wp-admin/admin-post.php?action=gt_action_send_email&table={table}&row={row}';
        if (function_exists('wp_create_nonce')) {
            $url .= '&_wpnonce=' . urlencode(wp_create_nonce('gt_action_send_email'));
        }
        $label = function_exists('__') ? __('Send email', 'tc-data-tables') : 'Send email';
        return [
            'id'           => 'send_email',
            'label'        => $label,
            'url_template' => $url,
            'capability'   => 'edit_posts',
            'icon'         => 'email',
        ];
    }

    /**
     * #618 slice 4 — Built-in post_webhook action.
     *
     * Mirrors register_builtin_send_email's shape but targets the
     * gt_action_post_webhook admin-post handler. The handler reads
     * the destination URL from the gt_post_webhook_url filter (no
     * admin UI yet — slice 5 ships per-table config).
     */
    public static function register_builtin_post_webhook(): array {
        $url = '/wp-admin/admin-post.php?action=gt_action_post_webhook&table={table}&row={row}';
        if (function_exists('wp_create_nonce')) {
            $url .= '&_wpnonce=' . urlencode(wp_create_nonce('gt_action_post_webhook'));
        }
        $label = function_exists('__') ? __('Send to webhook', 'tc-data-tables') : 'Send to webhook';
        return [
            'id'           => 'post_webhook',
            'label'        => $label,
            'url_template' => $url,
            'capability'   => 'edit_posts',
            'icon'         => 'webhook',
        ];
    }

    /**
     * Replace `{table}` and `{row}` placeholders in the url_template
     * with their concrete values (urlencoded for safety).
     */
    public static function interpolate_url(string $url_template, int $table_id, int $row_id): string {
        return strtr($url_template, [
            '{table}' => urlencode((string) $table_id),
            '{row}'   => urlencode((string) $row_id),
        ]);
    }

    /**
     * Minimal URL escaping fallback for non-WP contexts (mainly tests).
     * Production runs always have esc_url available.
     */
    // @codeCoverageIgnoreStart
    private static function escape_url_basic(string $url): string {
        return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // @codeCoverageIgnoreEnd
    }

    // @codeCoverageIgnoreStart
    private static function escape_html_basic(string $html): string {
        return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // @codeCoverageIgnoreEnd
    }
}
