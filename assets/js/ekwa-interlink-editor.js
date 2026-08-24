/**
 * Ekwa Internal Links — Gutenberg editor sidebar.
 *
 * Scans the page being edited for phrases that match other published pages (and
 * the Practice Name / Appointment / Directions settings targets) and proposes
 * one-click internal links. Each destination is linked at most once per page:
 * a destination the page already links to — by anchor, or by a block-level Link
 * Source — is dropped from the suggestions entirely. Headings are left alone.
 *
 * Pipeline: fetch targets (REST) → scan blocks → match phrases in rich-text
 * fields (text nodes only, skipping existing links and headings) → apply by
 * wrapping the matched text in an <a> and writing back via
 * updateBlockAttributes (undo-safe).
 *
 * Plain wp.element (no JSX / build step), matching the theme's other editor JS.
 *
 * @package ekwa
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.data || ! wp.blocks ) {
		return;
	}

	var el            = wp.element.createElement;
	var Fragment      = wp.element.Fragment;
	var useState      = wp.element.useState;
	var useRef        = wp.element.useRef;
	var registerPlugin = wp.plugins.registerPlugin;
	var Button        = wp.components.Button;
	var Spinner       = wp.components.Spinner;
	var Notice        = wp.components.Notice;
	var SelectControl = wp.components.SelectControl;
	var apiFetch      = wp.apiFetch;
	var __            = wp.i18n.__;

	var PluginSidebar = ( wp.editor && wp.editor.PluginSidebar )
		|| ( wp.editPost && wp.editPost.PluginSidebar ) || null;
	var PluginSidebarMoreMenuItem = ( wp.editor && wp.editor.PluginSidebarMoreMenuItem )
		|| ( wp.editPost && wp.editPost.PluginSidebarMoreMenuItem ) || null;

	if ( ! PluginSidebar ) {
		return; // No sidebar host (very old WP) — nothing to render.
	}

	var CFG           = window.ekwaInterlink || {};
	var MODEL_OPTIONS = Array.isArray( CFG.models ) && CFG.models.length
		? CFG.models
		// PHP always sends the list; this only fires if localization failed.
		// An empty value means "let the server pick", so it can never go stale.
		: [ { value: '', label: __( 'Theme default', 'ekwa' ) } ];
	var DEFAULT_MODEL = CFG.defaultModel || MODEL_OPTIONS[0].value;
	var HAS_API_KEY   = !! CFG.hasApiKey;

	var SIDEBAR_NAME = 'ekwa-internal-links';

	// ─── Block scanning ───────────────────────────────────────────────────────

	// Blocks whose text renders as a heading. Headings are never linked: the
	// anchor competes with the heading's own role as the section's label, and
	// a link there reads as navigation rather than as a reference.
	var HEADING_BLOCKS = {
		'core/heading':      true,
		'core/post-title':   true,
		'core/site-title':   true,
		'core/query-title':  true,
		'ekwa/faq-question': true,
		'ekwa/page-title':   true
	};

	// True for a heading block, including a wrapper set to an h1–h6 tag — in
	// which case everything nested inside it is heading text too.
	function isHeadingBlock( block ) {
		if ( ! block || ! block.name ) {
			return false;
		}
		if ( HEADING_BLOCKS[ block.name ] ) {
			return true;
		}
		var tag = block.attributes && block.attributes.tagName;
		return !! ( tag && /^h[1-6]$/i.test( String( tag ) ) );
	}

	var richAttrCache = {};

	// Rich-text-backed attribute keys for a block type (source html | rich-text).
	function richTextAttrKeys( blockName ) {
		if ( richAttrCache[ blockName ] ) {
			return richAttrCache[ blockName ];
		}
		var type = wp.blocks.getBlockType( blockName );
		var keys = [];
		if ( type && type.attributes ) {
			Object.keys( type.attributes ).forEach( function ( key ) {
				var def = type.attributes[ key ];
				if ( def && ( def.source === 'html' || def.source === 'rich-text' ) ) {
					keys.push( key );
				}
			} );
		}
		richAttrCache[ blockName ] = keys;
		return keys;
	}

	// Coerce a rich-text attribute value to a plain HTML string.
	function toHtmlString( value ) {
		if ( value === null || value === undefined ) {
			return '';
		}
		if ( typeof value === 'string' ) {
			return value;
		}
		if ( typeof value.toHTMLString === 'function' ) {
			return value.toHTMLString();
		}
		return String( value );
	}

	// Depth-first walk yielding { clientId, attrKey, html } for each linkable
	// rich field. Heading blocks are skipped along with anything nested inside
	// them, so a paragraph placed inside an h2 wrapper is skipped too.
	function forEachRichField( blocks, cb ) {
		for ( var i = 0; i < blocks.length; i++ ) {
			var block = blocks[ i ];
			if ( ! block || isHeadingBlock( block ) ) {
				continue;
			}
			var keys = richTextAttrKeys( block.name );
			for ( var k = 0; k < keys.length; k++ ) {
				var attrKey = keys[ k ];
				var html = toHtmlString( block.attributes ? block.attributes[ attrKey ] : '' );
				if ( html ) {
					cb( { clientId: block.clientId, attrKey: attrKey, html: html } );
				}
			}
			if ( block.innerBlocks && block.innerBlocks.length ) {
				forEachRichField( block.innerBlocks, cb );
			}
		}
	}

	// ─── HTML parsing / matching ────────────────────────────────────────────────

	function parseFragment( html ) {
		var doc = new DOMParser().parseFromString(
			'<!doctype html><body>' + html + '</body>', 'text/html'
		);
		return doc.body;
	}

	// Elements whose text is off limits: already a link, code, or a heading
	// written as markup inside an otherwise ordinary field.
	var UNLINKABLE_TAGS = {
		A: true, CODE: true, PRE: true,
		H1: true, H2: true, H3: true, H4: true, H5: true, H6: true
	};

	// True when a text node sits inside an element we must not link into.
	function isUnlinkable( node ) {
		var p = node.parentNode;
		while ( p && p.nodeType === 1 ) {
			if ( UNLINKABLE_TAGS[ p.tagName ] ) {
				return true;
			}
			p = p.parentNode;
		}
		return false;
	}

	// Linkable text runs with cumulative offsets into one concatenated string,
	// plus the normalised form findPhrase() matches against.
	function collectRuns( bodyEl ) {
		var walker = bodyEl.ownerDocument.createTreeWalker( bodyEl, NodeFilter.SHOW_TEXT, null );
		var runs = [];
		var plain = '';
		var node;
		while ( ( node = walker.nextNode() ) ) {
			if ( isUnlinkable( node ) ) {
				continue;
			}
			var text = node.nodeValue || '';
			if ( ! text ) {
				continue;
			}
			runs.push( { node: node, start: plain.length, end: plain.length + text.length } );
			plain += text;
		}
		return { runs: runs, plain: plain, norm: normalizeForMatch( plain ) };
	}

	/**
	 * Collapse whitespace runs to a single space and treat commas as whitespace,
	 * keeping a map from each normalised character back to its index in `plain`.
	 *
	 * Addresses are why this exists. "1234 Main St, Springfield, FL 33511" in
	 * Settings has to match copy that wraps the address over two lines, uses a
	 * non-breaking space, or leaves the commas out — a literal indexOf misses
	 * all three. Ordinary title keywords benefit from the same tolerance.
	 *
	 * @param {string} plain Concatenated text of the linkable runs.
	 * @return {{text: string, map: number[]}} Normalised text and its index map.
	 */
	function normalizeForMatch( plain ) {
		var text = '';
		var map  = [];
		var pendingSpace = false;
		for ( var i = 0; i < plain.length; i++ ) {
			var ch = plain.charAt( i );
			if ( ',' === ch || /\s/.test( ch ) ) {
				// Leading separators are dropped outright; the rest collapse to
				// one space, which is enough to keep words apart.
				if ( text.length ) {
					pendingSpace = true;
				}
				continue;
			}
			if ( pendingSpace ) {
				text += ' ';
				map.push( i );      // the space stands in for the run before `i`
				pendingSpace = false;
			}
			text += ch;
			map.push( i );
		}
		return { text: text, map: map };
	}

	function isWordChar( ch ) {
		return ch !== null && ch !== undefined && /[A-Za-z0-9]/.test( ch );
	}

	/**
	 * First whole-word, case-insensitive occurrence of `phrase`.
	 *
	 * Both sides go through normalizeForMatch(), so the returned offsets are
	 * translated back to the original text and still span the real characters —
	 * commas and line breaks included — for wrapMatch() to slice.
	 *
	 * @param {{text: string, map: number[]}} norm   Normalised haystack.
	 * @param {string}                        phrase Candidate anchor phrase.
	 * @return {?{index: number, length: number}} Offsets into the original text.
	 */
	function findPhrase( norm, phrase ) {
		var needle = normalizeForMatch( String( phrase || '' ) ).text;
		if ( ! needle ) {
			return null;
		}
		var hay = norm.text.toLowerCase();
		var ndl = needle.toLowerCase();
		if ( ndl.length > hay.length ) {
			return null;
		}
		var from = 0;
		while ( from <= hay.length - ndl.length ) {
			var idx = hay.indexOf( ndl, from );
			if ( idx === -1 ) {
				return null;
			}
			var before = idx > 0 ? norm.text.charAt( idx - 1 ) : null;
			var after  = ( idx + ndl.length ) < norm.text.length ? norm.text.charAt( idx + ndl.length ) : null;
			var leftOk  = ! isWordChar( before ) || ! isWordChar( needle.charAt( 0 ) );
			var rightOk = ! isWordChar( after )  || ! isWordChar( needle.charAt( needle.length - 1 ) );
			if ( leftOk && rightOk ) {
				var start = norm.map[ idx ];
				var end   = norm.map[ idx + ndl.length - 1 ] + 1;
				return { index: start, length: end - start };
			}
			from = idx + 1;
		}
		return null;
	}

	// Wrap the matched range in <a href>. When linkType is 'appointment', marks
	// the anchor for ekwa_resolve_appointment_link_format() to keep re-resolving
	// on every render instead of baking in a URL that goes stale — see
	// ekwa-appointment-link-format.js. Returns true on success.
	function wrapMatch( bodyEl, runs, hit, url, linkType ) {
		var containing = null;
		for ( var i = 0; i < runs.length; i++ ) {
			if ( hit.index >= runs[ i ].start && hit.index < runs[ i ].end ) {
				containing = runs[ i ];
				break;
			}
		}
		if ( ! containing ) {
			return false;
		}
		// Skip matches that span an inline element boundary — too risky to wrap.
		if ( hit.index + hit.length > containing.end ) {
			return false;
		}

		var node = containing.node;
		var localStart = hit.index - containing.start;
		var localEnd   = localStart + hit.length;

		node.splitText( localEnd );             // tail after the match
		var mid = node.splitText( localStart );  // mid = exactly the matched text

		var anchor = bodyEl.ownerDocument.createElement( 'a' );
		anchor.setAttribute( 'href', url );
		if ( 'appointment' === linkType ) {
			anchor.className = 'ekwa-appointment-link';
			anchor.setAttribute( 'data-ekwa-link-type', 'appointment' );
		}
		anchor.textContent = mid.nodeValue;      // preserve original casing
		mid.parentNode.replaceChild( anchor, mid );
		return true;
	}

	// ─── Writing back to the store ──────────────────────────────────────────────

	function makeAttrValue( blockName, attrKey, newHtml ) {
		var type = wp.blocks.getBlockType( blockName );
		var def  = type && type.attributes ? type.attributes[ attrKey ] : null;
		if ( def && def.source === 'rich-text' && wp.richText && wp.richText.RichTextData
			&& typeof wp.richText.RichTextData.fromHTMLString === 'function' ) {
			return wp.richText.RichTextData.fromHTMLString( newHtml );
		}
		return newHtml; // source: 'html' (or fallback) — plain HTML string.
	}

	function writeField( clientId, blockName, attrKey, newHtml ) {
		var change = {};
		change[ attrKey ] = makeAttrValue( blockName, attrKey, newHtml );
		wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( clientId, change );
	}

	// ─── Existing links ─────────────────────────────────────────────────────────

	/**
	 * A comparable key for a URL: host + path + query, ignoring the scheme,
	 * "www.", a trailing slash and the fragment.
	 *
	 * "/about-us/" and "https://www.example.com/about-us" are the same
	 * destination, and a page must not end up linking both.
	 *
	 * @param {string} url Absolute or root-relative URL.
	 * @return {string} Key, or '' for anything that isn't a page destination.
	 */
	function urlKey( url ) {
		var raw = String( url || '' ).trim();
		if ( ! raw || /^(#|mailto:|tel:|javascript:)/i.test( raw ) ) {
			return '';
		}
		var parsed;
		try {
			parsed = new URL( raw, window.location.origin );
		} catch ( e ) {
			return '';
		}
		var host = parsed.hostname.toLowerCase().replace( /^www\./, '' );
		var path = parsed.pathname.replace( /\/+$/, '' );
		return host + ( path || '/' ) + ( parsed.search || '' );
	}

	// Keys identifying a candidate's destination. Any one of them already in the
	// document means this destination is linked and the candidate is dropped.
	function candidateKeys( c ) {
		var keys = [ c.destKey ];
		if ( 'appointment' === c.linkType ) {
			keys.push( 'type:appointment' );
		}
		if ( c.postId ) {
			keys.push( 'post:' + c.postId );
		}
		return keys;
	}

	/**
	 * Every destination the page already links to.
	 *
	 * Covers anchors written into rich text (including ones this sidebar added
	 * on an earlier pass, which is what stops a second link to the same page
	 * being proposed after a save) and the block-level Link Source control on
	 * ekwa/link, ekwa/button, ekwa/div and friends — that one stores a post ID
	 * rather than a URL, hence the "post:" keys.
	 *
	 * Headings are deliberately included: a link already sitting in an H2 still
	 * counts as linking that page, even though we would never add one there.
	 *
	 * @return {Object} Key set.
	 */
	function collectExistingKeys() {
		var keys = {};

		function note( key ) {
			if ( key ) {
				keys[ key ] = true;
			}
		}

		function walk( blocks ) {
			for ( var i = 0; i < blocks.length; i++ ) {
				var block = blocks[ i ];
				if ( ! block ) {
					continue;
				}
				var attrs = block.attributes || {};

				if ( 'appointment' === attrs.linkType ) {
					note( 'type:appointment' );
				} else if ( 'internal' === attrs.linkType && attrs.pageId ) {
					note( 'post:' + attrs.pageId );
				}
				note( typeof attrs.url === 'string' ? urlKey( attrs.url ) : '' );
				note( typeof attrs.href === 'string' ? urlKey( attrs.href ) : '' );

				richTextAttrKeys( block.name ).forEach( function ( attrKey ) {
					var html = toHtmlString( attrs[ attrKey ] );
					if ( ! html || html.indexOf( '<a' ) === -1 ) {
						return;
					}
					var anchors = parseFragment( html ).querySelectorAll( 'a' );
					for ( var a = 0; a < anchors.length; a++ ) {
						if ( 'appointment' === anchors[ a ].getAttribute( 'data-ekwa-link-type' ) ) {
							note( 'type:appointment' );
						}
						note( urlKey( anchors[ a ].getAttribute( 'href' ) ) );
					}
				} );

				if ( block.innerBlocks && block.innerBlocks.length ) {
					walk( block.innerBlocks );
				}
			}
		}

		walk( wp.data.select( 'core/block-editor' ).getBlocks() );
		return keys;
	}

	// ─── Suggestion collection ──────────────────────────────────────────────────

	// Flatten targets ({postId,title,url,keywords[]}) into candidate phrases,
	// longest first. Each target's destination key is resolved once here — it
	// both de-dupes suggestions and is how an existing link is recognised, and
	// parsing the URL again for every phrase and every field would be wasteful.
	function buildCandidates( targets, currentUrl ) {
		var selfKey = urlKey( currentUrl );
		var list = [];
		( targets || [] ).forEach( function ( t ) {
			if ( ! t || ! t.url ) {
				return;
			}
			var destKey = urlKey( t.url ) || t.url;
			if ( selfKey && destKey === selfKey ) {
				return; // No self-links, however the two URLs are spelled.
			}
			( t.keywords || [] ).forEach( function ( kw ) {
				kw = String( kw || '' ).trim();
				if ( kw.length >= 3 ) {
					list.push( {
						phrase:   kw,
						url:      t.url,
						destKey:  destKey,
						postId:   t.postId || 0,
						title:    t.title || t.url,
						linkType: t.linkType || ''
					} );
				}
			} );
		} );
		list.sort( function ( a, b ) { return b.phrase.length - a.phrase.length; } );
		return list;
	}

	// Scan the document and return one suggestion per destination page (topic).
	// Candidates are sorted longest-phrase-first and fields walked in document
	// order, so the most specific phrase in the earliest block wins per target.
	// Destinations the page already links to are skipped outright.
	function collectSuggestions( candidates ) {
		var blocks = wp.data.select( 'core/block-editor' ).getBlocks();
		var existing = collectExistingKeys();
		var usedDest = {};
		var suggestions = [];

		// Destinations the page already links to drop out before the scan, so
		// the per-field loop below only ever considers open ones.
		var open = candidates.filter( function ( c ) {
			return ! candidateKeys( c ).some( function ( key ) { return existing[ key ]; } );
		} );

		forEachRichField( blocks, function ( field ) {
			var collected = collectRuns( parseFragment( field.html ) );
			if ( ! collected.plain ) {
				return;
			}
			open.forEach( function ( c ) {
				if ( usedDest[ c.destKey ] ) {
					return;
				}
				var hit = findPhrase( collected.norm, c.phrase );
				if ( hit ) {
					usedDest[ c.destKey ] = true;
					suggestions.push( {
						phrase:   c.phrase,
						url:      c.url,
						destKey:  c.destKey,
						postId:   c.postId,
						title:    c.title,
						linkType: c.linkType,
						clientId: field.clientId,
						attrKey:  field.attrKey
					} );
				}
			} );
		} );

		return suggestions;
	}

	// ─── Applying ───────────────────────────────────────────────────────────────

	function getLiveHtml( clientId, attrKey ) {
		var block = wp.data.select( 'core/block-editor' ).getBlock( clientId );
		if ( ! block ) {
			return null;
		}
		return { block: block, html: toHtmlString( block.attributes[ attrKey ] ) };
	}

	// Apply a single suggestion against the live block content.
	function applyOne( s ) {
		var live = getLiveHtml( s.clientId, s.attrKey );
		if ( ! live ) {
			return false;
		}
		var body = parseFragment( live.html );
		var collected = collectRuns( body );
		var hit = findPhrase( collected.norm, s.phrase );
		if ( ! hit || ! wrapMatch( body, collected.runs, hit, s.url, s.linkType ) ) {
			return false;
		}
		writeField( s.clientId, live.block.name, s.attrKey, body.innerHTML );
		return true;
	}

	// Apply many suggestions: one store write per field, re-matching live each wrap.
	function applyMany( suggestions ) {
		var groups = {};
		suggestions.forEach( function ( s ) {
			var k = s.clientId + '|' + s.attrKey;
			if ( ! groups[ k ] ) {
				groups[ k ] = { clientId: s.clientId, attrKey: s.attrKey, items: [] };
			}
			groups[ k ].items.push( s );
		} );

		var run = function () {
			Object.keys( groups ).forEach( function ( k ) {
				var g = groups[ k ];
				var live = getLiveHtml( g.clientId, g.attrKey );
				if ( ! live ) {
					return;
				}
				var body = parseFragment( live.html );
				// Longest phrase first so overlaps resolve to the specific link.
				g.items.sort( function ( a, b ) { return b.phrase.length - a.phrase.length; } );
				var changed = false;
				g.items.forEach( function ( s ) {
					var collected = collectRuns( body ); // re-collect → no stale offsets
					var hit = findPhrase( collected.norm, s.phrase );
					if ( hit && wrapMatch( body, collected.runs, hit, s.url, s.linkType ) ) {
						changed = true;
					}
				} );
				if ( changed ) {
					writeField( g.clientId, live.block.name, g.attrKey, body.innerHTML );
				}
			} );
		};

		// Collapse all writes into a single undo step when batching is available.
		if ( wp.data.batch ) {
			wp.data.batch( run );
		} else {
			run();
		}
	}

	// ─── Sidebar UI ───────────────────────────────────────────────────────────

	function currentPostId() {
		var ed = wp.data.select( 'core/editor' );
		return ed && ed.getCurrentPostId ? ed.getCurrentPostId() : 0;
	}

	function currentPostUrl() {
		var ed = wp.data.select( 'core/editor' );
		return ed && ed.getEditedPostAttribute ? ( ed.getEditedPostAttribute( 'link' ) || '' ) : '';
	}

	function Sidebar() {
		var sg = useState( [] );      var suggestions = sg[0]; var setSuggestions = sg[1];
		var st = useState( 'idle' );  var status = st[0];      var setStatus = st[1];   // idle|loading|ready
		var er = useState( '' );      var error = er[0];       var setError = er[1];
		var rf = useState( false );   var refining = rf[0];    var setRefining = rf[1];
		var md = useState( DEFAULT_MODEL ); var model = md[0];  var setModel = md[1];
		var targetsRef = useRef( null );

		function fetchTargets() {
			if ( targetsRef.current ) {
				return Promise.resolve( targetsRef.current );
			}
			return apiFetch( { path: '/ekwa/v1/interlink-targets?exclude=' + currentPostId() } )
				.then( function ( res ) {
					targetsRef.current = ( res && res.targets ) || [];
					return targetsRef.current;
				} );
		}

		function scan() {
			setStatus( 'loading' );
			setError( '' );
			fetchTargets().then( function ( targets ) {
				var candidates = buildCandidates( targets, currentPostUrl() );
				setSuggestions( collectSuggestions( candidates ) );
				setStatus( 'ready' );
			} ).catch( function ( e ) {
				setError( ( e && e.message ) || __( 'Could not load link targets.', 'ekwa' ) );
				setStatus( 'ready' );
			} );
		}

		function onApply( s ) {
			applyOne( s );
			rescanFromCache();
		}

		function onApplyAll() {
			applyMany( suggestions.slice() );
			rescanFromCache();
		}

		function onSkip( s ) {
			setSuggestions( suggestions.filter( function ( x ) { return x !== s; } ) );
		}

		// Re-scan using already-fetched targets (applied phrases drop out).
		function rescanFromCache() {
			var candidates = buildCandidates( targetsRef.current || [], currentPostUrl() );
			setSuggestions( collectSuggestions( candidates ) );
		}

		function onRefine() {
			if ( ! suggestions.length ) {
				return;
			}
			setRefining( true );
			setError( '' );
			var text = ( wp.data.select( 'core/editor' ).getEditedPostContent
				? wp.data.select( 'core/editor' ).getEditedPostContent() : '' );
			apiFetch( {
				path: '/ekwa/v1/interlink-refine',
				method: 'POST',
				data: {
					candidates: suggestions.map( function ( s ) {
						return { phrase: s.phrase, url: s.url, title: s.title };
					} ),
					text: text,
					model: model
				}
			} ).then( function ( res ) {
				var kept = ( res && res.candidates ) || [];
				// Keep only suggestions the AI returned (match by phrase+url).
				var keepSet = {};
				kept.forEach( function ( c ) { keepSet[ c.phrase.toLowerCase() + '|' + c.url ] = true; } );
				setSuggestions( suggestions.filter( function ( s ) {
					return keepSet[ s.phrase.toLowerCase() + '|' + s.url ];
				} ) );
				setRefining( false );
			} ).catch( function ( e ) {
				setError( ( e && e.message ) || __( 'AI refine failed.', 'ekwa' ) );
				setRefining( false );
			} );
		}

		// ── render helpers ──
		function row( s, i ) {
			return el( 'div', { key: i, className: 'ekwa-interlink-row', style: {
				borderBottom: '1px solid #e0e0e0', padding: '10px 0'
			} },
				el( 'div', { style: { fontWeight: 600, marginBottom: 2 } }, '“' + s.phrase + '”' ),
				el( 'div', { style: { fontSize: 12, color: '#555', marginBottom: 6, wordBreak: 'break-word' } },
					'→ ' + s.title ),
				el( 'div', { style: { display: 'flex', gap: 8 } },
					el( Button, { variant: 'primary', isSmall: true, onClick: function () { onApply( s ); } },
						__( 'Apply', 'ekwa' ) ),
					el( Button, { variant: 'tertiary', isSmall: true, onClick: function () { onSkip( s ); } },
						__( 'Skip', 'ekwa' ) )
				)
			);
		}

		var body;
		if ( status === 'idle' ) {
			body = el( 'p', null, __( 'Scan this page for internal links to other pages.', 'ekwa' ) );
		} else if ( status === 'loading' ) {
			body = el( 'div', { style: { display: 'flex', alignItems: 'center', gap: 8 } },
				el( Spinner, null ), el( 'span', null, __( 'Scanning…', 'ekwa' ) ) );
		} else if ( ! suggestions.length ) {
			body = el( 'p', null, __( 'No internal link suggestions found on this page.', 'ekwa' ) );
		} else {
			body = el( 'div', null,
				el( 'p', { style: { fontSize: 12, color: '#555' } },
					suggestions.length + ' ' + __( 'suggestion(s). Each page is linked once, and never from a heading.', 'ekwa' ) ),
				suggestions.map( row )
			);
		}

		var sidebarChildren = el( 'div', { style: { padding: '16px' } },
			error ? el( Notice, { status: 'error', isDismissible: false }, error ) : null,
			el( 'div', { style: { display: 'flex', gap: 8, marginBottom: 12, flexWrap: 'wrap' } },
				el( Button, { variant: 'secondary', onClick: scan, disabled: status === 'loading' },
					status === 'idle' ? __( 'Scan page', 'ekwa' ) : __( 'Rescan', 'ekwa' ) ),
				el( Button, {
					variant: 'primary',
					onClick: onApplyAll,
					disabled: ! suggestions.length
				}, __( 'Apply all', 'ekwa' ) )
			),
			HAS_API_KEY ? el( 'div', { style: { marginBottom: 12 } },
				el( SelectControl, {
					label: __( 'Refine model', 'ekwa' ),
					value: model,
					options: MODEL_OPTIONS,
					onChange: setModel
				} ),
				el( Button, {
					variant: 'secondary',
					onClick: onRefine,
					disabled: refining || ! suggestions.length,
					icon: 'superhero'
				}, refining ? __( 'Refining…', 'ekwa' ) : __( 'Refine with AI', 'ekwa' ) ),
				refining ? el( Spinner, null ) : null
			) : null,
			body
		);

		return el( Fragment, null,
			PluginSidebarMoreMenuItem ? el( PluginSidebarMoreMenuItem,
				{ target: SIDEBAR_NAME, icon: 'admin-links' },
				__( 'Internal Links', 'ekwa' ) ) : null,
			el( PluginSidebar,
				{ name: SIDEBAR_NAME, title: __( 'Internal Links', 'ekwa' ), icon: 'admin-links' },
				sidebarChildren )
		);
	}

	registerPlugin( 'ekwa-internal-links', { render: Sidebar, icon: 'admin-links' } );

} )( window.wp );
