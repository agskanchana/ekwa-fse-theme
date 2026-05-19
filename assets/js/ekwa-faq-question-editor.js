/**
 * Ekwa FAQ Question Block — Block Editor UI.
 *
 * Child of ekwa/faq-container. RichText heading with selectable level
 * (h2–h6) and text alignment. Frontend renders the chosen <hN>.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType  = wp.blocks.registerBlockType;
	var el                 = wp.element.createElement;
	var Fragment           = wp.element.Fragment;
	var BlockControls      = wp.blockEditor.BlockControls;
	var AlignmentToolbar   = wp.blockEditor.AlignmentToolbar;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var RichText           = wp.blockEditor.RichText;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var PanelBody          = wp.components.PanelBody;
	var SelectControl      = wp.components.SelectControl;
	var __                 = wp.i18n.__;

	var LEVEL_OPTIONS = [
		{ label: 'H2', value: 2 },
		{ label: 'H3', value: 3 },
		{ label: 'H4', value: 4 },
		{ label: 'H5', value: 5 },
		{ label: 'H6', value: 6 },
	];

	function Edit( props ) {
		var attrs    = props.attributes;
		var setAttrs = props.setAttributes;

		var level     = attrs.level && attrs.level >= 2 && attrs.level <= 6 ? attrs.level : 2;
		var tagName   = 'h' + level;
		var textAlign = attrs.textAlign || '';

		var style = textAlign ? { textAlign: textAlign } : {};

		var blockProps = useBlockProps( {
			className: 'ekwa-faq-container__q',
			style:     style,
		} );

		return el( Fragment, null,

			el( BlockControls, null,
				el( AlignmentToolbar, {
					value:    textAlign,
					onChange: function ( v ) { setAttrs( { textAlign: v || '' } ); },
				} )
			),

			el( InspectorControls, null,
				el( PanelBody, { title: __( 'Question Settings', 'ekwa' ), initialOpen: true },
					el( SelectControl, {
						label:    __( 'Heading level', 'ekwa' ),
						value:    String( level ),
						options:  LEVEL_OPTIONS.map( function ( o ) {
							return { label: o.label, value: String( o.value ) };
						} ),
						onChange: function ( v ) { setAttrs( { level: parseInt( v, 10 ) || 2 } ); },
						__nextHasNoMarginBottom: true,
					} )
				)
			),

			el( RichText, Object.assign( {}, blockProps, {
				tagName:        tagName,
				value:          attrs.content || '',
				onChange:       function ( v ) { setAttrs( { content: v } ); },
				placeholder:    __( 'Type the question…', 'ekwa' ),
				allowedFormats: [ 'core/bold', 'core/italic' ],
			} ) )
		);
	}

	registerBlockType( 'ekwa/faq-question', {
		edit: Edit,
		save: function () {
			// Server-rendered.
			return null;
		},
	} );

} )( window.wp );
