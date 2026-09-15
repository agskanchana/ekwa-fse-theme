<?php
/**
 * Find in Site — one screen that answers "where is this word used?".
 *
 * WHY THIS EXISTS: WordPress' own admin search covers `post_title` and
 * `post_content`, for one post type at a time, and nothing else. It misses the
 * Yoast title/description in postmeta, the block markup in `templates/*.html`
 * and `parts/header.html`, the Site Editor's *database overrides* of those same
 * template files, nav menu item labels, and every `ekwa_*` option (locations,
 * schema template, banner text). So "where does 'Main Line Dental Health' still
 * appear?" today needs a grep of the theme folder AND hand-written SQL against
 * three tables — per site, per question.
 *
 * WHAT IT DOES: searches all of those at once and reports each hit with the
 * link that opens the right editor. A theme file and its Site Editor override
 * are reported separately and honestly, because once anyone edits the header in
 * the Site Editor the database row is what renders and the file is dead weight —
 * which is exactly the case a grep of the theme folder gets wrong.
 *
 * THE SEARCH WRITES NOTHING. Not a post, not an option, not a transient. It
 * runs on GET so a result page is linkable and a refresh just re-runs it.
 *
 * REPLACE is opt-in and two-step: tick rows, preview old → new, then confirm.
 * The apply step re-runs the whole scan server-side and only writes to rows the
 * fresh scan produced and marked writable, so a hand-forged POST naming a
 * row that isn't writable is rejected rather than merely hidden in the UI.
 *
 * PARENT-THEME FILES ARE NEVER WRITTEN. The theme auto-updates from GitHub, so
 * a write into `themes/ekwa/parts/*.html` is guaranteed to be reverted by the
 * next release. Those hits are reported with their path and a pointer to the
 * Site Editor (which saves a database override) or the child theme.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Max rows one scanner will return before the report is marked truncated. */
const EKWA_FIND_MAX_ROWS = 400;

/** Max context snippets kept per row (the hit count stays exact). */
const EKWA_FIND_MAX_SNIPPETS = 4;

/* ==================================================================
 * Shims over helpers that live in inc/ekwa-import-legacy.php and
 * inc/ekwa-js-editor.php.
 *
 * Guarded with function_exists() and given an inline fallback so the
 * include order in functions.php can never fatal this page, and so a
 * child theme that unhooks one of those files does not take this one
 * down with it.
 * ================================================================== */

/**
 * Post meta keys treated as SEO fields.
 *
 * @return string[]
 */
function ekwa_find_meta_keys() {
	$keys = function_exists( 'ekwa_legacy_seo_meta_keys' )
		? ekwa_legacy_seo_meta_keys()
		: array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc' );

	// Image alt text: a practice name baked into alt attributes is exactly the
	// kind of leftover this tool is for, and it lives nowhere else.
	$keys[] = '_wp_attachment_image_alt';

	// Yoast's focus keyword. Never rendered, but it is where an old practice
	// name survives longest, and leaving it out makes the report quietly
	// disagree with a hand-written query over wp_postmeta.
	$keys[] = '_yoast_wpseo_focuskw';

	/**
	 * Filter the post meta keys Find in Site searches.
	 *
	 * @param string[] $keys Meta keys.
	 */
	return array_values( array_unique( (array) apply_filters( 'ekwa_find_meta_keys', $keys ) ) );
}

/**
 * Post statuses the scan reads.
 *
 * @param bool $include_trash Whether to add 'trash'.
 * @return string[]
 */
function ekwa_find_post_statuses( $include_trash = false ) {
	$statuses = function_exists( 'ekwa_legacy_post_statuses' )
		? ekwa_legacy_post_statuses()
		: array( 'publish', 'future', 'draft', 'pending', 'private' );

	// Attachments are 'inherit'; template parts and menu items are 'publish'.
	$statuses[] = 'inherit';

	if ( $include_trash ) {
		$statuses[] = 'trash';
	}

	return array_values( array_unique( $statuses ) );
}

/**
 * A post type's singular label, for display.
 *
 * @param string $type Post type name.
 * @return string
 */
function ekwa_find_type_label( $type ) {
	if ( function_exists( 'ekwa_legacy_post_type_label' ) ) {
		return ekwa_legacy_post_type_label( $type );
	}
	$object = get_post_type_object( $type );
	return ( $object && ! empty( $object->labels->singular_name ) ) ? $object->labels->singular_name : $type;
}

/**
 * Whether writing theme files from the admin is permitted at all.
 *
 * @return bool
 */
function ekwa_find_file_mods_allowed() {
	if ( function_exists( 'ekwa_js_editor_file_mods_allowed' ) ) {
		return ekwa_js_editor_file_mods_allowed();
	}
	if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
		return false;
	}
	if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
		return false;
	}
	return true;
}

/* ==================================================================
 * Matching.
 * ================================================================== */

/**
 * Escape for HTML *without* collapsing entities that are already in the value.
 *
 * esc_attr() and esc_html() both call _wp_specialchars() with
 * $double_encode = false, which is right for ordinary content and catastrophic
 * here. A search for the literal text "Main Line Dental Health &amp; Wellness"
 * — which is how WordPress actually stores "… & …" in post_title — written
 * through esc_attr() into a hidden input reaches the browser as
 *
 *     value="Main Line Dental Health &amp; Wellness"
 *
 * and the BROWSER decodes the entity on submit, so the next request searches
 * for "… & …" instead. The replace preview then re-runs the scan, matches
 * nothing, and reports "Nothing selected that can be written to" even though
 * rows were ticked.
 *
 * Double-encoding makes the value that comes back byte-for-byte the value that
 * went out, and makes "&" and "&amp;" visibly different on screen so the two
 * searches can be told apart.
 *
 * @param string $value Raw value.
 * @return string
 */
function ekwa_find_esc( $value ) {
	return _wp_specialchars( (string) $value, ENT_QUOTES, false, true );
}

/**
 * Normalize the search arguments out of a request array.
 *
 * @param array $src $_GET or $_POST.
 * @return array{term:string,mode:string,word:bool,trash:bool}
 */
function ekwa_find_args( $src ) {
	$term = isset( $src['ekwa_find_term'] ) ? wp_unslash( $src['ekwa_find_term'] ) : '';
	$term = is_string( $term ) ? trim( $term ) : '';
	// No sanitize_text_field(): the term may legitimately contain markup or
	// bracket characters ("[ekwa_phone", "<span class=…"), and stripping them
	// would silently search for something other than what was typed. It is
	// escaped at every output point and passed to SQL only through
	// $wpdb->prepare(), so keeping it verbatim is safe and correct.
	$term = wp_check_invalid_utf8( $term, true );

	return array(
		'term'  => $term,
		'mode'  => ( isset( $src['ekwa_find_mode'] ) && 'shortcode' === $src['ekwa_find_mode'] ) ? 'shortcode' : 'text',
		'word'  => ! empty( $src['ekwa_find_word'] ),
		'trash' => ! empty( $src['ekwa_find_trash'] ),
	);
}

/**
 * Every match of the term in one string.
 *
 * Returns byte offsets, because the snippet builder slices with substr() and
 * the replace step rebuilds the string with substr_replace() — both byte-based.
 *
 * Shortcode mode deliberately returns the offset of the TAG NAME only, not the
 * opening bracket: that makes a replace rename the shortcode rather than eat
 * the delimiter and corrupt every page it appears on.
 *
 * @param string $text Haystack.
 * @param array  $args Search args from ekwa_find_args().
 * @return array<int,array{offset:int,length:int}>
 */
function ekwa_find_matches( $text, $args ) {
	$text = (string) $text;
	$term = (string) $args['term'];

	if ( '' === $term || '' === $text ) {
		return array();
	}

	$pattern = '';
	if ( 'shortcode' === $args['mode'] ) {
		// [tag] [tag attr=…] [tag/] [/tag] — group 1 is the bare tag name.
		$pattern = '/\[\/?(' . preg_quote( $term, '/' ) . ')(?=[\s\]\/])/iu';
	} elseif ( $args['word'] ) {
		// Lookarounds rather than \b: \b is only meaningful when the term
		// starts and ends with a word character, which a phrase like
		// "[ekwa_phone" or "Dr." does not.
		$pattern = '/(?<![A-Za-z0-9_])(' . preg_quote( $term, '/' ) . ')(?![A-Za-z0-9_])/iu';
	}

	if ( '' !== $pattern ) {
		$found = preg_match_all( $pattern, $text, $m, PREG_OFFSET_CAPTURE );
		if ( false !== $found ) {
			$out = array();
			foreach ( $m[1] as $hit ) {
				$out[] = array( 'offset' => (int) $hit[1], 'length' => strlen( (string) $hit[0] ) );
			}
			return $out;
		}
		// preg_* with /u returns false on invalid UTF-8. A post that survived a
		// bad import is precisely the sort of thing someone is searching for, so
		// fall through to the byte scan rather than reporting it as clean.
	}

	$out    = array();
	$len    = strlen( $term );
	$offset = 0;
	while ( true ) {
		$pos = stripos( $text, $term, $offset );
		if ( false === $pos ) {
			break;
		}
		$out[]  = array( 'offset' => $pos, 'length' => $len );
		$offset = $pos + $len;
	}

	return $out;
}

