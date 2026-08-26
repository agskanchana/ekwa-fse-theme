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
