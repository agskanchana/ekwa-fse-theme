<?php
/**
 * Site Audit — Spelling.
 *
 * Proofreads the visible text of published posts, pages and public custom
 * posts (title, content, excerpt, Yoast SEO title and meta description),
 * synced patterns, navigation menus, templates and template parts (header,
 * footer…) with the site's AI provider — the Gemini key or the MCP connector,
 * through ekwa_ai_generate_call_gemini() like every AI feature.
 *
 * The prompt is written for medical practices: medical, dental, dermatology
 * and cosmetic terms, brand and drug names, people and place names are never
 * reported, and when the AI is unsure it must stay quiet. Each reported word
 * must really occur in the text it was reported for (the server checks), and
 * the site's own dictionary ("Ignore" on a word) is applied to every result.
 *
 * Checks run from the browser, a few pages per AI call, only on a click. The
 * check route reads everything first and writes its results only after the AI
 * has answered (the MCP relay re-sends the request — nothing before the AI
 * call may have side effects). Results are kept per page with a hash of the
 * text they were made from, so an edited page shows as "changed since".
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Option: spelling results by source key (not autoloaded). */
const EKWA_SITE_AUDIT_SPELLING_OPTION = 'ekwa_site_audit_spelling';

/** Option: words the site's spelling check ignores (not autoloaded). */
const EKWA_SITE_AUDIT_DICTIONARY_OPTION = 'ekwa_site_audit_dictionary';

/**
 * Routes.
 */
function ekwa_site_audit_spelling_routes() {
	$can = static function () {
		return current_user_can( ekwa_page_audit_dashboard_cap() );
	};
	register_rest_route( 'ekwa/v1', '/site-audit/spelling', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'ekwa_site_audit_rest_spelling',
		'permission_callback' => $can,
	) );
	register_rest_route( 'ekwa/v1', '/site-audit/spelling/check', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_site_audit_rest_spelling_check',
		'permission_callback' => static function () use ( $can ) {
			if ( ! $can() ) {
				return false;
			}
			return function_exists( 'ekwa_ai_rest_permission' ) ? ekwa_ai_rest_permission() : current_user_can( 'edit_posts' );
		},
		'args'                => array(
			'keys'  => array( 'required' => true, 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'model' => array( 'type' => 'string', 'default' => '' ),
		),
	) );
	register_rest_route( 'ekwa/v1', '/site-audit/spelling/dictionary', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_site_audit_rest_dictionary',
		'permission_callback' => $can,
		'args'                => array(
			'word'   => array( 'required' => true, 'type' => 'string' ),
			'action' => array( 'type' => 'string', 'enum' => array( 'add', 'remove' ), 'default' => 'add' ),
		),
	) );
}
add_action( 'rest_api_init', 'ekwa_site_audit_spelling_routes' );

/**
 * The site's ignore list.
 *
 * @return string[]
 */
function ekwa_site_audit_dictionary() {
	$list = get_option( EKWA_SITE_AUDIT_DICTIONARY_OPTION, array() );
	return is_array( $list ) ? array_values( array_filter( array_map( 'strval', $list ) ) ) : array();
}

/**
 * Lower-cased lookup of the ignore list.
 *
 * @return array<string,bool>
 */
function ekwa_site_audit_dictionary_map() {
	$map = array();
	foreach ( ekwa_site_audit_dictionary() as $word ) {
		$map[ function_exists( 'mb_strtolower' ) ? mb_strtolower( $word ) : strtolower( $word ) ] = true;
	}
	return $map;
}

/**
 * The text a source shows, as one string (deduplicated lines).
 *
 * @param array $src From ekwa_site_audit_each_source().
 * @return string
 */
function ekwa_site_audit_spelling_text( $src ) {
	$parts = array();
	if ( 'content' === $src['group'] && '' !== trim( $src['title'] ) && 0 !== strpos( $src['title'], '(' ) ) {
		$parts[] = $src['title'];
	}
	$parsed = ekwa_site_audit_parse( $src['content'] );
	foreach ( $parsed['segments'] as $seg ) {
		$parts[] = $seg['t'];
	}
	if ( ! empty( $src['excerpt'] ) ) {
		$parts[] = ekwa_site_audit_html_text( $src['excerpt'] );
	}
	if ( 'content' === $src['group'] && $src['id'] && ekwa_site_audit_yoast_active() ) {
		foreach ( array( EKWA_SITE_AUDIT_YOAST_TITLE, EKWA_SITE_AUDIT_YOAST_DESC ) as $key ) {
			$value = trim( preg_replace( '/%%[a-z0-9_]+%%/i', ' ', (string) get_post_meta( $src['id'], $key, true ) ) );
			if ( '' !== $value ) {
				$parts[] = $value;
			}
		}
	}
	$lines = array();
	foreach ( $parts as $part ) {
		foreach ( preg_split( '/\n+/', (string) $part ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line && preg_match( '/\p{L}{2}/u', $line ) ) {
				$lines[ $line ] = true;
			}
		}
	}
	$text = implode( "\n", array_keys( $lines ) );
	$max  = (int) apply_filters( 'ekwa_site_audit_spelling_max_chars', 30000 );
	if ( strlen( $text ) > $max ) {
		$text = function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, $max ) : substr( $text, 0, $max );
	}
	return $text;
}

