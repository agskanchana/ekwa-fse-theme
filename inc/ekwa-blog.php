<?php
/**
 * Ekwa Blog — TOC, author link, AJAX load-more, post card helpers.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// TABLE OF CONTENTS — Add IDs to H2/H3 headings in post content.
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Add id attributes to H2 and H3 headings that don't already have one.
 */
/**
 * Add id attributes to H2/H3 headings. Used by the_content filter
 * AND called directly by the TOC block render callback.
 *
 * @param string $content  HTML content.
 * @param bool   $force    Skip is_single() guard (for direct calls).
 * @return string
 */
function ekwa_toc_add_heading_ids( $content, $force = false ) {
	if ( ! $force && ( ! is_single() || ! is_main_query() ) ) {
		return $content;
	}

	$used_ids = array();

	$content = preg_replace_callback(
		'/<h([23])([^>]*)>(.*?)<\/h([23])>/si',
		function ( $matches ) use ( &$used_ids ) {
			$level      = $matches[1];
			$attrs      = $matches[2];
			$inner      = $matches[3];
			$close_level = $matches[4];

			if ( preg_match( '/\bid=["\']/', $attrs ) ) {
				return $matches[0];
			}

			$text = wp_strip_all_tags( $inner );
			$id   = sanitize_title( $text );
			if ( empty( $id ) ) {
				$id = 'heading-' . count( $used_ids );
			}

			$base = $id;
			$n    = 2;
			while ( in_array( $id, $used_ids, true ) ) {
				$id = $base . '-' . $n++;
			}
			$used_ids[] = $id;

			return '<h' . $level . $attrs . ' id="' . esc_attr( $id ) . '">' . $inner . '</h' . $close_level . '>';
		},
		$content
	);

	return $content;
}
add_filter( 'the_content', 'ekwa_toc_add_heading_ids', 8 );

// ═══════════════════════════════════════════════════════════════════════════════
// AUTHOR LINK — Redirect to the ekwa_author_page setting.
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Override the author archive link with the Ekwa author page setting.
 */
function ekwa_filter_author_link( $link ) {
	if ( is_admin() ) {
		return $link;
	}

	$author_page_id = absint( get_option( 'ekwa_author_page', 0 ) );
	if ( $author_page_id && 'publish' === get_post_status( $author_page_id ) ) {
		return get_permalink( $author_page_id );
	}

	return $link;
}
add_filter( 'author_link', 'ekwa_filter_author_link' );

// ═══════════════════════════════════════════════════════════════════════════════
// SHARED POST CARD — Used by templates and AJAX load-more.
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Render a compact post card used by the related-articles carousel.
 *
 * @param int $post_id Post ID.
 * @return string Card HTML.
 */
function ekwa_render_post_card( $post_id ) {
	$permalink = get_permalink( $post_id );
	$title     = get_the_title( $post_id );
	$excerpt   = get_the_excerpt( $post_id );
	$date      = get_the_date( 'M j, Y', $post_id );
	$thumb     = get_the_post_thumbnail( $post_id, 'medium_large', array(
		'loading' => 'lazy',
	) );

	$html  = '<article class="ekwa-post-card">';
	$html .= '<a href="' . esc_url( $permalink ) . '" class="ekwa-post-card__image">' . $thumb . '</a>';
	$html .= '<div class="ekwa-post-card__body">';
	$html .= '<h3 class="ekwa-post-card__title"><a href="' . esc_url( $permalink ) . '">' . esc_html( $title ) . '</a></h3>';
	$html .= '<p class="ekwa-post-card__excerpt">' . esc_html( wp_trim_words( $excerpt, 20, '&hellip;' ) ) . '</p>';
	$html .= '<span class="ekwa-post-card__date"><i class="fa-regular fa-calendar" aria-hidden="true"></i> ' . esc_html( $date ) . '</span>';
	$html .= '</div>';
	$html .= '</article>';

	return $html;
}

/**
 * Render a blog-grid card matching the post-template markup in index.html.
 * Wrapped in <li class="wp-block-post"> so it slots into the existing grid
 * when appended via the AJAX load-more flow.
 */
