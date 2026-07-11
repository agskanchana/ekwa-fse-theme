/**
 * Ekwa Responsive Visibility — editor controls for every ekwa/* block.
 *
 * Adds ekwaHideDesktop / ekwaHideTablet / ekwaHideMobile boolean attributes
 * and a "Responsive visibility" inspector panel to all ekwa blocks. The
 * blocks are server-rendered, so the actual hide classes are stamped onto the
 * frontend markup by a render_block filter (inc/ekwa-responsive.php) — here we
 * only manage the attributes and a visual editor indicator. Breakpoints come
 * from window.ekwaResponsive (Ekwa Settings), so the help text always matches.
 */
( function ( wp ) {
	'use strict';

	var addFilter          = wp.hooks.addFilter;
	var el                 = wp.element.createElement;
	var Fragment           = wp.element.Fragment;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var PanelBody          = wp.components.PanelBody;
	var ToggleControl      = wp.components.ToggleControl;
	var createHigherOrderComponent = wp.compose.createHigherOrderComponent;
	var __                 = wp.i18n.__;

	var bp = window.ekwaResponsive || { tablet: 1199, mobile: 599 };

	var HIDE_ATTRS = [ 'ekwaHideDesktop', 'ekwaHideTablet', 'ekwaHideMobile' ];

	function isEkwaBlock( name ) {
		return typeof name === 'string' && name.indexOf( 'ekwa/' ) === 0;
	}

	// 1. Register the three boolean attributes on every ekwa/* block.
	addFilter( 'blocks.registerBlockType', 'ekwa/responsive-attrs', function ( settings, name ) {
		if ( ! isEkwaBlock( name ) ) {
			return settings;
		}
		settings.attributes = Object.assign( {}, settings.attributes, {
			ekwaHideDesktop: { type: 'boolean', default: false },
			ekwaHideTablet:  { type: 'boolean', default: false },
			ekwaHideMobile:  { type: 'boolean', default: false },
		} );
		return settings;
	} );

	// 2. Add the inspector panel.
	var withVisibilityControls = createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			if ( ! isEkwaBlock( props.name ) ) {
				return el( BlockEdit, props );
			}

			var a   = props.attributes;
			var set = props.setAttributes;

			return el( Fragment, null,
				el( BlockEdit, props ),
				el( InspectorControls, null,
					el( PanelBody, {
						title: __( 'Responsive visibility', 'ekwa' ),
						initialOpen: false,
					},
						el( 'p', { style: { fontSize: '12px', color: '#757575', marginTop: 0 } },
							__( 'Hide this block on specific screen sizes. The block still shows here in the editor.', 'ekwa' )
						),
						el( ToggleControl, {
							label: __( 'Hide on desktop', 'ekwa' ),
							help:  __( 'Wider than', 'ekwa' ) + ' ' + bp.tablet + 'px',
							checked: !! a.ekwaHideDesktop,
							onChange: function ( v ) { set( { ekwaHideDesktop: v } ); },
						} ),
						el( ToggleControl, {
							label: __( 'Hide on tablet', 'ekwa' ),
							help:  ( bp.mobile + 1 ) + '–' + bp.tablet + 'px',
							checked: !! a.ekwaHideTablet,
							onChange: function ( v ) { set( { ekwaHideTablet: v } ); },
						} ),
						el( ToggleControl, {
							label: __( 'Hide on mobile', 'ekwa' ),
							help:  __( 'Up to', 'ekwa' ) + ' ' + bp.mobile + 'px',
							checked: !! a.ekwaHideMobile,
							onChange: function ( v ) { set( { ekwaHideMobile: v } ); },
						} )
					)
				)
			);
		};
	}, 'withEkwaVisibilityControls' );
	addFilter( 'editor.BlockEdit', 'ekwa/responsive-controls', withVisibilityControls );

	// 3. Editor indicator: tag the block wrapper so CSS can badge hidden blocks.
	var withVisibilityClass = createHigherOrderComponent( function ( BlockListBlock ) {
		return function ( props ) {
			var a = props.attributes || {};
			if ( ! isEkwaBlock( props.name ) || ! ( a.ekwaHideDesktop || a.ekwaHideTablet || a.ekwaHideMobile ) ) {
				return el( BlockListBlock, props );
			}
			var marks = [];
			if ( a.ekwaHideDesktop ) { marks.push( 'D' ); }
			if ( a.ekwaHideTablet )  { marks.push( 'T' ); }
			if ( a.ekwaHideMobile )  { marks.push( 'M' ); }

			var extra = 'ekwa-has-hidden ekwa-hidden-on-' + marks.join( '' ).toLowerCase();
			var newProps = Object.assign( {}, props, {
				className: ( props.className ? props.className + ' ' : '' ) + extra,
				wrapperProps: Object.assign( {}, props.wrapperProps, {
					'data-ekwa-hidden': marks.join( ' ' ),
				} ),
			} );
			return el( BlockListBlock, newProps );
		};
	}, 'withEkwaVisibilityClass' );
	addFilter( 'editor.BlockListBlock', 'ekwa/responsive-indicator', withVisibilityClass );
} )( window.wp );
