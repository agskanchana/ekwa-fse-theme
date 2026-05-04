/**
 * Ekwa Reveal Hidden Block — Block Editor UI.
 *
 * Marks a region inside an ekwa/reveal block as hidden until the trigger
 * button is clicked on the front-end. In the editor it stays expanded with
 * a labelled outline so the author can edit it.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el                = wp.element.createElement;
	var InnerBlocks       = wp.blockEditor.InnerBlocks;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var __                = wp.i18n.__;

	var TEMPLATE = [
		[ 'core/paragraph', { placeholder: 'Hidden content — revealed when the trigger is clicked.' } ],
	];

	registerBlockType( 'ekwa/reveal-hidden', {
		edit: function () {
			var blockProps = useBlockProps( { className: 'reveal-hidden' } );
			return el( 'div', blockProps,
				el( InnerBlocks, {
					template: TEMPLATE,
					templateLock: false,
				} )
			);
		},

		save: function () {
			return el( InnerBlocks.Content, null );
		},
	} );
} )( window.wp );
