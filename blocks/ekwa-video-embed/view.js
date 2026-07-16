/**
 * Ekwa YouTube/Vimeo Video — frontend.
 *
 * Click-to-play: swaps the thumbnail button for a real iframe, sized purely
 * by the frame's CSS aspect-ratio (set inline from PHP) — no resize listener
 * needed. The YouTube Iframe API / Vimeo Player.js are only fetched the
 * moment a visitor actually clicks play, never on page load. GA4 events fire
 * via window.gtag() if the theme's Analytics is configured (inc/ekwa-analytics.php)
 * — no separate tracking setup. Lightbox playback needs nothing here at all;
 * the block's <a class="ekwa-lightbox"> is picked up by the theme-wide
 * GLightbox loader (inc/ekwa-lightbox.php).
 */
( function () {
	'use strict';

	var ytApiPromise    = null;
	var vimeoApiPromise = null;

	function loadYouTubeAPI() {
		if ( ytApiPromise ) { return ytApiPromise; }
		if ( window.YT && window.YT.Player ) { return Promise.resolve( window.YT ); }

		ytApiPromise = new Promise( function ( resolve ) {
			var timeout = setTimeout( function () { resolve( null ); }, 6000 );
			var existing = window.onYouTubeIframeAPIReady;
			window.onYouTubeIframeAPIReady = function () {
				clearTimeout( timeout );
				if ( existing ) { existing(); }
				resolve( window.YT );
			};
			var tag = document.createElement( 'script' );
			tag.src = 'https://www.youtube.com/iframe_api';
			tag.async = true;
			tag.onerror = function () { clearTimeout( timeout ); resolve( null ); };
			document.head.appendChild( tag );
		} );
		return ytApiPromise;
	}

	function loadVimeoAPI() {
		if ( vimeoApiPromise ) { return vimeoApiPromise; }
		if ( window.Vimeo && window.Vimeo.Player ) { return Promise.resolve( window.Vimeo ); }

		vimeoApiPromise = new Promise( function ( resolve ) {
			var timeout = setTimeout( function () { resolve( null ); }, 6000 );
			var tag = document.createElement( 'script' );
			tag.src = 'https://player.vimeo.com/api/player.js';
			tag.async = true;
			tag.onload = function () { clearTimeout( timeout ); resolve( window.Vimeo ); };
			tag.onerror = function () { clearTimeout( timeout ); resolve( null ); };
			document.head.appendChild( tag );
		} );
		return vimeoApiPromise;
	}

	function track( name, params ) {
		if ( typeof window.gtag === 'function' ) {
			window.gtag( 'event', name, params );
		}
	}

	function videoTitleFor( root ) {
		var el = root.querySelector( '.ekwa-video-embed__title' );
		return el ? el.textContent.trim() : '';
	}

	/**
	 * Build the iframe, inject it in place of the thumbnail, and wire up
	 * provider-specific playback-state tracking once its API is available.
	 */
	function play( root, button ) {
		var frame    = button.closest( '.ekwa-video-embed__frame' );
		var provider = button.getAttribute( 'data-provider' );
		var videoId  = button.getAttribute( 'data-video-id' );
		var embedUrl = button.getAttribute( 'data-embed-url' );
		var title    = videoTitleFor( root );
		if ( ! frame || ! embedUrl ) { return; }

		var sep = embedUrl.indexOf( '?' ) > -1 ? '&' : '?';
		var src = 'youtube' === provider
			? embedUrl + sep + 'autoplay=1&playsinline=1&enablejsapi=1&origin=' + encodeURIComponent( window.location.origin )
			: embedUrl + sep + 'autoplay=1&title=0&byline=0&portrait=0&dnt=1';

		var iframe = document.createElement( 'iframe' );
		iframe.className = 'ekwa-video-embed__iframe';
		iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
		iframe.allowFullscreen = true;
		iframe.src = src;

		button.remove();
		frame.appendChild( iframe );

		// Fire video_start optimistically the moment playback is requested —
		// tracking then still works even if the provider's JS API never loads.
		track( 'video_start', { video_title: title, video_provider: provider, video_id: videoId } );

		if ( 'youtube' === provider ) {
			loadYouTubeAPI().then( function ( YT ) {
				if ( ! YT || ! YT.Player ) { return; }
				var triggered = {};
				var progressTimer = null;
				new YT.Player( iframe, {
					events: {
						onStateChange: function ( e ) {
							if ( e.data === YT.PlayerState.PLAYING ) {
								clearInterval( progressTimer );
								progressTimer = setInterval( function () {
									var duration = e.target.getDuration();
									var current  = e.target.getCurrentTime();
									if ( ! duration ) { return; }
									var percent = ( current / duration ) * 100;
									[ 25, 50, 75 ].forEach( function ( m ) {
										if ( percent >= m && ! triggered[ m ] ) {
											triggered[ m ] = true;
											track( 'video_progress', { video_title: title, video_provider: provider, video_id: videoId, progress_percentage: m } );
										}
									} );
								}, 1000 );
							} else if ( e.data === YT.PlayerState.PAUSED ) {
								clearInterval( progressTimer );
								track( 'video_pause', { video_title: title, video_provider: provider, video_id: videoId, video_current_time: Math.round( e.target.getCurrentTime() ) } );
							} else if ( e.data === YT.PlayerState.ENDED ) {
								clearInterval( progressTimer );
								track( 'video_complete', { video_title: title, video_provider: provider, video_id: videoId } );
							}
						},
					},
				} );
			} );
		} else {
			loadVimeoAPI().then( function ( Vimeo ) {
				if ( ! Vimeo || ! Vimeo.Player ) { return; }
				var player    = new Vimeo.Player( iframe );
				var triggered = {};
				var duration  = 0;
				player.getDuration().then( function ( d ) { duration = d; } );
				player.on( 'timeupdate', function ( data ) {
					if ( ! duration ) { return; }
					var percent = ( data.seconds / duration ) * 100;
					[ 25, 50, 75 ].forEach( function ( m ) {
						if ( percent >= m && ! triggered[ m ] ) {
							triggered[ m ] = true;
							track( 'video_progress', { video_title: title, video_provider: provider, video_id: videoId, progress_percentage: m } );
						}
					} );
				} );
				player.on( 'pause', function ( data ) {
					track( 'video_pause', { video_title: title, video_provider: provider, video_id: videoId, video_current_time: Math.round( data.seconds ) } );
				} );
				player.on( 'ended', function () {
					track( 'video_complete', { video_title: title, video_provider: provider, video_id: videoId } );
				} );
			} );
		}
	}

	function init( root ) {
		if ( root.__ekwaVideoEmbed ) { return; }
		root.__ekwaVideoEmbed = true;

		var button = root.querySelector( '.ekwa-video-embed__play' );
		if ( ! button ) { return; } // Lightbox instance — nothing to bind here.

		button.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			play( root, button );
		} );
	}

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( '.ekwa-video-embed' ), init );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