/**
 * Stored results.
 *
 * @return array<string,array>
 */
function ekwa_site_audit_spelling_stored() {
	$stored = get_option( EKWA_SITE_AUDIT_SPELLING_OPTION, array() );
	return is_array( $stored ) && isset( $stored['r'] ) && is_array( $stored['r'] ) ? $stored['r'] : array();
}

/**
 * Issues without the words the site ignores.
 *
 * @param array $issues
 * @param array $dict ekwa_site_audit_dictionary_map()
 * @return array
 */
function ekwa_site_audit_spelling_filter( $issues, $dict ) {
	$out = array();
	foreach ( is_array( $issues ) ? $issues : array() as $issue ) {
		if ( ! is_array( $issue ) || empty( $issue['w'] ) ) {
			continue;
		}
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $issue['w'] ) : strtolower( (string) $issue['w'] );
		if ( ! isset( $dict[ $lower ] ) ) {
			$out[] = $issue;
		}
	}
	return $out;
}

/**
 * GET /site-audit/spelling — every source with text, and its last result.
 *
 * @return WP_REST_Response
 */
function ekwa_site_audit_rest_spelling() {
	$stored  = ekwa_site_audit_spelling_stored();
	$dict    = ekwa_site_audit_dictionary_map();
	$sources = array();

	ekwa_site_audit_each_source( static function ( $src ) use ( &$sources, $stored, $dict ) {
		$text  = ekwa_site_audit_spelling_text( $src );
		$words = ekwa_site_audit_word_count( $text );
		if ( $words < 2 ) {
			return;
		}
		$hash   = substr( md5( $text ), 0, 12 );
		$result = null;
		if ( isset( $stored[ $src['key'] ] ) && is_array( $stored[ $src['key'] ] ) ) {
			$r      = $stored[ $src['key'] ];
			$result = array(
				't'      => isset( $r['t'] ) ? (int) $r['t'] : 0,
				'stale'  => ! isset( $r['h'] ) || $r['h'] !== $hash,
				'issues' => ekwa_site_audit_spelling_filter( isset( $r['n'] ) ? $r['n'] : array(), $dict ),
			);
		}
		$sources[] = array_merge( ekwa_site_audit_public_source( $src ), array(
			'chars'  => strlen( $text ),
			'words'  => $words,
			'h'      => $hash,
			'result' => $result,
		) );
	} );

	return rest_ensure_response( array(
		'sources'    => $sources,
		'dictionary' => ekwa_site_audit_dictionary(),
	) );
}

/**
 * The proofreading instructions.
 *
 * @return string
 */
function ekwa_site_audit_spelling_prompt() {
	$prompt = <<<'PROMPT'
You are a careful proofreader for the website of a medical practice — dentists, orthodontists, oral surgeons, dermatologists, plastic surgeons, med spas, chiropractors, optometrists and similar.

TASK: find SPELLING mistakes only — misspelled words and typing errors: wrong, missing, doubled or swapped letters, or two words run together (e.g. "recieve", "dentisry", "appointmnet", "teh", "implantss", "orthodonticcare").

NEVER report:
- medical, dental, dermatological, surgical, cosmetic, anatomical or pharmaceutical terms, including rare and Latin ones (periodontitis, gingivectomy, abdominoplasty, blepharoplasty, rhinoplasty, microneedling, hyaluronic, endodontic, bruxism, rosacea, keratosis, liposuction, apicoectomy, frenectomy…)
- brand, product, drug and device names (Invisalign, Botox, Juvéderm, Kybella, CoolSculpting, Sculptra, Restylane, CEREC, Morpheus8, SureSmile, Zoom…)
- names of people, practices, streets, cities and other places; abbreviations, acronyms and credentials (TMJ, TMD, DDS, DMD, MD, FACS, PRP, IV…)
- American vs. British spelling, capitalisation, grammar, punctuation, spacing, word choice or style
- phone numbers, prices, URLs, e-mail addresses, codes, file names, words in another language
- any word in IGNORE
If you are not sure a word is wrong, leave it out: a false alarm is worse than a miss.

INPUT: JSON {"SOURCES":[{"id":"S1","text":"…"}],"IGNORE":["…"]}.

OUTPUT: ONLY a JSON object, no prose, no code fences:
{"issues":[{"source":"S1","word":"<the misspelled word exactly as written>","suggestion":"<the correct spelling>"}]}
Report each misspelled word once per source. Return {"issues":[]} when there are none.
PROMPT;
	/**
	 * Filter the spelling-check instructions (e.g. to name the practice's
	 * specialty or brand names).
	 *
	 * @param string $prompt
	 */
	return (string) apply_filters( 'ekwa_site_audit_spelling_prompt', $prompt );
}

