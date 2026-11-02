<?php
/**
 * MCP connector — the job relay.
 *
 * With the MCP connector on, an AI call does not go to Gemini. The person's own
 * AI app (Claude Code, Codex, Gemini CLI, Claude Desktop / claude.ai, ChatGPT)
 * is connected to this site over MCP, and in MCP that app is the client: the
 * site cannot call it. So the call becomes a JOB the app picks up:
 *
 *   1. The browser sends an AI request as usual, with `X-Ekwa-AI-Relay: <rid>`
 *      (assets/js/ekwa-ai-relay.js adds it).
 *   2. The feature's handler runs unchanged until it reaches
 *      ekwa_ai_generate_call_gemini(), which hands the call to
 *      ekwa_ai_relay_call(). There is no answer yet, so a job is stored and the
 *      request is HALTED with HTTP 202 and the job's details.
 *   3. The AI app reads the job (ekwa_get_job) and answers it
 *      (ekwa_submit_job_result) — inc/ekwa-mcp-server.php.
 *   4. The browser, which has been polling the job, re-sends the SAME request
 *      with the same rid. This time ekwa_ai_relay_call() finds the answer and
 *      returns it in exactly the shape Gemini's would have had, and the handler
 *      finishes as it always has: same parsing, same repair, same response.
 *
 * Jobs are keyed by (user, rid, call number within the request), NOT by a hash
 * of the prompt: a re-sent request does not always build a byte-identical
 * prompt (pattern replication mints random class names, the page census reads
 * a page that may have autosaved, the design vocabulary is a cached transient).
 * A request that makes two AI calls (Build with AI + its self-correct pass)
 * makes job 1, then on the re-send job 2, and so on.
 *
 * Why halt instead of returning a WP_Error: several callers swallow an AI error
 * and carry on — the self-correct pass returns null and the request returns
 * 200, /ai-convert goes on to write the CSS pool, menus and logo, interlinking
 * writes empty keywords, refine and stock suggestions fall back. Halting at the
 * pending call means nothing after it runs until the answer exists.
 *
 * Storage: one non-autoloaded option row per job (`ekwa_ai_job_…`), one per
 * attached image (`ekwa_ai_jobimg_…`) and one heartbeat row the waiting editor
 * keeps fresh (`ekwa_ai_jobhb_…`), all read straight from the database so a
 * long-poll or a second process never sees a stale cached copy. Old rows are
 * cleared lazily. Rows only ever exist because someone clicked an AI button with
 * the MCP connector on.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once get_template_directory() . '/inc/ekwa-ai-provider.php';

const EKWA_AI_RELAY_JOB_PREFIX = 'ekwa_ai_job_';
const EKWA_AI_RELAY_IMG_PREFIX = 'ekwa_ai_jobimg_';
const EKWA_AI_RELAY_HB_PREFIX  = 'ekwa_ai_jobhb_';

/** Seconds without a poll from the editor before a waiting job counts as abandoned. */
const EKWA_AI_RELAY_ABANDON_AFTER = 300;

/** Seconds after which a claimed-but-unanswered job may be taken by another app. */
const EKWA_AI_RELAY_CLAIM_TTL = 600;

/** Largest answer accepted for one job, in bytes. */
const EKWA_AI_RELAY_MAX_RESULT = 5242880;

// ═══════════════════════════════════════════════════════════════════════════════
// THE CHOKE-POINT SIDE
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Answer an AI call from the person's AI app, or queue it and halt.
 *
 * Called by ekwa_ai_generate_call_gemini() when ekwa_ai_relay_applies().
 * Returns what that function returns — `['content','tokens','finish_reason']`
 * or a WP_Error — or does not return at all (the request is halted with 202
 * until the app has answered).
 *
 * @param string     $system_prompt     System instruction.
 * @param array      $contents          Gemini-style contents.
 * @param float      $temperature       Sampling temperature (recorded as a hint).
 * @param string     $model             Gemini model id the feature asked for (quality hint).
 * @param int        $max_output_tokens Recorded as a hint.
 * @param int|null   $thinking_budget   Recorded as a hint.
 * @return array|WP_Error
 */
function ekwa_ai_relay_call( $system_prompt, $contents, $temperature, $model, $max_output_tokens, $thinking_budget ) {
	$user_id = get_current_user_id();
	$rid     = ekwa_ai_relay_request_id();
	$step    = ekwa_ai_relay_next_step();

	$job = ekwa_ai_relay_read( $user_id, $rid, $step );

	if ( $job ) {
		$context = ekwa_ai_relay_context();
		if ( isset( $job['feature'] ) && $job['feature'] !== $context['feature'] ) {
			// The re-sent request took a different path to its AI calls than the
			// first send did. Answers are matched by call number, so say so.
			error_log( sprintf( '[ekwa-ai] relay %s: step %d was queued for "%s" but the re-send asked for "%s".', $rid, $step, $job['feature'], $context['feature'] ) );
		}

		switch ( $job['status'] ) {
			case 'done':
				ekwa_ai_relay_mark_consumed( $job );
				return array(
					'content'       => (string) $job['result'],
					'tokens'        => 0,
					'finish_reason' => 'STOP',
				);

			case 'failed':
				return new WP_Error(
					'ekwa_ai_relay_failed',
					sprintf(
						/* translators: %s: the reason the AI app gave. */
						__( 'Your AI app could not complete this: %s', 'ekwa' ),
						'' !== (string) $job['error'] ? (string) $job['error'] : __( 'no reason given.', 'ekwa' )
					)
				);

			case 'cancelled':
				return new WP_Error( 'ekwa_ai_relay_cancelled', __( 'This AI request was cancelled.', 'ekwa' ) );
		}

		// Still pending or being worked on: tell the browser to keep waiting.
		ekwa_ai_relay_halt( $job );
	}

	ekwa_ai_relay_gc();

	$job = ekwa_ai_relay_create( $user_id, $rid, $step, $system_prompt, $contents, $temperature, $model, $max_output_tokens, $thinking_budget );
	if ( is_wp_error( $job ) ) {
		return $job;
	}

	ekwa_ai_relay_halt( $job );

	// Not reached — ekwa_ai_relay_halt() ends the request.
	return new WP_Error( 'ekwa_ai_relay_pending', __( 'Waiting for your AI app.', 'ekwa' ) );
}

