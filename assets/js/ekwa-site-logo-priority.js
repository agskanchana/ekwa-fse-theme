/**
 * Site Logo — "Load first (LCP image)" toggle (see inc/ekwa-site-logo-priority.php).
 *
 * Declares the ekwaFetchPriority attribute on core/site-logo in the editor too
 * (so the post and site editors keep it when a header is re-saved) and adds the
 * switch to the block's settings.
 */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.hooks || ! wp.compose || ! wp.blockEditor || ! wp.components ) {
		return;
	}
	var el = wp.element.createElement;
	var __ = wp.i18n.__;

	wp.hooks.addFilter( 'blocks.registerBlockType', 'ekwa/site-logo-priority', function ( settings, name ) {
		if ( 'core/site-logo' !== name ) {
			return settings;
		}
		var attrs = Object.assign( {}, settings.attributes );
		attrs.ekwaFetchPriority = { type: 'boolean', 'default': false };
		return Object.assign( {}, settings, { attributes: attrs } );
	} );

	var withToggle = wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			if ( 'core/site-logo' !== props.name ) {
				return el( BlockEdit, props );
			}
			return el( wp.element.Fragment, null,
				el( BlockEdit, props ),
				el( wp.blockEditor.InspectorControls, null,
					el( wp.components.PanelBody, { title: __( 'Performance', 'ekwa' ), initialOpen: !! props.attributes.ekwaFetchPriority },
						el( wp.components.ToggleControl, {
							label: __( 'Load first (LCP image)', 'ekwa' ),
							help: __( 'Preload this logo and fetch it with high priority, never lazy-loaded. Turn on when PageSpeed reports the logo as the Largest Contentful Paint — typically the logo in the mobile header.', 'ekwa' ),
							checked: !! props.attributes.ekwaFetchPriority,
							onChange: function ( v ) { props.setAttributes( { ekwaFetchPriority: v } ); },
						} )
					)
				)
			);
		};
	}, 'withEkwaSiteLogoPriority' );

	wp.hooks.addFilter( 'editor.BlockEdit', 'ekwa/site-logo-priority-toggle', withToggle );
}( window.wp ) );
