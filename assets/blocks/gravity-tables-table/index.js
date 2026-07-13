( function ( blocks, element, blockEditor, components, i18n ) {
    var el               = element.createElement;
    var __               = i18n.__;
    var registerBlockType = blocks.registerBlockType;
    var ServerSideRender = blockEditor.ServerSideRender || components.ServerSideRender;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody        = components.PanelBody;
    var TextControl      = components.TextControl;
    var ToggleControl    = components.ToggleControl;
    var SelectControl    = components.SelectControl;
    var RangeControl     = components.RangeControl;
    var useBlockProps    = blockEditor.useBlockProps;

    registerBlockType( 'gravity-tables/table', {
        edit: function ( props ) {
            var attributes  = props.attributes;
            var setAttributes = props.setAttributes;
            var blockProps  = useBlockProps ? useBlockProps() : {};

            return el(
                element.Fragment,
                null,
                el(
                    InspectorControls,
                    null,
                    el(
                        PanelBody,
                        { title: __( 'Table Settings', 'gravity-tables' ), initialOpen: true },
                        attributes.tableId > 0 && el(
                            'p',
                            { style: { marginBottom: '12px' } },
                            el(
                                'a',
                                {
                                    href: attributes.editTableUrl
                                        || ( window.gtBlockData && window.gtBlockData.adminBase
                                            ? window.gtBlockData.adminBase + '&id=' + attributes.tableId
                                            : '#' ),
                                    target: '_blank',
                                    rel: 'noopener noreferrer',
                                    className: 'button button-secondary gt-edit-table-link'
                                },
                                __( 'Edit table', 'gravity-tables' )
                            )
                        ),
                        el( TextControl, {
                            label: __( 'Table ID', 'gravity-tables' ),
                            help: __( 'Enter the numeric ID of the Gravity Table to display.', 'gravity-tables' ),
                            type: 'number',
                            value: attributes.tableId || '',
                            onChange: function ( val ) {
                                setAttributes( { tableId: parseInt( val, 10 ) || 0 } );
                            }
                        } ),
                        el( RangeControl, {
                            label: __( 'Page Size', 'gravity-tables' ),
                            help: __( 'Override entries per page (0 = table default).', 'gravity-tables' ),
                            value: attributes.pageSize,
                            min: 0,
                            max: 200,
                            onChange: function ( val ) {
                                setAttributes( { pageSize: val } );
                            }
                        } ),
                        el( TextControl, {
                            label: __( 'Default Sort Column', 'gravity-tables' ),
                            value: attributes.defaultSortColumn,
                            onChange: function ( val ) {
                                setAttributes( { defaultSortColumn: val } );
                            }
                        } ),
                        el( SelectControl, {
                            label: __( 'Default Sort Direction', 'gravity-tables' ),
                            value: attributes.defaultSortDirection,
                            options: [
                                { label: __( 'Ascending', 'gravity-tables' ),  value: 'asc' },
                                { label: __( 'Descending', 'gravity-tables' ), value: 'desc' }
                            ],
                            onChange: function ( val ) {
                                setAttributes( { defaultSortDirection: val } );
                            }
                        } )
                    ),
                    el(
                        PanelBody,
                        { title: __( 'Display Options', 'gravity-tables' ), initialOpen: false },
                        el( ToggleControl, {
                            label: __( 'Show Search', 'gravity-tables' ),
                            checked: attributes.showSearch,
                            onChange: function ( val ) { setAttributes( { showSearch: val } ); }
                        } ),
                        el( ToggleControl, {
                            label: __( 'Show Filters', 'gravity-tables' ),
                            checked: attributes.showFilters,
                            onChange: function ( val ) { setAttributes( { showFilters: val } ); }
                        } ),
                        el( ToggleControl, {
                            label: __( 'Show Pagination', 'gravity-tables' ),
                            checked: attributes.showPagination,
                            onChange: function ( val ) { setAttributes( { showPagination: val } ); }
                        } ),
                        el( ToggleControl, {
                            label: __( 'Show Export Buttons', 'gravity-tables' ),
                            checked: attributes.showExport,
                            onChange: function ( val ) { setAttributes( { showExport: val } ); }
                        } )
                    )
                ),
                el(
                    'div',
                    blockProps,
                    attributes.tableId
                        ? el( ServerSideRender, {
                            block: 'gravity-tables/table',
                            attributes: attributes
                        } )
                        : el(
                            'div',
                            { className: 'gt-block-placeholder' },
                            el( 'p', null, __( 'Gravity Table - enter a Table ID in the sidebar to display a table.', 'gravity-tables' ) )
                        )
                )
            );
        }
    } );

} )(
    window.wp.blocks,
    window.wp.element,
    window.wp.blockEditor,
    window.wp.components,
    window.wp.i18n
);
