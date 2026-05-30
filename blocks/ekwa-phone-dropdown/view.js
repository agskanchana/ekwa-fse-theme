/**
 * Ekwa Phone dropdown block — same pattern as the address dropdown.
 */
(function () {
	'use strict';

	function closeAllPhone() {
		document.querySelectorAll('.ekwa-phone-dd.is-open').forEach(function (el) {
			el.classList.remove('is-open');
			var t = el.querySelector('.ekwa-phone-dd__trigger');
			if (t) t.setAttribute('aria-expanded', 'false');
		});
	}

	function closeAllAddr() {
		document.querySelectorAll('.ekwa-addr-dd.is-open').forEach(function (el) {
			el.classList.remove('is-open');
			var t = el.querySelector('.ekwa-addr-dd__trigger');
			if (t) t.setAttribute('aria-expanded', 'false');
		});
	}

	function positionPhonePanel(dd) {
		var panel = dd.querySelector('.ekwa-phone-dd__panel');
		if (!panel) return;
		panel.classList.remove('ekwa-phone-dd__panel--right');
		requestAnimationFrame(function () {
			var rect = panel.getBoundingClientRect();
			var vw   = window.innerWidth || document.documentElement.clientWidth;
			if (rect.right > vw - 8) {
				panel.classList.add('ekwa-phone-dd__panel--right');
			}
		});
	}

	function init() {
		document.addEventListener('click', function (e) {
			var trigger = e.target.closest('.ekwa-phone-dd__trigger');

			if (trigger) {
				var dd = trigger.closest('.ekwa-phone-dd');
				if (!dd) return;
				var isOpen = dd.classList.contains('is-open');

				// Close all open phone dropdowns, and any open address dropdowns.
				closeAllPhone();
				closeAllAddr();

				if (!isOpen) {
					dd.classList.add('is-open');
					trigger.setAttribute('aria-expanded', 'true');
					positionPhonePanel(dd);
				}
				return;
			}

			// Click outside → close all phone dropdowns.
			if (!e.target.closest('.ekwa-phone-dd')) {
				closeAllPhone();
			}
		});

		// Escape key closes open phone dropdowns.
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				closeAllPhone();
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
