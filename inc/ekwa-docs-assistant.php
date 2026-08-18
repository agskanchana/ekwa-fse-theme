<?php
/**
 * Documentation links + the in-admin "Ask AI" documentation assistant.
 *
 * The published docs site (Astro/Starlight) exposes every page's raw markdown
 * at /docs-index.json — the same corpus its own Ask AI box uses. This brings
 * that assistant into the theme so an admin never has to leave WordPress, with
 * two differences that matter:
 *
 *   - The key is the theme's already-configured Gemini key (Ekwa Settings → AI).
 *     Nobody pastes a key into a browser, and the call happens server-side, so
 *     the key is never exposed to the page.
 *   - It goes through the theme's AI governance (ekwa_ai_rest_permission):
 *     minimum role, payload ceiling, per-user daily limit and usage accounting,
 *     exactly like every other AI feature here.
 *
 * The corpus is fetched from the live docs site and cached in a transient, so
 * the answers track whatever is published without the theme shipping — or
 * going stale with — its own copy of the documentation.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** How long a fetched docs corpus stays cached. */
if ( ! defined( 'EKWA_DOCS_CACHE_TTL' ) ) {
	define( 'EKWA_DOCS_CACHE_TTL', 12 * HOUR_IN_SECONDS );
}

/** Transient holding the fetched corpus. */
const EKWA_DOCS_CORPUS_TRANSIENT = 'ekwa_docs_corpus';

/**
 * Base URL of the published documentation, no trailing slash.
 *
 * Filterable so a fork or an internally-hosted mirror can repoint both the
 * links and the assistant's corpus in one place.
 *
 * @return string
 */
function ekwa_docs_base_url() {
	$url = defined( 'EKWA_DOCS_URL' ) && EKWA_DOCS_URL
		? EKWA_DOCS_URL
		: 'https://agskanchana.github.io/ekwa-fse-theme-docs';

	/**
	 * Filter the documentation base URL.
	 *
	 * @param string $url Base URL without a trailing slash.
	 */
	return untrailingslashit( (string) apply_filters( 'ekwa_docs_url', $url ) );
}

/**
 * Build a link to a documentation page.
 *
 * @param string $path Page path, e.g. 'blocks/lightbox'. Empty for the home page.
 * @return string
 */
function ekwa_docs_link( $path = '' ) {
	$path = trim( (string) $path, '/' );
	return ekwa_docs_base_url() . '/' . ( '' === $path ? '' : $path . '/' );
}

/**
 * Fetch the documentation corpus, cached.
 *
 * @param bool $force Skip the cache and re-fetch.
 * @return array|WP_Error List of { id, title, body }, or an error.
 */
function ekwa_docs_corpus( $force = false ) {
	if ( ! $force ) {
		$cached = get_transient( EKWA_DOCS_CORPUS_TRANSIENT );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}
	}

	$url      = ekwa_docs_base_url() . '/docs-index.json';
	$response = wp_remote_get( $url, array( 'timeout' => 20 ) );

	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'ekwa_docs_unreachable',
			sprintf(
				/* translators: 1: docs URL, 2: underlying error message. */
				__( 'Could not reach the documentation at %1$s — %2$s', 'ekwa' ),
				$url,
				$response->get_error_message()
			)
		);
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		return new WP_Error(
			'ekwa_docs_http_error',
			sprintf(
				/* translators: 1: HTTP status code, 2: docs URL. */
				__( 'The documentation site returned HTTP %1$d for %2$s.', 'ekwa' ),
				$code,
				$url
			)
		);
	}

	$pages = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $pages ) || empty( $pages ) ) {
		return new WP_Error(
			'ekwa_docs_bad_payload',
			__( 'The documentation index could not be read. It may still be building.', 'ekwa' )
		);
	}

	set_transient( EKWA_DOCS_CORPUS_TRANSIENT, $pages, EKWA_DOCS_CACHE_TTL );

	return $pages;
}

/**
 * Flatten the corpus into the text handed to the model.
 *
 * Each page keeps its id so the model can name the page an answer came from,
 * which is what makes an answer checkable rather than something to take on
 * trust.
 *
 * @param array $pages Corpus entries.
 * @return string
 */