/**
 * A readable snippet of the text around one match.
 *
 * ekwa_legacy_context_snippet() is not reused here: it strips HTML comments,
 * and in a block theme the match is very often *inside* one (a block delimiter,
 * or a `scopedCss` attribute). Stripping would return an empty snippet for the
 * hits that matter most. So the cleaned form is tried first and kept only when
 * the match survived it; otherwise the raw window is shown, markup and all,
 * which is genuinely the useful view for a template part.
 *
 * @param string $text   Field the match was found in.
 * @param int    $offset Byte offset of the match.
 * @param int    $length Byte length of the match.
 * @param int    $pad    Characters of context on each side.
 * @return string
 */
function ekwa_find_snippet( $text, $offset, $length, $pad = 70 ) {
	$text  = (string) $text;
	$start = max( 0, (int) $offset - $pad );
	$raw   = substr( $text, $start, ( (int) $offset - $start ) + (int) $length + $pad );
	$match = substr( $text, (int) $offset, (int) $length );

	$clean = preg_replace( '/<!--.*?-->/s', ' ', $raw );
	$clean = wp_strip_all_tags( (string) $clean );
	$clean = preg_replace( '/\s+/u', ' ', (string) $clean );
	$clean = trim( (string) wp_check_invalid_utf8( (string) $clean, true ) );

	if ( '' !== $clean && ( '' === $match || false !== stripos( $clean, $match ) ) ) {
		return $clean;
	}

	$raw = preg_replace( '/\s+/u', ' ', $raw );

	// Slicing on a byte offset can cut a multibyte character in half, and an
	// invalid UTF-8 sequence renders as nothing at all.
	return trim( (string) wp_check_invalid_utf8( (string) $raw, true ) );
}

/**
 * 1-based line number of a byte offset.
 *
 * @param string $text   Haystack.
 * @param int    $offset Byte offset.
 * @return int
 */
function ekwa_find_line_at( $text, $offset ) {
	return substr_count( substr( (string) $text, 0, (int) $offset ), "\n" ) + 1;
}

/**
 * Escape a snippet for output with the matched text wrapped in <mark>.
 *
 * @param string $snippet Raw snippet.
 * @param string $match   The matched substring, as it appeared.
 * @return string Safe HTML.
 */
function ekwa_find_highlight( $snippet, $match ) {
	$snippet = esc_html( (string) $snippet );
	$match   = esc_html( (string) $match );

	if ( '' === $match ) {
		return $snippet;
	}

	$marked = preg_replace( '/' . preg_quote( $match, '/' ) . '/iu', '<mark>$0</mark>', $snippet );

	return ( null === $marked ) ? $snippet : $marked;
}

/**
 * Apply the replacement to every match in a string.
 *
 * Walks backwards so each splice leaves the earlier offsets valid.
 *
 * @param string $text        Original.
 * @param array  $args        Search args.
 * @param string $replacement Replacement text.
 * @return array{text:string,count:int}
 */
function ekwa_find_replace_in_text( $text, $args, $replacement ) {
	$matches = ekwa_find_matches( $text, $args );
	if ( ! $matches ) {
		return array( 'text' => (string) $text, 'count' => 0 );
	}

	$out = (string) $text;
	foreach ( array_reverse( $matches ) as $hit ) {
		$out = substr_replace( $out, (string) $replacement, $hit['offset'], $hit['length'] );
	}

	return array( 'text' => $out, 'count' => count( $matches ) );
}

/* ==================================================================
 * Result rows.
 * ================================================================== */

/**
 * Build one report row, or null when the field holds no match.
 *
 * @param array $row {
 *     @type string $group    Display group.
 *     @type string $label    Human name of the thing (page title, option name…).
 *     @type string $where    Field name.
 *     @type string $text     The text that was searched.
 *     @type array  $args     Search args.
 *     @type string $edit_url Optional.
 *     @type string $view_url Optional.
 *     @type bool   $writable Optional, default false.
 *     @type array  $ref      Replace target descriptor.
 *     @type string $note     Optional explanatory note.
 *     @type bool   $lines    Optional: report line numbers (files).
 * }
 * @return array|null
 */
function ekwa_find_build_row( $row ) {
	$matches = ekwa_find_matches( $row['text'], $row['args'] );
	if ( ! $matches ) {
		return null;
	}

	$snippets = array();
	foreach ( array_slice( $matches, 0, EKWA_FIND_MAX_SNIPPETS ) as $hit ) {
		$snippets[] = array(
			'text'  => ekwa_find_snippet( $row['text'], $hit['offset'], $hit['length'] ),
			'match' => substr( (string) $row['text'], $hit['offset'], $hit['length'] ),
			'line'  => empty( $row['lines'] ) ? 0 : ekwa_find_line_at( $row['text'], $hit['offset'] ),
		);
	}

	return array(
		'group'    => $row['group'],
		'label'    => (string) $row['label'],
		'where'    => (string) $row['where'],
		'count'    => count( $matches ),
		'snippets' => $snippets,
		'edit_url' => isset( $row['edit_url'] ) ? (string) $row['edit_url'] : '',
		'view_url' => isset( $row['view_url'] ) ? (string) $row['view_url'] : '',
		'writable' => ! empty( $row['writable'] ),
		'note'     => isset( $row['note'] ) ? (string) $row['note'] : '',
		'ref'      => $row['ref'],
		'key'      => ekwa_find_row_key( $row['ref'] ),
	);
}

/**
 * A stable identifier for one row.
 *
 * The apply step re-runs the scan and matches submitted keys against freshly
 * built rows, so this is the only thing that crosses the request boundary — the
 * replace target itself is always re-derived server-side.
 *
 * @param array $ref Reference descriptor.
 * @return string
 */
function ekwa_find_row_key( $ref ) {
	switch ( $ref['type'] ) {
		case 'post':
			return 'post|' . (int) $ref['id'] . '|' . $ref['field'];
		case 'meta':
			return 'meta|' . (int) $ref['id'] . '|' . $ref['key'];
		case 'option':
			$path = isset( $ref['path'] ) ? (array) $ref['path'] : array();
			return 'option|' . $ref['key'] . '|' . implode( '/', array_map( 'rawurlencode', $path ) );
		case 'term':
			return 'term|' . (int) $ref['id'] . '|' . $ref['taxonomy'] . '|' . $ref['field'];
		case 'file':
			return 'file|' . $ref['which'] . '|' . $ref['rel'];
	}
	return 'unknown';
}

/* ==================================================================
 * Scanners.
 * ================================================================== */

/**
 * Post types the content scan covers.
 *
 * Deliberately not ekwa_legacy_post_types(): that filters to public => true,
 * which would skip the theme's own `ekwa_shortcode` post type (a fragment
 * embedded on many pages — exactly what someone tracing a phrase needs to
 * find), along with templates, synced patterns and menu items.
 *
 * @return string[]
 */
