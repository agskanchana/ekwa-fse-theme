<?php
/**
 * ekwa/field — print an ACF field (or raw post meta) for the current page.
 *
 * The problem this solves: a header or footer template part is shared by every
 * page, but a per-page custom field is not. Dropping a normal block in there and
 * hoping it stays empty leaves the wrapper element behind on every page that has
 * no value — an empty <h2>, a bordered box with nothing in it, a gap in the flex
 * row. This block renders *nothing at all* when the field is empty: no wrapper,
 * no before/after text, no whitespace. So a heading driven by `extra_title` can
 * live in the header permanently and simply not exist on the pages that don't
 * set it.
 *
 * ACF is used when it is active (get_field(), so return-format, defaults and
 * field-group logic all apply); otherwise the value comes straight from
 * get_post_meta(). Neither is required — the block degrades to post meta.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reduce a field value to a printable string.
 *
 * Scalars pass through; a flat array of scalars (checkbox, multi-select) is
 * joined. Anything with more structure than that — an ACF image/file array, a
 * repeater, a post object, a nested group — returns '' rather than "Array" or a
 * raw URL where the author expected text. A structured field wants markup this
 * block can't guess at, so it renders nothing instead of something wrong.
 *
 * @param mixed $value Raw field value.
 * @return string
 */
function ekwa_field_stringify( $value ) {
	if ( is_string( $value ) ) {
		return $value;
	}
	if ( is_int( $value ) || is_float( $value ) ) {
		return (string) $value;
	}
	if ( is_bool( $value ) ) {
		return $value ? '1' : '';
	}
	if ( is_array( $value ) ) {
		$parts = array();
		foreach ( $value as $item ) {
			if ( is_string( $item ) || is_int( $item ) || is_float( $item ) ) {
				$parts[] = (string) $item;
				continue;
			}
			return ''; // Nested structure — not ours to render.
		}
		return implode( ', ', $parts );
	}
	return '';
}

/**
 * Read a field for a post.
 *
 * @param string $key     Field name / meta key.
 * @param int    $post_id Post id.
 * @param string $source  'auto' | 'acf' | 'meta'.
 * @return string Printable value, '' when unset or unprintable.
 */
function ekwa_field_value( $key, $post_id, $source = 'auto' ) {
	$key     = trim( (string) $key );
	$post_id = (int) $post_id;
	if ( '' === $key || $post_id <= 0 ) {
		return '';
	}

	$has_acf = function_exists( 'get_field' );

	if ( 'meta' === $source || ( ! $has_acf && 'acf' !== $source ) ) {
		return ekwa_field_stringify( get_post_meta( $post_id, $key, true ) );
	}
	if ( ! $has_acf ) {
		return ''; // Pinned to ACF, but ACF isn't active.
	}

	$value = get_field( $key, $post_id );

	// ACF returns null both for "field group not assigned here" and for "empty".
	// Fall back to raw meta in the first case so a key written by an importer or
	// by update_post_meta() still resolves under the default 'auto' source.
	if ( ( null === $value || '' === $value ) && 'acf' !== $source ) {
		return ekwa_field_stringify( get_post_meta( $post_id, $key, true ) );
	}

	return ekwa_field_stringify( $value );
}

/**
 * May this ekwa/field print `$key` from ANOTHER post (postSource "specific")?
 *
 * The block takes any post ID and any key, so unchecked it prints a private or
 * draft post's fields — or a protected `_key` (plugin data, form entries) — for
 * whoever can get it rendered: a Contributor previewing a draft or calling the
 * editor's block preview, an Author publishing a post.
 *
 * A public post's ordinary field is unrestricted, as it always was. A read is
 * restricted when the key is protected (needs `edit_post` on that post) or the
 * post is not publicly viewable (needs `read_post`). It is still allowed when the
 * viewer could read it anyway, or when the person who wrote the markup could:
 * the owner of the post content, synced pattern or template part the block sits
 * in (ekwa_field_owner_stack()). Outside any post content — templates, template
 * parts, widgets, which only administrators write — it reads as before, except in
 * a REST request: there the markup came with the request (the editor's block
 * preview, the AI preview), so the viewer is all there is to judge. Every block an
 * administrator or editor placed therefore renders exactly as before, for every
 * visitor.
 *
 * @param int    $post_id Post the field is read from.
 * @param string $key     Field name / meta key.
 * @return bool
 */
