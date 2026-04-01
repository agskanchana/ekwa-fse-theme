(function (wp) {
	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var RangeControl = wp.components.RangeControl;
	var ToggleControl = wp.components.ToggleControl;
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;

	registerBlockType('ekwa/inner-banner', {
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
						{ title: __('Banner Settings', 'ekwa') },
						el(RangeControl, {
							label: __('Overlay Opacity (%)', 'ekwa'),
							value: attrs.overlayOpacity,
							onChange: function (val) { props.setAttributes({ overlayOpacity: val }); },
							min: 0,
							max: 100,
						}),
						el(RangeControl, {
							label: __('Min Height (px)', 'ekwa'),
							value: attrs.minHeight,
							onChange: function (val) { props.setAttributes({ minHeight: val }); },
							min: 100,
							max: 500,
						}),
						el(ToggleControl, {
							label: __('Show Breadcrumbs', 'ekwa'),
							checked: attrs.showBreadcrumbs,
							onChange: function (val) { props.setAttributes({ showBreadcrumbs: val }); },
						})
					)
				),
				el(ServerSideRender, {
					block: 'ekwa/inner-banner',
					attributes: attrs,
				})
			);
		},
		save: function () { return null; },
	});
})(window.wp);
