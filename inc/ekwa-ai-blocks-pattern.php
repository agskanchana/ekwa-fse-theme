<?php
/**
 * Build with AI (Blocks) — replicate a pattern.
 *
 * The last choice in the modal's Creativity select. Instead of designing a
 * section, the model is handed one pattern the author picked — a saved pattern,
 * a theme or child-theme pattern, or a section of the Inner Page Template — and
 * pours the author's content into that exact structure: the same blocks, the
 * same attributes, the same classNames.
 *
 * The pattern's CSS never goes through the model. It is lifted out of the
 * `scopedCss` attributes before the call and re-attached to the matching blocks
 * afterwards, so the look cannot drift and a large stylesheet costs no output
 * tokens (the Block Builder has been fighting request timeouts).
 *
 * Opt-in: nothing here runs unless the request names a pattern. A request
 * without one — any older editor script, any other Creativity choice — takes
 * exactly the path it took before.
 *
 * Read-only: patterns are read, never written.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Largest pattern (markup, CSS already lifted out) the model is asked to copy.
 *
 * The reply is the whole pattern again with the new content in it; past this
 * size it risks running into the output-token cap or the request timeout, and a
 * cut-off reply is a section with blocks missing. Saying so up front is kinder.
 */
const EKWA_AI_PATTERN_MAX_CHARS = 60000;

/**
 * Handle GET /ekwa/v1/ai-blocks-patterns — the patterns the modal offers.
 *
 * @return WP_REST_Response
 */
function ekwa_ai_blocks_patterns_request() {
	return rest_ensure_response( array( 'patterns' => ekwa_ai_pattern_choices() ) );
}

/**
 * Every pattern that can be replicated, for the modal's picker.
 *
 * Three sources, in the order the picker groups them:
 *   saved    — patterns saved on this site (wp_block posts; the editor's
 *              "My patterns"), synced or not.
 *   theme    — patterns registered by the theme, the child theme's patterns/
 *              folder, or a plugin. Core's own and the pattern directory's are
 *              left out: they are not this site's designs.
 *   template — the sections of the Inner Page Template, when one is set.
 *
 * @return array<int,array{value:string,label:string,group:string,summary:string}>
 */
function ekwa_ai_pattern_choices() {
	$choices = array();

	foreach ( get_posts( array(
		'post_type'   => 'wp_block',
		'post_status' => 'publish',
		'numberposts' => 200,
		'orderby'     => 'title',
		'order'       => 'ASC',
	) ) as $post ) {
		$choices[] = ekwa_ai_pattern_choice( 'block:' . $post->ID, $post->post_title, 'saved', $post->post_content );
	}

	if ( class_exists( 'WP_Block_Patterns_Registry' ) ) {
		foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			if ( ! ekwa_ai_pattern_is_site_pattern( $pattern ) ) {
				continue;
			}
			$choices[] = ekwa_ai_pattern_choice( 'theme:' . $pattern['name'], (string) ( $pattern['title'] ?? '' ), 'theme', (string) $pattern['content'] );
		}
	}

	if ( function_exists( 'ekwa_inner_template_patterns' ) ) {
		foreach ( ekwa_inner_template_patterns() as $section ) {
			$choices[] = ekwa_ai_pattern_choice( 'template:' . $section['key'], $section['label'], 'template', $section['markup'] );
		}
	}

	return array_values( array_filter( $choices ) );
}

/**
 * Whether a registered pattern is one of this site's, rather than core's.
 *
 * @param array $pattern Entry from WP_Block_Patterns_Registry.
 * @return bool
 */
function ekwa_ai_pattern_is_site_pattern( $pattern ) {
	$name   = isset( $pattern['name'] ) ? (string) $pattern['name'] : '';
	$source = isset( $pattern['source'] ) ? (string) $pattern['source'] : '';

	if ( '' === $name || 0 === strpos( $name, 'core/' ) ) {
		return false;
	}
	if ( 'core' === $source || 0 === strpos( $source, 'pattern-directory' ) ) {
		return false;
	}
	// Hidden from the inserter on purpose (template-only patterns) — hidden here too.
	if ( isset( $pattern['inserter'] ) && false === $pattern['inserter'] ) {
		return false;
	}

	return '' !== trim( (string) ( $pattern['content'] ?? '' ) );
}

