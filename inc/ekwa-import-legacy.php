<?php
/**
 * Legacy import repair — old-theme shortcodes and Carbon Fields blocks.
 *
 * Content moved in with WordPress's own export/import tools arrives byte-exact,
 * which is the problem: it still speaks the OLD site's vocabulary. Two things
 * break, and both break silently.
 *
 *  1. Shortcodes the old theme registered and this one does not. `[phone]` is
 *     the common one — it renders as the literal text "[phone]" in a paragraph,
 *     and in a Yoast meta description it ships to Google that way, because
 *     nothing runs shortcodes on meta output at all. Every other legacy tag
 *     ([cta], [call], …) has the same failure mode, so rather than guess at a
 *     replacement this file INVENTORIES them and converts only what someone has
 *     explicitly mapped.
 *
 *  2. Carbon Fields blocks. Carbon Fields stores the whole block in the block
 *     comment's attributes and renders it from PHP, so once the plugin and its
 *     block registration are gone the content is still in post_content but
 *     nothing renders it — the editor shows an "unsupported block" placeholder
 *     and the front end shows nothing. The FAQ block is the one that matters
 *     here: its questions and answers are real page content and, unconverted,
 *     they are invisible to readers and to search engines alike.
 *
 * Everything here is READ-ONLY until someone presses Convert. The scan reports;
 * the preview shows the exact before/after; only the convert step writes. Posts
 * are written through wp_update_post(), so every change leaves a revision to
 * roll back to.
 *
 * Appearance → Ekwa Settings → Legacy Import.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ==================================================================
 * Shortcode mapping.
 * ================================================================== */

/**
 * Legacy shortcode tags this theme knows a replacement for out of the box.
 *
 * Deliberately tiny. `[phone]` is the only legacy tag whose meaning is the same
 * on every old Ekwa site, so it is the only one that can be mapped without
 * someone looking at the content first. Anything else — [cta], [call], [form] —
 * meant something site-specific and gets reported for a human decision instead.
 *
 * @return array<string,string> tag (no brackets) => replacement text.
 */
function ekwa_legacy_shortcode_map_defaults() {
	return array(
		'phone' => '[ekwa_phone]',
	);
}

/**
 * The active legacy shortcode map: built-in defaults plus whatever was saved.
 *
 * A saved entry always wins over the default of the same name, INCLUDING an
 * empty one — that is how someone says "I looked at [phone] and I do not want
 * it converted". Merging the other way round would quietly re-map it on the
 * next page load and there would be no way to switch it off.
 *
 * @return array<string,string> tag => replacement ('' = deliberately unmapped).
 */
function ekwa_legacy_shortcode_map() {
	$map   = ekwa_legacy_shortcode_map_defaults();
	$saved = get_option( 'ekwa_legacy_shortcode_map', null );

	if ( is_array( $saved ) ) {
		foreach ( $saved as $tag => $replacement ) {
			$tag = ekwa_legacy_sanitize_tag( $tag );
			if ( '' !== $tag ) {
				$map[ $tag ] = is_string( $replacement ) ? $replacement : '';
			}
		}
	}

	return $map;
}

/**
 * Reduce a string to a usable shortcode tag, or '' when it is not one.
 *
 * @param string $tag Raw tag, with or without brackets.
 * @return string
 */
function ekwa_legacy_sanitize_tag( $tag ) {
	$tag = trim( (string) $tag, " \t\n\r\0\x0B[]/" );
	return preg_match( '/^[a-zA-Z][a-zA-Z0-9_-]*$/', $tag ) ? $tag : '';
}

/**
 * Post-meta keys whose text is treated as "the SEO tags".
 *
 * Starts from the Yoast list inc/ekwa-phone-tokens.php already keeps in sync
 * with the location numbers — the same fields, for the same reason — and adds
 * the Rank Math and AIOSEO equivalents so a site that migrated between SEO
 * plugins is still covered.
 *
 * @return string[]
 */
function ekwa_legacy_seo_meta_keys() {
	$keys = function_exists( 'ekwa_phone_seo_meta_keys' ) ? ekwa_phone_seo_meta_keys() : array(
		'_yoast_wpseo_title',
		'_yoast_wpseo_metadesc',
	);

	$keys = array_merge(
		$keys,
		array(
			'rank_math_title',
			'rank_math_description',
			'rank_math_facebook_title',
			'rank_math_facebook_description',
			'rank_math_twitter_title',
			'rank_math_twitter_description',
			'_aioseo_title',
			'_aioseo_description',
		)
	);

	/**
	 * Filter the meta keys the legacy importer rewrites shortcodes in.
	 *
	 * @param string[] $keys Meta keys.
	 */
	return array_values( array_unique( (array) apply_filters( 'ekwa_legacy_seo_meta_keys', $keys ) ) );
}

/* ==================================================================
 * Finding shortcodes.
 * ================================================================== */

/**
 * A shortcode regex that does not need the tag to be registered.
 *
 * get_shortcode_regex() only ever matches tags WordPress already knows about,
 * which is exactly the set we do not care about — a legacy tag is by definition
 * one nothing registers any more. This is core's own pattern with the tag list
 * swapped for a generic tag, so the awkward parts (escaped [[tag]], self-closing
 * [tag /], enclosing [tag]…[/tag], attributes containing slashes) behave the
 * way WordPress behaves.
 *
 * Capture groups, matching core's:
 *   1 extra "[" when escaped     4 "/" when self-closing
 *   2 tag name                   5 enclosed content
 *   3 attribute string           6 extra "]" when escaped
 *
 * @return string PCRE pattern.
 */
function ekwa_legacy_shortcode_regex() {
	return '/'
		. '\\['                               // Opening bracket.
		. '(\\[?)'                            // 1: escaped opening bracket.
		. '([a-zA-Z][a-zA-Z0-9_-]*)'          // 2: tag name.
		. '(?![\\w-])'                        // Not part of a longer word.
		. '('                                 // 3: attributes.
		.     '[^\\]\\/]*'
		.     '(?:'
		.         '\\/(?!\\])'                // A slash that is not the closer.
		.         '[^\\]\\/]*'
		.     ')*?'
		. ')'
		. '(?:'
		.     '(\\/)'                         // 4: self-closing.
		.     '\\]'
		. '|'
		.     '\\]'
		.     '(?:'
		.         '('                         // 5: enclosed content.
		.             '[^\\[]*+'
		.             '(?:'
		.                 '\\[(?!\\/\\2\\])'
		.                 '[^\\[]*+'
		.             ')*+'
		.         ')'
		.         '\\[\\/\\2\\]'
		.     ')?'
		. ')'
		. '(\\]?)'                            // 6: escaped closing bracket.
		. '/s';
}

/**
 * Every shortcode-shaped thing in a blob of text.
 *
 * Escaped shortcodes ([[tag]]) are skipped: they are already the literal text
 * the author asked for.
 *
 * @param string $text Text to scan.
 * @return array<int,array> Each: tag, match, atts, content, offset.
 */
