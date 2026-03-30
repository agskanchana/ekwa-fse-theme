/**
 * Ekwa Address Block — Block Editor UI.
 *
 * Renders a live server-side preview in the editor and exposes
 * InspectorControls that map 1-to-1 with the [ekwa_address] shortcode
 * attributes. Default mode is 'full' (full address including city/state/zip).
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

	registerBlockType( 'ekwa/address', {

		edit: function ( props ) {
			var attrs    = props.attributes;
			var setAttrs = props.setAttributes;

			var blockProps = useBlockProps( {
				className: 'ekwa-address-block-wrapper',
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
						{ title: __( 'Address Settings', 'ekwa' ), initialOpen: true },

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

						el( SelectControl, {
							label:   __( 'Display Mode', 'ekwa' ),
							value:   attrs.mode,
							options: [
								{ label: __( 'Full Address (Street + City/State/Zip)', 'ekwa' ), value: 'full' },
								{ label: __( 'Address (City, State)',                   'ekwa' ), value: 'address' },
								{ label: __( 'Directions Label',                        'ekwa' ), value: 'text' },
								{ label: __( 'Icon Only',                               'ekwa' ), value: 'icon' },
							],
							onChange: function ( value ) {
								setAttrs( { mode: value } );
							},
						} ),

						'text' === attrs.mode && el( TextControl, {
							label:    __( 'Custom Label', 'ekwa' ),
							help:     __( 'Shown as the link text. Defaults to "Directions".', 'ekwa' ),
							value:    attrs.label,
							onChange: function ( value ) {
								setAttrs( { label: value } );
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
							help:     __( 'Font Awesome class string, e.g. fa-solid fa-location-dot', 'ekwa' ),
							value:    attrs.iconClass,
							onChange: function ( value ) {
								setAttrs( { iconClass: value } );
							},
						} ),

						el( ToggleControl, {
							label:    __( 'Open in New Tab', 'ekwa' ),
							checked:  attrs.newTab,
							onChange: function ( value ) {
								setAttrs( { newTab: value } );
							},
						} )
					)
				),

				/* ---- Editor preview ---- */
				el(
					'div',
					blockProps,
					el( ServerSideRender, {
						block:      'ekwa/address',
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