/**
 * One row of the picker.
 *
 * The summary names what the pattern is made of, so two patterns with similar
 * titles can be told apart without leaving the modal.
 *
 * @param string $value  Reference the generate request sends back.
 * @param string $label  Pattern title.
 * @param string $group  saved | theme | template.
 * @param string $markup Pattern block markup.
 * @return array|null Null when the pattern holds no blocks.
 */
function ekwa_ai_pattern_choice( $value, $label, $group, $markup ) {
	$counts  = array();
	$has_css = false;

	$walk = static function ( $blocks ) use ( &$walk, &$counts, &$has_css ) {
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$counts[ $block['blockName'] ] = ( $counts[ $block['blockName'] ] ?? 0 ) + 1;
			if ( ! empty( $block['attrs']['scopedCss'] ) ) {
				$has_css = true;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$walk( $block['innerBlocks'] );
			}
		}
	};
	$walk( parse_blocks( (string) $markup ) );

	if ( ! $counts ) {
		return null;
	}

	// Wrappers say nothing about what a pattern is for.
	$skip     = array( 'ekwa/div', 'ekwa/container', 'ekwa/section', 'core/group' );
	$registry = WP_Block_Type_Registry::get_instance();
	$parts    = array();

	foreach ( $counts as $name => $count ) {
		if ( in_array( $name, $skip, true ) ) {
			continue;
		}
		if ( count( $parts ) >= 6 ) {
			$parts[] = '…';
			break;
		}
		$type    = $registry->get_registered( $name );
		$title   = ( $type && ! empty( $type->title ) ) ? $type->title : $name;
		$parts[] = $count > 1 ? $title . ' ×' . $count : $title;
	}

	$summary = implode( ' · ', $parts );
	if ( $has_css ) {
		$summary .= ( '' !== $summary ? ' — ' : '' ) . __( 'carries its own CSS', 'ekwa' );
	}

	return array(
		'value'   => (string) $value,
		'label'   => '' !== trim( (string) $label ) ? (string) $label : __( '(untitled pattern)', 'ekwa' ),
		'group'   => (string) $group,
		'summary' => $summary,
	);
}

/**
 * Look a picker reference back up.
 *
 * The request carries only the reference, never the markup, so what gets
 * replicated is always what the site actually holds.
 *
 * @param string $ref block:<id> | theme:<pattern name> | template:<key>.
 * @return array{label:string,markup:string}|WP_Error
 */
function ekwa_ai_pattern_resolve( $ref ) {
	$ref       = trim( (string) $ref );
	$not_found = new WP_Error(
		'pattern_not_found',
		__( 'That pattern could not be found — it may have been deleted or renamed. Pick it again from the list.', 'ekwa' ),
		array( 'status' => 400 )
	);

	$colon = strpos( $ref, ':' );
	if ( false === $colon ) {
		return $not_found;
	}
	$kind = substr( $ref, 0, $colon );
	$id   = substr( $ref, $colon + 1 );

	if ( 'block' === $kind ) {
		$post = get_post( (int) $id );
		if ( ! $post || 'wp_block' !== $post->post_type || 'publish' !== $post->post_status
			|| ! current_user_can( 'read_post', $post->ID ) ) {
			return $not_found;
		}
		return array( 'label' => $post->post_title, 'markup' => (string) $post->post_content );
	}

	if ( 'theme' === $kind && class_exists( 'WP_Block_Patterns_Registry' ) ) {
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( $id );
		if ( ! $pattern || ! ekwa_ai_pattern_is_site_pattern( $pattern ) ) {
			return $not_found;
		}
		return array( 'label' => (string) ( $pattern['title'] ?? $id ), 'markup' => (string) $pattern['content'] );
	}

	if ( 'template' === $kind && function_exists( 'ekwa_inner_template_patterns' ) ) {
		foreach ( ekwa_inner_template_patterns() as $section ) {
			if ( (string) $section['key'] === $id ) {
				return array( 'label' => (string) $section['label'], 'markup' => (string) $section['markup'] );
			}
		}
	}

	return $not_found;
}

