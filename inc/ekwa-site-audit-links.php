<?php
/**
 * Site Audit — Broken Links.
 *
 * Collects every link and image URL from published posts, pages and public
 * custom posts, synced patterns, navigation menus (block and classic),
 * templates, template parts and the theme's link settings (appointment link,
 * social links) — each with the place it is in and that place's editor link.
 *
 * Checking runs from the browser in small batches (POST /site-audit/links/check)
 * so a large site never hits a PHP time limit; each batch checks its URLs in
 * parallel and saves the results (EKWA_SITE_AUDIT_LINKS_OPTION, not autoloaded)
 * so the screen shows the last run when it is opened again.
 *
 *   - Links to this site are answered without a web request when possible:
 *     a page/post (url_to_postid) must be published, an uploaded file must
 *     exist. Everything else gets an HTTP request (HEAD, then GET when a
 *     server refuses HEAD).
 *   - Ekwa blocks that link to a chosen page (Link Source → page) are judged
 *     on that page: deleted, trashed or unpublished = broken.
 *   - A link that starts with "/" skips the folder a site lives in on the test
 *     server (ekwa-testbench.info/website-launchpad/example.com/); it is
 *     reported with the address it should have.
 *   - Only http(s) URLs that pass wp_http_validate_url() are requested (no
 *     private network addresses).
 *
 * Opening the screen writes nothing.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Option: last link-check results, by URL hash (not autoloaded). */
const EKWA_SITE_AUDIT_LINKS_OPTION = 'ekwa_site_audit_links';

/**
 * Routes.
 */
function ekwa_site_audit_links_routes() {
	$can = static function () {
		return current_user_can( ekwa_page_audit_dashboard_cap() );
	};
	register_rest_route( 'ekwa/v1', '/site-audit/links', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'ekwa_site_audit_rest_links',
		'permission_callback' => $can,
	) );
	register_rest_route( 'ekwa/v1', '/site-audit/links/check', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_site_audit_rest_links_check',
		'permission_callback' => $can,
		'args'                => array(
			'urls' => array( 'required' => true, 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
		),
	) );
}
add_action( 'rest_api_init', 'ekwa_site_audit_links_routes' );

/**
 * Hash used to key a URL.
 *
 * @param string $url
 * @return string
 */
function ekwa_site_audit_link_hash( $url ) {
	return substr( md5( (string) $url ), 0, 16 );
}

/**
 * Collapse ./ and ../ in a path.
 *
 * @param string $path
 * @return string
 */
function ekwa_site_audit_links_normalize_path( $path ) {
	$query = '';
	$q     = strpos( $path, '?' );
	if ( false !== $q ) {
		$query = substr( $path, $q );
		$path  = substr( $path, 0, $q );
	}
	$out = array();
	foreach ( explode( '/', $path ) as $i => $seg ) {
		if ( '..' === $seg ) {
			if ( count( $out ) > 1 ) {
				array_pop( $out );
			}
		} elseif ( '.' !== $seg ) {
			$out[] = $seg;
		}
	}
	$joined = implode( '/', $out );
	if ( '' === $joined || '/' !== $joined[0] ) {
		$joined = '/' . $joined;
	}
	if ( preg_match( '#/\.{1,2}$#', $path ) ) {
		$joined = rtrim( $joined, '/' ) . '/';
	}
	return $joined . $query;
}

/**
 * Resolve a link as a browser would, from the page it is on.
 *
 * @param string $raw  As written.
 * @param string $base The page URL (home when it has none).
 * @return array|null null = not something to check (mailto:, tel:, #anchor…).
 *                    {check: absolute URL without #fragment, rr: root-relative,
 *                    empty: href=""}
 */
