<?php
/**
 * Tables List View
 *
 * @package GravityTables
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get tables data
$admin = TC_Admin::get_instance();
$tables = $admin->get_tables();

// Get available Gravity Forms for reference
$forms = array();
if (class_exists('GFAPI')) {
    $forms = GFAPI::get_forms();
}

// Create a map of form IDs to titles for display
$form_titles = array();
foreach ($forms as $form) {
    $form_titles[$form['id']] = $form['title'];
}
?>

<div class="wrap">
    <h1 class="wp-heading-inline"><?php _e('TableCrafter', 'tc-data-tables'); ?></h1>

    <?php
    // #2063 — getting-started: one-click demo tables. Click a dataset to create a
    // ready-to-view table instantly (great for first-run / evaluation).
    if (current_user_can('manage_options') && class_exists('TC_Demo_Data')) :
        ?>
        <div class="notice notice-info gt-getting-started" style="margin-top:12px;padding:12px;">
            <p style="margin:0 0 8px;"><strong><?php esc_html_e('New here? Load a demo table in one click:', 'tc-data-tables'); ?></strong></p>
            <p style="margin:0;">
                <?php foreach (TC_Demo_Data::datasets() as $gt_demo_key => $gt_demo) : ?>
                    <button type="button" class="button gt-load-demo" data-demo="<?php echo esc_attr($gt_demo_key); ?>"><?php echo esc_html($gt_demo['label']); ?></button>
                <?php endforeach; ?>
                <span class="gt-load-demo-result" style="margin-left:8px;"></span>
            </p>
        </div>
    <?php endif; ?>

    <?php
    // #2022 — offer to migrate deprecated [gravity_table] shortcodes in post
    // content to [tablecrafter]. Shown to admins; runs a dry-run preview first.
    if (current_user_can('manage_options')) :
        global $wpdb;
        $gt_deprecated_in_content = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_status IN ('publish','draft','pending','private','future')
               AND (post_content LIKE '%[gravity_table %' OR post_content LIKE '%[gravity_table]%'
                    OR post_content LIKE '%[gravity_tables %' OR post_content LIKE '%[gravity_tables]%')"
        );
        if ($gt_deprecated_in_content > 0) :
        ?>
        <div class="notice notice-warning gt-shortcode-migrate-notice" style="margin-top:12px;">
            <p>
                <?php printf(esc_html__('%d page(s) still use the deprecated [gravity_table] shortcode. Migrate them to [tablecrafter].', 'tc-data-tables'), $gt_deprecated_in_content); ?>
                <button type="button" class="button gt-migrate-shortcodes" data-dry-run="1"><?php esc_html_e('Preview', 'tc-data-tables'); ?></button>
                <button type="button" class="button button-primary gt-migrate-shortcodes" data-dry-run="0"><?php esc_html_e('Migrate now', 'tc-data-tables'); ?></button>
                <span class="gt-migrate-shortcodes-result" style="margin-left:8px;"></span>
            </p>
        </div>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (gt_is_free_plan()): ?>
        <?php
        global $wpdb;
        $table_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}gravity_tables WHERE status = 'active'");
        if ($table_count >= TC_FREE_MAX_TABLES):
        ?>
            <span class="page-title-action disabled" style="background: #ccc; color: #666; cursor: not-allowed;" title="<?php _e('Free plan limit reached', 'tc-data-tables'); ?>">
                <?php _e('Add New', 'tc-data-tables'); ?> (<?php echo $table_count; ?>/<?php echo TC_FREE_MAX_TABLES; ?>)
            </span>
            <?php if (function_exists('wgt_fs') && !wgt_fs()->is_trial() && !wgt_fs()->is_trial_utilized()): ?>
                <a href="<?php echo wgt_fs()->get_trial_url(); ?>" class="page-title-action" style="background: #00a32a; border-color: #007f2a; color: #ffffff; text-decoration: none;">
                    <?php _e('🎯 Start Free Trial', 'tc-data-tables'); ?>
                </a>
            <?php else: ?>
                <a href="<?php echo function_exists('wgt_fs') ? wgt_fs()->get_upgrade_url() : '#'; ?>" class="page-title-action" style="background: #0073aa; border-color: #005a87; color: #ffffff; text-decoration: none;">
                    <?php _e('🚀 Upgrade to Pro', 'tc-data-tables'); ?>
                </a>
            <?php endif; ?>
        <?php else: ?>
            <a href="<?php echo admin_url('admin.php?page=gravity-tables-new'); ?>" class="page-title-action">
                <?php _e('Add New', 'tc-data-tables'); ?> (<?php echo $table_count; ?>/<?php echo TC_FREE_MAX_TABLES; ?>)
            </a>
        <?php endif; ?>
    <?php else: ?>
        <a href="<?php echo admin_url('admin.php?page=gravity-tables-new'); ?>" class="page-title-action"><?php _e('Add New', 'tc-data-tables'); ?></a>
    <?php endif; ?>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=tablecrafter-wizard' ) ); ?>" class="page-title-action gt-wizard-entry-btn">
        ✦ <?php _e( 'New (Wizard)', 'tc-data-tables' ); ?>
        <span class="gt-wizard-entry-beta"><?php _e( 'Beta', 'tc-data-tables' ); ?></span>
    </a>
    <a href="<?php echo esc_url(admin_url('admin.php?page=gravity-tables-import')); ?>" class="page-title-action"><?php _e('Import from CSV', 'tc-data-tables'); ?></a>
    <?php
    // TC_Bulk_Migration_Bundle_Service slice 2a (v4.9.16) — Export-all
    // button. Slice 2b (v4.9.17) — Import bundle (button toggles a hidden
    // form below the header).
    if (class_exists('TC_Bulk_Migration_Bundle_Service')):
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin:0;">
        <?php wp_nonce_field('gt_bundle_export', 'gt_bundle_export_nonce'); ?>
        <input type="hidden" name="action" value="gt_bundle_export">
        <button type="submit" class="page-title-action"><?php _e('Export all to JSON', 'tc-data-tables'); ?></button>
    </form>
    <a href="#gt-bundle-import" class="page-title-action"
       onclick="document.getElementById('gt-bundle-import').open = !document.getElementById('gt-bundle-import').open; return false;"><?php _e('Import bundle', 'tc-data-tables'); ?></a>
    <?php endif; ?>

    <hr class="wp-header-end">

    <div class="gt-wizard-promo" id="gt-wizard-promo" style="display:none">
        <span class="gt-wizard-promo-icon dashicons dashicons-star-filled"></span>
        <span class="gt-wizard-promo-text">
            <strong><?php esc_html_e( 'New!', 'tc-data-tables' ); ?></strong>
            <?php esc_html_e( 'Create a table in under 2 minutes with the guided Wizard.', 'tc-data-tables' ); ?>
        </span>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=tablecrafter-wizard' ) ); ?>" class="button button-small gt-wizard-promo-cta">
            <?php esc_html_e( 'Try the Wizard', 'tc-data-tables' ); ?> &rarr;
        </a>
        <button type="button" class="gt-wizard-promo-close" aria-label="<?php esc_attr_e( 'Dismiss', 'tc-data-tables' ); ?>">&times;</button>
    </div>
    <script>
    (function () {
        if ( localStorage.getItem( 'gt_wizard_promo_dismissed' ) ) { return; }
        var el = document.getElementById( 'gt-wizard-promo' );
        if ( ! el ) { return; }
        el.style.display = '';
        el.querySelector( '.gt-wizard-promo-close' ).addEventListener( 'click', function () {
            localStorage.setItem( 'gt_wizard_promo_dismissed', '1' );
            el.style.display = 'none';
        } );
    }() );
    </script>

    <?php // #1615 — Table History modal (hidden until the Revisions row action opens it). ?>
    <div id="gt-rev-modal" hidden style="position:fixed;top:15%;left:50%;transform:translateX(-50%);z-index:100000;background:#fff;border:1px solid #c3c4c7;border-radius:4px;box-shadow:0 3px 12px rgba(0,0,0,.2);padding:16px;min-width:560px;max-height:70vh;overflow:auto;">
        <h2 style="margin-top:0;"><?php _e('Table history', 'tc-data-tables'); ?></h2>
        <p class="description"><?php _e('"Load into builder" opens the snapshot for review — nothing changes until you click Save there. "Restore now" overwrites the current configuration immediately.', 'tc-data-tables'); ?></p>
        <div class="gt-rev-list"></div>
        <p><button type="button" class="button gt-rev-cancel"><?php _e('Close', 'tc-data-tables'); ?></button></p>
    </div>

    <?php // #1614 — Find & Replace modal (hidden until a row action opens it). ?>
    <div id="gt-fr-modal" hidden style="position:fixed;top:20%;left:50%;transform:translateX(-50%);z-index:100000;background:#fff;border:1px solid #c3c4c7;border-radius:4px;box-shadow:0 3px 12px rgba(0,0,0,.2);padding:16px;min-width:420px;">
        <h2 style="margin-top:0;"><?php _e('Find &amp; Replace', 'tc-data-tables'); ?></h2>
        <p>
            <label style="display:block;font-weight:600;"><?php _e('Find', 'tc-data-tables'); ?></label>
            <input type="text" class="gt-fr-find regular-text">
        </p>
        <p>
            <label style="display:block;font-weight:600;"><?php _e('Replace with', 'tc-data-tables'); ?></label>
            <input type="text" class="gt-fr-replace regular-text">
        </p>
        <p>
            <label style="display:block;font-weight:600;"><?php _e('Columns (leave empty for all text columns)', 'tc-data-tables'); ?></label>
            <select class="gt-fr-columns" multiple style="min-width:260px;min-height:70px;"></select>
        </p>
        <p>
            <label><input type="checkbox" class="gt-fr-case"> <?php _e('Case sensitive', 'tc-data-tables'); ?></label>
            &nbsp;&nbsp;
            <label><input type="checkbox" class="gt-fr-whole"> <?php _e('Whole cell only', 'tc-data-tables'); ?></label>
        </p>
        <p class="description"><span class="gt-fr-count"></span></p>
        <p>
            <button type="button" class="button button-primary gt-fr-apply" disabled><?php _e('Replace all', 'tc-data-tables'); ?></button>
            <button type="button" class="button gt-fr-cancel"><?php _e('Cancel', 'tc-data-tables'); ?></button>
        </p>
    </div>

    <?php
    // Slice 2b — Import bundle form. Hidden inside <details> so it's
    // invocable via the "Import bundle" button but doesn't clutter the
    // page when not in use. Read the result transient pattern from
    // TC_Import for the post-redirect notice.
    if (class_exists('TC_Bulk_Migration_Bundle_Service')):
        $gt_bundle_result_key = isset($_GET['gt_bundle_import_result']) ? sanitize_key($_GET['gt_bundle_import_result']) : '';
        $gt_bundle_result = '';
        if ($gt_bundle_result_key !== '') {
            $gt_bundle_result = get_transient('gt_bundle_import_result_' . $gt_bundle_result_key);
            if ($gt_bundle_result !== false) {
                delete_transient('gt_bundle_import_result_' . $gt_bundle_result_key);
            }
        }
        if (is_array($gt_bundle_result) && !empty($gt_bundle_result)):
            $notice_class = !empty($gt_bundle_result['error']) ? 'notice-error' : 'notice-success';
        ?>
        <div class="notice <?php echo esc_attr($notice_class); ?> is-dismissible">
            <p><?php echo esc_html($gt_bundle_result['message'] ?? ''); ?></p>
            <?php if (!empty($gt_bundle_result['summary']) && is_array($gt_bundle_result['summary'])): ?>
            <p>
                <?php foreach ($gt_bundle_result['summary'] as $action => $count): ?>
                    <strong><?php echo esc_html(ucfirst((string) $action)); ?>:</strong> <?php echo (int) $count; ?>&nbsp;&nbsp;
                <?php endforeach; ?>
            </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <details id="gt-bundle-import" style="margin:12px 0;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px;">
        <summary style="font-weight:600;cursor:pointer;"><?php _e('Import bundle from JSON', 'tc-data-tables'); ?></summary>
        <p class="description" style="margin-top:8px;"><?php _e('Upload a JSON bundle previously exported from this or another TableCrafter site. Each table is processed individually with the conflict-resolution policy you select below.', 'tc-data-tables'); ?></p>
        <?php // #1602 — secret-scrub disclaimer so importers know to re-enter credentials. ?>
        <p class="description gt-bundle-secret-note" style="margin-top:4px;"><?php _e('Credentials are never included in bundles: API keys, Airtable/Notion tokens, webhook URLs, notification emails and similar secrets are stripped on export. After importing on a new site, reconfigure those credentials on each table that uses an integration.', 'tc-data-tables'); ?></p>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;">
            <?php wp_nonce_field('gt_bundle_import', 'gt_bundle_import_nonce'); ?>
            <input type="hidden" name="action" value="gt_bundle_import">
            <p>
                <label for="gt-bundle-file"><strong><?php _e('Bundle file (.json)', 'tc-data-tables'); ?></strong></label><br>
                <input type="file" id="gt-bundle-file" name="bundle_file" accept=".json,application/json" required>
            </p>
            <p>
                <label for="gt-bundle-policy"><strong><?php _e('Conflict resolution policy', 'tc-data-tables'); ?></strong></label><br>
                <select id="gt-bundle-policy" name="conflict_policy">
                    <option value="skip"><?php _e('Skip — keep existing tables, only create new ones', 'tc-data-tables'); ?></option>
                    <option value="overwrite"><?php _e('Overwrite — replace existing tables with bundle versions', 'tc-data-tables'); ?></option>
                    <option value="create_as_new"><?php _e('Create as new — always insert; never overwrite', 'tc-data-tables'); ?></option>
                </select>
                <span class="description" style="display:block;margin-top:4px;"><?php _e('"Existing" matches by id (within this site). For cross-site migration, prefer "Create as new" to avoid id collisions.', 'tc-data-tables'); ?></span>
            </p>
            <?php submit_button(__('Import bundle', 'tc-data-tables'), 'primary', 'submit', false); ?>
        </form>
    </details>
    <?php endif; ?>
    
    <?php
    // Show error message if redirected due to limit
    if (isset($_GET['error']) && $_GET['error'] === 'limit_reached'):
    ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <strong><?php _e('Free Plan Limit Reached', 'tc-data-tables'); ?></strong><br>
                <?php printf(
                    __('You have reached the maximum of %d tables allowed in the free plan. Please upgrade to Pro to create unlimited tables.', 'tc-data-tables'),
                    TC_FREE_MAX_TABLES
                ); ?>
            </p>
        </div>
    <?php endif; ?>
    
    <?php if (gt_is_free_plan()):
        $tc_has_fs      = function_exists('wgt_fs') && wgt_fs();
        $tc_can_trial   = $tc_has_fs && !wgt_fs()->is_trial() && !wgt_fs()->is_trial_utilized();
        $tc_cta_url     = $tc_can_trial ? wgt_fs()->get_trial_url() : ($tc_has_fs ? wgt_fs()->get_upgrade_url() : '#');
        $tc_cta_label   = $tc_can_trial ? __('Start 7-Day Free Trial', 'tc-data-tables') : __('Upgrade to Pro', 'tc-data-tables');
        $tc_license_url = admin_url('admin.php?page=gravity-tables-license');
    ?>
    <div class="tc-upgrade-card">
        <div class="tc-upgrade-free">
            <span class="tc-badge-free"><?php _e('FREE PLAN', 'tc-data-tables'); ?></span>
            <h3 class="tc-upgrade-h"><?php _e('You\'re on Free — and it\'s genuinely generous', 'tc-data-tables'); ?></h3>
            <p class="tc-upgrade-sub"><?php _e('Unlimited tables, columns &amp; rows from JSON, CSV, Google Sheets &amp; Excel — with search, sort, pagination &amp; export.', 'tc-data-tables'); ?></p>
        </div>
        <div class="tc-upgrade-pro">
            <div class="tc-pro-title"><?php _e('Do more with', 'tc-data-tables'); ?> <span class="tc-pro-word">PRO</span></div>
            <ul class="tc-pro-feats">
                <li><span>⚡</span><?php _e('Frontend inline editing', 'tc-data-tables'); ?></li>
                <li><span>💪</span><?php _e('Bulk operations &amp; column fill', 'tc-data-tables'); ?></li>
                <li><span>🎛️</span><?php _e('Advanced filters &amp; formatting', 'tc-data-tables'); ?></li>
                <li><span>🔌</span><?php _e('Gravity Forms, WooCommerce, Airtable &amp; Notion', 'tc-data-tables'); ?></li>
            </ul>
            <div class="tc-cta-row">
                <a href="<?php echo esc_url($tc_cta_url); ?>" class="tc-btn-pro"><?php echo esc_html($tc_cta_label); ?> &rarr;</a>
                <a href="<?php echo esc_url($tc_license_url); ?>" class="tc-btn-ghost"><?php _e('Enter License Key', 'tc-data-tables'); ?></a>
            </div>
            <?php if ($tc_can_trial): ?>
            <p class="tc-cta-fine"><?php _e('No payment required for the trial · cancel anytime', 'tc-data-tables'); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <style>
        .tc-upgrade-card{display:flex;flex-wrap:wrap;margin:20px 0;background:#fff;border:1px solid #e5e3dd;border-radius:14px;overflow:hidden;box-shadow:0 4px 18px rgba(13,148,136,.08)}
        .tc-upgrade-free{flex:1 1 320px;padding:22px 26px;position:relative}
        .tc-upgrade-free::before{content:"";position:absolute;left:0;top:0;bottom:0;width:5px;background:linear-gradient(180deg,#2dd4bf,#0d9488)}
        .tc-badge-free{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.08em;color:#0d9488;background:#f0fdfa;border:1px solid #99f6e4;border-radius:999px;padding:3px 10px}
        .tc-upgrade-h{margin:12px 0 6px;font-size:18px;line-height:1.3;color:#141312}
        .tc-upgrade-sub{margin:0;color:#6b6560;font-size:13.5px;line-height:1.6;max-width:48ch}
        .tc-upgrade-pro{flex:1 1 340px;padding:22px 26px;background:linear-gradient(135deg,#f0fdfa 0%,#fdfdfb 100%);border-left:1px solid #e5e3dd}
        .tc-pro-title{font-size:15px;font-weight:600;color:#141312;margin-bottom:12px}
        .tc-pro-word{display:inline-block;font-size:12px;font-weight:800;letter-spacing:.06em;color:#fff;background:linear-gradient(135deg,#14b8a6,#0d9488);border-radius:6px;padding:2px 8px;vertical-align:middle}
        .tc-pro-feats{margin:0 0 16px;padding:0;list-style:none;display:grid;grid-template-columns:1fr 1fr;gap:9px 16px}
        .tc-pro-feats li{display:flex;align-items:center;gap:8px;font-size:13px;color:#2b2926;margin:0}
        .tc-pro-feats li span{font-size:15px;line-height:1}
        .tc-cta-row{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
        .tc-btn-pro{display:inline-block;background:linear-gradient(135deg,#14b8a6,#0d9488);color:#fff!important;text-decoration:none;font-weight:600;font-size:14px;padding:10px 20px;border-radius:9px;box-shadow:0 2px 10px rgba(13,148,136,.35);transition:transform .12s,box-shadow .12s}
        .tc-btn-pro:hover{transform:translateY(-1px);box-shadow:0 5px 16px rgba(13,148,136,.45);color:#fff!important}
        .tc-btn-ghost{display:inline-block;color:#0d9488!important;text-decoration:none;font-weight:600;font-size:14px;padding:9px 16px;border:1.5px solid #5eead4;border-radius:9px;transition:background .12s,border-color .12s}
        .tc-btn-ghost:hover{background:#f0fdfa;border-color:#14b8a6}
        .tc-cta-fine{margin:12px 0 0;font-size:11.5px;color:#9a948c}
        @media(max-width:782px){.tc-upgrade-pro{border-left:none;border-top:1px solid #e5e3dd}.tc-pro-feats{grid-template-columns:1fr}}
    </style>
    <?php endif; ?>
    
    <div id="gt-tables-list" class="gt-admin-container">
        <?php if (empty($tables)): ?>
            <div class="gt-no-tables">
                <p><?php _e('No tables have been created yet.', 'tc-data-tables'); ?></p>
                <a href="<?php echo admin_url('admin.php?page=gravity-tables-new'); ?>" class="button button-primary"><?php _e('Create Your First Table', 'tc-data-tables'); ?></a>
            </div>
        <?php else: ?>
            <div class="gt-responsive-table-wrap">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col" class="manage-column column-title column-primary"><?php _e('Title', 'tc-data-tables'); ?></th>
                        <th scope="col" class="manage-column column-source"><?php _e('Source', 'tc-data-tables'); ?></th>
                        <th scope="col" class="manage-column column-shortcode"><?php _e('Shortcode', 'tc-data-tables'); ?></th>
                        <th scope="col" class="manage-column column-date"><?php _e('Last Modified', 'tc-data-tables'); ?></th>
                        <th scope="col" class="manage-column column-used-in"><?php _e('Used in', 'tc-data-tables'); ?></th>
                        <th scope="col" class="manage-column column-actions"><?php _e('Actions', 'tc-data-tables'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Load migration tool to detect legacy tables.
                    if (!class_exists('TC_Migration_Tool')) {
                        $mt_path = defined('TC_PLUGIN_PATH') ? TC_PLUGIN_PATH . 'includes/class-tc-migration-tool.php' : '';
                        if ($mt_path && file_exists($mt_path)) {
                            require_once $mt_path;
                        }
                    }
                    // #2271 — compact display labels for the Source column,
                    // resolved once for the whole list.
                    $gt_source_labels = class_exists('TC_Data_Integrity_Guard')
                        ? TC_Data_Integrity_Guard::source_labels()
                        : array();
                    ?>
                    <?php foreach ($tables as $table): ?>
                        <?php
                        $table_settings = maybe_unserialize($table->settings ?? '');
                        $is_legacy = class_exists('TC_Migration_Tool') && is_array($table_settings) && TC_Migration_Tool::is_legacy_table($table_settings);
                        ?>
                        <tr<?php echo $is_legacy ? ' class="gt-legacy-row"' : ''; ?>>
                            <td class="title column-title has-row-actions column-primary" data-colname="<?php _e('Title', 'tc-data-tables'); ?>">
                                <strong>
                                    <a href="<?php echo admin_url('admin.php?page=gravity-tables-new&id=' . $table->id); ?>">
                                        <?php echo esc_html($table->title); ?>
                                    </a>
                                    <?php if ($is_legacy): ?>
                                    <span class="gt-legacy-badge" style="background:#e65100;color:#fff;border-radius:3px;font-size:10px;padding:2px 6px;margin-left:6px;font-weight:600;vertical-align:middle"><?php _e('Legacy', 'tc-data-tables'); ?></span>
                                    <?php endif; ?>
                                </strong>
                                <div class="row-actions">
                                    <span class="edit">
                                        <a href="<?php echo admin_url('admin.php?page=gravity-tables-new&id=' . $table->id); ?>">
                                            <?php _e('Edit', 'tc-data-tables'); ?>
                                        </a>
                                    </span>
                                    <?php if ($is_legacy): ?>
                                    |
                                    <span class="migrate">
                                        <a href="#" class="gt-migrate-table"
                                           data-table-id="<?php echo absint($table->id); ?>"
                                           data-nonce="<?php echo esc_attr(wp_create_nonce('gravity_tables_nonce')); ?>">
                                            <?php _e('Migrate to current format', 'tc-data-tables'); ?>
                                        </a>
                                    </span>
                                    <?php endif; ?>
                                    |
                                    <span class="find-replace">
                                        <?php
                                        // #1614 — column scope for the Find/Replace modal.
                                        $gt_fr_settings = json_decode((string) ($table->settings ?? ''), true);
                                        $gt_fr_cols = array();
                                        if (is_array($gt_fr_settings)) {
                                            $gt_fr_labels = isset($gt_fr_settings['column_labels']) && is_array($gt_fr_settings['column_labels']) ? $gt_fr_settings['column_labels'] : array();
                                            $gt_fr_field_ids = (array) ($gt_fr_settings['selected_fields'] ?? ($gt_fr_settings['columns'] ?? array()));
                                            foreach ($gt_fr_field_ids as $gt_fr_fid) {
                                                $gt_fr_fid = (string) $gt_fr_fid;
                                                $gt_fr_cols[] = array(
                                                    'id'    => $gt_fr_fid,
                                                    'label' => isset($gt_fr_labels[$gt_fr_fid]) && $gt_fr_labels[$gt_fr_fid] !== '' ? (string) $gt_fr_labels[$gt_fr_fid] : $gt_fr_fid,
                                                );
                                            }
                                        }
                                        ?>
                                        <a href="#" class="gt-find-replace"
                                           data-table-id="<?php echo absint($table->id); ?>"
                                           data-nonce="<?php echo esc_attr(wp_create_nonce('gravity_tables_nonce')); ?>"
                                           data-columns="<?php echo esc_attr(wp_json_encode($gt_fr_cols)); ?>"
                                           title="<?php esc_attr_e('Find and replace cell values across this table', 'tc-data-tables'); ?>">
                                            <?php _e('Find / Replace', 'tc-data-tables'); ?>
                                        </a>
                                    </span>
                                    |
                                    <span class="revisions">
                                        <a href="#" class="gt-revisions"
                                           data-table-id="<?php echo absint($table->id); ?>"
                                           data-restore-nonce="<?php echo esc_attr(wp_create_nonce('gt_action_restore_revision')); ?>"
                                           data-list-nonce="<?php echo esc_attr(wp_create_nonce('gt_list_revisions')); ?>"
                                           title="<?php esc_attr_e('View and restore previous saved versions of this table', 'tc-data-tables'); ?>">
                                            <?php _e('Revisions', 'tc-data-tables'); ?>
                                        </a>
                                    </span>
                                    |
                                    <span class="duplicate">
                                        <a href="#" class="gt-duplicate-table"
                                           data-table-id="<?php echo absint($table->id); ?>"
                                           data-nonce="<?php echo esc_attr(wp_create_nonce('gt_admin_nonce')); ?>"
                                           title="<?php esc_attr_e('Clone this table configuration into a new copy', 'tc-data-tables'); ?>">
                                            <?php _e('Duplicate', 'tc-data-tables'); ?>
                                        </a>
                                    </span>
                                    |
                                    <span class="trash">
                                        <a href="#" class="gt-delete-table" data-table-id="<?php echo $table->id; ?>" data-nonce="<?php echo wp_create_nonce('gt_admin_nonce'); ?>">
                                            <?php _e('Delete', 'tc-data-tables'); ?>
                                        </a>
                                    </span>
                                </div>
                                <?php
                                // #538 slice 3 — minimal Find / Replace dialog driven by
                                // browser prompts. Fancier modal can come later. For each
                                // table, when the user clicks Find / Replace:
                                //   1. prompt for find text
                                //   2. fetch wp_ajax_gt_find_matches → show count, ask to proceed
                                //   3. prompt for replacement, confirm, fire wp_ajax_gt_apply_replace
                                //   4. show count of entries updated
                                ?>
                                <button type="button" class="toggle-row" aria-expanded="false"><span class="screen-reader-text"><?php _e('Show more details', 'tc-data-tables'); ?></span></button>
                            </td>
                            <td class="source column-source" data-colname="<?php esc_attr_e('Source', 'tc-data-tables'); ?>">
                                <?php
                                // #2271 — show the table's data source instead of the old
                                // "Gravity Form" column, which rendered a meaningless
                                // numeric form-id fallback for every non-GF table.
                                // Settings are stored as JSON; an absent data_source_type
                                // means Gravity Forms (pre-convergence tables never wrote
                                // the key).
                                $gt_src_settings = json_decode((string) ($table->settings ?? ''), true);
                                $gt_source_key   = (is_array($gt_src_settings) && !empty($gt_src_settings['data_source_type']))
                                    ? (string) $gt_src_settings['data_source_type']
                                    : 'gravity_forms';
                                $gt_source_label = isset($gt_source_labels[$gt_source_key])
                                    ? $gt_source_labels[$gt_source_key]
                                    : ucwords(str_replace('_', ' ', $gt_source_key));
                                echo esc_html($gt_source_label);
                                // For GF-source tables the form title is the useful
                                // detail — keep it visible next to the source name.
                                if ($gt_source_key === 'gravity_forms' && isset($form_titles[$table->form_id])) {
                                    echo ': ' . esc_html($form_titles[$table->form_id]);
                                }
                                ?>
                            </td>
                            <td class="shortcode column-shortcode" data-colname="<?php _e('Shortcode', 'tc-data-tables'); ?>">
                                <code><?php echo esc_html($table->shortcode); ?></code>
                                <button type="button" class="button-link gt-copy-shortcode" data-clipboard-text="<?php echo esc_attr($table->shortcode); ?>">
                                    <?php _e('Copy', 'tc-data-tables'); ?>
                                </button>
                                <?php
                                // #2133 — embeddable public table: offer the <iframe> snippet so
                                // the table can be embedded on any external site.
                                if (class_exists('TC_Embed')) :
                                    $gt_embed_code = TC_Embed::embed_code($table->id);
                                ?>
                                    <br>
                                    <button type="button" class="button-link gt-copy-shortcode" data-clipboard-text="<?php echo esc_attr($gt_embed_code); ?>" title="<?php esc_attr_e('Copy an <iframe> snippet to embed this table on another site', 'tc-data-tables'); ?>">
                                        <?php _e('Copy embed code', 'tc-data-tables'); ?>
                                    </button>
                                <?php endif; ?>
                            </td>
                            <td class="date column-date" data-colname="<?php _e('Last Modified', 'tc-data-tables'); ?>">
                                <?php echo date_i18n(get_option('date_format'), strtotime($table->updated_at)); ?>
                            </td>
                            <td class="used-in column-used-in" data-colname="<?php _e('Used in', 'tc-data-tables'); ?>">
                                <?php
                                // #542 slice 1: surface "where am I being used" so admins can clean up
                                // unused tables without fear. On-render WP_Query is fine for the typical
                                // 5-50-table install; a save-time indexer comes in slice 2 once scale matters.
                                if (!class_exists('TC_Where_Used_Service')) {
                                    $svc_path = defined('TC_PLUGIN_PATH') ? TC_PLUGIN_PATH . 'includes/services/class-tc-where-used-service.php' : '';
                                    if ($svc_path && file_exists($svc_path)) {
                                        require_once $svc_path;
                                    }
                                }
                                $usages = class_exists('TC_Where_Used_Service')
                                    ? TC_Where_Used_Service::find_usages((int) $table->id)
                                    : [];
                                $usage_count = count($usages);
                                if ($usage_count === 0) {
                                    echo '<span class="gt-usage-empty" style="color:#888">' . esc_html__('Not used yet', 'tc-data-tables') . '</span>';
                                } else {
                                    printf(
                                        '<details class="gt-usage-details"><summary><strong>%s</strong></summary><ul style="margin:6px 0 0;padding-left:18px">',
                                        esc_html(sprintf(
                                            _n('%d post', '%d posts', $usage_count, 'tc-data-tables'),
                                            $usage_count
                                        ))
                                    );
                                    foreach ($usages as $u) {
                                        $title  = $u['title'] !== '' ? $u['title'] : sprintf(__('(no title) #%d', 'tc-data-tables'), (int) $u['post_id']);
                                        $status = $u['post_status'] !== 'publish' ? ' <em style="color:#888">[' . esc_html($u['post_status']) . ']</em>' : '';
                                        // Slice 1.1 of #542: show post_type so two same-titled entries
                                        // (e.g. a post and a page both called "Pricing") are
                                        // distinguishable before the user clicks through.
                                        $type = !empty($u['post_type'])
                                            ? ' <span style="color:#666;font-size:11px">(' . esc_html((string) $u['post_type']) . ')</span>'
                                            : '';
                                        $edit_link = !empty($u['edit_url'])
                                            ? sprintf(' <a href="%s" style="font-size:11px">%s</a>', esc_url((string) $u['edit_url']), esc_html__('Edit', 'tc-data-tables'))
                                            : '';
                                        $view_link = !empty($u['view_url'])
                                            ? sprintf(' <a href="%s" target="_blank" rel="noopener noreferrer" style="font-size:11px" title="%s">%s ↗</a>', esc_url((string) $u['view_url']), esc_attr__('View on front end', 'tc-data-tables'), esc_html__('View', 'tc-data-tables'))
                                            : '';
                                        printf(
                                            '<li>%s%s%s%s%s</li>',
                                            esc_html($title),
                                            $type,
                                            $status,
                                            $edit_link,
                                            $view_link
                                        );
                                    }
                                    echo '</ul></details>';
                                }
                                ?>
                            </td>
                            <td class="actions column-actions" data-colname="<?php _e('Actions', 'tc-data-tables'); ?>">
                                <a href="<?php echo admin_url('admin.php?page=gravity-tables-new&id=' . $table->id); ?>" class="button button-small">
                                    <?php _e('Edit', 'tc-data-tables'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div><!-- .gt-responsive-table-wrap -->
        <?php endif; ?>
    </div>
</div>

