/**
 * Ekwa Custom Blocks — shared front-end JavaScript.
 *
 * Covers: search, hamburger-menu, mobile-dock, scroll-top, social.
 * Uses event delegation so no per-instance inline scripts are needed.
 *
 * @package ekwa
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {

		/* =============================================================
		   Search block
		   ============================================================= */
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

		/* =============================================================
		   Hamburger-menu block (mmenu-light)
		   ============================================================= */
		var mmenuNav = document.getElementById('ekwa-mobile-nav');
		var mmenuDrawer = null;

		if (mmenuNav && typeof MmenuLight !== 'undefined') {
			var m = new MmenuLight(mmenuNav);
			m.navigation({ title: 'Menu' });
			mmenuDrawer = m.offcanvas({ position: 'left' });
		}

		document.addEventListener('click', function (e) {
			var btn = e.target.closest('.ekwa-hamburger-btn');
			if (btn && mmenuDrawer) {
				e.preventDefault();
				mmenuDrawer.open();
			}
		});

		/* =============================================================
		   Mobile dock block
		   ============================================================= */

		// Scroll up.
		document.addEventListener('click', function (e) {
			if (e.target.closest('.ekwa-mobile-dock .scroll-up-item')) {
				window.scrollTo({ top: 0, behavior: 'smooth' });
			}
		});

		// Services → open mobile menu.
		document.addEventListener('click', function (e) {
			if (e.target.closest('.ekwa-mobile-dock .services-item')) {
				var hBtn = document.querySelector('.ekwa-hamburger-btn');
				if (hBtn) hBtn.click();
			}
		});

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

		/* =============================================================
		   Scroll-to-top block
		   ============================================================= */
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

		/* =============================================================
		   Social block — close share popover on outside click
		   ============================================================= */
		document.addEventListener('click', function (e) {
			if (!e.target.closest('.ekwa-social-icons .addthis')) {
				document.querySelectorAll('.ekwa-social-icons .share-toggle.active')
					.forEach(function (el) { el.classList.remove('active'); });
			}
		});

		/* =============================================================
		   Address dropdown block
		   ============================================================= */
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

		document.addEventListener('click', function (e) {
			var trigger = e.target.closest('.ekwa-addr-dd__trigger');

			if (trigger) {
				var dd = trigger.closest('.ekwa-addr-dd');
				if (!dd) return;

				var isOpen = dd.classList.contains('is-open');

				// Close all open dropdowns (address + phone).
				document.querySelectorAll('.ekwa-addr-dd.is-open').forEach(function (el) {
					el.classList.remove('is-open');
					el.querySelector('.ekwa-addr-dd__trigger').setAttribute('aria-expanded', 'false');
				});
				document.querySelectorAll('.ekwa-phone-dd.is-open').forEach(function (el) {
					el.classList.remove('is-open');
					el.querySelector('.ekwa-phone-dd__trigger').setAttribute('aria-expanded', 'false');
				});

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
				document.querySelectorAll('.ekwa-addr-dd.is-open').forEach(function (el) {
					el.classList.remove('is-open');
					el.querySelector('.ekwa-addr-dd__trigger').setAttribute('aria-expanded', 'false');
				});
			}
		});

		/* =============================================================
		   Phone dropdown block (same pattern as address dropdown)
		   ============================================================= */
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

		document.addEventListener('click', function (e) {
			var trigger = e.target.closest('.ekwa-phone-dd__trigger');

			if (trigger) {
				var dd = trigger.closest('.ekwa-phone-dd');
				if (!dd) return;
				var isOpen = dd.classList.contains('is-open');

				// Close all open phone dropdowns.
				document.querySelectorAll('.ekwa-phone-dd.is-open').forEach(function (el) {
					el.classList.remove('is-open');
					el.querySelector('.ekwa-phone-dd__trigger').setAttribute('aria-expanded', 'false');
				});
				// Also close any open address dropdowns.
				document.querySelectorAll('.ekwa-addr-dd.is-open').forEach(function (el) {
					el.classList.remove('is-open');
					el.querySelector('.ekwa-addr-dd__trigger').setAttribute('aria-expanded', 'false');
				});

				if (!isOpen) {
					dd.classList.add('is-open');
					trigger.setAttribute('aria-expanded', 'true');
					positionPhonePanel(dd);
				}
				return;
			}

			// Click outside → close all phone dropdowns.
			if (!e.target.closest('.ekwa-phone-dd')) {
				document.querySelectorAll('.ekwa-phone-dd.is-open').forEach(function (el) {
					el.classList.remove('is-open');
					el.querySelector('.ekwa-phone-dd__trigger').setAttribute('aria-expanded', 'false');
				});
			}
		});

		// Escape key closes all dropdowns (address + phone).
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				document.querySelectorAll('.ekwa-addr-dd.is-open').forEach(function (el) {
					el.classList.remove('is-open');
					el.querySelector('.ekwa-addr-dd__trigger').setAttribute('aria-expanded', 'false');
				});
				document.querySelectorAll('.ekwa-phone-dd.is-open').forEach(function (el) {
					el.classList.remove('is-open');
					el.querySelector('.ekwa-phone-dd__trigger').setAttribute('aria-expanded', 'false');
				});
			}
		});

	}); // DOMContentLoaded
})();