function ekwa_site_audit_links_absolute( $raw, $base ) {
	$url = trim( html_entity_decode( (string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	if ( '' === $url ) {
		return array( 'check' => '', 'rr' => false, 'empty' => true );
	}
	if ( '#' === $url[0] || preg_match( '/^(mailto|tel|sms|javascript|data|callto|skype|whatsapp|fax|geo|webcal|about|blob|intent|facetime|maps):/i', $url ) ) {
		return null;
	}
	if ( false !== strpos( $url, '{{' ) || false !== strpos( $url, '%%' ) || '[' === $url[0] || false !== strpos( $url, '<?' ) ) {
		return null; // Template placeholders and shortcodes are filled in at render time.
	}

	$b      = wp_parse_url( $base );
	$scheme = isset( $b['scheme'] ) ? strtolower( $b['scheme'] ) : 'https';
	$origin = $scheme . '://' . ( isset( $b['host'] ) ? $b['host'] : '' ) . ( isset( $b['port'] ) ? ':' . $b['port'] : '' );
	$rr     = false;

	if ( 0 === strpos( $url, '//' ) ) {
		$abs = $scheme . ':' . $url;
	} elseif ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $url ) ) {
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return null; // ftp:, custom app schemes…
		}
		$abs = $url;
	} elseif ( '/' === $url[0] ) {
		$abs = $origin . ekwa_site_audit_links_normalize_path( $url );
		$rr  = true;
	} else {
		$path = isset( $b['path'] ) ? $b['path'] : '/';
		if ( '?' === $url[0] ) {
			$abs = $origin . $path . $url;
		} else {
			$dir = substr( $path, 0, (int) strrpos( $path, '/' ) + 1 );
			$abs = $origin . ekwa_site_audit_links_normalize_path( $dir . $url );
		}
	}
	$check = preg_replace( '/#.*$/s', '', $abs );
	$check = str_replace( array( ' ', '"', '<', '>' ), array( '%20', '%22', '%3C', '%3E' ), (string) $check );
	return array( 'check' => $check, 'rr' => $rr, 'empty' => false );
}

/**
 * Host + path facts about the site, for "internal" and the subfolder hint.
 *
 * @return array{host:string,path:string}
 */
function ekwa_site_audit_links_home() {
	$home = wp_parse_url( home_url( '/' ) );
	return array(
		'host' => isset( $home['host'] ) ? strtolower( $home['host'] ) : '',
		'path' => isset( $home['path'] ) ? trailingslashit( $home['path'] ) : '/',
	);
}

/**
 * Collect every link with where it is. Read-only.
 *
 * @return array{links:array,sources:array}
 */
