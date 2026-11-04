/**
 * Ekwa — Site Audit in the post editor: "this page is hidden from search
 * engines, but it has content now".
 *
 * Pages without content ("coming soon") are hidden from search engines through
 * Yoast's per-page setting (Site Audit → Content & SEO). Once someone fills one
 * in and saves, this says so and offers to show it again — by setting Yoast's
 * own "Allow search engines to show this page in search results?" field in
 * the editor and saving, exactly as if the person had done it in Yoast's
 * Advanced tab. Nothing else is written.
 *
 * Only after a (non-auto) save, at most once per editing session, and never on
 * policy, thank-you or blog pages (meant to stay hidden). Loaded only when
 * Yoast is active (inc/ekwa-site-audit.php).
 */
( function ( wp ) {
	'use strict';

	var CFG = window.ekwaSiteAuditEditor;
	if ( ! CFG || ! wp || ! wp.data || ! wp.i18n ) { return; }

	var __        = wp.i18n.__;
	var NOTICE_ID = 'ekwa-site-audit-hidden';
	var SETS      = CFG.blocks || { layout: [], text: [], chrome: [] };
	var TEXT_KEYS = [ 'text', 'content', 'title', 'heading', 'subtitle', 'subheading', 'label', 'caption', 'description', 'question', 'answer', 'buttonText', 'linkText', 'tagline', 'eyebrow' ];

	// Yoast's hidden form field is what it saves (its store's setNoIndex writes
	// it too). The store itself reads '' until Yoast's Advanced panel loads, so
	// it is only the fallback.
	function noindexValue() {
		var input = document.getElementById( 'yoast_wpseo_meta-robots-noindex' );
		if ( input ) { return String( input.value ); }
		var y = wp.data.select( 'yoast-seo/editor' );
		if ( y && 'function' === typeof y.getNoIndex ) {
			var v = y.getNoIndex();
			if ( null != v ) { return String( v ); }
		}
		return '';
	}

	// Yoast: '1' = hidden, '2' = shown, '' / '0' = the post type's default.
	function isHidden( v ) {
		return '1' === v || ( '2' !== v && !! CFG.typeNoindex );
	}

	// Text of saved HTML without running or loading anything in it.
	function textOf( html ) {
		if ( ! html ) { return ''; }
		try {
			var doc = new window.DOMParser().parseFromString( String( html ), 'text/html' );
			return doc.body ? doc.body.textContent || '' : '';
		} catch ( e ) {
			return String( html ).replace( /<[^>]*>/g, ' ' );
		}
	}

	function countWords( text ) {
		var m;
		try {
			m = String( text ).match( new RegExp( '[\\p{L}\\p{N}][\\p{L}\\p{N}\'’-]*', 'gu' ) );
		} catch ( e ) {
			m = String( text ).match( /[A-Za-z0-9À-ɏ][A-Za-z0-9À-ɏ'’-]*/g );
		}
		return m ? m.length : 0;
	}

	function attrText( attrs ) {
		var out = [];
		TEXT_KEYS.forEach( function ( k ) {
			var v = attrs && attrs[ k ];
			if ( 'string' === typeof v && v && ! /^(https?:|\/|#|var\()/i.test( v ) && -1 === v.indexOf( '{' ) ) {
				out.push( textOf( v ) );
			}
		} );
		return out.join( ' ' );
	}

	// Same test as ekwa_site_audit_content_state() on the server.
	function walk( blocks, chrome, acc ) {
		( blocks || [] ).forEach( function ( b ) {
			var name     = b.name || '';
			var isChrome = chrome || -1 !== SETS.chrome.indexOf( name );
			if ( ! isChrome && -1 === SETS.layout.indexOf( name ) ) {
				if ( -1 !== SETS.text.indexOf( name ) || 'core/freeform' === name ) {
					var html = '';
					try { html = wp.blocks.getBlockContent( b ); } catch ( e ) {}
					var t = textOf( html ) + ' ' + attrText( b.attributes );
					acc.words += countWords( t );
					acc.text  += ' ' + t;
					if ( /<(img|iframe|video|audio|embed|object|form)\b/i.test( html ) ) { acc.media++; }
				} else {
					acc.media++;
					acc.text += ' ' + attrText( b.attributes );
				}
			}
			if ( b.innerBlocks && b.innerBlocks.length ) { walk( b.innerBlocks, isChrome, acc ); }
		} );
	}

	function hasContent() {
		var be = wp.data.select( 'core/block-editor' );
		if ( ! be ) { return false; }
		var acc = { words: 0, media: 0, text: '' };
		walk( be.getBlocks(), false, acc );
		var empty = acc.words < ( CFG.minWords || 5 ) && 0 === acc.media;
		var soon  = /\b(coming\s+soon|under\s+construction)\b/i.test( acc.text );
		return ! empty && ! soon;
	}

	function yoastCanSet() {
		var d = wp.data.dispatch( 'yoast-seo/editor' );
		return ( d && 'function' === typeof d.setNoIndex ) || !! document.getElementById( 'yoast_wpseo_meta-robots-noindex' );
	}

	var notices = function () { return wp.data.dispatch( 'core/notices' ); };
	var shown   = false;

	function unhide() {
		var target = CFG.typeNoindex ? '2' : '0';
		var d      = wp.data.dispatch( 'yoast-seo/editor' );
		if ( d && 'function' === typeof d.setNoIndex ) {
			d.setNoIndex( target ); // Updates Yoast's panel and its form field.
		}
		var input = document.getElementById( 'yoast_wpseo_meta-robots-noindex' );
		if ( input ) { input.value = target; }
		notices().removeNotice( NOTICE_ID );
		// Yoast's fields are stored with the post: save once more.
		var saved = wp.data.dispatch( 'core/editor' ).savePost();
		var done  = function () {
			notices().createSuccessNotice( __( 'This page can now show in search results (Yoast SEO → Advanced → “Allow search engines to show this page in search results?” → Yes).', 'ekwa' ), { type: 'snackbar', id: NOTICE_ID + '-done' } );
		};
		if ( saved && 'function' === typeof saved.then ) { saved.then( done ); } else { done(); }
	}

	function check() {
		if ( shown || CFG.kind ) { return; }
		if ( ! isHidden( noindexValue() ) || ! hasContent() ) { return; }
		shown = true;
		var actions = [];
		if ( CFG.canChange && yoastCanSet() ) {
			actions.push( { label: __( 'Show it in search results', 'ekwa' ), onClick: unhide } );
		}
		actions.push( { label: __( 'Keep it hidden', 'ekwa' ), onClick: function () { notices().removeNotice( NOTICE_ID ); } } );
		notices().createWarningNotice(
			__( 'This page is hidden from search engines (Yoast SEO → Advanced → “Allow search engines to show this page in search results?” is No), but it has content now. Google will not list it until it is shown.', 'ekwa' )
				+ ( CFG.canChange ? '' : ' ' + __( 'Ask an administrator to change it.', 'ekwa' ) ),
			{ id: NOTICE_ID, isDismissible: true, actions: actions }
		);
	}

	var wasSaving = false;
	wp.data.subscribe( function () {
		var ed = wp.data.select( 'core/editor' );
		if ( ! ed || 'function' !== typeof ed.isSavingPost ) { return; }
		var saving = ed.isSavingPost() && ! ( ed.isAutosavingPost && ed.isAutosavingPost() );
		if ( saving ) {
			wasSaving = true;
			return;
		}
		if ( wasSaving ) {
			wasSaving = false;
			if ( ed.didPostSaveRequestSucceed && ! ed.didPostSaveRequestSucceed() ) { return; }
			window.setTimeout( check, 600 );
		}
	} );
}( window.wp ) );
