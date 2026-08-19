/**
 * Ekwa YouTube Video — Block Editor UI.
 *
 * URL in sidebar → REST metadata fetch (title/thumbnail/duration/upload date)
 * → thumbnail preview. Also registers a paste-transform: a bare YouTube URL
 * pasted on its own line becomes this block instead of core/embed (priority
 * below core/embed's default so ours wins), plus a block-switcher transform
 * to/from core/embed.
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

	var YT_URL_RE = /(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/;

	function extractBasicInfo( url ) {
		var m = url.match( YT_URL_RE );
		if ( ! m ) { return { videoId: '', embedUrl: '' }; }
		return { videoId: m[ 1 ], embedUrl: 'https://www.youtube-nocookie.com/embed/' + m[ 1 ] + '?rel=0' };
	}

	// ISO 8601 duration ("PT1H4M30S") <-> human input ("1:04:30"), for the
	// manual-entry field — schema markup needs ISO 8601, humans don't write it.
	function isoDurationToHuman( iso ) {
		var m = iso && iso.match( /^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/ );
		if ( ! m ) { return ''; }
		var h = parseInt( m[ 1 ] || '0', 10 );
		var mi = parseInt( m[ 2 ] || '0', 10 );
		var s = parseInt( m[ 3 ] || '0', 10 );
		if ( ! h && ! mi && ! s ) { return ''; }
		var pad = function ( n ) { return ( n < 10 ? '0' : '' ) + n; };
		return h > 0 ? ( h + ':' + pad( mi ) + ':' + pad( s ) ) : ( mi + ':' + pad( s ) );
	}

	// Returns an ISO 8601 duration string, '' for a cleared field, or null if
	// the text isn't a recognizable minutes:seconds / h:m:s value.
	function humanDurationToIso( human ) {
		var trimmed = ( human || '' ).trim();
		if ( ! trimmed ) { return ''; }
		var parts = trimmed.split( ':' );
		if ( parts.length < 1 || parts.length > 3 || parts.some( function ( p ) { return '' === p.trim() || isNaN( p.trim() ); } ) ) {
			return null;
		}
		var nums = parts.map( function ( p ) { return parseInt( p.trim(), 10 ); } );
		var h = 3 === nums.length ? nums[ 0 ] : 0;
		var mi = 3 === nums.length ? nums[ 1 ] : ( 2 === nums.length ? nums[ 0 ] : 0 );
		var s = 1 === nums.length ? nums[ 0 ] : nums[ nums.length - 1 ];
		var out = 'PT';
		if ( h > 0 ) { out += h + 'H'; }
		if ( mi > 0 ) { out += mi + 'M'; }
		if ( s > 0 || ( 0 === h && 0 === mi ) ) { out += s + 'S'; }
		return out;
	}

	function isoDateToInputValue( iso ) {
		var m = iso && String( iso ).match( /^(\d{4}-\d{2}-\d{2})/ );
		return m ? m[ 1 ] : '';
	}

	function inputValueToIsoDate( value ) {
		return value ? value + 'T00:00:00Z' : '';
	}

	registerBlockType( 'ekwa/youtube-video', {
		edit: function ( props ) {
			var a   = props.attributes;
			var set = props.setAttributes;

			var isLoadingState          = useState( false );
			var isLoading                = isLoadingState[ 0 ];
			var setIsLoading             = isLoadingState[ 1 ];
			var errorState               = useState( '' );
			var error                    = errorState[ 0 ];
			var setError                 = errorState[ 1 ];
			var fetchingTranscriptState  = useState( false );
			var fetchingTranscript       = fetchingTranscriptState[ 0 ];
			var setFetchingTranscript    = fetchingTranscriptState[ 1 ];
			var transcriptStatusState    = useState( null );
			var transcriptStatus         = transcriptStatusState[ 0 ];
			var setTranscriptStatus      = transcriptStatusState[ 1 ];
			var durationInputState       = useState( isoDurationToHuman( a.videoDuration ) );
			var durationInput             = durationInputState[ 0 ];
			var setDurationInput          = durationInputState[ 1 ];
			var durationErrorState       = useState( '' );
			var durationError             = durationErrorState[ 0 ];
			var setDurationError          = durationErrorState[ 1 ];

			// Keep the human-readable duration field in sync when the ISO value
			// changes from elsewhere (metadata fetch, URL cleared, etc.).
			useEffect( function () {
				setDurationInput( isoDurationToHuman( a.videoDuration ) );
				setDurationError( '' );
				// eslint-disable-next-line
			}, [ a.videoDuration ] );

			function commitDuration() {
				var iso = humanDurationToIso( durationInput );
				if ( null === iso ) {
					setDurationError( __( 'Enter as minutes:seconds, e.g. 4:30 (or hours:minutes:seconds).', 'ekwa' ) );
					return;
				}
				setDurationError( '' );
				set( { videoDuration: iso } );
			}

			function fetchMetadata( url ) {
				setIsLoading( true );
				apiFetch( { path: '/ekwa/v1/video-metadata?url=' + encodeURIComponent( url ) + '&provider=youtube' } )
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
					setError( __( "That doesn't look like a YouTube URL.", 'ekwa' ) );
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

			function fetchTranscript( forceRefresh ) {
				if ( fetchingTranscript || ! a.videoId ) { return; }
				setFetchingTranscript( true );
				setTranscriptStatus( {
					type: 'info',
					message: forceRefresh ? __( 'Retrying (skipping cache)…', 'ekwa' ) : __( 'Fetching transcript…', 'ekwa' ),
				} );
				apiFetch( {
					path: '/ekwa/v1/video-transcript',
					method: 'POST',
					data: { video_id: a.videoId, provider: 'youtube', force_refresh: !! forceRefresh },
				} )
					.then( function ( res ) {
						set( { transcript: res.transcript, showTranscript: true } );
						setTranscriptStatus( {
							type: 'auto' === res.source ? 'warning' : 'success',
							message: 'auto' === res.source
								? __( 'Transcript fetched (auto-generated — please review).', 'ekwa' )
								: __( 'Transcript fetched.', 'ekwa' ),
						} );
					} )
					.catch( function ( err ) {
						setTranscriptStatus( { type: 'error', message: ( err && err.message ) || __( 'Could not fetch transcript.', 'ekwa' ) } );
					} )
					.finally( function () { setFetchingTranscript( false ); } );
			}

			var blockProps = useBlockProps( { className: 'ekwa-video-embed-editor' } );

			// Soft nudge — the API didn't return these, but they matter for the
			// schema markup, so ask rather than silently publishing without them.
			var hasVideo         = a.videoUrl && a.videoId && ! isLoading;
			var missingDuration   = hasVideo && ! a.videoDuration;
			var missingUploadDate = hasVideo && ! a.uploadDate;
			var missingFieldsNotice = null;
			if ( ! error && ( missingDuration || missingUploadDate ) ) {
				var missingLabel = missingDuration && missingUploadDate
					? __( "duration and upload date couldn't", 'ekwa' )
					: missingDuration
						? __( "duration couldn't", 'ekwa' )
						: __( "upload date couldn't", 'ekwa' );
				missingFieldsNotice = __( "This video's ", 'ekwa' ) + missingLabel + __( ' be detected automatically. You can enter it in the Video Information panel below.', 'ekwa' );
			}

			var inspector = el( InspectorControls, null,
				el( PanelBody, { title: __( 'YouTube Video', 'ekwa' ), initialOpen: true },
					el( TextControl, {
						label: __( 'YouTube URL', 'ekwa' ),
						value: a.videoUrl,
						onChange: onVideoUrlChange,
						placeholder: __( 'https://www.youtube.com/watch?v=…', 'ekwa' ),
					} ),
					isLoading ? el( Spinner ) : null,
					error ? el( Notice, { status: 'error', isDismissible: false }, error ) : null,
					missingFieldsNotice ? el( Notice, { status: 'warning', isDismissible: false }, missingFieldsNotice ) : null,
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
				a.videoUrl && a.videoId ? el( PanelBody, { title: __( 'Video Information', 'ekwa' ), initialOpen: true },
					el( 'p', { style: { marginTop: 0, color: '#757575', fontSize: '12px' } },
						__( 'Auto-filled when available. Edit any field to override it, or fill in anything that could not be detected — used for the on-page display and the schema.org markup.', 'ekwa' ) ),
					el( TextControl, {
						label: __( 'Video title', 'ekwa' ),
						value: a.videoTitle || '',
						onChange: function ( v ) { set( { videoTitle: v } ); },
					} ),
					el( TextareaControl, {
						label: __( 'Video description', 'ekwa' ),
						value: a.videoDescription || '',
						onChange: function ( v ) { set( { videoDescription: v } ); },
						rows: 4,
						help: __( 'If left blank, the title is used as the description in the schema markup.', 'ekwa' ),
					} ),
					el( TextControl, {
						label: __( 'Duration', 'ekwa' ),
						value: durationInput,
						onChange: setDurationInput,
						onBlur: commitDuration,
						placeholder: __( 'e.g. 4:30 or 1:04:30', 'ekwa' ),
						help: durationError || __( 'Minutes:seconds, or hours:minutes:seconds.', 'ekwa' ),
					} ),
					el( TextControl, {
						type: 'date',
						label: __( 'Upload date', 'ekwa' ),
						value: isoDateToInputValue( a.uploadDate ),
						onChange: function ( v ) { set( { uploadDate: inputValueToIsoDate( v ) } ); },
					} )
				) : null,
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
					el( 'div', { style: { margin: '8px 0' } },
						el( Button, {
							variant: 'secondary',
							disabled: fetchingTranscript,
							onClick: function () { fetchTranscript( false ); },
						}, fetchingTranscript ? __( 'Fetching…', 'ekwa' ) : __( 'Fetch transcript from YouTube', 'ekwa' ) ),
						transcriptStatus && 'error' === transcriptStatus.type
							? el( Button, { variant: 'secondary', disabled: fetchingTranscript, style: { marginLeft: '8px' }, onClick: function () { fetchTranscript( true ); } }, __( 'Retry (skip cache)', 'ekwa' ) )
							: null
					),
					transcriptStatus ? el( Notice, {
						status: 'warning' === transcriptStatus.type ? 'warning' : 'error' === transcriptStatus.type ? 'error' : 'success' === transcriptStatus.type ? 'success' : 'info',
						isDismissible: true,
						onRemove: function () { setTranscriptStatus( null ); },
					}, transcriptStatus.message ) : null,
					el( TextareaControl, {
						label: __( 'Transcript text', 'ekwa' ),
						help: __( 'Blank line between paragraphs. Fetched automatically above, or paste your own.', 'ekwa' ),
						value: a.transcript || '',
						onChange: function ( v ) { set( { transcript: v } ); },
						rows: 8,
					} )
				) : null
			);

			var preview;
			if ( ! a.videoUrl ) {
				preview = el( 'div', { style: { padding: '32px 16px', textAlign: 'center', border: '1px dashed #ccc', borderRadius: '4px', color: '#757575' } },
					el( 'p', { style: { margin: 0, fontWeight: 600 } }, __( 'Ekwa YouTube Video', 'ekwa' ) ),
					el( 'p', { style: { margin: '4px 0 0' } }, __( 'Paste a YouTube URL in the sidebar — or paste one directly into the editor to create this block automatically.', 'ekwa' ) )
				);
			} else if ( ! a.videoId ) {
				preview = el( 'div', { style: { padding: '24px 16px', textAlign: 'center', color: '#cc1818' } }, __( 'Invalid YouTube URL.', 'ekwa' ) );
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
							&& YT_URL_RE.test( node.textContent || '' );
					},
					transform: function ( node ) {
						return createBlock( 'ekwa/youtube-video', { videoUrl: node.textContent.trim() } );
					},
				},
				{
					type: 'block',
					blocks: [ 'core/embed' ],
					isMatch: function ( attributes ) {
						return YT_URL_RE.test( attributes.url || '' );
					},
					transform: function ( attributes ) {
						return createBlock( 'ekwa/youtube-video', { videoUrl: attributes.url } );
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
