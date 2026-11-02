/**
 * Ekwa MCP connector — connection keys, setup instructions, test job and job list.
 *
 * Fills every `.ekwa-mcp[data-ekwa-mcp]` shell rendered by
 * ekwa_mcp_admin_render_component() (inc/ekwa-mcp-admin.php): on the profile
 * screen and in Ekwa Settings → AI. Both live inside a <form>, so every button
 * is type="button" and the label input has no name and swallows Enter —
 * nothing here may submit or add to the surrounding form.
 *
 * The plaintext key exists only in the POST /mcp-keys response. It is kept in
 * the DOM until the person hides it or leaves the page, and never written to
 * storage or the console. Server strings are inserted with textContent only.
 */
( function () {
	'use strict';

	var G = window.ekwaMcpAdmin;
	if ( ! G ) {
		return;
	}
	var S = G.strings || {};

	// ─── small DOM helpers ───────────────────────────────────────────────────

	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( text !== undefined && text !== null ) {
			node.textContent = String( text );
		}
		return node;
	}

	function button( label, cls ) {
		var b = el( 'button', 'button' + ( cls ? ' ' + cls : '' ), label );
		b.type = 'button';
		return b;
	}

	function fmt( str, value ) {
		return String( str || '' ).replace( /%[sd]/, String( value ) );
	}

	function clear( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function setStatus( node, text, kind ) {
		node.textContent = text || '';
		node.className = 'ekwa-mcp__status' + ( kind ? ' is-' + kind : '' );
	}

	// Appends a query string to a REST URL that may already carry one
	// (?rest_route=… on sites without pretty permalinks).
	function withQuery( url, query ) {
		return query ? url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + query : url;
	}

	// ─── REST ────────────────────────────────────────────────────────────────

	function request( method, url, body, fetcher ) {
		var init = {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': G.nonce, 'Accept': 'application/json' }
		};
		if ( body ) {
			init.headers[ 'Content-Type' ] = 'application/json';
			init.body = JSON.stringify( body );
		}
		return ( fetcher || window.fetch ).call( window, url, init ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				if ( ! res.ok ) {
					var err = new Error( ( data && data.message ) || S.error );
					err.data = data;
					throw err;
				}
				return data;
			} );
		} );
	}

	// ─── clipboard ───────────────────────────────────────────────────────────

	function copyText( text, btn ) {
		function done( ok ) {
			var original = S.copy;
			btn.textContent = ok ? S.copied : S.copyFailed;
			window.setTimeout( function () {
				btn.textContent = original;
			}, 2000 );
		}
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( function () {
				done( true );
			}, function () {
				done( fallbackCopy( text ) );
			} );
			return;
		}
		done( fallbackCopy( text ) );
	}

	// Plain-HTTP sites (most local ones) have no navigator.clipboard.
	function fallbackCopy( text ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.setAttribute( 'readonly', '' );
		ta.style.position = 'fixed';
		ta.style.top = '-1000px';
		document.body.appendChild( ta );
		ta.select();
		var ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch ( e ) {
			ok = false;
		}
		document.body.removeChild( ta );
		return ok;
	}

	// A read-only value with a Copy button. Multi-line values use <pre>.
	function copyable( value, multiline ) {
		var wrap = el( 'div', 'ekwa-mcp__copy' );
		var box = el( multiline ? 'pre' : 'code', 'ekwa-mcp__code', value );
		var btn = button( S.copy, 'button-small ekwa-mcp__copy-btn' );
		btn.addEventListener( 'click', function () {
			copyText( value, btn );
		} );
		wrap.appendChild( box );
		wrap.appendChild( btn );
		return wrap;
	}

	// ─── time ────────────────────────────────────────────────────────────────

	function Clock( serverNow ) {
		// Offset between the server and this computer, so ages stay right on a
		// machine whose clock is off.
		this.offset = serverNow ? serverNow - Math.floor( Date.now() / 1000 ) : 0;
	}
	Clock.prototype.now = function () {
		return Math.floor( Date.now() / 1000 ) + this.offset;
	};
	Clock.prototype.ago = function ( ts ) {
		if ( ! ts ) {
			return S.never;
		}
		var diff = Math.max( 0, this.now() - ts );
		if ( diff < 60 ) {
			return S.justNow;
		}
		if ( diff < 3600 ) {
			return fmt( S.minutesAgo, Math.floor( diff / 60 ) );
		}
		if ( diff < 86400 ) {
			return fmt( S.hoursAgo, Math.floor( diff / 3600 ) );
		}
		return fmt( S.daysAgo, Math.floor( diff / 86400 ) );
	};
	Clock.prototype.date = function ( ts ) {
		if ( ! ts ) {
			return '—';
		}
		try {
			return new Date( ts * 1000 ).toLocaleDateString();
		} catch ( e ) {
			return '—';
		}
	};

	// ─── setup instructions ──────────────────────────────────────────────────

	// The exact command shapes each app documents. A URL with characters a
	// shell would interpret (sites without pretty permalinks put "?" in it) is
	// quoted; a plain one is left as the apps' own docs show it.
	function shellArg( value ) {
		return /[\s?&*;|<>()$`'"]/.test( value ) ? '"' + value + '"' : value;
	}

	function appBlock( title, unavailable ) {
		var block = el( 'div', 'ekwa-mcp__app' + ( unavailable ? ' ekwa-mcp__app--unavailable' : '' ) );
		var h = el( 'h4', 'ekwa-mcp__app-title', title );
		if ( unavailable ) {
			h.appendChild( el( 'span', 'ekwa-mcp__badge', S.unavailable ) );
		}
		block.appendChild( h );
		if ( unavailable ) {
			block.appendChild( el( 'p', 'description', S.unavailableWhy ) );
		}
		return block;
	}

	/**
	 * @param {Object} facts  GET/POST /mcp-keys facts.
	 * @param {string} key    The new key, or '' for the placeholder.
	 * @param {string} url    The connection URL with the key, or ''.
	 */
	function buildSetup( facts, key, url ) {
		var wrap = el( 'div', 'ekwa-mcp__setup' );
		var name = String( facts.serverName || 'ekwa' );
		var endpoint = String( facts.endpoint || '' );
		var theKey = key || S.keyPlaceholder;
		var theUrl = url || ( endpoint + '/' + S.keyPlaceholder );
		var local = !! facts.isLocal;

		wrap.appendChild( el( 'h3', '', S.setupHeading ) );
		wrap.appendChild( el( 'p', 'description', key ? S.setupIntroKey : S.setupIntroNoKey ) );
		if ( local ) {
			wrap.appendChild( el( 'div', 'notice notice-info inline ekwa-mcp__notice', S.localNotice ) );
		}

		var block;
		var apps = [];

		// "Which AI app do you use?" — one button per app; only the chosen app's
		// steps are shown. The choice is a per-browser convenience (never the key).
		var picker = el( 'div', 'ekwa-mcp__picker' );
		picker.setAttribute( 'role', 'group' );
		picker.appendChild( el( 'p', 'ekwa-mcp__picker-label', S.pickHeading || 'Which AI app will you use?' ) );
		var pickerRow = el( 'div', 'ekwa-mcp__picker-row' );
		picker.appendChild( pickerRow );
		wrap.appendChild( picker );

		var realAppend = wrap.appendChild.bind( wrap );
		wrap.appendChild = function ( node ) {
			if ( node && node.classList && node.classList.contains( 'ekwa-mcp__app' ) ) {
				var title = node.querySelector( '.ekwa-mcp__app-title' );
				apps.push( { node: node, label: title ? title.firstChild.textContent : '' } );
			}
			return realAppend( node );
		};

		block = appBlock( S.appClaudeCode, false );
		block.appendChild( el( 'p', '', S.claudeCodeStep ) );
		block.appendChild( copyable(
			'claude mcp add -s user --transport http ' + name + ' ' + shellArg( endpoint ) +
			' --header "Authorization: Bearer ' + theKey + '"'
		) );
		wrap.appendChild( block );

		block = appBlock( S.appCodex, false );
		block.appendChild( el( 'p', '', S.codexStep ) );
		block.appendChild( copyable( 'codex mcp add ' + name + ' --url ' + shellArg( theUrl ) ) );
		wrap.appendChild( block );

		block = appBlock( S.appGemini, false );
		block.appendChild( el( 'p', '', S.geminiStep ) );
		block.appendChild( copyable(
			'gemini mcp add -s user -t http --trust ' + name + ' ' + shellArg( endpoint ) +
			' -H "Authorization: Bearer ' + theKey + '"'
		) );
		wrap.appendChild( block );

		block = appBlock( S.appDesktop, false );
		if ( local ) {
			// Desktop's own connectors connect from Anthropic's servers, so a
			// local site goes through the mcp-remote bridge on this computer.
			var args = [ '-y', 'mcp-remote', theUrl ];
			if ( /^http:\/\//i.test( theUrl ) ) {
				args.push( '--allow-http' );
			}
			var servers = {};
			servers[ name ] = { command: 'npx', args: args };
			block.appendChild( el( 'p', '', S.desktopLocal ) );
			block.appendChild( copyable( JSON.stringify( { mcpServers: servers }, null, 2 ), true ) );
		} else {
			block.appendChild( el( 'p', '', fmt( S.desktopLive, facts.siteName || name ) ) );
			block.appendChild( copyable( theUrl ) );
		}
		wrap.appendChild( block );

		block = appBlock( S.appClaudeAi, local );
		if ( ! local ) {
			block.appendChild( el( 'p', '', S.claudeAiStep ) );
			block.appendChild( copyable( theUrl ) );
		}
		wrap.appendChild( block );

		block = appBlock( S.appChatGpt, local );
		if ( ! local ) {
			block.appendChild( el( 'p', '', S.chatGptStep ) );
			block.appendChild( copyable( theUrl ) );
			block.appendChild( el( 'p', 'description', S.chatGptAuth ) );
		}
		wrap.appendChild( block );
		wrap.appendChild = realAppend;

		var stored = '';
		try { stored = window.localStorage.getItem( 'ekwaMcpApp' ) || ''; } catch ( e ) {}
		function choose( index ) {
			apps.forEach( function ( app, i ) {
				app.node.style.display = i === index ? '' : 'none';
				app.btn.className = 'button' + ( i === index ? ' button-primary' : '' );
				app.btn.setAttribute( 'aria-pressed', i === index ? 'true' : 'false' );
			} );
			try { window.localStorage.setItem( 'ekwaMcpApp', apps[ index ].label ); } catch ( e ) {}
		}
		var initial = 0;
		apps.forEach( function ( app, i ) {
			app.btn = button( app.label, 'button' );
			app.btn.addEventListener( 'click', function () { choose( i ); } );
			pickerRow.appendChild( app.btn );
			if ( app.label === stored ) {
				initial = i;
			}
		} );
		if ( apps.length ) {
			choose( initial );
		}

		// Using it.
		var using = el( 'div', 'ekwa-mcp__using' );
		using.appendChild( el( 'h3', '', S.usingHeading ) );
		using.appendChild( el( 'p', '', S.usingAny ) );
		using.appendChild( copyable( S.usingPhrase ) );
		var p = el( 'p', '' );
		appendWithCode( p, S.usingClaudeRun, '/mcp__' + name + '__run_jobs' );
		p.appendChild( document.createTextNode( ' ' ) );
		appendWithCode( p, S.usingClaudeListen, '/mcp__' + name + '__listen' );
		using.appendChild( p );
		wrap.appendChild( using );

		return wrap;
	}

	// "In Claude Code type %s" with the command as <code>, without innerHTML.
	function appendWithCode( parent, template, code ) {
		var parts = String( template ).split( '%s' );
		parent.appendChild( document.createTextNode( parts[ 0 ] ) );
		if ( parts.length > 1 ) {
			parent.appendChild( el( 'code', '', code ) );
			parent.appendChild( document.createTextNode( parts.slice( 1 ).join( '%s' ) ) );
		}
	}

	// ─── a two-click "are you sure" without window.confirm() ─────────────────

	function armable( btn, label, confirmLabel, action ) {
		var timer = null;
		btn.addEventListener( 'click', function () {
			if ( btn.getAttribute( 'data-armed' ) === '1' ) {
				window.clearTimeout( timer );
				btn.removeAttribute( 'data-armed' );
				btn.classList.remove( 'ekwa-mcp__armed' );
				action();
				return;
			}
			btn.setAttribute( 'data-armed', '1' );
			btn.classList.add( 'ekwa-mcp__armed' );
			btn.textContent = confirmLabel;
			timer = window.setTimeout( function () {
				btn.removeAttribute( 'data-armed' );
				btn.classList.remove( 'ekwa-mcp__armed' );
				btn.textContent = label;
			}, 4000 );
		} );
	}

	// ─── the component ───────────────────────────────────────────────────────

	function Component( root, config ) {
		this.root = root;
		this.config = config;
		this.facts = config.data || {};
		this.keys = ( config.data && config.data.keys ) || [];
		this.clock = new Clock( config.now );
		this.render();
	}

	Component.prototype.keysUrl = function ( id ) {
		var url = G.keysUrl + ( id ? '/' + encodeURIComponent( id ) : '' );
		return this.config.isSelf ? url : withQuery( url, 'user_id=' + encodeURIComponent( this.config.userId ) );
	};

	Component.prototype.render = function () {
		var self = this;
		var c = this.config;
		clear( this.root );

		if ( ! this.facts.enabled ) {
			this.root.appendChild( el( 'div', 'notice notice-warning inline ekwa-mcp__notice', S.offNotice ) );
		}

		// Key table.
		this.tableWrap = el( 'div', 'ekwa-mcp__keys' );
		this.root.appendChild( this.tableWrap );
		this.keysStatus = el( 'p', 'ekwa-mcp__status' );
		this.root.appendChild( this.keysStatus );
		this.renderKeys();

		// Create.
		if ( c.canCreate ) {
			var create = el( 'div', 'ekwa-mcp__create' );
			create.appendChild( el( 'h3', '', S.createHeading ) );
			create.appendChild( el( 'p', 'description', S.createIntro ) );
			var row = el( 'div', 'ekwa-mcp__create-row' );
			var input = el( 'input', 'regular-text' );
			input.type = 'text';
			input.maxLength = 80;
			input.setAttribute( 'autocomplete', 'off' );
			input.placeholder = S.labelPlaceholder;
			input.setAttribute( 'aria-label', S.labelA11y );
			var btn = button( S.create, 'button-primary' );
			row.appendChild( input );
			row.appendChild( btn );
			create.appendChild( row );
			var status = el( 'p', 'ekwa-mcp__status' );
			create.appendChild( status );
			this.root.appendChild( create );

			// Enter would submit the surrounding settings/profile form.
			input.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' || e.keyCode === 13 ) {
					e.preventDefault();
					btn.click();
				}
			} );
			btn.addEventListener( 'click', function () {
				self.createKey( input, btn, status );
			} );
		}

		// Where the one-time key appears.
		this.createdWrap = el( 'div', 'ekwa-mcp__created-slot' );
		this.root.appendChild( this.createdWrap );

		// Setup instructions with the placeholder key.
		if ( c.showSetup ) {
			this.setupSlot = el( 'div', 'ekwa-mcp__setup-slot' );
			this.setupSlot.appendChild( buildSetup( this.facts, '', '' ) );
			this.root.appendChild( this.setupSlot );
		}

		if ( c.showTest ) {
			this.renderTest();
		}
		if ( c.showJobs ) {
			this.renderJobs();
		}
	};

	Component.prototype.renderKeys = function () {
		var self = this;
		clear( this.tableWrap );

		if ( ! this.keys.length ) {
			this.tableWrap.appendChild( el( 'p', 'description', this.config.isSelf ? S.noKeys : S.noKeysOther ) );
			return;
		}

		var table = el( 'table', 'widefat striped ekwa-mcp__table' );
		var thead = el( 'thead' );
		var hr = el( 'tr' );
		[ S.colLabel, S.colCreated, S.colLastUsed, S.colLastApp, '' ].forEach( function ( h ) {
			hr.appendChild( el( 'th', '', h ) );
		} );
		thead.appendChild( hr );
		table.appendChild( thead );

		var tbody = el( 'tbody' );
		this.keys.forEach( function ( k ) {
			var tr = el( 'tr' );
			tr.appendChild( el( 'td', 'ekwa-mcp__label', k.label ) );
			tr.appendChild( el( 'td', '', self.clock.date( k.created ) ) );
			tr.appendChild( el( 'td', '', self.clock.ago( k.lastUsed ) ) );
			tr.appendChild( el( 'td', '', k.lastClient || '—' ) );
			var td = el( 'td', 'ekwa-mcp__actions' );
			var revoke = button( S.revoke, 'button-small button-link-delete' );
			armable( revoke, S.revoke, S.revokeConfirm, function () {
				self.revokeKey( k, revoke );
			} );
			td.appendChild( revoke );
			tr.appendChild( td );
			tbody.appendChild( tr );
		} );
		table.appendChild( tbody );
		this.tableWrap.appendChild( table );
	};

	Component.prototype.createKey = function ( input, btn, status ) {
		var self = this;
		btn.disabled = true;
		setStatus( status, S.creating );
		request( 'POST', this.keysUrl(), { label: input.value } ).then( function ( data ) {
			btn.disabled = false;
			setStatus( status, '' );
			input.value = '';
			// Keep the site facts for later re-renders, but not the key: it
			// should live only in the one-time box below.
			self.facts = {
				endpoint: data.endpoint,
				enabled: data.enabled,
				isLocal: data.isLocal,
				serverName: data.serverName,
				siteName: data.siteName,
				siteUrl: data.siteUrl
			};
			if ( data.record ) {
				self.keys.push( data.record );
			}
			self.renderKeys();
			self.showCreated( data );
		} ).catch( function ( err ) {
			btn.disabled = false;
			setStatus( status, err.message || S.error, 'error' );
		} );
	};

	// The only time the key is on screen.
	Component.prototype.showCreated = function ( data ) {
		var self = this;
		clear( this.createdWrap );

		var box = el( 'div', 'ekwa-mcp__created' );
		box.setAttribute( 'role', 'region' );
		box.setAttribute( 'aria-label', S.newHeading );
		box.appendChild( el( 'h3', '', S.newHeading ) );
		box.appendChild( el( 'p', 'ekwa-mcp__warning', S.newWarning ) );

		box.appendChild( el( 'p', 'ekwa-mcp__field-label', S.keyLabel ) );
		box.appendChild( copyable( String( data.key || '' ) ) );
		box.appendChild( el( 'p', 'ekwa-mcp__field-label', S.urlLabel ) );
		box.appendChild( copyable( String( data.url || '' ) ) );
		box.appendChild( el( 'p', 'description', S.urlWarning ) );

		box.appendChild( buildSetup( data, String( data.key || '' ), String( data.url || '' ) ) );

		var hide = button( S.hideKey, 'ekwa-mcp__hide' );
		hide.addEventListener( 'click', function () {
			clear( self.createdWrap );
			if ( self.setupSlot ) {
				self.setupSlot.hidden = false;
			}
		} );
		box.appendChild( hide );

		this.createdWrap.appendChild( box );
		// The placeholder copy of the instructions would only compete with the
		// filled-in one while the key is on screen.
		if ( this.setupSlot ) {
			this.setupSlot.hidden = true;
		}
		if ( box.scrollIntoView ) {
			box.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
	};

	Component.prototype.revokeKey = function ( k, btn ) {
		var self = this;
		btn.disabled = true;
		btn.textContent = S.revoking;
		request( 'DELETE', this.keysUrl( k.id ) ).then( function () {
			self.keys = self.keys.filter( function ( x ) {
				return x.id !== k.id;
			} );
			self.renderKeys();
			setStatus( self.keysStatus, S.revoked, 'success' );
		} ).catch( function ( err ) {
			btn.disabled = false;
			btn.textContent = S.revoke;
			setStatus( self.keysStatus, err.message || S.error, 'error' );
		} );
	};

	// ─── test job ────────────────────────────────────────────────────────────

	Component.prototype.renderTest = function () {
		var self = this;
		var wrap = el( 'div', 'ekwa-mcp__test' );
		wrap.appendChild( el( 'h3', '', S.testHeading ) );
		wrap.appendChild( el( 'p', 'description', S.testIntro ) );
		var btn = button( S.test, 'button-secondary' );
		var status = el( 'p', 'ekwa-mcp__status' );
		var reply = el( 'p', 'ekwa-mcp__reply' );
		wrap.appendChild( btn );
		wrap.appendChild( status );
		wrap.appendChild( reply );
		this.root.appendChild( wrap );

		btn.addEventListener( 'click', function () {
			reply.textContent = '';
			// The test is answered through the relay (202 + polling), which only
			// the relay client knows how to wait for.
			var relay = window.ekwaAiRelay;
			if ( ! relay || typeof relay.fetch !== 'function' ) {
				setStatus( status, S.testNoRelay, 'error' );
				return;
			}
			btn.disabled = true;
			setStatus( status, S.testing );
			request( 'POST', G.testUrl, {}, function ( url, init ) {
				return relay.fetch( url, init );
			} ).then( function ( data ) {
				btn.disabled = false;
				setStatus( status, data.message || '', data.ok ? 'success' : 'error' );
				if ( data.ok && data.reply ) {
					reply.textContent = fmt( S.testReply, data.reply );
				}
				self.loadJobs();
			} ).catch( function ( err ) {
				btn.disabled = false;
				setStatus( status, err.message || S.error, 'error' );
				self.loadJobs();
			} );
		} );
	};

	// ─── recent jobs ─────────────────────────────────────────────────────────

	Component.prototype.renderJobs = function () {
		var self = this;
		var wrap = el( 'div', 'ekwa-mcp__jobs' );
		var head = el( 'div', 'ekwa-mcp__jobs-head' );
		head.appendChild( el( 'h3', '', S.jobsHeading ) );
		var refresh = button( S.jobsRefresh, 'button-small' );
		var clearBtn = button( S.jobsClear, 'button-small' );
		head.appendChild( refresh );
		head.appendChild( clearBtn );
		wrap.appendChild( head );
		this.jobsList = el( 'div', 'ekwa-mcp__jobs-list' );
		this.jobsList.appendChild( el( 'p', 'description', S.loading ) );
		wrap.appendChild( this.jobsList );
		this.jobsStatus = el( 'p', 'ekwa-mcp__status' );
		wrap.appendChild( this.jobsStatus );
		this.root.appendChild( wrap );

		refresh.addEventListener( 'click', function () {
			self.loadJobs();
		} );
		armable( clearBtn, S.jobsClear, S.jobsClearConfirm, function () {
			clearBtn.disabled = true;
			request( 'DELETE', G.jobsUrl ).then( function () {
				clearBtn.disabled = false;
				clearBtn.textContent = S.jobsClear;
				self.drawJobs( [] );
				setStatus( self.jobsStatus, S.jobsCleared, 'success' );
			} ).catch( function ( err ) {
				clearBtn.disabled = false;
				clearBtn.textContent = S.jobsClear;
				setStatus( self.jobsStatus, err.message || S.error, 'error' );
			} );
		} );

		this.loadJobs();
	};

	Component.prototype.loadJobs = function () {
		var self = this;
		if ( ! this.jobsList ) {
			return;
		}
		request( 'GET', G.jobsUrl ).then( function ( data ) {
			setStatus( self.jobsStatus, '' );
			self.drawJobs( ( data && data.jobs ) || [] );
		} ).catch( function ( err ) {
			setStatus( self.jobsStatus, err.message || S.error, 'error' );
		} );
	};

	Component.prototype.drawJobs = function ( jobs ) {
		var self = this;
		clear( this.jobsList );
		if ( ! jobs.length ) {
			this.jobsList.appendChild( el( 'p', 'description', S.jobsEmpty ) );
			return;
		}
		var statuses = S.statuses || {};
		var table = el( 'table', 'widefat striped ekwa-mcp__table' );
		var thead = el( 'thead' );
		var hr = el( 'tr' );
		[ S.colStatus, S.colJob, S.colApp, S.colAge ].forEach( function ( h ) {
			hr.appendChild( el( 'th', '', h ) );
		} );
		thead.appendChild( hr );
		table.appendChild( thead );
		var tbody = el( 'tbody' );
		jobs.forEach( function ( job ) {
			var tr = el( 'tr' );
			var status = String( job.status || '' );
			tr.appendChild( el( 'td', 'ekwa-mcp__job-status ekwa-mcp__job-status--' + status.replace( /[^a-z]/g, '' ), statuses[ status ] || status ) );
			var label = el( 'td', '', job.label || '' );
			if ( job.error ) {
				label.appendChild( el( 'span', 'ekwa-mcp__job-error', job.error ) );
			}
			tr.appendChild( label );
			tr.appendChild( el( 'td', '', job.client || '—' ) );
			tr.appendChild( el( 'td', '', self.clock.ago( job.created ) ) );
			tbody.appendChild( tr );
		} );
		table.appendChild( tbody );
		this.jobsList.appendChild( table );
	};

	// ─── boot ────────────────────────────────────────────────────────────────

	function boot() {
		var roots = document.querySelectorAll( '.ekwa-mcp[data-ekwa-mcp]' );
		Array.prototype.forEach.call( roots, function ( root ) {
			var config;
			try {
				config = JSON.parse( root.getAttribute( 'data-ekwa-mcp' ) );
			} catch ( e ) {
				return;
			}
			new Component( root, config ); // eslint-disable-line no-new
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
