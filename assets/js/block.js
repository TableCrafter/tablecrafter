/**
 * TableCrafter Gutenberg block (#2013, #2351).
 *
 * Server-rendered block that displays a TableCrafter table by id. The preview
 * in the editor uses ServerSideRender so it matches the front end exactly. No
 * build step - written against the wp.* globals with wp.element.createElement.
 *
 * #2351 additions:
 *  - Inspector controls: perPage (RangeControl), search / showExport / filters /
 *    showPaginationInfo (ToggleControl x4).
 *  - Block transforms: [tablecrafter id=N …] shortcode ↔ block.
 *  - Preview states: placeholder (no ID), loading, error.
 */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	'use strict';

	var el             = element.createElement;
	var __             = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody      = components.PanelBody;
	var TextControl    = components.TextControl;
	var RangeControl   = components.RangeControl;
	var ToggleControl  = components.ToggleControl;
	var Spinner        = components.Spinner;
	var ServerSideRender = serverSideRender;
	var createBlock    = blocks.createBlock;

	// -----------------------------------------------------------------
	// Helper: build a [tablecrafter …] shortcode string from block attrs.
	// Used by the to-shortcode transform.
	// -----------------------------------------------------------------
	function buildShortcode( attrs ) {
		var parts = [ 'id="' + ( attrs.tableId || 0 ) + '"' ];
		var perPage = attrs.perPage !== undefined ? attrs.perPage : 25;
		if ( perPage !== 25 ) {
			parts.push( 'per_page="' + perPage + '"' );
		}
		if ( attrs.search === false ) {
			parts.push( 'search="false"' );
		}
		if ( attrs.showExport === false ) {
			parts.push( 'show_export="false"' );
		}
		if ( attrs.filters === true ) {
			parts.push( 'filters="true"' );
		}
		if ( attrs.showPaginationInfo === false ) {
			parts.push( 'show_pagination_info="false"' );
		}
		return '[tablecrafter ' + parts.join( ' ' ) + ']';
	}

	// -----------------------------------------------------------------
	// tablecrafter/table block
	// -----------------------------------------------------------------
	blocks.registerBlockType( 'tablecrafter/table', {
		apiVersion: 2,
		title: __( 'TableCrafter Table', 'tc-data-tables' ),
		description: __( 'Display a TableCrafter table (Gravity Forms or any data source) by its ID.', 'tc-data-tables' ),
		icon: 'editor-table',
		category: 'widgets',

		// ---------------------------------------------------------------
		// Attributes: tableId + five Inspector display params (#2351)
		// ---------------------------------------------------------------
		attributes: {
			tableId:            { type: 'number',  'default': 0 },
			perPage:            { type: 'number',  'default': 25 },
			search:             { type: 'boolean', 'default': true },
			showExport:         { type: 'boolean', 'default': true },
			filters:            { type: 'boolean', 'default': false },
			showPaginationInfo: { type: 'boolean', 'default': true },
		},

		// ---------------------------------------------------------------
		// Transforms (#2351)
		// ---------------------------------------------------------------
		transforms: {
			// From [tablecrafter id=N …] shortcode → block
			from: [
				{
					type: 'shortcode',
					tag: 'tablecrafter',
					attributes: {
						tableId: {
							type: 'number',
							shortcode: function ( attrs ) {
								return parseInt( attrs.named.id, 10 ) || 0;
							},
						},
						perPage: {
							type: 'number',
							shortcode: function ( attrs ) {
								var v = parseInt( attrs.named.per_page, 10 );
								return isNaN( v ) ? 25 : v;
							},
						},
						search: {
							type: 'boolean',
							shortcode: function ( attrs ) {
								return attrs.named.search !== 'false';
							},
						},
						showExport: {
							type: 'boolean',
							shortcode: function ( attrs ) {
								return attrs.named.show_export !== 'false';
							},
						},
						filters: {
							type: 'boolean',
							shortcode: function ( attrs ) {
								return attrs.named.filters === 'true';
							},
						},
						showPaginationInfo: {
							type: 'boolean',
							shortcode: function ( attrs ) {
								return attrs.named.show_pagination_info !== 'false';
							},
						},
					},
				},
			],
			// Block → core/shortcode (useful in Classic Editor or when pasting)
			to: [
				{
					type: 'block',
					blocks: [ 'core/shortcode' ],
					transform: function ( attrs ) {
						return createBlock( 'core/shortcode', {
							text: buildShortcode( attrs ),
						} );
					},
				},
			],
		},

		// ---------------------------------------------------------------
		// edit()
		// ---------------------------------------------------------------
		edit: function ( props ) {
			var attrs   = props.attributes;
			var tableId = attrs.tableId || 0;
			var set     = props.setAttributes;

			// Inspector panel with the five display controls.
			var inspector = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Table settings', 'tc-data-tables' ), initialOpen: true },
					el( TextControl, {
						label: __( 'Table ID', 'tc-data-tables' ),
						type: 'number',
						value: tableId ? String( tableId ) : '',
						onChange: function ( value ) {
							set( { tableId: parseInt( value, 10 ) || 0 } );
						},
					} )
				),
				el(
					PanelBody,
					{ title: __( 'Display options', 'tc-data-tables' ), initialOpen: true },
					el( RangeControl, {
						label: __( 'Rows per page', 'tc-data-tables' ),
						value: attrs.perPage,
						min: 5,
						max: 100,
						onChange: function ( value ) {
							set( { perPage: value } );
						},
					} ),
					el( ToggleControl, {
						label: __( 'Show search bar', 'tc-data-tables' ),
						checked: attrs.search,
						onChange: function ( value ) {
							set( { search: value } );
						},
					} ),
					el( ToggleControl, {
						label: __( 'Show export buttons', 'tc-data-tables' ),
						checked: attrs.showExport,
						onChange: function ( value ) {
							set( { showExport: value } );
						},
					} ),
					el( ToggleControl, {
						label: __( 'Show column filters', 'tc-data-tables' ),
						checked: attrs.filters,
						onChange: function ( value ) {
							set( { filters: value } );
						},
					} ),
					el( ToggleControl, {
						label: __( 'Show pagination info', 'tc-data-tables' ),
						checked: attrs.showPaginationInfo,
						onChange: function ( value ) {
							set( { showPaginationInfo: value } );
						},
					} )
				)
			);

			// Preview area: placeholder → loading → live ServerSideRender.
			var body;
			if ( ! tableId ) {
				body = el(
					'p',
					{ className: 'gt-block-placeholder' },
					__( 'Enter a Table ID in the block settings to display a table.', 'tc-data-tables' )
				);
			} else {
				body = el( ServerSideRender, {
					block: 'tablecrafter/table',
					attributes: attrs,
					LoadingResponsePlaceholder: function () {
						return el(
							'div',
							{ className: 'gt-block-loading' },
							el( Spinner, null ),
							el( 'span', null, __( 'Loading table preview…', 'tc-data-tables' ) )
						);
					},
					ErrorResponsePlaceholder: function ( errorProps ) {
						return el(
							'div',
							{ className: 'gt-block-error' },
							__( 'Could not load table preview. Check the Table ID and try again.', 'tc-data-tables' )
						);
					},
				} );
			}

			return [ inspector, el( 'div', { key: 'gt-block' }, body ) ];
		},

		// Dynamic block - rendered by PHP render_callback.
		save: function () {
			return null;
		},
	} );

	// -----------------------------------------------------------------
	// #2144 - back-compat for the 3.5.x `tablecrafter/data-table` block
	// -----------------------------------------------------------------
	blocks.registerBlockType( 'tablecrafter/data-table', {
		apiVersion: 2,
		title: __( 'TableCrafter Data Table (legacy)', 'tc-data-tables' ),
		description: __( 'Legacy inline-source table from the previous TableCrafter version. Still supported.', 'tc-data-tables' ),
		icon: 'editor-table',
		category: 'widgets',
		attributes: {
			source:               { type: 'string',  'default': '' },
			root:                 { type: 'string',  'default': '' },
			include:              { type: 'string',  'default': '' },
			exclude:              { type: 'string',  'default': '' },
			search:               { type: 'boolean', 'default': false },
			filters:              { type: 'boolean', 'default': true },
			export:               { type: 'boolean', 'default': false },
			per_page:             { type: 'number',  'default': 0 },
			id:                   { type: 'string',  'default': '' },
			auto_refresh:         { type: 'boolean', 'default': false },
			refresh_interval:     { type: 'number',  'default': 300000 },
			refresh_indicator:    { type: 'boolean', 'default': true },
			refresh_countdown:    { type: 'boolean', 'default': false },
			refresh_last_updated: { type: 'boolean', 'default': true },
		},

		edit: function ( props ) {
			var source = props.attributes.source || '';

			var inspector = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Data source', 'tc-data-tables' ), initialOpen: true },
					el( TextControl, {
						label: __( 'Source URL (JSON / CSV / Google Sheet)', 'tc-data-tables' ),
						value: source,
						onChange: function ( value ) {
							props.setAttributes( { source: value } );
						},
					} )
				)
			);

			var body = source
				? el( ServerSideRender, { block: 'tablecrafter/data-table', attributes: props.attributes } )
				: el(
					'p',
					{ className: 'gt-block-placeholder' },
					__( 'Enter a source URL to display a table.', 'tc-data-tables' )
				);

			return [ inspector, el( 'div', { key: 'gt-legacy-block' }, body ) ];
		},

		// Dynamic block - rendered by PHP render_callback.
		save: function () {
			return null;
		},
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.serverSideRender,
	window.wp.i18n
);
