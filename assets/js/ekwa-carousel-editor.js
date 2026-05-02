/**
 * Ekwa Carousel Block — Editor UI.
 *
 * Wraps inner blocks (slides) and exposes responsive item-per-view,
 * navigation, autoplay, and loop options.
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
	var ToggleControl      = wp.components.ToggleControl;
	var RangeControl       = wp.components.RangeControl;
	var TextControl        = wp.components.TextControl;
	var __                 = wp.i18n.__;

	function Edit( props ) {
		var a   = props.attributes;
		var set = props.setAttributes;

		var blockProps = useBlockProps( {
			className: 'ekwa-carousel ekwa-carousel--editor',
		} );

		// In the editor we render slides as a simple stack so authors can
		// see/edit every slide. Frontend handles the carousel behaviour.
		return el( Fragment, null,

			el( InspectorControls, null,

				el( PanelBody, { title: __( 'Items per view', 'ekwa' ), initialOpen: true },
					el( RangeControl, {
						label:    __( 'Desktop', 'ekwa' ),
						value:    a.desktopItems,
						min:      1,
						max:      8,
						onChange: function ( v ) { set( { desktopItems: v } ); },
						__next40pxDefaultSize:   true,
						__nextHasNoMarginBottom: true,
					} ),
					el( RangeControl, {
						label:    __( 'Tablet', 'ekwa' ),
						value:    a.tabletItems,
						min:      1,
						max:      8,
						onChange: function ( v ) { set( { tabletItems: v } ); },
						__next40pxDefaultSize:   true,
						__nextHasNoMarginBottom: true,
					} ),
					el( RangeControl, {
						label:    __( 'Mobile', 'ekwa' ),
						value:    a.mobileItems,
						min:      1,
						max:      4,
						onChange: function ( v ) { set( { mobileItems: v } ); },
						__next40pxDefaultSize:   true,
						__nextHasNoMarginBottom: true,
					} )
				),

				el( PanelBody, { title: __( 'Breakpoints (px)', 'ekwa' ), initialOpen: false },
					el( RangeControl, {
						label:    __( 'Desktop ≥', 'ekwa' ),
						help:     __( 'Width at and above which "Desktop" items per view applies.', 'ekwa' ),
						value:    a.tabletBreakpoint,
						min:      600,
						max:      1600,
						step:     1,
						onChange: function ( v ) { set( { tabletBreakpoint: v } ); },
						__next40pxDefaultSize:   true,
						__nextHasNoMarginBottom: true,
					} ),
					el( RangeControl, {
						label:    __( 'Tablet ≥', 'ekwa' ),
						help:     __( 'Width at and above which "Tablet" applies; below this is "Mobile".', 'ekwa' ),
						value:    a.mobileBreakpoint,
						min:      320,
						max:      1000,
						step:     1,
						onChange: function ( v ) { set( { mobileBreakpoint: v } ); },
						__next40pxDefaultSize:   true,
						__nextHasNoMarginBottom: true,
					} )
				),

				el( PanelBody, { title: __( 'Navigation', 'ekwa' ), initialOpen: false },
					el( ToggleControl, {
						label:    __( 'Show arrows', 'ekwa' ),
						checked:  !! a.showArrows,
						onChange: function ( v ) { set( { showArrows: v } ); },
						__nextHasNoMarginBottom: true,
					} ),
					el( ToggleControl, {
						label:    __( 'Show dots', 'ekwa' ),
						checked:  !! a.showDots,
						onChange: function ( v ) { set( { showDots: v } ); },
						__nextHasNoMarginBottom: true,
					} )
				),

				el( PanelBody, { title: __( 'Behavior', 'ekwa' ), initialOpen: false },
					el( ToggleControl, {
						label:    __( 'Loop (infinite)', 'ekwa' ),
						checked:  !! a.loop,
						onChange: function ( v ) { set( { loop: v } ); },
						__nextHasNoMarginBottom: true,
					} ),
					el( ToggleControl, {
						label:    __( 'Autoplay', 'ekwa' ),
						help:     __( 'Pauses on hover, focus, and when the tab is hidden. Disabled when the user prefers reduced motion.', 'ekwa' ),
						checked:  !! a.autoplay,
						onChange: function ( v ) { set( { autoplay: v } ); },
						__nextHasNoMarginBottom: true,
					} ),
					a.autoplay && el( RangeControl, {
						label:    __( 'Autoplay interval (ms)', 'ekwa' ),
						value:    a.autoplayInterval,
						min:      1000,
						max:      15000,
						step:     500,
						onChange: function ( v ) { set( { autoplayInterval: v } ); },
						__next40pxDefaultSize:   true,
						__nextHasNoMarginBottom: true,
					} ),
					el( RangeControl, {
						label:    __( 'Slide gap (px)', 'ekwa' ),
						value:    a.gap,
						min:      0,
						max:      60,
						onChange: function ( v ) { set( { gap: v } ); },
						__next40pxDefaultSize:   true,
						__nextHasNoMarginBottom: true,
					} ),
					el( RangeControl, {
						label:    __( 'Transition speed (ms)', 'ekwa' ),
						value:    a.speed,
						min:      100,
						max:      1500,
						step:     50,
						onChange: function ( v ) { set( { speed: v } ); },
						__next40pxDefaultSize:   true,
						__nextHasNoMarginBottom: true,
					} )
				),

				el( PanelBody, { title: __( 'Accessibility', 'ekwa' ), initialOpen: false },
					el( TextControl, {
						label:    __( 'ARIA label', 'ekwa' ),
						help:     __( 'Describes the carousel to assistive tech (e.g. "Featured testimonials"). Defaults to "Carousel".', 'ekwa' ),
						value:    a.ariaLabel || '',
						onChange: function ( v ) { set( { ariaLabel: v } ); },
						__next40pxDefaultSize:   true,
						__nextHasNoMarginBottom: true,
					} )
				)
			),

			el( 'div', blockProps,
				el( 'div', { className: 'ekwa-carousel__editor-notice' },
					el( 'small', null, __( 'Carousel — slides shown stacked in editor; rendered as a slider on the frontend.', 'ekwa' ) )
				),
				el( 'div', { className: 'ekwa-carousel__editor-slides' },
					el( InnerBlocks, {
						templateLock: false,
						orientation:  'vertical',
						renderAppender: InnerBlocks.ButtonBlockAppender,
					} )
				)
			)
		);
	}

	registerBlockType( 'ekwa/carousel', {
		edit: Edit,
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );

} )( window.wp );
