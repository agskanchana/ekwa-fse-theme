/**
 * Ekwa Scroll Up Block — Block Editor UI.
 *
 * Renders a preview of the scroll-to-top button.
 * Position, size, and colour options are exposed in the Inspector sidebar.
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
	var ServerSideRender   = wp.serverSideRender;
	var __                 = wp.i18n.__;

	/* ------------------------------------------------------------------ */
	/* Edit component                                                       */
	/* ------------------------------------------------------------------ */
	function Edit( props ) {
		var attrs    = props.attributes;
		var setAttrs = props.setAttributes;

		var blockProps = useBlockProps( { className: 'ekwa-scroll-top-editor-wrapper' } );

		return el(
			Fragment,
			null,

			/* ---- Inspector sidebar ---- */
			el(
				InspectorControls,
				null,

				/* Button panel */
				el(
					PanelBody,
					{ title: __( 'Button Settings', 'ekwa' ), initialOpen: true },

					el( RangeControl, {
						label:    __( 'Icon Size (px)', 'ekwa' ),
						value:    attrs.iconSize,
						min:      12,
						max:      40,
						onChange: function ( v ) { setAttrs( { iconSize: v } ); },
					} ),

					el( RangeControl, {
						label:    __( 'Button Size (px)', 'ekwa' ),
						value:    attrs.buttonSize,
						min:      32,
						max:      80,
						onChange: function ( v ) { setAttrs( { buttonSize: v } ); },
					} ),

					el( RangeControl, {
						label:    __( 'Border Radius (px)', 'ekwa' ),
						value:    attrs.borderRadius,
						min:      0,
						max:      40,
						onChange: function ( v ) { setAttrs( { borderRadius: v } ); },
					} )
				),

				/* Position panel */
				el(
					PanelBody,
					{ title: __( 'Position', 'ekwa' ), initialOpen: false },

					el( RangeControl, {
						label:    __( 'Bottom Offset (px)', 'ekwa' ),
						value:    attrs.offsetBottom,
						min:      10,
						max:      100,
						onChange: function ( v ) { setAttrs( { offsetBottom: v } ); },
					} ),

					el( RangeControl, {
						label:    __( 'Right Offset (px)', 'ekwa' ),
						value:    attrs.offsetRight,
						min:      10,
						max:      100,
						onChange: function ( v ) { setAttrs( { offsetRight: v } ); },
					} ),

					el( RangeControl, {
						label:    __( 'Scroll Threshold (px)', 'ekwa' ),
						help:     __( 'How far the user must scroll before the button appears.', 'ekwa' ),
						value:    attrs.scrollThreshold,
						min:      50,
						max:      1000,
						step:     10,
						onChange: function ( v ) { setAttrs( { scrollThreshold: v } ); },
					} )
				),

				/* Colors panel */
				el( PanelColorSettings, {
					title:        __( 'Colors', 'ekwa' ),
					initialOpen:  false,
					colorSettings: [
						{
							value:    attrs.iconColor,
							onChange: function ( v ) { setAttrs( { iconColor: v || '#ffffff' } ); },
							label:    __( 'Icon Color', 'ekwa' ),
						},
						{
							value:    attrs.buttonBg,
							onChange: function ( v ) { setAttrs( { buttonBg: v || '#0073aa' } ); },
							label:    __( 'Button Background', 'ekwa' ),
						},
					],
				} )
			),

			/* ---- Canvas: live server-side preview ---- */
			el(
				'div',
				blockProps,
				el( ServerSideRender, {
					block:      'ekwa/scroll-top',
					attributes: attrs,
				} )
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Register the block                                                   */
	/* ------------------------------------------------------------------ */
	registerBlockType( 'ekwa/scroll-top', {
		edit: Edit,
		save: function () {
			return null;
		},
	} );

} )( window.wp );