function ekwa_docs_corpus_text( $pages ) {
	$out = array();
	foreach ( $pages as $page ) {
		if ( empty( $page['body'] ) ) {
			continue;
		}
		$title = isset( $page['title'] ) ? $page['title'] : '';
		$id    = isset( $page['id'] ) ? $page['id'] : '';
		$out[] = '## ' . $title . ' (page: ' . $id . ")\n" . $page['body'];
	}
	return implode( "\n\n", $out );
}

/**
 * Register the Ask-AI route.
 */
function ekwa_docs_register_routes() {
	register_rest_route( 'ekwa/v1', '/ask-docs', array(
		'methods'             => WP_REST_Server::CREATABLE,
		// Same gate as every other AI feature: role, payload size, daily limit.
		'permission_callback' => 'ekwa_ai_rest_permission',
		'callback'            => 'ekwa_docs_rest_ask',
		'args'                => array(
			'question' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'history'  => array(
				'required' => false,
				'type'     => 'array',
			),
			'refresh'  => array(
				'required' => false,
				'type'     => 'boolean',
			),
		),
	) );
}
add_action( 'rest_api_init', 'ekwa_docs_register_routes' );

/**
 * REST: answer a question from the documentation.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function ekwa_docs_rest_ask( $request ) {
	$question = trim( (string) $request->get_param( 'question' ) );
	if ( '' === $question ) {
		return rest_ensure_response( array(
			'ok'      => false,
			'message' => __( 'Ask a question first.', 'ekwa' ),
		) );
	}

	$api_key = function_exists( 'ekwa_get_ai_api_key' ) ? ekwa_get_ai_api_key() : false;
	if ( ! $api_key ) {
		return rest_ensure_response( array(
			'ok'      => false,
			'message' => __( 'No Gemini API key is configured. Add one under Ekwa Settings → AI.', 'ekwa' ),
		) );
	}

	$pages = ekwa_docs_corpus( (bool) $request->get_param( 'refresh' ) );
	if ( is_wp_error( $pages ) ) {
		return rest_ensure_response( array(
			'ok'      => false,
			'message' => $pages->get_error_message(),
		) );
	}

	// Carry a few turns of context so follow-ups ("and for a video?") work, but
	// keep it bounded — the corpus already dominates the prompt.
	$contents = array();
	$history  = $request->get_param( 'history' );
	if ( is_array( $history ) ) {
		foreach ( array_slice( $history, -6 ) as $turn ) {
			if ( empty( $turn['text'] ) ) {
				continue;
			}
			$role       = ( isset( $turn['role'] ) && 'ai' === $turn['role'] ) ? 'model' : 'user';
			$contents[] = array(
				'role'  => $role,
				'parts' => array( array( 'text' => sanitize_textarea_field( $turn['text'] ) ) ),
			);
		}
	}
	$contents[] = array(
		'role'  => 'user',
		'parts' => array( array( 'text' => $question ) ),
	);

	$system = "You are the documentation assistant for the Ekwa FSE WordPress theme, answering inside the WordPress admin.\n"
		. "Answer from the documentation below. Be concise and practical, and prefer the concrete steps or option names an admin would actually click.\n"
		. "Name the relevant documentation page when it helps the reader verify you (the page id is in each heading).\n"
		. "Never invent option names, block attributes or settings that do not appear in the documentation — a confidently wrong answer about a setting wastes more of the reader's time than an honest gap.\n"
		. "But do not stop at 'the docs don't cover this'. When there is no direct answer, say so in one line and then give the reader the most useful thing you do have: the closest related feature, the page that gets them nearest, or the general mechanism the theme uses for that kind of thing. A bare refusal is the least useful answer you can give.\n\n"
		. "DOCUMENTATION:\n\n" . ekwa_docs_corpus_text( $pages );

	ekwa_ai_current_feature( 'ask-docs' );
	// thinkingBudget 0: this is retrieval from a supplied corpus, not reasoning,
	// and the thinking step otherwise eats the output budget — spending the
	// whole allowance before writing any answer. Pinned to the Flash tier for
	// that reason: the Pro models don't let thinking be switched off.
	$result = ekwa_ai_generate_call_gemini( $system, $contents, 0.2, $api_key, ekwa_ai_fast_model(), 4096, 0 );

	if ( is_wp_error( $result ) ) {
		return rest_ensure_response( array(
			'ok'      => false,
			'message' => $result->get_error_message(),
		) );
	}

	// The shared caller returns array( content, tokens, finish_reason ) — not a
	// bare string. Handing the array straight to the client rendered it as
	// "[object Object]".
	$answer    = is_array( $result ) ? (string) ( isset( $result['content'] ) ? $result['content'] : '' ) : (string) $result;
	$truncated = is_array( $result ) && isset( $result['finish_reason'] ) && 'MAX_TOKENS' === $result['finish_reason'];

	if ( '' === trim( $answer ) ) {
		return rest_ensure_response( array(
			'ok'      => false,
			'message' => __( 'The model returned an empty answer. Try rephrasing the question.', 'ekwa' ),
		) );
	}

	return rest_ensure_response( array(
		'ok'        => true,
		'answer'    => $answer,
		'truncated' => $truncated,
		'pages'     => count( $pages ),
	) );
}

/**
 * The documentation pages worth a one-click shortcut from the admin.
 *
 * @return array<string, string> path => label.
 */