function ekwa_site_audit_links_collect() {
	$links   = array();
	$sources = array();
	$home    = ekwa_site_audit_links_home();
	$max_occ = 25;

	$add = static function ( $raw, $kind, $text, $block, $src_key, $base, $pre = null ) use ( &$links, $home, $max_occ ) {
		$r = ekwa_site_audit_links_absolute( $raw, $base );
		if ( null === $r ) {
			return false;
		}
		if ( $r['empty'] ) {
			if ( 'image' === $kind ) {
				return false;
			}
			$h   = 'empty';
			$pre = array(
				's' => 'warn',
				'e' => __( 'Empty link (href="") — it goes nowhere. Add the address or remove the link.', 'ekwa' ),
			);
		} else {
			$h = ekwa_site_audit_link_hash( $r['check'] );
		}
		if ( ! isset( $links[ $h ] ) ) {
			$parts    = wp_parse_url( $r['check'] );
			$host     = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
			$path     = isset( $parts['path'] ) ? $parts['path'] : '/';
			$internal = '' !== $host && $host === $home['host'];
			$sub      = $r['rr'] && $internal && '/' !== $home['path'] && 0 !== strpos( trailingslashit( $path ), $home['path'] );
			$pq       = $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
			$links[ $h ] = array(
				'h'        => $h,
				'u'        => $r['check'],
				'k'        => $kind,
				'internal' => $internal,
				'sub'      => $sub,
				'alt'      => $sub ? home_url( ltrim( $pq, '/' ) ) : '',
				'pre'      => $pre,
				'n'        => 0,
				'occ'      => array(),
			);
		} elseif ( 'link' === $kind ) {
			$links[ $h ]['k'] = 'link'; // Used both as a link and an image: report as a link.
		}
		++$links[ $h ]['n'];
		if ( count( $links[ $h ]['occ'] ) < $max_occ ) {
			$occ = array(
				's' => $src_key,
				't' => (string) $text,
				'b' => (string) $block,
			);
			if ( ! in_array( $occ, $links[ $h ]['occ'], true ) ) {
				$links[ $h ]['occ'][] = $occ;
			}
		}
		return true;
	};

	ekwa_site_audit_each_source( static function ( $src ) use ( &$sources, $add ) {
		$parsed = ekwa_site_audit_parse( $src['content'] );
		$base   = '' !== $src['view'] ? $src['view'] : home_url( '/' );
		$any    = false;
		foreach ( $parsed['links'] as $l ) {
			if ( ! empty( $l['pid'] ) ) {
				$p   = get_post( (int) $l['pid'] );
				$pre = null;
				$url = $p ? (string) get_permalink( $p ) : '';
				if ( ! $p ) {
					/* translators: %d: post ID */
					$pre = array( 's' => 'broken', 'e' => sprintf( __( 'Set to link to page #%d, which no longer exists — the link is missing on the site. Choose the page again.', 'ekwa' ), (int) $l['pid'] ) );
					$url = home_url( '/?page_id=' . (int) $l['pid'] );
				} elseif ( 'trash' === $p->post_status ) {
					/* translators: %s: page title */
					$pre = array( 's' => 'broken', 'e' => sprintf( __( 'Links to “%s”, which is in the trash.', 'ekwa' ), get_the_title( $p ) ) );
				} elseif ( 'publish' !== $p->post_status ) {
					/* translators: 1: page title, 2: status */
					$pre = array( 's' => 'broken', 'e' => sprintf( __( 'Links to “%1$s”, which is not published (%2$s) — visitors get an error.', 'ekwa' ), get_the_title( $p ), $p->post_status ) );
				}
				$any = $add( $url, 'link', $l['t'], $l['b'], $src['key'], $base, $pre ) || $any;
				continue;
			}
			$any = $add( $l['u'], $l['k'], $l['t'], $l['b'], $src['key'], $base ) || $any;
		}
		foreach ( $parsed['images'] as $img ) {
			$label = null === $img['alt'] || '' === trim( (string) $img['alt'] ) ? __( '(image)', 'ekwa' ) : sprintf( /* translators: %s: alt text */ __( 'Image: %s', 'ekwa' ), ekwa_site_audit_clip( $img['alt'], 70 ) );
			$any   = $add( $img['src'], 'image', $label, $img['b'], $src['key'], $base ) || $any;
		}
		if ( $any ) {
			$sources[ $src['key'] ] = ekwa_site_audit_public_source( $src );
		}
	} );

	// Classic menus.
	foreach ( (array) wp_get_nav_menus() as $menu ) {
		$key   = 'menu:' . (int) $menu->term_id;
		$items = wp_get_nav_menu_items( $menu->term_id, array( 'update_post_term_cache' => false ) );
		$any   = false;
		foreach ( (array) $items as $item ) {
			$pre = null;
			if ( 'post_type' === $item->type && $item->object_id ) {
				$p = get_post( (int) $item->object_id );
				if ( $p && 'publish' !== $p->post_status ) {
					/* translators: 1: page title, 2: status */
					$pre = array( 's' => 'broken', 'e' => sprintf( __( 'Menu item links to “%1$s”, which is not published (%2$s).', 'ekwa' ), get_the_title( $p ), $p->post_status ) );
				}
			}
			$any = $add( (string) $item->url, 'link', wp_strip_all_tags( (string) $item->title ), __( 'menu item', 'ekwa' ), $key, home_url( '/' ), $pre ) || $any;
		}
		if ( $any ) {
			$sources[ $key ] = array(
				'key'       => $key,
				'group'     => 'menu',
				'type'      => 'nav_menu',
				'typeLabel' => __( 'Menu', 'ekwa' ),
				'title'     => (string) $menu->name,
				'status'    => 'publish',
				'edit'      => admin_url( 'nav-menus.php?menu=' . (int) $menu->term_id ),
				'view'      => '',
				'file'      => false,
			);
		}
	}

	// Theme settings that hold links.
	$settings = array(
		'key'       => 'setting:ekwa',
		'group'     => 'setting',
		'type'      => 'setting',
		'typeLabel' => __( 'Ekwa Settings', 'ekwa' ),
		'title'     => __( 'Appointment & social links', 'ekwa' ),
		'status'    => 'publish',
		'edit'      => admin_url( 'themes.php?page=ekwa-settings' ),
		'view'      => '',
		'file'      => false,
	);
	$any = false;
	if ( 'url' === get_option( 'ekwa_appt_type', 'page' ) && '' !== (string) get_option( 'ekwa_appt_url', '' ) ) {
		$any = $add( (string) get_option( 'ekwa_appt_url', '' ), 'link', __( 'Appointment link', 'ekwa' ), __( 'setting', 'ekwa' ), $settings['key'], home_url( '/' ) ) || $any;
	} elseif ( 'page' === get_option( 'ekwa_appt_type', 'page' ) && absint( get_option( 'ekwa_appt_page', 0 ) ) ) {
		$p   = get_post( absint( get_option( 'ekwa_appt_page', 0 ) ) );
		$pre = ( ! $p || 'publish' !== $p->post_status ) ? array( 's' => 'broken', 'e' => __( 'The appointment page chosen in Ekwa Settings is missing or not published — every appointment button is broken.', 'ekwa' ) ) : null;
		$any = $add( $p ? (string) get_permalink( $p ) : home_url( '/?page_id=' . absint( get_option( 'ekwa_appt_page', 0 ) ) ), 'link', __( 'Appointment link', 'ekwa' ), __( 'setting', 'ekwa' ), $settings['key'], home_url( '/' ), $pre ) || $any;
	}
	$social = get_option( 'ekwa_social', array() );
	foreach ( is_array( $social ) ? $social : array() as $row ) {
		if ( is_array( $row ) && ! empty( $row['link'] ) && is_string( $row['link'] ) ) {
			$any = $add( $row['link'], 'link', isset( $row['name'] ) ? (string) $row['name'] : __( 'Social link', 'ekwa' ), __( 'social link', 'ekwa' ), $settings['key'], home_url( '/' ) ) || $any;
		}
	}
	if ( $any ) {
		$sources[ $settings['key'] ] = $settings;
	}

	return array(
		'links'   => array_values( $links ),
		'sources' => $sources,
	);
}

