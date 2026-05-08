/**
 * Ekwa Custom HTML Attributes — shared Inspector control.
 *
 * Exposes window.EkwaCustomAttributes.Control, a key/value repeater that
 * reads and writes a block's `customAttributes` object attribute. Used by
 * ekwa/div and ekwa/text so authors can pass through data-*, aria-* and
 * a small allowlist of static attributes (role, title, tabindex, lang, dir)
 * for client-side scripts and accessibility wiring.
 *
 * Allowlist is enforced both here (UI hint) and again in the PHP renderer
 * via ekwa_render_custom_attributes() — server is the source of truth.
 */
( function ( wp ) {
	'use strict';

	var el        = wp.element.createElement;
	var useState  = wp.element.useState;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var Button    = wp.components.Button;
	var __        = wp.i18n.__;

	var STATIC_ALLOWED = [ 'role', 'title', 'tabindex', 'lang', 'dir' ];
	var DATA_ARIA_RE   = /^(?:data|aria)-[a-z0-9_-]+$/;

	function isValidName( name ) {
		if ( ! name ) { return false; }
		var lower = String( name ).toLowerCase();
		return DATA_ARIA_RE.test( lower ) || STATIC_ALLOWED.indexOf( lower ) !== -1;
	}

	function rowsFromAttr( obj ) {
		if ( ! obj || typeof obj !== 'object' ) { return []; }
		return Object.keys( obj ).map( function ( k ) {
			var v = obj[ k ];
			return {
				name:  k,
				value: ( v == null ) ? '' : String( v ),
			};
		} );
	}

	function objFromRows( rows ) {
		var out = {};
		rows.forEach( function ( r ) {
			var name = ( r.name || '' ).toLowerCase().trim();
			if ( name ) {
				out[ name ] = ( r.value == null ) ? '' : String( r.value );
			}
		} );
		return out;
	}

	function Control( props ) {
		var setAttributes = props.setAttributes;
		var attributes    = props.attributes || {};

		// Local rows state lets the user keep an empty draft row visible while
		// typing — empty-name rows aren't persisted to the attribute object.
		var s = useState( function () { return rowsFromAttr( attributes.customAttributes ); } );
		var rows    = s[0];
		var setRows = s[1];

		function commit( nextRows ) {
			setRows( nextRows );
			setAttributes( { customAttributes: objFromRows( nextRows ) } );
		}

		function updateRow( idx, patch ) {
			var next = rows.slice();
			next[ idx ] = Object.assign( {}, next[ idx ], patch );
			commit( next );
		}

		function removeRow( idx ) {
			var next = rows.slice();
			next.splice( idx, 1 );
			commit( next );
		}

		function addRow() {
			commit( rows.concat( [ { name: '', value: '' } ] ) );
		}

		var rowEls = rows.map( function ( row, idx ) {
			// Show the inline error only once the user has typed a plausibly-
			// complete name (contains a dash, or is at least 3 chars long) to
			// avoid yelling at every intermediate keystroke.
			var trimmed   = ( row.name || '' ).trim();
			var lookComplete = trimmed.indexOf( '-' ) !== -1 || trimmed.length >= 3;
			var showError = trimmed && lookComplete && ! isValidName( trimmed );

			return el( 'div', {
				key: 'row-' + idx,
				style: { display: 'flex', gap: '6px', alignItems: 'flex-start', marginBottom: '10px' },
			},
				el( 'div', { style: { flex: '1 1 0', minWidth: 0 } },
					el( TextControl, {
						label: idx === 0 ? __( 'Name', 'ekwa' ) : '',
						hideLabelFromVision: idx !== 0,
						value: row.name,
						placeholder: 'data-target',
						onChange: function ( v ) { updateRow( idx, { name: v } ); },
						__next40pxDefaultSize: true,
						__nextHasNoMarginBottom: true,
					} ),
					showError ? el( 'p', {
						style: { color: '#cc1818', fontSize: '11px', margin: '4px 0 0', lineHeight: 1.3 },
					}, __( 'Only data-*, aria-*, role, title, tabindex, lang, dir.', 'ekwa' ) ) : null
				),
				el( 'div', { style: { flex: '1 1 0', minWidth: 0 } },
					el( TextControl, {
						label: idx === 0 ? __( 'Value', 'ekwa' ) : '',
						hideLabelFromVision: idx !== 0,
						value: row.value,
						placeholder: '30',
						onChange: function ( v ) { updateRow( idx, { value: v } ); },
						__next40pxDefaultSize: true,
						__nextHasNoMarginBottom: true,
					} )
				),
				el( Button, {
					icon: 'no-alt',
					label: __( 'Remove attribute', 'ekwa' ),
					isSmall: true,
					onClick: function () { removeRow( idx ); },
					style: { marginTop: idx === 0 ? '24px' : '4px' },
				} )
			);
		} );

		var children = [];

		children.push(
			el( 'p', {
				key: 'help',
				style: { fontSize: '12px', color: '#757575', margin: '0 0 12px' },
			},
				__( 'Pass-through HTML attributes (data-*, aria-*, role, title, tabindex, lang, dir).', 'ekwa' )
			)
		);

		if ( rowEls.length ) {
			children.push( el( 'div', { key: 'rows' }, rowEls ) );
		}

		children.push(
			el( Button, {
				key: 'add',
				variant: 'secondary',
				isSecondary: true,
				isSmall: true,
				onClick: addRow,
			}, '+ ' + __( 'Add Attribute', 'ekwa' ) )
		);

		return el( PanelBody, {
			title: __( 'Custom HTML Attributes', 'ekwa' ),
			initialOpen: rows.length > 0,
		}, children );
	}

	window.EkwaCustomAttributes = window.EkwaCustomAttributes || {};
	window.EkwaCustomAttributes.Control = Control;

} )( window.wp );
