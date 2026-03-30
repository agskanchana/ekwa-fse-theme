/**
 * Ekwa Copyright Block — Block Editor UI.
 *
 * No configurable attributes — just renders a live server-side preview.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el                = wp.element.createElement;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var ServerSideRender  = wp.serverSideRender;

	registerBlockType( 'ekwa/copyright', {

		edit: function ( props ) {
			var blockProps = useBlockProps( {
				className: 'ekwa-copyright-block-wrapper',
			} );

			return el(
				'div',
				blockProps,
				el( ServerSideRender, {
					block:      'ekwa/copyright',
					attributes: props.attributes,
				} )
			);
		},

		save: function () {
			return null;
		},
	} );

} )( window.wp );
