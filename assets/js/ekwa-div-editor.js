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
		{ label: 'a',           value: 'a' },
		{ label: '— inline —',  value: '',         disabled: true },
		{ label: 'span',        value: 'span' },
		{ label: 'small',       value: 'small' },
		{ label: 'strong',      value: 'strong' },
		{ label: 'em',          value: 'em' },
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

	registerBlockType( 'ekwa/div', {
		edit: function ( props ) {
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;
			var tagName       = attributes.tagName || 'div';
			var bgImage       = attributes.backgroundImage || '';
			var isSelected    = props.isSelected;

			// Build editor wrapper style from inlineStyle + backgroundImage.
			var editorStyle = parseStyleString( attributes.inlineStyle || '' );
			if ( bgImage ) {
				editorStyle.backgroundImage = "url('" + bgImage + "')";
				if ( ! editorStyle.backgroundSize )     { editorStyle.backgroundSize = 'cover'; }
				if ( ! editorStyle.backgroundPosition ) { editorStyle.backgroundPosition = 'center'; }
			}

			// The editor renders <div> for all tags (WP requirement for useBlockProps),
			// but passes through all the user's CSS classes via className support.
			var blockProps = useBlockProps( { style: editorStyle } );

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
						title: __( 'Section CSS (advanced)' ),
						initialOpen: false,
					},
						el( ScopedCssEditor, {
							key: 'scoped-css-editor',
							value: attributes.scopedCss || '',
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

			return el( Fragment, null,
				el( InspectorControls, null, panels ),
				el( 'div', blockProps,
					attributes.scopedCss && ! attributes.scopedCssOffInEditor
						? el( 'style', null, attributes.scopedCss )
						: null,
					tagLabel,
					el( InnerBlocks, null )
				)
			);
		},

		save: function () {
			return el( InnerBlocks.Content, null );
		},
	} );
} )( window.wp );
