/**
 * Ekwa Button Block — Block Editor UI.
 *
 * A clean button/link — outputs a single <a> or <button> element.
 * Supports variants (filled, outline, ghost), sizes, and optional FA icon.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType  = wp.blocks.registerBlockType;
	var el                 = wp.element.createElement;
	var Fragment           = wp.element.Fragment;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var PanelBody          = wp.components.PanelBody;
	var TextControl        = wp.components.TextControl;
	var SelectControl      = wp.components.SelectControl;
	var ToggleControl      = wp.components.ToggleControl;
	var __                 = wp.i18n.__;

	var VARIANT_OPTIONS = [
		{ label: 'Filled',  value: 'filled' },
		{ label: 'Outline', value: 'outline' },
		{ label: 'Ghost',   value: 'ghost' },
	];

	var SIZE_OPTIONS = [
		{ label: 'Small',   value: 'sm' },
		{ label: 'Default', value: 'default' },
		{ label: 'Large',   value: 'lg' },
	];

	var TAG_OPTIONS = [
		{ label: '<a> (Link)',       value: 'a' },
		{ label: '<button> (Button)', value: 'button' },
	];

	var ICON_POS_OPTIONS = [
		{ label: 'Left',  value: 'left' },
		{ label: 'Right', value: 'right' },
	];

	function Edit( props ) {
		var attributes    = props.attributes;
		var setAttributes = props.setAttributes;

		var text         = attributes.text         || '';
		var url          = attributes.url          || '';
		var newTab       = !! attributes.newTab;
		var rel          = attributes.rel          || '';
		var htmlTag      = attributes.htmlTag      || 'a';
		var variant      = attributes.variant      || 'filled';
		var size         = attributes.size         || 'default';
		var iconClass    = attributes.iconClass    || '';
		var iconPosition = attributes.iconPosition || 'left';

		var classes = 'ekwa-btn ekwa-btn--' + variant;
		if ( size !== 'default' ) {
			classes += ' ekwa-btn--' + size;
		}

		var blockProps = useBlockProps( {
			className: classes,
		} );

		var iconEl = iconClass
			? el( 'i', { className: iconClass, 'aria-hidden': 'true', style: { marginRight: iconPosition === 'left' ? '6px' : 0, marginLeft: iconPosition === 'right' ? '6px' : 0 } } )
			: null;

		return el( Fragment, null,

			el( InspectorControls, null,
				el( PanelBody, { title: __( 'Button Settings', 'ekwa' ), initialOpen: true },
					el( TextControl, {
						label:    __( 'Text', 'ekwa' ),
						value:    text,
						onChange: function ( v ) { setAttributes( { text: v } ); },
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} ),
					el( TextControl, {
						label:    __( 'URL', 'ekwa' ),
						value:    url,
						onChange: function ( v ) { setAttributes( { url: v.trim() } ); },
						type:     'url',
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} ),
					el( ToggleControl, {
						label:    __( 'Open in new tab', 'ekwa' ),
						checked:  newTab,
						onChange: function ( v ) { setAttributes( { newTab: v } ); },
						__nextHasNoMarginBottom: true,
					} ),
					el( TextControl, {
						label:    __( 'Link rel', 'ekwa' ),
						help:     __( 'Optional rel attribute (e.g. nofollow).', 'ekwa' ),
						value:    rel,
						onChange: function ( v ) { setAttributes( { rel: v.trim() } ); },
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} ),
					el( SelectControl, {
						label:    __( 'HTML Tag', 'ekwa' ),
						value:    htmlTag,
						options:  TAG_OPTIONS,
						onChange: function ( v ) { setAttributes( { htmlTag: v } ); },
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} )
				),

				el( PanelBody, { title: __( 'Style', 'ekwa' ), initialOpen: true },
					el( SelectControl, {
						label:    __( 'Variant', 'ekwa' ),
						value:    variant,
						options:  VARIANT_OPTIONS,
						onChange: function ( v ) { setAttributes( { variant: v } ); },
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} ),
					el( SelectControl, {
						label:    __( 'Size', 'ekwa' ),
						value:    size,
						options:  SIZE_OPTIONS,
						onChange: function ( v ) { setAttributes( { size: v } ); },
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} )
				),

				el( PanelBody, { title: __( 'Icon', 'ekwa' ), initialOpen: false },
					el( TextControl, {
						label:    __( 'Icon Class', 'ekwa' ),
						help:     __( 'Font Awesome class (e.g. fa-solid fa-phone).', 'ekwa' ),
						value:    iconClass,
						onChange: function ( v ) { setAttributes( { iconClass: v.trim() } ); },
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} ),
					iconClass && el( SelectControl, {
						label:    __( 'Icon Position', 'ekwa' ),
						value:    iconPosition,
						options:  ICON_POS_OPTIONS,
						onChange: function ( v ) { setAttributes( { iconPosition: v } ); },
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} )
				)
			),

			/* ---------- Canvas ---------- */
			el( 'div', blockProps,
				iconPosition === 'left' && iconEl,
				el( 'span', null, text || __( 'Button text…', 'ekwa' ) ),
				iconPosition === 'right' && iconEl
			)
		);
	}

	registerBlockType( 'ekwa/button', {
		edit: Edit,
		save: function () { return null; },
	} );

} )( window.wp );
