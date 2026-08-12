/**
 * Ekwa Related Posts Block — Editor UI. DEPRECATED.
 *
 * Superseded by ekwa/related-articles, which queries the same way and adds
 * carousel and grid layouts. block.json hides this one from the inserter, so
 * nothing new can be added; blocks already on live sites stay fully editable
 * and keep rendering. The transform below moves one over in place — every
 * attribute maps one-to-one, and useCarousel is set false so the layout stays
 * the grid it is today.
 */
( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var registerBlockType = wp.blocks.registerBlockType;
	var createBlock = wp.blocks.createBlock;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var SelectControl = wp.components.SelectControl;
	var RangeControl = wp.components.RangeControl;
	var ToggleControl = wp.components.ToggleControl;
	var Notice = wp.components.Notice;
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;

	registerBlockType( 'ekwa/related-posts', {
		transforms: {
			to: [ {
				type: 'block',
				blocks: [ 'ekwa/related-articles' ],
				transform: function ( a ) {
					return createBlock( 'ekwa/related-articles', {
						headingLevel:          a.headingLevel,
						singularLabel:         a.singularLabel,
						pluralLabel:           a.pluralLabel,
						featuredSingularLabel: a.featuredSingularLabel,
						featuredPluralLabel:   a.featuredPluralLabel,
						featuredCategorySlug:  a.featuredCategorySlug,
						count:                 a.count,
						hideHeading:           a.hideHeading,
						useCarousel:           false
					} );
				}
			} ]
		},
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Heading' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Heading level' ),
							value: a.headingLevel,
							options: [
								{ label: 'H1', value: 'h1' },
								{ label: 'H2', value: 'h2' },
								{ label: 'H3', value: 'h3' },
								{ label: 'H4', value: 'h4' },
								{ label: 'H5', value: 'h5' },
								{ label: 'H6', value: 'h6' }
							],
							onChange: function ( v ) { set( { headingLevel: v } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Hide heading' ),
							checked: a.hideHeading,
							onChange: function ( v ) { set( { hideHeading: v } ); }
						} ),
						el( TextControl, {
							label: __( 'Singular label (1 post)' ),
							value: a.singularLabel,
							onChange: function ( v ) { set( { singularLabel: v } ); }
						} ),
						el( TextControl, {
							label: __( 'Plural label (2+ posts)' ),
							value: a.pluralLabel,
							onChange: function ( v ) { set( { pluralLabel: v } ); }
						} ),
						el( TextControl, {
							label: __( 'Featured singular label' ),
							value: a.featuredSingularLabel,
							onChange: function ( v ) { set( { featuredSingularLabel: v } ); }
						} ),
						el( TextControl, {
							label: __( 'Featured plural label' ),
							value: a.featuredPluralLabel,
							onChange: function ( v ) { set( { featuredPluralLabel: v } ); }
						} )
					),
					el( PanelBody, { title: __( 'Query' ), initialOpen: false },
						el( TextControl, {
							label: __( 'Home/front-page category slug' ),
							help: __( 'Used when no page slug match is found (e.g. front page).' ),
							value: a.featuredCategorySlug,
							onChange: function ( v ) { set( { featuredCategorySlug: v } ); }
						} ),
						el( RangeControl, {
							label: __( 'Posts to show' ),
							value: a.count,
							min: 1,
							max: 12,
							onChange: function ( v ) { set( { count: v } ); }
						} )
					)
				),
				el( 'div', useBlockProps(),
					el( Notice, { status: 'warning', isDismissible: false },
						__( 'Deprecated block. Use Ekwa Related Articles instead — same posts, plus carousel and grid layouts. This block keeps working, but to move it over select it and choose Transform → Ekwa Related Articles. Note that Related Articles renders on pages and the front page only.' )
					),
					el( Notice, { status: 'info', isDismissible: false },
						__( 'Pulls posts whose category slug matches the current page slug. The post item template and date format are configured under Appearance → Ekwa Settings → Related Posts.' )
					),
					el( ServerSideRender, { block: 'ekwa/related-posts', attributes: a } )
				)
			);
		},
		save: function () { return null; },
	} );
} )( window.wp );
