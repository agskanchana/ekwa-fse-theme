<?php
/**
 * Site Health — can the block editor actually save?
 *
 * The failure this exists for: a server-side WAF (ModSecurity with the OWASP
 * Core Rule Set, which cPanel enables by default) inspects the body of the
 * editor's REST write, matches a rule, and answers 403/406 with an HTML error
 * page. Gutenberg expected JSON, so it reports
 *
 *     "Updating failed. The response is not a valid JSON response."
 *
 * The request never reaches PHP, so nothing in WordPress or this theme can
 * catch it or say anything more useful. The error names neither the WAF nor the
 * rule, and the usual "fix" is to switch ModSecurity off for the whole domain.
 *
 * Ekwa content makes the false positive much likelier than a stock WP site: an
 * ekwa/div carries its section stylesheet in a `scopedCss` block attribute, so
 * raw CSS is serialized into post_content and POSTed on every save. A CSS
 * comment is read as CRS 942440 "SQL Comment Sequence Detected"; <style> and
 * inline-style fragments hit the 941xxx XSS rules.
 * ekwa_css_strip_comments() removes the commonest trigger at write time, but
 * only a rule exclusion on the server actually fixes this.
 *
 * So: send the server a POST that looks like a real Ekwa save and see whether
 * it comes back. The loopback is the only way to observe it, because the block
 * is upstream of PHP.
 *
 * Read-only — writes nothing, not even a transient (the probe token is derived,
 * not stored).
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) {
	exit;
}

/**
 * Token authorizing one probe request, derived rather than stored.
 *
 * The probe endpoint has to answer an unauthenticated loopback request (the
 * loopback carries no cookies), so it needs its own proof that Site Health on
 * this site is what called it. wp_hash() over a coarse time window gives that
 * without writing an option or a transient — which the theme's rules forbid as
 * a side effect anyway.
 *
 * @param int $back Windows to go back (1 = the previous window, for requests
 *                  that straddle a boundary).
 * @return string
 */
function ekwa_site_health_probe_token( $back = 0 ) {
	$window = (int) floor( time() / 300 ) - (int) $back;
	return wp_hash( 'ekwa-waf-probe|' . $window );
}

/**
 * Whether a submitted probe token is one we could have just issued.
 *
 * @param string $token Token from the request body.
 * @return bool
 */
function ekwa_site_health_probe_token_valid( $token ) {
	$token = (string) $token;
	if ( '' === $token ) {
		return false;
	}
	// Accept the current and previous window so a probe crossing a 5-minute
	// boundary isn't reported as a server failure.
	return hash_equals( ekwa_site_health_probe_token( 0 ), $token )
		|| hash_equals( ekwa_site_health_probe_token( 1 ), $token );
}

/**
 * The probe endpoint. Does nothing and touches nothing — reaching it at all is
 * the entire result, because it means the WAF let the body through.
 */
function ekwa_site_health_register_probe_route() {
	register_rest_route(
		'ekwa/v1',
		'/waf-probe',
		array(
			'methods'             => 'POST',
			// Gated on the derived token, not on a capability: the loopback
			// request is necessarily anonymous. The route has no side effects
			// and returns no site data, so the token is the whole surface.
			'permission_callback' => function ( $request ) {
				return ekwa_site_health_probe_token_valid( $request->get_param( 'token' ) );
			},
			'callback'            => function () {
				return rest_ensure_response( array( 'ok' => true ) );
			},
		)
	);
}
add_action( 'rest_api_init', 'ekwa_site_health_register_probe_route' );

/**
 * POST one probe body to the probe route and classify what came back.
 *
 * @param string $content Body content to send as the `content` field.
 * @return array{ ok:bool, code:int, body:string, error:string }
 */
