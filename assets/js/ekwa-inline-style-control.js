/**
 * Ekwa Inline Style — shared Inspector control.
 *
 * Exposes window.EkwaInlineStyle:
 *   .Control( { attributes, setAttributes, label, help } )  → a TextareaControl
 *      bound to the block's `inlineStyle` string attribute. Returns a plain
 *      control (not a PanelBody) so blocks can drop it into an existing panel.
 *   .decode( css )   → undo the kses escaping WordPress applies on save.
 *   .parse( css )    → CSS text → React style object, for editor previews.
 *
 * The converter fills `inlineStyle` from the mockup's own style attribute
 * (ekwa_mc_style_attr) and the PHP renderers print it back via
 * ekwa_render_inline_style_attr() — this control is where an author edits it
 * afterwards. ekwa/div predates this module and keeps its own copy of the
 * field inside its Element Settings panel.
 */
( function ( wp ) {
	'use strict';

	var el              = wp.element.createElement;
	var TextareaControl = wp.components.TextareaControl;
	var __              = wp.i18n.__;

	/**
	 * Undo the HTML escaping WordPress applies to string block attributes on
	 * save (core's filter_block_kses_value) for anyone without the
	 * `unfiltered_html` capability. kses is HTML-aware, not CSS-aware, so a
	 * `&` inside url(a.png?x=1&y=2) comes back as `&amp;` and the value breaks.
	 * Mirrors ekwa_css_decode_entities() on the PHP side.
	 */
	function decode( css ) {
		if ( ! css || css.indexOf( '&' ) === -1 ) { return css || ''; }
		return css
			.replace( /&gt;/g, '>' )
			.replace( /&lt;/g, '<' )
			.replace( /&quot;/g, '"' )
			.replace( /&#0?39;/g, "'" )
			.replace( /&amp;/g, '&' ); // last, or "&amp;gt;" would collapse to ">"
	}

	/**
	 * "border-radius: 6px; color: white" → { borderRadius: '6px', color: 'white' }
	 * so the editor preview can show what the front end will render.
	 */
	function parse( str ) {
		if ( ! str ) { return {}; }
		var style = {};
		decode( str ).split( ';' ).forEach( function ( part ) {
			part = part.trim();
			if ( ! part ) { return; }
			var colon = part.indexOf( ':' );
			if ( colon < 1 ) { return; }
			var key = part.substring( 0, colon ).trim();
			var val = part.substring( colon + 1 ).trim();
			// Custom properties (--x) must keep their literal name; everything
			// else becomes camelCase for React.
			if ( key.indexOf( '--' ) !== 0 ) {
				key = key.replace( /-([a-z])/g, function ( m, c ) { return c.toUpperCase(); } );
			}
			style[ key ] = val;
		} );
		return style;
	}

	function Control( props ) {
		var attributes    = props.attributes || {};
		var setAttributes = props.setAttributes;

		return el( TextareaControl, {
			label: props.label || __( 'Inline Style', 'ekwa' ),
			help:  props.help  || __( 'Additional raw CSS properties, e.g. border-radius: 6px.', 'ekwa' ),
			value: attributes.inlineStyle || '',
			rows:  props.rows || 2,
			onChange: function ( val ) { setAttributes( { inlineStyle: val } ); },
			__nextHasNoMarginBottom: true,
		} );
	}

	window.EkwaInlineStyle = window.EkwaInlineStyle || {};
	window.EkwaInlineStyle.Control = Control;
	window.EkwaInlineStyle.decode  = decode;
	window.EkwaInlineStyle.parse   = parse;

} )( window.wp );
