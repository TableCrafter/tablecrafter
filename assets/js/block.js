/**
 * TableCrafter Gutenberg block (#2013).
 *
 * Server-rendered block that displays a TableCrafter table by id. The preview
 * in the editor uses ServerSideRender so it matches the front end exactly. No
 * build step — written against the wp.* globals with wp.element.createElement.
 */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var ServerSideRender = serverSideRender;

	blocks.registerBlockType( 'tablecrafter/table', {
		apiVersion: 2,
		title: __( 'TableCrafter Table', 'tc-data-tables' ),
		description: __( 'Display a TableCrafter table (Gravity Forms or any data source) by its ID.', 'tc-data-tables' ),
		icon: 'editor-table',
		category: 'widgets',
		attributes: {
			tableId: { type: 'number', 'default': 0 }
		},

		edit: function ( props ) {
			var tableId = props.attributes.tableId || 0;

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
							props.setAttributes( { tableId: parseInt( value, 10 ) || 0 } );
						}
					} )
				)
			);

			var body = tableId
				? el( ServerSideRender, { block: 'tablecrafter/table', attributes: props.attributes } )
				: el(
					'p',
					{ className: 'gt-block-placeholder' },
					__( 'Enter a Table ID in the block settings to display a table.', 'tc-data-tables' )
				);

			return [ inspector, el( 'div', { key: 'gt-block' }, body ) ];
		},

		// Dynamic block — rendered by PHP render_callback.
		save: function () {
			return null;
		}
	} );

	// #2144 — back-compat registration for the 3.5.x `tablecrafter/data-table`
	// block so existing posts are recognised in the editor (not "unsupported")
	// and keep rendering. Deprecated: edit shows a live preview + the source.
	blocks.registerBlockType( 'tablecrafter/data-table', {
		apiVersion: 2,
		title: __( 'TableCrafter Data Table (legacy)', 'tc-data-tables' ),
		description: __( 'Legacy inline-source table from the previous TableCrafter version. Still supported.', 'tc-data-tables' ),
		icon: 'editor-table',
		category: 'widgets',
		attributes: {
			source: { type: 'string', 'default': '' },
			root: { type: 'string', 'default': '' },
			include: { type: 'string', 'default': '' },
			exclude: { type: 'string', 'default': '' },
			search: { type: 'boolean', 'default': false },
			filters: { type: 'boolean', 'default': true },
			export: { type: 'boolean', 'default': false },
			per_page: { type: 'number', 'default': 0 },
			id: { type: 'string', 'default': '' },
			auto_refresh: { type: 'boolean', 'default': false },
			refresh_interval: { type: 'number', 'default': 300000 },
			refresh_indicator: { type: 'boolean', 'default': true },
			refresh_countdown: { type: 'boolean', 'default': false },
			refresh_last_updated: { type: 'boolean', 'default': true }
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
						}
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

		// Dynamic block — rendered by PHP render_callback.
		save: function () {
			return null;
		}
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.serverSideRender,
	window.wp.i18n
);
