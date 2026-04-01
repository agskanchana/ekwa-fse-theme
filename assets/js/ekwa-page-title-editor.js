(function (wp) {
	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;

	registerBlockType('ekwa/page-title', {
		edit: function (props) {
			return el(
				'div',
				{ className: props.className },
				el(ServerSideRender, {
					block: 'ekwa/page-title',
					attributes: props.attributes,
				})
			);
		},
		save: function () { return null; },
	});
})(window.wp);
