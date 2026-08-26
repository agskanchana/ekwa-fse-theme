/**
 * Ekwa Banner Title — Block Editor UI.
 *
 * Server-rendered preview, because the value comes from the Main Menu and the
 * current post — neither of which the editor can resolve client-side. Inside a
 * template (where there is no post) the preview shows the "menu name differs"
 * shape, since that is the case the layout has to accommodate.
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
	var ServerSideRender  = wp.serverSideRender;
	var __                = wp.i18n.__;
	var CustomAttrsControl = window.EkwaCustomAttributes && window.EkwaCustomAttributes.Control;

	registerBlockType( 'ekwa/banner-title', {
		edit: function ( props ) {
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;
			var source        = attributes.source || 'auto';
			var blockProps    = useBlockProps();

			var sourceHelp;
			if ( 'auto' === source ) {
				sourceHelp = __( 'Shows the Main Menu label for this page. When the label and the page title match — or the page is not in the menu — it shows the page title as the <h1> instead, and Ekwa Page Title renders nothing. When they differ it uses a <p>, leaving the <h1> to Ekwa Page Title below the banner. This is how the legacy Inner Page Banner behaved.' );
			} else if ( 'menu' === source ) {
				sourceHelp = __( 'Always the menu label. Falls back to the page title when this page is not in the menu.' );
			} else if ( 'breadcrumb-title' === source ) {
				sourceHelp = __( 'Yoast\'s breadcrumb title for this page, falling back to the menu label, then the page title.' );
			} else {
				sourceHelp = __( 'Always the full page title.' );
			}

			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Title' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Text Source' ),
							value: source,
							options: [
								{ label: __( 'Auto — menu name, or page title when they match' ), value: 'auto' },
								{ label: __( 'Menu name' ),             value: 'menu' },
								{ label: __( 'Page title' ),            value: 'page' },
								{ label: __( 'Yoast breadcrumb title' ), value: 'breadcrumb-title' },
							],
							help: sourceHelp,
							onChange: function ( val ) { setAttributes( { source: val } ); },
						} ),
						el( SelectControl, {
							label: __( 'HTML Tag' ),
							value: attributes.tagName || 'auto',
							options: [
								{ label: __( 'Auto (h1 or p)' ), value: 'auto' },
								{ label: 'h1',   value: 'h1' },
								{ label: 'h2',   value: 'h2' },
								{ label: 'h3',   value: 'h3' },
								{ label: 'h4',   value: 'h4' },
								{ label: 'h5',   value: 'h5' },
								{ label: 'h6',   value: 'h6' },
								{ label: 'p',    value: 'p' },
								{ label: 'span', value: 'span' },
								{ label: 'div',  value: 'div' },
							],
							help: __( 'Auto keeps one — and only one — <h1> on the page: this block takes it when the page title is not shown separately, and steps down to <p> when Ekwa Page Title has it.' ),
							onChange: function ( val ) { setAttributes( { tagName: val } ); },
						} ),
						'menu' === source
							? el( TextControl, {
								label: __( 'Menu Location' ),
								value: attributes.menuLocation || 'main_menu',
								help: __( 'Theme menu location slug. Default: main_menu.' ),
								onChange: function ( val ) { setAttributes( { menuLocation: val } ); },
							} )
							: null,
						el( TextControl, {
							label: __( 'Fallback Text' ),
							value: attributes.fallback || '',
							help: __( 'Used when the chosen source is empty. Defaults to the page title.' ),
							onChange: function ( val ) { setAttributes( { fallback: val } ); },
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
						block: 'ekwa/banner-title',
						attributes: attributes,
					} )
				)
			);
		},

		save: function () { return null; },
	} );
} )( window.wp );
