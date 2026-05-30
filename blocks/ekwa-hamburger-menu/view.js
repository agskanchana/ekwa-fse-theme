/**
 * Ekwa Hamburger-menu block — mmenu-light drawers (main + mobile services).
 *
 * Works in two modes:
 *   • Normal: mmenu-light.js is enqueued, so MmenuLight exists at DOMContentLoaded
 *     and the drawers are built immediately.
 *   • Deferred (Performance → "Defer mobile menu"): mmenu-light is injected on the
 *     first interaction by window.ekwaLoadMmenu (inc/ekwa-perf-head.php). The drawer
 *     build is exposed as window.ekwaBuildMmenu and invoked once the library loads;
 *     a hamburger tap before that triggers the load, then opens.
 *
 * Exposes window.ekwaMmenuDrawer / window.ekwaServicesDrawer so the mobile-dock
 * block can open the services drawer without sharing scope.
 */
( function () {
	'use strict';

	var built = false;

	/**
	 * Inject a close button into an mmenu-light offcanvas drawer.
	 */
	function addMmenuCloseButton( drawer, label ) {
		if ( ! drawer || ! drawer.content ) return;
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'ekwa-mmenu-close';
		btn.setAttribute( 'aria-label', label || 'Close' );
		btn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>';
		btn.addEventListener( 'click', function () { drawer.close(); } );
		drawer.content.appendChild( btn );
	}

	/**
	 * Build the mmenu drawers. Idempotent and safe to call once the library is
	 * present. Exposed as window.ekwaBuildMmenu for the interaction loader.
	 */
	function buildDrawers() {
		if ( built || typeof MmenuLight === 'undefined' ) return;
		built = true;

		var mmenuNav = document.getElementById( 'ekwa-mobile-nav' );
		if ( mmenuNav ) {
			var m = new MmenuLight( mmenuNav );
			m.navigation( { title: 'Menu' } );
			var mmenuDrawer = m.offcanvas( { position: 'left' } );
			addMmenuCloseButton( mmenuDrawer, 'Close menu' );
			window.ekwaMmenuDrawer = mmenuDrawer;
		}

		var servicesNav = document.getElementById( 'ekwa-mobile-services-nav' );
		if ( servicesNav ) {
			var ms = new MmenuLight( servicesNav );
			ms.navigation( { title: 'Services' } );
			var servicesDrawer = ms.offcanvas( { position: 'right' } );
			addMmenuCloseButton( servicesDrawer, 'Close services menu' );
			window.ekwaServicesDrawer = servicesDrawer;
		}
	}
	window.ekwaBuildMmenu = buildDrawers;

	/** Ensure the library is ready, then run cb (build is triggered as needed). */
	function ensureMmenu( cb ) {
		if ( typeof MmenuLight !== 'undefined' ) {
			buildDrawers();
			if ( cb ) cb();
		} else if ( typeof window.ekwaLoadMmenu === 'function' ) {
			window.ekwaLoadMmenu( cb || function () {} );
		}
	}

	function init() {
		// Build now when the library is already on the page (normal mode).
		if ( typeof MmenuLight !== 'undefined' ) {
			buildDrawers();
		}

		// Hamburger opens the main drawer (loading the library first if deferred).
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.ekwa-hamburger-btn' );
			if ( ! btn ) return;
			e.preventDefault();
			if ( window.ekwaMmenuDrawer ) {
				window.ekwaMmenuDrawer.open();
				return;
			}
			ensureMmenu( function () {
				if ( window.ekwaMmenuDrawer ) window.ekwaMmenuDrawer.open();
			} );
		} );

		// Escape closes whichever drawer is open.
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key !== 'Escape' ) return;
			if ( ! document.body.classList.contains( 'mm-ocd-opened' ) ) return;
			if ( window.ekwaMmenuDrawer )    window.ekwaMmenuDrawer.close();
			if ( window.ekwaServicesDrawer ) window.ekwaServicesDrawer.close();
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
