/**
 * Ekwa Sitemap Block — Block Editor UI.
 *
 * Shows a live server-side preview of the collapsible sitemap tree.
 * All options are exposed in the Inspector sidebar.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType    = wp.blocks.registerBlockType;
	var el                   = wp.element.createElement;
	var Fragment             = wp.element.Fragment;
	var InspectorControls    = wp.blockEditor.InspectorControls;
	var PanelColorSettings   = wp.blockEditor.PanelColorSettings;
	var useBlockProps        = wp.blockEditor.useBlockProps;
	var PanelBody            = wp.components.PanelBody;
	var RangeControl         = wp.components.RangeControl;
	var TextControl          = wp.components.TextControl;
	var ToggleControl        = wp.components.ToggleControl;
	var SelectControl        = wp.components.SelectControl;
	var ServerSideRender     = wp.serverSideRender;
	var __                   = wp.i18n.__;

	/* ------------------------------------------------------------------ */
	/* Edit component                                                       */
	/* ------------------------------------------------------------------ */
	function Edit( props ) {
		var attrs    = props.attributes;
		var setAttrs = props.setAttributes;

		var blockProps = useBlockProps( { className: 'ekwa-sitemap-editor-wrapper' } );

		return el(
			Fragment,
			null,

			/* ---- Inspector sidebar ---- */
			el(
				InspectorControls,
				null,

				/* Behavior panel */
				el(
					PanelBody,
					{ title: __( 'Behavior', 'ekwa' ), initialOpen: true },

					el( ToggleControl, {
						label:    __( 'Show "Collapse All / Expand All" controls', 'ekwa' ),
						checked:  attrs.showControls,
						onChange: function ( v ) { setAttrs( { showControls: v } ); },
					} ),

					el( ToggleControl, {
						label:    __( 'Start fully collapsed', 'ekwa' ),
						help:     attrs.startCollapsed
							? __( 'Tree opens with all children hidden.', 'ekwa' )
							: __( 'Tree opens fully expanded.', 'ekwa' ),
						checked:  attrs.startCollapsed,
						onChange: function ( v ) { setAttrs( { startCollapsed: v } ); },
					} )
				),

				/* Layout panel */
				el(
					PanelBody,
					{ title: __( 'Layout', 'ekwa' ), initialOpen: false },

					el( RangeControl, {
						label:    __( 'Columns', 'ekwa' ),
						help:     __( 'Split top-level items across columns.', 'ekwa' ),
						value:    attrs.columns,
						min:      1,
						max:      4,
						onChange: function ( v ) { setAttrs( { columns: v } ); },
					} ),

					el( RangeControl, {
						label:    __( 'Depth', 'ekwa' ),
						help:     __( '0 = unlimited levels. 1 = top-level only. 2 = top + one child level, etc.', 'ekwa' ),
						value:    attrs.depth,
						min:      0,
						max:      5,
						onChange: function ( v ) { setAttrs( { depth: v } ); },
					} )
				),

				/* Display panel */
				el(
					PanelBody,
					{ title: __( 'Display', 'ekwa' ), initialOpen: false },

					el( TextControl, {
						label:    __( 'Heading', 'ekwa' ),
						help:     __( 'Optional heading shown above the sitemap.', 'ekwa' ),
						value:    attrs.title,
						onChange: function ( v ) { setAttrs( { title: v } ); },
					} ),

					! attrs.useMenu && el( ToggleControl, {
						label:    __( 'Show Page Description', 'ekwa' ),
						help:     __( 'Displays the page excerpt under each link.', 'ekwa' ),
						checked:  attrs.showDescription,
						onChange: function ( v ) { setAttrs( { showDescription: v } ); },
					} ),

					! attrs.useMenu && el( ToggleControl, {
						label:    __( 'Show Last Modified Date', 'ekwa' ),
						checked:  attrs.showDate,
						onChange: function ( v ) { setAttrs( { showDate: v } ); },
					} )
				),

				/* Source & Filtering panel */
				el(
					PanelBody,
					{ title: __( 'Source & Filtering', 'ekwa' ), initialOpen: false },

					el( ToggleControl, {
						label:    __( 'Use a nav menu instead of pages', 'ekwa' ),
						help:     attrs.useMenu
							? __( 'Displaying items from a WordPress nav menu.', 'ekwa' )
							: __( 'Auto-building from all published pages.', 'ekwa' ),
						checked:  attrs.useMenu,
						onChange: function ( v ) { setAttrs( { useMenu: v } ); },
					} ),

					attrs.useMenu && el( TextControl, {
						label:    __( 'Menu Slug / Location', 'ekwa' ),
						help:     __( 'The menu slug (e.g. site-map) as shown in Appearance → Menus.', 'ekwa' ),
						value:    attrs.menuSlug,
						onChange: function ( v ) { setAttrs( { menuSlug: v } ); },
					} ),

					! attrs.useMenu && el( SelectControl, {
						label:   __( 'Sort By', 'ekwa' ),
						value:   attrs.sortBy,
						options: [
							{ value: 'menu_order', label: __( 'Menu Order', 'ekwa' ) },
							{ value: 'title',      label: __( 'Title (A – Z)', 'ekwa' ) },
							{ value: 'date',       label: __( 'Publish Date', 'ekwa' ) },
							{ value: 'modified',   label: __( 'Last Modified', 'ekwa' ) },
							{ value: 'ID',         label: __( 'Page ID', 'ekwa' ) },
						],
						onChange: function ( v ) { setAttrs( { sortBy: v } ); },
					} ),

					! attrs.useMenu && el( SelectControl, {
						label:   __( 'Sort Order', 'ekwa' ),
						value:   attrs.sortOrder,
						options: [
							{ value: 'ASC',  label: __( 'Ascending', 'ekwa' ) },
							{ value: 'DESC', label: __( 'Descending', 'ekwa' ) },
						],
						onChange: function ( v ) { setAttrs( { sortOrder: v } ); },
					} ),

					! attrs.useMenu && el( TextControl, {
						label:    __( 'Exclude Page IDs', 'ekwa' ),
						help:     __( 'Comma-separated IDs to hide, e.g. 12, 45, 89. Child pages of excluded pages are also hidden.', 'ekwa' ),
						value:    attrs.excludeIds,
						onChange: function ( v ) { setAttrs( { excludeIds: v } ); },
					} )
				),

				/* Colors panel */
				el( PanelColorSettings, {
					title: __( 'Colors', 'ekwa' ),
					initialOpen: false,
					colorSettings: [
						{
							value:    attrs.linkColor,
							onChange: function ( v ) { setAttrs( { linkColor: v || '' } ); },
							label:    __( 'Link Color', 'ekwa' ),
						},
						{
							value:    attrs.controlColor,
							onChange: function ( v ) { setAttrs( { controlColor: v || '' } ); },
							label:    __( 'Collapse / Expand Link Color', 'ekwa' ),
						},
					],
				} )
			),

			/* ---- Canvas: live server-side preview ---- */
			el(
				'div',
				blockProps,
				el( ServerSideRender, {
					block:      'ekwa/sitemap',
					attributes: attrs,
					EmptyResponsePlaceholder: function () {
						return el(
							'div',
							{
								style: {
									padding:    '24px',
									background: '#f0f0f0',
									border:     '2px dashed #ccc',
									textAlign:  'center',
									color:      '#666',
									fontSize:   '14px',
								},
							},
							el( 'span', {
								className: 'dashicons dashicons-admin-site-alt3',
								style:     { fontSize: '36px', width: '36px', height: '36px', color: '#aaa', display: 'block', margin: '0 auto 8px' },
							} ),
							attrs.useMenu
								? __( 'Ekwa Sitemap — no menu found for slug "' + attrs.menuSlug + '".', 'ekwa' )
								: __( 'Ekwa Sitemap — no published pages found.', 'ekwa' )
						);
					},
				} )
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Register the block                                                   */
	/* ------------------------------------------------------------------ */
	registerBlockType( 'ekwa/sitemap', {
		edit: Edit,
		save: function () {
			return null;
		},
	} );

} )( window.wp );
