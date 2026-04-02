/**
 * Ekwa Flex Block — Block Editor UI.
 *
 * A flexbox container for horizontal or vertical layouts.
 * Outputs <div> (or semantic tag) with display:flex on frontend.
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

	var TAG_OPTIONS = [
		{ label: 'div',    value: 'div' },
		{ label: 'nav',    value: 'nav' },
		{ label: 'header', value: 'header' },
		{ label: 'footer', value: 'footer' },
		{ label: 'aside',  value: 'aside' },
	];

	var DIRECTION_OPTIONS = [
		{ label: 'Row',            value: 'row' },
		{ label: 'Row Reverse',    value: 'row-reverse' },
		{ label: 'Column',         value: 'column' },
		{ label: 'Column Reverse', value: 'column-reverse' },
	];

	var JUSTIFY_OPTIONS = [
		{ label: 'Start',         value: 'flex-start' },
		{ label: 'Center',        value: 'center' },
		{ label: 'End',           value: 'flex-end' },
		{ label: 'Space Between', value: 'space-between' },
		{ label: 'Space Around',  value: 'space-around' },
		{ label: 'Space Evenly',  value: 'space-evenly' },
	];

	var ALIGN_OPTIONS = [
		{ label: 'Stretch',  value: 'stretch' },
		{ label: 'Start',    value: 'flex-start' },
		{ label: 'Center',   value: 'center' },
		{ label: 'End',      value: 'flex-end' },
		{ label: 'Baseline', value: 'baseline' },
	];

	var WRAP_OPTIONS = [
		{ label: 'Wrap',    value: 'wrap' },
		{ label: 'No Wrap', value: 'nowrap' },
	];

	function Edit( props ) {
		var attributes    = props.attributes;
		var setAttributes = props.setAttributes;

		var tagName        = attributes.tagName        || 'div';
		var direction      = attributes.direction      || 'row';
		var justifyContent = attributes.justifyContent || 'flex-start';
		var alignItems     = attributes.alignItems     || 'center';
		var wrap           = attributes.wrap            || 'wrap';

		var blockProps = useBlockProps( {
			className: 'ekwa-flex',
			style: {
				display:        'flex',
				flexDirection:  direction,
				justifyContent: justifyContent,
				alignItems:     alignItems,
				flexWrap:       wrap,
			},
		} );

		return el( Fragment, null,

			el( InspectorControls, null,
				el( PanelBody, { title: __( 'Flex Layout', 'ekwa' ), initialOpen: true },
					el( SelectControl, {
						label:    __( 'HTML Tag', 'ekwa' ),
						value:    tagName,
						options:  TAG_OPTIONS,
						onChange: function ( v ) { setAttributes( { tagName: v } ); },
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
					} ),
					el( SelectControl, {
						label:    __( 'Justify Content', 'ekwa' ),
						value:    justifyContent,
						options:  JUSTIFY_OPTIONS,
						onChange: function ( v ) { setAttributes( { justifyContent: v } ); },
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} ),
					el( SelectControl, {
						label:    __( 'Align Items', 'ekwa' ),
						value:    alignItems,
						options:  ALIGN_OPTIONS,
						onChange: function ( v ) { setAttributes( { alignItems: v } ); },
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} ),
					el( SelectControl, {
						label:    __( 'Wrap', 'ekwa' ),
						value:    wrap,
						options:  WRAP_OPTIONS,
						onChange: function ( v ) { setAttributes( { wrap: v } ); },
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

	registerBlockType( 'ekwa/flex', {
		edit: Edit,
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );

} )( window.wp );