function ekwa_legacy_find_shortcodes( $text ) {
	if ( ! is_string( $text ) || false === strpos( $text, '[' ) ) {
		return array();
	}

	if ( ! preg_match_all( ekwa_legacy_shortcode_regex(), $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		return array();
	}

	$found = array();

	foreach ( $matches as $m ) {
		$escaped = ( '[' === $m[1][0] && ']' === ( isset( $m[6][0] ) ? $m[6][0] : '' ) );
		if ( $escaped ) {
			continue;
		}

		$found[] = array(
			'tag'     => $m[2][0],
			'match'   => $m[0][0],
			'atts'    => isset( $m[3][0] ) ? trim( $m[3][0] ) : '',
			'content' => isset( $m[5][0] ) ? $m[5][0] : '',
			'offset'  => (int) $m[0][1],
		);
	}

	return $found;
}

/**
 * Replace mapped legacy shortcodes in a blob of text.
 *
 * An enclosing shortcode ([cta]Book now[/cta]) whose replacement says nothing
 * about the wrapped text is LEFT ALONE and reported, never converted. Dropping
 * the words between the tags would be silent data loss, and there is no safe
 * guess about where they should go — put {content} in the replacement to say.
 *
 * @param string $text    Text to rewrite.
 * @param array  $map     tag => replacement, from ekwa_legacy_shortcode_map().
 * @param array  $report  By reference: converted[tag] => n, skipped[tag] => n.
 * @return string Text, unchanged byte-for-byte when nothing was mapped.
 */
function ekwa_legacy_replace_shortcodes( $text, $map, &$report = array() ) {
	if ( ! isset( $report['converted'] ) ) {
		$report['converted'] = array();
	}
	if ( ! isset( $report['skipped'] ) ) {
		$report['skipped'] = array();
	}

	if ( ! is_string( $text ) || false === strpos( $text, '[' ) || ! $map ) {
		return $text;
	}

	$out = preg_replace_callback(
		ekwa_legacy_shortcode_regex(),
		static function ( $m ) use ( $map, &$report ) {
			$whole   = $m[0];
			$open    = isset( $m[1] ) ? $m[1] : '';
			$tag     = isset( $m[2] ) ? $m[2] : '';
			$close   = isset( $m[6] ) ? $m[6] : '';
			$content = isset( $m[5] ) ? $m[5] : '';

			// Escaped — the author wanted the brackets on the page.
			if ( '[' === $open && ']' === $close ) {
				return $whole;
			}

			if ( ! isset( $map[ $tag ] ) || '' === $map[ $tag ] ) {
				return $whole;
			}

			$replacement = $map[ $tag ];

			if ( '' !== $content && false === strpos( $replacement, '{content}' ) ) {
				$report['skipped'][ $tag ] = ( isset( $report['skipped'][ $tag ] ) ? $report['skipped'][ $tag ] : 0 ) + 1;
				return $whole;
			}

			$report['converted'][ $tag ] = ( isset( $report['converted'][ $tag ] ) ? $report['converted'][ $tag ] : 0 ) + 1;

			return str_replace( '{content}', $content, $replacement );
		},
		$text
	);

	// preg_* returns null on a subject that is not valid UTF-8; handing back the
	// original beats blanking the field.
	return null === $out ? $text : $out;
}

/* ==================================================================
 * Carbon Fields blocks.
 * ================================================================== */

/**
 * Match a Carbon Fields block comment, self-closing or paired.
 *
 * Carbon Fields registers its blocks as `carbon-fields/<slug>` and keeps the
 * entire payload in the attributes, so the self-closing form is what these
 * exports actually contain — but the paired form is matched too rather than
 * being left as a surprise.
 *
 * Groups: 1 block name, 2 attribute JSON, 3 "/" when self-closing,
 *         4 inner content when paired.
 *
 * @return string PCRE pattern.
 */
function ekwa_legacy_carbon_regex() {
	return '#<!--\s+wp:(carbon-fields/[A-Za-z0-9_-]+)(?:\s+(\{.*?\}))?\s*(?:(/)-->|-->(.*?)<!--\s+/wp:\1\s+-->)#s';
}

/**
 * Every Carbon Fields block in a post's markup.
 *
 * @param string $content Post content.
 * @return array<int,array> Each: name, match, attrs (decoded array), rows.
 */
function ekwa_legacy_find_carbon_blocks( $content ) {
	if ( ! is_string( $content ) || false === strpos( $content, 'carbon-fields/' ) ) {
		return array();
	}

	if ( ! preg_match_all( ekwa_legacy_carbon_regex(), $content, $matches, PREG_SET_ORDER ) ) {
		return array();
	}

	$blocks = array();

	foreach ( $matches as $m ) {
		$attrs = array();
		if ( ! empty( $m[2] ) ) {
			$decoded = json_decode( $m[2], true );
			if ( is_array( $decoded ) ) {
				$attrs = $decoded;
			}
		}

		$data = isset( $attrs['data'] ) && is_array( $attrs['data'] ) ? $attrs['data'] : $attrs;

		$blocks[] = array(
			'name'  => $m[1],
			'match' => $m[0],
			'attrs' => $attrs,
			'rows'  => ekwa_legacy_faq_rows( $data ),
		);
	}

	return $blocks;
}

/**
 * Pull question/answer pairs out of a Carbon Fields block's data.
 *
 * Matched by SHAPE, not by field name. The block in the export that prompted
 * this stores its rows under `faq_collapes` — a typo that was baked into the
 * old theme's field registration and is now permanent in every post saved with
 * it — so keying off the literal name would work on one site and silently find
 * nothing on the next. Any complex field whose rows carry something *question*
 * and something *answer* is an FAQ as far as this is concerned.
 *
 * @param array $data Carbon Fields `data` payload.
 * @return array<int,array{question:string,answer:string}>
 */
function ekwa_legacy_faq_rows( $data ) {
	if ( ! is_array( $data ) ) {
		return array();
	}

	foreach ( $data as $value ) {
		if ( ! is_array( $value ) || ! $value ) {
			continue;
		}

		$rows      = array();
		$is_complex = true;

		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				$is_complex = false;
				break;
			}

			$question = '';
			$answer   = '';

			foreach ( $row as $key => $field ) {
				if ( ! is_string( $field ) ) {
					continue;
				}
				$key = strtolower( (string) $key );
				if ( '' === $question && false !== strpos( $key, 'question' ) ) {
					$question = $field;
				} elseif ( '' === $answer && false !== strpos( $key, 'answer' ) ) {
					$answer = $field;
				}
			}

			if ( '' === trim( $question ) && '' === trim( wp_strip_all_tags( $answer ) ) ) {
				continue;
			}

			$rows[] = array(
				'question' => $question,
				'answer'   => $answer,
			);
		}

		if ( $is_complex && $rows ) {
			return $rows;
		}
	}

	return array();
}

/* ==================================================================
 * HTML → core blocks.
 * ================================================================== */

/**
 * Load an HTML fragment into a DOM tree with its own root.
 *
 * Same shape as ekwa_import_prepare_html(): the XML declaration pins UTF-8 and
 * the wrapper keeps several top-level siblings alive, which is what an FAQ
 * answer always is.
 *
 * @param string $html Fragment.
 * @return DOMDocument|null
 */
function ekwa_legacy_load_fragment( $html ) {
	if ( ! is_string( $html ) || '' === trim( $html ) ) {
		return null;
	}

	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$ok = $doc->loadHTML(
		'<?xml encoding="utf-8"?><div data-ekwa-legacy-root="1">' . $html . '</div>',
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
	);
	libxml_clear_errors();

	return $ok ? $doc : null;
}

/**
 * A node's inner HTML.
 *
 * @param DOMNode $node Node.
 * @return string
 */
function ekwa_legacy_inner_html( $node ) {
	$html = '';
	foreach ( $node->childNodes as $child ) {
		$html .= $node->ownerDocument->saveHTML( $child );
	}
	return $html;
}

/**
 * Serialize block attributes the way WordPress does.
 *
 * Wraps core's serialize_block_attributes() so the escaping that keeps a block
 * comment parseable — "--" and the angle brackets in particular — is core's
 * problem rather than a regex here that has to stay in step with it.
 *
 * @param array $attrs Attributes.
 * @return string Leading space + JSON, or '' when there are none.
 */
function ekwa_legacy_block_attrs( $attrs ) {
	if ( empty( $attrs ) ) {
		return '';
	}
	return ' ' . serialize_block_attributes( $attrs );
}

/**
 * Collapse the source formatting inside a run of inline HTML.
 *
 * The newlines and indentation an old editor left between tags are not content
 * and would show up as gaps in a block, so they collapse to single spaces.
 *
 * A NON-BREAKING SPACE IS CONTENT AND MUST SURVIVE. `\s` with the /u modifier
 * matches U+00A0, so the obvious `preg_replace('/\s+/u', ' ', …)` silently eats
 * every `&nbsp;` — and this content is full of them, including trailing ones
 * that are the only thing separating a phrase from the punctuation after it.
 * Matching ASCII whitespace explicitly keeps them, and keeps the output
 * identical to what WordPress itself wrote for the same list.
 *
 * @param string $html Raw inner HTML.
 * @return string
 */
function ekwa_legacy_clean_inline( $html ) {
	$html = preg_replace( '/[ \t\r\n\f\x0B]+/', ' ', (string) $html );
	$html = trim( (string) $html, " \t\r\n\f\x0B" );

	// DOMDocument writes U+00A0 as a raw byte; the block editor writes &nbsp;.
	// Both render the same character, but emitting the entity makes the output
	// byte-identical to what WordPress itself saves for the same list, which is
	// what lets the comparison in the test harness be an equality check rather
	// than an approximation.
	return str_replace( "\xC2\xA0", '&nbsp;', $html );
}

