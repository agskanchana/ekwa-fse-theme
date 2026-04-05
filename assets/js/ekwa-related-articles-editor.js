( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var RangeControl = wp.components.RangeControl;
	var ToggleControl = wp.components.ToggleControl;
	var TextControl = wp.components.TextControl;
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;

	registerBlockType( 'ekwa/related-articles', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Settings' ), initialOpen: true },
						el( TextControl, { label: __( 'Title' ), value: a.title, onChange: function ( v ) { set( { title: v } ); } } ),
						el( RangeControl, { label: __( 'Posts to show' ), value: a.count, min: 2, max: 12, onChange: function ( v ) { set( { count: v } ); } } ),
						el( RangeControl, { label: __( 'Desktop items' ), value: a.desktopItems, min: 1, max: 4, onChange: function ( v ) { set( { desktopItems: v } ); } } ),
						el( RangeControl, { label: __( 'Tablet items' ), value: a.tabletItems, min: 1, max: 3, onChange: function ( v ) { set( { tabletItems: v } ); } } ),
						el( RangeControl, { label: __( 'Mobile items' ), value: a.mobileItems, min: 1, max: 2, onChange: function ( v ) { set( { mobileItems: v } ); } } ),
						el( ToggleControl, { label: __( 'Show arrows' ), checked: a.showArrows, onChange: function ( v ) { set( { showArrows: v } ); } } ),
						el( ToggleControl, { label: __( 'Show dots' ), checked: a.showDots, onChange: function ( v ) { set( { showDots: v } ); } } )
					)
				),
				el( 'div', useBlockProps(),
					el( ServerSideRender, { block: 'ekwa/related-articles', attributes: a } )
				)
			);
		},
		save: function () { return null; },
	} );
} )( window.wp );
