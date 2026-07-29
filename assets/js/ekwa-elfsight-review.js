/*! ekwa-elfsight-review — load Elfsight platform.js on first user interaction */
(function () {
	'use strict';

	var SELECTOR = '.ekwa-elfsight-review[data-elfsight-src]';
	// Events that signal the visitor is engaging with the page. Covers desktop
	// (mousemove, wheel/scroll, keyboard), mobile (touchstart), and clicks.
	var EVENTS = ['mousemove', 'scroll', 'wheel', 'keydown', 'touchstart', 'click'];
	var OPTS = { passive: true, capture: true };
	var loaded = {}; // src -> true, so each CDN bundle is injected only once.
	var listening = false;

	function loadScript(src) {
		if (!src || loaded[src]) return;
		loaded[src] = true;
		var s = document.createElement('script');
		s.src = src;
		s.async = true;
		document.head.appendChild(s);
	}

	function loadAll() {
		var nodes = document.querySelectorAll(SELECTOR);
		for (var i = 0; i < nodes.length; i++) {
			loadScript(nodes[i].getAttribute('data-elfsight-src'));
			nodes[i].removeAttribute('data-elfsight-src');
		}
	}

	function stopListening() {
		if (!listening) return;
		listening = false;
		for (var i = 0; i < EVENTS.length; i++) {
			window.removeEventListener(EVENTS[i], onInteract, OPTS);
		}
	}

	function onInteract() {
		stopListening();
		loadAll();
	}

	function init() {
		if (!document.querySelector(SELECTOR)) return;
		listening = true;
		for (var i = 0; i < EVENTS.length; i++) {
			window.addEventListener(EVENTS[i], onInteract, OPTS);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
