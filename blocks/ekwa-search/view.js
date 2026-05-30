/**
 * Ekwa Search block — front-end behaviour (overlay open/close).
 */
(function () {
	'use strict';

	function init() {
		var searchOverlay = document.getElementById('ekwa-search-overlay-1');
		var searchInput   = document.getElementById('ekwa-search-input-1');

		function openSearch() {
			if (!searchOverlay || !searchInput) return;
			searchOverlay.classList.add('is-open');
			document.body.style.overflow = 'hidden';
			searchInput.value = '';
			setTimeout(function () { searchInput.focus(); }, 60);
		}

		function closeSearch() {
			if (!searchOverlay) return;
			searchOverlay.classList.remove('is-open');
			document.querySelectorAll('.ekwa-search-trigger').forEach(function (t) {
				t.setAttribute('aria-expanded', 'false');
			});
			document.body.style.overflow = '';
		}

		// Any search trigger opens the shared overlay.
		document.addEventListener('click', function (e) {
			var trigger = e.target.closest('.ekwa-search-trigger');
			if (trigger) {
				e.preventDefault();
				trigger.setAttribute('aria-expanded', 'true');
				openSearch();
			}
		});

		if (searchOverlay) {
			// Close button.
			var closeBtn = searchOverlay.querySelector('.ekwa-search-overlay__close');
			if (closeBtn) closeBtn.addEventListener('click', closeSearch);

			// Backdrop click.
			var bg = searchOverlay.querySelector('.ekwa-search-overlay__bg');
			if (bg) bg.addEventListener('click', closeSearch);
		}

		// Escape key.
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && searchOverlay && searchOverlay.classList.contains('is-open')) {
				closeSearch();
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
