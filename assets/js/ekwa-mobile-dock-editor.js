(function (wp) {
	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;

	registerBlockType('ekwa/mobile-dock', {
		edit: function (props) {
			return el(
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
		},

		save: function () {
			return null;
		},
	});
})(window.wp);
