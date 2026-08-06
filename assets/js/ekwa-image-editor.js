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
	var InlineStyle        = window.EkwaInlineStyle || null;
	var LinkSourceControls = window.EkwaLinkSource && window.EkwaLinkSource.Controls;
	var useSelect          = wp.data.useSelect;

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
			var linkNewTab  = !! attributes.linkNewTab;
			var disableWebp = !! attributes.disableWebp;
			var lightbox        = !! attributes.lightbox;
			// lightboxSrc is legacy: it used to be a second URL field in the
			// Lightbox panel. The renderer still honours it for already-saved
			// content, but there is no UI for it — Link Settings is the one
			// place a URL is entered.
			var lightboxGroup   = attributes.lightboxGroup   || '';
			var lightboxCaption = attributes.lightboxCaption || '';

			// An <img> wrapped in a lightbox anchor that itself sits inside an
			// ekwa/div rendered as <a> produces nested links — invalid HTML the
			// browser silently un-nests, breaking both. Warn instead of guessing
			// which one the author meant.
			var insideAnchor = useSelect( function ( select ) {
				var editor = select( 'core/block-editor' );
				if ( ! editor || ! editor.getBlockParents ) { return false; }
				return editor.getBlockParents( props.clientId ).some( function ( id ) {
					var parent = editor.getBlock( id );
					return parent
						&& parent.name === 'ekwa/div'
						&& parent.attributes
						&& parent.attributes.tagName === 'a';
				} );
			}, [ props.clientId ] );

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

			// Blocks saved before the Link Source control existed hold their URL
			// in `linkUrl`. Show that value in the new control instead of an
			// empty field, and drop the legacy attribute the moment the author
			// edits — otherwise clearing the field would fall back to the stale
			// one on the front end.
			var linkAttrs = attributes;
			if ( ! attributes.url && attributes.linkUrl && ( ! attributes.linkType || attributes.linkType === 'external' ) ) {
				linkAttrs = Object.assign( {}, attributes, { url: attributes.linkUrl } );
			}

			// Is a link actually configured? Depends on which source is active —
			// appointment resolves from settings, so it always counts.
			var hasLink = ( function () {
				switch ( linkAttrs.linkType || 'external' ) {
					case 'internal':    return !! linkAttrs.pageId;
					case 'media':       return !! linkAttrs.mediaUrl;
					case 'appointment': return true;
					default:            return !! linkAttrs.url;
				}
			} )();

			function setLinkAttrs( next ) {
				var patch = Object.assign( {}, next );
				if ( attributes.linkUrl ) {
					patch.linkUrl = '';
				}
				// Keep the "external links open in a new tab" convenience the
				// plain URL field used to provide.
				if ( Object.prototype.hasOwnProperty.call( next, 'url' ) ) {
					patch.linkNewTab = isExternalUrl( next.url );
				}
				setAttributes( patch );
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
					} ),
					InlineStyle ? el( InlineStyle.Control, {
						attributes:    attributes,
						setAttributes: setAttributes,
						help:          __( 'Extra raw CSS on the <img>, e.g. border-radius: 8px.' ),
					} ) : null
				),
				el( PanelBody, { title: __( 'Lightbox' ), initialOpen: lightbox },
					el( ToggleControl, {
						label: __( 'Open in lightbox' ),
						checked: lightbox,
						onChange: function ( val ) { setAttributes( { lightbox: val } ); },
						help: __( 'Open in an overlay instead of navigating. The lightbox code is only downloaded once the visitor interacts with the page, so pages that use it cost nothing to load.' ),
					} ),
					lightbox && insideAnchor ? el( Notice, {
						status: 'warning',
						isDismissible: false,
					}, __( 'This image sits inside an Ekwa Div rendered as a link, so it would produce a link inside a link. Turn the lightbox on for the parent Div instead, or change the parent’s tag.' ) ) : null,
					lightbox ? el( Fragment, null,
						// What opens is whatever Link Settings points at — there is
						// deliberately no second URL field here.
						el( 'p', { style: { margin: '0 0 16px', fontSize: '12px', color: '#757575' } },
							hasLink
								? __( 'Opens the link set under Link Settings below.' )
								: __( 'Opens this image at full size. To open something else — a larger original, a PDF or a video — set it under Link Settings below.' )
						),
						el( TextControl, {
							label: __( 'Gallery group' ),
							value: lightboxGroup,
							onChange: function ( val ) { setAttributes( { lightboxGroup: val } ); },
							help: __( 'Images sharing a group name open as one gallery with next/previous arrows and swipe. Leave empty to open this image on its own. Videos set to open in the lightbox can join a group too.' ),
						} ),
						el( TextControl, {
							label: __( 'Caption' ),
							value: lightboxCaption,
							onChange: function ( val ) { setAttributes( { lightboxCaption: val } ); },
							help: __( 'Optional text shown under the image in the overlay.' ),
						} )
					) : null
				),
				el( PanelBody, { title: __( 'Link Settings' ), initialOpen: false },
					// The link controls always live here, whatever the lightbox is
					// doing — one home for "where does this image point". The
					// precedence note stays in this panel too, and only appears
					// when both are actually set, so it describes a real conflict
					// instead of pointing at another panel.
					LinkSourceControls ? el( LinkSourceControls, {
						attributes:    linkAttrs,
						setAttributes: setLinkAttrs,
					} ) : el( TextControl, {
						// Fallback for the (unexpected) case where the shared
						// control script did not load — better a plain field
						// than no link UI at all.
						label: __( 'Link URL' ),
						value: linkAttrs.url || '',
						onChange: function ( val ) { setLinkAttrs( { url: val } ); },
						help: __( 'External links automatically open in a new tab.' ),
					} ),
					// Irrelevant while the lightbox is on: the link opens in the
					// overlay rather than navigating anywhere.
					hasLink && ! lightbox ? el( ToggleControl, {
						label: __( 'Open in new tab' ),
						checked: linkNewTab,
						onChange: function ( val ) { setAttributes( { linkNewTab: val } ); },
					} ) : null,
					lightbox ? el( 'p', { style: { margin: '8px 0 0', fontSize: '12px', color: '#757575' } },
						__( 'The lightbox is on, so this opens in an overlay instead of navigating.' )
					) : null
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
			// inlineStyle last so it wins on conflict, matching the PHP renderer.
			if ( InlineStyle ) {
				imgStyle = Object.assign( imgStyle, InlineStyle.parse( attributes.inlineStyle ) );
			}

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
