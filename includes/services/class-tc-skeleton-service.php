<?php
/**
 * Skeleton loader / loading animation service.
 *
 * Renders a CSS-only shimmer placeholder matching the table's column count and
 * configured rows-per-page height, so there is no layout shift when data loads.
 * Three animation styles: 'skeleton' (shimmer), 'spinner', 'none'.
 * Respects prefers-reduced-motion to disable animation for affected users.
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Skeleton_Service {

    const STYLE_SKELETON = 'skeleton';
    const STYLE_SPINNER  = 'spinner';
    const STYLE_NONE     = 'none';

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return the configured animation style for a table, defaulting to 'skeleton'.
     *
     * @param array $settings  Table settings array.
     * @return string  One of: 'skeleton', 'spinner', 'none'.
     */
    public static function get_animation_style( array $settings ): string {
        $style = $settings['loading_animation'] ?? self::STYLE_SKELETON;
        if ( ! in_array( $style, [ self::STYLE_SKELETON, self::STYLE_SPINNER, self::STYLE_NONE ], true ) ) {
            return self::STYLE_SKELETON;
        }
        return $style;
    }

    /**
     * Return true when a loading animation should be rendered for this table.
     *
     * @param array $settings
     * @return bool
     */
    public static function is_enabled( array $settings ): bool {
        // Disabled only when style is explicitly 'none'.
        return self::get_animation_style( $settings ) !== self::STYLE_NONE;
    }

    /**
     * Generate the skeleton / spinner placeholder HTML for a table.
     *
     * @param int   $table_id
     * @param array $settings  Table settings including column definitions and per_page.
     * @return string  Safe HTML string.
     */
    public static function get_html( int $table_id, array $settings ): string {
        $style = self::get_animation_style( $settings );

        if ( $style === self::STYLE_NONE ) {
            return '';
        }

        $table_id  = (int) $table_id;
        $col_count = (int) max( 1, count( $settings['columns'] ?? [] ) ?: 4 );
        $row_count = (int) max( 1, $settings['rows_per_page'] ?? $settings['default_per_page'] ?? 10 );

        if ( $style === self::STYLE_SPINNER ) {
            return sprintf(
                '<div id="gt-skeleton-%d" class="gt-skeleton gt-skeleton--spinner" role="status" aria-label="%s"><div class="gt-spinner"></div></div>',
                $table_id,
                esc_attr__( 'Loading table data…', 'tc-data-tables' )
            );
        }

        // Skeleton shimmer style.
        $col_html = '';
        for ( $c = 0; $c < $col_count; $c++ ) {
            $col_html .= '<th class="gt-skeleton-cell"><span class="gt-skeleton-bar"></span></th>';
        }

        $rows_html = '';
        for ( $r = 0; $r < $row_count; $r++ ) {
            $cells = '';
            for ( $c = 0; $c < $col_count; $c++ ) {
                $cells .= '<td class="gt-skeleton-cell"><span class="gt-skeleton-bar"></span></td>';
            }
            $rows_html .= '<tr>' . $cells . '</tr>';
        }

        return sprintf(
            '<div id="gt-skeleton-%d" class="gt-skeleton gt-skeleton--shimmer" role="status" aria-label="%s">'
            . '<table class="gt-skeleton-table"><thead><tr>%s</tr></thead><tbody>%s</tbody></table>'
            . '</div>',
            $table_id,
            esc_attr__( 'Loading table data…', 'tc-data-tables' ),
            $col_html,
            $rows_html
        );
    }

    /**
     * Generate CSS for the skeleton animation, scoped to the given table.
     *
     * Uses CSS-only @keyframes shimmer - no JS required. Includes a
     * prefers-reduced-motion media query that disables animation for users
     * who have that OS preference set.
     *
     * @param int   $table_id
     * @param array $settings
     * @return string  CSS string (no <style> wrapper - use wp_add_inline_style).
     */
    public static function get_css( int $table_id, array $settings ): string {
        $table_id = (int) $table_id;
        $style    = self::get_animation_style( $settings );

        if ( $style === self::STYLE_NONE ) {
            return '';
        }

        $row_height = $settings['skeleton_row_height'] ?? '48px';
        $row_height = preg_match( '/^\d+(\.\d+)?(px|em|rem|vh)$/', $row_height ) ? $row_height : '48px';

        $css = "
/* Gravity Tables skeleton loader - table #gt-table-{$table_id} */
@keyframes gt-shimmer-{$table_id} {
    0%   { background-position: -400px 0; }
    100% { background-position: 400px 0; }
}

#gt-skeleton-{$table_id} { width: 100%; overflow: hidden; }

#gt-skeleton-{$table_id} .gt-skeleton-table {
    width: 100%;
    border-collapse: collapse;
}

#gt-skeleton-{$table_id} .gt-skeleton-cell {
    padding: 8px 12px;
    height: {$row_height};
}

#gt-skeleton-{$table_id} .gt-skeleton-bar {
    display: block;
    height: 14px;
    border-radius: 4px;
    background: linear-gradient(90deg, #e8e8e8 25%, #f5f5f5 50%, #e8e8e8 75%);
    background-size: 800px 100%;
    animation: gt-shimmer-{$table_id} 1.4s infinite linear;
}

/* Spinner style */
#gt-skeleton-{$table_id}.gt-skeleton--spinner {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 120px;
}

#gt-skeleton-{$table_id} .gt-spinner {
    width: 36px;
    height: 36px;
    border: 4px solid #ddd;
    border-top-color: #0073aa;
    border-radius: 50%;
    animation: gt-spin-{$table_id} 0.8s linear infinite;
}

@keyframes gt-spin-{$table_id} {
    to { transform: rotate(360deg); }
}

/* Respect user OS preference - disable all animation */
@media (prefers-reduced-motion: reduce) {
    #gt-skeleton-{$table_id} .gt-skeleton-bar,
    #gt-skeleton-{$table_id} .gt-spinner {
        animation: none;
    }
    #gt-skeleton-{$table_id} .gt-skeleton-bar {
        background: #e8e8e8;
    }
}
";

        return $css;
    }

    /**
     * Enqueue skeleton CSS as inline style appended to the gt-frontend handle.
     *
     * @param int   $table_id
     * @param array $settings
     */
    public static function enqueue_css( int $table_id, array $settings ): void {
        $css = self::get_css( $table_id, $settings );
        if ( $css !== '' ) {
            wp_add_inline_style( 'gravity-tables-frontend', $css );
        }
    }
}
