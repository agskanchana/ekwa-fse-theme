/**
 * Ekwa FAQ Container Block — Block Editor UI.
 *
 * Content-style FAQ: holds alternating ekwa/faq-question + ekwa/faq-answer
 * children. Frontend renders <h2>/<div> markup plus FAQPage JSON-LD schema.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el                = wp.element.createElement;
	var Fragment          = wp.element.Fragment;
	var InnerBlocks       = wp.blockEditor.InnerBlocks;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var PanelBody         = wp.components.PanelBody;
	var ToggleControl     = wp.components.ToggleControl;
	var __                = wp.i18n.__;

	var ALLOWED  = [ 'ekwa/faq-question', 'ekwa/faq-answer' ];
	var TEMPLATE = [
		[ 'ekwa/faq-question', { content: '' } ],
		[ 'ekwa/faq-answer', {}, [
			[ 'core/paragraph', { placeholder: 'Write the answer…' } ],
		] ],
	];

	function Edit( props ) {
		var attrs    = props.attributes;
		var setAttrs = props.setAttributes;

		var blockProps = useBlockProps( { className: 'ekwa-faq-container' } );

		return el( Fragment, null,

			el( InspectorControls, null,
				el( PanelBody, { title: __( 'FAQ Container', 'ekwa' ), initialOpen: true },
					el( ToggleControl, {
						label:    __( 'Output FAQPage schema (JSON-LD)', 'ekwa' ),
						help:     __( 'Adds Google-recognised FAQ schema markup.', 'ekwa' ),
						checked:  !! attrs.emitSchema,
						onChange: function ( v ) { setAttrs( { emitSchema: v } ); },
						__nextHasNoMarginBottom: true,
					} )
				)
			),

			el( 'div', blockProps,
				el( InnerBlocks, {
					allowedBlocks: ALLOWED,
					template:      TEMPLATE,
					templateLock:  'all',
					orientation:   'vertical',
				} )
			)
		);
	}

	registerBlockType( 'ekwa/faq-container', {
		edit: Edit,
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );

} )( window.wp );
