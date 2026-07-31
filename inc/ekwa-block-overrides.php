<?php
/**
 * Copy an Ekwa block into the active child theme so it can be edited there,
 * safe from parent-theme updates.
 *
 * Pairs with ekwa_block_dir() (inc/ekwa-blocks.php) and the child-first asset
 * resolution in ekwa_inline_read() / the editor-script registration: once a block
 * folder (carrying its block.json) lives in the child theme, that copy wins for
 * block.json, style.css, view.js and the editor script. The parent keeps the
 * dynamic render_callback, so a dynamic block's server output stays parent-owned.
 *
 * Surfaced in Ekwa Settings → General, next to the Starter child theme card.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every overridable Ekwa block: parent block folders that contain a block.json.
 *
 * @return array<string,array{title:string,name:string,version:string}> keyed by slug.
 */
function ekwa_block_overrides_available() {
	static $list = null;
	if ( null !== $list ) {
		return $list;
	}
	$list = array();
	foreach ( glob( get_template_directory() . '/blocks/*/block.json' ) ?: array() as $file ) {
		$slug = basename( dirname( $file ) );
		$data = json_decode( (string) file_get_contents( $file ), true );
		$list[ $slug ] = array(
			'title'   => isset( $data['title'] ) ? (string) $data['title'] : $slug,
			'name'    => isset( $data['name'] ) ? (string) $data['name'] : '',
			'version' => isset( $data['version'] ) ? (string) $data['version'] : '',
		);
	}
	ksort( $list );
	return $list;
}

/**
 * Slugs of blocks currently overridden in the active child theme.
 *
 * @return string[]
 */
function ekwa_block_overrides_in_child() {
	if ( get_stylesheet_directory() === get_template_directory() ) {
		return array();
	}
	$slugs = array();
	foreach ( glob( get_stylesheet_directory() . '/blocks/*/block.json' ) ?: array() as $file ) {
		$slugs[] = basename( dirname( $file ) );
	}
	sort( $slugs );
	return $slugs;
}

/**
 * Read a child override's copy stamp (written at copy time), if present.
 *
 * @param string $slug Block folder name.
 * @return array|null { parent_version, parent_theme, copied_at } or null.
 */
function ekwa_block_override_stamp( $slug ) {
	$file = get_stylesheet_directory() . '/blocks/' . $slug . '/.ekwa-source.json';
	if ( ! is_readable( $file ) ) {
		return null;
	}
	$data = json_decode( (string) file_get_contents( $file ), true );
	return is_array( $data ) ? $data : null;
}

/**
 * Copy the flat files of a block folder from parent → child.
 *
 * @param string $src Absolute parent block dir.
 * @param string $dst Absolute child block dir.
 * @return int|WP_Error Number of files copied, or WP_Error.
 */