/**
 * Stored results (tolerates a missing or malformed option).
 *
 * @return array{t:int,r:array}
 */
function ekwa_site_audit_links_stored() {
	$stored = get_option( EKWA_SITE_AUDIT_LINKS_OPTION, array() );
	return array(
		't' => is_array( $stored ) && isset( $stored['t'] ) ? (int) $stored['t'] : 0,
		'r' => is_array( $stored ) && isset( $stored['r'] ) && is_array( $stored['r'] ) ? $stored['r'] : array(),
	);
}

/**
 * GET /site-audit/links — every link, where it is, and the last results.
 *
 * @return WP_REST_Response
 */
function ekwa_site_audit_rest_links() {
	$data   = ekwa_site_audit_links_collect();
	$stored = ekwa_site_audit_links_stored();
	$home   = ekwa_site_audit_links_home();
	$result = array();
	foreach ( $data['links'] as $link ) {
		if ( isset( $stored['r'][ $link['h'] ] ) && is_array( $stored['r'][ $link['h'] ] ) ) {
			$result[ $link['h'] ] = $stored['r'][ $link['h'] ];
		}
	}
	return rest_ensure_response( array(
		'links'    => $data['links'],
		'sources'  => $data['sources'],
		'results'  => (object) $result,
		'checked'  => $stored['t'],
		'homePath' => $home['path'],
	) );
}

/**
 * Check one internal URL without a web request, when that is conclusive.
 *
 * @param string $url
 * @return array|null Result, or null to fall back to HTTP.
 */