function ekwa_find_post_types() {
	$types = get_post_types( array( 'show_ui' => true ), 'names' );

	foreach ( array( 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation', 'nav_menu_item', 'attachment' ) as $extra ) {
		if ( post_type_exists( $extra ) ) {
			$types[ $extra ] = $extra;
		}
	}

	unset( $types['revision'] );

	/**
	 * Filter the post types Find in Site searches.
	 *
	 * @param string[] $types Post type names.
	 */
	return array_values( (array) apply_filters( 'ekwa_find_post_types', array_values( $types ) ) );
}

/**
 * Which display group a post type belongs in.
 *
 * @param string $type Post type.
 * @return string
 */
function ekwa_find_group_for_type( $type ) {
	if ( 'wp_template' === $type || 'wp_template_part' === $type ) {
		return 'template';
	}
	if ( 'nav_menu_item' === $type || 'wp_navigation' === $type ) {
		return 'menu';
	}
	if ( 'attachment' === $type ) {
		return 'media';
	}
	return 'content';
}

/**
 * Admin edit link for one post, by type.
 *
 * @param object $post Row with ID, post_type, post_name.
 * @return string
 */
function ekwa_find_post_edit_url( $post ) {
	$id   = (int) $post->ID;
	$type = (string) $post->post_type;

	if ( 'wp_template' === $type || 'wp_template_part' === $type ) {
		// The Site Editor addresses templates as "{theme}//{slug}". The theme is
		// the part's own wp_theme term, NOT get_stylesheet(): a site can carry an
		// override saved against the parent theme and another against the child,
		// both with post_name "footer", and hardcoding the active theme would
		// point both rows at the same URL — sending you to edit the wrong one.
		$theme = get_stylesheet();
		$terms = get_the_terms( $id, 'wp_theme' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$theme = $terms[0]->name;
		}

		return admin_url(
			'site-editor.php?postType=' . rawurlencode( $type )
			. '&postId=' . rawurlencode( $theme . '//' . (string) $post->post_name )
			. '&canvas=edit'
		);
	}

	if ( 'wp_navigation' === $type ) {
		return admin_url( 'site-editor.php?postType=wp_navigation&postId=' . $id . '&canvas=edit' );
	}

	if ( 'nav_menu_item' === $type ) {
		$menus = wp_get_object_terms( $id, 'nav_menu', array( 'fields' => 'ids' ) );
		if ( $menus && ! is_wp_error( $menus ) ) {
			return admin_url( 'nav-menus.php?menu=' . (int) $menus[0] );
		}
		return admin_url( 'nav-menus.php' );
	}

	$link = get_edit_post_link( $id, 'raw' );

	return $link ? $link : '';
}

/**
 * Front-end link for one post, when it has one.
 *
 * @param object $post Row with ID, post_type, post_status.
 * @return string
 */
function ekwa_find_post_view_url( $post ) {
	if ( ! is_post_type_viewable( (string) $post->post_type ) ) {
		return '';
	}
	if ( in_array( (string) $post->post_status, array( 'draft', 'pending', 'auto-draft', 'trash' ), true ) ) {
		return '';
	}
	$link = get_permalink( (int) $post->ID );

	return $link ? $link : '';
}

/**
 * Scan post_title / post_content / post_excerpt.
 *
 * @param array $args Search args.
 * @param bool  $truncated By reference.
 * @return array Rows.
 */
function ekwa_find_scan_posts( $args, &$truncated ) {
	global $wpdb;

	$types    = ekwa_find_post_types();
	$statuses = ekwa_find_post_statuses( $args['trash'] );

	if ( ! $types ) {
		return array();
	}

	$like      = '%' . $wpdb->esc_like( $args['term'] ) . '%';
	$type_in   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$status_in = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_title, post_name, post_type, post_status, post_content, post_excerpt
			 FROM {$wpdb->posts}
			 WHERE post_type IN ({$type_in})
			   AND post_status IN ({$status_in})
			   AND ( post_title LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s )
			 ORDER BY post_type ASC, post_title ASC
			 LIMIT %d",
			array_merge( $types, $statuses, array( $like, $like, $like, EKWA_FIND_MAX_ROWS + 1 ) )
		)
	);
	// phpcs:enable

	if ( count( (array) $rows ) > EKWA_FIND_MAX_ROWS ) {
		$truncated = true;
		$rows      = array_slice( (array) $rows, 0, EKWA_FIND_MAX_ROWS );
	}

	$out = array();

	foreach ( (array) $rows as $row ) {
		$group    = ekwa_find_group_for_type( $row->post_type );
		$title    = '' !== trim( (string) $row->post_title ) ? $row->post_title : sprintf( '(no title) #%d', (int) $row->ID );
		$edit_url = ekwa_find_post_edit_url( $row );
		$view_url = ekwa_find_post_view_url( $row );

		$note = '';
		if ( 'trash' === $row->post_status ) {
			$note = __( 'In the trash.', 'ekwa' );
		} elseif ( 'wp_template_part' === $row->post_type || 'wp_template' === $row->post_type ) {
			// Two overrides can share a title ("Footer") and differ only by slug
			// and owning theme, so both go in the label — otherwise the report
			// shows two identical-looking rows and no way to tell them apart.
			$theme = get_the_terms( (int) $row->ID, 'wp_theme' );
			$theme = ( $theme && ! is_wp_error( $theme ) ) ? $theme[0]->name : get_stylesheet();
			$title = sprintf( '%s (%s — %s)', $title, (string) $row->post_name, $theme );
			$note  = __( 'Site Editor override saved in the database — this is what renders, not the theme file of the same name.', 'ekwa' );
		}

		$fields = array(
			'post_title'   => array( __( 'Title', 'ekwa' ), (string) $row->post_title ),
			'post_content' => array( __( 'Content', 'ekwa' ), (string) $row->post_content ),
			'post_excerpt' => array( __( 'Excerpt', 'ekwa' ), (string) $row->post_excerpt ),
		);

		// nav_menu_item keeps its label in post_title; "Content" is meaningless there.
		if ( 'nav_menu_item' === $row->post_type ) {
			$fields['post_title'][0] = __( 'Menu label', 'ekwa' );
		}

		foreach ( $fields as $field => $meta ) {
			$built = ekwa_find_build_row(
				array(
					'group'    => $group,
					'label'    => $title . ' — ' . ekwa_find_type_label( $row->post_type ),
					'where'    => $meta[0],
					'text'     => $meta[1],
					'args'     => $args,
					'edit_url' => $edit_url,
					'view_url' => $view_url,
					'writable' => true,
					'note'     => $note,
					'ref'      => array( 'type' => 'post', 'id' => (int) $row->ID, 'field' => $field ),
				)
			);
			if ( $built ) {
				$out[] = $built;
			}
		}
	}

	return $out;
}

/**
 * Friendly name for a searched meta key.
 *
 * @param string $key Meta key.
 * @return string
 */
function ekwa_find_meta_label( $key ) {
	$map = array(
		'_yoast_wpseo_title'                 => __( 'SEO title (Yoast)', 'ekwa' ),
		'_yoast_wpseo_metadesc'              => __( 'Meta description (Yoast)', 'ekwa' ),
		'_yoast_wpseo_opengraph-title'       => __( 'OpenGraph title (Yoast)', 'ekwa' ),
		'_yoast_wpseo_opengraph-description' => __( 'OpenGraph description (Yoast)', 'ekwa' ),
		'_yoast_wpseo_twitter-title'         => __( 'Twitter title (Yoast)', 'ekwa' ),
		'_yoast_wpseo_twitter-description'   => __( 'Twitter description (Yoast)', 'ekwa' ),
		'rank_math_title'                    => __( 'SEO title (Rank Math)', 'ekwa' ),
		'rank_math_description'              => __( 'Meta description (Rank Math)', 'ekwa' ),
		'_aioseo_title'                      => __( 'SEO title (AIOSEO)', 'ekwa' ),
		'_aioseo_description'                => __( 'Meta description (AIOSEO)', 'ekwa' ),
		'_wp_attachment_image_alt'           => __( 'Image alt text', 'ekwa' ),
		'_yoast_wpseo_focuskw'               => __( 'Focus keyword (Yoast)', 'ekwa' ),
	);

	return isset( $map[ $key ] ) ? $map[ $key ] : $key;
}

/**
 * Scan the SEO / alt-text post meta.
 *
 * @param array $args Search args.
 * @param bool  $truncated By reference.
 * @return array Rows.
 */
function ekwa_find_scan_meta( $args, &$truncated ) {
	global $wpdb;

	$keys = ekwa_find_meta_keys();
	if ( ! $keys ) {
		return array();
	}

	$like   = '%' . $wpdb->esc_like( $args['term'] ) . '%';
	$key_in = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			// p.ID is selected alongside m.post_id because ekwa_find_post_edit_url()
			// and ekwa_find_post_view_url() both read ->ID, the shape the posts
			// scanner hands them.
			"SELECT p.ID, m.post_id, m.meta_key, m.meta_value, p.post_title, p.post_type, p.post_status, p.post_name
			 FROM {$wpdb->postmeta} m
			 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			 WHERE m.meta_key IN ({$key_in})
			   AND m.meta_value LIKE %s
			 ORDER BY p.post_title ASC
			 LIMIT %d",
			array_merge( $keys, array( $like, EKWA_FIND_MAX_ROWS + 1 ) )
		)
	);
	// phpcs:enable

	if ( count( (array) $rows ) > EKWA_FIND_MAX_ROWS ) {
		$truncated = true;
		$rows      = array_slice( (array) $rows, 0, EKWA_FIND_MAX_ROWS );
	}

	$out = array();

	foreach ( (array) $rows as $row ) {
		if ( ! is_string( $row->meta_value ) ) {
			continue;
		}

		$title = '' !== trim( (string) $row->post_title ) ? $row->post_title : sprintf( '(no title) #%d', (int) $row->post_id );

		$built = ekwa_find_build_row(
			array(
				'group'    => '_wp_attachment_image_alt' === $row->meta_key ? 'media' : 'seo',
				'label'    => $title . ' — ' . ekwa_find_type_label( $row->post_type ),
				'where'    => ekwa_find_meta_label( $row->meta_key ),
				'text'     => $row->meta_value,
				'args'     => $args,
				'edit_url' => ekwa_find_post_edit_url( $row ),
				'view_url' => ekwa_find_post_view_url( $row ),
				'writable' => true,
				'ref'      => array( 'type' => 'meta', 'id' => (int) $row->post_id, 'key' => (string) $row->meta_key ),
			)
		);

		if ( $built ) {
			$out[] = $built;
		}
	}

	return $out;
}

