/**
 * Ekwa Social Icons Block — Block Editor UI.
 *
 * Fetches the saved social links via a small REST endpoint so the sidebar
 * can show a named, icon-previewed color picker for each link.
 *
 * Sidebar panels:
 *   1. Settings        – show share button, icon size
 *   2. Global Color    – one color applied to all icons (unless overridden)
 *   3. Per-Icon Colors – individual color picker per saved social link
 */
( function ( wp ) {
	'use strict';

	var registerBlockType  = wp.blocks.registerBlockType;
	var el                 = wp.element.createElement;
	var Fragment           = wp.element.Fragment;
	var useState           = wp.element.useState;
	var useEffect          = wp.element.useEffect;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var PanelBody          = wp.components.PanelBody;
	var BaseControl        = wp.components.BaseControl;
	var ToggleControl      = wp.components.ToggleControl;
	var RangeControl       = wp.components.RangeControl;
	var ColorPalette       = wp.components.ColorPalette;
	var Button             = wp.components.Button;
	var ServerSideRender   = wp.serverSideRender;
	var apiFetch           = wp.apiFetch;
	var __                 = wp.i18n.__;

	registerBlockType( 'ekwa/social', {

		edit: function ( props ) {
			var attrs    = props.attributes;
			var setAttrs = props.setAttributes;

			/* -- Fetch saved social links for per-icon colour pickers ------ */
			var linksState = useState( [] );
			var links      = linksState[0];
			var setLinks   = linksState[1];

			useEffect( function () {
				apiFetch( { path: '/ekwa/v1/social-links' } )
					.then( function ( data ) {
						if ( Array.isArray( data ) ) { setLinks( data ); }
					} )
					.catch( function () {} );
			}, [] );

			/* -- Helpers for iconColors (object attribute, keyed by index) - */
			var iconColors = attrs.iconColors || {};

			function setIconColor( idx, color ) {
				var updated = Object.assign( {}, iconColors );
				if ( color ) {
					updated[ idx ] = color;
				} else {
					delete updated[ idx ];
				}
				setAttrs( { iconColors: updated } );
			}

			var blockProps = useBlockProps( {
				className: 'ekwa-social-block-wrapper',
				style: { display: 'block' },
			} );

			return el(
				Fragment,
				null,

				/* ---- Sidebar ----------------------------------------- */
				el(
					InspectorControls,
					null,

					/* General panel */
					el(
						PanelBody,
						{ title: __( 'Settings', 'ekwa' ), initialOpen: true },

						el( ToggleControl, {
							label:    __( 'Show Share Button', 'ekwa' ),
help:     __( 'Adds a pop-up with Facebook, X and Pinterest share links.', 'ekwa' ),
checked:  attrs.showShare,
onChange: function ( v ) { setAttrs( { showShare: v } ); },
} ),

el( RangeControl, {
label:   __( 'Icon Size (px)', 'ekwa' ),
help:    __( 'Set to 0 to inherit from your stylesheet.', 'ekwa' ),
value:   attrs.iconSize,
min:     0,
max:     64,
step:    2,
onChange: function ( v ) { setAttrs( { iconSize: v } ); },
} )
),

/* Global colour panel */
el(
PanelBody,
{ title: __( 'Global Icon Color', 'ekwa' ), initialOpen: false },
el(
BaseControl,
{
id:   'ekwa-social-global-color',
help: __( 'Applied to every icon. Override individual icons below.', 'ekwa' ),
},
el( ColorPalette, {
value:    attrs.iconColor,
onChange: function ( c ) { setAttrs( { iconColor: c || '' } ); },
} ),
attrs.iconColor && el(
Button,
{
variant:   'tertiary',
isSmall:   true,
style:     { marginTop: '6px' },
onClick:   function () { setAttrs( { iconColor: '' } ); },
},
__( 'Clear global color', 'ekwa' )
)
)
),

/* Per-icon colours panel */
( links.length > 0 || attrs.showShare ) && el(
PanelBody,
{ title: __( 'Per-Icon Colors', 'ekwa' ), initialOpen: false },
links.map( function ( link, idx ) {
var iconLabel    = link.name || ( __( 'Icon', 'ekwa' ) + ' ' + ( idx + 1 ) );
var currentColor = iconColors[ idx ] || '';
var previewColor = currentColor || attrs.iconColor || '';

return el(
BaseControl,
{
key:   idx,
id:    'ekwa-social-icon-color-' + idx,
label: iconLabel,
},
/* icon preview */
link.icon && el(
'div',
{ style: { marginBottom: '6px' } },
el( 'i', {
className:    link.icon,
'aria-hidden': 'true',
style: {
fontSize: '22px',
color:    previewColor || 'inherit',
transition: 'color .15s',
},
} )
),
el( ColorPalette, {
value:    currentColor,
onChange: function ( c ) { setIconColor( idx, c || '' ); },
} ),
currentColor && el(
Button,
{
variant: 'tertiary',
isSmall: true,
style:   { marginTop: '4px' },
onClick: function () { setIconColor( idx, '' ); },
},
__( 'Clear', 'ekwa' )
)
);
} ),

/* Share button icon colour (only when share is enabled) */
attrs.showShare && el(
BaseControl,
{
id:    'ekwa-social-share-icon-color',
label: __( 'Share Button Icon', 'ekwa' ),
},
el(
'div',
{ style: { marginBottom: '6px' } },
el( 'i', {
className:     'fa-solid fa-share-nodes',
'aria-hidden':  'true',
style: {
fontSize:   '22px',
color:      attrs.shareIconColor || attrs.iconColor || 'inherit',
transition: 'color .15s',
},
} )
),
el( ColorPalette, {
value:    attrs.shareIconColor || '',
onChange: function ( c ) { setAttrs( { shareIconColor: c || '' } ); },
} ),
attrs.shareIconColor && el(
Button,
{
variant: 'tertiary',
isSmall: true,
style:   { marginTop: '4px' },
onClick: function () { setAttrs( { shareIconColor: '' } ); },
},
__( 'Clear', 'ekwa' )
)
)
)
),

/* ---- Editor preview ---------------------------------- */
el(
'div',
blockProps,
el( ServerSideRender, {
block:      'ekwa/social',
attributes: attrs,
} )
)
);
},

save: function () {
return null;
},
} );

} )( window.wp );