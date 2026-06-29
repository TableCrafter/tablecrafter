<?php
/**
 * Gutenberg block registration for Gravity Tables (#80)
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Blocks {

    public function __construct() {
        add_action('init', array($this, 'register_blocks'));
    }

    public function register_blocks(): void {
        if (!function_exists('register_block_type')) {
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        register_block_type(
            TC_PLUGIN_PATH . 'assets/blocks/gravity-tables-table',
            array(
                'render_callback' => array($this, 'render_table_block'),
            )
        );

        // Load block editor translations so strings in index.js are translatable.
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations(
                'gravity-tables-table-editor-script',
                'tc-data-tables',
                TC_PLUGIN_PATH . 'languages'
            );
        }
    }

    /**
     * Return the admin edit URL for a given table.
     *
     * @param int $table_id Table ID.
     * @return string Absolute admin URL.
     */
    public function get_edit_url( int $table_id ): string {
        return admin_url( 'admin.php?page=gravity-tables&id=' . $table_id );
    }

    /**
     * Server-side render callback for the gravity-tables/table block.
     *
     * Delegates to do_shortcode() so the block and the shortcode always produce
     * identical markup — no separate rendering path to maintain.
     *
     * @param array $attributes Block attributes from the editor.
     * @return string HTML output.
     */
    public function render_table_block(array $attributes): string {
        $table_id = isset($attributes['tableId']) ? (int) $attributes['tableId'] : 0;

        if ($table_id <= 0) {
            return '';
        }

        $atts = array('id' => $table_id);

        if (isset($attributes['showFilters'])) {
            $atts['show_filters'] = $attributes['showFilters'] ? 'true' : 'false';
        }
        if (isset($attributes['showPagination'])) {
            $atts['show_pagination'] = $attributes['showPagination'] ? 'true' : 'false';
        }
        if (isset($attributes['showSearch'])) {
            $atts['show_search'] = $attributes['showSearch'] ? 'true' : 'false';
        }
        if (isset($attributes['showExport'])) {
            $atts['show_export'] = $attributes['showExport'] ? 'true' : 'false';
        }
        if (!empty($attributes['defaultSortColumn'])) {
            $atts['sort_column'] = sanitize_text_field($attributes['defaultSortColumn']);
        }
        if (!empty($attributes['defaultSortDirection'])) {
            $direction = $attributes['defaultSortDirection'] === 'desc' ? 'desc' : 'asc';
            $atts['sort_direction'] = $direction;
        }
        if (!empty($attributes['pageSize']) && $attributes['pageSize'] > 0) {
            $atts['per_page'] = (int) $attributes['pageSize'];
        }

        $atts_str = '';
        foreach ($atts as $key => $value) {
            $atts_str .= ' ' . esc_attr($key) . '="' . esc_attr($value) . '"';
        }

        $shortcode_output = do_shortcode('[gravity_table' . $atts_str . ']');
        $edit_url         = esc_url( $this->get_edit_url( $table_id ) );

        return '<div class="gt-block-wrapper" data-edit-url="' . $edit_url . '">'
               . $shortcode_output
               . '</div>';
    }
}

// @codeCoverageIgnoreStart
new TC_Blocks();
// @codeCoverageIgnoreEnd
