( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var __ = wp.i18n.__;

	registerBlockType( 'ekwa/load-more', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Settings' ) },
						el( TextControl, { label: __( 'Button text' ), value: a.buttonText, onChange: function ( v ) { set( { buttonText: v } ); } } ),
						el( TextControl, { label: __( 'Loading text' ), value: a.loadingText, onChange: function ( v ) { set( { loadingText: v } ); } } ),
						el( TextControl, { label: __( 'No more text' ), value: a.noMoreText, onChange: function ( v ) { set( { noMoreText: v } ); } } )
					)
				),
				el( 'div', useBlockProps( { style: { textAlign: 'center', padding: '16px' } } ),
					el( 'button', {
						className: 'ekwa-load-more__btn',
						style: { padding: '10px 28px', fontSize: '14px', borderRadius: '6px', border: '1px solid #ccc', cursor: 'pointer', background: '#fff' },
						disabled: true,
					}, a.buttonText )
				)
			);
		},
		save: function () { return null; },
	} );
} )( window.wp );
