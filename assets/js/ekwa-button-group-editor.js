/**
 * Ekwa Button Group Block — Block Editor UI.
 *
 * A flex wrapper for grouping ekwa/button blocks.
 * Outputs <div> with display:flex on frontend.
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
	var SelectControl      = wp.components.SelectControl;
	var __                 = wp.i18n.__;

	var ALLOWED_BLOCKS = [ 'ekwa/button' ];

	var TEMPLATE = [
		[ 'ekwa/button', { text: 'Button' } ],
	];

	var JUSTIFY_OPTIONS = [
		{ label: 'Start',         value: 'flex-start' },
		{ label: 'Center',        value: 'center' },
		{ label: 'End',           value: 'flex-end' },
		{ label: 'Space Between', value: 'space-between' },
	];

	var DIRECTION_OPTIONS = [
		{ label: 'Row',            value: 'row' },
		{ label: 'Row Reverse',    value: 'row-reverse' },
		{ label: 'Column',         value: 'column' },
	];

	function Edit( props ) {
		var attributes    = props.attributes;
		var setAttributes = props.setAttributes;

		var justifyContent = attributes.justifyContent || 'flex-start';
		var direction      = attributes.direction      || 'row';

		var blockProps = useBlockProps( {
			className: 'ekwa-button-group',
			style: {
				display:        'flex',
				flexDirection:  direction,
				justifyContent: justifyContent,
				flexWrap:       'wrap',
			},
		} );

		return el( Fragment, null,

			el( InspectorControls, null,
				el( PanelBody, { title: __( 'Layout', 'ekwa' ), initialOpen: true },
					el( SelectControl, {
						label:    __( 'Justify Content', 'ekwa' ),
						value:    justifyContent,
						options:  JUSTIFY_OPTIONS,
						onChange: function ( v ) { setAttributes( { justifyContent: v } ); },
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} ),
					el( SelectControl, {
						label:    __( 'Direction', 'ekwa' ),
						value:    direction,
						options:  DIRECTION_OPTIONS,
						onChange: function ( v ) { setAttributes( { direction: v } ); },
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} )
				)
			),

			el( 'div', blockProps,
				el( InnerBlocks, {
					allowedBlocks: ALLOWED_BLOCKS,
					template:      TEMPLATE,
					templateLock:  false,
				} )
			)
		);
	}

	registerBlockType( 'ekwa/button-group', {
		edit: Edit,
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );

} )( window.wp );
