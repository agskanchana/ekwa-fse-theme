<?php
/**
 * Inner Page Template — the design vocabulary imported content is built from.
 *
 * THE IDEA: converting a page faithfully gets the content across but leaves it
 * looking like the site it came from. What makes an imported page look like it
 * belongs here is being rebuilt out of THIS site's sections. So one page — the
 * Inner Page Template — holds one example of every section design an inner page
 * should be able to use: intro, two-column with image, styled lists, FAQ,
 * before/after, video, call-to-action, and whatever else the practice adds
 * later.
 *
 * WHY IT WORKS: every section the AI Block Builder produces is a single
 * top-level ekwa/div carrying its own CSS in its `scopedCss` attribute (see
 * inc/ekwa-ai-generate-blocks.php). A section is therefore self-contained —
 * copying its block markup copies its design with it, no stylesheet to chase.
 * That turns "design this page" into "choose a section and fill it in", which
 * is a far smaller and far more reliable job than inventing a layout, and it is
 * why adding a section to this page immediately improves every future import.
 *
 * The page is deliberately a NORMAL page, editable in the block editor, so the
 * practice can keep growing the vocabulary without touching code. It is kept
 * out of search engines, site search, the sitemap and the internal-link index —
 * it is a workbench, not content.
 *
 * NOTHING HERE IS AUTOMATIC. The page is created only when someone clicks the
 * button in Ekwa Settings → Design Setup, and an existing page is never
 * overwritten or repurposed.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Option holding the designated template page's ID. */
const EKWA_INNER_TEMPLATE_OPTION = 'ekwa_inner_template_id';

/**
 * The designated Inner Page Template page ID, or 0 when none is set.
 *
 * Returns 0 rather than a stale ID when the page has been deleted or trashed,
 * so every caller can treat "no template" as one condition.
 *
 * @return int
 */
function ekwa_inner_template_id() {
	$id = (int) get_option( EKWA_INNER_TEMPLATE_OPTION, 0 );
	if ( $id <= 0 ) {
		return 0;
	}

	$post = get_post( $id );
	if ( ! $post || 'page' !== $post->post_type || 'trash' === $post->post_status ) {
		return 0;
	}

	return $id;
}

/**
 * The template page object, or null.
 *
 * @return WP_Post|null
 */
function ekwa_inner_template_post() {
	$id = ekwa_inner_template_id();
	return $id ? get_post( $id ) : null;
}

/**
 * Whether a page is the Inner Page Template.
 *
 * @param int $post_id
 * @return bool
 */
function ekwa_inner_template_is( $post_id ) {
	$id = ekwa_inner_template_id();
	return $id && (int) $post_id === $id;
}

/* ------------------------------------------------------------------
 * Keeping it out of the way.
 * ------------------------------------------------------------------ */

/**
 * Mark the template page noindex for search engines.
 *
 * Two belts: the Yoast meta this theme already writes elsewhere (so the tag is
 * right in Yoast's own output and its sitemap), and a wp_robots filter for
 * sites without Yoast. Setting only the meta would leave a Yoast-less site
 * indexing the workbench.
 *
 * @param int $post_id
 * @return void
 */
function ekwa_inner_template_apply_noindex( $post_id ) {
	update_post_meta( (int) $post_id, '_yoast_wpseo_meta-robots-noindex', '1' );
	update_post_meta( (int) $post_id, '_yoast_wpseo_meta-robots-nofollow', '1' );
}

/**
 * wp_robots fallback — noindex the template even without an SEO plugin.
 *
 * @param array $robots
 * @return array
 */
function ekwa_inner_template_robots( $robots ) {
	if ( is_singular( 'page' ) && ekwa_inner_template_is( get_queried_object_id() ) ) {
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
		unset( $robots['index'], $robots['follow'] );
	}
	return $robots;
}
add_filter( 'wp_robots', 'ekwa_inner_template_robots' );

/**
 * Keep the template out of WordPress's own sitemap.
 *
 * @param array $args
 * @return array
 */
function ekwa_inner_template_exclude_from_sitemap( $args ) {
	$id = ekwa_inner_template_id();
	if ( $id ) {
		$args['post__not_in'] = array_merge(
			isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(),
			array( $id )
		);
	}
	return $args;
}
add_filter( 'wp_sitemaps_posts_query_args', 'ekwa_inner_template_exclude_from_sitemap' );

/**
 * Keep the template out of on-site search results.
 *
 * Front-end search only — it must stay findable in the admin, which is where
 * the practice edits it.
 *
 * @param WP_Query $query
 * @return void
 */
function ekwa_inner_template_exclude_from_search( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
		return;
	}
	$id = ekwa_inner_template_id();
	if ( ! $id ) {
		return;
	}
	$excluded = (array) $query->get( 'post__not_in' );
	$query->set( 'post__not_in', array_merge( $excluded, array( $id ) ) );
}
add_action( 'pre_get_posts', 'ekwa_inner_template_exclude_from_search' );

/**
 * Keep the template out of the internal-linking suggestions.
 *
 * Without this the workbench becomes a link target and starts turning up as a
 * suggested destination while writing real pages.
 *
 * @param array $targets
 * @return array
 */
function ekwa_inner_template_filter_interlink( $targets ) {
	$id = ekwa_inner_template_id();
	if ( ! $id || ! is_array( $targets ) ) {
		return $targets;
	}

	return array_values( array_filter( $targets, static function ( $t ) use ( $id ) {
		return ! isset( $t['postId'] ) || (int) $t['postId'] !== $id;
	} ) );
}
add_filter( 'ekwa_interlink_page_targets', 'ekwa_inner_template_filter_interlink' );

/* ------------------------------------------------------------------
 * Designating / creating the page.
 * ------------------------------------------------------------------ */

