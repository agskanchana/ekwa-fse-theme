/**
 * Ekwa Carousel — Vanilla JS responsive carousel with touch/swipe.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.ekwa-carousel' ).forEach( initCarousel );
	} );

	function initCarousel( container ) {
		var track    = container.querySelector( '.ekwa-carousel__track' );
		var items    = track ? track.querySelectorAll( '.ekwa-carousel__item' ) : [];
		var total    = items.length;

		if ( total < 2 ) return;

		var desktop  = parseInt( container.dataset.desktopItems ) || 3;
		var tablet   = parseInt( container.dataset.tabletItems )  || 2;
		var mobile   = parseInt( container.dataset.mobileItems )  || 1;
		var hasArrows = container.dataset.showArrows !== 'false';
		var hasDots   = container.dataset.showDots !== 'false';

		var current  = 0;
		var visible  = getVisible();

		// Set item widths.
		function setWidths() {
			visible = getVisible();
			items.forEach( function ( item ) {
				item.style.flex = '0 0 ' + ( 100 / visible ) + '%';
				item.style.maxWidth = ( 100 / visible ) + '%';
			} );
			slide( false );
		}

		function getVisible() {
			var w = window.innerWidth;
			if ( w >= 1024 ) return desktop;
			if ( w >= 768 )  return tablet;
			return mobile;
		}

		function maxSlide() {
			return Math.max( 0, total - visible );
		}

		function slide( animate ) {
			var max = maxSlide();
			if ( current > max ) current = max;
			if ( current < 0 )   current = 0;
			track.style.transition = animate !== false ? 'transform 0.35s ease' : 'none';
			track.style.transform  = 'translateX(-' + ( current * ( 100 / visible ) ) + '%)';
			updateArrows();
			updateDots();
		}

		// Arrows.
		var prevBtn = container.querySelector( '.ekwa-carousel__arrow--prev' );
		var nextBtn = container.querySelector( '.ekwa-carousel__arrow--next' );

		function updateArrows() {
			if ( prevBtn ) prevBtn.disabled = current <= 0;
			if ( nextBtn ) nextBtn.disabled = current >= maxSlide();
		}

		if ( prevBtn ) prevBtn.addEventListener( 'click', function () { current--; slide(); } );
		if ( nextBtn ) nextBtn.addEventListener( 'click', function () { current++; slide(); } );

		// Dots.
		var dotsContainer = container.querySelector( '.ekwa-carousel__dots' );

		function buildDots() {
			if ( ! dotsContainer || ! hasDots ) return;
			dotsContainer.innerHTML = '';
			var count = maxSlide() + 1;
			for ( var i = 0; i < count; i++ ) {
				var dot = document.createElement( 'button' );
				dot.className = 'ekwa-carousel__dot' + ( i === current ? ' is-active' : '' );
				dot.setAttribute( 'aria-label', 'Slide ' + ( i + 1 ) );
				dot.dataset.index = i;
				dot.addEventListener( 'click', function () {
					current = parseInt( this.dataset.index );
					slide();
				} );
				dotsContainer.appendChild( dot );
			}
		}

		function updateDots() {
			if ( ! dotsContainer ) return;
			dotsContainer.querySelectorAll( '.ekwa-carousel__dot' ).forEach( function ( d, i ) {
				d.classList.toggle( 'is-active', i === current );
			} );
		}

		// Touch/swipe.
		var startX = 0, deltaX = 0, dragging = false;

		track.addEventListener( 'touchstart', function ( e ) {
			startX = e.touches[0].clientX;
			dragging = true;
		}, { passive: true } );

		track.addEventListener( 'touchmove', function ( e ) {
			if ( dragging ) deltaX = e.touches[0].clientX - startX;
		}, { passive: true } );

		track.addEventListener( 'touchend', function () {
			if ( ! dragging ) return;
			dragging = false;
			if ( deltaX < -50 ) { current++; slide(); }
			else if ( deltaX > 50 ) { current--; slide(); }
			deltaX = 0;
		} );

		// Init.
		setWidths();
		buildDots();

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