/**
 * Option name prefixes the scan reports on.
 *
 * An unrestricted options scan is useless noise — every plugin's cache, log and
 * changeset matches sooner or later. These are the namespaces that hold text
 * someone can actually see on the front end.
 *
 * @return string[]
 */
function ekwa_find_option_prefixes() {
	$prefixes = array( 'ekwa_', 'wpseo', 'theme_mods_' );

	/**
	 * Filter the option name prefixes Find in Site reports on.
	 *
	 * @param string[] $prefixes Option name prefixes.
	 */
	return array_values( (array) apply_filters( 'ekwa_find_option_prefixes', $prefixes ) );
}

/**
 * Exact option names always included.
 *
 * @return string[]
 */
function ekwa_find_option_names() {
	/** Filter the exact option names Find in Site reports on. */
	return array_values( (array) apply_filters( 'ekwa_find_option_names', array( 'blogname', 'blogdescription' ) ) );
}

/**
 * Collect every string inside a (possibly serialized, possibly nested) value
 * that contains the term, with the key path that reaches it.
 *
 * Reporting `ekwa_locations[0][name]` rather than "somewhere in ekwa_locations"
 * is the difference between a usable report and a second search by hand.
 *
 * @param mixed $value  Value to walk.
 * @param array $args   Search args.
 * @param array $path   Key path so far.
 * @param array $hits   By reference: collected array{path:array,value:string}.
 * @param int   $depth  Recursion guard.
 */
function ekwa_find_walk_value( $value, $args, $path, &$hits, $depth = 0 ) {
	if ( $depth > 8 || count( $hits ) >= EKWA_FIND_MAX_SNIPPETS * 10 ) {
		return;
	}

	if ( is_string( $value ) ) {
		if ( ekwa_find_matches( $value, $args ) ) {
			$hits[] = array( 'path' => $path, 'value' => $value );
		}
		return;
	}

	if ( is_object( $value ) ) {
		$value = get_object_vars( $value );
	}

	if ( is_array( $value ) ) {
		foreach ( $value as $key => $child ) {
			ekwa_find_walk_value( $child, $args, array_merge( $path, array( (string) $key ) ), $hits, $depth + 1 );
		}
	}
}

/**
 * Format a key path for display: ekwa_locations[0][name].
 *
 * @param string $option Option name.
 * @param array  $path   Key path.
 * @return string
 */
function ekwa_find_path_label( $option, $path ) {
	$label = $option;
	foreach ( (array) $path as $key ) {
		$label .= '[' . $key . ']';
	}
	return $label;
}

/**
 * Where an option is edited, as a link.
 *
 * @param string $name Option name.
 * @return string
 */
function ekwa_find_option_edit_url( $name ) {
	if ( 0 === strpos( $name, 'theme_mods_' ) ) {
		return admin_url( 'customize.php' );
	}
	if ( 0 === strpos( $name, 'wpseo' ) ) {
		return defined( 'WPSEO_VERSION' ) ? admin_url( 'admin.php?page=wpseo_page_settings' ) : '';
	}
	if ( in_array( $name, array( 'blogname', 'blogdescription' ), true ) ) {
		return admin_url( 'options-general.php' );
	}
	if ( 0 === strpos( $name, 'ekwa_' ) ) {
		return admin_url( 'themes.php?page=ekwa-settings' );
	}
	return '';
}

/**
 * Scan the options table.
 *
 * @param array $args Search args.
 * @param bool  $truncated By reference.
 * @return array Rows.
 */
function ekwa_find_scan_options( $args, &$truncated ) {
	global $wpdb;

	$like = '%' . $wpdb->esc_like( $args['term'] ) . '%';

	// Transients are excluded in SQL rather than in PHP: on a busy site they are
	// the bulk of the table and the biggest values in it, and dragging every
	// cached object through unserialize() to then discard it is the one thing
	// that would make this scan slow.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_name, option_value
			 FROM {$wpdb->options}
			 WHERE option_value LIKE %s
			   AND option_name NOT LIKE %s
			   AND option_name NOT LIKE %s
			 ORDER BY option_name ASC",
			$like,
			$wpdb->esc_like( '_transient' ) . '%',
			$wpdb->esc_like( '_site_transient' ) . '%'
		)
	);

	$prefixes = ekwa_find_option_prefixes();
	$names    = ekwa_find_option_names();
	$out      = array();

	foreach ( (array) $rows as $row ) {
		$name = (string) $row->option_name;

		$wanted = in_array( $name, $names, true );
		if ( ! $wanted ) {
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( $name, $prefix ) ) {
					$wanted = true;
					break;
				}
			}
		}
		if ( ! $wanted ) {
			continue;
		}

		$hits = array();
		ekwa_find_walk_value( maybe_unserialize( $row->option_value ), $args, array(), $hits );

		foreach ( $hits as $hit ) {
			if ( count( $out ) >= EKWA_FIND_MAX_ROWS ) {
				$truncated = true;
				break 2;
			}

			$built = ekwa_find_build_row(
				array(
					'group'    => 'option',
					'label'    => $name,
					'where'    => ekwa_find_path_label( $name, $hit['path'] ),
					'text'     => $hit['value'],
					'args'     => $args,
					'edit_url' => ekwa_find_option_edit_url( $name ),
					'writable' => true,
					'ref'      => array( 'type' => 'option', 'key' => $name, 'path' => $hit['path'] ),
				)
			);

			if ( $built ) {
				$out[] = $built;
			}
		}
	}

	return $out;
}

/**
 * Scan term names and descriptions.
 *
 * @param array $args Search args.
 * @param bool  $truncated By reference.
 * @return array Rows.
 */
function ekwa_find_scan_terms( $args, &$truncated ) {
	global $wpdb;

	$like = '%' . $wpdb->esc_like( $args['term'] ) . '%';

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT t.term_id, t.name, tt.taxonomy, tt.description
			 FROM {$wpdb->terms} t
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			 WHERE t.name LIKE %s OR tt.description LIKE %s
			 ORDER BY t.name ASC
			 LIMIT %d",
			$like,
			$like,
			EKWA_FIND_MAX_ROWS + 1
		)
	);

	if ( count( (array) $rows ) > EKWA_FIND_MAX_ROWS ) {
		$truncated = true;
		$rows      = array_slice( (array) $rows, 0, EKWA_FIND_MAX_ROWS );
	}

	$out = array();

	foreach ( (array) $rows as $row ) {
		// nav_menu terms are menu containers, already covered by the menu group.
		$taxonomy = (string) $row->taxonomy;
		$edit     = get_edit_term_link( (int) $row->term_id, $taxonomy );
		$tax_obj  = get_taxonomy( $taxonomy );
		$tax_name = ( $tax_obj && ! empty( $tax_obj->labels->singular_name ) ) ? $tax_obj->labels->singular_name : $taxonomy;

		foreach ( array( 'name' => __( 'Name', 'ekwa' ), 'description' => __( 'Description', 'ekwa' ) ) as $field => $label ) {
			$built = ekwa_find_build_row(
				array(
					'group'    => 'term',
					'label'    => $row->name . ' — ' . $tax_name,
					'where'    => $label,
					'text'     => (string) $row->{$field},
					'args'     => $args,
					'edit_url' => ( $edit && ! is_wp_error( $edit ) ) ? $edit : '',
					'writable' => true,
					'ref'      => array( 'type' => 'term', 'id' => (int) $row->term_id, 'taxonomy' => $taxonomy, 'field' => $field ),
				)
			);
			if ( $built ) {
				$out[] = $built;
			}
		}
	}

	return $out;
}

/**
 * The theme files the scan reads, as which => list of relative paths.
 *
 * The parent theme contributes only its content-bearing files (templates, parts,
 * patterns). Its assets/ and inc/ are theme SOURCE — a search for "phone" or
 * "search" would bury the real results under the theme's own implementation.
 *
 * The child theme additionally contributes its CSS and JS, because that is the
 * site's own code and a hardcoded name genuinely lives there.
 *
 * @return array<string,array{dir:string,files:string[]}>
 */
function ekwa_find_file_sets() {
	$stylesheet = get_stylesheet_directory();
	$template   = get_template_directory();
	$has_child  = ( get_stylesheet() !== get_template() );

	$content_globs = array( 'templates/*.html', 'parts/*.html', 'patterns/*.php' );
	$child_globs   = array_merge( $content_globs, array( 'assets/css/*.css', 'assets/js/*.js', 'style.css', 'theme.json' ) );

	$sets = array();

	$sets['stylesheet'] = array(
		'dir'   => $stylesheet,
		'globs' => $has_child ? $child_globs : $content_globs,
	);

	if ( $has_child ) {
		$sets['template'] = array(
			'dir'   => $template,
			'globs' => $content_globs,
		);
	}

	return $sets;
}

