/**
 * Ekwa Container Block — Block Editor UI.
 *
 * A centered container with configurable max-width.
 * Outputs <div style="max-width:…;margin:0 auto"> on frontend.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType  = wp.blocks.registerBlockType;
	var el                 = wp.element.createElement;
	var Fragment           = wp.element.Fragment;
	var InnerBlocks        = wp.blockEditor.InnerBlocks;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var PanelBody          = wp.components.PanelBody;
	var TextControl        = wp.components.TextControl;
	var ButtonGroup        = wp.components.ButtonGroup;
	var Button             = wp.components.Button;
	var __                 = wp.i18n.__;

	var PRESETS = [ '700px', '900px', '1000px', '1100px', '1280px' ];

	function Edit( props ) {
		var attributes    = props.attributes;
		var setAttributes = props.setAttributes;
		var maxWidth      = attributes.maxWidth || '1280px';

		var blockProps = useBlockProps( {
			className: 'ekwa-container',
			style: {
				maxWidth:    maxWidth,
				marginLeft:  'auto',
				marginRight: 'auto',
			},
		} );

		return el( Fragment, null,

			el( InspectorControls, null,
				el( PanelBody, { title: __( 'Container', 'ekwa' ), initialOpen: true },
					el( TextControl, {
						label:    __( 'Max Width', 'ekwa' ),
						value:    maxWidth,
						onChange: function ( v ) { setAttributes( { maxWidth: v.trim() } ); },
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} ),
					el( 'div', { style: { display: 'flex', flexWrap: 'wrap', gap: '4px', marginTop: '8px' } },
						PRESETS.map( function ( p ) {
							return el( Button, {
								key:       p,
								size:      'compact',
								variant:   maxWidth === p ? 'primary' : 'secondary',
								onClick:   function () { setAttributes( { maxWidth: p } ); },
							}, p );
						} )
					)
				)
			),

			el( 'div', blockProps,
				el( InnerBlocks, { templateLock: false } )
			)
		);
	}

	registerBlockType( 'ekwa/container', {
		edit: Edit,
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );

} )( window.wp );
