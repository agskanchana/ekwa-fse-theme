<?php
/**
 * Keep the mockup's inline styles on the core blocks the converter emits.
 *
 * The Ekwa blocks solve this with a real `inlineStyle` attribute their PHP
 * renderer prints (see ekwa_render_inline_style_attr()). Core's heading,
 * paragraph, list, quote and table blocks can't: they are STATIC blocks whose
 * saved markup has to match what their JS `save()` regenerates, and a `style`
 * attribute nothing in the block schema accounts for fails block validation —
 * the editor greets the page with "This block contains unexpected or invalid
 * content" and offers to throw the markup away.
 *
 * So the declarations ride in the block comment instead:
 *
 *     <!-- wp:heading {"inlineStyle":"color:#fff"} -->
 *     <h2 class="wp-block-heading">…</h2>
 *     <!-- /wp:heading -->
 *
 * The comment is not part of the validated markup, so save() is untouched and
 * the block stays valid. This file puts the style back on the element at render
 * time, and assets/js/ekwa-core-inline-style.js declares the attribute in the
 * editor (otherwise Gutenberg drops the unknown key on the next save), shows it
 * in the sidebar, and previews it on the canvas.
 *
 * Populated by ekwa_mc_style_attr() during conversion.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core blocks that carry an `inlineStyle` attribute.
 *
 * Exactly the static core blocks the mockup converter emits. Dynamic core
 * blocks are excluded — they render from attributes, so their own supports
 * already cover styling.
 *
 * @return string[]
 */
function ekwa_core_inline_style_blocks() {
	/**
	 * Filter the core blocks that support the Ekwa inline-style passthrough.
	 *
	 * @param string[] $blocks Block names.
	 */
	return (array) apply_filters(
		'ekwa_core_inline_style_blocks',
		array( 'core/heading', 'core/paragraph', 'core/list', 'core/quote', 'core/table' )
	);
}

/**
 * The tag the style belongs on, when it isn't the block's outermost element.
 *
 * core/table wraps its table in `<figure class="wp-block-table">`; a mockup's
 * `<table style="width:100%">` means the TABLE, not the figure, so target it
 * directly. Everything else styles its first tag.
 *
 * @param string $block_name
 * @return string Uppercase tag name, or '' for "first tag".
 */
function ekwa_core_inline_style_target_tag( $block_name ) {
	return ( 'core/table' === $block_name ) ? 'TABLE' : '';
}

/**
 * Declare the attribute server-side.
 *
 * `render_block` reads the raw parsed attrs so it doesn't strictly need this,
 * but the REST block-renderer and any code going through WP_Block do, and an
 * undeclared attribute there is silently dropped.
 *
 * @param array  $args Block type registration args.
 * @param string $name Block name.
 * @return array
 */
function ekwa_core_inline_style_register_attribute( $args, $name ) {
	if ( ! in_array( $name, ekwa_core_inline_style_blocks(), true ) ) {
		return $args;
	}
	if ( empty( $args['attributes'] ) || ! is_array( $args['attributes'] ) ) {
		$args['attributes'] = array();
	}
	$args['attributes']['inlineStyle'] = array( 'type' => 'string', 'default' => '' );
	return $args;
}
add_filter( 'register_block_type_args', 'ekwa_core_inline_style_register_attribute', 10, 2 );

/**
 * Put the saved declarations back onto the rendered element.
 *
 * Merges with (rather than replaces) any style the block already printed from
 * its own supports, and comes last so the field an author edits wins.
 *
 * @param string $block_content Rendered block HTML.
 * @param array  $block         Parsed block (raw comment attrs in `attrs`).
 * @return string
 */
function ekwa_core_inline_style_render( $block_content, $block ) {
	if ( empty( $block['attrs']['inlineStyle'] ) || '' === trim( (string) $block_content ) ) {
		return $block_content;
	}
	$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
	if ( ! in_array( $name, ekwa_core_inline_style_blocks(), true ) ) {
		return $block_content;
	}

	// Shared with the Ekwa blocks: decodes kses escaping and refuses a hostile
	// declaration.
	$style = ekwa_inline_style_value( $block['attrs'] );
	if ( '' === $style ) {
		return $block_content;
	}

	$tags   = new WP_HTML_Tag_Processor( $block_content );
	$target = ekwa_core_inline_style_target_tag( $name );
	$found  = $target ? $tags->next_tag( array( 'tag_name' => $target ) ) : $tags->next_tag();
	if ( ! $found ) {
		return $block_content;
	}

	$existing = $tags->get_attribute( 'style' );
	$existing = is_string( $existing ) ? trim( $existing, " \t\n\r;" ) : '';
	$tags->set_attribute( 'style', $existing ? $existing . ';' . $style : $style );

	return $tags->get_updated_html();
}
add_filter( 'render_block', 'ekwa_core_inline_style_render', 9, 2 );

/**
 * Editor side: declare the attribute, expose it in the sidebar, preview it.
 */
function ekwa_core_inline_style_editor_assets() {
	wp_enqueue_script(
		'ekwa-core-inline-style',
		get_template_directory_uri() . '/assets/js/ekwa-core-inline-style.js',
		array( 'wp-hooks', 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-compose', 'wp-i18n', 'ekwa-inline-style-control' ),
		filemtime( get_template_directory() . '/assets/js/ekwa-core-inline-style.js' ),
		true
	);

	wp_localize_script(
		'ekwa-core-inline-style',
		'ekwaCoreInlineStyle',
		array( 'blocks' => array_values( ekwa_core_inline_style_blocks() ) )
	);
}
add_action( 'enqueue_block_editor_assets', 'ekwa_core_inline_style_editor_assets' );
