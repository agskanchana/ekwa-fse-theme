/**
 * Ekwa Reveal Block — Block Editor UI.
 *
 * Wrapper block with a trigger button that reveals nested ekwa/reveal-hidden
 * blocks on the front-end. The actual show/hide animation is driven by the
 * shared front-end JS in assets/js/ekwa-blocks.js.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el                = wp.element.createElement;
	var Fragment          = wp.element.Fragment;
	var InnerBlocks       = wp.blockEditor.InnerBlocks;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var PanelBody         = wp.components.PanelBody;
	var TextControl       = wp.components.TextControl;
	var SelectControl     = wp.components.SelectControl;
	var ToggleControl     = wp.components.ToggleControl;
	var __                = wp.i18n.__;

	var TEMPLATE = [
		[ 'core/paragraph', { placeholder: 'Visible content. Add an Ekwa Reveal Hidden block where you want hidden content.' } ],
		[ 'ekwa/reveal-hidden' ],
	];

	registerBlockType( 'ekwa/reveal', {
		edit: function ( props ) {
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;
			var triggerText     = attributes.triggerText     || 'Learn More';
			var closeText       = attributes.closeText       || '';
			var buttonClassName = attributes.buttonClassName || 'btn btn-dark';
			var alignButton     = attributes.alignButton     || 'center';

			var blockProps = useBlockProps( { className: 'ekwa-reveal-edit reveal' } );

			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Trigger Button', 'ekwa' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Button text', 'ekwa' ),
							value: triggerText,
							onChange: function ( v ) { setAttributes( { triggerText: v } ); },
						} ),
						el( TextControl, {
							label: __( 'Button text when open', 'ekwa' ),
							help: __( 'Optional. Leave blank to keep the same label after opening.', 'ekwa' ),
							value: closeText,
							onChange: function ( v ) { setAttributes( { closeText: v } ); },
						} ),
						el( TextControl, {
							label: __( 'Button CSS classes', 'ekwa' ),
							help: __( 'Applied to the <a> tag. Default: btn btn-dark', 'ekwa' ),
							value: buttonClassName,
							onChange: function ( v ) { setAttributes( { buttonClassName: v } ); },
						} ),
						el( SelectControl, {
							label: __( 'Button alignment', 'ekwa' ),
							value: alignButton,
							options: [
								{ label: __( 'Left',   'ekwa' ), value: 'left' },
								{ label: __( 'Center', 'ekwa' ), value: 'center' },
								{ label: __( 'Right',  'ekwa' ), value: 'right' },
							],
							onChange: function ( v ) { setAttributes( { alignButton: v } ); },
						} ),
						el( ToggleControl, {
							label: __( 'Hide trigger after revealing', 'ekwa' ),
							help: __( 'Once the user clicks, the button stays hidden (no toggle back).', 'ekwa' ),
							checked: !! attributes.hideAfterReveal,
							onChange: function ( v ) { setAttributes( { hideAfterReveal: v } ); },
						} )
					)
				),
				el( 'div', blockProps,
					el( InnerBlocks, {
						template: TEMPLATE,
						templateLock: false,
					} ),
					// Editor preview of the trigger button.
					el( 'div', {
						className: 'ekwa-reveal-edit__trigger-preview',
						style: { textAlign: alignButton, marginTop: '16px' },
					},
						el( 'a', {
							href: '#',
							className: buttonClassName + ' reveal-trigger',
							onClick: function ( e ) { e.preventDefault(); },
							style: { pointerEvents: 'none' },
						}, triggerText )
					)
				)
			);
		},

		save: function () {
			return el( InnerBlocks.Content, null );
		},
	} );
} )( window.wp );
