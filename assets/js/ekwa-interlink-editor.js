/**
 * Ekwa Internal Links — Gutenberg editor sidebar.
 *
 * Scans the page being edited for phrases that match other published pages (and
 * the Practice Name / Appointment / Directions settings targets) and proposes
 * one-click internal links. Each phrase is linked only once (first occurrence).
 *
 * Pipeline: fetch targets (REST) → scan blocks → match phrases in rich-text
 * fields (text nodes only, skipping existing links) → apply by wrapping the
 * matched text in an <a> and writing back via updateBlockAttributes (undo-safe).
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
		: [ { value: 'gemini-2.5-flash', label: 'Gemini 2.5 Flash' } ];
	var DEFAULT_MODEL = CFG.defaultModel || MODEL_OPTIONS[0].value;
	var HAS_API_KEY   = !! CFG.hasApiKey;

	var SIDEBAR_NAME = 'ekwa-internal-links';

	// ─── Block scanning ───────────────────────────────────────────────────────

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

	// Depth-first walk yielding { clientId, attrKey, html } for each rich field.
	function forEachRichField( blocks, cb ) {
		for ( var i = 0; i < blocks.length; i++ ) {
			var block = blocks[ i ];
			if ( ! block ) {
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

	// True when a text node sits inside an element we must not link into.
	function isInsideLink( node ) {
		var p = node.parentNode;
		while ( p && p.nodeType === 1 ) {
			var tag = p.tagName;
			if ( tag === 'A' || tag === 'CODE' || tag === 'PRE' ) {
				return true;
			}
			p = p.parentNode;
		}
		return false;
	}

	// Linkable text runs with cumulative offsets into one concatenated string.
	function collectRuns( bodyEl ) {
		var walker = bodyEl.ownerDocument.createTreeWalker( bodyEl, NodeFilter.SHOW_TEXT, null );
		var runs = [];
		var plain = '';
		var node;
		while ( ( node = walker.nextNode() ) ) {
			if ( isInsideLink( node ) ) {
				continue;
			}
			var text = node.nodeValue || '';
			if ( ! text ) {
				continue;
			}
			runs.push( { node: node, start: plain.length, end: plain.length + text.length } );
			plain += text;
		}
		return { runs: runs, plain: plain };
	}

	function isWordChar( ch ) {
		return ch !== null && ch !== undefined && /[A-Za-z0-9]/.test( ch );
	}

	// First whole-word, case-insensitive occurrence of `phrase` in `plain`.
	function findPhrase( plain, phrase ) {
		if ( ! phrase ) {
			return null;
		}
		var hay = plain.toLowerCase();
		var needle = phrase.toLowerCase();
		if ( ! needle.length || needle.length > hay.length ) {
			return null;
		}
		var from = 0;
		while ( from <= hay.length - needle.length ) {
			var idx = hay.indexOf( needle, from );
			if ( idx === -1 ) {
				return null;
			}
			var before = idx > 0 ? plain.charAt( idx - 1 ) : null;
			var after  = ( idx + needle.length ) < plain.length ? plain.charAt( idx + needle.length ) : null;
			var leftOk  = ! isWordChar( before ) || ! isWordChar( phrase.charAt( 0 ) );
			var rightOk = ! isWordChar( after )  || ! isWordChar( phrase.charAt( phrase.length - 1 ) );
			if ( leftOk && rightOk ) {
				return { index: idx, length: needle.length };
			}
			from = idx + 1;
		}
		return null;
	}

	// Wrap the matched range in <a href>. Returns true on success.
	function wrapMatch( bodyEl, runs, hit, url ) {
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

	// ─── Suggestion collection ──────────────────────────────────────────────────

	// Flatten targets ({title,url,keywords[]}) into candidate phrases, longest first.
	function buildCandidates( targets, currentUrl ) {
		var list = [];
		( targets || [] ).forEach( function ( t ) {
			if ( ! t || ! t.url || t.url === currentUrl ) {
				return;
			}
			( t.keywords || [] ).forEach( function ( kw ) {
				kw = String( kw || '' ).trim();
				if ( kw.length >= 3 ) {
					list.push( { phrase: kw, url: t.url, title: t.title || t.url } );
				}
			} );
		} );
		list.sort( function ( a, b ) { return b.phrase.length - a.phrase.length; } );
		return list;
	}

	// Scan the document and return one suggestion per destination page (topic).
	// Candidates are sorted longest-phrase-first and fields walked in document
	// order, so the most specific phrase in the earliest block wins per target.
	function collectSuggestions( candidates ) {
		var blocks = wp.data.select( 'core/block-editor' ).getBlocks();
		var usedUrl = {};
		var suggestions = [];

		forEachRichField( blocks, function ( field ) {
			var collected = collectRuns( parseFragment( field.html ) );
			if ( ! collected.plain ) {
				return;
			}
			candidates.forEach( function ( c ) {
				if ( usedUrl[ c.url ] ) {
					return;
				}
				var hit = findPhrase( collected.plain, c.phrase );
				if ( hit ) {
					usedUrl[ c.url ] = true;
					suggestions.push( {
						phrase:   c.phrase,
						url:      c.url,
						title:    c.title,
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
		var hit = findPhrase( collected.plain, s.phrase );
		if ( ! hit || ! wrapMatch( body, collected.runs, hit, s.url ) ) {
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
					var hit = findPhrase( collected.plain, s.phrase );
					if ( hit && wrapMatch( body, collected.runs, hit, s.url ) ) {
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
					suggestions.length + ' ' + __( 'suggestion(s). Each phrase is linked once.', 'ekwa' ) ),
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
