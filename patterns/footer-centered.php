<?php
/**
 * Title: Footer — Centered
 * Slug: ekwa/footer-centered
 * Categories: ekwa-headers-footers
 * Block Types: core/template-part/footer
 * Description: Centered logo, contact line, social icons and copyright — a compact footer for smaller sites.
 * Keywords: footer, centered, minimal, social
 */
?>
<!-- wp:group {"tagName":"footer","style":{"spacing":{"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|lg","left":"var:preset|spacing|md","right":"var:preset|spacing|md"},"blockGap":"var:preset|spacing|md"}},"backgroundColor":"foreground","textColor":"white","layout":{"type":"constrained","contentSize":"900px"}} -->
<footer class="wp-block-group has-white-color has-foreground-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--xl);padding-right:var(--wp--preset--spacing--md);padding-bottom:var(--wp--preset--spacing--lg);padding-left:var(--wp--preset--spacing--md)">

	<!-- wp:group {"layout":{"type":"flex","justifyContent":"center"}} -->
	<div class="wp-block-group">
		<!-- wp:ekwa/svg-logo {"maxWidth":160} /-->
	</div>
	<!-- /wp:group -->

	<!-- wp:group {"layout":{"type":"flex","justifyContent":"center","flexWrap":"wrap"},"style":{"spacing":{"blockGap":"var:preset|spacing|md"}}} -->
	<div class="wp-block-group">
		<!-- wp:ekwa/address {"mode":"address"} /-->
		<!-- wp:ekwa/phone /-->
	</div>
	<!-- /wp:group -->

	<!-- wp:group {"layout":{"type":"flex","justifyContent":"center"}} -->
	<div class="wp-block-group">
		<!-- wp:ekwa/social {"showShare":false} /-->
	</div>
	<!-- /wp:group -->

	<!-- wp:group {"layout":{"type":"flex","justifyContent":"center"}} -->
	<div class="wp-block-group">
		<!-- wp:ekwa/copyright /-->
	</div>
	<!-- /wp:group -->

</footer>
<!-- /wp:group -->