function ekwa_render_blog_grid_li( $post_id ) {
	$permalink = get_permalink( $post_id );
	$title     = get_the_title( $post_id );
	$excerpt   = get_the_excerpt( $post_id );
	$date      = get_the_date( 'M j, Y', $post_id );
	$thumb     = get_the_post_thumbnail( $post_id, 'medium_large', array( 'loading' => 'lazy' ) );

	$html  = '<li class="wp-block-post post type-post status-publish has-post-thumbnail">';
	$html .= '<div class="wp-block-group ekwa-blog-card">';
	$html .= '<div class="wp-block-group ekwa-blog-card__media">';
	$html .= '<figure class="wp-block-post-featured-image"><a href="' . esc_url( $permalink ) . '">' . $thumb . '</a></figure>';
	$html .= '<div class="wp-block-post-date ekwa-blog-card__date has-small-font-size"><time datetime="' . esc_attr( get_the_date( 'c', $post_id ) ) . '">' . esc_html( $date ) . '</time></div>';
	$html .= '</div>';
	$html .= '<div class="wp-block-group ekwa-blog-card__body">';
	$html .= '<h3 class="wp-block-post-title has-medium-font-size"><a href="' . esc_url( $permalink ) . '">' . esc_html( $title ) . '</a></h3>';
	$html .= '<div class="wp-block-post-excerpt has-small-font-size"><p class="wp-block-post-excerpt__excerpt">' . esc_html( wp_trim_words( $excerpt, 22, '&hellip;' ) ) . '</p></div>';
	$html .= '<a class="wp-block-read-more" href="' . esc_url( $permalink ) . '">Continue Reading</a>';
	$html .= '</div>';
	$html .= '</div>';
	$html .= '</li>';

	return $html;
}

// ═══════════════════════════════════════════════════════════════════════════════
// AJAX LOAD MORE
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Handle AJAX load-more requests.
 */
function ekwa_ajax_load_more() {
	check_ajax_referer( 'ekwa_load_more', 'nonce' );

	$page  = absint( $_POST['page'] ?? 2 );
	$query = json_decode( stripslashes( $_POST['query'] ?? '{}' ), true );

	if ( ! is_array( $query ) ) {
		wp_send_json_error( 'Invalid query.' );
	}

	// Whitelist allowed query keys.
	$allowed = array( 'post_type', 'posts_per_page', 'orderby', 'order', 'category_name', 'tag', 's', 'tax_query', 'author' );
	$args    = array_intersect_key( $query, array_flip( $allowed ) );
	$args['paged']  = $page;
	$args['post_status'] = 'publish';

	$q    = new WP_Query( $args );
	$html = '';

	if ( $q->have_posts() ) {
		while ( $q->have_posts() ) {
			$q->the_post();
			$html .= ekwa_render_blog_grid_li( get_the_ID() );
		}
		wp_reset_postdata();
	}

	wp_send_json_success( array(
		'html'    => $html,
		'hasMore' => $q->max_num_pages > $page,
	) );
}
add_action( 'wp_ajax_ekwa_load_more', 'ekwa_ajax_load_more' );
add_action( 'wp_ajax_nopriv_ekwa_load_more', 'ekwa_ajax_load_more' );

/**
 * Inject query data attributes into core/query blocks that contain ekwa/load-more.
 */
