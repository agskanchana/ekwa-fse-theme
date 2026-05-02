/**
 * Ekwa FAQ Item Block — Block Editor UI.
 *
 * Single question + answer (InnerBlocks). Lives inside ekwa/faq.
 * In the editor we render an open <details>-like preview so the
 * answer InnerBlocks are always visible/editable.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el                = wp.element.createElement;
	var Fragment          = wp.element.Fragment;
	var InnerBlocks       = wp.blockEditor.InnerBlocks;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var RichText          = wp.blockEditor.RichText;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var PanelBody         = wp.components.PanelBody;
	var ToggleControl     = wp.components.ToggleControl;
	var __                = wp.i18n.__;

	var TEMPLATE = [
		[ 'core/paragraph', { placeholder: 'Write the answer…' } ],
	];

	function Edit( props ) {
		var attrs    = props.attributes;
		var setAttrs = props.setAttributes;

		var defaultOpen = !! attrs.defaultOpen;

		var blockProps = useBlockProps( {
			className: 'ekwa-faq__item' + ( defaultOpen ? ' is-default-open' : '' ),
		} );

		return el( Fragment, null,

			el( InspectorControls, null,
				el( PanelBody, { title: __( 'FAQ Item', 'ekwa' ), initialOpen: true },
					el( ToggleControl, {
						label:   __( 'Open by default', 'ekwa' ),
						help:    __( 'This item starts expanded on page load.', 'ekwa' ),
						checked: defaultOpen,
						onChange: function ( v ) { setAttrs( { defaultOpen: v } ); },
						__nextHasNoMarginBottom: true,
					} )
				)
			),

			el( 'div', blockProps,
				el( 'div', { className: 'ekwa-faq__q' },
					el( RichText, {
						tagName:           'span',
						className:         'ekwa-faq__q-text',
						value:             attrs.question || '',
						onChange:          function ( v ) { setAttrs( { question: v } ); },
						placeholder:       __( 'Type the question…', 'ekwa' ),
						allowedFormats:    [ 'core/bold', 'core/italic' ],
						multiline:         false,
					} ),
					el( 'span', { className: 'ekwa-faq__icon', 'aria-hidden': 'true' },
						el( 'i', { className: 'fa-solid fa-chevron-down' } )
					)
				),
				el( 'div', { className: 'ekwa-faq__a' },
					el( InnerBlocks, {
						template:     TEMPLATE,
						templateLock: false,
					} )
				)
			)
		);
	}

	registerBlockType( 'ekwa/faq-item', {
		edit: Edit,
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );

} )( window.wp );
