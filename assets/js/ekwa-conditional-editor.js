/**
 * Ekwa Conditional Block — Block Editor UI.
 *
 * Registers the ekwa/conditional block type with full inspector controls.
 * The actual show/hide logic runs server-side (see inc/ekwa-blocks.php).
 *
 * Conditions supported:
 *   • Page visibility   — show/hide on specific pages
 *   • Content type      — posts, pages, front page, archive, search, 404…
 *   • Device type       — all / mobile / desktop
 *   • User state        — all / logged-in (+ role filter) / logged-out
 *   • Ad tracking       — ignore / show only when tracking / hide when tracking
 *   • Schedule          — optional date-time range
 *   • Days of week      — restrict to specific days
 */
( function ( wp ) {
	'use strict';

	var registerBlockType  = wp.blocks.registerBlockType;
	var el                 = wp.element.createElement;
	var Fragment           = wp.element.Fragment;
	var useState           = wp.element.useState;
	var useEffect          = wp.element.useEffect;
	var useSelect          = wp.data.useSelect;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var InnerBlocks        = wp.blockEditor.InnerBlocks;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var PanelBody          = wp.components.PanelBody;
	var SelectControl      = wp.components.SelectControl;
	var ToggleControl      = wp.components.ToggleControl;
	var CheckboxControl    = wp.components.CheckboxControl;
	var TextControl        = wp.components.TextControl;
	var __                 = wp.i18n.__;

	var ALL_DAYS  = [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ];
	var ALL_ROLES = [ 'subscriber', 'contributor', 'author', 'editor', 'administrator' ];

	/* ------------------------------------------------------------------ */
	/* Edit component                                                       */
	/* ------------------------------------------------------------------ */
	function Edit( props ) {
		var attrs    = props.attributes;
		var setAttrs = props.setAttributes;

		/* Fetch all published pages (up to 100) for the checkbox list */
		var pages = useSelect( function ( select ) {
			return select( 'core' ).getEntityRecords( 'postType', 'page', {
				per_page : 100,
				status   : 'publish',
			} );
		}, [] );

		function togglePage( id ) {
			setAttrs( { selectedPageIds: toggleItem( attrs.selectedPageIds || [], id ) } );
		}

		/* Toggle helpers */
		function toggleItem( list, item ) {
			var copy = list.slice();
			var idx  = copy.indexOf( item );
			if ( idx > -1 ) { copy.splice( idx, 1 ); } else { copy.push( item ); }
			return copy;
		}

		/* Build condition summary for the editor badge */
		var conditions = [];
		if ( attrs.pageVisibility === 'show_on' ) {
			conditions.push( __( 'Show on', 'ekwa' ) + ' ' + ( attrs.selectedPageIds || [] ).length + ' ' + __( 'pages', 'ekwa' ) );
		} else if ( attrs.pageVisibility === 'hide_on' ) {
			conditions.push( __( 'Hide on', 'ekwa' ) + ' ' + ( attrs.selectedPageIds || [] ).length + ' ' + __( 'pages', 'ekwa' ) );
		}
		if ( attrs.contentType && attrs.contentType !== 'all' ) {
			conditions.push( attrs.contentType.replace( /_/g, ' ' ) );
		}
		if ( attrs.deviceType && attrs.deviceType !== 'all' ) {
			conditions.push( attrs.deviceType.replace( /_/g, ' ' ) );
		}
		if ( attrs.userState && attrs.userState !== 'all' ) {
			if ( attrs.userState === 'logged_in' && attrs.userRoles && attrs.userRoles.length ) {
				conditions.push( __( 'Roles', 'ekwa' ) + ': ' + attrs.userRoles.join( ', ' ) );
			} else {
				conditions.push( attrs.userState.replace( /_/g, ' ' ) );
			}
		}
		if ( attrs.adTracking && attrs.adTracking !== 'all' ) {
			conditions.push( __( 'Ad tracking', 'ekwa' ) + ': ' + attrs.adTracking.replace( /_/g, ' ' ) );
		}
		if ( attrs.scheduleEnabled ) {
			conditions.push( __( 'Scheduled', 'ekwa' ) );
		}
		if ( attrs.daysOfWeek && attrs.daysOfWeek.length ) {
			conditions.push( attrs.daysOfWeek.length + ' ' + __( 'days', 'ekwa' ) );
		}

		var blockProps = useBlockProps( {
			style : {
				border       : '2px dashed #0073aa',
				borderRadius : '3px',
				overflow     : 'hidden',
				padding      : '0',
				margin       : '4px 0',
			},
		} );

		return el(
			Fragment,
			null,

			/* ============================================================
			 * Inspector Controls
			 * ============================================================ */
			el( InspectorControls, null,

				/* --- Page Visibility --- */
				el( PanelBody, { title: __( 'Page Visibility', 'ekwa' ), initialOpen: true },
					el( SelectControl, {
						label    : __( 'Visibility rule', 'ekwa' ),
						value    : attrs.pageVisibility,
						options  : [
							{ label: __( 'Show everywhere',          'ekwa' ), value: 'everywhere' },
							{ label: __( 'Show only on selected pages', 'ekwa' ), value: 'show_on'    },
							{ label: __( 'Hide on selected pages',   'ekwa' ), value: 'hide_on'    },
						],
						onChange : function ( v ) { setAttrs( { pageVisibility: v } ); },
					} ),
					( attrs.pageVisibility === 'show_on' || attrs.pageVisibility === 'hide_on' ) &&
					el( 'div', { style: { marginTop: 8 } },
						el( 'p', { style: { fontWeight: 600, marginBottom: 4, fontSize: 12 } },
							__( 'Select pages', 'ekwa' )
						),
						! pages && el( 'p', { style: { fontSize: 12, color: '#757575' } }, __( 'Loading pages…', 'ekwa' ) ),
						pages && pages.length === 0 && el( 'p', { style: { fontSize: 12, color: '#757575' } }, __( 'No published pages found.', 'ekwa' ) ),
						pages && pages.length > 0 && el( 'div', {
							style: {
								maxHeight    : '200px',
								overflowY    : 'auto',
								border       : '1px solid #ddd',
								borderRadius : '2px',
								padding      : '4px 8px',
								background   : '#fff',
							},
						},
							pages.map( function ( page ) {
								var title = ( page.title && ( page.title.rendered || page.title.raw ) ) || '(untitled)';
								var isChecked = ( attrs.selectedPageIds || [] ).indexOf( page.id ) > -1;
								return el( CheckboxControl, {
									key     : page.id,
									label   : title,
									checked : isChecked,
									onChange: function () { togglePage( page.id ); },
								} );
							} )
						),
						( attrs.selectedPageIds && attrs.selectedPageIds.length > 0 ) &&
							el( 'p', { style: { fontSize: 11, color: '#757575', marginTop: 4 } },
								attrs.selectedPageIds.length + ' ' + __( 'page(s) selected', 'ekwa' )
							)
					)
				),

				/* --- Content Type --- */
				el( PanelBody, { title: __( 'Content Type', 'ekwa' ), initialOpen: false },
					el( SelectControl, {
						label    : __( 'Display on', 'ekwa' ),
						value    : attrs.contentType,
						options  : [
							{ label: __( 'All content',        'ekwa' ), value: 'all'           },
							{ label: __( 'Posts only',         'ekwa' ), value: 'posts_only'    },
							{ label: __( 'Pages only',         'ekwa' ), value: 'pages_only'    },
							{ label: __( 'Front page only',    'ekwa' ), value: 'front_page'    },
							{ label: __( 'Archive pages',      'ekwa' ), value: 'archive'       },
							{ label: __( 'Search results',     'ekwa' ), value: 'search'        },
							{ label: __( '404 page',           'ekwa' ), value: '404'           },
							{ label: __( 'Hide on posts',      'ekwa' ), value: 'hide_on_posts' },
							{ label: __( 'Hide on pages',      'ekwa' ), value: 'hide_on_pages' },
						],
						onChange : function ( v ) { setAttrs( { contentType: v } ); },
					} )
				),

				/* --- Device Type --- */
				el( PanelBody, { title: __( 'Device Type', 'ekwa' ), initialOpen: false },
					el( SelectControl, {
						label    : __( 'Show on', 'ekwa' ),
						value    : attrs.deviceType,
						options  : [
							{ label: __( 'All devices',    'ekwa' ), value: 'all'          },
							{ label: __( 'Mobile only',    'ekwa' ), value: 'mobile_only'  },
							{ label: __( 'Desktop only',   'ekwa' ), value: 'desktop_only' },
						],
						onChange : function ( v ) { setAttrs( { deviceType: v } ); },
					} ),
					el( 'p', { style: { fontSize: 12, color: '#757575', margin: '4px 0 0' } },
						__( 'Uses WordPress wp_is_mobile() detection.', 'ekwa' )
					)
				),

				/* --- User State --- */
				el( PanelBody, { title: __( 'User State', 'ekwa' ), initialOpen: false },
					el( SelectControl, {
						label    : __( 'Visitor', 'ekwa' ),
						value    : attrs.userState,
						options  : [
							{ label: __( 'All visitors',     'ekwa' ), value: 'all'        },
							{ label: __( 'Logged in only',   'ekwa' ), value: 'logged_in'  },
							{ label: __( 'Logged out only',  'ekwa' ), value: 'logged_out' },
						],
						onChange : function ( v ) {
							setAttrs( { userState: v } );
							if ( v !== 'logged_in' ) { setAttrs( { userRoles: [] } ); }
						},
					} ),
					attrs.userState === 'logged_in' && el( 'div', { style: { marginTop: 12 } },
						el( 'p', { style: { fontWeight: 600, marginBottom: 4, fontSize: 12 } },
							__( 'Restrict to user roles (leave all unchecked for any role):', 'ekwa' )
						),
						ALL_ROLES.map( function ( role ) {
							return el( CheckboxControl, {
								key     : role,
								label   : role.charAt( 0 ).toUpperCase() + role.slice( 1 ),
								checked : ( attrs.userRoles || [] ).indexOf( role ) > -1,
								onChange: function () {
									setAttrs( { userRoles: toggleItem( attrs.userRoles || [], role ) } );
								},
							} );
						} )
					)
				),

				/* --- Ad Tracking --- */
				el( PanelBody, { title: __( 'Ad Tracking', 'ekwa' ), initialOpen: false },
					el( SelectControl, {
						label    : __( 'Show this block', 'ekwa' ),
						value    : attrs.adTracking,
						options  : [
							{ label: __( 'Always (ignore ad tracking)',      'ekwa' ), value: 'all'               },
							{ label: __( 'Only during ad tracking',          'ekwa' ), value: 'tracking_only'     },
							{ label: __( 'Hide during ad tracking',          'ekwa' ), value: 'hide_when_tracking' },
						],
						onChange : function ( v ) { setAttrs( { adTracking: v } ); },
					} ),
					el( 'p', { style: { fontSize: 12, color: '#757575', margin: '4px 0 0' } },
						__( 'Tracking is active when the adward_number cookie is set or ?ads is in the URL.', 'ekwa' )
					)
				),

				/* --- Schedule --- */
				el( PanelBody, { title: __( 'Schedule', 'ekwa' ), initialOpen: false },
					el( ToggleControl, {
						label    : __( 'Enable date/time scheduling', 'ekwa' ),
						checked  : attrs.scheduleEnabled,
						onChange : function ( v ) { setAttrs( { scheduleEnabled: v } ); },
					} ),
					attrs.scheduleEnabled && el( Fragment, null,
						el( TextControl, {
							label    : __( 'Show from (date & time)', 'ekwa' ),
							type     : 'datetime-local',
							value    : attrs.scheduleFrom,
							onChange : function ( v ) { setAttrs( { scheduleFrom: v } ); },
						} ),
						el( TextControl, {
							label    : __( 'Show until (date & time)', 'ekwa' ),
							type     : 'datetime-local',
							value    : attrs.scheduleTo,
							onChange : function ( v ) { setAttrs( { scheduleTo: v } ); },
						} ),
						el( 'p', { style: { fontSize: 12, color: '#757575', margin: '4px 0 0' } },
							__( 'The site timezone is used for evaluation. Leave either field blank for open-ended.', 'ekwa' )
						)
					),

					/* Days of week (independent of schedule toggle) */
					el( 'div', { style: { marginTop: 16 } },
						el( 'p', { style: { fontWeight: 600, marginBottom: 4, fontSize: 12 } },
							__( 'Days of week (leave all unchecked for every day):', 'ekwa' )
						),
						ALL_DAYS.map( function ( day ) {
							return el( CheckboxControl, {
								key     : day,
								label   : day,
								checked : ( attrs.daysOfWeek || [] ).indexOf( day ) > -1,
								onChange: function () {
									setAttrs( { daysOfWeek: toggleItem( attrs.daysOfWeek || [], day ) } );
								},
							} );
						} ),
						( attrs.daysOfWeek && attrs.daysOfWeek.length > 0 ) &&
							el( 'button', {
								style  : { background: 'none', border: 'none', color: '#0073aa', cursor: 'pointer', fontSize: 11, padding: '4px 0 0' },
								onClick: function () { setAttrs( { daysOfWeek: [] } ); },
							}, __( '↺ Reset to all days', 'ekwa' ) )
					)
				)
			),

			/* ============================================================
			 * Editor preview
			 * ============================================================ */
			el( 'div', blockProps,
				/* Blue header bar showing active conditions */
				el( 'div', {
					style: {
						background     : '#0073aa',
						color          : 'white',
						padding        : '7px 14px',
						fontSize       : 12,
						display        : 'flex',
						alignItems     : 'center',
						gap            : 8,
						userSelect     : 'none',
					},
				},
					el( 'svg', { width: 14, height: 14, viewBox: '0 0 24 24', fill: 'currentColor', style: { flexShrink: 0 }, 'aria-hidden': true },
						el( 'path', { d: 'M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z' } )
					),
					el( 'strong', { style: { fontSize: 12 } }, __( 'Conditional', 'ekwa' ) ),
					conditions.length
						? el( 'span', { style: { opacity: 0.85 } }, '— ' + conditions.join( ' · ' ) )
						: el( 'span', { style: { opacity: 0.65 } }, '— ' + __( 'show everywhere', 'ekwa' ) )
				),
				/* Inner blocks area */
				el( 'div', { style: { padding: 12 } },
					el( InnerBlocks )
				)
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Register                                                             */
	/* ------------------------------------------------------------------ */
	registerBlockType( 'ekwa/conditional', {
		edit: Edit,
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );

} )( window.wp );