/**
 * Keep only issues that are real: the word occurs in that source's text, the
 * suggestion differs, the site does not ignore the word.
 *
 * @param mixed $decoded AI answer.
 * @param array $map     id => {key, text}
 * @param array $dict    ekwa_site_audit_dictionary_map()
 * @return array<string,array[]> key => issues
 */
function ekwa_site_audit_spelling_validate( $decoded, $map, $dict ) {
	$out = array();
	foreach ( $map as $entry ) {
		$out[ $entry['key'] ] = array();
	}
	$list = array();
	if ( is_array( $decoded ) && isset( $decoded['issues'] ) && is_array( $decoded['issues'] ) ) {
		$list = $decoded['issues'];
	} elseif ( is_array( $decoded ) && isset( $decoded[0] ) ) {
		$list = $decoded;
	}
	$lower = static function ( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s ) : strtolower( (string) $s );
	};
	$seen = array();
	foreach ( $list as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$sid = isset( $row['source'] ) ? (string) $row['source'] : '';
		if ( ! isset( $map[ $sid ] ) ) {
			if ( 1 !== count( $map ) ) {
				continue;
			}
			$sid = (string) key( $map );
		}
		$word = trim( isset( $row['word'] ) ? (string) $row['word'] : '', " \t\n\r\0\x0B\"'“”‘’.,;:!?()" );
		$sugg = trim( isset( $row['suggestion'] ) ? (string) $row['suggestion'] : '' );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $word ) : strlen( $word );
		if ( $len < 2 || $len > 40 || '' === $sugg || preg_match( '/[\d@\/\\\\_]|\.\w/u', $word ) || $lower( $sugg ) === $lower( $word ) ) {
			continue;
		}
		if ( isset( $dict[ $lower( $word ) ] ) ) {
			continue;
		}
		$text    = $map[ $sid ]['text'];
		$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $word, '/' ) . '(?![\p{L}\p{N}])/u';
		if ( ! preg_match( $pattern, $text, $m, PREG_OFFSET_CAPTURE ) ) {
			continue; // Not in the text: the AI made it up or changed it.
		}
		$key = $map[ $sid ]['key'];
		$id  = $key . '|' . $lower( $word );
		if ( isset( $seen[ $id ] ) || count( $out[ $key ] ) >= 80 ) {
			continue;
		}
		$seen[ $id ] = true;
		$offset      = (int) $m[0][1];
		$start       = max( 0, $offset - 60 );
		$cut         = function_exists( 'mb_strcut' ) ? mb_strcut( $text, $start, 150 + strlen( $word ) ) : substr( $text, $start, 150 + strlen( $word ) );
		$out[ $key ][] = array(
			'w' => $word,
			's' => ekwa_site_audit_clip( $sugg, 60 ),
			'c' => ( $start > 0 ? '…' : '' ) . trim( preg_replace( '/\s+/u', ' ', $cut ) ) . '…',
			'x' => (int) preg_match_all( $pattern, $text ),
		);
	}
	return $out;
}

