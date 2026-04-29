(function (wp) {
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = (wp.blockEditor || wp.editor).InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextareaControl = wp.components.TextareaControl;
	var __ = wp.i18n.__;

	var iconSlots = [
		{ key: 'iconCall',     label: __( 'Call icon (SVG)', 'ekwa' ) },
		{ key: 'iconBook',     label: __( 'Book icon (SVG)', 'ekwa' ) },
		{ key: 'iconUp',       label: __( 'Scroll-Up icon (SVG)', 'ekwa' ) },
		{ key: 'iconServices', label: __( 'Services icon (SVG)', 'ekwa' ) },
		{ key: 'iconFindUs',   label: __( 'Find Us icon (SVG)', 'ekwa' ) }
	];

	registerBlockType('ekwa/mobile-dock', {
		edit: function (props) {
			var attrs = props.attributes || {};

			var inspector = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{
						title: __( 'Custom Icons', 'ekwa' ),
						initialOpen: false
					},
					el(
						'p',
						{ style: { fontSize: '12px', color: '#555', marginTop: 0 } },
						__( 'Paste any SVG. Leave empty to use the default. Use stroke="currentColor" or fill="currentColor" so the icon inherits the dock color.', 'ekwa' )
					),
					iconSlots.map( function ( slot ) {
						var value = attrs[ slot.key ] || '';
						var preview = null;
						if ( value && /<svg/i.test( value ) ) {
							preview = el( 'div', {
								style: {
									width: '32px',
									height: '32px',
									marginTop: '6px',
									color: '#1a6ef5',
									border: '1px solid #ddd',
									borderRadius: '4px',
									display: 'flex',
									alignItems: 'center',
									justifyContent: 'center'
								},
								dangerouslySetInnerHTML: { __html: value }
							} );
						}
						return el(
							'div',
							{ key: slot.key, style: { marginBottom: '14px' } },
							el( TextareaControl, {
								label: slot.label,
								value: value,
								rows: 4,
								onChange: function ( v ) {
									var update = {};
									update[ slot.key ] = v;
									props.setAttributes( update );
								}
							} ),
							preview
						);
					} )
				)
			);

			var preview = el(
				'div',
				{ className: props.className },
				el(
					'div',
					{
						style: {
							display: 'flex',
							alignItems: 'center',
							gap: '6px',
							padding: '10px 16px',
							background: 'rgba(255,255,255,0.85)',
							borderRadius: '24px',
							boxShadow: '0 8px 32px rgba(0,0,0,0.12), 0 2px 8px rgba(0,0,0,0.08)',
							border: '1px solid rgba(0,0,0,0.06)',
							width: 'fit-content',
							margin: '20px auto',
						},
					},
					el('span', { style: { padding: '10px 14px', textAlign: 'center', fontSize: '11px' } }, '📞 Call'),
					el('span', { style: { padding: '10px 14px', textAlign: 'center', fontSize: '11px' } }, '📅 Book'),
					el('span', {
						style: {
							width: '40px',
							height: '40px',
							borderRadius: '50%',
							background: 'var(--wp--preset--color--primary, #2563eb)',
							display: 'inline-flex',
							alignItems: 'center',
							justifyContent: 'center',
							color: '#fff',
							fontSize: '16px',
						},
					}, '↑'),
					el('span', { style: { padding: '10px 14px', textAlign: 'center', fontSize: '11px' } }, '✚ Services'),
					el('span', { style: { padding: '10px 14px', textAlign: 'center', fontSize: '11px' } }, '📍 Find Us')
				),
				el(
					'p',
					{
						style: {
							textAlign: 'center',
							fontSize: '12px',
							color: '#666',
							marginTop: '8px',
						},
					},
					__('Mobile Dock — visible only on screens < 1200px', 'ekwa')
				)
			);

			return el( Fragment, null, inspector, preview );
		},

		save: function () {
			return null;
		},
	});
})(window.wp);
