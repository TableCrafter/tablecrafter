<?php
/**
 * #517 slice 3b - Airtable settings admin view.
 *
 * Shows form to enter base id + table id + token, submits to three
 * separate admin-post handlers (save / test / clear). Token is never
 * pre-filled; when credentials are configured, the form shows a
 * masked-state hint instead of leaking the stored token.
 */

if (!defined('ABSPATH')) {
    exit;
}

$creds = TC_Airtable_Credential_Service::load();
$is_configured = TC_Airtable_Credential_Service::is_configured();
$current_base  = $creds['base_id']  ?? '';
$current_table = $creds['table_id'] ?? '';

$saved   = isset($_GET['gt_airtable_saved']) ? (string) $_GET['gt_airtable_saved'] : '';
$test    = isset($_GET['gt_airtable_test']) ? (string) $_GET['gt_airtable_test'] : '';
$test_err = isset($_GET['gt_airtable_error']) ? rawurldecode((string) $_GET['gt_airtable_error']) : '';
$cleared = isset($_GET['gt_airtable_cleared']) ? (string) $_GET['gt_airtable_cleared'] : '';

$post_url = esc_url(admin_url('admin-post.php'));
?>
<div class="wrap gt-airtable-settings">
    <h1><?php esc_html_e('Airtable Connection', 'tc-data-tables'); ?></h1>

    <?php if ($saved === '1') : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Airtable credentials saved.', 'tc-data-tables'); ?></p></div>
    <?php elseif ($saved === '0') : ?>
        <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Could not save credentials. All three fields are required.', 'tc-data-tables'); ?></p></div>
    <?php endif; ?>

    <?php if ($test === 'ok') : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Connection successful - Airtable accepted the credentials.', 'tc-data-tables'); ?></p></div>
    <?php elseif ($test === 'fail') : ?>
        <div class="notice notice-error is-dismissible"><p><?php printf(esc_html__('Connection failed: %s', 'tc-data-tables'), esc_html($test_err)); ?></p></div>
    <?php elseif ($test === 'unconfigured') : ?>
        <div class="notice notice-warning is-dismissible"><p><?php esc_html_e('Save credentials first, then run Test Connection.', 'tc-data-tables'); ?></p></div>
    <?php endif; ?>

    <?php if ($cleared === '1') : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Airtable credentials cleared.', 'tc-data-tables'); ?></p></div>
    <?php endif; ?>

    <p class="description">
        <?php esc_html_e('Connect a Gravity Table to an Airtable base. Tokens are encrypted at rest using your WordPress AUTH_KEY salt and stored in a non-autoloaded option.', 'tc-data-tables'); ?>
    </p>

    <?php if ($is_configured) : ?>
        <div class="gt-airtable-configured" style="padding:12px;background:#f0f6fc;border-left:4px solid #0073aa;margin:16px 0;">
            <strong><?php esc_html_e('Configured', 'tc-data-tables'); ?>:</strong>
            <?php
            printf(
                /* translators: %1$s base id, %2$s table id */
                esc_html__('Connected to base %1$s, table %2$s. Token is encrypted at rest.', 'tc-data-tables'),
                '<code>' . esc_html($current_base) . '</code>',
                '<code>' . esc_html($current_table) . '</code>'
            );
            ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo $post_url; ?>" style="max-width:640px;">
        <?php wp_nonce_field('gt_airtable_save_credentials'); ?>
        <input type="hidden" name="action" value="gt_airtable_save_credentials" />

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="gt-airtable-base-id"><?php esc_html_e('Base ID', 'tc-data-tables'); ?></label></th>
                <td>
                    <input
                        type="text"
                        id="gt-airtable-base-id"
                        name="base_id"
                        value="<?php echo esc_attr($current_base); ?>"
                        class="regular-text"
                        placeholder="appAbc123XYZ"
                        autocomplete="off" />
                    <p class="description"><?php esc_html_e('Starts with "app". Find this in your Airtable base URL.', 'tc-data-tables'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="gt-airtable-table-id"><?php esc_html_e('Table name or ID', 'tc-data-tables'); ?></label></th>
                <td>
                    <input
                        type="text"
                        id="gt-airtable-table-id"
                        name="table_id"
                        value="<?php echo esc_attr($current_table); ?>"
                        class="regular-text"
                        placeholder="Customers"
                        autocomplete="off" />
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="gt-airtable-token"><?php esc_html_e('API Token', 'tc-data-tables'); ?></label></th>
                <td>
                    <input
                        type="password"
                        id="gt-airtable-token"
                        name="token"
                        class="regular-text"
                        placeholder="<?php echo $is_configured ? esc_attr__('(stored - leave blank to keep current)', 'tc-data-tables') : 'pat...'; ?>"
                        autocomplete="off" />
                    <p class="description">
                        <?php esc_html_e('Personal access token (PAT) with data.records:read scope. Encrypted before storage.', 'tc-data-tables'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button(__('Save credentials', 'tc-data-tables')); ?>
    </form>

    <?php if ($is_configured) : ?>
        <hr style="margin:24px 0;">

        <h2><?php esc_html_e('Test connection', 'tc-data-tables'); ?></h2>
        <p class="description"><?php esc_html_e('Sends a one-record probe to Airtable to verify authentication and that the base / table exist.', 'tc-data-tables'); ?></p>
        <form method="post" action="<?php echo $post_url; ?>" style="display:inline-block;">
            <?php wp_nonce_field('gt_airtable_test_connection'); ?>
            <input type="hidden" name="action" value="gt_airtable_test_connection" />
            <button type="submit" class="button button-secondary"><?php esc_html_e('Test connection', 'tc-data-tables'); ?></button>
        </form>

        <hr style="margin:24px 0;">

        <h2><?php esc_html_e('Clear credentials', 'tc-data-tables'); ?></h2>
        <p class="description"><?php esc_html_e('Removes the encrypted token and base/table mapping. The Airtable services stay available - just unconfigured.', 'tc-data-tables'); ?></p>
        <form method="post" action="<?php echo $post_url; ?>" onsubmit="return confirm('<?php echo esc_js(__('Clear stored Airtable credentials? You will need to re-enter them to reconnect.', 'tc-data-tables')); ?>');" style="display:inline-block;">
            <?php wp_nonce_field('gt_airtable_clear_credentials'); ?>
            <input type="hidden" name="action" value="gt_airtable_clear_credentials" />
            <button type="submit" class="button button-link-delete"><?php esc_html_e('Clear credentials', 'tc-data-tables'); ?></button>
        </form>
    <?php endif; ?>

    <?php
    // #517 slice 4e - Recent push activity. Surfaces the audit log from
    // TC_Airtable_Audit_Log_Service so admins can inspect what's been pushed
    // (success + failure) without grepping debug.log. Capped at 25 events
    // for the on-page view; the underlying option holds up to 100.
    if (class_exists('TC_Airtable_Audit_Log_Service')) :
        $gt_audit_events = TC_Airtable_Audit_Log_Service::load(25);
    ?>
        <hr style="margin:24px 0;">

        <h2><?php esc_html_e('Recent push activity', 'tc-data-tables'); ?></h2>
        <p class="description">
            <?php
            printf(
                /* translators: %d max events shown */
                esc_html__('Last %d Airtable push attempts (success + failure). Slice 4f+ will add filtering, conflict-resolution flags, and rate-limit indicators.', 'tc-data-tables'),
                25
            );
            ?>
        </p>

        <?php if (empty($gt_audit_events)) : ?>
            <p><em><?php esc_html_e('No push activity recorded yet. Inline-edits will start appearing here once sync_direction is push_only or two_way for at least one table.', 'tc-data-tables'); ?></em></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:900px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Time', 'tc-data-tables'); ?></th>
                        <th><?php esc_html_e('Entry', 'tc-data-tables'); ?></th>
                        <th><?php esc_html_e('Record', 'tc-data-tables'); ?></th>
                        <th><?php esc_html_e('Result', 'tc-data-tables'); ?></th>
                        <th><?php esc_html_e('Detail', 'tc-data-tables'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($gt_audit_events as $gt_evt) : ?>
                    <tr>
                        <td><code><?php
                            $gt_ts = isset($gt_evt['timestamp']) ? (int) $gt_evt['timestamp'] : 0;
                            echo esc_html($gt_ts > 0 ? gmdate('Y-m-d H:i:s', $gt_ts) . ' UTC' : ' - ');
                        ?></code></td>
                        <td><code><?php echo esc_html((string) ($gt_evt['entry_id'] ?? ' - ')); ?></code></td>
                        <td><code><?php echo esc_html((string) ($gt_evt['record_id'] ?? ' - ')); ?></code></td>
                        <td>
                            <?php if (!empty($gt_evt['ok'])) : ?>
                                <span style="color:#2271b1;font-weight:600;"><?php esc_html_e('OK', 'tc-data-tables'); ?></span>
                            <?php else : ?>
                                <span style="color:#d63638;font-weight:600;"><?php esc_html_e('FAIL', 'tc-data-tables'); ?></span>
                            <?php endif; ?>
                            <?php if (isset($gt_evt['http_code']) && $gt_evt['http_code'] !== null) : ?>
                                <small>(<?php echo esc_html((string) $gt_evt['http_code']); ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td><?php
                            $gt_err = isset($gt_evt['error']) && $gt_evt['error'] !== null ? (string) $gt_evt['error'] : '';
                            echo $gt_err === '' ? ' - ' : esc_html($gt_err);
                        ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</div>
