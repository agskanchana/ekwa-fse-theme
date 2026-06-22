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

	// Limits matching the server side (inc/ekwa-ai-generate-blocks.php).
	var MAX_IMAGES      = 6;
	var MAX_IMAGE_BYTES = 4 * 1024 * 1024;

	// Localized config from PHP (functions.php → wp_localize_script).
	var cfg           = window.ekwaAiBlocks || {};
	var CHILD_CSS_URL = cfg.childStylesheetUrl || '';
	var MODEL_OPTIONS = Array.isArray( cfg.models ) && cfg.models.length
		? cfg.models
		: [ { value: 'gemini-2.5-flash', label: 'Gemini 2.5 Flash' } ];
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
		var s2  = useState( [] );            var images       = s2[0];  var setImages       = s2[1];
		var s3  = useState( false );         var generating   = s3[0];  var setGenerating   = s3[1];
		var s4  = useState( null );          var error        = s4[0];  var setError        = s4[1];
		var s5  = useState( '' );            var markup       = s5[0];  var setMarkup       = s5[1];
		var s6  = useState( '' );            var css          = s6[0];  var setCss          = s6[1];
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

		var fileRef = useRef( null );

		var step = markup ? 'result' : 'generate';

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
			setMarkup( '' );
			setCss( '' );
			setRenderedHtml( '' );
			setWarnings( [] );
			setHistory( [] );
			setError( null );
			setIsFullscreen( false );
			setInserted( false );
		}

		// ── Insert into editor ─────────────────────────────────────────

		function handleInsert() {
			if ( ! markup ) { return; }
			try {
				var blocks = wp.blocks.parse( markup );
				if ( blocks && blocks.length ) {
					wp.data.dispatch( 'core/block-editor' ).insertBlocks( blocks );
					setInserted( true );
				} else {
					setError( __( 'Nothing to insert — the markup produced no blocks.', 'ekwa' ) );
				}
			} catch ( e ) {
				setError( ( e && e.message ) || __( 'Could not insert blocks.', 'ekwa' ) );
			}
		}

		// ── Render ─────────────────────────────────────────────────────

		var children = [];

		var contextBadge = el( 'div', { key: 'ctx', className: 'ekwa-ai-context-badge' },
			el( 'span', { className: 'ekwa-ai-context-badge__dot' } ),
			el( 'span', null,
				__( 'Building for: ', 'ekwa' ),
				el( 'strong', null, CONTEXT_LABELS[ context ] || CONTEXT_LABELS.section )
			)
		);

		if ( step === 'generate' ) {
			children.push( contextBadge );

			children.push(
				el( 'p', { key: 'desc', className: 'ekwa-ai-desc' },
					__( 'Describe what you want. The AI builds it with real Ekwa blocks (no HTML conversion needed) and returns the CSS to paste into your stylesheet.', 'ekwa' )
				)
			);

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
					sandbox: '',
					srcDoc: '<!doctype html><html><head><meta charset="utf-8">'
						+ '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">'
						+ ( CHILD_CSS_URL ? '<link rel="stylesheet" href="' + CHILD_CSS_URL + '">' : '' )
						+ '<style>body{margin:0;padding:0;}img{max-width:100%;height:auto;}</style>'
						+ ( css ? '<style>' + css + '</style>' : '' )
						+ '</head><body>' + renderedHtml + '</body></html>',
				} )
				: el( 'div', { className: 'ekwa-ai-preview-frame ekwa-ai-preview-frame--placeholder' },
					el( 'span', null, __( 'Live preview unavailable for this content — the block markup below is still valid and ready to insert.', 'ekwa' ) )
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

			// Generated CSS panel.
			children.push(
				el( 'div', { key: 'css', className: 'ekwa-ai-extract' },
					el( 'div', { className: 'ekwa-ai-extract-header' },
						el( 'strong', null, __( 'CSS for these blocks', 'ekwa' ) ),
						el( 'span', { className: 'ekwa-ai-extract-hint' },
							__( 'Paste into your common / child stylesheet. Full CSS supported (media queries, hover, keyframes).', 'ekwa' )
						)
					),
					el( TextareaControl, {
						value: css,
						onChange: setCss,
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

			// Refine section.
			var turnCount = Math.floor( history.length / 2 );
			children.push(
				el( 'div', { key: 'refine', className: 'ekwa-ai-refine' },
					el( 'div', { className: 'ekwa-ai-refine-header' },
						el( 'strong', null, __( 'Refine', 'ekwa' ) ),
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
						placeholder: __( 'e.g. "Left-align the menu, add a thin top utility bar with the phone and address."', 'ekwa' ),
					} ),
					el( 'div', { className: 'ekwa-ai-refine-actions' },
						el( Button, {
							variant: 'secondary',
							isBusy: generating,
							disabled: generating || ! prompt.trim(),
							onClick: handleGenerate,
						}, generating
							? el( Fragment, null, el( Spinner, null ), __( ' Refining...', 'ekwa' ) )
							: __( 'Refine', 'ekwa' )
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
						disabled: ! markup.trim(),
					}, inserted ? __( 'Inserted ✓ — insert again', 'ekwa' ) : __( 'Insert into editor', 'ekwa' ) )
				)
			);
		}

		return el( Modal, {
			title: __( 'Build with AI (Blocks)', 'ekwa' ),
			onRequestClose: onClose,
			className: 'ekwa-converter-modal ekwa-ai-modal ekwa-ai-blocks-modal',
			shouldCloseOnClickOutside: false,
		},
			el( 'div', { className: 'ekwa-ai-modal-body', onPaste: handlePaste }, children )
		);
	}

	// ─── Plugin Registration ────────────────────────────────────────────────

	function BlocksPlugin() {
		var ms       = useState( false );
		var isOpen   = ms[0];
		var setOpen  = ms[1];
		var cs       = useState( 'section' );
		var ctx      = cs[0];
		var setCtx   = cs[1];

		function open() {
			setCtx( detectContext() );
			setOpen( true );
		}

		var trigger;
		if ( PluginMoreMenuItem ) {
			trigger = el( PluginMoreMenuItem, { icon: 'layout', onClick: open },
				__( 'Build with AI (Blocks)', 'ekwa' ) );
		} else {
			trigger = el( Button, {
				icon: 'layout',
				label: __( 'Build with AI (Blocks)', 'ekwa' ),
				onClick: open,
				className: 'ekwa-ai-fab ekwa-ai-fab--blocks',
			}, __( 'Blocks AI', 'ekwa' ) );
		}

		return el( Fragment, null,
			trigger,
			isOpen
				? el( BlocksModal, { context: ctx, onClose: function () { setOpen( false ); } } )
				: null
		);
	}

	registerPlugin( 'ekwa-ai-blocks', {
		render: BlocksPlugin,
		icon: 'layout',
	} );

} )( window.wp );
