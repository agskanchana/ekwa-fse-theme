<?php
/**
 * Title: Footer — Minimal Bar
 * Slug: ekwa/footer-minimal
 * Categories: ekwa-headers-footers
 * Block Types: core/template-part/footer
 * Description: A single slim row: logo, copyright and social icons.
 * Keywords: footer, minimal, bar, slim
 */
?>
<!-- wp:group {"tagName":"footer","style":{"spacing":{"padding":{"top":"var:preset|spacing|md","bottom":"var:preset|spacing|md","left":"var:preset|spacing|md","right":"var:preset|spacing|md"}}},"backgroundColor":"foreground","textColor":"white","layout":{"type":"constrained","contentSize":"1280px"}} -->
<footer class="wp-block-group has-white-color has-foreground-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--md);padding-right:var(--wp--preset--spacing--md);padding-bottom:var(--wp--preset--spacing--md);padding-left:var(--wp--preset--spacing--md)">

	<!-- wp:group {"layout":{"type":"flex","justifyContent":"space-between","flexWrap":"wrap"}} -->
	<div class="wp-block-group">
		<!-- wp:ekwa/svg-logo {"maxWidth":120} /-->
		<!-- wp:ekwa/copyright /-->
		<!-- wp:ekwa/social {"showShare":false} /-->
	</div>
	<!-- /wp:group -->

</footer>
<!-- /wp:group -->
