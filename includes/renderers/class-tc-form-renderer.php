<?php

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Form_Renderer
{
    private static ?TC_Form_Renderer $instance = null;

    public static function get_instance(): TC_Form_Renderer
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function render_form_html(int $form_id): string
    {
        // Start output buffering to capture form HTML
        ob_start();

        // Enqueue Gravity Forms scripts and styles
        wp_enqueue_script('gform_gravityforms');
        wp_enqueue_style('gform_basic');
        wp_enqueue_style('gform_theme');

        echo '<div class="gt-form-container">';

        try {
            // Enqueue scripts specifically for this form
            if (function_exists('gravity_form_enqueue_scripts')) {
                gravity_form_enqueue_scripts($form_id, true);
            }

            // Method 1: Use the standard gravity_form function
            if (function_exists('gravity_form')) {
                $form_string = gravity_form($form_id, false, false, false, null, true, 1, false);
                echo $form_string;
            }
            // Method 2: Direct GFFormDisplay call
            elseif (class_exists('GFFormDisplay')) {
                $form_string = GFFormDisplay::get_form($form_id, false, false, true, null, true, 1, false);
                echo $form_string;
            } else {
                throw new Exception('Neither GFFormDisplay nor gravity_form available');
            }

            // Check if anything was actually output
            $current_output = ob_get_contents();

            // If no substantial HTML output, create a manual fallback form
            if (strlen($current_output) < 200 || !strpos($current_output, '<form')) {
                ob_clean(); // Clear buffer
                $this->render_manual_fallback($form_id);
            }

        } catch (Exception $e) {
            echo '<p>Error loading form: ' . esc_html($e->getMessage()) . '</p>';
            echo '<p>Please try refreshing the page or contact support.</p>';
        }

        echo '</div>'; // close .gt-form-container

        // Auto-close script for backend popup
        $this->render_auto_close_script();

        return ob_get_clean();
    }

    private function render_manual_fallback(int $form_id): void
    {
        global $wpdb;

        if (!class_exists('GFAPI')) {
            echo '<p>Gravity Forms API not available.</p>';
            return;
        }

        $form = GFAPI::get_form($form_id);
        if (!$form || is_wp_error($form)) {
            echo '<p>Form not found.</p>';
            return;
        }

        // Get table configuration to check for lookup fields
        $table_config = null;
        $table_data = $wpdb->get_row($wpdb->prepare(
            "SELECT settings FROM {$wpdb->prefix}gravity_tables WHERE form_id = %d AND status = 'active' LIMIT 1",
            $form_id
        ));
        if ($table_data) {
            $table_config = json_decode($table_data->settings, true);
        }

        echo '<form method="post" id="gform_' . $form_id . '" class="gravity-form manual-fallback">';

        foreach ($form['fields'] as $field) {
            if ($field->type !== 'hidden') {
                echo '<div class="gfield">';
                echo '<label class="gfield_label" for="input_' . $form_id . '_' . $field->id . '">' . esc_html($field->label) . '</label>';
                $this->render_manual_field($field, $form_id, $table_config);
                echo '</div>';
            }
        }

        echo '<input type="hidden" name="gform_submit" value="' . $form_id . '" />';
        echo '<input type="submit" value="Submit" class="gform_button button" />';
        echo '</form>';
    }

    private function render_manual_field($field, $form_id, $table_config)
    {
        switch ($field->type) {
            case 'text':
            case 'email':
            case 'phone':
                echo '<input type="' . $field->type . '" name="input_' . $field->id . '" id="input_' . $form_id . '_' . $field->id . '" class="gfield_input" placeholder="' . esc_attr($field->placeholder ?? '') . '" />';
                break;

            case 'textarea':
                echo '<textarea name="input_' . $field->id . '" id="input_' . $form_id . '_' . $field->id . '" class="gfield_input" placeholder="' . esc_attr($field->placeholder ?? '') . '"></textarea>';
                break;

            case 'date':
                $date_format = 'm/d/Y';
                $placeholder = 'Select date (MM/DD/YYYY)';
                echo '<div class="gt-date-input-wrapper gt-modal-date-wrapper" data-date-format="' . esc_attr($date_format) . '">';
                echo '<input type="date" name="input_' . $field->id . '" id="input_' . $form_id . '_' . $field->id . '_html5" class="gt-date-html5" />';
                echo '<input type="text" id="input_' . $form_id . '_' . $field->id . '" class="gfield_input gt-date-display" placeholder="' . esc_attr($placeholder) . '" readonly />';
                echo '</div>';
                break;

            case 'number':
                $min = isset($field->rangeMin) ? ' min="' . esc_attr($field->rangeMin) . '"' : '';
                $max = isset($field->rangeMax) ? ' max="' . esc_attr($field->rangeMax) . '"' : '';
                $step = isset($field->numberFormat) && $field->numberFormat == 'decimal_comma' ? ' step="0.001"' : ' step="0.001"';
                echo '<input type="number" name="input_' . $field->id . '" id="input_' . $form_id . '_' . $field->id . '" class="gfield_input"' . $min . $max . $step . ' placeholder="' . esc_attr($field->placeholder ?? '') . '" />';
                break;

            case 'select':
                $this->render_select_field($field, $form_id, $table_config);
                break;

            case 'driver_selector':
                $this->render_driver_selector($field, $form_id);
                break;

            case 'radio':
                if (isset($field->choices) && is_array($field->choices)) {
                    echo '<div class="gfield_radio">';
                    foreach ($field->choices as $choice) {
                        echo '<label class="gfield_option">';
                        echo '<input type="radio" name="input_' . $field->id . '" value="' . esc_attr($choice['value']) . '" class="gfield_radio_input" />';
                        echo '<span>' . esc_html($choice['text']) . '</span>';
                        echo '</label>';
                    }
                    echo '</div>';
                }
                break;

            case 'checkbox':
                if (isset($field->choices) && is_array($field->choices)) {
                    echo '<div class="gfield_checkbox">';
                    foreach ($field->choices as $choice) {
                        echo '<label class="gfield_option">';
                        echo '<input type="checkbox" name="input_' . $field->id . '[]" value="' . esc_attr($choice['value']) . '" class="gfield_checkbox_input" />';
                        echo '<span>' . esc_html($choice['text']) . '</span>';
                        echo '</label>';
                    }
                    echo '</div>';
                }
                break;

            default:
                echo '<input type="text" name="input_' . $field->id . '" id="input_' . $form_id . '_' . $field->id . '" class="gfield_input" placeholder="' . esc_attr($field->placeholder ?? '') . '" />';
        }
    }

    private function render_select_field($field, $form_id, $table_config)
    {
        $is_lookup = false;
        $lookup_options = array();

        if ($table_config && isset($table_config['lookup_fields'][$field->id])) {
            $lookup_config = $table_config['lookup_fields'][$field->id];
            if ($lookup_config['type'] === 'user') {
                $is_lookup = true;
                $user_roles = $lookup_config['user_roles'] ?? array();
                $args = array('orderby' => 'display_name', 'order' => 'ASC');
                if (!empty($user_roles)) {
                    $args['role__in'] = $user_roles;
                }
                $users = get_users($args);
                foreach ($users as $user) {
                    $field_name = $lookup_config['user_field'] ?? 'display_name';
                    switch ($field_name) {
                        case 'display_name':
                            $label = $user->display_name;
                            break;
                        case 'user_login':
                            $label = $user->user_login;
                            break;
                        case 'user_email':
                            $label = $user->user_email;
                            break;
                        default:
                            $label = $user->display_name;
                    }
                    if (!empty($label)) {
                        $lookup_options[] = array('value' => $user->ID, 'text' => $label);
                    }
                }
            }
        }

        $select_class = $is_lookup ? 'gfield_input gt-lookup-dropdown' : 'gfield_input';
        echo '<select name="input_' . $field->id . '" id="input_' . $form_id . '_' . $field->id . '" class="' . $select_class . '">';
        echo '<option value="">' . esc_html($field->placeholder ?? 'Select an option') . '</option>';

        if ($is_lookup && !empty($lookup_options)) {
            foreach ($lookup_options as $option) {
                echo '<option value="' . esc_attr($option['value']) . '">' . esc_html($option['text']) . '</option>';
            }
        } elseif (isset($field->choices) && is_array($field->choices)) {
            foreach ($field->choices as $choice) {
                echo '<option value="' . esc_attr($choice['value']) . '">' . esc_html($choice['text']) . '</option>';
            }
        }
        echo '</select>';
    }

    private function render_driver_selector($field, $form_id)
    {
        echo '<select name="input_' . $field->id . '" id="input_' . $form_id . '_' . $field->id . '" class="gfield_input gt-lookup-dropdown" data-field-type="driver_selector">';
        echo '<option value="">Choose Driver</option>';

        $role_filter = array();
        if (isset($field->driverRoleFilter)) {
            $role_filter = is_string($field->driverRoleFilter) ? explode(',', $field->driverRoleFilter) : $field->driverRoleFilter;
        }

        $user_args = array('orderby' => 'display_name', 'order' => 'ASC');
        if (!empty($role_filter)) {
            $user_args['role__in'] = array_map('trim', $role_filter);
        } else {
            $driver_users = get_users(array('role' => 'driver', 'number' => 1));
            if (!empty($driver_users)) {
                $user_args['role'] = 'driver';
            } else {
                $user_args['exclude'] = array(1);
            }
        }

        $users = get_users($user_args);
        foreach ($users as $user) {
            $display_name = $user->display_name ?: ($user->first_name . ' ' . $user->last_name);
            if (empty(trim($display_name)))
                $display_name = $user->user_login;
            echo '<option value="' . esc_attr($user->ID) . '">' . esc_html($display_name) . '</option>';
        }
        echo '</select>';
    }

    private function render_auto_close_script()
    {
        ?>
        <script>
            jQuery(document).ready(function ($) {
                function checkAndClose() {
                    if ($('.sgb-success-message').length > 0) {
                        setTimeout(function () {
                            if (window.parent && window.parent.tb_remove) {
                                window.parent.tb_remove();
                                if (window.parent.jQuery) {
                                    window.parent.jQuery('.gt-apply-filters').trigger('click');
                                    window.parent.jQuery('.gt-table-wrapper').trigger('gt-refresh-needed');
                                }
                            }
                        }, 1500);
                    }
                }
                $(document).bind('gform_confirmation_loaded', function (event, formId) { checkAndClose(); });
                $(document).bind('gform_post_render', function (event, formId) { checkAndClose(); });
            });
        </script>
        <?php
    }
}
