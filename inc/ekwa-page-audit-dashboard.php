<?php
/**
 * Page audit — site-wide dashboard (admin menu Site Audit → PageSpeed; the
 * menu was called "Page Speed" before 3.5.0 — same slug and URL).
 *
 * Every published page and post, grouped separately, with PASS/FAIL per
 * device against the team's sitemap-tool criteria (ekwa_page_audit_thresholds())
 * and the Performance, Accessibility, SEO and Best Practices scores, plus
 * charts of where the site stands. Tests run from the browser, one PageSpeed
 * call per page and device through the same /page-audit/run route the editor
 * uses, so each result is saved the moment it lands: closing the tab loses
 * only the tests still in flight, and "Test untested" / "Retest failed" carry
 * on from there.
 *
 * Reading the dashboard writes nothing.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL of the dashboard.
 *
 * @return string
 */
function ekwa_page_audit_dashboard_url() {
	return admin_url( 'admin.php?page=ekwa-page-audit' );
}

/**
 * Capability needed to see the dashboard and run tests from it.
 *
 * @return string
 */
function ekwa_page_audit_dashboard_cap() {
	return (string) apply_filters( 'ekwa_page_audit_dashboard_capability', 'edit_pages' );
}

/**
 * Register the menu page: "Site Audit" (same slug and URL as the old
 * "Page Speed" menu), whose first entry is this dashboard. The other Site
 * Audit screens are added by inc/ekwa-site-audit.php.
 */
function ekwa_page_audit_dashboard_menu() {
	add_menu_page(
		__( 'PageSpeed & Accessibility', 'ekwa' ),
		__( 'Site Audit', 'ekwa' ),
		ekwa_page_audit_dashboard_cap(),
		'ekwa-page-audit',
		'ekwa_page_audit_dashboard_render',
		'dashicons-performance',
		'58.7'
	);
	add_submenu_page(
		'ekwa-page-audit',
		__( 'PageSpeed & Accessibility', 'ekwa' ),
		__( 'PageSpeed', 'ekwa' ),
		ekwa_page_audit_dashboard_cap(),
		'ekwa-page-audit',
		'ekwa_page_audit_dashboard_render'
	);
}
add_action( 'admin_menu', 'ekwa_page_audit_dashboard_menu' );

/**
 * Render the page shell; the app is assets/js/ekwa-page-audit-dashboard.js.
 */
function ekwa_page_audit_dashboard_render() {
	?>
	<div class="wrap ekwa-pad-wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'PageSpeed & Accessibility', 'ekwa' ); ?></h1>
		<div id="ekwa-pad-app" class="ekwa-pad"><p><span class="spinner is-active" style="float:none;"></span> <?php esc_html_e( 'Loading…', 'ekwa' ); ?></p></div>
		<noscript><p><?php esc_html_e( 'This dashboard needs JavaScript.', 'ekwa' ); ?></p></noscript>
	</div>
	<?php
}

/**
 * Assets for the dashboard screen.
 *
 * @param string $hook
 */
function ekwa_page_audit_dashboard_enqueue( $hook ) {
	if ( 'toplevel_page_ekwa-page-audit' !== $hook ) {
		return;
	}
	$js  = 'assets/js/ekwa-page-audit-dashboard.js';
	$css = 'assets/css/ekwa-page-audit-dashboard.css';
	wp_enqueue_script( 'ekwa-page-audit-dashboard', get_template_directory_uri() . '/' . $js, array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ), filemtime( get_template_directory() . '/' . $js ), true );
	wp_enqueue_style( 'wp-components' );
	wp_enqueue_style( 'ekwa-page-audit-dashboard', get_template_directory_uri() . '/' . $css, array( 'wp-components' ), filemtime( get_template_directory() . '/' . $css ) );
	wp_localize_script( 'ekwa-page-audit-dashboard', 'ekwaPaDash', array(
		'thresholds'  => ekwa_page_audit_thresholds(),
		'psiKey'      => '' !== ekwa_page_audit_psi_key(),
		'canManage'   => current_user_can( 'manage_options' ),
		'settingsUrl' => admin_url( 'themes.php?page=ekwa-settings&ekwa_tab=performance#ekwa-page-audit-settings' ),
		'blogPublic'  => '0' !== (string) get_option( 'blog_public', '1' ),
		'contentUrl'  => function_exists( 'ekwa_site_audit_url' ) ? ekwa_site_audit_url( 'content' ) : '',
	) );
}
add_action( 'admin_enqueue_scripts', 'ekwa_page_audit_dashboard_enqueue' );

/**
 * REST: GET /ekwa/v1/page-audit/overview.
 */
function ekwa_page_audit_dashboard_routes() {
	register_rest_route( 'ekwa/v1', '/page-audit/overview', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'ekwa_page_audit_rest_overview',
		'permission_callback' => static function () {
			return current_user_can( ekwa_page_audit_dashboard_cap() );
		},
	) );
}
add_action( 'rest_api_init', 'ekwa_page_audit_dashboard_routes' );

