/**
 * Ekwa Figure Block — Block Editor UI.
 *
 * Renders an actual <figure> in the editor so any CSS targeting figure applies.
 * Accepts any inner blocks; pairs naturally with ekwa/image + ekwa/text
 * (with tagName=figcaption) for image-plus-caption layouts.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType  = wp.blocks.registerBlockType;
	var el                 = wp.element.createElement;
	var Fragment           = wp.element.Fragment;
	var InnerBlocks        = wp.blockEditor.InnerBlocks;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var PanelBody          = wp.components.PanelBody;
	var __                 = wp.i18n.__;
	var CustomAttrsControl = window.EkwaCustomAttributes && window.EkwaCustomAttributes.Control;

	registerBlockType( 'ekwa/figure', {
		edit: function ( props ) {
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;

			var blockProps = useBlockProps.save
				? useBlockProps( { className: attributes.className || '' } )
				: { className: attributes.className || '' };

			// Render as an actual <figure> in the editor so figure-targeted CSS works.
			var inner = el(
				'figure',
				blockProps,
				el( InnerBlocks, {
					template: [
						[ 'ekwa/image', {} ],
					],
					templateLock: false,
				} )
			);

			return el(
				Fragment,
				null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Figure', 'ekwa' ), initialOpen: true },
						el( 'p', { style: { fontSize: 12, color: '#646970' } },
							__( 'Add an Ekwa Image block and (optionally) an Ekwa Text block with tag = figcaption for the caption.', 'ekwa' )
						),
						CustomAttrsControl ? el( CustomAttrsControl, {
							value:    attributes.customAttributes || {},
							onChange: function ( v ) { setAttributes( { customAttributes: v } ); },
						} ) : null
					)
				),
				inner
			);
		},
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );

} )( window.wp );