/**
 * POST /site-audit/spelling/check — proofread a few sources in one AI call.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_site_audit_rest_spelling_check( $request ) {
	if ( function_exists( 'ekwa_ai_current_feature' ) ) {
		ekwa_ai_current_feature( 'spelling' );
	}
	if ( ! function_exists( 'ekwa_ai_is_configured' ) || ! ekwa_ai_is_configured() ) {
		return new WP_Error( 'ekwa_ai_unavailable', __( 'No AI provider is set up. Add a Gemini API key or turn on the MCP connector in Ekwa Settings → AI.', 'ekwa' ), array( 'status' => 400 ) );
	}
	$keys = array_slice( array_values( array_unique( array_map( 'strval', (array) $request->get_param( 'keys' ) ) ) ), 0, 12 );
	if ( ! $keys ) {
		return new WP_Error( 'ekwa_site_audit_no_keys', __( 'Nothing to check.', 'ekwa' ), array( 'status' => 400 ) );
	}

	// Read everything first — no side effects before the AI call.
	$budget = (int) apply_filters( 'ekwa_site_audit_spelling_batch_chars', 45000 );
	$map    = array();
	$hashes = array();
	$sent   = array();
	$used   = 0;
	$n      = 0;
	ekwa_site_audit_each_source( static function ( $src ) use ( &$map, &$hashes, &$used, &$n, $budget ) {
		$text = ekwa_site_audit_spelling_text( $src );
		if ( '' === $text || ( $used > 0 && $used + strlen( $text ) > $budget ) ) {
			return; // Left for the next batch.
		}
		$used += strlen( $text );
		$id    = 'S' . ( ++$n );
		$map[ $id ]             = array( 'key' => $src['key'], 'text' => $text );
		$hashes[ $src['key'] ] = substr( md5( $text ), 0, 12 );
	}, array( 'only' => $keys ) );

	if ( ! $map ) {
		return new WP_Error( 'ekwa_site_audit_no_text', __( 'Those pages have no text to check (or no longer exist).', 'ekwa' ), array( 'status' => 404 ) );
	}
	foreach ( $map as $id => $entry ) {
		$sent[] = array( 'id' => $id, 'text' => $entry['text'] );
	}

	$payload  = array(
		'SOURCES' => $sent,
		'IGNORE'  => array_slice( ekwa_site_audit_dictionary(), 0, 600 ),
	);
	$contents = array(
		array(
			'role'  => 'user',
			'parts' => array(
				array( 'text' => "Proofread these texts (JSON):\n" . wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n\nReturn the JSON object described in your instructions." ),
			),
		),
	);
	$model  = function_exists( 'ekwa_ai_resolve_model' ) ? ekwa_ai_resolve_model( (string) $request->get_param( 'model' ), 'flash' ) : '';
	$result = ekwa_ai_generate_call_gemini( ekwa_site_audit_spelling_prompt(), $contents, 0.1, (string) ekwa_get_ai_api_key(), $model, 8192, null );
	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();
		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			$result->add_data( array( 'status' => 502 ) );
		}
		return $result;
	}
	$decoded = ekwa_page_audit_decode_json( isset( $result['content'] ) ? (string) $result['content'] : '' );
	if ( ! is_array( $decoded ) ) {
		return new WP_Error( 'ekwa_site_audit_bad_answer', __( 'The AI answer could not be read. Try again.', 'ekwa' ), array( 'status' => 502 ) );
	}

	$dict    = ekwa_site_audit_dictionary_map();
	$issues  = ekwa_site_audit_spelling_validate( $decoded, $map, $dict );
	$now     = time();
	$results = array();
	foreach ( $issues as $key => $list ) {
		$results[ $key ] = array(
			't' => $now,
			'h' => $hashes[ $key ],
			'n' => $list,
		);
	}

	// Save (inside the lock, re-reading what other requests saved).
	$lock    = ekwa_site_audit_lock( 'spelling' );
	$stored  = ekwa_site_audit_spelling_stored();
	update_option( EKWA_SITE_AUDIT_SPELLING_OPTION, array(
		'v' => 1,
		'r' => array_merge( $stored, $results ),
	), false );
	ekwa_site_audit_unlock( $lock );

	$public = array();
	foreach ( $results as $key => $r ) {
		$public[ $key ] = array(
			't'      => $r['t'],
			'h'      => $r['h'],
			'stale'  => false,
			'issues' => $r['n'],
		);
	}
	return rest_ensure_response( array(
		'results' => (object) $public,
		'skipped' => array_values( array_diff( $keys, array_keys( $results ) ) ),
	) );
}

/**
 * POST /site-audit/spelling/dictionary — ignore a word site-wide (or stop
 * ignoring it). Ignored words disappear from every result at once.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_site_audit_rest_dictionary( $request ) {
	$word = trim( sanitize_text_field( (string) $request->get_param( 'word' ) ) );
	if ( '' === $word || strlen( $word ) > 60 ) {
		return new WP_Error( 'ekwa_site_audit_word', __( 'That is not a word.', 'ekwa' ), array( 'status' => 400 ) );
	}
	$lower = function_exists( 'mb_strtolower' ) ? 'mb_strtolower' : 'strtolower';
	$lock  = ekwa_site_audit_lock( 'dictionary' );
	$list  = array();
	foreach ( ekwa_site_audit_dictionary() as $w ) {
		$list[ $lower( $w ) ] = $w;
	}
	if ( 'remove' === $request->get_param( 'action' ) ) {
		unset( $list[ $lower( $word ) ] );
	} else {
		$list[ $lower( $word ) ] = $word;
	}
	ksort( $list );
	update_option( EKWA_SITE_AUDIT_DICTIONARY_OPTION, array_slice( array_values( $list ), 0, 3000 ), false );
	ekwa_site_audit_unlock( $lock );
	return rest_ensure_response( array( 'dictionary' => ekwa_site_audit_dictionary() ) );
}
