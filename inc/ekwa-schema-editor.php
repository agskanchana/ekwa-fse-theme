<?php
/**
 * Schema Editor admin page (Appearance → Schema Editor).
 *
 * Edits the JSON-LD *template* rendered by inc/ekwa-schema.php. The preview
 * pane deliberately goes through the same server-side renderer the front end
 * uses (via the ekwa/v1/schema-preview route) rather than re-implementing the
 * tag syntax in JavaScript — otherwise "what you copied" and "what the site
 * outputs" could drift apart, which is the one thing this page must never do.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * Register the page under Appearance, next to Shortcodes.
 * ------------------------------------------------------------------ */
function ekwa_add_schema_editor_page() {
	add_theme_page(
		__( 'Schema Editor', 'ekwa' ),
		__( 'Schema Editor', 'ekwa' ),
		'manage_options',
		'ekwa-schema-editor',
		'ekwa_render_schema_editor_page'
	);
}
add_action( 'admin_menu', 'ekwa_add_schema_editor_page' );

/* ------------------------------------------------------------------
 * Assets.
 * ------------------------------------------------------------------ */
function ekwa_schema_editor_enqueue( $hook ) {
	if ( 'appearance_page_ekwa-schema-editor' !== $hook ) {
		return;
	}

	wp_enqueue_style(
		'ekwa-admin-css',
		get_template_directory_uri() . '/assets/css/ekwa-admin.css',
		array(),
		wp_get_theme()->get( 'Version' )
	);

	// The page data below is attached to the jquery handle, so make sure it is
	// actually on the page rather than relying on another screen to pull it in.
	wp_enqueue_script( 'jquery' );

	// JSON mode gives strings/numbers/keys their colours; the {{…}} overlay is
	// layered on top in the page script. Lint is off: a template using {{#each}}
	// is not valid JSON *as text*, so the linter would cry wolf on every load.
	$editor = wp_enqueue_code_editor(
		array(
			'type'       => 'application/json',
			'codemirror' => array(
				'lint'         => false,
				'gutters'      => array(),
				'lineNumbers'  => true,
				'lineWrapping' => true,
			),
		)
	);

	wp_localize_script(
		'jquery',
		'ekwaSchemaEditor',
		array(
			'codemirror' => $editor ? $editor['codemirror'] : false,
			'previewUrl' => esc_url_raw( rest_url( 'ekwa/v1/schema-preview' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'i18n'       => array(
				'valid'      => __( 'Valid JSON', 'ekwa' ),
				'invalid'    => __( 'Invalid', 'ekwa' ),
				'checking'   => __( 'Rendering…', 'ekwa' ),
				'failed'     => __( 'Preview request failed.', 'ekwa' ),
				'copied'     => __( 'Copied!', 'ekwa' ),
				'copy'       => __( 'Copy schema', 'ekwa' ),
				'confirmReset' => __( 'Replace the template with the theme default? Unsaved changes will be lost.', 'ekwa' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'ekwa_schema_editor_enqueue' );

/* ------------------------------------------------------------------
 * REST: render a template with live site data.
 * ------------------------------------------------------------------ */
function ekwa_schema_editor_register_rest() {
	register_rest_route(
		'ekwa/v1',
		'/schema-preview',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'ekwa_schema_rest_preview',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => array(
				'template' => array( 'required' => true, 'type' => 'string' ),
			),
		)
	);
}
add_action( 'rest_api_init', 'ekwa_schema_editor_register_rest' );

/**
 * REST: render the submitted template against the real settings.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function ekwa_schema_rest_preview( $request ) {
	$result = ekwa_schema_build_json( (string) $request->get_param( 'template' ) );

	return rest_ensure_response(
		array(
			'json'  => $result['json'],
			'error' => $result['error'],
		)
	);
}

/* ------------------------------------------------------------------
 * Save.
 * ------------------------------------------------------------------ */
function ekwa_schema_editor_save() {
	if ( ! isset( $_POST['ekwa_schema_editor_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ekwa_schema_editor_nonce'] ) ), 'ekwa_save_schema_template' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Prefer the base64 copy: some hosts run WAF rules that strip POST fields
	// containing brace-heavy payloads, which would silently blank the template.
	$template = null;
	if ( isset( $_POST['ekwa_schema_template_b64'] ) && '' !== $_POST['ekwa_schema_template_b64'] ) {
		$decoded = base64_decode( wp_unslash( $_POST['ekwa_schema_template_b64'] ), true );
		if ( false !== $decoded ) {
			$template = $decoded;
		}
	}
	if ( null === $template && isset( $_POST['ekwa_schema_template'] ) ) {
		$template = wp_unslash( $_POST['ekwa_schema_template'] );
	}

	if ( null !== $template ) {
		// Strip anything that could turn the JSON-LD block into a script escape.
		$template = str_replace( array( '</script', '<script' ), '', $template );
		update_option( 'ekwa_schema_template', $template );
	}

	update_option( 'ekwa_schema_prune_empty', isset( $_POST['ekwa_schema_prune_empty'] ) ? '1' : '' );

	add_settings_error(
		'ekwa_schema_editor',
		'ekwa_schema_saved',
		__( 'Schema template saved.', 'ekwa' ),
		'success'
	);
	set_transient( 'ekwa_schema_editor_notice_' . get_current_user_id(), 1, 30 );

	wp_safe_redirect( admin_url( 'themes.php?page=ekwa-schema-editor' ) );
	exit;
}
add_action( 'admin_init', 'ekwa_schema_editor_save' );

/* ------------------------------------------------------------------
 * Render.
 * ------------------------------------------------------------------ */
function ekwa_render_schema_editor_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$template  = ekwa_schema_get_template();
	$is_custom = '' !== trim( (string) get_option( 'ekwa_schema_template', '' ) );
	$prune     = get_option( 'ekwa_schema_prune_empty', '1' );
	$groups    = ekwa_schema_template_tags();
	$initial   = ekwa_schema_build_json( $template );

	if ( get_transient( 'ekwa_schema_editor_notice_' . get_current_user_id() ) ) {
		delete_transient( 'ekwa_schema_editor_notice_' . get_current_user_id() );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Schema template saved.', 'ekwa' ) . '</p></div>';
	}
	?>
	<div class="wrap ekwa-schema-wrap">
		<h1>
			<span class="dashicons dashicons-editor-code" aria-hidden="true"></span>
			<?php esc_html_e( 'Schema Editor', 'ekwa' ); ?>
		</h1>
		<p class="description ekwa-schema-intro">
			<?php esc_html_e( 'Edit the JSON-LD template printed in the site head. Values in double braces are filled from Ekwa Settings when the page renders — the preview on the right shows the real markup this site outputs right now.', 'ekwa' ); ?>
		</p>

		<form method="post" action="" id="ekwa-schema-form">
			<?php wp_nonce_field( 'ekwa_save_schema_template', 'ekwa_schema_editor_nonce' ); ?>

			<div class="ekwa-schema-grid">

				<!-- LEFT: template -->
				<div class="ekwa-schema-col">
					<div class="ekwa-schema-col__head">
						<h2><?php esc_html_e( 'Template', 'ekwa' ); ?></h2>
						<span class="ekwa-schema-badge <?php echo $is_custom ? 'is-custom' : 'is-default'; ?>">
							<?php echo $is_custom ? esc_html__( 'Customized', 'ekwa' ) : esc_html__( 'Theme default', 'ekwa' ); ?>
						</span>
					</div>

					<textarea id="ekwa_schema_template" name="ekwa_schema_template" rows="28" class="large-text code" spellcheck="false"><?php echo esc_textarea( $template ); ?></textarea>
					<input type="hidden" id="ekwa_schema_template_b64" name="ekwa_schema_template_b64" value="" />

					<p class="ekwa-schema-actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save template', 'ekwa' ); ?></button>
						<button type="button" class="button" id="ekwa-schema-reset"><?php esc_html_e( 'Reset to theme default', 'ekwa' ); ?></button>
						<label class="ekwa-schema-prune">
							<input type="checkbox" name="ekwa_schema_prune_empty" value="1" <?php checked( $prune, '1' ); ?> />
							<?php esc_html_e( 'Remove properties that have no value', 'ekwa' ); ?>
						</label>
					</p>
					<p class="description">
						<?php esc_html_e( 'With that option on, a tag with nothing behind it drops its property entirely instead of leaving an empty string in the output.', 'ekwa' ); ?>
					</p>
				</div>

				<!-- RIGHT: rendered output -->
				<div class="ekwa-schema-col">
					<div class="ekwa-schema-col__head">
						<h2><?php esc_html_e( 'Output preview', 'ekwa' ); ?></h2>
						<span class="ekwa-schema-status" id="ekwa-schema-status"></span>
					</div>

					<div id="ekwa-schema-error" class="notice notice-error inline ekwa-schema-error" <?php echo '' === $initial['error'] ? 'style="display:none;"' : ''; ?>>
						<p><?php echo esc_html( $initial['error'] ); ?></p>
					</div>

					<pre id="ekwa-schema-preview" class="ekwa-schema-preview"><?php echo esc_html( $initial['json'] ); ?></pre>

					<p class="ekwa-schema-actions">
						<button type="button" class="button button-secondary" id="ekwa-schema-copy"><?php esc_html_e( 'Copy schema', 'ekwa' ); ?></button>
						<a class="button" href="https://search.google.com/test/rich-results" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Rich Results Test', 'ekwa' ); ?></a>
					</p>
					<p class="description">
						<?php esc_html_e( 'Copies the rendered markup with real values — paste it straight into a validator.', 'ekwa' ); ?>
					</p>
				</div>
			</div>

			<!-- Tag reference -->
			<div class="ekwa-schema-tags">
				<h2><?php esc_html_e( 'Available tags', 'ekwa' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Click a tag to insert it at the cursor. Tags coloured orange stand for a whole node and must be written inside quotes — the quotes are replaced along with the tag, which is what keeps the template itself valid JSON.', 'ekwa' ); ?>
				</p>

				<?php foreach ( $groups as $group_key => $group ) : ?>
					<div class="ekwa-schema-taggroup ekwa-schema-taggroup--<?php echo esc_attr( $group_key ); ?>">
						<h3><?php echo esc_html( $group['label'] ); ?></h3>
						<ul>
							<?php foreach ( $group['tags'] as $tag => $desc ) : ?>
								<li>
									<button type="button" class="ekwa-schema-tag" data-tag="<?php echo esc_attr( $tag ); ?>" data-group="<?php echo esc_attr( $group_key ); ?>">
										<?php echo esc_html( '{{' . $tag . '}}' ); ?>
									</button>
									<span class="ekwa-schema-tagdesc"><?php echo esc_html( $desc ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			</div>
		</form>
	</div>

	<script>
	document.addEventListener( 'DOMContentLoaded', function () {
		var cfg = window.ekwaSchemaEditor || {};
		var ta  = document.getElementById( 'ekwa_schema_template' );
		if ( ! ta ) { return; }

		var editor = null;

		// ── Syntax highlighting ────────────────────────────────────────────
		// JSON mode colours the literal JSON; this overlay sits on top and
		// classifies the template syntax, which JSON mode would otherwise
		// render as ordinary string content.
		function tagOverlay() {
			return {
				token: function ( stream ) {
					if ( stream.match( /\{\{[#\/](?:each|if|unless)\b[^}]*\}\}/ ) ) { return 'ekwa-block'; }
					if ( stream.match( /\{\{\s*@[A-Za-z0-9_]+\s*\}\}/ ) )           { return 'ekwa-helper'; }
					if ( stream.match( /\{\{\s*[A-Za-z0-9_.]+\s*\}\}/ ) )           { return 'ekwa-tag'; }
					// Advance to the next candidate brace rather than one char
					// at a time, so long templates don't get slow.
					while ( stream.next() != null && ! stream.match( /\{\{/, false ) ) { /* seek */ }
					return null;
				}
			};
		}

		if ( cfg.codemirror && window.wp && wp.codeEditor ) {
			var inst = wp.codeEditor.initialize( ta, { codemirror: cfg.codemirror } );
			if ( inst && inst.codemirror ) {
				editor = inst.codemirror;
				editor.addOverlay( tagOverlay() );
				editor.setSize( null, 520 );
			}
		}

		function getValue() { return editor ? editor.getValue() : ta.value; }
		function setValue( v ) {
			if ( editor ) { editor.setValue( v ); } else { ta.value = v; }
			schedulePreview();
		}

		// ── Live preview (server-rendered, same code path as the front end) ─
		var statusEl  = document.getElementById( 'ekwa-schema-status' );
		var previewEl = document.getElementById( 'ekwa-schema-preview' );
		var errorEl   = document.getElementById( 'ekwa-schema-error' );
		var timer     = null;
		var inFlight  = 0;

		function setStatus( text, state ) {
			statusEl.textContent = text;
			statusEl.className   = 'ekwa-schema-status is-' + state;
		}

		function preview() {
			var seq = ++inFlight;
			setStatus( cfg.i18n.checking, 'busy' );

			fetch( cfg.previewUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify( { template: getValue() } )
			} ).then( function ( r ) { return r.json(); } ).then( function ( res ) {
				// Ignore a response that a newer keystroke has already superseded.
				if ( seq !== inFlight ) { return; }
				if ( res && res.error ) {
					errorEl.querySelector( 'p' ).textContent = res.error;
					errorEl.style.display = '';
					setStatus( cfg.i18n.invalid, 'bad' );
				} else {
					errorEl.style.display = 'none';
					previewEl.textContent = ( res && res.json ) || '';
					setStatus( cfg.i18n.valid, 'good' );
				}
			} ).catch( function () {
				if ( seq !== inFlight ) { return; }
				setStatus( cfg.i18n.failed, 'bad' );
			} );
		}

		function schedulePreview() {
			clearTimeout( timer );
			timer = setTimeout( preview, 400 );
		}

		if ( editor ) { editor.on( 'change', schedulePreview ); }
		else { ta.addEventListener( 'input', schedulePreview ); }
		preview();

		// ── Insert a tag at the cursor ─────────────────────────────────────
		document.querySelectorAll( '.ekwa-schema-tag' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var tag   = btn.getAttribute( 'data-tag' );
				var group = btn.getAttribute( 'data-group' );
				var text;

				if ( 'blocks' === group && '#' === tag.charAt( 0 ) ) {
					// Insert the matching close tag too — an unbalanced block is
					// the easiest way to end up with output that won't parse.
					var kind = tag.split( ' ' )[0].substring( 1 );
					text = '{{' + tag + '}}\n    \n{{/' + kind + '}}';
				} else if ( 'structural' === group ) {
					text = '"{{' + tag + '}}"';
				} else {
					text = '{{' + tag + '}}';
				}

				if ( editor ) {
					editor.replaceSelection( text );
					editor.focus();
				} else {
					var start = ta.selectionStart, end = ta.selectionEnd;
					ta.value = ta.value.slice( 0, start ) + text + ta.value.slice( end );
					ta.selectionStart = ta.selectionEnd = start + text.length;
					ta.focus();
				}
				schedulePreview();
			} );
		} );

		// ── Copy rendered schema ───────────────────────────────────────────
		var copyBtn = document.getElementById( 'ekwa-schema-copy' );
		copyBtn.addEventListener( 'click', function () {
			var text = previewEl.textContent;
			if ( ! text ) { return; }
			navigator.clipboard.writeText( text ).then( function () {
				copyBtn.textContent = cfg.i18n.copied;
				setTimeout( function () { copyBtn.textContent = cfg.i18n.copy; }, 1600 );
			} );
		} );

		// ── Reset ──────────────────────────────────────────────────────────
		document.getElementById( 'ekwa-schema-reset' ).addEventListener( 'click', function () {
			if ( window.confirm( cfg.i18n.confirmReset ) ) {
				setValue( <?php echo wp_json_encode( ekwa_schema_default_template() ); ?> );
			}
		} );

		// ── Ship a base64 copy so a WAF can't blank the template on save ───
		document.getElementById( 'ekwa-schema-form' ).addEventListener( 'submit', function () {
			if ( editor ) { editor.save(); }
			try {
				var bytes = new TextEncoder().encode( ta.value );
				var bin   = '';
				for ( var i = 0; i < bytes.length; i++ ) { bin += String.fromCharCode( bytes[ i ] ); }
				document.getElementById( 'ekwa_schema_template_b64' ).value = btoa( bin );
			} catch ( e ) { /* fall back to the plain field */ }
		} );
	} );
	</script>
	<?php
}
