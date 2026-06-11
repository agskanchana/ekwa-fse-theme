/**
 * Ekwa Social block — close the share popover on outside click or Escape.
 */
(function () {
	'use strict';

	function closeOpenPopovers(restoreFocus) {
		var open = document.querySelectorAll('.ekwa-social-icons .share-toggle.active');
		open.forEach(function (el) {
			el.classList.remove('active');
			if (restoreFocus) {
				var btn = el.closest('.addthis');
				if (btn) btn.focus();
			}
		});
		return open.length > 0;
	}

	function init() {
		document.addEventListener('click', function (e) {
			if (!e.target.closest('.ekwa-social-icons .addthis')) {
				closeOpenPopovers(false);
			}
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' || e.key === 'Esc') {
				closeOpenPopovers(true);
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