/**
 * Site Editor link for a template FILE, when it maps to one.
 *
 * WordPress exposes a theme-file template under the same "{theme}//{slug}" id it
 * uses for a database override, so the link opens the right thing either way —
 * and saving there creates the override rather than touching the file.
 *
 * @param string $rel   Relative path inside the theme.
 * @param string $which 'stylesheet' or 'template'.
 * @return string
 */
function ekwa_find_file_editor_url( $rel, $which = 'stylesheet' ) {
	// The theme already ships an editor for its two child JS files, on the
	// Design Setup tab — point at it rather than reporting "no way to edit this".
	if ( 'stylesheet' === $which && function_exists( 'ekwa_js_editor_files' ) ) {
		foreach ( ekwa_js_editor_files() as $file ) {
			if ( isset( $file['rel'] ) && $file['rel'] === $rel ) {
				return admin_url( 'themes.php?page=ekwa-settings&ekwa_tab=tokens' );
			}
		}
	}

	if ( 0 === strpos( $rel, 'templates/' ) && '.html' === substr( $rel, -5 ) ) {
		$type = 'wp_template';
	} elseif ( 0 === strpos( $rel, 'parts/' ) && '.html' === substr( $rel, -5 ) ) {
		$type = 'wp_template_part';
	} else {
		return '';
	}

	$slug = basename( $rel, '.html' );

	return admin_url(
		'site-editor.php?postType=' . $type
		. '&postId=' . rawurlencode( get_stylesheet() . '//' . $slug )
		. '&canvas=edit'
	);
}

/**
 * Scan theme files.
 *
 * @param array $args Search args.
 * @param bool  $truncated By reference.
 * @return array Rows.
 */
function ekwa_find_scan_files( $args, &$truncated ) {
	$sets       = ekwa_find_file_sets();
	$mods_ok    = ekwa_find_file_mods_allowed();
	$out        = array();
	$seen_rel   = array();
	$child_name = wp_get_theme( get_stylesheet() )->get( 'Name' );

	foreach ( $sets as $which => $set ) {
		foreach ( $set['globs'] as $glob ) {
			$paths = glob( $set['dir'] . '/' . $glob );
			if ( ! $paths ) {
				continue;
			}

			foreach ( $paths as $path ) {
				if ( count( $out ) >= EKWA_FIND_MAX_ROWS ) {
					$truncated = true;
					break 3;
				}

				$rel = ltrim( str_replace( $set['dir'], '', $path ), '/\\' );
				$rel = str_replace( '\\', '/', $rel );

				// A file the child overrides is reported once, as the child's —
				// that is the copy that renders, and the parent's is shadowed.
				if ( 'template' === $which && isset( $seen_rel[ $rel ] ) ) {
					continue;
				}
				$seen_rel[ $rel ] = true;

				if ( ! is_readable( $path ) ) {
					continue;
				}

				$content = (string) file_get_contents( $path );
				if ( '' === $content ) {
					continue;
				}

				$is_child = ( 'stylesheet' === $which && get_stylesheet() !== get_template() );

				if ( 'template' === $which ) {
					$note     = __( 'Parent theme file — the theme auto-updates from GitHub, so an edit here is reverted by the next release. Open it in the Site Editor (which saves a database override) or copy it into the child theme.', 'ekwa' );
					$writable = false;
				} elseif ( ! $is_child ) {
					// No child theme active: the "stylesheet" IS the parent.
					$note     = __( 'Parent theme file — the theme auto-updates from GitHub, so an edit here is reverted by the next release. Open it in the Site Editor (which saves a database override) or create a child theme.', 'ekwa' );
					$writable = false;
				} else {
					$note     = '';
					$writable = $mods_ok && wp_is_writable( $path );
					if ( ! $writable ) {
						$note = $mods_ok
							? __( 'File is not writable by PHP.', 'ekwa' )
							: __( 'File editing is disabled on this site (DISALLOW_FILE_MODS / DISALLOW_FILE_EDIT).', 'ekwa' );
					}
				}

				$built = ekwa_find_build_row(
					array(
						'group'    => 'file',
						'label'    => $is_child ? $child_name : wp_get_theme( get_template() )->get( 'Name' ),
						'where'    => $rel,
						'text'     => $content,
						'args'     => $args,
						'edit_url' => ekwa_find_file_editor_url( $rel, $which ),
						'writable' => $writable,
						'note'     => $note,
						'lines'    => true,
						'ref'      => array( 'type' => 'file', 'which' => $which, 'rel' => $rel ),
					)
				);

				if ( $built ) {
					$out[] = $built;
				}
			}
		}
	}

	return $out;
}

/**
 * Run every scanner and group the results.
 *
 * Read-only: writes nothing, not even a transient.
 *
 * @param array $args Search args from ekwa_find_args().
 * @return array{groups:array,total:int,places:int,truncated:bool,args:array}
 */
function ekwa_find_scan( $args ) {
	$truncated = false;

	$rows = array_merge(
		ekwa_find_scan_posts( $args, $truncated ),
		ekwa_find_scan_meta( $args, $truncated ),
		ekwa_find_scan_options( $args, $truncated ),
		ekwa_find_scan_terms( $args, $truncated ),
		ekwa_find_scan_files( $args, $truncated )
	);

	$groups = array();
	$total  = 0;

	foreach ( $rows as $row ) {
		$groups[ $row['group'] ][] = $row;
		$total                    += (int) $row['count'];
	}

	// Stable, useful reading order.
	$order  = array_keys( ekwa_find_group_labels() );
	$sorted = array();
	foreach ( $order as $group ) {
		if ( ! empty( $groups[ $group ] ) ) {
			$sorted[ $group ] = $groups[ $group ];
		}
	}

	return array(
		'groups'    => $sorted,
		'total'     => $total,
		'places'    => count( $rows ),
		'truncated' => $truncated,
		'args'      => $args,
	);
}

/**
 * Display group slug => heading.
 *
 * @return array<string,string>
 */
function ekwa_find_group_labels() {
	return array(
		'content'  => __( 'Pages, posts & content', 'ekwa' ),
		'seo'      => __( 'SEO title & meta description', 'ekwa' ),
		'template' => __( 'Templates & template parts (Site Editor)', 'ekwa' ),
		'file'     => __( 'Theme files', 'ekwa' ),
		'menu'     => __( 'Menus', 'ekwa' ),
		'option'   => __( 'Settings', 'ekwa' ),
		'term'     => __( 'Categories & tags', 'ekwa' ),
		'media'    => __( 'Media', 'ekwa' ),
	);
}

/* ==================================================================
 * Replace.
 * ================================================================== */

/**
 * Transient holding the last apply result for the current user.
 *
 * @return string
 */
function ekwa_find_result_transient() {
	return 'ekwa_find_result_' . get_current_user_id();
}

/**
 * Read one row's current text straight from the database / disk.
 *
 * Deliberately re-read at apply time rather than carried through the request:
 * between the preview and the confirmation someone else may have edited the
 * page, and writing back a stale copy would silently discard their work.
 *
 * @param array $ref Reference descriptor.
 * @return string|null Null when the target no longer exists.
 */
function ekwa_find_read_ref( $ref ) {
	switch ( $ref['type'] ) {
		case 'post':
			$post = get_post( (int) $ref['id'] );
			if ( ! $post ) {
				return null;
			}
			return isset( $post->{$ref['field']} ) ? (string) $post->{$ref['field']} : null;

		case 'meta':
			$value = get_post_meta( (int) $ref['id'], (string) $ref['key'], true );
			return is_string( $value ) ? $value : null;

		case 'option':
			$value = get_option( (string) $ref['key'] );
			foreach ( (array) $ref['path'] as $key ) {
				if ( is_object( $value ) ) {
					$value = get_object_vars( $value );
				}
				if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
					return null;
				}
				$value = $value[ $key ];
			}
			return is_string( $value ) ? $value : null;

		case 'term':
			$term = get_term( (int) $ref['id'], (string) $ref['taxonomy'] );
			if ( ! $term || is_wp_error( $term ) ) {
				return null;
			}
			return isset( $term->{$ref['field']} ) ? (string) $term->{$ref['field']} : null;

		case 'file':
			$path = ekwa_find_ref_file_path( $ref );
			if ( '' === $path || ! is_readable( $path ) ) {
				return null;
			}
			return (string) file_get_contents( $path );
	}

	return null;
}

/**
 * Absolute path for a file ref, or '' when it escapes its theme directory.
 *
 * @param array $ref Reference descriptor.
 * @return string
 */
