<?php
/**
 * Elementor widget for Gravity Tables (#81)
 *
 * Registers a TC_Elementor_Widget only when Elementor is active.
 * Class is declared inside the hook callback so this file can load before Elementor
 * without triggering "Class Elementor\Widget_Base not found".
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
/**
 * Register Elementor widget once Elementor APIs are available.
 *
 * @param \Elementor\Widgets_Manager $widgets_manager Widget manager instance.
 */
function gt_register_gravity_tables_elementor_widget($widgets_manager)
{
    if (!class_exists('\Elementor\Widget_Base')) {
        // @codeCoverageIgnoreStart
        return;
        // @codeCoverageIgnoreEnd
    }

    if (!class_exists('TC_Elementor_Widget', false)) {

        class TC_Elementor_Widget extends \Elementor\Widget_Base
        {

            public function get_name(): string
            {
                return 'gravity_table';
            }

            public function get_title(): string
            {
                return __('TableCrafter Table', 'tc-data-tables');
            }

            public function get_icon(): string
            {
                return 'eicon-table';
            }

            public function get_categories(): array
            {
                return ['general'];
            }

            public function get_keywords(): array
            {
                return ['tablecrafter', 'table', 'data', 'gravity forms', 'json', 'airtable', 'csv'];
            }

            protected function register_controls(): void
            {
                $this->start_controls_section(
                    'section_content',
                    array(
                        'label' => __('Table Settings', 'tc-data-tables'),
                        'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
                    )
                );

                $this->add_control(
                    'table_id',
                    array(
                        'label'       => __('Table ID', 'tc-data-tables'),
                        'type'        => \Elementor\Controls_Manager::NUMBER,
                        'min'         => 0,
                        'default'     => 0,
                        'description' => __('Enter the numeric ID of the TableCrafter table to display. Works with any data source (Gravity Forms, JSON, Airtable, CSV, and more).', 'tc-data-tables'),
                    )
                );

                // #2145 - legacy inline data source (back-compat with the 3.5.x
                // widget). When set, it takes precedence over Table ID and
                // renders the URL directly (JSON / CSV / public Google Sheet).
                $this->add_control(
                    'data_source',
                    array(
                        'label'       => __('Or: Data Source URL', 'tc-data-tables'),
                        'type'        => \Elementor\Controls_Manager::URL,
                        'placeholder' => 'https://api.example.com/data.json',
                        'description' => __('Inline source: a JSON / CSV / public Google Sheet URL. Overrides Table ID when set.', 'tc-data-tables'),
                    )
                );

                $this->add_control(
                    'root_path',
                    array(
                        'label'       => __('JSON Root Path', 'tc-data-tables'),
                        'type'        => \Elementor\Controls_Manager::TEXT,
                        'description' => __('Dot-path to the data array in a JSON response (e.g. data.results).', 'tc-data-tables'),
                    )
                );

                $this->add_control(
                    'include_columns',
                    array(
                        'label'     => __('Include Columns', 'tc-data-tables'),
                        'type'      => \Elementor\Controls_Manager::TEXT,
                        'description' => __('Comma-separated columns to show (inline source).', 'tc-data-tables'),
                    )
                );

                $this->add_control(
                    'exclude_columns',
                    array(
                        'label'     => __('Exclude Columns', 'tc-data-tables'),
                        'type'      => \Elementor\Controls_Manager::TEXT,
                        'description' => __('Comma-separated columns to hide (inline source).', 'tc-data-tables'),
                    )
                );

                $this->add_control(
                    'show_search',
                    array(
                        'label'        => __('Show Search', 'tc-data-tables'),
                        'type'         => \Elementor\Controls_Manager::SWITCHER,
                        'label_on'     => __('Yes', 'tc-data-tables'),
                        'label_off'    => __('No', 'tc-data-tables'),
                        'return_value' => 'true',
                        'default'      => 'true',
                    )
                );

                $this->add_control(
                    'show_filters',
                    array(
                        'label'        => __('Show Filters', 'tc-data-tables'),
                        'type'         => \Elementor\Controls_Manager::SWITCHER,
                        'label_on'     => __('Yes', 'tc-data-tables'),
                        'label_off'    => __('No', 'tc-data-tables'),
                        'return_value' => 'true',
                        'default'      => 'true',
                    )
                );

                $this->add_control(
                    'show_pagination',
                    array(
                        'label'        => __('Show Pagination', 'tc-data-tables'),
                        'type'         => \Elementor\Controls_Manager::SWITCHER,
                        'label_on'     => __('Yes', 'tc-data-tables'),
                        'label_off'    => __('No', 'tc-data-tables'),
                        'return_value' => 'true',
                        'default'      => 'true',
                    )
                );

                $this->add_control(
                    'show_export',
                    array(
                        'label'        => __('Show Export Buttons', 'tc-data-tables'),
                        'type'         => \Elementor\Controls_Manager::SWITCHER,
                        'label_on'     => __('Yes', 'tc-data-tables'),
                        'label_off'    => __('No', 'tc-data-tables'),
                        'return_value' => 'true',
                        'default'      => '',
                    )
                );

                $this->add_control(
                    'per_page',
                    array(
                        'label'       => __('Entries per Page', 'tc-data-tables'),
                        'type'        => \Elementor\Controls_Manager::NUMBER,
                        'min'         => 0,
                        'default'     => 0,
                        'description' => __('0 = use the table default.', 'tc-data-tables'),
                    )
                );

                $this->end_controls_section();
            }

            protected function render(): void
            {
                $settings = $this->get_settings_for_display();

                // #2145 - legacy inline source takes precedence over table id,
                // so 3.5.x Elementor elements (and new inline ones) render.
                if (class_exists('TC_Inline_Shortcode_Compat')) {
                    $inline = TC_Inline_Shortcode_Compat::elementor_inline_shortcode($settings);
                    if ($inline !== '') {
                        echo do_shortcode($inline);
                        return;
                    }
                }

                $table_id = (int) ($settings['table_id'] ?? 0);
                if ($table_id <= 0) {
                    if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
                        echo '<div class="gt-elementor-placeholder">' .
                            esc_html__('Gravity Table - set a Table ID in the widget controls.', 'tc-data-tables') .
                            '</div>';
                    }
                    return;
                }

                $atts = array('id' => $table_id);

                foreach (['show_search', 'show_filters', 'show_pagination', 'show_export'] as $key) {
                    if (isset($settings[$key])) {
                        $atts[$key] = $settings[$key] === 'true' ? 'true' : 'false';
                    }
                }

                if (!empty($settings['per_page']) && (int) $settings['per_page'] > 0) {
                    $atts['per_page'] = (int) $settings['per_page'];
                }

                $atts_str = '';
                foreach ($atts as $key => $value) {
                    $atts_str .= ' ' . esc_attr($key) . '="' . esc_attr($value) . '"';
                }

                // #2014 - canonical shortcode. The shortcode dispatches by the
                // table's stored data_source_type, so this renders any source
                // (Gravity Forms or external) for the given table id.
                echo do_shortcode('[tablecrafter' . $atts_str . ']');
            }
        }
    }

    $widgets_manager->register(new TC_Elementor_Widget());
}

