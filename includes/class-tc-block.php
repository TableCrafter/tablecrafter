<?php
/**
 * TC_Block — Gutenberg block for TableCrafter tables.
 *
 * Issue #2013 (convergence epic #2006, Phase 3). Premium shipped no Gutenberg
 * block; this ports the free plugin's block as a server-rendered block that
 * delegates to the [tablecrafter] shortcode, so a table (Gravity Forms or any
 * external source) can be inserted from the block editor.
 */

class TC_Block
{
    const BLOCK_NAME        = 'tablecrafter/table';
    const LEGACY_BLOCK_NAME = 'tablecrafter/data-table';
    const SCRIPT_HANDLE = 'gt-block-editor';

    /** Hook block registration on init. */
    public static function boot(): void
    {
        if (function_exists('add_action')) {
            add_action('init', array(__CLASS__, 'register'));
        }
    }

    /** Register the editor script + the dynamic block. */
    public static function register(): void
    {
        if (!function_exists('register_block_type')) {
            return; // Block editor unavailable (very old WP) — shortcode still works.
        }

        if (function_exists('wp_register_script') && defined('TC_PLUGIN_URL')) {
            wp_register_script(
                self::SCRIPT_HANDLE,
                TC_PLUGIN_URL . 'assets/js/block.js',
                array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n'),
                defined('TC_VERSION') ? TC_VERSION : '1.0',
                true
            );
        }

        register_block_type(self::BLOCK_NAME, array(
            'api_version'     => 2,
            'attributes'      => array(
                'tableId' => array('type' => 'number', 'default' => 0),
            ),
            'render_callback' => array(__CLASS__, 'render_block'),
            'editor_script'   => self::SCRIPT_HANDLE,
        ));

        // #2144 — back-compat for the 3.5.x `tablecrafter/data-table` block.
        // Registered server-side so existing posts render again on the front
        // end (and the editor recognises it via the same editor script).
        register_block_type(self::LEGACY_BLOCK_NAME, array(
            'api_version'     => 2,
            'attributes'      => array(
                'source'   => array('type' => 'string',  'default' => ''),
                'root'     => array('type' => 'string',  'default' => ''),
                'include'  => array('type' => 'string',  'default' => ''),
                'exclude'  => array('type' => 'string',  'default' => ''),
                'search'   => array('type' => 'boolean', 'default' => false),
                'filters'  => array('type' => 'boolean', 'default' => true),
                'export'   => array('type' => 'boolean', 'default' => false),
                'per_page' => array('type' => 'number',  'default' => 0),
                // Preserved so saved content isn't flagged invalid; auto-refresh
                // not yet honoured on render (tracked under #2143).
                'id'                   => array('type' => 'string',  'default' => ''),
                'auto_refresh'         => array('type' => 'boolean', 'default' => false),
                'refresh_interval'     => array('type' => 'number',  'default' => 300000),
                'refresh_indicator'    => array('type' => 'boolean', 'default' => true),
                'refresh_countdown'    => array('type' => 'boolean', 'default' => false),
                'refresh_last_updated' => array('type' => 'boolean', 'default' => true),
            ),
            'render_callback' => array(__CLASS__, 'render_legacy_block'),
            'editor_script'   => self::SCRIPT_HANDLE,
        ));
    }

    /**
     * #2144 — Render a legacy `tablecrafter/data-table` block by mapping its
     * attributes onto the inline `[tablecrafter source=...]` shortcode.
     *
     * @param array $attributes Legacy block attributes.
     */
    public static function render_legacy_block($attributes): string
    {
        $shortcode = self::legacy_data_table_shortcode(is_array($attributes) ? $attributes : array());
        if ($shortcode === '') {
            return '';
        }
        return do_shortcode($shortcode);
    }

    /**
     * Pure mapping: legacy block attributes → a `[tablecrafter ...]` shortcode
     * string. The old `id` attr was a DOM id (uniqid()), NOT a stored-table id,
     * so it is intentionally dropped. Returns '' when there is no source.
     *
     * @param array $attributes
     */
    public static function legacy_data_table_shortcode(array $attributes): string
    {
        $clean = static function ($v): string {
            // Keep the shortcode parseable: drop quotes and brackets.
            return trim(str_replace(array('"', ']', '[', "\n"), '', (string) $v));
        };

        $source = $clean($attributes['source'] ?? '');
        if ($source === '') {
            return '';
        }

        $parts = array('source="' . $source . '"');

        foreach (array('root', 'include', 'exclude') as $key) {
            $val = $clean($attributes[$key] ?? '');
            if ($val !== '') {
                $parts[] = $key . '="' . $val . '"';
            }
        }

        $per_page = isset($attributes['per_page']) ? (int) $attributes['per_page'] : 0;
        if ($per_page > 0) {
            $parts[] = 'per_page="' . $per_page . '"';
        }

        foreach (array('search', 'export', 'filters') as $key) {
            $parts[] = $key . '="' . (!empty($attributes[$key]) ? 'true' : 'false') . '"';
        }

        return '[tablecrafter ' . implode(' ', $parts) . ']';
    }

    /**
     * Server-side render: delegate to the [tablecrafter] shortcode.
     *
     * @param array $attributes Block attributes ({ tableId }).
     * @return string
     */
    public static function render_block($attributes): string
    {
        $table_id = isset($attributes['tableId']) ? absint($attributes['tableId']) : 0;

        if ($table_id <= 0) {
            // Show a hint in the editor; render nothing on the front end.
            if (function_exists('current_user_can') && current_user_can('edit_posts')) {
                return '<p class="gt-block-placeholder">'
                    . esc_html__('Select a table to display.', 'tc-data-tables')
                    . '</p>';
            }
            return '';
        }

        return do_shortcode('[tablecrafter id="' . $table_id . '"]');
    }
}