/**
 * Get a pattern ready to show the model.
 *
 * - Lifts every `scopedCss` attribute out of the markup and keeps it aside,
 *   with the block it came from, for ekwa_ai_pattern_attach_css().
 * - Gives each generated section scope (eai-sec-…) a fresh name, in the markup
 *   and the CSS alike, so the copy and the original never share a scope: a later
 *   "Edit with AI" on one must not restyle the other on the same page.
 * - Drops pattern-override bindings, which only mean something inside the
 *   synced pattern they came from and would lock the copied text in the editor.
 *
 * @param string $markup Pattern block markup.
 * @return array{markup:string,styles:array<int,array{block:string,class:string,top:bool,css:string}>}
 */
function ekwa_ai_pattern_prepare( $markup ) {
	$styles = array();
	$blocks = ekwa_ai_pattern_strip_walk( parse_blocks( (string) $markup ), $styles, true );
	$out    = trim( serialize_blocks( $blocks ) );

	if ( preg_match_all( '/\b(?:eai|ekwa)-sec-[0-9a-f]{4,}\b/i', $out, $m ) ) {
		$map = array();
		foreach ( array_unique( $m[0] ) as $old ) {
			$map[ $old ] = 'eai-sec-' . substr( md5( uniqid( $old, true ) ), 0, 6 );
		}
		$out = strtr( $out, $map );
		foreach ( $styles as $i => $style ) {
			$styles[ $i ]['class'] = strtr( $style['class'], $map );
			$styles[ $i ]['css']   = strtr( $style['css'], $map );
		}
	}

	return array( 'markup' => $out, 'styles' => $styles );
}

/**
 * Recursive worker for ekwa_ai_pattern_prepare().
 *
 * @param array $blocks Parsed blocks.
 * @param array $styles Lifted CSS, by reference.
 * @param bool  $top    Whether $blocks is the top level.
 * @return array
 */
function ekwa_ai_pattern_strip_walk( $blocks, &$styles, $top ) {
	foreach ( $blocks as $i => $block ) {
		if ( empty( $block['blockName'] ) ) {
			continue;
		}

		if ( isset( $block['attrs']['scopedCss'] ) ) {
			$css = (string) $block['attrs']['scopedCss'];
			// Saved by someone without unfiltered_html, the CSS comes back
			// kses-escaped ("&gt;"); the renderer decodes it, and so must we.
			if ( function_exists( 'ekwa_css_decode_entities' ) ) {
				$css = ekwa_css_decode_entities( $css );
			}
			if ( '' !== trim( $css ) ) {
				$styles[] = array(
					'block' => (string) $block['blockName'],
					'class' => isset( $block['attrs']['className'] ) ? (string) $block['attrs']['className'] : '',
					'top'   => (bool) $top,
					'css'   => trim( $css ),
				);
			}
			unset( $blocks[ $i ]['attrs']['scopedCss'] );
		}

		if ( isset( $block['attrs']['metadata']['bindings'] ) && is_array( $block['attrs']['metadata']['bindings'] ) ) {
			foreach ( $block['attrs']['metadata']['bindings'] as $attr => $binding ) {
				if ( is_array( $binding ) && isset( $binding['source'] ) && 'core/pattern-overrides' === $binding['source'] ) {
					unset( $blocks[ $i ]['attrs']['metadata']['bindings'][ $attr ] );
				}
			}
			if ( empty( $blocks[ $i ]['attrs']['metadata']['bindings'] ) ) {
				unset( $blocks[ $i ]['attrs']['metadata']['bindings'] );
			}
			if ( empty( $blocks[ $i ]['attrs']['metadata'] ) ) {
				unset( $blocks[ $i ]['attrs']['metadata'] );
			}
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			$blocks[ $i ]['innerBlocks'] = ekwa_ai_pattern_strip_walk( $block['innerBlocks'], $styles, false );
		}
	}

	return $blocks;
}

