/**
 * Ekwa Div Block — Block Editor UI.
 *
 * Clean wrapper element that outputs only your tag, classes, and children.
 * Output: <div class="...">children</div> or <section class="...">children</section> etc.
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
	var SelectControl      = wp.components.SelectControl;
	var __                 = wp.i18n.__;

	var TAG_OPTIONS = [
		{ label: 'div',     value: 'div' },
		{ label: 'section', value: 'section' },
		{ label: 'header',  value: 'header' },
		{ label: 'footer',  value: 'footer' },
		{ label: 'nav',     value: 'nav' },
		{ label: 'main',    value: 'main' },
		{ label: 'aside',   value: 'aside' },
		{ label: 'article', value: 'article' },
	];

	registerBlockType( 'ekwa/div', {
		edit: function ( props ) {
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;
			var tagName       = attributes.tagName || 'div';

			var blockProps = useBlockProps( {
				style: {
					border: '1px dashed #ccc',
					padding: '4px',
					minHeight: '32px',
					position: 'relative',
				},
			} );

			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Element Settings' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'HTML Tag' ),
							value: tagName,
							options: TAG_OPTIONS,
							onChange: function ( val ) { setAttributes( { tagName: val } ); },
						} )
					)
				),
				el( 'div', blockProps,
					el( 'span', {
						style: {
							position: 'absolute',
							top: '-10px',
							left: '8px',
							fontSize: '10px',
							fontFamily: 'monospace',
							color: '#999',
							backgroundColor: '#fff',
							padding: '0 4px',
							lineHeight: '1.4',
							zIndex: 1,
						},
					}, '<' + tagName + '>' ),
					el( InnerBlocks, null )
				)
			);
		},

		save: function () {
			return el( InnerBlocks.Content, null );
		},
	} );
} )( window.wp );
