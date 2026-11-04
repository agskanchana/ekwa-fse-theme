<?php
/**
 * Site Audit — Image Alt Text.
 *
 * Every image in published posts, pages and public custom posts, synced
 * patterns, templates and template parts — core Image/Gallery blocks, HTML,
 * classic content and the ekwa/image block (alt kept in its attributes) — with
 * its alt text judged:
 *   missing   no alt attribute at all (Lighthouse "image-alt" fails)
 *   empty     alt=""  (fine only for a purely decorative image)
 *   filename  the alt is a file name or slug ("IMG_2041", "dental-implants-2")
 * plus the media library's images that have no alt text — that alt is what
 * featured images, the site logo and every image inserted from now on use.
 *
 * Fixing an image in content happens in its editor (the Edit link). Media
 * library alt text can be typed (or suggested by the AI) right on the screen;
 * it saves through the core REST route (/wp/v2/media/<id>, alt_text), so the
 * usual upload_files / edit_post permission applies. It does not change images
 * already placed in content — they keep the alt they were inserted with.
 *
 * Opening the screen writes nothing.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Route.
 */
function ekwa_site_audit_images_routes() {
	register_rest_route( 'ekwa/v1', '/site-audit/images', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'ekwa_site_audit_rest_images',
		'permission_callback' => static function () {
			return current_user_can( ekwa_page_audit_dashboard_cap() );
		},
	) );
}
add_action( 'rest_api_init', 'ekwa_site_audit_images_routes' );

/**
 * Judge an alt text.
 *
 * @param string|null $alt null = attribute missing.
 * @param string      $src
 * @return string missing|empty|filename|ok
 */
function ekwa_site_audit_alt_state( $alt, $src ) {
	if ( null === $alt ) {
		return 'missing';
	}
	$alt = trim( (string) $alt );
	if ( '' === $alt ) {
		return 'empty';
	}
	$file = strtolower( (string) pathinfo( (string) wp_parse_url( $src, PHP_URL_PATH ), PATHINFO_FILENAME ) );
	$file = preg_replace( '/-(\d+x\d+|scaled|e\d{10,})$/', '', $file );
	$norm = static function ( $s ) {
		return trim( preg_replace( '/[\s_\-.]+/', ' ', strtolower( (string) $s ) ) );
	};
	if ( preg_match( '/\.(jpe?g|png|gif|webp|avif|svg|bmp|tiff?)$/i', $alt )
		|| preg_match( '/^(img|image|dsc|dscn|dcim|pxl|photo|pic|screenshot|screen shot|unnamed|untitled|whatsapp image|wp-image)[\s_\-]*\d/i', $alt )
		|| ( '' !== $file && strlen( $file ) > 3 && preg_match( '/[\d_\-]/', $file ) && $norm( $alt ) === $norm( $file ) )
		|| ( false === strpos( $alt, ' ' ) && strlen( $alt ) > 10 && preg_match( '/[_\-]/', $alt ) ) ) {
		return 'filename';
	}
	return 'ok';
}

/**
 * GET /site-audit/images — images with their alt state, and media library
 * images without alt text. Read-only.
 *
 * @return WP_REST_Response
 */