function ekwa_docs_quick_links() {
	return apply_filters( 'ekwa_docs_quick_links', array(
		'quickstart-mockup' => __( 'Quick Start: Convert a Mockup', 'ekwa' ),
		'converter'         => __( 'The Mockup Converter', 'ekwa' ),
		'authoring-kit'     => __( 'Mockup Authoring Kit', 'ekwa' ),
		'blocks/overview'   => __( 'Block Reference', 'ekwa' ),
		'blocks/layout'     => __( 'Ekwa Div (layout)', 'ekwa' ),
		'blocks/lightbox'   => __( 'Lightbox & Galleries', 'ekwa' ),
		'design/design-setup' => __( 'Design Setup (tokens)', 'ekwa' ),
		'settings'          => __( 'Settings Reference', 'ekwa' ),
		'performance'       => __( 'Performance', 'ekwa' ),
		'ai/governance'     => __( 'AI Governance & API Key', 'ekwa' ),
	) );
}

/* ------------------------------------------------------------------
 * Appearance → Help & Ask AI.
 *
 * This used to be a tab on the settings page. It's a page of its own now:
 * nothing here saves settings, so it never belonged inside that form, and a
 * top-level entry makes the docs reachable without hunting through tabs.
 * ------------------------------------------------------------------ */
function ekwa_add_help_page() {
	add_theme_page(
		__( 'Help & Ask AI', 'ekwa' ),
		__( 'Help & Ask AI', 'ekwa' ),
		'manage_options',
		'ekwa-help',
		'ekwa_render_help_page'
	);
}
add_action( 'admin_menu', 'ekwa_add_help_page' );

/**
 * Assets for the standalone Help page.
 *
 * The Ask AI script is inline in ekwa_render_help_tab() and reads its endpoint
 * off `ekwaAdmin`, which is normally localized onto ekwa-admin-js — a handle
 * that is only enqueued on the settings screen. Provide the same global here
 * with just the keys that script touches.
 *
 * @param string $hook
 */
