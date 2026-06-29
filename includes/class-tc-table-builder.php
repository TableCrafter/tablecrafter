<?php
/**
 * Table Builder functionality for Gravity Tables
 * 
 * Provides the drag-and-drop table configuration interface.
 * Handles column configuration, field mapping, and preview generation.
 * 
 * Supports advanced features like lookup fields, custom filtering,
 * mobile responsiveness settings, and role-based permissions.
 *
 * @package GravityTables
 * @author Fahad Murtaza <business@isupercoder.com>
 * @since 1.0.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Table_Builder
{

    private static ?TC_Table_Builder $instance = null;

    public static function get_instance(): TC_Table_Builder
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Table builder is initialized when needed
    }

    /**
     * Generate table configuration from form fields
     */
    public function generate_config(int $form_id, array $settings = array()): array|false
    {
        if (!class_exists('GFAPI')) {
            return false;
        }

        $form = GFAPI::get_form($form_id);
        if (!$form || is_wp_error($form)) {
            return false;
        }

        $config = array(
            'form_id' => $form_id,
            'form_title' => $form['title'],
            'fields' => array(),
            'settings' => wp_parse_args($settings, $this->get_default_settings())
        );

        // Process form fields
        foreach ($form['fields'] as $field) {
            if (in_array($field->type, array('html', 'section', 'page'))) {
                continue;
            }

            $config['fields'][$field->id] = array(
                'id' => $field->id,
                'label' => $field->label,
                'admin_label' => $field->adminLabel ?: $field->label,
                'type' => $field->type,
                'required' => $field->isRequired,
                'choices' => $this->get_field_choices($field),
                'input_type' => $this->get_input_type($field),
                'css_class' => $field->cssClass,
                'width' => $this->get_field_width($field),
                'sortable' => $this->is_field_sortable($field),
                'editable' => $this->is_field_editable($field),
                'filterable' => $this->is_field_filterable($field),
                'upload_capable' => ($field->type === 'fileupload'),
            );
        }

        return $config;
    }

    /**
     * Get default table settings
     */
    public function get_default_settings(): array
    {
        return array(
            'per_page' => 25,
            'show_search' => true,
            'show_pagination' => true,
            'show_selection' => true,
            'show_bulk_actions' => true,
            'show_advanced_filters' => true,
            'show_entry_info' => true,
            'enable_frontend_editing' => false,
            'sticky_header' => false,
            'responsive_table' => true,
            'persistent_filters' => true,
            'bulk_actions' => array('delete', 'export'),
            'user_role_filter' => '',
            'date_format' => 'm/d/Y',
            'time_format' => 'g:i A',
            'css_class' => '',
            'table_style' => 'default',
            'responsive' => true,
            'stripe_rows' => true,
            'hover_effect' => true,
            'show_column_totals' => false
        );
    }

    /**
     * Get field choices for select/radio/checkbox fields
     */
    private function get_field_choices(object $field): array
    {
        $choices = array();

        if (isset($field->choices) && is_array($field->choices)) {
            foreach ($field->choices as $choice) {
                $choices[] = array(
                    'text' => $choice['text'],
                    'value' => $choice['value']
                );
            }
        }

        return $choices;
    }

    /**
     * Determine input type for frontend editing
     */
    private function get_input_type(object $field): string
    {
        switch ($field->type) {
            case 'text':
            case 'hidden':
                return 'text';
            case 'textarea':
                return 'textarea';
            case 'select':
                return 'select';
            case 'multiselect':
                return 'multiselect';
            case 'radio':
                return 'radio';
            case 'checkbox':
                return 'checkbox';
            case 'number':
                return 'number';
            case 'date':
                return 'date';
            case 'time':
                return 'time';
            case 'email':
                return 'email';
            case 'website':
                return 'url';
            case 'phone':
                return 'tel';
            default:
                return 'text';
        }
    }

    /**
     * Get field width
     */
    private function get_field_width(object $field): string
    {
        if (isset($field->size)) {
            switch ($field->size) {
                case 'small':
                    return '150px';
                case 'medium':
                    return '250px';
                case 'large':
                    return '400px';
                default:
                    return 'auto';
            }
        }

        return 'auto';
    }

    /**
     * Check if field is sortable by default
     */
    private function is_field_sortable(object $field): bool
    {
        // Make all fields sortable by default except for specific non-sortable types
        $non_sortable_types = array('html', 'section', 'page');
        return !in_array($field->type, $non_sortable_types);
    }

    /**
     * Check if field is editable by default
     */
    private function is_field_editable(object $field): bool
    {
        $non_editable_types = array('html', 'section', 'page', 'fileupload', 'post_image');
        return !in_array($field->type, $non_editable_types);
    }

    /**
     * Check if field is filterable by default
     */
    private function is_field_filterable(object $field): bool
    {
        $filterable_types = array('text', 'select', 'radio', 'checkbox', 'date', 'number');
        return in_array($field->type, $filterable_types);
    }

    /**
     * Generate shortcode from configuration
     */
    public function generate_shortcode(array $config): string
    {
        $shortcode = '[gravity_table';

        if (isset($config['form_id'])) {
            $shortcode .= ' form_id="' . intval($config['form_id']) . '"';
        }

        if (isset($config['settings']['per_page'])) {
            $shortcode .= ' per_page="' . intval($config['settings']['per_page']) . '"';
        }

        if (isset($config['selected_fields']) && !empty($config['selected_fields'])) {
            $shortcode .= ' columns="' . implode(',', $config['selected_fields']) . '"';
        }

        if (isset($config['field_labels']) && !empty($config['field_labels'])) {
            $shortcode .= ' column_labels="' . implode(',', $config['field_labels']) . '"';
        }

        if (isset($config['editable_fields']) && !empty($config['editable_fields'])) {
            $shortcode .= ' editable_fields="' . implode(',', $config['editable_fields']) . '"';
        }

        if (isset($config['sortable_fields']) && !empty($config['sortable_fields'])) {
            $shortcode .= ' sortable_fields="' . implode(',', $config['sortable_fields']) . '"';
        }

        $boolean_settings = array(
            'show_search',
            'show_pagination',
            'show_selection',
            'show_bulk_actions',
            'show_advanced_filters',
            'enable_frontend_editing',
            'show_column_totals'
        );

        foreach ($boolean_settings as $setting) {
            if (isset($config['settings'][$setting])) {
                $value = $config['settings'][$setting] ? 'true' : 'false';
                $shortcode .= ' ' . $setting . '="' . $value . '"';
            }
        }

        if (isset($config['settings']['bulk_actions']) && !empty($config['settings']['bulk_actions'])) {
            $shortcode .= ' bulk_actions="' . implode(',', $config['settings']['bulk_actions']) . '"';
        }

        // Handle user role access control (new field takes priority)
        if (isset($config['settings']['allowed_user_roles']) && !empty($config['settings']['allowed_user_roles'])) {
            $roles = is_array($config['settings']['allowed_user_roles'])
                ? implode(',', $config['settings']['allowed_user_roles'])
                : $config['settings']['allowed_user_roles'];
            $shortcode .= ' allowed_user_roles="' . $roles . '"';
        } elseif (isset($config['settings']['user_role_filter']) && !empty($config['settings']['user_role_filter'])) {
            // Backward compatibility
            $shortcode .= ' user_role_filter="' . $config['settings']['user_role_filter'] . '"';
        }

        if (isset($config['settings']['css_class']) && !empty($config['settings']['css_class'])) {
            $shortcode .= ' css_class="' . $config['settings']['css_class'] . '"';
        }

        $shortcode .= ']';

        return $shortcode;
    }

    /**
     * Validate table configuration
     */
    public function validate_config(array $config): array
    {
        $errors = array();

        $source_type = $config['data_source_type'] ?? 'gravity_forms';
        if ($source_type === 'gravity_forms' && empty($config['form_id'])) {
            $errors[] = __('Form ID is required', 'tc-data-tables');
        }

        if (empty($config['title'])) {
            $errors[] = __('Table title is required', 'tc-data-tables');
        }

        if ($source_type === 'gravity_forms' && empty($config['selected_fields'])) {
            $errors[] = __('At least one field must be selected', 'tc-data-tables');
        }

        // Validate form exists
        if (!empty($config['form_id']) && class_exists('GFAPI')) {
            $form = GFAPI::get_form($config['form_id']);
            if (!$form || is_wp_error($form)) {
                $errors[] = __('Selected form does not exist', 'tc-data-tables');
            }
        }

        return $errors;
    }
}