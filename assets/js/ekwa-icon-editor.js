/**
 * Ekwa Icon Block — Block Editor UI.
 *
 * Solution 1: Standalone Font Awesome icon block.
 * Output: <div class="way-icon"><i class="fa-solid fa-bolt" aria-hidden="true"></i></div>
 */
( function ( wp ) {
	'use strict';

	var registerBlockType  = wp.blocks.registerBlockType;
	var el                 = wp.element.createElement;
	var useState           = wp.element.useState;
	var Fragment           = wp.element.Fragment;
	var BlockControls      = wp.blockEditor.BlockControls;
	var AlignmentControl   = wp.blockEditor.AlignmentControl;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var PanelBody          = wp.components.PanelBody;
	var TextControl        = wp.components.TextControl;
	var RangeControl       = wp.components.RangeControl;
	var ColorPicker        = wp.components.ColorPicker;
	var __                 = wp.i18n.__;  // double underscore — the i18n translation function

	/* ------------------------------------------------------------------ */
	/* Icon list                                                            */
	/* ------------------------------------------------------------------ */
	var EKWA_ICONS = [
		// Brands
		{ name: 'Facebook',           cls: 'fa-brands fa-facebook' },
		{ name: 'Facebook F',         cls: 'fa-brands fa-facebook-f' },
		{ name: 'X / Twitter',        cls: 'fa-brands fa-x-twitter' },
		{ name: 'Instagram',          cls: 'fa-brands fa-instagram' },
		{ name: 'LinkedIn',           cls: 'fa-brands fa-linkedin' },
		{ name: 'YouTube',            cls: 'fa-brands fa-youtube' },
		{ name: 'TikTok',             cls: 'fa-brands fa-tiktok' },
		{ name: 'Pinterest',          cls: 'fa-brands fa-pinterest' },
		{ name: 'Snapchat',           cls: 'fa-brands fa-snapchat' },
		{ name: 'WhatsApp',           cls: 'fa-brands fa-whatsapp' },
		{ name: 'Google',             cls: 'fa-brands fa-google' },
		{ name: 'Yelp',               cls: 'fa-brands fa-yelp' },
		{ name: 'Tripadvisor',        cls: 'fa-brands fa-tripadvisor' },
		{ name: 'Reddit',             cls: 'fa-brands fa-reddit' },
		{ name: 'GitHub',             cls: 'fa-brands fa-github' },
		{ name: 'Spotify',            cls: 'fa-brands fa-spotify' },
		{ name: 'Threads',            cls: 'fa-brands fa-threads' },
		{ name: 'Bluesky',            cls: 'fa-brands fa-bluesky' },
		{ name: 'Mastodon',           cls: 'fa-brands fa-mastodon' },
		{ name: 'Medium',             cls: 'fa-brands fa-medium' },
		{ name: 'Behance',            cls: 'fa-brands fa-behance' },
		{ name: 'Dribbble',           cls: 'fa-brands fa-dribbble' },
		{ name: 'Discord',            cls: 'fa-brands fa-discord' },
		{ name: 'Slack',              cls: 'fa-brands fa-slack' },
		{ name: 'Twitch',             cls: 'fa-brands fa-twitch' },
		{ name: 'Vimeo',              cls: 'fa-brands fa-vimeo' },
		{ name: 'SoundCloud',         cls: 'fa-brands fa-soundcloud' },
		{ name: 'Google Play',        cls: 'fa-brands fa-google-play' },
		{ name: 'App Store',          cls: 'fa-brands fa-app-store-ios' },
		// Solid — communication
		{ name: 'Phone',              cls: 'fa-solid fa-phone' },
		{ name: 'Envelope',           cls: 'fa-solid fa-envelope' },
		{ name: 'Comment',            cls: 'fa-solid fa-comment' },
		{ name: 'Comments',           cls: 'fa-solid fa-comments' },
		{ name: 'Globe',              cls: 'fa-solid fa-globe' },
		// Solid — location / map
		{ name: 'Location',           cls: 'fa-solid fa-location-dot' },
		{ name: 'Map Marker',         cls: 'fa-solid fa-map-marker-alt' },
		{ name: 'Map',                cls: 'fa-solid fa-map' },
		// Solid — navigation
		{ name: 'Arrow Right',        cls: 'fa-solid fa-arrow-right' },
		{ name: 'Arrow Left',         cls: 'fa-solid fa-arrow-left' },
		{ name: 'Arrow Up',           cls: 'fa-solid fa-arrow-up' },
		{ name: 'Arrow Down',         cls: 'fa-solid fa-arrow-down' },
		{ name: 'Chevron Right',      cls: 'fa-solid fa-chevron-right' },
		{ name: 'Chevron Down',       cls: 'fa-solid fa-chevron-down' },
		// Solid — UI
		{ name: 'Star',               cls: 'fa-solid fa-star' },
		{ name: 'Heart',              cls: 'fa-solid fa-heart' },
		{ name: 'Clock',              cls: 'fa-solid fa-clock' },
		{ name: 'Calendar',           cls: 'fa-solid fa-calendar-days' },
		{ name: 'Search',             cls: 'fa-solid fa-magnifying-glass' },
		{ name: 'Share',              cls: 'fa-solid fa-share-nodes' },
		{ name: 'Link',               cls: 'fa-solid fa-link' },
		{ name: 'Plus',               cls: 'fa-solid fa-plus' },
		{ name: 'Xmark / Close',      cls: 'fa-solid fa-xmark' },
		{ name: 'Check',              cls: 'fa-solid fa-check' },
		{ name: 'Circle Check',       cls: 'fa-solid fa-circle-check' },
		{ name: 'Info',               cls: 'fa-solid fa-circle-info' },
		{ name: 'Warning',            cls: 'fa-solid fa-triangle-exclamation' },
		{ name: 'RSS',                cls: 'fa-solid fa-rss' },
		{ name: 'Tag',                cls: 'fa-solid fa-tag' },
		// Solid — people
		{ name: 'User',               cls: 'fa-solid fa-user' },
		{ name: 'Users',              cls: 'fa-solid fa-users' },
		{ name: 'House',              cls: 'fa-solid fa-house' },
		// Solid — media
		{ name: 'Camera',             cls: 'fa-solid fa-camera' },
		{ name: 'Video',              cls: 'fa-solid fa-video' },
		{ name: 'Microphone',         cls: 'fa-solid fa-microphone' },
		// Solid — actions / misc
		{ name: 'Bolt / Lightning',   cls: 'fa-solid fa-bolt' },
		{ name: 'Shield',             cls: 'fa-solid fa-shield-halved' },
		{ name: 'Lock',               cls: 'fa-solid fa-lock' },
		{ name: 'Key',                cls: 'fa-solid fa-key' },
		{ name: 'Award',              cls: 'fa-solid fa-award' },
		{ name: 'Trophy',             cls: 'fa-solid fa-trophy' },
		{ name: 'Thumbs Up',          cls: 'fa-solid fa-thumbs-up' },
		{ name: 'Quote Left',         cls: 'fa-solid fa-quote-left' },
		// Solid — health / trades
		{ name: 'Tooth',              cls: 'fa-solid fa-tooth' },
		{ name: 'Stethoscope',        cls: 'fa-solid fa-stethoscope' },
		{ name: 'Hospital',           cls: 'fa-solid fa-hospital' },
		{ name: 'Brain',              cls: 'fa-solid fa-brain' },
		{ name: 'Eye',                cls: 'fa-solid fa-eye' },
		{ name: 'Wrench',             cls: 'fa-solid fa-wrench' },
		{ name: 'Screwdriver Wrench', cls: 'fa-solid fa-screwdriver-wrench' },
		{ name: 'Hammer',             cls: 'fa-solid fa-hammer' },
		{ name: 'Paint Brush',        cls: 'fa-solid fa-paint-brush' },
		{ name: 'Scissors',           cls: 'fa-solid fa-scissors' },
		{ name: 'Truck',              cls: 'fa-solid fa-truck' },
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
	/* Icon picker component (used inside the sidebar panel)               */
	/* ------------------------------------------------------------------ */
	function IconPicker( props ) {
		var selected = props.selected;
		var onSelect = props.onChange;

		var state0  = useState( '' );
		var query   = state0[ 0 ];
		var setQuery = state0[ 1 ];

		var results = iconSearch( query );

		return el( 'div', { className: 'ekwa-blk-icon-picker' },

			el( TextControl, {
				value:                  query,
				onChange:               setQuery,
				placeholder:            __( 'Search icons…', 'ekwa' ),
				autoComplete:           'off',
				__next40pxDefaultSize:  true,
				__nextHasNoMarginBottom: true,
			} ),

			el( 'div', { className: 'ekwa-blk-icon-grid' },
				results.map( function ( icon ) {
					return el( 'button', {
						key:          icon.cls,
						type:         'button',
						className:    'ekwa-blk-icon-btn' + ( selected === icon.cls ? ' is-active' : '' ),
						title:        icon.cls,
						'aria-label': icon.name,
						onClick:      function () { onSelect( icon.cls ); },
					},
						el( 'i', { className: icon.cls, 'aria-hidden': 'true' } ),
						el( 'span', null, icon.name )
					);
				} )
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Edit component                                                       */
	/* ------------------------------------------------------------------ */
	function Edit( props ) {
		var attributes    = props.attributes;
		var setAttributes = props.setAttributes;

		var iconClass    = attributes.iconClass    !== undefined ? attributes.iconClass    : 'fa-solid fa-star';
		var wrapperClass = attributes.wrapperClass !== undefined ? attributes.wrapperClass : 'way-icon';
		var size         = attributes.size         !== undefined ? attributes.size         : 0;
		var color        = attributes.color        !== undefined ? attributes.color        : '';
		var align        = attributes.align        !== undefined ? attributes.align        : '';

		var blockProps = useBlockProps( {
			style: { textAlign: align || undefined },
		} );

		var iconStyle = {};
		if ( size )  { iconStyle.fontSize = size + 'px'; }
		if ( color ) { iconStyle.color    = color; }

		return el( Fragment, null,

			/* ---------- Block toolbar: left / center / right ---------- */
			el( BlockControls, { group: 'block' },
				el( AlignmentControl, {
					value:    align,
					onChange: function ( v ) { setAttributes( { align: v || '' } ); },
				} )
			),

			/* ---------- Inspector sidebar ---------- */
			el( InspectorControls, null,

				el( PanelBody, { title: __( 'Choose Icon', 'ekwa' ), initialOpen: true },
					el( IconPicker, {
						selected: iconClass,
						onChange: function ( cls ) { setAttributes( { iconClass: cls } ); },
					} ),
					el( TextControl, {
					label:                  __( 'Custom class', 'ekwa' ),
					help:                   __( 'Override — type any FA class e.g. fa-solid fa-bolt', 'ekwa' ),
					value:                  iconClass,
					onChange:               function ( v ) { setAttributes( { iconClass: v.trim() } ); },
					__next40pxDefaultSize:  true,
					__nextHasNoMarginBottom: true,
					} )
				),

				el( PanelBody, { title: __( 'Styling', 'ekwa' ), initialOpen: false },
					el( RangeControl, {
						label:      __( 'Size (px)', 'ekwa' ),
						value:      size || 0,
						min:        0,
						max:        160,
						help:       __( '0 = inherit from CSS', 'ekwa' ),
						onChange:   function ( v ) { setAttributes( { size: v || 0 } ); },
						allowReset: true,
					} ),

					/* Color picker */
					el( 'div', { className: 'ekwa-blk-color-wrap' },
						el( 'div', { className: 'ekwa-blk-color-label' },
							el( 'span', null, __( 'Icon Color', 'ekwa' ) ),
							color && el( 'button', {
								type:      'button',
								className: 'ekwa-blk-color-clear',
								onClick:   function () { setAttributes( { color: '' } ); },
							}, __( 'Clear', 'ekwa' ) )
						),
						el( ColorPicker, {
							color:       color || '',
							onChange:    function ( v ) {
								// onChange gives a hex string in WP 5.x; an object in some WP 6.x builds
								var hex = typeof v === 'string' ? v : ( v && ( v.hex || v.color || '' ) );
								setAttributes( { color: hex || '' } );
							},
							enableAlpha: false,
						} )
					),

					el( TextControl, {
					label:                  __( 'Wrapper div class', 'ekwa' ),
					help:                   __( 'CSS class on the wrapping <div>', 'ekwa' ),
					value:                  wrapperClass,
					onChange:               function ( v ) { setAttributes( { wrapperClass: v.trim() } ); },
					__next40pxDefaultSize:  true,
					__nextHasNoMarginBottom: true,
					} )
				)
			),

			/* ---------- Canvas preview ---------- */
			el( 'div', blockProps,
				el( 'div', { className: wrapperClass || 'way-icon' },
					el( 'i', { className: iconClass, style: iconStyle, 'aria-hidden': 'true' } )
				)
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Register block                                                       */
	/* ------------------------------------------------------------------ */
	registerBlockType( 'ekwa/icon', {
		edit: Edit,
		save: function () { return null; }, // server-side render via PHP
	} );

} )( window.wp );
