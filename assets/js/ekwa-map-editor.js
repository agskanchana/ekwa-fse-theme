/**
 * Ekwa Google Map Block — Block Editor UI.
 *
 * Lets the user paste a Google Maps iframe embed code in the sidebar.
 * The block extracts the src, renders a live 100%-wide preview in the editor,
 * and outputs a clean, colorful iframe on the front end (server-side render).
 */
( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el                = wp.element.createElement;
	var useState          = wp.element.useState;
	var useEffect         = wp.element.useEffect;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var PanelBody         = wp.components.PanelBody;
	var TextareaControl   = wp.components.TextareaControl;
	var RangeControl      = wp.components.RangeControl;
	var ToggleControl     = wp.components.ToggleControl;
	var Notice            = wp.components.Notice;
	var __                = wp.i18n.__;  

	/**
	 * Extract the src URL from a raw iframe string.
	 * Returns empty string if nothing is found or the URL is not a Google Maps URL.
	 */
	function extractMapSrc( embedCode ) {
		if ( ! embedCode ) {
			return '';
		}
		var match = embedCode.match( /src=["']([^"']+)["']/i );
		if ( ! match ) {
			return '';
		}
		var src = match[ 1 ];
		// Accept google.com/maps embed URLs only.
		if ( ! /^https:\/\/www\.google\.com\/maps\//i.test( src ) ) {
			return '';
		}
		return src;
	}

	/* ------------------------------------------------------------------ */
	/* Edit component                                                       */
	/* ------------------------------------------------------------------ */
	function Edit( props ) {
		var attrs    = props.attributes;
		var setAttrs = props.setAttributes;

		var embedCode = attrs.embedCode || '';
		var height    = attrs.height    || 450;
		var colorful  = attrs.colorful !== undefined ? attrs.colorful : true;

		var mapSrc  = extractMapSrc( embedCode );
		var isValid = mapSrc !== '';
		var isPasted = embedCode.trim() !== '';

		var blockProps = useBlockProps( {
			className: 'ekwa-map-block-wrapper',
		} );

		return el(
			wp.element.Fragment,
			null,

			/* ---- Inspector sidebar ---- */
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Map Settings', 'ekwa' ), initialOpen: true },

					el( TextareaControl, {
						label: __( 'Google Maps Embed Code', 'ekwa' ),
						help: __( 'Paste the full <iframe> embed code from Google Maps → Share → Embed a map.', 'ekwa' ),
						value: embedCode,
						rows: 6,
						onChange: function ( value ) {
							setAttrs( { embedCode: value } );
						},
					} ),

					isPasted && ! isValid && el( Notice, {
						status: 'warning',
						isDismissible: false,
					}, __( 'No valid Google Maps src found. Make sure you pasted the full <iframe> embed code.', 'ekwa' ) ),

					el( RangeControl, {
						label: __( 'Map Height (px)', 'ekwa' ),
						value: height,
						min: 200,
						max: 800,
						step: 10,
						onChange: function ( value ) {
							setAttrs( { height: value } );
						},
					} ),

					el( ToggleControl, {
						label: __( 'Colorful Map', 'ekwa' ),
						help: colorful
							? __( 'Map is displayed in full color.', 'ekwa' )
							: __( 'Map is displayed in grayscale.', 'ekwa' ),
						checked: colorful,
						onChange: function ( value ) {
							setAttrs( { colorful: value } );
						},
					} )
				)
			),

			/* ---- Editor preview ---- */
			el(
				'div',
				blockProps,
				isValid
					? el(
						'div',
						{
							className: 'ekwa-map-preview',
							style: { position: 'relative', pointerEvents: 'none' },
						},
						el( 'iframe', {
							src: mapSrc,
							width: '100%',
							height: height,
							style: {
								border: 'none',
								display: 'block',
								width: '100%',
								filter: 'none',
								WebkitFilter: 'none',
							},
							loading: 'lazy',
							title: __( 'Google Map', 'ekwa' ),
						} )
					)
					: el(
						'div',
						{
							className: 'ekwa-map-placeholder',
							style: {
								height: height + 'px',
								display: 'flex',
								alignItems: 'center',
								justifyContent: 'center',
								flexDirection: 'column',
								gap: '10px',
								background: '#f0f0f0',
								border: '2px dashed #ccc',
								color: '#666',
								fontSize: '14px',
							},
						},
						el( 'span', {
							className: 'dashicons dashicons-location-alt',
							style: { fontSize: '40px', width: '40px', height: '40px', color: '#aaa' },
						} ),
						el( 'span', null, __( 'Ekwa Google Map', 'ekwa' ) ),
						el( 'span', { style: { fontSize: '12px', color: '#999' } },
							__( 'Paste your Google Maps iframe code in the block settings →', 'ekwa' )
						)
					)
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Register the block                                                   */
	/* ------------------------------------------------------------------ */
	registerBlockType( 'ekwa/map', {
		edit: Edit,
		// No client-side save — server-side render callback handles output.
		save: function () {
			return null;
		},
	} );

} )( window.wp );
