(function (wp) {
	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;

	registerBlockType('ekwa/phone-dropdown', {
		edit: function (props) {
			var attrs = props.attributes;

			return el(
				'div',
				{ className: props.className },
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __('Settings', 'ekwa') },
						el(TextControl, {
							label: __('Button Label', 'ekwa'),
							value: attrs.label,
							onChange: function (val) {
								props.setAttributes({ label: val });
							},
						}),
						el(TextControl, {
							label: __('Icon Class', 'ekwa'),
							value: attrs.iconClass,
							onChange: function (val) {
								props.setAttributes({ iconClass: val });
							},
							help: __('Font Awesome class, e.g. fa-solid fa-phone', 'ekwa'),
						}),
						el(ToggleControl, {
							label: __('Show Icon', 'ekwa'),
							checked: attrs.showIcon,
							onChange: function (val) {
								props.setAttributes({ showIcon: val });
							},
						})
					)
				),
				el(ServerSideRender, {
					block: 'ekwa/phone-dropdown',
					attributes: attrs,
				})
			);
		},

		save: function () {
			return null;
		},
	});
})(window.wp);
