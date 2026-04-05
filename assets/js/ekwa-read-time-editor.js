( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var RangeControl = wp.components.RangeControl;
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;

	registerBlockType( 'ekwa/read-time', {
		edit: function ( props ) {
			return el( wp.element.Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Settings' ) },
						el( RangeControl, {
							label: __( 'Words per minute' ),
							value: props.attributes.wordsPerMinute,
							min: 100, max: 400, step: 10,
							onChange: function ( v ) { props.setAttributes( { wordsPerMinute: v } ); },
						} )
					)
				),
				el( 'div', useBlockProps( { style: { display: 'inline' } } ),
					el( ServerSideRender, { block: 'ekwa/read-time', attributes: props.attributes } )
				)
			);
		},
		save: function () { return null; },
	} );
} )( window.wp );
