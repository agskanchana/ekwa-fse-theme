/**
 * Ekwa AI Block Builder — Gutenberg Editor Plugin.
 *
 * Standalone tool that calls Gemini to produce Ekwa/core BLOCK MARKUP directly
 * (no HTML→block conversion step), previews it (server-rendered), and inserts it
 * straight into the editor with wp.blocks.parse() + insertBlocks().
 *
 * Generated CSS comes back in a separate panel for the user to paste into their
 * shared/child stylesheet (styling is classes + one stylesheet by design).
 *
 * @package ekwa
 */
( function ( wp ) {
	'use strict';

	var el              = wp.element.createElement;
	var Fragment        = wp.element.Fragment;
	var useState        = wp.element.useState;
	var useEffect       = wp.element.useEffect;
	var useRef          = wp.element.useRef;
	var createPortal    = wp.element.createPortal;
	var registerPlugin  = wp.plugins.registerPlugin;
	var Modal           = wp.components.Modal;
	var Button          = wp.components.Button;
	var TextareaControl = wp.components.TextareaControl;
	var ToggleControl   = wp.components.ToggleControl;
	var SelectControl   = wp.components.SelectControl;
	var Notice          = wp.components.Notice;
	var Spinner         = wp.components.Spinner;
	var apiFetch        = wp.apiFetch;
	var __              = wp.i18n.__;

	var PluginMoreMenuItem = ( wp.editor && wp.editor.PluginMoreMenuItem )
		? wp.editor.PluginMoreMenuItem
		: ( wp.editPost && wp.editPost.PluginMoreMenuItem
			? wp.editPost.PluginMoreMenuItem
			: null );

	// Adds an "Edit with AI" item to a selected block's options (⋮) menu.
	var PluginBlockSettingsMenuItem = ( wp.editor && wp.editor.PluginBlockSettingsMenuItem )
		? wp.editor.PluginBlockSettingsMenuItem
		: ( wp.editPost && wp.editPost.PluginBlockSettingsMenuItem
			? wp.editPost.PluginBlockSettingsMenuItem
			: null );

	// Limits matching the server side (inc/ekwa-ai-generate-blocks.php).
	var MAX_IMAGES      = 6;
	var MAX_IMAGE_BYTES = 4 * 1024 * 1024;

	// Localized config from PHP (functions.php → wp_localize_script).
	var cfg           = window.ekwaAiBlocks || {};
	var CHILD_CSS_URL = cfg.childStylesheetUrl || '';
	// Design tokens + mockup sheet + fonts, exactly as <head> prints them. The
	// preview iframe is styled from scratch, so without these the rendered blocks
	// resolve none of their var()s and land on browser defaults.
	var PREVIEW_CSS   = cfg.previewCss || '';
	var MODEL_OPTIONS = Array.isArray( cfg.models ) && cfg.models.length
		? cfg.models
		// PHP always sends the list; this only fires if localization failed.
		// An empty value means "let the server pick", so it can never go stale.
		: [ { value: '', label: __( 'Theme default', 'ekwa' ) } ];
	var DEFAULT_MODEL = cfg.defaultModel || MODEL_OPTIONS[0].value;

	var CONTEXT_LABELS = {
		header:  __( 'Header', 'ekwa' ),
		footer:  __( 'Footer', 'ekwa' ),
		section: __( 'Page section', 'ekwa' ),
	};

	// ─── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Auto-detect whether the editor is on the Header / Footer template part.
	 * Falls back to 'section' for regular posts/pages or when detection fails.
	 */
	function detectContext() {
		try {
			var ed = wp.data && wp.data.select( 'core/editor' );
			if ( ed ) {
				var postType = ed.getCurrentPostType ? ed.getCurrentPostType() : '';
				var post     = ed.getCurrentPost ? ed.getCurrentPost() : null;
				if ( 'wp_template_part' === postType && post ) {
					var area = post.area || ( post.meta && post.meta.area ) || '';
					if ( 'header' === area ) { return 'header'; }
					if ( 'footer' === area ) { return 'footer'; }
					// Fall back to the slug when area is "uncategorized".
					var slug = ( post.slug || '' ).toLowerCase();
					if ( slug.indexOf( 'header' ) !== -1 ) { return 'header'; }
					if ( slug.indexOf( 'footer' ) !== -1 ) { return 'footer'; }
				}
			}
		} catch ( e ) { /* noop */ }
		return 'section';
	}

	/**
	 * Build the seed for "Edit with AI" from the selected blocks: serialize them
	 * to block markup, and pull any wrapper scopedCss out into a separate CSS
	 * string so the model edits clean markup + a <style> (the same shape it
	 * generates), rather than a giant CSS blob escaped inside a JSON attribute.
	 *
	 * @param {Array} blocks Block objects from getBlock().
	 * @return {{ markup: string, css: string }}
	 */
	function prepareEditSeed( blocks ) {
		var css = '';
		var cleaned = ( blocks || [] ).map( function ( b ) {
			if ( b && b.name === 'ekwa/div' && b.attributes && b.attributes.scopedCss ) {
				css += ( css ? '\n\n' : '' ) + b.attributes.scopedCss;
				var clone = {};
				Object.keys( b ).forEach( function ( k ) { clone[ k ] = b[ k ]; } );
				var attrs = {};
				Object.keys( b.attributes ).forEach( function ( k ) {
					if ( k !== 'scopedCss' ) { attrs[ k ] = b.attributes[ k ]; }
				} );
				clone.attributes = attrs;
				return clone;
			}
			return b;
		} );
		var markup = '';
		try { markup = wp.blocks.serialize( cleaned ); } catch ( e ) { markup = ''; }
		return { markup: markup, css: css };
	}

	/**
	 * Render block markup server-side, the way the front end would, so "Edit with
	 * AI" can preview the current section before any AI round-trip.
	 *
	 * Reading the editor canvas instead is tempting but wrong: the canvas holds
	 * editor DOM — media placeholders, Replace/Remove buttons, block wrappers —
	 * styled by stylesheets the preview iframe doesn't load, so it renders as
	 * unstyled markup. The REST render also inlines each block's front-end CSS.
	 *
	 * @param {string} markup Serialized block markup.
	 * @return {Promise<string>} Rendered HTML, or '' when it can't be rendered.
	 */
	function renderPreviewMarkup( markup ) {
		if ( ! markup ) { return Promise.resolve( '' ); }
		return apiFetch( {
			path: '/ekwa/v1/ai-blocks-preview',
			method: 'POST',
			data: { markup: markup },
		} ).then( function ( res ) {
			return ( res && res.rendered_html ) || '';
		} ).catch( function () {
			return '';
		} );
	}

	function readFileAsBase64( file ) {
		return new Promise( function ( resolve, reject ) {
			var reader = new FileReader();
			reader.onload  = function ( e ) {
				var result = e.target.result || '';
				var comma  = result.indexOf( ',' );
				resolve( comma >= 0 ? result.slice( comma + 1 ) : result );
			};
			reader.onerror = function () { reject( new Error( 'Could not read file: ' + file.name ) ); };
			reader.readAsDataURL( file );
		} );
	}

	function copyToClipboard( text, onDone ) {
		function fallback() {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0;';
			document.body.appendChild( ta );
			ta.select();
			try { document.execCommand( 'copy' ); } catch ( e ) { /* noop */ }
			document.body.removeChild( ta );
		}
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( onDone ).catch( function () { fallback(); onDone(); } );
		} else {
			fallback();
			onDone();
		}
	}

	// ─── Image thumbnail strip ──────────────────────────────────────────────

	function ImageStrip( props ) {
		var images   = props.images;
		var onRemove = props.onRemove;
		if ( ! images.length ) { return null; }
		return el( 'div', { className: 'ekwa-ai-image-strip' },
			images.map( function ( img, i ) {
				return el( 'div', { key: i, className: 'ekwa-ai-image-thumb' },
					el( 'img', { src: img.previewUrl, alt: img.name } ),
					el( 'button', {
						type: 'button',
						className: 'ekwa-ai-image-remove',
						title: __( 'Remove image', 'ekwa' ),
						onClick: function () { onRemove( i ); },
					}, '×' )
				);
			} )
		);
	}

	// ─── Block Builder Modal ────────────────────────────────────────────────

	function BlocksModal( props ) {
		var onClose = props.onClose;

		var s1  = useState( '' );            var prompt       = s1[0];  var setPrompt       = s1[1];
		// Imported-content handoff (pages created by the Bulk Page Creator).
		var si1 = useState( null );  var importStatus = si1[0]; var setImportStatus = si1[1];
		var si2 = useState( false ); var importBusy   = si2[0]; var setImportBusy   = si2[1];
		var si3 = useState( '' );    var importStats  = si3[0]; var setImportStats  = si3[1];
		var s2  = useState( [] );            var images       = s2[0];  var setImages       = s2[1];
		var s3  = useState( false );         var generating   = s3[0];  var setGenerating   = s3[1];
		var s4  = useState( null );          var error        = s4[0];  var setError        = s4[1];
		var s5  = useState( props.seedMarkup || '' ); var markup    = s5[0];  var setMarkup       = s5[1];
		var s6  = useState( props.seedCss || '' );    var css       = s6[0];  var setCss          = s6[1];
		var s7  = useState( '' );            var renderedHtml = s7[0];  var setRenderedHtml = s7[1];
		var s8  = useState( [] );            var warnings     = s8[0];  var setWarnings     = s8[1];
		var s9  = useState( false );         var copiedMarkup = s9[0];  var setCopiedMarkup = s9[1];
		var s10 = useState( false );         var copiedCss    = s10[0]; var setCopiedCss    = s10[1];
		var s11 = useState( [] );            var history      = s11[0]; var setHistory      = s11[1];
		var s12 = useState( true );          var useChildCss  = s12[0]; var setUseChildCss  = s12[1];
		var s13 = useState( DEFAULT_MODEL ); var model        = s13[0]; var setModel        = s13[1];
		var s14 = useState( false );         var isFullscreen = s14[0]; var setIsFullscreen = s14[1];
		var s15 = useState( props.context || 'section' ); var context = s15[0]; var setContext = s15[1];
		var s16 = useState( false );         var inserted     = s16[0]; var setInserted     = s16[1];
		// The front-end render of the untouched selection: the edit-mode starting
		// preview, and what "Back" returns to. Filled in by the effect below.
		var s17 = useState( '' );            var seedRendered = s17[0]; var setSeedRendered = s17[1];
		var s18 = useState( !! props.editMode ); var previewLoading = s18[0]; var setPreviewLoading = s18[1];

		var editMode      = !! props.editMode;
		var editClientIds = props.editClientIds || [];

		// Persistent conversation memory (create mode only — an edit session is
		// tied to a specific existing selection, not a reusable conversation).
		var ss1 = useState( null ); var sessionId   = ss1[0]; var setSessionId   = ss1[1];
		var ss2 = useState( [] );   var sessions    = ss2[0]; var setSessions    = ss2[1];
		var ss3 = useState( false ); var showSessions = ss3[0]; var setShowSessions = ss3[1];
		var ss4 = useState( false ); var restoring   = ss4[0]; var setRestoring   = ss4[1];
		var sessionsEnabled = ! editMode;

		var fileRef = useRef( null );

		var step = markup ? 'result' : 'generate';

		// Edit mode: render the selected blocks server-side for the opening preview.
		// Fire-and-forget — until it lands the preview pane shows its placeholder.
		useEffect( function () {
			if ( ! editMode || ! props.seedMarkup ) { setPreviewLoading( false ); return; }
			renderPreviewMarkup( props.seedMarkup ).then( function ( html ) {
				setPreviewLoading( false );
				if ( ! html ) { return; }
				setSeedRendered( html );
				// Adopt it only while nothing else fills the pane; if an AI turn beat
				// us to it, that result's own render must stand.
				setRenderedHtml( function ( prev ) { return prev ? prev : html; } );
			} );
		}, [] );

		// Load the recent-sessions index once when the modal mounts.
		useEffect( function () {
			if ( ! sessionsEnabled ) { return; }
			apiFetch( { path: '/ekwa/v1/ai-sessions' } ).then( function ( res ) {
				if ( res && Array.isArray( res.sessions ) ) { setSessions( res.sessions ); }
			} ).catch( function () { /* non-fatal */ } );
		}, [] );

		// Persist the current conversation after a generate/refine turn. Upserts
		// by sessionId; stores the returned id and refreshes the index.
		function persistSession( turns, newMarkup, newCss ) {
			if ( ! sessionsEnabled ) { return; }
			apiFetch( {
				path: '/ekwa/v1/ai-sessions',
				method: 'POST',
				data: {
					id:      sessionId,
					turns:   turns,
					markup:  newMarkup,
					css:     newCss,
					context: context,
				},
			} ).then( function ( res ) {
				if ( ! res || ! res.id ) { return; }
				if ( ! sessionId ) { setSessionId( res.id ); }
				// Move/insert this session at the top of the local index.
				setSessions( function ( prev ) {
					var without = ( prev || [] ).filter( function ( s ) { return s.id !== res.id; } );
					return [ { id: res.id, title: res.title, context: context, updated: res.updated } ].concat( without );
				} );
			} ).catch( function () { /* saving memory is best-effort */ } );
		}

		// Restore a saved session: load its full turn history + result and drop
		// the user straight into the result view to keep refining.
		function restoreSession( id ) {
			setRestoring( true );
			apiFetch( { path: '/ekwa/v1/ai-sessions/' + encodeURIComponent( id ) } ).then( function ( data ) {
				setHistory( Array.isArray( data.turns ) ? data.turns : [] );
				setMarkup( data.markup || '' );
				setCss( data.css || '' );
				setRenderedHtml( '' );
				setWarnings( [] );
				setContext( data.context || 'section' );
				setSessionId( data.id );
				setError( null );
				setInserted( false );
				setShowSessions( false );
				setRestoring( false );
			} ).catch( function () {
				setError( __( 'Could not load that session.', 'ekwa' ) );
				setRestoring( false );
			} );
		}

		function deleteSession( id ) {
			apiFetch( { path: '/ekwa/v1/ai-sessions/' + encodeURIComponent( id ), method: 'DELETE' } )
				.then( function () {
					setSessions( function ( prev ) {
						return ( prev || [] ).filter( function ( s ) { return s.id !== id; } );
					} );
					if ( id === sessionId ) { setSessionId( null ); }
				} ).catch( function () { /* non-fatal */ } );
		}

		function startNewSession() {
			setSessionId( null );
			setHistory( [] );
			setMarkup( '' );
			setCss( '' );
			setRenderedHtml( '' );
			setWarnings( [] );
			setError( null );
			setInserted( false );
			setShowSessions( false );
		}

		// ── Image handling ─────────────────────────────────────────────

		function addFiles( fileList ) {
			if ( ! fileList || ! fileList.length ) { return; }
			var room = MAX_IMAGES - images.length;
			if ( room <= 0 ) { setError( __( 'Maximum of 6 images.', 'ekwa' ) ); return; }
			var picked  = Array.prototype.slice.call( fileList, 0, room );
			var rejects = [];

			Promise.all( picked.map( function ( file ) {
				if ( ! file || ! file.type || file.type.indexOf( 'image/' ) !== 0 ) {
					rejects.push( ( file && file.name ? file.name : 'item' ) + ' (not an image)' );
					return null;
				}
				if ( file.size > MAX_IMAGE_BYTES ) {
					rejects.push( file.name + ' (over 4 MB)' );
					return null;
				}
				return readFileAsBase64( file ).then( function ( b64 ) {
					return {
						name:        file.name || ( 'pasted-' + Date.now() + '.png' ),
						mime:        file.type,
						data_base64: b64,
						previewUrl:  URL.createObjectURL( file ),
					};
				} );
			} ) ).then( function ( results ) {
				var added = results.filter( Boolean );
				if ( added.length ) { setImages( images.concat( added ) ); }
				if ( rejects.length ) { setError( __( 'Skipped: ', 'ekwa' ) + rejects.join( ', ' ) ); }
			} ).catch( function ( err ) {
				setError( err.message || 'Failed to read image.' );
			} );
		}

		function handleFiles( event ) {
			addFiles( event.target.files );
			if ( fileRef.current ) { fileRef.current.value = ''; }
		}

		function handlePaste( event ) {
			var items = event.clipboardData && event.clipboardData.items;
			if ( ! items || ! items.length ) { return; }
			var files = [];
			for ( var i = 0; i < items.length; i++ ) {
				if ( items[ i ].kind === 'file' && items[ i ].type && items[ i ].type.indexOf( 'image/' ) === 0 ) {
					var f = items[ i ].getAsFile();
					if ( f ) { files.push( f ); }
				}
			}
			if ( files.length ) { event.preventDefault(); addFiles( files ); }
		}

		function removeImage( index ) {
			var next = images.slice();
			var removed = next.splice( index, 1 )[0];
			if ( removed && removed.previewUrl ) {
				try { URL.revokeObjectURL( removed.previewUrl ); } catch ( e ) { /* noop */ }
			}
			setImages( next );
		}

		// ── Imported content ───────────────────────────────────────────

		// Ask once per open whether this page has content parked on it. On every
		// other page the block above never renders and the modal is unchanged.
		useEffect( function () {
			var sel = wp.data && wp.data.select( 'core/editor' );
			var id  = ( sel && sel.getCurrentPostId ) ? sel.getCurrentPostId() : 0;
			if ( ! id ) { return; }
			apiFetch( { path: '/ekwa/v1/import-status?post_id=' + id } )
				.then( setImportStatus )
				.catch( function () { setImportStatus( null ); } );
		}, [] );

		function insertImported() {
			var sel = wp.data && wp.data.select( 'core/editor' );
			var id  = ( sel && sel.getCurrentPostId ) ? sel.getCurrentPostId() : 0;
			if ( ! id ) { return; }

			setImportBusy( true );
			setError( '' );

			apiFetch( {
				path:   '/ekwa/v1/import-prepared',
				method: 'POST',
				data:   { post_id: id, sideload: true },
			} ).then( function ( res ) {
				// Append rather than replace: an author who already typed the
				// layout they want should not lose it by pressing this.
				var body = res.html || '';
				setPrompt( function ( current ) {
					var lead = __( 'Build a page from this content. Use the text exactly as given — do not rewrite, summarise or add to it.', 'ekwa' );
					return ( current && current.trim() )
						? current.replace( /\s*$/, '' ) + '\n\n' + body
						: lead + '\n\n' + body;
				} );

				var s = res.stats || {};
				var bits = [];
				if ( s.images_imported ) { bits.push( s.images_imported + ' ' + __( 'images copied in', 'ekwa' ) ); }
				if ( s.phones_converted ) { bits.push( s.phones_converted + ' ' + __( 'phone numbers', 'ekwa' ) ); }
				if ( s.links_remapped ) { bits.push( s.links_remapped + ' ' + __( 'links re-pointed', 'ekwa' ) ); }
				setImportStats( bits.length ? bits.join( ' · ' ) : __( 'Content inserted.', 'ekwa' ) );

				// Surface anything that needs a human — an unconfigured phone
				// number, a link with no matching page, an image that failed.
				( res.warnings || [] ).forEach( function ( w ) {
					if ( 'phone' === w.category || 'link' === w.category || 'media' === w.category ) {
						setError( function ( prev ) {
							return prev ? prev + '\n' + w.message : w.message;
						} );
					}
				} );

				setImportBusy( false );
			} ).catch( function ( e ) {
				setError( ( e && e.message ) || __( 'Could not prepare the imported content.', 'ekwa' ) );
				setImportBusy( false );
			} );
		}

		// ── Generate ───────────────────────────────────────────────────

		function handleGenerate() {
			if ( ! prompt.trim() ) {
				setError( __( 'Please describe what you want to build.', 'ekwa' ) );
				return;
			}
			setGenerating( true );
			setError( null );
			setInserted( false );

			var payloadImages = images.map( function ( img ) {
				return { mime: img.mime, data_base64: img.data_base64 };
			} );

			// When refining, splice the user's currently-edited markup/css into the
			// last model turn so the AI evolves from what they see now.
			var historyPayload = history.slice();
			if ( markup && historyPayload.length ) {
				for ( var i = historyPayload.length - 1; i >= 0; i-- ) {
					if ( historyPayload[ i ].role === 'model' ) {
						historyPayload[ i ] = { role: 'model', html: markup, css: css, js: '' };
						break;
					}
				}
			}

			apiFetch( {
				path: '/ekwa/v1/ai-generate-blocks',
				method: 'POST',
				data: {
					prompt:        prompt,
					images:        payloadImages,
					history:       historyPayload,
					use_child_css: useChildCss,
					model:         model,
					context:       context,
					mode:          editMode ? 'edit' : 'create',
					// The original selection is the edit baseline; the server only
					// injects it on the first turn (history empty), then relies on
					// the model's own prior output carried forward in history.
					base_markup:   editMode ? ( props.seedMarkup || '' ) : '',
					base_css:      editMode ? ( props.seedCss || '' ) : '',
				},
			} ).then( function ( res ) {
				var newHistory = historyPayload.concat( [
					{ role: 'user',  text: prompt, images: payloadImages },
					{ role: 'model', html: res.block_markup || '', css: res.extracted_css || '', js: '' },
				] );
				setHistory( newHistory );
				setMarkup( res.block_markup || '' );
				setCss( res.extracted_css || '' );
				setRenderedHtml( res.rendered_html || '' );
				setWarnings( res.warnings || [] );

				// Persist this turn to conversation memory (best-effort). Drop the
				// heavy base64 image payloads from stored turns to stay small.
				var storedHistory = newHistory.map( function ( t ) {
					if ( t.role === 'user' ) { return { role: 'user', text: t.text }; }
					return t;
				} );
				persistSession( storedHistory, res.block_markup || '', res.extracted_css || '' );

				setPrompt( '' );
				images.forEach( function ( img ) {
					if ( img.previewUrl ) {
						try { URL.revokeObjectURL( img.previewUrl ); } catch ( e ) { /* noop */ }
					}
				} );
				setImages( [] );
				if ( fileRef.current ) { fileRef.current.value = ''; }
				setGenerating( false );
			} ).catch( function ( err ) {
				var msg = err.message || 'AI generation failed.';
				if ( err.code === 'no_api_key' ) {
					msg = __( 'Gemini API key not configured. Set it in Appearance → Ekwa Settings → AI.', 'ekwa' );
				}
				setError( msg );
				setGenerating( false );
			} );
		}

		function handleBack() {
			// In edit mode "Back" restores the original selection seed rather than
			// emptying the form (which would drop the section being edited).
			setMarkup( editMode ? ( props.seedMarkup || '' ) : '' );
			setCss( editMode ? ( props.seedCss || '' ) : '' );
			setRenderedHtml( editMode ? seedRendered : '' );
			setWarnings( [] );
			setHistory( [] );
			setError( null );
			setIsFullscreen( false );
			setInserted( false );
			// Emptying the conversation starts a fresh session, so the next
			// generation gets a new id instead of overwriting the saved one.
			if ( ! editMode ) { setSessionId( null ); }
		}

		// ── Insert / apply into editor ─────────────────────────────────

		function handleInsert() {
			if ( ! markup ) { return; }
			try {
				var blocks = wp.blocks.parse( markup );
				if ( ! blocks || ! blocks.length ) {
					setError( __( 'Nothing to insert — the markup produced no blocks.', 'ekwa' ) );
					return;
				}
				// Edit mode: replace the originally selected blocks in place. The
				// markup here is always a server result (the Apply button is gated on
				// a completed update), so its CSS is self-contained.
				if ( editMode && editClientIds && editClientIds.length ) {
					wp.data.dispatch( 'core/block-editor' ).replaceBlocks( editClientIds, blocks );
					if ( typeof onClose === 'function' ) { onClose(); }
					return;
				}
				wp.data.dispatch( 'core/block-editor' ).insertBlocks( blocks );
				setInserted( true );
			} catch ( e ) {
				setError( ( e && e.message ) || __( 'Could not insert blocks.', 'ekwa' ) );
			}
		}

		// ── Render ─────────────────────────────────────────────────────

		var children = [];

		var contextBadge = el( 'div', { key: 'ctx', className: 'ekwa-ai-context-badge' },
			el( 'span', { className: 'ekwa-ai-context-badge__dot' } ),
			el( 'span', null,
				editMode ? __( 'Editing: ', 'ekwa' ) : __( 'Building for: ', 'ekwa' ),
				el( 'strong', null,
					editMode
						? ( editClientIds.length > 1
							? ( editClientIds.length + ' ' + __( 'selected blocks', 'ekwa' ) )
							: __( 'selected block', 'ekwa' ) )
						: ( CONTEXT_LABELS[ context ] || CONTEXT_LABELS.section )
				)
			)
		);

		if ( step === 'generate' ) {
			children.push( contextBadge );

			// Recent sessions (conversation memory).
			if ( sessionsEnabled && sessions.length ) {
				var sessionRows = sessions.map( function ( sObj ) {
					return el( 'li', { key: sObj.id, className: 'ekwa-ai-session-row' },
						el( 'button', {
							type: 'button',
							className: 'ekwa-ai-session-restore',
							disabled: restoring,
							onClick: function () { restoreSession( sObj.id ); },
						},
							el( 'span', { className: 'ekwa-ai-session-title' }, sObj.title || __( 'Untitled', 'ekwa' ) ),
							el( 'span', { className: 'ekwa-ai-session-meta' },
								( CONTEXT_LABELS[ sObj.context ] || CONTEXT_LABELS.section ) + ' · ' + ( sObj.updated || '' )
							)
						),
						el( Button, {
							className: 'ekwa-ai-session-del',
							icon: 'trash',
							label: __( 'Delete session', 'ekwa' ),
							isDestructive: true,
							isSmall: true,
							onClick: function () { deleteSession( sObj.id ); },
						} )
					);
				} );

				children.push(
					el( 'div', { key: 'sessions', className: 'ekwa-ai-sessions' },
						el( 'div', { className: 'ekwa-ai-sessions-head' },
							el( Button, {
								isLink: true,
								onClick: function () { setShowSessions( ! showSessions ); },
							}, ( showSessions ? '▾ ' : '▸ ' ) + __( 'Recent sessions', 'ekwa' ) + ' (' + sessions.length + ')' ),
							sessionId ? el( Button, {
								isSecondary: true,
								isSmall: true,
								onClick: startNewSession,
							}, __( 'New session', 'ekwa' ) ) : null
						),
						showSessions ? el( 'ul', { className: 'ekwa-ai-sessions-list' }, sessionRows ) : null
					)
				);
			}

			children.push(
				el( 'p', { key: 'desc', className: 'ekwa-ai-desc' },
					__( 'Describe what you want. The AI builds it with real Ekwa blocks (no HTML conversion needed) and returns the CSS to paste into your stylesheet.', 'ekwa' )
				)
			);

			// ── Imported content ───────────────────────────────────────
			// Only on pages the Bulk Page Creator parked source HTML on. It
			// drops that content straight into the prompt, having first done
			// the things this tool cannot do for itself: resolve lazy-loaded
			// images and copy them into the Media Library, swap configured
			// phone numbers for [ekwa_phone], and re-point the old site's
			// internal links. Everything after that is a normal build.
			if ( importStatus && importStatus.has_content ) {
				children.push(
					el( 'div', {
						key: 'imported',
						className: 'ekwa-ai-imported',
						style: {
							margin: '0 0 10px',
							padding: '10px 12px',
							border: '1px solid #c3c4c7',
							borderRadius: '4px',
							background: '#f6f7f7',
						},
					},
						el( 'div', { style: { display: 'flex', gap: '10px', alignItems: 'center', flexWrap: 'wrap' } },
							el( Button, {
								variant: 'secondary',
								onClick: insertImported,
								disabled: importBusy || generating,
							}, importBusy
								? __( 'Preparing…', 'ekwa' )
								: __( 'Insert imported page content', 'ekwa' ) ),
							importBusy ? el( Spinner, null ) : null,
							el( 'span', { style: { fontSize: '12px', color: '#646970' } },
								importStats
									? importStats
									: __( 'This page has content imported from the old site.', 'ekwa' )
							)
						),
						el( 'p', { style: { margin: '6px 0 0', fontSize: '11px', color: '#757575' } },
							__( 'Images are copied into the Media Library, phone numbers become [ekwa_phone], and links to the old site are re-pointed at your pages — then describe the layout you want below and Generate.', 'ekwa' )
						)
					)
				);
			}

			children.push(
				el( TextareaControl, {
					key: 'prompt',
					label: __( 'Prompt', 'ekwa' ),
					value: prompt,
					onChange: setPrompt,
					rows: 9,
					className: 'ekwa-ai-prompt',
					placeholder: context === 'header'
						? __( 'e.g. "Logo on the left, centered main menu, and on the right a search icon, a click-to-call new-patient phone, and a Book Appointment button."', 'ekwa' )
						: ( context === 'footer'
							? __( 'e.g. "Four columns: address + hours, quick links menu, social icons, and a Google map. Below them a copyright bar. Add a back-to-top button."', 'ekwa' )
							: __( 'e.g. "A 3-column services section. Each card has an icon, a heading, two lines of copy, and a Read More link."', 'ekwa' ) ),
				} )
			);

			children.push(
				el( 'div', { key: 'images', className: 'ekwa-ai-image-picker' },
					el( 'label', { className: 'ekwa-ai-image-label' },
						el( 'span', null, __( 'Reference screenshots', 'ekwa' ) ),
						el( 'span', { className: 'ekwa-ai-image-hint' },
							__( 'Paste (Ctrl+V) or pick · max 6 · 4 MB each', 'ekwa' )
						)
					),
					el( 'input', {
						ref: fileRef,
						type: 'file',
						accept: 'image/png,image/jpeg,image/webp,image/gif',
						multiple: true,
						onChange: handleFiles,
						disabled: images.length >= MAX_IMAGES,
					} ),
					el( ImageStrip, { images: images, onRemove: removeImage } )
				)
			);

			children.push(
				el( 'div', { key: 'opts', className: 'ekwa-ai-options' },
					el( SelectControl, {
						label: __( 'Model', 'ekwa' ),
						help: __( 'Flash is fast and cheap; Pro gives the best quality for complex layouts.', 'ekwa' ),
						value: model,
						options: MODEL_OPTIONS,
						onChange: setModel,
						className: 'ekwa-ai-model-select',
					} ),
					el( ToggleControl, {
						label: __( 'Send child theme stylesheet as context', 'ekwa' ),
						help: __( 'Lets the AI reuse classes and CSS variables from your child theme.', 'ekwa' ),
						checked: useChildCss,
						onChange: setUseChildCss,
					} )
				)
			);

			if ( error ) {
				children.push(
					el( Notice, { key: 'err', status: 'error', isDismissible: true,
						onRemove: function () { setError( null ); }
					}, error )
				);
			}

			children.push(
				el( 'div', { key: 'actions', className: 'ekwa-ai-actions' },
					el( Button, {
						variant: 'primary',
						isBusy: generating,
						disabled: generating || ! prompt.trim(),
						onClick: handleGenerate,
					}, generating
						? el( Fragment, null, el( Spinner, null ), __( ' Building...', 'ekwa' ) )
						: __( 'Build blocks', 'ekwa' )
					)
				)
			);
		} else {
			// ── Result step ────────────────────────────────────────────

			children.push(
				el( 'div', { key: 'header', className: 'ekwa-ai-result-header' },
					el( Button, { isSmall: true, icon: 'arrow-left-alt', onClick: handleBack },
						__( 'Back', 'ekwa' ) ),
					contextBadge
				)
			);

			if ( warnings && warnings.length ) {
				children.push(
					el( Notice, { key: 'warn', status: 'warning', isDismissible: false },
						el( 'strong', null, __( 'Heads up:', 'ekwa' ) ),
						el( 'ul', { style: { margin: '4px 0 0', paddingLeft: '18px' } },
							warnings.map( function ( w, i ) { return el( 'li', { key: i }, w ); } )
						)
					)
				);
			}

			// Server-rendered preview (with child CSS + generated CSS applied).
			var previewIframe = renderedHtml
				? el( 'iframe', {
					className: 'ekwa-ai-preview-frame',
					// allow-same-origin, and nothing else. An empty sandbox gives the
					// frame an opaque origin, and @font-face requests from an opaque
					// origin fail the CORS check — so the site's own webfonts silently
					// never load and the preview falls back to a system font. Scripts
					// stay blocked (no allow-scripts).
					sandbox: 'allow-same-origin',
					srcDoc: '<!doctype html><html><head><meta charset="utf-8">'
						+ '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">'
						+ ( CHILD_CSS_URL ? '<link rel="stylesheet" href="' + CHILD_CSS_URL + '">' : '' )
						// Site CSS before the reset, so the reset's margin:0 wins,
						// and both before the section CSS, which must stay last.
						+ ( PREVIEW_CSS ? '<style>' + PREVIEW_CSS + '</style>' : '' )
						+ '<style>body{margin:0;padding:0;}img{max-width:100%;height:auto;}</style>'
						+ ( css ? '<style>' + css + '</style>' : '' )
						+ '</head><body>' + renderedHtml + '</body></html>',
				} )
				: el( 'div', { className: 'ekwa-ai-preview-frame ekwa-ai-preview-frame--placeholder' },
					previewLoading
						? el( Fragment, null, el( Spinner, null ), el( 'span', null, __( 'Rendering preview…', 'ekwa' ) ) )
						: el( 'span', null, __( 'Live preview unavailable for this content — the block markup below is still valid and ready to insert.', 'ekwa' ) )
				);

			var previewHeader = el( 'div', { className: 'ekwa-ai-preview-label' },
				el( 'strong', null, __( 'Preview', 'ekwa' ) ),
				renderedHtml ? el( Button, {
					isSmall: true,
					variant: 'secondary',
					icon: isFullscreen ? 'editor-contract' : 'editor-expand',
					onClick: function () { setIsFullscreen( ! isFullscreen ); },
					className: 'ekwa-ai-preview-fullscreen-btn',
				}, isFullscreen ? __( 'Exit fullscreen', 'ekwa' ) : __( 'Fullscreen', 'ekwa' ) ) : null
			);

			if ( isFullscreen && renderedHtml && createPortal && typeof document !== 'undefined' ) {
				children.push(
					el( 'div', { key: 'preview', className: 'ekwa-ai-preview ekwa-ai-preview--placeholder' },
						previewHeader,
						el( 'div', { className: 'ekwa-ai-preview-frame ekwa-ai-preview-frame--placeholder' },
							el( 'span', null, __( 'Preview is open in fullscreen.', 'ekwa' ) )
						)
					)
				);
				children.push(
					createPortal(
						el( 'div', { className: 'ekwa-ai-preview ekwa-ai-preview--fullscreen' },
							previewHeader, previewIframe ),
						document.body,
						'blocks-preview-portal'
					)
				);
			} else {
				children.push(
					el( 'div', { key: 'preview', className: 'ekwa-ai-preview' },
						previewHeader, previewIframe )
				);
			}

			// Block markup (editable).
			children.push(
				el( TextareaControl, {
					key: 'markup',
					label: __( 'Block markup (editable)', 'ekwa' ),
					value: markup,
					onChange: function ( v ) { setMarkup( v ); setInserted( false ); },
					rows: 8,
					className: 'ekwa-ai-html',
				} )
			);

			// Generated CSS panel — informational. The CSS is already embedded in
			// the section's wrapper block and auto-inlined where it renders, so
			// there's nothing to paste; this is read-only for transparency.
			children.push(
				el( 'div', { key: 'css', className: 'ekwa-ai-extract' },
					el( 'div', { className: 'ekwa-ai-extract-header' },
						el( 'strong', null, __( 'Section CSS', 'ekwa' ) ),
						el( 'span', { className: 'ekwa-ai-extract-hint' },
							__( 'Already embedded in the block and auto-inlined only where this section renders — no need to paste it anywhere. Copy it only if you’d rather manage it in your stylesheet.', 'ekwa' )
						)
					),
					el( TextareaControl, {
						value: css,
						readOnly: true,
						rows: 8,
						className: 'ekwa-ai-extract-textarea',
						placeholder: __( '/* No extra CSS was generated. */', 'ekwa' ),
					} ),
					el( Button, {
						isSmall: true,
						variant: 'secondary',
						disabled: ! css,
						onClick: function () {
							copyToClipboard( css, function () {
								setCopiedCss( true );
								setTimeout( function () { setCopiedCss( false ); }, 2000 );
							} );
						},
					}, copiedCss ? __( 'Copied!', 'ekwa' ) : __( 'Copy CSS', 'ekwa' ) )
				)
			);

			// Conversation thread — the visible chat history for this session.
			if ( history.length ) {
				var threadItems = history.map( function ( turn, i ) {
					if ( turn.role === 'user' ) {
						return el( 'div', { key: i, className: 'ekwa-ai-msg ekwa-ai-msg--user' },
							el( 'span', { className: 'ekwa-ai-msg__who' }, __( 'You', 'ekwa' ) ),
							el( 'div', { className: 'ekwa-ai-msg__body' }, turn.text || '' )
						);
					}
					return el( 'div', { key: i, className: 'ekwa-ai-msg ekwa-ai-msg--ai' },
						el( 'span', { className: 'ekwa-ai-msg__who' }, __( 'AI', 'ekwa' ) ),
						el( 'div', { className: 'ekwa-ai-msg__body' },
							i <= 1 ? __( 'Built the section.', 'ekwa' ) : __( 'Updated the section.', 'ekwa' )
						)
					);
				} );
				children.push(
					el( 'div', { key: 'thread', className: 'ekwa-ai-thread' },
						el( 'div', { className: 'ekwa-ai-thread__head' }, __( 'Conversation', 'ekwa' ) ),
						threadItems
					)
				);
			}

			// Refine section.
			var turnCount = Math.floor( history.length / 2 );
			children.push(
				el( 'div', { key: 'refine', className: 'ekwa-ai-refine' },
					el( 'div', { className: 'ekwa-ai-refine-header' },
						el( 'strong', null, editMode ? __( 'Describe your change', 'ekwa' ) : __( 'Refine', 'ekwa' ) ),
						turnCount > 0
							? el( 'span', { className: 'ekwa-ai-refine-count' },
								turnCount === 1 ? __( '1 turn so far', 'ekwa' ) : turnCount + ' ' + __( 'turns so far', 'ekwa' ) )
							: null
					),
					el( TextareaControl, {
						value: prompt,
						onChange: setPrompt,
						rows: 3,
						className: 'ekwa-ai-refine-prompt',
						placeholder: editMode
							? __( 'e.g. "Make the buttons larger, and add more spacing between the cards."', 'ekwa' )
							: __( 'e.g. "Left-align the menu, add a thin top utility bar with the phone and address."', 'ekwa' ),
					} ),
					el( 'div', { className: 'ekwa-ai-refine-actions' },
						el( Button, {
							variant: 'secondary',
							isBusy: generating,
							disabled: generating || ! prompt.trim(),
							onClick: handleGenerate,
						}, generating
							? el( Fragment, null, el( Spinner, null ), editMode ? __( ' Updating...', 'ekwa' ) : __( ' Refining...', 'ekwa' ) )
							: ( editMode ? __( 'Update', 'ekwa' ) : __( 'Refine', 'ekwa' ) )
						)
					)
				)
			);

			if ( error ) {
				children.push(
					el( Notice, { key: 'err', status: 'error', isDismissible: true,
						onRemove: function () { setError( null ); }
					}, error )
				);
			}

			children.push(
				el( 'div', { key: 'actions', className: 'ekwa-ai-actions' },
					el( Button, {
						variant: 'secondary',
						onClick: function () {
							copyToClipboard( markup, function () {
								setCopiedMarkup( true );
								setTimeout( function () { setCopiedMarkup( false ); }, 2000 );
							} );
						},
					}, copiedMarkup ? __( 'Copied!', 'ekwa' ) : __( 'Copy block markup', 'ekwa' ) ),
					el( Button, {
						variant: 'primary',
						onClick: handleInsert,
						// In edit mode, only allow applying once an Update has produced a
						// self-contained server result (the original selection's CSS was
						// split out into the seed, so the unmodified seed isn't safe to apply).
						disabled: ! markup.trim() || ( editMode && history.length === 0 ),
					}, editMode
						? __( 'Apply to selected blocks', 'ekwa' )
						: ( inserted ? __( 'Inserted ✓ — insert again', 'ekwa' ) : __( 'Insert into editor', 'ekwa' ) )
					)
				)
			);
		}

		return el( Modal, {
			title: editMode ? __( 'Edit with AI', 'ekwa' ) : __( 'Build with AI (Blocks)', 'ekwa' ),
			onRequestClose: onClose,
			className: 'ekwa-converter-modal ekwa-ai-modal ekwa-ai-blocks-modal',
			shouldCloseOnClickOutside: false,
		},
			el( 'div', { className: 'ekwa-ai-modal-body', onPaste: handlePaste }, children )
		);
	}

	// ─── Plugin Registration ────────────────────────────────────────────────

	function BlocksPlugin() {
		var ms = useState( false );      var isOpen     = ms[0]; var setOpen       = ms[1];
		var cs = useState( 'section' );  var ctx        = cs[0]; var setCtx        = cs[1];
		var em = useState( false );      var editMode   = em[0]; var setEditMode   = em[1];
		var sm = useState( '' );         var seedMarkup = sm[0]; var setSeedMarkup = sm[1];
		var sc = useState( '' );         var seedCss    = sc[0]; var setSeedCss    = sc[1];
		var ci = useState( [] );         var editIds    = ci[0]; var setEditIds    = ci[1];

		// Build a new section from scratch (from the editor "more" menu).
		function open() {
			setEditMode( false );
			setSeedMarkup( '' );
			setSeedCss( '' );
			setEditIds( [] );
			setCtx( detectContext() );
			setOpen( true );
		}

		// Edit the currently selected block(s) with AI (from the block ⋮ menu).
		function openEdit() {
			var sel = wp.data && wp.data.select( 'core/block-editor' );
			if ( ! sel ) { return; }
			var ids = sel.getMultiSelectedBlockClientIds ? sel.getMultiSelectedBlockClientIds() : [];
			if ( ( ! ids || ! ids.length ) && sel.getSelectedBlockClientId ) {
				var one = sel.getSelectedBlockClientId();
				if ( one ) { ids = [ one ]; }
			}
			if ( ! ids || ! ids.length ) { return; }
			var blocks = ids.map( function ( id ) { return sel.getBlock( id ); } ).filter( Boolean );
			if ( ! blocks.length ) { return; }
			var seed = prepareEditSeed( blocks );
			if ( ! seed.markup ) { return; }
			setEditIds( ids );
			setSeedMarkup( seed.markup );
			setSeedCss( seed.css );
			setEditMode( true );
			setCtx( detectContext() );
			setOpen( true );
		}

		var moreTrigger;
		if ( PluginMoreMenuItem ) {
			moreTrigger = el( PluginMoreMenuItem, { icon: 'layout', onClick: open },
				__( 'Build with AI (Blocks)', 'ekwa' ) );
		} else {
			moreTrigger = el( Button, {
				icon: 'layout',
				label: __( 'Build with AI (Blocks)', 'ekwa' ),
				onClick: open,
				className: 'ekwa-ai-fab ekwa-ai-fab--blocks',
			}, __( 'Blocks AI', 'ekwa' ) );
		}

		var blockMenuTrigger = PluginBlockSettingsMenuItem
			? el( PluginBlockSettingsMenuItem, {
				icon: 'art',
				label: __( 'Edit with AI', 'ekwa' ),
				onClick: openEdit,
			} )
			: null;

		return el( Fragment, null,
			moreTrigger,
			blockMenuTrigger,
			isOpen
				? el( BlocksModal, {
					context: ctx,
					editMode: editMode,
					seedMarkup: seedMarkup,
					seedCss: seedCss,
					editClientIds: editIds,
					onClose: function () { setOpen( false ); },
				} )
				: null
		);
	}

	registerPlugin( 'ekwa-ai-blocks', {
		render: BlocksPlugin,
		icon: 'layout',
	} );

} )( window.wp );