/**
 * Number the AI calls made in this request: 1, 2, 3…
 *
 * @return int
 */
function ekwa_ai_relay_next_step() {
	static $step = 0;
	return ++$step;
}

/**
 * End the request with 202 and the job the browser should wait for.
 *
 * Writes the response itself rather than through wp_send_json(): under the REST
 * API that function raises _doing_it_wrong, and with WP_DEBUG display on the
 * notice would land in front of the JSON.
 *
 * @param array $job
 */
function ekwa_ai_relay_halt( $job ) {
	$payload = array(
		'code'        => 'ekwa_ai_relay_pending',
		'message'     => __( 'Waiting for your AI app to answer through the MCP connector.', 'ekwa' ),
		'data'        => array( 'status' => 202 ),
		'ekwaAiRelay' => ekwa_ai_relay_public_job( $job ),
	);

	if ( ! headers_sent() ) {
		status_header( 202 );
		nocache_headers();
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		header( 'X-Ekwa-AI-Relay-Job: ' . $job['id'] );
	}

	echo wp_json_encode( $payload );
	exit;
}

/**
 * Record that a stored answer was handed back, once per job, and log it.
 *
 * The usage log gets a row (model "mcp:<app>") so the AI tab still shows what
 * ran, but the per-user daily counter is NOT bumped: that cap exists to protect
 * the site's Gemini quota, and these calls cost the site nothing.
 *
 * @param array $job
 */
function ekwa_ai_relay_mark_consumed( $job ) {
	if ( ! empty( $job['consumed'] ) ) {
		return;
	}
	$first = ekwa_ai_relay_mutate( $job, function ( $fresh ) {
		if ( ! empty( $fresh['consumed'] ) ) {
			return array( null, false );
		}
		$fresh['consumed'] = time();
		return array( $fresh, true );
	} );

	if ( true === $first && function_exists( 'ekwa_ai_record_usage' ) ) {
		$client = '' !== (string) $job['client'] ? (string) $job['client'] : 'app';
		ekwa_ai_record_usage( 0, (string) $job['feature'], 'mcp:' . $client, false );
	}
}

// ═══════════════════════════════════════════════════════════════════════════════
// STORAGE
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * The option name of a job.
 *
 * @param int    $user_id
 * @param string $rid
 * @param int    $step
 * @return string
 */
function ekwa_ai_relay_job_option( $user_id, $rid, $step ) {
	return EKWA_AI_RELAY_JOB_PREFIX . (int) $user_id . '_' . $rid . '_' . (int) $step;
}

/**
 * Split a public job id ("<rid>.<step>") into its parts.
 *
 * @param mixed $job_id
 * @return array{rid:string,step:int}|null
 */
function ekwa_ai_relay_parse_id( $job_id ) {
	if ( ! is_string( $job_id ) || ! preg_match( '/^([A-Za-z0-9]{12,40})\.([0-9]{1,3})$/', $job_id, $m ) ) {
		return null;
	}
	return array( 'rid' => $m[1], 'step' => (int) $m[2] );
}

/**
 * Read one option's raw value straight from the database (no object cache).
 *
 * @param string $name
 * @return mixed|null Unserialized value, or null when the row does not exist.
 */
function ekwa_ai_relay_read_raw( $name ) {
	global $wpdb;
	$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
	return null === $value ? null : maybe_unserialize( $value );
}

/**
 * Load a job, fresh from the database.
 *
 * @param int    $user_id
 * @param string $rid
 * @param int    $step
 * @return array|null
 */
function ekwa_ai_relay_read( $user_id, $rid, $step ) {
	$job = ekwa_ai_relay_read_raw( ekwa_ai_relay_job_option( $user_id, $rid, $step ) );
	if ( ! is_array( $job ) || ! isset( $job['id'], $job['user'] ) || (int) $job['user'] !== (int) $user_id ) {
		return null;
	}
	return $job;
}

/**
 * Load one of a user's jobs by its public id.
 *
 * @param int    $user_id
 * @param string $job_id
 * @return array|null
 */
function ekwa_ai_relay_find( $user_id, $job_id ) {
	$parts = ekwa_ai_relay_parse_id( $job_id );
	if ( ! $parts || ! $user_id ) {
		return null;
	}
	return ekwa_ai_relay_read( $user_id, $parts['rid'], $parts['step'] );
}

/**
 * Write a job back.
 *
 * @param array $job
 * @return bool
 */
function ekwa_ai_relay_save( $job ) {
	$job['updated'] = time();
	$name           = ekwa_ai_relay_job_option( $job['user'], $job['rid'], $job['step'] );
	// Compare against the database row, not a copy cached earlier in this request.
	wp_cache_delete( $name, 'options' );
	return update_option( $name, $job, false );
}

/**
 * Delete a job and everything stored with it.
 *
 * @param array $job
 */
function ekwa_ai_relay_delete( $job ) {
	$base = (int) $job['user'] . '_' . $job['rid'] . '_' . (int) $job['step'];
	delete_option( EKWA_AI_RELAY_JOB_PREFIX . $base );
	delete_option( EKWA_AI_RELAY_HB_PREFIX . $base );
	$images = isset( $job['images'] ) ? (int) $job['images'] : 0;
	for ( $n = 1; $n <= $images; $n++ ) {
		delete_option( EKWA_AI_RELAY_IMG_PREFIX . $base . '_' . $n );
	}
}

/**
 * Rows whose option name starts with a prefix, oldest first.
 *
 * @param string $prefix
 * @return array<int,object>
 */
