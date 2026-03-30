/**
 * Ekwa Phone Number Block — Block Editor UI.
 *
 * Renders a live server-side preview in the editor and exposes
 * InspectorControls that map 1-to-1 with the [ekwa_phone] shortcode
 * attributes.
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
	var RangeControl       = wp.components.RangeControl;
	var ServerSideRender   = wp.serverSideRender;
	var __                 = wp.i18n.__;

	registerBlockType( 'ekwa/phone', {

		edit: function ( props ) {
			var attrs    = props.attributes;
			var setAttrs = props.setAttributes;

			var blockProps = useBlockProps( {
				className: 'ekwa-phone-block-wrapper',
				style: { display: 'inline-block' },
			} );

			return el(
				Fragment,
				null,

				/* ---- Inspector sidebar ---- */
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Phone Settings', 'ekwa' ), initialOpen: true },

						el( SelectControl, {
							label:   __( 'Patient Type', 'ekwa' ),
							value:   attrs.type,
							options: [
								{ label: __( 'New Patients', 'ekwa' ),      value: 'new' },
								{ label: __( 'Existing Patients', 'ekwa' ), value: 'existing' },
							],
							onChange: function ( value ) {
								setAttrs( { type: value } );
							},
						} ),

						el( RangeControl, {
							label:   __( 'Location', 'ekwa' ),
							value:   attrs.location,
							min:     1,
							max:     10,
							step:    1,
							onChange: function ( value ) {
								setAttrs( { location: value } );
							},
						} ),

						el( TextControl, {
							label:    __( 'Prefix Label', 'ekwa' ),
							help:     __( 'Leave blank to use the default label (e.g. "New Patients:").', 'ekwa' ),
							value:    attrs.prefix,
							onChange: function ( value ) {
								setAttrs( { prefix: value } );
							},
						} ),

						el( ToggleControl, {
							label:    __( 'Show Icon', 'ekwa' ),
							checked:  attrs.showIcon,
							onChange: function ( value ) {
								setAttrs( { showIcon: value } );
							},
						} ),

						attrs.showIcon && el( TextControl, {
							label:    __( 'Icon Class', 'ekwa' ),
							help:     __( 'Font Awesome class string, e.g. fa-solid fa-phone', 'ekwa' ),
							value:    attrs.iconClass,
							onChange: function ( value ) {
								setAttrs( { iconClass: value } );
							},
						} ),

						el( TextControl, {
							label:    __( 'Country Code Override', 'ekwa' ),
							help:     __( 'Digits only (e.g. 44 for UK). Leave blank for auto-detect. Use "none" to suppress.', 'ekwa' ),
							value:    attrs.countryCode,
							onChange: function ( value ) {
								setAttrs( { countryCode: value } );
							},
						} )
					)
				),

				/* ---- Editor preview ---- */
				el(
					'div',
					blockProps,
					el( ServerSideRender, {
						block:      'ekwa/phone',
						attributes: attrs,
					} )
				)
			);
		},

		// No save — fully server-side rendered.
		save: function () {
			return null;
		},
	} );

} )( window.wp );
