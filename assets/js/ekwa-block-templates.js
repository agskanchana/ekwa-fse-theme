/**
 * Ekwa Templated Dynamic Blocks — editor panel.
 *
 * Adds a "Custom HTML template (advanced)" panel to the dynamic data blocks
 * (phone, address, hours, social, copyright). Paste the mockup's own markup
 * for the element with {{placeholders}} where the live data goes; the block
 * then renders YOUR markup with the real settings data (see
 * inc/ekwa-block-templates.php). Leave empty for the block's canonical output.
 */
( function ( wp ) {
	'use strict';

	var addFilter         = wp.hooks.addFilter;
	var el                = wp.element.createElement;
	var Fragment          = wp.element.Fragment;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody         = wp.components.PanelBody;
	var TextareaControl   = wp.components.TextareaControl;
	var createHigherOrderComponent = wp.compose.createHigherOrderComponent;
	var __                = wp.i18n.__;

	var cfg          = window.ekwaBlockTemplates || {};
	var PLACEHOLDERS = cfg.placeholders || {};

	function isSupported( name ) {
		return Object.prototype.hasOwnProperty.call( PLACEHOLDERS, name );
	}

	// 1. Register the attribute client-side (server side is registered via
	//    register_block_type_args so ServerSideRender previews accept it).
	addFilter( 'blocks.registerBlockType', 'ekwa/tpl-attr', function ( settings, name ) {
		if ( ! isSupported( name ) ) {
			return settings;
		}
		settings.attributes = Object.assign( {}, settings.attributes, {
			customTemplate: { type: 'string', default: '' },
		} );
		return settings;
	} );

	// 2. The inspector panel.
	var withTemplatePanel = createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			if ( ! isSupported( props.name ) ) {
				return el( BlockEdit, props );
			}

			var value = props.attributes.customTemplate || '';

			return el( Fragment, null,
				el( BlockEdit, props ),
				el( InspectorControls, null,
					el( PanelBody, {
						title: __( 'Custom HTML template (advanced)', 'ekwa' ),
						initialOpen: !! value,
					},
						el( TextareaControl, {
							label: __( 'Template', 'ekwa' ),
							help: __( 'Renders YOUR markup with live settings data. Placeholders: ', 'ekwa' ) + ( PLACEHOLDERS[ props.name ] || '' ) + __( '. Leave empty for the standard output. Scripts/event handlers are stripped.', 'ekwa' ),
							value: value,
							rows: 7,
							onChange: function ( v ) { props.setAttributes( { customTemplate: v } ); },
						} )
					)
				)
			);
		};
	}, 'withEkwaTemplatePanel' );
	addFilter( 'editor.BlockEdit', 'ekwa/tpl-panel', withTemplatePanel );
} )( window.wp );
