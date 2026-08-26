/**
 * Ekwa Breadcrumb — Block Editor UI.
 *
 * Server-rendered preview: the trail depends on the current post's ancestors and
 * on whichever SEO plugin is active, neither of which the editor can resolve.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el                = wp.element.createElement;
	var Fragment          = wp.element.Fragment;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var PanelBody         = wp.components.PanelBody;
	var SelectControl     = wp.components.SelectControl;
	var TextControl       = wp.components.TextControl;
	var TextareaControl   = wp.components.TextareaControl;
	var ToggleControl     = wp.components.ToggleControl;
	var ServerSideRender  = wp.serverSideRender;
	var __                = wp.i18n.__;
	var CustomAttrsControl = window.EkwaCustomAttributes && window.EkwaCustomAttributes.Control;

	var SEPARATOR_PRESETS = [ '»', '›', '/', '|', '—', '•', '>' ];

	registerBlockType( 'ekwa/breadcrumb', {
		edit: function ( props ) {
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;
			var sepType       = attributes.separatorType || 'text';
			var provider      = attributes.provider || 'auto';
			var blockProps    = useBlockProps();

			// Everything except the separator and the aria-label is ignored when
			// an SEO plugin owns the markup, so say so rather than leaving dead
			// controls in the panel.
			var pluginOwned = ( 'yoast' === provider || 'rankmath' === provider || 'auto' === provider )
				&& ! attributes.customTemplate;

			var sepChildren = [
				el( SelectControl, {
					key: 'sep-type',
					label: __( 'Separator' ),
					value: sepType,
					options: [
						{ label: __( 'Text' ),               value: 'text' },
						{ label: __( 'Font Awesome icon' ),  value: 'icon' },
					],
					onChange: function ( val ) { setAttributes( { separatorType: val } ); },
				} ),
			];

			if ( 'icon' === sepType ) {
				sepChildren.push( el( TextControl, {
					key: 'sep-icon',
					label: __( 'Icon Class' ),
					value: attributes.separatorIcon || '',
					placeholder: 'fa-solid fa-angle-right',
					onChange: function ( val ) { setAttributes( { separatorIcon: val } ); },
				} ) );
			} else {
				sepChildren.push( el( TextControl, {
					key: 'sep-text',
					label: __( 'Separator Character' ),
					value: attributes.separator || '',
					placeholder: '»',
					onChange: function ( val ) { setAttributes( { separator: val } ); },
				} ) );
				sepChildren.push( el( 'div', {
					key: 'sep-presets',
					style: { display: 'flex', gap: '4px', flexWrap: 'wrap', marginTop: '-8px', marginBottom: '16px' },
				}, SEPARATOR_PRESETS.map( function ( char ) {
					return el( 'button', {
						key: char,
						type: 'button',
						className: 'components-button is-small is-secondary',
						onClick: function () { setAttributes( { separator: char } ); },
						style: { minWidth: '28px', justifyContent: 'center' },
					}, char );
				} ) ) );
			}

			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Breadcrumb' ), initialOpen: true },
						sepChildren,
						el( SelectControl, {
							label: __( 'Source' ),
							value: provider,
							options: [
								{ label: __( 'Auto — SEO plugin if present' ), value: 'auto' },
								{ label: __( 'Yoast SEO' ),                    value: 'yoast' },
								{ label: __( 'Rank Math' ),                    value: 'rankmath' },
								{ label: __( 'Build it in the theme' ),        value: 'builtin' },
							],
							help: pluginOwned
								? __( 'The SEO plugin supplies the markup and the trail; the separator above is pushed into it. The options below apply to the theme-built trail only.' )
								: __( 'The theme builds the trail from the page\'s ancestors.' ),
							onChange: function ( val ) { setAttributes( { provider: val } ); },
						} ),
						el( SelectControl, {
							label: __( 'Current Page Label' ),
							value: attributes.currentSource || 'auto',
							options: [
								{ label: __( 'Auto — breadcrumb title, then menu name, then page title' ), value: 'auto' },
								{ label: __( 'Yoast breadcrumb title' ), value: 'breadcrumb-title' },
								{ label: __( 'Menu name' ),              value: 'menu' },
								{ label: __( 'Page title' ),             value: 'page' },
							],
							onChange: function ( val ) { setAttributes( { currentSource: val } ); },
						} ),
						el( ToggleControl, {
							label: __( 'Show "Home" crumb' ),
							checked: false !== attributes.showHome,
							onChange: function ( val ) { setAttributes( { showHome: val } ); },
						} ),
						false !== attributes.showHome
							? el( TextControl, {
								label: __( 'Home Label' ),
								value: attributes.homeLabel || '',
								placeholder: __( 'Home' ),
								onChange: function ( val ) { setAttributes( { homeLabel: val } ); },
							} )
							: null,
						el( ToggleControl, {
							label: __( 'Link the current page' ),
							help: __( 'Off by default — a link to the page you are already on is noise for keyboard and screen-reader users.' ),
							checked: !! attributes.linkCurrent,
							onChange: function ( val ) { setAttributes( { linkCurrent: val } ); },
						} ),
						el( ToggleControl, {
							label: __( 'Emit BreadcrumbList schema' ),
							help: __( 'Off by default: Yoast already outputs a BreadcrumbList on most sites, and two competing graphs is worse than one.' ),
							checked: !! attributes.emitSchema,
							onChange: function ( val ) { setAttributes( { emitSchema: val } ); },
						} ),
						el( TextControl, {
							label: __( 'Aria Label' ),
							value: attributes.ariaLabel || '',
							placeholder: __( 'Breadcrumb' ),
							onChange: function ( val ) { setAttributes( { ariaLabel: val } ); },
						} ),
						el( TextareaControl, {
							label: __( 'Inline Style' ),
							value: attributes.inlineStyle || '',
							rows: 2,
							onChange: function ( val ) { setAttributes( { inlineStyle: val } ); },
						} )
					),
					CustomAttrsControl
						? el( CustomAttrsControl, { attributes: attributes, setAttributes: setAttributes } )
						: null
				),
				el( 'div', blockProps,
					el( ServerSideRender, {
						block: 'ekwa/breadcrumb',
						attributes: attributes,
					} )
				)
			);
		},

		save: function () { return null; },
	} );
} )( window.wp );
