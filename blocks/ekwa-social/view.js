/**
 * Ekwa Social block — close the share popover on outside click.
 */
(function () {
	'use strict';

	function init() {
		document.addEventListener('click', function (e) {
			if (!e.target.closest('.ekwa-social-icons .addthis')) {
				document.querySelectorAll('.ekwa-social-icons .share-toggle.active')
					.forEach(function (el) { el.classList.remove('active'); });
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
