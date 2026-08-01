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
	var TextControl        = wp.components.TextControl;
	var ToggleControl      = wp.components.ToggleControl;
	var ServerSideRender   = wp.serverSideRender;
	var __                 = wp.i18n.__;

	/**
	 * The structural positions a mockup class can be mapped onto. The block
	 * always emits its own canonical class at each of these; anything set here
	 * is added alongside, so a converted header renders with the exact
	 * selectors the mockup's stylesheet targets. Mirrors
	 * ekwa_header_menu_class_slots() in PHP.
	 */
	var CLASS_SLOTS = [
		{ key: 'nav',           label: __( 'Nav element', 'ekwa' ),        canonical: 'ekwa-header-nav' },
		{ key: 'menu',          label: __( 'Menu list', 'ekwa' ),          canonical: 'ekwa-header-menu' },
		{ key: 'item',          label: __( 'Menu item', 'ekwa' ),          canonical: 'menu-item' },
		{ key: 'hasChildren',   label: __( 'Item with dropdown', 'ekwa' ), canonical: 'menu-item-has-children' },
		{ key: 'link',          label: __( 'Menu link', 'ekwa' ),          canonical: '—' },
		{ key: 'label',         label: __( 'Link label', 'ekwa' ),         canonical: 'ekwa-menu-label' },
		{ key: 'caret',         label: __( 'Dropdown caret', 'ekwa' ),     canonical: 'ekwa-caret' },
		{ key: 'submenu',       label: __( 'Submenu list', 'ekwa' ),       canonical: 'sub-menu' },
		{ key: 'submenuItem',   label: __( 'Submenu item', 'ekwa' ),       canonical: 'menu-item' },
		{ key: 'submenuLink',   label: __( 'Submenu link', 'ekwa' ),       canonical: '—' },
		{ key: 'megaParent',    label: __( 'Mega-menu parent', 'ekwa' ),   canonical: 'menu-item-megamenu' },
		{ key: 'mega',          label: __( 'Mega panel', 'ekwa' ),         canonical: 'ekwa-megamenu' },
		{ key: 'megaGrid',      label: __( 'Mega grid', 'ekwa' ),          canonical: 'ekwa-megamenu-grid' },
		{ key: 'megaColumn',    label: __( 'Mega column', 'ekwa' ),        canonical: 'ekwa-megamenu-column' },
		{ key: 'megaImageWrap', label: __( 'Mega image wrapper', 'ekwa' ), canonical: 'ekwa-megamenu-image-wrap' },
		{ key: 'megaImage',     label: __( 'Mega image', 'ekwa' ),         canonical: 'ekwa-megamenu-image' },
		{ key: 'megaHeading',   label: __( 'Mega column heading', 'ekwa' ), canonical: 'ekwa-megamenu-heading' },
		{ key: 'megaList',      label: __( 'Mega column list', 'ekwa' ),   canonical: 'ekwa-megamenu-list' },
		{ key: 'megaItem',      label: __( 'Mega column item', 'ekwa' ),   canonical: 'menu-item' },
		{ key: 'megaLink',      label: __( 'Mega column link', 'ekwa' ),   canonical: '—' }
	];

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
						{ title: __( 'Match the mockup’s markup', 'ekwa' ), initialOpen: false },
						el( 'p', { style: { fontSize: '12px', lineHeight: 1.5 } },
							__( 'Give each part of the menu the class name your mockup uses for it, and its CSS applies with no rewriting. The block’s own class is always kept as well, so its dropdown behaviour keeps working. The Mockup Converter fills these in for you when it converts a header.', 'ekwa' )
						),

						el( SelectControl, {
							label:    __( 'Caret element', 'ekwa' ),
							help:     __( 'Use <i> when the mockup draws the dropdown arrow with an icon font.', 'ekwa' ),
							value:    attrs.caretTag || 'span',
							options:  [
								{ label: '<span>', value: 'span' },
								{ label: '<i>',    value: 'i' }
							],
							onChange: function ( v ) { setAttrs( { caretTag: v } ); }
						} ),

						el( ToggleControl, {
							label:    __( 'Wrap link text in a <span>', 'ekwa' ),
							help:     __( 'Off outputs the text directly inside the <a>, for mockups that style the link itself.', 'ekwa' ),
							checked:  attrs.wrapLabel !== false,
							onChange: function ( v ) { setAttrs( { wrapLabel: v } ); }
						} ),

						el( ToggleControl, {
							label:    __( 'Load this block’s stylesheet', 'ekwa' ),
							help:     __( 'Turn off when the mockup’s CSS fully styles the menu — the block’s own positioning rules would otherwise compete with it. Keyboard and touch behaviour is unaffected.', 'ekwa' ),
							checked:  attrs.useBlockCss !== false,
							onChange: function ( v ) { setAttrs( { useBlockCss: v } ); }
						} ),

						el( TextControl, {
							label:    __( 'Nav aria-label', 'ekwa' ),
							value:    attrs.navLabel || '',
							placeholder: __( 'Main Navigation', 'ekwa' ),
							onChange: function ( v ) { setAttrs( { navLabel: v } ); }
						} ),

						el( 'hr' ),

						CLASS_SLOTS.map( function ( slot ) {
							return el( TextControl, {
								key:      slot.key,
								label:    slot.label,
								help:     __( 'Block class: ', 'ekwa' ) + slot.canonical,
								value:    ( attrs.classMap && attrs.classMap[ slot.key ] ) || '',
								onChange: function ( v ) {
									var next = Object.assign( {}, attrs.classMap || {} );
									if ( v ) { next[ slot.key ] = v; } else { delete next[ slot.key ]; }
									setAttrs( { classMap: next } );
								}
							} );
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
