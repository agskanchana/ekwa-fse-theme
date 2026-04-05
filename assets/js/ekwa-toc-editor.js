( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var __ = wp.i18n.__;

	registerBlockType( 'ekwa/toc', {
		edit: function ( props ) {
			var title = props.attributes.title || 'Table of Contents';
			return el( wp.element.Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Settings' ) },
						el( TextControl, {
							label: __( 'Title' ),
							value: title,
							onChange: function ( v ) { props.setAttributes( { title: v } ); },
						} )
					)
				),
				el( 'div', useBlockProps( {
					style: { background: '#f9fafb', borderRadius: '10px', padding: '20px', border: '1px solid #e5e7eb' },
				} ),
					el( 'p', { style: { fontWeight: 600, marginBottom: '10px', fontSize: '14px' } },
						el( 'i', { className: 'fa-solid fa-bookmark', style: { marginRight: '6px', color: '#6366f1' } } ),
						title
					),
					el( 'p', { style: { fontSize: '12px', color: '#9ca3af', margin: 0 } },
						__( 'Auto-generated from post headings (H2/H3).', 'ekwa' )
					)
				)
			);
		},
		save: function () { return null; },
	} );
} )( window.wp );