function ekwa_ai_relay_rows( $prefix ) {
	global $wpdb;
	return (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC",
			$wpdb->esc_like( $prefix ) . '%'
		)
	);
}

/**
 * All of a user's jobs, oldest first.
 *
 * @param int $user_id
 * @return array<int,array>
 */
function ekwa_ai_relay_user_jobs( $user_id ) {
	$jobs = array();
	if ( ! $user_id ) {
		return $jobs;
	}
	foreach ( ekwa_ai_relay_rows( EKWA_AI_RELAY_JOB_PREFIX . (int) $user_id . '_' ) as $row ) {
		$job = maybe_unserialize( $row->option_value );
		if ( is_array( $job ) && isset( $job['id'], $job['user'] ) && (int) $job['user'] === (int) $user_id ) {
			$jobs[] = $job;
		}
	}
	return $jobs;
}

/**
 * Jobs an AI app may take now: waiting, or claimed so long ago the app that
 * claimed them has evidently gone away. Never ones the editor stopped waiting for.
 *
 * @param int    $user_id
 * @param string $rid Only this request's jobs; '' for all.
 * @return array<int,array>
 */
function ekwa_ai_relay_open_jobs( $user_id, $rid = '' ) {
	$open = array();
	foreach ( ekwa_ai_relay_user_jobs( $user_id ) as $job ) {
		if ( '' !== $rid && $job['rid'] !== $rid ) {
			continue;
		}
		if ( ekwa_ai_relay_is_abandoned( $job ) ) {
			continue;
		}
		if ( 'pending' === $job['status']
			|| ( 'claimed' === $job['status'] && time() - (int) $job['claimed'] > EKWA_AI_RELAY_CLAIM_TTL ) ) {
			$open[] = $job;
		}
	}
	return $open;
}

/**
 * Create and store a job.
 *
 * @return array|WP_Error The job.
 */
function ekwa_ai_relay_create( $user_id, $rid, $step, $system_prompt, $contents, $temperature, $model, $max_output_tokens, $thinking_budget ) {
	$context = ekwa_ai_relay_context();
	$split   = ekwa_ai_relay_normalize_contents( $contents );
	$images  = $split['images'];

	$tier = '';
	if ( '' !== (string) $model && function_exists( 'ekwa_ai_parse_model_id' ) ) {
		$parsed = ekwa_ai_parse_model_id( (string) $model );
		$tier   = $parsed ? $parsed['tier'] : '';
	}

	$now = time();
	$job = array(
		'v'        => 1,
		'id'       => $rid . '.' . (int) $step,
		'user'     => (int) $user_id,
		'rid'      => $rid,
		'step'     => (int) $step,
		'status'   => 'pending',
		'feature'  => $context['feature'],
		'label'    => $context['label'],
		'route'    => $context['route'],
		'expect'   => $context['expect'],
		'created'  => $now,
		'updated'  => $now,
		'claimed'  => 0,
		'client'   => '',
		'done_at'  => 0,
		'consumed' => 0,
		'images'   => count( $images ),
		'request'  => array(
			'system'      => (string) $system_prompt,
			'turns'       => $split['turns'],
			'temperature' => (float) $temperature,
			'model'       => (string) $model,
			'tier'        => $tier,
			'max_tokens'  => (int) $max_output_tokens,
			'thinking'    => null === $thinking_budget ? null : (int) $thinking_budget,
		),
		'partial'  => '',
		'result'   => '',
		'error'    => '',
	);

	$base = (int) $user_id . '_' . $rid . '_' . (int) $step;

	// Two sends of the same request (a double click, a retry) must not both
	// create — or worse, the loser clean up the winner's rows. Creation happens
	// under the job's lock, and only after checking nobody created it first.
	$lock = ekwa_ai_lock( 'job:' . (int) $user_id . ':' . $rid . ':' . (int) $step );
	if ( '' === $lock ) {
		return new WP_Error( 'ekwa_ai_relay_busy', __( 'The AI job is busy — try again in a moment.', 'ekwa' ) );
	}
	try {
		$existing = ekwa_ai_relay_read( $user_id, $rid, $step );
		if ( $existing ) {
			return $existing;
		}

		// Only rows THIS attempt inserted are removed if it fails part-way.
		$inserted = array();
		$cleanup  = function () use ( &$inserted ) {
			foreach ( $inserted as $name ) {
				delete_option( $name );
			}
		};

		// Pictures first, so a job never becomes visible without the images it refers to.
		foreach ( array_values( $images ) as $i => $image ) {
			$name = EKWA_AI_RELAY_IMG_PREFIX . $base . '_' . ( $i + 1 );
			if ( ! add_option( $name, $image, '', false ) ) {
				$cleanup();
				return new WP_Error(
					'ekwa_ai_relay_store',
					__( 'The AI job could not be saved for your AI app — its images are probably too large for the database. Try fewer or smaller images.', 'ekwa' )
				);
			}
			$inserted[] = $name;
		}

		if ( ! add_option( ekwa_ai_relay_job_option( $user_id, $rid, $step ), $job, '', false ) ) {
			$cleanup();
			return new WP_Error(
				'ekwa_ai_relay_store',
				__( 'The AI job could not be saved for your AI app — the request is probably too large for the database.', 'ekwa' )
			);
		}

		return $job;
	} finally {
		ekwa_ai_unlock( $lock );
	}
}

/**
 * Gemini-style contents → plain turns, with images lifted out.
 *
 * Turns come back as `[{role, parts: [{text} | {image: n, mime}]}]`; images as
 * `[{mime, data}]` (base64), numbered from 1 in order of appearance.
 *
 * @param mixed $contents
 * @return array{turns:array,images:array}
 */
