<?php
/**
 * Edit a copied child-theme block with AI.
 *
 * A block that was copied into the active child theme (see inc/ekwa-block-overrides.php)
 * can be edited in place with Gemini: describe a change and the model rewrites the
 * block's own files — block.json, style.css, view.js and its editor script. The
 * flow is always preview → confirm → write, and every apply is backed up so a
 * single click reverts it. The parent theme's PHP render_callback is never touched.
 *
 * Reuses the shared AI plumbing (inc/ekwa-ai-generate.php, ekwa-ai-governance.php):
 * key resolution, model list, the Gemini caller, and the role-gate / daily-cap
 * permission. On top of that, every route here also requires edit_theme_options
 * (it writes theme files) and only ever writes to a fixed per-block whitelist.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The (at most four) files that make up a copied block, restricted to those that
 * actually exist in the active child theme. This is the authoritative whitelist:
 * writes and backups only ever touch these paths, recomputed server-side, never
 * from client input.
 *
 * @param string $slug Block folder name (e.g. 'ekwa-button').
 * @return array<string,string> Map of relative path => absolute child path.
 */
function ekwa_ai_edit_block_paths( $slug ) {
	$child = get_stylesheet_directory();
	$map   = array();
	$rels  = array(
		'blocks/' . $slug . '/block.json',
		'blocks/' . $slug . '/style.css',
		'blocks/' . $slug . '/view.js',
		'assets/js/' . $slug . '-editor.js',
	);
	foreach ( $rels as $rel ) {
		$abs = $child . '/' . $rel;
		if ( is_file( $abs ) ) {
			$map[ $rel ] = $abs;
		}
	}
	return $map;
}

/**
 * True when $slug is a block currently overridden in the active child theme
 * (i.e. the child holds blocks/<slug>/block.json).
 *
 * @param string $slug
 * @return bool
 */
function ekwa_ai_edit_block_is_child_override( $slug ) {
	if ( '' === $slug || get_stylesheet_directory() === get_template_directory() ) {
		return false;
	}
	return is_file( get_stylesheet_directory() . '/blocks/' . $slug . '/block.json' );
}

/**
 * REST permission for the AI edit route: must be able to edit theme files AND
 * pass the shared AI role-gate / rate-limit.
 *
 * @return true|WP_Error
 */
function ekwa_ai_edit_block_permission() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'forbidden', __( 'You need permission to edit themes.', 'ekwa' ), array( 'status' => 403 ) );
	}
	if ( function_exists( 'ekwa_ai_rest_permission' ) ) {
		return ekwa_ai_rest_permission();
	}
	return true;
}

/**
 * REST permission for the write/revert routes (no AI call): edit_theme_options only.
 *
 * @return bool
 */
function ekwa_ai_edit_block_write_permission() {
	return current_user_can( 'edit_theme_options' );
}

add_action( 'rest_api_init', 'ekwa_ai_edit_block_routes' );

/**
 * Register the AI block-edit REST routes.
 */
function ekwa_ai_edit_block_routes() {
	register_rest_route( 'ekwa/v1', '/edit-block-ai', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_ai_edit_block_generate',
		'permission_callback' => 'ekwa_ai_edit_block_permission',
		'args'                => array(
			'slug'        => array( 'required' => true, 'type' => 'string' ),
			'instruction' => array( 'required' => true, 'type' => 'string' ),
			'model'       => array( 'required' => false, 'type' => 'string', 'default' => 'gemini-2.5-flash' ),
		),
	) );

	register_rest_route( 'ekwa/v1', '/apply-block-edit', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_ai_edit_block_apply',
		'permission_callback' => 'ekwa_ai_edit_block_write_permission',
		'args'                => array(
			'slug'  => array( 'required' => true, 'type' => 'string' ),
			'files' => array( 'required' => true, 'type' => 'object' ),
		),
	) );

	register_rest_route( 'ekwa/v1', '/revert-block-edit', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_ai_edit_block_revert',
		'permission_callback' => 'ekwa_ai_edit_block_write_permission',
		'args'                => array(
			'slug' => array( 'required' => true, 'type' => 'string' ),
		),
	) );
}