/**
 * Put the pattern's CSS back on the replica.
 *
 * Each lifted stylesheet goes back onto every block of the same type whose
 * classes include the ones it was lifted from — the copied wrapper, and any
 * copies of a styled unit the content made the model repeat. The renderer
 * inlines identical CSS once per page, so repeats cost nothing on the front end.
 *
 * What cannot be matched that way (the model mangled a wrapper's class) rides
 * on the first top-level block that can hold CSS, and so does anything the
 * model wrote itself — unless that is merely an echo of the pattern's own CSS.
 *
 * @param string $markup Generated block markup.
 * @param array  $styles From ekwa_ai_pattern_prepare().
 * @param string $extra  CSS from the model's own <style> block (normally empty).
 * @return array{markup:string,css:string,warnings:array<int,string>}
 */
function ekwa_ai_pattern_attach_css( $markup, $styles, $extra = '' ) {
	$warnings = array();
	$extra    = trim( (string) $extra );
	$styles   = is_array( $styles ) ? array_values( $styles ) : array();

	if ( '' !== $extra && ekwa_ai_pattern_css_is_echo( $extra, $styles ) ) {
		$extra = '';
	}

	// What the modal's CSS panel shows, and what the next refine turn sees.
	$all = array();
	foreach ( $styles as $style ) {
		$all[] = $style['css'];
	}
	if ( '' !== $extra ) {
		$all[] = $extra;
	}
	$panel_css = implode( "\n\n", array_unique( $all ) );

	if ( ! $styles && '' === $extra ) {
		return array( 'markup' => $markup, 'css' => '', 'warnings' => $warnings );
	}

	$blocks  = parse_blocks( $markup );
	$matched = array_fill( 0, count( $styles ), 0 );
	$blocks  = ekwa_ai_pattern_attach_walk( $blocks, $styles, $matched );

	$leftover = array();
	foreach ( $styles as $k => $style ) {
		if ( ! $matched[ $k ] ) {
			$leftover[] = $style;
		}
	}

	if ( $leftover || '' !== $extra ) {
		$host = ekwa_ai_pattern_css_host( $blocks );

		if ( null === $host ) {
			$warnings[] = __( 'Some of the pattern’s CSS could not be attached to the new section (it has no block that can hold CSS). Copy the Section CSS panel into your stylesheet.', 'ekwa' );
		} else {
			foreach ( $leftover as $style ) {
				// The copied wrapper lost the classes its CSS targets — give
				// them back, or the re-attached selectors would match nothing.
				if ( $style['top'] && $style['block'] === $blocks[ $host ]['blockName'] ) {
					$blocks[ $host ] = ekwa_ai_pattern_merge_class( $blocks[ $host ], $style['class'] );
				}
				$blocks[ $host ] = ekwa_ai_pattern_add_css( $blocks[ $host ], $style['css'] );
			}
			if ( '' !== $extra ) {
				$blocks[ $host ] = ekwa_ai_pattern_add_css( $blocks[ $host ], $extra );
			}
		}
	}

	return array(
		'markup'   => serialize_blocks( $blocks ),
		'css'      => $panel_css,
		'warnings' => $warnings,
	);
}

/**
 * Recursive worker for ekwa_ai_pattern_attach_css().
 *
 * @param array $blocks  Parsed blocks.
 * @param array $styles  Lifted CSS.
 * @param array $matched Match counts per style, by reference.
 * @return array
 */
