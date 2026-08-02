/**
 * Ekwa Link Block — Block Editor UI.
 *
 * Clean anchor element — outputs only your classes, no button styles.
 * Output: <a href="..." class="...">text</a>
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
	var ToggleControl      = wp.components.ToggleControl;
	var __                 = wp.i18n.__;
	var LinkSourceControls = window.EkwaLinkSource && window.EkwaLinkSource.Controls;
	var CustomAttrsControl = window.EkwaCustomAttributes && window.EkwaCustomAttributes.Control;
	var InlineStyle        = window.EkwaInlineStyle || null;

	registerBlockType( 'ekwa/link', {
		edit: function ( props ) {
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;

			var text   = attributes.text   || '';
			var newTab = !! attributes.newTab;
			var rel    = attributes.rel    || '';

			// Preview the mockup's own inline style so the editor matches the
			// front end (the converter carries it into `inlineStyle`).
			var previewStyle = InlineStyle ? InlineStyle.parse( attributes.inlineStyle ) : {};
			previewStyle = Object.assign(
				{ display: 'inline-block', cursor: 'pointer' },
				previewStyle
			);

			var blockProps = useBlockProps( { style: previewStyle } );

			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Link Settings' ), initialOpen: true },
						LinkSourceControls && el( LinkSourceControls, {
							attributes:    attributes,
							setAttributes: setAttributes
						} ),
						el( TextControl, {
							label: __( 'Link Text' ),
							value: text,
							onChange: function ( val ) { setAttributes( { text: val } ); },
						} ),
						el( ToggleControl, {
							label: __( 'Open in new tab' ),
							checked: newTab,
							onChange: function ( val ) { setAttributes( { newTab: val } ); },
						} ),
						el( TextControl, {
							label: __( 'Rel attribute' ),
							value: rel,
							onChange: function ( val ) { setAttributes( { rel: val } ); },
							help: __( 'e.g. nofollow, sponsored' ),
						} ),
						InlineStyle && el( InlineStyle.Control, {
							attributes:    attributes,
							setAttributes: setAttributes,
						} )
					),
					CustomAttrsControl && el( CustomAttrsControl, {
						attributes:    attributes,
						setAttributes: setAttributes,
					} )
				),
				el( 'span', blockProps,
					text || __( '(Link — set text in sidebar)' )
				)
			);
		},

		save: function () {
			return null;
		},
	} );
} )( window.wp );
