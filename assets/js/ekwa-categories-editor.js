( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;

	registerBlockType( 'ekwa/categories', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Settings' ), initialOpen: true },
						el( TextControl, { label: __( 'Heading' ), value: a.title, onChange: function ( v ) { set( { title: v } ); } } )
					)
				),
				el( 'div', useBlockProps(),
					el( ServerSideRender, { block: 'ekwa/categories', attributes: a } )
				)
			);
		},
		save: function () { return null; },
	} );
} )( window.wp );
