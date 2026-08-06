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
 * ── One instance, on purpose ──────────────────────────────────────────────────
 * ekwa/image, ekwa/div (tagName "a") and the video blocks' "Open in lightbox"
 * option ALL emit the same `ekwa-lightbox` class and are driven by the single
 * GLightbox instance built here. That is what keeps images and videos from
 * fighting: there is only ever one library copy, one selector and one instance
 * on the page, so a gallery can even mix the two. Anything adding a lightbox to
 * this theme should reuse ekwa_lightbox_trigger_attrs() rather than enqueue a
 * second library.
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

/** The class every lightbox trigger carries — also half of the JS selector. */
if ( ! defined( 'EKWA_LIGHTBOX_CLASS' ) ) {
	define( 'EKWA_LIGHTBOX_CLASS', 'ekwa-lightbox' );
}

/**
 * Build the ` class="…" data-gallery="…"` attribute string for a lightbox
 * trigger anchor.
 *
 * Every block that offers a lightbox routes through here so the markup — and
 * therefore the selector the single GLightbox instance binds to — can only ever
 * be defined in one place.
 *
 * The group is what turns separate triggers into one swipeable gallery: items
 * sharing a group open together, and an empty group is left without the
 * attribute so the loader's assignGalleries() can hand it a unique id and it
 * opens on its own.
 *
 * @param string $group   Gallery group name. Empty for a standalone item.
 * @param string $caption Optional caption shown under the media.
 * @param string $classes Extra classes to place alongside the trigger class.
 * @return string Attribute string starting with a space, ready to concatenate.
 */
function ekwa_lightbox_trigger_attrs( $group = '', $caption = '', $classes = '' ) {
	$class_list = trim( $classes . ' ' . EKWA_LIGHTBOX_CLASS );
	$out        = ' class="' . esc_attr( $class_list ) . '"';

	// Group names are a plain lookup key, not a CSS class — spaces and mixed
	// case are fine, so only strip tags/control characters rather than forcing
	// them through sanitize_html_class() and silently mangling the author's
	// name into something that no longer matches its siblings.
	$group = trim( sanitize_text_field( $group ) );
	if ( '' !== $group ) {
		$out .= ' data-gallery="' . esc_attr( $group ) . '"';
	}

	// GLightbox reads `data-title` off the element's dataset (see its
	// parseConfig) and falls back to the anchor's own title/alt when absent.
	$caption = trim( sanitize_text_field( $caption ) );
	if ( '' !== $caption ) {
		$out .= ' data-title="' . esc_attr( $caption ) . '"';
	}

	return $out;
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
	// The counter lives outside the function on purpose: already-assigned nodes
	// are skipped on a re-scan, so a counter that restarted at 0 each call would
	// hand an AJAX-inserted trigger an id an existing one already owns — and two
	// unrelated items (say a photo and a video) would silently become one
	// two-slide gallery.
	var solo = 0;
	function assignGalleries(){
		var nodes = document.querySelectorAll(SELECTOR);
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
		// e.target is not guaranteed to be an Element with .closest — a
		// synthetic/dispatched event can land on the document or a text node,
		// and calling closest() on those throws, which would take down every
		// other click handler on the page with it.
		var t = e.target;
		if (!t || typeof t.closest !== 'function') return;
		var trigger = t.closest(SELECTOR);
		if (!trigger) return;
		e.preventDefault();
		window.ekwaLoadLightbox(function(){
			// open() needs the trigger to be one of the elements the instance
			// was built from; a trigger inserted after build (AJAX) is not, so
			// re-scan first rather than opening an empty lightbox.
			if (!instance) return;
			if (instance.elements && instance.elements.length === 0) return;
			try { instance.open(trigger); } catch (err) {}
		});
	}, true);

	// Prewarm on the first interaction so the first real click is instant.
	// `click` is in the list too: a click anywhere (not just on a trigger)
	// means the visitor is engaging, and the capture handler above already
	// covers the case where that click IS the first trigger click.
	var events = ['mousemove','scroll','touchstart','keydown','click'];
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
