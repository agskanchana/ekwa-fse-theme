/**
 * Ekwa – Phone Number Button extension for core/button block.
 *
 * Adds a "Phone Number" panel to the button inspector.
 * When enabled, the server replaces the button href with a tel: link
 * at render time (including ad-tracking logic).
 */
( function ( wp ) {
	'use strict';

	var addFilter               = wp.hooks.addFilter;
	var createHigherOrderComponent = wp.compose.createHigherOrderComponent;
	var el                      = wp.element.createElement;
	var Fragment                = wp.element.Fragment;
	var InspectorControls       = wp.blockEditor.InspectorControls;
	var PanelBody               = wp.components.PanelBody;
	var PanelRow                = wp.components.PanelRow;
	var ToggleControl           = wp.components.ToggleControl;
	var SelectControl           = wp.components.SelectControl;
	var TextControl             = wp.components.TextControl;
	var Notice                  = wp.components.Notice;
	var __                      = wp.i18n.__;

	/* ------------------------------------------------------------------
	 * 1. Register custom attributes on core/button
	 * ------------------------------------------------------------------ */
	addFilter(
		'blocks.registerBlockType',
		'ekwa/button-phone-attributes',
		function ( settings, name ) {
			if ( 'core/button' !== name ) {
				return settings;
			}
			settings.attributes = Object.assign( {}, settings.attributes, {
				ekwaPhoneButton:   { type: 'boolean', default: false },
				ekwaPhoneType:     { type: 'string',  default: 'new' },
				ekwaPhoneLocation: { type: 'number',  default: 1 },
			} );
			return settings;
		}
	);

	/* ------------------------------------------------------------------
	 * 2. Add inspector panel to core/button edit component
	 * ------------------------------------------------------------------ */
	var withPhoneControls = createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			if ( 'core/button' !== props.name ) {
				return el( BlockEdit, props );
			}

			var attrs    = props.attributes;
			var setAttrs = props.setAttributes;
			var enabled  = !! attrs.ekwaPhoneButton;

			return el(
				Fragment,
				null,

				el( BlockEdit, props ),

				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{
							title: __( 'Phone Number', 'ekwa' ),
							initialOpen: false,
						},

						el( ToggleControl, {
							label:    __( 'Use as phone number button', 'ekwa' ),
							help:     enabled
								? __( 'The link will be replaced with a tel: number on the front end.', 'ekwa' )
								: __( 'Enable to auto-populate the link with a saved phone number.', 'ekwa' ),
							checked:  enabled,
							onChange: function ( val ) {
								setAttrs( { ekwaPhoneButton: val } );
							},
						} ),

						enabled && el( SelectControl, {
							label:    __( 'Phone Type', 'ekwa' ),
							value:    attrs.ekwaPhoneType,
							options: [
								{ label: __( 'New Patients',      'ekwa' ), value: 'new'      },
								{ label: __( 'Existing Patients', 'ekwa' ), value: 'existing' },
							],
							onChange: function ( val ) {
								setAttrs( { ekwaPhoneType: val } );
							},
						} ),

						enabled && el( PanelRow, null,
							el( TextControl, {
								label:    __( 'Location', 'ekwa' ),
								type:     'number',
								min:      1,
								value:    attrs.ekwaPhoneLocation,
								onChange: function ( val ) {
									setAttrs( { ekwaPhoneLocation: Math.max( 1, parseInt( val, 10 ) || 1 ) } );
								},
							} )
						),

						enabled && el(
							Notice,
							{
								status:      'info',
								isDismissible: false,
							},
							__( 'Leave the button URL blank — the phone number is injected automatically on the front end. Ad-tracking (adward_number cookie / ?ads) is respected.', 'ekwa' )
						)
					)
				)
			);
		};
	}, 'withPhoneControls' );

	addFilter(
		'editor.BlockEdit',
		'ekwa/button-phone-controls',
		withPhoneControls
	);

} )( window.wp );
