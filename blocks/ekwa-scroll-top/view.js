/**
 * Ekwa Scroll-to-top block — show on scroll past threshold, smooth scroll up.
 */
(function () {
	'use strict';

	function init() {
		document.querySelectorAll('.ekwa-scroll-top-btn').forEach(function (btn) {
			var threshold = parseInt(btn.getAttribute('data-threshold'), 10) || 300;

			function toggle() {
				if (window.scrollY > threshold) {
					btn.classList.add('is-visible');
				} else {
					btn.classList.remove('is-visible');
				}
			}

			window.addEventListener('scroll', toggle, { passive: true });
			toggle();

			btn.addEventListener('click', function () {
				window.scrollTo({ top: 0, behavior: 'smooth' });
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