function ekwa_ai_relay_normalize_contents( $contents ) {
	if ( is_string( $contents ) ) {
		$contents = array( array( 'role' => 'user', 'parts' => array( array( 'text' => $contents ) ) ) );
	}

	$turns  = array();
	$images = array();

	foreach ( (array) $contents as $turn ) {
		if ( ! is_array( $turn ) ) {
			continue;
		}
		$role  = isset( $turn['role'] ) && 'model' === $turn['role'] ? 'model' : 'user';
		$parts = array();

		foreach ( isset( $turn['parts'] ) ? (array) $turn['parts'] : array() as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}
			if ( isset( $part['text'] ) ) {
				$parts[] = array( 'text' => (string) $part['text'] );
				continue;
			}
			$inline = null;
			if ( isset( $part['inline_data'] ) && is_array( $part['inline_data'] ) ) {
				$inline = $part['inline_data'];
			} elseif ( isset( $part['inlineData'] ) && is_array( $part['inlineData'] ) ) {
				$inline = $part['inlineData'];
			}
			if ( $inline ) {
				$mime = isset( $inline['mime_type'] ) ? (string) $inline['mime_type'] : ( isset( $inline['mimeType'] ) ? (string) $inline['mimeType'] : '' );
				$data = isset( $inline['data'] ) ? (string) $inline['data'] : '';
				if ( '' !== $mime && '' !== $data ) {
					$images[] = array( 'mime' => $mime, 'data' => $data );
					$parts[]  = array( 'image' => count( $images ), 'mime' => $mime );
				}
			}
		}

		if ( $parts ) {
			$turns[] = array( 'role' => $role, 'parts' => $parts );
		}
	}

	return array( 'turns' => $turns, 'images' => $images );
}

/**
 * A job's images, in order.
 *
 * @param array $job
 * @return array<int,array{mime:string,data:string}>
 */
function ekwa_ai_relay_job_images( $job ) {
	$out    = array();
	$images = isset( $job['images'] ) ? (int) $job['images'] : 0;
	$base   = (int) $job['user'] . '_' . $job['rid'] . '_' . (int) $job['step'];
	for ( $n = 1; $n <= $images; $n++ ) {
		$image = ekwa_ai_relay_read_raw( EKWA_AI_RELAY_IMG_PREFIX . $base . '_' . $n );
		if ( is_array( $image ) && ! empty( $image['data'] ) ) {
			$out[] = array( 'mime' => (string) $image['mime'], 'data' => (string) $image['data'] );
		}
	}
	return $out;
}

/**
 * Which feature is asking, in words, and what shape its answer must have.
 *
 * Read from the request: the feature label the handler set
 * (ekwa_ai_current_feature()), falling back to the route for the handlers that
 * never set one.
 *
 * @return array{feature:string,label:string,route:string,expect:string}
 */