/**
 * Point the theme at an existing page as the Inner Page Template.
 *
 * Used by the Design Setup tab's picker. Applies the noindex meta as part of
 * designating, because a page becoming the workbench should stop being indexed
 * from that moment — that is the user's explicit action, not a side effect.
 *
 * @param int $post_id
 * @return true|WP_Error
 */
function ekwa_inner_template_designate( $post_id ) {
	$post_id = (int) $post_id;
	$post    = $post_id ? get_post( $post_id ) : null;

	if ( ! $post || 'page' !== $post->post_type ) {
		return new WP_Error( 'ekwa_inner_template_bad_page', __( 'That is not a page.', 'ekwa' ) );
	}

	update_option( EKWA_INNER_TEMPLATE_OPTION, $post_id );
	ekwa_inner_template_apply_noindex( $post_id );

	return true;
}

/**
 * Create a starter Inner Page Template page.
 *
 * Only ever called from an explicit click. Refuses when a template is already
 * designated, and never adopts or rewrites an existing page — if a page with
 * this slug is already there, it is reported so the user can decide, which is
 * the theme's rule for anything that touches site state.
 *
 * The starter content is intentionally thin: a handful of clearly-labelled
 * sections that establish the shape, with a note telling the practice to
 * replace them with their own designs. The point is the vocabulary, and only
 * they know what their inner pages should look like.
 *
 * @return int|WP_Error New page ID.
 */
function ekwa_inner_template_create() {
	if ( ekwa_inner_template_id() ) {
		return new WP_Error(
			'ekwa_inner_template_exists',
			__( 'An Inner Page Template is already set. Open it to edit, or pick a different page.', 'ekwa' )
		);
	}

	$slug     = 'inner-page-template';
	$existing = get_page_by_path( $slug, OBJECT, 'page' );
	if ( $existing ) {
		return new WP_Error(
			'ekwa_inner_template_slug_taken',
			sprintf(
				/* translators: %s: page title */
				__( 'A page called "%s" already exists at that address. Choose it from the list above if it is the one you want, or rename it first — nothing was changed.', 'ekwa' ),
				get_the_title( $existing )
			)
		);
	}

	$page_id = wp_insert_post( array(
		'post_type'    => 'page',
		'post_title'   => __( 'Inner Page Template', 'ekwa' ),
		'post_name'    => $slug,
		'post_status'  => 'draft',
		'post_content' => ekwa_inner_template_starter_content(),
	), true );

	if ( is_wp_error( $page_id ) ) {
		return $page_id;
	}

	update_option( EKWA_INNER_TEMPLATE_OPTION, (int) $page_id );
	ekwa_inner_template_apply_noindex( (int) $page_id );

	return (int) $page_id;
}

/**
 * Block markup for the starter template.
 *
 * Each section is one top-level ekwa/div with a metadata.name — that name is
 * what the block editor's "Rename" shows in the list view, and it is what the
 * pattern extractor uses as the section's label. Naming a section is therefore
 * the whole interface for teaching the AI what a section is for.
 *
 * @return string
 */
function ekwa_inner_template_starter_content() {
	$sections = array(
		array(
			'name'    => __( 'Intro', 'ekwa' ),
			'heading' => __( 'Section heading', 'ekwa' ),
			'body'    => __( 'A short opening paragraph that introduces the topic of the page. Replace this whole section with your own intro design — the layout and styling you put here is what imported pages will be built from.', 'ekwa' ),
		),
		array(
			'name'    => __( 'Body with heading', 'ekwa' ),
			'heading' => __( 'A sub-topic on the page', 'ekwa' ),
			'body'    => __( 'The workhorse section: a heading and a few paragraphs of copy. Most imported content lands in one of these.', 'ekwa' ),
		),
		array(
			'name'    => __( 'Call to action', 'ekwa' ),
			'heading' => __( 'Ready to book?', 'ekwa' ),
			'body'    => __( 'A closing prompt with a phone number or an appointment link.', 'ekwa' ),
		),
	);

	$out = '';
	foreach ( $sections as $section ) {
		$attrs = wp_json_encode( array(
			'metadata'  => array( 'name' => $section['name'] ),
			'className' => 'ekwa-inner-section',
		) );

		$out .= '<!-- wp:ekwa/div ' . $attrs . ' -->' . "\n"
			. '<!-- wp:heading -->' . "\n"
			. '<h2 class="wp-block-heading">' . esc_html( $section['heading'] ) . '</h2>' . "\n"
			. '<!-- /wp:heading -->' . "\n"
			. '<!-- wp:paragraph -->' . "\n"
			. '<p>' . esc_html( $section['body'] ) . '</p>' . "\n"
			. '<!-- /wp:paragraph -->' . "\n"
			. '<!-- /wp:ekwa/div -->' . "\n\n";
	}

	return $out;
}

/* ------------------------------------------------------------------
 * The design vocabulary.
 *
 * The Inner Page Template is ONE source of section designs, not the only one.
 * A practice that has never opened that page still has designs worth reusing —
 * on its home page, in patterns it saved while building, in the theme's own
 * blocks. Requiring the template before anything could be built made the
 * feature useless until someone did homework first.
 *
 * Sources, in priority order (first wins on a tie):
 *   1. Patterns the practice saved themselves (wp_block posts — what the editor
 *      calls "Patterns"). This is the library that GROWS: build a page, see a
 *      section worth keeping, save it, and every later page can use it.
 *   2. Sections on the Inner Page Template, when one is set.
 *   3. Sections already on the site's own pages — the home page first. This is
 *      what makes the very first import look right with no setup at all.
 *
 * Everything here is READ-ONLY. Nothing in this file writes to a page, and a
 * site that never turns the design pass on is not affected by any of it.
 * ------------------------------------------------------------------ */

/** Cache key for the assembled vocabulary. */
const EKWA_DESIGN_VOCAB_TRANSIENT = 'ekwa_design_vocabulary';

