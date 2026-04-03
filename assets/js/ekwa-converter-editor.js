/**
 * Ekwa Mockup Converter — Gutenberg Editor Plugin.
 *
 * Adds a "Mockup Converter" trigger to the editor:
 *  - WP 6.6+: menu item in the Options (three-dot) menu (both editors).
 *  - WP < 6.6 post editor: menu item via wp.editPost.PluginMoreMenuItem.
 *  - WP < 6.6 site editor: floating button (bottom-left).
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
	var apiFetch           = wp.apiFetch;
	var parse              = wp.blocks.parse;
	var dispatch           = wp.data.dispatch;
	var __                 = wp.i18n.__;

	// Resolve PluginMoreMenuItem from whichever package has it.
	var PluginMoreMenuItem = ( wp.editor && wp.editor.PluginMoreMenuItem )
		? wp.editor.PluginMoreMenuItem
		: ( wp.editPost && wp.editPost.PluginMoreMenuItem
			? wp.editPost.PluginMoreMenuItem
			: null );

	// ─── Converter Modal Component ──────────────────────────────────────────

	function ConverterModal( props ) {
		var onClose = props.onClose;

		var htmlInput         = useState( '' );
		var htmlValue         = htmlInput[0];
		var setHtmlValue      = htmlInput[1];

		var manifestState     = useState( null );
		var manifestData      = manifestState[0];
		var setManifestData   = manifestState[1];

		var manifestNameState = useState( '' );
		var manifestFileName  = manifestNameState[0];
		var setManifestFileName = manifestNameState[1];

		var serverManifest    = useState( true );
		var useServerManifest = serverManifest[0];
		var setUseServerManifest = serverManifest[1];

		var convertingState   = useState( false );
		var isConverting      = convertingState[0];
		var setIsConverting   = convertingState[1];

		var resultState       = useState( '' );
		var resultMarkup      = resultState[0];
		var setResultMarkup   = resultState[1];

		var warningsState     = useState( [] );
		var warnings          = warningsState[0];
		var setWarnings       = warningsState[1];

		var errorState        = useState( null );
		var error             = errorState[0];
		var setError          = errorState[1];

		var copiedState       = useState( false );
		var copied            = copiedState[0];
		var setCopied         = copiedState[1];

		var fileInputRef      = useRef( null );

		// ─── Handlers ───────────────────────────────────────────────────

		function handleConvert() {
			if ( ! htmlValue.trim() ) {
				setError( 'Please paste some HTML markup first.' );
				return;
			}

			setIsConverting( true );
			setError( null );
			setWarnings( [] );
			setResultMarkup( '' );
			setCopied( false );

			var body = {
				html: htmlValue,
				use_server_manifest: useServerManifest,
			};

			if ( ! useServerManifest && manifestData ) {
				body.manifest = manifestData;
				body.use_server_manifest = false;
			}

			apiFetch( {
				path: '/ekwa/v1/convert-markup',
				method: 'POST',
				data: body,
			} ).then( function ( res ) {
				setResultMarkup( res.markup || '' );
				setWarnings( res.warnings || [] );
				setIsConverting( false );
			} ).catch( function ( err ) {
				setError( err.message || 'Conversion failed.' );
				setIsConverting( false );
			} );
		}

		function handleInsert() {
			if ( ! resultMarkup ) return;
			var blocks = parse( resultMarkup );
			if ( blocks && blocks.length ) {
				dispatch( 'core/block-editor' ).insertBlocks( blocks );
				onClose();
			}
		}

		function handleCopy() {
			if ( ! resultMarkup ) return;
			navigator.clipboard.writeText( resultMarkup ).then( function () {
				setCopied( true );
				setTimeout( function () { setCopied( false ); }, 2000 );
			} );
		}

		function handleManifestUpload( event ) {
			var file = event.target.files && event.target.files[0];
			if ( ! file ) return;
			setManifestFileName( file.name );
			var reader = new FileReader();
			reader.onload = function ( e ) {
				try {
					var data = JSON.parse( e.target.result );
					setManifestData( data );
					setError( null );
				} catch ( err ) {
					setError( 'Invalid manifest JSON: ' + err.message );
					setManifestData( null );
				}
			};
			reader.readAsText( file );
		}

		function handleClearManifest() {
			setManifestData( null );
			setManifestFileName( '' );
			if ( fileInputRef.current ) {
				fileInputRef.current.value = '';
			}
		}

		// ─── Render ─────────────────────────────────────────────────────

		var children = [];

		// HTML input.
		children.push(
			el( TextareaControl, {
				key: 'html-input',
				label: __( 'HTML Markup', 'ekwa' ),
				help: __( 'Paste your mockup HTML here.', 'ekwa' ),
				value: htmlValue,
				onChange: setHtmlValue,
				rows: 12,
				className: 'ekwa-converter-textarea',
			} )
		);

		// Manifest section.
		var manifestChildren = [];

		manifestChildren.push(
			el( ToggleControl, {
				key: 'server-manifest-toggle',
				label: __( 'Use server manifest', 'ekwa' ),
				help: __( 'Auto-load manifest from wp-content/uploads/', 'ekwa' ),
				checked: useServerManifest,
				onChange: setUseServerManifest,
			} )
		);

		if ( ! useServerManifest ) {
			var uploadChildren = [];

			uploadChildren.push(
				el( 'input', {
					key: 'file-input',
					ref: fileInputRef,
					type: 'file',
					accept: '.json,application/json',
					onChange: handleManifestUpload,
					className: 'ekwa-converter-file-input',
				} )
			);

			if ( manifestFileName ) {
				uploadChildren.push(
					el( 'span', {
						key: 'file-name',
						className: 'ekwa-converter-manifest-name',
					}, manifestFileName )
				);
				uploadChildren.push(
					el( Button, {
						key: 'clear-manifest',
						isDestructive: true,
						isSmall: true,
						onClick: handleClearManifest,
					}, __( 'Clear', 'ekwa' ) )
				);
			}

			manifestChildren.push(
				el( 'div', {
					key: 'upload-row',
					className: 'ekwa-converter-upload-row',
				}, uploadChildren )
			);
		}

		children.push(
			el( 'div', {
				key: 'manifest-section',
				className: 'ekwa-converter-manifest-section',
			}, manifestChildren )
		);

		// Convert button.
		children.push(
			el( 'div', {
				key: 'actions',
				className: 'ekwa-converter-actions',
			},
				el( Button, {
					isPrimary: true,
					isBusy: isConverting,
					disabled: isConverting || ! htmlValue.trim(),
					onClick: handleConvert,
				}, isConverting ? __( 'Converting...', 'ekwa' ) : __( 'Convert', 'ekwa' ) )
			)
		);

		// Error notice.
		if ( error ) {
			children.push(
				el( Notice, {
					key: 'error',
					status: 'error',
					isDismissible: true,
					onRemove: function () { setError( null ); },
				}, error )
			);
		}

		// Warnings.
		if ( warnings.length > 0 ) {
			children.push(
				el( Notice, {
					key: 'warnings',
					status: 'warning',
					isDismissible: false,
				},
					el( 'strong', null, __( 'Warnings:', 'ekwa' ) ),
					el( 'ul', { className: 'ekwa-converter-warnings-list' },
						warnings.map( function ( w, i ) {
							return el( 'li', { key: i }, w );
						} )
					)
				)
			);
		}

		// Result section.
		if ( resultMarkup ) {
			var resultChildren = [];

			resultChildren.push(
				el( TextareaControl, {
					key: 'result-textarea',
					label: __( 'Block Markup', 'ekwa' ),
					value: resultMarkup,
					readOnly: true,
					rows: 12,
					className: 'ekwa-converter-textarea',
				} )
			);

			resultChildren.push(
				el( 'div', {
					key: 'result-actions',
					className: 'ekwa-converter-result-actions',
				},
					el( Button, {
						isSecondary: true,
						onClick: handleCopy,
					}, copied ? __( 'Copied!', 'ekwa' ) : __( 'Copy to Clipboard', 'ekwa' ) ),
					el( Button, {
						isPrimary: true,
						onClick: handleInsert,
					}, __( 'Insert into Editor', 'ekwa' ) )
				)
			);

			children.push(
				el( 'div', {
					key: 'result-section',
					className: 'ekwa-converter-result',
				}, resultChildren )
			);
		}

		return el( Modal, {
			title: __( 'Mockup Converter', 'ekwa' ),
			onRequestClose: onClose,
			className: 'ekwa-converter-modal',
			shouldCloseOnClickOutside: false,
		}, children );
	}

	// ─── Plugin Registration ────────────────────────────────────────────────

	function ConverterPlugin() {
		var modalState = useState( false );
		var isOpen     = modalState[0];
		var setIsOpen  = modalState[1];

		var trigger;

		if ( PluginMoreMenuItem ) {
			// WP 6.6+ (both editors) or post editor on older WP.
			trigger = el( PluginMoreMenuItem, {
				icon: 'editor-code',
				onClick: function () { setIsOpen( true ); },
			}, __( 'Mockup Converter', 'ekwa' ) );
		} else {
			// Fallback: floating button (site editor on WP < 6.6).
			trigger = el( Button, {
				icon: 'editor-code',
				label: __( 'Mockup Converter', 'ekwa' ),
				onClick: function () { setIsOpen( true ); },
				className: 'ekwa-converter-fab',
			}, __( 'Converter', 'ekwa' ) );
		}

		return el( Fragment, null,
			trigger,
			isOpen
				? el( ConverterModal, {
					onClose: function () { setIsOpen( false ); },
				} )
				: null
		);
	}

	registerPlugin( 'ekwa-converter', {
		render: ConverterPlugin,
		icon: 'editor-code',
	} );

} )( window.wp );
