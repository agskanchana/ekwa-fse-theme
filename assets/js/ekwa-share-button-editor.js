( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var __ = wp.i18n.__;

	registerBlockType( 'ekwa/share-button', {
		edit: function () {
			return el( 'div', useBlockProps( {
				style: { display: 'inline-flex', alignItems: 'center', gap: '6px', padding: '6px 14px', background: '#f0f0f0', borderRadius: '4px', fontSize: '13px', color: '#666' },
			} ),
				el( 'i', { className: 'fa-solid fa-share-nodes', 'aria-hidden': 'true' } ),
				__( 'Share (loaded on front-end)', 'ekwa' )
			);
		},
		save: function () { return null; },
	} );
} )( window.wp );
