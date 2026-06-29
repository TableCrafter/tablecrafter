<?php
/**
 * Star Rating column-type renderer for Gravity Tables.
 *
 * Renders a numeric value (0–max) as filled/half/empty SVG stars with full
 * accessibility support and DataTables-compatible numeric sort value.
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Star_Rating_Service {

    /**
     * Render a star-rating widget as an HTML string.
     *
     * @param float|string $value   The numeric rating (e.g. 3.5).
     * @param int          $max     Maximum number of stars (default 5).
     * @param array        $options {
     *   @type string $color        Filled-star colour (CSS colour value, default '#f5a623').
     *   @type string $empty_color  Empty-star colour (default '#d3d3d3').
     *   @type string $size         Star size as a CSS value (default '1em').
     * }
     * @return string HTML string with role="img" and aria-label for accessibility.
     */
    public static function render( $value, int $max = 5, array $options = [] ): string {
        $rating      = floatval( $value );
        $max         = max( 1, (int) $max );
        $rating      = min( max( 0.0, $rating ), (float) $max );

        $color       = esc_attr( $options['color']       ?? '#f5a623' );
        $empty_color = esc_attr( $options['empty_color'] ?? '#d3d3d3' );
        $size        = esc_attr( $options['size']        ?? '1em' );

        $label = sprintf(
            /* translators: 1: numeric rating, 2: max stars */
            esc_attr__( '%1$s out of %2$s stars', 'tc-data-tables' ),
            number_format_i18n( $rating, 1 ),
            $max
        );

        $stars = '';
        for ( $i = 1; $i <= $max; $i++ ) {
            $diff = $rating - ( $i - 1 );
            if ( $diff >= 1.0 ) {
                $stars .= self::star_svg( 'full', $color, $empty_color, $size );
            } elseif ( $diff >= 0.5 ) {
                $stars .= self::star_svg( 'half', $color, $empty_color, $size );
            } else {
                $stars .= self::star_svg( 'empty', $color, $empty_color, $size );
            }
        }

        return sprintf(
            '<span class="gt-star-rating" role="img" aria-label="%s" data-rating="%s" data-max="%d">%s</span>',
            $label,
            esc_attr( number_format( $rating, 2, '.', '' ) ),
            $max,
            $stars
        );
    }

    /**
     * Return the numeric value to use for DataTables column sorting.
     *
     * @param float|string $value
     * @return float
     */
    public static function get_sort_value( $value ): float {
        return floatval( $value );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Render a single SVG star: full, half, or empty.
     *
     * @param 'full'|'half'|'empty' $type
     * @param string $fill        Fill colour for the star body.
     * @param string $empty_fill  Fill colour for the empty portion.
     * @param string $size        CSS size (width/height).
     * @return string  SVG string.
     */
    private static function star_svg( string $type, string $fill, string $empty_fill, string $size ): string {
        // SVG star path (5-point star centred at 12,12 in a 24×24 viewport).
        $path = 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z';

        if ( $type === 'full' ) {
            return sprintf(
                '<svg class="gt-star gt-star--full" width="%s" height="%s" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="%s" fill="%s"/></svg>',
                $size, $size, $path, $fill
            );
        }

        if ( $type === 'empty' ) {
            return sprintf(
                '<svg class="gt-star gt-star--empty" width="%s" height="%s" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="%s" fill="%s"/></svg>',
                $size, $size, $path, $empty_fill
            );
        }

        // Half star: clip-path reveals left half filled, right half empty.
        return sprintf(
            '<svg class="gt-star gt-star--half" width="%s" height="%s" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' .
            '<defs><clipPath id="gt-half-clip"><rect x="0" y="0" width="12" height="24"/></clipPath></defs>' .
            '<path d="%s" fill="%s"/>' .
            '<path d="%s" fill="%s" clip-path="url(#gt-half-clip)"/>' .
            '</svg>',
            $size, $size,
            $path, $empty_fill,
            $path, $fill
        );
    }
}
