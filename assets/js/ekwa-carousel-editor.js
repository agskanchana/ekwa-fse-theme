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
	var SelectControl      = wp.components.SelectControl;
	var TextControl        = wp.components.TextControl;
	var TextareaControl    = wp.components.TextareaControl;
	var __                 = wp.i18n.__;

	/**
	 * Mirror of the server's ekwa_sanitize_svg_markup(): drop the XML prolog,
	 * <script> elements and inline event handlers, and treat anything without an
	 * <svg> root as empty. Applied to the preview so it shows the same markup the
	 * frontend will actually ship — the server sanitizes on every render, so this
	 * is a fidelity measure, not the security boundary.
	 *
	 * @param {string} markup Raw markup from the attribute.
	 * @return {string} Sanitized markup, or '' when it isn't an SVG.
	 */
	function sanitizeSvg( markup ) {
		if ( ! markup ) {
			return '';
		}
		var svg = String( markup )
			.replace( /<\?xml[\s\S]*?\?>/g, '' )
			.replace( /<script[^>]*>[\s\S]*?<\/script>/gi, '' )
			.replace( /\bon\w+\s*=\s*["'][^"']*["']/gi, '' );

		return /<svg/i.test( svg ) ? svg.trim() : '';
	}

	/**
	 * One arrow as it will render on the frontend: the pasted SVG, or the
	 * built-in chevron when the field is empty or isn't valid SVG — the same
	 * fallback the render callback applies.
	 *
	 * @param {string} markup    SVG markup from the attribute, possibly empty.
	 * @param {string} direction 'left' or 'right' — picks the default chevron.
	 */
	function arrowPreview( markup, direction ) {
		var svg   = sanitizeSvg( markup );
		var inner = svg
			? el( 'span', {
				className: 'ekwa-carousel__arrow-icon',
				dangerouslySetInnerHTML: { __html: svg },
			} )
			: el( 'i', { className: 'fa-solid fa-chevron-' + direction } );

		return el( 'span', { className: 'ekwa-carousel__arrow' }, inner );
	}

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

					a.showArrows && el( SelectControl, {
						label:   __( 'Arrow position', 'ekwa' ),
						value:   a.arrowPosition || 'inside',
						options: [
							{ label: __( 'Over the slides (left & right)', 'ekwa' ), value: 'inside' },
							{ label: __( 'Beside the slides (left & right)', 'ekwa' ), value: 'outside' },
							{ label: __( 'Above — left', 'ekwa' ),    value: 'top-left' },
							{ label: __( 'Above — centre', 'ekwa' ),  value: 'top-center' },
							{ label: __( 'Above — right', 'ekwa' ),   value: 'top-right' },
							{ label: __( 'Below — left', 'ekwa' ),    value: 'bottom-left' },
							{ label: __( 'Below — centre', 'ekwa' ),  value: 'bottom-center' },
							{ label: __( 'Below — right', 'ekwa' ),   value: 'bottom-right' }
						],
						help:    __( '“Beside” and the above/below positions reserve their own space, so the arrows never cover a slide.', 'ekwa' ),
						onChange: function ( v ) { set( { arrowPosition: v } ); },
						__nextHasNoMarginBottom: true,
					} ),

					a.showArrows && ( a.arrowPosition || 'inside' ) !== 'inside' && el( RangeControl, {
						label:    __( 'Arrow offset (px)', 'ekwa' ),
						help:     __( 'Distance from the slides.', 'ekwa' ),
						value:    a.arrowOffset === undefined ? 16 : a.arrowOffset,
						min:      0,
						max:      80,
						onChange: function ( v ) { set( { arrowOffset: v } ); },
						__nextHasNoMarginBottom: true,
					} ),

					a.showArrows && ( a.arrowPosition || 'inside' ).indexOf( '-' ) > 0 && el( RangeControl, {
						label:    __( 'Gap between arrows (px)', 'ekwa' ),
						value:    a.arrowGap === undefined ? 12 : a.arrowGap,
						min:      0,
						max:      48,
						onChange: function ( v ) { set( { arrowGap: v } ); },
						__nextHasNoMarginBottom: true,
					} ),

					// Custom arrow icons. Left empty (the default) the block keeps
					// its built-in chevrons, so nothing changes for existing carousels.
					a.showArrows && el( TextareaControl, {
						label:    __( 'Previous arrow icon (SVG)', 'ekwa' ),
						help:     __( 'Paste SVG markup to replace the default chevron. Leave empty for the default. Scripts and event handlers are stripped on output.', 'ekwa' ),
						value:    a.prevIcon || '',
						rows:     4,
						onChange: function ( v ) { set( { prevIcon: v } ); },
						__nextHasNoMarginBottom: true,
					} ),

					a.showArrows && el( TextareaControl, {
						label:    __( 'Next arrow icon (SVG)', 'ekwa' ),
						help:     __( 'Paste SVG markup to replace the default chevron. Leave empty for the default.', 'ekwa' ),
						value:    a.nextIcon || '',
						rows:     4,
						onChange: function ( v ) { set( { nextIcon: v } ); },
						__nextHasNoMarginBottom: true,
					} ),

					// Preview of the pair, since the editor canvas stacks the slides
					// and never draws the arrows themselves.
					a.showArrows && ( a.prevIcon || a.nextIcon ) && el( 'div', { className: 'ekwa-carousel__icon-preview' },
						el( 'span', { className: 'ekwa-carousel__icon-preview-label' }, __( 'Preview', 'ekwa' ) ),
						el( 'span', { className: 'ekwa-carousel__icon-preview-arrows' },
							arrowPreview( a.prevIcon, 'left' ),
							arrowPreview( a.nextIcon, 'right' )
						)
					),

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
