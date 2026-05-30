/**
 * Ekwa Address dropdown block — toggle panel, smart right-edge positioning,
 * outside-click and Escape to close.
 */
(function () {
	'use strict';

	function closeAllAddr() {
		document.querySelectorAll('.ekwa-addr-dd.is-open').forEach(function (el) {
			el.classList.remove('is-open');
			var t = el.querySelector('.ekwa-addr-dd__trigger');
			if (t) t.setAttribute('aria-expanded', 'false');
		});
	}

	function closeAllPhone() {
		document.querySelectorAll('.ekwa-phone-dd.is-open').forEach(function (el) {
			el.classList.remove('is-open');
			var t = el.querySelector('.ekwa-phone-dd__trigger');
			if (t) t.setAttribute('aria-expanded', 'false');
		});
	}

	function positionAddrPanel(dd) {
		var panel = dd.querySelector('.ekwa-addr-dd__panel');
		if (!panel) return;

		// Reset positioning before measuring.
		panel.classList.remove('ekwa-addr-dd__panel--right');

		// Measure after a frame so the panel is visible for getBoundingClientRect.
		requestAnimationFrame(function () {
			var rect = panel.getBoundingClientRect();
			var vw   = window.innerWidth || document.documentElement.clientWidth;

			// If panel overflows right edge, flip to right-aligned.
			if (rect.right > vw - 8) {
				panel.classList.add('ekwa-addr-dd__panel--right');
			}
		});
	}

	function init() {
		document.addEventListener('click', function (e) {
			var trigger = e.target.closest('.ekwa-addr-dd__trigger');

			if (trigger) {
				var dd = trigger.closest('.ekwa-addr-dd');
				if (!dd) return;

				var isOpen = dd.classList.contains('is-open');

				// Close all open dropdowns (address + phone).
				closeAllAddr();
				closeAllPhone();

				// Toggle this one.
				if (!isOpen) {
					dd.classList.add('is-open');
					trigger.setAttribute('aria-expanded', 'true');
					positionAddrPanel(dd);
				}
				return;
			}

			// Click outside → close all.
			if (!e.target.closest('.ekwa-addr-dd')) {
				closeAllAddr();
			}
		});

		// Escape key closes open address dropdowns.
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				closeAllAddr();
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