function ekwa_ai_relay_context() {
	$feature = function_exists( 'ekwa_ai_current_feature' ) ? (string) ekwa_ai_current_feature() : 'ai';

	$route = '';
	if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && ! empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
		$route = untrailingslashit( (string) $GLOBALS['wp']->query_vars['rest_route'] );
	} elseif ( wp_doing_ajax() && isset( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- naming the action, not acting on it.
		$route = 'ajax:' . sanitize_key( wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	$labels = array(
		'html'        => __( 'Generate with AI', 'ekwa' ),
		'blocks'      => __( 'Build with AI', 'ekwa' ),
		'blocks-edit' => __( 'Build with AI (edit)', 'ekwa' ),
		'edit-block'  => __( 'Edit with AI', 'ekwa' ),
		'convert'     => __( 'Convert with AI', 'ekwa' ),
		'reconvert'   => __( 'Reconvert with AI', 'ekwa' ),
		'alt-text'    => __( 'Image alt text', 'ekwa' ),
		'ask-docs'    => __( 'Docs assistant', 'ekwa' ),
		'test'        => __( 'Connection test', 'ekwa' ),
		'page-audit'  => __( 'Page speed & accessibility fixes', 'ekwa' ),
	);

	// Handlers that never name themselves, by route — and the ones whose answer
	// is parsed as JSON, so the AI app can be told before it answers.
	$by_route = array(
		'/ekwa/v1/interlink-refine'    => array( 'interlink-refine', __( 'Internal links: refine suggestions', 'ekwa' ) ),
		'/ekwa/v1/dp-suggest-featured' => array( 'stock-suggest', __( 'Stock photo search terms', 'ekwa' ) ),
		'ajax:ekwa_fonts_ai_detect'    => array( 'fonts-detect', __( 'Font detection', 'ekwa' ) ),
	);
	$json_features = array( 'interlink-refine', 'stock-suggest', 'fonts-detect', 'page-audit' );

	if ( ( '' === $feature || 'ai' === $feature ) && isset( $by_route[ $route ] ) ) {
		$feature = $by_route[ $route ][0];
		$label   = $by_route[ $route ][1];
	} elseif ( isset( $labels[ $feature ] ) ) {
		$label = $labels[ $feature ];
	} else {
		$label = ucfirst( str_replace( '-', ' ', '' !== $feature ? $feature : 'ai' ) );
	}

	return array(
		'feature' => '' !== $feature ? $feature : 'ai',
		'label'   => $label,
		'route'   => $route,
		'expect'  => in_array( $feature, $json_features, true ) ? 'json' : '',
	);
}

// ═══════════════════════════════════════════════════════════════════════════════
// STATE CHANGES (editor and AI app)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Last time the waiting editor asked about this job.
 *
 * @param array $job
 * @return int Unix time; the creation time when it has not polled yet.
 */
function ekwa_ai_relay_last_heartbeat( $job ) {
	$base = (int) $job['user'] . '_' . $job['rid'] . '_' . (int) $job['step'];
	$beat = (int) ekwa_ai_relay_read_raw( EKWA_AI_RELAY_HB_PREFIX . $base );
	return max( $beat, (int) $job['created'] );
}

/**
 * Note that the editor is still waiting. Written at most every 10 seconds.
 *
 * Kept out of the job row on purpose: the editor polls while the AI app writes
 * its answer, and two processes rewriting the same row would race.
 *
 * @param array $job
 */
function ekwa_ai_relay_touch_heartbeat( $job ) {
	$now = time();
	if ( $now - ekwa_ai_relay_last_heartbeat( $job ) < 10 ) {
		return;
	}
	$name = EKWA_AI_RELAY_HB_PREFIX . (int) $job['user'] . '_' . $job['rid'] . '_' . (int) $job['step'];
	wp_cache_delete( $name, 'options' );
	update_option( $name, $now, false );
}

/**
 * Has the person stopped waiting for this job (closed the editor, navigated away)?
 *
 * @param array $job
 * @return bool
 */
function ekwa_ai_relay_is_abandoned( $job ) {
	if ( ! in_array( $job['status'], array( 'pending', 'claimed' ), true ) ) {
		return false;
	}
	return time() - ekwa_ai_relay_last_heartbeat( $job ) > EKWA_AI_RELAY_ABANDON_AFTER;
}

/**
 * Change a job atomically: lock it, re-read it from the database, apply the
 * change, save. Every state change goes through here, so the editor (cancel),
 * the AI app (claim, pieces, answer, give up) and the re-sent request (hand the
 * answer back) can never overwrite each other with a stale copy.
 *
 * @param array    $job    Identifies the job (user, rid, step); its data is re-read.
 * @param callable $change Receives the fresh job; returns
 *                         `array( $job_to_save_or_null, $result )` or a WP_Error.
 * @return mixed The callable's $result, or a WP_Error.
 */
function ekwa_ai_relay_mutate( $job, $change ) {
	$lock = ekwa_ai_lock( 'job:' . (int) $job['user'] . ':' . $job['rid'] . ':' . (int) $job['step'] );
	if ( '' === $lock ) {
		return new WP_Error( 'ekwa_ai_relay_busy', __( 'The job is busy — try again in a moment.', 'ekwa' ) );
	}
	try {
		$fresh = ekwa_ai_relay_read( (int) $job['user'], (string) $job['rid'], (int) $job['step'] );
		if ( ! $fresh ) {
			return new WP_Error( 'ekwa_ai_relay_gone', __( 'This job no longer exists — it expired or was cleared.', 'ekwa' ) );
		}
		$out = call_user_func( $change, $fresh );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		if ( is_array( $out[0] ) ) {
			ekwa_ai_relay_save( $out[0] );
		}
		return $out[1];
	} finally {
		ekwa_ai_unlock( $lock );
	}
}

/**
 * Mark a job as being worked on by an AI app. Only an open job can be claimed:
 * a claim never reopens one that was answered, failed or cancelled meanwhile.
 *
 * @param array  $job
 * @param string $client App name/version, for the editor's status line.
 * @return array The job as it now is.
 */
function ekwa_ai_relay_claim( $job, $client ) {
	$out = ekwa_ai_relay_mutate( $job, function ( $fresh ) use ( $client ) {
		if ( ! in_array( $fresh['status'], array( 'pending', 'claimed' ), true ) ) {
			return array( null, $fresh );
		}
		$fresh['status']  = 'claimed';
		$fresh['claimed'] = time();
		if ( '' !== (string) $client ) {
			$fresh['client'] = (string) $client;
		}
		return array( $fresh, $fresh );
	} );
	return is_array( $out ) ? $out : $job;
}

/**
 * Store an answer (or one piece of it) from the AI app.
 *
 * @param array  $job
 * @param string $content
 * @param bool   $done    False to append a piece and wait for more.
 * @param string $client
 * @return array|WP_Error `{state: 'part'|'done', chars: int, job: array}`.
 */
function ekwa_ai_relay_submit( $job, $content, $done, $client = '' ) {
	return ekwa_ai_relay_mutate( $job, function ( $job ) use ( $content, $done, $client ) {
		if ( 'cancelled' === $job['status'] ) {
			return new WP_Error( 'ekwa_ai_relay_closed', __( 'The person cancelled this job in WordPress. Do not resend it.', 'ekwa' ) );
		}
		if ( in_array( $job['status'], array( 'done', 'failed' ), true ) ) {
			return new WP_Error( 'ekwa_ai_relay_closed', __( 'This job is already finished.', 'ekwa' ) );
		}
		if ( ekwa_ai_relay_is_abandoned( $job ) ) {
			return new WP_Error( 'ekwa_ai_relay_abandoned', __( 'The person who asked for this has stopped waiting (the editor was closed or left). Nothing to deliver.', 'ekwa' ) );
		}

		$full = (string) $job['partial'] . (string) $content;
		if ( strlen( $full ) > EKWA_AI_RELAY_MAX_RESULT ) {
			$job['partial'] = '';
			ekwa_ai_relay_save( $job );
			return new WP_Error( 'ekwa_ai_relay_too_large', __( 'That answer is larger than 5 MB, which is more than any feature here can use. Start again with a shorter answer.', 'ekwa' ) );
		}

		if ( '' !== (string) $client ) {
			$job['client'] = (string) $client;
		}

		if ( ! $done ) {
			$job['partial'] = $full;
			if ( 'pending' === $job['status'] ) {
				$job['status']  = 'claimed';
				$job['claimed'] = time();
			}
			return array( $job, array( 'state' => 'part', 'chars' => strlen( $full ), 'job' => $job ) );
		}

		if ( '' === trim( $full ) ) {
			return new WP_Error( 'ekwa_ai_relay_empty', __( 'The answer is empty. Send the output the job asks for, or call ekwa_fail_job with a reason.', 'ekwa' ) );
		}

		if ( 'json' === $job['expect'] ) {
			$json = ekwa_ai_relay_normalize_json( $full );
			if ( null === $json ) {
				$job['partial'] = '';
				ekwa_ai_relay_save( $job );
				return new WP_Error( 'ekwa_ai_relay_bad_json', __( 'This feature reads the answer as JSON, and that is not valid JSON. Send the whole answer again as valid JSON only.', 'ekwa' ) );
			}
			// Store exactly the JSON that parsed: the features decode the answer
			// directly, so prose around it would make them fall back silently.
			$full = $json;
		}

		$job['status']  = 'done';
		$job['result']  = $full;
		$job['partial'] = '';
		$job['done_at'] = time();

		return array( $job, array( 'state' => 'done', 'chars' => strlen( $full ), 'job' => $job ) );
	} );
}

/**
 * The JSON in an answer — the whole answer, or the answer without Markdown
 * fences, or the outermost {…} / […] inside it — or null when none parses.
 *
 * @param string $text
 * @return string|null
 */
function ekwa_ai_relay_normalize_json( $text ) {
	$text = trim( (string) $text );
	$text = trim( (string) preg_replace( '/^```[a-zA-Z]*\s*|\s*```$/', '', $text ) );
	json_decode( $text );
	if ( '' !== $text && JSON_ERROR_NONE === json_last_error() ) {
		return $text;
	}
	$start = strcspn( $text, '{[' );
	$end   = max( (int) strrpos( $text, '}' ), (int) strrpos( $text, ']' ) );
	if ( $start < strlen( $text ) && $end > $start ) {
		$inner = substr( $text, $start, $end - $start + 1 );
		json_decode( $inner );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			return $inner;
		}
	}
	return null;
}

/**
 * Whether an answer contains usable JSON.
 *
 * @param string $text
 * @return bool
 */
function ekwa_ai_relay_looks_like_json( $text ) {
	return null !== ekwa_ai_relay_normalize_json( $text );
}

/**
 * The AI app gives up on a job.
 *
 * @param array  $job
 * @param string $reason Shown to the person in the editor.
 * @param string $client
 * @return array|WP_Error The updated job.
 */
function ekwa_ai_relay_fail( $job, $reason, $client = '' ) {
	$reason = sanitize_textarea_field( (string) $reason );
	if ( strlen( $reason ) > 600 ) {
		$reason = substr( $reason, 0, 600 ) . '…';
	}
	return ekwa_ai_relay_mutate( $job, function ( $fresh ) use ( $reason, $client ) {
		if ( ! in_array( $fresh['status'], array( 'pending', 'claimed' ), true ) ) {
			return new WP_Error( 'ekwa_ai_relay_closed', __( 'This job is already finished.', 'ekwa' ) );
		}
		$fresh['status'] = 'failed';
		$fresh['error']  = '' !== $reason ? $reason : __( 'No reason given.', 'ekwa' );
		if ( '' !== (string) $client ) {
			$fresh['client'] = (string) $client;
		}
		return array( $fresh, $fresh );
	} );
}

/**
 * The person cancels a job from the editor. A job already answered stays answered.
 *
 * @param array $job
 * @return array The job as it now is.
 */
function ekwa_ai_relay_cancel( $job ) {
	$out = ekwa_ai_relay_mutate( $job, function ( $fresh ) {
		if ( ! in_array( $fresh['status'], array( 'pending', 'claimed' ), true ) ) {
			return array( null, $fresh );
		}
		$fresh['status'] = 'cancelled';
		$fresh['error']  = __( 'Cancelled in WordPress.', 'ekwa' );
		return array( $fresh, $fresh );
	} );
	return is_array( $out ) ? $out : $job;
}

/**
 * Clear old jobs. Runs at most every 10 minutes, only while jobs are being made.
 *
 * Works per REQUEST, not per job: a request that is still running (one of its
 * steps is waiting, or anything in it changed in the last 15 minutes) keeps
 * every step — the browser re-sends the request from the start, and an earlier
 * step's answer deleted here would be asked for all over again. Otherwise it
 * removes jobs older than a day, answers handed back more than 15 minutes ago,
 * finished and cancelled jobs after an hour or two, and abandoned ones.
 *
 * @param bool $force Skip the 10-minute throttle.
 */
function ekwa_ai_relay_gc( $force = false ) {
	if ( ! $force && get_transient( 'ekwa_ai_relay_gc' ) ) {
		return;
	}
	set_transient( 'ekwa_ai_relay_gc', 1, 10 * MINUTE_IN_SECONDS );

	$now      = time();
	$requests = array();
	foreach ( ekwa_ai_relay_rows( EKWA_AI_RELAY_JOB_PREFIX ) as $row ) {
		$job = maybe_unserialize( $row->option_value );
		if ( ! is_array( $job ) || ! isset( $job['user'], $job['rid'], $job['step'], $job['created'] ) ) {
			delete_option( $row->option_name );
			continue;
		}
		$requests[ (int) $job['user'] . ':' . $job['rid'] ][] = $job;
	}

	foreach ( $requests as $jobs ) {
		$active = false;
		foreach ( $jobs as $job ) {
			$status = isset( $job['status'] ) ? (string) $job['status'] : '';
			if ( ( in_array( $status, array( 'pending', 'claimed' ), true ) && ! ekwa_ai_relay_is_abandoned( $job ) )
				|| $now - (int) $job['updated'] < 15 * MINUTE_IN_SECONDS ) {
				$active = true;
				break;
			}
		}

		foreach ( $jobs as $job ) {
			$status = isset( $job['status'] ) ? (string) $job['status'] : '';
			if ( $now - (int) $job['created'] > DAY_IN_SECONDS ) {
				ekwa_ai_relay_delete( $job );
				continue;
			}
			if ( $active ) {
				continue;
			}
			$stale = ! empty( $job['consumed'] )
				|| ( in_array( $status, array( 'cancelled', 'failed' ), true ) && $now - (int) $job['updated'] > HOUR_IN_SECONDS )
				|| ( 'done' === $status && $now - (int) $job['done_at'] > 2 * HOUR_IN_SECONDS )
				|| ( in_array( $status, array( 'pending', 'claimed' ), true ) && $now - ekwa_ai_relay_last_heartbeat( $job ) > HOUR_IN_SECONDS );
			if ( $stale ) {
				ekwa_ai_relay_delete( $job );
			}
		}
	}
}

// ═══════════════════════════════════════════════════════════════════════════════
// WHAT THE BROWSER AND THE AI APP SEE
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * A job as the browser sees it: status, never the prompt or the answer.
 *
 * @param array $job
 * @return array
 */
function ekwa_ai_relay_public_job( $job ) {
	$status = (string) $job['status'];
	if ( ekwa_ai_relay_is_abandoned( $job ) ) {
		$status = 'abandoned';
	}
	$user_id = (int) $job['user'];

	return array(
		'id'        => (string) $job['id'],
		'rid'       => (string) $job['rid'],
		'step'      => (int) $job['step'],
		'status'    => $status,
		'feature'   => (string) $job['feature'],
		'label'     => (string) $job['label'],
		'client'    => (string) $job['client'],
		'error'     => (string) $job['error'],
		'created'   => (int) $job['created'],
		'claimed'   => (int) $job['claimed'],
		'images'    => (int) $job['images'],
		'pollUrl'   => rest_url( 'ekwa/v1/ai-relay/job/' . $job['id'] ),
		'hasKey'    => function_exists( 'ekwa_mcp_user_has_key' ) ? ekwa_mcp_user_has_key( $user_id ) : false,
		'canGemini' => (bool) ekwa_get_ai_api_key(),
	);
}

/**
 * The whole job as text for the AI app: what to do, the feature's own
 * instructions, and the conversation.
 *
 * Must come out identical on every call for the same job: the AI app reads it in
 * parts by character offset, so anything that drifts between reads (a relative
 * "2 seconds ago") would shift every later part. Time-dependent details belong
 * in the per-part header the MCP tool adds, never in here.
 *
 * @param array $job
 * @return string
 */
function ekwa_ai_relay_job_text( $job ) {
	$request = isset( $job['request'] ) && is_array( $job['request'] ) ? $job['request'] : array();
	$user    = get_userdata( (int) $job['user'] );
	$who     = $user ? $user->display_name : __( 'someone', 'ekwa' );
	$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$images  = (int) $job['images'];

	$tiers = array(
		'pro'        => 'Quality matters: this feature normally runs on the most capable model.',
		'flash'      => 'A quick task: this feature normally runs on a fast model.',
		'flash-lite' => 'A small task: this feature normally runs on the smallest model.',
	);
	$tier = isset( $request['tier'] ) ? (string) $request['tier'] : '';

	$out  = 'EKWA AI JOB ' . $job['id'] . ' — ' . $job['label'] . "\n";
	$out .= 'Site: ' . $site . ' (' . home_url( '/' ) . ")\n";
	$out .= 'Requested by ' . $who . " from the WordPress editor.\n";
	if ( (int) $job['step'] > 1 ) {
		$out .= 'This is step ' . (int) $job['step'] . ' of one request (' . $job['rid'] . "); the earlier steps are already answered.\n";
	}
	if ( isset( $tiers[ $tier ] ) ) {
		$out .= $tiers[ $tier ] . "\n";
	}

	$out .= "\nHOW TO COMPLETE THIS JOB\n";
	$out .= "You are standing in for the AI model this WordPress feature normally calls, and the person is waiting in the editor for your answer.\n";
	$out .= "1. Read everything below: the TASK RULES (the feature's own instructions to its model), then the CONVERSATION.\n";
	$out .= "2. Write the answer the TASK RULES ask for, in exactly that format. Output only the answer: nothing before or after it, and no Markdown code fences unless the rules ask for them.\n";
	$out .= '3. Deliver it with ekwa_submit_job_result (job_id "' . $job['id'] . "\"). If it is longer than about 40,000 characters, send it in pieces: done=false on every piece except the last.\n";
	$out .= "4. Do not change the site any other way. WordPress checks and applies your answer itself.\n";
	if ( 'json' === $job['expect'] ) {
		$out .= "This feature parses the answer as JSON, so it must be valid JSON.\n";
	}
	if ( $images ) {
		$out .= sprintf( "The conversation includes %d image(s); they are attached to the first part of this job.\n", $images );
	}

	$out .= "\n==================== TASK RULES ====================\n";
	$out .= trim( isset( $request['system'] ) ? (string) $request['system'] : '' ) . "\n";

	$out  .= "\n==================== CONVERSATION ====================\n";
	$turns = isset( $request['turns'] ) && is_array( $request['turns'] ) ? $request['turns'] : array();
	$last  = count( $turns ) - 1;
	foreach ( $turns as $i => $turn ) {
		if ( 'model' === $turn['role'] ) {
			$out .= "\n--- your earlier answer ---\n";
		} elseif ( $i === $last ) {
			$out .= "\n--- user (the current request: answer this) ---\n";
		} else {
			$out .= "\n--- user ---\n";
		}
		foreach ( $turn['parts'] as $part ) {
			if ( isset( $part['text'] ) ) {
				$out .= $part['text'] . "\n";
			} elseif ( isset( $part['image'] ) ) {
				$out .= '[Image ' . (int) $part['image'] . " — attached]\n";
			}
		}
	}
	$out .= "\n==================== END OF JOB ====================\n";

	return $out;
}

// ═══════════════════════════════════════════════════════════════════════════════
// REST — the browser's side of the relay
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'rest_api_init', 'ekwa_ai_relay_register_routes' );