/**
 * Every section design available to build a page from.
 *
 * A "design" is one top-level block that carries its own CSS in the wrapper's
 * `scopedCss` attribute, so its markup and its look travel together — copy it
 * and the styling comes along. That is what lets the model assemble a page by
 * choosing and filling sections rather than inventing CSS.
 *
 * Sections with no scopedCss are still collected from the template page (the
 * practice put them there on purpose) but NOT harvested from ordinary pages,
 * where an unstyled div is far more likely to be incidental markup than a
 * design worth reusing.
 *
 * @param int $exclude_post_id Page being built — never harvest from itself.
 * @param int $limit           Cap, so the prompt cannot grow without bound.
 * @return array<int,array{key:string,label:string,source:string,markup:string,
 *                         has_heading:bool,text_slots:int,media_slots:int}>
 */
function ekwa_design_vocabulary( $exclude_post_id = 0, $limit = 20 ) {
	$cached = get_transient( EKWA_DESIGN_VOCAB_TRANSIENT );
	if ( ! is_array( $cached ) ) {
		$cached = ekwa_design_vocabulary_build( $limit );
		set_transient( EKWA_DESIGN_VOCAB_TRANSIENT, $cached, HOUR_IN_SECONDS );
	}

	if ( $exclude_post_id ) {
		$cached = array_values( array_filter( $cached, static function ( $p ) use ( $exclude_post_id ) {
			return (int) $p['post_id'] !== (int) $exclude_post_id;
		} ) );
	}

	return $cached;
}

/**
 * Work out what to call a harvested section.
 *
 * The label is the only thing telling the model what a section is FOR, so it is
 * worth getting right. In order:
 *
 *   1. The block's name in the editor (List View → Rename) — the deliberate answer.
 *   2. Its wrapper classes: a practice that wrote class="section list-section"
 *      has already said what it is. Machine-generated scope classes are dropped
 *      first — the AI Block Builder stamps each section with something like
 *      `eai-sec-444a3d`, and "Eai sec 444a3d" is worse than no label at all.
 *   3. Its first heading, which at least says what the section is about.
 *   4. The caller's fallback.
 *
 * @param array  $block    Parsed block.
 * @param string $fallback Used when nothing better can be derived.
 * @return string
 */
function ekwa_design_label_for_block( $block, $fallback = '' ) {
	if ( ! empty( $block['attrs']['metadata']['name'] ) ) {
		return (string) $block['attrs']['metadata']['name'];
	}

	$class = isset( $block['attrs']['className'] ) ? trim( (string) $block['attrs']['className'] ) : '';
	if ( '' !== $class ) {
		$keep = array();
		foreach ( preg_split( '/\s+/', $class ) as $token ) {
			// Generated scope classes (eai-sec-1a2b3c, ekwa-sec-…, wp-block-…)
			// and the bare word "section" describe nothing.
			if ( '' === $token
				|| preg_match( '/^(e(kwa|ai)?-?sec|sec)-[0-9a-f]{4,}$/i', $token )
				|| preg_match( '/^(wp-block|is-layout|has)-/i', $token )
				|| preg_match( '/^sections?$/i', $token ) ) {
				continue;
			}
			$keep[] = $token;
		}

		$words = trim( preg_replace( '/\s+/', ' ', preg_replace( '/[-_]+/', ' ', implode( ' ', $keep ) ) ) );
		// A one-or-two character remnant is noise, not a name.
		if ( strlen( $words ) > 2 ) {
			return ucfirst( $words );
		}
	}

	$heading = ekwa_inner_template_first_heading( $block );

	return '' !== $heading ? $heading : $fallback;
}

/**
 * Assemble the vocabulary from every source. @see ekwa_design_vocabulary().
 *
 * @param int $limit
 * @return array
 */
