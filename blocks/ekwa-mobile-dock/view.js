/**
 * Ekwa Mobile dock block — scroll-up, services drawer, auto-hide, popups.
 *
 * The services button opens the mmenu services drawer exposed by the
 * hamburger-menu block (window.ekwaServicesDrawer), falling back to a click on
 * the hamburger button.
 */
(function () {
	'use strict';

	function init() {
		var prefersReducedMotion = window.matchMedia &&
			window.matchMedia('(prefers-reduced-motion: reduce)').matches;

		// Scroll up.
		document.addEventListener('click', function (e) {
			if (e.target.closest('.ekwa-mobile-dock .scroll-up-item')) {
				window.scrollTo({
					top: 0,
					behavior: prefersReducedMotion ? 'auto' : 'smooth'
				});
			}
		});

		// Services → open Mobile Services drawer (or fall back to hamburger).
		document.addEventListener('click', function (e) {
			if (!e.target.closest('.ekwa-mobile-dock .services-item')) return;
			if (window.ekwaServicesDrawer) { window.ekwaServicesDrawer.open(); return; }
			var hBtn = document.querySelector('.ekwa-hamburger-btn');
			if (hBtn) hBtn.click();
		});

		// Auto-hide dock on scroll down, show on scroll up.
		(function () {
			var dock = document.querySelector('.ekwa-mobile-dock');
			if (!dock || prefersReducedMotion) return;

			var lastY = window.scrollY || 0;
			var ticking = false;

			window.addEventListener('scroll', function () {
				if (ticking) return;
				ticking = true;
				window.requestAnimationFrame(function () {
					var y = window.scrollY || 0;
					var delta = y - lastY;
					if (y > 100 && delta > 4) {
						dock.classList.add('ekwa-dock-hidden');
					} else if (delta < -4 || y <= 100) {
						dock.classList.remove('ekwa-dock-hidden');
					}
					lastY = y;
					ticking = false;
				});
			}, { passive: true });
		})();

		// Popup open (data-popup attribute).
		document.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-popup]');
			if (btn) {
				var popup = document.getElementById(btn.getAttribute('data-popup'));
				if (popup) {
					popup.classList.add('active');
					document.body.style.overflow = 'hidden';
				}
			}
		});

		// Popup close button.
		document.addEventListener('click', function (e) {
			var closeBtn = e.target.closest('.ekwa-dock-popup .popup-close');
			if (closeBtn) {
				var popup = closeBtn.closest('.ekwa-dock-popup');
				if (popup) {
					popup.classList.remove('active');
					document.body.style.overflow = '';
				}
			}
		});

		// Popup backdrop click.
		document.addEventListener('click', function (e) {
			if (e.target.classList && e.target.classList.contains('ekwa-dock-popup')) {
				e.target.classList.remove('active');
				document.body.style.overflow = '';
			}
		});

		// Accordion toggle.
		document.addEventListener('click', function (e) {
			var header = e.target.closest('.ekwa-dock-popup .accordion-header');
			if (!header) return;

			var popup  = header.closest('.ekwa-dock-popup');
			var tid    = header.getAttribute('data-accordion');
			var tbody  = document.getElementById(tid);
			var active = header.classList.contains('active');

			// Close all in this popup.
			popup.querySelectorAll('.accordion-header').forEach(function (h) { h.classList.remove('active'); });
			popup.querySelectorAll('.accordion-body').forEach(function (b) { b.classList.remove('active'); });

			// Open clicked if it wasn't already active.
			if (!active) {
				header.classList.add('active');
				if (tbody) tbody.classList.add('active');
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