function ekwa_help_page_enqueue( $hook ) {
	if ( 'appearance_page_ekwa-help' !== $hook ) {
		return;
	}

	wp_enqueue_style(
		'ekwa-admin-css',
		get_template_directory_uri() . '/assets/css/ekwa-admin.css',
		array(),
		wp_get_theme()->get( 'Version' )
	);

	// ekwaAdmin rides on the jquery handle here, so ensure it is enqueued.
	wp_enqueue_script( 'jquery' );

	wp_localize_script(
		'jquery',
		'ekwaAdmin',
		array(
			'askDocsUrl'     => esc_url_raw( rest_url( 'ekwa/v1/ask-docs' ) ),
			'webpRestNonce'  => wp_create_nonce( 'wp_rest' ),
			'askDocsStrings' => array(
				'thinking'  => __( 'Thinking…', 'ekwa' ),
				'error'     => __( 'Request failed. Check the connection and try again.', 'ekwa' ),
				'truncated' => __( 'That answer was cut off at the length limit — ask something narrower for the full answer.', 'ekwa' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'ekwa_help_page_enqueue' );

/**
 * Render the standalone Help & Ask AI page.
 */
function ekwa_render_help_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap ekwa-settings-wrap">
		<h1>
			<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
			<?php esc_html_e( 'Help & Ask AI', 'ekwa' ); ?>
		</h1>
		<?php ekwa_render_help_tab(); ?>
	</div>
	<?php
}

/**
 * Render the Help & Ask AI panes.
 *
 * Deliberately contains no <form>: pressing Enter in the question box must
 * never submit anything.
 */
function ekwa_render_help_tab() {
	$has_key = function_exists( 'ekwa_get_ai_api_key' ) && ekwa_get_ai_api_key();
	?>
	<div class="ekwa-section">
		<h2><?php esc_html_e( 'Documentation', 'ekwa' ); ?></h2>
		<p class="description" style="margin-bottom:1em;">
			<?php esc_html_e( 'Full documentation for the theme — mockup conversion, every block, the AI tools, design tokens and performance.', 'ekwa' ); ?>
		</p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( ekwa_docs_link() ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Open the documentation', 'ekwa' ); ?>
			</a>
		</p>
		<ul class="ekwa-docs-links">
			<?php foreach ( ekwa_docs_quick_links() as $path => $label ) : ?>
				<li>
					<a href="<?php echo esc_url( ekwa_docs_link( $path ) ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( $label ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>

	<div class="ekwa-section">
		<h2><?php esc_html_e( 'Ask AI', 'ekwa' ); ?></h2>
		<p class="description" style="margin-bottom:1em;">
			<?php esc_html_e( 'Ask anything about the theme. Answers come from the documentation above, using the Gemini key already configured under the AI tab — nothing extra to set up, and the key never leaves the server.', 'ekwa' ); ?>
		</p>

		<?php if ( ! $has_key ) : ?>
			<div class="notice notice-warning inline" style="margin:0 0 1em;">
				<p>
					<?php
					printf(
						/* translators: %s: link to the AI tab. */
						esc_html__( 'No Gemini API key is configured, so Ask AI is unavailable. Add one under %s.', 'ekwa' ),
						'<a href="' . esc_url( admin_url( 'themes.php?page=ekwa-settings&ekwa_tab=ai' ) ) . '">'
							. esc_html__( 'Ekwa Settings → AI', 'ekwa' ) . '</a>'
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<div id="ekwa-ask" class="ekwa-ask">
			<div id="ekwa-ask-thread" class="ekwa-ask__thread" role="log" aria-live="polite" aria-label="<?php esc_attr_e( 'Ask AI conversation', 'ekwa' ); ?>">
				<p class="ekwa-ask__empty">
					<?php esc_html_e( 'Try: “How do I group images into one lightbox gallery?” or “What does the hero flag on an image do?”', 'ekwa' ); ?>
				</p>
			</div>
			<div class="ekwa-ask__form">
				<label class="screen-reader-text" for="ekwa-ask-q"><?php esc_html_e( 'Your question', 'ekwa' ); ?></label>
				<input type="text" id="ekwa-ask-q" class="regular-text"
					placeholder="<?php esc_attr_e( 'Ask a question about the theme…', 'ekwa' ); ?>"
					autocomplete="off"<?php echo $has_key ? '' : ' disabled'; ?> />
				<button type="button" class="button button-primary" id="ekwa-ask-send"<?php echo $has_key ? '' : ' disabled'; ?>>
					<?php esc_html_e( 'Ask', 'ekwa' ); ?>
				</button>
			</div>
			<p class="description ekwa-ask__foot">
				<?php esc_html_e( 'Answers are generated and can be wrong — each one names the documentation page it came from so you can check it. Counts toward the AI usage limits on the AI tab.', 'ekwa' ); ?>
			</p>
		</div>
	</div>

	<script>
	// Deferred to DOMContentLoaded because `ekwaAdmin` is localized onto
	// ekwa-admin-js, which WordPress prints in the FOOTER — at body-parse time
	// it does not exist yet, and the guard below would bail before binding
	// anything, leaving the Ask button inert.
	document.addEventListener( 'DOMContentLoaded', function () {
		var input  = document.getElementById( 'ekwa-ask-q' );
		var send   = document.getElementById( 'ekwa-ask-send' );
		var thread = document.getElementById( 'ekwa-ask-thread' );
		if ( ! input || ! send || ! thread || typeof ekwaAdmin === 'undefined' ) { return; }

		var history = [];
		var busy    = false;

		function esc( s ) {
			return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
		}

		// Escape first, then re-introduce the small subset of markdown the model
		// actually emits. Order matters: escaping after would eat the tags.
		function format( text ) {
			return esc( text )
				.replace( /```([\s\S]*?)```/g, function ( m, code ) { return '<pre><code>' + code.trim() + '</code></pre>'; } )
				.replace( /`([^`\n]+)`/g, '<code>$1</code>' )
				.replace( /\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>' )
				.replace( /\n/g, '<br>' );
		}

		function add( role, text, isHtml ) {
			var empty = thread.querySelector( '.ekwa-ask__empty' );
			if ( empty ) { empty.remove(); }
			var d = document.createElement( 'div' );
			d.className = 'ekwa-ask__msg ekwa-ask__msg--' + role;
			if ( isHtml ) { d.innerHTML = text; } else { d.textContent = text; }
			thread.appendChild( d );
			thread.scrollTop = thread.scrollHeight;
			return d;
		}

		function ask() {
			if ( busy ) { return; }
			var q = input.value.trim();
			if ( ! q ) { return; }

			busy = true;
			send.disabled = true;
			input.value = '';
			add( 'user', q, false );
			history.push( { role: 'user', text: q } );
			var pending = add( 'ai', ekwaAdmin.askDocsStrings.thinking, false );

			fetch( ekwaAdmin.askDocsUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': ekwaAdmin.webpRestNonce
				},
				body: JSON.stringify( { question: q, history: history.slice( 0, -1 ) } )
			} ).then( function ( r ) { return r.json(); } ).then( function ( res ) {
				pending.remove();
				// Guard on the TYPE, not just truthiness: an unexpected payload
				// shape (an object where a string was assumed) rendered as
				// "[object Object]" in the bubble instead of failing loudly.
				if ( res && res.ok && typeof res.answer === 'string' && res.answer ) {
					add( 'ai', format( res.answer ), true );
					history.push( { role: 'ai', text: res.answer } );
					if ( res.truncated ) {
						add( 'error', ekwaAdmin.askDocsStrings.truncated, false );
					}
				} else {
					var raw = ( res && ( res.message || res.code ) ) || '';
					var msg = ( typeof raw === 'string' && raw ) ? raw : ekwaAdmin.askDocsStrings.error;
					add( 'error', msg, false );
				}
			} ).catch( function () {
				pending.remove();
				add( 'error', ekwaAdmin.askDocsStrings.error, false );
			} ).then( function () {
				busy = false;
				send.disabled = false;
				input.focus();
			} );
		}

		send.addEventListener( 'click', ask );
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				// No enclosing form to submit, but stop it bubbling anyway so this
				// can't start saving settings if the markup is ever moved.
				e.preventDefault();
				ask();
			}
		} );
	} );
	</script>
	<?php
}

/**
 * Drop the cached corpus whenever the docs URL changes, so a repointed site
 * doesn't keep answering from the old documentation.
 */
function ekwa_docs_flush_corpus_cache() {
	delete_transient( EKWA_DOCS_CORPUS_TRANSIENT );
}
add_action( 'update_option_ekwa_docs_url', 'ekwa_docs_flush_corpus_cache' );