function ekwa_design_vocabulary_build( $limit = 20 ) {
	$out  = array();
	$seen = array();

	// A design is identified by its CSS + wrapper classes, so the same section
	// repeated across ten pages is offered once.
	$add = static function ( $block, $label, $source, $post_id, $require_css ) use ( &$out, &$seen ) {
		if ( empty( $block['blockName'] ) ) {
			return;
		}
		$css = isset( $block['attrs']['scopedCss'] ) ? (string) $block['attrs']['scopedCss'] : '';

		// On an ordinary page we need SOME signal that a top-level block is a
		// deliberate section rather than incidental markup. Carrying its own
		// CSS is the strongest, but it is not the only one — requiring it meant
		// a page built by hand in the editor contributed nothing at all, which
		// makes "build one good page and the rest improve" simply untrue.
		//
		// Also accepted: a block the author NAMED in the List View (naming it is
		// an explicit "this is a section"), and a block with its own className
		// that actually holds content. Both are things a person did on purpose.
		if ( $require_css && '' === trim( $css ) ) {
			$named     = ! empty( $block['attrs']['metadata']['name'] );
			$has_class = '' !== trim( (string) ( $block['attrs']['className'] ?? '' ) );

			if ( ! $named && ! $has_class ) {
				return;
			}

			$shape = ekwa_inner_template_census( $block );
			// A section worth reusing has a shape: a heading, or a couple of
			// blocks of content. A lone wrapper around one paragraph is not a
			// design, it is a paragraph.
			if ( ! $named && ! ( $shape['headings'] > 0 || $shape['text'] >= 2 || $shape['media'] > 0 ) ) {
				return;
			}
		}

		$fingerprint = md5( $css . '|' . ( $block['attrs']['className'] ?? '' ) );
		if ( isset( $seen[ $fingerprint ] ) ) {
			return;
		}

		$markup = trim( serialize_block( $block ) );
		if ( '' === $markup ) {
			return;
		}

		$seen[ $fingerprint ] = true;
		$census               = ekwa_inner_template_census( $block );

		$out[] = array(
			'key'         => 'P' . ( count( $out ) + 1 ),
			'label'       => $label,
			'source'      => $source,
			'post_id'     => (int) $post_id,
			'markup'      => $markup,
			'has_heading' => $census['headings'] > 0,
			'text_slots'  => $census['text'],
			'media_slots' => $census['media'],
		);
	};

	$name_of = 'ekwa_design_label_for_block';

	// ── 1. Patterns the practice saved themselves ──────────────────
	foreach ( get_posts( array(
		'post_type'   => 'wp_block',
		'post_status' => 'publish',
		'numberposts' => $limit,
		'orderby'     => 'modified',
		'order'       => 'DESC',
	) ) as $pattern ) {
		foreach ( parse_blocks( $pattern->post_content ) as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$add( $block, $pattern->post_title, 'saved-pattern', $pattern->ID, false );
			break; // One design per saved pattern — its first top-level block.
		}
	}

	// ── 2. The Inner Page Template ─────────────────────────────────
	foreach ( ekwa_inner_template_patterns() as $p ) {
		foreach ( parse_blocks( $p['markup'] ) as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				$add( $block, $p['label'], 'template', ekwa_inner_template_id(), false );
			}
		}
	}

	// ── 3. Designs already on the site's own pages ─────────────────
	global $wpdb;
	$front = (int) get_option( 'page_on_front', 0 );

	// Front page first: it is where a practice's best sections usually live.
	$page_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts}
		  WHERE post_type = 'page' AND post_status = 'publish'
		    AND post_content LIKE %s
		  ORDER BY ID = %d DESC, post_modified DESC
		  LIMIT 25",
		'%scopedCss%',
		$front
	) );

	foreach ( (array) $page_ids as $page_id ) {
		if ( count( $out ) >= $limit ) {
			break;
		}
		$page = get_post( (int) $page_id );
		if ( ! $page ) {
			continue;
		}
		$n = 0;
		foreach ( parse_blocks( $page->post_content ) as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$n++;
			// require_css: on an ordinary page an unstyled wrapper is far more
			// likely to be incidental markup than a design worth offering.
			$add( $block, $name_of( $block, $page->post_title . ' section ' . $n ), 'page', $page->ID, true );
		}
	}

	return array_slice( $out, 0, $limit );
}

/**
 * Drop the cached vocabulary when a page or saved pattern changes.
 *
 * @param int $post_id
 * @return void
 */
function ekwa_design_vocabulary_flush( $post_id = 0 ) {
	delete_transient( EKWA_DESIGN_VOCAB_TRANSIENT );
}
add_action( 'save_post_page', 'ekwa_design_vocabulary_flush' );
add_action( 'save_post_wp_block', 'ekwa_design_vocabulary_flush' );
add_action( 'deleted_post', 'ekwa_design_vocabulary_flush' );

/* ------------------------------------------------------------------
 * The pattern vocabulary.
 * ------------------------------------------------------------------ */

/**
 * The template's sections, as a vocabulary of reusable patterns.
 *
 * One pattern per TOP-LEVEL block on the template page. Each carries its own
 * serialized markup, and because a section keeps its CSS in the wrapper's
 * `scopedCss` attribute, that markup is the design — copy it and the look comes
 * with it. This is what makes the design pass reliable: the model picks and
 * fills patterns instead of inventing CSS.
 *
 * The label is how the practice tells the model what a section is FOR:
 *   1. the block's metadata.name — what the editor's "Rename" sets, and the
 *      intended way to label a section;
 *   2. failing that, the first heading inside it;
 *   3. failing that, a positional name, which is a hint to go and name it.
 *
 * @return array<int,array{key:string,label:string,markup:string,blocks:array,
 *                         has_heading:bool,text_slots:int,media_slots:int}>
 */
function ekwa_inner_template_patterns() {
	$post = ekwa_inner_template_post();
	if ( ! $post || '' === trim( (string) $post->post_content ) ) {
		return array();
	}

	$patterns = array();
	$index    = 0;

	foreach ( parse_blocks( $post->post_content ) as $block ) {
		// parse_blocks() emits whitespace between blocks as nameless entries.
		if ( empty( $block['blockName'] ) ) {
			continue;
		}
		$index++;

		$markup = trim( serialize_block( $block ) );
		if ( '' === $markup ) {
			continue;
		}

		$label = '';
		if ( ! empty( $block['attrs']['metadata']['name'] ) ) {
			$label = (string) $block['attrs']['metadata']['name'];
		}
		if ( '' === $label ) {
			$label = ekwa_inner_template_first_heading( $block );
		}
		if ( '' === $label ) {
			/* translators: %d: section number */
			$label = sprintf( __( 'Section %d', 'ekwa' ), $index );
		}

		$census = ekwa_inner_template_census( $block );

		$patterns[] = array(
			'key'         => 'P' . $index,
			'label'       => $label,
			'markup'      => $markup,
			'blocks'      => $census['blocks'],
			'has_heading' => $census['headings'] > 0,
			'text_slots'  => $census['text'],
			'media_slots' => $census['media'],
		);
	}

	return $patterns;
}

/**
 * The text of the first heading anywhere inside a block tree.
 *
 * @param array $block Parsed block.
 * @return string
 */
function ekwa_inner_template_first_heading( $block ) {
	if ( isset( $block['blockName'] ) && 'core/heading' === $block['blockName'] ) {
		$text = trim( wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ) );
		if ( '' !== $text ) {
			return $text;
		}
	}

	foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $child ) {
		$found = ekwa_inner_template_first_heading( $child );
		if ( '' !== $found ) {
			return $found;
		}
	}

	return '';
}

/**
 * Count what a pattern is made of, so the model can judge what fits in it.
 *
 * A pattern with two text slots and one image is a poor home for a twelve
 * paragraph run; one with a heading and open body is a good one. The counts
 * travel with the pattern so that judgement is possible without shipping the
 * model the whole markup twice.
 *
 * @param array $block Parsed block.
 * @return array{blocks:array<string,int>,headings:int,text:int,media:int}
 */
