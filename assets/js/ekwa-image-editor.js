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
	var useState           = wp.element.useState;
	var useRef             = wp.element.useRef;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var MediaUpload        = wp.blockEditor.MediaUpload;
	var MediaUploadCheck   = wp.blockEditor.MediaUploadCheck;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var PanelBody          = wp.components.PanelBody;
	var TextControl        = wp.components.TextControl;
	var SelectControl      = wp.components.SelectControl;
	var Button             = wp.components.Button;
	var ToggleControl      = wp.components.ToggleControl;
	var Notice             = wp.components.Notice;
	var Spinner            = wp.components.Spinner;
	var Placeholder        = wp.components.Placeholder;
	var apiFetch           = wp.apiFetch;
	var __                 = wp.i18n.__;

	registerBlockType( 'ekwa/image', {
		edit: function ( props ) {
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;

			var src         = attributes.src         || '';
			var mediaId     = attributes.mediaId     || 0;
			var alt         = attributes.alt         || '';
			var width       = attributes.width       || '';
			var height      = attributes.height      || '';
			var hero        = !! attributes.hero;
			var objectFit   = attributes.objectFit   || '';
			var linkUrl     = attributes.linkUrl     || '';
			var linkNewTab  = !! attributes.linkNewTab;
			var disableWebp = !! attributes.disableWebp;

			// Per-image WebP action state — kept local; not persisted on the block.
			var wpState = useState( { busy: false, notice: null } );
			var webp = wpState[0]; var setWebp = wpState[1];
			var webpFileRef = useRef( null );

			function setWebpNotice( status, message ) {
				setWebp( { busy: false, notice: { status: status, message: message } } );
			}

			// AI alt-text action state — local, not persisted on the block.
			var altState = useState( { busy: false, notice: null } );
			var altAi = altState[0]; var setAltAi = altState[1];

			function setAltNotice( status, message ) {
				setAltAi( { busy: false, notice: { status: status, message: message } } );
			}

			function handleGenerateAlt() {
				if ( ! mediaId ) {
					setAltNotice( 'error', __( 'Pick an image from the media library first.' ) );
					return;
				}
				setAltAi( { busy: true, notice: null } );
				apiFetch( {
					path: '/ekwa/v1/generate-alt',
					method: 'POST',
					data: { attachment_id: mediaId },
				} ).then( function ( res ) {
					if ( res && res.alt ) {
						setAttributes( { alt: res.alt } );
						setAltNotice( 'success', __( 'Alt text generated.' ) );
					} else {
						setAltNotice( 'error', __( 'No alt text was returned.' ) );
					}
				} ).catch( function ( err ) {
					var msg = ( err && err.message ) ? err.message : __( 'Alt text generation failed.' );
					if ( err && err.code === 'no_api_key' ) {
						msg = __( 'Gemini API key is not configured (Settings → AI).' );
					}
					setAltNotice( 'error', msg );
				} );
			}

			function handleRegenWebp() {
				if ( ! mediaId ) {
					setWebpNotice( 'error', __( 'Pick an image from the media library first.' ) );
					return;
				}
				setWebp( { busy: true, notice: null } );
				apiFetch( {
					path: '/ekwa/v1/webp-regen-one',
					method: 'POST',
					data: { attachment_id: mediaId },
				} ).then( function ( res ) {
					if ( res && res.primary_ok ) {
						setWebpNotice( 'success', __( 'WebP regenerated.' ) + ' (' + ( res.generated || 0 ) + ' ' + __( 'file(s)' ) + ')' );
					} else {
						setWebpNotice( 'warning', __( 'Regeneration finished but the primary WebP is still invalid. The original image will be served.' ) );
					}
				} ).catch( function ( err ) {
					setWebpNotice( 'error', ( err && err.message ) ? err.message : __( 'Regeneration failed.' ) );
				} );
			}

			function handleUploadWebp( event ) {
				var file = event.target.files && event.target.files[0];
				if ( webpFileRef.current ) webpFileRef.current.value = '';
				if ( ! file ) return;
				if ( ! mediaId ) {
					setWebpNotice( 'error', __( 'Pick an image from the media library first.' ) );
					return;
				}
				if ( file.type && file.type !== 'image/webp' ) {
					setWebpNotice( 'error', __( 'File must be a .webp image.' ) );
					return;
				}
				var form = new FormData();
				form.append( 'attachment_id', String( mediaId ) );
				form.append( 'file', file );
				setWebp( { busy: true, notice: null } );
				apiFetch( {
					path: '/ekwa/v1/webp-upload-one',
					method: 'POST',
					body: form,
				} ).then( function ( res ) {
					if ( res && res.primary_ok ) {
						setWebpNotice( 'success', __( 'Replacement WebP installed.' ) + ' (' + ( res.bytes_written || 0 ) + ' bytes)' );
					} else {
						setWebpNotice( 'warning', __( 'Upload finished but verification failed.' ) );
					}
				} ).catch( function ( err ) {
					setWebpNotice( 'error', ( err && err.message ) ? err.message : __( 'Upload failed.' ) );
				} );
			}

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
					el( 'div', { style: { margin: '-4px 0 16px' } },
						el( Button, {
							variant: 'secondary',
							isSmall: true,
							onClick: handleGenerateAlt,
							disabled: altAi.busy || ! mediaId,
						}, altAi.busy
							? el( Fragment, null, el( Spinner, null ), __( ' Generating…' ) )
							: __( 'Generate with AI' )
						),
						! mediaId ? el( 'p', { style: { margin: '6px 0 0', fontSize: '12px', color: '#757575' } },
							__( 'Select a media-library image to enable AI alt text.' )
						) : null,
						altAi.notice ? el( 'div', { style: { marginTop: '8px' } },
							el( Notice, {
								status: altAi.notice.status,
								isDismissible: true,
								onRemove: function () { setAltAi( { busy: false, notice: null } ); },
							}, altAi.notice.message )
						) : null
					),
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
					el( ToggleControl, {
						label: __( 'Hero image (above the fold)' ),
						checked: hero,
						onChange: function ( val ) {
							setAttributes( {
								hero: val,
								loading: val ? 'eager' : 'lazy',
							} );
						},
						help: __( 'Loads eagerly with high fetch priority and a <link rel=preload> hint. Use for the LCP image only.' ),
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
				),
				el( PanelBody, { title: __( 'WebP' ), initialOpen: false },
					el( ToggleControl, {
						label: __( 'Use original image (skip WebP)' ),
						checked: disableWebp,
						onChange: function ( val ) { setAttributes( { disableWebp: val } ); },
						help: __( 'Forces the original JPG/PNG to be served for this image even when WebP mode is on. Use when the WebP version is broken or incorrect.' ),
					} ),
					mediaId ? el( 'div', { style: { marginTop: '12px' } },
						el( Button, {
							variant: 'secondary',
							isSmall: true,
							onClick: handleRegenWebp,
							disabled: webp.busy,
						}, webp.busy
							? el( Fragment, null, el( Spinner, null ), __( ' Working...' ) )
							: __( 'Regenerate WebP' )
						),
						el( 'p', { style: { fontSize: '12px', color: '#6b7280', margin: '6px 0 12px' } },
							__( 'Deletes existing .webp companions for this image and tries again. Good when the server-side conversion produced an empty or broken file.' )
						),
						el( 'label', {
							style: {
								display: 'inline-block',
								padding: '2px 10px',
								border: '1px solid #757575',
								borderRadius: '2px',
								background: '#fff',
								fontSize: '13px',
								cursor: webp.busy ? 'not-allowed' : 'pointer',
								opacity: webp.busy ? 0.6 : 1,
							},
						},
							__( 'Upload replacement .webp' ),
							el( 'input', {
								ref: webpFileRef,
								type: 'file',
								accept: 'image/webp,.webp',
								onChange: handleUploadWebp,
								disabled: webp.busy,
								style: { display: 'none' },
							} )
						),
						el( 'p', { style: { fontSize: '12px', color: '#6b7280', margin: '6px 0 0' } },
							__( 'Use this when server conversion just cannot encode the source — convert it offline (Squoosh, cwebp) and drop the .webp here.' )
						)
					) : null,
					webp.notice ? el( 'div', { style: { marginTop: '12px' } },
						el( Notice, {
							status: webp.notice.status,
							isDismissible: true,
							onRemove: function () { setWebp( { busy: false, notice: null } ); },
						}, webp.notice.message )
					) : null
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
