/**
 * Ekwa Rich Paste — turn a clipboard's rich-text flavour into clean HTML.
 *
 * The AI prompt boxes are plain <textarea>s, so a paste from Word, Google Docs
 * or a rendered web page arrives as text/plain. Headings, lists, bold runs and
 * — most damagingly — links are already gone by the time the text reaches
 * Gemini, and a document's list collapses into lines indistinguishable from its
 * paragraphs. This module reads the clipboard's text/html flavour instead and
 * rewrites it down to the small set of semantic tags the generator's system
 * prompt already asks the model to emit (<h1>–<h6>, <p>, <ul>/<ol>, <a>, <img>,
 * <strong>/<em>, tables), throwing every span, class and inline style away.
 *
 * It is deliberately conservative about when it acts at all:
 *
 *   - no text/html on the clipboard         → caller pastes as before
 *   - the plain flavour is itself markup    → caller pastes it verbatim, so
 *     (DevTools "Copy element", a code        copied source is never rewritten
 *     editor, a saved template)               into the thing it renders as
 *   - an image with no words beside it      → caller's screenshot picker takes it
 *   - nothing structural survived the clean → caller pastes plain text, so a
 *                                             word or a sentence typed into a
 *                                             refine box never becomes <p>word</p>
 *
 * Exposed as window.ekwaRichPaste so every prompt box shares one policy.
 *
 * @package ekwa
 */