/**
 * Convert an HTML fragment into core block markup.
 *
 * Deliberately narrow: paragraphs, headings and lists become the core blocks
 * that own them, and ANYTHING ELSE falls through to core/html rather than being
 * approximated. A table or an embed that survives as core/html is still on the
 * page and still editable; one that gets flattened into a paragraph is gone.
 *
 * @param string $html   Fragment (an FAQ answer, in practice).
 * @param string $indent Leading whitespace for each emitted line.
 * @return string Block markup, '' when the fragment held nothing.
 */
function ekwa_legacy_html_to_blocks( $html, $indent = '' ) {
	$doc = ekwa_legacy_load_fragment( $html );
	if ( ! $doc || ! $doc->documentElement ) {
		return '';
	}

	$out     = '';
	$pending = '';

	// Consecutive text and inline elements at the top level belong to one
	// paragraph — "Some text <strong>bold</strong> more text" is one sentence,
	// not three blocks.
	$flush = static function () use ( &$pending, &$out, $indent ) {
		$inline = ekwa_legacy_clean_inline( $pending );
		$pending = '';
		if ( '' === $inline || '' === trim( wp_strip_all_tags( $inline ) ) ) {
			return;
		}
		$out .= $indent . '<!-- wp:paragraph -->' . "\n"
			. $indent . '<p>' . $inline . '</p>' . "\n"
			. $indent . '<!-- /wp:paragraph -->' . "\n";
	};

	foreach ( $doc->documentElement->childNodes as $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$pending .= $doc->saveHTML( $node );
			continue;
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			continue;
		}

		$tag = strtolower( $node->nodeName );

		if ( 'p' === $tag ) {
			$flush();
			$inner = ekwa_legacy_clean_inline( ekwa_legacy_inner_html( $node ) );
			if ( '' === $inner || '' === trim( wp_strip_all_tags( $inner ) ) ) {
				continue;
			}
			$out .= $indent . '<!-- wp:paragraph -->' . "\n"
				. $indent . '<p>' . $inner . '</p>' . "\n"
				. $indent . '<!-- /wp:paragraph -->' . "\n";
			continue;
		}

		if ( preg_match( '/^h([1-6])$/', $tag, $hm ) ) {
			$flush();
			$inner = ekwa_legacy_clean_inline( ekwa_legacy_inner_html( $node ) );
			if ( '' === trim( wp_strip_all_tags( $inner ) ) ) {
				continue;
			}
			// An <h1> inside an answer would be a second <h1> on the page; the
			// question above it is already the section heading, so demote.
			$level = max( 2, (int) $hm[1] );
			$attrs = ( 2 === $level ) ? array() : array( 'level' => $level );
			$out  .= $indent . '<!-- wp:heading' . ekwa_legacy_block_attrs( $attrs ) . ' -->' . "\n"
				. $indent . '<h' . $level . ' class="wp-block-heading">' . $inner . '</h' . $level . '>' . "\n"
				. $indent . '<!-- /wp:heading -->' . "\n";
			continue;
		}

		if ( 'ul' === $tag || 'ol' === $tag ) {
			$flush();
			$list = ekwa_legacy_list_to_block( $node, $indent );
			if ( '' !== $list ) {
				$out .= $list;
			}
			continue;
		}

		if ( in_array( $tag, array( 'span', 'a', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'code', 'small', 'mark', 'abbr', 'cite', 'time', 'br' ), true ) ) {
			$pending .= $doc->saveHTML( $node );
			continue;
		}

		// Anything else keeps its markup verbatim.
		$flush();
		$raw = trim( $doc->saveHTML( $node ) );
		if ( '' !== $raw ) {
			$out .= $indent . '<!-- wp:html -->' . "\n"
				. $indent . $raw . "\n"
				. $indent . '<!-- /wp:html -->' . "\n";
		}
	}

	$flush();

	return $out;
}

/**
 * Convert a <ul>/<ol> element into a core/list block.
 *
 * Emits the modern list-item form — core/list wrapping core/list-item children
 * — rather than the pre-6.0 shape where the <li> elements were raw HTML inside
 * the list block. The old shape still loads, but only through a deprecation
 * that rewrites the block the first time someone opens the post, which turns a
 * conversion someone reviewed into a change they did not.
 *
 * A nested list is serialized inside its parent <li>, which is where core puts
 * an inner list block.
 *
 * @param DOMElement $node   <ul> or <ol>.
 * @param string     $indent Leading whitespace.
 * @return string Block markup, '' when the list had no items.
 */