/**
 * Register the relay's browser routes.
 */
function ekwa_ai_relay_register_routes() {
	$id = '(?P<id>[A-Za-z0-9]{12,40}\.[0-9]{1,3})';

	register_rest_route( 'ekwa/v1', '/ai-relay/job/' . $id, array(
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'ekwa_ai_relay_rest_status',
			'permission_callback' => 'ekwa_ai_relay_rest_permission',
		),
		array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => 'ekwa_ai_relay_rest_cancel',
			'permission_callback' => 'ekwa_ai_relay_rest_permission',
		),
	) );

	register_rest_route( 'ekwa/v1', '/ai-relay/jobs', array(
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'ekwa_ai_relay_rest_list',
			'permission_callback' => 'ekwa_ai_relay_rest_permission',
		),
		array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => 'ekwa_ai_relay_rest_clear',
			'permission_callback' => 'ekwa_ai_relay_rest_permission',
		),
	) );

	register_rest_route( 'ekwa/v1', '/ai-relay/test', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_ai_relay_rest_test',
		'permission_callback' => 'ekwa_ai_rest_permission',
	) );
}

/**
 * Who may watch and cancel their own jobs: anyone allowed to use the AI features.
 * Deliberately not ekwa_ai_rest_permission() — polling is not an AI request and
 * must not be refused by the daily cap halfway through a wait.
 *
 * @return true|WP_Error
 */
