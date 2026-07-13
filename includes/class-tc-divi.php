<?php
/**
 * Divi page builder module for Gravity Tables (#105)
 *
 * Registers TC_Divi_Module only when Divi (ET_BUILDER_VERSION) is active.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
// Gate legacy Divi 4 module registration behind TC_Divi_Compat so
// Divi 5 (which removed the ET_Builder_Module signature this code
// targets) does not fatal during subclass instantiation. (#486)
// @codeCoverageIgnoreStart
if (defined('ET_BUILDER_VERSION') && TC_Divi_Compat::should_register_legacy_module(ET_BUILDER_VERSION)) {
// @codeCoverageIgnoreEnd
    add_action('et_builder_ready', function () {
        if (class_exists('ET_Builder_Module') && TC_Divi_Compat::should_register_legacy_module(TC_Divi_Compat::active_version())) {
            new TC_Divi_Module();
        }
    });
}

// @codeCoverageIgnoreStart
if (class_exists('ET_Builder_Module')) :
// @codeCoverageIgnoreEnd
    // phpcs:ignore -- conditional class declaration, valid PHP; guard prevents fatal when Divi is absent (#345)
    class TC_Divi_Module extends ET_Builder_Module {

    public $slug       = 'et_pb_gravity_table';
    public $vb_support = 'on';

    public function init(): void {
        $this->name            = esc_html__('TableCrafter', 'tc-data-tables');
        $this->plural          = esc_html__('TableCrafter', 'tc-data-tables');
        $this->main_css_element = '%%order_class%%';
        $this->icon_path        = TC_PLUGIN_PATH . 'assets/images/divi-icon.svg';

        $this->settings_modal_toggles = array(
            'general'  => array(
                'toggles' => array(
                    'table'   => esc_html__('Table Selection', 'tc-data-tables'),
                    'display' => esc_html__('Display Options', 'tc-data-tables'),
                    'sorting' => esc_html__('Default Sorting', 'tc-data-tables'),
                ),
            ),
        );
    }

    public function get_fields(): array {
        return array(
            'table_id' => array(
                'label'           => esc_html__('Table', 'tc-data-tables'),
                'type'            => 'text',
                'option_category' => 'basic_option',
                'description'     => esc_html__('Enter the numeric ID of the Gravity Table to display.', 'tc-data-tables'),
                'toggle_slug'     => 'table',
                'default'         => '',
            ),
            'show_search' => array(
                'label'           => esc_html__('Show Search Bar', 'tc-data-tables'),
                'type'            => 'yes_no_button',
                'option_category' => 'configuration',
                'options'         => array(
                    'on'  => esc_html__('Yes', 'tc-data-tables'),
                    'off' => esc_html__('No', 'tc-data-tables'),
                ),
                'toggle_slug'     => 'display',
                'default'         => 'on',
            ),
            'show_filters' => array(
                'label'           => esc_html__('Show Filters', 'tc-data-tables'),
                'type'            => 'yes_no_button',
                'option_category' => 'configuration',
                'options'         => array(
                    'on'  => esc_html__('Yes', 'tc-data-tables'),
                    'off' => esc_html__('No', 'tc-data-tables'),
                ),
                'toggle_slug'     => 'display',
                'default'         => 'on',
            ),
            'show_pagination' => array(
                'label'           => esc_html__('Show Pagination', 'tc-data-tables'),
                'type'            => 'yes_no_button',
                'option_category' => 'configuration',
                'options'         => array(
                    'on'  => esc_html__('Yes', 'tc-data-tables'),
                    'off' => esc_html__('No', 'tc-data-tables'),
                ),
                'toggle_slug'     => 'display',
                'default'         => 'on',
            ),
            'show_export' => array(
                'label'           => esc_html__('Show Export Buttons', 'tc-data-tables'),
                'type'            => 'yes_no_button',
                'option_category' => 'configuration',
                'options'         => array(
                    'on'  => esc_html__('Yes', 'tc-data-tables'),
                    'off' => esc_html__('No', 'tc-data-tables'),
                ),
                'toggle_slug'     => 'display',
                'default'         => 'off',
            ),
            'per_page' => array(
                'label'           => esc_html__('Entries per Page', 'tc-data-tables'),
                'type'            => 'text',
                'option_category' => 'configuration',
                'description'     => esc_html__('Override the number of entries shown per page. Leave blank to use the table default.', 'tc-data-tables'),
                'toggle_slug'     => 'display',
                'default'         => '',
            ),
            'sort_column' => array(
                'label'           => esc_html__('Default Sort Column', 'tc-data-tables'),
                'type'            => 'text',
                'option_category' => 'configuration',
                'description'     => esc_html__('Column key to sort by on initial load. Leave blank to use the table default.', 'tc-data-tables'),
                'toggle_slug'     => 'sorting',
                'default'         => '',
            ),
            'sort_direction' => array(
                'label'           => esc_html__('Default Sort Direction', 'tc-data-tables'),
                'type'            => 'select',
                'option_category' => 'configuration',
                'options'         => array(
                    ''     => esc_html__('Table default', 'tc-data-tables'),
                    'asc'  => esc_html__('Ascending', 'tc-data-tables'),
                    'desc' => esc_html__('Descending', 'tc-data-tables'),
                ),
                'toggle_slug'     => 'sorting',
                'default'         => '',
            ),
        );
    }

    public function render($attrs, $content, $render_slug): string {
        $table_id = (int) $this->props['table_id'];

        if ($table_id <= 0) {
            if (et_core_is_fb_enabled()) {
                return '<div class="gt-divi-placeholder" style="padding:20px;text-align:center;background:#f0f0f0;border:2px dashed #ccc;">' .
                    esc_html__('Gravity Table - set a Table ID in the module settings.', 'tc-data-tables') .
                    '</div>';
            }
            return '';
        }

        $atts = array('id' => $table_id);

        $toggle_map = array(
            'show_search'     => 'show_search',
            'show_filters'    => 'show_filters',
            'show_pagination' => 'show_pagination',
            'show_export'     => 'show_export',
        );

        foreach ($toggle_map as $prop => $attr) {
            if (isset($this->props[$prop])) {
                $atts[$attr] = $this->props[$prop] === 'on' ? 'true' : 'false';
            }
        }

        $per_page = (int) $this->props['per_page'];
        if ($per_page > 0) {
            $atts['per_page'] = $per_page;
        }

        if (!empty($this->props['sort_column'])) {
            $atts['sort_column'] = sanitize_key($this->props['sort_column']);
        }

        if (!empty($this->props['sort_direction']) && in_array($this->props['sort_direction'], array('asc', 'desc'), true)) {
            $atts['sort_direction'] = $this->props['sort_direction'];
        }

        $atts_str = '';
        foreach ($atts as $key => $value) {
            $atts_str .= ' ' . esc_attr($key) . '="' . esc_attr($value) . '"';
        }

        return do_shortcode('[gravity_table' . $atts_str . ']');
    }
}
endif; // class_exists('ET_Builder_Module')
