/**
 * Ekwa Page Banner — Block Editor UI.
 *
 * Uses real InnerBlocks rather than ServerSideRender, so the title, breadcrumb
 * and anything else inside stay directly editable on the canvas. The featured
 * image can't be resolved in a template context, so the editor previews the
 * background only when the block points at a specific image; otherwise it shows
 * a labelled placeholder tint.
 *
 * Scoped CSS behaves exactly as it does on ekwa/div — same CodeMirror editor,
 * same "disable in the editor canvas" escape hatch, same decode of the entities
 * kses writes into the attribute on save.
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
	// The children have to be DIRECT children of .ekwa-page-banner__content, or
	// the flex gap and alignment the block styles depend on would land on
	// InnerBlocks' own wrapper divs and the editor would stop matching the front
	// end. useInnerBlocksProps is how you get that; plain <InnerBlocks/> is not.
	var useInnerBlocksProps = wp.blockEditor.useInnerBlocksProps;
	var MediaUpload        = wp.blockEditor.MediaUpload;
	var MediaUploadCheck   = wp.blockEditor.MediaUploadCheck;
	var PanelBody          = wp.components.PanelBody;
	var SelectControl      = wp.components.SelectControl;
	var TextControl        = wp.components.TextControl;
	var TextareaControl    = wp.components.TextareaControl;
	var ToggleControl      = wp.components.ToggleControl;
	var RangeControl       = wp.components.RangeControl;
	var ColorPalette       = wp.components.ColorPalette;
	var BaseControl        = wp.components.BaseControl;
	var Button             = wp.components.Button;
	var __                 = wp.i18n.__;
	var _n                 = wp.i18n._n;
	var sprintf            = wp.i18n.sprintf;
	var CustomAttrsControl = window.EkwaCustomAttributes && window.EkwaCustomAttributes.Control;

	var TAG_OPTIONS = [
		{ label: 'section', value: 'section' },
		{ label: 'div',     value: 'div' },
		{ label: 'header',  value: 'header' },
		{ label: 'aside',   value: 'aside' },
		{ label: 'article', value: 'article' },
		{ label: 'main',    value: 'main' },
	];

	var TEMPLATE = [
		[ 'ekwa/banner-title', {} ],
		[ 'ekwa/breadcrumb', {} ],
	];

	/** Mirrors ekwa_css_decode_entities() — see the same helper in ekwa-div-editor.js. */
	function decodeCss( css ) {
		if ( ! css || css.indexOf( '&' ) === -1 ) { return css || ''; }
		return css
			.replace( /&gt;/g, '>' )
			.replace( /&lt;/g, '<' )
			.replace( /&quot;/g, '"' )
			.replace( /&#0?39;/g, "'" )
			.replace( /&amp;/g, '&' ); // last, or "&amp;gt;" would collapse to ">"
	}

	/** "color: red; gap: 4px" → { color: 'red', gap: '4px' } for the canvas preview. */
	function parseStyleString( str ) {
		if ( ! str ) { return {}; }
		var style = {};
		decodeCss( str ).split( ';' ).forEach( function ( part ) {
			part = part.trim();
			if ( ! part ) { return; }
			var colon = part.indexOf( ':' );
			if ( colon < 1 ) { return; }
			var key = part.substring( 0, colon ).trim().replace( /-([a-z])/g, function ( m, c ) {
				return c.toUpperCase();
			} );
			style[ key ] = part.substring( colon + 1 ).trim();
		} );
		return style;
	}

	/**
	 * Scoped-CSS field on WP's bundled CodeMirror, falling back to a plain
	 * textarea when the user turned syntax highlighting off in their profile.
	 * The settings object is shared with ekwa/div — one wp_enqueue_code_editor()
	 * call serves both blocks.
	 */
	function ScopedCssEditor( props ) {
		var value       = props.value || '';
		var onChange    = props.onChange;
		var wrapRef     = useRef( null );
		var cmRef       = useRef( null );
		var onChangeRef = useRef( onChange );
		onChangeRef.current = onChange;

		var canCodeMirror = !! ( wp.codeEditor && window.ekwaDivCodeEditor && window.ekwaDivCodeEditor.settings );

		useEffect( function () {
			if ( ! canCodeMirror || ! wrapRef.current ) { return undefined; }
			var textarea = wrapRef.current.querySelector( 'textarea' );
			if ( ! textarea ) { return undefined; }
			var instance = wp.codeEditor.initialize( textarea, window.ekwaDivCodeEditor.settings );
			cmRef.current = instance.codemirror;
			cmRef.current.on( 'change', function ( cm ) {
				onChangeRef.current( cm.getValue() );
			} );
			return function () {
				if ( cmRef.current && cmRef.current.toTextArea ) { cmRef.current.toTextArea(); }
				cmRef.current = null;
			};
		}, [] );

		useEffect( function () {
			if ( cmRef.current && cmRef.current.getValue() !== value ) {
				cmRef.current.setValue( value );
			}
		}, [ value ] );

		if ( ! canCodeMirror ) {
			return el( TextareaControl, {
				label: __( 'Scoped CSS' ),
				help: __( 'Inlined on the front end only where this banner renders, and only once per page.' ),
				value: value,
				rows: 8,
				onChange: onChange,
			} );
		}

		return el( 'div', { className: 'ekwa-div-css-editor', ref: wrapRef },
			el( 'label', { className: 'ekwa-div-css-editor__label' }, __( 'Scoped CSS' ) ),
			el( 'textarea', { defaultValue: value, rows: 8 } ),
			el( 'p', { className: 'ekwa-div-css-editor__help' },
				__( 'Inlined on the front end only where this banner renders, and only once per page.' )
			)
		);
	}

	registerBlockType( 'ekwa/page-banner', {
		edit: function ( props ) {
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;
			var isSelected    = props.isSelected;

			var bgSource  = attributes.bgSource || 'featured';
			var bgRender  = attributes.bgRender || 'picture';
			var bgMobile  = attributes.bgMobile || 'crop';
			var customBg  = attributes.bgImage || '';

			var scopedCss      = decodeCss( attributes.scopedCss || '' );
			var hasScopedCss   = '' !== scopedCss.trim();
			var cssOffInEditor = !! attributes.scopedCssOffInEditor;
			var cssRuleCount   = hasScopedCss ? ( scopedCss.match( /\{/g ) || [] ).length : 0;

			// Canvas preview. Only a custom image can be previewed — a featured
			// image belongs to whatever page the template ends up on, which the
			// editor has no way to know.
			var editorStyle = parseStyleString( attributes.inlineStyle );
			if ( attributes.minHeight > 0 ) {
				editorStyle.minHeight = attributes.minHeight + 'px';
			}
			if ( 'custom' === bgSource && customBg ) {
				editorStyle.backgroundImage    = "url('" + customBg + "')";
				editorStyle.backgroundSize     = attributes.bgSize || 'cover';
				editorStyle.backgroundPosition = attributes.bgPosition || '50% 50%';
				editorStyle.backgroundRepeat   = 'no-repeat';
			}

			var blockProps = useBlockProps( {
				className: 'ekwa-page-banner',
				style: editorStyle,
			} );

			// Mirrors the render callback, so the canvas and the front end put
			// the same classes on the content well and the style variations
			// preview accurately in the editor.
			var contentClass = 'ekwa-page-banner__content'
				+ ( 'full' === attributes.contentWidth ? ' ekwa-page-banner__content--full' : '' );
			var contentStyle = {};
			if ( attributes.contentWidth && 'full' !== attributes.contentWidth ) {
				contentStyle.maxWidth = attributes.contentWidth;
			}

			// Hooks must run unconditionally, so this is resolved here rather
			// than at the point of use.
			var innerProps = useInnerBlocksProps
				? useInnerBlocksProps(
					{ className: contentClass, style: contentStyle },
					{ template: TEMPLATE }
				)
				: null;

			var panels = [];

			// ── Banner ───────────────────────────────────────────────
			panels.push(
				el( PanelBody, { key: 'banner', title: __( 'Banner' ), initialOpen: true },
					el( SelectControl, {
						label: __( 'HTML Tag' ),
						value: attributes.tagName || 'section',
						options: TAG_OPTIONS,
						onChange: function ( val ) { setAttributes( { tagName: val } ); },
					} ),
					el( RangeControl, {
						label: __( 'Min Height (px)' ),
						value: attributes.minHeight || 0,
						min: 0,
						max: 900,
						step: 10,
						allowReset: true,
						help: __( '0 leaves the height to the content and your padding.' ),
						onChange: function ( val ) { setAttributes( { minHeight: val || 0 } ); },
					} ),
					el( TextControl, {
						label: __( 'Content Width' ),
						value: attributes.contentWidth || '',
						placeholder: __( 'theme content width' ),
						help: __( 'A CSS length (e.g. 900px, 70rem), or "full" to remove the max-width entirely.' ),
						onChange: function ( val ) { setAttributes( { contentWidth: val } ); },
					} ),
					el( TextControl, {
						label: __( 'Aria Label' ),
						value: attributes.ariaLabel || '',
						help: __( 'Optional screen-reader label for the banner region. Leave blank for none.' ),
						onChange: function ( val ) { setAttributes( { ariaLabel: val } ); },
					} ),
					el( TextareaControl, {
						label: __( 'Inline Style' ),
						help: __( 'Additional raw CSS properties on the banner element.' ),
						value: attributes.inlineStyle || '',
						rows: 2,
						onChange: function ( val ) { setAttributes( { inlineStyle: val } ); },
					} )
				)
			);

			// ── Background ───────────────────────────────────────────
			var bgChildren = [];

			bgChildren.push(
				el( SelectControl, {
					key: 'bg-source',
					label: __( 'Background Image' ),
					value: bgSource,
					options: [
						{ label: __( "This page's featured image" ), value: 'featured' },
						{ label: __( 'A specific image' ),           value: 'custom' },
						{ label: __( 'None' ),                       value: 'none' },
					],
					onChange: function ( val ) { setAttributes( { bgSource: val } ); },
				} )
			);

			if ( 'custom' === bgSource ) {
				if ( customBg ) {
					bgChildren.push(
						el( 'div', {
							key: 'bg-preview',
							style: { marginBottom: '8px', borderRadius: '4px', overflow: 'hidden' },
						}, el( 'img', { src: customBg, alt: '', style: { width: '100%', height: 'auto', display: 'block' } } ) )
					);
				}
				bgChildren.push(
					el( MediaUploadCheck, { key: 'bg-upload' },
						el( MediaUpload, {
							onSelect: function ( media ) {
								setAttributes( { bgImage: media.url, bgImageId: media.id } );
							},
							allowedTypes: [ 'image' ],
							value: attributes.bgImageId,
							render: function ( obj ) {
								return el( Button, { onClick: obj.open, isSecondary: true, style: { marginRight: '8px' } },
									customBg ? __( 'Replace Image' ) : __( 'Select Image' ) );
							},
						} )
					)
				);
				if ( customBg ) {
					bgChildren.push(
						el( Button, {
							key: 'bg-clear', isDestructive: true, isSmall: true,
							onClick: function () { setAttributes( { bgImage: '', bgImageId: 0 } ); },
						}, __( 'Remove' ) )
					);
				}
			}

			if ( 'none' !== bgSource ) {
				bgChildren.push(
					el( SelectControl, {
						key: 'bg-render',
						label: __( 'Render background as' ),
						value: bgRender,
						options: [
							{ label: __( 'Image layer (recommended)' ), value: 'picture' },
							{ label: __( 'CSS background' ),            value: 'css' },
						],
						help: 'picture' === bgRender
							? __( 'A real <img> behind the content: art-directed per breakpoint, served as WebP, and given fetchpriority="high" so it is fetched as the page\'s LCP image.' )
							: __( 'A background-image declaration. Unlocks background-position and background-attachment, but the browser cannot prioritise it the way it can an <img> — slower for an above-the-fold banner.' ),
						onChange: function ( val ) { setAttributes( { bgRender: val } ); },
					} )
				);

				bgChildren.push(
					el( SelectControl, {
						key: 'bg-mobile',
						label: __( 'On phones (under 480px)' ),
						value: bgMobile,
						options: [
							{ label: __( 'Use the 480px crop' ),  value: 'crop' },
							{ label: __( 'Use the full image' ),  value: 'full' },
							{ label: __( 'No background image' ), value: 'hide' },
						],
						help: 'hide' === bgMobile
							? __( 'Phones download nothing for the background and fall back to the banner\'s background color.' )
							: __( 'The 480px crop needs one thumbnail regeneration on images uploaded before this theme version; until then phones fall through to the next size up.' ),
						onChange: function ( val ) { setAttributes( { bgMobile: val } ); },
					} )
				);
			}

			if ( 'none' !== bgSource && 'css' === bgRender ) {
				bgChildren.push(
					el( TextControl, {
						key: 'bg-size',
						label: __( 'Background Size' ),
						value: attributes.bgSize || 'cover',
						onChange: function ( val ) { setAttributes( { bgSize: val } ); },
					} )
				);
				bgChildren.push(
					el( TextControl, {
						key: 'bg-position',
						label: __( 'Background Position' ),
						value: attributes.bgPosition || '50% 50%',
						onChange: function ( val ) { setAttributes( { bgPosition: val } ); },
					} )
				);
				bgChildren.push(
					el( SelectControl, {
						key: 'bg-attachment',
						label: __( 'Background Attachment' ),
						value: attributes.bgAttachment || 'scroll',
						options: [
							{ label: 'scroll', value: 'scroll' },
							{ label: 'fixed',  value: 'fixed' },
							{ label: 'local',  value: 'local' },
						],
						onChange: function ( val ) { setAttributes( { bgAttachment: val } ); },
					} )
				);
			}

			panels.push(
				el( PanelBody, { key: 'background', title: __( 'Background' ), initialOpen: false }, bgChildren )
			);

			// ── Overlay ──────────────────────────────────────────────
			panels.push(
				el( PanelBody, { key: 'overlay', title: __( 'Overlay' ), initialOpen: false },
					el( RangeControl, {
						label: __( 'Overlay Opacity (%)' ),
						value: undefined === attributes.overlayOpacity ? 45 : attributes.overlayOpacity,
						min: 0,
						max: 100,
						help: 'none' === bgSource
							? __( 'The overlay only renders over a background image. With no image, set the banner colour under Styles → Color instead.' )
							: __( 'Tints the photo so the title stays readable. 0 renders no overlay element at all.' ),
						onChange: function ( val ) { setAttributes( { overlayOpacity: val || 0 } ); },
					} ),
					attributes.overlayOpacity > 0
						? el( BaseControl, { label: __( 'Overlay Color' ) },
							el( ColorPalette, {
								value: attributes.overlayColor || '',
								onChange: function ( val ) { setAttributes( { overlayColor: val || '' } ); },
							} )
						)
						: null
				)
			);

			// ── Section CSS ──────────────────────────────────────────
			if ( hasScopedCss || isSelected ) {
				var isCustomStyle = -1 !== ( attributes.className || '' ).indexOf( 'is-style-custom' );

				panels.push(
					el( PanelBody, {
						key: 'scoped-css',
						title: hasScopedCss
							? __( 'Section CSS (advanced)' ) + ' — ' +
								sprintf( _n( '%d rule', '%d rules', cssRuleCount ), cssRuleCount )
							: __( 'Section CSS (advanced)' ),
						initialOpen: false,
					},
						el( 'p', {
							style: { fontSize: '12px', color: '#757575', marginTop: 0 },
						}, isCustomStyle
							? __( 'This banner is set to "Custom (No Styles)" — the theme applies no padding, alignment or type sizing, so everything below is yours to define. The background and overlay layers stay positioned.' )
							: __( 'Pick "Custom (No Styles)" under Styles to strip the theme\'s defaults and start from bare markup.' )
						),
						el( ScopedCssEditor, {
							value: scopedCss,
							onChange: function ( val ) { setAttributes( { scopedCss: val } ); },
						} ),
						hasScopedCss
							? el( ToggleControl, {
								label: __( 'Disable this CSS in the editor canvas' ),
								help: __( 'Editor-only: use when this banner\'s CSS overlaps other blocks and makes them hard to select. The front end is not affected.' ),
								checked: cssOffInEditor,
								onChange: function ( val ) { setAttributes( { scopedCssOffInEditor: val } ); },
							} )
							: null
					)
				);
			}

			if ( CustomAttrsControl ) {
				panels.push( el( CustomAttrsControl, {
					key: 'custom-attrs',
					attributes: attributes,
					setAttributes: setAttributes,
				} ) );
			}

			// Tell the author what the canvas can't show them: on a template,
			// "featured image" resolves per page at render time.
			var bgNote = ( 'featured' === bgSource )
				? el( 'span', {
					className: 'ekwa-page-banner__editor-note',
					style: {
						position: 'absolute', top: '8px', left: '8px', zIndex: 5,
						fontSize: '11px', fontFamily: 'monospace', color: '#555',
						background: 'rgba(255,255,255,.85)', padding: '2px 6px',
						borderRadius: '2px', pointerEvents: 'none',
						opacity: isSelected ? 1 : 0.55, transition: 'opacity .15s',
					},
				}, __( 'background: featured image of the current page' ) )
				: null;

			// Overlay preview, but only when the canvas is actually showing the
			// photo it would tint — i.e. a specific image. On "featured image"
			// the canvas falls back to the brand colour, and darkening that
			// would show a tint the front end never renders.
			var overlayPreview = ( 'custom' === bgSource && customBg && attributes.overlayOpacity > 0 )
				? el( 'div', {
					className: 'ekwa-page-banner__overlay',
					style: {
						opacity: attributes.overlayOpacity / 100,
						background: attributes.overlayColor || undefined,
					},
				} )
				: null;

			return el( Fragment, null,
				el( InspectorControls, null, panels ),
				el( 'div', blockProps,
					hasScopedCss && ! cssOffInEditor ? el( 'style', null, scopedCss ) : null,
					bgNote,
					overlayPreview,
					innerProps
						? el( 'div', innerProps )
						: el( 'div', { className: contentClass, style: contentStyle },
							el( InnerBlocks, { template: TEMPLATE } )
						)
				)
			);
		},

		save: function () {
			return el( InnerBlocks.Content, null );
		},
	} );
} )( window.wp );