function ekwa_ai_pattern_attach_walk( $blocks, $styles, &$matched ) {
	foreach ( $blocks as $i => $block ) {
		if ( empty( $block['blockName'] ) ) {
			continue;
		}

		$have = ekwa_ai_pattern_class_tokens( $block['attrs']['className'] ?? '' );
		foreach ( $styles as $k => $style ) {
			$want = ekwa_ai_pattern_class_tokens( $style['class'] );
			// A styled block with no class of its own can't be told apart from
			// its siblings; the fallback host takes its CSS instead.
			if ( ! $want || $style['block'] !== $block['blockName'] || array_diff( $want, $have ) ) {
				continue;
			}
			$blocks[ $i ] = ekwa_ai_pattern_add_css( $blocks[ $i ], $style['css'] );
			$matched[ $k ]++;
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			$blocks[ $i ]['innerBlocks'] = ekwa_ai_pattern_attach_walk( $block['innerBlocks'], $styles, $matched );
		}
	}

	return $blocks;
}

/**
 * Index of the first top-level block whose type can hold `scopedCss`.
 *
 * @param array $blocks Parsed top-level blocks.
 * @return int|null
 */
function ekwa_ai_pattern_css_host( $blocks ) {
	$registry = WP_Block_Type_Registry::get_instance();
	foreach ( $blocks as $i => $block ) {
		if ( empty( $block['blockName'] ) ) {
			continue;
		}
		$type = $registry->get_registered( $block['blockName'] );
		if ( $type && isset( $type->attributes['scopedCss'] ) ) {
			return $i;
		}
	}
	return null;
}

/**
 * Append CSS to a block's `scopedCss`, once.
 *
 * Comments are stripped on the way in, as every other writer of this attribute
 * does — see ekwa_css_strip_comments() for why.
 *
 * @param array  $block Parsed block.
 * @param string $css   CSS to add.
 * @return array
 */