( function ( window ) {
	'use strict';

	if ( ! window || ! window.DOMParser ) {
		return;
	}

	// ─── Tag policy ─────────────────────────────────────────────────────────

	// Source tag → what it becomes. Anything absent from this map is UNWRAPPED:
	// the element goes, its children stay. That single rule is what turns Word's
	// and Google Docs' <div>/<span>/<font> scaffolding back into prose without
	// having to enumerate it. Only DROP_TAGS take their contents with them.
	var KEEP_TAGS = {
		H1: 'h1', H2: 'h2', H3: 'h3', H4: 'h4', H5: 'h5', H6: 'h6',
		P: 'p', BLOCKQUOTE: 'blockquote', PRE: 'pre',
		UL: 'ul', OL: 'ol', LI: 'li',
		TABLE: 'table', THEAD: 'thead', TBODY: 'tbody', TFOOT: 'tbody',
		TR: 'tr', TH: 'th', TD: 'td', CAPTION: 'caption',
		A: 'a', IMG: 'img', BR: 'br', HR: 'hr',
		STRONG: 'strong', B: 'strong', EM: 'em', I: 'em',
		CODE: 'code', SUP: 'sup', SUB: 'sub'
	};

	// Elements whose content is not prose. <button>, <label> and <form> are
	// deliberately NOT here — a CTA copied off a page is content worth keeping,
	// so they unwrap to their text like any other wrapper.
	var DROP_TAGS = {
		STYLE: 1, SCRIPT: 1, LINK: 1, META: 1, TITLE: 1, HEAD: 1, NOSCRIPT: 1,
		SVG: 1, CANVAS: 1, IFRAME: 1, OBJECT: 1, EMBED: 1, VIDEO: 1, AUDIO: 1,
		INPUT: 1, SELECT: 1, OPTION: 1, TEXTAREA: 1, TEMPLATE: 1,
		// Word's namespaced leftovers.
		'O:P': 1, 'W:SDT': 1, 'V:SHAPETYPE': 1, 'V:SHAPE': 1, XML: 1
	};

	// Attributes that survive, per output tag. Everything else — class, style,
	// id, data-*, width/height, Word's lang/dir — is dropped, because the whole
	// point is to hand the model content, not the source page's design.
	var KEEP_ATTRS = {
		a:   [ 'href' ],
		img: [ 'src', 'alt' ],
		th:  [ 'colspan', 'rowspan' ],
		td:  [ 'colspan', 'rowspan' ],
		ol:  [ 'start' ],
		// Icon fonts carry their meaning in the class and nowhere else, and the
		// generator's own prompt asks for exactly this markup.
		i:   [ 'class' ]
	};

	var VOID_TAGS  = { br: 1, hr: 1, img: 1 };
	var BLOCK_TAGS = {
		h1: 1, h2: 1, h3: 1, h4: 1, h5: 1, h6: 1, p: 1, blockquote: 1, pre: 1,
		ul: 1, ol: 1, li: 1, table: 1, thead: 1, tbody: 1, tr: 1, th: 1, td: 1,
		caption: 1, hr: 1
	};
	// Tags that only ever hold other blocks — their children always indent.
	var NEST_TAGS  = { ul: 1, ol: 1, blockquote: 1, table: 1, thead: 1, tbody: 1, tr: 1 };
	// Emptiness is not a reason to drop these: table cells hold the grid's shape
	// and an icon <i> is entirely an empty element with a class.
	var KEEP_EMPTY = { br: 1, hr: 1, img: 1, td: 1, th: 1, tr: 1, i: 1 };
	// Content in their own right, even with no text: their presence is enough to
	// keep whatever wraps them.
	var SUBSTANTIVE = { img: 1, hr: 1, i: 1 };
	var MERGEABLE  = { strong: 1, em: 1, code: 1, sup: 1, sub: 1 };

	var ICON_CLASS = /(^|\s)(fa[bsrltdk]?|fa-[\w-]+|icon-[\w-]+|dashicons(-[\w-]+)?|material-icons)(\s|$)/i;

	// ─── Small helpers ──────────────────────────────────────────────────────

	function repeat( str, times ) {
		return times > 0 ? new Array( times + 1 ).join( str ) : '';
	}

	function tagOf( node ) {
		return node.tagName ? node.tagName.toUpperCase() : '';
	}

	function attr( node, name ) {
		return ( node.getAttribute && node.getAttribute( name ) ) || '';
	}

	/** Whitespace that means nothing once the markup is normalised. */
	function collapse( text ) {
		return String( text ).replace( /[\s ]+/g, ' ' );
	}

	function isBlank( text ) {
		return '' === String( text ).replace( /[\s ]+/g, '' );
	}

	/** True when the output tree already has this tag above `node`. */
	function hasAncestor( node, tag ) {
		while ( node && 1 === node.nodeType ) {
			if ( node.tagName.toLowerCase() === tag ) {
				return true;
			}
			node = node.parentNode;
		}
		return false;
	}

	// ─── URLs ───────────────────────────────────────────────────────────────

	/**
	 * Normalise one href/src off the clipboard.
	 *
	 * Google Docs rewrites every external link through its own redirector
	 * (https://www.google.com/url?q=…&sa=D&ust=…). Left alone those URLs reach
	 * the model, the generated markup, and eventually the client's page — so the
	 * real target is pulled back out of the `q` parameter.
	 *
	 * @param {string} raw  Attribute value as authored.
	 * @param {string} base Document base URL, when the source supplied one.
	 * @return {string} Usable URL, or '' when the link carries no destination.
	 */
	function cleanUrl( raw, base ) {
		var url = String( raw || '' ).trim();
		if ( ! url ) {
			return '';
		}

		var redirect = /^https?:\/\/(?:www\.)?google\.[a-z.]+\/url\?(.+)$/i.exec( url );
		if ( redirect ) {
			var target = /(?:^|&|&amp;)(?:q|url)=([^&]+)/.exec( redirect[ 1 ] );
			if ( target ) {
				try {
					url = decodeURIComponent( target[ 1 ] );
				} catch ( e ) { /* keep the redirector rather than lose the link */ }
			}
		}

		// Anchors to nowhere and scripted hrefs are noise in a prompt.
		if ( '#' === url || /^javascript:/i.test( url ) || /^about:/i.test( url ) ) {
			return '';
		}

		// Relative paths only resolve when the source shipped a <base>; without
		// one they stay as authored rather than being resolved against wp-admin.
		if ( base && ! /^[a-z][a-z0-9+.-]*:|^\/\//i.test( url ) && window.URL ) {
			try {
				url = new window.URL( url, base ).href;
			} catch ( e ) { /* leave it relative */ }
		}

		return url;
	}

	// ─── Word list paragraphs ───────────────────────────────────────────────
	//
	// Word does not put lists on the clipboard as <ul>/<li>. Every item is a
	// <p class=MsoListParagraph style='…mso-list:l0 level1 lfo1'> whose bullet or
	// number is literal text in a leading span. Pasted as-is, a five-item list is
	// five paragraphs that each begin with a stray "·" — which is exactly how it
	// has been reaching the model.

	var MSO_MARKER   = /mso-list\s*:\s*ignore/i;
	var MSO_LIST     = /mso-list\s*:\s*(l\d+)/i;
	var MSO_LEVEL    = /mso-list\s*:[^;"']*level(\d+)/i;
	var BULLET_GLYPH = /^[·•▪●■◦⁃−§*o-]+$/i;
	var ORDERED_MARK = /^\(?(\d+|[a-z]{1,2}|[ivxlcdm]+)[.)]$/i;

	/**
	 * Word's list identity for a paragraph, or null when it is not a list item.
	 *
	 * The id matters as much as the level: Word gives each list its own `l<n>`,
	 * so a numbered list following a bulleted one is two lists, not one run.
	 *
	 * @param {Element} node Candidate paragraph.
	 * @return {?{id: string, level: number, styled: boolean}} `styled` is false
	 *         when only the "List Paragraph" class identified it — Word applies
	 *         that class to plain indented text too.
	 */
	function msoList( node ) {
		if ( 'P' !== tagOf( node ) ) {
			return null;
		}
		var style = attr( node, 'style' );
		var id    = MSO_LIST.exec( style );
		if ( id ) {
			var level = MSO_LEVEL.exec( style );
			return {
				id:     id[ 1 ].toLowerCase(),
				level:  level ? ( parseInt( level[ 1 ], 10 ) || 1 ) : 1,
				styled: true
			};
		}
		if ( /\bMsoListParagraph/i.test( attr( node, 'class' ) ) ) {
			return { id: '', level: 1, styled: false };
		}
		return null;
	}

	/**
	 * Pull the bullet/number off a Word list paragraph, mutating it.
	 *
	 * @param {Element} para   Candidate paragraph.
	 * @param {boolean} styled Whether mso-list, rather than the class alone,
	 *                         identified it (see msoList()).
	 * @return {?{ordered: boolean, start: number}} Null when no marker is found,
	 *         which is the signal that this is ordinary indented text wearing the
	 *         "List Paragraph" style rather than a real list item.
	 */
	function takeMsoMarker( para, styled ) {
		var marker = '';
		var spans  = para.querySelectorAll( '*[style]' );

		for ( var i = 0; i < spans.length; i++ ) {
			if ( MSO_MARKER.test( attr( spans[ i ], 'style' ) ) ) {
				marker = collapse( spans[ i ].textContent ).trim();
				spans[ i ].parentNode.removeChild( spans[ i ] );
				break;
			}
		}

		// Older exports and pastes through intermediate apps lose the
		// mso-list:Ignore span and leave the glyph as plain leading text. Only
		// trusted when mso-list vouched for the paragraph — reading the first
		// word of anything merely wearing the class would eat real copy.
		if ( ! marker && styled ) {
			var walker = para;
			while ( walker && walker.firstChild ) {
				walker = walker.firstChild;
			}
			if ( ! walker || 3 !== walker.nodeType ) {
				return null;
			}
			var lead = /^\s*(\S+)\s/.exec( collapse( walker.nodeValue ) );
			if ( ! lead || ( ! BULLET_GLYPH.test( lead[ 1 ] ) && ! ORDERED_MARK.test( lead[ 1 ] ) ) ) {
				return null;
			}
			marker = lead[ 1 ];
			walker.nodeValue = collapse( walker.nodeValue ).replace( /^\s*\S+\s/, '' );
		}

		if ( ! marker ) {
			return null;
		}
		// Bullets are checked first: Word's level-2 bullet is a literal "o",
		// which would otherwise read as an alphabetic list marker.
		if ( BULLET_GLYPH.test( marker ) ) {
			return { ordered: false, start: 0 };
		}
		if ( ORDERED_MARK.test( marker ) ) {
			var digits = /(\d+)/.exec( marker );
			return { ordered: true, start: digits ? parseInt( digits[ 1 ], 10 ) : 0 };
		}
		return null;
	}

	/** Replace one run of consecutive Word list paragraphs with real lists. */
	function buildMsoList( run ) {
		var doc   = run[ 0 ].node.ownerDocument;
		var roots = [];
		var stack = [];

		for ( var i = 0; i < run.length; i++ ) {
			var entry = run[ i ];

			// Coming back up a level closes every list opened below this one.
			while ( stack.length && stack[ stack.length - 1 ].level > entry.level ) {
				stack.pop();
			}

			var top = stack[ stack.length - 1 ];

			// Same depth but a different list: Word restarts numbering under a
			// new id, and a bulleted run after a numbered one is a second list,
			// not a continuation of the first.
			if ( top && top.level === entry.level
				&& ( top.ordered !== entry.ordered || ( top.id && entry.id && top.id !== entry.id ) ) ) {
				stack.pop();
				top = stack[ stack.length - 1 ];
			}

			if ( ! top || top.level < entry.level ) {
				var list = doc.createElement( entry.ordered ? 'ol' : 'ul' );
				if ( entry.ordered && entry.start > 1 ) {
					list.setAttribute( 'start', String( entry.start ) );
				}
				if ( top && top.list.lastChild ) {
					top.list.lastChild.appendChild( list );
				} else {
					roots.push( list );
				}
				stack.push( {
					level:   entry.level,
					id:      entry.id,
					ordered: entry.ordered,
					list:    list
				} );
				top = stack[ stack.length - 1 ];
			}

			var item = doc.createElement( 'li' );
			while ( entry.node.firstChild ) {
				item.appendChild( entry.node.firstChild );
			}
			top.list.appendChild( item );
		}

		var anchor = run[ 0 ].node;
		for ( var r = 0; r < roots.length; r++ ) {
			anchor.parentNode.insertBefore( roots[ r ], anchor );
		}
		for ( var j = 0; j < run.length; j++ ) {
			run[ j ].node.parentNode.removeChild( run[ j ].node );
		}
	}

	/** One run entry, or null when the element is not a Word list paragraph. */
	function msoEntry( node ) {
		var info = msoList( node );
		if ( ! info ) {
			return null;
		}
		var mark = takeMsoMarker( node, info.styled );
		if ( ! mark ) {
			return null;
		}
		return {
			node:    node,
			level:   info.level,
			id:      info.id,
			ordered: mark.ordered,
			start:   mark.start
		};
	}

	/** Walk the parsed document turning every run of list paragraphs into lists. */
	function groupMsoLists( parent ) {
		var child = parent.firstElementChild;
		while ( child ) {
			var next  = child.nextElementSibling;
			var entry = msoEntry( child );

			if ( entry ) {
				var run = [ entry ];
				while ( next ) {
					var nextEntry = msoEntry( next );
					if ( ! nextEntry ) {
						break;
					}
					run.push( nextEntry );
					next = next.nextElementSibling;
				}
				buildMsoList( run );
			} else {
				groupMsoLists( child );
			}

			child = next;
		}
	}

	// ─── The walk ───────────────────────────────────────────────────────────

	// Anchored on a property boundary so Word's mso-bidi-font-weight:normal —
	// which rides along on genuinely bold runs — cannot be read as the real
	// font-weight and strip a document's bold on the way through.
	var BOLD_ON    = /(?:^|[;{\s])font-weight\s*:\s*(bold(er)?|[6-9]00)\b/i;
	var BOLD_OFF   = /(?:^|[;{\s])font-weight\s*:\s*(normal|lighter|[1-5]00)\b/i;
	var ITALIC_ON  = /(?:^|[;{\s])font-style\s*:\s*italic/i;
	var ITALIC_OFF = /(?:^|[;{\s])font-style\s*:\s*normal/i;

	/**
	 * Inline emphasis Google Docs expresses as a style rather than a tag.
	 *
	 * Its clipboard HTML has no <b> or <i> at all — every run is a <span> with
	 * font-weight:700 / font-style:italic baked into the style attribute, so
	 * dropping styles without reading them first loses all of a document's bold.
	 *
	 * @param {Element} node Source element.
	 * @return {Array<string>} Output tags to wrap the element's content in.
	 */
	function emphasisFor( node ) {
		var style = attr( node, 'style' );
		var tags  = [];
		if ( BOLD_ON.test( style ) ) {
			tags.push( 'strong' );
		}
		if ( ITALIC_ON.test( style ) ) {
			tags.push( 'em' );
		}
		return tags;
	}

	/** True when the style explicitly cancels the tag's own emphasis. */
	function emphasisCancelled( node, tag ) {
		var style = attr( node, 'style' );
		return 'strong' === tag ? BOLD_OFF.test( style ) : ITALIC_OFF.test( style );
	}

	function isIcon( node ) {
		var tag = tagOf( node );
		if ( 'I' !== tag && 'SPAN' !== tag ) {
			return false;
		}
		// An icon element is empty — the glyph comes from the font, via ::before.
		// Without this, a wrapper like <span class="icon-box">Book now</span>
		// matches the class pattern and its copy is thrown away.
		return ICON_CLASS.test( attr( node, 'class' ) ) && isBlank( node.textContent );
	}

	function copyAttrs( src, out, tag, ctx ) {
		var allowed = KEEP_ATTRS[ tag ];
		if ( ! allowed ) {
			return true;
		}
		for ( var i = 0; i < allowed.length; i++ ) {
			var name  = allowed[ i ];
			var value = attr( src, name );
			if ( ! value ) {
				continue;
			}
			if ( 'href' === name || 'src' === name ) {
				value = cleanUrl( value, ctx.base );
				if ( ! value ) {
					continue;
				}
				// A pasted document's images can be megabytes of base64. Keeping
				// them would blow the request budget for no gain, since the model
				// cannot see an image it is handed as prompt text anyway.
				if ( 'src' === name && /^(data|blob):/i.test( value ) ) {
					ctx.stats.droppedImages++;
					return false;
				}
			}
			out.setAttribute( name, collapse( value ).trim() );
		}
		// An anchor that lost its href is just text; report it so the caller
		// unwraps rather than emitting a destination-less <a>.
		return ! ( 'a' === tag && ! out.getAttribute( 'href' ) )
			&& ! ( 'img' === tag && ! out.getAttribute( 'src' ) );
	}

	function appendChildren( src, parent, ctx ) {
		var kids = src.childNodes;
		for ( var i = 0; i < kids.length; i++ ) {
			appendConverted( kids[ i ], parent, ctx );
		}
	}

	/** Wrap `src`'s converted children in `tags`, innermost last. */
	function appendWrapped( src, parent, tags, ctx ) {
		var target = parent;
		for ( var i = 0; i < tags.length; i++ ) {
			// <strong><strong>x</strong></strong> is what Google Docs' per-run
			// spans produce inside an already-bold heading; one level is enough.
			if ( hasAncestor( target, tags[ i ] ) ) {
				continue;
			}
			var wrapper = ctx.doc.createElement( tags[ i ] );
			target.appendChild( wrapper );
			target = wrapper;
		}
		appendChildren( src, target, ctx );
	}

	function appendConverted( node, parent, ctx ) {
		if ( 3 === node.nodeType ) {
			// <pre> is the one place the source's own spacing is the content.
			var text = hasAncestor( parent, 'pre' ) ? node.nodeValue : collapse( node.nodeValue );
			// Layout whitespace is dealt with in a post-pass, once the tree's
			// shape is known — see dropLayoutWhitespace().
			if ( '' !== text ) {
				parent.appendChild( ctx.doc.createTextNode( text ) );
			}
			return;
		}

		if ( 1 !== node.nodeType ) {
			return; // comments (including Word's <![if !supportLists]> markers)
		}

		var tag = tagOf( node );
		if ( DROP_TAGS[ tag ] ) {
			return;
		}

		if ( isIcon( node ) ) {
			var icon = ctx.doc.createElement( 'i' );
			icon.setAttribute( 'class', collapse( attr( node, 'class' ) ).trim() );
			parent.appendChild( icon );
			return;
		}

		var out = KEEP_TAGS[ tag ];

		// Unwrapped scaffolding — but read its style for emphasis on the way past.
		if ( ! out ) {
			appendWrapped( node, parent, emphasisFor( node ), ctx );
			return;
		}

		// Google Docs wraps an entire copied selection in
		// <b style="font-weight:normal" id="docs-internal-guid-…">; kept as a tag
		// it would bold the whole paste.
		if ( ( 'strong' === out || 'em' === out ) && emphasisCancelled( node, out ) ) {
			appendChildren( node, parent, ctx );
			return;
		}

		var element = ctx.doc.createElement( out );
		if ( ! copyAttrs( node, element, out, ctx ) ) {
			// The tag lost the only attribute that justified it.
			if ( VOID_TAGS[ out ] ) {
				return;
			}
			appendChildren( node, parent, ctx );
			return;
		}

		// One level of emphasis is enough — a <b> inside an already-bold run is
		// how Word and Docs express a single continuous phrase.
		if ( ( 'strong' === out || 'em' === out ) && hasAncestor( parent, out ) ) {
			appendChildren( node, parent, ctx );
			return;
		}

		parent.appendChild( element );

		if ( ! VOID_TAGS[ out ] ) {
			appendChildren( node, element, ctx );
		}
	}

	/**
	 * Count what is actually in the finished tree.
	 *
	 * Counting during the walk would overstate it: dropEmpty() then removes
	 * blank paragraphs and emptied wrappers, so a paste of nothing but a bold
	 * non-breaking space would report emphasis and slip through the
	 * worthRewriting() gate. The numbers are also shown to the operator, so
	 * they have to describe the text that landed in the box.
	 *
	 * @param {Element} root  Cleaned tree.
	 * @param {Object}  stats Counters to fill in (droppedImages is left alone —
	 *                        those are gone by definition and still worth saying).
	 */
	function recount( root, stats ) {
		var all = root.querySelectorAll( '*' );
		for ( var i = 0; i < all.length; i++ ) {
			var tag = all[ i ].tagName.toLowerCase();
			if ( 'p' === tag ) { stats.paragraphs++; }
			else if ( /^h[1-6]$/.test( tag ) ) { stats.headings++; }
			else if ( 'ul' === tag || 'ol' === tag ) { stats.lists++; }
			else if ( 'li' === tag ) { stats.listItems++; }
			else if ( 'a' === tag ) { stats.links++; }
			else if ( 'img' === tag ) { stats.images++; }
			else if ( 'table' === tag ) { stats.tables++; }
			else if ( 'strong' === tag || 'em' === tag ) { stats.emphasis++; }
			else if ( 'i' === tag ) { stats.icons++; }
		}
	}

	// ─── Post-passes ────────────────────────────────────────────────────────

	/** Google Docs puts a <p> inside every <li>; the item is the paragraph. */
	function flattenListItems( root ) {
		var items = root.querySelectorAll( 'li' );
		for ( var i = 0; i < items.length; i++ ) {
			var item  = items[ i ];
			var paras = [];
			for ( var k = 0; k < item.children.length; k++ ) {
				if ( 'P' === item.children[ k ].tagName ) {
					paras.push( item.children[ k ] );
				}
			}
			for ( var j = 0; j < paras.length; j++ ) {
				var para = paras[ j ];
				if ( j > 0 ) {
					// A genuine second paragraph in one item keeps its break.
					para.parentNode.insertBefore( para.ownerDocument.createElement( 'br' ), para );
				}
				while ( para.firstChild ) {
					para.parentNode.insertBefore( para.firstChild, para );
				}
				para.parentNode.removeChild( para );
			}
		}
	}

	/**
	 * Bottom-up removal of elements that ended up holding nothing — Word's
	 * <p><o:p>&nbsp;</o:p></p> spacers, and the wrappers left behind once a
	 * source's styling spans were stripped out.
	 *
	 * @param {Node} node Subtree root.
	 * @return {boolean} Whether real content survived inside it — which is also
	 *         what decides whether the node justifies keeping its own parent.
	 */
	function dropEmpty( node ) {
		var kept = false;
		var kids = Array.prototype.slice.call( node.childNodes );

		for ( var i = 0; i < kids.length; i++ ) {
			var kid = kids[ i ];

			if ( 3 === kid.nodeType ) {
				if ( ! isBlank( kid.nodeValue ) ) {
					kept = true;
				}
				continue;
			}
			if ( 1 !== kid.nodeType ) {
				continue;
			}

			var tag     = kid.tagName.toLowerCase();
			var content = dropEmpty( kid ) || !! SUBSTANTIVE[ tag ];

			if ( content ) {
				kept = true;
				continue;
			}
			// Empty but structural: an empty cell holds the grid's shape, and a
			// lone <br> stays inside a paragraph that has other content. Neither
			// is reason enough on its own to keep the element around it.
			if ( KEEP_EMPTY[ tag ] ) {
				continue;
			}
			kid.parentNode.removeChild( kid );
		}

		return kept;
	}

	/** Google Docs splits one bold phrase across a span per formatting run. */
	function mergeInline( node ) {
		var kid = node.firstChild;
		while ( kid ) {
			var next = kid.nextSibling;
			if ( 1 === kid.nodeType && next && 1 === next.nodeType
				&& MERGEABLE[ kid.tagName.toLowerCase() ]
				&& kid.tagName === next.tagName
				&& ! kid.attributes.length && ! next.attributes.length ) {
				while ( next.firstChild ) {
					kid.appendChild( next.firstChild );
				}
				next.parentNode.removeChild( next );
				continue; // the one after it may merge in too
			}
			if ( 1 === kid.nodeType ) {
				mergeInline( kid );
			}
			kid = next;
		}
	}

	/**
	 * Drop the whitespace that only ever laid the source out.
	 *
	 * Word and Google Docs put a newline between block elements; collapsing
	 * turns each into a lone space, which the pretty-printer then prints on a
	 * line of its own. Wherever an element holds block children, its blank text
	 * nodes are layout, not copy.
	 */
	function dropLayoutWhitespace( root ) {
		var parents = [ root ];
		var all     = root.querySelectorAll( '*' );
		for ( var a = 0; a < all.length; a++ ) {
			parents.push( all[ a ] );
		}

		for ( var p = 0; p < parents.length; p++ ) {
			var parent = parents[ p ];
			if ( 'PRE' === tagOf( parent ) ) {
				continue;
			}

			var holdsBlock = false;
			for ( var c = 0; c < parent.children.length; c++ ) {
				if ( BLOCK_TAGS[ parent.children[ c ].tagName.toLowerCase() ] ) {
					holdsBlock = true;
					break;
				}
			}
			if ( ! holdsBlock ) {
				continue;
			}

			var kids = Array.prototype.slice.call( parent.childNodes );
			for ( var k = 0; k < kids.length; k++ ) {
				if ( 3 === kids[ k ].nodeType && isBlank( kids[ k ].nodeValue ) ) {
					parent.removeChild( kids[ k ] );
				}
			}
		}
	}

	function isBlockElement( node ) {
		return !! ( node && 1 === node.nodeType && BLOCK_TAGS[ node.tagName.toLowerCase() ] );
	}

	/** Strip the spaces collapsing left at the edges of a block's content. */
	function trimBlocks( root ) {
		var blocks = root.querySelectorAll( 'h1,h2,h3,h4,h5,h6,p,li,th,td,blockquote,caption' );
		for ( var i = 0; i < blocks.length; i++ ) {
			var kids = blocks[ i ].childNodes;
			for ( var k = 0; k < kids.length; k++ ) {
				var kid = kids[ k ];
				if ( 3 !== kid.nodeType ) {
					continue;
				}
				// Nothing sits before a block's first run of text, and a block
				// sibling — a list nested inside a list item, say — is a break in
				// its own right, so the space against it carried no meaning.
				if ( ! kid.previousSibling || isBlockElement( kid.previousSibling ) ) {
					kid.nodeValue = kid.nodeValue.replace( /^ +/, '' );
				}
				if ( ! kid.nextSibling || isBlockElement( kid.nextSibling ) ) {
					kid.nodeValue = kid.nodeValue.replace( / +$/, '' );
				}
			}
		}
	}

	// ─── Serialisation ──────────────────────────────────────────────────────

	function escapeText( text ) {
		return String( text )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	function escapeAttr( value ) {
		return String( value )
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /</g, '&lt;' );
	}

	function renderAttrs( node ) {
		var out = '';
		for ( var i = 0; i < node.attributes.length; i++ ) {
			out += ' ' + node.attributes[ i ].name + '="' + escapeAttr( node.attributes[ i ].value ) + '"';
		}
		return out;
	}

	/** A block holding other blocks indents them, so the nesting stays readable. */
	function nests( node, tag ) {
		if ( NEST_TAGS[ tag ] ) {
			return true;
		}
		for ( var i = 0; i < node.children.length; i++ ) {
			if ( BLOCK_TAGS[ node.children[ i ].tagName.toLowerCase() ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Pretty-print the cleaned tree.
	 *
	 * The result lands in a <textarea> the operator reads and edits before
	 * generating, so one unbroken line of markup would be worse than the plain
	 * text this replaces. Blocks get their own line, containers indent, inline
	 * runs stay put.
	 */
	function render( node, depth ) {
		if ( 3 === node.nodeType ) {
			return escapeText( node.nodeValue );
		}
		if ( 1 !== node.nodeType ) {
			return '';
		}

		var tag  = node.tagName.toLowerCase();
		var pad  = repeat( '  ', depth );
		var open = '<' + tag + renderAttrs( node ) + '>';
		// One blank line between top-level blocks; single breaks deeper in.
		var lead = BLOCK_TAGS[ tag ] ? ( depth ? '\n' + pad : '\n\n' ) : '';

		if ( VOID_TAGS[ tag ] ) {
			return lead + open;
		}

		var inner = '';
		var nest  = nests( node, tag );
		for ( var i = 0; i < node.childNodes.length; i++ ) {
			inner += render( node.childNodes[ i ], nest ? depth + 1 : depth );
		}
		if ( nest ) {
			inner += '\n' + pad;
		}

		return lead + open + inner + '</' + tag + '>';
	}

	// ─── Public surface ─────────────────────────────────────────────────────

	function emptyStats() {
		return {
			paragraphs: 0, headings: 0, lists: 0, listItems: 0, links: 0,
			images: 0, tables: 0, emphasis: 0, icons: 0, droppedImages: 0,
			textLength: 0
		};
	}

	/**
	 * Rewrite a clipboard HTML flavour into clean prompt HTML.
	 *
	 * @param {string} html Raw text/html off the clipboard.
	 * @return {{html: string, stats: Object}}
	 */
	function clean( html ) {
		var stats = emptyStats();
		var out   = { html: '', stats: stats };

		if ( ! html || ! String( html ).trim() ) {
			return out;
		}

		var doc;
		try {
			doc = new window.DOMParser().parseFromString( String( html ), 'text/html' );
		} catch ( e ) {
			return out;
		}
		if ( ! doc || ! doc.body ) {
			return out;
		}

		var baseTag = doc.querySelector( 'base[href]' );
		var ctx     = {
			doc:   doc,
			stats: stats,
			base:  baseTag ? baseTag.getAttribute( 'href' ) : ''
		};

		groupMsoLists( doc.body );

		var root = doc.createElement( 'div' );
		appendChildren( doc.body, root, ctx );

		flattenListItems( root );
		mergeInline( root );
		dropLayoutWhitespace( root );
		dropEmpty( root );
		trimBlocks( root );
		recount( root, stats );

		stats.textLength = root.textContent.replace( /[\s ]+/g, '' ).length;

		// Unwrapping a page's <div>/<article> scaffolding leaves loose inline
		// content — an icon, a CTA link, an image — sitting between blocks at the
		// root. Those get a line of their own rather than trailing the heading
		// above them, so the textarea stays legible.
		var text    = '';
		var afterBlock = false;
		for ( var i = 0; i < root.childNodes.length; i++ ) {
			var child = root.childNodes[ i ];
			var piece = render( child, 0 );
			if ( '' === piece ) {
				continue;
			}
			var isBlock = 1 === child.nodeType && BLOCK_TAGS[ child.tagName.toLowerCase() ];
			if ( ! isBlock && afterBlock ) {
				text += '\n\n';
			}
			text      += piece;
			afterBlock = !! isBlock;
		}

		out.html = text.replace( /\n{3,}/g, '\n\n' ).trim();
		return out;
	}

	/** A short, human summary of what a paste brought in. */
	function describe( stats ) {
		var bits = [];
		if ( stats.headings ) { bits.push( stats.headings + ( 1 === stats.headings ? ' heading' : ' headings' ) ); }
		if ( stats.paragraphs ) { bits.push( stats.paragraphs + ( 1 === stats.paragraphs ? ' paragraph' : ' paragraphs' ) ); }
		if ( stats.lists ) { bits.push( stats.lists + ( 1 === stats.lists ? ' list' : ' lists' ) ); }
		if ( stats.tables ) { bits.push( stats.tables + ( 1 === stats.tables ? ' table' : ' tables' ) ); }
		if ( stats.links ) { bits.push( stats.links + ( 1 === stats.links ? ' link' : ' links' ) ); }
		if ( stats.images ) { bits.push( stats.images + ( 1 === stats.images ? ' image' : ' images' ) ); }
		var summary = bits.length ? bits.join( ' · ' ) : 'formatting';
		if ( stats.droppedImages ) {
			summary += ' · ' + stats.droppedImages
				+ ( 1 === stats.droppedImages ? ' embedded image dropped' : ' embedded images dropped' );
		}
		return summary;
	}

	function hasImageFile( clipboard ) {
		var items = clipboard.items;
		var i;
		if ( items && items.length ) {
			for ( i = 0; i < items.length; i++ ) {
				if ( 'file' === items[ i ].kind && items[ i ].type && 0 === items[ i ].type.indexOf( 'image/' ) ) {
					return true;
				}
			}
		}
		var files = clipboard.files;
		if ( files && files.length ) {
			for ( i = 0; i < files.length; i++ ) {
				if ( files[ i ].type && 0 === files[ i ].type.indexOf( 'image/' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Is the plain flavour hand-written markup rather than prose? */
	function looksLikeMarkup( text ) {
		var trimmed = String( text || '' ).trim();
		if ( '<' !== trimmed.charAt( 0 ) ) {
			return false;
		}
		return /<\/[a-z][\w:-]*\s*>|\/\s*>/i.test( trimmed );
	}

	/** Is the rewrite an improvement on the plain text, or just noise? */
	function worthRewriting( stats ) {
		return !! ( stats.links || stats.lists || stats.headings || stats.images
			|| stats.tables || stats.icons || stats.emphasis || stats.paragraphs > 1 );
	}

	/**
	 * Decide what a paste event should put into a plain textarea.
	 *
	 * @param {ClipboardEvent} event
	 * @return {?{text: string, plain: string, stats: Object}} Null to leave the
	 *         paste entirely alone — the caller must not preventDefault.
	 */
	function resolve( event ) {
		var clipboard = event.clipboardData || window.clipboardData;
		if ( ! clipboard ) {
			return null;
		}

		var html, plain;
		try {
			html  = clipboard.getData( 'text/html' ) || '';
			plain = clipboard.getData( 'text/plain' ) || '';
		} catch ( e ) {
			return null; // clipboard locked down
		}

		if ( ! html.trim() ) {
			return null;
		}

		// Copied source — DevTools "Copy element", a code editor, a saved
		// template. The point of that paste is the markup itself, so it goes in
		// untouched even though the source app also offered a rendered flavour.
		if ( looksLikeMarkup( plain ) ) {
			return null;
		}

		var result = clean( html );
		if ( ! result.html ) {
			return null;
		}

		// An image with no words beside it is a screenshot and belongs to the
		// picker. "Has an image file" cannot decide this on its own: Word and
		// Excel put a bitmap of the selection on the clipboard next to the rich
		// text, and "Copy image" in a browser puts an <img> on it next to the file.
		if ( ! result.stats.textLength && hasImageFile( clipboard ) ) {
			return null;
		}

		if ( ! worthRewriting( result.stats ) ) {
			return null;
		}

		return { text: result.html, plain: plain, stats: result.stats };
	}

	/**
	 * Work out the textarea's next value with `text` dropped in at the caret.
	 *
	 * Returns the value rather than writing it, because these fields are React
	 * controlled inputs — the caller owns the state and the caret restore.
	 *
	 * @param {HTMLTextAreaElement} textarea
	 * @param {string}              text
	 * @return {{value: string, start: number, end: number, chunk: string, lead: string, tail: string}}
	 */
	function spliceInto( textarea, text ) {
		var value = textarea.value || '';
		var start = textarea.selectionStart;
		var end   = textarea.selectionEnd;
		if ( 'number' !== typeof start ) {
			start = value.length;
			end   = value.length;
		}

		var before = value.slice( 0, start );
		var after  = value.slice( end );
		// A fragment dropped mid-line reads as a run-on, and the model treats the
		// whole thing as one instruction — so it gets its own paragraph.
		var lead   = ( before && ! /\n\s*$/.test( before ) ) ? '\n\n' : '';
		var tail   = ( after && ! /^\s*\n/.test( after ) ) ? '\n\n' : '';
		var chunk  = lead + text + tail;

		return {
			value: before + chunk + after,
			start: start,
			end:   start + chunk.length,
			chunk: chunk,
			lead:  lead,
			tail:  tail
		};
	}

	window.ekwaRichPaste = {
		clean:      clean,
		describe:   describe,
		resolve:    resolve,
		spliceInto: spliceInto
	};

}( window ) );