function ekwa_legacy_list_to_block( $node, $indent = '' ) {
	$tag     = strtolower( $node->nodeName );
	$ordered = ( 'ol' === $tag );
	$items   = '';

	foreach ( $node->childNodes as $child ) {
		if ( XML_ELEMENT_NODE !== $child->nodeType || 'li' !== strtolower( $child->nodeName ) ) {
			continue;
		}

		$inline = '';
		$nested = '';

		foreach ( $child->childNodes as $part ) {
			if ( XML_ELEMENT_NODE === $part->nodeType ) {
				$part_tag = strtolower( $part->nodeName );

				if ( 'ul' === $part_tag || 'ol' === $part_tag ) {
					$nested .= ekwa_legacy_list_to_block( $part, '' );
					continue;
				}

				// core/list-item holds inline content only, so a block-level
				// child is unwrapped rather than dropped: its text stays in the
				// bullet instead of disappearing with its tag.
				if ( in_array( $part_tag, array( 'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
					$unwrapped = ekwa_legacy_clean_inline( ekwa_legacy_inner_html( $part ) );
					if ( '' !== $unwrapped ) {
						$inline .= ( '' === trim( $inline ) ? '' : '<br>' ) . $unwrapped;
					}
					continue;
				}
			}

			$inline .= $child->ownerDocument->saveHTML( $part );
		}

		$inline = ekwa_legacy_clean_inline( $inline );

		if ( '' === trim( wp_strip_all_tags( $inline ) ) && '' === $nested ) {
			continue;
		}

		$items .= '<!-- wp:list-item -->' . "\n"
			. '<li>' . $inline . ( '' !== $nested ? "\n" . $nested : '' ) . '</li>' . "\n"
			. '<!-- /wp:list-item -->';
	}

	if ( '' === $items ) {
		return '';
	}

	$attrs = $ordered ? array( 'ordered' => true ) : array();

	return $indent . '<!-- wp:list' . ekwa_legacy_block_attrs( $attrs ) . ' -->' . "\n"
		. $indent . '<' . $tag . ' class="wp-block-list">' . $items . '</' . $tag . '>' . "\n"
		. $indent . '<!-- /wp:list -->' . "\n";
}

/* ==================================================================
 * FAQ rows → block markup.
 * ================================================================== */

/**
 * Inline markup allowed in a question.
 *
 * Matches what ekwa_render_faq_question_block() will actually print — it runs
 * the attribute through wp_kses with this list — so what gets stored is what
 * gets rendered, instead of markup that silently disappears at render time.
 *
 * @param string $question Raw question text.
 * @return string
 */
function ekwa_legacy_clean_question( $question ) {
	$question = wp_kses(
		(string) $question,
		array(
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
		)
	);
	return ekwa_legacy_clean_inline( $question );
}

/**
 * Turn question/answer pairs into block markup.
 *
 * Two shapes, and the difference is schema:
 *
 *  - 'container' (default) builds ekwa/faq-container → ekwa/faq-question +
 *    ekwa/faq-answer. The question still renders as an <h2> and the answer
 *    still renders as paragraphs, so it reads identically to the heading form,
 *    but the container also collects the pairs into one FAQPage JSON-LD block
 *    in the footer. That schema is the whole reason the old block existed.
 *
 *  - 'heading' builds plain core/heading + core/paragraph. Nothing theme-
 *    specific, nothing to migrate again later, no schema.
 *
 * @param array  $rows Rows from ekwa_legacy_faq_rows().
 * @param string $mode 'container' or 'heading'.
 * @return string Block markup, '' when no row had usable content.
 */
function ekwa_legacy_faq_to_blocks( $rows, $mode = 'container' ) {
	$pairs = array();

	foreach ( (array) $rows as $row ) {
		$question = ekwa_legacy_clean_question( isset( $row['question'] ) ? $row['question'] : '' );
		$answer   = ekwa_legacy_html_to_blocks( isset( $row['answer'] ) ? $row['answer'] : '' );

		if ( '' === trim( wp_strip_all_tags( $question ) ) && '' === $answer ) {
			continue;
		}

		$pairs[] = array(
			'question' => $question,
			'answer'   => $answer,
		);
	}

	if ( ! $pairs ) {
		return '';
	}

	if ( 'heading' === $mode ) {
		$out = '';
		foreach ( $pairs as $pair ) {
			if ( '' !== trim( wp_strip_all_tags( $pair['question'] ) ) ) {
				$out .= '<!-- wp:heading -->' . "\n"
					. '<h2 class="wp-block-heading">' . $pair['question'] . '</h2>' . "\n"
					. '<!-- /wp:heading -->' . "\n\n";
			}
			if ( '' !== $pair['answer'] ) {
				$out .= $pair['answer'] . "\n";
			}
		}
		return rtrim( $out ) . "\n";
	}

	$out = '<!-- wp:ekwa/faq-container -->' . "\n";

	foreach ( $pairs as $pair ) {
		$out .= '<!-- wp:ekwa/faq-question ' . serialize_block_attributes( array( 'content' => $pair['question'] ) ) . ' /-->' . "\n\n";
		$out .= '<!-- wp:ekwa/faq-answer -->' . "\n"
			. ( '' !== $pair['answer'] ? $pair['answer'] : '<!-- wp:paragraph -->' . "\n" . '<p></p>' . "\n" . '<!-- /wp:paragraph -->' . "\n" )
			. '<!-- /wp:ekwa/faq-answer -->' . "\n\n";
	}

	return rtrim( $out ) . "\n" . '<!-- /wp:ekwa/faq-container -->' . "\n";
}

/* ==================================================================
 * Links.
 * ================================================================== */

/**
 * Post markup with any Carbon Fields payload decoded back into real HTML.
 *
 * Only for SCANNING. serialize_block_attributes() escapes every `"` in a block
 * comment's JSON as ", so an <a href="…"> living inside a Carbon block's
 * attributes is invisible to an href regex run over the raw post — which is how
 * 35 of this export's links hid. Decoding the payload and appending it puts
 * those links back in view without double-counting the ones in ordinary blocks,
 * because those are never inside the JSON to begin with.
 *
 * @param string $content Post content.
 * @return string Content plus decoded Carbon HTML.
 */
function ekwa_legacy_scannable_html( $content ) {
	$extra = '';

	foreach ( ekwa_legacy_find_carbon_blocks( $content ) as $block ) {
		foreach ( $block['rows'] as $row ) {
			$extra .= ' ' . $row['question'] . ' ' . $row['answer'];
		}
	}

	return $content . $extra;
}

/**
 * Group a post's outbound links by host.
 *
 * No opinion about which host is "the old site" — the counts say it. The domain
 * content was imported from turns up with an order of magnitude more links than
 * the handful pointing at a social profile, so sorting by count puts it on top
 * without anyone having to configure anything.
 *
 * @param string $html      HTML to scan.
 * @param string $self_host This site's host with any leading "www." removed;
 *                          links to it are skipped. '' disables the check.
 * @return array<string,array<string,int>> host => path => count.
 */
function ekwa_legacy_find_links( $html, $self_host = '' ) {
	$hosts = array();

	if ( ! is_string( $html ) || ! preg_match_all( '/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/is', $html, $matches ) ) {
		return $hosts;
	}

	foreach ( $matches[2] as $url ) {
		$url = html_entity_decode( trim( $url ), ENT_QUOTES, 'UTF-8' );

		if ( '' === $url || preg_match( '/^(?:\#|mailto:|tel:|javascript:|data:)/i', $url ) ) {
			continue;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			continue;
		}

		// Compare with "www." off both sides, so a site reached at either form
		// does not report its own links as somebody else's.
		$host = strtolower( $host );
		if ( '' !== $self_host && preg_replace( '/^www\./', '', $host ) === $self_host ) {
			continue;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = $path ? $path : '/';

		if ( ! isset( $hosts[ $host ] ) ) {
			$hosts[ $host ] = array();
		}
		$hosts[ $host ][ $path ] = ( isset( $hosts[ $host ][ $path ] ) ? $hosts[ $host ][ $path ] : 0 ) + 1;
	}

	return $hosts;
}

/* ==================================================================
 * Converting one post.
 * ================================================================== */

/**
 * Default options for a conversion run.
 *
 * @return array
 */
function ekwa_legacy_default_options() {
	return array(
		'do_shortcodes' => true,
		'do_faq'        => true,
		'faq_mode'      => 'container',
	);
}

/**
 * Work out what a post would become, without writing anything.
 *
 * Order matters. The FAQ blocks are converted FIRST, so that by the time the
 * shortcode pass runs, a `[phone]` that was sitting inside a Carbon block's
 * JSON payload is sitting in an ordinary paragraph instead — one code path over
 * plain block markup, rather than a second one that has to rewrite JSON without
 * breaking it.
 *
 * @param WP_Post|int $post    Post or ID.
 * @param array       $options From ekwa_legacy_default_options().
 * @return array|null Plan, or null when the post could not be read.
 */
function ekwa_legacy_plan_post( $post, $options = array() ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return null;
	}

	$options = wp_parse_args( $options, ekwa_legacy_default_options() );
	$map     = ekwa_legacy_shortcode_map();

	$plan = array(
		'post_id'    => (int) $post->ID,
		'title'      => $post->post_title,
		'faq_blocks' => 0,
		'faq_rows'   => 0,
		'converted'  => array(),
		'skipped'    => array(),
		'fields'     => array(),
		'samples'    => array(),
		'changed'    => false,
	);

	$content = $post->post_content;

	// ── Carbon Fields FAQ blocks ───────────────────────────────────
	if ( ! empty( $options['do_faq'] ) ) {
		foreach ( ekwa_legacy_find_carbon_blocks( $content ) as $block ) {
			if ( ! $block['rows'] ) {
				continue;
			}

			$markup = ekwa_legacy_faq_to_blocks( $block['rows'], $options['faq_mode'] );
			if ( '' === $markup ) {
				continue;
			}

			$content = str_replace( $block['match'], $markup, $content );

			$plan['faq_blocks']++;
			$plan['faq_rows'] += count( $block['rows'] );

			if ( count( $plan['samples'] ) < 3 ) {
				$plan['samples'][] = array(
					'type'   => 'faq',
					'label'  => $block['name'],
					'before' => $block['match'],
					'after'  => $markup,
				);
			}
		}
	}

	// ── Shortcodes, in the content and in the SEO fields ───────────
	$meta_updates = array();

	if ( ! empty( $options['do_shortcodes'] ) ) {
		$report = array();

		$new_content = ekwa_legacy_replace_shortcodes( $content, $map, $report );
		if ( $new_content !== $content ) {
			$plan['fields']['post_content'] = true;
			$content                        = $new_content;
		}

		$new_excerpt = ekwa_legacy_replace_shortcodes( $post->post_excerpt, $map, $report );
		if ( $new_excerpt !== $post->post_excerpt ) {
			$plan['fields']['post_excerpt'] = true;
			$plan['excerpt']                = $new_excerpt;
		}

		$new_title = ekwa_legacy_replace_shortcodes( $post->post_title, $map, $report );
		if ( $new_title !== $post->post_title ) {
			$plan['fields']['post_title'] = true;
			$plan['post_title']           = $new_title;
		}

		foreach ( ekwa_legacy_seo_meta_keys() as $key ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}
			$new_value = ekwa_legacy_replace_shortcodes( $value, $map, $report );
			if ( $new_value !== $value ) {
				$meta_updates[ $key ]     = $new_value;
				$plan['fields'][ $key ]   = true;
				if ( count( $plan['samples'] ) < 8 ) {
					$plan['samples'][] = array(
						'type'   => 'meta',
						'label'  => $key,
						'before' => $value,
						'after'  => $new_value,
					);
				}
			}
		}

		$plan['converted'] = isset( $report['converted'] ) ? $report['converted'] : array();
		$plan['skipped']   = isset( $report['skipped'] ) ? $report['skipped'] : array();
	}

	$plan['content'] = $content;
	$plan['meta']    = $meta_updates;
	$plan['changed'] = ( $content !== $post->post_content )
		|| ! empty( $meta_updates )
		|| ! empty( $plan['fields'] );

	if ( $content !== $post->post_content ) {
		$plan['fields']['post_content'] = true;
	}

	return $plan;
}

/**
 * Apply a plan.
 *
 * wp_update_post() rather than a direct write, so the change lands in the
 * revision history and can be rolled back post by post — the only undo a bulk
 * content rewrite gets.
 *
 * @param array $plan From ekwa_legacy_plan_post().
 * @return true|WP_Error
 */
function ekwa_legacy_apply_plan( $plan ) {
	if ( empty( $plan['changed'] ) ) {
		return true;
	}

	$update = array( 'ID' => (int) $plan['post_id'] );

	if ( ! empty( $plan['fields']['post_content'] ) ) {
		// wp_update_post() expects slashed data and unslashes it on the way in.
		$update['post_content'] = wp_slash( $plan['content'] );
	}
	if ( isset( $plan['excerpt'] ) ) {
		$update['post_excerpt'] = wp_slash( $plan['excerpt'] );
	}
	if ( isset( $plan['post_title'] ) ) {
		$update['post_title'] = wp_slash( $plan['post_title'] );
	}

	if ( count( $update ) > 1 ) {
		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	foreach ( (array) $plan['meta'] as $key => $value ) {
		update_post_meta( (int) $plan['post_id'], $key, wp_slash( $value ) );
	}

	return true;
}

/* ==================================================================
 * Scanning the site.
 * ================================================================== */

/**
 * Post types the scan looks at.
 *
 * @return string[]
 */
function ekwa_legacy_post_types() {
	$types = get_post_types( array( 'public' => true ), 'names' );
	unset( $types['attachment'] );

	/**
	 * Filter the post types the legacy import scan covers.
	 *
	 * @param string[] $types Post type names.
	 */
	return array_values( (array) apply_filters( 'ekwa_legacy_post_types', array_values( $types ) ) );
}

/**
 * Post statuses the scan looks at.
 *
 * @return string[]
 */
function ekwa_legacy_post_statuses() {
	return array( 'publish', 'future', 'draft', 'pending', 'private' );
}

/**
 * Walk the site and report every legacy thing found.
 *
 * Reads in batches and keeps only counts and short samples, so the peak memory
 * is one batch of posts rather than the whole library.
 *
 * @param int $limit Maximum posts to read.
 * @return array Report.
 */
function ekwa_legacy_scan( $limit = 20000 ) {
	global $wpdb;

	$types    = ekwa_legacy_post_types();
	$statuses = ekwa_legacy_post_statuses();
	$map      = ekwa_legacy_shortcode_map();
	$seo_keys = ekwa_legacy_seo_meta_keys();
	$self     = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$self     = preg_replace( '/^www\./', '', $self );

	$report = array(
		'scanned'    => 0,
		'shortcodes' => array(),
		'carbon'     => array(),
		'links'      => array(),
		'posts'      => array(),
		'truncated'  => false,
		'time'       => current_time( 'mysql' ),
	);

	if ( ! $types ) {
		return $report;
	}

	$type_in   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$status_in = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

	$batch  = 200;
	$after  = 0;

	while ( $report['scanned'] < $limit ) {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_content, post_excerpt
				 FROM {$wpdb->posts}
				 WHERE post_type IN ({$type_in})
				   AND post_status IN ({$status_in})
				   AND ID > %d
				 ORDER BY ID ASC
				 LIMIT %d",
				array_merge( $types, $statuses, array( $after, $batch ) )
			)
		);
		// phpcs:enable

		if ( ! $rows ) {
			break;
		}

		$ids = wp_list_pluck( $rows, 'ID' );
		$after = (int) end( $ids );

		// One query for every SEO field in the batch.
		$meta_by_post = array();
		if ( $seo_keys ) {
			$id_in   = implode( ',', array_map( 'intval', $ids ) );
			$key_in  = implode( ',', array_fill( 0, count( $seo_keys ), '%s' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$metas = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_key, meta_value
					 FROM {$wpdb->postmeta}
					 WHERE post_id IN ({$id_in})
					   AND meta_key IN ({$key_in})",
					$seo_keys
				)
			);
			// phpcs:enable
			foreach ( (array) $metas as $meta ) {
				$meta_by_post[ (int) $meta->post_id ][ $meta->meta_key ] = $meta->meta_value;
			}
		}

		foreach ( $rows as $row ) {
			$report['scanned']++;
			$post_id = (int) $row->ID;
			$touched = false;

			$fields = array( 'post_content' => $row->post_content );
			if ( '' !== (string) $row->post_excerpt ) {
				$fields['post_excerpt'] = $row->post_excerpt;
			}
			if ( '' !== (string) $row->post_title ) {
				$fields['post_title'] = $row->post_title;
			}
			foreach ( ( isset( $meta_by_post[ $post_id ] ) ? $meta_by_post[ $post_id ] : array() ) as $key => $value ) {
				if ( is_string( $value ) && '' !== $value ) {
					$fields[ 'meta:' . $key ] = $value;
				}
			}

			// ── Shortcodes ────────────────────────────────────────
			foreach ( $fields as $field => $value ) {
				foreach ( ekwa_legacy_find_shortcodes( $value ) as $hit ) {
					$tag = $hit['tag'];

					if ( ! isset( $report['shortcodes'][ $tag ] ) ) {
						$report['shortcodes'][ $tag ] = array(
							'count'  => 0,
							'posts'  => array(),
							'fields' => array(),
							'sample' => $hit['match'],
						);
					}

					$report['shortcodes'][ $tag ]['count']++;
					$report['shortcodes'][ $tag ]['posts'][ $post_id ]  = true;
					$report['shortcodes'][ $tag ]['fields'][ $field ]   = isset( $report['shortcodes'][ $tag ]['fields'][ $field ] )
						? $report['shortcodes'][ $tag ]['fields'][ $field ] + 1
						: 1;

					if ( isset( $map[ $tag ] ) && '' !== $map[ $tag ] ) {
						$touched = true;
					}
				}
			}

			// ── Carbon Fields blocks ──────────────────────────────
			foreach ( ekwa_legacy_find_carbon_blocks( $row->post_content ) as $block ) {
				$name = $block['name'];

				if ( ! isset( $report['carbon'][ $name ] ) ) {
					$report['carbon'][ $name ] = array(
						'blocks' => 0,
						'rows'   => 0,
						'posts'  => array(),
					);
				}

				$report['carbon'][ $name ]['blocks']++;
				$report['carbon'][ $name ]['rows'] += count( $block['rows'] );
				$report['carbon'][ $name ]['posts'][ $post_id ] = true;

				if ( $block['rows'] ) {
					$touched = true;
				}
			}

			// ── Off-site links ────────────────────────────────────
			foreach ( ekwa_legacy_find_links( ekwa_legacy_scannable_html( $row->post_content ), $self ) as $host => $paths ) {
				if ( ! isset( $report['links'][ $host ] ) ) {
					$report['links'][ $host ] = array( 'count' => 0, 'paths' => array(), 'posts' => array() );
				}
				foreach ( $paths as $path => $n ) {
					$report['links'][ $host ]['count']          += $n;
					$report['links'][ $host ]['paths'][ $path ]  = ( isset( $report['links'][ $host ]['paths'][ $path ] ) ? $report['links'][ $host ]['paths'][ $path ] : 0 ) + $n;
				}
				$report['links'][ $host ]['posts'][ $post_id ] = true;
			}

			if ( $touched ) {
				$report['posts'][] = $post_id;
			}
		}

		if ( count( $rows ) < $batch ) {
			break;
		}
	}

	if ( $report['scanned'] >= $limit ) {
		$report['truncated'] = true;
	}

	// Classify each tag and sort the report so the biggest problems are first.
	foreach ( $report['shortcodes'] as $tag => &$entry ) {
		$entry['posts'] = array_keys( $entry['posts'] );

		if ( isset( $map[ $tag ] ) && '' !== $map[ $tag ] ) {
			$entry['status']    = 'mapped';
			$entry['mapped_to'] = $map[ $tag ];
		} elseif ( shortcode_exists( $tag ) ) {
			$entry['status']    = 'registered';
			$entry['mapped_to'] = '';
		} else {
			$entry['status']    = 'unmapped';
			$entry['mapped_to'] = '';
		}
	}
	unset( $entry );

	foreach ( $report['carbon'] as &$carbon ) {
		$carbon['posts'] = array_keys( $carbon['posts'] );
	}
	unset( $carbon );

	foreach ( $report['links'] as &$link ) {
		$link['posts'] = array_keys( $link['posts'] );
		arsort( $link['paths'] );
	}
	unset( $link );

	uasort(
		$report['shortcodes'],
		static function ( $a, $b ) {
			return $b['count'] - $a['count'];
		}
	);
	uasort(
		$report['links'],
		static function ( $a, $b ) {
			return $b['count'] - $a['count'];
		}
	);

	$report['posts'] = array_values( array_unique( $report['posts'] ) );

	return $report;
}

