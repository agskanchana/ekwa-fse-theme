/**
 * Ekwa Text Block — Block Editor UI.
 *
 * An inline text element — outputs <span>, <small>, <strong>, <em>, etc.
 * For badges, labels, stats, icon companions, and other inline text.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType  = wp.blocks.registerBlockType;
	var el                 = wp.element.createElement;
	var Fragment           = wp.element.Fragment;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var PanelBody          = wp.components.PanelBody;
	var TextControl        = wp.components.TextControl;
	var SelectControl      = wp.components.SelectControl;
	var __                 = wp.i18n.__;

	var TAG_OPTIONS = [
		{ label: 'span',   value: 'span' },
		{ label: 'small',  value: 'small' },
		{ label: 'strong', value: 'strong' },
		{ label: 'em',     value: 'em' },
		{ label: 'mark',   value: 'mark' },
		{ label: 'time',   value: 'time' },
		{ label: 'label',  value: 'label' },
		{ label: 'sup',    value: 'sup' },
		{ label: 'sub',    value: 'sub' },
	];

	function Edit( props ) {
		var attributes    = props.attributes;
		var setAttributes = props.setAttributes;

		var tagName = attributes.tagName || 'span';
		var text    = attributes.text    || '';

		var blockProps = useBlockProps( {
			className: 'ekwa-text',
		} );

		return el( Fragment, null,

			el( InspectorControls, null,
				el( PanelBody, { title: __( 'Text Settings', 'ekwa' ), initialOpen: true },
					el( SelectControl, {
						label:    __( 'HTML Tag', 'ekwa' ),
						value:    tagName,
						options:  TAG_OPTIONS,
						onChange: function ( v ) { setAttributes( { tagName: v } ); },
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} ),
					el( TextControl, {
						label:    __( 'Text Content', 'ekwa' ),
						value:    text,
						onChange: function ( v ) { setAttributes( { text: v } ); },
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} )
				)
			),

			el( 'div', blockProps,
				el( tagName, null, text || __( 'Text…', 'ekwa' ) )
			)
		);
	}

	registerBlockType( 'ekwa/text', {
		edit: Edit,
		save: function () { return null; },
	} );

} )( window.wp );
