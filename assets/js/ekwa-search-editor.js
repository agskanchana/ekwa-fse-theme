/**
 * Ekwa Search Block — Block Editor UI.
 *
 * Renders a live preview of the search icon button.
 * All overlay and styling options are exposed in the Inspector sidebar.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType  = wp.blocks.registerBlockType;
	var el                 = wp.element.createElement;
	var Fragment           = wp.element.Fragment;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var PanelColorSettings = wp.blockEditor.PanelColorSettings;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var PanelBody          = wp.components.PanelBody;
	var RangeControl       = wp.components.RangeControl;
	var TextControl        = wp.components.TextControl;
	var ToggleControl      = wp.components.ToggleControl;
	var ServerSideRender   = wp.serverSideRender;
	var __                 = wp.i18n.__;

	/* ------------------------------------------------------------------ */
	/* Edit component                                                       */
	/* ------------------------------------------------------------------ */
	function Edit( props ) {
		var attrs    = props.attributes;
		var setAttrs = props.setAttributes;

		var blockProps = useBlockProps( { className: 'ekwa-search-editor-wrapper' } );

		return el(
			Fragment,
			null,

			/* ---- Inspector sidebar ---- */
			el(
				InspectorControls,
				null,

				/* Icon panel */
				el(
					PanelBody,
					{ title: __( 'Search Icon', 'ekwa' ), initialOpen: true },

					el( RangeControl, {
						label:    __( 'Icon Size (px)', 'ekwa' ),
						value:    attrs.iconSize,
						min:      12,
						max:      60,
						onChange: function ( v ) { setAttrs( { iconSize: v } ); },
					} )
				),

				/* Overlay panel */
				el(
					PanelBody,
					{ title: __( 'Search Modal', 'ekwa' ), initialOpen: false },

					el( TextControl, {
						label:    __( 'Input Placeholder', 'ekwa' ),
						value:    attrs.placeholder,
						onChange: function ( v ) { setAttrs( { placeholder: v } ); },
					} ),

					el( TextControl, {
						label:    __( 'Search Button Label', 'ekwa' ),
						value:    attrs.buttonLabel,
						onChange: function ( v ) { setAttrs( { buttonLabel: v } ); },
					} ),

					el( ToggleControl, {
						label:    __( 'Blur page background', 'ekwa' ),
						help:     attrs.overlayBlur
							? __( 'Page content behind the overlay is blurred.', 'ekwa' )
							: __( 'No blur on background content.', 'ekwa' ),
						checked:  attrs.overlayBlur,
						onChange: function ( v ) { setAttrs( { overlayBlur: v } ); },
					} ),

					el( TextControl, {
						label:    __( 'Overlay Background (CSS color / rgba)', 'ekwa' ),
						help:     __( 'e.g. rgba(15,23,42,0.85)', 'ekwa' ),
						value:    attrs.overlayBg,
						onChange: function ( v ) { setAttrs( { overlayBg: v } ); },
					} )
				),

				/* Colors panel */
				el( PanelColorSettings, {
					title:        __( 'Colors', 'ekwa' ),
					initialOpen:  false,
					colorSettings: [
						{
							value:    attrs.iconColor,
							onChange: function ( v ) { setAttrs( { iconColor: v || '' } ); },
							label:    __( 'Icon Color', 'ekwa' ),
						},
						{
							value:    attrs.buttonBg,
							onChange: function ( v ) { setAttrs( { buttonBg: v || '' } ); },
							label:    __( 'Icon Button Background', 'ekwa' ),
						},
						{
							value:    attrs.searchBtnBg,
							onChange: function ( v ) { setAttrs( { searchBtnBg: v || '' } ); },
							label:    __( '"Search" Button Background', 'ekwa' ),
						},
						{
							value:    attrs.searchBtnColor,
							onChange: function ( v ) { setAttrs( { searchBtnColor: v || '' } ); },
							label:    __( '"Search" Button Text Color', 'ekwa' ),
						},
					],
				} )
			),

			/* ---- Canvas: live server-side preview ---- */
			el(
				'div',
				blockProps,
				el( ServerSideRender, {
					block:      'ekwa/search',
					attributes: attrs,
				} )
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Register the block                                                   */
	/* ------------------------------------------------------------------ */
	registerBlockType( 'ekwa/search', {
		edit: Edit,
		save: function () {
			return null;
		},
	} );

} )( window.wp );