function ekwa_inject_query_data( $block_content, $block ) {
	if ( $block['blockName'] !== 'core/query' ) {
		return $block_content;
	}

	// Check if this query block contains a load-more block.
	$lm_attrs = ekwa_find_load_more_in_blocks( $block['innerBlocks'] ?? array() );

	if ( false === $lm_attrs ) {
		return $block_content;
	}

	$pagination_type = $lm_attrs['paginationType'] ?? 'load-more';

	// Extract query attributes.
	$query_attrs = $block['attrs']['query'] ?? array();
	$per_page    = $query_attrs['perPage'] ?? get_option( 'posts_per_page', 10 );

	// Build WP_Query args from block query.
	$args = array(
		'post_type'      => $query_attrs['postType'] ?? 'post',
		'posts_per_page' => $per_page,
		'orderby'        => $query_attrs['orderBy'] ?? 'date',
		'order'          => $query_attrs['order'] ?? 'desc',
	);

	// Inherit from the current query (archive, search, etc.).
	if ( ! empty( $query_attrs['inherit'] ) ) {
		global $wp_query;
		if ( $wp_query->is_category() ) {
			$args['category_name'] = get_queried_object()->slug;
		} elseif ( $wp_query->is_tag() ) {
			$args['tag'] = get_queried_object()->slug;
		} elseif ( $wp_query->is_search() ) {
			$args['s'] = get_search_query();
		} elseif ( $wp_query->is_author() ) {
			$args['author'] = get_queried_object_id();
		}
	}

	// Calculate max pages.
	$count_args = $args;
	$count_args['posts_per_page'] = -1;
	$count_args['fields'] = 'ids';
	$total      = count( get_posts( $count_args ) );
	$max_pages  = (int) ceil( $total / $per_page );

	// Current page (from /page/N/ or ?paged=N) and the URL pattern for page links.
	$paged       = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
	$big         = 999999999;
	$url_pattern = str_replace( (string) $big, '%d', get_pagenum_link( $big, false ) );
	$page1_url   = get_pagenum_link( 1, false );

	$nonce      = wp_create_nonce( 'ekwa_load_more' );
	$query_json = esc_attr( wp_json_encode( $args ) );

	$data_attrs = ' data-ekwa-query="' . $query_json . '"'
		. ' data-ekwa-max-pages="' . $max_pages . '"'
		. ' data-ekwa-nonce="' . $nonce . '"'
		. ' data-ekwa-current-page="' . $paged . '"'
		. ' data-ekwa-url-pattern="' . esc_attr( $url_pattern ) . '"'
		. ' data-ekwa-page1-url="' . esc_attr( $page1_url ) . '"';

	// Inject data attributes into the first div whose class contains wp-block-query.
	// WordPress may add layout classes (e.g. "wp-block-query is-layout-flow ..."),
	// so match wp-block-query as a class token rather than the exact attribute value.
	$block_content = preg_replace(
		'/(<div\b[^>]*\bclass="[^"]*\bwp-block-query\b[^"]*"[^>]*?)>/s',
		'$1' . $data_attrs . '>',
		$block_content,
		1
	);

	// Server-render numbered pagination links so pages are crawlable and work without JS.
	if ( 'numbered' === $pagination_type && $max_pages > 1 ) {
		$links = ekwa_numbered_pagination_html(
			$paged,
			$max_pages,
			$lm_attrs['prevText'] ?? __( 'Prev', 'ekwa' ),
			$lm_attrs['nextText'] ?? __( 'Next', 'ekwa' )
		);
		$block_content = preg_replace_callback(
			'/(<nav\b[^>]*\bekwa-load-more__pagination\b[^>]*>)\s*(<\/nav>)/',
			function ( $m ) use ( $links ) {
				return $m[1] . $links . $m[2];
			},
			$block_content,
			1
		);
	}

	return $block_content;
}
add_filter( 'render_block', 'ekwa_inject_query_data', 10, 2 );

/**
 * Find an ekwa/load-more block in a nested block tree. Returns its attrs or false.
 */
function ekwa_find_load_more_in_blocks( $blocks ) {
	foreach ( $blocks as $b ) {
		if ( ( $b['blockName'] ?? '' ) === 'ekwa/load-more' ) {
			return $b['attrs'] ?? array();
		}
		if ( ! empty( $b['innerBlocks'] ) ) {
			$found = ekwa_find_load_more_in_blocks( $b['innerBlocks'] );
			if ( false !== $found ) {
				return $found;
			}
		}
	}
	return false;
}

/**
 * Page range with ellipsis. Mirrors getPageRange() in ekwa-load-more.js.
 */
function ekwa_pagination_range( $current, $total ) {
	if ( $total <= 7 ) {
		return range( 1, $total );
	}
	$pages = array( 1 );
	$start = max( 2, $current - 2 );
	$end   = min( $total - 1, $current + 2 );
	if ( $start > 2 ) {
		$pages[] = '...';
	}
	for ( $i = $start; $i <= $end; $i++ ) {
		$pages[] = $i;
	}
	if ( $end < $total - 1 ) {
		$pages[] = '...';
	}
	$pages[] = $total;
	return $pages;
}

/**
 * Server-rendered numbered pagination links (same markup the JS builds).
 */
