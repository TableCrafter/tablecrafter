<?php
/**
 * #1741 - Status Badge Cell Type (Free).
 *
 * Renders a colored pill badge for a cell value when the value appears
 * in the configured badge map for that column. Pure static helpers.
 */
class TC_Badge_Service {

    /**
     * Render $value through the badge map.
     *
     * @param string $value     Raw cell value.
     * @param array  $badge_map { value_string => ['bg' => '#hex', 'text' => '#hex'] }
     * @return string  Badge HTML span, or raw esc_html value when no map entry matches.
     */
    public static function render( string $value, array $badge_map ): string {
        if ( empty( $badge_map ) || ! array_key_exists( $value, $badge_map ) ) {
            return $value;
        }

        $entry = $badge_map[ $value ];
        $bg    = sanitize_hex_color( (string) ( $entry['bg']   ?? '' ) ) ?: '#e5e7eb';
        $text  = sanitize_hex_color( (string) ( $entry['text'] ?? '' ) ) ?: '#111827';

        return sprintf(
            '<span class="gt-badge" style="background:%s;color:%s;border-radius:9999px;padding:2px 10px;font-size:.8em;font-weight:600;display:inline-block;white-space:nowrap;">%s</span>',
            esc_attr( $bg ),
            esc_attr( $text ),
            esc_html( $value )
        );
    }

    /**
     * Sanitize a raw badge map (e.g. from $_POST) for storage.
     *
     * Entries with missing or invalid hex colors are dropped.
     *
     * @param mixed $raw  Decoded PHP value.
     * @return array  Sanitized map, keyed by value string.
     */
    public static function sanitize_map( $raw ): array {
        if ( ! is_array( $raw ) ) {
            return [];
        }

        $out = [];
        foreach ( $raw as $value => $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $bg   = sanitize_hex_color( (string) ( $entry['bg']   ?? '' ) );
            $text = sanitize_hex_color( (string) ( $entry['text'] ?? '' ) );
            if ( $bg === '' || $text === '' ) {
                continue;
            }
            $out[ (string) $value ] = [ 'bg' => $bg, 'text' => $text ];
        }
        return $out;
    }
}
