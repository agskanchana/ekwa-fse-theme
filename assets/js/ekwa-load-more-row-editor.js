/**
 * Ekwa Load More Row — a single row inside ekwa/load-more-rows.
 * Holds any inner blocks; the parent handles show/hide on the front end.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el                = wp.element.createElement;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var InnerBlocks        = wp.blockEditor.InnerBlocks;

	registerBlockType( 'ekwa/load-more-row', {
		edit: function () {
			var blockProps = useBlockProps( { className: 'ekwa-lmr__row ekwa-lmr__row--editor' } );
			return el(
				'div',
				blockProps,
				el( InnerBlocks, { templateLock: false } )
			);
		},

		save: function () {
			return el( InnerBlocks.Content );
		},
	} );

} )( window.wp );
