/**
 * Ekwa AI HTML Generator — Gutenberg Editor Plugin.
 *
 * Standalone tool that calls Gemini (with optional reference screenshots) to
 * produce a clean HTML fragment, previews it, and hands the result off to the
 * Mockup Converter via window.ekwaMockupConverter.openWithHtml().
 *
 * @package ekwa
 */
( function ( wp ) {
	'use strict';

	var el                 = wp.element.createElement;
	var Fragment           = wp.element.Fragment;
	var useState           = wp.element.useState;
	var useRef             = wp.element.useRef;
	var registerPlugin     = wp.plugins.registerPlugin;
	var Modal              = wp.components.Modal;
	var Button             = wp.components.Button;
	var TextareaControl    = wp.components.TextareaControl;
	var ToggleControl      = wp.components.ToggleControl;
	var Notice             = wp.components.Notice;
	var Spinner            = wp.components.Spinner;
	var apiFetch           = wp.apiFetch;
	var __                 = wp.i18n.__;

	var PluginMoreMenuItem = ( wp.editor && wp.editor.PluginMoreMenuItem )
		? wp.editor.PluginMoreMenuItem
		: ( wp.editPost && wp.editPost.PluginMoreMenuItem
			? wp.editPost.PluginMoreMenuItem
			: null );

	// Limits matching the server side (inc/ekwa-ai-generate.php).
	var MAX_IMAGES        = 6;
	var MAX_IMAGE_BYTES   = 4 * 1024 * 1024;

	// Localized config from PHP (functions.php → wp_localize_script).
	var bridgeCfg     = window.ekwaAiGenerate || {};
	var CHILD_CSS_URL = bridgeCfg.childStylesheetUrl || '';

	// ─── Helpers ────────────────────────────────────────────────────────────

	function readFileAsBase64( file ) {
		return new Promise( function ( resolve, reject ) {
			var reader = new FileReader();
			reader.onload  = function ( e ) {
				var result = e.target.result || '';
				// strip "data:<mime>;base64," prefix
				var comma = result.indexOf( ',' );
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
		if ( ! images.length ) return null;
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

	// ─── AI Generator Modal ─────────────────────────────────────────────────

	function GenerateModal( props ) {
		var onClose = props.onClose;

		var s1 = useState( '' );    var prompt        = s1[0]; var setPrompt        = s1[1];
		var s2 = useState( [] );    var images        = s2[0]; var setImages        = s2[1];
		var s3 = useState( false ); var generating    = s3[0]; var setGenerating    = s3[1];
		var s4 = useState( null );  var error         = s4[0]; var setError         = s4[1];
		var s5 = useState( '' );    var html          = s5[0]; var setHtml          = s5[1];
		var s6 = useState( '' );    var extractedCss  = s6[0]; var setExtractedCss  = s6[1];
		var s7 = useState( '' );    var extractedJs   = s7[0]; var setExtractedJs   = s7[1];
		var s8 = useState( false ); var copiedHtml    = s8[0]; var setCopiedHtml    = s8[1];
		var s9 = useState( false ); var copiedCss     = s9[0]; var setCopiedCss     = s9[1];
		var s10 = useState( false ); var copiedJs     = s10[0]; var setCopiedJs     = s10[1];
		var s11 = useState( true );  var showCss      = s11[0]; var setShowCss      = s11[1];
		var s12 = useState( true );  var showJs       = s12[0]; var setShowJs       = s12[1];
		var s13 = useState( [] );    var history      = s13[0]; var setHistory      = s13[1];
		var s14 = useState( true );  var useChildCss  = s14[0]; var setUseChildCss  = s14[1];
		// step is derived: 'generate' before HTML, 'preview' after.

		var fileRef = useRef( null );

		var step = html ? 'preview' : 'generate';

		// ── Image handling ─────────────────────────────────────────────

		function addFiles( fileList ) {
			if ( ! fileList || ! fileList.length ) return;
			var room = MAX_IMAGES - images.length;
			if ( room <= 0 ) {
				setError( __( 'Maximum of 6 images.', 'ekwa' ) );
				return;
			}
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
				if ( added.length ) setImages( images.concat( added ) );
				if ( rejects.length ) setError( __( 'Skipped: ', 'ekwa' ) + rejects.join( ', ' ) );
			} ).catch( function ( err ) {
				setError( err.message || 'Failed to read image.' );
			} );
		}

		function handleFiles( event ) {
			addFiles( event.target.files );
			if ( fileRef.current ) fileRef.current.value = '';
		}

		function handlePaste( event ) {
			var items = event.clipboardData && event.clipboardData.items;
			if ( ! items || ! items.length ) return;
			var files = [];
			for ( var i = 0; i < items.length; i++ ) {
				if ( items[ i ].kind === 'file' && items[ i ].type && items[ i ].type.indexOf( 'image/' ) === 0 ) {
					var f = items[ i ].getAsFile();
					if ( f ) files.push( f );
				}
			}
			if ( files.length ) {
				event.preventDefault();
				addFiles( files );
			}
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
				setError( __( 'Please describe what you want to generate.', 'ekwa' ) );
				return;
			}
			setGenerating( true );
			setError( null );

			var payloadImages = images.map( function ( img ) {
				return { mime: img.mime, data_base64: img.data_base64 };
			} );

			// When refining, splice the user's currently-edited html/css/js
			// into the last model turn so the AI sees those edits as the
			// "previous" output to evolve from.
			var historyPayload = history.slice();
			if ( html && historyPayload.length ) {
				for ( var i = historyPayload.length - 1; i >= 0; i-- ) {
					if ( historyPayload[ i ].role === 'model' ) {
						historyPayload[ i ] = {
							role: 'model',
							html: html,
							css:  extractedCss,
							js:   extractedJs,
						};
						break;
					}
				}
			}

			apiFetch( {
				path: '/ekwa/v1/ai-generate-html',
				method: 'POST',
				data: {
					prompt:        prompt,
					images:        payloadImages,
					history:       historyPayload,
					use_child_css: useChildCss,
				},
			} ).then( function ( res ) {
				var newHistory = historyPayload.concat( [
					{ role: 'user',  text: prompt, images: payloadImages },
					{ role: 'model', html: res.html || '', css: res.extracted_css || '', js: res.extracted_js || '' },
				] );
				setHistory( newHistory );
				setHtml( res.html || '' );
				setExtractedCss( res.extracted_css || '' );
				setExtractedJs( res.extracted_js || '' );

				// Clear input ready for the next refine turn.
				setPrompt( '' );
				images.forEach( function ( img ) {
					if ( img.previewUrl ) {
						try { URL.revokeObjectURL( img.previewUrl ); } catch ( e ) { /* noop */ }
					}
				} );
				setImages( [] );
				if ( fileRef.current ) fileRef.current.value = '';
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
			setHtml( '' );
			setExtractedCss( '' );
			setExtractedJs( '' );
			setHistory( [] );
			setError( null );
		}

		// ── Handoff to Markup Converter ────────────────────────────────

		function handleSendToConverter() {
			if ( ! html ) return;
			var bridge = window.ekwaMockupConverter;
			if ( ! bridge || typeof bridge.openWithHtml !== 'function' ) {
				setError( __( 'Mockup Converter is not available on this screen.', 'ekwa' ) );
				return;
			}
			onClose();
			bridge.openWithHtml( html );
		}

		// ── Render ─────────────────────────────────────────────────────

		var children = [];

		if ( step === 'generate' ) {
			children.push(
				el( 'p', { key: 'desc', className: 'ekwa-ai-desc' },
					__( 'Describe the section you want, paste in real content, and optionally attach reference screenshots. The AI will generate clean HTML you can preview before sending it to the Mockup Converter.', 'ekwa' )
				)
			);

			children.push(
				el( TextareaControl, {
					key: 'prompt',
					label: __( 'Prompt', 'ekwa' ),
					value: prompt,
					onChange: setPrompt,
					rows: 10,
					className: 'ekwa-ai-prompt',
					placeholder: __( 'e.g. "A 3-column services section. Each card has a Font Awesome icon, a heading, two lines of copy, and a Read More link. Use the content below: ..."', 'ekwa' ),
				} )
			);

			children.push(
				el( 'div', { key: 'images', className: 'ekwa-ai-image-picker' },
					el( 'label', { className: 'ekwa-ai-image-label' },
						el( 'span', null, __( 'Screenshots', 'ekwa' ) ),
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
					el( ToggleControl, {
						label: __( 'Send child theme stylesheet as context', 'ekwa' ),
						help: __( 'Lets the AI reuse classes and CSS variables from your child theme. Turn off for a fresh, theme-agnostic design.', 'ekwa' ),
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
						? el( Fragment, null, el( Spinner, null ), __( ' Generating...', 'ekwa' ) )
						: __( 'Generate HTML', 'ekwa' )
					)
				)
			);
		} else {
			// ── Preview step ───────────────────────────────────────────

			children.push(
				el( 'div', { key: 'header', className: 'ekwa-ai-result-header' },
					el( Button, {
						isSmall: true,
						icon: 'arrow-left-alt',
						onClick: handleBack,
					}, __( 'Back', 'ekwa' ) )
				)
			);

			// Sandboxed visual preview.
			children.push(
				el( 'div', { key: 'preview', className: 'ekwa-ai-preview' },
					el( 'div', { className: 'ekwa-ai-preview-label' },
						el( 'strong', null, __( 'Preview', 'ekwa' ) )
					),
					el( 'iframe', {
						className: 'ekwa-ai-preview-frame',
						sandbox: '',
						srcDoc: '<!doctype html><html><head><meta charset="utf-8">'
							+ '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">'
							+ ( CHILD_CSS_URL ? '<link rel="stylesheet" href="' + CHILD_CSS_URL + '">' : '' )
							+ '<style>body{margin:0;padding:0;}img{max-width:100%;height:auto;}</style>'
							+ ( extractedCss ? '<style>' + extractedCss + '</style>' : '' )
							+ '</head><body>' + html + '</body></html>',
					} )
				)
			);

			// Editable HTML.
			children.push(
				el( TextareaControl, {
					key: 'html',
					label: __( 'Generated HTML (editable)', 'ekwa' ),
					value: html,
					onChange: setHtml,
					rows: 10,
					className: 'ekwa-ai-html',
				} )
			);

			// Extracted CSS panel.
			if ( extractedCss ) {
				children.push(
					el( 'div', { key: 'css', className: 'ekwa-ai-extract' },
						el( 'div', { className: 'ekwa-ai-extract-header' },
							el( Button, {
								isSmall: true,
								icon: showCss ? 'arrow-down' : 'arrow-right',
								onClick: function () { setShowCss( ! showCss ); },
							}, __( 'Extracted CSS', 'ekwa' ) ),
							el( 'span', { className: 'ekwa-ai-extract-hint' },
								__( 'Separated from the HTML — paste into your common stylesheet (full CSS supported: media queries, hover, keyframes).', 'ekwa' )
							)
						),
						showCss ? el( Fragment, null,
							el( TextareaControl, {
								value: extractedCss,
								onChange: setExtractedCss,
								rows: 8,
								className: 'ekwa-ai-extract-textarea',
							} ),
							el( Button, {
								isSmall: true,
								variant: 'secondary',
								onClick: function () {
									copyToClipboard( extractedCss, function () {
										setCopiedCss( true );
										setTimeout( function () { setCopiedCss( false ); }, 2000 );
									} );
								},
							}, copiedCss ? __( 'Copied!', 'ekwa' ) : __( 'Copy CSS', 'ekwa' ) )
						) : null
					)
				);
			}

			// Extracted JS panel.
			if ( extractedJs ) {
				children.push(
					el( 'div', { key: 'js', className: 'ekwa-ai-extract' },
						el( 'div', { className: 'ekwa-ai-extract-header' },
							el( Button, {
								isSmall: true,
								icon: showJs ? 'arrow-down' : 'arrow-right',
								onClick: function () { setShowJs( ! showJs ); },
							}, __( 'Extracted JavaScript', 'ekwa' ) ),
							el( 'span', { className: 'ekwa-ai-extract-hint' },
								__( 'Separated from the HTML — paste into your common JS file.', 'ekwa' )
							)
						),
						showJs ? el( Fragment, null,
							el( TextareaControl, {
								value: extractedJs,
								onChange: setExtractedJs,
								rows: 8,
								className: 'ekwa-ai-extract-textarea',
							} ),
							el( Button, {
								isSmall: true,
								variant: 'secondary',
								onClick: function () {
									copyToClipboard( extractedJs, function () {
										setCopiedJs( true );
										setTimeout( function () { setCopiedJs( false ); }, 2000 );
									} );
								},
							}, copiedJs ? __( 'Copied!', 'ekwa' ) : __( 'Copy JS', 'ekwa' ) )
						) : null
					)
				);
			}

			// Refine section — chat-compose pattern: user reads the result, then
			// tells the AI what to change. Reuses the same prompt/images state
			// as Step A but with a more compact layout.
			var turnCount = Math.floor( history.length / 2 );
			children.push(
				el( 'div', { key: 'refine', className: 'ekwa-ai-refine' },
					el( 'div', { className: 'ekwa-ai-refine-header' },
						el( 'strong', null, __( 'Refine this generation', 'ekwa' ) ),
						turnCount > 0
							? el( 'span', { className: 'ekwa-ai-refine-count' },
								turnCount === 1
									? __( '1 turn so far', 'ekwa' )
									: turnCount + ' ' + __( 'turns so far', 'ekwa' )
							)
							: null
					),
					el( TextareaControl, {
						value: prompt,
						onChange: setPrompt,
						rows: 3,
						className: 'ekwa-ai-refine-prompt',
						placeholder: __( 'e.g. "Make it 4 columns instead of 3, add a Font Awesome icon to each card, use the brand primary color for the headings."', 'ekwa' ),
					} ),
					el( 'div', { className: 'ekwa-ai-image-picker ekwa-ai-image-picker--compact' },
						el( 'label', { className: 'ekwa-ai-image-label' },
							el( 'span', null, __( 'Add screenshots (optional)', 'ekwa' ) ),
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
					),
					el( 'div', { className: 'ekwa-ai-options ekwa-ai-options--compact' },
						el( ToggleControl, {
							label: __( 'Send child theme stylesheet as context', 'ekwa' ),
							checked: useChildCss,
							onChange: setUseChildCss,
						} )
					),
					el( 'div', { className: 'ekwa-ai-refine-actions' },
						el( Button, {
							variant: 'primary',
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
							copyToClipboard( html, function () {
								setCopiedHtml( true );
								setTimeout( function () { setCopiedHtml( false ); }, 2000 );
							} );
						},
					}, copiedHtml ? __( 'Copied!', 'ekwa' ) : __( 'Copy HTML', 'ekwa' ) ),
					el( Button, {
						variant: 'primary',
						onClick: handleSendToConverter,
						disabled: ! html.trim(),
					}, __( 'Send to Markup Converter', 'ekwa' ) )
				)
			);
		}

		return el( Modal, {
			title: __( 'Generate with AI', 'ekwa' ),
			onRequestClose: onClose,
			className: 'ekwa-converter-modal ekwa-ai-modal',
			shouldCloseOnClickOutside: false,
		},
			el( 'div', { className: 'ekwa-ai-modal-body', onPaste: handlePaste }, children )
		);
	}

	// ─── Plugin Registration ────────────────────────────────────────────────

	function GeneratePlugin() {
		var ms = useState( false );
		var isOpen  = ms[0];
		var setOpen = ms[1];

		var trigger;

		if ( PluginMoreMenuItem ) {
			trigger = el( PluginMoreMenuItem, {
				icon: 'art',
				onClick: function () { setOpen( true ); },
			}, __( 'Generate with AI', 'ekwa' ) );
		} else {
			trigger = el( Button, {
				icon: 'art',
				label: __( 'Generate with AI', 'ekwa' ),
				onClick: function () { setOpen( true ); },
				className: 'ekwa-ai-fab',
			}, __( 'AI', 'ekwa' ) );
		}

		return el( Fragment, null,
			trigger,
			isOpen
				? el( GenerateModal, { onClose: function () { setOpen( false ); } } )
				: null
		);
	}

	registerPlugin( 'ekwa-ai-generate', {
		render: GeneratePlugin,
		icon: 'art',
	} );

} )( window.wp );
