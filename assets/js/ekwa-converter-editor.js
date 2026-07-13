/**
 * Ekwa Mockup Converter — Gutenberg Editor Plugin.
 *
 * Adds a "Mockup Converter" trigger to the editor. Features:
 *  - Paste HTML, convert to WP block markup via REST API
 *  - Map missing media to WP media library items
 *  - Copy or insert converted blocks into the editor
 *
 * Also exposes window.ekwaMockupConverter so the AI HTML Generator plugin can
 * open this modal with pre-filled HTML (handoff from "Send to Markup Converter").
 *
 * @package ekwa
 */
( function ( wp ) {
	'use strict';

	var el                 = wp.element.createElement;
	var Fragment           = wp.element.Fragment;
	var useState           = wp.element.useState;
	var useRef             = wp.element.useRef;
	var useEffect          = wp.element.useEffect;
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
	var createBlock        = wp.blocks.createBlock;
	var SelectControl      = wp.components.SelectControl;
	var dispatch           = wp.data.dispatch;
	var __                 = wp.i18n.__;

	var PluginMoreMenuItem = ( wp.editor && wp.editor.PluginMoreMenuItem )
		? wp.editor.PluginMoreMenuItem
		: ( wp.editPost && wp.editPost.PluginMoreMenuItem
			? wp.editPost.PluginMoreMenuItem
			: null );

	// ─── Cross-plugin handoff store ─────────────────────────────────────────
	// The AI Generator plugin calls window.ekwaMockupConverter.openWithHtml(html)
	// to pre-fill this modal. We hold the pending HTML and a single open-listener
	// that the converter plugin registers when it mounts.

	var pendingHtml   = '';
	var openListener  = null;

	window.ekwaMockupConverter = window.ekwaMockupConverter || {};

	window.ekwaMockupConverter.openWithHtml = function ( html ) {
		pendingHtml = typeof html === 'string' ? html : '';
		if ( typeof openListener === 'function' ) {
			openListener();
		}
	};

	// Internal helpers used by the modal component.
	function consumePendingHtml() {
		var v = pendingHtml;
		pendingHtml = '';
		return v;
	}

	function setOpenListener( fn ) {
		openListener = fn;
	}

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
		var onClose      = props.onClose;
		var initialHtml  = props.initialHtml || '';

		// ── State ────────────────────────────────────────────────────
		var s1 = useState( initialHtml ); var htmlValue    = s1[0]; var setHtmlValue    = s1[1];
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
		var s22 = useState( false );   var aiConvert   = s22[0]; var setAiConvert   = s22[1];
		// steps: 'input' | 'result'

		// Mockup CSS import + structured report.
		var s13 = useState( '' );        var cssValue   = s13[0]; var setCssValue   = s13[1];
		var s14 = useState( 'extract' ); var cssMode    = s14[0]; var setCssMode    = s14[1];
		var s21 = useState( false );     var aiExtract  = s21[0]; var setAiExtract  = s21[1];
		var s15 = useState( null );      var cssExtract = s15[0]; var setCssExtract = s15[1];
		var s16 = useState( false );     var cssSaved   = s16[0]; var setCssSaved   = s16[1];
		var s17 = useState( '' );        var cssScoped  = s17[0]; var setCssScoped  = s17[1];
		var s18 = useState( [] );        var report     = s18[0]; var setReport     = s18[1];

		var fileRef = useRef( null );

		// ── Handlers ─────────────────────────────────────────────────

		function doConvert( extraManifest ) {
			setConverting( true );
			setError( null );
			setWarnings( [] );
			setMarkup( '' );
			setCopied( false );

			// AI Convert — semantic conversion. The whole HTML goes to Gemini,
			// which maps dynamic content to the dynamic blocks and preserves the
			// rest. The CSS workflow (extract / child / scoped / AI-extract) is
			// the same as the deterministic path.
			if ( aiConvert ) {
				var aiBody = { html: htmlValue };
				if ( cssValue.trim() ) {
					aiBody.css      = cssValue;
					aiBody.css_mode = cssMode;
				}
				if ( aiExtract ) {
					aiBody.css_ai_extract = true;
				}
				apiFetch( {
					path: '/ekwa/v1/ai-convert',
					method: 'POST',
					data: aiBody,
				} ).then( function ( res ) {
					setMarkup( res.markup || '' );
					setWarnings( res.warnings || [] );
					setReport( [] );
					setCssExtract( res.css_extract || null );
					setCssSaved( !! res.css_saved );
					setCssScoped( res.css_scoped || '' );
					setMediaMaps( {} );
					setConverting( false );
					setStep( 'result' );
				} ).catch( function ( err ) {
					var msg = err.message || 'AI conversion failed.';
					if ( err.code === 'ekwa_ai_rate_limited' || err.code === 'ekwa_ai_forbidden' ) {
						msg = err.message;
					}
					setError( msg );
					setConverting( false );
				} );
				return;
			}

			var body = {
				html: htmlValue,
				use_server_manifest: useServerM,
				detect_dynamic: detectDyn,
			};

			if ( cssValue.trim() ) {
				body.css      = cssValue;
				body.css_mode = cssMode;
			}
			if ( aiExtract ) {
				// Server extracts this section's rules from the pasted CSS (or
				// the saved Design Tokens mockup stylesheet when none pasted)
				// and returns them as css_scoped for the wrapper block.
				body.css_ai_extract = true;
			}

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
				setReport( res.report || [] );
				setCssExtract( res.css_extract || null );
				setCssSaved( !! res.css_saved );
				setCssScoped( res.css_scoped || '' );
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

		function handleInsert() {
			if ( ! markup ) return;
			var blocks = parse( markup );
			if ( ! blocks || ! blocks.length ) return;

			// "Attach to section" CSS mode: carry the mockup CSS in the wrapper's
			// scopedCss attribute so it inlines only where this section renders.
			if ( cssScoped ) {
				if ( blocks.length === 1 && blocks[0].name === 'ekwa/div' ) {
					var existing = blocks[0].attributes.scopedCss || '';
					blocks[0].attributes.scopedCss = existing ? existing + '\n\n' + cssScoped : cssScoped;
				} else {
					blocks = [ createBlock( 'ekwa/div', { scopedCss: cssScoped, className: 'ekwa-mc-import' }, blocks ) ];
				}
			}

			dispatch( 'core/block-editor' ).insertBlocks( blocks );
			onClose();
		}

		function handleCopy() {
			if ( ! markup ) return;

			function onSuccess() {
				setCopied( true );
				setTimeout( function () { setCopied( false ); }, 2000 );
			}

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( markup ).then( onSuccess ).catch( function () {
					copyFallback( markup );
					onSuccess();
				} );
			} else {
				copyFallback( markup );
				onSuccess();
			}
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

			// Mockup CSS import.
			inputChildren.push(
				el( 'div', { key: 'css', className: 'ekwa-mc-css' },
					el( TextareaControl, {
						label: __( 'Mockup CSS (optional)', 'ekwa' ),
						value: cssValue,
						onChange: setCssValue,
						rows: 8,
						className: 'ekwa-mc-textarea',
						placeholder: '/* paste the mockup’s style.css here */',
						help: __( 'Fonts and colors are always extracted and shown after conversion.', 'ekwa' ),
					} ),
					el( ToggleControl, {
						label: __( 'Extract this section’s CSS with AI → attach as Scoped CSS', 'ekwa' ),
						help: __( 'Pulls just the rules that style this HTML (incl. ::before/::after, hover, media queries), rewritten to your design-token variables. Uses the field above, or the stylesheet saved in Ekwa Settings → Design Setup when empty.', 'ekwa' ),
						checked: aiExtract,
						onChange: setAiExtract,
					} ),
					( cssValue.trim() && ! aiExtract ) ? el( SelectControl, {
						label: __( 'CSS destination', 'ekwa' ),
						value: cssMode,
						options: [
							{ label: __( 'Just extract fonts & colors', 'ekwa' ), value: 'extract' },
							{ label: __( 'Append to child theme style.css', 'ekwa' ), value: 'child' },
							{ label: __( 'Attach to section as Scoped CSS (inlined only where it renders)', 'ekwa' ), value: 'scoped' },
						],
						onChange: setCssMode,
					} ) : null
				)
			);

			// Manifest settings. (Import the mockup's images/videos once from
			// Ekwa Settings → Design Setup → "Import mockup assets"; the server
			// manifest then resolves their filenames automatically here.)
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

			// AI Convert toggle — the foolproof semantic path.
			inputChildren.push(
				el( 'div', { key: 'ai-convert', className: 'ekwa-mc-aiconvert' },
					el( ToggleControl, {
						label: __( 'Convert with AI (semantic — best for headers, footers & complex layouts)', 'ekwa' ),
						help: aiConvert
							? __( 'The AI reads the whole HTML and maps phone, address, hours, social, copyright, logo, menu and map to the dynamic blocks — without over-capturing or dropping content. Slower, uses your AI quota. The CSS options above work the same as the fast path.', 'ekwa' )
							: __( 'Uses Gemini to understand the layout instead of pattern-matching. Turn on for headers/footers or any dense section the fast converter mis-maps.', 'ekwa' ),
						checked: aiConvert,
						onChange: setAiConvert,
					} )
				)
			);

			// Dynamic data detection toggle (deterministic path only).
			if ( ! aiConvert ) {
				inputChildren.push(
					el( ToggleControl, {
						key: 'detect-dynamic',
						label: __( 'Detect dynamic data', 'ekwa' ),
						help: __( 'Auto-replace phone, email, hours, social links with dynamic shortcodes.', 'ekwa' ),
						checked: detectDyn,
						onChange: setDetectDyn,
					} )
				);
			}

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
						? el( Fragment, null, el( Spinner, null ), aiConvert ? __( ' Converting with AI…', 'ekwa' ) : __( ' Converting...', 'ekwa' ) )
						: ( aiConvert ? __( 'Convert with AI', 'ekwa' ) : __( 'Convert to Blocks', 'ekwa' ) )
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

		// CSS import results — extracted fonts/colors + destination confirmation.
		if ( cssExtract && ( ( cssExtract.fonts || [] ).length || ( cssExtract.colors || [] ).length ) ) {
			var cssChildren = [
				el( 'div', { key: 'css-head', className: 'ekwa-mc-report-head' },
					el( 'strong', null, __( 'Mockup CSS', 'ekwa' ) ),
					cssSaved
						? el( 'span', { className: 'ekwa-mc-css-saved' }, __( '✓ appended to child style.css', 'ekwa' ) )
						: ( cssScoped
							? el( 'span', { className: 'ekwa-mc-css-saved' }, __( 'will attach to the section on insert', 'ekwa' ) )
							: null )
				),
			];
			if ( ( cssExtract.fonts || [] ).length ) {
				cssChildren.push(
					el( 'div', { key: 'fonts', className: 'ekwa-mc-css-row' },
						el( 'span', { className: 'ekwa-mc-css-label' }, __( 'Fonts found:', 'ekwa' ) ),
						cssExtract.fonts.map( function ( f, i ) {
							return el( 'code', { key: i, className: 'ekwa-mc-chip' }, f );
						} ),
						el( 'span', { className: 'ekwa-mc-css-hint' },
							__( '→ self-host them via Ekwa Settings → Fonts', 'ekwa' )
						)
					)
				);
			}
			if ( ( cssExtract.colors || [] ).length ) {
				cssChildren.push(
					el( 'div', { key: 'colors', className: 'ekwa-mc-css-row' },
						el( 'span', { className: 'ekwa-mc-css-label' }, __( 'Palette:', 'ekwa' ) ),
						cssExtract.colors.map( function ( c, i ) {
							return el( 'span', {
								key: i,
								className: 'ekwa-mc-swatch',
								title: c.value + ( c.var ? ' (' + c.var + ')' : '' ) + ' ×' + c.count,
							},
								el( 'i', { style: { background: c.value } } ),
								el( 'code', null, c.var || c.value )
							);
						} )
					)
				);
			}
			resultChildren.push( el( 'div', { key: 'css-extract', className: 'ekwa-mc-css-extract' }, cssChildren ) );
		}

		// Conversion report — grouped by category so nothing is silently lost.
		var reportGroups = {};
		( report || [] ).forEach( function ( entry ) {
			var cat = entry.category || 'general';
			if ( ! reportGroups[ cat ] ) { reportGroups[ cat ] = []; }
			reportGroups[ cat ].push( entry.message );
		} );
		var groupMeta = {
			'dynamic':   { label: __( 'Dynamic data detected', 'ekwa' ),      tone: 'ok' },
			'converted': { label: __( 'Rescued into editable blocks', 'ekwa' ), tone: 'ok' },
			'media':     { label: __( 'Media', 'ekwa' ),                      tone: 'warn' },
			'raw-html':  { label: __( 'Raw HTML fallbacks', 'ekwa' ),         tone: 'warn' },
			'dropped':   { label: __( 'Dropped content', 'ekwa' ),            tone: 'bad' },
			'general':   { label: __( 'Notes', 'ekwa' ),                      tone: 'warn' },
		};
		var groupOrder = [ 'dropped', 'raw-html', 'media', 'general', 'converted', 'dynamic' ];
		var reportSections = [];
		groupOrder.forEach( function ( cat ) {
			var msgs = reportGroups[ cat ];
			if ( ! msgs || ! msgs.length ) { return; }
			// Media misses are handled by the mapping UI above — skip duplicates.
			if ( cat === 'media' ) {
				msgs = msgs.filter( function ( m ) { return ! /No manifest match/i.test( m ); } );
				if ( ! msgs.length ) { return; }
			}
			var meta = groupMeta[ cat ] || groupMeta.general;
			reportSections.push(
				el( 'details', { key: cat, className: 'ekwa-mc-report-group ekwa-mc-report-group--' + meta.tone, open: meta.tone !== 'ok' },
					el( 'summary', null, meta.label + ' (' + msgs.length + ')' ),
					el( 'ul', { className: 'ekwa-mc-warnings-list' },
						msgs.map( function ( m, i ) { return el( 'li', { key: i }, m ); } )
					)
				)
			);
		} );
		if ( reportSections.length ) {
			resultChildren.push(
				el( 'div', { key: 'report', className: 'ekwa-mc-report' },
					el( 'div', { className: 'ekwa-mc-report-head' },
						el( 'strong', null, __( 'Conversion report', 'ekwa' ) )
					),
					reportSections
				)
			);
		} else {
			// Fallback for older responses without a structured report.
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
		}

		// Result markup.
		if ( markup ) {
			resultChildren.push(
				el( 'div', { key: 'result', className: 'ekwa-mc-result' },
					el( 'div', { className: 'ekwa-mc-result-label' },
						el( 'strong', null, __( 'Block Markup', 'ekwa' ) ),
						hasMissing && ! allMapped
							? el( 'span', { className: 'ekwa-mc-result-note' },
								__( 'Some media not mapped — you can still insert and fix later', 'ekwa' )
							)
							: null
					),
					el( TextareaControl, {
						value: markup,
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

		var hs = useState( '' );
		var modalInitialHtml = hs[0];
		var setModalInitialHtml = hs[1];

		// Register the open-listener so the AI Generator plugin can open us.
		useEffect( function () {
			setOpenListener( function () {
				setModalInitialHtml( consumePendingHtml() );
				setOpen( true );
			} );
			return function () { setOpenListener( null ); };
		}, [] );

		function handleClose() {
			setOpen( false );
			setModalInitialHtml( '' );
		}

		var trigger;

		if ( PluginMoreMenuItem ) {
			trigger = el( PluginMoreMenuItem, {
				icon: 'editor-code',
				onClick: function () {
					setModalInitialHtml( '' );
					setOpen( true );
				},
			}, __( 'Mockup Converter', 'ekwa' ) );
		} else {
			trigger = el( Button, {
				icon: 'editor-code',
				label: __( 'Mockup Converter', 'ekwa' ),
				onClick: function () {
					setModalInitialHtml( '' );
					setOpen( true );
				},
				className: 'ekwa-converter-fab',
			}, __( 'Converter', 'ekwa' ) );
		}

		return el( Fragment, null,
			trigger,
			isOpen
				? el( ConverterModal, {
					onClose: handleClose,
					initialHtml: modalInitialHtml,
				} )
				: null
		);
	}

	registerPlugin( 'ekwa-converter', {
		render: ConverterPlugin,
		icon: 'editor-code',
	} );

} )( window.wp );