function ekwa_site_audit_rest_images() {
	$images  = array();
	$sources = array();
	$counts  = array( 'missing' => 0, 'empty' => 0, 'filename' => 0, 'ok' => 0 );
	$cap     = (int) apply_filters( 'ekwa_site_audit_images_max', 4000 );
	$ids     = array();

	ekwa_site_audit_each_source( static function ( $src ) use ( &$images, &$sources, &$counts, &$ids, $cap ) {
		$parsed = ekwa_site_audit_parse( $src['content'] );
		if ( ! $parsed['images'] ) {
			return;
		}
		$seen = array();
		foreach ( $parsed['images'] as $img ) {
			$state = ekwa_site_audit_alt_state( $img['alt'], $img['src'] );
			++$counts[ $state ];
			$dedupe = $img['src'] . '|' . $img['b'] . '|' . (string) $img['alt'];
			if ( isset( $seen[ $dedupe ] ) || count( $images ) >= $cap ) {
				continue;
			}
			$seen[ $dedupe ] = true;
			if ( $img['id'] ) {
				$ids[ (int) $img['id'] ] = true;
			}
			$images[] = array(
				'src'   => $img['src'],
				'alt'   => $img['alt'],
				'state' => $state,
				'b'     => $img['b'],
				'id'    => (int) $img['id'],
				's'     => $src['key'],
			);
		}
		$sources[ $src['key'] ] = ekwa_site_audit_public_source( $src );
	} );

	// The library's alt for images placed from it — "the media library has
	// alt text for this one; open the editor and use it".
	$lib_alt = array();
	if ( $ids ) {
		foreach ( ekwa_site_audit_meta_map( array_keys( $ids ), array( '_wp_attachment_image_alt' ) ) as $id => $m ) {
			if ( isset( $m['_wp_attachment_image_alt'] ) && '' !== trim( $m['_wp_attachment_image_alt'] ) ) {
				$lib_alt[ $id ] = trim( $m['_wp_attachment_image_alt'] );
			}
		}
	}
	foreach ( $images as &$img ) {
		$lib = $img['id'] && isset( $lib_alt[ $img['id'] ] ) ? $lib_alt[ $img['id'] ] : '';
		// Only worth pointing at when it is good alt text and not the same text.
		$img['libAlt'] = ( '' !== $lib && 0 !== strcasecmp( $lib, trim( (string) $img['alt'] ) ) && 'ok' === ekwa_site_audit_alt_state( $lib, $img['src'] ) ) ? $lib : '';
	}
	unset( $img );

	return rest_ensure_response( array(
		'images'  => $images,
		'sources' => $sources,
		'counts'  => $counts,
		'library' => ekwa_site_audit_library_without_alt(),
	) );
}

/**
 * Media library images with no alt text, with where each is the featured image.
 *
 * @return array{items:array[],total:int}
 */
function ekwa_site_audit_library_without_alt() {
	global $wpdb;
	$limit = (int) apply_filters( 'ekwa_site_audit_library_max', 400 );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$where = "p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type LIKE 'image/%%' AND p.post_mime_type <> 'image/svg+xml'
		AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt' AND TRIM(m.meta_value) <> '' )";
	$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE $where AND 1 = %d", 1 ) );
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, p.post_title FROM {$wpdb->posts} p WHERE $where ORDER BY p.post_date DESC LIMIT %d", $limit ) );
	// phpcs:enable

	$ids      = wp_list_pluck( (array) $rows, 'ID' );
	$featured = array();
	if ( $ids ) {
		$in   = implode( ',', array_map( 'intval', $ids ) );
		$used = $wpdb->get_results( "SELECT pm.meta_value AS att, p.ID, p.post_title FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_thumbnail_id' AND pm.meta_value IN ($in) AND p.post_status = 'publish' LIMIT 2000" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
		foreach ( (array) $used as $u ) {
			$featured[ (int) $u->att ][] = array(
				'title' => html_entity_decode( wp_strip_all_tags( (string) $u->post_title ), ENT_QUOTES, 'UTF-8' ),
				'edit'  => (string) get_edit_post_link( (int) $u->ID, 'raw' ),
			);
		}
	}

	$items = array();
	$logo  = (int) get_theme_mod( 'custom_logo' );
	foreach ( (array) $rows as $row ) {
		$id      = (int) $row->ID;
		$items[] = array(
			'id'       => $id,
			'title'    => html_entity_decode( wp_strip_all_tags( (string) $row->post_title ), ENT_QUOTES, 'UTF-8' ),
			'thumb'    => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
			'full'     => (string) wp_get_attachment_url( $id ),
			'edit'     => (string) get_edit_post_link( $id, 'raw' ),
			'featured' => isset( $featured[ $id ] ) ? array_slice( $featured[ $id ], 0, 5 ) : array(),
			'uses'     => isset( $featured[ $id ] ) ? count( $featured[ $id ] ) : 0,
			'logo'     => $id === $logo,
			'canEdit'  => current_user_can( 'edit_post', $id ),
		);
	}
	// Featured images and the logo first — their alt shows on the site as is.
	usort( $items, static function ( $a, $b ) {
		return ( $b['uses'] + ( $b['logo'] ? 1000 : 0 ) ) - ( $a['uses'] + ( $a['logo'] ? 1000 : 0 ) );
	} );

	return array(
		'items' => $items,
		'total' => $total,
	);
}
