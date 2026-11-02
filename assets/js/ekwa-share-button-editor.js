( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components && wp.components.PanelBody;
	var SelectControl = wp.components && wp.components.SelectControl;
	var __ = wp.i18n.__;

	registerBlockType( 'ekwa/share-button', {
		edit: function ( props ) {
			var loadOn = props.attributes.loadOn || 'load';

			var inspector = ( InspectorControls && PanelBody && SelectControl )
				? el( InspectorControls, null,
					el( PanelBody, { title: __( 'Performance', 'ekwa' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Load the share buttons', 'ekwa' ),
							value: loadOn,
							options: [
								{ value: 'load', label: __( 'After the page has loaded (or on first scroll / interaction)', 'ekwa' ) },
								{ value: 'interaction', label: __( 'Only on first scroll / interaction', 'ekwa' ) },
							],
							help: 'interaction' === loadOn
								? __( 'Nothing is downloaded until the visitor scrolls, taps, clicks or types — the lightest option for PageSpeed. A visitor who never interacts never sees the buttons.', 'ekwa' )
								: __( 'Loads once the page has finished loading, so it never delays the first paint. Scrolling or interacting first starts it sooner.', 'ekwa' ),
							onChange: function ( val ) { props.setAttributes( { loadOn: val } ); },
						} )
					)
				)
				: null;

			return el( Fragment, null,
				inspector,
				el( 'div', useBlockProps( {
					style: { display: 'inline-flex', alignItems: 'center', gap: '6px', padding: '6px 14px', background: '#f0f0f0', borderRadius: '4px', fontSize: '13px', color: '#666' },
				} ),
					el( 'i', { className: 'fa-solid fa-share-nodes', 'aria-hidden': 'true' } ),
					__( 'Share (loaded on front-end)', 'ekwa' )
				)
			);
		},
		save: function () { return null; },
	} );
} )( window.wp );
