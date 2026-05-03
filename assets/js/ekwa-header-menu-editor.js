/**
 * Ekwa Header Menu Block — Block Editor UI.
 *
 * Renders a server-side preview that pulls the menu assigned to the
 * "Main Menu" theme location and respects the per-item mega-menu flag.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType  = wp.blocks.registerBlockType;
	var el                 = wp.element.createElement;
	var Fragment           = wp.element.Fragment;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var PanelBody          = wp.components.PanelBody;
	var RangeControl       = wp.components.RangeControl;
	var SelectControl      = wp.components.SelectControl;
	var ServerSideRender   = wp.serverSideRender;
	var __                 = wp.i18n.__;

	registerBlockType( 'ekwa/header-menu', {
		edit: function ( props ) {
			var attrs    = props.attributes;
			var setAttrs = props.setAttributes;

			var blockProps = useBlockProps( { className: 'ekwa-header-menu-editor-wrapper' } );

			return el(
				Fragment,
				null,

				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Layout', 'ekwa' ), initialOpen: true },

						el( SelectControl, {
							label:    __( 'Alignment', 'ekwa' ),
							value:    attrs.alignment,
							options:  [
								{ label: __( 'Left', 'ekwa' ),   value: 'left' },
								{ label: __( 'Center', 'ekwa' ), value: 'center' },
								{ label: __( 'Right', 'ekwa' ),  value: 'right' },
								{ label: __( 'Space Between', 'ekwa' ), value: 'space-between' }
							],
							onChange: function ( v ) { setAttrs( { alignment: v } ); }
						} ),

						el( RangeControl, {
							label:    __( 'Item gap (px)', 'ekwa' ),
							value:    attrs.itemGap,
							min:      0,
							max:      80,
							onChange: function ( v ) { setAttrs( { itemGap: v } ); }
						} ),

						el( RangeControl, {
							label:    __( 'Submenu min-width (px)', 'ekwa' ),
							help:     __( 'Applies to standard (non-mega) flyout submenus.', 'ekwa' ),
							value:    attrs.submenuMinWidth,
							min:      120,
							max:      420,
							step:     10,
							onChange: function ( v ) { setAttrs( { submenuMinWidth: v } ); }
						} )
					),

					el(
						PanelBody,
						{ title: __( 'How to use', 'ekwa' ), initialOpen: false },
						el( 'p', { style: { fontSize: '12px', lineHeight: 1.5 } },
							__( 'Assign a menu to the "Main Menu" location at Appearance → Menus. On any top-level item, tick "Render as Mega Menu" to expand its children into a columnar grid. Use the per-item Image field to add a thumbnail above each mega-menu column.', 'ekwa' )
						)
					)
				),

				el(
					'div',
					blockProps,
					el( ServerSideRender, {
						block:      'ekwa/header-menu',
						attributes: attrs
					} )
				)
			);
		},

		save: function () {
			return null;
		}
	} );
} )( window.wp );