function ekwa_find_ref_file_path( $ref ) {
	$sets = ekwa_find_file_sets();
	$which = (string) $ref['which'];

	if ( ! isset( $sets[ $which ] ) ) {
		return '';
	}

	$dir = $sets[ $which ]['dir'];
	$rel = (string) $ref['rel'];

	// Belt and braces: the ref is re-derived from a fresh scan before it gets
	// here, but a path that resolves outside the theme must never be written.
	if ( false !== strpos( $rel, '..' ) ) {
		return '';
	}

	$path = $dir . '/' . $rel;
	$real = realpath( $path );
	$base = realpath( $dir );

	if ( ! $real || ! $base || 0 !== strpos( $real, $base ) ) {
		return '';
	}

	return $real;
}

/**
 * Set a nested value at a key path, leaving everything else identical.
 *
 * @param mixed $value Container.
 * @param array $path  Key path.
 * @param mixed $new   New leaf value.
 * @return mixed
 */
function ekwa_find_set_at_path( $value, $path, $new ) {
	if ( ! $path ) {
		return $new;
	}

	$key  = array_shift( $path );
	$rest = $path;

	if ( is_array( $value ) ) {
		if ( ! array_key_exists( $key, $value ) ) {
			return $value;
		}
		$value[ $key ] = ekwa_find_set_at_path( $value[ $key ], $rest, $new );
		return $value;
	}

	if ( is_object( $value ) ) {
		if ( ! isset( $value->$key ) ) {
			return $value;
		}
		$value->$key = ekwa_find_set_at_path( $value->$key, $rest, $new );
		return $value;
	}

	return $value;
}

/**
 * Write one row's replaced text back.
 *
 * @param array  $ref  Reference descriptor.
 * @param string $text New text.
 * @return true|WP_Error
 */
function ekwa_find_write_ref( $ref, $text ) {
	switch ( $ref['type'] ) {
		case 'post':
			// wp_slash(): wp_update_post() expects slashed data and unslashes it.
			$result = wp_update_post(
				array(
					'ID'            => (int) $ref['id'],
					$ref['field']   => wp_slash( $text ),
				),
				true
			);
			return is_wp_error( $result ) ? $result : true;

		case 'meta':
			update_post_meta( (int) $ref['id'], (string) $ref['key'], wp_slash( $text ) );
			return true;

		case 'option':
			$name    = (string) $ref['key'];
			$current = get_option( $name );
			$updated = ekwa_find_set_at_path( $current, (array) $ref['path'], $text );
			update_option( $name, $updated );
			return true;

		case 'term':
			$result = wp_update_term(
				(int) $ref['id'],
				(string) $ref['taxonomy'],
				array( $ref['field'] => $text )
			);
			return is_wp_error( $result ) ? $result : true;

		case 'file':
			$path = ekwa_find_ref_file_path( $ref );
			if ( '' === $path ) {
				return new WP_Error( 'ekwa_find_path', __( 'Could not resolve the file path.', 'ekwa' ) );
			}
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( ! WP_Filesystem() ) {
				return new WP_Error( 'ekwa_find_fs', __( 'Could not access the filesystem to write the file.', 'ekwa' ) );
			}
			global $wp_filesystem;
			if ( ! $wp_filesystem->put_contents( $path, $text, FS_CHMOD_FILE ) ) {
				return new WP_Error( 'ekwa_find_write', __( 'Could not write the file — the theme folder may be read-only.', 'ekwa' ) );
			}
			return true;
	}

	return new WP_Error( 'ekwa_find_ref', __( 'Unknown target.', 'ekwa' ) );
}

/**
 * Rows the request selected, re-derived from a fresh scan.
 *
 * This is the security boundary for the whole replace feature. Nothing about a
 * target comes from the POST except its key: the scan is re-run server-side and
 * only rows it produced, and marked writable, can be selected. A hand-forged
 * POST naming a parent-theme file gets nothing back.
 *
 * @param array $args Search args.
 * @param array $keys Submitted row keys.
 * @return array Rows.
 */
function ekwa_find_selected_rows( $args, $keys ) {
	$keys = array_flip( array_map( 'strval', (array) $keys ) );
	$scan = ekwa_find_scan( $args );
	$out  = array();

	foreach ( $scan['groups'] as $rows ) {
		foreach ( $rows as $row ) {
			if ( isset( $keys[ $row['key'] ] ) && $row['writable'] ) {
				$out[] = $row;
			}
		}
	}

	return $out;
}

/**
 * admin_init: apply a confirmed replace, then redirect.
 *
 * Redirecting (rather than writing during the page render) is what makes a
 * browser refresh safe — it cannot re-post the write.
 */
function ekwa_find_handle_apply() {
	if ( ! isset( $_POST['ekwa_find_action'] ) || 'apply' !== $_POST['ekwa_find_action'] ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! isset( $_POST['ekwa_find_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ekwa_find_nonce'] ) ), 'ekwa_find_replace' ) ) {
		return;
	}

	$args        = ekwa_find_args( $_POST );
	$replacement = isset( $_POST['ekwa_find_replacement'] ) ? (string) wp_unslash( $_POST['ekwa_find_replacement'] ) : '';
	$keys        = isset( $_POST['ekwa_find_rows'] ) ? (array) wp_unslash( $_POST['ekwa_find_rows'] ) : array();

	$result = array(
		'changed' => 0,
		'hits'    => 0,
		'skipped' => 0,
		'errors'  => array(),
		'term'    => $args['term'],
		'to'      => $replacement,
	);

	if ( '' === $args['term'] || ! $keys ) {
		set_transient( ekwa_find_result_transient(), $result, MINUTE_IN_SECONDS * 5 );
		wp_safe_redirect( ekwa_find_page_url( $args ) );
		exit;
	}

	$rows      = ekwa_find_selected_rows( $args, $keys );
	$found_key = array();
	foreach ( $rows as $row ) {
		$found_key[ $row['key'] ] = true;
	}
	$result['skipped'] = count( array_diff( array_map( 'strval', $keys ), array_keys( $found_key ) ) );

	foreach ( $rows as $row ) {
		$current = ekwa_find_read_ref( $row['ref'] );
		if ( null === $current ) {
			$result['errors'][] = sprintf(
				/* translators: %s: where the match was */
				__( '%s no longer exists — skipped.', 'ekwa' ),
				$row['label'] . ' → ' . $row['where']
			);
			continue;
		}

		$replaced = ekwa_find_replace_in_text( $current, $args, $replacement );
		if ( 0 === $replaced['count'] ) {
			continue;
		}

		$written = ekwa_find_write_ref( $row['ref'], $replaced['text'] );
		if ( is_wp_error( $written ) ) {
			$result['errors'][] = $row['label'] . ' → ' . $row['where'] . ': ' . $written->get_error_message();
			continue;
		}

		$result['changed']++;
		$result['hits'] += $replaced['count'];
	}

	set_transient( ekwa_find_result_transient(), $result, MINUTE_IN_SECONDS * 5 );
	wp_safe_redirect( ekwa_find_page_url( $args ) );
	exit;
}
add_action( 'admin_init', 'ekwa_find_handle_apply' );

/* ==================================================================
 * Admin page.
 * ================================================================== */

/**
 * URL of this page, optionally carrying a search.
 *
 * @param array|null $args Search args, or null for the bare page.
 * @return string
 */
function ekwa_find_page_url( $args = null ) {
	$url = admin_url( 'themes.php?page=ekwa-find' );

	if ( is_array( $args ) && '' !== $args['term'] ) {
		// add_query_arg() does NOT encode its values (build_query() passes
		// $urlencode = false), so the term is encoded here. Without this a term
		// containing "&" or "#" — "Family & General Dentistry" is a real page
		// title — silently truncates the redirect after a replace.
		$query = array( 'ekwa_find_term' => rawurlencode( $args['term'] ) );
		if ( 'shortcode' === $args['mode'] ) {
			$query['ekwa_find_mode'] = 'shortcode';
		}
		if ( $args['word'] ) {
			$query['ekwa_find_word'] = '1';
		}
		if ( $args['trash'] ) {
			$query['ekwa_find_trash'] = '1';
		}
		$url = add_query_arg( $query, $url );
	}

	return $url;
}

/**
 * The HTML-entity-encoded form of a term, when it differs from the term itself.
 *
 * WordPress stores "Family & General Dentistry" in post_title as
 * "Family &amp; General Dentistry", so a literal search for the phrase as it is
 * READ on the page correctly finds nothing — which looks exactly like a broken
 * tool. The search stays literal (guessing would make results unpredictable and
 * the replace step unsafe); instead the encoded form is offered as a second
 * search the user can click.
 *
 * @param string $term Search term.
 * @return string Encoded variant, or '' when identical.
 */
function ekwa_find_encoded_variant( $term ) {
	$encoded = esc_html( (string) $term );

	return ( $encoded !== (string) $term ) ? $encoded : '';
}

/**
 * Render the "your term may be stored HTML-encoded" hint.
 *
 * @param array $args Search args.
 */
