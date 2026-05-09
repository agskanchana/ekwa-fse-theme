/**
 * Ekwa Policy Pages Block — Block Editor UI.
 *
 * Server-side block. The editor only collects the policy page ID; all of
 * the practice values (name, address, phone, email, country) are pulled
 * from Ekwa Settings at render time, not edited per-instance.
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
	var __                = wp.i18n.__;

	var POLICY_OPTIONS = [
		{ label: '— Select a policy page —', value: '' },
		{ label: 'Accessibility Statement',  value: '199' },
		{ label: 'Privacy Policy',           value: 'priv_policy' },
		{ label: 'Cookie Policy',            value: 'cookie_policy' },
		{ label: 'Hipaa Policy',             value: '48' },
	];

	function policyLabel( id ) {
		for ( var i = 0; i < POLICY_OPTIONS.length; i++ ) {
			if ( POLICY_OPTIONS[ i ].value === id ) {
				return POLICY_OPTIONS[ i ].label;
			}
		}
		return id;
	}

	function Edit( props ) {
		var attributes    = props.attributes;
		var setAttributes = props.setAttributes;
		var pageId        = attributes.policyPageId || '';

		var blockProps = useBlockProps( { className: 'ekwa-policy-pages-editor' } );

		var settingsUrl = ( window.ekwaBlockData && window.ekwaBlockData.apptSettingsUrl )
			|| '/wp-admin/themes.php?page=ekwa-settings';

		return el( Fragment, null,

			el( InspectorControls, null,
				el( PanelBody, { title: __( 'Policy Page', 'ekwa' ), initialOpen: true },
					el( SelectControl, {
						label:    __( 'Policy Page', 'ekwa' ),
						help:     __( 'Pulled from policies.ekwa.com.', 'ekwa' ),
						value:    pageId,
						options:  POLICY_OPTIONS,
						onChange: function ( v ) { setAttributes( { policyPageId: v } ); },
						__next40pxDefaultSize:  true,
						__nextHasNoMarginBottom: true,
					} )
				),
				el( PanelBody, { title: __( 'Practice values', 'ekwa' ), initialOpen: false },
					el( 'p', { style: { fontSize: '12px', color: '#555', margin: '0 0 8px' } },
						__( 'Practice name, address, phone, email and country are pulled from Ekwa Settings at render time.', 'ekwa' )
					),
					el( 'a', { href: settingsUrl, target: '_blank', rel: 'noopener' },
						__( 'Open Ekwa Settings →', 'ekwa' )
					)
				)
			),

			el( 'div', blockProps,
				el( 'div', {
					style: {
						border:        '1px dashed #c3c4c7',
						borderRadius:  '4px',
						padding:       '24px',
						textAlign:     'center',
						background:    '#f6f7f7',
					},
				},
					el( 'span', {
						className: 'dashicons dashicons-shield',
						style:     { fontSize: '28px', width: '28px', height: '28px', color: '#2271b1' },
					} ),
					el( 'div', { style: { fontWeight: 600, marginTop: '8px' } },
						__( 'Policy Pages', 'ekwa' )
					),
					el( 'div', { style: { fontSize: '13px', color: '#555', marginTop: '4px' } },
						pageId
							? __( 'Will render:', 'ekwa' ) + ' ' + policyLabel( pageId )
							: __( 'Choose a policy page in the sidebar.', 'ekwa' )
					)
				)
			)
		);
	}

	registerBlockType( 'ekwa/policy-pages', {
		edit: Edit,
		save: function () { return null; },
	} );

} )( window.wp );
