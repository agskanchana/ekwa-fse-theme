/**
 * Ekwa Hero Video — frontend.
 * Pause/play toggle + respects prefers-reduced-motion (video starts paused,
 * poster shows) + pauses offscreen videos to save battery/bandwidth.
 */
( function () {
	'use strict';

	function init( root ) {
		if ( root.__ekwaHeroVideo ) { return; }
		root.__ekwaHeroVideo = true;

		var video  = root.querySelector( '.ekwa-hero-video__media' );
		var toggle = root.querySelector( '.ekwa-hero-video__toggle' );
		if ( ! video ) { return; }

		var userPaused = false;

		function setState( playing ) {
			if ( toggle ) {
				toggle.setAttribute( 'data-state', playing ? 'playing' : 'paused' );
				toggle.setAttribute( 'aria-label', playing ? 'Pause background video' : 'Play background video' );
			}
		}

		// Reduced motion: don't run the video; the poster stays.
		if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			video.removeAttribute( 'autoplay' );
			video.pause();
			userPaused = true;
			setState( false );
		}

		if ( toggle ) {
			toggle.addEventListener( 'click', function () {
				if ( video.paused ) {
					userPaused = false;
					video.play();
					setState( true );
				} else {
					userPaused = true;
					video.pause();
					setState( false );
				}
			} );
		}

		// Battery/bandwidth: pause when scrolled offscreen, resume when back
		// (unless the visitor paused it themselves).
		if ( 'IntersectionObserver' in window ) {
			new IntersectionObserver( function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						if ( ! userPaused && video.paused ) { video.play(); }
					} else if ( ! video.paused ) {
						video.pause();
					}
				} );
			}, { threshold: 0.1 } ).observe( root );
		}
	}

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( '.ekwa-hero-video' ), init );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
