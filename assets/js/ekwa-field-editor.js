/**
 * Ekwa Custom Field — Block Editor UI.
 *
 * Pick a field from ACF or type a key by hand — both write the same `fieldKey`
 * attribute, so a key that ACF doesn't know about (an importer's meta, a key
 * added later) is never locked out by the dropdown.
 *
 * Server-rendered preview, because the value is per-post. On a page where the
 * field is empty the preview says so explicitly: the front end renders nothing
 * at all there, and an invisible block would be impossible to select again.
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
	var TextControl       = wp.components.TextControl;
	var TextareaControl   = wp.components.TextareaControl;
	var Notice            = wp.components.Notice;
	var ServerSideRender  = wp.serverSideRender;
	var __                = wp.i18n.__;
	var CustomAttrsControl = window.EkwaCustomAttributes && window.EkwaCustomAttributes.Control;

	var cfg     = window.ekwaFieldBlock || {};
	var CHOICES = cfg.choices || [];
	var HAS_ACF = !! cfg.hasAcf;

	var TAG_OPTIONS = [
		{ label: __( '— no wrapper —' ), value: '' },
		{ label: 'div',     value: 'div' },
		{ label: 'span',    value: 'span' },
		{ label: 'p',       value: 'p' },
		{ label: 'h1',      value: 'h1' },
		{ label: 'h2',      value: 'h2' },
		{ label: 'h3',      value: 'h3' },
		{ label: 'h4',      value: 'h4' },
		{ label: 'h5',      value: 'h5' },
		{ label: 'h6',      value: 'h6' },
		{ label: 'section', value: 'section' },
		{ label: 'header',  value: 'header' },
		{ label: 'footer',  value: 'footer' },
		{ label: 'aside',   value: 'aside' },
		{ label: 'article', value: 'article' },
		{ label: 'li',      value: 'li' },
		{ label: 'small',   value: 'small' },
		{ label: 'strong',  value: 'strong' },
		{ label: 'em',      value: 'em' },
		{ label: 'mark',    value: 'mark' },
		{ label: 'time',    value: 'time' },
		{ label: 'label',   value: 'label' },
		{ label: 'figcaption', value: 'figcaption' },
	];

	/** ACF fields as <select> options, grouped by field-group title. */
	function acfOptions( current ) {
		var opts = [ { label: __( '— type a key below —' ), value: '' } ];
		var seenInList = false;

		CHOICES.forEach( function ( field ) {
			opts.push( {
				label: ( field.group ? field.group + ' › ' : '' ) + field.label + ' (' + field.name + ')',
				value: field.name,
			} );
			if ( field.name === current ) { seenInList = true; }
		} );

		// A hand-typed key that ACF doesn't list still has to show as selected,
		// or the dropdown would silently read as "nothing chosen".
		if ( current && ! seenInList ) {
			opts.push( {
				/* translators: %s: custom field key. */
				label: current + ' — ' + __( 'custom key' ),
				value: current,
			} );
		}

		return opts;
	}

	registerBlockType( 'ekwa/field', {
		edit: function ( props ) {
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;
			var fieldKey      = attributes.fieldKey || '';
			var blockProps    = useBlockProps();

			var fieldChildren = [];

			if ( HAS_ACF && CHOICES.length ) {
				fieldChildren.push( el( SelectControl, {
					key: 'acf-pick',
					label: __( 'ACF Field' ),
					value: fieldKey,
					options: acfOptions( fieldKey ),
					onChange: function ( val ) { setAttributes( { fieldKey: val } ); },
				} ) );
			} else if ( HAS_ACF ) {
				fieldChildren.push( el( Notice, {
					key: 'acf-empty', status: 'info', isDismissible: false,
					style: { marginBottom: '16px' },
				}, __( 'ACF is active but has no top-level fields yet. Type a key below — repeater and group sub-fields are not listed, because they cannot be read by name outside their loop.' ) ) );
			}

			fieldChildren.push( el( TextControl, {
				key: 'field-key',
				label: __( 'Field Key' ),
				value: fieldKey,
				placeholder: 'extra_title',
				help: __( 'The field name / meta key. Works with or without ACF.' ),
				onChange: function ( val ) { setAttributes( { fieldKey: val } ); },
			} ) );

			fieldChildren.push( el( SelectControl, {
				key: 'source',
				label: __( 'Read From' ),
				value: attributes.source || 'auto',
				options: [
					{ label: __( 'Auto — ACF, then post meta' ), value: 'auto' },
					{ label: __( 'ACF only' ),                   value: 'acf' },
					{ label: __( 'Post meta only' ),             value: 'meta' },
				],
				onChange: function ( val ) { setAttributes( { source: val } ); },
			} ) );

			fieldChildren.push( el( SelectControl, {
				key: 'post-source',
				label: __( 'From Which Post' ),
				value: attributes.postSource || 'current',
				options: [
					{ label: __( 'The page being viewed' ), value: 'current' },
					{ label: __( 'A specific post ID' ),    value: 'specific' },
				],
				help: __( 'Leave on "the page being viewed" when this block sits in a header, footer or other shared template.' ),
				onChange: function ( val ) { setAttributes( { postSource: val } ); },
			} ) );

			if ( 'specific' === attributes.postSource ) {
				fieldChildren.push( el( TextControl, {
					key: 'post-id',
					label: __( 'Post ID' ),
					type: 'number',
					value: attributes.postId || '',
					onChange: function ( val ) { setAttributes( { postId: parseInt( val, 10 ) || 0 } ); },
				} ) );
			}

			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Field' ), initialOpen: true }, fieldChildren ),
					el( PanelBody, { title: __( 'Output' ), initialOpen: false },
						el( SelectControl, {
							label: __( 'Wrapper Tag' ),
							value: undefined === attributes.tagName ? 'div' : attributes.tagName,
							options: TAG_OPTIONS,
							help: __( 'On pages where the field is empty, nothing is output — this wrapper included.' ),
							onChange: function ( val ) { setAttributes( { tagName: val } ); },
						} ),
						el( SelectControl, {
							label: __( 'Value Format' ),
							value: attributes.format || 'text',
							options: [
								{ label: __( 'Plain text (escaped)' ),  value: 'text' },
								{ label: __( 'HTML' ),                  value: 'html' },
								{ label: __( 'HTML + run shortcodes' ), value: 'shortcode' },
							],
							help: __( 'HTML modes are filtered through wp_kses_post, so scripts and event handlers are stripped either way.' ),
							onChange: function ( val ) { setAttributes( { format: val } ); },
						} ),
						el( TextControl, {
							label: __( 'Before' ),
							value: attributes.before || '',
							help: __( 'Printed in front of the value — and only when there is one.' ),
							onChange: function ( val ) { setAttributes( { before: val } ); },
						} ),
						el( TextControl, {
							label: __( 'After' ),
							value: attributes.after || '',
							onChange: function ( val ) { setAttributes( { after: val } ); },
						} ),
						el( TextareaControl, {
							label: __( 'Inline Style' ),
							value: attributes.inlineStyle || '',
							rows: 2,
							onChange: function ( val ) { setAttributes( { inlineStyle: val } ); },
						} )
					),
					CustomAttrsControl
						? el( CustomAttrsControl, { attributes: attributes, setAttributes: setAttributes } )
						: null
				),
				el( 'div', blockProps,
					el( ServerSideRender, {
						block: 'ekwa/field',
						attributes: attributes,
					} )
				)
			);
		},

		save: function () { return null; },
	} );
} )( window.wp );
