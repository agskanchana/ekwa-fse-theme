/**
 * Ekwa TOC — Table of Contents scroll tracking + active highlighting.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var toc = document.querySelector( '.ekwa-toc' );
		if ( ! toc ) return;

		var links    = toc.querySelectorAll( '.ekwa-toc__link' );
		var headings = [];

		links.forEach( function ( link ) {
			var id = link.getAttribute( 'href' );
			if ( id && id.charAt( 0 ) === '#' ) {
				var el = document.getElementById( id.substring( 1 ) );
				if ( el ) headings.push( { el: el, link: link } );
			}
		} );

		if ( ! headings.length ) return;

		// Smooth scroll on click.
		links.forEach( function ( link ) {
			link.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var id     = this.getAttribute( 'href' ).substring( 1 );
				var target = document.getElementById( id );
				if ( target ) {
					var top = target.getBoundingClientRect().top + window.pageYOffset - 100;
					window.scrollTo( { top: top, behavior: 'smooth' } );
					history.replaceState( null, '', '#' + id );
				}
			} );
		} );

		// IntersectionObserver for active tracking.
		if ( ! ( 'IntersectionObserver' in window ) ) return;

		var observer = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					links.forEach( function ( l ) { l.classList.remove( 'is-active' ); } );
					headings.forEach( function ( h ) {
						if ( h.el === entry.target ) {
							h.link.classList.add( 'is-active' );
						}
					} );
				}
			} );
		}, {
			rootMargin: '-80px 0px -70% 0px',
			threshold: 0,
		} );

		headings.forEach( function ( h ) { observer.observe( h.el ); } );

		// On mobile the TOC is always expanded — no collapse toggle.
	} );
} )();
