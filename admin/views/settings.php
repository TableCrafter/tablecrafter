<?php
/**
 * Settings View
 *
 * @package GravityTables
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get current settings
$settings = get_option('gt_settings', array());
?>

<div class="wrap">
    <h1><?php _e('TableCrafter Settings', 'tc-data-tables'); ?></h1>
    
    <form method="post" action="options.php">
        <?php settings_fields('gt_settings'); ?>
        <?php do_settings_sections('gt_settings'); ?>
        
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Default Entries Per Page', 'tc-data-tables'); ?></th>
                <td>
                    <input type="number" name="gt_settings[default_per_page]" 
                           value="<?php echo isset($settings['default_per_page']) ? esc_attr($settings['default_per_page']) : '25'; ?>" 
                           min="1" max="1000" class="small-text">
                    <p class="description"><?php _e('Number of entries to display per page by default.', 'tc-data-tables'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><?php _e('Frontend Editing', 'tc-data-tables'); ?> <?php if (!gt_is_premium()): ?><span style="color: #0073aa; font-weight: bold;">(Pro)</span><?php endif; ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="gt_settings[enable_frontend_editing]" value="1" 
                               <?php checked(isset($settings['enable_frontend_editing']) ? $settings['enable_frontend_editing'] : true); ?>
                               <?php if (!gt_is_premium()): ?>disabled<?php endif; ?>>
                        <?php _e('Enable frontend editing for tables', 'tc-data-tables'); ?>
                    </label>
                    <p class="description">
                        <?php _e('Allow users to edit entries directly from the frontend table view.', 'tc-data-tables'); ?>
                        <?php if (!gt_is_premium()): ?>
                            <br><strong style="color: #d63638;"><?php _e('This is a Pro feature.', 'tc-data-tables'); ?> 
                            <?php if (function_exists('wgt_fs') && !wgt_fs()->is_trial() && !wgt_fs()->is_trial_utilized()): ?>
                                <a href="<?php echo wgt_fs()->get_trial_url(); ?>" style="color: #00a32a;"><?php _e('Start Free Trial', 'tc-data-tables'); ?></a>, 
                                <a href="<?php echo admin_url('admin.php?page=gravity-tables-license'); ?>"><?php _e('Enter License Key', 'tc-data-tables'); ?></a>, or 
                                <a href="<?php echo wgt_fs()->get_upgrade_url(); ?>"><?php _e('Upgrade to Pro', 'tc-data-tables'); ?></a>
                            <?php else: ?>
                                <a href="<?php echo admin_url('admin.php?page=gravity-tables-license'); ?>"><?php _e('Enter License Key', 'tc-data-tables'); ?></a> or 
                                <a href="<?php echo function_exists('wgt_fs') ? wgt_fs()->get_upgrade_url() : '#'; ?>"><?php _e('Upgrade to Pro', 'tc-data-tables'); ?></a>
                            <?php endif; ?>
                            </strong>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><?php _e('Delete Functionality', 'tc-data-tables'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="gt_settings[enable_delete]" value="1" 
                               <?php checked(isset($settings['enable_delete']) ? $settings['enable_delete'] : false); ?>>
                        <?php _e('Enable delete functionality for tables', 'tc-data-tables'); ?>
                    </label>
                    <p class="description"><?php _e('Allow users to permanently delete entries from tables. <strong>Warning:</strong> Deleted entries cannot be recovered.', 'tc-data-tables'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><?php _e('Bulk Actions', 'tc-data-tables'); ?> <?php if (!gt_is_premium()): ?><span style="color: #0073aa; font-weight: bold;">(Pro)</span><?php endif; ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="gt_settings[enable_bulk_actions]" value="1" 
                               <?php checked(isset($settings['enable_bulk_actions']) ? $settings['enable_bulk_actions'] : true); ?>
                               <?php if (!gt_is_premium()): ?>disabled<?php endif; ?>>
                        <?php _e('Enable bulk actions for tables', 'tc-data-tables'); ?>
                    </label>
                    <p class="description">
                        <?php _e('Allow users to perform bulk operations on table entries.', 'tc-data-tables'); ?>
                        <?php if (!gt_is_premium()): ?>
                            <br><strong style="color: #d63638;"><?php _e('This is a Pro feature.', 'tc-data-tables'); ?> 
                            <?php if (function_exists('wgt_fs') && !wgt_fs()->is_trial() && !wgt_fs()->is_trial_utilized()): ?>
                                <a href="<?php echo wgt_fs()->get_trial_url(); ?>" style="color: #00a32a;"><?php _e('Start Free Trial', 'tc-data-tables'); ?></a>, 
                                <a href="<?php echo admin_url('admin.php?page=gravity-tables-license'); ?>"><?php _e('Enter License Key', 'tc-data-tables'); ?></a>, or 
                                <a href="<?php echo wgt_fs()->get_upgrade_url(); ?>"><?php _e('Upgrade to Pro', 'tc-data-tables'); ?></a>
                            <?php else: ?>
                                <a href="<?php echo admin_url('admin.php?page=gravity-tables-license'); ?>"><?php _e('Enter License Key', 'tc-data-tables'); ?></a> or 
                                <a href="<?php echo function_exists('wgt_fs') ? wgt_fs()->get_upgrade_url() : '#'; ?>"><?php _e('Upgrade to Pro', 'tc-data-tables'); ?></a>
                            <?php endif; ?>
                            </strong>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><?php _e('Advanced Filters', 'tc-data-tables'); ?> <?php if (!gt_is_premium()): ?><span style="color: #0073aa; font-weight: bold;">(Pro)</span><?php endif; ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="gt_settings[enable_advanced_filters]" value="1" 
                               <?php checked(isset($settings['enable_advanced_filters']) ? $settings['enable_advanced_filters'] : true); ?>
                               <?php if (!gt_is_premium()): ?>disabled<?php endif; ?>>
                        <?php _e('Enable advanced filtering options', 'tc-data-tables'); ?>
                    </label>
                    <p class="description">
                        <?php _e('Show advanced filtering controls in table views.', 'tc-data-tables'); ?>
                        <?php if (!gt_is_premium()): ?>
                            <br><strong style="color: #d63638;"><?php _e('This is a Pro feature.', 'tc-data-tables'); ?> 
                            <?php if (function_exists('wgt_fs') && !wgt_fs()->is_trial() && !wgt_fs()->is_trial_utilized()): ?>
                                <a href="<?php echo wgt_fs()->get_trial_url(); ?>" style="color: #00a32a;"><?php _e('Start Free Trial', 'tc-data-tables'); ?></a>, 
                                <a href="<?php echo admin_url('admin.php?page=gravity-tables-license'); ?>"><?php _e('Enter License Key', 'tc-data-tables'); ?></a>, or 
                                <a href="<?php echo wgt_fs()->get_upgrade_url(); ?>"><?php _e('Upgrade to Pro', 'tc-data-tables'); ?></a>
                            <?php else: ?>
                                <a href="<?php echo admin_url('admin.php?page=gravity-tables-license'); ?>"><?php _e('Enter License Key', 'tc-data-tables'); ?></a> or 
                                <a href="<?php echo function_exists('wgt_fs') ? wgt_fs()->get_upgrade_url() : '#'; ?>"><?php _e('Upgrade to Pro', 'tc-data-tables'); ?></a>
                            <?php endif; ?>
                            </strong>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><?php _e('Date Format', 'tc-data-tables'); ?></th>
                <td>
                    <input type="text" name="gt_settings[date_format]" 
                           value="<?php echo isset($settings['date_format']) ? esc_attr($settings['date_format']) : 'm/d/Y'; ?>" 
                           class="regular-text">
                    <p class="description">
                        <?php _e('Default date format for table displays. Uses PHP date format.', 'tc-data-tables'); ?>
                        <br>
                        <span style="display: inline-block; margin-top: 8px; padding: 8px 12px; background: #0073aa; border: 1px solid #005a87; border-radius: 3px; box-shadow: 0 1px 0 #ccc;">
                            <a href="https://fahdmurtaza.com/datecoder" target="_blank" rel="noopener noreferrer" style="color: white; text-decoration: none; font-weight: 500; display: flex; align-items: center; gap: 6px; font-size: 13px;">
                                <span style="font-size: 14px;">📅</span>
                                <span><?php _e('Use DateCoder Tool', 'tc-data-tables'); ?></span>
                            </a>
                        </span>
                        <br><small style="color: #666; margin-top: 4px; display: inline-block;"><?php _e('Interactive tool to easily build and test custom date formats', 'tc-data-tables'); ?></small>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><?php _e('Time Format', 'tc-data-tables'); ?></th>
                <td>
                    <input type="text" name="gt_settings[time_format]" 
                           value="<?php echo isset($settings['time_format']) ? esc_attr($settings['time_format']) : 'g:i A'; ?>" 
                           class="regular-text">
                    <p class="description">
                        <?php _e('Default time format for table displays. Uses PHP time format.', 'tc-data-tables'); ?>
                        <br>
                        <span style="display: inline-block; margin-top: 8px; padding: 8px 12px; background: #0073aa; border: 1px solid #005a87; border-radius: 3px; box-shadow: 0 1px 0 #ccc;">
                            <a href="https://fahdmurtaza.com/datecoder" target="_blank" rel="noopener noreferrer" style="color: white; text-decoration: none; font-weight: 500; display: flex; align-items: center; gap: 6px; font-size: 13px;">
                                <span style="font-size: 14px;">🕐</span>
                                <span><?php _e('Use DateCoder Tool', 'tc-data-tables'); ?></span>
                            </a>
                        </span>
                        <br><small style="color: #666; margin-top: 4px; display: inline-block;"><?php _e('Interactive tool to easily build and test custom time formats', 'tc-data-tables'); ?></small>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><?php _e('User Roles Allowed to Edit', 'tc-data-tables'); ?></th>
                <td>
                    <?php 
                    $editable_roles = wp_roles()->get_names();
                    $allowed_roles = isset($settings['user_roles_can_edit']) ? $settings['user_roles_can_edit'] : array('administrator', 'editor');
                    ?>
                    <fieldset>
                        <legend class="screen-reader-text"><?php _e('User Roles Allowed to Edit', 'tc-data-tables'); ?></legend>
                        <?php foreach ($editable_roles as $role_key => $role_name): ?>
                            <label>
                                <input type="checkbox" name="gt_settings[user_roles_can_edit][]" value="<?php echo esc_attr($role_key); ?>" 
                                       <?php checked(in_array($role_key, $allowed_roles)); ?>>
                                <?php echo esc_html($role_name); ?>
                            </label><br>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="description"><?php _e('Select which user roles can edit entries in frontend tables.', 'tc-data-tables'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><?php _e('CSS Framework', 'tc-data-tables'); ?></th>
                <td>
                    <select name="gt_settings[css_framework]">
                        <option value="default" <?php selected(isset($settings['css_framework']) ? $settings['css_framework'] : 'default', 'default'); ?>>
                            <?php _e('Default (Custom Styling)', 'tc-data-tables'); ?>
                        </option>
                        <option value="minimal" <?php selected(isset($settings['css_framework']) ? $settings['css_framework'] : 'default', 'minimal'); ?>>
                            <?php _e('Minimal (Basic table styles)', 'tc-data-tables'); ?>
                        </option>
                        <option value="none" <?php selected(isset($settings['css_framework']) ? $settings['css_framework'] : 'default', 'none'); ?>>
                            <?php _e('None (Use theme styles only)', 'tc-data-tables'); ?>
                        </option>
                    </select>
                    <p class="description"><?php _e('Wired through to asset enqueue as of v4.8.14 (#633 closed). Default loads the full frontend.css (~125 KB). Minimal loads frontend-minimal.css (~3.4 KB) - basic table styles only, intended for theme-styled sites. None registers the stylesheet handle with no source so the plugin contributes no CSS at all and only your theme styles apply.', 'tc-data-tables'); ?></p>
                </td>
            </tr>
        </table>
        
        <?php submit_button(); ?>
    </form>

    <!-- Performance: autoload management (#213) -->
    <div class="gt-settings-section" style="margin-top:30px">
        <h2><?php _e('Performance - Autoload Management', 'tc-data-tables'); ?></h2>
        <p><?php _e('WordPress loads all autoloaded options on every page request. TableCrafter options that store table data should not be autoloaded. Use this tool to check and fix your autoload footprint.', 'tc-data-tables'); ?></p>
        <?php
        $manager_path = defined('TC_PLUGIN_PATH') ? TC_PLUGIN_PATH . 'includes/class-tc-autoload-manager.php' : '';
        if ($manager_path && file_exists($manager_path) && !class_exists('TC_Autoload_Manager')) {
            require_once $manager_path;
        }
        $autoload_bytes = class_exists('TC_Autoload_Manager') ? TC_Autoload_Manager::get_autoload_stat() : 0;
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Autoloaded options size', 'tc-data-tables'); ?></th>
                <td>
                    <span id="gt-autoload-size"><?php echo esc_html(function_exists('size_format') ? size_format($autoload_bytes) : $autoload_bytes . ' B'); ?></span>
                    <p class="description"><?php _e('Total size of TableCrafter options in the WordPress autoload set. Ideally 0 B.', 'tc-data-tables'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Optimise autoload', 'tc-data-tables'); ?></th>
                <td>
                    <button type="button" id="gt-optimize-autoload" class="button">
                        <?php _e('Set all GT options to autoload = no', 'tc-data-tables'); ?>
                    </button>
                    <span id="gt-autoload-result" style="margin-left:10px"></span>
                    <script>
                    (function ($) {
                        $('#gt-optimize-autoload').on('click', function () {
                            var $btn    = $(this);
                            var $result = $('#gt-autoload-result');
                            $btn.prop('disabled', true).text('<?php echo esc_js(__('Optimising…', 'tc-data-tables')); ?>');
                            $.post(ajaxurl, {
                                action: 'gt_optimize_autoload',
                                nonce:  '<?php echo esc_js(wp_create_nonce('gravity_tables_nonce')); ?>'
                            }, function (response) {
                                $btn.prop('disabled', false).text('<?php echo esc_js(__('Set all GT options to autoload = no', 'tc-data-tables')); ?>');
                                if (response.success) {
                                    $result.css('color', 'green').text(response.data.message);
                                    $('#gt-autoload-size').text(response.data.autoload_size + ' B');
                                } else {
                                    $result.css('color', 'red').text(response.data);
                                }
                            });
                        });
                    }(jQuery));
                    </script>
                </td>
            </tr>
        </table>
    </div>
</div>