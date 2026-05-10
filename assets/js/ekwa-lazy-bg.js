/*! ekwa-lazy-bg — defer CSS background-image until in viewport */
(function () {
	'use strict';

	var SELECTOR = '.ekwa-lazy-bg[data-bg]';

	function load(el) {
		var url = el.getAttribute('data-bg');
		if (!url) return;
		el.style.backgroundImage = "url('" + url.replace(/'/g, "\\'") + "')";
		el.removeAttribute('data-bg');
		el.classList.add('ekwa-lazy-bg-loaded');
	}

	function init() {
		var nodes = document.querySelectorAll(SELECTOR);
		if (!nodes.length) return;

		// Browsers without IntersectionObserver: load everything immediately.
		if (typeof IntersectionObserver === 'undefined') {
			for (var i = 0; i < nodes.length; i++) load(nodes[i]);
			return;
		}

		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					load(entry.target);
					io.unobserve(entry.target);
				}
			});
		}, { rootMargin: '200px 0px' });

		nodes.forEach(function (el) { io.observe(el); });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
