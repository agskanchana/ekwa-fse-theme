/**
 * Ekwa FAQ Answer Block — Block Editor UI.
 *
 * Child of ekwa/faq-container. Holds the body content of an answer via
 * InnerBlocks. Frontend wraps the content in a styled div.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el                = wp.element.createElement;
	var InnerBlocks       = wp.blockEditor.InnerBlocks;
	var useBlockProps     = wp.blockEditor.useBlockProps;

	var ALLOWED = [
		'core/paragraph',
		'core/list',
		'core/heading',
		'core/image',
		'ekwa/image',
		'ekwa/figure',
		'ekwa/div',
	];

	var TEMPLATE = [
		[ 'core/paragraph', { placeholder: 'Write the answer…' } ],
	];

	function Edit() {
		var blockProps = useBlockProps( { className: 'ekwa-faq-container__a' } );

		return el( 'div', blockProps,
			el( InnerBlocks, {
				allowedBlocks: ALLOWED,
				template:      TEMPLATE,
				templateLock:  false,
			} )
		);
	}

	registerBlockType( 'ekwa/faq-answer', {
		edit: Edit,
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );

} )( window.wp );
