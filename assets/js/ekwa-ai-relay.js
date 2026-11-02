/**
 * Ekwa — MCP connector relay client.
 *
 * With the MCP connector on, an AI request is answered by the person's own AI
 * app (Claude Code, Codex, Gemini CLI, ChatGPT…), not by Gemini. WordPress can't
 * call that app, so the server queues a JOB and halts the request with HTTP 202
 * (inc/ekwa-ai-relay.php). This script is the browser's half:
 *
 *   1. It tags the theme's AI requests with `X-Ekwa-AI-Relay: <rid>` — one rid
 *      per original call, reused for every re-send of it. Without the header the
 *      server never relays (it has nothing that would re-send the request).
 *   2. On a 202 carrying `ekwaAiRelay` it polls the job (which is also the
 *      server's "someone is still waiting" heartbeat) and shows a small waiting
 *      card with what to tell the AI app.
 *   3. When the job is answered (done or failed) it re-sends the SAME request.
 *      The server then either finishes normally or queues the next step (another
 *      202), and the loop repeats until a normal response comes back — which is
 *      what the original caller receives, as if nothing had happened.
 *
 * Three entry points, all exposed on window.ekwaAiRelay:
 *   - an apiFetch middleware (block editor features), registered automatically;
 *   - ekwaAiRelay.fetch( url, init ) for window.fetch callers;
 *   - ekwaAiRelay.ajax( settings ) for jQuery callers.
 *
 * Only enqueued when the site's AI provider is the MCP connector
 * (inc/ekwa-ai-relay-client.php), and every caller falls back to its old code
 * when this script is absent — a Gemini site sends exactly what it always did.
 *
 * Server strings (feature label, AI app name) are untrusted and only ever go
 * into textContent — never innerHTML.
 */
