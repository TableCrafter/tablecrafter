<?php
/**
 * Customizable pagination control label text.
 *
 * Provides per-table overrides for all five DataTables pagination/info labels.
 * Empty fields fall back to plugin defaults (partial overrides are supported).
 * Output is escaped before use; DataTables language config is JSON-encoded via
 * wp_json_encode() so it is safe to inline in <script> blocks.
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Pagination_Label_Service {

    /**
     * The five configurable label fields.
     * Keys match table settings array keys; values are plugin defaults.
     */
    const FIELDS = [
        'info_text'      => 'Showing {start} to {end} of {total} entries',
        'previous_label' => 'Previous',
        'next_label'     => 'Next',
        'no_results'     => 'No matching entries found.',
        'loading'        => 'Loading…',
    ];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return the default label values.
     *
     * @return array  [ field_key => default_string ]
     */
    public static function get_defaults(): array {
        return [
            'info_text'      => __( 'Showing {start} to {end} of {total} entries', 'tc-data-tables' ),
            'previous_label' => __( 'Previous', 'tc-data-tables' ),
            'next_label'     => __( 'Next', 'tc-data-tables' ),
            'no_results'     => __( 'No matching entries found.', 'tc-data-tables' ),
            'loading'        => __( 'Loading…', 'tc-data-tables' ),
        ];
    }

    /**
     * Merge per-table settings over defaults and return all five label values.
     * An empty string in $settings for any field causes the default to be used.
     *
     * @param array $settings  Table settings array.
     * @return array  [ field_key => resolved_string ]
     */
    public static function get_labels( array $settings ): array {
        $defaults = self::get_defaults();
        $labels   = [];

        foreach ( $defaults as $key => $default ) {
            $raw = $settings[ $key ] ?? '';
            $labels[ $key ] = ( $raw !== '' ) ? $raw : $default;
        }

        return $labels;
    }

    /**
     * Build the DataTables `language` configuration object for a given table.
     *
     * The info_text placeholders {start}/{end}/{total} are converted to the
     * DataTables tokens _START_, _END_, _TOTAL_.
     *
     * @param array $settings  Table settings array.
     * @return array  DataTables language config array (ready for wp_json_encode).
     */
    public static function get_datatables_language( array $settings ): array {
        $labels   = self::get_labels( $settings );
        $info_str = self::convert_info_placeholders( $labels['info_text'] );

        return [
            'info'           => esc_html( $info_str ),
            'infoFiltered'   => esc_html( __( '(filtered from _MAX_ total entries)', 'tc-data-tables' ) ),
            'zeroRecords'    => esc_html( $labels['no_results'] ),
            'emptyTable'     => esc_html( $labels['no_results'] ),
            'loadingRecords' => esc_html( $labels['loading'] ),
            'processing'     => esc_html( $labels['loading'] ),
            'paginate'       => [
                'previous' => wp_kses_post( $labels['previous_label'] ),
                'next'     => wp_kses_post( $labels['next_label'] ),
            ],
        ];
    }

    /**
     * Return a JSON-encoded DataTables language string safe for inline <script>.
     *
     * @param array $settings
     * @return string
     */
    public static function get_datatables_language_json( array $settings ): string {
        return wp_json_encode( self::get_datatables_language( $settings ) );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Convert user-facing {start}/{end}/{total} tokens to DataTables _START_/_END_/_TOTAL_.
     */
    private static function convert_info_placeholders( string $text ): string {
        return str_replace(
            [ '{start}', '{end}', '{total}' ],
            [ '_START_', '_END_',  '_TOTAL_' ],
            $text
        );
    }
}
