/**
 * Ekwa AI — "Images and videos to use" picker.
 *
 * Shared by Build with AI (Blocks) and Generate with AI. The author picks
 * images from the Media Library and pastes YouTube / Vimeo links; the modal
 * sends them with the prompt, and the server writes them into it so the AI
 * places the real files instead of grey placeholders (inc/ekwa-ai-media.php).
 *
 * Not the same thing as the "reference screenshots" beside it: those are
 * pictures the AI LOOKS AT for layout; these are content it PUTS ON THE PAGE.
 *
 * Exposes window.ekwaAiMedia, the way ekwa-rich-paste.js exposes
 * window.ekwaRichPaste. Each modal checks for it and simply goes without the
 * picker when it is missing.
 *
 * Value shape (the modal keeps it in state): { images: [], videos: [] }
 *   image: { key, id, thumb, alt, name }
 *   video: { key, provider, videoId, url, thumb, title, loading }
 * Numbers ("Image 1") are not stored: they are the position in the list,
 * continued from the conversation so far, so what the author sees beside a
 * thumbnail is what the prompt calls it.
 *
 * @package ekwa
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.element || ! wp.components || ! wp.i18n ) {
		return;
	}

	var el          = wp.element.createElement;
	var useState    = wp.element.useState;
	var useRef      = wp.element.useRef;
	var Button      = wp.components.Button;
	var TextControl = wp.components.TextControl;
	var apiFetch    = wp.apiFetch;
	var __          = wp.i18n.__;
	var sprintf     = wp.i18n.sprintf;

	// Same caps as the server (inc/ekwa-ai-media.php).
	var MAX_IMAGES = 20;
	var MAX_VIDEOS = 10;

	var EMPTY = { images: [], videos: [] };

	// ─── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Recognise a YouTube or Vimeo link. The server checks it again with the
	 * video blocks' own parser; this only catches a wrong paste early.
	 *
	 * @param {string} raw
	 * @return {?{provider:string,id:string,url:string}}
	 */
	function parseVideoUrl( raw ) {
		var url = String( raw || '' ).trim();
		if ( ! url ) { return null; }
		if ( ! /^https?:\/\//i.test( url ) ) { url = 'https://' + url.replace( /^\/+/, '' ); }

		var host;
		try { host = new URL( url ).hostname.toLowerCase(); } catch ( e ) { return null; }

		var m;
		if ( /(^|\.)(youtube\.com|youtube-nocookie\.com|youtu\.be)$/.test( host ) ) {
			m = url.match( /(?:youtube(?:-nocookie)?\.com\/(?:watch\?(?:.*&)?v=|embed\/|v\/|shorts\/|live\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/ );
			return m ? { provider: 'youtube', id: m[1], url: 'https://www.youtube.com/watch?v=' + m[1] } : null;
		}
		if ( /(^|\.)vimeo\.com$/.test( host ) ) {
			m = url.match( /vimeo\.com\/(?:.*\/)?(\d{5,})/ );
			return m ? { provider: 'vimeo', id: m[1], url: url } : null;
		}
		return null;
	}

	function youtubeThumb( id ) {
		return 'https://i.ytimg.com/vi/' + encodeURIComponent( id ) + '/hqdefault.jpg';
	}

	/**
	 * Where this turn's numbering starts: after the highest number used by any
	 * earlier turn, so "Image 4" in a refine never collides with the "Image 1"
	 * the first turn placed.
	 *
	 * @param {Array} history Conversation turns ({ role, text, media? }).
	 * @return {{image:number,video:number}}
	 */
	function startNumbers( history ) {
		var start = { image: 0, video: 0 };
		( history || [] ).forEach( function ( turn ) {
			if ( ! turn || 'user' !== turn.role || ! Array.isArray( turn.media ) ) { return; }
			turn.media.forEach( function ( item ) {
				if ( ! item || ! start.hasOwnProperty( item.type ) ) { return; }
				start[ item.type ] = Math.max( start[ item.type ], parseInt( item.n, 10 ) || 0 );
			} );
		} );
		return start;
	}

	/**
	 * The picker's value as the request's `media` param. Also what a history
	 * turn keeps, so later turns can repeat the list to the model.
	 *
	 * @param {Object} value { images, videos }
	 * @param {Object} start From startNumbers().
	 * @return {Array}
	 */
	function toPayload( value, start ) {
		value = value || EMPTY;
		start = start || { image: 0, video: 0 };
		var out = [];
		( value.images || [] ).forEach( function ( img, i ) {
			out.push( { type: 'image', id: img.id, n: start.image + i + 1 } );
		} );
		( value.videos || [] ).forEach( function ( vid, i ) {
			var entry = { type: 'video', url: vid.url, n: start.video + i + 1 };
			if ( vid.title ) { entry.title = vid.title; }
			if ( vid.thumb ) { entry.thumb = vid.thumb; }
			out.push( entry );
		} );
		return out;
	}

	function isEmpty( value ) {
		return ! value || ( ! ( value.images || [] ).length && ! ( value.videos || [] ).length );
	}

	/**
	 * "Image 1, Image 2, Video 1" — how a sent turn names what it carried, for
	 * the conversation thread.
	 *
	 * @param {Array} media A turn's payload list.
	 * @return {string}
	 */
	function describe( media ) {
		return ( media || [] ).map( function ( item ) {
			return ( 'video' === item.type ? __( 'Video', 'ekwa' ) : __( 'Image', 'ekwa' ) ) + ' ' + item.n;
		} ).join( ', ' );
	}

	function imageFromAttachment( att ) {
		var sizes = att.sizes || {};
		var small = sizes.thumbnail || sizes.medium || sizes.full || {};
		return {
			key:   'i' + att.id,
			id:    att.id,
			thumb: small.url || att.url || '',
			alt:   att.alt || '',
			name:  att.filename || att.title || '',
		};
	}

	// ─── Picker ─────────────────────────────────────────────────────────────

	function Thumb( props ) {
		return el( 'li', {
			className: 'ekwa-ai-media__item' + ( props.video ? ' ekwa-ai-media__item--video' : '' ),
			title: props.title || props.label,
		},
			props.src
				? el( 'img', { src: props.src, alt: '' } )
				: el( 'span', { className: 'ekwa-ai-media__noimg' } ),
			props.video ? el( 'span', { className: 'ekwa-ai-media__play', 'aria-hidden': 'true' } ) : null,
			el( 'span', { className: 'ekwa-ai-media__badge' }, props.label ),
			el( 'button', {
				type: 'button',
				className: 'ekwa-ai-media__remove',
				/* translators: %s: item name, e.g. "Image 2". */
				'aria-label': sprintf( __( 'Remove %s', 'ekwa' ), props.label ),
				title: sprintf( __( 'Remove %s', 'ekwa' ), props.label ),
				onClick: props.onRemove,
				disabled: props.disabled,
			}, '×' )
		);
	}

	/**
	 * @param {Object}   props
	 * @param {Object}   props.value    { images, videos }.
	 * @param {Function} props.onChange A state setter — called with an updater
	 *                                  function, since a video's title arrives
	 *                                  after other changes may have been made.
	 * @param {Object}   [props.start]  From startNumbers().
	 * @param {boolean}  [props.compact]
	 * @param {boolean}  [props.disabled]
	 */
	function MediaPicker( props ) {
		var value  = props.value || EMPTY;
		var start  = props.start || { image: 0, video: 0 };
		var images = value.images || [];
		var videos = value.videos || [];

		var s1 = useState( '' );   var link  = s1[0]; var setLink  = s1[1];
		var s2 = useState( null ); var error = s2[0]; var setError = s2[1];

		// One frame per picker, reused; the images ref lets its "open" handler
		// see the current list rather than the one it was created with.
		var frameRef  = useRef( null );
		var imagesRef = useRef( images );
		imagesRef.current = images;

		var hasLibrary = !! ( window.wp && window.wp.media );

		function update( fn ) {
			props.onChange( function ( prev ) {
				return fn( prev || EMPTY );
			} );
		}

		function openLibrary() {
			if ( ! hasLibrary ) { return; }
			setError( null );

			if ( ! frameRef.current ) {
				var frame = wp.media( {
					title:    __( 'Choose images for the AI to place', 'ekwa' ),
					button:   { text: __( 'Use these images', 'ekwa' ) },
					library:  { type: 'image' },
					multiple: 'add',
				} );

				// Open on the current choice, so the frame both adds and removes.
				frame.on( 'open', function () {
					var selection = frame.state().get( 'selection' );
					selection.reset( [] );
					imagesRef.current.forEach( function ( img ) {
						var attachment = wp.media.attachment( img.id );
						attachment.fetch();
						selection.add( attachment );
					} );
				} );

				frame.on( 'select', function () {
					var picked = frame.state().get( 'selection' ).toJSON().filter( function ( att ) {
						return att && att.id && ( ! att.type || 'image' === att.type );
					} );
					if ( picked.length > MAX_IMAGES ) {
						/* translators: %d: maximum number of images. */
						setError( sprintf( __( 'Only the first %d images are used.', 'ekwa' ), MAX_IMAGES ) );
					}
					var next = picked.slice( 0, MAX_IMAGES ).map( imageFromAttachment );
					update( function ( prev ) {
						return { images: next, videos: prev.videos || [] };
					} );
				} );

				frameRef.current = frame;
			}

			frameRef.current.open();
		}

		function patchVideo( key, patch ) {
			update( function ( prev ) {
				return {
					images: prev.images || [],
					videos: ( prev.videos || [] ).map( function ( vid ) {
						if ( vid.key !== key ) { return vid; }
						return {
							key:      vid.key,
							provider: vid.provider,
							videoId:  vid.videoId,
							url:      vid.url,
							// YouTube's thumbnail is known from the id; Vimeo's
							// only arrives with the metadata.
							thumb:    vid.thumb || patch.thumb || '',
							title:    patch.title || vid.title || '',
							loading:  false,
						};
					} ),
				};
			} );
		}

		function addVideo() {
			var info = parseVideoUrl( link );
			if ( ! info ) {
				setError( __( 'That is not a YouTube or Vimeo video link.', 'ekwa' ) );
				return;
			}
			var dup = videos.some( function ( vid ) {
				return vid.provider === info.provider && vid.videoId === info.id;
			} );
			if ( dup ) {
				setError( __( 'That video is already on the list.', 'ekwa' ) );
				return;
			}
			if ( videos.length >= MAX_VIDEOS ) {
				/* translators: %d: maximum number of videos. */
				setError( sprintf( __( 'Up to %d videos per request.', 'ekwa' ), MAX_VIDEOS ) );
				return;
			}

			setError( null );
			setLink( '' );

			var key = 'v-' + info.provider + '-' + info.id;
			update( function ( prev ) {
				return {
					images: prev.images || [],
					videos: ( prev.videos || [] ).concat( [ {
						key:      key,
						provider: info.provider,
						videoId:  info.id,
						url:      info.url,
						thumb:    'youtube' === info.provider ? youtubeThumb( info.id ) : '',
						title:    '',
						loading:  true,
					} ] ),
				};
			} );

			// Title and thumbnail from the video blocks' own lookup — the one the
			// block itself makes, so its cache is warm for the preview too.
			if ( ! apiFetch ) {
				patchVideo( key, {} );
				return;
			}
			apiFetch( {
				path: '/ekwa/v1/video-metadata?url=' + encodeURIComponent( info.url ) + '&provider=' + info.provider,
			} ).then( function ( res ) {
				patchVideo( key, {
					title: ( res && res.videoTitle ) || '',
					thumb: ( res && res.thumbnailUrl ) || '',
				} );
			} ).catch( function () {
				patchVideo( key, {} );
			} );
		}

		function removeImage( key ) {
			update( function ( prev ) {
				return {
					images: ( prev.images || [] ).filter( function ( img ) { return img.key !== key; } ),
					videos: prev.videos || [],
				};
			} );
		}

		function removeVideo( key ) {
			update( function ( prev ) {
				return {
					images: prev.images || [],
					videos: ( prev.videos || [] ).filter( function ( vid ) { return vid.key !== key; } ),
				};
			} );
		}

		var items = images.map( function ( img, i ) {
			/* translators: %d: image number. */
			var label = sprintf( __( 'Image %d', 'ekwa' ), start.image + i + 1 );
			return el( Thumb, {
				key: img.key,
				label: label,
				src: img.thumb,
				title: label + ( img.alt ? ' — ' + img.alt : ( img.name ? ' — ' + img.name : '' ) ),
				onRemove: function () { removeImage( img.key ); },
				disabled: props.disabled,
			} );
		} ).concat( videos.map( function ( vid, i ) {
			/* translators: %d: video number. */
			var label = sprintf( __( 'Video %d', 'ekwa' ), start.video + i + 1 );
			return el( Thumb, {
				key: vid.key,
				label: label,
				src: vid.thumb,
				video: true,
				title: label + ( vid.title ? ' — ' + vid.title : ( vid.loading ? ' — ' + __( 'loading…', 'ekwa' ) : ' — ' + vid.url ) ),
				onRemove: function () { removeVideo( vid.key ); },
				disabled: props.disabled,
			} );
		} ) );

		return el( 'div', { className: 'ekwa-ai-media' + ( props.compact ? ' ekwa-ai-media--compact' : '' ) },
			el( 'div', { className: 'ekwa-ai-media__head' },
				el( 'span', { className: 'ekwa-ai-media__title' },
					props.compact ? __( 'Add images or videos (optional)', 'ekwa' ) : __( 'Images and videos to use', 'ekwa' ) ),
				el( 'span', { className: 'ekwa-ai-media__hint' },
					__( 'Placed in the design as chosen — call them “Image 1”, “Video 1” in the prompt.', 'ekwa' ) )
			),
			el( 'div', { className: 'ekwa-ai-media__controls' },
				el( Button, {
					variant: 'secondary',
					icon: 'format-image',
					onClick: openLibrary,
					disabled: ! hasLibrary || props.disabled,
				}, images.length ? __( 'Change images', 'ekwa' ) : __( 'Choose from Media Library', 'ekwa' ) ),
				el( 'div', { className: 'ekwa-ai-media__link' },
					el( TextControl, {
						label: __( 'YouTube or Vimeo link', 'ekwa' ),
						hideLabelFromVision: true,
						placeholder: __( 'Paste a YouTube or Vimeo link', 'ekwa' ),
						value: link,
						onChange: function ( next ) {
							setLink( next );
							if ( error ) { setError( null ); }
						},
						// Enter adds the link rather than doing nothing.
						onKeyDown: function ( event ) {
							if ( 'Enter' === event.key ) {
								event.preventDefault();
								addVideo();
							}
						},
						disabled: props.disabled,
						__nextHasNoMarginBottom: true,
					} ),
					el( Button, {
						variant: 'secondary',
						onClick: addVideo,
						disabled: ! link.trim() || props.disabled,
					}, __( 'Add video', 'ekwa' ) )
				)
			),
			error ? el( 'p', { className: 'ekwa-ai-media__error', role: 'alert' }, error ) : null,
			hasLibrary ? null : el( 'p', { className: 'ekwa-ai-media__note' },
				__( 'The Media Library is not available on this screen — video links still work.', 'ekwa' ) ),
			items.length ? el( 'ul', { className: 'ekwa-ai-media__strip' }, items ) : null
		);
	}

	window.ekwaAiMedia = {
		EMPTY:        EMPTY,
		MediaPicker:  MediaPicker,
		startNumbers: startNumbers,
		toPayload:    toPayload,
		isEmpty:      isEmpty,
		describe:     describe,
		parseVideoUrl: parseVideoUrl,
	};

} )( window.wp );
