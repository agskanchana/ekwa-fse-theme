/*! ekwa-share-button — inject the DoneForMeSocial share widget after load, on scroll or on interaction */
/**
 * The block used to enqueue DoneForMeSocial's embed.js, which only works on a
 * page that has a global jQuery `$` (WordPress loads jQuery in noConflict mode,
 * so it usually does not) and an element with id="sm-buttons" (the block never
 * rendered one). On most pages the buttons therefore never appeared.
 *
 * This loader does embed.js's job without either dependency: it fetches the
 * same widget markup (the endpoint allows cross-origin requests), wraps it in
 * the same .sm-wrapper element, and runs the scripts it contains — which
 * innerHTML alone never would.
 *
 * When: data-ekwa-share-load="load" (the default) waits for the page's load
 * event and the next idle moment; "interaction" waits for the first scroll,
 * wheel, pointer move, touch, key or click. Either way the first of those
 * events starts it early, so it is never later than the visitor needs it.
 */
( function () {
	'use strict';

	var SELECTOR = '.ekwa-share-button[data-ekwa-share-src]';
	var EVENTS   = [ 'scroll', 'wheel', 'mousemove', 'touchstart', 'keydown', 'click' ];
	var OPTS     = { passive: true, capture: true };
	var started  = false;
	var ranSrc   = {}; // script URL -> true, so N buttons never load a library N times.

	function absolute( url ) {
		var a = document.createElement( 'a' );
		a.href = url;
		return a.href;
	}

	// innerHTML inserts <script> tags inert. Replace each with a fresh element,
	// which runs once the wrapper is attached to the document.
	function revive( wrap ) {
		var scripts = wrap.querySelectorAll( 'script' );
		for ( var i = 0; i < scripts.length; i++ ) {
			var old = scripts[ i ];
			var src = old.getAttribute( 'src' );
			if ( src ) {
				var abs = absolute( src );
				if ( ranSrc[ abs ] ) {
					old.parentNode.removeChild( old );
					continue;
				}
				ranSrc[ abs ] = true;
			}
			var fresh = document.createElement( 'script' );
			for ( var a = 0; a < old.attributes.length; a++ ) {
				fresh.setAttribute( old.attributes[ a ].name, old.attributes[ a ].value );
			}
			if ( ! src ) {
				fresh.text = old.text || old.textContent || '';
			}
			old.parentNode.replaceChild( fresh, old );
		}
	}

	function fill( targets, html ) {
		for ( var i = 0; i < targets.length; i++ ) {
			var wrap = document.createElement( 'div' );
			wrap.className = 'sm-wrapper';
			wrap.innerHTML = html;
			revive( wrap );
			targets[ i ].appendChild( wrap );
		}
	}

	function unbind() {
		for ( var i = 0; i < EVENTS.length; i++ ) {
			window.removeEventListener( EVENTS[ i ], start, OPTS );
		}
	}

	function start() {
		if ( started ) {
			return;
		}
		started = true;
		unbind();

		var nodes = document.querySelectorAll( SELECTOR );
		var bySrc = {};
		for ( var i = 0; i < nodes.length; i++ ) {
			var src = nodes[ i ].getAttribute( 'data-ekwa-share-src' );
			nodes[ i ].removeAttribute( 'data-ekwa-share-src' );
			if ( ! src ) {
				continue;
			}
			( bySrc[ src ] = bySrc[ src ] || [] ).push( nodes[ i ] );
		}

		Object.keys( bySrc ).forEach( function ( src ) {
			if ( ! window.fetch ) {
				return;
			}
			window.fetch( src, { credentials: 'omit' } )
				.then( function ( res ) {
					return res.ok ? res.text() : '';
				} )
				.then( function ( html ) {
					if ( html && html.trim() ) {
						fill( bySrc[ src ], html );
					}
				} )
				.catch( function () {
					// Network or CORS failure: leave the container empty, as before.
				} );
		} );
	}

	function whenIdle() {
		if ( window.requestIdleCallback ) {
			window.requestIdleCallback( start, { timeout: 2000 } );
		} else {
			window.setTimeout( start, 200 );
		}
	}

	function init() {
		var nodes = document.querySelectorAll( SELECTOR );
		if ( ! nodes.length ) {
			return;
		}

		for ( var i = 0; i < EVENTS.length; i++ ) {
			window.addEventListener( EVENTS[ i ], start, OPTS );
		}

		var onLoad = false;
		for ( var j = 0; j < nodes.length; j++ ) {
			if ( 'interaction' !== nodes[ j ].getAttribute( 'data-ekwa-share-load' ) ) {
				onLoad = true;
				break;
			}
		}
		if ( ! onLoad ) {
			return;
		}
		if ( 'complete' === document.readyState ) {
			whenIdle();
		} else {
			window.addEventListener( 'load', whenIdle, { once: true } );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