/**
 * POST /ekwa/v1/edit-block-ai — ask Gemini to rewrite the block's files. Returns
 * a preview only (nothing is written).
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_ai_edit_block_generate( $request ) {
	if ( function_exists( 'ekwa_ai_current_feature' ) ) {
		ekwa_ai_current_feature( 'edit-block' );
	}

	$slug        = sanitize_key( (string) $request->get_param( 'slug' ) );
	$instruction = trim( (string) $request->get_param( 'instruction' ) );
	$model       = (string) $request->get_param( 'model' );

	if ( ! ekwa_ai_edit_block_is_child_override( $slug ) ) {
		return new WP_Error( 'not_override', __( 'That block is not copied into the active child theme yet.', 'ekwa' ), array( 'status' => 400 ) );
	}
	if ( '' === $instruction ) {
		return new WP_Error( 'no_instruction', __( 'Describe the change you want.', 'ekwa' ), array( 'status' => 400 ) );
	}
	$allowed_models = function_exists( 'ekwa_ai_generate_allowed_models' ) ? ekwa_ai_generate_allowed_models() : array();
	if ( ! isset( $allowed_models[ $model ] ) ) {
		$model = 'gemini-2.5-flash';
	}

	$api_key = function_exists( 'ekwa_get_ai_api_key' ) ? ekwa_get_ai_api_key() : '';
	if ( ! $api_key ) {
		return new WP_Error( 'no_api_key', __( 'Gemini API key not configured (Ekwa Settings → AI).', 'ekwa' ), array( 'status' => 400 ) );
	}

	$paths = ekwa_ai_edit_block_paths( $slug );
	if ( empty( $paths ) ) {
		return new WP_Error( 'no_files', __( 'No editable files found for this block in the child theme.', 'ekwa' ), array( 'status' => 400 ) );
	}

	// Assemble the current files for the model.
	$current = array();
	$blob    = '';
	foreach ( $paths as $rel => $abs ) {
		$body           = (string) file_get_contents( $abs );
		$current[ $rel ] = $body;
		$blob          .= "<<<EKWA_FILE path=" . $rel . ">>>\n" . $body . "\n<<<EKWA_END>>>\n\n";
	}

	$system = "You edit the SOURCE files of one custom WordPress block that lives in a child theme.\n"
		. "You are given the block's current files, each wrapped as:\n"
		. "<<<EKWA_FILE path=RELATIVE/PATH>>>\n...content...\n<<<EKWA_END>>>\n\n"
		. "The files may include:\n"
		. "- blocks/<name>/block.json  — block metadata: attributes, supports, editorScript handle.\n"
		. "- blocks/<name>/style.css   — the block's front-end + editor CSS.\n"
		. "- blocks/<name>/view.js     — the block's front-end JavaScript (vanilla, runs on the page).\n"
		. "- assets/js/<name>-editor.js— the block's editor script (Gutenberg edit()/save(), uses wp.* globals).\n\n"
		. "RULES:\n"
		. "1. Apply the user's instruction by editing ONLY these files. Return ONLY the files you actually change.\n"
		. "2. Keep block.json valid JSON. NEVER change its \"name\". If you add/rename an attribute, update editor.js and (for static blocks) save() to match.\n"
		. "3. This block's dynamic server output (if any) is rendered by PHP in the PARENT theme and you CANNOT change it. So for server-rendered blocks, focus on CSS, attributes and editor UX; do not expect to change the front-end HTML via these files.\n"
		. "4. view.js and editor.js must stay valid JavaScript. Preserve existing globals/handles and the editorScript handle name.\n"
		. "5. Do not invent new files or new asset references — a new file would not be wired up.\n"
		. "6. Preserve the existing code style. Change as little as possible to satisfy the instruction.\n\n"
		. "OUTPUT FORMAT — return each changed file EXACTLY as, and nothing else (no markdown, no commentary):\n"
		. "<<<EKWA_FILE path=RELATIVE/PATH>>>\n<full new file content>\n<<<EKWA_END>>>";

	$contents = array(
		array(
			'role'  => 'user',
			'parts' => array(
				array( 'text' => "BLOCK: ekwa/" . preg_replace( '/^ekwa-/', '', $slug ) . "\n\nINSTRUCTION:\n" . $instruction . "\n\nCURRENT FILES:\n\n" . $blob ),
			),
		),
	);

	$result = ekwa_ai_generate_call_gemini( $system, $contents, 0.2, $api_key, $model, 32768, null );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$parsed   = ekwa_ai_edit_block_parse( $result['content'] );
	$changed  = array();
	$warnings = array();

	foreach ( $parsed as $rel => $new ) {
		if ( ! isset( $paths[ $rel ] ) ) {
			// Model returned a file outside the whitelist — ignore it.
			$warnings[] = sprintf( __( 'Ignored an out-of-scope file the AI returned: %s', 'ekwa' ), $rel );
			continue;
		}
		$old = isset( $current[ $rel ] ) ? $current[ $rel ] : '';
		if ( trim( $new ) === trim( $old ) ) {
			continue; // no real change
		}
		// Validate block.json before offering it.
		if ( 'block.json' === basename( $rel ) ) {
			$check = ekwa_ai_edit_block_validate_json( $new, $old );
			if ( is_wp_error( $check ) ) {
				$warnings[] = $check->get_error_message();
				continue;
			}
		} elseif ( '' === trim( $new ) && '' !== trim( $old ) ) {
			$warnings[] = sprintf( __( 'Skipped %s — the AI returned it empty.', 'ekwa' ), $rel );
			continue;
		}
		$changed[] = array( 'path' => $rel, 'old' => $old, 'new' => $new );
	}

	if ( isset( $result['finish_reason'] ) && 'MAX_TOKENS' === $result['finish_reason'] ) {
		$warnings[] = __( 'The AI response was cut short (token limit) — review carefully; some files may be incomplete.', 'ekwa' );
	}
	if ( empty( $changed ) && empty( $warnings ) ) {
		$warnings[] = __( 'The AI did not propose any file changes for that instruction.', 'ekwa' );
	}

	return rest_ensure_response( array(
		'slug'     => $slug,
		'changed'  => $changed,
		'warnings' => $warnings,
	) );
}

/**
 * Parse the model's <<<EKWA_FILE path=...>>> ... <<<EKWA_END>>> blocks.
 *
 * @param string $out Raw model output.
 * @return array<string,string> Map of relative path => file content.
 */
