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
	var InnerBlocks        = wp.blockEditor.InnerBlocks;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var MediaUpload        = wp.blockEditor.MediaUpload;
	var MediaUploadCheck   = wp.blockEditor.MediaUploadCheck;
	var PanelBody          = wp.components.PanelBody;
	var SelectControl      = wp.components.SelectControl;
	var TextControl        = wp.components.TextControl;
	var TextareaControl    = wp.components.TextareaControl;
	var Button             = wp.components.Button;
	var __                 = wp.i18n.__;

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
				settingsChildren.push(
					el( TextControl, {
						key: 'href',
						label: __( 'URL (href)' ),
						value: attributes.href || '',
						onChange: function ( val ) { setAttributes( { href: val } ); },
					} )
				);
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

			panels.push(
				el( PanelBody, { key: 'background', title: __( 'Background Image' ), initialOpen: !! bgImage },
					bgChildren
				)
			);

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
				}, '<' + tagName + '>' + ( tagName === 'a' && attributes.href ? ' ' + attributes.href : '' ) )
				: null;

			return el( Fragment, null,
				el( InspectorControls, null, panels ),
				el( 'div', blockProps,
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
