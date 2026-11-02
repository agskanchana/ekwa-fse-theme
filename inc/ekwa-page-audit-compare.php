<?php
/**
 * Page audit — side-by-side compare of a stylesheet fix on the real page.
 *
 * "Compare" on a stylesheet fix opens a full-screen admin view with two live
 * copies of the page: without the fix and with it. Both are the real front
 * end; the only difference is the stylesheet text WordPress prints, swapped
 * in memory for that request:
 *
 *   - a fix not applied yet: left = the stylesheet now, right = with the fix
 *     applied in memory (nothing is saved — this is how to judge it first);
 *   - an applied fix: left = the stylesheet with the fix reverted in memory,
 *     right = the stylesheet now.
 *
 * The preview requests carry a nonce bound to the user, page and fix, are
 * only honoured for a user who can edit that page, and are never cached.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL of the compare screen.
 *
 * @param array $args post+strategy+fix, or change (a logged change entry id).
 * @return string
 */
function ekwa_page_audit_compare_url( $args ) {
	return add_query_arg( array_merge( array( 'page' => 'ekwa-page-audit-compare' ), (array) $args ), admin_url( 'admin.php' ) );
}

/**
 * Register the (hidden) compare screen under the dashboard menu.
 */
function ekwa_page_audit_compare_menu() {
	add_submenu_page( 'ekwa-page-audit', __( 'Compare fix', 'ekwa' ), __( 'Compare fix', 'ekwa' ), 'edit_posts', 'ekwa-page-audit-compare', 'ekwa_page_audit_compare_render' );
}
add_action( 'admin_menu', 'ekwa_page_audit_compare_menu', 20 );

/**
 * Keep the compare screen out of the menu (it is opened from a fix).
 */
function ekwa_page_audit_compare_hide_menu() {
	remove_submenu_page( 'ekwa-page-audit', 'ekwa-page-audit-compare' );
}
add_action( 'admin_head', 'ekwa_page_audit_compare_hide_menu' );

/**
 * Resolve what to compare.
 *
 * @param int    $post_id
 * @param string $strategy
 * @param string $fix_id
 * @param string $change_id
 * @return array|WP_Error {post_id, fix, entry, variants}
 */
function ekwa_page_audit_compare_resolve( $post_id, $strategy, $fix_id, $change_id ) {
	$entry = null;
	$fix   = null;
	if ( '' !== $change_id ) {
		foreach ( ekwa_page_audit_changes() as $e ) {
			if ( isset( $e['id'] ) && $e['id'] === $change_id ) {
				$entry = $e;
			}
		}
		if ( ! $entry || 'css' !== ( isset( $entry['type'] ) ? $entry['type'] : '' ) ) {
			return new WP_Error( 'ekwa_page_audit_compare', __( 'That stylesheet change is no longer in the log.', 'ekwa' ) );
		}
		$post_id = (int) $entry['post_id'];
		$fix     = array(
			'id'    => (string) $entry['fix_id'],
			'title' => (string) $entry['title'],
			'kind'  => 'css',
			'css'   => $entry['css'],
		);
		if ( ! empty( $entry['undone'] ) ) {
			$entry = null; // Show what it would do if applied again.
		}
	} else {
		$fix = ekwa_page_audit_find_fix( $post_id, $strategy, $fix_id );
		if ( ! $fix || 'css' !== ( isset( $fix['kind'] ) ? $fix['kind'] : '' ) ) {
			return new WP_Error( 'ekwa_page_audit_compare', __( 'That stylesheet fix is not in the latest analysis any more.', 'ekwa' ) );
		}
		$entry = ekwa_page_audit_change_for_fix( $fix_id );
	}

	$variants = ekwa_page_audit_css_variants( $fix, $entry );
	if ( is_wp_error( $variants ) ) {
		return $variants;
	}
	return array(
		'post_id'  => (int) $post_id,
		'fix'      => $fix,
		'entry'    => $entry,
		'variants' => $variants,
	);
}

