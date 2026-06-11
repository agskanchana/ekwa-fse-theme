( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var SelectControl = wp.components.SelectControl;
	var __ = wp.i18n.__;

	registerBlockType( 'ekwa/load-more', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var isNumbered = a.paginationType === 'numbered';

			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Settings' ) },
						el( SelectControl, {
							label: __( 'Pagination type' ),
							value: a.paginationType,
							options: [
								{ label: __( 'Load More button' ), value: 'load-more' },
								{ label: __( 'Numbered pagination' ), value: 'numbered' },
							],
							onChange: function ( v ) { set( { paginationType: v } ); },
						} ),
						! isNumbered && el( TextControl, { label: __( 'Button text' ),   value: a.buttonText,  onChange: function ( v ) { set( { buttonText: v } ); } } ),
						! isNumbered && el( TextControl, { label: __( 'Loading text' ),  value: a.loadingText, onChange: function ( v ) { set( { loadingText: v } ); } } ),
						! isNumbered && el( TextControl, { label: __( 'No more text' ),  value: a.noMoreText,  onChange: function ( v ) { set( { noMoreText: v } ); } } ),
						isNumbered && el( TextControl, { label: __( 'Previous label' ), value: a.prevText,    onChange: function ( v ) { set( { prevText: v } ); } } ),
						isNumbered && el( TextControl, { label: __( 'Next label' ),     value: a.nextText,    onChange: function ( v ) { set( { nextText: v } ); } } )
					)
				),
				isNumbered
					? el( 'div', useBlockProps( { style: { textAlign: 'center', padding: '16px' } } ),
						el( 'div', { className: 'ekwa-load-more__pagination ekwa-load-more__pagination--preview' },
							el( 'button', { className: 'ekwa-pagination__btn ekwa-pagination__prev', disabled: true }, a.prevText ),
							el( 'button', { className: 'ekwa-pagination__btn ekwa-pagination__page is-active' }, '1' ),
							el( 'button', { className: 'ekwa-pagination__btn ekwa-pagination__page' }, '2' ),
							el( 'button', { className: 'ekwa-pagination__btn ekwa-pagination__page' }, '3' ),
							el( 'button', { className: 'ekwa-pagination__btn ekwa-pagination__next' }, a.nextText )
						)
					  )
					: el( 'div', useBlockProps( { style: { textAlign: 'center', padding: '16px' } } ),
						el( 'button', {
							className: 'ekwa-load-more__btn',
							style: { padding: '10px 28px', fontSize: '14px', borderRadius: '999px', border: '2px solid #1f2937', cursor: 'pointer', background: '#fff' },
							disabled: true,
						}, a.buttonText )
					  )
			);
		},
		save: function () { return null; },
	} );
} )( window.wp );