function ekwa_ai_relay_rest_permission() {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'rest_forbidden', __( 'Sign in to WordPress first.', 'ekwa' ), array( 'status' => 401 ) );
	}
	$cap = function_exists( 'ekwa_ai_min_capability' ) ? ekwa_ai_min_capability() : 'edit_posts';
	if ( ! current_user_can( $cap ) ) {
		return new WP_Error( 'ekwa_ai_forbidden', __( 'Your role is not allowed to use the AI features.', 'ekwa' ), array( 'status' => 403 ) );
	}
	return true;
}

/**
 * GET /ai-relay/job/<id> — the job's status; also tells the server the editor
 * is still waiting.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_ai_relay_rest_status( $request ) {
	$job = ekwa_ai_relay_find( get_current_user_id(), (string) $request['id'] );
	if ( ! $job ) {
		return new WP_Error( 'ekwa_ai_relay_gone', __( 'This AI job no longer exists — it expired or was cleared.', 'ekwa' ), array( 'status' => 404 ) );
	}
	if ( in_array( $job['status'], array( 'pending', 'claimed' ), true ) ) {
		ekwa_ai_relay_touch_heartbeat( $job );
	}
	$response = rest_ensure_response( array( 'job' => ekwa_ai_relay_public_job( $job ) ) );
	$response->header( 'Cache-Control', 'no-store, max-age=0' );
	return $response;
}

/**
 * DELETE /ai-relay/job/<id> — cancel.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_ai_relay_rest_cancel( $request ) {
	$job = ekwa_ai_relay_find( get_current_user_id(), (string) $request['id'] );
	if ( ! $job ) {
		return new WP_Error( 'ekwa_ai_relay_gone', __( 'This AI job no longer exists.', 'ekwa' ), array( 'status' => 404 ) );
	}
	return rest_ensure_response( array( 'job' => ekwa_ai_relay_public_job( ekwa_ai_relay_cancel( $job ) ) ) );
}

/**
 * GET /ai-relay/jobs — the current user's recent jobs, newest first.
 *
 * @return WP_REST_Response
 */