function ekwa_numbered_pagination_html( $current, $max, $prev_text, $next_text ) {
	$out = '';

	if ( $current > 1 ) {
		$out .= '<a class="ekwa-pagination__btn ekwa-pagination__prev" href="' . esc_url( get_pagenum_link( $current - 1, false ) ) . '">' . esc_html( $prev_text ) . '</a>';
	} else {
		$out .= '<span class="ekwa-pagination__btn ekwa-pagination__prev is-disabled" aria-disabled="true">' . esc_html( $prev_text ) . '</span>';
	}

	foreach ( ekwa_pagination_range( $current, $max ) as $p ) {
		if ( '...' === $p ) {
			$out .= '<span class="ekwa-pagination__ellipsis">&hellip;</span>';
		} elseif ( $p === $current ) {
			$out .= '<span class="ekwa-pagination__btn ekwa-pagination__page is-active" aria-current="page">' . $p . '</span>';
		} else {
			$out .= '<a class="ekwa-pagination__btn ekwa-pagination__page" href="' . esc_url( get_pagenum_link( $p, false ) ) . '" aria-label="' . esc_attr( sprintf( __( 'Page %d', 'ekwa' ), $p ) ) . '">' . $p . '</a>';
		}
	}

	if ( $current < $max ) {
		$out .= '<a class="ekwa-pagination__btn ekwa-pagination__next" href="' . esc_url( get_pagenum_link( $current + 1, false ) ) . '">' . esc_html( $next_text ) . '</a>';
	} else {
		$out .= '<span class="ekwa-pagination__btn ekwa-pagination__next is-disabled" aria-disabled="true">' . esc_html( $next_text ) . '</span>';
	}

	return $out;
}

/**
 * Track query blocks that contain a numbered load-more, so the paged URL
 * can be applied to their custom (non-inherited) queries.
 */
function ekwa_track_numbered_query_blocks( $parsed_block ) {
	if ( ( $parsed_block['blockName'] ?? '' ) === 'core/query' ) {
		$lm = ekwa_find_load_more_in_blocks( $parsed_block['innerBlocks'] ?? array() );
		if ( false !== $lm && 'numbered' === ( $lm['paginationType'] ?? '' ) ) {
			$GLOBALS['ekwa_numbered_query_ids'][] = (int) ( $parsed_block['attrs']['queryId'] ?? 0 );
		}
	}
	return $parsed_block;
}
add_filter( 'render_block_data', 'ekwa_track_numbered_query_blocks' );

/**
 * Make /blog/page/N/ render the right page server-side for tracked query blocks.
 * Core computes the offset from its own query-{id}-page URL param, so the paged
 * var has to be translated into an offset here.
 */
function ekwa_query_block_respect_paged( $query, $block ) {
	if ( ! empty( $block->context['query']['inherit'] ) ) {
		return $query;
	}

	$query_id = (int) ( $block->context['queryId'] ?? 0 );
	$tracked  = $GLOBALS['ekwa_numbered_query_ids'] ?? array();
	if ( ! in_array( $query_id, $tracked, true ) ) {
		return $query;
	}

	$paged = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
	if ( $paged > 1 ) {
		$per_page        = (int) ( $block->context['query']['perPage'] ?? get_option( 'posts_per_page' ) );
		$base_offset     = (int) ( $block->context['query']['offset'] ?? 0 );
		$query['offset'] = ( $per_page * ( $paged - 1 ) ) + $base_offset;
	}

	return $query;
}
add_filter( 'query_loop_block_query_vars', 'ekwa_query_block_respect_paged', 10, 2 );

// ═══════════════════════════════════════════════════════════════════════════════
// BLOG ASSETS — Conditional enqueue.
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Inline the blog stylesheet in <head> on blog page types.
 *
 * The bulk of ekwa-blog.css is template-level (single-post grid, archive cards,
 * sidebar widgets) and isn't tied to one block, so it inlines on the page types
 * that use those templates. The same file is also inlined on demand by blog
 * blocks used elsewhere (see inc/ekwa-inline-assets.php); the shared per-path
 * dedupe guarantees it prints at most once per request.
 */
function ekwa_blog_inline_assets() {
	// Single posts, archives, search, blog home — NOT pages or front page.
	if ( is_singular( 'post' ) || is_archive() || is_search() || is_home() ) {
		ekwa_inline_print_style( 'assets/css/ekwa-blog.css' );
	}
}
add_action( 'wp_head', 'ekwa_blog_inline_assets', 7 );
