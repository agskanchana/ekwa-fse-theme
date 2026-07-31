/**
 * Ekwa — "Edit block with AI" admin UI.
 *
 * Wires the per-block "Edit with AI" buttons in Ekwa Settings → General. Opens a
 * modal where you describe a change; Gemini rewrites the copied block's files
 * (block.json / style.css / view.js / editor.js). You preview (and can hand-tweak)
 * each file, then Apply (backed up automatically) or Revert the last AI edit.
 *
 * Talks to the ekwa/v1 REST routes in inc/ekwa-ai-edit-block.php. AI-returned
 * content is only ever placed into textarea.value / element.textContent — never
 * innerHTML — so a block's own markup can't inject into this page.
 */
( function () {
	'use strict';

	var cfg = window.ekwaAiEditBlock;
	if ( ! cfg || ! cfg.rest ) { return; }

	var __ = ( window.wp && wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function ( s ) { return s; };

	function api( route, body ) {
		return fetch( cfg.rest + route, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify( body )
		} ).then( function ( r ) {
			return r.json().then( function ( data ) {
				if ( ! r.ok ) {
					throw new Error( ( data && data.message ) ? data.message : ( 'HTTP ' + r.status ) );
				}
				return data;
			} );
		} );
	}

	function el( tag, props, children ) {
		var n = document.createElement( tag );
		if ( props ) { Object.keys( props ).forEach( function ( k ) {
			if ( k === 'class' ) { n.className = props[ k ]; }
			else if ( k === 'text' ) { n.textContent = props[ k ]; }
			else if ( k === 'style' ) { n.setAttribute( 'style', props[ k ] ); }
			else { n.setAttribute( k, props[ k ] ); }
		} ); }
		( children || [] ).forEach( function ( c ) { if ( c ) { n.appendChild( c ); } } );
		return n;
	}

	function injectStyles() {
		if ( document.getElementById( 'ekwa-ai-edit-css' ) ) { return; }
		var css =
			'.ekwa-aieb-ov{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:100000;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto}' +
			'.ekwa-aieb{background:#fff;max-width:900px;width:100%;border-radius:6px;box-shadow:0 10px 40px rgba(0,0,0,.3);padding:20px 22px}' +
			'.ekwa-aieb h2{margin:0 0 .25em;font-size:18px}' +
			'.ekwa-aieb .row{margin:12px 0}' +
			'.ekwa-aieb textarea.inst{width:100%;min-height:70px}' +
			'.ekwa-aieb .file{border:1px solid #dcdcde;border-radius:4px;margin:10px 0}' +
			'.ekwa-aieb .file h4{margin:0;padding:8px 10px;background:#f6f7f7;border-bottom:1px solid #dcdcde;font-family:monospace;font-size:13px}' +
			'.ekwa-aieb .file textarea{width:100%;min-height:150px;border:0;font-family:monospace;font-size:12px;padding:8px 10px;box-sizing:border-box}' +
			'.ekwa-aieb .file details{padding:6px 10px;border-top:1px solid #f0f0f1}' +
			'.ekwa-aieb .file details pre{max-height:220px;overflow:auto;background:#f6f7f7;padding:8px;font-size:12px}' +
			'.ekwa-aieb .bar{display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-top:14px;flex-wrap:wrap}' +
			'.ekwa-aieb .bar .left{margin-right:auto}' +
			'.ekwa-aieb .msg{padding:6px 0}' +
			'.ekwa-aieb .msg.err{color:#b32d2e}.ekwa-aieb .msg.ok{color:#008a20}' +
			'.ekwa-aieb .warn{color:#8a6d00;font-size:12px;margin:2px 0}';
		var s = el( 'style', { id: 'ekwa-ai-edit-css' } );
		s.textContent = css;
		document.head.appendChild( s );
	}

	function openModal( slug ) {
		injectStyles();

		var modelSel = el( 'select', { class: 'ekwa-aieb-model' } );
		( cfg.models || [] ).forEach( function ( m ) {
			var o = el( 'option', { value: m.value, text: m.label } );
			if ( m.value === cfg.defaultModel ) { o.selected = true; }
			modelSel.appendChild( o );
		} );

		var inst   = el( 'textarea', { class: 'inst', placeholder: __( 'e.g. Make the button pill-shaped with a subtle shadow, and add a "subtitle" attribute shown under the label.', 'ekwa' ) } );
		var msg    = el( 'div', { class: 'msg' } );
		var files  = el( 'div', { class: 'files' } );
		var genBtn = el( 'button', { class: 'button button-primary', type: 'button', text: __( 'Generate', 'ekwa' ) } );
		var applyBtn = el( 'button', { class: 'button button-primary', type: 'button', text: __( 'Apply changes', 'ekwa' ) } );
		applyBtn.disabled = true;
		var revertBtn = el( 'button', { class: 'button', type: 'button', text: __( 'Revert last AI edit', 'ekwa' ) } );
		var closeBtn  = el( 'button', { class: 'button', type: 'button', text: __( 'Close', 'ekwa' ) } );

		function setMsg( text, kind ) { msg.textContent = text || ''; msg.className = 'msg' + ( kind ? ' ' + kind : '' ); }
		function busy( on ) { genBtn.disabled = on; applyBtn.disabled = on || ! files.querySelector( 'textarea' ); revertBtn.disabled = on; }

		genBtn.addEventListener( 'click', function () {
			var text = inst.value.trim();
			if ( ! text ) { setMsg( __( 'Describe the change first.', 'ekwa' ), 'err' ); return; }
			files.textContent = '';
			applyBtn.disabled = true;
			busy( true );
			setMsg( __( 'Asking the AI…', 'ekwa' ) );
			api( 'edit-block-ai', { slug: slug, instruction: text, model: modelSel.value } ).then( function ( data ) {
				busy( false );
				( data.warnings || [] ).forEach( function ( w ) { files.appendChild( el( 'div', { class: 'warn', text: '⚠ ' + w } ) ); } );
				if ( ! data.changed || ! data.changed.length ) {
					setMsg( __( 'No file changes proposed.', 'ekwa' ) );
					return;
				}
				setMsg( data.changed.length + ' ' + __( 'file(s) proposed — review, tweak if needed, then Apply.', 'ekwa' ), 'ok' );
				data.changed.forEach( function ( f ) {
					var ta = el( 'textarea', { 'data-path': f.path, spellcheck: 'false' } );
					ta.value = f.new;
					var orig = el( 'pre' ); orig.textContent = f.old;
					var det = el( 'details', {}, [ el( 'summary', { text: __( 'View original', 'ekwa' ) } ), orig ] );
					files.appendChild( el( 'div', { class: 'file' }, [ el( 'h4', { text: f.path } ), ta, det ] ) );
				} );
				applyBtn.disabled = false;
			} ).catch( function ( e ) { busy( false ); setMsg( e.message, 'err' ); } );
		} );

		applyBtn.addEventListener( 'click', function () {
			var map = {};
			files.querySelectorAll( 'textarea[data-path]' ).forEach( function ( ta ) { map[ ta.getAttribute( 'data-path' ) ] = ta.value; } );
			if ( ! Object.keys( map ).length ) { return; }
			busy( true ); setMsg( __( 'Writing files…', 'ekwa' ) );
			api( 'apply-block-edit', { slug: slug, files: map } ).then( function ( data ) {
				busy( false );
				setMsg( __( 'Applied. A backup was saved — use “Revert last AI edit” to undo.', 'ekwa' ) + ' (' + ( data.applied || [] ).join( ', ' ) + ')', 'ok' );
			} ).catch( function ( e ) { busy( false ); setMsg( e.message, 'err' ); } );
		} );

		revertBtn.addEventListener( 'click', function () {
			if ( ! window.confirm( __( 'Restore this block’s files from the last AI-edit backup?', 'ekwa' ) ) ) { return; }
			busy( true ); setMsg( __( 'Reverting…', 'ekwa' ) );
			api( 'revert-block-edit', { slug: slug } ).then( function ( data ) {
				busy( false );
				files.textContent = ''; applyBtn.disabled = true;
				setMsg( __( 'Reverted:', 'ekwa' ) + ' ' + ( data.reverted || [] ).join( ', ' ), 'ok' );
			} ).catch( function ( e ) { busy( false ); setMsg( e.message, 'err' ); } );
		} );

		var overlay = el( 'div', { class: 'ekwa-aieb-ov' }, [
			el( 'div', { class: 'ekwa-aieb' }, [
				el( 'h2', { text: __( 'Edit block with AI', 'ekwa' ) + ' — ' + slug } ),
				el( 'p', { class: 'description', text: __( 'Describe the change. The AI rewrites this block’s files in your child theme; the parent’s PHP render is never touched.', 'ekwa' ) } ),
				el( 'div', { class: 'row' }, [ inst ] ),
				el( 'div', { class: 'row' }, [ el( 'label', { style: 'margin-right:6px', text: __( 'Model:', 'ekwa' ) } ), modelSel, el( 'span', { style: 'margin-left:8px' }, [ genBtn ] ) ] ),
				msg,
				files,
				el( 'div', { class: 'bar' }, [
					el( 'span', { class: 'left' }, [ revertBtn ] ),
					applyBtn,
					closeBtn
				] )
			] )
		] );

		function close() { overlay.remove(); }
		closeBtn.addEventListener( 'click', close );
		overlay.addEventListener( 'click', function ( e ) { if ( e.target === overlay ) { close(); } } );
		document.addEventListener( 'keydown', function esc( e ) { if ( e.key === 'Escape' ) { close(); document.removeEventListener( 'keydown', esc ); } } );

		document.body.appendChild( overlay );
		inst.focus();
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.ekwa-ai-edit-block' ) : null;
		if ( ! btn ) { return; }
		e.preventDefault();
		openModal( btn.getAttribute( 'data-slug' ) );
	} );
} )();
