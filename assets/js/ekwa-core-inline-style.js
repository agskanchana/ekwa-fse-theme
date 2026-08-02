/**
 * Ekwa Inline Style on core blocks — editor side.
 *
 * The converter keeps a mockup's `style` attribute on core/heading,
 * core/paragraph, core/list, core/quote and core/table by storing it as an
 * `inlineStyle` attribute in the block comment (see inc/ekwa-core-inline-style.php
 * for why it can't go on the tag). Three jobs here:
 *
 *   1. Declare the attribute — Gutenberg discards comment keys the block type
 *      doesn't know about, so without this, opening and saving the page wipes
 *      every converted style.
 *   2. Show it in the sidebar so it can be edited or cleared.
 *   3. Preview it on the canvas, since the front end applies it via render_block.
 *
 * Deliberately no save() change: the attribute must NOT reach the saved markup
 * or core's block validation rejects the block.
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

	var cfg     = window.ekwaCoreInlineStyle || {};
	var TARGETS = cfg.blocks || [ 'core/heading', 'core/paragraph', 'core/list', 'core/quote', 'core/table' ];

	var shared  = window.EkwaInlineStyle || null;

	function isTarget( name ) {
		return TARGETS.indexOf( name ) !== -1;
	}

	/** Fall back to a local parse when the shared control isn't loaded. */
	function parseStyle( str ) {
		if ( shared ) { return shared.parse( str ); }
		if ( ! str ) { return {}; }
		var out = {};
		String( str ).split( ';' ).forEach( function ( part ) {
			part = part.trim();
			var colon = part.indexOf( ':' );
			if ( colon < 1 ) { return; }
			var key = part.substring( 0, colon ).trim();
			var val = part.substring( colon + 1 ).trim();
			if ( key.indexOf( '--' ) !== 0 ) {
				key = key.replace( /-([a-z])/g, function ( m, c ) { return c.toUpperCase(); } );
			}
			out[ key ] = val;
		} );
		return out;
	}

	// 1. Declare the attribute.
	addFilter( 'blocks.registerBlockType', 'ekwa/core-inline-style-attr', function ( settings, name ) {
		if ( ! isTarget( name ) ) {
			return settings;
		}
		settings.attributes = Object.assign( {}, settings.attributes, {
			inlineStyle: { type: 'string', default: '' },
		} );
		return settings;
	} );

	// 2. Sidebar panel — only for blocks that actually carry a style, so the
	//    inspector of a hand-inserted paragraph stays uncluttered.
	var withInlineStylePanel = createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			if ( ! isTarget( props.name ) ) {
				return el( BlockEdit, props );
			}

			var value = props.attributes.inlineStyle || '';
			if ( ! value && ! props.isSelected ) {
				return el( BlockEdit, props );
			}

			return el( Fragment, null,
				el( BlockEdit, props ),
				el( InspectorControls, null,
					el( PanelBody, {
						title: __( 'Inline Style', 'ekwa' ),
						initialOpen: false,
					},
						el( 'p', { style: { fontSize: '12px', color: '#757575', marginTop: 0 } },
							__( 'Raw CSS carried over from the mockup. Applied to this element on the front end.', 'ekwa' )
						),
						el( TextareaControl, {
							label: __( 'CSS declarations', 'ekwa' ),
							help:  __( 'e.g. color: #fff; margin-bottom: 0', 'ekwa' ),
							value: value,
							rows:  3,
							onChange: function ( v ) { props.setAttributes( { inlineStyle: v } ); },
							__nextHasNoMarginBottom: true,
						} )
					)
				)
			);
		};
	}, 'withEkwaCoreInlineStylePanel' );
	addFilter( 'editor.BlockEdit', 'ekwa/core-inline-style-panel', withInlineStylePanel );

	// 3. Canvas preview.
	var withInlineStylePreview = createHigherOrderComponent( function ( BlockListBlock ) {
		return function ( props ) {
			var value = props.attributes && props.attributes.inlineStyle;
			if ( ! isTarget( props.name ) || ! value ) {
				return el( BlockListBlock, props );
			}
			var wrapperProps = Object.assign( {}, props.wrapperProps );
			wrapperProps.style = Object.assign( {}, wrapperProps.style, parseStyle( value ) );
			return el( BlockListBlock, Object.assign( {}, props, { wrapperProps: wrapperProps } ) );
		};
	}, 'withEkwaCoreInlineStylePreview' );
	addFilter( 'editor.BlockListBlock', 'ekwa/core-inline-style-preview', withInlineStylePreview );

} )( window.wp );
