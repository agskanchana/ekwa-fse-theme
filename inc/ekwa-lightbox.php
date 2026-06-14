<?php
/**
 * Lightbox loader (GLightbox).
 *
 * Adds an opt-in, class-driven lightbox for images and videos (YouTube, Vimeo,
 * self-hosted HTML5, iframes and inline content). It mirrors the mmenu-light
 * deferral in inc/ekwa-perf-head.php: the GLightbox CSS/JS are NOT enqueued —
 * a tiny inline footer script injects them on the FIRST user interaction
 * (mousemove / scroll / touch / key) so the library never blocks initial render
 * or hurts Core Web Vitals. If a page has no lightbox triggers, nothing loads.
 *
 * ── Usage (initialize by class) ───────────────────────────────────────────────
 * Add the `ekwa-lightbox` class to any clickable element whose `href` points at
 * the media (the library's own `glightbox` class is also accepted):
 *
 *   Image:    <a class="ekwa-lightbox" href="/photo-full.jpg">…</a>
 *   YouTube:  <a class="ekwa-lightbox" href="https://www.youtube.com/watch?v=ID">…</a>
 *   Vimeo:    <a class="ekwa-lightbox" href="https://vimeo.com/ID">…</a>
 *   MP4:      <a class="ekwa-lightbox" href="/clip.mp4">…</a>
 *
 * Grouping: triggers that share the same `data-gallery="name"` open as one
 * swipeable gallery; triggers without it open on their own (each is given a
 * unique gallery id automatically). Optional per-item caption via `data-title`
 * / `data-description` — see GLightbox docs for the full data-* API.
 *
 * For AJAX-injected markup (e.g. load-more), call window.ekwaLightboxRefresh()
 * after inserting new triggers.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Bundled GLightbox version — used for cache-busting the vendored files. */
if ( ! defined( 'EKWA_GLIGHTBOX_VER' ) ) {
	define( 'EKWA_GLIGHTBOX_VER', '3.3.1' );
}

/**
 * Print the lightbox interaction-loader in the footer.
 *
 * Exposes:
 *   window.ekwaLoadLightbox(cb) — inject the GLightbox CSS <link> + JS <script>
 *                                 once, build the instance, then run `cb`.
 *   window.ekwaLightbox         — the live GLightbox instance (once ready).
 *   window.ekwaLightboxRefresh()— re-scan the DOM after AJAX content changes.
 *
 * The script bails immediately when the page contains no `.ekwa-lightbox`
 * (or `.glightbox`) triggers, so it costs nothing on pages that don't use it.
 */
function ekwa_lightbox_emit_loader() {
	if ( is_admin() ) {
		return;
	}

	$base = get_template_directory_uri() . '/assets/glightbox/';
	$css  = wp_json_encode( $base . 'glightbox.min.css?ver=' . EKWA_GLIGHTBOX_VER );
	$js   = wp_json_encode( $base . 'glightbox.min.js?ver=' . EKWA_GLIGHTBOX_VER );
	?>
<script id="ekwa-lightbox-loader">
(function(){
	var SELECTOR = '.ekwa-lightbox, .glightbox';
	// Nothing to do when the page has no lightbox triggers — zero cost.
	if (!document.querySelector(SELECTOR)) return;

	var cssUrl = <?php echo $css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
	var jsUrl  = <?php echo $js;  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
	var state  = 0; // 0 = idle, 1 = loading, 2 = ready, 3 = failed.
	var queue  = [];
	var instance = null;

	// Give every ungrouped trigger a unique data-gallery so it opens on its own;
	// authored data-gallery="name" values are left intact and stay grouped.
	function assignGalleries(){
		var nodes = document.querySelectorAll(SELECTOR);
		var solo = 0;
		for (var i = 0; i < nodes.length; i++){
			if (!nodes[i].getAttribute('data-gallery')){
				nodes[i].setAttribute('data-gallery', 'ekwa-lb-solo-' + (solo++));
			}
		}
	}

	function build(){
		if (instance || typeof GLightbox === 'undefined') return;
		assignGalleries();
		instance = GLightbox({
			selector: SELECTOR,
			touchNavigation: true,
			loop: false,
			openEffect: 'fade',
			closeEffect: 'fade'
		});
		window.ekwaLightbox = instance;
	}

	window.ekwaLoadLightbox = function(cb){
		if (state === 2){ if (cb) cb(); return; }
		if (state === 3){ return; } // previous load failed — links fall back to native.
		if (cb) queue.push(cb);
		if (state === 1){ return; }
		state = 1;

		var l = document.createElement('link');
		l.rel = 'stylesheet';
		l.href = cssUrl;
		document.head.appendChild(l);

		var s = document.createElement('script');
		s.src = jsUrl;
		s.onload = function(){
			state = 2;
			build();
			while (queue.length){ try { queue.shift()(); } catch (e) {} }
		};
		s.onerror = function(){ state = 3; queue.length = 0; };
		document.body.appendChild(s);
	};

	// First click on a trigger may land before the library has loaded. Intercept
	// it (capture phase), load GLightbox, then open the clicked item. Once the
	// instance exists, GLightbox's own handler takes over and we step aside.
	document.addEventListener('click', function(e){
		if (instance || state === 3) return;
		var trigger = e.target.closest(SELECTOR);
		if (!trigger) return;
		e.preventDefault();
		window.ekwaLoadLightbox(function(){
			if (instance) instance.open(trigger);
		});
	}, true);

	// Prewarm on the first interaction so the first real click is instant.
	var events = ['mousemove','scroll','touchstart','keydown'];
	var warmed = false;
	function warm(){
		if (warmed) return;
		warmed = true;
		window.ekwaLoadLightbox();
		events.forEach(function(ev){ window.removeEventListener(ev, warm); });
	}
	events.forEach(function(ev){ window.addEventListener(ev, warm, { passive: true, once: true }); });

	// Re-scan after AJAX-injected content (load-more, filters, etc.).
	window.ekwaLightboxRefresh = function(){
		if (!instance){ window.ekwaLoadLightbox(); return; }
		assignGalleries();
		if (typeof instance.reload === 'function') instance.reload();
	};
})();
</script>
	<?php
}
add_action( 'wp_print_footer_scripts', 'ekwa_lightbox_emit_loader' );
