( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var ServerSideRender = wp.serverSideRender;

	registerBlockType( 'ekwa/back-to-category', {
		edit: function () {
			return el( 'div', useBlockProps(),
				el( ServerSideRender, { block: 'ekwa/back-to-category' } )
			);
		},
		save: function () { return null; },
	} );
} )( window.wp );
