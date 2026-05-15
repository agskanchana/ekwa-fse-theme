/**
 * Ekwa Video Block — Block Editor UI.
 *
 * Clean video element that outputs <video> with attributes.
 * No figure wrapper, no wp-block-video class.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType  = wp.blocks.registerBlockType;
	var el                 = wp.element.createElement;
	var Fragment           = wp.element.Fragment;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var MediaUpload        = wp.blockEditor.MediaUpload;
	var MediaUploadCheck   = wp.blockEditor.MediaUploadCheck;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var PanelBody          = wp.components.PanelBody;
	var TextControl        = wp.components.TextControl;
	var ToggleControl      = wp.components.ToggleControl;
	var TextareaControl    = wp.components.TextareaControl;
	var Button             = wp.components.Button;
	var __                 = wp.i18n.__;

	registerBlockType( 'ekwa/video', {
		edit: function ( props ) {
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;

			var blockProps = useBlockProps( {
				style: {
					border: '1px dashed #ccc',
					padding: '12px',
					minHeight: '60px',
					textAlign: 'center',
					backgroundColor: '#f9f9f9',
				},
			} );

			var preview = null;
			if ( attributes.src ) {
				preview = el( 'video', {
					src: attributes.src,
					poster: attributes.poster || undefined,
					controls: true,
					style: { maxWidth: '100%', maxHeight: '200px' },
				} );
			} else {
				preview = el( 'div', {
					style: { padding: '20px', color: '#999', fontSize: '13px' },
				}, __( 'No video selected. Use the sidebar to add a video source.', 'ekwa' ) );
			}

			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Video Source' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Video URL' ),
							value: attributes.src,
							onChange: function ( val ) { setAttributes( { src: val } ); },
						} ),
						el( MediaUploadCheck, null,
							el( MediaUpload, {
								onSelect: function ( media ) {
									setAttributes( { src: media.url, mediaId: media.id } );
								},
								allowedTypes: [ 'video' ],
								value: attributes.mediaId,
								render: function ( obj ) {
									return el( Button, {
										onClick: obj.open,
										isSecondary: true,
										style: { marginBottom: '12px' },
									}, attributes.mediaId ? __( 'Replace Video' ) : __( 'Select Video' ) );
								},
							} )
						),
						el( TextControl, {
							label: __( 'Poster Image URL' ),
							value: attributes.poster,
							onChange: function ( val ) { setAttributes( { poster: val } ); },
						} ),
						el( MediaUploadCheck, null,
							el( MediaUpload, {
								onSelect: function ( media ) {
									setAttributes( { poster: media.url, posterId: media.id } );
								},
								allowedTypes: [ 'image' ],
								value: attributes.posterId,
								render: function ( obj ) {
									return el( Button, {
										onClick: obj.open,
										isSecondary: true,
									}, attributes.posterId ? __( 'Replace Poster' ) : __( 'Select Poster' ) );
								},
							} )
						)
					),
					el( PanelBody, { title: __( 'Playback' ), initialOpen: false },
						el( ToggleControl, {
							label: __( 'Autoplay' ),
							checked: attributes.autoplay,
							onChange: function ( val ) { setAttributes( { autoplay: val } ); },
						} ),
						el( ToggleControl, {
							label: __( 'Loop' ),
							checked: attributes.loop,
							onChange: function ( val ) { setAttributes( { loop: val } ); },
						} ),
						el( ToggleControl, {
							label: __( 'Muted' ),
							checked: attributes.muted,
							onChange: function ( val ) { setAttributes( { muted: val } ); },
						} ),
						el( ToggleControl, {
							label: __( 'Plays Inline' ),
							checked: attributes.playsinline,
							onChange: function ( val ) { setAttributes( { playsinline: val } ); },
						} ),
						el( ToggleControl, {
							label: __( 'Show Controls' ),
							checked: attributes.controls,
							onChange: function ( val ) { setAttributes( { controls: val } ); },
						} )
					),
					el( PanelBody, { title: __( 'Performance' ), initialOpen: false },
						el( ToggleControl, {
							label: __( 'Lazy load' ),
							help: __( 'With Performance → Lazy mode = lazysizes, the video file is fetched only when the wrapper enters the viewport (works alongside Autoplay). With Native mode, applies preload="none" — ignored if Autoplay is on.' ),
							checked: !! attributes.lazyLoad,
							onChange: function ( val ) { setAttributes( { lazyLoad: val } ); },
						} )
					),
					el( PanelBody, { title: __( 'Transcript' ), initialOpen: false },
						el( 'p', { style: { marginTop: 0, color: '#666', fontSize: '12px' } },
							__( 'Leave blank to hide the transcript toggle. Use line breaks for paragraphs.', 'ekwa' )
						),
						el( TextareaControl, {
							label: __( 'Transcript text', 'ekwa' ),
							help: __( 'One paragraph per blank-line-separated block. Plain text — formatting is added automatically.', 'ekwa' ),
							value: attributes.transcript || '',
							onChange: function ( val ) { setAttributes( { transcript: val } ); },
							rows: 8,
						} ),
						el( TextControl, {
							label: __( 'Show button label', 'ekwa' ),
							value: attributes.transcriptShowLabel,
							onChange: function ( val ) { setAttributes( { transcriptShowLabel: val } ); },
						} ),
						el( TextControl, {
							label: __( 'Hide button label', 'ekwa' ),
							value: attributes.transcriptHideLabel,
							onChange: function ( val ) { setAttributes( { transcriptHideLabel: val } ); },
						} ),
						el( TextControl, {
							label: __( 'Icon class', 'ekwa' ),
							help: __( 'Font Awesome class for the button icon. Leave blank for no icon.', 'ekwa' ),
							value: attributes.transcriptIcon,
							onChange: function ( val ) { setAttributes( { transcriptIcon: val } ); },
						} )
					)
				),
				el( 'div', blockProps, preview,
					attributes.transcript
						? el( 'div', {
							style: {
								marginTop: '8px',
								padding: '6px 8px',
								borderTop: '1px dashed #ccc',
								fontSize: '11px',
								color: '#666',
								textAlign: 'left',
							},
						},
							el( 'strong', null, __( 'Transcript:', 'ekwa' ) ),
							' ',
							attributes.transcript.length > 120
								? attributes.transcript.substring( 0, 120 ) + '…'
								: attributes.transcript
						)
						: null
				)
			);
		},

		save: function () {
			return null;
		},
	} );
} )( window.wp );
