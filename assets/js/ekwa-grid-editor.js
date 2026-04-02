/**
 * Ekwa Grid Block — Block Editor UI.
 *
 * A CSS Grid container with responsive column breakpoints.
 * Children are placed directly into the grid — no column wrappers needed.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType  = wp.blocks.registerBlockType;
	var el                 = wp.element.createElement;
	var Fragment           = wp.element.Fragment;
	var InnerBlocks        = wp.blockEditor.InnerBlocks;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var PanelBody          = wp.components.PanelBody;
	var RangeControl       = wp.components.RangeControl;
	var TextControl        = wp.components.TextControl;
	var __                 = wp.i18n.__;

	function Edit( props ) {
		var attributes    = props.attributes;
		var setAttributes = props.setAttributes;

		var columns       = attributes.columns       || 3;
		var columnWidths  = attributes.columnWidths   || '';
		var tabletColumns = attributes.tabletColumns  || 2;
		var mobileColumns = attributes.mobileColumns  || 1;

		var gridTemplate = columnWidths || ( 'repeat(' + columns + ', 1fr)' );

		var blockProps = useBlockProps( {
			className: 'ekwa-grid',
			style: {
				display: 'grid',
				gridTemplateColumns: gridTemplate,
			},
		} );

		return el( Fragment, null,

			el( InspectorControls, null,
				el( PanelBody, { title: __( 'Grid Layout', 'ekwa' ), initialOpen: true },
					el( RangeControl, {
						label:    __( 'Desktop Columns', 'ekwa' ),
						value:    columns,
						onChange: function ( v ) { setAttributes( { columns: v } ); },
						min:      1,
						max:      6,
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} ),
					el( TextControl, {
						label:    __( 'Custom Column Widths', 'ekwa' ),
						help:     __( 'Override with custom grid-template-columns (e.g. 1fr 2fr 1fr). Leave empty to use column count.', 'ekwa' ),
						value:    columnWidths,
						onChange: function ( v ) { setAttributes( { columnWidths: v.trim() } ); },
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} ),
					el( RangeControl, {
						label:    __( 'Tablet Columns (< 1200px)', 'ekwa' ),
						value:    tabletColumns,
						onChange: function ( v ) { setAttributes( { tabletColumns: v } ); },
						min:      1,
						max:      6,
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} ),
					el( RangeControl, {
						label:    __( 'Mobile Columns (< 600px)', 'ekwa' ),
						value:    mobileColumns,
						onChange: function ( v ) { setAttributes( { mobileColumns: v } ); },
						min:      1,
						max:      4,
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} )
				)
			),

			el( 'div', blockProps,
				el( InnerBlocks, { templateLock: false } )
			)
		);
	}

	registerBlockType( 'ekwa/grid', {
		edit: Edit,
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );

} )( window.wp );