/* ==================================================================
 * Admin.
 * ================================================================== */

/**
 * Transient key holding the current user's scan / preview / result payload.
 *
 * @param string $what 'scan', 'preview' or 'result'.
 * @return string
 */
function ekwa_legacy_transient( $what ) {
	return 'ekwa_legacy_' . $what . '_' . get_current_user_id();
}

/**
 * Read the options a form submitted.
 *
 * @return array
 */
function ekwa_legacy_options_from_post() {
	$faq_mode = empty( $_POST['ekwa_legacy_faq_core'] ) ? 'container' : 'heading';

	return array(
		'do_shortcodes' => ! empty( $_POST['ekwa_legacy_do_shortcodes'] ),
		'do_faq'        => ! empty( $_POST['ekwa_legacy_do_faq'] ),
		'faq_mode'      => $faq_mode,
	);
}

/**
 * Handle the Legacy Import tab's actions.
 *
 * Post/Redirect/Get with the payload parked in a transient, the same shape the
 * Bulk Page Creator uses, so a refresh after a conversion cannot run it twice.
 */
function ekwa_legacy_handle_actions() {
	if ( empty( $_POST['ekwa_legacy_action'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! isset( $_POST['ekwa_legacy_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ekwa_legacy_nonce'] ) ), 'ekwa_legacy_import' ) ) {
		return;
	}

	$action  = sanitize_key( wp_unslash( $_POST['ekwa_legacy_action'] ) );
	$options = ekwa_legacy_options_from_post();

	// The mapping table is submitted with every action, so a replacement typed
	// in next to [call] is saved by the same click that previews it.
	if ( isset( $_POST['ekwa_legacy_map'] ) && is_array( $_POST['ekwa_legacy_map'] ) ) {
		$saved = array();
		foreach ( wp_unslash( $_POST['ekwa_legacy_map'] ) as $tag => $replacement ) {
			$tag = ekwa_legacy_sanitize_tag( $tag );
			if ( '' === $tag ) {
				continue;
			}
			// Not sanitize_text_field(): the replacement IS a shortcode, and
			// that would be fine, but it may also legitimately carry markup.
			$saved[ $tag ] = trim( wp_kses_post( (string) $replacement ) );
		}
		update_option( 'ekwa_legacy_shortcode_map', $saved, false );
	}

	delete_transient( ekwa_legacy_transient( 'preview' ) );
	delete_transient( ekwa_legacy_transient( 'result' ) );

	if ( 'scan' === $action || 'save_map' === $action ) {
		set_transient( ekwa_legacy_transient( 'scan' ), ekwa_legacy_scan(), HOUR_IN_SECONDS );
	}

	if ( 'preview' === $action || 'convert' === $action ) {
		$scan = ekwa_legacy_scan();
		set_transient( ekwa_legacy_transient( 'scan' ), $scan, HOUR_IN_SECONDS );

		$targets = array_slice( $scan['posts'], 0, 500 );
		$plans   = array();

		foreach ( $targets as $post_id ) {
			$plan = ekwa_legacy_plan_post( $post_id, $options );
			if ( $plan && $plan['changed'] ) {
				$plans[] = $plan;
			}
		}

		if ( 'preview' === $action ) {
			// The rewritten content is only needed to apply a plan; parking it
			// in a transient would put a copy of every post in the options
			// table for an hour to display a summary that never reads it.
			$light = array();
			foreach ( $plans as $plan ) {
				unset( $plan['content'], $plan['meta'], $plan['excerpt'], $plan['post_title'] );
				$light[] = $plan;
			}
			set_transient(
				ekwa_legacy_transient( 'preview' ),
				array(
					'plans'     => $light,
					'options'   => $options,
					'remaining' => max( 0, count( $scan['posts'] ) - count( $targets ) ),
				),
				HOUR_IN_SECONDS
			);
		} else {
			$done   = 0;
			$errors = array();

			foreach ( $plans as $plan ) {
				$applied = ekwa_legacy_apply_plan( $plan );
				if ( is_wp_error( $applied ) ) {
					$errors[] = sprintf( '#%d — %s', $plan['post_id'], $applied->get_error_message() );
					continue;
				}
				$done++;
			}

			set_transient(
				ekwa_legacy_transient( 'result' ),
				array(
					'posts'     => $done,
					'errors'    => $errors,
					'options'   => $options,
					'remaining' => max( 0, count( $scan['posts'] ) - count( $targets ) ),
				),
				HOUR_IN_SECONDS
			);

			// The site just changed; the scan on screen describes the site
			// before it did.
			delete_transient( ekwa_legacy_transient( 'scan' ) );
		}
	}

	wp_safe_redirect( admin_url( 'themes.php?page=ekwa-settings&ekwa_tab=legacy-import' ) );
	exit;
}
add_action( 'admin_init', 'ekwa_legacy_handle_actions' );

