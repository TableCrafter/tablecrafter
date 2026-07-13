<?php
/**
 * #972 v4.161.0 - Trash admin tab (phase 1c of #593).
 *
 * Lists trashed tables with Restore + Delete Permanently action buttons.
 * Expects $trashed to be set by the controller (TC_Admin::admin_page_trash).
 *
 * @var array $trashed
 */
if (!defined('ABSPATH')) { exit; }

$ajax_url = admin_url('admin-ajax.php');
$nonce    = wp_create_nonce('gt_admin_nonce');

// #976 v4.163.0 - Retention window in days for the auto-purge countdown.
$retention_days = (int) apply_filters('gravity_tables_trash_retention_days', TC_TRASH_RETENTION_DAYS);
if ($retention_days < 1) {
    $retention_days = 1; // Sanity floor - a zero retention would mark everything as past-due immediately.
}
?>
<div class="wrap gt-trash-page">
    <h1><?php _e('Trash', 'tc-data-tables'); ?></h1>
    <p class="description">
        <?php _e('Tables you have deleted recently. Restore brings them back to the live list; Delete Permanently is irreversible.', 'tc-data-tables'); ?>
    </p>

    <?php if (empty($trashed)) : ?>
        <div class="notice notice-info inline">
            <p><?php _e('Trash is empty. Tables you delete will appear here.', 'tc-data-tables'); ?></p>
        </div>
    <?php else : ?>
        <p class="gt-trash-bulk-actions" style="margin: 12px 0;">
            <button type="button"
                    class="button button-link-delete gt-empty-trash-btn"
                    data-nonce="<?php echo esc_attr($nonce); ?>">
                <?php
                /* translators: %d is the number of items currently in trash. */
                printf(esc_html(_n('Empty Trash (%d item)', 'Empty Trash (%d items)', count($trashed), 'tc-data-tables')), count($trashed));
                ?>
            </button>
        </p>
        <table class="wp-list-table widefat fixed striped gt-trash-table">
            <thead>
                <tr>
                    <th><?php _e('Title', 'tc-data-tables'); ?></th>
                    <th><?php _e('Form ID', 'tc-data-tables'); ?></th>
                    <th><?php _e('Deleted', 'tc-data-tables'); ?></th>
                    <th><?php _e('Auto-purges', 'tc-data-tables'); ?></th>
                    <th><?php _e('Actions', 'tc-data-tables'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($trashed as $row) : ?>
                    <tr data-table-id="<?php echo esc_attr($row->id); ?>">
                        <td><strong><?php echo esc_html($row->title); ?></strong></td>
                        <td><?php echo esc_html($row->form_id); ?></td>
                        <td>
                            <?php
                            // Convert MySQL datetime to WP-formatted local time + a relative-time hint.
                            $ts = strtotime($row->deleted_at);
                            if ($ts) {
                                echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $ts));
                                echo '<br><small>' . esc_html(sprintf(__('%s ago', 'tc-data-tables'), human_time_diff($ts, current_time('timestamp')))) . '</small>';
                            } else {
                                echo '&mdash;';
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            // #976 v4.163.0 - Retention countdown. Compute purge_at = deleted_at + retention_days.
                            // current_time('timestamp') honours the site timezone the same way deleted_at was set.
                            if ($ts) {
                                $purge_ts = $ts + ($retention_days * DAY_IN_SECONDS);
                                $now      = current_time('timestamp');
                                $days_remaining = (int) ceil(($purge_ts - $now) / DAY_IN_SECONDS);
                                if ($days_remaining <= 0) {
                                    echo '<span class="gt-trash-past-due" style="color:#d63638;font-weight:600;">'
                                        . esc_html__('Past retention - purges on next run', 'tc-data-tables')
                                        . '</span>';
                                } else {
                                    echo esc_html(sprintf(
                                        /* translators: %d is the number of days until auto-purge. */
                                        _n('In %d day', 'In %d days', $days_remaining, 'tc-data-tables'),
                                        $days_remaining
                                    ));
                                }
                            } else {
                                echo '&mdash;';
                            }
                            ?>
                        </td>
                        <td>
                            <button type="button"
                                    class="button button-secondary gt-restore-btn"
                                    data-table-id="<?php echo esc_attr($row->id); ?>"
                                    data-nonce="<?php echo esc_attr($nonce); ?>">
                                <?php _e('Restore', 'tc-data-tables'); ?>
                            </button>
                            <button type="button"
                                    class="button button-link-delete gt-force-delete-btn"
                                    data-table-id="<?php echo esc_attr($row->id); ?>"
                                    data-nonce="<?php echo esc_attr($nonce); ?>">
                                <?php _e('Delete Permanently', 'tc-data-tables'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
jQuery(function ($) {
    'use strict';
    var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;

    function refreshRow($row) {
        // Fade + remove row after successful action; show "Trash is empty" notice when last row goes.
        $row.fadeOut(200, function () {
            $row.remove();
            if ($('.gt-trash-table tbody tr').length === 0) {
                $('.gt-trash-table').replaceWith(
                    '<div class="notice notice-info inline"><p><?php echo esc_js(__('Trash is empty. Tables you delete will appear here.', 'tc-data-tables')); ?></p></div>'
                );
            }
        });
    }

    $(document).on('click', '.gt-restore-btn', function () {
        var $btn = $(this);
        var $row = $btn.closest('tr');
        $btn.prop('disabled', true).text('<?php echo esc_js(__('Restoring...', 'tc-data-tables')); ?>');
        $.post(ajaxUrl, {
            action:   'gt_restore_table',
            table_id: $btn.data('table-id'),
            nonce:    $btn.data('nonce'),
        }).done(function (response) {
            if (response && response.success) {
                refreshRow($row);
            } else {
                $btn.prop('disabled', false).text('<?php echo esc_js(__('Restore', 'tc-data-tables')); ?>');
                alert((response && response.data) || '<?php echo esc_js(__('Failed to restore', 'tc-data-tables')); ?>');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('Restore', 'tc-data-tables')); ?>');
            alert('<?php echo esc_js(__('Network error', 'tc-data-tables')); ?>');
        });
    });

    $(document).on('click', '.gt-empty-trash-btn', function () {
        var $btn = $(this);
        var itemCount = $('.gt-trash-table tbody tr').length;
        var confirmMsg = '<?php echo esc_js(__('Permanently delete ALL items in trash? This cannot be undone.', 'tc-data-tables')); ?>';
        confirmMsg += '\n\n' + itemCount + ' item(s) will be lost.';
        if (!confirm(confirmMsg)) { return; }

        $btn.prop('disabled', true).text('<?php echo esc_js(__('Emptying...', 'tc-data-tables')); ?>');
        $.post(ajaxUrl, {
            action: 'gt_empty_trash',
            nonce:  $btn.data('nonce'),
        }).done(function (response) {
            if (response && response.success) {
                // Wipe every row + the bulk-action toolbar, then replace with the empty-state notice.
                $('.gt-trash-table').fadeOut(200, function () { $(this).remove(); });
                $('.gt-trash-bulk-actions').fadeOut(200, function () { $(this).remove(); });
                $('.gt-trash-page h1').after(
                    $('<div class="notice notice-success is-dismissible"><p>' + (response.data && response.data.message ? response.data.message : '<?php echo esc_js(__('Trash emptied', 'tc-data-tables')); ?>') + '</p></div>')
                );
                $('.gt-trash-page > .description').after(
                    '<div class="notice notice-info inline"><p><?php echo esc_js(__('Trash is empty. Tables you delete will appear here.', 'tc-data-tables')); ?></p></div>'
                );
            } else {
                $btn.prop('disabled', false).text('<?php echo esc_js(__('Empty Trash', 'tc-data-tables')); ?>');
                alert((response && response.data) || '<?php echo esc_js(__('Failed to empty trash', 'tc-data-tables')); ?>');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('Empty Trash', 'tc-data-tables')); ?>');
            alert('<?php echo esc_js(__('Network error', 'tc-data-tables')); ?>');
        });
    });

    $(document).on('click', '.gt-force-delete-btn', function () {
        if (!confirm('<?php echo esc_js(__('Permanently delete this table? This cannot be undone.', 'tc-data-tables')); ?>')) {
            return;
        }
        var $btn = $(this);
        var $row = $btn.closest('tr');
        $btn.prop('disabled', true).text('<?php echo esc_js(__('Deleting...', 'tc-data-tables')); ?>');
        $.post(ajaxUrl, {
            action:   'gt_force_delete_table',
            table_id: $btn.data('table-id'),
            nonce:    $btn.data('nonce'),
        }).done(function (response) {
            if (response && response.success) {
                refreshRow($row);
            } else {
                $btn.prop('disabled', false).text('<?php echo esc_js(__('Delete Permanently', 'tc-data-tables')); ?>');
                alert((response && response.data) || '<?php echo esc_js(__('Failed to delete', 'tc-data-tables')); ?>');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('Delete Permanently', 'tc-data-tables')); ?>');
            alert('<?php echo esc_js(__('Network error', 'tc-data-tables')); ?>');
        });
    });
});
</script>