function ekwa_field_can_read( $post_id, $key ) {
	$post_id = (int) $post_id;
	$post    = $post_id > 0 ? get_post( $post_id ) : null;
	if ( ! $post ) {
		return true; // Nothing there to protect — the value is '' either way.
	}

	$need = null;
	if ( is_protected_meta( (string) $key, 'post' ) ) {
		$need = 'edit_post';
	} elseif ( ! is_post_publicly_viewable( $post ) ) {
		$need = 'read_post';
	}
	if ( null === $need ) {
		return true;
	}

	if ( current_user_can( $need, $post_id ) ) {
		$allowed = true;
	} else {
		$owner = ekwa_field_owner_stack();
		if ( true === $owner ) {
			$allowed = true;
		} elseif ( is_int( $owner ) && $owner > 0 ) {
			$allowed = user_can( $owner, $need, $post_id );
		} else {
			$allowed = ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		}
	}

	/**
	 * Filters whether an ekwa/field block may print a restricted field.
	 *
	 * @param bool   $allowed The verdict.
	 * @param int    $post_id Post the field is read from.
	 * @param string $key     Field name / meta key.
	 * @param string $need    'read_post' or 'edit_post'.
	 */
	return (bool) apply_filters( 'ekwa_field_can_read', $allowed, $post_id, $key, $need );
}

/**
 * Whose saved content is being rendered right now.
 *
 * Entries are `true` (trusted: a template part, or content no user wrote), a
 * user ID, or `false` (nothing known — transparent, the entry below it counts).
 * Post content pushes its post's owner around the_content; synced patterns and
 * template parts push around their render callbacks
 * (ekwa_field_owner_wrap_callbacks()). Not around render_block filters: a synced
 * pattern renders its own block instance a second time, so render_block runs
 * twice for one render.
 *
 * @param string $op    'top' (default) | 'push' | 'pop' | 'open' | 'close'.
 * @param mixed  $value Owner to push/open, or the token from 'push' to pop back to.
 * @return mixed 'push' returns its token; 'top' the innermost owner, or false.
 */
function ekwa_field_owner_stack( $op = 'top', $value = null ) {
	static $stack   = array();
	static $content = array(); // Depths at which still-open the_content calls began.

	switch ( $op ) {
		case 'push':
			$token   = count( $stack );
			$stack[] = $value;
			return $token;
		case 'pop':
			// Back to the depth before the matching push: never below zero, and
			// whatever a failed nested render left above it goes too.
			$stack = array_slice( $stack, 0, max( 0, (int) $value ) );
			return null;
		case 'open':
			$content[] = count( $stack );
			$stack[]   = $value;
			return null;
		case 'close':
			if ( $content ) {
				$stack = array_slice( $stack, 0, array_pop( $content ) );
			}
			return null;
	}

	for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
		if ( false !== $stack[ $i ] ) {
			return $stack[ $i ];
		}
	}
	return false;
}

/**
 * The owner entry for a post's saved content (see ekwa_field_owner_stack()).
 *
 * @param WP_Post|int|null $post
 * @return int|bool User ID; true when no user wrote it (no author, or one that no
 *                  longer exists — an import or a script); false for no post.
 */
function ekwa_field_owner_of( $post ) {
	$post = $post ? get_post( $post ) : null;
	if ( ! $post ) {
		return false;
	}
	$author = (int) $post->post_author;
	if ( $author <= 0 || ! get_userdata( $author ) ) {
		return true;
	}
	return $author;
}

/**
 * the_content, first and last: open and close the post's owner entry. Both
 * return the content untouched; apply_filters() always runs the pair.
 *
 * @param string $content
 * @return string
 */
