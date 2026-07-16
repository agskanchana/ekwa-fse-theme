/**
 * Ekwa Vimeo Video — Block Editor UI.
 *
 * URL in sidebar → REST metadata fetch (title/thumbnail/duration/upload date)
 * → thumbnail preview. Also registers a paste-transform: a bare Vimeo URL
 * pasted on its own line becomes this block instead of core/embed (priority
 * below core/embed's default so ours wins), plus a block-switcher transform
 * to/from core/embed. Vimeo has no public transcript API, so — unlike the
 * YouTube block — transcript text here is paste-your-own only.
 *
 * Plain wp.element (no JSX / build step), matching the theme's other editor JS.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var createBlock       = wp.blocks.createBlock;
	var el                 = wp.element.createElement;
	var Fragment           = wp.element.Fragment;
	var useState           = wp.element.useState;
	var useEffect          = wp.element.useEffect;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var MediaUpload         = wp.blockEditor.MediaUpload;
	var MediaUploadCheck    = wp.blockEditor.MediaUploadCheck;
	var useBlockProps       = wp.blockEditor.useBlockProps;
	var PanelBody           = wp.components.PanelBody;
	var TextControl         = wp.components.TextControl;
	var TextareaControl     = wp.components.TextareaControl;
	var ToggleControl       = wp.components.ToggleControl;
	var Button              = wp.components.Button;
	var Spinner             = wp.components.Spinner;
	var Notice              = wp.components.Notice;
	var apiFetch            = wp.apiFetch;
	var __                  = wp.i18n.__;

	var VIMEO_URL_RE = /(?:www\.)?vimeo\.com\/(?:channels\/(?:\w+\/)?|groups\/[^\/]*\/videos\/|album\/\d+\/video\/|video\/|)(\d+)(?:$|\/|\?)/;
	var VIMEO_URL_RE_FALLBACK = /vimeo\.com\/(\d+)/;

	function extractBasicInfo( url ) {
		var m = url.match( VIMEO_URL_RE ) || url.match( VIMEO_URL_RE_FALLBACK );
		if ( ! m ) { return { videoId: '', embedUrl: '' }; }
		return { videoId: m[ 1 ], embedUrl: 'https://player.vimeo.com/video/' + m[ 1 ] };
	}

	function matchesVimeoUrl( url ) {
		return VIMEO_URL_RE.test( url || '' ) || VIMEO_URL_RE_FALLBACK.test( url || '' );
	}

	registerBlockType( 'ekwa/vimeo-video', {
		edit: function ( props ) {
			var a   = props.attributes;
			var set = props.setAttributes;

			var isLoadingState = useState( false );
			var isLoading       = isLoadingState[ 0 ];
			var setIsLoading    = isLoadingState[ 1 ];
			var errorState      = useState( '' );
			var error            = errorState[ 0 ];
			var setError         = errorState[ 1 ];

			function fetchMetadata( url ) {
				setIsLoading( true );
				apiFetch( { path: '/ekwa/v1/video-metadata?url=' + encodeURIComponent( url ) + '&provider=vimeo' } )
					.then( function ( res ) {
						set( {
							videoId: res.videoId,
							embedUrl: res.embedUrl,
							videoTitle: res.videoTitle || '',
							videoDescription: res.videoDescription || '',
							videoDuration: res.videoDuration || '',
							uploadDate: res.uploadDate || '',
							thumbnailUrl: res.thumbnailUrl || '',
						} );
					} )
					.catch( function ( err ) {
						setError( ( err && err.message ) || __( 'Could not fetch video metadata. You can still publish — the video will still play.', 'ekwa' ) );
					} )
					.finally( function () { setIsLoading( false ); } );
			}

			function onVideoUrlChange( url ) {
				set( { videoUrl: url } );
				if ( ! url ) {
					set( { videoId: '', embedUrl: '', videoTitle: '', videoDescription: '', videoDuration: '', uploadDate: '', thumbnailUrl: '' } );
					setError( '' );
					return;
				}
				var basic = extractBasicInfo( url );
				if ( ! basic.videoId ) {
					setError( __( "That doesn't look like a Vimeo URL.", 'ekwa' ) );
					return;
				}
				setError( '' );
				set( { videoId: basic.videoId, embedUrl: basic.embedUrl } );
				fetchMetadata( url );
			}

			// A block created via the paste-transform only has videoUrl set —
			// resolve the rest once, on mount.
			useEffect( function () {
				if ( a.videoUrl && ! a.videoId ) {
					var basic = extractBasicInfo( a.videoUrl );
					if ( basic.videoId ) {
						set( { videoId: basic.videoId, embedUrl: basic.embedUrl } );
						fetchMetadata( a.videoUrl );
					}
				}
				// eslint-disable-next-line
			}, [] );

			var blockProps = useBlockProps( { className: 'ekwa-video-embed-editor' } );

			var inspector = el( InspectorControls, null,
				el( PanelBody, { title: __( 'Vimeo Video', 'ekwa' ), initialOpen: true },
					el( TextControl, {
						label: __( 'Vimeo URL', 'ekwa' ),
						value: a.videoUrl,
						onChange: onVideoUrlChange,
						placeholder: __( 'https://vimeo.com/…', 'ekwa' ),
					} ),
					isLoading ? el( Spinner ) : null,
					error ? el( Notice, { status: 'error', isDismissible: false }, error ) : null,
					a.videoUrl && a.videoId ? [
						el( ToggleControl, { key: 'title', label: __( 'Show title', 'ekwa' ), checked: a.showTitle, onChange: function ( v ) { set( { showTitle: v } ); } } ),
						el( ToggleControl, { key: 'desc', label: __( 'Show description', 'ekwa' ), checked: a.showDescription, onChange: function ( v ) { set( { showDescription: v } ); } } ),
						el( ToggleControl, {
							key: 'lightbox', label: __( 'Open in lightbox', 'ekwa' ), checked: a.openInLightbox,
							onChange: function ( v ) { set( { openInLightbox: v } ); },
							help: __( "Opens in the site's lightbox popup instead of playing inline.", 'ekwa' ),
						} ),
						el( ToggleControl, {
							key: 'lazy', label: __( 'Disable lazy loading', 'ekwa' ), checked: a.disableLazyLoad,
							onChange: function ( v ) { set( { disableLazyLoad: v } ); },
							help: __( 'Turn on for an above-the-fold video so the thumbnail loads immediately.', 'ekwa' ),
						} ),
						el( ToggleControl, {
							key: 'fp', label: __( 'High fetch priority', 'ekwa' ), checked: a.fetchPriorityHigh,
							onChange: function ( v ) { set( { fetchPriorityHigh: v } ); },
							help: __( 'Hints the browser to prioritize the thumbnail as the LCP image.', 'ekwa' ),
						} ),
					] : null
				),
				a.videoUrl && a.videoId ? el( PanelBody, { title: __( 'Custom Thumbnail', 'ekwa' ), initialOpen: false },
					el( MediaUploadCheck, null,
						el( MediaUpload, {
							onSelect: function ( media ) {
								set( { customThumbnail: { id: media.id, url: media.url, alt: media.alt || '', width: media.width, height: media.height } } );
							},
							allowedTypes: [ 'image' ],
							value: a.customThumbnail && a.customThumbnail.id,
							render: function ( obj ) {
								return el( Button, { onClick: obj.open, variant: 'secondary' },
									a.customThumbnail && a.customThumbnail.url ? __( 'Replace thumbnail', 'ekwa' ) : __( 'Upload custom thumbnail', 'ekwa' ) );
							},
						} )
					),
					a.customThumbnail && a.customThumbnail.url
						? el( Button, { onClick: function () { set( { customThumbnail: {} } ); }, variant: 'link', isDestructive: true, style: { marginTop: '8px' } }, __( 'Remove custom thumbnail', 'ekwa' ) )
						: null
				) : null,
				a.videoUrl && a.videoId ? el( PanelBody, { title: __( 'Transcript', 'ekwa' ), initialOpen: false },
					el( ToggleControl, { label: __( 'Show transcript button', 'ekwa' ), checked: a.showTranscript, onChange: function ( v ) { set( { showTranscript: v } ); } } ),
					el( 'p', { style: { marginTop: 0, color: '#757575', fontSize: '12px' } }, __( 'Vimeo has no public captions API — paste the transcript text yourself.', 'ekwa' ) ),
					el( TextareaControl, {
						label: __( 'Transcript text', 'ekwa' ),
						help: __( 'Blank line between paragraphs.', 'ekwa' ),
						value: a.transcript || '',
						onChange: function ( v ) { set( { transcript: v } ); },
						rows: 8,
					} )
				) : null
			);

			var preview;
			if ( ! a.videoUrl ) {
				preview = el( 'div', { style: { padding: '32px 16px', textAlign: 'center', border: '1px dashed #ccc', borderRadius: '4px', color: '#757575' } },
					el( 'p', { style: { margin: 0, fontWeight: 600 } }, __( 'Ekwa Vimeo Video', 'ekwa' ) ),
					el( 'p', { style: { margin: '4px 0 0' } }, __( 'Paste a Vimeo URL in the sidebar — or paste one directly into the editor to create this block automatically.', 'ekwa' ) )
				);
			} else if ( ! a.videoId ) {
				preview = el( 'div', { style: { padding: '24px 16px', textAlign: 'center', color: '#cc1818' } }, __( 'Invalid Vimeo URL.', 'ekwa' ) );
			} else {
				var thumb = ( a.customThumbnail && a.customThumbnail.url ) || a.thumbnailUrl;
				preview = el( 'div', null,
					a.showTitle && a.videoTitle ? el( 'h3', { style: { margin: '0 0 8px' } }, a.videoTitle ) : null,
					el( 'div', { style: { position: 'relative', aspectRatio: '16/9', background: '#000', overflow: 'hidden' } },
						thumb ? el( 'img', { src: thumb, alt: '', style: { width: '100%', height: '100%', objectFit: 'cover', display: 'block' } } ) : null,
						el( 'div', { style: { position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center' } },
							el( 'div', { style: { width: 0, height: 0, borderTop: '14px solid transparent', borderBottom: '14px solid transparent', borderLeft: '22px solid #fff', filter: 'drop-shadow(0 0 4px rgba(0,0,0,.6))' } } )
						)
					),
					isLoading ? el( Spinner ) : null
				);
			}

			return el( Fragment, null, inspector, el( 'div', blockProps, preview ) );
		},

		save: function () {
			return null;
		},

		transforms: {
			from: [
				{
					type: 'raw',
					priority: 5,
					isMatch: function ( node ) {
						return 'P' === node.nodeName
							&& /^\s*(https?:\/\/\S+)\s*$/i.test( node.textContent || '' )
							&& matchesVimeoUrl( node.textContent || '' );
					},
					transform: function ( node ) {
						return createBlock( 'ekwa/vimeo-video', { videoUrl: node.textContent.trim() } );
					},
				},
				{
					type: 'block',
					blocks: [ 'core/embed' ],
					isMatch: function ( attributes ) {
						return matchesVimeoUrl( attributes.url );
					},
					transform: function ( attributes ) {
						return createBlock( 'ekwa/vimeo-video', { videoUrl: attributes.url } );
					},
				},
			],
			to: [
				{
					type: 'block',
					blocks: [ 'core/embed' ],
					transform: function ( attributes ) {
						return createBlock( 'core/embed', { url: attributes.videoUrl } );
					},
				},
			],
		},
	} );
} )( window.wp );