function ekwa_inner_template_census( $block ) {
	$out = array( 'blocks' => array(), 'headings' => 0, 'text' => 0, 'media' => 0 );

	$walk = static function ( $node ) use ( &$walk, &$out ) {
		$name = isset( $node['blockName'] ) ? (string) $node['blockName'] : '';
		if ( '' !== $name ) {
			$out['blocks'][ $name ] = ( $out['blocks'][ $name ] ?? 0 ) + 1;

			if ( 'core/heading' === $name ) {
				$out['headings']++;
			} elseif ( in_array( $name, array( 'core/paragraph', 'core/list', 'ekwa/text' ), true ) ) {
				$out['text']++;
			} elseif ( in_array( $name, array( 'ekwa/image', 'core/image', 'ekwa/figure', 'ekwa/youtube-video', 'ekwa/vimeo-video' ), true ) ) {
				$out['media']++;
			}
		}
		foreach ( (array) ( $node['innerBlocks'] ?? array() ) as $child ) {
			$walk( $child );
		}
	};
	$walk( $block );

	return $out;
}

/**
 * A compact description of the vocabulary, for the model's prompt.
 *
 * Deliberately not the full markup of every pattern — that is sent separately
 * and only for the patterns actually in play. This is the menu.
 *
 * @return string Empty when no template is configured.
 */
function ekwa_inner_template_vocabulary_text() {
	$patterns = ekwa_inner_template_patterns();
	if ( ! $patterns ) {
		return '';
	}

	$lines = array();
	foreach ( $patterns as $p ) {
		$parts = array();
		if ( $p['has_heading'] ) {
			$parts[] = 'heading';
		}
		if ( $p['text_slots'] ) {
			$parts[] = $p['text_slots'] . ' text slot' . ( 1 === $p['text_slots'] ? '' : 's' );
		}
		if ( $p['media_slots'] ) {
			$parts[] = $p['media_slots'] . ' media slot' . ( 1 === $p['media_slots'] ? '' : 's' );
		}

		$lines[] = sprintf(
			'- %s "%s" — %s',
			$p['key'],
			$p['label'],
			$parts ? implode( ', ', $parts ) : 'no content slots'
		);
	}

	return implode( "\n", $lines );
}

/* ------------------------------------------------------------------
 * The design pass.
 * ------------------------------------------------------------------ */

/**
 * Block types the design pass must never let the model rewrite.
 *
 * These are the blocks the deterministic import got exactly right, and whose
 * correctness lives in attributes a language model has no way to reproduce: a
 * video's transcript and upload date, an image's resolved attachment id, an
 * accordion's question/answer pairing. Sending them through the model risks
 * losing all of that to no benefit — their design is already the theme's.
 *
 * @return string[]
 */
function ekwa_inner_template_protected_blocks() {
	return array(
		'ekwa/faq',
		'ekwa/youtube-video',
		'ekwa/vimeo-video',
		'ekwa/image',
		'ekwa/figure',
		'ekwa/hero-video',
		'ekwa/carousel',
		'ekwa/slider',
	);
}

/**
 * Swap protected blocks for placeholder tokens.
 *
 * The model is asked to arrange a page around tokens like [[EKWA_KEEP_3]],
 * which it can move but cannot corrupt, and they are swapped back afterwards.
 * This is what lets the design pass be creative with layout while the import's
 * hard-won exactness — transcripts, attachment ids, phone shortcodes inside FAQ
 * answers — survives untouched.
 *
 * @param string $markup Block markup from the import conversion.
 * @return array{markup:string,kept:array<int,string>}
 */
function ekwa_inner_template_protect( $markup ) {
	$protected = ekwa_inner_template_protected_blocks();

	// Containers that only carried the SOURCE site's layout. They are unwrapped
	// so the model receives a flat run of content instead of the old site's div
	// soup — it is rebuilding the layout from this site's patterns, so carrying
	// the previous one across is worse than useless: it invites the model to
	// preserve a structure it has been asked to replace.
	$layout_wrappers = array( 'ekwa/div', 'core/group', 'core/columns', 'core/column', 'ekwa/section' );

	$kept = array();
	$out  = '';

	// Depth-first. Protected blocks are replaced WHEREVER they occur, not just
	// at the top level — in real imported markup a video sits three or four
	// wrappers deep, so a top-level-only sweep protects almost nothing.
	$walk = static function ( $blocks ) use ( &$walk, &$kept, &$out, $protected, $layout_wrappers ) {
		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

			// parse_blocks() emits the whitespace between blocks as nameless
			// entries; they carry nothing and would only add noise.
			if ( '' === $name ) {
				continue;
			}

			if ( in_array( $name, $protected, true ) ) {
				$kept[] = trim( serialize_block( $block ) );
				$out   .= "\n[[EKWA_KEEP_" . ( count( $kept ) - 1 ) . "]]\n";
				continue;
			}

			if ( in_array( $name, $layout_wrappers, true ) && ! empty( $block['innerBlocks'] ) ) {
				$walk( $block['innerBlocks'] );
				continue;
			}

			$out .= trim( serialize_block( $block ) ) . "\n";
		}
	};
	$walk( parse_blocks( $markup ) );

	return array( 'markup' => $out, 'kept' => $kept );
}

/**
 * Put the protected blocks back.
 *
 * A token the model dropped is not silently lost: every unused block is
 * appended at the end and reported, because losing a video or an FAQ off the
 * bottom of a page is exactly the kind of failure that is invisible in a
 * preview and expensive to discover later.
 *
 * @param string $markup   Model output containing tokens.
 * @param array  $kept     Serialized blocks from ekwa_inner_template_protect().
 * @param array  $warnings By reference.
 * @return string
 */