function ekwa_field_owner_content_open( $content ) {
	ekwa_field_owner_stack( 'open', ekwa_field_owner_of( get_post() ) );
	return $content;
}

/**
 * @param string $content
 * @return string
 */
function ekwa_field_owner_content_close( $content ) {
	ekwa_field_owner_stack( 'close' );
	return $content;
}
add_filter( 'the_content', 'ekwa_field_owner_content_open', PHP_INT_MIN );
add_filter( 'the_content', 'ekwa_field_owner_content_close', PHP_INT_MAX );

/**
 * Wrap the render callbacks of core/block (owner: the synced pattern's author)
 * and core/template-part (trusted) so the owner entry is in place for exactly the
 * time their content renders. The wrapper passes every argument through and
 * returns the original output unchanged.
 *
 * @param array  $args Block type args.
 * @param string $name Block name.
 * @return array
 */
function ekwa_field_owner_wrap_callbacks( $args, $name ) {
	if ( ( 'core/block' !== $name && 'core/template-part' !== $name )
		|| empty( $args['render_callback'] ) || ! is_callable( $args['render_callback'] ) ) {
		return $args;
	}

	$render                  = $args['render_callback'];
	$args['render_callback'] = static function ( ...$call ) use ( $render, $name ) {
		$owner = true; // A template part: only administrators write those.
		if ( 'core/block' === $name ) {
			$ref     = isset( $call[0]['ref'] ) ? (int) $call[0]['ref'] : 0;
			$pattern = $ref > 0 ? get_post( $ref ) : null;
			$owner   = ( $pattern && 'wp_block' === $pattern->post_type ) ? ekwa_field_owner_of( $pattern ) : false;
		}
		$token = ekwa_field_owner_stack( 'push', $owner );
		try {
			return call_user_func_array( $render, $call );
		} finally {
			ekwa_field_owner_stack( 'pop', $token );
		}
	};

	return $args;
}
add_filter( 'register_block_type_args', 'ekwa_field_owner_wrap_callbacks', 10, 2 );

