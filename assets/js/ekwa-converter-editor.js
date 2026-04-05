/**
 * Ekwa Mockup Converter — Gutenberg Editor Plugin.
 *
 * Adds a "Mockup Converter" trigger to the editor. Features:
 *  - Paste HTML, convert to WP block markup via REST API
 *  - Map missing media to WP media library items
 *  - Copy or insert converted blocks into the editor
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
	var MediaUpload        = wp.blockEditor.MediaUpload;
	var MediaUploadCheck   = wp.blockEditor.MediaUploadCheck;
	var apiFetch           = wp.apiFetch;
	var parse              = wp.blocks.parse;
	var dispatch           = wp.data.dispatch;
	var __                 = wp.i18n.__;

	var PluginMoreMenuItem = ( wp.editor && wp.editor.PluginMoreMenuItem )
		? wp.editor.PluginMoreMenuItem
		: ( wp.editPost && wp.editPost.PluginMoreMenuItem
			? wp.editPost.PluginMoreMenuItem
			: null );

	// ─── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Parse warnings to extract missing media filenames.
	 * Warning format: "No manifest match for 'filename.jpg' (src: assets/filename.jpg)"
	 */
	function parseMissingMedia( warnings ) {
		var missing = {};
		( warnings || [] ).forEach( function ( w ) {
			var m = w.match( /No manifest match for (?:background |poster |style url )?'([^']+)'/i );
			if ( m && m[1] ) {
				var filename = m[1];
				if ( ! missing[ filename ] ) {
					missing[ filename ] = { filename: filename, media: null };
				}
			}
		} );
		return missing;
	}

	/**
	 * Build a manifest object from media mappings.
	 */
	function buildManifestFromMappings( mappings ) {
		var media = [];
		Object.keys( mappings ).forEach( function ( key ) {
			var entry = mappings[ key ];
			if ( entry.media ) {
				media.push( {
					filename: entry.filename,
					url:      entry.media.url,
					id:       entry.media.id,
					alt:      entry.media.alt || '',
					width:    entry.media.width || 0,
					height:   entry.media.height || 0,
				} );
			}
		} );
		return { media: media };
	}

	// ─── Media Mapping Row ──────────────────────────────────────────────────

	function MediaMappingRow( props ) {
		var filename = props.filename;
		var mapped   = props.mapped;    // media object or null
		var onSelect = props.onSelect;
		var onClear  = props.onClear;

		if ( mapped ) {
			var isImage = mapped.url && /\.(jpe?g|png|webp|gif|svg)$/i.test( mapped.url );
			return el( 'div', { className: 'ekwa-mc-media-row ekwa-mc-media-row--mapped' },
				isImage
					? el( 'img', { src: mapped.url, className: 'ekwa-mc-media-thumb' } )
					: el( 'span', { className: 'dashicons dashicons-media-video ekwa-mc-media-icon' } ),
				el( 'div', { className: 'ekwa-mc-media-info' },
					el( 'code', null, filename ),
					el( 'span', { className: 'ekwa-mc-media-mapped-url' }, mapped.url.split( '/' ).pop() )
				),
				el( Button, {
					isSmall: true,
					isDestructive: true,
					onClick: onClear,
				}, __( 'Clear', 'ekwa' ) )
			);
		}

		return el( 'div', { className: 'ekwa-mc-media-row' },
			el( 'span', { className: 'dashicons dashicons-warning ekwa-mc-media-warn-icon' } ),
			el( 'code', { className: 'ekwa-mc-media-filename' }, filename ),
			el( MediaUploadCheck, null,
				el( MediaUpload, {
					onSelect: function ( media ) {
						onSelect( {
							id:     media.id,
							url:    media.url,
							alt:    media.alt || '',
							width:  media.width || 0,
							height: media.height || 0,
						} );
					},
					allowedTypes: [ 'image', 'video' ],
					render: function ( obj ) {
						return el( Button, {
							isSecondary: true,
							isSmall: true,
							onClick: obj.open,
						}, __( 'Select Media', 'ekwa' ) );
					},
				} )
			)
		);
	}

	// ─── Converter Modal ────────────────────────────────────────────────────

	function ConverterModal( props ) {
		var onClose = props.onClose;

		// ── State ────────────────────────────────────────────────────
		var s1 = useState( '' );      var htmlValue    = s1[0]; var setHtmlValue    = s1[1];
		var s2 = useState( null );    var manifestData = s2[0]; var setManifestData = s2[1];
		var s3 = useState( '' );      var manifestName = s3[0]; var setManifestName = s3[1];
		var s4 = useState( true );    var useServerM   = s4[0]; var setUseServerM   = s4[1];
		var s5 = useState( false );   var converting   = s5[0]; var setConverting   = s5[1];
		var s6 = useState( '' );      var markup       = s6[0]; var setMarkup       = s6[1];
		var s7 = useState( [] );      var warnings     = s7[0]; var setWarnings     = s7[1];
		var s8 = useState( null );    var error        = s8[0]; var setError        = s8[1];
		var s9 = useState( false );   var copied       = s9[0]; var setCopied       = s9[1];
		var s10 = useState( {} );     var mediaMaps    = s10[0]; var setMediaMaps   = s10[1];
		var s11 = useState( 'input' ); var step        = s11[0]; var setStep        = s11[1];
		var s12 = useState( true );    var detectDyn   = s12[0]; var setDetectDyn   = s12[1];
		var s13 = useState( '' );      var refined     = s13[0]; var setRefined     = s13[1];
		var s14 = useState( false );   var refining    = s14[0]; var setRefining    = s14[1];
		var s15 = useState( null );    var aiNotes     = s15[0]; var setAiNotes     = s15[1];
		var s16 = useState( null );    var aiError     = s16[0]; var setAiError     = s16[1];
		var s17 = useState( false );   var showAi      = s17[0]; var setShowAi      = s17[1];
		// steps: 'input' | 'result'

		var fileRef = useRef( null );

		// ── Handlers ─────────────────────────────────────────────────

		function doConvert( extraManifest ) {
			setConverting( true );
			setError( null );
			setWarnings( [] );
			setMarkup( '' );
			setCopied( false );

			var body = {
				html: htmlValue,
				use_server_manifest: useServerM,
				detect_dynamic: detectDyn,
			};

			if ( ! useServerM && manifestData ) {
				body.manifest = manifestData;
				body.use_server_manifest = false;
			}

			// Merge extra mappings manifest.
			if ( extraManifest && extraManifest.media && extraManifest.media.length ) {
				if ( body.manifest && body.manifest.media ) {
					body.manifest.media = body.manifest.media.concat( extraManifest.media );
				} else if ( body.use_server_manifest ) {
					// Server manifest will load, we add extra media on top.
					body.manifest = extraManifest;
				} else {
					body.manifest = extraManifest;
				}
			}

			apiFetch( {
				path: '/ekwa/v1/convert-markup',
				method: 'POST',
				data: body,
			} ).then( function ( res ) {
				setMarkup( res.markup || '' );
				setWarnings( res.warnings || [] );
				setConverting( false );
				setStep( 'result' );

				// Parse missing media from warnings.
				var missing = parseMissingMedia( res.warnings );
				setMediaMaps( missing );
			} ).catch( function ( err ) {
				setError( err.message || 'Conversion failed.' );
				setConverting( false );
			} );
		}

		function handleConvert() {
			if ( ! htmlValue.trim() ) {
				setError( 'Please paste some HTML markup first.' );
				return;
			}
			doConvert( null );
		}

		function handleReconvert() {
			var manifest = buildManifestFromMappings( mediaMaps );
			doConvert( manifest );
		}

		// Active markup — refined if available and toggled on, otherwise original.
		var activeMarkup = showAi && refined ? refined : markup;

		function handleInsert() {
			if ( ! activeMarkup ) return;
			var blocks = parse( activeMarkup );
			if ( blocks && blocks.length ) {
				dispatch( 'core/block-editor' ).insertBlocks( blocks );
				onClose();
			}
		}

		function handleCopy() {
			if ( ! activeMarkup ) return;

			function onSuccess() {
				setCopied( true );
				setTimeout( function () { setCopied( false ); }, 2000 );
			}

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( activeMarkup ).then( onSuccess ).catch( function () {
					copyFallback( activeMarkup );
					onSuccess();
				} );
			} else {
				copyFallback( activeMarkup );
				onSuccess();
			}
		}

		function handleRefine() {
			setRefining( true );
			setAiError( null );
			setAiNotes( null );
			setRefined( '' );
			setCopied( false );

			apiFetch( {
				path: '/ekwa/v1/ai-refine-markup',
				method: 'POST',
				data: {
					html: htmlValue,
					markup: markup,
					warnings: warnings,
				},
			} ).then( function ( res ) {
				setRefined( res.refined_markup || '' );
				setAiNotes( res.ai_notes || [] );
				setRefining( false );
				setShowAi( true );
			} ).catch( function ( err ) {
				var msg = err.message || 'AI refinement failed.';
				if ( err.code === 'no_api_key' ) {
					msg = 'Gemini API key not configured. Set it in Ekwa Settings or wp-config.php.';
				}
				setAiError( msg );
				setRefining( false );
			} );
		}

		function copyFallback( text ) {
			var textarea = document.createElement( 'textarea' );
			textarea.value = text;
			textarea.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0;';
			document.body.appendChild( textarea );
			textarea.select();
			try { document.execCommand( 'copy' ); } catch ( e ) { /* noop */ }
			document.body.removeChild( textarea );
		}

		function handleManifestFile( event ) {
			var file = event.target.files && event.target.files[0];
			if ( ! file ) return;
			setManifestName( file.name );
			var reader = new FileReader();
			reader.onload = function ( e ) {
				try {
					setManifestData( JSON.parse( e.target.result ) );
					setError( null );
				} catch ( err ) {
					setError( 'Invalid manifest JSON: ' + err.message );
					setManifestData( null );
				}
			};
			reader.readAsText( file );
		}

		function handleBack() {
			setStep( 'input' );
		}

		// ── Render: Input Step ───────────────────────────────────────

		if ( step === 'input' ) {
			var inputChildren = [];

			// Description.
			inputChildren.push(
				el( 'p', { key: 'desc', className: 'ekwa-mc-desc' },
					__( 'Paste mockup HTML below and convert it to WordPress block markup.', 'ekwa' )
				)
			);

			// HTML textarea.
			inputChildren.push(
				el( TextareaControl, {
					key: 'html',
					label: __( 'HTML Markup', 'ekwa' ),
					value: htmlValue,
					onChange: setHtmlValue,
					rows: 14,
					className: 'ekwa-mc-textarea',
					placeholder: '<section class="hero">...</section>',
				} )
			);

			// Manifest settings.
			inputChildren.push(
				el( 'div', { key: 'manifest', className: 'ekwa-mc-manifest' },
					el( ToggleControl, {
						label: __( 'Use server manifest', 'ekwa' ),
						help: __( 'Auto-detect from wp-content/uploads/', 'ekwa' ),
						checked: useServerM,
						onChange: setUseServerM,
					} ),
					! useServerM ? el( 'div', { className: 'ekwa-mc-manifest-upload' },
						el( 'input', {
							ref: fileRef,
							type: 'file',
							accept: '.json',
							onChange: handleManifestFile,
						} ),
						manifestName ? el( 'span', { className: 'ekwa-mc-manifest-name' }, manifestName ) : null,
						manifestName ? el( Button, {
							isSmall: true,
							isDestructive: true,
							onClick: function () {
								setManifestData( null );
								setManifestName( '' );
								if ( fileRef.current ) fileRef.current.value = '';
							},
						}, __( 'Clear', 'ekwa' ) ) : null
					) : null
				)
			);

			// Dynamic data detection toggle.
			inputChildren.push(
				el( ToggleControl, {
					key: 'detect-dynamic',
					label: __( 'Detect dynamic data', 'ekwa' ),
					help: __( 'Auto-replace phone, email, hours, social links with dynamic shortcodes.', 'ekwa' ),
					checked: detectDyn,
					onChange: setDetectDyn,
				} )
			);

			// Error.
			if ( error ) {
				inputChildren.push(
					el( Notice, { key: 'err', status: 'error', isDismissible: true, onRemove: function () { setError( null ); } },
						error
					)
				);
			}

			// Convert button.
			inputChildren.push(
				el( 'div', { key: 'actions', className: 'ekwa-mc-actions' },
					el( Button, {
						variant: 'primary',
						isBusy: converting,
						disabled: converting || ! htmlValue.trim(),
						onClick: handleConvert,
						className: 'ekwa-mc-convert-btn',
					}, converting
						? el( Fragment, null, el( Spinner, null ), __( ' Converting...', 'ekwa' ) )
						: __( 'Convert to Blocks', 'ekwa' )
					)
				)
			);

			return el( Modal, {
				title: __( 'Mockup Converter', 'ekwa' ),
				onRequestClose: onClose,
				className: 'ekwa-converter-modal',
				shouldCloseOnClickOutside: false,
			}, inputChildren );
		}

		// ── Render: Result Step ──────────────────────────────────────

		var resultChildren = [];

		// Back button + title row.
		resultChildren.push(
			el( 'div', { key: 'header', className: 'ekwa-mc-result-header' },
				el( Button, {
					isSmall: true,
					icon: 'arrow-left-alt',
					onClick: handleBack,
				}, __( 'Back', 'ekwa' ) )
			)
		);

		// Missing media mapping section.
		var missingKeys = Object.keys( mediaMaps );
		var hasMissing  = missingKeys.length > 0;
		var allMapped   = hasMissing && missingKeys.every( function ( k ) { return mediaMaps[ k ].media; } );

		if ( hasMissing ) {
			var mediaRows = missingKeys.map( function ( key ) {
				var entry = mediaMaps[ key ];
				return el( MediaMappingRow, {
					key: key,
					filename: entry.filename,
					mapped: entry.media,
					onSelect: function ( media ) {
						var updated = Object.assign( {}, mediaMaps );
						updated[ key ] = Object.assign( {}, entry, { media: media } );
						setMediaMaps( updated );
					},
					onClear: function () {
						var updated = Object.assign( {}, mediaMaps );
						updated[ key ] = Object.assign( {}, entry, { media: null } );
						setMediaMaps( updated );
					},
				} );
			} );

			var mappedCount = missingKeys.filter( function ( k ) { return mediaMaps[ k ].media; } ).length;

			resultChildren.push(
				el( 'div', { key: 'media-section', className: 'ekwa-mc-media-section' },
					el( 'div', { className: 'ekwa-mc-media-header' },
						el( 'h3', null, __( 'Missing Media', 'ekwa' ) ),
						el( 'span', { className: 'ekwa-mc-media-count' },
							mappedCount + ' / ' + missingKeys.length + ' ' + __( 'mapped', 'ekwa' )
						)
					),
					el( 'p', { className: 'ekwa-mc-media-help' },
						__( 'These files were not found in the manifest. Map them to media library items and re-convert.', 'ekwa' )
					),
					el( 'div', { className: 'ekwa-mc-media-list' }, mediaRows ),
					el( 'div', { className: 'ekwa-mc-media-actions' },
						el( Button, {
							variant: 'primary',
							disabled: mappedCount === 0 || converting,
							isBusy: converting,
							onClick: handleReconvert,
						}, __( 'Re-convert with Mappings', 'ekwa' ) )
					)
				)
			);
		}

		// Error.
		if ( error ) {
			resultChildren.push(
				el( Notice, { key: 'err', status: 'error', isDismissible: true, onRemove: function () { setError( null ); } },
					error
				)
			);
		}

		// Warnings (non-media).
		var otherWarnings = ( warnings || [] ).filter( function ( w ) {
			return ! /No manifest match/i.test( w );
		} );
		if ( otherWarnings.length > 0 ) {
			resultChildren.push(
				el( Notice, { key: 'warnings', status: 'warning', isDismissible: false },
					el( 'ul', { className: 'ekwa-mc-warnings-list' },
						otherWarnings.map( function ( w, i ) { return el( 'li', { key: i }, w ); } )
					)
				)
			);
		}

		// AI Refinement section.
		if ( markup && ! refined && ! refining ) {
			resultChildren.push(
				el( 'div', { key: 'ai-section', className: 'ekwa-mc-ai-section' },
					el( 'div', { className: 'ekwa-mc-ai-prompt' },
						el( 'span', { className: 'dashicons dashicons-superhero ekwa-mc-ai-icon' } ),
						el( 'div', { className: 'ekwa-mc-ai-prompt-text' },
							el( 'strong', null, __( 'AI Refinement', 'ekwa' ) ),
							el( 'p', null, __( 'Improve block types, fix nesting, and wire dynamic data blocks.', 'ekwa' ) )
						),
						el( Button, {
							variant: 'secondary',
							onClick: handleRefine,
							className: 'ekwa-mc-ai-btn',
						}, __( 'Refine with AI', 'ekwa' ) )
					)
				)
			);
		}

		if ( refining ) {
			resultChildren.push(
				el( 'div', { key: 'ai-refining', className: 'ekwa-mc-ai-refining' },
					el( Spinner, null ),
					el( 'span', null, __( 'Refining with AI... This may take a moment.', 'ekwa' ) )
				)
			);
		}

		if ( aiError ) {
			resultChildren.push(
				el( Notice, { key: 'ai-err', status: 'error', isDismissible: true,
					onRemove: function () { setAiError( null ); }
				}, aiError )
			);
		}

		// Before/After toggle.
		if ( refined ) {
			resultChildren.push(
				el( 'div', { key: 'ai-toggle', className: 'ekwa-mc-ai-toggle' },
					el( Button, {
						variant: showAi ? 'primary' : 'secondary',
						isSmall: true,
						onClick: function () { setShowAi( true ); },
					}, __( 'AI Refined', 'ekwa' ) ),
					el( Button, {
						variant: ! showAi ? 'primary' : 'secondary',
						isSmall: true,
						onClick: function () { setShowAi( false ); },
					}, __( 'Original', 'ekwa' ) )
				)
			);

			if ( aiNotes && aiNotes.length > 0 ) {
				resultChildren.push(
					el( Notice, { key: 'ai-notes', status: 'info', isDismissible: false },
						el( 'strong', null, __( 'AI Changes:', 'ekwa' ) ),
						el( 'ul', { className: 'ekwa-mc-ai-notes-list' },
							aiNotes.map( function ( n, i ) { return el( 'li', { key: i }, n ); } )
						)
					)
				);
			}
		}

		// Result markup.
		if ( markup ) {
			resultChildren.push(
				el( 'div', { key: 'result', className: 'ekwa-mc-result' },
					el( 'div', { className: 'ekwa-mc-result-label' },
						el( 'strong', null, __( 'Block Markup', 'ekwa' )
							+ ( refined && showAi ? ' (AI Refined)' : '' ) ),
						hasMissing && ! allMapped && ! showAi
							? el( 'span', { className: 'ekwa-mc-result-note' },
								__( 'Some media not mapped — you can still insert and fix later', 'ekwa' )
							)
							: null
					),
					el( TextareaControl, {
						value: activeMarkup,
						readOnly: true,
						rows: 12,
						className: 'ekwa-mc-textarea',
					} ),
					el( 'div', { className: 'ekwa-mc-result-actions' },
						el( Button, {
							variant: 'secondary',
							onClick: handleCopy,
						}, copied ? __( 'Copied!', 'ekwa' ) : __( 'Copy to Clipboard', 'ekwa' ) ),
						el( Button, {
							variant: 'primary',
							onClick: handleInsert,
						}, __( 'Insert into Editor', 'ekwa' ) )
					)
				)
			);
		}

		return el( Modal, {
			title: __( 'Mockup Converter', 'ekwa' ),
			onRequestClose: onClose,
			className: 'ekwa-converter-modal',
			shouldCloseOnClickOutside: false,
		}, resultChildren );
	}

	// ─── Plugin Registration ────────────────────────────────────────────────

	function ConverterPlugin() {
		var ms = useState( false );
		var isOpen  = ms[0];
		var setOpen = ms[1];

		var trigger;

		if ( PluginMoreMenuItem ) {
			trigger = el( PluginMoreMenuItem, {
				icon: 'editor-code',
				onClick: function () { setOpen( true ); },
			}, __( 'Mockup Converter', 'ekwa' ) );
		} else {
			trigger = el( Button, {
				icon: 'editor-code',
				label: __( 'Mockup Converter', 'ekwa' ),
				onClick: function () { setOpen( true ); },
				className: 'ekwa-converter-fab',
			}, __( 'Converter', 'ekwa' ) );
		}

		return el( Fragment, null,
			trigger,
			isOpen
				? el( ConverterModal, { onClose: function () { setOpen( false ); } } )
				: null
		);
	}

	registerPlugin( 'ekwa-converter', {
		render: ConverterPlugin,
		icon: 'editor-code',
	} );

} )( window.wp );