function ekwa_inner_template_restore( $markup, $kept, &$warnings ) {
	$used = array();

	$markup = preg_replace_callback(
		'/\[\[EKWA_KEEP_(\d+)\]\]/',
		static function ( $m ) use ( $kept, &$used ) {
			$i = (int) $m[1];
			if ( ! isset( $kept[ $i ] ) ) {
				return '';
			}
			$used[ $i ] = true;
			return "\n" . $kept[ $i ] . "\n";
		},
		$markup
	);

	$missing = array();
	foreach ( $kept as $i => $block ) {
		if ( ! isset( $used[ $i ] ) ) {
			$missing[] = $block;
		}
	}

	if ( $missing ) {
		$markup .= "\n" . implode( "\n", $missing ) . "\n";
		$warnings[] = array(
			'category' => 'dropped',
			'message'  => sprintf(
				/* translators: %d: number of blocks */
				_n(
					'The design pass left out %d block that had already been converted (an FAQ, video or image). It has been added at the end of the page — move it where it belongs.',
					'The design pass left out %d blocks that had already been converted (FAQs, videos or images). They have been added at the end of the page — move them where they belong.',
					count( $missing ),
					'ekwa'
				),
				count( $missing )
			),
		);
	}

	return $markup;
}

/**
 * Build the design-pass instruction sent to the model.
 *
 * The framing matters more than anything else here: this is a LAYOUT job, not a
 * writing job. The content has already been converted correctly and the model's
 * only task is to rehouse it in the site's own sections. Every rule below
 * exists to stop it drifting into rewriting copy, inventing CSS, or "improving"
 * links and phone numbers that the import deliberately set.
 *
 * @param array $patterns From ekwa_inner_template_patterns().
 * @return string
 */
function ekwa_inner_template_design_prompt( $patterns ) {
	$prompt = "You are rebuilding an existing page so that it looks like it belongs on THIS site.\n\n"
		. "WHAT YOU ARE GIVEN:\n"
		. ( $patterns
			? "1. SECTION DESIGNS — real sections from this site. Each is a complete top-level block whose CSS travels with it in the wrapper's scopedCss attribute.\n"
			: "" )
		. ( $patterns ? "2. " : "1. " ) . "PAGE CONTENT — the page's real content, already converted to blocks.\n\n"
		. "YOUR JOB: return the page's content, laid out as a well-built page using this theme's blocks"
		. ( $patterns ? ", reusing the section designs wherever they fit.\n\n" : ".\n\n" )
		. "HARD RULES — breaking any of these makes the result unusable:\n"
		. "- DO NOT rewrite, summarise, shorten, reorder or 'improve' any text. Every sentence of the supplied content must appear in your output, with the same wording, in the same order.\n"
		. "- [[EKWA_KEEP_n]] tokens are already-converted blocks (FAQ accordions, videos, images) that are already correct. Place each token EXACTLY ONCE, on its own line, where that content belongs in the flow. Never delete one, never duplicate one, never write anything inside one, and never alter the token text.\n"
		. "- Preserve every [ekwa_phone] shortcode and every href EXACTLY as written. They were resolved deliberately against this site's settings and pages.\n"
		. "- Output ONLY block markup. No prose, no explanation, no Markdown fences.\n\n";

	if ( $patterns ) {
		$vocab = array();
		foreach ( $patterns as $p ) {
			$vocab[] = "### " . $p['key'] . ' — "' . $p['label'] . "\"\n" . $p['markup'];
		}

		$prompt .= "USING THE SECTION DESIGNS:\n"
			. "- COPY them. Take a design's markup and replace the text inside it with the real content. Keep its className values and its scopedCss EXACTLY as given — that attribute IS the design, and retyping or 'tidying' it breaks the styling.\n"
			. "- Reuse a design as many times as the content calls for. That is the point of having them.\n"
			. "- Match content to the design whose shape fits: a heading plus body copy belongs in a body section; a list of points belongs in a list section; a closing prompt belongs in a call-to-action.\n"
			. "- Where nothing fits, build the section yourself from the blocks in the spec below rather than forcing content into the wrong design.\n\n"
			. "SECTION DESIGNS AVAILABLE:\n\n"
			. implode( "\n\n", $vocab ) . "\n\n";
	} else {
		// No designs anywhere yet — first import on a fresh site. Build from the
		// theme's blocks, and keep the CSS on the wrapper so whatever the model
		// produces is itself a self-contained section the practice can save as a
		// pattern afterwards, which is how the library starts.
		$prompt .= "THIS SITE HAS NO SAVED SECTION DESIGNS YET, so build the sections yourself:\n"
			. "- Group the content into sections, each one a top-level ekwa/div.\n"
			. "- Give each section a className and put ALL of its CSS in that wrapper's scopedCss attribute, scoped under that className. Do not emit a <style> block and do not style anything globally.\n"
			. "- Reuse the site's existing design tokens (the CSS custom properties listed in the site stylesheet below) for colors, spacing and fonts. Do not hardcode a hex value that an existing token already represents.\n"
			. "- Keep it restrained and readable: generous spacing, a clear heading hierarchy, no decoration the content does not ask for.\n\n";
	}

	return $prompt;
}

/**
 * Run the design pass over converted import markup.
 *
 * Returns the markup unchanged, with a warning, whenever it cannot do better —
 * no template configured, no patterns on it, no API key, or a model error. A
 * faithful page is always an acceptable outcome; a broken one is not, so every
 * failure path here falls back rather than propagating.
 *
 * @param string $markup   Block markup from the import conversion.
 * @param array  $warnings By reference.
 * @param array  $args     'model' => override.
 * @return string
 */
