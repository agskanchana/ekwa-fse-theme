/**
 * Ekwa Image Block — Block Editor UI.
 *
 * Clean <img> element with no figure wrapper.
 * Output: <img class="..." src="..." alt="..." loading="lazy">
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
	var SelectControl      = wp.components.SelectControl;
	var Button             = wp.components.Button;
	var ToggleControl      = wp.components.ToggleControl;
	var Placeholder        = wp.components.Placeholder;
	var __                 = wp.i18n.__;

	registerBlockType( 'ekwa/image', {
		edit: function ( props ) {
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;

			var src        = attributes.src        || '';
			var mediaId    = attributes.mediaId    || 0;
			var alt        = attributes.alt        || '';
			var width      = attributes.width      || '';
			var height     = attributes.height     || '';
			var loading    = attributes.loading    || 'lazy';
			var objectFit  = attributes.objectFit  || '';
			var linkUrl    = attributes.linkUrl    || '';
			var linkNewTab = !! attributes.linkNewTab;

			var blockProps = useBlockProps( {
				style: {
					lineHeight: 0,
				},
			} );

			function onSelectImage( media ) {
				setAttributes( {
					src: media.url,
					mediaId: media.id,
					alt: alt || media.alt || '',
					width: media.width ? String( media.width ) : '',
					height: media.height ? String( media.height ) : '',
				} );
			}

			function onRemoveImage() {
				setAttributes( {
					src: '',
					mediaId: 0,
				} );
			}

			function isExternalUrl( url ) {
				if ( ! url || url.charAt( 0 ) === '/' || url.charAt( 0 ) === '#' ) {
					return false;
				}
				try {
					var linkHost = new URL( url ).hostname;
					return linkHost !== window.location.hostname;
				} catch ( e ) {
					return false;
				}
			}

			function onLinkUrlChange( val ) {
				setAttributes( {
					linkUrl: val,
					linkNewTab: isExternalUrl( val ),
				} );
			}

			// Inspector controls.
			var inspector = el( InspectorControls, null,
				el( PanelBody, { title: __( 'Image Settings' ), initialOpen: true },
					el( TextControl, {
						label: __( 'Image URL' ),
						value: src,
						onChange: function ( val ) { setAttributes( { src: val } ); },
					} ),
					el( TextControl, {
						label: __( 'Alt Text' ),
						value: alt,
						onChange: function ( val ) { setAttributes( { alt: val } ); },
					} ),
					el( TextControl, {
						label: __( 'Width' ),
						value: width,
						onChange: function ( val ) { setAttributes( { width: val } ); },
						help: __( 'e.g. 600, 100%, auto' ),
					} ),
					el( TextControl, {
						label: __( 'Height' ),
						value: height,
						onChange: function ( val ) { setAttributes( { height: val } ); },
					} ),
					el( SelectControl, {
						label: __( 'Loading' ),
						value: loading,
						options: [
							{ label: 'Lazy',  value: 'lazy' },
							{ label: 'Eager', value: 'eager' },
						],
						onChange: function ( val ) { setAttributes( { loading: val } ); },
					} ),
					el( SelectControl, {
						label: __( 'Object Fit' ),
						value: objectFit,
						options: [
							{ label: 'None',    value: '' },
							{ label: 'Cover',   value: 'cover' },
							{ label: 'Contain', value: 'contain' },
							{ label: 'Fill',    value: 'fill' },
						],
						onChange: function ( val ) { setAttributes( { objectFit: val } ); },
					} )
				),
				el( PanelBody, { title: __( 'Link Settings' ), initialOpen: false },
					el( TextControl, {
						label: __( 'Link URL' ),
						value: linkUrl,
						onChange: onLinkUrlChange,
						help: __( 'External links automatically open in a new tab.' ),
					} ),
					linkUrl ? el( ToggleControl, {
						label: __( 'Open in new tab' ),
						checked: linkNewTab,
						onChange: function ( val ) { setAttributes( { linkNewTab: val } ); },
					} ) : null
				)
			);

			// No image selected — show placeholder with upload button.
			if ( ! src ) {
				return el( Fragment, null,
					inspector,
					el( 'div', blockProps,
						el( MediaUploadCheck, null,
							el( MediaUpload, {
								onSelect: onSelectImage,
								allowedTypes: [ 'image' ],
								value: mediaId,
								render: function ( renderProps ) {
									return el( Placeholder, {
										icon: 'format-image',
										label: __( 'Ekwa Image' ),
										instructions: __( 'Select or upload an image.' ),
									},
										el( Button, {
											variant: 'primary',
											onClick: renderProps.open,
										}, __( 'Choose Image' ) )
									);
								},
							} )
						)
					)
				);
			}

			// Image selected — show preview.
			var imgStyle = {};
			if ( objectFit ) { imgStyle.objectFit = objectFit; }
			if ( width )     { imgStyle.maxWidth = '100%'; }

			return el( Fragment, null,
				inspector,
				el( 'div', blockProps,
					el( 'img', {
						src: src,
						alt: alt,
						width: width || undefined,
						height: height || undefined,
						style: imgStyle,
					} ),
					el( 'div', { style: { marginTop: '8px', display: 'flex', gap: '8px' } },
						el( MediaUploadCheck, null,
							el( MediaUpload, {
								onSelect: onSelectImage,
								allowedTypes: [ 'image' ],
								value: mediaId,
								render: function ( renderProps ) {
									return el( Button, {
										variant: 'secondary',
										isSmall: true,
										onClick: renderProps.open,
									}, __( 'Replace' ) );
								},
							} )
						),
						el( Button, {
							variant: 'link',
							isDestructive: true,
							isSmall: true,
							onClick: onRemoveImage,
						}, __( 'Remove' ) )
					)
				)
			);
		},

		save: function () {
			return null;
		},
	} );
} )( window.wp );
