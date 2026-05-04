( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var RangeControl = wp.components.RangeControl;
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;

	registerBlockType( 'ekwa/recent-posts', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Settings' ), initialOpen: true },
						el( TextControl, { label: __( 'Heading' ), value: a.title, onChange: function ( v ) { set( { title: v } ); } } ),
						el( RangeControl, { label: __( 'Posts to show' ), value: a.count, min: 1, max: 12, onChange: function ( v ) { set( { count: v } ); } } )
					)
				),
				el( 'div', useBlockProps(),
					el( ServerSideRender, { block: 'ekwa/recent-posts', attributes: a } )
				)
			);
		},
		save: function () { return null; },
	} );
} )( window.wp );
