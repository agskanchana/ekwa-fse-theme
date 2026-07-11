/**
 * Ekwa Design Overlay — pixel-perfect reference overlay for the block editor.
 *
 * Superimposes an exported design (e.g. an Adobe XD artboard PNG) over the
 * editor at adjustable opacity, width and offset so you can build to match it
 * exactly. The overlay is pointer-events:none (never blocks editing) and its
 * settings persist in localStorage. Editor-only — nothing ships to the front
 * end. A "difference" blend mode is included for the classic pixel-diff check
 * (matching pixels render black).
 */
( function ( wp ) {
	'use strict';

	var el             = wp.element.createElement;
	var Fragment       = wp.element.Fragment;
	var useState       = wp.element.useState;
	var useEffect      = wp.element.useEffect;
	var createPortal   = wp.element.createPortal;
	var registerPlugin = wp.plugins.registerPlugin;
	var MediaUpload      = wp.blockEditor.MediaUpload;
	var MediaUploadCheck = wp.blockEditor.MediaUploadCheck;
	var PanelBody      = wp.components.PanelBody;
	var RangeControl   = wp.components.RangeControl;
	var ToggleControl  = wp.components.ToggleControl;
	var Button         = wp.components.Button;
	var __             = wp.i18n.__;

	var editor = wp.editor || wp.editPost || {};
	var PluginSidebar             = editor.PluginSidebar;
	var PluginSidebarMoreMenuItem = editor.PluginSidebarMoreMenuItem;

	if ( ! PluginSidebar ) {
		return; // Editor build too old — fail quietly.
	}

	var STORE_KEY = 'ekwaDesignOverlay';

	function loadState() {
		try {
			var raw = window.localStorage.getItem( STORE_KEY );
			if ( raw ) { return JSON.parse( raw ); }
		} catch ( e ) { /* ignore */ }
		return {};
	}

	function DesignOverlay() {
		var saved = loadState();

		var s = useState( saved.url || '' );        var url      = s[0];      var setUrl      = s[1];
		var o = useState( saved.opacity != null ? saved.opacity : 50 ); var opacity = o[0]; var setOpacity = o[1];
		var w = useState( saved.width || 1280 );     var width    = w[0];      var setWidth    = w[1];
		var x = useState( saved.x || 0 );            var offsetX  = x[0];      var setOffsetX  = x[1];
		var y = useState( saved.y || 0 );            var offsetY  = y[0];      var setOffsetY  = y[1];
		var v = useState( saved.visible !== false ); var visible  = v[0];      var setVisible  = v[1];
		var d = useState( !! saved.diff );           var diff     = d[0];      var setDiff     = d[1];

		// Persist on every change.
		useEffect( function () {
			try {
				window.localStorage.setItem( STORE_KEY, JSON.stringify( {
					url: url, opacity: opacity, width: width, x: offsetX, y: offsetY, visible: visible, diff: diff,
				} ) );
			} catch ( e ) { /* ignore */ }
		}, [ url, opacity, width, offsetX, offsetY, visible, diff ] );

		// The fixed overlay, portalled to <body> so it floats above the canvas.
		var overlayNode = ( url && visible )
			? createPortal(
				el( 'img', {
					src: url,
					alt: '',
					className: 'ekwa-design-overlay-img',
					style: {
						position: 'fixed',
						top: offsetY + 'px',
						left: offsetX + 'px',
						width: width + 'px',
						height: 'auto',
						opacity: opacity / 100,
						pointerEvents: 'none',
						zIndex: 9998,
						mixBlendMode: diff ? 'difference' : 'normal',
					},
				} ),
				document.body
			)
			: null;

		var panel = el( PanelBody, { title: __( 'Reference image', 'ekwa' ), initialOpen: true },
			el( MediaUploadCheck, null,
				el( MediaUpload, {
					onSelect: function ( m ) { setUrl( m.url ); },
					allowedTypes: [ 'image' ],
					render: function ( obj ) {
						return el( Button, { variant: 'secondary', onClick: obj.open },
							url ? __( 'Replace image', 'ekwa' ) : __( 'Select design export…', 'ekwa' )
						);
					},
				} )
			),
			url ? el( Button, {
				variant: 'tertiary',
				isDestructive: true,
				style: { marginTop: '8px' },
				onClick: function () { setUrl( '' ); },
			}, __( 'Remove', 'ekwa' ) ) : null,
			url ? el( 'p', { style: { fontSize: '12px', color: '#757575', marginTop: '8px' } },
				__( 'Export the artboard from Adobe XD as PNG at 1× and align the top-left. The overlay never blocks clicks.', 'ekwa' )
			) : null
		);

		var controls = url ? el( PanelBody, { title: __( 'Overlay controls', 'ekwa' ), initialOpen: true },
			el( ToggleControl, {
				label: __( 'Show overlay', 'ekwa' ),
				checked: visible,
				onChange: setVisible,
			} ),
			el( ToggleControl, {
				label: __( 'Difference blend (pixel diff)', 'ekwa' ),
				help: __( 'Matching pixels turn black — nudge until it goes dark.', 'ekwa' ),
				checked: diff,
				onChange: setDiff,
			} ),
			el( RangeControl, {
				label: __( 'Opacity', 'ekwa' ),
				value: opacity, min: 0, max: 100,
				onChange: function ( val ) { setOpacity( val == null ? 50 : val ); },
			} ),
			el( RangeControl, {
				label: __( 'Width (px)', 'ekwa' ),
				value: width, min: 320, max: 2560, step: 10,
				onChange: function ( val ) { setWidth( val == null ? 1280 : val ); },
			} ),
			el( RangeControl, {
				label: __( 'Horizontal offset (px)', 'ekwa' ),
				value: offsetX, min: -400, max: 1200, step: 1,
				onChange: function ( val ) { setOffsetX( val == null ? 0 : val ); },
			} ),
			el( RangeControl, {
				label: __( 'Vertical offset (px)', 'ekwa' ),
				value: offsetY, min: -2000, max: 2000, step: 1,
				onChange: function ( val ) { setOffsetY( val == null ? 0 : val ); },
			} )
		) : null;

		return el( Fragment, null,
			PluginSidebarMoreMenuItem
				? el( PluginSidebarMoreMenuItem, { target: 'ekwa-design-overlay', icon: 'visibility' },
					__( 'Design overlay', 'ekwa' ) )
				: null,
			el( PluginSidebar, {
				name: 'ekwa-design-overlay',
				icon: 'visibility',
				title: __( 'Design overlay', 'ekwa' ),
			}, panel, controls ),
			overlayNode
		);
	}

	registerPlugin( 'ekwa-design-overlay', { render: DesignOverlay } );
} )( window.wp );
