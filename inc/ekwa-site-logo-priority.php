<?php
/**
 * Site Logo — "Load first (LCP image)".
 *
 * On many pages — above all on phones, where the mobile header is short and
 * the logo is the biggest thing above the fold — the Largest Contentful Paint
 * element is the site logo, a core/site-logo block in a header template part.
 * PageSpeed then asks for it to be discoverable early and fetched with high
 * priority ("LCP request discovery").
 *
 * This adds one attribute to core/site-logo, ekwaFetchPriority (default false:
 * every logo already saved renders exactly as before). When it is on:
 *   - the logo <img> gets fetchpriority="high" and is never lazy-loaded;
 *   - a <link rel="preload" as="image" fetchpriority="high"> for the very same
 *     file (same src/srcset — WebP swaps included, since it is read from the
 *     rendered tag) is printed in <head>. Block themes render the template
 *     before wp_head, so the logo is known by then.
 *
 * Set it per logo block: in the Site Logo block's Performance panel, or by the
 * page audit's AI as a template-part fix (e.g. on the mobile header's logo).
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the attribute on core/site-logo (server side).
 *
 * @param array  $args
 * @param string $name
 * @return array
 */
function ekwa_site_logo_priority_args( $args, $name ) {
	if ( 'core/site-logo' !== $name ) {
		return $args;
	}
	$args['attributes']                      = isset( $args['attributes'] ) && is_array( $args['attributes'] ) ? $args['attributes'] : array();
	$args['attributes']['ekwaFetchPriority'] = array(
		'type'    => 'boolean',
		'default' => false,
	);
	return $args;
}
add_filter( 'register_block_type_args', 'ekwa_site_logo_priority_args', 10, 2 );

/**
 * Logos to preload on this request.
 *
 * @param array|null $add {src, srcset, sizes} to queue.
 * @return array[]
 */
function ekwa_site_logo_priority_queue( $add = null ) {
	static $queue = array();
	if ( is_array( $add ) && ! empty( $add['src'] ) && ! isset( $queue[ $add['src'] ] ) ) {
		$queue[ $add['src'] ] = $add;
	}
	return $queue;
}

/**
 * Prioritise a flagged logo's <img>. Runs on render_block_core/site-logo,
 * i.e. after the generic render_block filters (WebP) have run.
 *
 * @param string $html
 * @param array  $block
 * @return string
 */
function ekwa_site_logo_priority_render( $html, $block ) {
	if ( empty( $block['attrs']['ekwaFetchPriority'] ) || false === strpos( $html, '<img' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $html;
	}
	$p = new WP_HTML_Tag_Processor( $html );
	if ( ! $p->next_tag( 'img' ) ) {
		return $html;
	}
	$p->set_attribute( 'fetchpriority', 'high' );
	if ( 'lazy' === $p->get_attribute( 'loading' ) ) {
		$p->set_attribute( 'loading', 'eager' );
	}
	$src = $p->get_attribute( 'src' );
	if ( is_string( $src ) && '' !== $src && 0 !== strpos( $src, 'data:' ) ) {
		ekwa_site_logo_priority_queue( array(
			'src'    => $src,
			'srcset' => is_string( $p->get_attribute( 'srcset' ) ) ? $p->get_attribute( 'srcset' ) : '',
			'sizes'  => is_string( $p->get_attribute( 'sizes' ) ) ? $p->get_attribute( 'sizes' ) : '',
		) );
	}
	return $p->get_updated_html();
}
add_filter( 'render_block_core/site-logo', 'ekwa_site_logo_priority_render', 30, 2 );

/**
 * Print the preload for each flagged logo, early in <head>.
 */
function ekwa_site_logo_priority_preload() {
	foreach ( ekwa_site_logo_priority_queue() as $logo ) {
		echo '<link rel="preload" as="image" href="' . esc_url( $logo['src'] ) . '"';
		if ( '' !== $logo['srcset'] ) {
			echo ' imagesrcset="' . esc_attr( $logo['srcset'] ) . '"';
			if ( '' !== $logo['sizes'] ) {
				echo ' imagesizes="' . esc_attr( $logo['sizes'] ) . '"';
			}
		}
		echo " fetchpriority=\"high\">\n";
		if ( function_exists( 'ekwa_perf_image_preload_emitted' ) ) {
			ekwa_perf_image_preload_emitted( true );
		}
	}
}
add_action( 'wp_head', 'ekwa_site_logo_priority_preload', 1 );

/**
 * The editor side: the attribute (client registration) and the toggle.
 */
function ekwa_site_logo_priority_editor() {
	$rel = 'assets/js/ekwa-site-logo-priority.js';
	wp_enqueue_script(
		'ekwa-site-logo-priority',
		get_template_directory_uri() . '/' . $rel,
		array( 'wp-hooks', 'wp-compose', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
		filemtime( get_template_directory() . '/' . $rel ),
		false
	);
}
add_action( 'enqueue_block_editor_assets', 'ekwa_site_logo_priority_editor' );