function ekwa_block_copy_dir( $src, $dst ) {
	if ( ! is_dir( $src ) ) {
		return new WP_Error( 'no_src', __( 'Source block folder not found.', 'ekwa' ) );
	}
	if ( ! wp_mkdir_p( $dst ) ) {
		return new WP_Error( 'mkdir', __( 'Could not create the block folder in the child theme — check its file permissions.', 'ekwa' ) );
	}
	$count = 0;
	foreach ( glob( $src . '/*' ) ?: array() as $file ) {
		if ( is_file( $file ) && @copy( $file, $dst . '/' . basename( $file ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$count++;
		}
	}
	if ( 0 === $count ) {
		return new WP_Error( 'empty', __( 'Nothing was copied — the block folder was empty or unreadable.', 'ekwa' ) );
	}
	return $count;
}

/**
 * Queue a one-shot admin notice (per user) for after the copy redirect.
 *
 * @param string $type 'success' or 'error'.
 * @param string $message
 */
function ekwa_block_copy_notice( $type, $message ) {
	set_transient(
		'ekwa_block_copy_notice_' . get_current_user_id(),
		array( 'type' => $type, 'message' => $message ),
		60
	);
}

/**
 * Show + clear the queued copy-result notice.
 */
function ekwa_block_copy_show_notice() {
	$key    = 'ekwa_block_copy_notice_' . get_current_user_id();
	$notice = get_transient( $key );
	if ( ! $notice || ! is_array( $notice ) ) {
		return;
	}
	delete_transient( $key );
	$class = ( isset( $notice['type'] ) && 'success' === $notice['type'] ) ? 'notice-success' : 'notice-error';
	printf(
		'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( $class ),
		esc_html( isset( $notice['message'] ) ? $notice['message'] : '' )
	);
}
add_action( 'admin_notices', 'ekwa_block_copy_show_notice' );

/**
 * Handle the "Copy block to child" admin-post action.
 */
function ekwa_copy_block_to_child() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ekwa' ) );
	}
	check_admin_referer( 'ekwa_copy_block_to_child' );

	$slug      = isset( $_REQUEST['ekwa_block'] ) ? sanitize_key( wp_unslash( $_REQUEST['ekwa_block'] ) ) : '';
	$overwrite = ! empty( $_REQUEST['overwrite'] );
	$available = ekwa_block_overrides_available();
	$redirect  = admin_url( 'admin.php?page=ekwa-settings&tab=general' );

	if ( '' === $slug || ! isset( $available[ $slug ] ) ) {
		ekwa_block_copy_notice( 'error', __( 'Unknown block — nothing copied.', 'ekwa' ) );
		wp_safe_redirect( $redirect );
		exit;
	}
	if ( get_template_directory() === get_stylesheet_directory() ) {
		ekwa_block_copy_notice( 'error', __( 'No child theme is active. Activate one first (Starter child theme card).', 'ekwa' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	$src_dir = get_template_directory() . '/blocks/' . $slug;
	$dst_dir = get_stylesheet_directory() . '/blocks/' . $slug;

	if ( file_exists( $dst_dir . '/block.json' ) && ! $overwrite ) {
		/* translators: %s: block slug. */
		ekwa_block_copy_notice( 'error', sprintf( __( '%s is already in your child theme — use “Re-copy” to overwrite it.', 'ekwa' ), $slug ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	$copied = ekwa_block_copy_dir( $src_dir, $dst_dir );
	if ( is_wp_error( $copied ) ) {
		ekwa_block_copy_notice( 'error', $copied->get_error_message() );
		wp_safe_redirect( $redirect );
		exit;
	}

	// Copy the block's primary editor script too, when it exists, so the child
	// controls the editor behavior as well. Shared/utility editor scripts (e.g.
	// ekwa-link-source-control) stay in the parent and still load by handle.
	$editor_rel = 'assets/js/' . $slug . '-editor.js';
	$editor_src = get_template_directory() . '/' . $editor_rel;
	if ( is_readable( $editor_src ) ) {
		$editor_dst_dir = get_stylesheet_directory() . '/assets/js';
		if ( wp_mkdir_p( $editor_dst_dir ) ) {
			@copy( $editor_src, $editor_dst_dir . '/' . $slug . '-editor.js' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	// Stamp the source version so the drift notice can flag parent updates later.
	$stamp = array(
		'parent_version' => (string) $available[ $slug ]['version'],
		'parent_theme'   => (string) wp_get_theme( get_template() )->get( 'Version' ),
		'copied_at'      => gmdate( 'c' ),
	);
	@file_put_contents( $dst_dir . '/.ekwa-source.json', wp_json_encode( $stamp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore

	/* translators: 1: block slug, 2: number of files, 3: block slug. */
	ekwa_block_copy_notice( 'success', sprintf( __( 'Copied %1$s into your child theme (%2$d files). Edit it under blocks/%3$s/ in the child.', 'ekwa' ), $slug, (int) $copied, $slug ) );
	wp_safe_redirect( $redirect );
	exit;
}
add_action( 'admin_post_ekwa_copy_block_to_child', 'ekwa_copy_block_to_child' );

/**
 * Render the "Edit blocks in the child theme" settings card. Called inside the
 * main settings <form> (Ekwa Settings → General), so it triggers the copy via a
 * nonced link — a nested <form> would be invalid HTML (mirrors the child-theme
 * card in inc/ekwa-child-generator.php).
 */
function ekwa_block_overrides_card() {
	$is_child     = get_template_directory() !== get_stylesheet_directory();
	$blocks       = ekwa_block_overrides_available();
	$in_child     = ekwa_block_overrides_in_child();
	$ai_available = function_exists( 'ekwa_get_ai_api_key' ) && ekwa_get_ai_api_key();

	$copy_url = wp_nonce_url(
		add_query_arg( array( 'action' => 'ekwa_copy_block_to_child' ), admin_url( 'admin-post.php' ) ),
		'ekwa_copy_block_to_child'
	);
	?>
	<div class="ekwa-section">
		<h2><?php esc_html_e( 'Edit blocks in the child theme', 'ekwa' ); ?></h2>
		<p class="description" style="margin-bottom:1em;">
			<?php esc_html_e( 'Copy an Ekwa block into the active child theme to customize it — its block.json (attributes), style.css, view.js and editor script. The child copy overrides the parent and survives theme updates. A dynamic block’s server-side PHP output stays in the parent.', 'ekwa' ); ?>
		</p>
		<?php if ( ! $is_child ) : ?>
			<p><em><?php esc_html_e( 'Activate a child theme first (see “Starter child theme” above) — overrides live in the child theme.', 'ekwa' ); ?></em></p>
		<?php else : ?>
			<table class="form-table">
				<tr>
					<th><label for="ekwa-copy-block"><?php esc_html_e( 'Block', 'ekwa' ); ?></label></th>
					<td>
						<select id="ekwa-copy-block" class="regular-text">
							<?php foreach ( $blocks as $slug => $b ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $b['title'] . ' — ' . ( '' !== $b['name'] ? $b['name'] : $slug ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<a id="ekwa-copy-block-btn" href="<?php echo esc_url( $copy_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Copy to child', 'ekwa' ); ?></a>
						<p class="description"><?php esc_html_e( 'Then edit the files under your child theme’s blocks/<name>/ folder. Keep the block’s “name” in block.json unchanged.', 'ekwa' ); ?></p>
					</td>
				</tr>
			</table>

			<?php if ( ! empty( $in_child ) ) : ?>
				<h4 style="margin:1em 0 .35em;"><?php esc_html_e( 'Already overridden in your child theme', 'ekwa' ); ?></h4>
				<table class="widefat striped" style="max-width:660px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Block', 'ekwa' ); ?></th>
							<th><?php esc_html_e( 'Copied from', 'ekwa' ); ?></th>
							<th><?php esc_html_e( 'Parent now', 'ekwa' ); ?></th>
							<th><?php esc_html_e( 'Action', 'ekwa' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $in_child as $slug ) :
							$stamp  = ekwa_block_override_stamp( $slug );
							$from   = ( $stamp && ! empty( $stamp['parent_version'] ) ) ? (string) $stamp['parent_version'] : '';
							$now    = isset( $blocks[ $slug ]['version'] ) ? (string) $blocks[ $slug ]['version'] : '';
							$drift  = ( '' !== $from && '' !== $now && version_compare( $from, $now, '<' ) );
							$re_url = wp_nonce_url(
								add_query_arg(
									array(
										'action'     => 'ekwa_copy_block_to_child',
										'ekwa_block' => $slug,
										'overwrite'  => 1,
									),
									admin_url( 'admin-post.php' )
								),
								'ekwa_copy_block_to_child'
							);
							?>
							<tr>
								<td><code><?php echo esc_html( $slug ); ?></code></td>
								<td><?php echo esc_html( '' !== $from ? $from : '—' ); ?></td>
								<td>
									<?php echo esc_html( '' !== $now ? $now : '—' ); ?>
									<?php if ( $drift ) : ?><span title="<?php esc_attr_e( 'Parent is newer than your copy', 'ekwa' ); ?>" style="color:#b32d2e;font-weight:700;">&nbsp;&#9679;</span><?php endif; ?>
								</td>
								<td>
									<?php if ( $ai_available ) : ?>
										<button type="button" class="button button-small ekwa-ai-edit-block" data-slug="<?php echo esc_attr( $slug ); ?>"><?php esc_html_e( 'Edit with AI', 'ekwa' ); ?></button>
									<?php endif; ?>
									<a href="<?php echo esc_url( $re_url ); ?>" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Overwrite the child copy with the current parent version? Your edits to this block in the child theme will be lost.', 'ekwa' ) ); ?>');"><?php esc_html_e( 'Re-copy', 'ekwa' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'A red dot means the parent block has a newer version than your copy. Re-copy to pull in the parent’s changes — this overwrites your edits to that block.', 'ekwa' ); ?></p>
				<?php if ( $ai_available ) : ?>
					<p class="description"><?php esc_html_e( '“Edit with AI” lets you describe a change and have Gemini rewrite that block’s files (block.json, style.css, view.js, editor script) — preview each file, then Apply (auto-backed-up) or Revert.', 'ekwa' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>

			<script>
			( function () {
				var sel = document.getElementById( 'ekwa-copy-block' );
				var btn = document.getElementById( 'ekwa-copy-block-btn' );
				if ( ! sel || ! btn ) { return; }
				function sync() {
					var u = new URL( btn.href, window.location.origin );
					u.searchParams.set( 'ekwa_block', sel.value );
					btn.href = u.toString();
				}
				sel.addEventListener( 'change', sync );
				sync();
			} )();
			</script>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Admin notice when the parent theme ships a newer version of a block the site
 * overrode in its child theme — a prompt to review and re-sync. Shown only where
 * it's actionable (Ekwa Settings, Themes, Dashboard).
 */
function ekwa_block_overrides_drift_notice() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}
	if ( get_template_directory() === get_stylesheet_directory() ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen ) {
		return;
	}
	$on_settings = false !== strpos( (string) $screen->id, 'ekwa-settings' );
	if ( ! $on_settings && ! in_array( $screen->base, array( 'themes', 'dashboard' ), true ) ) {
		return;
	}

	$available = ekwa_block_overrides_available();
	$drifted   = array();
	foreach ( ekwa_block_overrides_in_child() as $slug ) {
		$stamp = ekwa_block_override_stamp( $slug );
		if ( ! $stamp || empty( $stamp['parent_version'] ) ) {
			continue;
		}
		$now = isset( $available[ $slug ]['version'] ) ? (string) $available[ $slug ]['version'] : '';
		if ( '' !== $now && version_compare( (string) $stamp['parent_version'], $now, '<' ) ) {
			$drifted[] = $slug . ' (' . $stamp['parent_version'] . ' → ' . $now . ')';
		}
	}
	if ( empty( $drifted ) ) {
		return;
	}

	$url = admin_url( 'admin.php?page=ekwa-settings&tab=general' );
	echo '<div class="notice notice-warning is-dismissible"><p>';
	printf(
		/* translators: 1: comma-separated block list, 2: settings URL. */
		wp_kses_post( __( '<strong>Ekwa blocks:</strong> the parent theme has newer versions of blocks overridden in your child theme: %1$s. <a href="%2$s">Review &amp; re-copy</a> to pick up the parent’s changes.', 'ekwa' ) ),
		esc_html( implode( ', ', $drifted ) ),
		esc_url( $url )
	);
	echo '</p></div>';
}
add_action( 'admin_notices', 'ekwa_block_overrides_drift_notice' );
