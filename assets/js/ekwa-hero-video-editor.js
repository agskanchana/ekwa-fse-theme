/**
 * Ekwa Hero Video — Block Editor UI. Background video with poster, overlay
 * and caption InnerBlocks.
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

	registerBlockType( 'ekwa/hero-video', {
		edit: function ( props ) {
			var a   = props.attributes;
			var set = props.setAttributes;

			var style = {
				minHeight: a.minHeight || '80vh',
				backgroundImage: a.posterUrl ? "url('" + a.posterUrl + "')" : undefined,
				backgroundSize: 'cover',
				backgroundPosition: 'center',
			};
			var blockProps = useBlockProps( { className: 'ekwa-hero-video--editor', style: style } );

			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Video', 'ekwa' ), initialOpen: true },
						el( MediaUploadCheck, null,
							el( MediaUpload, {
								onSelect: function ( media ) { set( { videoUrl: media.url, videoId: media.id } ); },
								allowedTypes: [ 'video' ],
								value: a.videoId,
								render: function ( obj ) {
									return el( Button, { onClick: obj.open, isSecondary: true },
										a.videoUrl ? __( 'Replace video', 'ekwa' ) : __( 'Select video (mp4/webm)', 'ekwa' ) );
								},
							} )
						),
						a.videoUrl ? el( 'p', { style: { fontSize: '11px', color: '#757575', wordBreak: 'break-all' } }, a.videoUrl ) : null,
						el( MediaUploadCheck, null,
							el( MediaUpload, {
								onSelect: function ( media ) { set( { posterUrl: media.url, posterId: media.id } ); },
								allowedTypes: [ 'image' ],
								value: a.posterId,
								render: function ( obj ) {
									return el( Button, { onClick: obj.open, isSecondary: true, style: { marginTop: '8px' } },
										a.posterUrl ? __( 'Replace poster image', 'ekwa' ) : __( 'Select poster image', 'ekwa' ) );
								},
							} )
						),
						el( 'p', { style: { fontSize: '12px', color: '#757575' } },
							__( 'The poster paints first and stays for visitors with reduced motion. Keep the video short, muted and compressed (a 10–20s loop under ~4 MB).', 'ekwa' )
						),
						el( ToggleControl, {
							label: __( 'Show pause button', 'ekwa' ),
							help: __( 'Accessible control to stop the background motion.', 'ekwa' ),
							checked: a.showPauseButton,
							onChange: function ( v ) { set( { showPauseButton: v } ); },
						} )
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
						el( TextControl, {
							label: __( 'Minimum height (CSS length)', 'ekwa' ),
							value: a.minHeight,
							onChange: function ( v ) { set( { minHeight: v } ); },
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
						className: 'ekwa-hero-video__editor-overlay',
						style: { background: a.overlayColor, opacity: a.overlayOpacity / 100 },
					} ) : null,
					! a.videoUrl ? el( 'div', { className: 'ekwa-hero-video__editor-empty' },
						__( 'Hero Video — select a background video in the sidebar.', 'ekwa' )
					) : null,
					el( 'div', { className: 'ekwa-hero-video__editor-content ekwa-hero-video--' + ( a.contentAlign || 'left' ) },
						el( InnerBlocks, null )
					)
				)
			);
		},
		save: function () { return el( InnerBlocks.Content, null ); },
	} );
} )( window.wp );