function ekwa_ai_edit_block_parse( $out ) {
	$files = array();
	if ( preg_match_all( '/<<<EKWA_FILE\s+path=([^\r\n>]+?)>>>\r?\n(.*?)\r?\n?<<<EKWA_END>>>/s', (string) $out, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $match ) {
			$rel     = trim( $match[1] );
			$content = $match[2];
			// Strip an accidental ```lang fence the model may still wrap content in.
			if ( function_exists( 'ekwa_ai_generate_strip_fences' ) ) {
				$stripped = ekwa_ai_generate_strip_fences( $content );
				// Only accept the fence-strip when it actually removed a fence,
				// so legitimate content isn't trimmed.
				if ( $stripped !== trim( $content ) && false !== strpos( $content, '```' ) ) {
					$content = $stripped;
				}
			}
			$files[ $rel ] = $content;
		}
	}
	return $files;
}

/**
 * Validate a proposed block.json: must be valid JSON and keep the same "name".
 *
 * @param string $new Proposed content.
 * @param string $old Current content.
 * @return true|WP_Error
 */
function ekwa_ai_edit_block_validate_json( $new, $old ) {
	$new_data = json_decode( $new, true );
	if ( ! is_array( $new_data ) ) {
		return new WP_Error( 'bad_json', __( 'Skipped block.json — the AI returned invalid JSON.', 'ekwa' ) );
	}
	$old_data = json_decode( $old, true );
	$old_name = is_array( $old_data ) && isset( $old_data['name'] ) ? $old_data['name'] : null;
	$new_name = isset( $new_data['name'] ) ? $new_data['name'] : null;
	if ( null !== $old_name && $new_name !== $old_name ) {
		return new WP_Error( 'name_changed', __( 'Skipped block.json — the AI changed the block "name" (that would break the block).', 'ekwa' ) );
	}
	return true;
}

/**
 * POST /ekwa/v1/apply-block-edit — back up the block's current files, then write
 * the provided contents. Only paths in the server-side whitelist are written.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_ai_edit_block_apply( $request ) {
	$slug  = sanitize_key( (string) $request->get_param( 'slug' ) );
	$files = (array) $request->get_param( 'files' );

	if ( ! ekwa_ai_edit_block_is_child_override( $slug ) ) {
		return new WP_Error( 'not_override', __( 'That block is not copied into the active child theme.', 'ekwa' ), array( 'status' => 400 ) );
	}
	$allowed = ekwa_ai_edit_block_paths( $slug );
	if ( empty( $allowed ) ) {
		return new WP_Error( 'no_files', __( 'No editable files for this block.', 'ekwa' ), array( 'status' => 400 ) );
	}

	// Keep only whitelisted paths, and validate block.json before touching disk.
	$to_write = array();
	foreach ( $files as $rel => $content ) {
		if ( ! isset( $allowed[ $rel ] ) ) {
			return new WP_Error( 'bad_path', sprintf( __( 'Refused to write an out-of-scope path: %s', 'ekwa' ), (string) $rel ), array( 'status' => 400 ) );
		}
		$content = (string) $content;
		if ( 'block.json' === basename( $rel ) ) {
			$check = ekwa_ai_edit_block_validate_json( $content, (string) file_get_contents( $allowed[ $rel ] ) );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}
		$to_write[ $rel ] = $content;
	}
	if ( empty( $to_write ) ) {
		return new WP_Error( 'nothing', __( 'No changes to apply.', 'ekwa' ), array( 'status' => 400 ) );
	}

	// Back up the CURRENT state of every whitelisted file (so revert restores the
	// full pre-edit picture, not just the files that changed this time).
	$backup = ekwa_ai_edit_block_backup( $slug, $allowed );
	if ( is_wp_error( $backup ) ) {
		return $backup;
	}

	$written = array();
	foreach ( $to_write as $rel => $content ) {
		if ( false !== file_put_contents( $allowed[ $rel ], $content, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			$written[] = $rel;
		}
	}

	return rest_ensure_response( array(
		'applied'    => $written,
		'backed_up'  => true,
		'can_revert' => true,
	) );
}

/**
 * Snapshot the current whitelisted files into blocks/<slug>/.ekwa-ai-backup/ with
 * a manifest, so revert can restore each to its original path.
 *
 * @param string               $slug
 * @param array<string,string> $allowed Map relpath => abs child path.
 * @return true|WP_Error
 */