function ekwa_site_audit_links_check_internal( $url ) {
	$parts = wp_parse_url( $url );
	$path  = isset( $parts['path'] ) ? rawurldecode( $parts['path'] ) : '/';

	// Uploaded files.
	$uploads = wp_get_upload_dir();
	$up_path = (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
	if ( '' !== $up_path && 0 === strpos( $path, trailingslashit( $up_path ) ) ) {
		$file = trailingslashit( $uploads['basedir'] ) . substr( $path, strlen( trailingslashit( $up_path ) ) );
		if ( false === strpos( $file, '..' ) ) {
			return file_exists( $file )
				? array( 'c' => 200, 's' => 'ok' )
				: array( 'c' => 404, 's' => 'broken', 'e' => __( 'The file is not in the uploads folder (deleted or moved).', 'ekwa' ) );
		}
	}

	// Posts and pages.
	if ( empty( $parts['query'] ) ) {
		$post_id = url_to_postid( $url );
		if ( $post_id ) {
			$p = get_post( $post_id );
			if ( $p && 'publish' === $p->post_status ) {
				$canonical = (string) get_permalink( $p );
				if ( '' !== $canonical && untrailingslashit( strtolower( preg_replace( '#^https?://#', '', $canonical ) ) ) !== untrailingslashit( strtolower( preg_replace( '#^https?://#', '', $url ) ) ) ) {
					return array( 'c' => 301, 's' => 'redirect', 'f' => $canonical );
				}
				return array( 'c' => 200, 's' => 'ok' );
			}
			if ( $p ) {
				/* translators: %s: post status */
				return array( 'c' => 404, 's' => 'broken', 'e' => sprintf( __( 'The page is not published (%s).', 'ekwa' ), $p->post_status ) );
			}
		}
	}
	return null;
}

/**
 * Turn an HTTP outcome into a result.
 *
 * @param string $url      Requested.
 * @param int    $code     Final status (0 = no response).
 * @param string $final    Final URL after redirects.
 * @param int    $redirects
 * @param string $error    Transport error.
 * @return array
 */
function ekwa_site_audit_links_classify( $url, $code, $final, $redirects, $error ) {
	if ( ! $code ) {
		$lower = strtolower( $error );
		if ( false !== strpos( $lower, 'resolve' ) || false !== strpos( $lower, 'could not connect' ) || false !== strpos( $lower, 'connection refused' ) ) {
			return array( 'c' => 0, 's' => 'broken', 'e' => __( 'The site does not exist or does not answer.', 'ekwa' ) . ' (' . ekwa_site_audit_clip( $error, 120 ) . ')' );
		}
		return array( 'c' => 0, 's' => 'warn', 'e' => __( 'Could not check:', 'ekwa' ) . ' ' . ekwa_site_audit_clip( '' !== $error ? $error : __( 'no answer', 'ekwa' ), 140 ) );
	}
	if ( $code >= 200 && $code < 400 ) {
		$a = untrailingslashit( preg_replace( '#^https?://(www\.)?#i', '', strtolower( $url ) ) );
		$b = untrailingslashit( preg_replace( '#^https?://(www\.)?#i', '', strtolower( (string) $final ) ) );
		if ( $redirects && '' !== $final && $a !== $b ) {
			return array( 'c' => $code, 's' => 'redirect', 'f' => $final );
		}
		return array( 'c' => $code, 's' => 'ok' );
	}
	if ( in_array( $code, array( 401, 403, 405, 406, 409, 429, 451, 999 ), true ) ) {
		/* translators: %d: HTTP status */
		return array( 'c' => $code, 's' => 'warn', 'e' => sprintf( __( 'The site refused the automatic check (HTTP %d). Open the link to check it by hand.', 'ekwa' ), $code ) );
	}
	if ( 404 === $code || 410 === $code ) {
		return array( 'c' => $code, 's' => 'broken', 'e' => 404 === $code ? __( 'Page not found (404).', 'ekwa' ) : __( 'Page removed (410).', 'ekwa' ) );
	}
	/* translators: %d: HTTP status */
	return array( 'c' => $code, 's' => 'broken', 'e' => sprintf( __( 'The server answered with an error (HTTP %d).', 'ekwa' ), $code ) );
}

/**
 * Request several URLs at once. HEAD first; GET for the ones a server
 * answered badly (many refuse or mis-answer HEAD).
 *
 * @param string[] $urls
 * @return array<string,array> url => result
 */
function ekwa_site_audit_links_http( $urls ) {
	$out = array();
	if ( ! $urls ) {
		return $out;
	}
	$class   = class_exists( '\WpOrg\Requests\Requests' ) ? '\WpOrg\Requests\Requests' : ( class_exists( 'Requests' ) ? 'Requests' : '' );
	$headers = array(
		'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/*,*/*;q=0.8',
		'Accept-Language' => 'en-US,en;q=0.8',
	);
	$options = array(
		'timeout'          => 12,
		'connect_timeout'  => 8,
		'useragent'        => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36 EkwaLinkCheck/1.0',
		'follow_redirects' => true,
		'redirects'        => 8,
		'verify'           => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
	);
	/** Filter the HTTP options of the link check (timeouts, user agent…). */
	$options = (array) apply_filters( 'ekwa_site_audit_link_check_options', $options );

	$run = static function ( $list, $type ) use ( $class, $headers, $options ) {
		$res = array();
		if ( '' !== $class ) {
			$requests = array();
			foreach ( $list as $i => $u ) {
				$requests[ $i ] = array(
					'url'     => $u,
					'type'    => $type,
					'headers' => $headers,
					'options' => 'GET' === $type ? array( 'max_bytes' => 65536 ) : array(),
				);
			}
			try {
				$responses = call_user_func( array( $class, 'request_multiple' ), $requests, $options );
			} catch ( \Throwable $e ) {
				$responses = array();
			}
			foreach ( $list as $i => $u ) {
				$r = isset( $responses[ $i ] ) ? $responses[ $i ] : null;
				if ( is_object( $r ) && isset( $r->status_code ) ) {
					$res[ $u ] = array( (int) $r->status_code, (string) $r->url, (int) $r->redirects, '' );
				} else {
					$res[ $u ] = array( 0, '', 0, is_object( $r ) && method_exists( $r, 'getMessage' ) ? (string) $r->getMessage() : __( 'no answer', 'ekwa' ) );
				}
			}
			return $res;
		}
		foreach ( $list as $u ) {
			$fn = 'GET' === $type ? 'wp_remote_get' : 'wp_remote_head';
			$r  = $fn( $u, array( 'timeout' => $options['timeout'], 'redirection' => $options['redirects'], 'user-agent' => $options['useragent'], 'headers' => $headers, 'limit_response_size' => 65536 ) );
			if ( is_wp_error( $r ) ) {
				$res[ $u ] = array( 0, '', 0, $r->get_error_message() );
			} else {
				$res[ $u ] = array( (int) wp_remote_retrieve_response_code( $r ), $u, 0, '' );
			}
		}
		return $res;
	};

	$head  = $run( array_values( $urls ), 'HEAD' );
	$again = array();
	foreach ( $head as $u => $r ) {
		if ( 0 === $r[0] || $r[0] >= 400 ) {
			$again[] = $u;
		}
	}
	$get = $again ? $run( $again, 'GET' ) : array();
	foreach ( $head as $u => $r ) {
		if ( isset( $get[ $u ] ) && ( $get[ $u ][0] || ! $r[0] ) ) {
			$r = $get[ $u ];
		}
		$out[ $u ] = ekwa_site_audit_links_classify( $u, $r[0], $r[1], $r[2], $r[3] );
	}
	return $out;
}

/**
 * POST /site-audit/links/check — check up to 12 URLs and save the results.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function ekwa_site_audit_rest_links_check( $request ) {
	$urls = array_slice( array_values( array_unique( array_filter( array_map( 'strval', (array) $request->get_param( 'urls' ) ) ) ) ), 0, 12 );
	$home = ekwa_site_audit_links_home();
	$done = array();
	$http = array();
	$dns  = array();

	foreach ( $urls as $url ) {
		if ( ! preg_match( '#^https?://#i', $url ) || strlen( $url ) > 2000 ) {
			$done[ $url ] = array( 'c' => 0, 's' => 'warn', 'e' => __( 'Not a web address.', 'ekwa' ) );
			continue;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( $host === $home['host'] ) {
			$quick = ekwa_site_audit_links_check_internal( $url );
			if ( $quick ) {
				$done[ $url ] = $quick;
				continue;
			}
		}
		// wp_http_validate_url() also fails when a domain does not resolve — say
		// that plainly (a typo'd or expired domain is a broken link).
		if ( '' !== $host && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
			if ( ! isset( $dns[ $host ] ) ) {
				$dns[ $host ] = gethostbyname( $host ) !== $host;
			}
			if ( ! $dns[ $host ] ) {
				$done[ $url ] = array( 'c' => 0, 's' => 'broken', 'e' => __( 'The domain does not exist (no DNS record) — check the spelling of the address, or the site is gone.', 'ekwa' ) );
				continue;
			}
		}
		if ( ! wp_http_validate_url( $url ) ) {
			$done[ $url ] = array( 'c' => 0, 's' => 'warn', 'e' => __( 'Not checked: the address is on a private network or is not valid.', 'ekwa' ) );
			continue;
		}
		$http[] = $url;
	}
	foreach ( ekwa_site_audit_links_http( $http ) as $url => $res ) {
		$done[ $url ] = $res;
	}

	$now     = time();
	$results = array();
	foreach ( $done as $url => $res ) {
		$res['t'] = $now;
		$results[ ekwa_site_audit_link_hash( $url ) ] = $res;
	}

	// Save (re-read inside the lock: other batches finish at the same time).
	$lock   = ekwa_site_audit_lock( 'links' );
	$stored = ekwa_site_audit_links_stored();
	$keep   = array();
	foreach ( $stored['r'] as $h => $res ) {
		if ( is_array( $res ) && isset( $res['t'] ) && (int) $res['t'] > $now - 90 * DAY_IN_SECONDS ) {
			$keep[ $h ] = $res;
		}
	}
	update_option( EKWA_SITE_AUDIT_LINKS_OPTION, array(
		'v' => 1,
		't' => $now,
		'r' => array_merge( $keep, $results ),
	), false );
	ekwa_site_audit_unlock( $lock );

	return rest_ensure_response( array( 'results' => (object) $results ) );
}