/**
 * Every published, public page and post with its stored summaries.
 *
 * Summaries are read straight from postmeta (one query), so a site with
 * hundreds of posts does not load each post's full reports.
 *
 * @return WP_REST_Response
 */
function ekwa_page_audit_rest_overview() {
	global $wpdb;

	$max   = (int) apply_filters( 'ekwa_page_audit_dashboard_max', 1500 );
	$items = array();
	$all   = array();
	$front = (int) get_option( 'page_on_front' );
	$blog  = (int) get_option( 'page_for_posts' );

	foreach ( array( 'page', 'post' ) as $type ) {
		$ids = get_posts( array(
			'post_type'        => $type,
			'post_status'      => 'publish',
			'has_password'     => false,
			'posts_per_page'   => $max,
			'fields'           => 'ids',
			'orderby'          => 'page' === $type ? array( 'menu_order' => 'ASC', 'title' => 'ASC' ) : array( 'date' => 'DESC' ),
			'no_found_rows'    => true,
			'suppress_filters' => false,
		) );
		foreach ( $ids as $id ) {
			$all[ (int) $id ] = $type;
		}
	}

	$summaries = array();
	$ids       = array_keys( $all );
	if ( $ids ) {
		_prime_post_caches( $ids, false, false );
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in   = implode( ',', array_map( 'intval', $chunk ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ($in)", EKWA_PAGE_AUDIT_META_SUMMARY ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
			foreach ( $rows as $row ) {
				$value = maybe_unserialize( $row->meta_value );
				if ( is_array( $value ) ) {
					$summaries[ (int) $row->post_id ] = $value;
				}
			}
			// Pages tested before summaries existed: build theirs from the report.
			$older = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s) AND post_id IN ($in)", EKWA_PAGE_AUDIT_META_REPORT . 'mobile', EKWA_PAGE_AUDIT_META_REPORT . 'desktop' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $older as $post_id ) {
				$post_id = (int) $post_id;
				$have    = isset( $summaries[ $post_id ] ) ? $summaries[ $post_id ] : array();
				if ( isset( $have['mobile'], $have['desktop'] ) ) {
					continue;
				}
				$summaries[ $post_id ] = array_merge( ekwa_page_audit_summaries( $post_id ), $have );
			}
		}
	}

	// Exclusion overrides (Site Audit: leave a page out / bring it back).
	$overrides = function_exists( 'ekwa_site_audit_meta_map' ) && $ids ? ekwa_site_audit_meta_map( $ids, array( EKWA_SITE_AUDIT_META_EXCLUDE ) ) : array();

	foreach ( $all as $id => $type ) {
		$post = get_post( $id );
		if ( ! $post ) {
			continue;
		}
		$sum     = isset( $summaries[ $id ] ) ? $summaries[ $id ] : array();
		$edit    = get_edit_post_link( $id, 'raw' );
		$state   = function_exists( 'ekwa_site_audit_content_state' ) ? ekwa_site_audit_content_state( $post->post_content ) : null;
		$over    = isset( $overrides[ $id ][ EKWA_SITE_AUDIT_META_EXCLUDE ] ) ? $overrides[ $id ][ EKWA_SITE_AUDIT_META_EXCLUDE ] : '';
		$items[] = array(
			'id'      => $id,
			'type'    => $type,
			'title'   => '' !== get_the_title( $post ) ? html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ) : __( '(no title)', 'ekwa' ),
			'url'     => get_permalink( $post ),
			'edit'    => $edit ? $edit . '&ekwa_pa=1' : '',
			'parent'  => (int) $post->post_parent,
			'front'   => $id === $front,
			'blog'    => $id === $blog,
			'mobile'  => isset( $sum['mobile'] ) && is_array( $sum['mobile'] ) ? $sum['mobile'] : null,
			'desktop' => isset( $sum['desktop'] ) && is_array( $sum['desktop'] ) ? $sum['desktop'] : null,
			// Site Audit: what kind of page (blog / thank-you / policy pages are
			// left out by default), the manual override, and whether it has content.
			'kind'    => function_exists( 'ekwa_site_audit_page_kind' ) ? ekwa_site_audit_page_kind( $post ) : '',
			'exclude' => in_array( $over, array( 'exclude', 'include' ), true ) ? $over : '',
			'empty'   => $state ? $state['empty'] : false,
			'soon'    => $state ? $state['soon'] : false,
		);
	}

	return rest_ensure_response( array(
		'items'      => $items,
		'kinds'      => function_exists( 'ekwa_site_audit_kind_labels' ) ? ekwa_site_audit_kind_labels() : array(),
		'thresholds' => ekwa_page_audit_thresholds(),
		'psiKey'     => '' !== ekwa_page_audit_psi_key(),
		'time'       => time(),
	) );
}
