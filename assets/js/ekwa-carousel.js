/**
 * Ekwa Carousel — Vanilla JS responsive, ADA-compliant carousel.
 *
 * Reads configuration from data-* attributes on the .ekwa-carousel container:
 *   data-desktop-items, data-tablet-items, data-mobile-items
 *   data-tablet-bp, data-mobile-bp        (px breakpoints)
 *   data-show-arrows, data-show-dots      ('true'|'false')
 *   data-autoplay, data-autoplay-interval (boolean, ms)
 *   data-loop                             (boolean — wrap around)
 *   data-gap                              (px between items)
 *   data-speed                            (ms transition duration)
 *
 * ADA features:
 *   - role="region" + aria-roledescription="carousel"
 *   - Keyboard nav (arrow keys when focus is inside carousel)
 *   - Slide group/aria-label per item
 *   - Live region announcing current slide
 *   - Pause autoplay on focus, hover, or when tab is hidden
 *   - Honors prefers-reduced-motion
 */
( function () {
	'use strict';

	var prefersReducedMotion = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function init() {
		document.querySelectorAll( '.ekwa-carousel' ).forEach( function ( el ) {
			if ( el.dataset.ekwaInit === '1' ) return;
			el.dataset.ekwaInit = '1';
			initCarousel( el );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	// Re-scan after block editor renders or AJAX content loads.
	window.ekwaCarouselInit = init;

	function initCarousel( container ) {
		var track = container.querySelector( '.ekwa-carousel__track' );
		if ( ! track ) return;
		var items = Array.prototype.slice.call( track.querySelectorAll( '.ekwa-carousel__item' ) );
		var total = items.length;
		if ( total < 1 ) return;

		var ds = container.dataset;
		var desktop          = parseInt( ds.desktopItems, 10 ) || 3;
		var tablet           = parseInt( ds.tabletItems, 10 )  || 2;
		var mobile           = parseInt( ds.mobileItems, 10 )  || 1;
		var tabletBp         = parseInt( ds.tabletBp, 10 )     || 992;
		var mobileBp         = parseInt( ds.mobileBp, 10 )     || 600;
		var hasArrows        = ds.showArrows !== 'false';
		var hasDots          = ds.showDots   !== 'false';
		var autoplay         = ds.autoplay === 'true';
		var autoplayInterval = parseInt( ds.autoplayInterval, 10 ) || 5000;
		var loop             = ds.loop === 'true';
		var gap              = parseInt( ds.gap, 10 ) || 0;
		var speed            = parseInt( ds.speed, 10 ) || 350;

		// ARIA scaffolding.
		container.setAttribute( 'role', 'region' );
		container.setAttribute( 'aria-roledescription', 'carousel' );
		if ( ! container.getAttribute( 'aria-label' ) ) {
			container.setAttribute( 'aria-label', 'Carousel' );
		}

		// Each slide gets group semantics + position label.
		items.forEach( function ( item, i ) {
			item.setAttribute( 'role', 'group' );
			item.setAttribute( 'aria-roledescription', 'slide' );
			item.setAttribute( 'aria-label', ( i + 1 ) + ' of ' + total );
		} );

		// Live region for SR announcements.
		var live = container.querySelector( '.ekwa-carousel__sr-status' );
		if ( ! live ) {
			live = document.createElement( 'div' );
			live.className = 'ekwa-carousel__sr-status';
			live.setAttribute( 'aria-live', 'polite' );
			live.setAttribute( 'aria-atomic', 'true' );
			container.appendChild( live );
		}

		// Apply gap.
		if ( gap > 0 ) {
			items.forEach( function ( item ) {
				item.style.paddingLeft  = ( gap / 2 ) + 'px';
				item.style.paddingRight = ( gap / 2 ) + 'px';
			} );
			track.style.marginLeft  = '-' + ( gap / 2 ) + 'px';
			track.style.marginRight = '-' + ( gap / 2 ) + 'px';
		}

		var current = 0;
		var visible = getVisible();

		function getVisible() {
			var w = window.innerWidth;
			if ( w >= tabletBp ) return desktop;
			if ( w >= mobileBp ) return tablet;
			return mobile;
		}

		function maxSlide() {
			return Math.max( 0, total - visible );
		}

		function setWidths() {
			visible = getVisible();
			var pct = 100 / visible;
			items.forEach( function ( item ) {
				item.style.flex     = '0 0 ' + pct + '%';
				item.style.maxWidth = pct + '%';
			} );
			slide( false );
		}

		function slide( animate ) {
			var max = maxSlide();
			if ( loop ) {
				if ( current > max ) current = 0;
				if ( current < 0 )   current = max;
			} else {
				if ( current > max ) current = max;
				if ( current < 0 )   current = 0;
			}
			var dur = ( animate === false || prefersReducedMotion ) ? 0 : speed;
			track.style.transition = dur ? ( 'transform ' + dur + 'ms ease' ) : 'none';
			track.style.transform  = 'translateX(-' + ( current * ( 100 / visible ) ) + '%)';

			updateArrows();
			updateDots();
			updateInert();
			announce();
		}

		function updateInert() {
			items.forEach( function ( item, i ) {
				var inView = i >= current && i < current + visible;
				if ( inView ) {
					item.removeAttribute( 'aria-hidden' );
					item.removeAttribute( 'inert' );
				} else {
					item.setAttribute( 'aria-hidden', 'true' );
					item.setAttribute( 'inert', '' );
				}
			} );
		}

		function announce() {
			live.textContent = 'Slide ' + ( current + 1 ) + ' of ' + ( maxSlide() + 1 );
		}

		// Arrows.
		var prevBtn = container.querySelector( '.ekwa-carousel__arrow--prev' );
		var nextBtn = container.querySelector( '.ekwa-carousel__arrow--next' );

		function updateArrows() {
			if ( ! hasArrows ) return;
			if ( loop ) {
				if ( prevBtn ) prevBtn.disabled = false;
				if ( nextBtn ) nextBtn.disabled = false;
			} else {
				if ( prevBtn ) prevBtn.disabled = current <= 0;
				if ( nextBtn ) nextBtn.disabled = current >= maxSlide();
			}
		}

		if ( prevBtn ) prevBtn.addEventListener( 'click', function () { current--; slide(); restartAutoplay(); } );
		if ( nextBtn ) nextBtn.addEventListener( 'click', function () { current++; slide(); restartAutoplay(); } );

		// Dots.
		var dotsContainer = container.querySelector( '.ekwa-carousel__dots' );

		function buildDots() {
			if ( ! dotsContainer || ! hasDots ) return;
			dotsContainer.innerHTML = '';
			var count = maxSlide() + 1;
			for ( var i = 0; i < count; i++ ) {
				var dot = document.createElement( 'button' );
				dot.type = 'button';
				dot.className = 'ekwa-carousel__dot' + ( i === current ? ' is-active' : '' );
				dot.setAttribute( 'aria-label', 'Go to slide ' + ( i + 1 ) );
				dot.setAttribute( 'aria-current', i === current ? 'true' : 'false' );
				dot.dataset.index = i;
				dot.addEventListener( 'click', function () {
					current = parseInt( this.dataset.index, 10 );
					slide();
					restartAutoplay();
				} );
				dotsContainer.appendChild( dot );
			}
		}

		function updateDots() {
			if ( ! dotsContainer ) return;
			dotsContainer.querySelectorAll( '.ekwa-carousel__dot' ).forEach( function ( d, i ) {
				var active = i === current;
				d.classList.toggle( 'is-active', active );
				d.setAttribute( 'aria-current', active ? 'true' : 'false' );
			} );
		}

		// Keyboard navigation when focus is inside carousel.
		container.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'ArrowLeft' )  { current--; slide(); restartAutoplay(); e.preventDefault(); }
			if ( e.key === 'ArrowRight' ) { current++; slide(); restartAutoplay(); e.preventDefault(); }
			if ( e.key === 'Home' )       { current = 0; slide(); restartAutoplay(); e.preventDefault(); }
			if ( e.key === 'End' )        { current = maxSlide(); slide(); restartAutoplay(); e.preventDefault(); }
		} );

		// Touch/swipe.
		var startX = 0, deltaX = 0, dragging = false;

		track.addEventListener( 'touchstart', function ( e ) {
			startX = e.touches[0].clientX;
			deltaX = 0;
			dragging = true;
		}, { passive: true } );

		track.addEventListener( 'touchmove', function ( e ) {
			if ( dragging ) deltaX = e.touches[0].clientX - startX;
		}, { passive: true } );

		track.addEventListener( 'touchend', function () {
			if ( ! dragging ) return;
			dragging = false;
			if ( deltaX < -50 ) { current++; slide(); restartAutoplay(); }
			else if ( deltaX > 50 ) { current--; slide(); restartAutoplay(); }
			deltaX = 0;
		} );

		// Autoplay.
		var autoplayTimer = null;

		function startAutoplay() {
			if ( ! autoplay || prefersReducedMotion || maxSlide() < 1 ) return;
			stopAutoplay();
			autoplayTimer = setInterval( function () {
				current++;
				slide();
			}, autoplayInterval );
		}

		function stopAutoplay() {
			if ( autoplayTimer ) {
				clearInterval( autoplayTimer );
				autoplayTimer = null;
			}
		}

		function restartAutoplay() {
			if ( autoplay ) {
				stopAutoplay();
				startAutoplay();
			}
		}

		if ( autoplay ) {
			container.addEventListener( 'mouseenter', stopAutoplay );
			container.addEventListener( 'mouseleave', startAutoplay );
			container.addEventListener( 'focusin',  stopAutoplay );
			container.addEventListener( 'focusout', startAutoplay );
			document.addEventListener( 'visibilitychange', function () {
				if ( document.hidden ) stopAutoplay();
				else                   startAutoplay();
			} );
		}

		// Init.
		setWidths();
		buildDots();
		startAutoplay();

		// Debounced resize.
		var resizeTimer;
		window.addEventListener( 'resize', function () {
			clearTimeout( resizeTimer );
			resizeTimer = setTimeout( function () {
				setWidths();
				buildDots();
			}, 150 );
		} );
	}
} )();
