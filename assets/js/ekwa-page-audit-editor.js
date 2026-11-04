/**
 * Ekwa — Page Speed & Accessibility sidebar (block editor).
 *
 * Two places in the editor:
 *   - a summary panel in the Page / Post tab of the settings sidebar: the two
 *     scores at a glance and a button that opens the full audit;
 *   - the full audit as its own sidebar (gauge icon in the top toolbar): run
 *     PageSpeed Insights (mobile or desktop), read the scores, metrics and
 *     failing audits, ask the AI for fixes, apply them one by one, undo them.
 *
 * Fix kinds (validated on the server, see inc/ekwa-page-audit.php):
 *   setting — a whitelisted Ekwa performance option, changed on the server.
 *             Site-wide, so only administrators get the Apply button.
 *   block   — attributes of one block, changed HERE with updateBlockAttributes
 *             (so the editor's own Undo works too, and the change is saved with
 *             the page). The before/after values are recorded on the server so
 *             the change can still be undone after a reload.
 *   css     — one declaration of the site stylesheet (+ maybe one new colour
 *             token), applied on the server, marked "ekwa-fix:" in the CSS,
 *             undoable, and viewable side by side with "Compare".
 *   part    — attributes of one block in a template part (header, footer…),
 *             applied on the server, undoable.
 *   manual  — steps for a person.
 *
 * Each report is judged PASS/FAIL against the team's sitemap-tool criteria
 * (server-side, ekwa_page_audit_evaluate()), shown per device.
 *
 * Colour changes show a before/after sample with the contrast ratio computed
 * here (not taken on trust from the AI), and a large colour shift has to be
 * acknowledged before Apply is enabled.
 *
 * AI requests go through wp.apiFetch, so with the MCP connector on, the relay
 * client (assets/js/ekwa-ai-relay.js) waits for the person's AI app exactly as
 * for every other Ekwa AI feature.
 *
 * Plain wp.element, no build step — like the theme's other editor scripts.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.data || ! wp.apiFetch || ! wp.components || ! wp.blocks ) {
		return;
	}

	var el          = wp.element.createElement;
	var Fragment    = wp.element.Fragment;
	var useState    = wp.element.useState;
	var useEffect   = wp.element.useEffect;
	var useSelect   = wp.data.useSelect;
	var select      = wp.data.select;
	var dispatch    = wp.data.dispatch;
	var apiFetch    = wp.apiFetch;
	var __          = wp.i18n.__;
	var sprintf     = wp.i18n.sprintf;

	var Button          = wp.components.Button;
	var Spinner         = wp.components.Spinner;
	var Notice          = wp.components.Notice;
	var SelectControl   = wp.components.SelectControl;
	var PanelBody       = wp.components.PanelBody;
	var CheckboxControl = wp.components.CheckboxControl;

	var PluginSidebar = ( wp.editor && wp.editor.PluginSidebar ) || ( wp.editPost && wp.editPost.PluginSidebar ) || null;
	var PluginSidebarMoreMenuItem = ( wp.editor && wp.editor.PluginSidebarMoreMenuItem ) || ( wp.editPost && wp.editPost.PluginSidebarMoreMenuItem ) || null;
	var PluginDocumentSettingPanel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel ) || null;

	if ( ! PluginSidebar ) {
		return;
	}

	var CFG     = window.ekwaPageAudit || {};
	var PLUGIN  = 'ekwa-page-audit';
	var SIDEBAR = 'ekwa-page-audit-sidebar';
	var ICON    = 'performance';
	var IS_MCP  = 'mcp' === CFG.aiProvider;

	// Blocks whose inner blocks belong to something else: editing them here
	// would change every page that uses the pattern / template part.
	var SKIP_INTO = {
		'core/block':         __( 'synced pattern', 'ekwa' ),
		'core/template-part': __( 'template part', 'ekwa' ),
	};

	// ─── Small utilities ────────────────────────────────────────────────────

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

	function isObj( v ) {
		return !! v && 'object' === typeof v && ! Array.isArray( v ) && 'function' !== typeof v.toHTMLString;
	}

	function plain( v ) {
		if ( v === undefined ) { return null; }
		if ( v && 'function' === typeof v.toHTMLString ) { return v.toHTMLString(); }
		try { return JSON.parse( JSON.stringify( v ) ); } catch ( e ) { return null; }
	}

	function merge( base, patch ) {
		var out = assign( {}, base );
		Object.keys( patch ).forEach( function ( k ) {
			out[ k ] = ( isObj( out[ k ] ) && isObj( patch[ k ] ) ) ? merge( out[ k ], patch[ k ] ) : patch[ k ];
		} );
		return out;
	}

	function stable( v ) {
		if ( v === undefined || v === null || v === '' ) { return 'null'; }
		if ( v && 'function' === typeof v.toHTMLString ) { return JSON.stringify( v.toHTMLString() ); }
		if ( Array.isArray( v ) ) { return '[' + v.map( stable ).join( ',' ) + ']'; }
		if ( 'object' === typeof v ) {
			return '{' + Object.keys( v ).sort().filter( function ( k ) { return v[ k ] !== undefined; } ).map( function ( k ) {
				return JSON.stringify( k ) + ':' + stable( v[ k ] );
			} ).join( ',' ) + '}';
		}
		return JSON.stringify( v );
	}

	function same( a, b ) {
		return stable( a ) === stable( b );
	}

	function truncate( s, n ) {
		s = String( s );
		return s.length > n ? s.slice( 0, n - 1 ) + '…' : s;
	}

	function toText( v ) {
		var html = ( v && 'function' === typeof v.toHTMLString ) ? v.toHTMLString() : String( v );
		return html.replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
	}

	function errText( err ) {
		if ( err && err.message ) { return String( err.message ); }
		return __( 'Something went wrong. Try again.', 'ekwa' );
	}

	function when( ts ) {
		if ( ! ts ) { return ''; }
		try {
			return new Date( ts * 1000 ).toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } );
		} catch ( e ) {
			return new Date( ts * 1000 ).toLocaleString();
		}
	}

	function fmtValue( v ) {
		if ( v === undefined || v === null || v === '' ) { return __( '(not set)', 'ekwa' ); }
		if ( true === v || 1 === v ) { return __( 'on', 'ekwa' ); }
		if ( false === v || 0 === v ) { return __( 'off', 'ekwa' ); }
		if ( 'string' === typeof v ) { return '“' + truncate( v, 70 ) + '”'; }
		if ( 'number' === typeof v ) { return String( v ); }
		return truncate( stable( v ), 90 );
	}

	function settingNorm( v ) {
		if ( true === v ) { return 1; }
		if ( false === v ) { return 0; }
		return v;
	}

	// ─── Colour maths (WCAG 2.x) ────────────────────────────────────────────

	function hexRgb( hex ) {
		var m = /^#?([0-9a-f]{6})$/i.exec( String( hex || '' ) );
		if ( ! m ) { return null; }
		var n = parseInt( m[ 1 ], 16 );
		return [ ( n >> 16 ) & 255, ( n >> 8 ) & 255, n & 255 ];
	}

	function lum( rgb ) {
		var c = rgb.map( function ( v ) {
			v /= 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
		} );
		return 0.2126 * c[ 0 ] + 0.7152 * c[ 1 ] + 0.0722 * c[ 2 ];
	}

	function contrast( a, b ) {
		var x = hexRgb( a ), y = hexRgb( b );
		if ( ! x || ! y ) { return null; }
		var l1 = lum( x ), l2 = lum( y );
		return ( Math.max( l1, l2 ) + 0.05 ) / ( Math.min( l1, l2 ) + 0.05 );
	}

	function lab( rgb ) {
		var c = rgb.map( function ( v ) {
			v /= 255;
			return v <= 0.04045 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
		} );
		var x = ( c[ 0 ] * 0.4124 + c[ 1 ] * 0.3576 + c[ 2 ] * 0.1805 ) / 0.95047;
		var y = ( c[ 0 ] * 0.2126 + c[ 1 ] * 0.7152 + c[ 2 ] * 0.0722 );
		var z = ( c[ 0 ] * 0.0193 + c[ 1 ] * 0.1192 + c[ 2 ] * 0.9505 ) / 1.08883;
		var f = function ( t ) { return t > 0.008856 ? Math.cbrt( t ) : ( 7.787 * t ) + 16 / 116; };
		return [ 116 * f( y ) - 16, 500 * ( f( x ) - f( y ) ), 200 * ( f( y ) - f( z ) ) ];
	}

	// CIE76 colour difference: ~2 is barely visible, 20+ reads as a different colour.
	function deltaE( a, b ) {
		var x = hexRgb( a ), y = hexRgb( b );
		if ( ! x || ! y ) { return 0; }
		var p = lab( x ), q = lab( y );
		return Math.sqrt( Math.pow( p[ 0 ] - q[ 0 ], 2 ) + Math.pow( p[ 1 ] - q[ 1 ], 2 ) + Math.pow( p[ 2 ] - q[ 2 ], 2 ) );
	}

	var BIG_COLOUR_CHANGE = 20;

	// The colour option the person picked, or the recommended one.
	function chosenOption( fix ) {
		var list = fix.colorOptions || [];
		if ( ! list.length ) { return null; }
		var want = store.choice[ fix.id ];
		var found = null;
		list.forEach( function ( o ) { if ( ( want && o.id === want ) || ( ! want && o.recommended ) ) { found = o; } } );
		return found || list[ 0 ];
	}

	// The css spec as it will be applied (chosen option swapped in).
	function cssWithOption( fix ) {
		var css = fix.css || {};
		var opt = chosenOption( fix );
		if ( ! opt || undefined === fix.colorEdit || ! css.edits || ! css.edits[ fix.colorEdit ] ) { return css; }
		var edits = css.edits.map( function ( e, i ) { return i === fix.colorEdit ? assign( {}, e, { to: opt.to, after_hex: opt.hex } ) : e; } );
		return assign( {}, css, { edits: edits, vars: opt.vars || [] } );
	}

	function bigColourChange( fix ) {
		var opt = chosenOption( fix );
		if ( opt ) { return opt.delta >= BIG_COLOUR_CHANGE; }
		return ( fix.colors || [] ).some( function ( c ) { return deltaE( c.before, c.after ) >= BIG_COLOUR_CHANGE; } );
	}

	// ─── Shared state (summary panel + sidebar) ─────────────────────────────

	var store = {
		postId:       0,
		loading:      false,
		loaded:       false,
		loadError:    '',
		data:         null,
		strategy:     'mobile',
		running:      '',
		runStarted:   0,
		runError:     '',
		analyzing:    '',
		analyzeError: '',
		focus:        'all',
		model:        CFG.defaultModel || '',
		busy:         {},
		messages:     {},
		ack:          {},
		groups:       { accessibility: true },
		choice:       {},
	};
	var listeners = [];

	function setStore( patch ) {
		store = assign( {}, store, patch );
		listeners.slice().forEach( function ( fn ) { fn( store ); } );
	}

	function setIn( bag, key, value ) {
		var next = assign( {}, store[ bag ] );
		if ( value === undefined ) { delete next[ key ]; } else { next[ key ] = value; }
		var patch = {};
		patch[ bag ] = next;
		setStore( patch );
	}

	function patchData( patch ) {
		setStore( { data: assign( {}, store.data || {}, patch ) } );
	}

	function useStore() {
		var pair = useState( store );
		useEffect( function () {
			var fn = function ( s ) { pair[ 1 ]( s ); };
			listeners.push( fn );
			fn( store );
			return function () {
				var i = listeners.indexOf( fn );
				if ( i >= 0 ) { listeners.splice( i, 1 ); }
			};
		}, [] );
		return pair[ 0 ];
	}

	function say( key, type, text ) {
		setIn( 'messages', key, text ? { type: type, text: text } : undefined );
	}

	function busy( key, on ) {
		setIn( 'busy', key, on ? true : undefined );
	}

	// ─── Server calls ───────────────────────────────────────────────────────

	function load( postId, force ) {
		if ( ! postId || store.loading || ( ! force && store.loaded && store.postId === postId ) ) {
			return;
		}
		setStore( { loading: true, postId: postId, loadError: '' } );
		apiFetch( { path: '/ekwa/v1/page-audit/state?post_id=' + postId } )
			.then( function ( data ) { setStore( { data: data, loaded: true, loading: false } ); } )
			.catch( function ( err ) { setStore( { loading: false, loadError: errText( err ) } ); } );
	}

	function runTest( postId, strategy ) {
		setStore( { running: strategy, runStarted: Date.now(), runError: '', verifyQueued: false } );
		apiFetch( { path: '/ekwa/v1/page-audit/run', method: 'POST', data: { post_id: postId, strategy: strategy } } )
			.then( function ( res ) {
				var reports    = assign( {}, ( store.data && store.data.reports ) || {} );
				var evaluation = assign( {}, ( store.data && store.data.evaluation ) || {} );
				reports[ strategy ]    = res.report;
				evaluation[ strategy ] = res.evaluation || null;
				patchData( { reports: reports, evaluation: evaluation, scores: res.scores || [] } );
			} )
			.catch( function ( err ) { setStore( { runError: errText( err ) } ); } )
			.then( function () {
				setStore( { running: '' } );
				if ( store.verifyAgain ) { setStore( { verifyAgain: false } ); scheduleVerify( postId ); }
			} );
	}

	// ─── Automatic verification ─────────────────────────────────────────────
	// After a site-wide fix (and after the page is saved with block fixes), the
	// page is re-tested automatically; each applied fix then shows whether its
	// PageSpeed audit passes now — nobody has to check by hand.
	var verifyTimer = null;
	function scheduleVerify( postId ) {
		if ( ! postId ) { return; }
		if ( store.running ) { setStore( { verifyAgain: true } ); return; }
		setStore( { verifyQueued: true } );
		if ( verifyTimer ) { window.clearTimeout( verifyTimer ); }
		verifyTimer = window.setTimeout( function () {
			verifyTimer = null;
			if ( store.running ) { setStore( { verifyAgain: true } ); return; }
			runTest( postId, store.strategy );
		}, 6000 );
	}

	// Did the latest test (after the fix) still report the fix's audit?
	function auditVerdict( fix, appliedAt ) {
		var data   = store.data || {};
		var report = data.reports && data.reports[ store.strategy ];
		if ( ! fix.audit || ! report || ! appliedAt || ( report.fetched || 0 ) <= appliedAt ) { return null; }
		var failing = false;
		Object.keys( report.audits || {} ).forEach( function ( cat ) {
			( report.audits[ cat ] || [] ).forEach( function ( a ) { if ( a.id === fix.audit ) { failing = true; } } );
		} );
		return failing ? 'failing' : 'passing';
	}

	function analyze( postId, strategy ) {
		var outline;
		try {
			outline = buildOutline();
		} catch ( e ) {
			outline = { blocks: [], schemas: {} };
		}
		setStore( { analyzing: strategy, analyzeError: '' } );
		apiFetch( {
			path:   '/ekwa/v1/page-audit/analyze',
			method: 'POST',
			data:   {
				post_id:  postId,
				strategy: strategy,
				focus:    store.focus,
				model:    store.model,
				blocks:   outline.blocks,
				schemas:  outline.schemas,
			},
		} )
			.then( function ( res ) {
				var analysis = assign( {}, ( store.data && store.data.analysis ) || {} );
				analysis[ strategy ] = res.analysis;
				setStore( { messages: {}, ack: {} } );
				patchData( { analysis: analysis } );
			} )
			.catch( function ( err ) { setStore( { analyzeError: errText( err ) } ); } )
			.then( function () { setStore( { analyzing: '' } ); } );
	}

	// ─── Block tree ─────────────────────────────────────────────────────────

	function findBlock( list, test ) {
		for ( var i = 0; i < list.length; i++ ) {
			if ( test( list[ i ] ) ) { return list[ i ]; }
			var inner = list[ i ].innerBlocks && list[ i ].innerBlocks.length ? findBlock( list[ i ].innerBlocks, test ) : null;
			if ( inner ) { return inner; }
		}
		return null;
	}

	// The page body: the post-content block's children when the editor shows
	// the template around the content, otherwise the root.
	function contentRoot() {
		var be  = select( 'core/block-editor' );
		var all = be.getBlocks();
		var pc  = findBlock( all, function ( b ) { return 'core/post-content' === b.name; } );
		return pc ? be.getBlocks( pc.clientId ) : all;
	}

	function blockAt( path ) {
		var parts = String( path || '' ).split( '.' );
		var list  = contentRoot();
		var block = null;
		for ( var i = 0; i < parts.length; i++ ) {
			block = list[ parseInt( parts[ i ], 10 ) ];
			if ( ! block ) { return null; }
			if ( i < parts.length - 1 ) {
				if ( SKIP_INTO[ block.name ] ) { return null; }
				list = block.innerBlocks || [];
			}
		}
		return block;
	}

	function isRich( def ) {
		return !! def && ( 'rich-text' === def.type || 'html' === def.source || 'rich-text' === def.source );
	}

	function compactAttrs( block ) {
		var type = wp.blocks.getBlockType( block.name );
		var defs = ( type && type.attributes ) || {};
		var out  = {};
		Object.keys( block.attributes || {} ).forEach( function ( k ) {
			if ( 'lock' === k || 'metadata' === k || 'scopedCss' === k ) { return; }
			var v = block.attributes[ k ];
			if ( v === undefined || v === null || v === '' ) { return; }
			var def = defs[ k ];
			if ( def && def[ 'default' ] !== undefined && same( def[ 'default' ], v ) ) { return; }
			if ( isRich( def ) ) {
				var text = toText( v );
				if ( text ) { out[ k ] = truncate( text, 140 ); }
				return;
			}
			if ( 'string' === typeof v ) { out[ k ] = truncate( v, 220 ); return; }
			if ( 'number' === typeof v || 'boolean' === typeof v ) { out[ k ] = v; return; }
			var json = stable( v );
			out[ k ] = json.length <= 700 ? plain( v ) : '[' + ( Array.isArray( v ) ? 'list' : 'object' ) + ', ' + json.length + ' chars — not shown]';
		} );
		return out;
	}

	var schemaCache = {};

	function schemaFor( name ) {
		if ( schemaCache[ name ] ) { return schemaCache[ name ]; }
		var type = wp.blocks.getBlockType( name );
		if ( ! type ) { return null; }
		var attrs = {};
		Object.keys( type.attributes || {} ).forEach( function ( k ) {
			if ( 'lock' === k || 'metadata' === k ) { return; }
			var d = type.attributes[ k ] || {};
			var s = { type: d.type || ( d.source ? 'string' : 'any' ) };
			if ( d[ 'enum' ] ) { s[ 'enum' ] = d[ 'enum' ]; }
			if ( d[ 'default' ] !== undefined ) {
				var j = stable( d[ 'default' ] );
				if ( j.length <= 80 ) { s[ 'default' ] = d[ 'default' ]; }
			}
			if ( isRich( d ) ) { s.richText = true; }
			attrs[ k ] = s;
		} );
		schemaCache[ name ] = { title: type.title || name, attrs: attrs };
		return schemaCache[ name ];
	}

	function buildOutline() {
		var blocks = [];
		var names  = {};
		( function walk( list, prefix ) {
			list.forEach( function ( b, i ) {
				if ( blocks.length >= 600 || ! b || ! b.name ) { return; }
				var path = prefix ? prefix + '.' + i : String( i );
				var row  = { path: path, name: b.name, attrs: compactAttrs( b ) };
				// Section CSS goes whole: "css" fixes may edit it in place.
				var scoped = b.attributes && 'string' === typeof b.attributes.scopedCss ? b.attributes.scopedCss : '';
				if ( scoped.trim() && scoped.length <= 40000 ) { row.css = scoped; }
				if ( SKIP_INTO[ b.name ] ) {
					row.note = sprintf( /* translators: %s: "synced pattern" or "template part" */ __( '%s — do not change from here', 'ekwa' ), SKIP_INTO[ b.name ] );
				}
				blocks.push( row );
				names[ b.name ] = true;
				if ( ! SKIP_INTO[ b.name ] && b.innerBlocks && b.innerBlocks.length ) {
					walk( b.innerBlocks, path );
				}
			} );
		}( contentRoot(), '' ) );

		var schemas = {};
		Object.keys( names ).forEach( function ( n ) {
			var s = schemaFor( n );
			if ( s ) { schemas[ n ] = s; }
		} );
		return { blocks: blocks, schemas: schemas };
	}

	function coerceAttr( def, val ) {
		var t = def && def.type;
		if ( 'boolean' === t ) {
			if ( 'string' === typeof val ) { return 'true' === val.toLowerCase() || '1' === val; }
			return !! val;
		}
		if ( ( 'number' === t || 'integer' === t ) && 'string' === typeof val && '' !== val && ! isNaN( Number( val ) ) ) {
			return 'integer' === t ? parseInt( val, 10 ) : Number( val );
		}
		if ( 'string' === t && ( 'number' === typeof val || 'boolean' === typeof val ) ) {
			return String( val );
		}
		return val;
	}

	// Can this block fix be applied to the editor as it is now?
	function checkBlockFix( fix ) {
		var block = blockAt( fix.path );
		if ( ! block || block.name !== fix.block ) {
			return { error: __( 'That block is no longer at the same place on the page — it was moved, removed, or the page changed since the analysis. Analyze again for an up-to-date fix.', 'ekwa' ) };
		}
		var type = wp.blocks.getBlockType( block.name );
		var defs = ( type && type.attributes ) || {};
		var keys = Object.keys( fix.attrs || {} );
		for ( var i = 0; i < keys.length; i++ ) {
			if ( ! defs[ keys[ i ] ] ) {
				return { error: sprintf( /* translators: %s: attribute name */ __( 'This block has no “%s” setting, so the fix cannot be applied automatically.', 'ekwa' ), keys[ i ] ) };
			}
			if ( isRich( defs[ keys[ i ] ] ) ) {
				return { error: __( 'This fix rewrites the block’s text, so it has to be done by hand.', 'ekwa' ) };
			}
		}
		return { block: block, defs: defs };
	}

	// Where a recorded block change stands in the editor right now.
	function entryState( entry ) {
		var block = blockAt( entry.path );
		if ( ! block || block.name !== entry.block ) { return 'missing'; }
		var keys    = Object.keys( entry.after || {} );
		var isAfter = keys.every( function ( k ) { return same( block.attributes[ k ], entry.after[ k ] ); } );
		if ( isAfter ) { return 'applied'; }
		var isBefore = keys.every( function ( k ) { return same( block.attributes[ k ], entry.before ? entry.before[ k ] : undefined ); } );
		return isBefore ? 'reverted' : 'changed';
	}

	function showBlock( path ) {
		var block = blockAt( path );
		if ( block ) {
			try { dispatch( 'core/block-editor' ).selectBlock( block.clientId ); } catch ( e ) {}
		}
	}

	// ─── Apply / undo ───────────────────────────────────────────────────────

	function applyBlockFix( postId, fix ) {
		var check = checkBlockFix( fix );
		if ( check.error ) {
			say( fix.id, 'error', check.error );
			return;
		}
		var block  = check.block;
		var before = {};
		var after  = {};
		Object.keys( fix.attrs ).forEach( function ( k ) {
			var cur = block.attributes[ k ];
			var val = coerceAttr( check.defs[ k ], fix.attrs[ k ] );
			before[ k ] = plain( cur );
			after[ k ]  = ( isObj( cur ) && isObj( val ) ) ? merge( cur, val ) : val;
		} );

		dispatch( 'core/block-editor' ).updateBlockAttributes( block.clientId, after );
		showBlock( fix.path );

		busy( fix.id, true );
		apiFetch( {
			path:   '/ekwa/v1/page-audit/history',
			method: 'POST',
			data:   {
				post_id: postId,
				op:      'add',
				entry:   { fix_id: fix.id, title: fix.title, category: fix.category, path: fix.path, block: fix.block, before: before, after: plain( after ) },
			},
		} )
			.then( function ( res ) {
				patchData( { history: res.history || [] } );
				setStore( { pendingSaveVerify: true } );
				say( fix.id, 'success', __( 'Applied in the editor. Save the page — it is then re-tested automatically.', 'ekwa' ) );
			} )
			.catch( function ( err ) {
				say( fix.id, 'warning', __( 'Applied in the editor, but the undo record could not be saved, so after a reload only the editor’s own Undo can revert it.', 'ekwa' ) + ' ' + errText( err ) );
			} )
			.then( function () { busy( fix.id, false ); } );
	}

	function undoBlockEntry( postId, entry, msgKey ) {
		var key   = msgKey || entry.id;
		var state = entryState( entry );
		if ( 'missing' === state ) {
			say( key, 'error', __( 'That block is no longer at the same place, so this cannot be undone automatically. Use the editor’s Undo, or change it by hand.', 'ekwa' ) );
			return;
		}
		if ( 'changed' === state ) {
			say( key, 'error', __( 'The block was edited after this fix, so it was not undone automatically — that would overwrite the newer edit.', 'ekwa' ) );
			return;
		}
		if ( 'applied' === state ) {
			var block   = blockAt( entry.path );
			var restore = {};
			Object.keys( entry.after || {} ).forEach( function ( k ) {
				var v = entry.before ? entry.before[ k ] : null;
				restore[ k ] = ( v === null || v === undefined ) ? undefined : v;
			} );
			dispatch( 'core/block-editor' ).updateBlockAttributes( block.clientId, restore );
			showBlock( entry.path );
		}

		busy( key, true );
		apiFetch( { path: '/ekwa/v1/page-audit/history', method: 'POST', data: { post_id: postId, op: 'undone', entry_id: entry.id } } )
			.then( function ( res ) {
				patchData( { history: res.history || [] } );
				say( key, 'success', 'reverted' === state
					? __( 'This change was not on the page any more (never saved, or already undone).', 'ekwa' )
					: __( 'Undone in the editor. Save the page to publish it.', 'ekwa' ) );
			} )
			.catch( function ( err ) { say( key, 'error', errText( err ) ); } )
			.then( function () { busy( key, false ); } );
	}

	function applySetting( postId, fix ) {
		busy( fix.id, true );
		apiFetch( {
			path:   '/ekwa/v1/page-audit/apply-setting',
			method: 'POST',
			data:   { post_id: postId, fix_id: fix.id, title: fix.title, setting: fix.setting, value: fix.value },
		} )
			.then( function ( res ) {
				patchData( { log: ( ( store.data && store.data.log ) || [] ).concat( [ res.entry ] ), settings: res.settings } );
				say( fix.id, 'success', __( 'Setting changed for the whole site. Re-testing automatically to verify it…', 'ekwa' ) );
				scheduleVerify( postId );
			} )
			.catch( function ( err ) { say( fix.id, 'error', errText( err ) ); } )
			.then( function () { busy( fix.id, false ); } );
	}

	function undoSetting( entry, msgKey ) {
		var key = msgKey || entry.id;
		busy( key, true );
		apiFetch( { path: '/ekwa/v1/page-audit/undo-setting', method: 'POST', data: { entry_id: entry.id } } )
			.then( function ( res ) {
				var log = ( ( store.data && store.data.log ) || [] ).map( function ( e ) { return e.id === res.entry.id ? res.entry : e; } );
				patchData( { log: log, settings: res.settings } );
				say( key, 'success', __( 'Setting restored to its value before the fix.', 'ekwa' ) );
			} )
			.catch( function ( err ) { say( key, 'error', errText( err ) ); } )
			.then( function () { busy( key, false ); } );
	}

	// Stylesheet / template-part fixes: the server reads the fix from the
	// stored analysis by id, applies it and logs it.
	function applySiteFix( postId, fix ) {
		var route = 'css' === fix.kind ? 'apply-css' : 'apply-part';
		busy( fix.id, true );
		apiFetch( {
			path:   '/ekwa/v1/page-audit/' + route,
			method: 'POST',
			data:   { post_id: postId, strategy: store.strategy, fix_id: fix.id, option: chosenOption( fix ) ? chosenOption( fix ).id : '' },
		} )
			.then( function ( res ) {
				patchData( { changes: ( ( store.data && store.data.changes ) || [] ).concat( [ res.entry ] ) } );
				say( fix.id, 'success', __( 'Applied for the whole site. Checking it on the live page and re-testing automatically…', 'ekwa' ) );
				scheduleVerify( postId );
			} )
			.catch( function ( err ) { say( fix.id, 'error', errText( err ) ); } )
			.then( function () { busy( fix.id, false ); } );
	}

	function isBlockCss( fix ) {
		return 'css' === fix.kind && fix.css && fix.css.in && 'block' === fix.css.in.type;
	}

	// A css fix on a page block's Section CSS: the server computes the new text
	// from the block's current CSS (comment-free markers), the editor applies it
	// like a block fix (its own undo works; the history entry survives reloads).
	function applyScopedFix( postId, fix ) {
		var place = fix.css.in;
		var block = blockAt( place.path );
		if ( ! block || ( place.block && block.name !== place.block ) ) {
			say( fix.id, 'error', __( 'That block is no longer at the same place on the page. Analyze again for an up-to-date fix.', 'ekwa' ) );
			return;
		}
		var current = 'string' === typeof block.attributes.scopedCss ? block.attributes.scopedCss : '';
		busy( fix.id, true );
		apiFetch( { path: '/ekwa/v1/page-audit/css-compute', method: 'POST', data: { post_id: postId, strategy: store.strategy, fix_id: fix.id, css: current, option: chosenOption( fix ) ? chosenOption( fix ).id : '' } } )
			.then( function ( res ) {
				dispatch( 'core/block-editor' ).updateBlockAttributes( block.clientId, { scopedCss: res.css } );
				showBlock( place.path );
				return apiFetch( {
					path: '/ekwa/v1/page-audit/history', method: 'POST',
					data: { post_id: postId, op: 'add', entry: { fix_id: fix.id, title: fix.title, category: fix.category, path: place.path, block: block.name, before: { scopedCss: current }, after: { scopedCss: res.css } } },
				} ).then( function ( h ) {
					patchData( { history: h.history || [] } );
					setStore( { pendingSaveVerify: true } );
					say( fix.id, 'success', __( 'Applied to the block’s Section CSS. Save the page — it is then re-tested automatically.', 'ekwa' ) );
				}, function () {
					say( fix.id, 'warning', __( 'Applied in the editor, but the undo record could not be saved, so after a reload only the editor’s own Undo can revert it.', 'ekwa' ) );
				} );
			} )
			.catch( function ( err ) { say( fix.id, 'error', errText( err ) ); } )
			.then( function () { busy( fix.id, false ); } );
	}

	function undoChange( entry, msgKey ) {
		var key = msgKey || entry.id;
		busy( key, true );
		apiFetch( { path: '/ekwa/v1/page-audit/undo-change', method: 'POST', data: { entry_id: entry.id } } )
			.then( function ( res ) {
				var list = ( ( store.data && store.data.changes ) || [] ).map( function ( e ) { return e.id === res.entry.id ? res.entry : e; } );
				patchData( { changes: list } );
				say( key, 'success', __( 'Undone — the site is back to how it was before this fix.', 'ekwa' ) );
			} )
			.catch( function ( err ) { say( key, 'error', errText( err ) ); } )
			.then( function () { busy( key, false ); } );
	}

	function compareHref( postId, fix ) {
		if ( ! CFG.compareUrl ) { return ''; }
		var opt = chosenOption( fix );
		return CFG.compareUrl + '&post=' + encodeURIComponent( postId ) + '&strategy=' + encodeURIComponent( store.strategy ) + '&fix=' + encodeURIComponent( fix.id ) + ( opt ? '&option=' + encodeURIComponent( opt.id ) : '' );
	}

	function logUndoable( entry ) {
		var settings = ( store.data && store.data.settings ) || {};
		var cur      = settings[ entry.setting ];
		return ! entry.undone && !! cur && settingNorm( cur.current ) === settingNorm( entry[ 'new' ] );
	}

	function lastWith( list, fixId ) {
		var found = null;
		( list || [] ).forEach( function ( e ) { if ( e.fix_id === fixId ) { found = e; } } );
		return found;
	}

	// Has this fix been applied, and how can it be undone?
	function fixStatus( fix ) {
		var data = store.data || {};
		if ( 'setting' === fix.kind ) {
			var entry = lastWith( data.log, fix.id );
			if ( entry && ! entry.undone ) {
				return { applied: true, entry: entry, undoable: logUndoable( entry ) };
			}
			var cur = data.settings && data.settings[ fix.setting ];
			if ( cur && settingNorm( cur.current ) === settingNorm( fix.value ) ) {
				return { inEffect: true };
			}
			return {};
		}
		if ( 'block' === fix.kind || isBlockCss( fix ) ) {
			var h = lastWith( data.history, fix.id );
			if ( h && ! h.undone ) {
				var st = entryState( h );
				if ( 'applied' === st || 'changed' === st || 'missing' === st ) {
					return { applied: true, entry: h, blockState: st };
				}
			}
		}
		if ( ( 'css' === fix.kind && ! isBlockCss( fix ) ) || 'part' === fix.kind ) {
			var c = lastWith( data.changes, fix.id );
			if ( c && ! c.undone ) {
				return { applied: true, entry: c, undoable: !! c.undoable, blockState: 'changed' === c.state || 'missing' === c.state ? c.state : '' };
			}
		}
		return {};
	}

	// ─── Presentational pieces ──────────────────────────────────────────────

	function scoreClass( s ) {
		if ( s === null || s === undefined ) { return 'is-none'; }
		return s >= 90 ? 'is-good' : ( s >= 50 ? 'is-average' : 'is-poor' );
	}

	function Gauge( props ) {
		var s     = props.score;
		var size  = props.small ? 44 : 72;
		var r     = props.small ? 18 : 30;
		var c     = 2 * Math.PI * r;
		var off   = ( s === null || s === undefined ) ? c : c * ( 1 - s / 100 );
		var label = ( s === null || s === undefined )
			? sprintf( /* translators: %s: category name */ __( '%s: not tested', 'ekwa' ), props.label )
			: sprintf( /* translators: 1: category name, 2: score */ __( '%1$s: %2$d out of 100', 'ekwa' ), props.label, s );
		return el( 'div', { className: 'ekwa-pa-gauge ' + scoreClass( s ) + ( props.small ? ' is-small' : '' ), role: 'img', 'aria-label': label },
			el( 'div', { className: 'ekwa-pa-gauge__ring', style: { width: size, height: size } },
				el( 'svg', { width: size, height: size, viewBox: '0 0 ' + size + ' ' + size, 'aria-hidden': 'true', focusable: 'false' },
					el( 'circle', { className: 'ekwa-pa-gauge__track', cx: size / 2, cy: size / 2, r: r, fill: 'none', strokeWidth: props.small ? 4 : 6 } ),
					el( 'circle', {
						className: 'ekwa-pa-gauge__bar', cx: size / 2, cy: size / 2, r: r, fill: 'none', strokeWidth: props.small ? 4 : 6,
						strokeDasharray: c, strokeDashoffset: off, strokeLinecap: 'round', transform: 'rotate(-90 ' + ( size / 2 ) + ' ' + ( size / 2 ) + ')',
					} )
				),
				el( 'span', { className: 'ekwa-pa-gauge__num', 'aria-hidden': 'true' }, ( s === null || s === undefined ) ? '–' : String( s ) )
			),
			el( 'span', { className: 'ekwa-pa-gauge__label', 'aria-hidden': 'true' }, props.label ),
			props.delta ? el( 'span', { className: 'ekwa-pa-gauge__delta ' + ( props.delta > 0 ? 'is-up' : 'is-down' ) },
				( props.delta > 0 ? '▲ ' : '▼ ' ) + Math.abs( props.delta ) ) : null
		);
	}

	function deltas( scores, strategy ) {
		var list = ( scores || [] ).filter( function ( r ) { return r.s === strategy; } );
		if ( list.length < 2 ) { return {}; }
		var a = list[ list.length - 2 ], b = list[ list.length - 1 ];
		return {
			p: ( null !== a.p && null !== b.p ) ? b.p - a.p : 0,
			a: ( null !== a.a && null !== b.a ) ? b.a - a.a : 0,
			o: ( null != a.o && null != b.o ) ? b.o - a.o : 0,
		};
	}

	function Badge( props ) {
		return el( 'span', { className: 'ekwa-pa-badge is-' + props.tone }, props.children );
	}

	// What PASS/FAIL is judged on — chosen on Site Audit → PageSpeed ("Judge
	// PASS/FAIL on"), kept in this browser. All three (the default) = the
	// server's evaluation unchanged, i.e. the team's sitemap tool.
	var JUDGE_KEYS = { performance: [ 'performance', 'fcp', 'lcp', 'cls', 'tbt' ], accessibility: [ 'accessibility' ], seo: [ 'seo' ] };
	var JUDGE_LABELS = { performance: __( 'Performance', 'ekwa' ), accessibility: __( 'Accessibility', 'ekwa' ), seo: __( 'SEO', 'ekwa' ) };
	function judgeList() {
		var raw = '';
		try { raw = window.localStorage.getItem( 'ekwaPad:judge' ) || ''; } catch ( e ) {}
		var list = raw.split( ',' ).filter( function ( k ) { return !! JUDGE_KEYS[ k ]; } );
		return list.length ? list : Object.keys( JUDGE_KEYS );
	}
	function judged( ev ) {
		if ( ! ev || ! ev.status || 'none' === ev.status ) { return ev; }
		var list = judgeList();
		if ( list.length === Object.keys( JUDGE_KEYS ).length ) { return ev; }
		var all    = ev.issues || [];
		var issues = all.filter( function ( i ) { return list.some( function ( g ) { return -1 !== JUDGE_KEYS[ g ].indexOf( i.key ); } ); } );
		return assign( {}, ev, { status: issues.length ? 'fail' : 'pass', issues: issues, ignored: all.length - issues.length, judge: list } );
	}

	// PASS / FAIL against the sitemap-tool criteria (judged on the server).
	function StatusChip( props ) {
		var ev = judged( props.evaluation );
		var st = ev ? ev.status : 'none';
		var lb = { pass: __( 'PASS', 'ekwa' ), fail: __( 'FAIL', 'ekwa' ), none: __( 'Not tested', 'ekwa' ) }[ st ] || st;
		return el( 'span', { className: 'ekwa-pa-status is-' + st + ( props.small ? ' is-small' : '' ) },
			el( 'span', { 'aria-hidden': 'true' }, 'pass' === st ? '✓ ' : ( 'fail' === st ? '✕ ' : '' ) ),
			props.label ? props.label + ' ' : '', lb );
	}

	function criteriaText( t ) {
		t = t || {};
		var parts = [];
		if ( null != t.performance ) { parts.push( sprintf( __( 'Performance ≥ %s', 'ekwa' ), t.performance ) ); }
		if ( null != t.accessibility ) { parts.push( sprintf( __( 'Accessibility %s', 'ekwa' ), t.accessibility ) ); }
		if ( null != t.seo ) { parts.push( sprintf( __( 'SEO %s', 'ekwa' ), t.seo ) ); }
		if ( null != t[ 'best-practices' ] ) { parts.push( sprintf( __( 'Best Practices ≥ %s', 'ekwa' ), t[ 'best-practices' ] ) ); }
		if ( null != t.fcp ) { parts.push( 'FCP ≤ ' + t.fcp + ' s' ); }
		if ( null != t.lcp ) { parts.push( 'LCP ≤ ' + t.lcp + ' s' ); }
		if ( null != t.cls ) { parts.push( 'CLS ≤ ' + t.cls ); }
		if ( null != t.tbt ) { parts.push( 'TBT ≤ ' + t.tbt + ' s' ); }
		return parts.join( ' · ' );
	}

	function Verdict( props ) {
		var ev = judged( props.evaluation );
		if ( ! ev || 'none' === ev.status ) { return null; }
		var issues = ev.issues || [];
		return el( 'div', { className: 'ekwa-pa-verdict is-' + ev.status },
			el( 'div', { className: 'ekwa-pa-verdict__head' },
				el( StatusChip, { evaluation: ev } ),
				el( 'span', null, 'pass' === ev.status
					? __( 'Meets every criterion.', 'ekwa' )
					: sprintf( /* translators: %d: number of criteria missed */ __( '%d criteria missed:', 'ekwa' ), issues.length ) )
			),
			issues.length ? el( 'ul', { className: 'ekwa-pa-verdict__list' }, issues.map( function ( i, n ) { return el( 'li', { key: n }, i.text ); } ) ) : null,
			ev.judge ? el( 'p', { className: 'ekwa-pa-muted' }, sprintf(
				/* translators: %s: categories, e.g. "Performance + Accessibility" */
				__( 'Judged on %s only (chosen on Site Audit → PageSpeed).', 'ekwa' ),
				ev.judge.map( function ( k ) { return JUDGE_LABELS[ k ]; } ).join( ' + ' )
			) + ( ev.ignored ? ' ' + sprintf( __( '%d other criteria not counted.', 'ekwa' ), ev.ignored ) : '' ) ) : null,
			el( 'p', { className: 'ekwa-pa-muted ekwa-pa-verdict__criteria' }, sprintf( /* translators: %s: list of criteria */ __( 'Criteria: %s', 'ekwa' ), criteriaText( props.thresholds ) ) )
		);
	}

	function Message( props ) {
		var m = props.message;
		if ( ! m ) { return null; }
		return el( Notice, { status: m.type, isDismissible: true, onRemove: props.onRemove, className: 'ekwa-pa-notice' }, m.text );
	}

	function ColourSample( props ) {
		var ratio = contrast( props.fg, props.bg );
		var pass  = null === ratio ? '' : ( ratio >= 4.5 ? 'is-pass' : ( ratio >= 3 ? 'is-large' : 'is-fail' ) );
		return el( 'div', { className: 'ekwa-pa-chip' },
			el( 'div', { className: 'ekwa-pa-chip__sample', style: { color: props.fg, background: props.bg } }, 'Aa' ),
			el( 'div', { className: 'ekwa-pa-chip__meta' },
				el( 'strong', null, props.label ),
				el( 'code', null, props.hex ),
				null === ratio ? null : el( 'span', { className: 'ekwa-pa-ratio ' + pass },
					ratio.toFixed( 2 ) + ':1 ' + ( ratio >= 4.5 ? __( 'passes', 'ekwa' ) : ( ratio >= 3 ? __( 'large text only', 'ekwa' ) : __( 'fails', 'ekwa' ) ) ) )
			)
		);
	}

	function ColourPreview( props ) {
		return el( 'div', { className: 'ekwa-pa-colours' },
			( props.colors || [] ).map( function ( c, i ) {
				var isBg    = 'background' === c.role;
				var against = c.against || ( isBg ? '#000000' : '#ffffff' );
				return el( 'div', { className: 'ekwa-pa-colour-row', key: i },
					el( ColourSample, { label: __( 'Before', 'ekwa' ), hex: c.before, fg: isBg ? against : c.before, bg: isBg ? c.before : against } ),
					el( 'span', { className: 'ekwa-pa-arrow', 'aria-hidden': 'true' }, '→' ),
					el( ColourSample, { label: __( 'After', 'ekwa' ), hex: c.after, fg: isBg ? against : c.after, bg: isBg ? c.after : against } )
				);
			} )
		);
	}

	function AttrDiff( props ) {
		var fix   = props.fix;
		// Once applied, "now" already equals "after" — show what was recorded.
		var entry = props.status && props.status.applied ? props.status.entry : null;
		var block = blockAt( fix.path );
		var rows  = Object.keys( fix.attrs || {} ).map( function ( k ) {
			var cur, next;
			if ( entry && entry.after && Object.prototype.hasOwnProperty.call( entry.after, k ) ) {
				cur  = entry.before ? entry.before[ k ] : undefined;
				next = entry.after[ k ];
			} else {
				cur  = block ? block.attributes[ k ] : undefined;
				next = ( isObj( cur ) && isObj( fix.attrs[ k ] ) ) ? merge( cur, fix.attrs[ k ] ) : fix.attrs[ k ];
			}
			return el( 'tr', { key: k },
				el( 'th', { scope: 'row' }, k ),
				el( 'td', null, fmtValue( cur ) ),
				el( 'td', null, fmtValue( next ) )
			);
		} );
		var type = wp.blocks.getBlockType( fix.block );
		return el( 'div', { className: 'ekwa-pa-diff' },
			el( 'div', { className: 'ekwa-pa-diff__target' },
				sprintf( /* translators: %s: block title */ __( 'Block: %s', 'ekwa' ), ( type && type.title ) || fix.block ),
				' ',
				el( Button, { variant: 'link', onClick: function () { showBlock( fix.path ); } }, __( 'Show', 'ekwa' ) )
			),
			el( 'table', null,
				el( 'thead', null, el( 'tr', null,
					el( 'th', { scope: 'col' }, __( 'Setting', 'ekwa' ) ),
					el( 'th', { scope: 'col' }, entry ? __( 'Before', 'ekwa' ) : __( 'Now', 'ekwa' ) ),
					el( 'th', { scope: 'col' }, __( 'After', 'ekwa' ) )
				) ),
				el( 'tbody', null, rows )
			)
		);
	}

	function SettingDiff( props ) {
		var fix   = props.fix;
		var cur   = store.data && store.data.settings && store.data.settings[ fix.setting ];
		var entry = props.status && props.status.applied ? props.status.entry : null;
		var from  = entry ? entry.old : ( cur ? cur.current : undefined );
		return el( 'div', { className: 'ekwa-pa-diff' },
			el( 'div', { className: 'ekwa-pa-diff__target' },
				el( Badge, { tone: 'site' }, __( 'Site-wide', 'ekwa' ) ), ' ',
				sprintf( /* translators: %s: setting label */ __( 'Ekwa Settings → Performance: %s', 'ekwa' ), fix.label || fix.setting )
			),
			el( 'p', { className: 'ekwa-pa-diff__change' },
				el( 'code', null, fmtValue( from ) ), ' → ', el( 'code', null, fmtValue( fix.value ) ) ),
			fix.risk ? el( 'p', { className: 'ekwa-pa-risk' }, fix.risk ) : null
		);
	}

	// Verified colour choices: every option passes against the background
	// Lighthouse measured; the person picks (the closest one is preselected).
	function ColourOptions( props ) {
		var fix    = props.fix;
		var t      = fix.colorTargets || {};
		var bg     = ( t.bgs && t.bgs[ 0 ] ) || '#ffffff';
		var chosen = chosenOption( fix );
		var before = fix.colors && fix.colors[ 0 ] ? fix.colors[ 0 ].before : '';
		var now    = before ? contrast( before, bg ) : null;
		return el( 'fieldset', { className: 'ekwa-pa-opts' },
			el( 'legend', null, __( 'Choose the new colour', 'ekwa' ) ),
			el( 'p', { className: 'ekwa-pa-muted' }, sprintf(
				/* translators: 1: required ratio, 2: background colour(s) */
				__( 'Every option reaches %1$s:1 against the background measured on the page (%2$s).', 'ekwa' ),
				t.required, ( t.bgs || [] ).join( ', ' ) ) ),
			before ? el( 'div', { className: 'ekwa-pa-opt is-current' },
				el( 'span', { className: 'ekwa-pa-opt__sample', style: { color: before, background: bg } }, 'Aa' ),
				el( 'span', { className: 'ekwa-pa-opt__body' },
					el( 'strong', null, __( 'Now', 'ekwa' ) ),
					el( 'code', null, before ),
					null !== now ? el( 'span', { className: 'ekwa-pa-ratio is-fail' }, now.toFixed( 2 ) + ':1 ' + __( 'fails', 'ekwa' ) ) : null
				)
			) : null,
			( fix.colorOptions || [] ).map( function ( o ) {
				var id     = 'ekwa-pa-opt-' + fix.id + '-' + o.id;
				var isMine = !! chosen && chosen.id === o.id;
				var change = o.delta < 10 ? __( 'very close to today’s colour', 'ekwa' ) : ( o.delta < BIG_COLOUR_CHANGE ? __( 'a little different', 'ekwa' ) : __( 'clearly different', 'ekwa' ) );
				return el( 'label', { key: o.id, className: 'ekwa-pa-opt' + ( isMine ? ' is-chosen' : '' ), htmlFor: id },
					el( 'input', {
						type: 'radio', id: id, name: 'ekwa-pa-opt-' + fix.id, checked: isMine,
						onChange: function () { setIn( 'choice', fix.id, o.id ); setIn( 'ack', fix.id, undefined ); },
					} ),
					el( 'span', { className: 'ekwa-pa-opt__sample', style: { color: o.sample || o.hex, background: bg }, 'aria-hidden': 'true' }, 'Aa' ),
					el( 'span', { className: 'ekwa-pa-opt__body' },
						el( 'strong', null, o.label, o.recommended ? el( 'span', { className: 'ekwa-pa-opt__rec' }, __( 'Recommended', 'ekwa' ) ) : null ),
						el( 'code', null, o.to + ( o.vars && o.vars.length ? ' = ' + o.vars[ 0 ].value : '' ) + ( o.to.charAt( 0 ) !== '#' && ( ! o.vars || ! o.vars.length ) ? ' (' + o.hex + ')' : '' ) ),
						el( 'span', { className: 'ekwa-pa-ratio is-pass' }, Number( o.ratio ).toFixed( 2 ) + ':1 ✓ · ' + change )
					)
				);
			} )
		);
	}

	function CssDiff( props ) {
		var fix    = props.fix;
		var entry  = props.status && props.status.applied ? props.status.entry : null;
		var css    = entry && entry.css ? entry.css : cssWithOption( fix );
		var place  = css.in || { type: 'site' };
		var target = 'part' === place.type
			? sprintf( /* translators: %s: template part slug */ __( 'Template part “%s” → Section CSS', 'ekwa' ), place.part )
			: ( 'block' === place.type
				? __( 'Section CSS of a block on this page', 'ekwa' )
				: ( ( store.data && store.data.cssTarget && store.data.cssTarget.label ) || css.target || __( 'Site stylesheet', 'ekwa' ) ) );
		var modeLb = { add: __( 'adds', 'ekwa' ), 'new': __( 'new rule', 'ekwa' ) };
		return el( 'div', { className: 'ekwa-pa-diff' },
			el( 'div', { className: 'ekwa-pa-diff__target' },
				'block' === place.type ? null : el( Badge, { tone: 'site' }, __( 'Site-wide', 'ekwa' ) ), ' ', target,
				'block' === place.type ? el( Button, { variant: 'link', onClick: function () { showBlock( place.path ); } }, ' ' + __( 'Show', 'ekwa' ) ) : null ),
			( css.edits || [] ).map( function ( e, i ) {
				return el( 'div', { key: i, className: 'ekwa-pa-css' },
					el( 'code', { className: 'ekwa-pa-css__sel' }, ( e.media ? e.media + ' › ' : '' ) + e.selector ),
					el( 'div', { className: 'ekwa-pa-css__decl' },
						e.from
							? el( 'del', null, e.property + ': ' + e.from )
							: el( 'span', { className: 'ekwa-pa-muted' }, modeLb[ e.mode ] || modeLb.add ),
						el( 'span', { 'aria-hidden': 'true' }, ' → ' ),
						el( 'ins', null, e.property + ': ' + e.to )
					)
				);
			} ),
			( css.vars || [] ).length ? el( 'div', { className: 'ekwa-pa-css__vars' },
				el( 'span', { className: 'ekwa-pa-muted' }, __( 'New colour token', 'ekwa' ) + ': ' ),
				css.vars.map( function ( v ) {
					return el( 'span', { key: v.name, className: 'ekwa-pa-token' },
						el( 'span', { className: 'ekwa-pa-swatch', style: { background: v.value }, 'aria-hidden': 'true' } ),
						el( 'code', null, v.name + ': ' + v.value ) );
				} )
			) : null,
			( fix.cssNotes || [] ).map( function ( n, i ) { return el( 'p', { key: 'n' + i, className: 'ekwa-pa-risk' }, n ); } ),
			el( 'p', { className: 'ekwa-pa-muted' }, 'site' !== place.type
				? __( 'The old value is kept next to the change as “--ekwa-fix-…” (Section CSS never gets comments: some firewalls block saving them).', 'ekwa' )
				: ( entry && entry.marker
					? sprintf( /* translators: %s: marker text */ __( 'The old value is kept in a comment marked “%s” in the stylesheet.', 'ekwa' ), entry.marker )
					: __( 'The old value is kept in a comment marked “ekwa-fix:” in the stylesheet, so it can be undone or found later.', 'ekwa' ) ) )
		);
	}

	function PartDiff( props ) {
		var fix   = props.fix;
		var entry = props.status && props.status.applied ? props.status.entry : null;
		var type  = wp.blocks.getBlockType( fix.block );
		var rows  = Object.keys( fix.attrs || {} ).map( function ( k ) {
			var before = entry && entry.before ? entry.before[ k ] : undefined;
			var after  = entry && entry.after ? entry.after[ k ] : fix.attrs[ k ];
			return el( 'tr', { key: k },
				el( 'th', { scope: 'row' }, k ),
				entry ? el( 'td', null, fmtValue( before ) ) : null,
				el( 'td', null, fmtValue( after ) )
			);
		} );
		return el( 'div', { className: 'ekwa-pa-diff' },
			el( 'div', { className: 'ekwa-pa-diff__target' },
				el( Badge, { tone: 'site' }, __( 'Site-wide', 'ekwa' ) ), ' ',
				sprintf( /* translators: 1: template part title, 2: block title */ __( 'Template part “%1$s” → %2$s', 'ekwa' ), fix.partTitle || fix.part, ( type && type.title ) || fix.block )
			),
			el( 'table', null,
				el( 'thead', null, el( 'tr', null,
					el( 'th', { scope: 'col' }, __( 'Setting', 'ekwa' ) ),
					entry ? el( 'th', { scope: 'col' }, __( 'Before', 'ekwa' ) ) : null,
					el( 'th', { scope: 'col' }, entry ? __( 'After', 'ekwa' ) : __( 'New value', 'ekwa' ) )
				) ),
				el( 'tbody', null, rows )
			)
		);
	}

	// What we know about whether a fix works: the CSS check against the page
	// (before applying), the live-page check (right after), and the next
	// PageSpeed test (automatic).
	function Verification( props ) {
		var fix    = props.fix;
		var status = props.status;
		var lines  = [];
		if ( ! status.applied && fix.cssCheck && fix.cssCheck.length ) {
			fix.cssCheck.forEach( function ( c, i ) {
				if ( 'ok' === c.status ) {
					lines.push( el( 'li', { key: 'c' + i, className: 'is-ok' }, '✓ ' + __( 'Checked on this page: ', 'ekwa' ) + ( c.detail || '' ) ) );
				} else if ( c.detail ) {
					lines.push( el( 'li', { key: 'c' + i, className: 'is-unknown' }, __( 'Not checked: ', 'ekwa' ) + c.detail ) );
				}
				if ( c.strengthened_from ) {
					lines.push( el( 'li', { key: 's' + i, className: 'is-note' }, sprintf( /* translators: 1: original selector, 2: stronger selector, 3: rule it beats */ __( 'Selector strengthened from “%1$s” to “%2$s” so it wins over %3$s.', 'ekwa' ), c.strengthened_from, c.selector, c.beats || '' ) ) );
				}
			} );
		}
		if ( status.applied && status.entry && status.entry.verify ) {
			var v = status.entry.verify;
			lines.push( el( 'li', { key: 'v', className: 'ok' === v.status ? 'is-ok' : ( 'fails' === v.status ? 'is-bad' : 'is-unknown' ) },
				( 'ok' === v.status ? '✓ ' : ( 'fails' === v.status ? '✕ ' : '' ) ) + __( 'Live page: ', 'ekwa' ) + ( v.detail || '' ) ) );
		}
		if ( status.applied && status.entry ) {
			var verdict = auditVerdict( fix, status.entry.time );
			if ( 'passing' === verdict ) {
				lines.push( el( 'li', { key: 'p', className: 'is-ok' }, '✓ ' + __( 'PageSpeed: this audit passes in the latest test.', 'ekwa' ) ) );
			} else if ( 'failing' === verdict ) {
				lines.push( el( 'li', { key: 'p', className: 'is-bad' }, '✕ ' + __( 'PageSpeed: still failing in the latest test. Analyze again — the AI is told this fix did not work.', 'ekwa' ) ) );
			} else if ( fix.audit && ( store.verifyQueued || store.running ) ) {
				lines.push( el( 'li', { key: 'p', className: 'is-unknown' }, __( 'PageSpeed: re-testing to verify…', 'ekwa' ) ) );
			} else if ( fix.audit && ( 'block' === fix.kind || isBlockCss( fix ) ) ) {
				lines.push( el( 'li', { key: 'p', className: 'is-unknown' }, __( 'PageSpeed: save the page — it is re-tested automatically.', 'ekwa' ) ) );
			}
		}
		return lines.length ? el( 'ul', { className: 'ekwa-pa-verify' }, lines ) : null;
	}

	// Before/after samples; once applied, "after" is the option that was used.
	function appliedColours( fix, status ) {
		var cols = fix.colors || [];
		if ( ! status.applied || ! status.entry || ! status.entry.css || undefined === fix.colorEdit ) { return cols; }
		var e = status.entry.css.edits && status.entry.css.edits[ fix.colorEdit ];
		if ( ! e || ! e.after_hex || ! cols[ 0 ] ) { return cols; }
		return [ assign( {}, cols[ 0 ], { after: e.after_hex } ) ].concat( cols.slice( 1 ) );
	}

	function FixCard( props ) {
		var fix    = props.fix;
		var postId = props.postId;
		var status = fixStatus( fix );
		var isBusy = !! store.busy[ fix.id ];
		var big    = bigColourChange( fix );
		var acked  = !! store.ack[ fix.id ];

		var tone   = { high: 'high', medium: 'medium', low: 'low' }[ fix.impact ] || 'medium';
		var kindLb = {
			setting: __( 'Setting', 'ekwa' ),
			block:   __( 'Block', 'ekwa' ),
			css:     __( 'Stylesheet', 'ekwa' ),
			part:    __( 'Header / footer', 'ekwa' ),
			manual:  __( 'Manual', 'ekwa' ),
		}[ fix.kind ];
		var place    = ( fix.css && fix.css.in ) || { type: 'site' };
		var isSite   = ( 'css' === fix.kind && 'block' !== place.type ) || 'part' === fix.kind;
		var allowed  = 'setting' === fix.kind || ( 'css' === fix.kind && 'site' === place.type )
			? CFG.canManage
			: ( 'part' === fix.kind || ( 'css' === fix.kind && 'part' === place.type ) ? CFG.canParts : true );
		var compare  = 'css' === fix.kind && 'site' === place.type ? compareHref( postId, fix ) : '';

		var actions = null;
		if ( 'manual' !== fix.kind ) {
			if ( status.applied ) {
				var canUndo = 'setting' === fix.kind ? ( CFG.canManage && status.undoable ) : ( isSite ? ( allowed && status.undoable ) : true );
				actions = el( 'div', { className: 'ekwa-pa-actions' },
					el( 'span', { className: 'ekwa-pa-applied' }, '✓ ' + ( 'changed' === status.blockState
						? __( 'Applied, edited since', 'ekwa' )
						: ( 'missing' === status.blockState ? __( 'Applied (block moved)', 'ekwa' ) : __( 'Applied', 'ekwa' ) ) ) ),
					canUndo ? el( Button, {
						variant: 'secondary', isBusy: isBusy, disabled: isBusy,
						onClick: function () {
							if ( 'setting' === fix.kind ) {
								undoSetting( status.entry, fix.id );
							} else if ( isSite && ! isBlockCss( fix ) ) {
								undoChange( status.entry, fix.id );
							} else {
								undoBlockEntry( postId, status.entry, fix.id );
							}
						},
					}, __( 'Undo', 'ekwa' ) ) : null,
					compare ? el( Button, { variant: 'tertiary', href: compare, target: '_blank', rel: 'noopener noreferrer' }, __( 'Compare', 'ekwa' ) ) : null,
					'setting' === fix.kind && ! status.undoable ? el( 'span', { className: 'ekwa-pa-muted' }, __( 'Changed again since — undo from Ekwa Settings if needed.', 'ekwa' ) ) : null,
					isSite && ! status.undoable ? el( 'span', { className: 'ekwa-pa-muted' }, __( 'Edited since — undo by hand (search the stylesheet for its ekwa-fix note) or from Ekwa Settings → Performance.', 'ekwa' ) ) : null
				);
			} else if ( status.inEffect ) {
				actions = el( 'div', { className: 'ekwa-pa-actions' }, el( 'span', { className: 'ekwa-pa-applied' }, '✓ ' + __( 'Already in effect', 'ekwa' ) ) );
			} else if ( ! allowed ) {
				actions = el( 'div', { className: 'ekwa-pa-actions' },
					el( 'p', { className: 'ekwa-pa-muted' }, __( 'Site-wide changes can only be made by an administrator.', 'ekwa' ) ),
					compare ? el( Button, { variant: 'secondary', href: compare, target: '_blank', rel: 'noopener noreferrer' }, __( 'Compare', 'ekwa' ) ) : null
				);
			} else {
				actions = el( 'div', { className: 'ekwa-pa-actions' },
					el( Button, {
						variant: 'primary', isBusy: isBusy, disabled: isBusy || ( big && ! acked ),
						onClick: function () {
							say( fix.id, '', '' );
							if ( 'setting' === fix.kind ) {
								applySetting( postId, fix );
							} else if ( isBlockCss( fix ) ) {
								applyScopedFix( postId, fix );
							} else if ( isSite ) {
								applySiteFix( postId, fix );
							} else {
								applyBlockFix( postId, fix );
							}
						},
					}, isBlockCss( fix ) ? __( 'Apply to Section CSS', 'ekwa' ) : ( 'css' === fix.kind && 'part' === place.type ? __( 'Apply to template part', 'ekwa' ) : ( { setting: __( 'Apply site-wide', 'ekwa' ), css: __( 'Apply to stylesheet', 'ekwa' ), part: __( 'Apply to template part', 'ekwa' ) }[ fix.kind ] || __( 'Apply fix', 'ekwa' ) ) ) ),
					compare ? el( Button, { variant: 'secondary', href: compare, target: '_blank', rel: 'noopener noreferrer' }, __( 'Compare before / after', 'ekwa' ) ) : null
				);
			}
		}

		return el( 'div', { className: 'ekwa-pa-fix' + ( status.applied ? ' is-applied' : '' ) },
			el( 'div', { className: 'ekwa-pa-fix__head' },
				el( Badge, { tone: tone }, { high: __( 'High impact', 'ekwa' ), medium: __( 'Medium', 'ekwa' ), low: __( 'Low', 'ekwa' ) }[ tone ] ),
				el( Badge, { tone: 'kind' }, kindLb )
			),
			el( 'h4', { className: 'ekwa-pa-fix__title' }, fix.title ),
			fix.why ? el( 'p', { className: 'ekwa-pa-fix__why' }, fix.why ) : null,
			fix.colorOptions && fix.colorOptions.length && ! status.applied ? el( ColourOptions, { fix: fix } ) : null,
			( ! fix.colorOptions || ! fix.colorOptions.length || status.applied ) && fix.colors && fix.colors.length
				? el( ColourPreview, { colors: appliedColours( fix, status ) } ) : null,
			'block' === fix.kind ? el( AttrDiff, { fix: fix, status: status } ) : null,
			'setting' === fix.kind ? el( SettingDiff, { fix: fix, status: status } ) : null,
			'css' === fix.kind ? el( CssDiff, { fix: fix, status: status } ) : null,
			'part' === fix.kind ? el( PartDiff, { fix: fix, status: status } ) : null,
			'manual' === fix.kind && fix.steps ? el( 'div', { className: 'ekwa-pa-steps' }, fix.steps ) : null,
			// The setting's own risk note is already shown above; skip a visual note that just repeats it.
			fix.visual && fix.visualNote && ( ! fix.risk || fix.visualNote.trim() !== fix.risk.trim() )
				? el( 'p', { className: 'ekwa-pa-visual' }, '⚠ ' + fix.visualNote ) : null,
			big && ! status.applied && 'manual' !== fix.kind ? el( 'div', { className: 'ekwa-pa-big' },
				el( 'p', null, __( 'This is a noticeable colour change — the design will look different. Compare the before and after samples above.', 'ekwa' ) ),
				el( CheckboxControl, {
					label: __( 'I understand this changes the colour', 'ekwa' ),
					checked: acked,
					onChange: function ( v ) { setIn( 'ack', fix.id, v ? true : undefined ); },
				} )
			) : null,
			fix.audit ? el( 'p', { className: 'ekwa-pa-muted ekwa-pa-audit-id' }, sprintf( /* translators: %s: Lighthouse audit id */ __( 'PageSpeed audit: %s', 'ekwa' ), fix.audit ) ) : null,
			el( Verification, { fix: fix, status: status } ),
			actions,
			el( Message, { message: store.messages[ fix.id ], onRemove: function () { say( fix.id, '', '' ); } } )
		);
	}

	// One collapsible category of fixes. Open/closed is kept in the shared
	// store so it survives re-renders (and the summary panel's updates).
	function FixGroup( props ) {
		var open    = !! store.groups[ props.id ];
		var applied = props.fixes.filter( function ( f ) { var st = fixStatus( f ); return st.applied || st.inEffect; } ).length;
		var manual  = props.fixes.filter( function ( f ) { return 'manual' === f.kind; } ).length;
		var panelId = 'ekwa-pa-group-' + props.id;
		var meta    = [ sprintf( /* translators: %d: number of fixes */ __( '%d fixes', 'ekwa' ), props.fixes.length ) ];
		if ( applied ) { meta.push( sprintf( /* translators: %d: number applied */ __( '%d applied', 'ekwa' ), applied ) ); }
		if ( manual ) { meta.push( sprintf( /* translators: %d: number of manual fixes */ __( '%d manual', 'ekwa' ), manual ) ); }
		return el( 'div', { className: 'ekwa-pa-group is-' + props.id + ( open ? ' is-open' : '' ) },
			el( 'button', {
				type: 'button',
				className: 'ekwa-pa-group__head',
				'aria-expanded': open,
				'aria-controls': panelId,
				onClick: function () {
					var next = assign( {}, store.groups );
					next[ props.id ] = ! open;
					setStore( { groups: next } );
				},
			},
				el( 'span', { className: 'ekwa-pa-group__chev', 'aria-hidden': 'true' }, open ? '▾' : '▸' ),
				el( 'span', { className: 'ekwa-pa-group__title' }, props.title ),
				el( 'span', { className: 'ekwa-pa-group__meta' }, meta.join( ' · ' ) )
			),
			open ? el( 'div', { className: 'ekwa-pa-group__body', id: panelId },
				props.fixes.map( function ( fix ) { return el( FixCard, { key: fix.id, fix: fix, postId: props.postId } ); } )
			) : null
		);
	}

	function AuditItem( props ) {
		var item  = props.item;
		var parts = [];
		if ( item.node ) {
			if ( item.node.label ) { parts.push( el( 'div', { key: 'l' }, item.node.label ) ); }
			if ( item.node.selector ) { parts.push( el( 'code', { key: 's', className: 'ekwa-pa-code' }, item.node.selector ) ); }
			if ( item.node.explanation ) { parts.push( el( 'div', { key: 'e', className: 'ekwa-pa-muted' }, item.node.explanation ) ); }
		}
		if ( item.url ) { parts.push( el( 'code', { key: 'u', className: 'ekwa-pa-code' }, truncate( item.url, 120 ) ) ); }
		if ( item.source ) { parts.push( el( 'code', { key: 'src', className: 'ekwa-pa-code' }, truncate( item.source, 120 ) ) ); }
		if ( item.entity ) { parts.push( el( 'div', { key: 'en' }, item.entity ) ); }
		var nums = [];
		if ( item.wastedBytes ) { nums.push( sprintf( __( '%s KiB savings', 'ekwa' ), Math.round( item.wastedBytes / 1024 ) ) ); }
		if ( item.wastedMs ) { nums.push( sprintf( __( '%s ms savings', 'ekwa' ), Math.round( item.wastedMs ) ) ); }
		if ( item.blockingTime ) { nums.push( sprintf( __( '%s ms blocking', 'ekwa' ), Math.round( item.blockingTime ) ) ); }
		if ( nums.length ) { parts.push( el( 'div', { key: 'n', className: 'ekwa-pa-muted' }, nums.join( ' · ' ) ) ); }
		return parts.length ? el( 'li', null, parts ) : null;
	}

	function AuditList( props ) {
		var audits = props.audits || [];
		if ( ! audits.length ) {
			return el( 'p', { className: 'ekwa-pa-muted' }, __( 'Nothing failing in this category.', 'ekwa' ) );
		}
		return el( 'ul', { className: 'ekwa-pa-audits' },
			audits.map( function ( a ) {
				return el( 'li', { key: a.id },
					el( 'details', null,
						el( 'summary', null,
							el( 'span', { className: 'ekwa-pa-dot ' + scoreClass( Math.round( ( a.score || 0 ) * 100 ) ), 'aria-hidden': 'true' } ),
							el( 'span', { className: 'ekwa-pa-audits__title' }, a.title ),
							a.display ? el( 'span', { className: 'ekwa-pa-muted' }, ' — ' + a.display ) : null
						),
						a.description ? el( 'p', { className: 'ekwa-pa-muted' }, a.description ) : null,
						a.items && a.items.length ? el( 'ul', { className: 'ekwa-pa-items' }, a.items.map( function ( item, i ) {
							return el( AuditItem, { key: i, item: item } );
						} ) ) : null
					)
				);
			} )
		);
	}

	function Metrics( props ) {
		var list = props.metrics || [];
		if ( ! list.length ) { return null; }
		return el( 'ul', { className: 'ekwa-pa-metrics' },
			list.map( function ( m ) {
				var s = null === m.score || undefined === m.score ? null : Math.round( m.score * 100 );
				return el( 'li', { key: m.id },
					el( 'span', { className: 'ekwa-pa-dot ' + scoreClass( s ), 'aria-hidden': 'true' } ),
					el( 'abbr', { title: m.title }, m.abbr ),
					el( 'span', { className: 'ekwa-pa-metrics__val' }, m.display || '–' )
				);
			} )
		);
	}

	// ─── Sections ───────────────────────────────────────────────────────────

	function usePost() {
		return useSelect( function ( sel ) {
			var ed = sel( 'core/editor' );
			if ( ! ed || ! ed.getCurrentPostId ) { return { ok: false }; }
			var id   = ed.getCurrentPostId();
			var type = ed.getCurrentPostType();
			var pt   = sel( 'core' ) && sel( 'core' ).getPostType ? sel( 'core' ).getPostType( type ) : null;
			var viewable = pt ? !! pt.viewable : ( 'page' === type || 'post' === type );
			return {
				ok:       'number' === typeof id && id > 0 && viewable,
				id:       id,
				status:   ed.getEditedPostAttribute( 'status' ),
				password: ed.getEditedPostAttribute( 'password' ),
				link:     ed.getPermalink ? ed.getPermalink() : '',
				dirty:    ed.isEditedPostDirty(),
			};
		}, [] );
	}

	function useTicker( on ) {
		var pair = useState( 0 );
		useEffect( function () {
			if ( ! on ) { return undefined; }
			var t = window.setInterval( function () { pair[ 1 ]( Date.now() ); }, 1000 );
			return function () { window.clearInterval( t ); };
		}, [ on ] );
	}

	function StrategySwitch( props ) {
		var s    = props.store;
		var ev   = ( s.data && s.data.evaluation ) || {};
		return el( 'div', { className: 'ekwa-pa-switch', role: 'group', 'aria-label': __( 'Device', 'ekwa' ) },
			[ [ 'mobile', __( 'Mobile', 'ekwa' ) ], [ 'desktop', __( 'Desktop', 'ekwa' ) ] ].map( function ( opt ) {
				var st = ev[ opt[ 0 ] ] ? ev[ opt[ 0 ] ].status : 'none';
				return el( Button, {
					key: opt[ 0 ],
					variant: s.strategy === opt[ 0 ] ? 'primary' : 'secondary',
					'aria-pressed': s.strategy === opt[ 0 ],
					onClick: function () { setStore( { strategy: opt[ 0 ] } ); },
				},
					opt[ 1 ],
					'none' !== st ? el( 'span', { className: 'ekwa-pa-switch__st is-' + st }, 'pass' === st ? __( 'PASS', 'ekwa' ) : __( 'FAIL', 'ekwa' ) ) : null
				);
			} )
		);
	}

	function ScoresSection( props ) {
		var s        = props.store;
		var post     = props.post;
		var data     = s.data || {};
		var report   = data.reports && data.reports[ s.strategy ];
		var d        = deltas( data.scores, s.strategy );
		var running  = s.running === s.strategy;
		var elapsed  = running ? Math.round( ( Date.now() - s.runStarted ) / 1000 ) : 0;
		var unpublished = 'publish' !== post.status || !! post.password;

		var sc       = ( report && report.scores ) || {};
		var bp       = sc[ 'best-practices' ];

		return el( PanelBody, { title: __( 'Scores', 'ekwa' ), initialOpen: true },
			el( StrategySwitch, { store: s } ),
			el( 'div', { className: 'ekwa-pa-gauges' },
				el( Gauge, { score: report ? sc.performance : null, label: __( 'Performance', 'ekwa' ), delta: d.p } ),
				el( Gauge, { score: report ? sc.accessibility : null, label: __( 'Accessibility', 'ekwa' ), delta: d.a } ),
				el( Gauge, { score: report && null != sc.seo ? sc.seo : null, label: __( 'SEO', 'ekwa' ), delta: d.o } )
			),
			report && null != bp ? el( 'p', { className: 'ekwa-pa-muted ekwa-pa-center' },
				sprintf( /* translators: %d: score */ __( 'Best Practices: %d (recorded, not part of PASS/FAIL)', 'ekwa' ), bp ) ) : null,
			report ? el( 'p', { className: 'ekwa-pa-muted ekwa-pa-center' },
				sprintf( /* translators: %s: date and time */ __( 'Tested %s', 'ekwa' ), when( report.fetched ) )
			) : el( 'p', { className: 'ekwa-pa-muted ekwa-pa-center' }, __( 'Not tested yet.', 'ekwa' ) ),
			report ? el( Verdict, { evaluation: ( data.evaluation || {} )[ s.strategy ], thresholds: data.thresholds } ) : null,
			report ? el( Metrics, { metrics: report.metrics } ) : null,
			report && report.field && report.field.overall ? el( 'p', { className: 'ekwa-pa-muted' },
				sprintf( /* translators: %s: FAST / AVERAGE / SLOW */ __( 'Real visitors (Chrome UX Report, 28 days): %s', 'ekwa' ), report.field.overall.toUpperCase() ) ) : null,

			unpublished ? el( Notice, { status: 'warning', isDismissible: false, className: 'ekwa-pa-notice' },
				__( 'PageSpeed tests the live page. Publish it (without a password) to run a test.', 'ekwa' ) ) : null,
			! unpublished && post.dirty ? el( Notice, { status: 'info', isDismissible: false, className: 'ekwa-pa-notice' },
				__( 'You have unsaved changes. The test measures the published page, so save first to include them.', 'ekwa' ) ) : null,
			! CFG.psiKey && ! ( data.psiKey ) ? el( 'p', { className: 'ekwa-pa-muted' },
				__( 'No PageSpeed API key is set, so Google allows only a few tests. ', 'ekwa' ),
				CFG.canManage ? el( 'a', { href: CFG.settingsUrl, target: '_blank', rel: 'noopener noreferrer' }, __( 'Add one in Ekwa Settings → Performance', 'ekwa' ) ) : null
			) : null,

			el( 'div', { className: 'ekwa-pa-actions' },
				el( Button, {
					variant: 'primary', isBusy: running, disabled: !! s.running || unpublished,
					onClick: function () { runTest( post.id, s.strategy ); },
				}, report ? __( 'Re-run test', 'ekwa' ) : __( 'Run PageSpeed test', 'ekwa' ) ),
				running ? el( 'span', { className: 'ekwa-pa-muted', 'aria-live': 'polite' },
					sprintf( /* translators: %d: seconds */ __( 'Testing… %ds (usually 20–60s)', 'ekwa' ), elapsed ) ) : null,
				! running && s.verifyQueued ? el( 'span', { className: 'ekwa-pa-muted', 'aria-live': 'polite' }, __( 'Re-testing in a few seconds to verify the applied fix…', 'ekwa' ) ) : null
			),
			s.runError ? el( Notice, { status: 'error', isDismissible: true, onRemove: function () { setStore( { runError: '' } ); }, className: 'ekwa-pa-notice' }, s.runError ) : null
		);
	}

	function IssuesSection( props ) {
		var s      = props.store;
		var report = s.data && s.data.reports && s.data.reports[ s.strategy ];
		if ( ! report ) { return null; }
		var perf = ( report.audits && report.audits.performance ) || [];
		var a11y = ( report.audits && report.audits.accessibility ) || [];
		var seo  = ( report.audits && report.audits.seo ) || [];
		return el( PanelBody, {
			title: sprintf( /* translators: %d: number of issues */ __( 'Issues found (%d)', 'ekwa' ), perf.length + a11y.length + seo.length ),
			initialOpen: false,
		},
			report.lcp ? el( 'div', { className: 'ekwa-pa-lcp' },
				el( 'strong', null, __( 'Largest Contentful Paint element', 'ekwa' ) ),
				report.lcp.label ? el( 'div', null, report.lcp.label ) : null,
				report.lcp.selector ? el( 'code', { className: 'ekwa-pa-code' }, report.lcp.selector ) : null
			) : null,
			el( 'h3', { className: 'ekwa-pa-h' }, sprintf( __( 'Performance (%d)', 'ekwa' ), perf.length ) ),
			el( AuditList, { audits: perf } ),
			el( 'h3', { className: 'ekwa-pa-h' }, sprintf( __( 'Accessibility (%d)', 'ekwa' ), a11y.length ) ),
			el( AuditList, { audits: a11y } ),
			report.scores && null != report.scores.seo ? el( Fragment, null,
				el( 'h3', { className: 'ekwa-pa-h' }, sprintf( __( 'SEO (%d)', 'ekwa' ), seo.length ) ),
				el( AuditList, { audits: seo } )
			) : null
		);
	}

	function AiSection( props ) {
		var s        = props.store;
		var post     = props.post;
		var data     = s.data || {};
		var report   = data.reports && data.reports[ s.strategy ];
		var analysis = data.analysis && data.analysis[ s.strategy ];
		var working  = s.analyzing === s.strategy;

		var body = [];
		if ( ! CFG.canAi ) {
			body.push( el( Notice, { key: 'noai', status: 'info', isDismissible: false, className: 'ekwa-pa-notice' },
				__( 'Set up an AI provider — a Gemini API key or the MCP connector — in Ekwa Settings → AI to get fixes.', 'ekwa' ), ' ',
				CFG.canManage ? el( 'a', { href: CFG.aiSettingsUrl, target: '_blank', rel: 'noopener noreferrer' }, __( 'Open AI settings', 'ekwa' ) ) : null
			) );
		} else {
			body.push( el( SelectControl, {
				key: 'focus',
				label: __( 'Look at', 'ekwa' ),
				value: s.focus,
				options: [
					{ value: 'all', label: __( 'Performance, accessibility and SEO', 'ekwa' ) },
					{ value: 'performance', label: __( 'Performance only', 'ekwa' ) },
					{ value: 'accessibility', label: __( 'Accessibility only', 'ekwa' ) },
					{ value: 'seo', label: __( 'SEO only', 'ekwa' ) },
				],
				onChange: function ( v ) { setStore( { focus: v } ); },
			} ) );
			if ( IS_MCP ) {
				body.push( el( 'p', { key: 'm', className: 'ekwa-pa-muted' }, __( 'Model: your connected AI app (MCP connector).', 'ekwa' ) ) );
			} else if ( CFG.models && CFG.models.length ) {
				body.push( el( SelectControl, {
					key: 'model',
					label: __( 'Model', 'ekwa' ),
					value: s.model,
					options: CFG.models,
					onChange: function ( v ) { setStore( { model: v } ); },
				} ) );
			}
			body.push( el( 'div', { key: 'go', className: 'ekwa-pa-actions' },
				el( Button, {
					variant: 'primary', icon: 'superhero', isBusy: working, disabled: !! s.analyzing || ! report,
					onClick: function () { analyze( post.id, s.strategy ); },
				}, analysis ? __( 'Analyze again', 'ekwa' ) : __( 'Find fixes with AI', 'ekwa' ) ),
				working ? el( Spinner, null ) : null
			) );
			if ( ! report ) {
				body.push( el( 'p', { key: 'nr', className: 'ekwa-pa-muted' }, __( 'Run the PageSpeed test first — the AI reads its report together with this page’s blocks and the site’s settings.', 'ekwa' ) ) );
			}
			if ( working ) {
				body.push( el( 'p', { key: 'w', className: 'ekwa-pa-muted', 'aria-live': 'polite' }, IS_MCP
					? __( 'Waiting for your AI app — see the panel at the bottom of the screen.', 'ekwa' )
					: __( 'Reading the report, the page and the site settings… this can take a minute.', 'ekwa' ) ) );
			}
		}
		if ( s.analyzeError ) {
			body.push( el( Notice, { key: 'err', status: 'error', isDismissible: true, onRemove: function () { setStore( { analyzeError: '' } ); }, className: 'ekwa-pa-notice' }, s.analyzeError ) );
		}

		if ( analysis ) {
			if ( report && analysis.report && analysis.report !== report.fetched ) {
				body.push( el( Notice, { key: 'stale', status: 'info', isDismissible: false, className: 'ekwa-pa-notice' },
					__( 'The test was run again after this analysis. Analyze again for fixes that match the new report.', 'ekwa' ) ) );
			}
			if ( analysis.summary ) {
				body.push( el( 'p', { key: 'sum', className: 'ekwa-pa-summary-text' }, analysis.summary ) );
			}
			body.push( el( 'p', { key: 'at', className: 'ekwa-pa-muted' },
				sprintf( /* translators: %s: date and time */ __( 'Analysed %s', 'ekwa' ), when( analysis.time ) ) +
				( 'mcp' === analysis.provider ? ' · ' + __( 'by your AI app', 'ekwa' ) : ( analysis.model ? ' · ' + analysis.model : '' ) ) ) );

			var fixes = analysis.fixes || [];
			if ( ! fixes.length ) {
				body.push( el( 'p', { key: 'none' }, __( 'No fixes suggested.', 'ekwa' ) ) );
			}
			[
				[ 'accessibility', __( 'Accessibility', 'ekwa' ) ],
				[ 'performance', __( 'Performance', 'ekwa' ) ],
				[ 'seo', __( 'SEO', 'ekwa' ) ],
			].forEach( function ( cat ) {
				var list = fixes.filter( function ( f ) { return ( f.category || 'performance' ) === cat[ 0 ]; } );
				if ( ! list.length ) { return; }
				body.push( el( FixGroup, { key: 'g-' + cat[ 0 ], id: cat[ 0 ], title: cat[ 1 ], fixes: list, postId: post.id } ) );
			} );
			if ( fixes.some( function ( f ) { return 'block' === f.kind; } ) ) {
				body.push( el( 'p', { key: 'save', className: 'ekwa-pa-muted' }, __( 'Block fixes change the page in the editor; save the page, then re-run the test to see the new score.', 'ekwa' ) ) );
			}
		}

		return el( PanelBody, { title: __( 'AI fixes', 'ekwa' ), initialOpen: true }, body );
	}

	function ChangesSection( props ) {
		var s       = props.store;
		var post    = props.post;
		var data    = s.data || {};
		var entries = [];
		( data.history || [] ).forEach( function ( h ) { entries.push( { kind: 'block', e: h } ); } );
		( data.log || [] ).forEach( function ( l ) { entries.push( { kind: 'setting', e: l } ); } );
		( data.changes || [] ).forEach( function ( c ) { entries.push( { kind: c.type, e: c } ); } );
		if ( ! entries.length ) { return null; }
		entries.sort( function ( a, b ) { return ( b.e.time || 0 ) - ( a.e.time || 0 ); } );

		return el( PanelBody, {
			title: sprintf( /* translators: %d: number of changes */ __( 'Changes made (%d)', 'ekwa' ), entries.length ),
			initialOpen: false,
		},
			el( 'ul', { className: 'ekwa-pa-changes' },
				entries.map( function ( row ) {
					var e       = row.e;
					var key     = 'c-' + e.id;
					var isBusy  = !! s.busy[ key ];
					var stateLb = '';
					var canUndo = false;
					if ( 'setting' === row.kind ) {
						canUndo = CFG.canManage && logUndoable( e );
						stateLb = e.undone ? __( 'Undone', 'ekwa' ) : ( canUndo ? __( 'Active (site-wide)', 'ekwa' ) : __( 'Changed again since', 'ekwa' ) );
					} else if ( 'css' === row.kind || 'part' === row.kind ) {
						canUndo = ( 'css' === row.kind ? CFG.canManage : CFG.canParts ) && !! e.undoable;
						stateLb = e.undone ? __( 'Undone', 'ekwa' ) : ( {
							applied:  __( 'Active (site-wide)', 'ekwa' ),
							reverted: __( 'Already reverted', 'ekwa' ),
							changed:  __( 'Edited since', 'ekwa' ),
							missing:  __( 'Block moved or removed', 'ekwa' ),
						}[ e.state ] || '' );
					} else if ( e.undone ) {
						stateLb = __( 'Undone', 'ekwa' );
					} else {
						var st = entryState( e );
						stateLb = {
							applied:  post.dirty ? __( 'Applied — unsaved', 'ekwa' ) : __( 'Applied', 'ekwa' ),
							reverted: __( 'Not on the page (reverted or never saved)', 'ekwa' ),
							changed:  __( 'Edited since', 'ekwa' ),
							missing:  __( 'Block moved or removed', 'ekwa' ),
						}[ st ];
						canUndo = 'applied' === st || 'reverted' === st;
					}
					var detail = {
						setting: function () { return sprintf( __( '%1$s: %2$s → %3$s', 'ekwa' ), e.label || e.setting, fmtValue( e.old ), fmtValue( e[ 'new' ] ) ); },
						css:     function () { return ( e.marker ? e.marker + ' · ' : '' ) + truncate( e.detail || '', 140 ); },
						part:    function () { return e.detail || ''; },
					}[ row.kind ];
					return el( 'li', { key: key },
						el( 'div', { className: 'ekwa-pa-changes__title' }, e.title || ( 'setting' === row.kind ? e.label : e.block ) ),
						el( 'div', { className: 'ekwa-pa-muted' },
							( detail ? detail() : sprintf( __( 'Block %s', 'ekwa' ), e.block ) ) +
							' · ' + when( e.time ) + ( e.user ? ' · ' + e.user : '' ) ),
						el( 'div', { className: 'ekwa-pa-actions' },
							el( 'span', { className: 'ekwa-pa-muted' }, stateLb ),
							! e.undone && canUndo ? el( Button, {
								variant: 'secondary', size: 'small', isBusy: isBusy, disabled: isBusy,
								onClick: function () {
									if ( 'setting' === row.kind ) {
										undoSetting( e, key );
									} else if ( 'css' === row.kind || 'part' === row.kind ) {
										undoChange( e, key );
									} else {
										undoBlockEntry( post.id, e, key );
									}
								},
							}, __( 'Undo', 'ekwa' ) ) : null,
							'css' === row.kind && ! e.undone && CFG.compareUrl ? el( Button, {
								variant: 'tertiary', size: 'small', href: CFG.compareUrl + '&change=' + encodeURIComponent( e.id ), target: '_blank', rel: 'noopener noreferrer',
							}, __( 'Compare', 'ekwa' ) ) : null
						),
						el( Message, { message: s.messages[ key ], onRemove: function () { say( key, '', '' ); } } )
					);
				} )
			)
		);
	}

	// ─── Plugin roots ───────────────────────────────────────────────────────

	function openSidebar() {
		var name = PLUGIN + '/' + SIDEBAR;
		var ep   = dispatch( 'core/edit-post' );
		if ( ep && ep.openGeneralSidebar ) {
			ep.openGeneralSidebar( name );
			return;
		}
		var ia = dispatch( 'core/interface' );
		if ( ia && ia.enableComplementaryArea ) {
			ia.enableComplementaryArea( 'core', name );
		}
	}

	function AuditSidebar() {
		var s    = useStore();
		var post = usePost();
		// Re-render when blocks change, so fix and change states stay current.
		useSelect( function ( sel ) { return sel( 'core/block-editor' ).getBlocks(); }, [] );
		var saving = useSelect( function ( sel ) {
			var ed = sel( 'core/editor' );
			return !! ( ed && ed.isSavingPost && ed.isSavingPost() && ! ( ed.isAutosavingPost && ed.isAutosavingPost() ) );
		}, [] );
		var wasSaving = wp.element.useRef( false );
		useEffect( function () {
			if ( wasSaving.current && ! saving && store.pendingSaveVerify ) {
				setStore( { pendingSaveVerify: false } );
				scheduleVerify( post.id );
			}
			wasSaving.current = saving;
		}, [ saving ] );
		useTicker( !! s.running );
		useEffect( function () {
			if ( post.ok ) { load( post.id ); }
		}, [ post.ok, post.id ] );

		if ( ! post.ok ) {
			return null;
		}

		var title = __( 'Page Speed & Accessibility', 'ekwa' );
		var inner;
		if ( s.loading && ! s.data ) {
			inner = el( 'div', { className: 'ekwa-pa-pad' }, el( Spinner, null ) );
		} else if ( s.loadError && ! s.data ) {
			inner = el( 'div', { className: 'ekwa-pa-pad' },
				el( Notice, { status: 'error', isDismissible: false }, s.loadError ),
				el( Button, { variant: 'secondary', onClick: function () { load( post.id, true ); } }, __( 'Try again', 'ekwa' ) )
			);
		} else {
			inner = el( 'div', { className: 'ekwa-pa' },
				el( ScoresSection, { store: s, post: post } ),
				el( IssuesSection, { store: s } ),
				el( AiSection, { store: s, post: post } ),
				el( ChangesSection, { store: s, post: post } )
			);
		}

		return el( Fragment, null,
			PluginSidebarMoreMenuItem ? el( PluginSidebarMoreMenuItem, { target: SIDEBAR, icon: ICON }, title ) : null,
			el( PluginSidebar, { name: SIDEBAR, title: title, icon: ICON, className: 'ekwa-pa-sidebar' }, inner )
		);
	}

	registerPluginSafe( PLUGIN, { render: AuditSidebar, icon: ICON } );

	function SummaryPanel() {
		var s    = useStore();
		var post = usePost();
		useEffect( function () {
			if ( post.ok ) { load( post.id ); }
		}, [ post.ok, post.id ] );

		if ( ! post.ok || ! PluginDocumentSettingPanel ) {
			return null;
		}
		var reports = ( s.data && s.data.reports ) || {};
		var mobile  = reports.mobile;
		var desktop = reports.desktop;

		var evals = ( s.data && s.data.evaluation ) || {};
		var row = function ( label, rep, key ) {
			return el( 'div', { className: 'ekwa-pa-summary-row' },
				el( 'span', { className: 'ekwa-pa-summary-row__label' }, label, el( StatusChip, { evaluation: evals[ key ], small: true } ) ),
				el( Gauge, { small: true, score: rep ? rep.scores.performance : null, label: __( 'Performance', 'ekwa' ) } ),
				el( Gauge, { small: true, score: rep ? rep.scores.accessibility : null, label: __( 'Accessibility', 'ekwa' ) } ),
				el( Gauge, { small: true, score: rep && null != rep.scores.seo ? rep.scores.seo : null, label: __( 'SEO', 'ekwa' ) } )
			);
		};

		return el( PluginDocumentSettingPanel, { name: 'ekwa-page-audit-summary', title: __( 'Page Speed & Accessibility', 'ekwa' ), className: 'ekwa-pa-summary' },
			mobile || desktop
				? el( Fragment, null,
					mobile ? row( __( 'Mobile', 'ekwa' ), mobile, 'mobile' ) : null,
					desktop ? row( __( 'Desktop', 'ekwa' ), desktop, 'desktop' ) : null,
					el( 'p', { className: 'ekwa-pa-muted' }, sprintf( __( 'Tested %s', 'ekwa' ), when( ( mobile || desktop ).fetched ) ) )
				)
				: el( 'p', { className: 'ekwa-pa-muted' }, __( 'Not tested yet. Test the published page and get AI fixes for speed, accessibility and SEO.', 'ekwa' ) ),
			el( Button, { variant: 'secondary', icon: ICON, onClick: openSidebar }, __( 'Open the audit', 'ekwa' ) ),
			CFG.dashboardUrl ? el( 'p', { className: 'ekwa-pa-muted' }, el( 'a', { href: CFG.dashboardUrl }, __( 'All pages: Site Audit → PageSpeed', 'ekwa' ) ) ) : null
		);
	}

	if ( PluginDocumentSettingPanel ) {
		registerPluginSafe( PLUGIN + '-summary', { render: SummaryPanel, icon: ICON } );
	}

	// Opened from the dashboard ("Open audit"): show the full audit right away.
	try {
		if ( window.URLSearchParams && new window.URLSearchParams( window.location.search ).get( 'ekwa_pa' ) ) {
			wp.domReady ? wp.domReady( function () { window.setTimeout( openSidebar, 400 ); } ) : window.setTimeout( openSidebar, 1200 );
		}
	} catch ( e ) {}

	function registerPluginSafe( name, settings ) {
		try {
			if ( wp.plugins.getPlugin && wp.plugins.getPlugin( name ) ) { return; }
			wp.plugins.registerPlugin( name, settings );
		} catch ( e ) {
			if ( window.console ) { window.console.warn( '[ekwa-page-audit]', e ); }
		}
	}
} )( window.wp );
