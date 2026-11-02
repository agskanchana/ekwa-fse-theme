/**
 * Ekwa — side-by-side compare of a stylesheet fix (admin compare screen).
 *
 * Both iframes are same-origin front-end pages, so this script can size them
 * to a device width, scroll them together and outline the elements the fix
 * targets directly in their documents.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'ekwa-cmp' );
	if ( ! root ) { return; }

	var CFG     = window.ekwaPaCompare || {};
	var frames  = Array.prototype.slice.call( root.querySelectorAll( 'iframe' ) );
	var buttons = Array.prototype.slice.call( root.querySelectorAll( '[data-width]' ) );
	var sync    = document.getElementById( 'ekwa-cmp-sync' );
	var hl      = document.getElementById( 'ekwa-cmp-hl' );
	var jump    = document.getElementById( 'ekwa-cmp-jump' );
	var msg     = document.getElementById( 'ekwa-cmp-msg' );
	var width   = 1366;


	try {
		var saved = parseInt( window.localStorage.getItem( 'ekwaCmpWidth' ), 10 );
		if ( saved ) { width = saved; }
	} catch ( e ) {}

	// Selectors without pseudo-classes/elements, so they can be matched.
	var selectors = ( CFG.selectors || [] ).map( function ( s ) {
		return String( s ).replace( /::?[a-z-]+(\([^)]*\))?/gi, '' ).trim();
	} ).filter( Boolean );

	function layout() {
		frames.forEach( function ( f ) {
			var box   = f.parentNode.getBoundingClientRect();
			var scale = Math.min( 1, box.width / width );
			f.style.width     = width + 'px';
			f.style.height    = Math.ceil( box.height / scale ) + 'px';
			f.style.transform = 'scale(' + scale + ')';
		} );
		buttons.forEach( function ( b ) {
			b.setAttribute( 'aria-pressed', String( parseInt( b.getAttribute( 'data-width' ), 10 ) === width ) );
		} );
	}

	function doc( f ) {
		try { return f.contentDocument; } catch ( e ) { return null; }
	}

	function outline( f ) {
		var d = doc( f );
		if ( ! d || ! d.head ) { return; }
		var style = d.getElementById( 'ekwa-cmp-hl' );
		if ( ! hl.checked || ! selectors.length ) {
			if ( style ) { style.remove(); }
			return;
		}
		if ( ! style ) {
			style    = d.createElement( 'style' );
			style.id = 'ekwa-cmp-hl';
			d.head.appendChild( style );
		}
		style.textContent = selectors.join( ',' ) + '{outline:3px dashed #d63638 !important;outline-offset:3px !important;}';
	}

	function first( f ) {
		var d = doc( f );
		if ( ! d ) { return null; }
		for ( var i = 0; i < selectors.length; i++ ) {
			try {
				var list = d.querySelectorAll( selectors[ i ] );
				for ( var j = 0; j < list.length; j++ ) {
					var r = list[ j ].getBoundingClientRect();
					if ( r.width > 0 && r.height > 0 ) { return list[ j ]; }
				}
			} catch ( e ) {}
		}
		return null;
	}

	// Instant, whatever the page's own `scroll-behavior: smooth` says — a
	// smooth scroll in one frame being mirrored into the other made the two
	// fight each other back up the page.
	function scrollFrame( f, y ) {
		if ( ! f.contentWindow ) { return; }
		try {
			f.contentWindow.scrollTo( { top: Math.max( 0, y ), left: 0, behavior: 'instant' } );
		} catch ( e ) {
			f.contentWindow.scrollTo( 0, Math.max( 0, y ) );
		}
	}

	// Only the frame the person is actually scrolling leads; the other one
	// follows and never echoes back.
	var leader = null;

	function mirror( from ) {
		if ( ! sync.checked || from !== leader ) { return; }
		var y = from.contentWindow ? from.contentWindow.scrollY : 0;
		frames.forEach( function ( f ) {
			if ( f !== from ) { scrollFrame( f, y ); }
		} );
	}

	frames.forEach( function ( f ) {
		var lead = function () { leader = f; };
		f.addEventListener( 'mouseenter', lead );
		f.addEventListener( 'focus', lead );
		f.addEventListener( 'load', function () {
			outline( f );
			try {
				var d = f.contentDocument;
				// The preview never needs smooth scrolling of its own.
				var s = d.createElement( 'style' );
				s.textContent = 'html,body{scroll-behavior:auto !important}';
				d.head.appendChild( s );
				[ 'wheel', 'touchstart', 'pointerdown', 'keydown' ].forEach( function ( type ) {
					f.contentWindow.addEventListener( type, lead, { passive: true } );
				} );
				f.contentWindow.addEventListener( 'scroll', function () { mirror( f ); }, { passive: true } );
			} catch ( e ) {}
		} );
	} );

	// Briefly pulse an element so the eye finds it after a jump.
	function flash( f, el ) {
		var d = doc( f );
		if ( ! d || ! el ) { return; }
		if ( ! d.getElementById( 'ekwa-cmp-flash-style' ) ) {
			var s = d.createElement( 'style' );
			s.id = 'ekwa-cmp-flash-style';
			s.textContent = '@keyframes ekwaCmpFlash{0%,100%{box-shadow:0 0 0 0 rgba(214,54,56,0)}30%,70%{box-shadow:0 0 0 8px rgba(214,54,56,.45)}}'
				+ '.ekwa-cmp-flash{animation:ekwaCmpFlash 1.4s ease-in-out 2 !important}';
			d.head.appendChild( s );
		}
		el.classList.remove( 'ekwa-cmp-flash' );
		void el.offsetWidth; // restart the animation
		el.classList.add( 'ekwa-cmp-flash' );
		setTimeout( function () { el.classList.remove( 'ekwa-cmp-flash' ); }, 3000 );
	}

	buttons.forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			width = parseInt( b.getAttribute( 'data-width' ), 10 ) || 1366;
			try { window.localStorage.setItem( 'ekwaCmpWidth', String( width ) ); } catch ( e ) {}
			layout();
		} );
	} );

	hl.addEventListener( 'change', function () { frames.forEach( outline ); } );

	jump.addEventListener( 'click', function () {
		// A programmatic jump: nobody leads, so neither frame mirrors the other.
		leader = null;
		var found = false;
		frames.forEach( function ( f ) {
			var el = first( f );
			if ( ! el || ! f.contentWindow ) { return; }
			found = true;
			// Each frame aims at its own copy (the fix may shift layout slightly).
			scrollFrame( f, el.getBoundingClientRect().top + f.contentWindow.scrollY - 120 );
			flash( f, el );
		} );
		msg.textContent = found ? '' : ( ( CFG.i18n && CFG.i18n.notFound ) || '' );
	} );

	window.addEventListener( 'resize', layout );
	layout();
}() );