/**
 * Server-side render callback for the ekwa/field block.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function ekwa_render_field_block( $attrs ) {
	$attrs   = is_array( $attrs ) ? $attrs : array();
	$is_rest = defined( 'REST_REQUEST' ) && REST_REQUEST;

	$key    = trim( (string) ( $attrs['fieldKey'] ?? '' ) );
	$source = ekwa_banner_tag( $attrs['source'] ?? 'auto', array( 'auto', 'acf', 'meta' ), 'auto' );

	$post_id = 'specific' === ( $attrs['postSource'] ?? 'current' )
		? (int) ( $attrs['postId'] ?? 0 )
		: (int) get_the_ID();

	// Another post's field that isn't public prints only for someone who may read
	// it (ekwa_field_can_read()); otherwise the block renders as an empty one does.
	if ( '' !== $key && 'specific' === ( $attrs['postSource'] ?? 'current' ) && ! ekwa_field_can_read( $post_id, $key ) ) {
		if ( ! $is_rest ) {
			return '';
		}
		/* translators: 1: custom field name, 2: post ID. */
		$note = sprintf( __( 'Ekwa Custom Field — you can’t read “%1$s” from post #%2$d, so nothing renders here.', 'ekwa' ), $key, $post_id );
		return '<span class="ekwa-field ekwa-field--empty" style="opacity:.55;font-style:italic">'
			. esc_html( $note ) . '</span>';
	}

	$value = '' !== $key ? ekwa_field_value( $key, $post_id, $source ) : '';

	// The whole point of the block: nothing to print means nothing is printed —
	// wrapper, before/after text and all. Only the editor gets a placeholder, so
	// an empty field isn't an invisible, unselectable block on the canvas.
	if ( '' === trim( $value ) ) {
		if ( ! $is_rest ) {
			return '';
		}
		$note = '' === $key
			? __( 'Ekwa Custom Field — choose a field', 'ekwa' )
			/* translators: %s: custom field name. */
			: sprintf( __( 'Ekwa Custom Field — “%s” is empty here, so nothing renders on the front end.', 'ekwa' ), $key );
		return '<span class="ekwa-field ekwa-field--empty" style="opacity:.55;font-style:italic">'
			. esc_html( $note ) . '</span>';
	}

	$format = ekwa_banner_tag( $attrs['format'] ?? 'text', array( 'text', 'html', 'shortcode' ), 'text' );
	switch ( $format ) {
		case 'html':
			$body = wp_kses_post( $value );
			break;
		case 'shortcode':
			$body = wp_kses_post( do_shortcode( $value ) );
			break;
		default:
			$body = esc_html( $value );
			break;
	}

	$before = (string) ( $attrs['before'] ?? '' );
	$after  = (string) ( $attrs['after'] ?? '' );
	$body   = esc_html( $before ) . $body . esc_html( $after );

	// Same allow-list ekwa/div validates against, plus '' for "no wrapper at all"
	// — useful when the value is being dropped straight into someone else's
	// markup and an extra element would break the layout.
	$tag = trim( (string) ( $attrs['tagName'] ?? 'div' ) );
	if ( '' === $tag ) {
		return $body;
	}
	$tag = ekwa_banner_tag(
		$tag,
		array(
			'div', 'section', 'header', 'footer', 'nav', 'main', 'aside', 'article', 'p', 'li',
			'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
			'span', 'small', 'strong', 'b', 'em', 'i', 'mark', 'time', 'label', 'figcaption',
		),
		'div'
	);

	// inlineStyle merged into the wrapper's style, not appended as a second style
	// attribute — see the note in ekwa_render_banner_title_block().
	$wrapper_attrs = get_block_wrapper_attributes( array(
		'class' => 'ekwa-field',
		'style' => function_exists( 'ekwa_inline_style_value' ) ? ekwa_inline_style_value( $attrs ) : '',
	) );

	$out = '<' . $tag . ' ' . $wrapper_attrs;
	if ( function_exists( 'ekwa_render_custom_attributes' ) ) {
		$out .= ekwa_render_custom_attributes( $attrs );
	}
	$out .= '>' . $body . '</' . $tag . '>';

	return $out;
}

/**
 * The ACF fields offered in the block's picker.
 *
 * Top-level fields from every field group, flattened, deduped by name. Sub-fields
 * of repeaters/groups are deliberately left out: get_field() can't resolve them
 * by name outside a have_rows() loop, so listing them would offer choices that
 * silently render nothing.
 *
 * @return array<int,array{name:string,label:string,type:string,group:string}>
 */
function ekwa_field_acf_choices() {
	if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
		return array();
	}

	$choices = array();
	$seen    = array();

	foreach ( (array) acf_get_field_groups() as $group ) {
		$fields = acf_get_fields( $group );
		if ( empty( $fields ) || ! is_array( $fields ) ) {
			continue;
		}
		foreach ( $fields as $field ) {
			$name = isset( $field['name'] ) ? (string) $field['name'] : '';
			if ( '' === $name || isset( $seen[ $name ] ) ) {
				continue;
			}
			$seen[ $name ] = true;
			$choices[]     = array(
				'name'  => $name,
				'label' => isset( $field['label'] ) ? (string) $field['label'] : $name,
				'type'  => isset( $field['type'] ) ? (string) $field['type'] : '',
				'group' => isset( $group['title'] ) ? (string) $group['title'] : '',
			);
		}
	}

	usort( $choices, static function ( $a, $b ) {
		return strcasecmp( $a['group'] . $a['label'], $b['group'] . $b['label'] );
	} );

	return $choices;
}

/**
 * Hand the editor the ACF field list. No REST route needed — the list is small,
 * static per request, and only useful while the editor is open.
 */
function ekwa_field_localize_editor() {
	wp_localize_script(
		'ekwa-field-editor',
		'ekwaFieldBlock',
		array(
			'hasAcf'  => function_exists( 'get_field' ),
			'choices' => ekwa_field_acf_choices(),
		)
	);
}
add_action( 'enqueue_block_editor_assets', 'ekwa_field_localize_editor' );