function ekwa_site_health_probe( $content ) {
	$response = wp_remote_post(
		rest_url( 'ekwa/v1/waf-probe' ),
		array(
			'timeout'   => 10,
			// Same as core's own loopback checks (WP_Site_Health): a staging
			// box with a self-signed certificate must not read as a WAF block.
			'sslverify' => false,
			'headers'   => array( 'Content-Type' => 'application/json' ),
			'body'      => wp_json_encode(
				array(
					'token'   => ekwa_site_health_probe_token(),
					'content' => $content,
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'ok'    => false,
			'code'  => 0,
			'body'  => '',
			'error' => $response->get_error_message(),
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = (string) wp_remote_retrieve_body( $response );
	$json = json_decode( $body, true );

	return array(
		'ok'    => ( 200 === $code && is_array( $json ) && ! empty( $json['ok'] ) ),
		'code'  => $code,
		'body'  => $body,
		'error' => '',
	);
}

/* ══════════════════════════════════════════════════════════════════════════
 * How long this server lets a request run
 * ══════════════════════════════════════════════════════════════════════════
 *
 * A second failure with the same shape as the WAF one above: the block is
 * OUTSIDE PHP, so nothing in WordPress can catch it or explain it.
 *
 *     Request Timeout
 *     This request takes too long to process, it is timed out by the server.
 *     If it should not be timed out, please contact administrator of this web
 *     site to increase 'Connection Timeout'.
 *
 * That page is LiteSpeed's (the wording is verbatim LSWS/OpenLiteSpeed). The
 * web server gave up waiting on PHP and answered the browser itself, so the
 * editor gets HTML where it expected JSON — exactly as with a WAF rejection,
 * and for the same reason: PHP never got to answer.
 *
 * It bites the AI features because they are the only requests that hold a
 * connection open for a long time: one Gemini call can run well past a minute,
 * and LiteSpeed's Connection Timeout commonly defaults to 60 seconds.
 *
 * The limit is not readable from PHP — it lives in the web server's config, not
 * in php.ini — so the only way to learn it is to measure it.
 */

/**
 * How long the theme is willing to wait for one Gemini call, in seconds.
 *
 * Pulled out of the call site so the diagnostic and the caller cannot disagree
 * about the number being tested, and so a site whose server cuts requests off
 * earlier can lower it — see ekwa_server_timeout_report(). Returning BEFORE the
 * web server's ceiling is what turns an unexplained HTML error page into a
 * readable message in the modal.
 *
 * @return int
 */
function ekwa_ai_http_timeout() {
	$seconds = defined( 'EKWA_AI_HTTP_TIMEOUT' ) ? (int) EKWA_AI_HTTP_TIMEOUT : 120;

	/**
	 * Filter the Gemini HTTP timeout.
	 *
	 * @param int $seconds Default 120 — the value this was hardcoded to before
	 *                     it became configurable, so nothing changes by default.
	 */
	$seconds = (int) apply_filters( 'ekwa_ai_http_timeout', $seconds );

	// A floor: below this even a small generation cannot finish, and a request
	// that always times out is worse than one that sometimes does.
	return max( 15, $seconds );
}

/**
 * Facts about this server that cost nothing to read.
 *
 * Deliberately separate from the measurement: these are free and instant, and
 * on a LiteSpeed box they are half the answer on their own.
 *
 * @return array<string,mixed>
 */
function ekwa_server_limits() {
	$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
	$sapi     = PHP_SAPI;

	// LiteSpeed shows up in either place depending on whether PHP is running
	// under LSAPI or as a proxied FPM pool.
	$is_litespeed = ( false !== stripos( $software, 'litespeed' ) )
		|| ( false !== stripos( $sapi, 'litespeed' ) );

	return array(
		'software'       => $software,
		'sapi'           => $sapi,
		'is_litespeed'   => $is_litespeed,
		// PHP's own ceiling. Note this is NOT what produces the LiteSpeed page:
		// on Unix, time spent blocked on a network read does not count toward
		// max_execution_time, so a slow Gemini call can sail past this number
		// and still be killed by the web server.
		'max_execution'  => (int) ini_get( 'max_execution_time' ),
		'ai_timeout'     => ekwa_ai_http_timeout(),
	);
}

/**
 * The probe: hold a request open for N seconds, then answer.
 *
 * `sleep()` is a faithful stand-in for what a Gemini call does to this server —
 * PHP sitting idle waiting on I/O, consuming no CPU — which is the case both
 * LiteSpeed's Connection Timeout and a proxy's read timeout are counting.
 *
 * Called from the BROWSER, not over the loopback, and that is the point: it
 * takes the identical path through the web server as the editor's request, so
 * whatever kills one kills the other.
 */
function ekwa_server_timeout_register_route() {
	register_rest_route(
		'ekwa/v1',
		'/server-timeout-probe',
		array(
			'methods'             => 'POST',
			// An admin-only route that deliberately occupies a PHP worker, so
			// it is gated on the capability rather than on the derived token the
			// WAF probe uses — this one is called with cookies, from a logged-in
			// settings page, and never anonymously.
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => array(
				'seconds' => array(
					'required' => false,
					'type'     => 'integer',
					'default'  => 30,
				),
				// Trickle whitespace while waiting instead of going silent.
				// This is the experiment that decides what can be done about a
				// low ceiling: if the server's limit counts IDLE time, a
				// connection that keeps producing bytes survives it and the fix
				// is ours to make; if it is an absolute cap on the request, this
				// dies at exactly the same second and only the host can help.
				// @see ekwa_server_timeout_report().
				'keepalive' => array(
					'required' => false,
					'type'     => 'boolean',
					'default'  => false,
				),
			),
			'callback'            => function ( $request ) {
				// Capped: a worker held open is a worker not serving the site,
				// and nothing is learned past the theme's own ceiling.
				$seconds = max( 1, min( 300, (int) $request->get_param( 'seconds' ) ) );

				// Take PHP's own limit out of the measurement, so whatever cuts
				// this off is unambiguously the web server. It matters on
				// Windows, where sleeping DOES count toward max_execution_time
				// (on Unix it does not, which is why a blocking Gemini call can
				// sail past max_execution_time and still be killed upstream).
				// Suppressed because some hosts disable it, and failing to
				// raise the limit is not a reason to refuse to measure.
				if ( function_exists( 'set_time_limit' ) ) {
					@set_time_limit( $seconds + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}

				$started = microtime( true );

				if ( $request->get_param( 'keepalive' ) ) {
					ekwa_server_timeout_trickle( $seconds );
				} else {
					sleep( $seconds );
				}

				return rest_ensure_response( array(
					'ok'        => true,
					'asked'     => $seconds,
					'keepalive' => (bool) $request->get_param( 'keepalive' ),
					'elapsed'   => round( microtime( true ) - $started, 2 ),
					'limits'    => ekwa_server_limits(),
				) );
			},
		)
	);
}
add_action( 'rest_api_init', 'ekwa_server_timeout_register_route' );

/**
 * Wait, but keep the connection producing bytes while doing it.
 *
 * Whitespace, one space a second, then the JSON body after it. That is safe for
 * the caller because JSON.parse — and therefore both jQuery and apiFetch —
 * skips leading whitespace, so the response still parses as the object it
 * always was. Nothing else on the site consumes this route.
 *
 * Every buffer between here and the socket has to be taken out of the way or
 * the trickle never leaves the server and the test measures nothing. That is
 * heavy-handed, which is why it happens only on this diagnostic route and only
 * when explicitly asked for.
 *
 * @param int $seconds How long to hold the connection.
 * @return void
 */
function ekwa_server_timeout_trickle( $seconds ) {
	// Best effort, and expected to fail: the REST server sends Content-Type
	// before dispatching, so headers are already out by the time a callback
	// runs and PHP refuses to change these. Kept because it costs nothing and
	// succeeds on setups that buffer differently — but the buffer draining
	// below is the part that actually does the work.
	// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
	@ini_set( 'zlib.output_compression', 'Off' );
	@ini_set( 'output_buffering', 'Off' );
	@ini_set( 'implicit_flush', '1' );
	// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged

	// Drain whatever WordPress or the host has already stacked up; a buffer left
	// in place swallows the trickle and the connection still looks idle.
	while ( ob_get_level() > 0 ) {
		@ob_end_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	ob_implicit_flush( true );

	for ( $i = 0; $i < (int) $seconds; $i++ ) {
		echo ' ';
		// Both, because which one is needed depends on whether a buffer got
		// re-established underneath us.
		if ( ob_get_level() > 0 ) {
			@ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		flush();
		sleep( 1 );
	}
}

/**
 * What a measured ceiling means, in words, for the AI features.
 *
 * @param int $ceiling Seconds the server allowed, or 0 when not yet measured.
 * @return array{status:string,headline:string,detail:string}
 */
function ekwa_server_timeout_report( $ceiling ) {
	$limits = ekwa_server_limits();
	$needed = (int) $limits['ai_timeout'];
	$ceiling = (int) $ceiling;

	if ( $ceiling <= 0 ) {
		return array(
			'status'   => 'unknown',
			'headline' => __( 'Not measured yet.', 'ekwa' ),
			'detail'   => '',
		);
	}

	if ( $ceiling >= $needed ) {
		return array(
			'status'   => 'good',
			'headline' => sprintf(
				/* translators: 1: measured seconds, 2: the theme's AI timeout. */
				__( 'This server allowed a request to run for %1$d seconds — longer than the %2$d the AI features are willing to wait. A timeout here is not the web server cutting the request off.', 'ekwa' ),
				$ceiling,
				$needed
			),
			'detail'   => __( 'If generation still fails, look at the PHP error log for a line starting [ekwa-ai] — a Gemini quota or network error reports there.', 'ekwa' ),
		);
	}

	return array(
		'status'   => 'bad',
		'headline' => sprintf(
			/* translators: 1: measured seconds, 2: the theme's AI timeout. */
			__( 'This server cut the request off after %1$d seconds. The AI features wait up to %2$d, so any generation slower than %1$d is killed by the server before PHP can answer — which is what produces the “Request Timeout” page instead of a result.', 'ekwa' ),
			$ceiling,
			$needed
		),
		'detail'   => $limits['is_litespeed']
			? __( 'This is LiteSpeed. Raise it in WebAdmin → Configuration → Server → Tuning → Connection Timeout (or, on cPanel, WHM → LiteSpeed Web Server → Configuration), and on the PHP side raise lsapi_max_process_time to match. Most hosts will do this on request; it is a standard change.', 'ekwa' )
			: __( 'Raise the web server’s read/connection timeout for PHP requests. On Apache with mod_proxy_fcgi that is ProxyTimeout and the fcgi:// connection timeout; on nginx it is fastcgi_read_timeout.', 'ekwa' ),
	);
}

/**
 * Name the WAF from its rejection, when it's recognizable.
 *
 * Only as good as the fingerprints below — an unrecognized blocker still gets
 * reported, just without a name.
 *
 * @param int    $code HTTP status.
 * @param string $body Response body.
 * @return string Human-readable guess, or '' when nothing matched.
 */
function ekwa_site_health_waf_fingerprint( $code, $body ) {
	$body = strtolower( (string) $body );

	if ( false !== strpos( $body, 'mod_security' ) || false !== strpos( $body, 'modsecurity' ) ) {
		return __( 'ModSecurity (named itself in the response)', 'ekwa' );
	}
	// cPanel/EasyApache ship ModSecurity configured to answer 406 with Apache's
	// stock "Not Acceptable" page — the single most common shape of this.
	if ( 406 === $code && false !== strpos( $body, 'not acceptable' ) ) {
		return __( 'ModSecurity on cPanel (406 “Not Acceptable”)', 'ekwa' );
	}
	if ( false !== strpos( $body, 'imunify' ) ) {
		return __( 'Imunify360', 'ekwa' );
	}
	if ( false !== strpos( $body, 'cloudflare' ) ) {
		return __( 'Cloudflare WAF', 'ekwa' );
	}
	if ( false !== strpos( $body, 'wordfence' ) ) {
		return __( 'Wordfence firewall', 'ekwa' );
	}
	return '';
}

/**
 * The remediation block — shown whenever a probe was blocked.
 *
 * @param bool $payload_specific Whether a plain body got through while the
 *                               CSS-shaped one did not.
 * @return string HTML.
 */
function ekwa_site_health_waf_remediation( $payload_specific ) {
	$out = '';

	if ( $payload_specific ) {
		$out .= '<p>' . esc_html__( 'A plain request body went through and only the one carrying CSS was blocked, so this is a content rule — most likely CRS 942440 (“SQL Comment Sequence Detected”), which matches the comment markers in a stylesheet, or a 941xxx XSS rule matching a style fragment.', 'ekwa' ) . '</p>';
	} else {
		$out .= '<p>' . esc_html__( 'Both a plain body and a CSS-carrying body were blocked, so the rule is matching REST writes generally rather than anything specific to the content.', 'ekwa' ) . '</p>';
	}

	$out .= '<p>' . esc_html__( 'Find the rule that fired, at the time of the failure, in WHM → ModSecurity™ Tools → Hit List, or on the server:', 'ekwa' ) . '</p>';
	$out .= '<pre style="white-space:pre-wrap">' . esc_html( "grep -B5 -A20 'wp-json' /usr/local/apache/logs/modsec_audit.log | tail -60" ) . '</pre>';
	$out .= '<p>' . esc_html__( 'Then exclude that rule for the REST path only — rather than switching the firewall off for the whole domain, which is what the editor error usually leads people to do. With root access, add it as a custom rule (WHM → ModSecurity™ Configuration → Rules List), substituting the IDs you actually saw:', 'ekwa' ) . '</p>';
	$out .= '<pre style="white-space:pre-wrap">' . esc_html( "<LocationMatch \"^/wp-json/\">\n    SecRuleRemoveById 942440\n</LocationMatch>" ) . '</pre>';
	$out .= '<p>' . esc_html__( '<LocationMatch> is a server-config directive and will not work in .htaccess, so this needs WHM or the host — the per-domain cPanel toggle can only turn ModSecurity off entirely.', 'ekwa' ) . '</p>';

	return $out;
}

/**
 * Site Health test: does a representative editor save survive the round trip?
 *
 * Sends the CSS-shaped body first and stops there when it succeeds, so the
 * healthy case costs one loopback request. A failure earns a second, plain
 * request, because "every REST write is blocked" and "this content is blocked"
 * point at different exclusions.
 *
 * @return array Site Health result.
 */
function ekwa_site_health_rest_write_test() {
	$result = array(
		'label'       => __( 'The block editor can save through this server', 'ekwa' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Ekwa', 'ekwa' ),
			'color' => 'blue',
		),
		'description' => '<p>' . esc_html__( 'A test save carrying section CSS — the shape of content Ekwa blocks store — reached the REST API and came back as JSON.', 'ekwa' ) . '</p>',
		'actions'     => '',
		'test'        => 'ekwa_rest_write',
	);

	// Shaped like the real thing: an ekwa/div block delimiter whose scopedCss
	// attribute holds a commented stylesheet, which is exactly what the
	// converter and the AI Block Builder produce.
	$css_body = '<!-- wp:ekwa/div {"scopedCss":"/* hero */ .hero{background:url(hero.jpg);color:#fff}"} -->'
		. '<div class="hero"><style>.hero h2{margin:0}</style></div>'
		. '<!-- /wp:ekwa/div -->';

	$probe = ekwa_site_health_probe( $css_body );

	if ( $probe['ok'] ) {
		return $result;
	}

	// Couldn't make the request at all — that's a loopback problem (DNS, a
	// closed egress, basic auth on staging), not evidence of a WAF. Core's own
	// loopback test covers it, so don't raise a duplicate alarm here.
	if ( 0 === $probe['code'] ) {
		$result['status']      = 'recommended';
		$result['label']       = __( 'The editor-save check could not run', 'ekwa' );
		$result['description'] = '<p>' . sprintf(
			/* translators: %s: HTTP error message. */
			esc_html__( 'This site could not make a request to itself, so whether the block editor can save is unknown: %s', 'ekwa' ),
			'<code>' . esc_html( $probe['error'] ) . '</code>'
		) . '</p><p>' . esc_html__( 'See the “loopback request” result elsewhere on this page; this check can only run once that works.', 'ekwa' ) . '</p>';
		return $result;
	}

	// Something answered, but not our JSON. Find out whether it's the content.
	$plain            = ekwa_site_health_probe( 'plain text, no markup' );
	$payload_specific = ! empty( $plain['ok'] );
	$waf              = ekwa_site_health_waf_fingerprint( $probe['code'], $probe['body'] );

	$result['status'] = 'critical';
	$result['badge']['color'] = 'red';
	$result['label']  = __( 'Something on this server is blocking block editor saves', 'ekwa' );

	$description  = '<p>' . sprintf(
		/* translators: %d: HTTP status code. */
		esc_html__( 'A test save was answered with HTTP %d and a non-JSON body, which is what produces “Updating failed. The response is not a valid JSON response.” in the editor. The request was rejected before it reached WordPress, so nothing in the theme or in WordPress can report it more precisely than this.', 'ekwa' ),
		(int) $probe['code']
	) . '</p>';

	if ( '' !== $waf ) {
		$description .= '<p>' . sprintf(
			/* translators: %s: WAF name. */
			esc_html__( 'It looks like: %s', 'ekwa' ),
			'<strong>' . esc_html( $waf ) . '</strong>'
		) . '</p>';
	}

	$snippet = trim( wp_strip_all_tags( $probe['body'] ) );
	if ( '' !== $snippet ) {
		$description .= '<p>' . esc_html__( 'What the server sent back:', 'ekwa' ) . '</p>'
			. '<pre style="white-space:pre-wrap">' . esc_html( mb_substr( $snippet, 0, 300 ) ) . '</pre>';
	}

	$result['description'] = $description;
	$result['actions']     = ekwa_site_health_waf_remediation( $payload_specific );

	return $result;
}

/**
 * Register the test.
 *
 * Direct rather than async: the happy path is a single loopback request, and a
 * direct test also runs in the scheduled weekly check, so a site that starts
 * failing surfaces it on the dashboard without anyone opening Site Health.
 *
 * @param array $tests Registered tests.
 * @return array
 */
function ekwa_site_health_register_tests( $tests ) {
	$tests['direct']['ekwa_rest_write'] = array(
		'label' => __( 'Block editor saves', 'ekwa' ),
		'test'  => 'ekwa_site_health_rest_write_test',
	);
	return $tests;
}
add_filter( 'site_status_tests', 'ekwa_site_health_register_tests' );