function ekwa_ai_edit_block_backup( $slug, $allowed ) {
	$dir = get_stylesheet_directory() . '/blocks/' . $slug . '/.ekwa-ai-backup';
	if ( ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'backup_dir', __( 'Could not create the backup folder in the child theme.', 'ekwa' ), array( 'status' => 500 ) );
	}
	$manifest = array( 'saved_at' => gmdate( 'c' ), 'files' => array() );
	foreach ( $allowed as $rel => $abs ) {
		$base = basename( $rel );
		if ( false !== @copy( $abs, $dir . '/' . $base ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$manifest['files'][ $rel ] = $base;
		}
	}
	@file_put_contents( $dir . '/manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore
	return true;
}

/**
 * POST /ekwa/v1/revert-block-edit — restore the block's files from the last
 * backup taken at apply time.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_ai_edit_block_revert( $request ) {
	$slug = sanitize_key( (string) $request->get_param( 'slug' ) );
	if ( ! ekwa_ai_edit_block_is_child_override( $slug ) ) {
		return new WP_Error( 'not_override', __( 'That block is not in the active child theme.', 'ekwa' ), array( 'status' => 400 ) );
	}
	$dir      = get_stylesheet_directory() . '/blocks/' . $slug . '/.ekwa-ai-backup';
	$manifest = $dir . '/manifest.json';
	if ( ! is_file( $manifest ) ) {
		return new WP_Error( 'no_backup', __( 'No AI-edit backup exists for this block yet.', 'ekwa' ), array( 'status' => 400 ) );
	}
	$data    = json_decode( (string) file_get_contents( $manifest ), true );
	$allowed = ekwa_ai_edit_block_paths( $slug );
	$restored = array();
	if ( is_array( $data ) && ! empty( $data['files'] ) ) {
		foreach ( $data['files'] as $rel => $base ) {
			// Restore only to whitelisted destinations.
			if ( ! isset( $allowed[ $rel ] ) ) {
				continue;
			}
			$src = $dir . '/' . basename( (string) $base );
			if ( is_file( $src ) && false !== @copy( $src, $allowed[ $rel ] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$restored[] = $rel;
			}
		}
	}
	if ( empty( $restored ) ) {
		return new WP_Error( 'revert_failed', __( 'Nothing could be restored from the backup.', 'ekwa' ), array( 'status' => 500 ) );
	}
	return rest_ensure_response( array( 'reverted' => $restored ) );
}

/**
 * Enqueue the AI block-editor UI on the Ekwa settings screen only.
 *
 * @param string $hook_suffix
 */
function ekwa_ai_edit_block_enqueue( $hook_suffix ) {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$on_settings = ( $screen && false !== strpos( (string) $screen->id, 'ekwa-settings' ) )
		|| ( is_string( $hook_suffix ) && false !== strpos( $hook_suffix, 'ekwa-settings' ) );
	if ( ! $on_settings || ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}
	if ( ! function_exists( 'ekwa_get_ai_api_key' ) || ! ekwa_get_ai_api_key() ) {
		return; // AI unavailable — the buttons aren't rendered either.
	}

	$rel = 'assets/js/ekwa-ai-edit-block.js';
	wp_enqueue_script(
		'ekwa-ai-edit-block',
		get_theme_file_uri( $rel ),
		array( 'wp-i18n' ),
		filemtime( get_theme_file_path( $rel ) ),
		true
	);

	$models = function_exists( 'ekwa_ai_generate_allowed_models' ) ? ekwa_ai_generate_allowed_models() : array();
	$list   = array();
	foreach ( $models as $id => $label ) {
		$list[] = array( 'value' => $id, 'label' => $label );
	}
	wp_localize_script( 'ekwa-ai-edit-block', 'ekwaAiEditBlock', array(
		'rest'         => esc_url_raw( rest_url( 'ekwa/v1/' ) ),
		'nonce'        => wp_create_nonce( 'wp_rest' ),
		'models'       => $list,
		'defaultModel' => 'gemini-2.5-flash',
	) );
}
add_action( 'admin_enqueue_scripts', 'ekwa_ai_edit_block_enqueue' );