function ekwa_find_render_encoded_hint( $args ) {
	$encoded = ekwa_find_encoded_variant( $args['term'] );
	if ( '' === $encoded ) {
		return;
	}

	$alt        = $args;
	$alt['term'] = $encoded;
	?>
	<p class="description ekwa-find-hint">
		<?php
		printf(
			/* translators: 1: encoded search term, 2: opening link tag, 3: closing link tag */
			esc_html__( 'WordPress stores characters like & and quotes encoded, so this phrase may be saved as %1$s. %2$sSearch for that instead%3$s.', 'ekwa' ),
			'<code>' . ekwa_find_esc( $encoded ) . '</code>',
			'<a href="' . esc_url( ekwa_find_page_url( $alt ) ) . '">',
			'</a>'
		);
		?>
	</p>
	<?php
}

/**
 * Register the page under Appearance, next to Schema Editor and Shortcodes.
 */
function ekwa_find_add_page() {
	add_theme_page(
		__( 'Find in Site', 'ekwa' ),
		__( 'Find in Site', 'ekwa' ),
		'manage_options',
		'ekwa-find',
		'ekwa_find_render_page'
	);
}
add_action( 'admin_menu', 'ekwa_find_add_page' );

/**
 * Enqueue the shared admin stylesheet on this page only.
 *
 * @param string $hook Current admin page hook.
 */
function ekwa_find_enqueue( $hook ) {
	if ( 'appearance_page_ekwa-find' !== $hook ) {
		return;
	}
	wp_enqueue_style(
		'ekwa-admin-css',
		get_template_directory_uri() . '/assets/css/ekwa-admin.css',
		array(),
		wp_get_theme()->get( 'Version' )
	);
}
add_action( 'admin_enqueue_scripts', 'ekwa_find_enqueue' );

/**
 * Render one results table row.
 *
 * @param array $row      Result row.
 * @param bool  $can_pick Whether to show the replace checkbox column.
 */
function ekwa_find_render_row( $row, $can_pick ) {
	?>
	<tr>
		<?php if ( $can_pick ) : ?>
			<th scope="row" class="check-column">
				<?php if ( $row['writable'] ) : ?>
					<input type="checkbox" name="ekwa_find_rows[]" value="<?php echo ekwa_find_esc( $row['key'] ); ?>" />
				<?php else : ?>
					<span class="dashicons dashicons-lock ekwa-find-lock" aria-hidden="true"></span>
					<span class="screen-reader-text"><?php esc_html_e( 'Read-only', 'ekwa' ); ?></span>
				<?php endif; ?>
			</th>
		<?php endif; ?>

		<td class="ekwa-find-where">
			<strong><?php echo esc_html( $row['label'] ); ?></strong>
			<div class="ekwa-find-field"><?php echo esc_html( $row['where'] ); ?></div>
			<?php if ( '' !== $row['note'] ) : ?>
				<div class="ekwa-find-note"><?php echo esc_html( $row['note'] ); ?></div>
			<?php endif; ?>
		</td>

		<td class="ekwa-find-count"><?php echo (int) $row['count']; ?></td>

		<td class="ekwa-find-context">
			<?php foreach ( $row['snippets'] as $snippet ) : ?>
				<p>
					<?php if ( ! empty( $snippet['line'] ) ) : ?>
						<span class="ekwa-find-line"><?php
							/* translators: %d: line number */
							printf( esc_html__( 'line %d', 'ekwa' ), (int) $snippet['line'] );
						?></span>
					<?php endif; ?>
					<?php
					// ekwa_find_highlight() escapes the snippet and adds only <mark>.
					echo wp_kses( ekwa_find_highlight( $snippet['text'], $snippet['match'] ), array( 'mark' => array() ) );
					?>
				</p>
			<?php endforeach; ?>
			<?php if ( $row['count'] > count( $row['snippets'] ) ) : ?>
				<p class="ekwa-find-more"><?php
					printf(
						/* translators: %d: number of further matches */
						esc_html( _n( '…and %d more match here.', '…and %d more matches here.', $row['count'] - count( $row['snippets'] ), 'ekwa' ) ),
						(int) ( $row['count'] - count( $row['snippets'] ) )
					);
				?></p>
			<?php endif; ?>
		</td>

		<td class="ekwa-find-links">
			<?php if ( '' !== $row['edit_url'] ) : ?>
				<a href="<?php echo esc_url( $row['edit_url'] ); ?>"><?php esc_html_e( 'Edit', 'ekwa' ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== $row['view_url'] ) : ?>
				<a href="<?php echo esc_url( $row['view_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'ekwa' ); ?></a>
			<?php endif; ?>
		</td>
	</tr>
	<?php
}

/**
 * Render the preview of a pending replace.
 *
 * @param array  $args        Search args.
 * @param array  $rows        Selected rows.
 * @param string $replacement Replacement text.
 */
