/**
 * Ekwa Carousel Controls — shared Inspector helper.
 *
 * Exposes window.EkwaCarouselControls for the carousel-driven editor scripts
 * (ekwa/carousel and ekwa/related-articles) so both offer the same arrow
 * position, offset, gap and custom-icon options from a single definition.
 *
 * Both blocks render the same .ekwa-carousel markup and share
 * blocks/ekwa-carousel/style.css on the frontend, so the option set and the
 * values it writes are identical — only the resolved defaults differ, and
 * those are passed in by the caller.
 */
( function ( wp ) {
	'use strict';

	var el              = wp.element.createElement;
	var RangeControl    = wp.components.RangeControl;
	var SelectControl   = wp.components.SelectControl;
	var TextareaControl = wp.components.TextareaControl;
	var __              = wp.i18n.__;

	var ARROW_POSITIONS = [
		{ label: __( 'Over the slides (left & right)', 'ekwa' ),   value: 'inside' },
		{ label: __( 'Beside the slides (left & right)', 'ekwa' ), value: 'outside' },
		{ label: __( 'Above — left', 'ekwa' ),    value: 'top-left' },
		{ label: __( 'Above — centre', 'ekwa' ),  value: 'top-center' },
		{ label: __( 'Above — right', 'ekwa' ),   value: 'top-right' },
		{ label: __( 'Below — left', 'ekwa' ),    value: 'bottom-left' },
		{ label: __( 'Below — centre', 'ekwa' ),  value: 'bottom-center' },
		{ label: __( 'Below — right', 'ekwa' ),   value: 'bottom-right' }
	];

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

	/**
	 * The arrow appearance controls: position, offset, pair gap and custom icons.
	 *
	 * Callers resolve the current values themselves — each block has its own
	 * defaults, and ekwa/related-articles also falls back to legacy attributes —
	 * so this only renders the controls and writes the chosen values back.
	 *
	 * Returned as a keyed array so the caller can spread it into a PanelBody
	 * alongside its own block-specific controls.
	 *
	 * @param {Object}   a       Block attributes.
	 * @param {Function} set     setAttributes.
	 * @param {Object}   current Resolved values: { position, offset, gap }.
	 * @return {Array} Inspector control elements.
	 */
	function arrowControls( a, set, current ) {
		var position = current.position;
		var controls = [];

		controls.push( el( SelectControl, {
			key:      'arrow-position',
			label:    __( 'Arrow position', 'ekwa' ),
			value:    position,
			options:  ARROW_POSITIONS,
			help:     __( '“Beside” and the above/below positions reserve their own space, so the arrows never cover a slide.', 'ekwa' ),
			onChange: function ( v ) { set( { arrowPosition: v } ); },
			__nextHasNoMarginBottom: true,
		} ) );

		// "inside" overlays the slides, so there is nothing to offset from.
		if ( 'inside' !== position ) {
			controls.push( el( RangeControl, {
				key:      'arrow-offset',
				label:    __( 'Arrow offset (px)', 'ekwa' ),
				help:     __( 'Distance from the slides.', 'ekwa' ),
				value:    current.offset,
				min:      0,
				max:      80,
				onChange: function ( v ) { set( { arrowOffset: v } ); },
				__nextHasNoMarginBottom: true,
			} ) );
		}

		// Only the corner positions lay the two arrows out as a pair.
		if ( position.indexOf( '-' ) > 0 ) {
			controls.push( el( RangeControl, {
				key:      'arrow-gap',
				label:    __( 'Gap between arrows (px)', 'ekwa' ),
				value:    current.gap,
				min:      0,
				max:      48,
				onChange: function ( v ) { set( { arrowGap: v } ); },
				__nextHasNoMarginBottom: true,
			} ) );
		}

		// Custom arrow icons. Left empty (the default) the block keeps its
		// built-in chevrons, so nothing changes for existing carousels.
		controls.push( el( TextareaControl, {
			key:      'prev-icon',
			label:    __( 'Previous arrow icon (SVG)', 'ekwa' ),
			help:     __( 'Paste SVG markup to replace the default chevron. Leave empty for the default. Scripts and event handlers are stripped on output.', 'ekwa' ),
			value:    a.prevIcon || '',
			rows:     4,
			onChange: function ( v ) { set( { prevIcon: v } ); },
			__nextHasNoMarginBottom: true,
		} ) );

		controls.push( el( TextareaControl, {
			key:      'next-icon',
			label:    __( 'Next arrow icon (SVG)', 'ekwa' ),
			help:     __( 'Paste SVG markup to replace the default chevron. Leave empty for the default.', 'ekwa' ),
			value:    a.nextIcon || '',
			rows:     4,
			onChange: function ( v ) { set( { nextIcon: v } ); },
			__nextHasNoMarginBottom: true,
		} ) );

		// Preview of the pair, since neither editor canvas draws the real arrows.
		if ( a.prevIcon || a.nextIcon ) {
			controls.push( el( 'div', { key: 'icon-preview', className: 'ekwa-carousel__icon-preview' },
				el( 'span', { className: 'ekwa-carousel__icon-preview-label' }, __( 'Preview', 'ekwa' ) ),
				el( 'span', { className: 'ekwa-carousel__icon-preview-arrows' },
					arrowPreview( a.prevIcon, 'left' ),
					arrowPreview( a.nextIcon, 'right' )
				)
			) );
		}

		return controls;
	}

	window.EkwaCarouselControls = {
		ARROW_POSITIONS: ARROW_POSITIONS,
		sanitizeSvg:     sanitizeSvg,
		arrowPreview:    arrowPreview,
		arrowControls:   arrowControls
	};
} )( window.wp );
