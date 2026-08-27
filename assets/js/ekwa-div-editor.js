/**
 * Ekwa Div Block — Block Editor UI.
 *
 * Clean wrapper element that outputs only your tag, classes, and children.
 * Renders the actual tag in the editor so mockup CSS applies correctly.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType  = wp.blocks.registerBlockType;
	var el                 = wp.element.createElement;
	var Fragment           = wp.element.Fragment;
	var useRef             = wp.element.useRef;
	var useEffect          = wp.element.useEffect;
	var InnerBlocks        = wp.blockEditor.InnerBlocks;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var MediaUpload        = wp.blockEditor.MediaUpload;
	var MediaUploadCheck   = wp.blockEditor.MediaUploadCheck;
	var PanelBody          = wp.components.PanelBody;
	var SelectControl      = wp.components.SelectControl;
	var TextControl        = wp.components.TextControl;
	var TextareaControl    = wp.components.TextareaControl;
	var ToggleControl      = wp.components.ToggleControl;
	var Button             = wp.components.Button;
	var __                 = wp.i18n.__;
	var _n                 = wp.i18n._n;
	var sprintf            = wp.i18n.sprintf;
	var LinkSourceControls = window.EkwaLinkSource && window.EkwaLinkSource.Controls;
	var CustomAttrsControl = window.EkwaCustomAttributes && window.EkwaCustomAttributes.Control;

	var TAG_OPTIONS = [
		{ label: 'div',         value: 'div' },
		{ label: 'section',     value: 'section' },
		{ label: 'header',      value: 'header' },
		{ label: 'footer',      value: 'footer' },
		{ label: 'nav',         value: 'nav' },
		{ label: 'main',        value: 'main' },
		{ label: 'aside',       value: 'aside' },
		{ label: 'article',     value: 'article' },
		{ label: 'ul',          value: 'ul' },
		{ label: 'ol',          value: 'ol' },
		{ label: 'li',          value: 'li' },
		{ label: 'a',           value: 'a' },
		// A <p> that wraps an image can't be a core/paragraph (its src would
		// never resolve), so the converter lands those here.
		{ label: 'p',           value: 'p' },
		{ label: '— inline —',  value: '',         disabled: true },
		{ label: 'span',        value: 'span' },
		{ label: 'small',       value: 'small' },
		{ label: 'strong',      value: 'strong' },
		{ label: 'b',           value: 'b' },
		{ label: 'em',          value: 'em' },
		{ label: 'i',           value: 'i' },
		{ label: 'u',           value: 'u' },
		{ label: 'mark',        value: 'mark' },
		{ label: 'time',        value: 'time' },
		{ label: 'label',       value: 'label' },
		{ label: 'sup',         value: 'sup' },
		{ label: 'sub',         value: 'sub' },
	];

	/**
	 * Scoped-CSS editor: WP's bundled CodeMirror (same as Customizer Custom
	 * CSS — line numbers + CSS highlighting) mounted on a textarea. The
	 * attribute stays a plain string, so existing CSS is untouched. Falls back
	 * to the plain TextareaControl when wp.codeEditor isn't available (user
	 * disabled syntax highlighting in their profile).
	 */
	function ScopedCssEditor( props ) {
		var value        = props.value || '';
		var onChange     = props.onChange;
		var wrapRef      = useRef( null );
		var cmRef        = useRef( null );
		var onChangeRef  = useRef( onChange );
		onChangeRef.current = onChange;

		var canCodeMirror = !! ( wp.codeEditor && window.ekwaDivCodeEditor && window.ekwaDivCodeEditor.settings );

		useEffect( function () {
			if ( ! canCodeMirror || ! wrapRef.current ) {
				return undefined;
			}
			var textarea = wrapRef.current.querySelector( 'textarea' );
			if ( ! textarea ) {
				return undefined;
			}
			var instance = wp.codeEditor.initialize( textarea, window.ekwaDivCodeEditor.settings );
			cmRef.current = instance.codemirror;
			cmRef.current.on( 'change', function ( cm ) {
				onChangeRef.current( cm.getValue() );
			} );
			return function () {
				if ( cmRef.current && cmRef.current.toTextArea ) {
					cmRef.current.toTextArea();
				}
				cmRef.current = null;
			};
		}, [] );

		// External value changes (undo/redo, AI edit) → push into CodeMirror.
		useEffect( function () {
			if ( cmRef.current && cmRef.current.getValue() !== value ) {
				cmRef.current.setValue( value );
			}
		}, [ value ] );

		if ( ! canCodeMirror ) {
			return el( TextareaControl, {
				label: __( 'Scoped CSS' ),
				help: __( 'Auto-inlined on the front end only where this block renders. Selectors are scoped to this section’s class.' ),
				value: value,
				rows: 8,
				onChange: onChange,
			} );
		}

		return el( 'div', { className: 'ekwa-div-css-editor', ref: wrapRef },
			el( 'label', { className: 'ekwa-div-css-editor__label' }, __( 'Scoped CSS' ) ),
			el( 'textarea', { defaultValue: value, rows: 8 } ),
			el( 'p', { className: 'ekwa-div-css-editor__help' },
				__( 'Auto-inlined on the front end only where this block renders. Selectors are scoped to this section’s class.' )
			)
		);
	}

	/**
	 * Parse an inlineStyle string into a React style object.
	 * "background-image:url(...); color: white" → { backgroundImage: 'url(...)', color: 'white' }
	 */
	function parseStyleString( str ) {
		if ( ! str ) return {};
		var style = {};
		str.split( ';' ).forEach( function ( part ) {
			part = part.trim();
			if ( ! part ) return;
			var colon = part.indexOf( ':' );
			if ( colon < 1 ) return;
			var key = part.substring( 0, colon ).trim();
			var val = part.substring( colon + 1 ).trim();
			// Convert CSS key to camelCase.
			key = key.replace( /-([a-z])/g, function ( m, c ) { return c.toUpperCase(); } );
			style[ key ] = val;
		} );
		return style;
	}

	/**
	 * Undo the HTML escaping WordPress applies to string block attributes on
	 * save (core's filter_block_kses_value) for anyone without the
	 * `unfiltered_html` capability — Authors/Contributors, every user on
	 * multisite, and everyone on a site defining DISALLOW_UNFILTERED_HTML.
	 *
	 * kses is HTML-aware, not CSS-aware, so it escapes exactly the characters
	 * CSS needs: ".card > .title" becomes ".card &gt; .title" and the rule
	 * stops matching. Decoding on read means the panel shows real CSS and a
	 * re-save writes the clean form back. Mirrors ekwa_css_decode_entities().
	 */
	function decodeCss( css ) {
		if ( ! css || css.indexOf( '&' ) === -1 ) { return css || ''; }
		return css
			.replace( /&gt;/g, '>' )
			.replace( /&lt;/g, '<' )
			.replace( /&quot;/g, '"' )
			.replace( /&#0?39;/g, "'" )
			.replace( /&amp;/g, '&' ); // last, or "&amp;gt;" would collapse to ">"
	}

	/**
	 * Whether a set of attributes carries non-empty scoped CSS.
	 *
	 * @param {Object} attributes Block attributes (may be null for a stale clientId).
	 * @return {boolean} True when the wrapper owns CSS.
	 */
	function hasScopedCssAttr( attributes ) {
		return '' !== decodeCss( ( attributes && attributes.scopedCss ) || '' ).trim();
	}

	registerBlockType( 'ekwa/div', {
		/**
		 * List View row label.
		 *
		 * The canvas badge only helps once you're looking at the block; List View
		 * is where you scan a whole page, so the marker has to reach it too. Core
		 * runs this label through __unstableStripHTML, so it is plain text only —
		 * a styled pill isn't available here, unlike the canvas badge.
		 *
		 * Restricted to the 'list-view' context on purpose: returning a label for
		 * the default 'visual' context would also rewrite the inspector block card
		 * and the editor breadcrumb, which is more than this is meant to change.
		 * Returning undefined falls back to the block's own title.
		 *
		 * @param {Object} attributes      Block attributes.
		 * @param {Object} options         Label options.
		 * @param {string} options.context Where the label will be shown.
		 * @return {string|undefined} Label, or undefined to keep the default title.
		 */
		__experimentalLabel: function ( attributes, options ) {
			if ( ! options || 'list-view' !== options.context || ! hasScopedCssAttr( attributes ) ) {
				return undefined;
			}

			// Read the title off the registry rather than repeating the string
			// from block.json, so a child theme retitling the block still works.
			var blockType = wp.blocks.getBlockType && wp.blocks.getBlockType( 'ekwa/div' );
			var title     = ( blockType && blockType.title ) || __( 'Ekwa Div' );

			return title + ' — ' + ( attributes.scopedCssOffInEditor ? __( 'CSS off' ) : __( 'CSS' ) );
		},

		edit: function ( props ) {
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;
			var tagName       = attributes.tagName || 'div';
			var bgImage       = attributes.backgroundImage || '';
			var isSelected    = props.isSelected;

			// Scoped CSS carried by this wrapper. Because it lives in an
			// attribute — not in any stylesheet — nothing on screen says the
			// block owns styles, which makes a section's CSS easy to lose track
			// of. The badge + panel counter below are that signal. The rule
			// count is the number of `{` in the CSS: close enough for a hint,
			// and it costs no parser.
			var scopedCss      = decodeCss( attributes.scopedCss || '' );
			var hasScopedCss   = hasScopedCssAttr( attributes );
			var cssOffInEditor = !! attributes.scopedCssOffInEditor;
			var cssRuleCount   = hasScopedCss ? ( scopedCss.match( /\{/g ) || [] ).length : 0;

			// Build editor wrapper style from inlineStyle + backgroundImage.
			var editorStyle = parseStyleString( decodeCss( attributes.inlineStyle ) );
			if ( bgImage ) {
				editorStyle.backgroundImage = "url('" + bgImage + "')";
				if ( ! editorStyle.backgroundSize )     { editorStyle.backgroundSize = 'cover'; }
				if ( ! editorStyle.backgroundPosition ) { editorStyle.backgroundPosition = 'center'; }
			}

			// The editor renders <div> for all tags (WP requirement for useBlockProps),
			// but passes through all the user's CSS classes via className support.
			var blockProps = useBlockProps( {
				style: editorStyle,
				// Editor-only marker (save() emits InnerBlocks.Content, so this
				// never reaches the saved markup or the front end).
				className: hasScopedCss
					? 'ekwa-div--has-scoped-css' + ( cssOffInEditor ? ' ekwa-div--scoped-css-off' : '' )
					: undefined,
			} );

			var panels = [];

			// ── Element Settings ─────────────────────────────────────
			var settingsChildren = [];
			settingsChildren.push(
				el( SelectControl, {
					key: 'tag',
					label: __( 'HTML Tag' ),
					value: tagName,
					options: TAG_OPTIONS,
					onChange: function ( val ) { setAttributes( { tagName: val } ); },
				} )
			);

			if ( tagName === 'a' ) {
				if ( LinkSourceControls ) {
					settingsChildren.push(
						el( LinkSourceControls, {
							key:           'link-source',
							attributes:    attributes,
							setAttributes: setAttributes
						} )
					);
				} else {
					settingsChildren.push(
						el( TextControl, {
							key: 'url',
							label: __( 'URL' ),
							value: attributes.url || attributes.href || '',
							onChange: function ( val ) { setAttributes( { url: val } ); },
						} )
					);
				}
				settingsChildren.push(
					el( SelectControl, {
						key: 'target',
						label: __( 'Target' ),
						value: attributes.target || '',
						options: [
							{ label: __( 'Default' ), value: '' },
							{ label: '_blank',        value: '_blank' },
						],
						onChange: function ( val ) { setAttributes( { target: val } ); },
					} )
				);
				settingsChildren.push(
					el( TextControl, {
						key: 'rel',
						label: __( 'Rel' ),
						value: attributes.rel || '',
						onChange: function ( val ) { setAttributes( { rel: val } ); },
					} )
				);

				// Lightbox lives with the link controls because it only applies
				// to <a> — it turns this wrapper into the trigger and the href
				// becomes what opens. Typical use: a thumbnail (or a whole card)
				// linking to the full-size photo.
				settingsChildren.push(
					el( ToggleControl, {
						key: 'lightbox',
						label: __( 'Open in lightbox' ),
						checked: !! attributes.lightbox,
						onChange: function ( val ) { setAttributes( { lightbox: val } ); },
						help: __( 'Opens the URL above in an overlay instead of navigating. Works with images, YouTube/Vimeo links and MP4 files. The lightbox code loads only after the visitor interacts with the page.' ),
					} )
				);

				if ( attributes.lightbox ) {
					settingsChildren.push(
						el( TextControl, {
							key: 'lightbox-group',
							label: __( 'Gallery group' ),
							value: attributes.lightboxGroup || '',
							onChange: function ( val ) { setAttributes( { lightboxGroup: val } ); },
							help: __( 'Items sharing a group name open as one gallery with next/previous arrows and swipe. Leave empty to open on its own.' ),
						} )
					);
					settingsChildren.push(
						el( TextControl, {
							key: 'lightbox-caption',
							label: __( 'Caption' ),
							value: attributes.lightboxCaption || '',
							onChange: function ( val ) { setAttributes( { lightboxCaption: val } ); },
							help: __( 'Optional text shown under the media in the overlay.' ),
						} )
					);
				}
			}

			settingsChildren.push(
				el( TextareaControl, {
					key: 'style',
					label: __( 'Inline Style' ),
					help: __( 'Additional raw CSS properties.' ),
					value: attributes.inlineStyle || '',
					rows: 2,
					onChange: function ( val ) { setAttributes( { inlineStyle: val } ); },
				} )
			);

			panels.push(
				el( PanelBody, { key: 'settings', title: __( 'Element Settings' ), initialOpen: true },
					settingsChildren
				)
			);

			// ── Background Image ─────────────────────────────────────
			var bgChildren = [];

			if ( bgImage ) {
				bgChildren.push(
					el( 'div', {
						key: 'bg-preview',
						style: { marginBottom: '8px', borderRadius: '4px', overflow: 'hidden' },
					},
						el( 'img', {
							src: bgImage, alt: '',
							style: { width: '100%', height: 'auto', display: 'block' },
						} )
					)
				);
			}

			bgChildren.push(
				el( MediaUploadCheck, { key: 'bg-upload-check' },
					el( MediaUpload, {
						onSelect: function ( media ) {
							setAttributes( { backgroundImage: media.url, backgroundImageId: media.id } );
						},
						allowedTypes: [ 'image' ],
						value: attributes.backgroundImageId,
						render: function ( obj ) {
							return el( Button, {
								onClick: obj.open, isSecondary: true,
								style: { marginRight: '8px' },
							}, bgImage ? __( 'Replace Image' ) : __( 'Select Image' ) );
						},
					} )
				)
			);

			if ( bgImage ) {
				bgChildren.push(
					el( Button, {
						key: 'bg-clear', isDestructive: true, isSmall: true,
						onClick: function () {
							setAttributes( { backgroundImage: '', backgroundImageId: 0 } );
						},
					}, __( 'Remove' ) )
				);
			}

			if ( ! bgImage ) {
				bgChildren.push(
					el( TextControl, {
						key: 'bg-url',
						label: __( 'Or enter URL' ),
						value: '',
						onChange: function ( val ) {
							if ( val ) { setAttributes( { backgroundImage: val } ); }
						},
						style: { marginTop: '8px' },
					} )
				);
			}

			if ( bgImage ) {
				var preloadBg = !! attributes.preloadBg;

				bgChildren.push(
					el( ToggleControl, {
						key:     'preload-bg',
						label:   __( 'High fetch priority (LCP background)' ),
						help:    __( 'Adds a <link rel=preload fetchpriority=high> hint so the browser fetches this background first. Use only for the above-the-fold LCP background. Turns off lazy loading.' ),
						checked: preloadBg,
						onChange: function ( val ) {
							// Preloading the LCP and lazy-loading it are mutually
							// exclusive — turning one on forces the other off.
							setAttributes( { preloadBg: val, lazyBg: val ? false : attributes.lazyBg } );
						},
					} )
				);

				bgChildren.push(
					el( ToggleControl, {
						key:     'lazy-bg',
						label:   __( 'Lazy load background' ),
						help:    __( 'Defer painting until the wrapper enters the viewport. Turn off for above-the-fold heroes.' ),
						checked: attributes.lazyBg !== false,
						disabled: preloadBg,
						onChange: function ( val ) {
							setAttributes( { lazyBg: val, preloadBg: val ? false : attributes.preloadBg } );
						},
					} )
				);

				bgChildren.push(
					el( 'p', {
						key: 'webp-note',
						style: { fontSize: '12px', color: '#757575', marginTop: '4px' },
					}, __( 'Served as WebP automatically when the browser supports it; the original JPG/PNG is the fallback.' ) )
				);
			}

			panels.push(
				el( PanelBody, { key: 'background', title: __( 'Background Image' ), initialOpen: !! bgImage },
					bgChildren
				)
			);

			// ── Section CSS (advanced) ───────────────────────────────
			// Self-contained scoped CSS set by the AI Block Builder. Shown in a
			// collapsed panel so it stays viewable/editable without cluttering
			// the common controls. Applied in the canvas via the <style> below.
			if ( attributes.scopedCss || isSelected ) {
				panels.push(
					el( PanelBody, {
						key: 'scoped-css',
						// Carry the rule count in the collapsed title so the panel
						// advertises that this block has CSS without being opened.
						title: hasScopedCss
							? __( 'Section CSS (advanced)' ) + ' — ' +
								sprintf( _n( '%d rule', '%d rules', cssRuleCount ), cssRuleCount )
							: __( 'Section CSS (advanced)' ),
						initialOpen: false,
					},
						el( ScopedCssEditor, {
							key: 'scoped-css-editor',
							value: decodeCss( attributes.scopedCss ),
							onChange: function ( val ) { setAttributes( { scopedCss: val } ); },
						} ),
						attributes.scopedCss
							? el( ToggleControl, {
								label: __( 'Disable this CSS in the editor canvas' ),
								help: __( 'Editor-only: use when this section’s CSS overlaps other blocks and makes them hard to select. The front end is not affected.' ),
								checked: !! attributes.scopedCssOffInEditor,
								onChange: function ( val ) { setAttributes( { scopedCssOffInEditor: val } ); },
							} )
							: null
					)
				);
			}

			// ── Custom HTML Attributes ───────────────────────────────
			if ( CustomAttrsControl ) {
				panels.push(
					el( CustomAttrsControl, {
						key: 'custom-attrs',
						attributes: attributes,
						setAttributes: setAttributes,
					} )
				);
			}

			// ── Editor render ────────────────────────────────────────
			var tagLabel = tagName !== 'div' || tagName === 'a'
				? el( 'span', {
					className: 'ekwa-div-tag-label',
					style: {
						position: 'absolute',
						top: '-10px',
						left: '8px',
						fontSize: '10px',
						fontFamily: 'monospace',
						color: bgImage ? '#fff' : '#999',
						backgroundColor: bgImage ? 'rgba(0,0,0,0.5)' : '#fff',
						padding: '0 4px',
						lineHeight: '1.4',
						zIndex: 1,
						borderRadius: '2px',
						opacity: isSelected ? 1 : 0,
						transition: 'opacity 0.15s',
						pointerEvents: 'none',
					},
				}, '<' + tagName + '>' + ( tagName === 'a' && ( attributes.url || attributes.href ) ? ' ' + ( attributes.url || attributes.href ) : '' ) )
				: null;

			// Scoped-CSS indicator. Unlike the tag label (hover/selected only)
			// this stays visible, because its job is to let an author spot the
			// blocks carrying their own CSS while scanning the page.
			var cssBadge = hasScopedCss
				? el( 'span', {
					className: 'ekwa-div-css-badge' + ( cssOffInEditor ? ' is-off' : '' ),
					title: cssOffInEditor
						? __( 'Scoped CSS — turned off in the editor canvas only; the front end still gets it. See “Section CSS (advanced)” in the sidebar.' )
						: sprintf(
							/* translators: %s: rule count, e.g. "4 rules". */
							__( 'Scoped CSS — %s. Edit it under “Section CSS (advanced)” in the sidebar.' ),
							sprintf( _n( '%d rule', '%d rules', cssRuleCount ), cssRuleCount )
						),
				}, cssOffInEditor ? __( 'CSS off' ) : __( 'CSS' ) )
				: null;

			return el( Fragment, null,
				el( InspectorControls, null, panels ),
				el( 'div', blockProps,
					hasScopedCss && ! cssOffInEditor
						? el( 'style', null, scopedCss )
						: null,
					tagLabel,
					cssBadge,
					el( InnerBlocks, null )
				)
			);
		},

		save: function () {
			return el( InnerBlocks.Content, null );
		},
	} );

	/**
	 * Deterministic per-tag color so, e.g., every "section" pill and every
	 * "ul" pill reads as the same color at a glance while scanning a long
	 * list — a fixed hand-picked map would need upkeep every time a tag is
	 * added to TAG_OPTIONS; hashing the tag name needs none and never
	 * collides with itself.
	 *
	 * @param {string} tag Tag name, e.g. "section".
	 * @return {string} Hex color.
	 */
	var TAG_PILL_PALETTE = [
		'#3858e9', '#0ca678', '#e8590c', '#ae3ec9',
		'#e64980', '#1971c2', '#f08c00', '#099268',
		'#7048e8', '#c2255c', '#0b7285', '#4c6ef5',
	];
	function colorForTag( tag ) {
		var hash = 0;
		for ( var i = 0; i < tag.length; i++ ) {
			hash = ( hash * 31 + tag.charCodeAt( i ) ) >>> 0;
		}
		return TAG_PILL_PALETTE[ hash % TAG_PILL_PALETTE.length ];
	}

	/**
	 * List View tag-name badge.
	 *
	 * List View has no per-block-type extension point for a second pill —
	 * only the `anchor` attribute gets one, hardcoded in core's row renderer.
	 * The pill itself is core's <Badge> component: a `components-badge`
	 * wrapping a `components-badge__flex-wrapper` > `components-badge__content`,
	 * positioned via `block-editor-list-view-block-select-button__anchor`.
	 * All three layers matter — the anchor class only positions it, the
	 * padding/border-radius/font-size come from `components-badge`, so
	 * skipping any of them is what left the first version unstyled.
	 *
	 * DOM injection into a React-owned tree is inherently fragile — a future
	 * Gutenberg version that renames these classes or restructures the row
	 * will silently stop showing the badge (fails soft, not loud) rather than
	 * throwing. Re-check against wp-includes/js/dist/block-editor.js and
	 * wp-includes/css/dist/components/style.css (.components-badge) if the
	 * badge stops appearing, or its styling drifts, after a core update.
	 */
	function initDivTagBadges() {
		var dataStore = wp.data && wp.data.select( 'core/block-editor' );
		if ( ! dataStore ) {
			return;
		}

		function applyBadge( row, tagName ) {
			var labelWrapper = row.querySelector( '.block-editor-list-view-block-select-button__label-wrapper' );
			if ( ! labelWrapper ) {
				return;
			}

			var pillWrapper = labelWrapper.querySelector( '.ekwa-div-tag-pill-wrapper' );

			if ( ! tagName || tagName === 'div' ) {
				if ( pillWrapper ) {
					pillWrapper.remove();
				}
				return;
			}

			if ( pillWrapper ) {
				var pill    = pillWrapper.querySelector( '.ekwa-div-tag-pill' );
				var content = pillWrapper.querySelector( '.components-badge__content' );
				if ( pill ) {
					pill.style.setProperty( '--ekwa-tag-color', colorForTag( tagName ) );
				}
				if ( content && content.textContent !== tagName ) {
					content.textContent = tagName;
				}
				return;
			}

			pillWrapper = document.createElement( 'span' );
			pillWrapper.className = 'block-editor-list-view-block-select-button__anchor-wrapper ekwa-div-tag-pill-wrapper';

			var badge = document.createElement( 'span' );
			badge.className = 'components-badge block-editor-list-view-block-select-button__anchor ekwa-div-tag-pill';
			badge.style.setProperty( '--ekwa-tag-color', colorForTag( tagName ) );

			var flexWrapper = document.createElement( 'span' );
			flexWrapper.className = 'components-badge__flex-wrapper';

			var badgeContent = document.createElement( 'span' );
			badgeContent.className = 'components-badge__content';
			badgeContent.textContent = tagName;

			flexWrapper.appendChild( badgeContent );
			badge.appendChild( flexWrapper );
			pillWrapper.appendChild( badge );
			labelWrapper.appendChild( pillWrapper );
		}

		function scan() {
			var rows = document.querySelectorAll( '.block-editor-list-view-tree tr[data-block]' );
			for ( var i = 0; i < rows.length; i++ ) {
				var row      = rows[ i ];
				var clientId = row.getAttribute( 'data-block' );
				if ( ! clientId || dataStore.getBlockName( clientId ) !== 'ekwa/div' ) {
					continue;
				}
				var attrs = dataStore.getBlockAttributes( clientId ) || {};
				applyBadge( row, attrs.tagName );
			}
		}

		var scheduled = false;
		function scheduleScan() {
			if ( scheduled ) {
				return;
			}
			scheduled = true;
			( window.requestAnimationFrame || window.setTimeout )( function () {
				scheduled = false;
				scan();
			} );
		}

		wp.data.subscribe( scheduleScan );
		new MutationObserver( scheduleScan ).observe( document.body, { childList: true, subtree: true } );
		scheduleScan();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initDivTagBadges );
	} else {
		initDivTagBadges();
	}
} )( window.wp );