function ekwa_inner_template_design_pass( $markup, &$warnings, $args = array() ) {
	if ( '' === trim( $markup ) ) {
		return $markup;
	}

	// Section designs from everywhere the site has them. An empty list is NOT a
	// blocker any more: with no designs the model still builds the page out of
	// the theme's own blocks from the block spec, which is what "create with AI"
	// does. Designs, when they exist, make it look like this site.
	$patterns = ekwa_design_vocabulary( isset( $args['post_id'] ) ? (int) $args['post_id'] : 0 );

	if ( ! function_exists( 'ekwa_get_ai_api_key' ) || ! ekwa_get_ai_api_key() ) {
		$warnings[] = array(
			'category' => 'general',
			'message'  => __( 'No Gemini API key is configured, so the page was built as a faithful conversion instead of being restyled.', 'ekwa' ),
		);
		return $markup;
	}

	// Label the call so its tokens land under their own line in the AI usage
	// log rather than being blamed on whichever feature ran last.
	if ( function_exists( 'ekwa_ai_current_feature' ) ) {
		ekwa_ai_current_feature( 'import-design' );
	}

	$protected = ekwa_inner_template_protect( $markup );

	$system = ekwa_inner_template_design_prompt( $patterns );

	// The block spec is what lets this work with no saved designs at all: it is
	// the theme's own block vocabulary, the same one the AI Block Builder uses.
	if ( function_exists( 'ekwa_ai_build_block_spec_section' ) ) {
		$system .= ekwa_ai_build_block_spec_section( 'section' );
	}

	// The site's colors, tokens and stylesheet, so anything the model builds
	// itself uses this practice's design language rather than inventing one.
	if ( function_exists( 'ekwa_tokens_ai_context' ) ) {
		$system .= ekwa_tokens_ai_context();
	}

	$contents = ekwa_ai_generate_build_contents(
		"PAGE CONTENT to rehouse in the section patterns:\n\n" . $protected['markup'],
		array(),
		array()
	);
	if ( is_wp_error( $contents ) ) {
		$warnings[] = array( 'category' => 'general', 'message' => $contents->get_error_message() );
		return $markup;
	}

	$model = ekwa_ai_resolve_model( isset( $args['model'] ) ? (string) $args['model'] : '', 'pro' );

	$response = ekwa_ai_generate_call_gemini(
		$system,
		$contents,
		0.25,
		ekwa_get_ai_api_key(),
		$model,
		32768
	);

	if ( is_wp_error( $response ) ) {
		$warnings[] = array(
			'category' => 'general',
			'message'  => sprintf(
				/* translators: %s: error message */
				__( 'The design pass could not run (%s), so the page was built as a faithful conversion.', 'ekwa' ),
				$response->get_error_message()
			),
		);
		return $markup;
	}

	// ekwa_ai_generate_call_gemini() returns [content, tokens, finish_reason].
	$designed = ekwa_ai_generate_strip_fences(
		is_array( $response ) && isset( $response['content'] ) ? (string) $response['content'] : ''
	);
	$designed = trim( (string) $designed );

	// A response with no block markup in it means the model answered in prose;
	// keeping the faithful conversion beats shipping that.
	if ( '' === $designed || false === strpos( $designed, '<!-- wp:' ) ) {
		$warnings[] = array(
			'category' => 'general',
			'message'  => __( 'The design pass did not return usable block markup, so the page was built as a faithful conversion. Try running it again.', 'ekwa' ),
		);
		return $markup;
	}

	$restored = ekwa_inner_template_restore( $designed, $protected['kept'], $warnings );

	if ( $patterns ) {
		// Name the designs that were on offer: it is the clearest way to show
		// that saving a section as a pattern feeds straight back into this.
		$labels = wp_list_pluck( array_slice( $patterns, 0, 8 ), 'label' );
		$warnings[] = array(
			'category' => 'converted',
			'message'  => sprintf(
				/* translators: 1: number of designs, 2: comma-separated names */
				_n(
					'Rebuilt with AI using %1$d section design from this site: %2$s.',
					'Rebuilt with AI using %1$d section designs from this site: %2$s.',
					count( $patterns ),
					'ekwa'
				),
				count( $patterns ),
				implode( ', ', $labels ) . ( count( $patterns ) > 8 ? '…' : '' )
			),
		);
	} else {
		$warnings[] = array(
			'category' => 'converted',
			'message'  => __( 'Rebuilt with AI using the theme\'s blocks. This site has no saved section designs yet — save a section you like as a pattern (select it, then Create pattern) and it will be reused on the next page you build.', 'ekwa' ),
		);
	}

	return $restored;
}

/* ------------------------------------------------------------------
 * Settings UI (rendered inside Design Setup).
 * ------------------------------------------------------------------ */

/**
 * Handle the Design Setup tab's Inner Page Template controls.
 *
 * Both actions are explicit clicks. Designating writes only the option (plus
 * the noindex meta that designating implies); creating refuses rather than
 * touching a page that already exists.
 *
 * @return void
 */
function ekwa_inner_template_save_settings() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}

	// Create a starter page — its own submit button, so it can never happen as
	// a side effect of saving other settings.
	if ( ! empty( $_POST['ekwa_inner_template_create'] ) ) {
		$result = ekwa_inner_template_create();
		set_transient(
			'ekwa_inner_template_notice_' . get_current_user_id(),
			is_wp_error( $result )
				? array( 'type' => 'error', 'text' => $result->get_error_message() )
				: array(
					'type' => 'success',
					'text' => __( 'Inner Page Template created as a draft. Open it and build the section designs you want imported pages to use.', 'ekwa' ),
				),
			60
		);
		return;
	}

	if ( ! isset( $_POST['ekwa_inner_template_id'] ) ) {
		return;
	}

	$chosen = (int) $_POST['ekwa_inner_template_id'];

	if ( $chosen <= 0 ) {
		// "None" — stop using a template. The page itself is left alone,
		// noindex included: it was deliberately set and un-setting it here
		// would silently expose a page the practice may still be working on.
		update_option( EKWA_INNER_TEMPLATE_OPTION, 0 );
	} elseif ( $chosen !== ekwa_inner_template_id() ) {
		ekwa_inner_template_designate( $chosen );
	}

	if ( function_exists( 'ekwa_interlink_flush_index' ) ) {
		ekwa_interlink_flush_index();
	}
}

