/**
 * Ekwa FA Icon Inline Format.
 *
 * Solution 2: Insert a Font Awesome icon inline inside any RichText block
 * (paragraph, heading, list item, button text, etc.).
 *
 * Output: <i class="fa-solid fa-phone" aria-hidden="true">&nbsp;</i>
 *
 * Usage:
 *  - Click the "FA" toolbar button while editing a paragraph/heading.
 *  - Search for an icon and click it — it is inserted at the cursor.
 *  - If text is selected, the format is applied to that text instead.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.richText || ! wp.richText.registerFormatType ) {
		return;
	}

	var registerFormatType    = wp.richText.registerFormatType;
	var applyFormat           = wp.richText.applyFormat;
	var insert                = wp.richText.insert;
	var RichTextToolbarButton = wp.blockEditor.RichTextToolbarButton;
	var el                    = wp.element.createElement;
	var useState              = wp.element.useState;
	var Fragment              = wp.element.Fragment;
	var Popover               = wp.components.Popover;
	var TextControl           = wp.components.TextControl;
	var __                    = wp.i18n.__;

	var FORMAT_NAME = 'ekwa/fa-icon';

	/* ------------------------------------------------------------------ */
	/* Icon list (subset — suited for inline use)                          */
	/* ------------------------------------------------------------------ */
	var EKWA_ICONS = [
		// Brands
		{ name: 'Facebook',         cls: 'fa-brands fa-facebook' },
		{ name: 'X / Twitter',      cls: 'fa-brands fa-x-twitter' },
		{ name: 'Instagram',        cls: 'fa-brands fa-instagram' },
		{ name: 'LinkedIn',         cls: 'fa-brands fa-linkedin' },
		{ name: 'YouTube',          cls: 'fa-brands fa-youtube' },
		{ name: 'TikTok',           cls: 'fa-brands fa-tiktok' },
		{ name: 'Pinterest',        cls: 'fa-brands fa-pinterest' },
		{ name: 'WhatsApp',         cls: 'fa-brands fa-whatsapp' },
		{ name: 'Google',           cls: 'fa-brands fa-google' },
		{ name: 'Yelp',             cls: 'fa-brands fa-yelp' },
		{ name: 'GitHub',           cls: 'fa-brands fa-github' },
		{ name: 'Spotify',          cls: 'fa-brands fa-spotify' },
		{ name: 'Threads',          cls: 'fa-brands fa-threads' },
		{ name: 'Bluesky',          cls: 'fa-brands fa-bluesky' },
		// Solid — communication
		{ name: 'Phone',            cls: 'fa-solid fa-phone' },
		{ name: 'Envelope',         cls: 'fa-solid fa-envelope' },
		{ name: 'Globe',            cls: 'fa-solid fa-globe' },
		{ name: 'Comment',          cls: 'fa-solid fa-comment' },
		// Solid — location
		{ name: 'Location',         cls: 'fa-solid fa-location-dot' },
		{ name: 'Map',              cls: 'fa-solid fa-map' },
		// Solid — UI / decoration
		{ name: 'Star',             cls: 'fa-solid fa-star' },
		{ name: 'Heart',            cls: 'fa-solid fa-heart' },
		{ name: 'Clock',            cls: 'fa-solid fa-clock' },
		{ name: 'Calendar',         cls: 'fa-solid fa-calendar-days' },
		{ name: 'Search',           cls: 'fa-solid fa-magnifying-glass' },
		{ name: 'Share',            cls: 'fa-solid fa-share-nodes' },
		{ name: 'Link',             cls: 'fa-solid fa-link' },
		{ name: 'Check',            cls: 'fa-solid fa-check' },
		{ name: 'Circle Check',     cls: 'fa-solid fa-circle-check' },
		{ name: 'Info',             cls: 'fa-solid fa-circle-info' },
		{ name: 'Warning',          cls: 'fa-solid fa-triangle-exclamation' },
		{ name: 'Bolt',             cls: 'fa-solid fa-bolt' },
		{ name: 'Arrow Right',      cls: 'fa-solid fa-arrow-right' },
		{ name: 'Chevron Right',    cls: 'fa-solid fa-chevron-right' },
		{ name: 'Plus',             cls: 'fa-solid fa-plus' },
		{ name: 'Xmark',            cls: 'fa-solid fa-xmark' },
		{ name: 'Tag',              cls: 'fa-solid fa-tag' },
		{ name: 'RSS',              cls: 'fa-solid fa-rss' },
		// Solid — people / health
		{ name: 'User',             cls: 'fa-solid fa-user' },
		{ name: 'House',            cls: 'fa-solid fa-house' },
		{ name: 'Tooth',            cls: 'fa-solid fa-tooth' },
		{ name: 'Stethoscope',      cls: 'fa-solid fa-stethoscope' },
		{ name: 'Hospital',         cls: 'fa-solid fa-hospital' },
		{ name: 'Brain',            cls: 'fa-solid fa-brain' },
		{ name: 'Eye',              cls: 'fa-solid fa-eye' },
		{ name: 'Award',            cls: 'fa-solid fa-award' },
		{ name: 'Thumbs Up',        cls: 'fa-solid fa-thumbs-up' },
		{ name: 'Quote Left',       cls: 'fa-solid fa-quote-left' },
		{ name: 'Shield',           cls: 'fa-solid fa-shield-halved' },
		{ name: 'Wrench',           cls: 'fa-solid fa-wrench' },
		{ name: 'Hammer',           cls: 'fa-solid fa-hammer' },
		{ name: 'Truck',            cls: 'fa-solid fa-truck' },
	];

	function iconSearch( query ) {
		var q = ( query || '' ).toLowerCase().trim();
		if ( ! q ) { return EKWA_ICONS; }
		return EKWA_ICONS.filter( function ( icon ) {
			return icon.name.toLowerCase().indexOf( q ) > -1 ||
			       icon.cls.toLowerCase().indexOf( q ) > -1;
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Toolbar button icon — simple "FA" SVG text                          */
	/* ------------------------------------------------------------------ */
	var FAToolbarIcon = el(
		'svg',
		{
			viewBox:   '0 0 24 24',
			xmlns:     'http://www.w3.org/2000/svg',
			width:     24,
			height:    24,
			fill:      'currentColor',
			'aria-hidden': 'true',
		},
		el( 'text', {
			x:          '2',
			y:          '17',
			fontSize:   '14',
			fontWeight: '700',
			fontFamily: 'Arial, sans-serif',
		}, 'FA' )
	);

	/* ------------------------------------------------------------------ */
	/* Format registration                                                  */
	/* ------------------------------------------------------------------ */
	registerFormatType( FORMAT_NAME, {
		title:     __( 'FA Icon', 'ekwa' ),
		tagName:   'i',
		className: null, // match all <i> — core uses <em> for italic so no conflict
		attributes: {
			class:      'class',
			ariaHidden: 'aria-hidden',
		},

		edit: function ( formatProps ) {
			var isActive = formatProps.isActive;
			var value    = formatProps.value;
			var onChange = formatProps.onChange;

			var state0      = useState( false );
			var isOpen      = state0[ 0 ];
			var setOpen     = state0[ 1 ];

			var state1      = useState( '' );
			var query       = state1[ 0 ];
			var setQuery    = state1[ 1 ];

			var state2      = useState( '' );
			var customCls   = state2[ 0 ];
			var setCustom   = state2[ 1 ];

			var results = iconSearch( query );

			function insertIcon( cls ) {
				var hasSelection = value.start !== value.end;
				var formatted;

				if ( hasSelection ) {
					// Wrap selected text with the icon format.
					formatted = applyFormat( value, {
						type:       FORMAT_NAME,
						attributes: { class: cls, ariaHidden: 'true' },
					} );
				} else {
					// No selection — insert a non-breaking space and wrap it.
					// The &nbsp; is invisible; FA renders via CSS ::before pseudo-element.
					var inserted = insert( value, '\u00A0' );
					formatted    = applyFormat(
						inserted,
						{ type: FORMAT_NAME, attributes: { class: cls, ariaHidden: 'true' } },
						inserted.start - 1,
						inserted.start
					);
				}

				onChange( formatted );
				setOpen( false );
				setQuery( '' );
			}

			return el( Fragment, null,

				/* ---- Toolbar button ---- */
				el( RichTextToolbarButton, {
					icon:    FAToolbarIcon,
					title:   __( 'Insert FA Icon', 'ekwa' ),
					onClick: function () { setOpen( ! isOpen ); },
					isActive: isActive,
				} ),

				/* ---- Picker popover ---- */
				isOpen && el( Popover, {
					onClose:   function () { setOpen( false ); },
					placement: 'bottom-start',
				},
					el( 'div', { className: 'ekwa-fa-popover' },

						el( 'p', { className: 'ekwa-fa-popover__title' },
							__( 'Insert Font Awesome Icon', 'ekwa' )
						),

						el( TextControl, {
							value:       query,
							onChange:    setQuery,
							placeholder: __( 'Search icon…', 'ekwa' ),
							autoComplete: 'off',
						} ),

						el( 'div', { className: 'ekwa-fa-popover__grid' },
							results.map( function ( icon ) {
								return el( 'button', {
									key:          icon.cls,
									type:         'button',
									className:    'ekwa-fa-popover__btn',
									title:        icon.cls,
									'aria-label': icon.name,
									onClick:      function () { insertIcon( icon.cls ); },
								},
									el( 'i', { className: icon.cls, 'aria-hidden': 'true' } ),
									el( 'span', null, icon.name )
								);
							} )
						),

						/* ---- Custom class row ---- */
						el( 'div', { className: 'ekwa-fa-popover__custom' },
							el( 'div', { className: 'ekwa-fa-popover__custom-preview' },
								customCls
									? el( 'i', { className: customCls, 'aria-hidden': 'true' } )
									: el( 'span', { className: 'ekwa-fa-popover__custom-hint' }, '?' )
							),
							el( 'input', {
								type:        'text',
								className:   'ekwa-fa-popover__custom-input',
								value:       customCls,
								placeholder: __( 'Custom class, e.g. fa-solid fa-bolt', 'ekwa' ),
								onChange:    function ( e ) { setCustom( e.target.value ); },
								onKeyDown:   function ( e ) {
									if ( e.key === 'Enter' && customCls.trim() ) {
										e.preventDefault();
										insertIcon( customCls.trim() );
										setCustom( '' );
									}
								},
							} ),
							el( 'button', {
								type:      'button',
								className: 'ekwa-fa-popover__custom-btn' + ( ! customCls.trim() ? ' is-disabled' : '' ),
								disabled:  ! customCls.trim(),
								onClick:   function () {
									if ( customCls.trim() ) {
										insertIcon( customCls.trim() );
										setCustom( '' );
									}
								},
							}, __( 'Insert', 'ekwa' ) )
						)
					)
				)
			);
		},
	} );

} )( window.wp );
