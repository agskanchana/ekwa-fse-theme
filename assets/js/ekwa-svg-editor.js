/**
 * Ekwa SVG Block — Block Editor UI.
 *
 * Inline SVG markup with a live preview. Output is sanitized server-side
 * (ekwa_sanitize_svg_markup) on every render, so pasted markup can't ship
 * scripts or event handlers to the front end.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el                = wp.element.createElement;
	var Fragment          = wp.element.Fragment;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var PanelBody         = wp.components.PanelBody;
	var TextControl       = wp.components.TextControl;
	var TextareaControl   = wp.components.TextareaControl;
	var __                = wp.i18n.__;

	registerBlockType( 'ekwa/svg', {
		edit: function ( props ) {
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;
			var svg           = attributes.svg || '';

			var previewStyle = {};
			if ( attributes.width )  { previewStyle.width  = attributes.width; }
			if ( attributes.height ) { previewStyle.height = attributes.height; }

			var blockProps = useBlockProps( { style: previewStyle } );

			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'SVG Settings' ), initialOpen: true },
						el( TextareaControl, {
							label: __( 'SVG markup' ),
							help: __( 'Sanitized on output — scripts and event handlers are stripped.' ),
							value: svg,
							rows: 8,
							onChange: function ( val ) { setAttributes( { svg: val } ); },
						} ),
						el( TextControl, {
							label: __( 'Width (CSS length, optional)' ),
							value: attributes.width || '',
							onChange: function ( val ) { setAttributes( { width: val } ); },
						} ),
						el( TextControl, {
							label: __( 'Height (CSS length, optional)' ),
							value: attributes.height || '',
							onChange: function ( val ) { setAttributes( { height: val } ); },
						} ),
						el( TextControl, {
							label: __( 'Aria label (optional)' ),
							help: __( 'Describe the graphic for screen readers; leave empty for decorative SVGs.' ),
							value: attributes.ariaLabel || '',
							onChange: function ( val ) { setAttributes( { ariaLabel: val } ); },
						} )
					)
				),
				svg
					? el( 'span', Object.assign( {}, blockProps, {
						dangerouslySetInnerHTML: { __html: svg },
					} ) )
					: el( 'span', Object.assign( {}, blockProps, {
						style: Object.assign( {}, previewStyle, {
							display: 'inline-block',
							padding: '12px 16px',
							border: '1px dashed #b3b3b3',
							color: '#555',
							fontSize: '13px',
						} ),
					} ), __( 'Ekwa SVG — paste markup in the sidebar.' ) )
			);
		},

		save: function () {
			return null;
		},
	} );
} )( window.wp );
