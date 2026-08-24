/**
 * Ekwa Paste Phone — swap pasted phone numbers for the live [ekwa_phone].
 *
 * Watches paste events in the editor. When the clipboard carries a number that
 * matches one saved in Ekwa Settings → Locations (new patient, existing
 * patient, any location), the number is replaced with the [ekwa_phone] shortcode
 * that renders it, so the copy tracks the setting instead of freezing whatever
 * the source document happened to say. Numbers the settings don't know about are
 * left alone.
 *
 * There is no filter to hook the editor's own paste handling, so the sequence is:
 * read the clipboard on the way past (capture phase), take a snapshot of the
 * document, let the editor paste normally, then rewrite only the fields the
 * paste actually changed. Anything already on the page is untouched.
 *
 * Plain wp.element-free JS (no JSX / build step), matching the theme's other
 * editor scripts. See inc/ekwa-paste-phone.php for the number map.
 *
 * @package ekwa
 */
( function ( wp ) {
	'use strict';

	var CFG = window.ekwaPastePhone || {};
	var MAP = CFG.map || {};   // digits -> '[ekwa_phone type="…" location="…"]'

	if ( ! wp || ! wp.data || ! wp.blocks || ! Object.keys( MAP ).length ) {
		return;
	}

	var __      = wp.i18n.__;
	var _n      = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;

	// Common US phone formats: optional country code, optional parens around the
	// area code, space / dot / hyphen separators. Deliberately loose — the map
	// lookup below is what decides whether a match is one of ours. Digit-boundary
	// checks are done by hand rather than with lookbehind so this keeps working
	// in browsers that don't support it.
	var PHONE_RE = /(?:\+?\d{1,3}[\s.\-]*)?\(?\d{3}\)?[\s.\-]*\d{3}[\s.\-]*\d{4}/g;

	// ─── Number matching ──────────────────────────────────────────────────────

	// Digits only, with any leading US country code trimmed — mirrors
	// ekwa_phone_normalize_digits() so both sides key the map the same way.
	function digitsOf( raw ) {
		var digits = String( raw || '' ).replace( /\D+/g, '' );
		if ( 11 === digits.length && '1' === digits.charAt( 0 ) ) {
			digits = digits.slice( 1 );
		}
		return digits;
	}

	// Walk every phone-shaped run in `text`, handing the caller the normalised
	// digits and letting it decide the replacement. Returns the rewritten string.
	function eachNumber( text, replacer ) {
		return String( text || '' ).replace( PHONE_RE, function ( match, offset, whole ) {
			// Don't claim part of a longer digit run (an order number, an NPI).
			var before = offset > 0 ? whole.charAt( offset - 1 ) : '';
			var after  = whole.charAt( offset + match.length );
			if ( /\d/.test( before ) || /\d/.test( after ) ) {
				return match;
			}
			var out = replacer( digitsOf( match ), match );
			return undefined === out ? match : out;
		} );
	}

	// The configured numbers present in `text`, as a digits -> true set.
	function configuredNumbersIn( text ) {
		var found = {};
		eachNumber( text, function ( digits ) {
			if ( MAP[ digits ] ) {
				found[ digits ] = true;
			}
		} );
		return found;
	}

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

	function getBlocks() {
		var store = wp.data.select( 'core/block-editor' );
		return store && store.getBlocks ? store.getBlocks() : [];
	}

	// Depth-first walk yielding { clientId, name, attrKey, html } per rich field.
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
					cb( {
						clientId: block.clientId,
						name:     block.name,
						attrKey:  attrKey,
						html:     html
					} );
				}
			}
			if ( block.innerBlocks && block.innerBlocks.length ) {
				forEachRichField( block.innerBlocks, cb );
			}
		}
	}

	// clientId|attrKey -> html, used to tell pasted content from what was there.
	function snapshotFields() {
		var snap = {};
		forEachRichField( getBlocks(), function ( field ) {
			snap[ field.clientId + '|' + field.attrKey ] = field.html;
		} );
		return snap;
	}

	// ─── HTML rewriting ───────────────────────────────────────────────────────

	function parseFragment( html ) {
		var doc = new DOMParser().parseFromString(
			'<!doctype html><body>' + html + '</body>', 'text/html'
		);
		return doc.body;
	}

	// Text we must not touch: a number inside a code sample is a code sample.
	function isInsideCode( node ) {
		var parent = node.parentNode;
		while ( parent && parent.nodeType === 1 ) {
			if ( 'CODE' === parent.tagName || 'PRE' === parent.tagName ) {
				return true;
			}
			parent = parent.parentNode;
		}
		return false;
	}

	/**
	 * Replace the wanted numbers in a field's HTML with their shortcodes.
	 *
	 * @param {string} html   Field HTML.
	 * @param {Object} wanted digits -> true; only these are swapped.
	 * @return {{html: string, count: number}} Rewritten HTML and how many landed.
	 */
	function replaceInHtml( html, wanted ) {
		var body  = parseFragment( html );
		var count = 0;

		// A number pasted as its own tel: link is replaced anchor and all — the
		// shortcode renders its own <a href="tel:…">, and leaving this one in
		// place would nest one anchor inside another.
		var anchors = body.querySelectorAll( 'a[href]' );
		for ( var i = anchors.length - 1; i >= 0; i-- ) {
			var anchor = anchors[ i ];
			var href   = anchor.getAttribute( 'href' ) || '';
			if ( ! /^tel:/i.test( href ) ) {
				continue;
			}
			// Prefer the href; fall back to the visible text when the href
			// carries an extension or other noise the digits can't line up with.
			var digits = digitsOf( href );
			if ( ! MAP[ digits ] ) {
				digits = digitsOf( anchor.textContent );
			}
			if ( ! wanted[ digits ] || ! MAP[ digits ] ) {
				continue;
			}
			anchor.parentNode.replaceChild(
				body.ownerDocument.createTextNode( MAP[ digits ] ),
				anchor
			);
			count++;
		}

		// Collect first, rewrite after — editing node values mid-walk would have
		// the TreeWalker re-visiting text it already handled.
		var walker = body.ownerDocument.createTreeWalker( body, NodeFilter.SHOW_TEXT, null );
		var nodes  = [];
		var node;
		while ( ( node = walker.nextNode() ) ) {
			if ( node.nodeValue && ! isInsideCode( node ) ) {
				nodes.push( node );
			}
		}

		nodes.forEach( function ( textNode ) {
			var next = eachNumber( textNode.nodeValue, function ( digits ) {
				if ( ! wanted[ digits ] || ! MAP[ digits ] ) {
					return undefined;
				}
				count++;
				return MAP[ digits ];
			} );
			if ( next !== textNode.nodeValue ) {
				textNode.nodeValue = next;
			}
		} );

		return { html: body.innerHTML, count: count };
	}

	// ─── Writing back to the store ────────────────────────────────────────────

	function makeAttrValue( blockName, attrKey, newHtml ) {
		var type = wp.blocks.getBlockType( blockName );
		var def  = type && type.attributes ? type.attributes[ attrKey ] : null;
		if ( def && def.source === 'rich-text' && wp.richText && wp.richText.RichTextData
			&& typeof wp.richText.RichTextData.fromHTMLString === 'function' ) {
			return wp.richText.RichTextData.fromHTMLString( newHtml );
		}
		return newHtml; // source: 'html' (or fallback) — plain HTML string.
	}

	function writeField( edit ) {
		var change = {};
		change[ edit.attrKey ] = makeAttrValue( edit.name, edit.attrKey, edit.html );
		wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( edit.clientId, change );
	}

	function notify( count ) {
		var notices = wp.data.dispatch( 'core/notices' );
		if ( ! notices || ! notices.createInfoNotice ) {
			return;
		}
		notices.createInfoNotice(
			sprintf(
				/* translators: %d: number of phone numbers replaced. */
				_n(
					'%d pasted phone number is now pulled live from Ekwa Settings.',
					'%d pasted phone numbers are now pulled live from Ekwa Settings.',
					count,
					'ekwa'
				),
				count
			),
			{
				type: 'snackbar',
				id:   'ekwa-paste-phone',
				actions: [ {
					label: __( 'Undo', 'ekwa' ),
					onClick: function () {
						var editor = wp.data.dispatch( 'core/editor' );
						if ( editor && editor.undo ) {
							editor.undo();
						}
					}
				} ]
			}
		);
	}

	/**
	 * Rewrite the fields the paste changed.
	 *
	 * @param {Object} wanted digits -> true, taken from the clipboard.
	 * @param {Object} before clientId|attrKey -> html, taken before the paste.
	 * @return {boolean} True when something was replaced.
	 */
	function applyToPastedFields( wanted, before ) {
		var edits = [];
		var total = 0;

		forEachRichField( getBlocks(), function ( field ) {
			// Untouched fields keep what they had: this only ever rewrites what
			// the paste just brought in, never the rest of the page.
			if ( before[ field.clientId + '|' + field.attrKey ] === field.html ) {
				return;
			}
			var result = replaceInHtml( field.html, wanted );
			if ( result.count ) {
				total += result.count;
				edits.push( {
					clientId: field.clientId,
					name:     field.name,
					attrKey:  field.attrKey,
					html:     result.html
				} );
			}
		} );

		if ( ! edits.length ) {
			return false;
		}

		// One undo step, so a single Ctrl+Z puts the pasted numbers back.
		var run = function () {
			edits.forEach( writeField );
		};
		if ( wp.data.batch ) {
			wp.data.batch( run );
		} else {
			run();
		}

		notify( total );
		return true;
	}

	// ─── Paste plumbing ───────────────────────────────────────────────────────

	// The editor builds the blocks in its own paste handler and the store write
	// lands a tick later, so try immediately and once more after the dust
	// settles. The second run is a no-op when the first already did the work.
	function scheduleAfterPaste( work ) {
		var done = false;
		var run  = function () {
			if ( ! done ) {
				done = work();
			}
		};
		setTimeout( run, 0 );
		setTimeout( run, 300 );
	}

	function onPaste( event ) {
		var target = event.target;
		// Plain fields — the code editor, sidebar inputs, the converter's HTML
		// box — are not block content and must paste through verbatim.
		if ( target && ( 'TEXTAREA' === target.tagName || 'INPUT' === target.tagName ) ) {
			return;
		}

		var clipboard = event.clipboardData || window.clipboardData;
		if ( ! clipboard ) {
			return;
		}

		var text;
		try {
			text = ( clipboard.getData( 'text/html' ) || '' ) + '\n'
				+ ( clipboard.getData( 'text/plain' ) || '' );
		} catch ( e ) {
			return; // Clipboard locked down — leave the paste alone.
		}

		var wanted = configuredNumbersIn( text );
		if ( ! Object.keys( wanted ).length ) {
			return;
		}

		var before = snapshotFields();
		scheduleAfterPaste( function () {
			return applyToPastedFields( wanted, before );
		} );
	}

	// ─── Bootstrap ────────────────────────────────────────────────────────────

	// Capture phase, at the document: this runs before the editor's own handler
	// on the element, which is the only way to see the clipboard before the
	// paste is consumed. Nothing is prevented — the editor still pastes.
	function watchDocument( doc ) {
		if ( ! doc || doc.ekwaPastePhoneWatched ) {
			return;
		}
		doc.ekwaPastePhoneWatched = true;
		doc.addEventListener( 'paste', onPaste, true );
	}

	// The editor canvas is an iframe whose document is swapped in on load, and
	// it doesn't exist yet when this script runs — hence the observer.
	function watchCanvasFrames() {
		var frames = document.querySelectorAll( 'iframe[name="editor-canvas"]' );
		for ( var i = 0; i < frames.length; i++ ) {
			( function ( frame ) {
				var attach = function () {
					try {
						watchDocument( frame.contentDocument );
					} catch ( e ) {
						// Cross-origin canvas — nothing to watch.
					}
				};
				attach();
				if ( ! frame.ekwaPastePhoneBound ) {
					frame.ekwaPastePhoneBound = true;
					frame.addEventListener( 'load', attach );
				}
			} )( frames[ i ] );
		}
	}

	function start() {
		watchDocument( document );
		watchCanvasFrames();

		if ( window.MutationObserver && document.body ) {
			new window.MutationObserver( watchCanvasFrames )
				.observe( document.body, { childList: true, subtree: true } );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}

} )( window.wp );
