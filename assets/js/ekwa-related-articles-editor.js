( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var RangeControl = wp.components.RangeControl;
	var ToggleControl = wp.components.ToggleControl;
	var TextControl = wp.components.TextControl;
	var SelectControl = wp.components.SelectControl;
	var Notice = wp.components.Notice;
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;

	registerBlockType( 'ekwa/related-articles', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;

			var carouselSettings = a.useCarousel ? [
				el( RangeControl, { key: 'd', label: __( 'Desktop items' ), value: a.desktopItems, min: 1, max: 4, onChange: function ( v ) { set( { desktopItems: v } ); } } ),
				el( RangeControl, { key: 't', label: __( 'Tablet items' ), value: a.tabletItems, min: 1, max: 3, onChange: function ( v ) { set( { tabletItems: v } ); } } ),
				el( RangeControl, { key: 'm', label: __( 'Mobile items' ), value: a.mobileItems, min: 1, max: 2, onChange: function ( v ) { set( { mobileItems: v } ); } } ),
				el( ToggleControl, { key: 'a', label: __( 'Show arrows' ), checked: a.showArrows, onChange: function ( v ) { set( { showArrows: v } ); } } ),
				el( ToggleControl, { key: 'o', label: __( 'Show dots' ),   checked: a.showDots,   onChange: function ( v ) { set( { showDots: v } ); } } )
			] : [];

			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Layout' ), initialOpen: true },
						el( ToggleControl, {
							label: __( 'Use carousel' ),
							help: a.useCarousel ? __( 'Off = simple grid.' ) : __( 'On = sliding carousel.' ),
							checked: a.useCarousel,
							onChange: function ( v ) { set( { useCarousel: v } ); }
						} ),
						el( RangeControl, { label: __( 'Posts to show' ), value: a.count, min: 1, max: 12, onChange: function ( v ) { set( { count: v } ); } } )
					),
					a.useCarousel && el( PanelBody, { title: __( 'Carousel options' ), initialOpen: true }, carouselSettings ),
					el( PanelBody, { title: __( 'Heading' ), initialOpen: false },
						el( SelectControl, {
							label: __( 'Heading level' ),
							value: a.headingLevel,
							options: [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ].map( function ( h ) { return { label: h.toUpperCase(), value: h }; } ),
							onChange: function ( v ) { set( { headingLevel: v } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Hide heading' ),
							checked: a.hideHeading,
							onChange: function ( v ) { set( { hideHeading: v } ); }
						} ),
						el( TextControl, { label: __( 'Singular label (1 post)' ), value: a.singularLabel, onChange: function ( v ) { set( { singularLabel: v } ); } } ),
						el( TextControl, { label: __( 'Plural label (2+ posts)' ), value: a.pluralLabel, onChange: function ( v ) { set( { pluralLabel: v } ); } } ),
						el( TextControl, { label: __( 'Featured singular label' ), value: a.featuredSingularLabel, onChange: function ( v ) { set( { featuredSingularLabel: v } ); } } ),
						el( TextControl, { label: __( 'Featured plural label' ),   value: a.featuredPluralLabel,   onChange: function ( v ) { set( { featuredPluralLabel: v } ); } } ),
						el( TextControl, {
							label: __( 'Featured category slug' ),
							help: __( 'When the matching category equals this slug, the featured labels are used instead of the related labels.' ),
							value: a.featuredCategorySlug,
							onChange: function ( v ) { set( { featuredCategorySlug: v } ); }
						} )
					)
				),
				el( 'div', useBlockProps(),
					el( Notice, { status: 'info', isDismissible: false },
						__( 'Renders only on WordPress pages whose slug matches a category slug. Hidden on archives, single posts and home unless the home is a static page with a matching category.' )
					),
					el( ServerSideRender, { block: 'ekwa/related-articles', attributes: a } )
				)
			);
		},
		save: function () { return null; },
	} );
} )( window.wp );
