/**
 * Ekwa Elfsight Review Block — Block Editor UI.
 *
 * Lets the user paste an Elfsight widget embed code in the sidebar. The
 * server-side render callback validates the input and rewrites it to a
 * lazysizes data-script trigger so the platform.js bundle is only loaded
 * once the widget enters the viewport.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el                = wp.element.createElement;
	var Fragment          = wp.element.Fragment;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var PanelBody         = wp.components.PanelBody;
	var TextareaControl   = wp.components.TextareaControl;
	var Notice            = wp.components.Notice;
	var __                = wp.i18n.__;

	var cfg = window.ekwaElfsightConfig || { lazyMode: 'native' };

	function extractAppId( code ) {
		if ( ! code ) { return ''; }
		var m = code.match( /class=["'](elfsight-app-[a-f0-9-]+)["']/i );
		return m ? m[1] : '';
	}

	function isElfsightScript( code ) {
		return /<script[^>]+src=["']https:\/\/elfsightcdn\.com\//i.test( code || '' );
	}

	function Edit( props ) {
		var attrs    = props.attributes;
		var setAttrs = props.setAttributes;

		var embedCode = attrs.embedCode || '';
		var isPasted  = embedCode.trim() !== '';
		var appId     = extractAppId( embedCode );
		var hasScript = isElfsightScript( embedCode );
		var isValid   = isPasted && appId && hasScript;

		var blockProps = useBlockProps( {
			className: 'ekwa-elfsight-review-block-wrapper',
		} );

		var inspectorChildren = [
			el( TextareaControl, {
				key: 'embed',
				label: __( 'Elfsight Embed Code', 'ekwa' ),
				help: __( 'Paste the full snippet from your Elfsight dashboard (the <script> tag and the elfsight-app div).', 'ekwa' ),
				value: embedCode,
				rows: 8,
				onChange: function ( value ) {
					setAttrs( { embedCode: value } );
				},
			} ),
		];

		if ( cfg.lazyMode !== 'lazysizes' ) {
			inspectorChildren.push(
				el( Notice, {
					key: 'lazy-mode-warning',
					status: 'warning',
					isDismissible: false,
				}, __( 'Performance → Lazy loading mode is not set to lazysizes. The widget will render with the lazy markup but will never initialize until you switch the global setting.', 'ekwa' ) )
			);
		}

		if ( isPasted && ! isValid ) {
			inspectorChildren.push(
				el( Notice, {
					key: 'invalid-paste',
					status: 'error',
					isDismissible: false,
				}, __( 'This does not look like a valid Elfsight embed. Make sure it includes <script src="https://elfsightcdn.com/..."> and a <div class="elfsight-app-..."> element.', 'ekwa' ) )
			);
		}

		var preview;
		if ( isValid ) {
			preview = el(
				'div',
				{
					className: 'ekwa-elfsight-review-preview',
					style: {
						padding: '24px',
						background: '#f5f7fb',
						border: '2px dashed #b9c3d8',
						borderRadius: '6px',
						textAlign: 'center',
						color: '#3a4860',
						display: 'flex',
						flexDirection: 'column',
						gap: '8px',
						alignItems: 'center',
					},
				},
				el( 'span', {
					className: 'dashicons dashicons-star-filled',
					style: { fontSize: '32px', width: '32px', height: '32px', color: '#f0b90b' },
				} ),
				el( 'strong', null, __( 'Elfsight Review Widget', 'ekwa' ) ),
				el( 'code', {
					style: { fontSize: '11px', color: '#6b7a99', wordBreak: 'break-all' },
				}, appId ),
				el( 'span', {
					style: { fontSize: '12px', color: '#7a8aac' },
				}, __( 'Live widget renders only on the front-end after lazy load.', 'ekwa' ) )
			);
		} else {
			preview = el(
				'div',
				{
					className: 'ekwa-elfsight-review-placeholder',
					style: {
						padding: '32px',
						background: '#f0f0f0',
						border: '2px dashed #ccc',
						borderRadius: '4px',
						textAlign: 'center',
						color: '#666',
						display: 'flex',
						flexDirection: 'column',
						gap: '10px',
						alignItems: 'center',
					},
				},
				el( 'span', {
					className: 'dashicons dashicons-star-filled',
					style: { fontSize: '40px', width: '40px', height: '40px', color: '#aaa' },
				} ),
				el( 'span', null, __( 'Ekwa Elfsight Review', 'ekwa' ) ),
				el( 'span', { style: { fontSize: '12px', color: '#999' } },
					__( 'Paste your Elfsight embed code in the block settings →', 'ekwa' )
				)
			);
		}

		return el(
			Fragment,
			null,
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Widget Settings', 'ekwa' ), initialOpen: true },
					inspectorChildren
				)
			),
			el( 'div', blockProps, preview )
		);
	}

	registerBlockType( 'ekwa/elfsight-review', {
		edit: Edit,
		save: function () {
			return null;
		},
	} );

} )( window.wp );