function ekwa_ai_pattern_add_css( $block, $css ) {
	$css = trim( (string) $css );
	if ( function_exists( 'ekwa_css_strip_comments' ) ) {
		$css = trim( ekwa_css_strip_comments( $css ) );
	}
	if ( '' === $css ) {
		return $block;
	}
	if ( ! isset( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
		$block['attrs'] = array();
	}

	$existing = isset( $block['attrs']['scopedCss'] ) ? trim( (string) $block['attrs']['scopedCss'] ) : '';
	if ( '' !== $existing && false !== strpos( $existing, $css ) ) {
		return $block;
	}

	$block['attrs']['scopedCss'] = '' !== $existing ? $existing . "\n\n" . $css : $css;
	return $block;
}

/**
 * Add any of $class's tokens the block is missing.
 *
 * @param array  $block Parsed block.
 * @param string $class Wanted classes.
 * @return array
 */
function ekwa_ai_pattern_merge_class( $block, $class ) {
	$have    = ekwa_ai_pattern_class_tokens( $block['attrs']['className'] ?? '' );
	$missing = array_diff( ekwa_ai_pattern_class_tokens( $class ), $have );
	if ( $missing ) {
		$block['attrs']['className'] = implode( ' ', array_merge( $have, $missing ) );
	}
	return $block;
}

/**
 * @param mixed $class className attribute.
 * @return string[]
 */
function ekwa_ai_pattern_class_tokens( $class ) {
	return array_values( array_unique( preg_split( '/\s+/', trim( (string) $class ), -1, PREG_SPLIT_NO_EMPTY ) ) );
}

/**
 * Whether the CSS the model wrote is just (part of) the pattern's own.
 *
 * Told not to, a model still sometimes hands the stylesheet back. Attached a
 * second time it would be harmless but doubles what every page view inlines.
 *
 * @param string $extra  Model CSS.
 * @param array  $styles Lifted CSS.
 * @return bool
 */
function ekwa_ai_pattern_css_is_echo( $extra, $styles ) {
	$squash = static function ( $css ) {
		if ( function_exists( 'ekwa_css_strip_comments' ) ) {
			$css = ekwa_css_strip_comments( $css );
		}
		return (string) preg_replace( '/\s+/', '', (string) $css );
	};

	$needle = $squash( $extra );
	if ( '' === $needle ) {
		return true;
	}

	$pattern_css = '';
	foreach ( (array) $styles as $style ) {
		$pattern_css .= $squash( $style['css'] );
	}

	return '' !== $pattern_css && false !== strpos( $pattern_css, $needle );
}

/**
 * The first-turn user message: the pattern, then the content to pour into it.
 *
 * @param string $label   Pattern title.
 * @param string $markup  Prepared pattern markup (CSS lifted out).
 * @param string $content The author's prompt.
 * @return string
 */
function ekwa_ai_pattern_user_message( $label, $markup, $content ) {
	return 'PATTERN TO REPLICATE — "' . $label . "\" (its CSS has been lifted out and is re-attached automatically; this markup is everything you work from):\n\n"
		. $markup
		. "\n\n---\nCONTENT FOR THE NEW SECTION — pour this into the pattern above:\n\n"
		. $content;
}

/**
 * System-prompt rules for the first turn of a replication.
 *
 * Appended after the normal Block Builder prompt, and said to override it where
 * they disagree: the normal prompt asks for a fresh EKWA_SCOPE wrapper and one
 * <style> block of new CSS, both of which are wrong when copying a pattern.
 *
 * @return string
 */
function ekwa_ai_pattern_system_prompt() {
	return "\n\nPATTERN REPLICATION MODE — these rules OVERRIDE the layout and styling rules above wherever the two disagree.\n"
		. "The user picked an existing pattern from this site as the EXACT design for this section. The user message holds that pattern's block markup, then the content to put into it. Pour the content into the pattern; do not design anything.\n"
		. "- STRUCTURE: reproduce the pattern's blocks exactly — the same block types, the same nesting and order, the same attributes, and the same className values character for character. Change only what carries content: heading and paragraph text, list items, button and link labels and their URLs, image URLs and alt text, FAQ questions and answers, and attributes of that kind.\n"
		. "- REPEATING UNITS (FAQ items, cards, list items, slides, repeated columns): emit one unit per item in the content by cloning the pattern's unit markup exactly, classNames included — even when that gives more or fewer units than the pattern has. Never merge items to fit the pattern's count, and never drop content to fit it.\n"
		. "- MAPPING: each piece of content goes into the pattern's slot of the same kind — the section heading into its heading, intro copy into its intro paragraph(s), questions and answers into FAQ items. When a slot receives more text than it holds, repeat that slot's block (same block type, same className) in the same place. When the content has something with no slot of its kind anywhere in the pattern, add it using the closest block the pattern already uses, beside the related content.\n"
		. "- UNFILLED SLOTS: keep the pattern's generic chrome as it is when the content does not replace it — a \"Book an Appointment\" button, icons, decorative images, dividers, and every data block (phone, address, hours, social, map, menus, logo). Remove a slot whose copy belongs to the pattern's old topic rather than leaving that old copy in the new section.\n"
		. "- COPY: use the user's text verbatim. Do not rewrite, summarise, reorder or add to it, and never carry the pattern's old wording into a slot the content fills.\n"
		. "- CSS: the pattern's CSS is re-attached automatically after you answer, and it already styles every className in the pattern. Do NOT write or copy CSS, do NOT add scopedCss or inlineStyle attributes, and do NOT invent classNames. Return an EMPTY <style></style> block. Only if you had to add an element the pattern has no equivalent for may the <style> block hold rules — for that element only, scoped under the pattern's top-level className.\n"
		. "- WRAPPER: keep the pattern's own top-level block(s) as your top level. Do not add a wrapper and do not use the EKWA_SCOPE placeholder — the pattern's classNames are final.";
}

/**
 * System-prompt note for the refine turns that follow a replication.
 *
 * By then the section is in the conversation as the model's own previous
 * answer, in the normal output shape, so the normal rules apply again — this
 * only stops a "make the heading bigger" from turning into a redesign.
 *
 * @return string
 */
function ekwa_ai_pattern_refine_prompt() {
	return "\n\nPATTERN REPLICA — the section in this conversation was built by pouring the user's content into a pattern they chose. Keep its structure, classNames and CSS exactly as they are and change only what the user asks for. Return the complete section with ALL of its CSS in the <style> block, as usual.";
}
