/**
 * Ekwa Reveal — show/hide content blocks driven by a trigger button.
 * Markup convention:
 *   <div class="reveal">
 *     … visible content …
 *     <div class="reveal-hidden"> … hidden content … </div>
 *     <a class="reveal-trigger" data-reveal-close-text="Show Less">Learn More</a>
 *   </div>
 * The trigger can sit anywhere inside the .reveal scope.
 */
(function () {
	'use strict';

	function init() {
		document.querySelectorAll('.reveal').forEach(function (scope) {
			var trigger = scope.querySelector('.reveal-trigger');
			var hidden  = scope.querySelector('.reveal-hidden');
			if (!trigger || !hidden) return;

			var openLabel  = trigger.textContent.trim();
			var closeLabel = trigger.getAttribute('data-reveal-close-text') || '';

			if (!hidden.id) {
				hidden.id = 'ekwa-reveal-' + Math.random().toString(36).slice(2, 9);
			}
			trigger.setAttribute('aria-controls', hidden.id);
			trigger.setAttribute('aria-expanded', 'false');
			hidden.setAttribute('aria-hidden', 'true');

			function openPanel() {
				hidden.style.maxHeight = hidden.scrollHeight + 'px';
				scope.classList.add('is-open');
				trigger.setAttribute('aria-expanded', 'true');
				hidden.setAttribute('aria-hidden', 'false');
				if (closeLabel) trigger.textContent = closeLabel;
				// After the height transition completes, drop the inline cap so
				// nested content (images, embeds) can change size naturally.
				setTimeout(function () {
					if (scope.classList.contains('is-open')) {
						hidden.style.maxHeight = 'none';
					}
				}, 450);
			}

			function closePanel() {
				// Snap to current pixel height first so the transition has a
				// concrete starting value when we collapse to 0.
				hidden.style.maxHeight = hidden.scrollHeight + 'px';
				void hidden.offsetHeight; // force reflow
				hidden.style.maxHeight = '0px';
				scope.classList.remove('is-open');
				trigger.setAttribute('aria-expanded', 'false');
				hidden.setAttribute('aria-hidden', 'true');
				if (closeLabel) trigger.textContent = openLabel;
			}

			trigger.addEventListener('click', function (e) {
				e.preventDefault();
				if (scope.classList.contains('is-open')) {
					if (scope.classList.contains('reveal--hide-after')) {
						return; // one-way reveal: ignore further clicks
					}
					closePanel();
				} else {
					openPanel();
				}
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
