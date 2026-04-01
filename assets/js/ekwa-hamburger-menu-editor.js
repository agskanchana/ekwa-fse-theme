(function (wp) {
	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var RangeControl = wp.components.RangeControl;
	var __ = wp.i18n.__;

	registerBlockType('ekwa/hamburger-menu', {
		edit: function (props) {
			var iconSize = props.attributes.iconSize;

			var barStyle = {
				display: 'block',
				width: iconSize + 'px',
				height: Math.max(2, Math.round(iconSize / 8)) + 'px',
				background: 'currentColor',
				borderRadius: '2px',
			};

			return el(
				'div',
				{ className: props.className },
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __('Menu Settings', 'ekwa') },
						el(RangeControl, {
							label: __('Icon Size', 'ekwa'),
							value: iconSize,
							onChange: function (val) {
								props.setAttributes({ iconSize: val });
							},
							min: 16,
							max: 40,
						})
					)
				),
				el(
					'button',
					{
						className: 'ekwa-hamburger-btn',
						style: {
							display: 'inline-flex',
							flexDirection: 'column',
							alignItems: 'center',
							justifyContent: 'center',
							gap: Math.max(3, Math.round(iconSize / 5)) + 'px',
							padding: '8px',
							background: 'none',
							border: 'none',
							cursor: 'pointer',
							color: 'inherit',
						},
						onClick: function (e) {
							e.preventDefault();
						},
					},
					el('span', { style: barStyle }),
					el('span', { style: barStyle }),
					el('span', { style: barStyle })
				)
			);
		},

		save: function () {
			return null; // Server-side rendered.
		},
	});
})(window.wp);
