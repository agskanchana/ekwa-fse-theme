/**
 * Ekwa Load More Rows — block editor UI.
 *
 * Container for ekwa/load-more-row children. All rows show in the editor; the
 * front-end hides rows beyond `visibleRows` and reveals them in `batchSize`
 * batches via the Load More button (server render + view.js).
 */
( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el                = wp.element.createElement;
	var Fragment          = wp.element.Fragment;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var InnerBlocks        = wp.blockEditor.InnerBlocks;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var PanelBody          = wp.components.PanelBody;
	var TextControl        = wp.components.TextControl;
	var SelectControl      = wp.components.SelectControl;
	var ToggleControl      = wp.components.ToggleControl;
	var __                 = wp.i18n.__;

	var ALLOWED  = [ 'ekwa/load-more-row' ];
	var TEMPLATE = [
		[ 'ekwa/load-more-row' ],
		[ 'ekwa/load-more-row' ],
		[ 'ekwa/load-more-row' ],
	];

	registerBlockType( 'ekwa/load-more-rows', {
		edit: function ( props ) {
			var a          = props.attributes;
			var setAttrs   = props.setAttributes;
			var blockProps = useBlockProps( { className: 'ekwa-lmr ekwa-lmr--editor' } );

			var controls = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Load More', 'ekwa' ), initialOpen: true },
					el( TextControl, {
						label: __( 'Rows visible initially', 'ekwa' ),
						type: 'number',
						min: 0,
						value: a.visibleRows,
						onChange: function ( v ) { setAttrs( { visibleRows: Math.max( 0, parseInt( v, 10 ) || 0 ) } ); },
					} ),
					el( TextControl, {
						label: __( 'Rows revealed per click', 'ekwa' ),
						type: 'number',
						min: 1,
						value: a.batchSize,
						onChange: function ( v ) { setAttrs( { batchSize: Math.max( 1, parseInt( v, 10 ) || 1 ) } ); },
					} ),
					el( TextControl, {
						label: __( 'Button text', 'ekwa' ),
						value: a.buttonText,
						onChange: function ( v ) { setAttrs( { buttonText: v } ); },
					} ),
					el( TextControl, {
						label: __( 'Button CSS classes', 'ekwa' ),
						value: a.buttonClassName,
						onChange: function ( v ) { setAttrs( { buttonClassName: v } ); },
					} ),
					el( SelectControl, {
						label: __( 'Button alignment', 'ekwa' ),
						value: a.alignButton,
						options: [
							{ label: __( 'Left', 'ekwa' ),   value: 'left' },
							{ label: __( 'Center', 'ekwa' ), value: 'center' },
							{ label: __( 'Right', 'ekwa' ),  value: 'right' },
						],
						onChange: function ( v ) { setAttrs( { alignButton: v } ); },
					} ),
					el( ToggleControl, {
						label: __( 'Hide button when all rows are shown', 'ekwa' ),
						checked: !! a.hideWhenDone,
						onChange: function ( v ) { setAttrs( { hideWhenDone: v } ); },
					} )
				)
			);

			return el(
				Fragment,
				null,
				controls,
				el(
					'div',
					blockProps,
					el( InnerBlocks, {
						allowedBlocks:  ALLOWED,
						template:       TEMPLATE,
						templateLock:   false,
						orientation:    'vertical',
						renderAppender: InnerBlocks.ButtonBlockAppender,
					} )
				)
			);
		},

		save: function () {
			return el( InnerBlocks.Content );
		},
	} );

} )( window.wp );
