/**
 * Ekwa Section Block — Block Editor UI.
 *
 * Semantic section wrapper with optional background image, overlay,
 * and inner container width. Outputs <section> (or other tag) on frontend.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType  = wp.blocks.registerBlockType;
	var el                 = wp.element.createElement;
	var Fragment           = wp.element.Fragment;
	var InnerBlocks        = wp.blockEditor.InnerBlocks;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var MediaUpload        = wp.blockEditor.MediaUpload;
	var MediaUploadCheck   = wp.blockEditor.MediaUploadCheck;
	var PanelBody          = wp.components.PanelBody;
	var SelectControl      = wp.components.SelectControl;
	var TextControl        = wp.components.TextControl;
	var RangeControl       = wp.components.RangeControl;
	var ToggleControl      = wp.components.ToggleControl;
	var Button             = wp.components.Button;
	var ColorPalette       = wp.components.ColorPalette;
	var __                 = wp.i18n.__;

	var TAG_OPTIONS = [
		{ label: 'section', value: 'section' },
		{ label: 'div',     value: 'div' },
		{ label: 'header',  value: 'header' },
		{ label: 'footer',  value: 'footer' },
		{ label: 'main',    value: 'main' },
		{ label: 'aside',   value: 'aside' },
		{ label: 'article', value: 'article' },
	];

	function Edit( props ) {
		var attributes    = props.attributes;
		var setAttributes = props.setAttributes;

		var tagName        = attributes.tagName        || 'section';
		var containerWidth = attributes.containerWidth  || '';
		var bgImageUrl     = attributes.bgImageUrl      || '';
		var bgSize         = attributes.bgSize          || 'cover';
		var bgPosition     = attributes.bgPosition      || '50% 50%';
		var bgFixed        = !! attributes.bgFixed;
		var overlayColor   = attributes.overlayColor    || '';
		var overlayOpacity = typeof attributes.overlayOpacity === 'number' ? attributes.overlayOpacity : 50;

		var wrapperStyle = {};
		if ( bgImageUrl ) {
			wrapperStyle.backgroundImage    = 'url(' + bgImageUrl + ')';
			wrapperStyle.backgroundSize     = bgSize;
			wrapperStyle.backgroundPosition = bgPosition;
			if ( bgFixed ) {
				wrapperStyle.backgroundAttachment = 'fixed';
			}
		}

		var blockProps = useBlockProps( {
			className: 'ekwa-section',
			style: wrapperStyle,
		} );

		return el( Fragment, null,

			/* ---------- Inspector sidebar ---------- */
			el( InspectorControls, null,

				el( PanelBody, { title: __( 'Tag & Structure', 'ekwa' ), initialOpen: true },
					el( SelectControl, {
						label:    __( 'HTML Tag', 'ekwa' ),
						value:    tagName,
						options:  TAG_OPTIONS,
						onChange: function ( v ) { setAttributes( { tagName: v } ); },
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} ),
					el( TextControl, {
						label:    __( 'Inner Container Width', 'ekwa' ),
						help:     __( 'Optional max-width for inner content (e.g. 900px, 1280px). Leave empty for full width.', 'ekwa' ),
						value:    containerWidth,
						onChange: function ( v ) { setAttributes( { containerWidth: v.trim() } ); },
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} )
				),

				el( PanelBody, { title: __( 'Background Image', 'ekwa' ), initialOpen: false },
					el( MediaUploadCheck, null,
						el( MediaUpload, {
							onSelect: function ( media ) {
								setAttributes( { bgImageUrl: media.url, bgImageId: media.id } );
							},
							allowedTypes: [ 'image' ],
							value:        attributes.bgImageId,
							render: function ( obj ) {
								return el( Fragment, null,
									bgImageUrl && el( 'div', { style: { marginBottom: '12px' } },
										el( 'img', {
											src:   bgImageUrl,
											alt:   '',
											style: { maxWidth: '100%', height: 'auto', borderRadius: '4px' },
										} )
									),
									el( Button, {
										onClick:   obj.open,
										variant:   bgImageUrl ? 'secondary' : 'primary',
										__next40pxDefaultSize: true,
									}, bgImageUrl ? __( 'Replace Image', 'ekwa' ) : __( 'Select Image', 'ekwa' ) ),
									bgImageUrl && el( Button, {
										onClick: function () {
											setAttributes( { bgImageUrl: '', bgImageId: 0 } );
										},
										variant: 'tertiary',
										isDestructive: true,
										style: { marginLeft: '8px' },
									}, __( 'Remove', 'ekwa' ) )
								);
							},
						} )
					),
					bgImageUrl && el( SelectControl, {
						label:   __( 'Background Size', 'ekwa' ),
						value:   bgSize,
						options: [
							{ label: 'cover',   value: 'cover' },
							{ label: 'contain', value: 'contain' },
							{ label: 'auto',    value: 'auto' },
						],
						onChange: function ( v ) { setAttributes( { bgSize: v } ); },
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} ),
					bgImageUrl && el( TextControl, {
						label:    __( 'Background Position', 'ekwa' ),
						help:     __( 'CSS background-position (e.g. 50% 50%, center top).', 'ekwa' ),
						value:    bgPosition,
						onChange: function ( v ) { setAttributes( { bgPosition: v.trim() } ); },
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} ),
					bgImageUrl && el( ToggleControl, {
						label:    __( 'Fixed (Parallax)', 'ekwa' ),
						checked:  bgFixed,
						onChange: function ( v ) { setAttributes( { bgFixed: v } ); },
						__nextHasNoMarginBottom: true,
					} )
				),

				el( PanelBody, { title: __( 'Overlay', 'ekwa' ), initialOpen: false },
					el( 'p', {
						style: { fontSize: '11px', textTransform: 'uppercase', fontWeight: 600, marginBottom: '8px' },
					}, __( 'Overlay Color', 'ekwa' ) ),
					el( ColorPalette, {
						value:    overlayColor,
						onChange: function ( v ) { setAttributes( { overlayColor: v || '' } ); },
					} ),
					overlayColor && el( RangeControl, {
						label:    __( 'Overlay Opacity', 'ekwa' ),
						value:    overlayOpacity,
						onChange: function ( v ) { setAttributes( { overlayOpacity: v } ); },
						min:      0,
						max:      100,
						step:     5,
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} )
				)
			),

			/* ---------- Canvas ---------- */
			el( 'div', blockProps,

				/* Overlay preview */
				overlayColor && el( 'div', {
					className:    'ekwa-section__overlay',
					'aria-hidden': 'true',
					style: {
						position:   'absolute',
						inset:      0,
						background: overlayColor,
						opacity:    overlayOpacity / 100,
						pointerEvents: 'none',
						zIndex:     0,
					},
				} ),

				/* Inner container preview */
				containerWidth
					? el( 'div', {
						style: {
							maxWidth:   containerWidth,
							marginLeft: 'auto',
							marginRight: 'auto',
							position:   'relative',
							zIndex:     1,
						},
					}, el( InnerBlocks, { templateLock: false } ) )
					: el( 'div', { style: { position: 'relative', zIndex: 1 } },
						el( InnerBlocks, { templateLock: false } )
					)
			)
		);
	}

	registerBlockType( 'ekwa/section', {
		edit: Edit,
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );

} )( window.wp );
