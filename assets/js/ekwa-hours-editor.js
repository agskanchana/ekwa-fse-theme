/**
 * Ekwa Working Hours Block — Block Editor UI.
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

	registerBlockType( 'ekwa/hours', {

		edit: function ( props ) {
			var attrs    = props.attributes;
			var setAttrs = props.setAttributes;

			var blockProps = useBlockProps( {
				className: 'ekwa-hours-block-wrapper',
			} );

			return el(
				Fragment,
				null,

				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Hours Settings', 'ekwa' ), initialOpen: true },

						el( RangeControl, {
							label:   __( 'Location', 'ekwa' ),
							value:   attrs.location,
							min:     1,
							max:     10,
							step:    1,
							onChange: function ( v ) { setAttrs( { location: v } ); },
						} ),

						el( SelectControl, {
							label:   __( 'Day Grouping', 'ekwa' ),
							value:   attrs.group,
							options: [
								{ label: __( 'None — one row per day', 'ekwa' ),           value: 'none' },
								{ label: __( 'Consecutive — e.g. Mon – Fri: 9–5', 'ekwa' ), value: 'consecutive' },
								{ label: __( 'All — group all days with same hours', 'ekwa' ), value: 'all' },
							],
							onChange: function ( v ) { setAttrs( { group: v } ); },
						} ),

						el( ToggleControl, {
							label:    __( 'Show Closed Days', 'ekwa' ),
							checked:  attrs.showClosed,
							onChange: function ( v ) { setAttrs( { showClosed: v } ); },
						} ),

						el( ToggleControl, {
							label:    __( 'Abbreviate Day Names', 'ekwa' ),
							help:     __( 'Shows Mon/Tue instead of Monday/Tuesday.', 'ekwa' ),
							checked:  attrs.shortDays,
							onChange: function ( v ) { setAttrs( { shortDays: v } ); },
						} ),

						el( ToggleControl, {
							label:    __( 'Show Extra Notes', 'ekwa' ),
							checked:  attrs.showNotes,
							onChange: function ( v ) { setAttrs( { showNotes: v } ); },
						} ),

						el( TextControl, {
							label:    __( 'Closed Day Label', 'ekwa' ),
							value:    attrs.closedLabel,
							onChange: function ( v ) { setAttrs( { closedLabel: v } ); },
						} )
					)
				),

				el(
					'div',
					blockProps,
					el( ServerSideRender, {
						block:      'ekwa/hours',
						attributes: attrs,
					} )
				)
			);
		},

		save: function () {
			return null;
		},
	} );

} )( window.wp );
