<?php
/**
 * Tag external links (target="_blank") with a descriptive title="…" attribute
 * on the user's first interaction (mousemove / scroll / touchstart).
 *
 * Deferred to first interaction so it doesn't run at page load and doesn't
 * cost anything for bots or users who never engage with the page.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_footer', 'ekwa_external_links_inline_script', 99 );

function ekwa_external_links_inline_script() {
	?>
<script id="ekwa-external-link-title">
(function(){
	var done = false;
	var events = ['mousemove','scroll','touchstart'];
	function tag(){
		if(done)return;
		done = true;
		for(var j=0;j<events.length;j++){
			window.removeEventListener(events[j], tag);
		}
		var links = document.querySelectorAll('a[target="_blank"]:not([title])');
		for(var i=0;i<links.length;i++){
			links[i].setAttribute('title','Link opens in a new window');
		}
	}
	for(var k=0;k<events.length;k++){
		window.addEventListener(events[k], tag, { passive:true });
	}
})();
</script>
	<?php
}
