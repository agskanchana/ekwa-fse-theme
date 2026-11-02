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
	var lock    = false;

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

	function mirror( from ) {
		if ( ! sync.checked || lock ) { return; }
		var y = from.contentWindow ? from.contentWindow.scrollY : 0;
		lock  = true;
		frames.forEach( function ( f ) {
			if ( f !== from && f.contentWindow ) { f.contentWindow.scrollTo( 0, y ); }
		} );
		window.requestAnimationFrame( function () { lock = false; } );
	}

	frames.forEach( function ( f ) {
		f.addEventListener( 'load', function () {
			outline( f );
			try {
				f.contentWindow.addEventListener( 'scroll', function () { mirror( f ); }, { passive: true } );
			} catch ( e ) {}
		} );
	} );

	buttons.forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			width = parseInt( b.getAttribute( 'data-width' ), 10 ) || 1366;
			try { window.localStorage.setItem( 'ekwaCmpWidth', String( width ) ); } catch ( e ) {}
			layout();
		} );
	} );

	hl.addEventListener( 'change', function () { frames.forEach( outline ); } );

	jump.addEventListener( 'click', function () {
		var target = frames[ 1 ] || frames[ 0 ];
		var el     = first( target );
		if ( ! el ) {
			msg.textContent = ( CFG.i18n && CFG.i18n.notFound ) || '';
			return;
		}
		msg.textContent = '';
		var y = el.getBoundingClientRect().top + target.contentWindow.scrollY - 120;
		frames.forEach( function ( f ) {
			if ( f.contentWindow ) { f.contentWindow.scrollTo( 0, Math.max( 0, y ) ); }
		} );
	} );

	window.addEventListener( 'resize', layout );
	layout();
}() );
