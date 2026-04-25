/**
 * Ekwa Card Link Block — Block Editor UI.
 *
 * A linked card wrapper: the entire card is an accessible <a> tag.
 * In the editor it renders as a <div> with InnerBlocks.
 * On the frontend PHP renders it as <a> (or <div> if no URL).
 */
( function ( wp ) {
	'use strict';

	var registerBlockType  = wp.blocks.registerBlockType;
	var el                 = wp.element.createElement;
	var Fragment           = wp.element.Fragment;
	var InnerBlocks        = wp.blockEditor.InnerBlocks;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var PanelBody          = wp.components.PanelBody;
	var TextControl        = wp.components.TextControl;
	var ToggleControl      = wp.components.ToggleControl;
	var __                 = wp.i18n.__;
	var LinkSourceControls = window.EkwaLinkSource && window.EkwaLinkSource.Controls;

	var TEMPLATE = [
		[ 'ekwa/icon', {} ],
		[ 'core/heading', { level: 4, placeholder: 'Card title' } ],
		[ 'core/paragraph', { placeholder: 'Card description…' } ],
	];

	/* ------------------------------------------------------------------ */
	/* Edit component                                                       */
	/* ------------------------------------------------------------------ */
	function Edit( props ) {
		var attributes    = props.attributes;
		var setAttributes = props.setAttributes;

		var linkType = attributes.linkType || 'external';
		var url      = attributes.url      || '';
		var newTab   = !! attributes.newTab;
		var rel      = attributes.rel      || '';

		// Indicator label depends on link source.
		var indicatorLabel = '';
		if ( 'external' === linkType ) {
			indicatorLabel = url;
		} else if ( 'internal' === linkType ) {
			indicatorLabel = attributes.pageId
				? __( 'Linked to selected page/post', 'ekwa' )
				: __( 'No page/post selected', 'ekwa' );
		} else if ( 'appointment' === linkType ) {
			indicatorLabel = __( 'Appointment URL (from settings)', 'ekwa' );
		}

		var blockProps = useBlockProps( {
			className: 'ekwa-card-link ekwa-card-link--editor',
		} );

		return el( Fragment, null,

			/* ---------- Inspector sidebar ---------- */
			el( InspectorControls, null,
				el( PanelBody, { title: __( 'Link Settings', 'ekwa' ), initialOpen: true },
					LinkSourceControls && el( LinkSourceControls, {
						attributes:    attributes,
						setAttributes: setAttributes
					} ),
					el( ToggleControl, {
						label:    __( 'Open in new tab', 'ekwa' ),
						checked:  newTab,
						onChange: function ( v ) { setAttributes( { newTab: v } ); },
						__nextHasNoMarginBottom: true,
					} ),
					el( TextControl, {
						label:                  __( 'Link rel', 'ekwa' ),
						help:                   __( 'Optional rel attribute (e.g. nofollow).', 'ekwa' ),
						value:                  rel,
						onChange:               function ( v ) { setAttributes( { rel: v.trim() } ); },
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} )
				)
			),

			/* ---------- Canvas ---------- */
			el( 'div', blockProps,

				/* Link indicator bar */
				indicatorLabel && el( 'div', { className: 'ekwa-card-link__indicator' },
					el( 'i', { className: 'fa-solid fa-link', 'aria-hidden': 'true' } ),
					el( 'span', null, ' ' + indicatorLabel )
				),

				el( InnerBlocks, {
					template:     TEMPLATE,
					templateLock: false,
				} )
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Register block                                                       */
	/* ------------------------------------------------------------------ */
	registerBlockType( 'ekwa/card-link', {
		edit: Edit,
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );

} )( window.wp );
