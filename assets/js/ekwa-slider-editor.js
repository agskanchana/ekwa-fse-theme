/**
 * Ekwa Slider suite — Block Editor UI for ekwa/slider, ekwa/slide and
 * ekwa/slide-content. Slides render stacked in the canvas so every slide
 * stays editable; animations show as a badge (name + delay) and replay on
 * the front end each time the slide activates.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el                = wp.element.createElement;
	var Fragment          = wp.element.Fragment;
	var InnerBlocks       = wp.blockEditor.InnerBlocks;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var MediaUpload       = wp.blockEditor.MediaUpload;
	var MediaUploadCheck  = wp.blockEditor.MediaUploadCheck;
	var PanelBody         = wp.components.PanelBody;
	var SelectControl     = wp.components.SelectControl;
	var ToggleControl     = wp.components.ToggleControl;
	var RangeControl      = wp.components.RangeControl;
	var TextControl       = wp.components.TextControl;
	var Button            = wp.components.Button;
	var __                = wp.i18n.__;

	var cfg         = window.ekwaSliderConfig || {};
	var ANIMATIONS  = cfg.animations || { none: 'None', fadeIn: 'Fade In', fadeInUp: 'Fade In Up' };
	var TRANSITIONS = cfg.transitions || { fade: 'Fade', slide: 'Slide (horizontal)', zoom: 'Zoom (Ken Burns fade)' };

	var ANIM_OPTIONS = Object.keys( ANIMATIONS ).map( function ( k ) {
		return { value: k, label: ANIMATIONS[ k ] };
	} );
	var TRANSITION_OPTIONS = Object.keys( TRANSITIONS ).map( function ( k ) {
		return { value: k, label: TRANSITIONS[ k ] };
	} );

	// ── ekwa/slider ─────────────────────────────────────────────────────
	registerBlockType( 'ekwa/slider', {
		edit: function ( props ) {
			var a   = props.attributes;
			var set = props.setAttributes;
			var blockProps = useBlockProps( { className: 'ekwa-slider--editor' } );

			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Slider Settings', 'ekwa' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Transition effect', 'ekwa' ),
							value: a.transition,
							options: TRANSITION_OPTIONS,
							onChange: function ( v ) { set( { transition: v } ); },
						} ),
						el( TextControl, {
							label: __( 'Minimum height (CSS length)', 'ekwa' ),
							value: a.minHeight,
							help: __( 'e.g. 80vh, 560px', 'ekwa' ),
							onChange: function ( v ) { set( { minHeight: v } ); },
						} ),
						el( ToggleControl, {
							label: __( 'Autoplay', 'ekwa' ),
							checked: a.autoplay,
							onChange: function ( v ) { set( { autoplay: v } ); },
						} ),
						a.autoplay ? el( RangeControl, {
							label: __( 'Autoplay interval (ms)', 'ekwa' ),
							value: a.interval,
							min: 2000, max: 15000, step: 500,
							onChange: function ( v ) { set( { interval: v } ); },
						} ) : null,
						el( ToggleControl, {
							label: __( 'Show arrows', 'ekwa' ),
							checked: a.showArrows,
							onChange: function ( v ) { set( { showArrows: v } ); },
						} ),
						el( ToggleControl, {
							label: __( 'Show dots', 'ekwa' ),
							checked: a.showDots,
							onChange: function ( v ) { set( { showDots: v } ); },
						} ),
						el( ToggleControl, {
							label: __( 'Loop', 'ekwa' ),
							checked: a.loop,
							onChange: function ( v ) { set( { loop: v } ); },
						} ),
						el( ToggleControl, {
							label: __( 'Pause on hover', 'ekwa' ),
							checked: a.pauseOnHover,
							onChange: function ( v ) { set( { pauseOnHover: v } ); },
						} )
					)
				),
				el( 'div', blockProps,
					el( 'p', { className: 'ekwa-slider__editor-notice' },
						__( 'Slider — slides are stacked here for editing; they cycle with the chosen transition on the live site.', 'ekwa' )
					),
					el( 'div', { className: 'ekwa-slider__editor-slides' },
						el( InnerBlocks, {
							allowedBlocks: [ 'ekwa/slide' ],
							template: [ [ 'ekwa/slide', {}, [ [ 'ekwa/slide-content' ] ] ] ],
							renderAppender: InnerBlocks.ButtonBlockAppender,
						} )
					)
				)
			);
		},
		save: function () { return el( InnerBlocks.Content, null ); },
	} );

	// ── ekwa/slide ──────────────────────────────────────────────────────
	registerBlockType( 'ekwa/slide', {
		edit: function ( props ) {
			var a   = props.attributes;
			var set = props.setAttributes;

			var style = {
				backgroundImage:    a.bgImage ? "url('" + a.bgImage + "')" : undefined,
				backgroundSize:     'cover',
				backgroundPosition: a.bgPosition || 'center center',
			};
			var blockProps = useBlockProps( { className: 'ekwa-slide--editor', style: style } );

			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Background', 'ekwa' ), initialOpen: true },
						a.bgImage ? el( 'div', { style: { marginBottom: '8px', borderRadius: '4px', overflow: 'hidden' } },
							el( 'img', { src: a.bgImage, alt: '', style: { width: '100%', display: 'block' } } )
						) : null,
						el( MediaUploadCheck, null,
							el( MediaUpload, {
								onSelect: function ( media ) { set( { bgImage: media.url, bgImageId: media.id } ); },
								allowedTypes: [ 'image' ],
								value: a.bgImageId,
								render: function ( obj ) {
									return el( Button, { onClick: obj.open, isSecondary: true },
										a.bgImage ? __( 'Replace image', 'ekwa' ) : __( 'Select background image', 'ekwa' ) );
								},
							} )
						),
						a.bgImage ? el( Button, {
							isDestructive: true, isSmall: true, style: { marginLeft: '8px' },
							onClick: function () { set( { bgImage: '', bgImageId: 0 } ); },
						}, __( 'Remove', 'ekwa' ) ) : null,
						el( SelectControl, {
							label: __( 'Focal position', 'ekwa' ),
							value: a.bgPosition,
							options: [
								{ value: 'center center', label: __( 'Center', 'ekwa' ) },
								{ value: 'center top',    label: __( 'Top', 'ekwa' ) },
								{ value: 'center bottom', label: __( 'Bottom', 'ekwa' ) },
								{ value: 'left center',   label: __( 'Left', 'ekwa' ) },
								{ value: 'right center',  label: __( 'Right', 'ekwa' ) },
							],
							onChange: function ( v ) { set( { bgPosition: v } ); },
						} ),
						el( 'p', { style: { fontSize: '12px', color: '#757575' } },
							__( 'Served as WebP automatically where supported; the original is the fallback.', 'ekwa' )
						)
					),
					el( PanelBody, { title: __( 'Overlay & Layout', 'ekwa' ), initialOpen: false },
						el( TextControl, {
							label: __( 'Overlay color', 'ekwa' ),
							value: a.overlayColor,
							onChange: function ( v ) { set( { overlayColor: v } ); },
						} ),
						el( RangeControl, {
							label: __( 'Overlay opacity (%)', 'ekwa' ),
							value: a.overlayOpacity,
							min: 0, max: 90,
							onChange: function ( v ) { set( { overlayOpacity: v } ); },
						} ),
						el( SelectControl, {
							label: __( 'Content alignment', 'ekwa' ),
							value: a.contentAlign,
							options: [
								{ value: 'left',   label: __( 'Left', 'ekwa' ) },
								{ value: 'center', label: __( 'Center', 'ekwa' ) },
								{ value: 'right',  label: __( 'Right', 'ekwa' ) },
							],
							onChange: function ( v ) { set( { contentAlign: v } ); },
						} )
					)
				),
				el( 'div', blockProps,
					a.overlayOpacity > 0 ? el( 'div', {
						className: 'ekwa-slide__editor-overlay',
						style: { background: a.overlayColor, opacity: a.overlayOpacity / 100 },
					} ) : null,
					el( 'div', { className: 'ekwa-slide__editor-inner ekwa-slide--' + ( a.contentAlign || 'left' ) },
						el( InnerBlocks, {
							allowedBlocks: [ 'ekwa/slide-content' ],
							template: [ [ 'ekwa/slide-content' ] ],
							renderAppender: InnerBlocks.ButtonBlockAppender,
						} )
					)
				)
			);
		},
		save: function () { return el( InnerBlocks.Content, null ); },
	} );

	// ── ekwa/slide-content ──────────────────────────────────────────────
	registerBlockType( 'ekwa/slide-content', {
		edit: function ( props ) {
			var a   = props.attributes;
			var set = props.setAttributes;
			var blockProps = useBlockProps( { className: 'ekwa-slide-content--editor' } );

			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Animation Settings', 'ekwa' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Animation type', 'ekwa' ),
							help: __( 'Entrance effect, replayed every time the slide becomes active.', 'ekwa' ),
							value: a.animation,
							options: ANIM_OPTIONS,
							onChange: function ( v ) { set( { animation: v } ); },
						} ),
						el( RangeControl, {
							label: __( 'Animation delay (ms)', 'ekwa' ),
							value: a.delay,
							min: 0, max: 3000, step: 100,
							onChange: function ( v ) { set( { delay: v } ); },
						} ),
						el( RangeControl, {
							label: __( 'Animation duration (ms)', 'ekwa' ),
							value: a.duration,
							min: 200, max: 3000, step: 100,
							onChange: function ( v ) { set( { duration: v } ); },
						} )
					)
				),
				el( 'div', blockProps,
					el( 'span', { className: 'ekwa-slide-content__badge' },
						__( 'Slider Content', 'ekwa' ),
						'none' !== a.animation
							? el( 'em', null, ' ' + ( ANIMATIONS[ a.animation ] || a.animation ) + ( a.delay ? ' +' + a.delay + 'ms' : '' ) )
							: null
					),
					el( InnerBlocks, null )
				)
			);
		},
		save: function () { return el( InnerBlocks.Content, null ); },
	} );
} )( window.wp );
