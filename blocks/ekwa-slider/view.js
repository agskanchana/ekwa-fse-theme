/**
 * Ekwa Slider — frontend engine.
 * Inlined before </body> only on pages where the block renders.
 * Transitions (fade / slide / zoom), arrows, dots, keyboard, touch swipe,
 * autoplay (pauses on hover/focus/hidden tab), lazy slide backgrounds, and
 * content entrance animations replayed on every slide activation.
 */
( function () {
	'use strict';

	function initSlider( root ) {
		if ( root.__ekwaSlider ) { return; }
		root.__ekwaSlider = true;

		var slides = Array.prototype.slice.call( root.querySelectorAll( '.ekwa-slide' ) );
		if ( ! slides.length ) { return; }

		var transition = root.getAttribute( 'data-transition' ) || 'fade';
		var autoplay   = root.hasAttribute( 'data-autoplay' );
		var interval   = parseInt( root.getAttribute( 'data-interval' ), 10 ) || 6000;
		var loop       = root.hasAttribute( 'data-loop' );
		var pauseHover = root.hasAttribute( 'data-pause-hover' );
		var reduced    = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

		var current = 0;
		var timer   = null;
		var busy    = false;

		// Hydrate lazy backgrounds (slide 1 got an inline style server-side).
		slides.forEach( function ( s ) {
			var bg = s.getAttribute( 'data-bg' );
			if ( bg && ! s.style.backgroundImage ) {
				s.style.backgroundImage = "url('" + bg + "')";
			}
		} );

		// Find the server-marked active slide (first).
		slides.forEach( function ( s, i ) {
			if ( s.classList.contains( 'is-active' ) ) { current = i; }
		} );

		// ── Dots ─────────────────────────────────────────────────────
		var dotsWrap = root.querySelector( '.ekwa-slider__dots' );
		var dots = [];
		if ( dotsWrap && slides.length > 1 ) {
			slides.forEach( function ( _, i ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'ekwa-slider__dot' + ( i === current ? ' is-active' : '' );
				b.setAttribute( 'role', 'tab' );
				b.setAttribute( 'aria-label', 'Go to slide ' + ( i + 1 ) );
				b.addEventListener( 'click', function () { goTo( i, i > current ? 1 : -1 ); restart(); } );
				dotsWrap.appendChild( b );
				dots.push( b );
			} );
		}

		// ── Content animations ───────────────────────────────────────
		function animateContent( slide ) {
			var items = slide.querySelectorAll( '.ekwa-slide-content[data-anim]' );
			Array.prototype.forEach.call( items, function ( el ) {
				el.classList.remove( 'ekwa-animate' );
				void el.offsetWidth; // Force reflow so the animation restarts.
				el.classList.add( 'ekwa-animate' );
			} );
		}

		function syncA11y() {
			slides.forEach( function ( s, i ) {
				s.setAttribute( 'aria-hidden', i === current ? 'false' : 'true' );
			} );
			dots.forEach( function ( d, i ) {
				d.classList.toggle( 'is-active', i === current );
			} );
		}

		// ── Navigation core ──────────────────────────────────────────
		function goTo( index, dir ) {
			if ( busy || index === current ) { return; }
			if ( index < 0 )               { if ( ! loop ) { return; } index = slides.length - 1; }
			if ( index >= slides.length )  { if ( ! loop ) { return; } index = 0; }

			var out = slides[ current ];
			var inn = slides[ index ];

			if ( 'slide' === transition && ! reduced ) {
				busy = true;
				// Position the incoming slide on the correct side first.
				inn.classList.remove( 'is-leaving', 'from-next', 'from-prev' );
				if ( dir < 0 ) { inn.classList.add( 'from-prev' ); }
				void inn.offsetWidth;
				inn.classList.add( 'is-active' );
				inn.classList.remove( 'from-prev' );
				out.classList.add( 'is-leaving' );
				if ( dir < 0 ) { out.classList.add( 'from-next' ); }
				out.classList.remove( 'is-active' );
				window.setTimeout( function () {
					out.classList.remove( 'is-leaving', 'from-next' );
					busy = false;
				}, 750 );
			} else {
				out.classList.remove( 'is-active' );
				inn.classList.add( 'is-active' );
			}

			current = index;
			syncA11y();
			animateContent( inn );
		}

		function next() { goTo( current + 1, 1 ); }
		function prev() { goTo( current - 1, -1 ); }

		// ── Arrows ───────────────────────────────────────────────────
		var prevBtn = root.querySelector( '.ekwa-slider__arrow--prev' );
		var nextBtn = root.querySelector( '.ekwa-slider__arrow--next' );
		if ( prevBtn ) { prevBtn.addEventListener( 'click', function () { prev(); restart(); } ); }
		if ( nextBtn ) { nextBtn.addEventListener( 'click', function () { next(); restart(); } ); }

		// ── Keyboard ─────────────────────────────────────────────────
		root.addEventListener( 'keydown', function ( e ) {
			if ( 'ArrowLeft' === e.key )  { prev(); restart(); }
			if ( 'ArrowRight' === e.key ) { next(); restart(); }
		} );

		// ── Touch swipe ──────────────────────────────────────────────
		var touchX = null;
		root.addEventListener( 'touchstart', function ( e ) {
			touchX = e.touches[ 0 ].clientX;
		}, { passive: true } );
		root.addEventListener( 'touchend', function ( e ) {
			if ( null === touchX ) { return; }
			var dx = e.changedTouches[ 0 ].clientX - touchX;
			touchX = null;
			if ( Math.abs( dx ) < 40 ) { return; }
			if ( dx < 0 ) { next(); } else { prev(); }
			restart();
		}, { passive: true } );

		// ── Autoplay ─────────────────────────────────────────────────
		function stop()  { if ( timer ) { window.clearInterval( timer ); timer = null; } }
		function start() {
			if ( ! autoplay || reduced || slides.length < 2 || timer ) { return; }
			timer = window.setInterval( next, interval );
		}
		function restart() { stop(); start(); }

		if ( pauseHover ) {
			root.addEventListener( 'mouseenter', stop );
			root.addEventListener( 'mouseleave', start );
			root.addEventListener( 'focusin', stop );
			root.addEventListener( 'focusout', start );
		}
		document.addEventListener( 'visibilitychange', function () {
			if ( document.hidden ) { stop(); } else { start(); }
		} );

		// ── Go ───────────────────────────────────────────────────────
		root.classList.add( 'ekwa-slider--ready' );
		syncA11y();
		animateContent( slides[ current ] );
		start();
	}

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( '.ekwa-slider' ), initSlider );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
