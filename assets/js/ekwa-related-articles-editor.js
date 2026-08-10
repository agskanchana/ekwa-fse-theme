/**
 * Ekwa Related Articles Block — Editor UI.
 *
 * In carousel mode the block renders the same .ekwa-carousel markup as
 * ekwa/carousel, so it exposes the same option set — items per view,
 * breakpoints, arrow position / offset / icons, autoplay and speed — via the
 * shared window.EkwaCarouselControls helper.
 */
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

	var arrowControls = window.EkwaCarouselControls.arrowControls;

	/**
	 * Arrow placement, honouring the pre-arrowPosition attributes.
	 *
	 * arrowPosition has no default, so blocks saved before it existed resolve
	 * from the old arrowsOutside toggle and keep the layout they had. Mirrors
	 * the same fallback in ekwa_render_related_articles_block().
	 *
	 * @param {Object} a Block attributes.
	 * @return {string} One of the eight arrow positions.
	 */
	function resolveArrowPosition( a ) {
		if ( a.arrowPosition ) {
			return a.arrowPosition;
		}
		return false === a.arrowsOutside ? 'inside' : 'outside';
	}

	/**
	 * Arrow offset, falling back to the legacy arrowSpacing attribute.
	 *
	 * @param {Object} a Block attributes.
	 * @return {number} Distance in px between the arrows and the items.
	 */
	function resolveArrowOffset( a ) {
		if ( a.arrowOffset !== undefined ) {
			return a.arrowOffset;
		}
		return a.arrowSpacing === undefined ? 12 : a.arrowSpacing;
	}

	registerBlockType( 'ekwa/related-articles', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;

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

					a.useCarousel && el( PanelBody, { title: __( 'Items per view' ), initialOpen: true },
						el( RangeControl, { label: __( 'Desktop' ), value: a.desktopItems, min: 1, max: 8, onChange: function ( v ) { set( { desktopItems: v } ); } } ),
						el( RangeControl, { label: __( 'Tablet' ),  value: a.tabletItems,  min: 1, max: 8, onChange: function ( v ) { set( { tabletItems: v } ); } } ),
						el( RangeControl, { label: __( 'Mobile' ),  value: a.mobileItems,  min: 1, max: 4, onChange: function ( v ) { set( { mobileItems: v } ); } } )
					),

					a.useCarousel && el( PanelBody, { title: __( 'Breakpoints (px)' ), initialOpen: false },
						el( RangeControl, {
							label: __( 'Desktop ≥' ),
							help: __( 'Width at and above which "Desktop" items per view applies.' ),
							value: a.tabletBreakpoint, min: 600, max: 1600, step: 1,
							onChange: function ( v ) { set( { tabletBreakpoint: v } ); }
						} ),
						el( RangeControl, {
							label: __( 'Tablet ≥' ),
							help: __( 'Width at and above which "Tablet" applies; below this is "Mobile".' ),
							value: a.mobileBreakpoint, min: 320, max: 1000, step: 1,
							onChange: function ( v ) { set( { mobileBreakpoint: v } ); }
						} )
					),

					a.useCarousel && el( PanelBody, { title: __( 'Navigation' ), initialOpen: false },
						el( ToggleControl, { label: __( 'Show arrows' ), checked: a.showArrows, onChange: function ( v ) { set( { showArrows: v } ); } } ),
						a.showArrows && arrowControls( a, set, {
							position: resolveArrowPosition( a ),
							offset:   resolveArrowOffset( a ),
							gap:      a.arrowGap === undefined ? 12 : a.arrowGap
						} ),
						el( ToggleControl, { label: __( 'Show dots' ), checked: a.showDots, onChange: function ( v ) { set( { showDots: v } ); } } )
					),

					a.useCarousel && el( PanelBody, { title: __( 'Behavior' ), initialOpen: false },
						el( ToggleControl, {
							label: __( 'Loop (infinite)' ),
							help: __( 'Wrap from the last slide back to the first.' ),
							checked: a.loop,
							onChange: function ( v ) { set( { loop: v } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Autoplay' ),
							help: __( 'Pauses on hover, focus, and when the tab is hidden. Disabled when the user prefers reduced motion.' ),
							checked: a.autoplay,
							onChange: function ( v ) { set( { autoplay: v } ); }
						} ),
						a.autoplay && el( RangeControl, {
							label: __( 'Autoplay interval (ms)' ),
							value: a.autoplayInterval, min: 1000, max: 15000, step: 500,
							onChange: function ( v ) { set( { autoplayInterval: v } ); }
						} ),
						el( RangeControl, { label: __( 'Slide gap (px)' ), value: a.gap, min: 0, max: 60, onChange: function ( v ) { set( { gap: v } ); } } ),
						el( RangeControl, {
							label: __( 'Transition speed (ms)' ),
							value: a.speed, min: 100, max: 1500, step: 50,
							onChange: function ( v ) { set( { speed: v } ); }
						} )
					),

					a.useCarousel && el( PanelBody, { title: __( 'Accessibility' ), initialOpen: false },
						el( TextControl, {
							label: __( 'ARIA label' ),
							help: __( 'Describes the carousel to assistive tech (e.g. "Related articles"). Defaults to "Carousel".' ),
							value: a.ariaLabel || '',
							onChange: function ( v ) { set( { ariaLabel: v } ); }
						} )
					),

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