( function () {
	'use strict';

	if ( window.ekwaAiRelay ) {
		return; // Loaded twice (block editor + admin hook on one screen).
	}

	var cfg = window.ekwaAiRelayConfig || {};
	var STR = cfg.strings || {};

	var RELAY_HEADER    = 'X-Ekwa-AI-Relay';
	var PROVIDER_HEADER = 'X-Ekwa-AI-Provider';
	var PENDING_CODE    = 'ekwa_ai_relay_pending';
	var CANCEL_CODE     = 'ekwa_ai_relay_cancelled';
	var GONE_CODE       = 'ekwa_ai_relay_gone';
	var POLL_MS         = 2000;

	function t( key, fallback ) {
		return ( typeof STR[ key ] === 'string' && STR[ key ] ) ? STR[ key ] : fallback;
	}

	function assign( target ) {
		for ( var i = 1; i < arguments.length; i++ ) {
			var src = arguments[ i ];
			if ( ! src ) { continue; }
			for ( var k in src ) {
				if ( Object.prototype.hasOwnProperty.call( src, k ) ) {
					target[ k ] = src[ k ];
				}
			}
		}
		return target;
	}

	/**
	 * Why a relayed call stopped without a response. Kept as its own type so the
	 * wrappers can tell it apart from the transport's own errors and reshape it
	 * for their callers (a REST error object, an Error, a jqXHR).
	 */
	function RelayAbort( code, message ) {
		this.code = code;
		this.message = message;
	}

	// ── Request ids ─────────────────────────────────────────────────────────────

	var RID_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

	function newRid() {
		var out = '';
		var c = window.crypto || window.msCrypto;
		while ( out.length < 24 ) {
			var buf = new Uint8Array( 32 );
			if ( c && c.getRandomValues ) {
				c.getRandomValues( buf );
			} else {
				for ( var j = 0; j < buf.length; j++ ) { buf[ j ] = Math.floor( Math.random() * 256 ); }
			}
			for ( var i = 0; i < buf.length && out.length < 24; i++ ) {
				// 248 = 4 × 62: dropping the bytes above it keeps every character equally likely.
				if ( buf[ i ] < 248 ) { out += RID_CHARS.charAt( buf[ i ] % 62 ); }
			}
		}
		return out;
	}

	// ── Which requests are relayed ──────────────────────────────────────────────

	function parseUrl( url ) {
		try {
			return new URL( String( url ), window.location.href );
		} catch ( e ) {
			return null;
		}
	}

	// The path REST routes hang off ("/wp-json/"), or '' with plain permalinks,
	// where the route travels in ?rest_route= instead.
	var restBasePath = ( function () {
		if ( ! cfg.restUrl ) { return ''; }
		var u = parseUrl( cfg.restUrl );
		return ( u && null === u.searchParams.get( 'rest_route' ) ) ? u.pathname : '';
	}() );

	function routeOfUrl( u ) {
		var rr = u.searchParams.get( 'rest_route' );
		if ( rr ) { return rr; }
		var p = u.pathname;
		if ( restBasePath && p.indexOf( restBasePath ) === 0 ) { return p.slice( restBasePath.length ); }
		var i = p.indexOf( '/wp-json/' );
		return -1 === i ? '' : p.slice( i + 9 );
	}

	/**
	 * Is this REST route one of the theme's (ekwa/v1/…) that may make an AI call?
	 * The relay's own routes and the MCP routes are not AI requests — except the
	 * connector's round-trip test, which exists to be relayed and refuses to run
	 * without the header.
	 */
	function isRelayRoute( route ) {
		var r = String( route || '' ).replace( /^\/+/, '' ).split( '?' )[ 0 ];
		if ( r.indexOf( 'ekwa/v1/' ) !== 0 ) { return false; }
		var rest = r.slice( 8 );
		if ( 'ai-relay/test' === rest.replace( /\/+$/, '' ) ) { return true; }
		return ! /^(ai-relay(\/|$)|mcp)/.test( rest );
	}

	/**
	 * For fetch / jQuery callers: a same-origin ekwa/v1 route or admin-ajax.php.
	 * Same origin only — a custom header on a cross-origin request would need a
	 * CORS preflight the REST API does not allow for it.
	 */
	function urlWantsRelay( url ) {
		var u = parseUrl( url );
		if ( ! u || u.origin !== window.location.origin ) { return false; }
		if ( /\/admin-ajax\.php$/.test( u.pathname ) ) { return true; }
		return isRelayRoute( routeOfUrl( u ) );
	}

	function objectHasHeader( headers, name ) {
		if ( ! headers || typeof headers !== 'object' ) { return false; }
		if ( typeof Headers !== 'undefined' && headers instanceof Headers ) { return headers.has( name ); }
		var lower = name.toLowerCase();
		for ( var k in headers ) {
			if ( Object.prototype.hasOwnProperty.call( headers, k ) && String( k ).toLowerCase() === lower ) {
				return true;
			}
		}
		return false;
	}

	/** The relay job in a 202 body, or null when the body is anything else. */
	function jobFromBody( body ) {
		if ( body && typeof body === 'object' && PENDING_CODE === body.code &&
			body.ekwaAiRelay && typeof body.ekwaAiRelay === 'object' &&
			typeof body.ekwaAiRelay.pollUrl === 'string' && body.ekwaAiRelay.pollUrl ) {
			return body.ekwaAiRelay;
		}
		return null;
	}

	// ── Talking to the job ──────────────────────────────────────────────────────

	function currentNonce() {
		// The block editor refreshes its nonce in place; prefer that over the one
		// printed with the page, which may have expired on a long-open screen.
		var mw = window.wp && window.wp.apiFetch && window.wp.apiFetch.nonceMiddleware;
		return ( mw && mw.nonce ) || cfg.nonce || '';
	}

	/** Resolves `{job}`, `{gone:true}`, or `{}` when the site couldn't be reached. */
	function getJob( job ) {
		return window.fetch( job.pollUrl, {
			method: 'GET',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { 'X-WP-Nonce': currentNonce(), Accept: 'application/json' }
		} ).then( function ( r ) {
			return r.json().then( function ( body ) {
				if ( r.ok && body && body.job && typeof body.job === 'object' ) { return { job: body.job }; }
				if ( 404 === r.status && body && GONE_CODE === body.code ) { return { gone: true }; }
				return {};
			}, function () { return {}; } );
		}, function () { return {}; } );
	}

	/** Cancel a job on the server. Always resolves: the caller moves on either way. */
	function deleteJob( job ) {
		return window.fetch( job.pollUrl, {
			method: 'DELETE',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': currentNonce(), Accept: 'application/json' }
		} ).then( function () {}, function () {} );
	}

	/**
	 * Wait until a job is answered.
	 *
	 * Resolves 'resend' (answered — send the request again) or 'gemini' (the
	 * person chose Gemini; call.extra now carries the provider header). Rejects
	 * with a RelayAbort when cancelled here, or when the job stopped waiting
	 * somewhere else (cancelled elsewhere, cleared, expired, abandoned).
	 */
	function waitForJob( job, call ) {
		return new Promise( function ( resolve, reject ) {
			var current = job;
			var timer = null;
			var settled = false;
			var card = panel.cardFor( call );

			card.show( current );

			function gone() {
				settled = true;
				reject( new RelayAbort( GONE_CODE, t( 'gone', 'This AI request is no longer waiting for your AI app — it was cancelled or expired. Try again.' ) ) );
			}

			card.onCancel = function () {
				if ( settled ) { return; }
				settled = true;
				clearTimeout( timer );
				card.busy( t( 'cancelling', 'Cancelling…' ) );
				deleteJob( current ).then( function () {
					reject( new RelayAbort( CANCEL_CODE, t( 'cancelled', 'Cancelled.' ) ) );
				} );
			};

			card.onGemini = function () {
				if ( settled ) { return; }
				settled = true;
				clearTimeout( timer );
				card.busy( t( 'askingGemini', 'Asking Gemini instead…' ) );
				// Cancel first, so an AI app that picks the job up meanwhile is
				// told nobody is waiting for it any more.
				deleteJob( current ).then( function () {
					call.extra[ PROVIDER_HEADER ] = 'gemini';
					resolve( 'gemini' );
				} );
			};

			function poll() {
				if ( settled ) { return; }
				getJob( current ).then( function ( res ) {
					if ( settled ) { return; }
					if ( res.gone ) { gone(); return; }
					if ( res.job ) {
						current = assign( {}, current, res.job );
						var st = String( current.status || '' );
						if ( 'done' === st || 'failed' === st ) {
							settled = true;
							card.busy( t( 'finishing', 'Finishing…' ) );
							resolve( 'resend' );
							return;
						}
						if ( 'cancelled' === st || 'abandoned' === st ) { gone(); return; }
						card.show( current );
					} else {
						card.offline();
					}
					// Keep polling in background tabs too: each poll is what tells
					// the server the person is still waiting.
					timer = setTimeout( poll, POLL_MS );
				} );
			}

			timer = setTimeout( poll, POLL_MS );
		} );
	}

	/**
	 * Run one relayed call.
	 *
	 * @param {Function} send     ( extraHeaders ) → Promise of a transport result.
	 * @param {Function} jobOf    ( result ) → Promise of the relay job in it, or null.
	 * @return {Promise} The final transport result; rejects with the transport's
	 *                   own error, or a RelayAbort.
	 */
	function relayed( send, jobOf ) {
		var call = { rid: newRid(), extra: {}, started: 0, card: null };
		call.extra[ RELAY_HEADER ] = call.rid;

		function attempt() {
			return send( assign( {}, call.extra ) ).then( function ( result ) {
				return jobOf( result ).then( function ( job ) {
					if ( ! job ) { return result; }
					return waitForJob( job, call ).then( attempt );
				} );
			} );
		}

		return attempt().then( function ( result ) {
			panel.remove( call );
			return result;
		}, function ( err ) {
			panel.remove( call );
			throw err;
		} );
	}

	// ── apiFetch middleware ─────────────────────────────────────────────────────

	function apiFetchWantsRelay( options ) {
		if ( ! options || 'POST' !== String( options.method || 'GET' ).toUpperCase() ) { return false; }
		if ( objectHasHeader( options.headers, RELAY_HEADER ) ) { return false; } // Already relayed.
		if ( typeof options.path === 'string' ) { return isRelayRoute( options.path ); }
		if ( typeof options.url === 'string' ) {
			var u = parseUrl( options.url );
			return !! u && u.origin === window.location.origin && isRelayRoute( routeOfUrl( u ) );
		}
		if ( typeof options.namespace === 'string' ) {
			return isRelayRoute( options.namespace.replace( /\/+$/, '' ) + '/' + String( options.endpoint || '' ).replace( /^\/+/, '' ) );
		}
		return false;
	}

	/**
	 * apiFetch resolves 2xx with the parsed body, so a relay reply arrives as an
	 * object carrying `ekwaAiRelay`; with `parse: false` it is a 202 Response.
	 * Each re-send goes back through next(), so the core nonce middleware adds a
	 * fresh nonce every time.
	 */
	function middleware( options, next ) {
		if ( ! apiFetchWantsRelay( options ) ) {
			return next( options );
		}
		var raw = false === options.parse;

		return relayed( function ( extra ) {
			var opts = assign( {}, options );
			opts.headers = assign( {}, options.headers, extra );
			return next( opts );
		}, function ( result ) {
			if ( raw ) {
				if ( result && 202 === result.status && typeof result.clone === 'function' ) {
					return result.clone().json().then( jobFromBody, function () { return null; } );
				}
				return Promise.resolve( null );
			}
			return Promise.resolve( jobFromBody( result ) );
		} ).catch( function ( err ) {
			if ( err instanceof RelayAbort ) {
				// The shape apiFetch rejects with for a REST error.
				throw { code: err.code, message: err.message, data: { status: 0 } };
			}
			throw err;
		} );
	}

	// ── fetch ───────────────────────────────────────────────────────────────────

	/**
	 * window.fetch-compatible. Resolves with the final Response; rejects with an
	 * Error carrying `.code` when the wait is cancelled or the job is gone.
	 *
	 * @param {string|URL} url
	 * @param {Object}     [init]
	 * @return {Promise<Response>}
	 */
	function relayFetch( url, init ) {
		var isUrl = typeof url === 'string' || ( typeof URL !== 'undefined' && url instanceof URL );
		var method = String( ( init && init.method ) || 'GET' ).toUpperCase();
		if ( ! isUrl || 'POST' !== method || ! urlWantsRelay( url ) || objectHasHeader( init && init.headers, RELAY_HEADER ) ) {
			return window.fetch.apply( window, arguments );
		}
		init = init || {};

		return relayed( function ( extra ) {
			var headers = new Headers( init.headers || {} );
			for ( var k in extra ) {
				if ( Object.prototype.hasOwnProperty.call( extra, k ) ) { headers.set( k, extra[ k ] ); }
			}
			return window.fetch( url, assign( {}, init, { headers: headers } ) );
		}, function ( r ) {
			if ( ! r || 202 !== r.status ) { return Promise.resolve( null ); }
			return r.clone().json().then( jobFromBody, function () { return null; } );
		} ).catch( function ( err ) {
			if ( err instanceof RelayAbort ) {
				var e = new Error( err.message );
				e.code = err.code;
				e.data = { status: 0 };
				throw e;
			}
			throw err;
		} );
	}

	// ── jQuery ──────────────────────────────────────────────────────────────────

	/**
	 * $.ajax-compatible. Returns a jQuery promise that resolves with
	 * ( data, textStatus, jqXHR ) of the final response and rejects with
	 * ( jqXHR, textStatus, errorThrown ). A cancelled wait rejects with a stand-in
	 * jqXHR whose responseJSON is `{ code, message }`.
	 *
	 * success / error / complete in settings run once, for the final outcome —
	 * not for the 202 replies in between.
	 *
	 * @param {Object} settings
	 * @return {Object} jQuery promise (or the plain jqXHR when not relayed).
	 */
	function relayAjax( settings ) {
		var $ = window.jQuery;
		if ( ! $ ) {
			throw new Error( 'ekwaAiRelay.ajax needs jQuery.' );
		}
		settings = settings || {};
		var method = String( settings.method || settings.type || 'GET' ).toUpperCase();
		if ( 'POST' !== method || ! settings.url || ! urlWantsRelay( settings.url ) || objectHasHeader( settings.headers, RELAY_HEADER ) ) {
			return $.ajax( settings );
		}

		var dfd = $.Deferred();
		var ctx = settings.context || settings;
		var onSuccess = settings.success;
		var onError = settings.error;
		var onComplete = settings.complete;
		var base = $.extend( {}, settings );
		delete base.success;
		delete base.error;
		delete base.complete;

		function each( fns, args ) {
			fns = Array.isArray( fns ) ? fns : ( fns ? [ fns ] : [] );
			for ( var i = 0; i < fns.length; i++ ) {
				if ( typeof fns[ i ] === 'function' ) { fns[ i ].apply( ctx, args ); }
			}
		}

		relayed( function ( extra ) {
			return new Promise( function ( resolve ) {
				$.ajax( $.extend( {}, base, { headers: $.extend( {}, base.headers, extra ) } ) ).then(
					function ( data, textStatus, xhr ) { resolve( { ok: true, args: [ data, textStatus, xhr ] } ); },
					function ( xhr, textStatus, errorThrown ) { resolve( { ok: false, args: [ xhr, textStatus, errorThrown ] } ); }
				);
			} );
		}, function ( res ) {
			var xhr = res.args[ 2 ];
			if ( ! res.ok || ! xhr || 202 !== xhr.status ) { return Promise.resolve( null ); }
			var body = xhr.responseJSON;
			if ( ! body ) {
				try { body = JSON.parse( xhr.responseText ); } catch ( e ) { body = null; }
			}
			return Promise.resolve( jobFromBody( body ) );
		} ).then( function ( res ) {
			if ( res.ok ) {
				each( onSuccess, res.args );
				dfd.resolveWith( ctx, res.args );
				each( onComplete, [ res.args[ 2 ], res.args[ 1 ] ] );
			} else {
				each( onError, res.args );
				dfd.rejectWith( ctx, res.args );
				each( onComplete, [ res.args[ 0 ], res.args[ 1 ] ] );
			}
		}, function ( err ) {
			var isAbort = err instanceof RelayAbort;
			var body = {
				code: isAbort ? err.code : 'ekwa_ai_relay_error',
				message: ( err && err.message ) ? String( err.message ) : 'Request failed.',
				data: { status: 0 }
			};
			var textStatus = ( isAbort && CANCEL_CODE === err.code ) ? 'abort' : 'error';
			var fakeXhr = {
				readyState: 4,
				status: 0,
				statusText: textStatus,
				responseJSON: body,
				responseText: JSON.stringify( body ),
				getResponseHeader: function () { return null; },
				getAllResponseHeaders: function () { return ''; },
				abort: function () {}
			};
			var args = [ fakeXhr, textStatus, body.message ];
			each( onError, args );
			dfd.rejectWith( ctx, args );
			each( onComplete, [ fakeXhr, textStatus ] );
		} );

		return dfd.promise();
	}

	// ── The waiting panel ───────────────────────────────────────────────────────

	var CSS =
		'.ekwa-air{position:fixed;right:16px;bottom:16px;z-index:1000001;width:320px;max-width:calc(100vw - 32px);max-height:calc(100vh - 32px);overflow:auto;display:flex;flex-direction:column;gap:8px;font:13px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;color:#1e1e1e;text-align:left}' +
		'.ekwa-air[hidden]{display:none}' +
		'.ekwa-air *{box-sizing:border-box}' +
		'.ekwa-air__sr{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);border:0;white-space:nowrap}' +
		'.ekwa-air__card{background:#fff;border:1px solid #dcdcde;border-left:3px solid var(--wp-admin-theme-color,#2271b1);border-radius:4px;box-shadow:0 4px 16px rgba(0,0,0,.12);padding:10px 12px}' +
		'.ekwa-air__head{display:flex;justify-content:space-between;align-items:baseline;gap:8px}' +
		'.ekwa-air__label{font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
		'.ekwa-air__time{flex:none;color:#646970;font-size:12px;font-variant-numeric:tabular-nums}' +
		'.ekwa-air__status{display:flex;align-items:center;gap:6px;margin-top:2px;color:#3c434a}' +
		'.ekwa-air__dot{flex:none;width:8px;height:8px;border-radius:50%;background:var(--wp-admin-theme-color,#2271b1);animation:ekwa-air-pulse 1.4s ease-in-out infinite}' +
		'.ekwa-air__card.is-claimed .ekwa-air__dot{background:#00a32a}' +
		'.ekwa-air__card.is-offline .ekwa-air__dot{background:#dba617}' +
		'@keyframes ekwa-air-pulse{0%,100%{opacity:1}50%{opacity:.3}}' +
		'@media (prefers-reduced-motion:reduce){.ekwa-air__dot{animation:none}}' +
		'.ekwa-air__help{margin-top:8px;padding-top:8px;border-top:1px solid #f0f0f1}' +
		'.ekwa-air__help[hidden],.ekwa-air__nokey[hidden],.ekwa-air__actions[hidden],.ekwa-air__btn[hidden]{display:none}' +
		'.ekwa-air__ask{color:#646970;font-size:12px}' +
		'.ekwa-air__copyrow{display:flex;gap:6px;align-items:center;margin-top:3px}' +
		'.ekwa-air__cmd{flex:1;min-width:0;background:#f6f7f7;border:1px solid #dcdcde;border-radius:3px;padding:3px 6px;font:12px/1.4 Menlo,Consolas,Monaco,monospace;color:#1e1e1e;overflow-wrap:anywhere;-webkit-user-select:all;user-select:all}' +
		'.ekwa-air__hint{margin:6px 0 0;color:#646970;font-size:12px;line-height:1.45}' +
		'.ekwa-air__hint code{font:11px/1.4 Menlo,Consolas,Monaco,monospace;background:#f6f7f7;color:#1e1e1e;padding:0 3px;border-radius:2px;overflow-wrap:anywhere}' +
		'.ekwa-air__nokey{margin:8px 0 0;font-size:12px;color:#3c434a}' +
		'.ekwa-air__nokey a{color:var(--wp-admin-theme-color,#2271b1);text-decoration:underline}' +
		'.ekwa-air__actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}' +
		'.ekwa-air .ekwa-air__btn{-webkit-appearance:none;appearance:none;margin:0;min-height:28px;padding:3px 10px;font:inherit;font-size:12px;line-height:1.4;border-radius:3px;border:1px solid var(--wp-admin-theme-color,#2271b1);background:#fff;color:var(--wp-admin-theme-color,#2271b1);cursor:pointer;box-shadow:none}' +
		'.ekwa-air .ekwa-air__btn:hover{background:#f6f7f7}' +
		'.ekwa-air .ekwa-air__btn--quiet{border-color:#c3c4c7;color:#1e1e1e}' +
		'.ekwa-air .ekwa-air__btn:focus{outline:none}' +
		'.ekwa-air .ekwa-air__btn:focus-visible,.ekwa-air__nokey a:focus-visible{outline:2px solid var(--wp-admin-theme-color,#2271b1);outline-offset:1px}' +
		'.ekwa-air .ekwa-air__btn:disabled{opacity:.5;cursor:default}';

	function el( tag, attrs, text ) {
		var n = document.createElement( tag );
		if ( attrs ) {
			for ( var k in attrs ) {
				if ( Object.prototype.hasOwnProperty.call( attrs, k ) ) { n.setAttribute( k, attrs[ k ] ); }
			}
		}
		if ( null != text ) { n.textContent = String( text ); }
		return n;
	}

	/** Replace %s / %1$s… in a translated string with nodes, keeping the rest as text. */
	function fillNodes( parent, template, nodes ) {
		var re = /%(?:(\d)\$)?s/g;
		var last = 0;
		var auto = 0;
		var m;
		while ( null !== ( m = re.exec( template ) ) ) {
			parent.appendChild( document.createTextNode( template.slice( last, m.index ) ) );
			var idx = m[ 1 ] ? parseInt( m[ 1 ], 10 ) - 1 : auto++;
			if ( nodes[ idx ] ) { parent.appendChild( nodes[ idx ] ); }
			last = re.lastIndex;
		}
		parent.appendChild( document.createTextNode( template.slice( last ) ) );
	}

	function formatElapsed( ms ) {
		var s = Math.max( 0, Math.floor( ms / 1000 ) );
		var h = Math.floor( s / 3600 );
		var m = Math.floor( ( s % 3600 ) / 60 );
		var sec = s % 60;
		var mm = h ? ( m < 10 ? '0' + m : String( m ) ) : String( m );
		return ( h ? h + ':' : '' ) + mm + ':' + ( sec < 10 ? '0' + sec : sec );
	}

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			var ta = el( 'textarea', { readonly: '', 'aria-hidden': 'true', style: 'position:fixed;top:-1000px;opacity:0' } );
			ta.value = text;
			document.body.appendChild( ta );
			ta.select();
			var ok = false;
			try { ok = document.execCommand( 'copy' ); } catch ( e ) { ok = false; }
			document.body.removeChild( ta );
			if ( ok ) { resolve(); } else { reject(); }
		} );
	}

	var panel = ( function () {
		var root = null;
		var live = null;
		var ticker = null;
		var cards = [];

		function ensureRoot() {
			if ( root && document.body.contains( root ) ) { return; }
			if ( ! document.getElementById( 'ekwa-air-css' ) ) {
				var style = el( 'style', { id: 'ekwa-air-css' } );
				style.textContent = CSS;
				( document.head || document.documentElement ).appendChild( style );
			}
			root = el( 'div', { 'class': 'ekwa-air', role: 'region', 'aria-label': t( 'panelLabel', 'AI requests waiting for your AI app' ) } );
			// One polite live region for status changes, rather than making the
			// whole panel live: the cards hold buttons and a ticking clock that
			// a screen reader should not read out every second.
			live = el( 'div', { 'class': 'ekwa-air__sr', role: 'status', 'aria-live': 'polite', 'aria-atomic': 'true' } );
			root.appendChild( live );
			document.body.appendChild( root );
		}

		function announce( text ) {
			if ( ! live ) { return; }
			// Deferred, so the live region exists before its first update — a
			// region created and filled in one go is often not announced.
			setTimeout( function () { if ( live ) { live.textContent = text; } }, 120 );
		}

		function tick() {
			var now = Date.now();
			for ( var i = 0; i < cards.length; i++ ) {
				cards[ i ].time.textContent = formatElapsed( now - cards[ i ].call.started );
			}
		}

		function buildCard( call ) {
			var c = { call: call, onCancel: null, onGemini: null, statusText: '' };
			var serverName = String( cfg.serverName || 'ekwa' );

			c.node = el( 'div', { 'class': 'ekwa-air__card' } );

			var head = el( 'div', { 'class': 'ekwa-air__head' } );
			c.label = el( 'span', { 'class': 'ekwa-air__label' } );
			c.time = el( 'span', { 'class': 'ekwa-air__time', 'aria-hidden': 'true' }, '0:00' );
			head.appendChild( c.label );
			head.appendChild( c.time );
			c.node.appendChild( head );

			var status = el( 'div', { 'class': 'ekwa-air__status' } );
			status.appendChild( el( 'span', { 'class': 'ekwa-air__dot', 'aria-hidden': 'true' } ) );
			c.status = el( 'span', { 'class': 'ekwa-air__status-text' } );
			status.appendChild( c.status );
			c.node.appendChild( status );

			c.help = el( 'div', { 'class': 'ekwa-air__help' } );
			c.help.appendChild( el( 'div', { 'class': 'ekwa-air__ask' }, t( 'askApp', 'In your AI app, say:' ) ) );
			var row = el( 'div', { 'class': 'ekwa-air__copyrow' } );
			var instruction = t( 'instruction', 'Run my pending Ekwa AI jobs' );
			row.appendChild( el( 'code', { 'class': 'ekwa-air__cmd' }, instruction ) );
			var copy = el( 'button', { type: 'button', 'class': 'ekwa-air__btn ekwa-air__btn--quiet', 'aria-label': t( 'copyLabel', 'Copy the instruction for your AI app' ) }, t( 'copy', 'Copy' ) );
			copy.addEventListener( 'click', function () {
				copyText( instruction ).then( function () {
					copy.textContent = t( 'copied', 'Copied' );
					setTimeout( function () { copy.textContent = t( 'copy', 'Copy' ); }, 1500 );
				}, function () {} );
			} );
			row.appendChild( copy );
			c.help.appendChild( row );
			var hint = el( 'p', { 'class': 'ekwa-air__hint' } );
			fillNodes( hint, t( 'hint', 'In Claude Code: %1$s — or start %2$s once to have jobs picked up automatically' ), [
				el( 'code', null, '/mcp__' + serverName + '__run_jobs' ),
				el( 'code', null, '/mcp__' + serverName + '__listen' )
			] );
			c.help.appendChild( hint );
			c.node.appendChild( c.help );

			c.nokey = el( 'p', { 'class': 'ekwa-air__nokey' } );
			c.nokey.appendChild( document.createTextNode( t( 'noKey', 'No AI app is connected to your account yet.' ) + ' ' ) );
			c.nokey.appendChild( el( 'a', { href: String( cfg.connectUrl || '' ) }, t( 'connect', 'Connect an AI app' ) ) );
			c.nokey.hidden = true;
			c.node.appendChild( c.nokey );

			c.actions = el( 'div', { 'class': 'ekwa-air__actions' } );
			c.cancelBtn = el( 'button', { type: 'button', 'class': 'ekwa-air__btn ekwa-air__btn--quiet' }, t( 'cancel', 'Cancel' ) );
			c.cancelBtn.addEventListener( 'click', function () { if ( c.onCancel ) { c.onCancel(); } } );
			c.geminiBtn = el( 'button', { type: 'button', 'class': 'ekwa-air__btn' }, t( 'useGemini', 'Use Gemini for this one' ) );
			c.geminiBtn.addEventListener( 'click', function () { if ( c.onGemini ) { c.onGemini(); } } );
			c.geminiBtn.hidden = true;
			c.actions.appendChild( c.cancelBtn );
			c.actions.appendChild( c.geminiBtn );
			c.node.appendChild( c.actions );

			function setStatus( text ) {
				c.status.textContent = text;
				if ( text !== c.statusText ) {
					c.statusText = text;
					announce( ( c.label.textContent ? c.label.textContent + ': ' : '' ) + text );
				}
			}

			/** Render a job's state, with the buttons live. */
			c.show = function ( job ) {
				var label = String( job.label || '' ) || t( 'defaultLabel', 'AI request' );
				c.label.textContent = label;
				c.label.title = label;

				var claimed = 'claimed' === job.status;
				var client = String( job.client || '' );
				c.node.className = 'ekwa-air__card' + ( claimed ? ' is-claimed' : '' );
				setStatus( claimed
					? ( client ? t( 'claimedBy', 'Picked up by %s…' ).replace( '%s', client ) : t( 'claimedAnon', 'Picked up by your AI app…' ) )
					: t( 'waiting', 'Waiting for your AI app…' ) );

				// Once an app has the job the instructions have done their work.
				c.help.hidden = claimed;
				var hasKey = ( 'boolean' === typeof job.hasKey ) ? job.hasKey : !! cfg.hasKey;
				var canGemini = ( 'boolean' === typeof job.canGemini ) ? job.canGemini : !! cfg.canGemini;
				c.nokey.hidden = hasKey || ! cfg.connectUrl;
				c.geminiBtn.hidden = ! canGemini;
				c.actions.hidden = false;
				c.cancelBtn.disabled = false;
				c.geminiBtn.disabled = false;
			};

			/** Working on our side (finishing, cancelling…): no buttons. */
			c.busy = function ( text ) {
				c.node.className = 'ekwa-air__card';
				c.help.hidden = true;
				c.nokey.hidden = true;
				c.actions.hidden = true;
				setStatus( text );
			};

			c.offline = function () {
				c.node.className = 'ekwa-air__card is-offline';
				setStatus( t( 'offline', 'Cannot reach the site — still trying…' ) );
			};

			return c;
		}

		return {
			cardFor: function ( call ) {
				if ( call.card ) { return call.card; }
				ensureRoot();
				call.started = call.started || Date.now();
				call.card = buildCard( call );
				cards.push( call.card );
				root.appendChild( call.card.node );
				root.hidden = false;
				tick();
				if ( ! ticker ) { ticker = setInterval( tick, 1000 ); }
				return call.card;
			},

			remove: function ( call ) {
				var c = call.card;
				if ( ! c ) { return; }
				call.card = null;
				for ( var i = cards.length - 1; i >= 0; i-- ) {
					if ( cards[ i ] === c ) { cards.splice( i, 1 ); }
				}
				// Don't strand keyboard focus on a button that is about to vanish.
				var hadFocus = c.node.contains( document.activeElement );
				if ( c.node.parentNode ) { c.node.parentNode.removeChild( c.node ); }
				if ( hadFocus && document.activeElement && document.activeElement.blur ) { document.activeElement.blur(); }
				if ( ! cards.length ) {
					if ( ticker ) { clearInterval( ticker ); ticker = null; }
					// Kept in the document (hidden) so its live region is ready next time.
					if ( root ) { root.hidden = true; }
				}
			}
		};
	}() );

	// ── Public API + apiFetch registration ──────────────────────────────────────

	window.ekwaAiRelay = {
		fetch: relayFetch,
		ajax: relayAjax,
		middleware: middleware,
		wantsRelay: urlWantsRelay
	};

	var registered = false;
	function registerApiFetch() {
		var api = window.wp && window.wp.apiFetch;
		if ( registered || ! api || typeof api.use !== 'function' ) { return; }
		registered = true;
		// use() puts this middleware outermost, so every re-send still passes
		// through core's nonce and root-URL middlewares.
		api.use( middleware );
	}

	registerApiFetch();
	if ( ! registered ) {
		// On screens without a hard dependency apiFetch may load after this file.
		document.addEventListener( 'DOMContentLoaded', registerApiFetch );
		window.addEventListener( 'load', registerApiFetch );
	}
}() );