/**
 * Shorten a blob for display without cutting a multibyte character in half.
 *
 * @param string $text  Text.
 * @param int    $limit Characters.
 * @return string
 */
function ekwa_legacy_excerpt( $text, $limit = 300 ) {
	$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	if ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) <= $limit : strlen( $text ) <= $limit ) {
		return $text;
	}
	$cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit ) : substr( $text, 0, $limit );
	return $cut . ' …';
}

/**
 * Render the Legacy Import tab.
 *
 * Rendered from ekwa_render_settings_page() after the Bulk Page Creator form,
 * in a form of its own — this runs an action, it does not save the settings the
 * main form saves.
 *
 * @param string $active_tab Currently selected tab slug.
 */
function ekwa_legacy_render_tab( $active_tab = '' ) {
	$scan    = get_transient( ekwa_legacy_transient( 'scan' ) );
	$preview = get_transient( ekwa_legacy_transient( 'preview' ) );
	$result  = get_transient( ekwa_legacy_transient( 'result' ) );

	if ( $result ) {
		delete_transient( ekwa_legacy_transient( 'result' ) );
	}

	$map      = ekwa_legacy_shortcode_map();
	$options  = $preview && isset( $preview['options'] ) ? $preview['options'] : ekwa_legacy_default_options();
	?>
	<form method="post" action="" class="ekwa-legacy-form" id="ekwa-legacy-form">
		<?php wp_nonce_field( 'ekwa_legacy_import', 'ekwa_legacy_nonce' ); ?>
		<div class="ekwa-tab-pane <?php echo 'legacy-import' === $active_tab ? 'active' : ''; ?>" data-tab="legacy-import">

			<div class="ekwa-section">
				<h2><?php esc_html_e( 'Legacy Import Cleanup', 'ekwa' ); ?></h2>
				<p class="description" style="margin-bottom:1em;max-width:52em;">
					<?php esc_html_e( 'Run this after the WordPress importer has finished. It finds shortcodes the old theme registered and this one does not — they render as literal text, in the page and in the SEO description — and Carbon Fields blocks, whose content is still in the database but has nothing left to render it. Nothing is written until you press Convert, and every post that changes keeps a revision you can roll back to.', 'ekwa' ); ?>
				</p>

				<?php if ( is_array( $result ) ) : ?>
					<div class="notice notice-success inline" style="padding:12px 14px;margin:0 0 16px;">
						<p style="margin:0;">
							<strong>
								<?php
								printf(
									/* translators: %d: number of posts */
									esc_html( _n( 'Converted %d post.', 'Converted %d posts.', (int) $result['posts'], 'ekwa' ) ),
									(int) $result['posts']
								);
								?>
							</strong>
							<?php if ( ! empty( $result['remaining'] ) ) : ?>
								<?php
								printf(
									/* translators: %d: number of posts left */
									esc_html__( '%d more still to do — run Convert again.', 'ekwa' ),
									(int) $result['remaining']
								);
								?>
							<?php endif; ?>
						</p>
						<?php if ( ! empty( $result['errors'] ) ) : ?>
							<p style="margin:8px 0 0;"><strong><?php esc_html_e( 'Errors:', 'ekwa' ); ?></strong></p>
							<ul style="margin:4px 0 0 18px;list-style:disc;">
								<?php foreach ( $result['errors'] as $error ) : ?>
									<li><?php echo esc_html( $error ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<p class="submit" style="margin:0 0 8px;">
					<button type="submit" name="ekwa_legacy_action" value="scan" class="button button-secondary">
						<?php esc_html_e( 'Scan content', 'ekwa' ); ?>
					</button>
					<?php if ( is_array( $scan ) ) : ?>
						<span class="description" style="margin-left:10px;">
							<?php
							printf(
								/* translators: 1: number of posts, 2: date and time */
								esc_html__( '%1$d posts scanned at %2$s.', 'ekwa' ),
								(int) $scan['scanned'],
								esc_html( mysql2date( get_option( 'time_format' ) . ', ' . get_option( 'date_format' ), $scan['time'] ) )
							);
							?>
						</span>
					<?php endif; ?>
				</p>

				<?php if ( is_array( $scan ) && ! empty( $scan['truncated'] ) ) : ?>
					<div class="notice notice-warning inline" style="padding:8px 12px;margin:0 0 16px;">
						<p style="margin:0;"><?php esc_html_e( 'The scan stopped at its post limit — the report below is partial.', 'ekwa' ); ?></p>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( is_array( $scan ) ) : ?>

				<?php // ── Shortcodes ─────────────────────────────── ?>
				<div class="ekwa-section">
					<h2><?php esc_html_e( 'Shortcodes found', 'ekwa' ); ?></h2>

					<?php if ( empty( $scan['shortcodes'] ) ) : ?>
						<p class="description"><?php esc_html_e( 'No shortcodes in any post. ✓', 'ekwa' ); ?></p>
					<?php else : ?>
						<p class="description" style="margin-bottom:1em;max-width:52em;">
							<?php esc_html_e( 'A tag with a replacement is converted when you press Convert. A tag without one is left exactly as it is — fill in what it should become and it will be converted on the next run, or leave it blank to keep it as literal text. Tags a plugin or this theme still registers are working already and are listed only so the inventory is complete.', 'ekwa' ); ?>
						</p>

						<table class="widefat striped" style="max-width:1100px;">
							<thead>
								<tr>
									<th style="width:140px;"><?php esc_html_e( 'Shortcode', 'ekwa' ); ?></th>
									<th style="width:70px;"><?php esc_html_e( 'Uses', 'ekwa' ); ?></th>
									<th style="width:70px;"><?php esc_html_e( 'Posts', 'ekwa' ); ?></th>
									<th><?php esc_html_e( 'Where', 'ekwa' ); ?></th>
									<th style="width:120px;"><?php esc_html_e( 'Status', 'ekwa' ); ?></th>
									<th style="width:260px;"><?php esc_html_e( 'Replace with', 'ekwa' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $scan['shortcodes'] as $tag => $entry ) : ?>
								<tr>
									<td><code>[<?php echo esc_html( $tag ); ?>]</code></td>
									<td><?php echo (int) $entry['count']; ?></td>
									<td><?php echo count( $entry['posts'] ); ?></td>
									<td>
										<?php
										$where = array();
										foreach ( $entry['fields'] as $field => $n ) {
											$label = ( 0 === strpos( $field, 'meta:' ) )
												? substr( $field, 5 )
												: $field;
											$where[] = $label . ' (' . (int) $n . ')';
										}
										echo esc_html( implode( ', ', $where ) );
										?>
									</td>
									<td>
										<?php if ( 'mapped' === $entry['status'] ) : ?>
											<span style="color:#1a7f37;font-weight:600;"><?php esc_html_e( '✓ mapped', 'ekwa' ); ?></span>
										<?php elseif ( 'registered' === $entry['status'] ) : ?>
											<span style="color:#646970;"><?php esc_html_e( 'registered', 'ekwa' ); ?></span>
										<?php else : ?>
											<span style="color:#996800;font-weight:600;"><?php esc_html_e( '⚠ needs a decision', 'ekwa' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( 'registered' === $entry['status'] ) : ?>
											<span class="description"><?php esc_html_e( 'works as-is', 'ekwa' ); ?></span>
										<?php else : ?>
											<input
												type="text"
												class="regular-text code"
												style="width:100%;"
												name="ekwa_legacy_map[<?php echo esc_attr( $tag ); ?>]"
												value="<?php echo esc_attr( isset( $map[ $tag ] ) ? $map[ $tag ] : '' ); ?>"
												placeholder="<?php esc_attr_e( 'e.g. [ekwa_phone]', 'ekwa' ); ?>"
												spellcheck="false"
											/>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<p class="description" style="margin-top:8px;">
							<?php
							printf(
								/* translators: %s: the literal token {content} */
								esc_html__( 'For a shortcode that wraps text — %1$s — put %2$s in the replacement to say where the wrapped text goes. Without it the shortcode is left alone rather than dropping the words between the tags.', 'ekwa' ),
								'<code>[cta]Book now[/cta]</code>',
								'<code>{content}</code>'
							);
							?>
						</p>
						<p class="submit" style="margin:8px 0 0;">
							<button type="submit" name="ekwa_legacy_action" value="save_map" class="button button-secondary">
								<?php esc_html_e( 'Save mapping', 'ekwa' ); ?>
							</button>
						</p>
					<?php endif; ?>
				</div>

				<?php // ── Carbon Fields ──────────────────────────── ?>
				<div class="ekwa-section">
					<h2><?php esc_html_e( 'Carbon Fields blocks', 'ekwa' ); ?></h2>

					<?php if ( empty( $scan['carbon'] ) ) : ?>
						<p class="description"><?php esc_html_e( 'No Carbon Fields blocks in any post. ✓', 'ekwa' ); ?></p>
					<?php else : ?>
						<table class="widefat striped" style="max-width:1100px;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Block', 'ekwa' ); ?></th>
									<th style="width:80px;"><?php esc_html_e( 'Blocks', 'ekwa' ); ?></th>
									<th style="width:110px;"><?php esc_html_e( 'Q&A pairs', 'ekwa' ); ?></th>
									<th style="width:70px;"><?php esc_html_e( 'Posts', 'ekwa' ); ?></th>
									<th><?php esc_html_e( 'Status', 'ekwa' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $scan['carbon'] as $name => $entry ) : ?>
								<tr>
									<td><code><?php echo esc_html( $name ); ?></code></td>
									<td><?php echo (int) $entry['blocks']; ?></td>
									<td><?php echo (int) $entry['rows']; ?></td>
									<td><?php echo count( $entry['posts'] ); ?></td>
									<td>
										<?php if ( $entry['rows'] ) : ?>
											<span style="color:#1a7f37;font-weight:600;"><?php esc_html_e( '✓ convertible', 'ekwa' ); ?></span>
										<?php else : ?>
											<span style="color:#996800;font-weight:600;"><?php esc_html_e( '⚠ no question/answer fields — left alone', 'ekwa' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<?php // ── Off-site links ─────────────────────────── ?>
				<?php if ( ! empty( $scan['links'] ) ) : ?>
					<div class="ekwa-section">
						<h2><?php esc_html_e( 'Off-site links', 'ekwa' ); ?></h2>
						<p class="description" style="margin-bottom:1em;max-width:52em;">
							<?php esc_html_e( 'Every link in the imported content that points somewhere other than this site, grouped by domain. Nothing here is changed — this is a list to check. The domain the content came from is normally the one at the top by a wide margin; those links still point at the old site.', 'ekwa' ); ?>
						</p>
						<table class="widefat striped" style="max-width:1100px;">
							<thead>
								<tr>
									<th style="width:300px;"><?php esc_html_e( 'Domain', 'ekwa' ); ?></th>
									<th style="width:80px;"><?php esc_html_e( 'Links', 'ekwa' ); ?></th>
									<th style="width:70px;"><?php esc_html_e( 'Posts', 'ekwa' ); ?></th>
									<th><?php esc_html_e( 'Most linked paths', 'ekwa' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $scan['links'] as $host => $entry ) : ?>
								<tr>
									<td><code><?php echo esc_html( $host ); ?></code></td>
									<td><?php echo (int) $entry['count']; ?></td>
									<td><?php echo count( $entry['posts'] ); ?></td>
									<td>
										<?php
										$paths = array_slice( $entry['paths'], 0, 6, true );
										$bits  = array();
										foreach ( $paths as $path => $n ) {
											$bits[] = esc_html( $path ) . ' <span style="color:#646970;">(' . (int) $n . ')</span>';
										}
										echo wp_kses_post( implode( '<br>', $bits ) );
										if ( count( $entry['paths'] ) > 6 ) {
											echo '<br><span style="color:#646970;">'
												. esc_html(
													sprintf(
														/* translators: %d: number of further paths */
														__( '+%d more', 'ekwa' ),
														count( $entry['paths'] ) - 6
													)
												)
												. '</span>';
										}
										?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>

				<?php // ── Run ────────────────────────────────────── ?>
				<div class="ekwa-section">
					<h2><?php esc_html_e( 'Convert', 'ekwa' ); ?></h2>

					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'What to convert', 'ekwa' ); ?></th>
							<td>
								<label style="display:block;margin-bottom:6px;">
									<input type="checkbox" name="ekwa_legacy_do_shortcodes" value="1" <?php checked( ! empty( $options['do_shortcodes'] ) ); ?> />
									<?php esc_html_e( 'Mapped shortcodes, in the content and in the SEO title / description', 'ekwa' ); ?>
								</label>
								<label style="display:block;">
									<input type="checkbox" name="ekwa_legacy_do_faq" value="1" <?php checked( ! empty( $options['do_faq'] ) ); ?> />
									<?php esc_html_e( 'Carbon Fields FAQ blocks', 'ekwa' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'FAQ blocks become', 'ekwa' ); ?></th>
							<td>
								<label style="display:block;">
									<input type="checkbox" name="ekwa_legacy_faq_core" value="1" <?php checked( 'heading' === $options['faq_mode'] ); ?> />
									<?php esc_html_e( 'Use plain heading + paragraphs instead', 'ekwa' ); ?>
								</label>
								<p class="description" style="max-width:52em;">
									<?php esc_html_e( 'Left unticked, each FAQ becomes an Ekwa FAQ Container: the question renders as an h2 and the answer as paragraphs, exactly as it reads now, and the container also emits FAQPage schema. Tick it to get bare core/heading and core/paragraph blocks with no schema.', 'ekwa' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<p class="submit" style="margin:0;">
						<button type="submit" name="ekwa_legacy_action" value="preview" class="button button-secondary">
							<?php esc_html_e( 'Preview changes', 'ekwa' ); ?>
						</button>
						<button type="submit" name="ekwa_legacy_action" value="convert" class="button button-primary"
							onclick="return confirm( '<?php echo esc_js( __( 'This rewrites post content. Each post keeps a revision you can roll back to. Continue?', 'ekwa' ) ); ?>' );">
							<?php esc_html_e( 'Convert', 'ekwa' ); ?>
						</button>
					</p>
				</div>

				<?php // ── Preview ────────────────────────────────── ?>
				<?php if ( is_array( $preview ) ) : ?>
					<div class="ekwa-section">
						<h2><?php esc_html_e( 'Preview', 'ekwa' ); ?></h2>

						<?php if ( empty( $preview['plans'] ) ) : ?>
							<p class="description"><?php esc_html_e( 'Nothing to change with the current options and mapping.', 'ekwa' ); ?></p>
						<?php else : ?>
							<p class="description" style="margin-bottom:1em;">
								<?php
								printf(
									/* translators: %d: number of posts */
									esc_html( _n( '%d post would change. Nothing has been written.', '%d posts would change. Nothing has been written.', count( $preview['plans'] ), 'ekwa' ) ),
									count( $preview['plans'] )
								);
								?>
							</p>

							<table class="widefat striped" style="max-width:1100px;">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Post', 'ekwa' ); ?></th>
										<th style="width:220px;"><?php esc_html_e( 'Shortcodes', 'ekwa' ); ?></th>
										<th style="width:150px;"><?php esc_html_e( 'FAQ', 'ekwa' ); ?></th>
										<th style="width:240px;"><?php esc_html_e( 'Fields', 'ekwa' ); ?></th>
									</tr>
								</thead>
								<tbody>
								<?php foreach ( $preview['plans'] as $plan ) : ?>
									<tr>
										<td>
											<a href="<?php echo esc_url( get_edit_post_link( $plan['post_id'] ) ); ?>" target="_blank" rel="noopener">
												<?php echo esc_html( $plan['title'] ); ?>
											</a>
										</td>
										<td>
											<?php
											$bits = array();
											foreach ( $plan['converted'] as $tag => $n ) {
												$bits[] = '[' . $tag . '] × ' . (int) $n;
											}
											echo esc_html( $bits ? implode( ', ', $bits ) : '—' );

											if ( ! empty( $plan['skipped'] ) ) {
												$skipped = array();
												foreach ( $plan['skipped'] as $tag => $n ) {
													$skipped[] = '[' . $tag . '] × ' . (int) $n;
												}
												echo '<br><span style="color:#996800;">'
													. esc_html(
														sprintf(
															/* translators: %s: list of shortcodes */
															__( 'skipped (wraps text): %s', 'ekwa' ),
															implode( ', ', $skipped )
														)
													)
													. '</span>';
											}
											?>
										</td>
										<td>
											<?php
											if ( $plan['faq_blocks'] ) {
												printf(
													/* translators: 1: number of blocks, 2: number of question/answer pairs */
													esc_html__( '%1$d block, %2$d Q&A', 'ekwa' ),
													(int) $plan['faq_blocks'],
													(int) $plan['faq_rows']
												);
											} else {
												echo '—';
											}
											?>
										</td>
										<td><?php echo esc_html( implode( ', ', array_keys( $plan['fields'] ) ) ); ?></td>
									</tr>
									<?php if ( ! empty( $plan['samples'] ) ) : ?>
										<tr>
											<td colspan="4" style="background:#fbfbfb;">
												<details>
													<summary style="cursor:pointer;"><?php esc_html_e( 'Show before / after', 'ekwa' ); ?></summary>
													<?php foreach ( $plan['samples'] as $sample ) : ?>
														<p style="margin:10px 0 4px;"><strong><?php echo esc_html( $sample['label'] ); ?></strong></p>
														<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
															<pre style="margin:0;padding:8px;background:#fff4f4;border:1px solid #f0c0c0;overflow:auto;max-height:260px;white-space:pre-wrap;word-break:break-word;font-size:11px;"><?php echo esc_html( ekwa_legacy_excerpt( $sample['before'], 1200 ) ); ?></pre>
															<pre style="margin:0;padding:8px;background:#f2fbf4;border:1px solid #b7deb7;overflow:auto;max-height:260px;white-space:pre-wrap;word-break:break-word;font-size:11px;"><?php echo esc_html( ekwa_legacy_excerpt( $sample['after'], 1200 ) ); ?></pre>
														</div>
													<?php endforeach; ?>
												</details>
											</td>
										</tr>
									<?php endif; ?>
								<?php endforeach; ?>
								</tbody>
							</table>

							<?php if ( ! empty( $preview['remaining'] ) ) : ?>
								<p class="description" style="margin-top:8px;">
									<?php
									printf(
										/* translators: %d: number of posts beyond the batch limit */
										esc_html__( 'Showing the first 500 posts; %d more will be handled by a second run.', 'ekwa' ),
										(int) $preview['remaining']
									);
									?>
								</p>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				<?php endif; ?>

			<?php else : ?>
				<div class="ekwa-section">
					<p class="description"><?php esc_html_e( 'Press “Scan content” to see what the import left behind.', 'ekwa' ); ?></p>
				</div>
			<?php endif; ?>

		</div><!-- /legacy-import -->
	</form>
	<?php
}