// Defense in depth: only wire the elementor/widgets/register hook if Elementor
// has finished bootstrapping. did_action('elementor/loaded') flips to >0 after
// Elementor's main plugin file has run init. Without this guard, sites that
// have Elementor installed but disabled (or only partially loaded) can register
// our hook against an Elementor that never reaches the widgets/register stage,
// creating a dangling callback. The function-side class_exists() check above
// already prevents fatal errors; this guard prevents the no-op hook entirely.
//
// #682: WP_INT_TEST short-circuit. When the file is required from
// tests/bootstrap.php (which defines WP_INT_TEST=true), did_action /
// add_action don't exist yet - WP_Mock's Patchwork shims activate after
// the require chain. Skipping the file-top wiring under test mode lets
// PHPUnit boot without the prior fatal.
// @codeCoverageIgnoreStart
if (!(defined('WP_INT_TEST') && WP_INT_TEST)) {
    if (did_action('elementor/loaded')) {
        add_action('elementor/widgets/register', 'gt_register_gravity_tables_elementor_widget');
// @codeCoverageIgnoreEnd
    } else {
        // If we loaded before Elementor, wait for its loaded action and rewire.
        // @codeCoverageIgnoreStart
        add_action('elementor/loaded', function () {
            add_action('elementor/widgets/register', 'gt_register_gravity_tables_elementor_widget');
        });
        // @codeCoverageIgnoreEnd
    }
}
