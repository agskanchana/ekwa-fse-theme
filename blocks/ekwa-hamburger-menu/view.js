/**
 * Ekwa Hamburger-menu block — mmenu-light drawers (main + mobile services).
 *
 * Exposes the created drawers on window.ekwaMmenuDrawer / window.ekwaServicesDrawer
 * so the mobile-dock block can open the services drawer without sharing scope.
 */
(function () {
	'use strict';

	/**
	 * Inject a close button into an mmenu-light offcanvas drawer.
	 * Positioned top-right inside the panel content; click closes the drawer.
	 */
	function addMmenuCloseButton(drawer, label) {
		if (!drawer || !drawer.content) return;
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'ekwa-mmenu-close';
		btn.setAttribute('aria-label', label || 'Close');
		btn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>';
		btn.addEventListener('click', function () { drawer.close(); });
		drawer.content.appendChild(btn);
	}

	function init() {
		var mmenuNav = document.getElementById('ekwa-mobile-nav');
		var mmenuDrawer = null;

		if (mmenuNav && typeof MmenuLight !== 'undefined') {
			var m = new MmenuLight(mmenuNav);
			m.navigation({ title: 'Menu' });
			mmenuDrawer = m.offcanvas({ position: 'left' });
			addMmenuCloseButton(mmenuDrawer, 'Close menu');
		}

		document.addEventListener('click', function (e) {
			var btn = e.target.closest('.ekwa-hamburger-btn');
			if (btn && mmenuDrawer) {
				e.preventDefault();
				mmenuDrawer.open();
			}
		});

		// Mobile Services drawer (mmenu-light, second instance).
		var servicesNav = document.getElementById('ekwa-mobile-services-nav');
		var servicesDrawer = null;

		if (servicesNav && typeof MmenuLight !== 'undefined') {
			var ms = new MmenuLight(servicesNav);
			ms.navigation({ title: 'Services' });
			servicesDrawer = ms.offcanvas({ position: 'right' });
			addMmenuCloseButton(servicesDrawer, 'Close services menu');
		}

		// Expose for the mobile-dock block (separate scope).
		window.ekwaMmenuDrawer    = mmenuDrawer;
		window.ekwaServicesDrawer = servicesDrawer;

		// Escape key closes whichever drawer is open.
		document.addEventListener('keydown', function (e) {
			if (e.key !== 'Escape') return;
			if (!document.body.classList.contains('mm-ocd-opened')) return;
			if (mmenuDrawer)    mmenuDrawer.close();
			if (servicesDrawer) servicesDrawer.close();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
