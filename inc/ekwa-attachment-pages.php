<?php
/**
 * Disable attachment (media) pages.
 *
 * WordPress gives every upload its own front-end URL — /parent-page/image-name/
 * or ?attachment_id=123 — rendering a near-empty page holding one image. They
 * carry no content of their own, compete with real pages in search results, and
 * are a standing thin-content liability.
 *
 * Rather than let those URLs 404 (which strands any link already pointing at
 * them), every attachment request is sent on with a 301: to the post or page the
 * file is attached to when there is one, otherwise to the file itself. Media
 * uploaded while editing a page is attached to it, so in practice most requests
 * land on the page that actually uses the image. Only an attachment with no
 * viewable parent and no file left on disk 404s.
 *
 * get_attachment_link() is filtered to match, so attachment-page URLs are never
 * emitted in the first place — including by the image block's "Link to →
 * Attachment Page" option, which now resolves to the media file.
 *
 * Filters:
 *   ekwa_disable_attachment_pages — return false to restore core behaviour.
 *   ekwa_attachment_redirect_url  — override the redirect target ('' to 404).
 *
 * WordPress core already omits attachments from its XML sitemaps, so nothing is
 * needed there.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether attachment pages are disabled.
 *
 * @return bool
 */
function ekwa_attachment_pages_disabled() {
	return (bool) apply_filters( 'ekwa_disable_attachment_pages', true );
}

/**
 * Where a request for an attachment page should be sent instead.
 *
 * Prefers the attached post — a real page that gives the image context — and
 * falls back to the file. Returns '' when neither is available, which the
 * caller turns into a 404.
 *
 * @param WP_Post $attachment The attachment being requested.
 * @return string Absolute URL, or '' when there is nowhere sensible to go.
 */
function ekwa_attachment_redirect_url( $attachment ) {
	$url = '';

	// Only redirect to a parent the visitor could actually reach: a draft,
	// trashed or password-protected parent is no better than the media page.
	$parent = $attachment->post_parent ? get_post( $attachment->post_parent ) : null;
	if ( $parent instanceof WP_Post && 'publish' === $parent->post_status && '' === $parent->post_password ) {
		$permalink = get_permalink( $parent );
		if ( $permalink ) {
			$url = $permalink;
		}
	}

	if ( '' === $url ) {
		$file = wp_get_attachment_url( $attachment->ID );
		$url  = $file ? $file : '';
	}

	/**
	 * Filter the redirect target for a disabled attachment page.
	 *
	 * @param string  $url        Absolute URL, or '' to serve a 404 instead.
	 * @param WP_Post $attachment The attachment being requested.
	 */
	return (string) apply_filters( 'ekwa_attachment_redirect_url', $url, $attachment );
}

/**
 * Front-end: 301 attachment page requests away, or 404 them.
 *
 * Runs early on template_redirect so nothing renders first. The default target
 * is either a normal page or a file under wp-content/uploads (or an offloaded
 * media host), none of which route back through WordPress — so this cannot
 * loop. A filter pointing back at the attachment itself is caught and 404s.
 *
 * @return void
 */
function ekwa_attachment_pages_redirect() {
	if ( ! is_attachment() || ! ekwa_attachment_pages_disabled() ) {
		return;
	}

	$attachment = get_queried_object();
	if ( ! $attachment instanceof WP_Post ) {
		return;
	}

	$url = ekwa_attachment_redirect_url( $attachment );

	// A target equal to the page we're already on would loop.
	if ( '' !== $url && untrailingslashit( $url ) !== untrailingslashit( ekwa_attachment_current_url() ) ) {
		wp_redirect( $url, 301 );
		exit;
	}

	// Nowhere to send them: make it a proper 404 rather than an empty page.
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
}
add_action( 'template_redirect', 'ekwa_attachment_pages_redirect', 1 );

/**
 * The URL currently being requested, used only for the loop guard above.
 *
 * Built from the request rather than home_url(), which would double the path
 * prefix on a subdirectory install. Returns '' when the host is unavailable —
 * the caller only compares against it, so an empty value simply never matches.
 *
 * @return string
 */
function ekwa_attachment_current_url() {
	$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
	if ( '' === $host ) {
		return '';
	}
	$path = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

	return ( is_ssl() ? 'https://' : 'http://' ) . $host . $path;
}

/**
 * Point attachment permalinks at the media file.
 *
 * Applies in the admin and REST API too, so the block editor never writes an
 * attachment-page URL into post content.
 *
 * @param string $link    The attachment page URL core built.
 * @param int    $post_id Attachment ID.
 * @return string
 */
function ekwa_attachment_link_to_file( $link, $post_id ) {
	if ( ! ekwa_attachment_pages_disabled() ) {
		return $link;
	}

	$file = wp_get_attachment_url( $post_id );

	return $file ? $file : $link;
}
add_filter( 'attachment_link', 'ekwa_attachment_link_to_file', 10, 2 );

/**
 * Keep attachments out of front-end search results.
 *
 * Core registers the attachment post type as searchable, so without this a
 * search could still surface media — as results linking to pages that only
 * redirect away.
 *
 * @param WP_Query $query The query being prepared.
 * @return void
 */
function ekwa_attachment_pages_exclude_from_search( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
		return;
	}
	if ( ! ekwa_attachment_pages_disabled() ) {
		return;
	}

	$types = $query->get( 'post_type' );

	// An unset or "any" post_type means core's full searchable list; spell it
	// out so attachment can be removed from it.
	if ( empty( $types ) || 'any' === $types ) {
		$types = array_values( get_post_types( array( 'exclude_from_search' => false ) ) );
	}

	$types = array_diff( (array) $types, array( 'attachment' ) );

	$query->set( 'post_type', $types ? $types : array( 'post', 'page' ) );
}
add_action( 'pre_get_posts', 'ekwa_attachment_pages_exclude_from_search' );