/**
 * Nonce action for one compare preview.
 *
 * @param int    $post_id
 * @param string $fix_id
 * @param string $change_id
 * @return string
 */
function ekwa_page_audit_compare_nonce_action( $post_id, $fix_id, $change_id ) {
	return 'ekwa_pa_preview|' . (int) $post_id . '|' . $fix_id . '|' . $change_id;
}

// ═══════════════════════════════════════════════════════════════════════════════
// FRONT END: the two previews
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Swap the printed stylesheet for a signed compare preview request.
 */
function ekwa_page_audit_preview_init() {
	if ( is_admin() || empty( $_GET['ekwa_pa_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$side      = 'after' === $_GET['ekwa_pa_preview'] ? 'after' : 'before';
	$post_id   = isset( $_GET['ekwa_pa_post'] ) ? absint( $_GET['ekwa_pa_post'] ) : 0;
	$strategy  = isset( $_GET['ekwa_pa_strategy'] ) ? sanitize_key( wp_unslash( $_GET['ekwa_pa_strategy'] ) ) : 'mobile';
	$fix_id    = isset( $_GET['ekwa_pa_fix'] ) ? sanitize_text_field( wp_unslash( $_GET['ekwa_pa_fix'] ) ) : '';
	$change_id = isset( $_GET['ekwa_pa_change'] ) ? sanitize_text_field( wp_unslash( $_GET['ekwa_pa_change'] ) ) : '';
	$nonce     = isset( $_GET['ekwa_pa_n'] ) ? sanitize_text_field( wp_unslash( $_GET['ekwa_pa_n'] ) ) : '';
	// phpcs:enable

	if ( ! is_user_logged_in() || ! $post_id || ! current_user_can( 'edit_post', $post_id )
		|| ! wp_verify_nonce( $nonce, ekwa_page_audit_compare_nonce_action( $post_id, $fix_id, $change_id ) ) ) {
		return;
	}
	$resolved = ekwa_page_audit_compare_resolve( $post_id, $strategy, $fix_id, $change_id );
	if ( is_wp_error( $resolved ) ) {
		return;
	}

	foreach ( $resolved['variants'][ $side ] as $option => $text ) {
		add_filter(
			'pre_option_' . $option,
			static function () use ( $text ) {
				return $text;
			},
			99
		);
	}

	add_filter( 'show_admin_bar', '__return_false' );
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	add_action( 'wp', static function () {
		do_action( 'litespeed_control_set_nocache', 'ekwa page audit compare preview' );
	} );
	add_action( 'wp_head', static function () {
		echo '<meta name="robots" content="noindex, nofollow">' . "\n";
	}, 1 );
}
add_action( 'init', 'ekwa_page_audit_preview_init', 20 );

// ═══════════════════════════════════════════════════════════════════════════════
// ADMIN: the compare screen
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Render the compare screen.
 */
function ekwa_page_audit_compare_render() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen.
	$post_id   = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
	$strategy  = isset( $_GET['strategy'] ) ? sanitize_key( wp_unslash( $_GET['strategy'] ) ) : 'mobile';
	$fix_id    = isset( $_GET['fix'] ) ? sanitize_text_field( wp_unslash( $_GET['fix'] ) ) : '';
	$change_id = isset( $_GET['change'] ) ? sanitize_text_field( wp_unslash( $_GET['change'] ) ) : '';
	// phpcs:enable

	$resolved = ekwa_page_audit_compare_resolve( $post_id, $strategy, $fix_id, $change_id );
	echo '<div class="wrap">';
	if ( is_wp_error( $resolved ) ) {
		echo '<h1>' . esc_html__( 'Compare fix', 'ekwa' ) . '</h1><div class="notice notice-error"><p>' . esc_html( $resolved->get_error_message() ) . '</p></div></div>';
		return;
	}
	$post_id = $resolved['post_id'];
	$post    = get_post( $post_id );
	if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
		echo '<h1>' . esc_html__( 'Compare fix', 'ekwa' ) . '</h1><div class="notice notice-error"><p>' . esc_html__( 'You cannot edit this page.', 'ekwa' ) . '</p></div></div>';
		return;
	}

	$nonce = wp_create_nonce( ekwa_page_audit_compare_nonce_action( $post_id, $fix_id, $change_id ) );
	$base  = array(
		'ekwa_pa_post'     => $post_id,
		'ekwa_pa_strategy' => $strategy,
		'ekwa_pa_fix'      => $fix_id,
		'ekwa_pa_change'   => $change_id,
		'ekwa_pa_n'        => $nonce,
	);
	$link      = get_permalink( $post );
	$before    = add_query_arg( array_merge( $base, array( 'ekwa_pa_preview' => 'before' ) ), $link );
	$after     = add_query_arg( array_merge( $base, array( 'ekwa_pa_preview' => 'after' ) ), $link );
	$selectors = array();
	foreach ( isset( $resolved['fix']['css']['edits'] ) ? (array) $resolved['fix']['css']['edits'] : array() as $edit ) {
		$selectors[] = (string) $edit['selector'];
	}
	$applied = ! empty( $resolved['variants']['applied'] );

	$rel = 'assets/js/ekwa-page-audit-compare.js';
	wp_enqueue_script( 'ekwa-page-audit-compare', get_template_directory_uri() . '/' . $rel, array(), filemtime( get_template_directory() . '/' . $rel ), true );
	wp_localize_script( 'ekwa-page-audit-compare', 'ekwaPaCompare', array(
		'selectors' => $selectors,
		'i18n'      => array(
			'loading'  => __( 'Loading…', 'ekwa' ),
			'notFound' => __( 'The changed elements are not on this page at this width.', 'ekwa' ),
		),
	) );
	?>
	<style>
		#wpcontent { padding-left: 0; }
		.ekwa-cmp { position: fixed; inset: 32px 0 0 0; z-index: 9990; display: flex; flex-direction: column; background: #f0f0f1; }
		@media screen and (max-width: 782px) { .ekwa-cmp { inset: 46px 0 0 0; } }
		.ekwa-cmp__bar { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; padding: 10px 16px; background: #fff; border-bottom: 1px solid #dcdcde; }
		.ekwa-cmp__title { flex: 1 1 320px; min-width: 0; }
		.ekwa-cmp__title h1 { font-size: 16px; line-height: 1.3; margin: 0; padding: 0; }
		.ekwa-cmp__title p { margin: 2px 0 0; color: #50575e; font-size: 12px; overflow-wrap: anywhere; }
		.ekwa-cmp__tools { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
		.ekwa-cmp__seg { display: inline-flex; border: 1px solid #8c8f94; border-radius: 4px; overflow: hidden; }
		.ekwa-cmp__seg button { border: 0; background: #fff; padding: 5px 10px; cursor: pointer; font-size: 13px; }
		.ekwa-cmp__seg button[aria-pressed="true"] { background: #2271b1; color: #fff; }
		.ekwa-cmp__seg button + button { border-left: 1px solid #8c8f94; }
		.ekwa-cmp__panes { flex: 1; display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 12px; min-height: 0; }
		.ekwa-cmp__pane { display: flex; flex-direction: column; min-height: 0; background: #fff; border: 1px solid #dcdcde; border-radius: 6px; overflow: hidden; }
		.ekwa-cmp__label { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 6px 10px; font-weight: 600; font-size: 13px; border-bottom: 1px solid #dcdcde; }
		.ekwa-cmp__label .is-before { color: #8a2424; }
		.ekwa-cmp__label .is-after { color: #00663d; }
		.ekwa-cmp__label a { font-weight: 400; font-size: 12px; }
		.ekwa-cmp__frame { position: relative; flex: 1; min-height: 0; overflow: hidden; background: #f6f7f7; }
		.ekwa-cmp__frame iframe { position: absolute; top: 0; left: 0; border: 0; background: #fff; transform-origin: 0 0; }
		.ekwa-cmp__note { color: #50575e; font-size: 12px; }
		@media (max-width: 900px) { .ekwa-cmp__panes { grid-template-columns: 1fr; grid-auto-rows: minmax(320px, 1fr); overflow: auto; } }
	</style>
	<div class="ekwa-cmp" id="ekwa-cmp">
		<div class="ekwa-cmp__bar">
			<div class="ekwa-cmp__title">
				<h1><?php echo esc_html( sprintf( /* translators: %s: fix title */ __( 'Compare: %s', 'ekwa' ), $resolved['fix']['title'] ) ); ?></h1>
				<p>
					<?php echo esc_html( get_the_title( $post ) ); ?> ·
					<code><?php echo esc_html( ekwa_page_audit_text( ekwa_page_audit_css_describe( $resolved['fix']['css'] ), 240 ) ); ?></code>
					· <?php echo $applied ? esc_html__( 'Applied — the left side shows the page with the fix reverted.', 'ekwa' ) : esc_html__( 'Not applied yet — the right side previews it; nothing is saved.', 'ekwa' ); ?>
				</p>
			</div>
			<div class="ekwa-cmp__tools">
				<div class="ekwa-cmp__seg" role="group" aria-label="<?php esc_attr_e( 'Width', 'ekwa' ); ?>">
					<button type="button" data-width="1366" aria-pressed="false"><?php esc_html_e( 'Desktop', 'ekwa' ); ?></button>
					<button type="button" data-width="820" aria-pressed="false"><?php esc_html_e( 'Tablet', 'ekwa' ); ?></button>
					<button type="button" data-width="390" aria-pressed="false"><?php esc_html_e( 'Mobile', 'ekwa' ); ?></button>
				</div>
				<label><input type="checkbox" id="ekwa-cmp-sync" checked> <?php esc_html_e( 'Scroll together', 'ekwa' ); ?></label>
				<label><input type="checkbox" id="ekwa-cmp-hl" checked> <?php esc_html_e( 'Outline changed elements', 'ekwa' ); ?></label>
				<button type="button" class="button" id="ekwa-cmp-jump"><?php esc_html_e( 'Go to changed element', 'ekwa' ); ?></button>
				<span class="ekwa-cmp__note" id="ekwa-cmp-msg" aria-live="polite"></span>
				<a class="button" href="<?php echo esc_url( get_edit_post_link( $post_id, 'raw' ) . '&ekwa_pa=1' ); ?>"><?php esc_html_e( 'Back to the page', 'ekwa' ); ?></a>
			</div>
		</div>
		<div class="ekwa-cmp__panes">
			<div class="ekwa-cmp__pane">
				<div class="ekwa-cmp__label"><span class="is-before"><?php esc_html_e( 'Without the fix', 'ekwa' ); ?></span><a href="<?php echo esc_url( $before ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'ekwa' ); ?></a></div>
				<div class="ekwa-cmp__frame"><iframe title="<?php esc_attr_e( 'Page without the fix', 'ekwa' ); ?>" data-side="before" src="<?php echo esc_url( $before ); ?>"></iframe></div>
			</div>
			<div class="ekwa-cmp__pane">
				<div class="ekwa-cmp__label"><span class="is-after"><?php esc_html_e( 'With the fix', 'ekwa' ); ?></span><a href="<?php echo esc_url( $after ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'ekwa' ); ?></a></div>
				<div class="ekwa-cmp__frame"><iframe title="<?php esc_attr_e( 'Page with the fix', 'ekwa' ); ?>" data-side="after" src="<?php echo esc_url( $after ); ?>"></iframe></div>
			</div>
		</div>
	</div>
	</div>
	<?php
}
