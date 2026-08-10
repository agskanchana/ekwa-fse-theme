/**
 * Ekwa Shortcode Blocks — slug panel in the editor sidebar.
 *
 * Adds a "Shortcode" document panel to the ekwa_shortcode post type: the slug
 * field (leave blank to auto-generate from the title on save) and the finished
 * shortcode string with a copy button.
 *
 * The slug shown before the first save is a client-side preview of what the
 * server will generate — ekwa_shortcode_blocks_sync_slug() is the authority and
 * also resolves collisions, so the panel re-reads the meta after each save.
 *
 * Plain wp.element (no JSX / build step), matching the theme's other editor JS.
 *
 * @package ekwa
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.data ) {
		return;
	}

	var el             = wp.element.createElement;
	var Fragment       = wp.element.Fragment;
	var useState       = wp.element.useState;
	var registerPlugin = wp.plugins.registerPlugin;
	var TextControl    = wp.components.TextControl;
	var Button         = wp.components.Button;
	var useSelect      = wp.data.useSelect;
	var useDispatch    = wp.data.useDispatch;
	var __             = wp.i18n.__;

	// Core moved the document-panel slot from wp-edit-post to wp-editor in 6.6;
	// keep the old path so this still mounts on earlier releases.
	var PluginDocumentSettingPanel = ( wp.editor && wp.editor.PluginDocumentSettingPanel )
		|| ( wp.editPost && wp.editPost.PluginDocumentSettingPanel ) || null;

	if ( ! PluginDocumentSettingPanel ) {
		return; // No panel host — the server still assigns a slug on save.
	}

	var CFG      = window.ekwaShortcodeBlocks || {};
	var META_KEY = CFG.metaKey || '_ekwa_shortcode_slug';

	/**
	 * Mirror of the PHP normalizer (ekwa_shortcode_blocks_normalize_slug) so the
	 * preview matches what gets stored. Accents are stripped by the server's
	 * remove_accents(); here they simply collapse into hyphens, which only
	 * affects the preview of a not-yet-saved title.
	 *
	 * @param {string} value Raw slug or title.
	 * @return {string} Shortcode-safe slug.
	 */
	function normalizeSlug( value ) {
		var slug = String( value || '' )
			.toLowerCase()
			.replace( /[^a-z0-9_-]+/g, '-' )
			.replace( /-{2,}/g, '-' )
			.replace( /^-+|-+$/g, '' );

		if ( slug && /^[0-9]+$/.test( slug ) ) {
			slug = 'sc-' + slug;
		}
		return slug;
	}

	/**
	 * Copy button with transient "Copied" feedback.
	 *
	 * @param {Object} props       Component props.
	 * @param {string} props.text  Text to place on the clipboard.
	 * @return {Object} Element.
	 */
	function CopyButton( props ) {
		var state = useState( false );
		var done  = state[ 0 ];
		var setDone = state[ 1 ];

		function flash() {
			setDone( true );
			setTimeout( function () { setDone( false ); }, 1800 );
		}

		function copy() {
			var text = props.text;
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( flash, function () { fallback( text ); } );
			} else {
				fallback( text );
			}
		}

		function fallback( text ) {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.style.position = 'fixed';
			ta.style.opacity  = '0';
			document.body.appendChild( ta );
			ta.select();
			try {
				document.execCommand( 'copy' );
				flash();
			} catch ( e ) {}
			document.body.removeChild( ta );
		}

		return el( Button, {
			variant: 'secondary',
			size: 'small',
			disabled: ! props.text,
			onClick: copy,
		}, done ? __( '✓ Copied' ) : __( 'Copy shortcode' ) );
	}

	function ShortcodePanel() {
		var data = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			return {
				meta:   editor.getEditedPostAttribute( 'meta' ) || {},
				title:  editor.getEditedPostAttribute( 'title' ) || '',
				status: editor.getEditedPostAttribute( 'status' ),
			};
		}, [] );

		var editPost = useDispatch( 'core/editor' ).editPost;

		var stored = data.meta[ META_KEY ] || '';
		// Before the first save the server hasn't generated anything yet, so
		// preview what it will derive from the title.
		var effective = normalizeSlug( stored ) || normalizeSlug( data.title );
		var isPreview = ! normalizeSlug( stored );
		var published = 'publish' === data.status;

		function setSlug( value ) {
			var next = {};
			next[ META_KEY ] = value;
			editPost( { meta: next } );
		}

		var children = [
			el( TextControl, {
				key: 'slug',
				label: __( 'Shortcode slug' ),
				value: stored,
				placeholder: effective || __( 'auto-generated from the title' ),
				help: __( 'Lowercase letters, numbers, hyphens and underscores. Leave blank to generate it from the title on save.' ),
				onChange: setSlug,
				onBlur: function () {
					// Normalize once the author stops typing rather than on every
					// keystroke, so a trailing hyphen mid-word isn't eaten.
					var clean = normalizeSlug( stored );
					if ( clean !== stored ) {
						setSlug( clean );
					}
				},
				__nextHasNoMarginBottom: true,
			} ),
		];

		if ( effective ) {
			children.push(
				el( 'div', {
					key: 'output',
					style: { marginTop: '12px' },
				},
					el( 'p', {
						style: { margin: '0 0 4px', fontSize: '11px', fontWeight: 500, textTransform: 'uppercase', color: '#757575' },
					}, __( 'Use this shortcode' ) ),
					el( 'code', {
						style: {
							display: 'block',
							padding: '6px 8px',
							marginBottom: '8px',
							background: '#f0f0f1',
							borderRadius: '2px',
							fontSize: '12px',
							wordBreak: 'break-all',
						},
					}, '[' + effective + ']' ),
					el( CopyButton, { text: '[' + effective + ']' } ),
					el( 'p', {
						style: { margin: '8px 0 0', fontSize: '12px', color: '#757575' },
					},
						__( 'Namespaced form (always works, even if a plugin owns the tag above): ' ),
						el( 'code', null, '[ekwa_block slug="' + effective + '"]' )
					)
				)
			);
		}

		if ( isPreview && effective ) {
			children.push(
				el( 'p', {
					key: 'preview-note',
					style: { marginTop: '8px', fontSize: '12px', color: '#757575' },
				}, __( 'Preview — the final slug is confirmed on save (a duplicate gets a -2 suffix).' ) )
			);
		}

		if ( ! published ) {
			children.push(
				el( 'p', {
					key: 'draft-note',
					style: { marginTop: '8px', fontSize: '12px', color: '#b32d2e' },
				}, __( 'This shortcode outputs nothing until the post is published.' ) )
			);
		}

		return el( PluginDocumentSettingPanel, {
			name: 'ekwa-shortcode-slug',
			title: __( 'Shortcode' ),
			className: 'ekwa-shortcode-slug-panel',
		}, el( Fragment, null, children ) );
	}

	registerPlugin( 'ekwa-shortcode-blocks', { render: ShortcodePanel } );
} )( window.wp );