function ekwa_ai_relay_rest_list() {
	$jobs = array_reverse( ekwa_ai_relay_user_jobs( get_current_user_id() ) );
	$jobs = array_slice( $jobs, 0, 30 );
	$response = rest_ensure_response( array( 'jobs' => array_map( 'ekwa_ai_relay_public_job', $jobs ) ) );
	$response->header( 'Cache-Control', 'no-store, max-age=0' );
	return $response;
}

/**
 * DELETE /ai-relay/jobs — clear all of the current user's jobs.
 *
 * @return WP_REST_Response
 */
function ekwa_ai_relay_rest_clear() {
	$jobs = ekwa_ai_relay_user_jobs( get_current_user_id() );
	foreach ( $jobs as $job ) {
		ekwa_ai_relay_delete( $job );
	}
	return rest_ensure_response( array( 'cleared' => count( $jobs ) ) );
}

/**
 * POST /ai-relay/test — a one-word round trip through the person's AI app.
 *
 * @return WP_REST_Response|WP_Error
 */
function ekwa_ai_relay_rest_test() {
	if ( ! ekwa_ai_mcp_enabled() ) {
		return new WP_Error( 'ekwa_ai_mcp_off', __( 'Turn on the MCP connector first (Ekwa Settings → AI → AI provider), and save.', 'ekwa' ), array( 'status' => 400 ) );
	}
	if ( function_exists( 'ekwa_mcp_user_has_key' ) && ! ekwa_mcp_user_has_key( get_current_user_id() ) ) {
		return new WP_Error( 'ekwa_ai_mcp_no_key', __( 'Create a connection key and add it to your AI app first — there is nothing connected to send the test to.', 'ekwa' ), array( 'status' => 400 ) );
	}
	if ( '' === ekwa_ai_relay_request_id() ) {
		return new WP_Error( 'ekwa_ai_relay_missing', __( 'This page is not set up to wait for your AI app. Reload it and try again.', 'ekwa' ), array( 'status' => 400 ) );
	}

	if ( function_exists( 'ekwa_ai_current_feature' ) ) {
		ekwa_ai_current_feature( 'test' );
	}

	$result = ekwa_ai_generate_call_gemini(
		'You are the connection check for the Ekwa WordPress theme\'s MCP connector. Reply with exactly the two letters OK and nothing else.',
		array( array( 'role' => 'user', 'parts' => array( array( 'text' => 'ping' ) ) ) ),
		0.0,
		'',
		'',
		64,
		null
	);

	if ( is_wp_error( $result ) ) {
		return rest_ensure_response( array( 'ok' => false, 'message' => $result->get_error_message() ) );
	}

	return rest_ensure_response( array(
		'ok'      => true,
		'reply'   => trim( (string) $result['content'] ),
		'message' => __( 'Your AI app answered through the MCP connector.', 'ekwa' ),
	) );
}
