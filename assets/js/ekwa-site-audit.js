/**
 * Ekwa — Site Audit screens (Content & SEO, Broken Links, Image Alt Text,
 * Spelling). The PageSpeed screen is assets/js/ekwa-page-audit-dashboard.js.
 *
 * One app, one view per screen (ekwaSiteAudit.view). Every screen loads with a
 * read-only GET; changes happen only on a click:
 *   content   hide / show pages in search engines (Yoast's per-page setting)
 *   links     run the link check (results are saved for next time)
 *   images    save alt text to the media library (core /wp/v2/media route)
 *   spelling  run the AI check, ignore a word site-wide
 *
 * Plain wp.element (no build step), like the theme's other admin scripts.
 */
( function ( wp ) {
	'use strict';

	var root = document.getElementById( 'ekwa-sa-app' );
	if ( ! root || ! wp || ! wp.element || ! wp.apiFetch ) { return; }

	var el          = wp.element.createElement;
	var Fragment    = wp.element.Fragment;
	var useState    = wp.element.useState;
	var useEffect   = wp.element.useEffect;
	var useRef      = wp.element.useRef;
	var __          = wp.i18n.__;
	var _n          = wp.i18n._n;
	var sprintf     = wp.i18n.sprintf;
	var apiFetch    = wp.apiFetch;
	var Button      = wp.components.Button;
	var Spinner     = wp.components.Spinner;
	var Notice      = wp.components.Notice;

	var CFG = window.ekwaSiteAudit || {};

	// ─── Helpers ────────────────────────────────────────────────────────────

	function errText( err ) {
		return err && err.message ? String( err.message ) : __( 'Something went wrong. Try again.', 'ekwa' );
	}

	function assign( target ) {
		for ( var i = 1; i < arguments.length; i++ ) {
			var src = arguments[ i ];
			if ( ! src ) { continue; }
			for ( var k in src ) {
				if ( Object.prototype.hasOwnProperty.call( src, k ) ) { target[ k ] = src[ k ]; }
			}
		}
		return target;
	}

	function store( key, value ) {
		try {
			if ( undefined === value ) { return window.localStorage.getItem( 'ekwaSa:' + key ); }
			window.localStorage.setItem( 'ekwaSa:' + key, String( value ) );
		} catch ( e ) {}
		return null;
	}

	function ago( ts ) {
		if ( ! ts ) { return ''; }
		var diff = Math.round( ( Date.now() / 1000 ) - ts );
		var fmt  = window.Intl && window.Intl.RelativeTimeFormat ? new window.Intl.RelativeTimeFormat( undefined, { numeric: 'auto' } ) : null;
		var unit = [ [ 60, 'second', 1 ], [ 3600, 'minute', 60 ], [ 86400, 'hour', 3600 ], [ 2592000, 'day', 86400 ], [ 31536000, 'month', 2592000 ], [ Infinity, 'year', 31536000 ] ];
		for ( var i = 0; i < unit.length; i++ ) {
			if ( diff < unit[ i ][ 0 ] ) {
				var n = Math.max( 0, Math.round( diff / unit[ i ][ 2 ] ) );
				return fmt ? fmt.format( -n, unit[ i ][ 1 ] ) : n + ' ' + unit[ i ][ 1 ] + 's ago';
			}
		}
		return '';
	}

	function shortUrl( u ) {
		return String( u || '' ).replace( /^https?:\/\//, '' );
	}

	function copyText( text ) {
		if ( window.navigator && window.navigator.clipboard && window.isSecureContext ) {
			return window.navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.setAttribute( 'readonly', '' );
			ta.style.position = 'fixed';
			ta.style.top = '-1000px';
			document.body.appendChild( ta );
			ta.select();
			try {
				var ok = document.execCommand( 'copy' );
				document.body.removeChild( ta );
				if ( ok ) { resolve(); } else { reject( new Error( 'copy' ) ); }
			} catch ( e ) {
				document.body.removeChild( ta );
				reject( e );
			}
		} );
	}

	// Run tasks a few at a time; onEach(task, result|error), onDone().
	function runQueue( tasks, worker, conc, hooks ) {
		var queue   = tasks.slice();
		var active  = 0;
		var stopped = false;
		function pump() {
			while ( ! stopped && active < conc && queue.length ) {
				( function ( task ) {
					active++;
					worker( task ).then( function ( res ) {
						if ( hooks.onEach ) { hooks.onEach( task, res, null ); }
					}, function ( err ) {
						if ( hooks.onEach ) { hooks.onEach( task, null, err ); }
						if ( err && hooks.fatal && hooks.fatal( err ) ) { stopped = true; queue = []; }
					} ).then( function () {
						active--;
						if ( ! queue.length && 0 === active ) {
							if ( hooks.onDone ) { hooks.onDone( stopped ); }
						} else {
							pump();
						}
					} );
				}( queue.shift() ) );
			}
		}
		pump();
		return {
			stop: function () { stopped = true; queue = []; if ( 0 === active && hooks.onDone ) { hooks.onDone( true ); } },
		};
	}

	function aiFatal( err ) {
		var c = err && err.code ? String( err.code ) : '';
		return 'ekwa_ai_unavailable' === c || 'ekwa_ai_forbidden' === c || 'ekwa_ai_relay_cancelled' === c || /quota|limit/i.test( c );
	}

	// ─── Small pieces ───────────────────────────────────────────────────────

	function CopyButton( props ) {
		var _s = useState( '' );
		var disabled = ! props.text;
		return el( Button, {
			variant: props.variant || 'secondary',
			size: 'small' === props.size ? 'small' : undefined,
			disabled: disabled,
			icon: 'admin-page',
			onClick: function () {
				copyText( props.text ).then( function () {
					_s[ 1 ]( 'ok' );
					setTimeout( function () { _s[ 1 ]( '' ); }, 2200 );
				}, function () { _s[ 1 ]( 'fail' ); } );
			},
		}, 'ok' === _s[ 0 ] ? __( 'Copied ✓', 'ekwa' ) : ( 'fail' === _s[ 0 ] ? __( 'Copy failed', 'ekwa' ) : props.label ) );
	}

	function Chips( props ) {
		return el( 'div', { className: 'ekwa-sa-chips', role: 'group', 'aria-label': props.label },
			props.options.map( function ( o ) {
				var on = props.value === o.key;
				return el( 'button', {
					key: o.key, type: 'button', 'aria-pressed': on,
					className: 'ekwa-sa-chip' + ( on ? ' is-on' : '' ) + ( o.tone ? ' is-' + o.tone : '' ),
					onClick: function () { props.onChange( o.key ); },
				}, o.label, null != o.count ? el( 'span', { className: 'ekwa-sa-chip__n' }, String( o.count ) ) : null );
			} )
		);
	}

	function Tag( props ) {
		return el( 'span', { className: 'ekwa-pad-tag' + ( props.tone ? ' ekwa-sa-tag--' + props.tone : '' ), title: props.title || undefined }, props.children );
	}

	function Badge( props ) {
		var icons = { ok: '✓', broken: '✕', warn: '!', redirect: '↪', none: '–', bad: '✕', info: 'i' };
		return el( 'span', { className: 'ekwa-sa-badge is-' + props.tone },
			el( 'span', { 'aria-hidden': 'true' }, icons[ props.tone ] || '' ), ' ', props.children );
	}

	function SourceLine( props ) {
		var s = props.source;
		if ( ! s ) { return el( 'span', { className: 'ekwa-pad-muted' }, __( '(unknown place)', 'ekwa' ) ); }
		return el( 'span', { className: 'ekwa-sa-src' },
			el( Tag, null, s.typeLabel ),
			el( 'span', { className: 'ekwa-sa-src__title' }, s.title ),
			'publish' !== s.status && s.status ? el( Tag, { tone: 'warn' }, s.status ) : null,
			s.file ? el( Tag, { title: __( 'Not edited in the Site Editor yet — this is the theme file. Saving in the Site Editor keeps your change.', 'ekwa' ) }, __( 'theme file', 'ekwa' ) ) : null,
			s.edit ? el( 'a', { className: 'ekwa-sa-src__edit', href: s.edit, target: '_blank', rel: 'noopener noreferrer' }, __( 'Edit ↗', 'ekwa' ) ) : null,
			s.view ? el( 'a', { className: 'ekwa-sa-src__view', href: s.view, target: '_blank', rel: 'noopener noreferrer' }, __( 'View ↗', 'ekwa' ) ) : null
		);
	}

	function Progress( props ) {
		var p = props.progress;
		if ( ! p ) { return null; }
		return el( 'div', { className: 'ekwa-pad-progress', 'aria-live': 'polite' },
			el( 'div', { className: 'ekwa-pad-progress__bar', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': p.total, 'aria-valuenow': p.done },
				el( 'div', { style: { width: ( p.total ? ( p.done / p.total ) * 100 : 0 ) + '%' } } ) ),
			el( 'span', null, p.finished
				? ( p.stopped ? sprintf( __( 'Stopped: %1$d of %2$d done.', 'ekwa' ), p.done, p.total ) : sprintf( __( 'Done: %1$d of %2$d.', 'ekwa' ), p.done, p.total ) ) + ( p.failed ? ' ' + sprintf( __( '%d could not run.', 'ekwa' ), p.failed ) : '' )
				: sprintf( props.runningText || __( '%1$d of %2$d done…', 'ekwa' ), p.done, p.total ) )
		);
	}

	function Loading( props ) {
		return el( 'p', null, el( Spinner, null ), ' ', props.text || __( 'Reading the site…', 'ekwa' ) );
	}

	function Message( props ) {
		var m = props.message;
		if ( ! m ) { return null; }
		return el( Notice, { status: m.type, isDismissible: true, onRemove: props.onRemove, className: 'ekwa-pad-notice' }, m.text );
	}

	function SearchBox( props ) {
		return el( 'input', {
			type: 'search', className: 'ekwa-pad-search', placeholder: props.placeholder, 'aria-label': props.placeholder,
			value: props.value, onChange: function ( e ) { props.onChange( e.target.value ); },
		} );
	}

	function matches( q, parts ) {
		if ( ! q ) { return true; }
		return -1 !== parts.join( ' ' ).toLowerCase().indexOf( q );
	}

	// ═══════════════════════════════════════════════════════════════════════
	// CONTENT & SEO
	// ═══════════════════════════════════════════════════════════════════════

	function hideReason( i ) {
		if ( 'policy' === i.kind || 'thank-you' === i.kind ) { return i.kind; }
		if ( i.soon ) { return 'soon'; }
		if ( i.empty ) { return 'empty'; }
		return '';
	}

	function ContentView() {
		var _data   = useState( null );
		var data    = _data[ 0 ];
		var _err    = useState( '' );
		var _filter = useState( store( 'cfilter' ) || 'nocontent' );
		var filter  = _filter[ 0 ];
		var _type   = useState( store( 'ctype' ) || 'page' );
		var type    = _type[ 0 ];
		var _q      = useState( '' );
		var _busy   = useState( {} );
		var _msg    = useState( null );
		var _conf   = useState( false );

		useEffect( function () {
			apiFetch( { path: '/ekwa/v1/site-audit/content' } )
				.then( function ( res ) { _data[ 1 ]( res ); } )
				.catch( function ( e ) { _err[ 1 ]( errText( e ) ); } );
		}, [] );

		if ( _err[ 0 ] ) { return el( Notice, { status: 'error', isDismissible: false }, _err[ 0 ] ); }
		if ( ! data ) { return el( Loading, { text: __( 'Reading pages and posts…', 'ekwa' ) } ); }

		var yoast = !! data.yoast;
		var types = data.types || {};
		var kinds = data.kinds || {};
		var all   = data.items || [];

		function noContent( i ) { return 'blog' !== i.kind && ( i.empty || i.soon ); }
		function shouldHide( i ) { return yoast && ! i.hidden && ! i.front && 'blog' !== i.kind && '' !== hideReason( i ); }

		var tests = {
			all:        function () { return true; },
			nocontent:  noContent,
			notitle:    function ( i ) { return yoast && ! i.seoTitle; },
			nodesc:     function ( i ) { return yoast && ! i.seoDesc; },
			shouldhide: shouldHide,
			hidden:     function ( i ) { return yoast && i.hidden; },
		};

		var inType = all.filter( function ( i ) { return 'all' === type || i.type === type; } );
		var q      = _q[ 0 ].trim().toLowerCase();
		var test   = tests[ filter ] || tests.all;
		var rows   = inType.filter( function ( i ) { return test( i ) && matches( q, [ i.title, i.url ] ); } );
		var count  = function ( k ) { return inType.filter( tests[ k ] ).length; };

		function patch( id, changes ) {
			_data[ 1 ]( function ( d ) {
				return assign( {}, d, { items: d.items.map( function ( i ) { return i.id === id ? assign( {}, i, changes ) : i; } ) } );
			} );
		}

		function setHidden( list, hide ) {
			var byReason = {};
			list.forEach( function ( i ) {
				var r = hide ? ( hideReason( i ) || 'manual' ) : 'manual';
				( byReason[ r ] = byReason[ r ] || [] ).push( i.id );
			} );
			var busy = {};
			list.forEach( function ( i ) { busy[ i.id ] = true; } );
			_busy[ 1 ]( function ( b ) { return assign( {}, b, busy ); } );
			var done = 0;
			var failed = 0;
			return Promise.all( Object.keys( byReason ).map( function ( reason ) {
				return apiFetch( { path: '/ekwa/v1/site-audit/visibility', method: 'POST', data: { ids: byReason[ reason ], hidden: hide, reason: reason } } )
					.then( function ( res ) {
						Object.keys( res.results || {} ).forEach( function ( id ) {
							var r = res.results[ id ];
							if ( 'forbidden' === r.result || 'missing' === r.result ) { failed++; return; }
							done++;
							patch( parseInt( id, 10 ), { hidden: !! r.hidden, noindex: r.noindex, hiddenBy: r.hiddenBy || null } );
						} );
					} );
			} ) ).then( function () {
				_msg[ 1 ]( {
					type: failed ? 'warning' : 'success',
					text: ( hide
						? sprintf( _n( '%d page is now hidden from search engines (Yoast: “Allow search engines to show this page” → No).', '%d pages are now hidden from search engines (Yoast: “Allow search engines to show this page” → No).', done, 'ekwa' ), done )
						: sprintf( _n( '%d page can show in search results again.', '%d pages can show in search results again.', done, 'ekwa' ), done ) )
						+ ( failed ? ' ' + sprintf( __( '%d could not be changed (no permission).', 'ekwa' ), failed ) : '' ),
				} );
			}, function ( e ) {
				_msg[ 1 ]( { type: 'error', text: errText( e ) } );
			} ).then( function () {
				_busy[ 1 ]( function ( b ) {
					var n = assign( {}, b );
					list.forEach( function ( i ) { delete n[ i.id ]; } );
					return n;
				} );
			} );
		}

		var hideable = rows.filter( function ( i ) { return shouldHide( i ) && i.canHide; } );
		var typeKeys = Object.keys( types );

		var filterHelp = {
			all:        '',
			nocontent:  __( 'No text (beyond the title and banner) and no images, forms or other content blocks — or the text says “coming soon”.', 'ekwa' ),
			notitle:    __( 'No SEO title set in Yoast — Google gets the default template instead.', 'ekwa' ),
			nodesc:     __( 'No meta description set in Yoast — Google picks some text from the page.', 'ekwa' ),
			shouldhide: __( 'Policy pages, thank-you pages (where a form sends people after submitting) and pages without content that search engines may still show.', 'ekwa' ),
			hidden:     __( 'Pages search engines are told not to show (Yoast → Advanced).', 'ekwa' ),
		};

		return el( 'div', null,
			! yoast ? el( Notice, { status: 'warning', isDismissible: false, className: 'ekwa-pad-notice' },
				__( 'Yoast SEO is not active. Pages without content are still listed; SEO titles, meta descriptions and hiding pages from search engines need Yoast.', 'ekwa' ) ) : null,
			! CFG.blogPublic ? el( Notice, { status: 'info', isDismissible: false, className: 'ekwa-pad-notice' },
				__( 'The whole site currently asks search engines not to index it (Settings → Reading — normal on the test server). The per-page settings below are what stay in effect once the site goes live.', 'ekwa' ), ' ',
				el( 'a', { href: CFG.readingUrl }, __( 'Reading settings', 'ekwa' ) ) ) : null,
			el( Message, { message: _msg[ 0 ], onRemove: function () { _msg[ 1 ]( null ); } } ),

			el( 'section', { className: 'ekwa-pad-card' },
				el( 'div', { className: 'ekwa-sa-toolbar' },
					el( 'label', null, __( 'Show', 'ekwa' ) + ' ',
						el( 'select', { value: type, onChange: function ( e ) { _type[ 1 ]( e.target.value ); store( 'ctype', e.target.value ); } },
							typeKeys.map( function ( t ) {
								var n = all.filter( function ( i ) { return i.type === t; } ).length;
								return n ? el( 'option', { key: t, value: t }, types[ t ].label + ' (' + n + ')' ) : null;
							} ),
							el( 'option', { value: 'all' }, __( 'Everything', 'ekwa' ) + ' (' + all.length + ')' ) ) ),
					el( SearchBox, { value: _q[ 0 ], onChange: _q[ 1 ], placeholder: __( 'Search title or URL…', 'ekwa' ) } )
				),
				el( Chips, { label: __( 'Filter', 'ekwa' ), value: filter, onChange: function ( v ) { _filter[ 1 ]( v ); store( 'cfilter', v ); _conf[ 1 ]( false ); }, options: [
					{ key: 'nocontent', label: __( 'No content / coming soon', 'ekwa' ), count: count( 'nocontent' ), tone: 'warn' },
					{ key: 'notitle', label: __( 'No SEO title', 'ekwa' ), count: yoast ? count( 'notitle' ) : null, tone: 'warn' },
					{ key: 'nodesc', label: __( 'No meta description', 'ekwa' ), count: yoast ? count( 'nodesc' ) : null, tone: 'warn' },
					{ key: 'shouldhide', label: __( 'Should be hidden from search', 'ekwa' ), count: yoast ? count( 'shouldhide' ) : null, tone: 'warn' },
					{ key: 'hidden', label: __( 'Hidden from search', 'ekwa' ), count: yoast ? count( 'hidden' ) : null },
					{ key: 'all', label: __( 'All', 'ekwa' ), count: inType.length },
				] } ),
				filterHelp[ filter ] ? el( 'p', { className: 'ekwa-pad-muted ekwa-sa-help' }, filterHelp[ filter ] ) : null,

				el( 'div', { className: 'ekwa-sa-actions' },
					el( CopyButton, {
						text: rows.map( function ( i ) { return i.url; } ).join( '\n' ),
						label: sprintf( _n( 'Copy %d link', 'Copy %d links', rows.length, 'ekwa' ), rows.length ),
					} ),
					el( CopyButton, {
						variant: 'tertiary',
						text: rows.map( function ( i ) { return i.title + '\t' + i.url; } ).join( '\n' ),
						label: __( 'Copy with titles', 'ekwa' ),
					} ),
					'shouldhide' === filter && hideable.length ? el( Button, { variant: 'primary', onClick: function () { _conf[ 1 ]( true ); } },
						sprintf( _n( 'Hide %d page from search engines', 'Hide %d pages from search engines', hideable.length, 'ekwa' ), hideable.length ) ) : null,
					el( 'span', { className: 'ekwa-pad-muted' }, sprintf( __( '%d shown', 'ekwa' ), rows.length ) )
				),
				_conf[ 0 ] ? el( 'div', { className: 'ekwa-pad-confirm', role: 'alert' },
					el( 'span', null, sprintf( __( 'Set Yoast’s “Allow search engines to show this page in search results?” to No on %d pages. Each can be shown again with one click here, or from its editor.', 'ekwa' ), hideable.length ) ),
					el( Button, { variant: 'primary', onClick: function () { _conf[ 1 ]( false ); setHidden( hideable, true ); } }, __( 'Hide them', 'ekwa' ) ),
					el( Button, { variant: 'tertiary', onClick: function () { _conf[ 1 ]( false ); } }, __( 'Cancel', 'ekwa' ) )
				) : null,

				el( 'table', { className: 'widefat ekwa-pad-table ekwa-sa-table' },
					el( 'thead', null, el( 'tr', null,
						el( 'th', { scope: 'col' }, __( 'Page', 'ekwa' ) ),
						el( 'th', { scope: 'col' }, __( 'Content', 'ekwa' ) ),
						el( 'th', { scope: 'col' }, __( 'SEO title', 'ekwa' ) ),
						el( 'th', { scope: 'col' }, __( 'Meta description', 'ekwa' ) ),
						el( 'th', { scope: 'col' }, __( 'Search engines', 'ekwa' ) )
					) ),
					el( 'tbody', null, rows.length ? rows.map( function ( i ) {
						return el( ContentRow, { key: i.id, item: i, yoast: yoast, types: types, kinds: kinds, busy: !! _busy[ 0 ][ i.id ], shouldHide: shouldHide( i ), onHide: function ( hide ) { setHidden( [ i ], hide ); } } );
					} ) : el( 'tr', null, el( 'td', { colSpan: 5, className: 'ekwa-pad-muted' }, __( 'Nothing here — all good.', 'ekwa' ) ) ) )
				)
			)
		);
	}

	function ContentRow( props ) {
		var i     = props.item;
		var t     = props.types[ i.type ] || {};
		var yoast = props.yoast;
		var len   = ( i.seoDesc || '' ).length;

		var content = 'blog' === i.kind
			? el( 'span', { className: 'ekwa-pad-muted' }, __( 'Lists the posts', 'ekwa' ) )
			: ( i.soon ? el( Badge, { tone: 'warn' }, __( 'Coming soon', 'ekwa' ) )
				: ( i.empty ? el( Badge, { tone: 'bad' }, __( 'No content', 'ekwa' ) )
					: el( 'span', { className: 'ekwa-pad-muted' }, sprintf( _n( '%d word', '%d words', i.words, 'ekwa' ), i.words ) ) ) );

		var search;
		if ( ! yoast ) {
			search = el( 'span', { className: 'ekwa-pad-muted' }, '—' );
		} else if ( i.hidden ) {
			search = el( 'div', { className: 'ekwa-sa-vis' },
				el( Badge, { tone: 'info' }, '1' === i.noindex ? __( 'Hidden', 'ekwa' ) : sprintf( __( 'Hidden (default for %s)', 'ekwa' ), t.label || i.type ) ),
				i.hiddenBy ? el( 'div', { className: 'ekwa-pad-muted' }, sprintf( __( 'by Site Audit %s', 'ekwa' ), ago( i.hiddenBy.t ) ) ) : null,
				i.canHide ? el( Button, { variant: 'link', disabled: props.busy, onClick: function () { props.onHide( false ); } }, __( 'Show in search results', 'ekwa' ) ) : null
			);
		} else {
			search = el( 'div', { className: 'ekwa-sa-vis' },
				el( Badge, { tone: props.shouldHide ? 'warn' : 'ok' }, __( 'Shown', 'ekwa' ) ),
				props.shouldHide ? el( 'div', { className: 'ekwa-pad-muted' }, __( 'Should be hidden', 'ekwa' ) ) : null,
				i.canHide && ! i.front ? el( Button, { variant: 'link', disabled: props.busy, onClick: function () { props.onHide( true ); } }, __( 'Hide from search', 'ekwa' ) ) : null
			);
		}

		return el( 'tr', { className: 'ekwa-sa-row' + ( props.shouldHide ? ' is-warn' : '' ) },
			el( 'td', { className: 'ekwa-sa-col-title' },
				el( 'a', { href: i.url, target: '_blank', rel: 'noopener noreferrer', className: 'ekwa-pad-title__link' }, i.title ),
				i.front ? el( Tag, null, __( 'Front page', 'ekwa' ) ) : null,
				i.kind ? el( Tag, null, props.kinds[ i.kind ] || i.kind ) : null,
				el( 'div', { className: 'ekwa-pad-row-links' },
					i.edit ? el( 'a', { href: i.edit }, __( 'Edit', 'ekwa' ) ) : null,
					el( 'span', { className: 'ekwa-pad-url' }, shortUrl( i.url ) )
				)
			),
			el( 'td', null, content ),
			el( 'td', { className: 'ekwa-sa-col-seo' }, ! yoast ? '—' : ( i.seoTitle
				? el( 'span', { title: i.seoTitle }, i.seoTitle )
				: el( Fragment, null, el( Badge, { tone: 'warn' }, __( 'Not set', 'ekwa' ) ), t.title ? el( 'div', { className: 'ekwa-pad-muted' }, sprintf( __( 'Uses: %s', 'ekwa' ), t.title ) ) : null ) ) ),
			el( 'td', { className: 'ekwa-sa-col-seo' }, ! yoast ? '—' : ( i.seoDesc
				? el( Fragment, null, el( 'span', { title: i.seoDesc }, i.seoDesc ), el( 'div', { className: 'ekwa-pad-muted' + ( len > 160 || len < 70 ? ' ekwa-sa-len-warn' : '' ) }, sprintf( __( '%d characters', 'ekwa' ), len ) ) )
				: el( Badge, { tone: 'warn' }, __( 'Not set', 'ekwa' ) ) ) ),
			el( 'td', { className: 'ekwa-sa-col-vis' }, props.busy ? el( Spinner, null ) : search )
		);
	}

	// ═══════════════════════════════════════════════════════════════════════
	// BROKEN LINKS
	// ═══════════════════════════════════════════════════════════════════════

	var LINK_STATES = {
		broken:   [ __( 'Broken', 'ekwa' ), 'broken' ],
		warn:     [ __( 'Check by hand', 'ekwa' ), 'warn' ],
		redirect: [ __( 'Redirects', 'ekwa' ), 'redirect' ],
		ok:       [ __( 'OK', 'ekwa' ), 'ok' ],
		none:     [ __( 'Not checked', 'ekwa' ), 'none' ],
	};

	function LinksView() {
		var _data    = useState( null );
		var data     = _data[ 0 ];
		var _err     = useState( '' );
		var _results = useState( {} );
		var results  = _results[ 0 ];
		var _filter  = useState( store( 'lfilter' ) || 'problems' );
		var filter   = _filter[ 0 ];
		var _kind    = useState( 'all' );
		var _where   = useState( 'all' );
		var _q       = useState( '' );
		var _prog    = useState( null );
		var _running = useState( {} );
		var _msg     = useState( null );
		var runner   = useRef( null );

		useEffect( function () {
			apiFetch( { path: '/ekwa/v1/site-audit/links' } )
				.then( function ( res ) {
					_data[ 1 ]( res );
					_results[ 1 ]( res.results || {} );
				} )
				.catch( function ( e ) { _err[ 1 ]( errText( e ) ); } );
		}, [] );

		useEffect( function () {
			var running = _prog[ 0 ] && ! _prog[ 0 ].finished;
			if ( ! running ) { return undefined; }
			var fn = function ( e ) { e.preventDefault(); e.returnValue = ''; return ''; };
			window.addEventListener( 'beforeunload', fn );
			return function () { window.removeEventListener( 'beforeunload', fn ); };
		}, [ _prog[ 0 ] && _prog[ 0 ].finished ] );

		if ( _err[ 0 ] ) { return el( Notice, { status: 'error', isDismissible: false }, _err[ 0 ] ); }
		if ( ! data ) { return el( Loading, { text: __( 'Collecting every link on the site…', 'ekwa' ) } ); }

		var links   = data.links || [];
		var sources = data.sources || {};

		function stateOf( l ) {
			if ( l.pre ) { return l.pre.s; }
			var r = results[ l.h ];
			return r ? r.s : 'none';
		}
		function infoOf( l ) {
			if ( l.pre ) { return l.pre; }
			return results[ l.h ] || null;
		}

		function check( list ) {
			var tasks = [];
			for ( var i = 0; i < list.length; i += 10 ) { tasks.push( list.slice( i, i + 10 ) ); }
			if ( ! tasks.length ) { _msg[ 1 ]( { type: 'info', text: __( 'Nothing to check.', 'ekwa' ) } ); return; }
			_msg[ 1 ]( null );
			_prog[ 1 ]( { total: list.length, done: 0, failed: 0 } );
			var busy = {};
			list.forEach( function ( l ) { busy[ l.h ] = true; } );
			_running[ 1 ]( busy );
			runner.current = runQueue( tasks, function ( batch ) {
				return apiFetch( { path: '/ekwa/v1/site-audit/links/check', method: 'POST', data: { urls: batch.map( function ( l ) { return l.u; } ) } } );
			}, 2, {
				onEach: function ( batch, res, err ) {
					if ( res && res.results ) {
						_results[ 1 ]( function ( r ) { return assign( {}, r, res.results ); } );
					}
					_running[ 1 ]( function ( m ) {
						var n = assign( {}, m );
						batch.forEach( function ( l ) { delete n[ l.h ]; } );
						return n;
					} );
					_prog[ 1 ]( function ( p ) { return p ? assign( {}, p, { done: p.done + batch.length, failed: p.failed + ( err ? batch.length : 0 ) } ) : p; } );
					if ( err ) { _msg[ 1 ]( { type: 'error', text: errText( err ) } ); }
				},
				onDone: function ( stopped ) {
					_running[ 1 ]( {} );
					_prog[ 1 ]( function ( p ) { return p ? assign( {}, p, { finished: true, stopped: stopped } ) : p; } );
				},
			} );
		}

		var checkable = links.filter( function ( l ) { return ! l.pre && 'empty' !== l.h; } );
		var unchecked = checkable.filter( function ( l ) { return ! results[ l.h ]; } );

		var q = _q[ 0 ].trim().toLowerCase();
		var pool = links.filter( function ( l ) {
			if ( 'all' !== _kind[ 0 ] && l.k !== _kind[ 0 ] ) { return false; }
			if ( 'internal' === _where[ 0 ] && ! l.internal ) { return false; }
			if ( 'external' === _where[ 0 ] && l.internal ) { return false; }
			return true;
		} );
		var counts = { problems: 0, broken: 0, warn: 0, redirect: 0, ok: 0, none: 0 };
		pool.forEach( function ( l ) {
			var s = stateOf( l );
			counts[ s ] = ( counts[ s ] || 0 ) + 1;
			if ( 'broken' === s || 'warn' === s ) { counts.problems++; }
		} );
		var order = { broken: 0, warn: 1, redirect: 2, none: 3, ok: 4 };
		var rows = pool.filter( function ( l ) {
			var s = stateOf( l );
			if ( 'problems' === filter && 'broken' !== s && 'warn' !== s ) { return false; }
			if ( 'problems' !== filter && 'all' !== filter && s !== filter ) { return false; }
			if ( q ) {
				var hay = [ l.u ];
				( l.occ || [] ).forEach( function ( o ) { hay.push( o.t ); if ( sources[ o.s ] ) { hay.push( sources[ o.s ].title ); } } );
				if ( ! matches( q, hay ) ) { return false; }
			}
			return true;
		} ).sort( function ( a, b ) { return order[ stateOf( a ) ] - order[ stateOf( b ) ]; } );

		var prog    = _prog[ 0 ];
		var running = prog && ! prog.finished;

		return el( 'div', null,
			el( 'section', { className: 'ekwa-pad-card ekwa-pad-run' },
				el( 'h2', null, __( 'Check links', 'ekwa' ) ),
				el( 'p', { className: 'ekwa-pad-muted' },
					sprintf( __( '%1$d links and images in %2$d places: pages, posts, every post type, templates, template parts (header, footer…), synced patterns, menus and the appointment / social links in Ekwa Settings.', 'ekwa' ), links.length, Object.keys( sources ).length ),
					data.checked ? ' ' + sprintf( __( 'Last check: %s.', 'ekwa' ), ago( data.checked ) ) : ''
				),
				el( 'div', { className: 'ekwa-pad-run__row' },
					el( Button, { variant: 'primary', disabled: running || ! unchecked.length, onClick: function () { check( unchecked ); } },
						unchecked.length
							? sprintf( _n( 'Check %d unchecked link', 'Check %d unchecked links', unchecked.length, 'ekwa' ), unchecked.length )
							: __( 'Every link is checked', 'ekwa' ) ),
					el( Button, { variant: 'secondary', disabled: running || ! checkable.length, onClick: function () { check( checkable ); } },
						sprintf( __( 'Check all %d again', 'ekwa' ), checkable.length ) ),
					running ? el( Button, { variant: 'secondary', isDestructive: true, onClick: function () { if ( runner.current ) { runner.current.stop(); } } }, __( 'Stop', 'ekwa' ) ) : null
				),
				el( Progress, { progress: prog, runningText: __( '%1$d of %2$d links checked…', 'ekwa' ) } ),
				el( Message, { message: _msg[ 0 ], onRemove: function () { _msg[ 1 ]( null ); } } )
			),

			el( 'section', { className: 'ekwa-pad-card' },
				el( 'div', { className: 'ekwa-sa-toolbar' },
					el( 'label', null, __( 'Show', 'ekwa' ) + ' ',
						el( 'select', { value: _kind[ 0 ], onChange: function ( e ) { _kind[ 1 ]( e.target.value ); } },
							el( 'option', { value: 'all' }, __( 'Links and images', 'ekwa' ) ),
							el( 'option', { value: 'link' }, __( 'Links', 'ekwa' ) ),
							el( 'option', { value: 'image' }, __( 'Images', 'ekwa' ) ) ) ),
					el( 'label', null, __( 'to', 'ekwa' ) + ' ',
						el( 'select', { value: _where[ 0 ], onChange: function ( e ) { _where[ 1 ]( e.target.value ); } },
							el( 'option', { value: 'all' }, __( 'anywhere', 'ekwa' ) ),
							el( 'option', { value: 'internal' }, __( 'this site', 'ekwa' ) ),
							el( 'option', { value: 'external' }, __( 'other sites', 'ekwa' ) ) ) ),
					el( SearchBox, { value: _q[ 0 ], onChange: _q[ 1 ], placeholder: __( 'Search URL, link text or page…', 'ekwa' ) } )
				),
				el( Chips, { label: __( 'Filter', 'ekwa' ), value: filter, onChange: function ( v ) { _filter[ 1 ]( v ); store( 'lfilter', v ); }, options: [
					{ key: 'problems', label: __( 'Problems', 'ekwa' ), count: counts.problems, tone: 'warn' },
					{ key: 'broken', label: LINK_STATES.broken[ 0 ], count: counts.broken },
					{ key: 'warn', label: LINK_STATES.warn[ 0 ], count: counts.warn },
					{ key: 'redirect', label: LINK_STATES.redirect[ 0 ], count: counts.redirect },
					{ key: 'none', label: LINK_STATES.none[ 0 ], count: counts.none },
					{ key: 'ok', label: LINK_STATES.ok[ 0 ], count: counts.ok },
					{ key: 'all', label: __( 'All', 'ekwa' ), count: pool.length },
				] } ),
				el( 'div', { className: 'ekwa-sa-actions' },
					el( CopyButton, { text: rows.map( function ( l ) { return l.u; } ).join( '\n' ), label: sprintf( _n( 'Copy %d URL', 'Copy %d URLs', rows.length, 'ekwa' ), rows.length ) } ),
					el( 'span', { className: 'ekwa-pad-muted' }, sprintf( __( '%d shown', 'ekwa' ), rows.length ) )
				),
				el( 'ul', { className: 'ekwa-sa-list' }, rows.length ? rows.map( function ( l ) {
					return el( LinkRow, {
						key: l.h, link: l, state: stateOf( l ), info: infoOf( l ), sources: sources, busy: !! _running[ 0 ][ l.h ],
						onRecheck: running ? null : function () { check( [ l ] ); },
					} );
				} ) : el( 'li', { className: 'ekwa-pad-muted' }, 'problems' === filter && unchecked.length ? __( 'No problems among the links checked so far.', 'ekwa' ) : __( 'Nothing here.', 'ekwa' ) ) )
			)
		);
	}

	function LinkRow( props ) {
		var l     = props.link;
		var st    = LINK_STATES[ props.state ] || LINK_STATES.none;
		var info  = props.info;
		var _open = useState( false );
		var occ   = l.occ || [];
		var show  = _open[ 0 ] ? occ : occ.slice( 0, 3 );
		return el( 'li', { className: 'ekwa-sa-item is-' + props.state },
			el( 'div', { className: 'ekwa-sa-item__head' },
				props.busy ? el( Spinner, null ) : el( Badge, { tone: st[ 1 ] }, st[ 0 ] + ( info && info.c ? ' · ' + info.c : '' ) ),
				'empty' === l.h
					? el( 'code', null, 'href=""' )
					: el( 'a', { className: 'ekwa-sa-url', href: l.u, target: '_blank', rel: 'noopener noreferrer' }, shortUrl( l.u ) ),
				'image' === l.k ? el( Tag, null, __( 'Image', 'ekwa' ) ) : null,
				el( Tag, null, l.internal ? __( 'This site', 'ekwa' ) : __( 'Other site', 'ekwa' ) ),
				props.onRecheck && ! l.pre && 'empty' !== l.h ? el( Button, { variant: 'link', className: 'ekwa-sa-item__act', onClick: props.onRecheck }, __( 'Check again', 'ekwa' ) ) : null
			),
			info && info.e ? el( 'p', { className: 'ekwa-sa-item__why' }, info.e ) : null,
			info && 'redirect' === info.s && info.f ? el( 'p', { className: 'ekwa-sa-item__why' }, __( 'Goes to ', 'ekwa' ), el( 'a', { href: info.f, target: '_blank', rel: 'noopener noreferrer' }, shortUrl( info.f ) ), ' — ', __( 'link straight to it to save a redirect.', 'ekwa' ) ) : null,
			l.sub ? el( 'p', { className: 'ekwa-sa-item__why is-hint' },
				__( 'This link starts with “/”, so it skips the folder this site is in. It works only once the site is at the root of its domain; on this server the address would be ', 'ekwa' ),
				el( 'a', { href: l.alt, target: '_blank', rel: 'noopener noreferrer' }, shortUrl( l.alt ) ),
				__( '. Picking the page in the link settings (instead of typing the address) works everywhere.', 'ekwa' ) ) : null,
			el( 'ul', { className: 'ekwa-sa-occ' },
				show.map( function ( o, n ) {
					return el( 'li', { key: n },
						el( SourceLine, { source: props.sources[ o.s ] } ),
						o.t ? el( 'span', { className: 'ekwa-sa-occ__text' }, '“' + o.t + '”' ) : null,
						o.b ? el( 'span', { className: 'ekwa-pad-muted' }, ' ' + o.b ) : null
					);
				} ),
				occ.length > 3 && ! _open[ 0 ] ? el( 'li', null, el( Button, { variant: 'link', onClick: function () { _open[ 1 ]( true ); } }, sprintf( __( 'Show all %d places', 'ekwa' ), occ.length ) ) ) : null,
				l.n > occ.length && _open[ 0 ] ? el( 'li', { className: 'ekwa-pad-muted' }, sprintf( __( '…and %d more times.', 'ekwa' ), l.n - occ.length ) ) : null
			)
		);
	}

	// ═══════════════════════════════════════════════════════════════════════
	// IMAGE ALT TEXT
	// ═══════════════════════════════════════════════════════════════════════

	var ALT_STATES = {
		missing:  [ __( 'No alt attribute', 'ekwa' ), 'broken', __( 'Screen readers read the file name; Lighthouse fails “Image elements have [alt] attributes”.', 'ekwa' ) ],
		empty:    [ __( 'Empty alt', 'ekwa' ), 'warn', __( 'alt="" tells screen readers to skip the image — right only for a purely decorative image.', 'ekwa' ) ],
		filename: [ __( 'Looks like a file name', 'ekwa' ), 'warn', __( 'Describe what the image shows instead.', 'ekwa' ) ],
		ok:       [ __( 'Has alt text', 'ekwa' ), 'ok', '' ],
	};

	function ImagesView() {
		var _data   = useState( null );
		var data    = _data[ 0 ];
		var _err    = useState( '' );
		var _filter = useState( store( 'ifilter' ) || 'problems' );
		var filter  = _filter[ 0 ];
		var _q      = useState( '' );

		useEffect( function () {
			apiFetch( { path: '/ekwa/v1/site-audit/images' } )
				.then( function ( res ) { _data[ 1 ]( res ); } )
				.catch( function ( e ) { _err[ 1 ]( errText( e ) ); } );
		}, [] );

		if ( _err[ 0 ] ) { return el( Notice, { status: 'error', isDismissible: false }, _err[ 0 ] ); }
		if ( ! data ) { return el( Loading, { text: __( 'Finding every image on the site…', 'ekwa' ) } ); }

		var images  = data.images || [];
		var sources = data.sources || {};
		var lib     = data.library || { items: [], total: 0 };
		var count   = function ( s ) { return images.filter( function ( i ) { return i.state === s; } ).length; };
		var probs   = images.filter( function ( i ) { return 'ok' !== i.state; } ).length;
		var q       = _q[ 0 ].trim().toLowerCase();

		var rows = images.filter( function ( i ) {
			if ( 'problems' === filter && 'ok' === i.state ) { return false; }
			if ( 'problems' !== filter && 'all' !== filter && i.state !== filter ) { return false; }
			var s = sources[ i.s ];
			return matches( q, [ i.src, i.alt || '', s ? s.title : '' ] );
		} );

		return el( 'div', null,
			el( 'section', { className: 'ekwa-pad-card' },
				el( 'p', { className: 'ekwa-pad-muted ekwa-sa-help' },
					sprintf( __( '%1$d images in pages, posts, every post type, templates, template parts and synced patterns (core Image and Gallery blocks, Ekwa Image blocks, HTML and classic content). Background images are decorative and not listed. Fix an image in its editor — the Edit link opens it.', 'ekwa' ), images.length ) ),
				el( 'div', { className: 'ekwa-sa-toolbar' },
					el( SearchBox, { value: _q[ 0 ], onChange: _q[ 1 ], placeholder: __( 'Search file, alt text or page…', 'ekwa' ) } )
				),
				el( Chips, { label: __( 'Filter', 'ekwa' ), value: filter, onChange: function ( v ) { _filter[ 1 ]( v ); store( 'ifilter', v ); }, options: [
					{ key: 'problems', label: __( 'Need alt text', 'ekwa' ), count: probs, tone: 'warn' },
					{ key: 'missing', label: ALT_STATES.missing[ 0 ], count: count( 'missing' ) },
					{ key: 'empty', label: ALT_STATES.empty[ 0 ], count: count( 'empty' ) },
					{ key: 'filename', label: ALT_STATES.filename[ 0 ], count: count( 'filename' ) },
					{ key: 'ok', label: ALT_STATES.ok[ 0 ], count: count( 'ok' ) },
					{ key: 'library', label: __( 'Media library without alt', 'ekwa' ), count: lib.total, tone: lib.total ? 'warn' : '' },
				] } ),
				'library' === filter
					? el( LibraryList, { library: lib, q: q } )
					: el( Fragment, null,
						ALT_STATES[ filter ] && ALT_STATES[ filter ][ 2 ] ? el( 'p', { className: 'ekwa-pad-muted ekwa-sa-help' }, ALT_STATES[ filter ][ 2 ] ) : null,
						el( 'div', { className: 'ekwa-sa-actions' },
							el( CopyButton, {
								text: uniq( rows.map( function ( i ) { var s = sources[ i.s ]; return s && s.view ? s.view : ( s ? s.title : '' ); } ) ).join( '\n' ),
								label: __( 'Copy the pages', 'ekwa' ),
							} ),
							el( 'span', { className: 'ekwa-pad-muted' }, sprintf( __( '%d shown', 'ekwa' ), rows.length ) )
						),
						el( 'ul', { className: 'ekwa-sa-list ekwa-sa-images' }, rows.length ? rows.map( function ( i, n ) {
							var st = ALT_STATES[ i.state ] || ALT_STATES.ok;
							return el( 'li', { key: n, className: 'ekwa-sa-item ekwa-sa-img is-' + i.state },
								el( 'a', { className: 'ekwa-sa-img__thumb', href: i.src, target: '_blank', rel: 'noopener noreferrer' },
									el( 'img', { src: i.src, alt: '', loading: 'lazy', decoding: 'async' } ) ),
								el( 'div', { className: 'ekwa-sa-img__body' },
									el( 'div', { className: 'ekwa-sa-item__head' },
										el( Badge, { tone: st[ 1 ] }, st[ 0 ] ),
										'ok' === i.state || 'filename' === i.state ? el( 'span', { className: 'ekwa-sa-alt' }, '“' + i.alt + '”' ) : null,
										el( 'span', { className: 'ekwa-pad-muted' }, i.b )
									),
									el( 'div', { className: 'ekwa-sa-url ekwa-pad-muted' }, shortUrl( i.src ) ),
									i.libAlt && 'ok' !== i.state ? el( 'p', { className: 'ekwa-sa-item__why is-hint' }, __( 'The media library has alt text for this image: ', 'ekwa' ), el( 'strong', null, '“' + i.libAlt + '”' ), __( ' — in the editor, select the image and paste it into “Alternative text”.', 'ekwa' ) ) : null,
									el( 'div', { className: 'ekwa-sa-occ' }, el( SourceLine, { source: sources[ i.s ] } ) )
								)
							);
						} ) : el( 'li', { className: 'ekwa-pad-muted' }, __( 'Nothing here — all good.', 'ekwa' ) ) )
					)
			)
		);
	}

	function uniq( list ) {
		var seen = {};
		return list.filter( function ( v ) { if ( ! v || seen[ v ] ) { return false; } seen[ v ] = true; return true; } );
	}

	function LibraryList( props ) {
		var lib   = props.library;
		var _done = useState( {} );
		var items = lib.items.filter( function ( i ) { return matches( props.q, [ i.title, i.full ] ); } );
		return el( Fragment, null,
			el( 'p', { className: 'ekwa-pad-muted ekwa-sa-help' },
				__( 'This alt text is used wherever WordPress takes it from the library: featured images, the site logo, and every image inserted from now on. Images already placed in content keep the alt they were inserted with (fix those in the other filters).', 'ekwa' ),
				lib.total > lib.items.length ? ' ' + sprintf( __( 'Showing the first %1$d of %2$d.', 'ekwa' ), lib.items.length, lib.total ) : '' ),
			el( 'ul', { className: 'ekwa-sa-list ekwa-sa-images' }, items.length ? items.map( function ( i ) {
				return el( LibraryItem, { key: i.id, item: i, saved: _done[ 0 ][ i.id ], onSaved: function ( alt ) { _done[ 1 ]( function ( d ) { var n = assign( {}, d ); n[ i.id ] = alt; return n; } ); } } );
			} ) : el( 'li', { className: 'ekwa-pad-muted' }, __( 'Every image in the media library has alt text.', 'ekwa' ) ) )
		);
	}

	function LibraryItem( props ) {
		var i      = props.item;
		var _alt   = useState( '' );
		var _busy  = useState( '' );
		var _err   = useState( '' );
		var canAi  = CFG.ai;

		function save() {
			var alt = _alt[ 0 ].trim();
			if ( ! alt ) { return; }
			_busy[ 1 ]( 'save' );
			_err[ 1 ]( '' );
			apiFetch( { path: '/wp/v2/media/' + i.id, method: 'POST', data: { alt_text: alt } } )
				.then( function () { props.onSaved( alt ); }, function ( e ) { _err[ 1 ]( errText( e ) ); } )
				.then( function () { _busy[ 1 ]( '' ); } );
		}
		function suggest() {
			_busy[ 1 ]( 'ai' );
			_err[ 1 ]( '' );
			apiFetch( { path: '/ekwa/v1/generate-alt', method: 'POST', data: { attachment_id: i.id, context: i.featured.length ? i.featured[ 0 ].title : '' } } )
				.then( function ( res ) { if ( res && res.alt ) { _alt[ 1 ]( res.alt ); } }, function ( e ) { _err[ 1 ]( errText( e ) ); } )
				.then( function () { _busy[ 1 ]( '' ); } );
		}

		return el( 'li', { className: 'ekwa-sa-item ekwa-sa-img' + ( props.saved ? ' is-ok' : ' is-empty' ) },
			el( 'a', { className: 'ekwa-sa-img__thumb', href: i.full, target: '_blank', rel: 'noopener noreferrer' },
				i.thumb ? el( 'img', { src: i.thumb, alt: '', loading: 'lazy', decoding: 'async' } ) : null ),
			el( 'div', { className: 'ekwa-sa-img__body' },
				el( 'div', { className: 'ekwa-sa-item__head' },
					el( 'strong', null, i.title || ( '#' + i.id ) ),
					i.logo ? el( Tag, { tone: 'warn' }, __( 'Site logo', 'ekwa' ) ) : null,
					i.uses ? el( Tag, { tone: 'warn' }, sprintf( _n( 'Featured image on %d post', 'Featured image on %d posts', i.uses, 'ekwa' ), i.uses ) ) : null,
					i.edit ? el( 'a', { href: i.edit, target: '_blank', rel: 'noopener noreferrer' }, __( 'Edit ↗', 'ekwa' ) ) : null
				),
				i.featured.length ? el( 'div', { className: 'ekwa-pad-muted' }, i.featured.map( function ( f, n ) {
					return el( Fragment, { key: n }, n ? ', ' : '', f.edit ? el( 'a', { href: f.edit, target: '_blank', rel: 'noopener noreferrer' }, f.title ) : f.title );
				} ) ) : null,
				props.saved
					? el( 'p', null, el( Badge, { tone: 'ok' }, __( 'Saved', 'ekwa' ) ), ' “' + props.saved + '”' )
					: ( CFG.canUpload && i.canEdit ? el( 'div', { className: 'ekwa-sa-altform' },
						el( 'input', {
							type: 'text', className: 'regular-text', value: _alt[ 0 ], placeholder: __( 'Describe the image…', 'ekwa' ), 'aria-label': sprintf( __( 'Alt text for %s', 'ekwa' ), i.title || i.id ),
							onChange: function ( e ) { _alt[ 1 ]( e.target.value ); },
							onKeyDown: function ( e ) { if ( 'Enter' === e.key ) { e.preventDefault(); save(); } },
						} ),
						el( Button, { variant: 'primary', size: 'small', disabled: ! _alt[ 0 ].trim() || !! _busy[ 0 ], isBusy: 'save' === _busy[ 0 ], onClick: save }, __( 'Save', 'ekwa' ) ),
						canAi ? el( Button, { variant: 'secondary', size: 'small', disabled: !! _busy[ 0 ], isBusy: 'ai' === _busy[ 0 ], onClick: suggest }, __( 'Suggest with AI', 'ekwa' ) ) : null
					) : null ),
				_err[ 0 ] ? el( 'p', { className: 'ekwa-pad-err' }, _err[ 0 ] ) : null
			)
		);
	}

	// ═══════════════════════════════════════════════════════════════════════
	// SPELLING
	// ═══════════════════════════════════════════════════════════════════════

	var GROUP_ORDER = { content: 0, part: 1, template: 2, pattern: 3, navigation: 4 };

	function Highlight( props ) {
		var text = props.text || '';
		var word = props.word || '';
		if ( ! word ) { return text; }
		var lower = text.toLowerCase();
		var idx   = lower.indexOf( word.toLowerCase() );
		if ( -1 === idx ) { return text; }
		return el( Fragment, null, text.slice( 0, idx ), el( 'mark', null, text.slice( idx, idx + word.length ) ), text.slice( idx + word.length ) );
	}

	function SpellingView() {
		var _data   = useState( null );
		var data    = _data[ 0 ];
		var _err    = useState( '' );
		var _filter = useState( store( 'sfilter' ) || 'issues' );
		var filter  = _filter[ 0 ];
		var _group  = useState( 'all' );
		var _q      = useState( '' );
		var _prog   = useState( null );
		var _running = useState( {} );
		var _msg    = useState( null );
		var _conf   = useState( null );
		var runner  = useRef( null );

		useEffect( function () {
			apiFetch( { path: '/ekwa/v1/site-audit/spelling' } )
				.then( function ( res ) { _data[ 1 ]( res ); } )
				.catch( function ( e ) { _err[ 1 ]( errText( e ) ); } );
		}, [] );

		useEffect( function () {
			var running = _prog[ 0 ] && ! _prog[ 0 ].finished;
			if ( ! running ) { return undefined; }
			var fn = function ( e ) { e.preventDefault(); e.returnValue = ''; return ''; };
			window.addEventListener( 'beforeunload', fn );
			return function () { window.removeEventListener( 'beforeunload', fn ); };
		}, [ _prog[ 0 ] && _prog[ 0 ].finished ] );

		if ( _err[ 0 ] ) { return el( Notice, { status: 'error', isDismissible: false }, _err[ 0 ] ); }
		if ( ! data ) { return el( Loading, { text: __( 'Reading the text of every page…', 'ekwa' ) } ); }

		var dict    = data.dictionary || [];
		var dictMap = {};
		dict.forEach( function ( w ) { dictMap[ w.toLowerCase() ] = true; } );
		var sources = ( data.sources || [] ).slice().sort( function ( a, b ) {
			var g = ( GROUP_ORDER[ a.group ] || 9 ) - ( GROUP_ORDER[ b.group ] || 9 );
			if ( g ) { return g; }
			if ( a.type !== b.type ) { return 'page' === a.type ? -1 : ( 'page' === b.type ? 1 : ( a.type < b.type ? -1 : 1 ) ); }
			return a.title.localeCompare( b.title );
		} );

		function issuesOf( s ) {
			return s.result ? ( s.result.issues || [] ).filter( function ( i ) { return ! dictMap[ String( i.w ).toLowerCase() ]; } ) : [];
		}

		function setResult( key, result ) {
			_data[ 1 ]( function ( d ) {
				return assign( {}, d, { sources: d.sources.map( function ( s ) { return s.key === key ? assign( {}, s, { result: result } ) : s; } ) } );
			} );
		}

		function batches( list ) {
			var out = [];
			var cur = [];
			var size = 0;
			list.forEach( function ( s ) {
				if ( cur.length && ( size + s.chars > 40000 || cur.length >= 8 ) ) { out.push( cur ); cur = []; size = 0; }
				cur.push( s );
				size += s.chars;
			} );
			if ( cur.length ) { out.push( cur ); }
			return out;
		}

		function run( list ) {
			var tasks = batches( list );
			if ( ! tasks.length ) { _msg[ 1 ]( { type: 'info', text: __( 'Nothing to check.', 'ekwa' ) } ); return; }
			_msg[ 1 ]( null );
			_prog[ 1 ]( { total: list.length, done: 0, failed: 0 } );
			var busy = {};
			list.forEach( function ( s ) { busy[ s.key ] = true; } );
			_running[ 1 ]( busy );
			var worker = function ( batch ) {
				return apiFetch( { path: '/ekwa/v1/site-audit/spelling/check', method: 'POST', data: { keys: batch.map( function ( s ) { return s.key; } ) } } )
					.then( function ( res ) {
						var skipped = ( res && res.skipped ) || [];
						if ( ! skipped.length ) { return res; }
						// The server left some for another call (too much text at once).
						return apiFetch( { path: '/ekwa/v1/site-audit/spelling/check', method: 'POST', data: { keys: skipped } } ).then( function ( more ) {
							return { results: assign( {}, res.results, more.results ), skipped: more.skipped || [] };
						} );
					} );
			};
			runner.current = runQueue( tasks, worker, 'mcp' === CFG.aiProvider ? 1 : 2, {
				fatal: aiFatal,
				onEach: function ( batch, res, err ) {
					if ( res && res.results ) {
						Object.keys( res.results ).forEach( function ( k ) { setResult( k, res.results[ k ] ); } );
					}
					_running[ 1 ]( function ( m ) {
						var n = assign( {}, m );
						batch.forEach( function ( s ) { delete n[ s.key ]; } );
						return n;
					} );
					_prog[ 1 ]( function ( p ) { return p ? assign( {}, p, { done: p.done + batch.length, failed: p.failed + ( err ? batch.length : 0 ) } ) : p; } );
					if ( err ) { _msg[ 1 ]( { type: 'error', text: errText( err ) } ); }
				},
				onDone: function ( stopped ) {
					_running[ 1 ]( {} );
					_prog[ 1 ]( function ( p ) { return p ? assign( {}, p, { finished: true, stopped: stopped } ) : p; } );
				},
			} );
		}

		function start( list ) {
			if ( batches( list ).length > 4 ) { _conf[ 1 ]( list ); } else { run( list ); }
		}

		function ignore( word, action ) {
			apiFetch( { path: '/ekwa/v1/site-audit/spelling/dictionary', method: 'POST', data: { word: word, action: action || 'add' } } )
				.then( function ( res ) { _data[ 1 ]( function ( d ) { return assign( {}, d, { dictionary: res.dictionary || [] } ); } ); } )
				.catch( function ( e ) { _msg[ 1 ]( { type: 'error', text: errText( e ) } ); } );
		}

		var groups = {
			all:      __( 'Everything', 'ekwa' ),
			content:  __( 'Pages & posts', 'ekwa' ),
			part:     __( 'Header, footer & other parts', 'ekwa' ),
			template: __( 'Templates', 'ekwa' ),
			pattern:  __( 'Synced patterns', 'ekwa' ),
			navigation: __( 'Navigation menus', 'ekwa' ),
		};
		var inGroup   = sources.filter( function ( s ) { return 'all' === _group[ 0 ] || s.group === _group[ 0 ]; } );
		var unchecked = inGroup.filter( function ( s ) { return ! s.result; } );
		var changed   = inGroup.filter( function ( s ) { return s.result && s.result.stale; } );
		var withIssues = inGroup.filter( function ( s ) { return issuesOf( s ).length; } );
		var totalIssues = withIssues.reduce( function ( a, s ) { return a + issuesOf( s ).length; }, 0 );
		var q = _q[ 0 ].trim().toLowerCase();
		var rows = inGroup.filter( function ( s ) {
			if ( 'issues' === filter && ! issuesOf( s ).length ) { return false; }
			if ( 'unchecked' === filter && s.result ) { return false; }
			if ( 'changed' === filter && ! ( s.result && s.result.stale ) ) { return false; }
			if ( 'clean' === filter && ( ! s.result || issuesOf( s ).length ) ) { return false; }
			return matches( q, [ s.title, s.typeLabel ].concat( issuesOf( s ).map( function ( i ) { return i.w; } ) ) );
		} );
		var prog    = _prog[ 0 ];
		var running = prog && ! prog.finished;

		return el( 'div', null,
			! CFG.ai ? el( Notice, { status: 'warning', isDismissible: false, className: 'ekwa-pad-notice' },
				__( 'The spelling check uses the site’s AI provider, and none is set up for you. ', 'ekwa' ),
				CFG.canManage ? el( 'a', { href: CFG.aiSettingsUrl }, __( 'Set it up in Ekwa Settings → AI', 'ekwa' ) ) : __( 'Ask an administrator to set it up (Ekwa Settings → AI).', 'ekwa' ) ) : null,

			el( 'section', { className: 'ekwa-pad-card ekwa-pad-run' },
				el( 'h2', null, __( 'Check spelling', 'ekwa' ) ),
				el( 'p', { className: 'ekwa-pad-muted' },
					sprintf( __( 'The AI proofreads the text of %d places — pages, posts, Yoast SEO titles and descriptions, header, footer, templates, synced patterns and menus. It is told to leave medical, dental, dermatology and cosmetic terms, brand and drug names, and people and place names alone, and to stay quiet when unsure. Words you ignore are never reported again on this site.', 'ekwa' ), sources.length ) ),
				el( 'div', { className: 'ekwa-pad-run__row' },
					el( 'label', null, __( 'Check', 'ekwa' ) + ' ',
						el( 'select', { value: _group[ 0 ], onChange: function ( e ) { _group[ 1 ]( e.target.value ); _conf[ 1 ]( null ); } },
							Object.keys( groups ).map( function ( g ) {
								var n = 'all' === g ? sources.length : sources.filter( function ( s ) { return s.group === g; } ).length;
								return 'all' === g || n ? el( 'option', { key: g, value: g }, groups[ g ] + ' (' + n + ')' ) : null;
							} ) ) ),
					el( Button, { variant: 'primary', disabled: running || ! CFG.ai || ! unchecked.length, onClick: function () { start( unchecked ); } },
						sprintf( _n( 'Check %d unchecked', 'Check %d unchecked', unchecked.length, 'ekwa' ), unchecked.length ) ),
					changed.length ? el( Button, { variant: 'secondary', disabled: running || ! CFG.ai, onClick: function () { start( changed ); } },
						sprintf( _n( 'Re-check %d changed', 'Re-check %d changed', changed.length, 'ekwa' ), changed.length ) ) : null,
					el( Button, { variant: 'tertiary', disabled: running || ! CFG.ai || ! inGroup.length, onClick: function () { start( inGroup ); } },
						sprintf( __( 'Check all %d again', 'ekwa' ), inGroup.length ) ),
					running ? el( Button, { variant: 'secondary', isDestructive: true, onClick: function () { if ( runner.current ) { runner.current.stop(); } } }, __( 'Stop', 'ekwa' ) ) : null
				),
				_conf[ 0 ] ? el( 'div', { className: 'ekwa-pad-confirm', role: 'alert' },
					el( 'span', null, sprintf( __( '%1$d places in about %2$d AI requests. Keep this tab open; results are saved as they arrive.', 'ekwa' ), _conf[ 0 ].length, batches( _conf[ 0 ] ).length ) ),
					el( Button, { variant: 'primary', onClick: function () { var l = _conf[ 0 ]; _conf[ 1 ]( null ); run( l ); } }, __( 'Start', 'ekwa' ) ),
					el( Button, { variant: 'tertiary', onClick: function () { _conf[ 1 ]( null ); } }, __( 'Cancel', 'ekwa' ) )
				) : null,
				el( Progress, { progress: prog, runningText: __( '%1$d of %2$d places checked…', 'ekwa' ) } ),
				el( Message, { message: _msg[ 0 ], onRemove: function () { _msg[ 1 ]( null ); } } )
			),

			el( 'section', { className: 'ekwa-pad-card' },
				el( 'div', { className: 'ekwa-sa-toolbar' },
					el( SearchBox, { value: _q[ 0 ], onChange: _q[ 1 ], placeholder: __( 'Search page or word…', 'ekwa' ) } )
				),
				el( Chips, { label: __( 'Filter', 'ekwa' ), value: filter, onChange: function ( v ) { _filter[ 1 ]( v ); store( 'sfilter', v ); }, options: [
					{ key: 'issues', label: __( 'Possible mistakes', 'ekwa' ), count: totalIssues, tone: totalIssues ? 'warn' : '' },
					{ key: 'changed', label: __( 'Changed since checked', 'ekwa' ), count: changed.length },
					{ key: 'unchecked', label: __( 'Not checked', 'ekwa' ), count: unchecked.length },
					{ key: 'clean', label: __( 'No mistakes', 'ekwa' ), count: inGroup.filter( function ( s ) { return s.result && ! issuesOf( s ).length; } ).length },
					{ key: 'all', label: __( 'All', 'ekwa' ), count: inGroup.length },
				] } ),
				el( 'ul', { className: 'ekwa-sa-list' }, rows.length ? rows.map( function ( s ) {
					var issues = issuesOf( s );
					return el( 'li', { key: s.key, className: 'ekwa-sa-item' + ( issues.length ? ' is-warn' : '' ) },
						el( 'div', { className: 'ekwa-sa-item__head' },
							_running[ 0 ][ s.key ] ? el( Spinner, null ) : ( s.result
								? ( issues.length ? el( Badge, { tone: 'warn' }, sprintf( _n( '%d possible mistake', '%d possible mistakes', issues.length, 'ekwa' ), issues.length ) ) : el( Badge, { tone: 'ok' }, __( 'No mistakes found', 'ekwa' ) ) )
								: el( Badge, { tone: 'none' }, __( 'Not checked', 'ekwa' ) ) ),
							el( SourceLine, { source: s } ),
							s.result ? el( 'span', { className: 'ekwa-pad-muted' }, ago( s.result.t ) ) : null,
							s.result && s.result.stale ? el( Tag, { tone: 'warn', title: __( 'The text changed after this check.', 'ekwa' ) }, __( 'changed since', 'ekwa' ) ) : null,
							CFG.ai && ! running ? el( Button, { variant: 'link', className: 'ekwa-sa-item__act', onClick: function () { run( [ s ] ); } }, s.result ? __( 'Check again', 'ekwa' ) : __( 'Check', 'ekwa' ) ) : null
						),
						issues.length ? el( 'ul', { className: 'ekwa-sa-words' }, issues.map( function ( i, n ) {
							return el( 'li', { key: n },
								el( 'span', { className: 'ekwa-sa-word' }, el( 'del', null, i.w ), ' → ', el( 'strong', null, i.s ) ),
								i.x > 1 ? el( 'span', { className: 'ekwa-pad-muted' }, ' ×' + i.x ) : null,
								el( 'span', { className: 'ekwa-sa-ctx' }, el( Highlight, { text: i.c, word: i.w } ) ),
								el( Button, { variant: 'link', className: 'ekwa-sa-item__act', onClick: function () { ignore( i.w ); } }, __( 'Not a mistake — ignore', 'ekwa' ) )
							);
						} ) ) : null
					);
				} ) : el( 'li', { className: 'ekwa-pad-muted' }, 'issues' === filter ? ( unchecked.length === inGroup.length ? __( 'Nothing checked yet.', 'ekwa' ) : __( 'No possible mistakes in what has been checked.', 'ekwa' ) ) : __( 'Nothing here.', 'ekwa' ) ) )
			),

			el( 'section', { className: 'ekwa-pad-card' },
				el( 'h2', null, sprintf( __( 'Ignored words (%d)', 'ekwa' ), dict.length ) ),
				dict.length ? el( 'ul', { className: 'ekwa-sa-dict' }, dict.map( function ( w ) {
					return el( 'li', { key: w }, w, el( 'button', { type: 'button', className: 'ekwa-sa-dict__x', 'aria-label': sprintf( __( 'Stop ignoring %s', 'ekwa' ), w ), onClick: function () { ignore( w, 'remove' ); } }, '×' ) );
				} ) ) : el( 'p', { className: 'ekwa-pad-muted' }, __( 'None yet. “Not a mistake — ignore” adds a word here for the whole site.', 'ekwa' ) )
			)
		);
	}

	// ─── Mount ──────────────────────────────────────────────────────────────

	var VIEWS = { content: ContentView, links: LinksView, images: ImagesView, spelling: SpellingView };
	var View  = VIEWS[ CFG.view ] || ContentView;

	var render = wp.element.createRoot
		? function ( node, target ) { wp.element.createRoot( target ).render( node ); }
		: function ( node, target ) { wp.element.render( node, target ); };
	render( el( View, null ), root );
}( window.wp ) );