/**
 * Render the Inner Page Template controls for the Design Setup tab.
 *
 * Shows the patterns currently detected, because that list IS the feature: it
 * is how the practice sees what the AI has to work with, and it makes the
 * relationship between "add a section to this page" and "imported pages get
 * better" visible rather than something to be taken on trust.
 *
 * @return void
 */
function ekwa_inner_template_render_section() {
	$template_id = ekwa_inner_template_id();
	$patterns    = ekwa_inner_template_patterns();

	$notice = get_transient( 'ekwa_inner_template_notice_' . get_current_user_id() );
	if ( $notice ) {
		delete_transient( 'ekwa_inner_template_notice_' . get_current_user_id() );
	}

	$pages = get_posts( array(
		'post_type'   => 'page',
		'post_status' => array( 'publish', 'draft', 'private' ),
		'numberposts' => -1,
		'orderby'     => 'title',
		'order'       => 'ASC',
	) );
	?>
	<div class="ekwa-section">
		<h2><?php esc_html_e( 'Inner Page Template', 'ekwa' ); ?></h2>

		<?php if ( $notice ) : ?>
			<div class="notice notice-<?php echo 'error' === $notice['type'] ? 'error' : 'success'; ?> inline">
				<p><?php echo esc_html( $notice['text'] ); ?></p>
			</div>
		<?php endif; ?>

		<p class="description" style="max-width:70em;">
			<?php
			echo wp_kses(
				__( 'One page holding an example of <strong>every section design an inner page can use</strong> — intro, body with image, styled lists, FAQ, before/after, video, call to action. When imported content is turned into a page, it is rebuilt out of these sections, which is what makes it look like it belongs on this site rather than the one it came from.', 'ekwa' ),
				array( 'strong' => array() )
			);
			?>
		</p>
		<p class="description" style="max-width:70em;">
			<?php esc_html_e( 'Each section keeps its own CSS, so adding a section here immediately makes it available to every future import — no code, no stylesheet to maintain. The page is kept out of search engines, site search, the sitemap and internal-link suggestions.', 'ekwa' ); ?>
		</p>

		<table class="form-table">
			<tr>
				<th><label for="ekwa_inner_template_id"><?php esc_html_e( 'Template page', 'ekwa' ); ?></label></th>
				<td>
					<select id="ekwa_inner_template_id" name="ekwa_inner_template_id">
						<option value="0"><?php esc_html_e( '— none —', 'ekwa' ); ?></option>
						<?php foreach ( $pages as $page ) : ?>
							<option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( $template_id, $page->ID ); ?>>
								<?php
								echo esc_html( $page->post_title ? $page->post_title : sprintf( __( '(no title) #%d', 'ekwa' ), $page->ID ) );
								if ( 'publish' !== $page->post_status ) {
									echo ' — ' . esc_html( $page->post_status );
								}
								?>
							</option>
						<?php endforeach; ?>
					</select>

					<?php if ( $template_id ) : ?>
						<a class="button" href="<?php echo esc_url( (string) get_edit_post_link( $template_id ) ); ?>">
							<?php esc_html_e( 'Edit the template', 'ekwa' ); ?>
						</a>
					<?php endif; ?>

					<p class="description">
						<?php esc_html_e( 'Choosing a page here also marks it noindex. Saving with “none” stops using a template; the page itself is left untouched.', 'ekwa' ); ?>
					</p>
				</td>
			</tr>

			<?php if ( ! $template_id ) : ?>
				<tr>
					<th><?php esc_html_e( 'No template yet', 'ekwa' ); ?></th>
					<td>
						<button type="submit" name="ekwa_inner_template_create" value="1" class="button button-secondary">
							<?php esc_html_e( 'Create a starter template page', 'ekwa' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'Creates a draft page with three placeholder sections to replace with your own designs. Nothing existing is modified.', 'ekwa' ); ?>
						</p>
					</td>
				</tr>
			<?php else : ?>
				<tr>
					<th><?php esc_html_e( 'Sections detected', 'ekwa' ); ?></th>
					<td>
						<?php if ( ! $patterns ) : ?>
							<p><em><?php esc_html_e( 'None yet — the template page has no top-level sections on it. Add some and they appear here.', 'ekwa' ); ?></em></p>
						<?php else : ?>
							<ul style="margin:0;">
								<?php foreach ( $patterns as $p ) : ?>
									<li>
										<strong><?php echo esc_html( $p['label'] ); ?></strong>
										<span style="color:#757575;">
											<?php
											$bits = array();
											if ( $p['has_heading'] ) {
												$bits[] = __( 'heading', 'ekwa' );
											}
											if ( $p['text_slots'] ) {
												$bits[] = sprintf(
													/* translators: %d: number of text slots */
													_n( '%d text slot', '%d text slots', $p['text_slots'], 'ekwa' ),
													$p['text_slots']
												);
											}
											if ( $p['media_slots'] ) {
												$bits[] = sprintf(
													/* translators: %d: number of media slots */
													_n( '%d media slot', '%d media slots', $p['media_slots'], 'ekwa' ),
													$p['media_slots']
												);
											}
											echo esc_html( $bits ? ' — ' . implode( ', ', $bits ) : '' );
											?>
										</span>
									</li>
								<?php endforeach; ?>
							</ul>
							<p class="description">
								<?php esc_html_e( 'Names come from the block’s name in the editor — select a section, open the List View and rename it. A well-named section (“Before and after”, “Treatment steps”) is how the AI knows what it is for.', 'ekwa' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>
		</table>
	</div>
	<?php
}