function ekwa_find_render_preview( $args, $rows, $replacement ) {
	?>
	<div class="wrap ekwa-find-wrap">
		<h1><?php esc_html_e( 'Find in Site — confirm replace', 'ekwa' ); ?></h1>

		<div class="notice notice-warning inline ekwa-find-confirm">
			<p>
				<?php
				printf(
					/* translators: 1: search term, 2: replacement */
					esc_html__( 'About to replace %1$s with %2$s in the places listed below.', 'ekwa' ),
					'<code>' . ekwa_find_esc( $args['term'] ) . '</code>',
					'' === $replacement ? '<code>' . esc_html__( '(nothing — the text is removed)', 'ekwa' ) . '</code>' : '<code>' . ekwa_find_esc( $replacement ) . '</code>'
				);
				?>
			</p>
			<p><?php esc_html_e( 'Posts and pages keep a revision you can roll back to. Options, terms and files do not — take a backup first if you are unsure.', 'ekwa' ); ?></p>
		</div>

		<?php if ( ! $rows ) : ?>
			<p><?php esc_html_e( 'Nothing selected that can be written to.', 'ekwa' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( ekwa_find_page_url( $args ) ); ?>"><?php esc_html_e( 'Back to results', 'ekwa' ); ?></a></p>
			</div>
			<?php
			return;
		endif;
		?>

		<table class="widefat striped ekwa-find-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Where', 'ekwa' ); ?></th>
					<th><?php esc_html_e( 'Before', 'ekwa' ); ?></th>
					<th><?php esc_html_e( 'After', 'ekwa' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$current = ekwa_find_read_ref( $row['ref'] );
				if ( null === $current ) {
					continue;
				}
				$after   = ekwa_find_replace_in_text( $current, $args, $replacement );
				$matches = ekwa_find_matches( $current, $args );
				$first   = $matches ? $matches[0] : null;
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( $row['label'] ); ?></strong>
						<div class="ekwa-find-field"><?php echo esc_html( $row['where'] ); ?></div>
						<div class="ekwa-find-field"><?php
							printf(
								/* translators: %d: number of replacements */
								esc_html( _n( '%d replacement', '%d replacements', (int) $after['count'], 'ekwa' ) ),
								(int) $after['count']
							);
						?></div>
					</td>
					<td class="ekwa-find-context">
						<?php if ( $first ) : ?>
							<?php echo wp_kses( ekwa_find_highlight( ekwa_find_snippet( $current, $first['offset'], $first['length'] ), substr( $current, $first['offset'], $first['length'] ) ), array( 'mark' => array() ) ); ?>
						<?php endif; ?>
					</td>
					<td class="ekwa-find-context">
						<?php
						if ( $first ) {
							// Same window, taken from the replaced text.
							$shift = $first['offset'];
							echo wp_kses( ekwa_find_highlight( ekwa_find_snippet( $after['text'], $shift, max( 1, strlen( $replacement ) ) ), $replacement ), array( 'mark' => array() ) );
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" action="">
			<?php wp_nonce_field( 'ekwa_find_replace', 'ekwa_find_nonce' ); ?>
			<input type="hidden" name="ekwa_find_action" value="apply" />
			<input type="hidden" name="ekwa_find_term" value="<?php echo ekwa_find_esc( $args['term'] ); ?>" />
			<input type="hidden" name="ekwa_find_replacement" value="<?php echo ekwa_find_esc( $replacement ); ?>" />
			<?php if ( 'shortcode' === $args['mode'] ) : ?>
				<input type="hidden" name="ekwa_find_mode" value="shortcode" />
			<?php endif; ?>
			<?php if ( $args['word'] ) : ?>
				<input type="hidden" name="ekwa_find_word" value="1" />
			<?php endif; ?>
			<?php if ( $args['trash'] ) : ?>
				<input type="hidden" name="ekwa_find_trash" value="1" />
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<input type="hidden" name="ekwa_find_rows[]" value="<?php echo ekwa_find_esc( $row['key'] ); ?>" />
			<?php endforeach; ?>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply these changes', 'ekwa' ); ?></button>
				<a class="button" href="<?php echo esc_url( ekwa_find_page_url( $args ) ); ?>"><?php esc_html_e( 'Cancel', 'ekwa' ); ?></a>
			</p>
		</form>
	</div>
	<?php
}

/**
 * Render the page.
 */
function ekwa_find_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$is_preview = isset( $_POST['ekwa_find_action'] ) && 'preview' === $_POST['ekwa_find_action']
		&& isset( $_POST['ekwa_find_nonce'] )
		&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ekwa_find_nonce'] ) ), 'ekwa_find_replace' );

	if ( $is_preview ) {
		$args        = ekwa_find_args( $_POST );
		$replacement = isset( $_POST['ekwa_find_replacement'] ) ? (string) wp_unslash( $_POST['ekwa_find_replacement'] ) : '';
		$keys        = isset( $_POST['ekwa_find_rows'] ) ? (array) wp_unslash( $_POST['ekwa_find_rows'] ) : array();
		ekwa_find_render_preview( $args, ekwa_find_selected_rows( $args, $keys ), $replacement );
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search.
	$args   = ekwa_find_args( $_GET );
	$result = get_transient( ekwa_find_result_transient() );
	if ( $result ) {
		delete_transient( ekwa_find_result_transient() );
	}
	?>
	<div class="wrap ekwa-find-wrap">
		<h1><?php esc_html_e( 'Find in Site', 'ekwa' ); ?></h1>

		<p class="description ekwa-find-intro">
			<?php esc_html_e( 'Searches pages, posts and every other content type, SEO titles and meta descriptions, image alt text, templates and template parts (both the Site Editor versions and the theme files), menu labels, theme settings, and categories. Searching changes nothing.', 'ekwa' ); ?>
		</p>

		<?php if ( is_array( $result ) ) : ?>
			<div class="notice notice-success inline ekwa-find-result">
				<p>
					<strong>
						<?php
						printf(
							/* translators: 1: number of replacements, 2: number of places */
							esc_html__( 'Replaced %1$d occurrence(s) across %2$d place(s).', 'ekwa' ),
							(int) $result['hits'],
							(int) $result['changed']
						);
						?>
					</strong>
					<?php if ( ! empty( $result['skipped'] ) ) : ?>
						<?php
						printf(
							/* translators: %d: number skipped */
							esc_html__( '%d selected row(s) were skipped because they are read-only or no longer match.', 'ekwa' ),
							(int) $result['skipped']
						);
						?>
					<?php endif; ?>
				</p>
				<?php if ( ! empty( $result['errors'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Problems:', 'ekwa' ); ?></strong></p>
					<ul class="ekwa-find-errors">
						<?php foreach ( $result['errors'] as $error ) : ?>
							<li><?php echo esc_html( $error ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<form method="get" action="" class="ekwa-find-search">
			<input type="hidden" name="page" value="ekwa-find" />
			<p>
				<label class="screen-reader-text" for="ekwa_find_term"><?php esc_html_e( 'Search for', 'ekwa' ); ?></label>
				<input
					type="text"
					id="ekwa_find_term"
					name="ekwa_find_term"
					class="regular-text ekwa-find-input"
					value="<?php echo ekwa_find_esc( $args['term'] ); ?>"
					placeholder="<?php esc_attr_e( 'e.g. Main Line Dental Health, or ekwa_phone', 'ekwa' ); ?>"
				/>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Find', 'ekwa' ); ?></button>
			</p>
			<p class="ekwa-find-opts">
				<label>
					<input type="checkbox" name="ekwa_find_word" value="1" <?php checked( $args['word'] ); ?> />
					<?php esc_html_e( 'Whole word only', 'ekwa' ); ?>
				</label>
				<label>
					<input type="checkbox" name="ekwa_find_mode" value="shortcode" <?php checked( 'shortcode' === $args['mode'] ); ?> />
					<?php esc_html_e( 'Shortcode tag', 'ekwa' ); ?>
					<span class="description"><?php esc_html_e( '(matches [term …] and [/term] only)', 'ekwa' ); ?></span>
				</label>
				<label>
					<input type="checkbox" name="ekwa_find_trash" value="1" <?php checked( $args['trash'] ); ?> />
					<?php esc_html_e( 'Include trash', 'ekwa' ); ?>
				</label>
			</p>
		</form>

		<?php
		if ( '' === $args['term'] ) {
			echo '</div>';
			return;
		}

		$scan     = ekwa_find_scan( $args );
		$labels   = ekwa_find_group_labels();
		$can_pick = true;
		?>

		<h2 class="ekwa-find-summary">
			<?php
			printf(
				/* translators: 1: number of matches, 2: number of places, 3: search term */
				esc_html__( '%1$d match(es) in %2$d place(s) for “%3$s”', 'ekwa' ),
				(int) $scan['total'],
				(int) $scan['places'],
				ekwa_find_esc( $args['term'] )
			);
			?>
		</h2>

		<?php ekwa_find_render_encoded_hint( $args ); ?>

		<?php if ( $scan['truncated'] ) : ?>
			<div class="notice notice-warning inline">
				<p><?php
					printf(
						/* translators: %d: row cap */
						esc_html__( 'Stopped at %d results — narrow the search to see the rest.', 'ekwa' ),
						(int) EKWA_FIND_MAX_ROWS
					);
				?></p>
			</div>
		<?php endif; ?>

		<?php if ( ! $scan['groups'] ) : ?>
			<p><?php esc_html_e( 'Not found anywhere.', 'ekwa' ); ?></p>
			</div>
			<?php
			return;
		endif;

		if ( ! current_user_can( 'unfiltered_html' ) ) :
			?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'Your account cannot post unfiltered HTML, so replacing inside page content could strip markup. Replace is limited to fields you can safely edit — check the result carefully.', 'ekwa' ); ?></p>
			</div>
			<?php
		endif;
		?>

		<form method="post" action="" class="ekwa-find-results">
			<?php wp_nonce_field( 'ekwa_find_replace', 'ekwa_find_nonce' ); ?>
			<input type="hidden" name="ekwa_find_action" value="preview" />
			<input type="hidden" name="ekwa_find_term" value="<?php echo ekwa_find_esc( $args['term'] ); ?>" />
			<?php if ( 'shortcode' === $args['mode'] ) : ?>
				<input type="hidden" name="ekwa_find_mode" value="shortcode" />
			<?php endif; ?>
			<?php if ( $args['word'] ) : ?>
				<input type="hidden" name="ekwa_find_word" value="1" />
			<?php endif; ?>
			<?php if ( $args['trash'] ) : ?>
				<input type="hidden" name="ekwa_find_trash" value="1" />
			<?php endif; ?>

			<?php foreach ( $scan['groups'] as $group => $rows ) : ?>
				<h2 class="ekwa-find-group"><?php echo esc_html( isset( $labels[ $group ] ) ? $labels[ $group ] : $group ); ?>
					<span class="ekwa-find-groupcount"><?php echo (int) count( $rows ); ?></span>
				</h2>
				<table class="widefat striped ekwa-find-table">
					<thead>
						<tr>
							<?php if ( $can_pick ) : ?>
								<td class="manage-column check-column"><input type="checkbox" class="ekwa-find-all" /></td>
							<?php endif; ?>
							<th class="manage-column"><?php esc_html_e( 'Where', 'ekwa' ); ?></th>
							<th class="manage-column ekwa-find-count"><?php esc_html_e( 'Hits', 'ekwa' ); ?></th>
							<th class="manage-column"><?php esc_html_e( 'Context', 'ekwa' ); ?></th>
							<th class="manage-column"><?php esc_html_e( 'Open', 'ekwa' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<?php ekwa_find_render_row( $row, $can_pick ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>

			<div class="ekwa-find-replacebar">
				<h2><?php esc_html_e( 'Replace (optional)', 'ekwa' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Tick the rows above, type the replacement, and you will get a before/after preview to confirm before anything is written. Rows with a padlock cannot be written to — parent-theme files are reverted by the next theme update, so edit those in the Site Editor or the child theme instead.', 'ekwa' ); ?>
				</p>
				<p>
					<label for="ekwa_find_replacement"><?php esc_html_e( 'Replace with', 'ekwa' ); ?></label>
					<input type="text" id="ekwa_find_replacement" name="ekwa_find_replacement" class="regular-text" value="" />
					<button type="submit" class="button"><?php esc_html_e( 'Preview replace', 'ekwa' ); ?></button>
				</p>
			</div>
		</form>

		<script>
		document.querySelectorAll( '.ekwa-find-all' ).forEach( function ( toggle ) {
			toggle.addEventListener( 'change', function () {
				var table = toggle.closest( 'table' );
				if ( ! table ) { return; }
				table.querySelectorAll( 'tbody input[type="checkbox"]' ).forEach( function ( box ) {
					box.checked = toggle.checked;
				} );
			} );
		} );
		</script>
	</div>
	<?php
}
