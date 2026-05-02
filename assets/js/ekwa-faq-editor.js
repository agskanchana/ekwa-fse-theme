/**
 * Ekwa FAQ Block — Block Editor UI.
 *
 * Container that holds ekwa/faq-item children. Outputs FAQPage JSON-LD
 * schema on the frontend. Visual designs are picked via Block Styles in
 * the sidebar (Default, Bordered, Boxed, Filled, Minimal).
 */
( function ( wp ) {
	'use strict';

	var registerBlockType  = wp.blocks.registerBlockType;
	var el                 = wp.element.createElement;
	var Fragment           = wp.element.Fragment;
	var InnerBlocks        = wp.blockEditor.InnerBlocks;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var PanelColorSettings = wp.blockEditor.PanelColorSettings;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var PanelBody          = wp.components.PanelBody;
	var ToggleControl      = wp.components.ToggleControl;
	var __                 = wp.i18n.__;

	var ALLOWED   = [ 'ekwa/faq-item' ];
	var TEMPLATE  = [
		[ 'ekwa/faq-item', { question: 'Frequently asked question?' } ],
		[ 'ekwa/faq-item', { question: 'Another question?' } ],
	];

	function Edit( props ) {
		var attrs    = props.attributes;
		var setAttrs = props.setAttributes;

		var accent      = attrs.accentColor   || '';
		var qColor      = attrs.questionColor || '';
		var aColor      = attrs.answerColor   || '';
		var itemBg      = attrs.itemBg        || '';

		var styleVars = {};
		if ( accent ) { styleVars[ '--ekwa-faq-accent' ]   = accent; }
		if ( qColor ) { styleVars[ '--ekwa-faq-q-color' ]  = qColor; }
		if ( aColor ) { styleVars[ '--ekwa-faq-a-color' ]  = aColor; }
		if ( itemBg ) { styleVars[ '--ekwa-faq-item-bg' ]  = itemBg; }

		var blockProps = useBlockProps( {
			className: 'ekwa-faq',
			style:     styleVars,
		} );

		return el( Fragment, null,

			el( InspectorControls, null,

				el( PanelBody, { title: __( 'Behaviour', 'ekwa' ), initialOpen: true },
					el( ToggleControl, {
						label:    __( 'Accordion mode (only one open)', 'ekwa' ),
						checked:  !! attrs.accordion,
						onChange: function ( v ) { setAttrs( { accordion: v } ); },
						__nextHasNoMarginBottom: true,
					} ),
					el( ToggleControl, {
						label:    __( 'Open first item by default', 'ekwa' ),
						checked:  !! attrs.firstOpen,
						onChange: function ( v ) { setAttrs( { firstOpen: v } ); },
						__nextHasNoMarginBottom: true,
					} ),
					el( ToggleControl, {
						label:    __( 'Output FAQPage schema (JSON-LD)', 'ekwa' ),
						help:     __( 'Adds Google-recognised FAQ schema markup.', 'ekwa' ),
						checked:  !! attrs.emitSchema,
						onChange: function ( v ) { setAttrs( { emitSchema: v } ); },
						__nextHasNoMarginBottom: true,
					} )
				),

				el( PanelColorSettings, {
					title:        __( 'FAQ Colors', 'ekwa' ),
					initialOpen:  false,
					colorSettings: [
						{
							value:    accent,
							onChange: function ( v ) { setAttrs( { accentColor: v || '' } ); },
							label:    __( 'Accent (icon / active)', 'ekwa' ),
						},
						{
							value:    qColor,
							onChange: function ( v ) { setAttrs( { questionColor: v || '' } ); },
							label:    __( 'Question Text', 'ekwa' ),
						},
						{
							value:    aColor,
							onChange: function ( v ) { setAttrs( { answerColor: v || '' } ); },
							label:    __( 'Answer Text', 'ekwa' ),
						},
						{
							value:    itemBg,
							onChange: function ( v ) { setAttrs( { itemBg: v || '' } ); },
							label:    __( 'Item Background', 'ekwa' ),
						},
					],
				} )
			),

			el( 'div', blockProps,
				el( InnerBlocks, {
					allowedBlocks: ALLOWED,
					template:      TEMPLATE,
					templateLock:  false,
					orientation:   'vertical',
				} )
			)
		);
	}

	registerBlockType( 'ekwa/faq', {
		edit: Edit,
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );

} )( window.wp );
