<?php
/**
 * Prebuilt templates gallery (#2134).
 *
 * One-click-installable starter tables (inventory, business directory, CRM
 * pipeline, event list, load tracker). Each is backed by a bundled JSON sample
 * file under demo-data/, so installation reuses the existing demo-create path:
 * create_demo_table() falls back to TC_Templates for keys TC_Demo_Data doesn't
 * know. Cuts time-to-value and gives each template its own searchable use case.
 *
 * The marketing landing page per template lives on the public site, not here.
 *
 * @since 8.0.17
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// @codeCoverageIgnoreEnd

class TC_Templates {

	/**
	 * @return array<string,array{label:string,description:string,category:string,file:string}>
	 */
	public static function all(): array {
		$t = static function ( string $s ): string {
			return function_exists( '__' ) ? __( $s, 'tc-data-tables' ) : $s;
		};

		return array(
			'template-inventory' => array(
				'label'       => $t( 'Inventory Tracker' ),
				'description' => $t( 'Track stock levels, reorder points, and unit prices across your products.' ),
				'category'    => $t( 'Operations' ),
				'file'        => 'template-inventory.json',
			),
			'template-directory' => array(
				'label'       => $t( 'Business Directory' ),
				'description' => $t( 'A searchable directory of businesses with contact details and websites.' ),
				'category'    => $t( 'Directory' ),
				'file'        => 'template-directory.json',
			),
			'template-crm' => array(
				'label'       => $t( 'CRM Pipeline' ),
				'description' => $t( 'A simple sales pipeline: contacts, deal stage, value, and next step.' ),
				'category'    => $t( 'Sales' ),
				'file'        => 'template-crm.json',
			),
			'template-events' => array(
				'label'       => $t( 'Event List' ),
				'description' => $t( 'Upcoming events with date, venue, and a tickets link.' ),
				'category'    => $t( 'Events' ),
				'file'        => 'template-events.json',
			),
			'template-load-tracker' => array(
				'label'       => $t( 'Load Tracker' ),
				'description' => $t( 'Trucking loads with driver, route, miles, rate, and status.' ),
				'category'    => $t( 'Logistics' ),
				'file'        => 'template-load-tracker.json',
			),
		);
	}

	/** Build the table settings for a template key. Null for an unknown key. */
	public static function table_settings( string $key ): ?array {
		$all = self::all();
		if ( ! isset( $all[ $key ] ) ) {
			return null;
		}
		$def = $all[ $key ];
		return array(
			'data_source_type' => 'json',
			'table_title'      => $def['label'],
			'json_url'         => TC_Demo_Data::url( $def['file'] ),
		);
	}

	/**
	 * Infer the column keys (JSON property names of the first row) so the
	 * installed table renders immediately instead of empty.
	 *
	 * @return array<int,string>
	 */
	public static function columns_for( string $key ): array {
		$all = self::all();
		if ( ! isset( $all[ $key ] ) ) {
			return array();
		}
		$body = TC_Demo_Data::read_local_body( TC_Demo_Data::url( $all[ $key ]['file'] ) );
		if ( $body === null ) {
			return array();
		}
		$rows = json_decode( $body, true );
		if ( ! is_array( $rows ) || empty( $rows ) || ! is_array( $rows[0] ) ) {
			return array();
		}
		return array_map( 'strval', array_keys( $rows[0] ) );
	}
}
