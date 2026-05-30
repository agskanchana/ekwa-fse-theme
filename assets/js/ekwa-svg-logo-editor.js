/**
 * Ekwa SVG Logo Block — Block Editor UI.
 *
 * The SVG markup itself lives in Theme Settings → Branding; this block renders
 * it inline (server-side) and exposes link / accessibility options.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el                = wp.element.createElement;
	var Fragment          = wp.element.Fragment;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var ServerSideRender  = wp.serverSideRender;
	var PanelBody         = wp.components.PanelBody;
	var ToggleControl     = wp.components.ToggleControl;
	var TextControl       = wp.components.TextControl;
	var __                = wp.i18n.__;

	registerBlockType( 'ekwa/svg-logo', {

		edit: function ( props ) {
			var a          = props.attributes;
			var setAttrs   = props.setAttributes;
			var blockProps = useBlockProps();

			var controls = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Logo Link', 'ekwa' ), initialOpen: true },
					el( ToggleControl, {
						label:    __( 'Link to home page', 'ekwa' ),
						checked:  a.linkToHome,
						onChange: function ( v ) { setAttrs( { linkToHome: v } ); },
					} ),
					a.linkToHome && el( TextControl, {
						label:       __( 'Custom URL (optional)', 'ekwa' ),
						help:        __( 'Leave empty to link to the site home page.', 'ekwa' ),
						value:       a.customUrl,
						onChange:    function ( v ) { setAttrs( { customUrl: v } ); },
					} ),
					el( TextControl, {
						label:    __( 'Accessible label (aria-label)', 'ekwa' ),
						value:    a.ariaLabel,
						onChange: function ( v ) { setAttrs( { ariaLabel: v } ); },
					} ),
					el( TextControl, {
						label:    __( 'Max width (px, 0 = none)', 'ekwa' ),
						type:     'number',
						value:    a.maxWidth,
						onChange: function ( v ) { setAttrs( { maxWidth: parseInt( v, 10 ) || 0 } ); },
					} ),
					el( 'p', { style: { marginTop: '12px', fontStyle: 'italic' } },
						__( 'Set the SVG markup in Theme Settings → Branding.', 'ekwa' )
					)
				)
			);

			return el(
				Fragment,
				null,
				controls,
				el(
					'div',
					blockProps,
					el( ServerSideRender, {
						block:      'ekwa/svg-logo',
						attributes: a,
					} )
				)
			);
		},

		save: function () {
			return null;
		},
	} );

} )( window.wp );
