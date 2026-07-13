<?php
/**
 * Push Audit Log admin view - #613 phase 2 v4.223.0.
 *
 * Surfaces:
 *   - The most-recent N push events from TC_Push_Audit_Log_Service (v4.205.0).
 *   - The per-source rate-limit caps from TC_Push_Rate_Limiter (v4.206.0).
 *
 * Read-only - no actions, no AJAX. Operators use this page to debug
 * customer reports like "the push didn't work" without dropping to wp-cli.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    wp_die(esc_html__('You do not have permission to view this page.', 'tc-data-tables'));
}

// Ensure the services we render are loaded - they're normally pulled in by
// the frontend shortcode path, not on admin pages.
foreach (array(
    TC_PLUGIN_PATH . 'includes/services/class-tc-push-audit-log-service.php',
    TC_PLUGIN_PATH . 'includes/services/class-tc-push-rate-limiter.php',
) as $gt_audit_dep) {
    if (file_exists($gt_audit_dep)) {
        require_once $gt_audit_dep;
    }
}

$gt_audit_events = class_exists('TC_Push_Audit_Log_Service')
    ? TC_Push_Audit_Log_Service::load(100)
    : array();

$gt_rate_sources = array('json', 'airtable', 'notion');
?>
<div class="wrap">
    <h1><?php esc_html_e('Push Audit Log', 'tc-data-tables'); ?></h1>
    <p class="description">
        <?php esc_html_e('Most-recent outgoing two-way-sync push events across JSON / Airtable / Notion. Read-only.', 'tc-data-tables'); ?>
    </p>

    <h2><?php esc_html_e('Per-source rate limits', 'tc-data-tables'); ?></h2>
    <table class="widefat striped" style="max-width: 480px;">
        <thead>
            <tr>
                <th><?php esc_html_e('Source', 'tc-data-tables'); ?></th>
                <th><?php esc_html_e('Cap (requests / window)', 'tc-data-tables'); ?></th>
                <th><?php esc_html_e('Window (seconds)', 'tc-data-tables'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (class_exists('TC_Push_Rate_Limiter')): ?>
            <?php foreach ($gt_rate_sources as $src): ?>
                <tr>
                    <td><code><?php echo esc_html($src); ?></code></td>
                    <td><?php echo esc_html((string) TC_Push_Rate_Limiter::limit_for($src)); ?></td>
                    <td><?php echo esc_html((string) TC_Push_Rate_Limiter::window_seconds()); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="3"><?php esc_html_e('TC_Push_Rate_Limiter unavailable.', 'tc-data-tables'); ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h2 style="margin-top: 2em;"><?php esc_html_e('Recent push events', 'tc-data-tables'); ?></h2>
    <?php if (empty($gt_audit_events)): ?>
        <p><?php esc_html_e('No push events recorded yet.', 'tc-data-tables'); ?></p>
    <?php else: ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Timestamp', 'tc-data-tables'); ?></th>
                    <th><?php esc_html_e('Source', 'tc-data-tables'); ?></th>
                    <th><?php esc_html_e('Table ID', 'tc-data-tables'); ?></th>
                    <th><?php esc_html_e('Row ID', 'tc-data-tables'); ?></th>
                    <th><?php esc_html_e('Outcome', 'tc-data-tables'); ?></th>
                    <th><?php esc_html_e('Error code', 'tc-data-tables'); ?></th>
                    <th><?php esc_html_e('HTTP', 'tc-data-tables'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($gt_audit_events as $evt): ?>
                <?php
                $ts        = isset($evt['timestamp']) ? (int) $evt['timestamp'] : 0;
                $source    = isset($evt['source']) ? (string) $evt['source'] : '';
                $table_id  = isset($evt['table_id']) ? (int) $evt['table_id'] : 0;
                $row_id    = isset($evt['row_id']) ? (string) $evt['row_id'] : '';
                $success   = !empty($evt['success']);
                $err_code  = isset($evt['error_code']) ? (string) $evt['error_code'] : '';
                $http_code = isset($evt['http_code']) ? (int) $evt['http_code'] : 0;
                $ts_str    = $ts > 0 ? wp_date('Y-m-d H:i:s', $ts) : ' - ';
                ?>
                <tr>
                    <td><code><?php echo esc_html($ts_str); ?></code></td>
                    <td><code><?php echo esc_html($source); ?></code></td>
                    <td><?php echo esc_html((string) $table_id); ?></td>
                    <td><code><?php echo esc_html($row_id); ?></code></td>
                    <td>
                        <?php if ($success): ?>
                            <span style="color: #1a7f37;">&#x2713; <?php esc_html_e('OK', 'tc-data-tables'); ?></span>
                        <?php else: ?>
                            <span style="color: #b32d2e;">&#x2717; <?php esc_html_e('Failed', 'tc-data-tables'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo esc_html($err_code); ?></code></td>
                    <td><?php echo $http_code > 0 ? esc_html((string) $http_code) : ' - '; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description">
            <?php
            printf(
                /* translators: %d = audit retention cap */
                esc_html__('Showing the most-recent up to %d events. Older events are pruned by the service.', 'tc-data-tables'),
                (int) (class_exists('TC_Push_Audit_Log_Service') ? TC_Push_Audit_Log_Service::max_entries() : 500)
            );
            ?>
        </p>
    <?php endif; ?>
</div>
