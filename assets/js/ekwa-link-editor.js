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

	registerBlockType( 'ekwa/link', {
		edit: function ( props ) {
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;

			var url    = attributes.url    || '';
			var text   = attributes.text   || '';
			var newTab = !! attributes.newTab;
			var rel    = attributes.rel    || '';

			var blockProps = useBlockProps( {
				style: {
					display: 'inline-block',
					cursor: 'pointer',
				},
			} );

			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Link Settings' ), initialOpen: true },
						el( TextControl, {
							label: __( 'URL' ),
							value: url,
							onChange: function ( val ) { setAttributes( { url: val } ); },
							type: 'url',
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
						} )
					)
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
