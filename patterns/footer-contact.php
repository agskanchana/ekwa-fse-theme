<?php
/**
 * Title: Footer — Contact Focus
 * Slug: ekwa/footer-contact
 * Categories: ekwa-headers-footers
 * Block Types: core/template-part/footer
 * Description: Two columns — logo, tagline and navigation left; address, phone and hours right — with a copyright bar.
 * Keywords: footer, contact, hours, two columns
 */
?>
<!-- wp:group {"tagName":"footer","style":{"spacing":{"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|xl","left":"var:preset|spacing|md","right":"var:preset|spacing|md"},"blockGap":"var:preset|spacing|lg"}},"backgroundColor":"foreground","textColor":"white","layout":{"type":"constrained","contentSize":"1280px"}} -->
<footer class="wp-block-group has-white-color has-foreground-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--xl);padding-right:var(--wp--preset--spacing--md);padding-bottom:var(--wp--preset--spacing--xl);padding-left:var(--wp--preset--spacing--md)">

	<!-- wp:columns {"style":{"spacing":{"blockGap":{"left":"var:preset|spacing|2-xl"}}}} -->
	<div class="wp-block-columns">

		<!-- wp:column {"width":"55%"} -->
		<div class="wp-block-column" style="flex-basis:55%">
			<!-- wp:ekwa/svg-logo {"maxWidth":180} /-->
			<!-- wp:site-tagline {"textColor":"surface","fontSize":"sm"} /-->
			<!-- wp:navigation {"overlayMenu":"never","layout":{"type":"flex","flexWrap":"wrap"},"style":{"spacing":{"blockGap":"var:preset|spacing|md"},"typography":{"fontSize":"var(--wp--preset--font-size--sm)"}},"textColor":"surface"} /-->
		</div>
		<!-- /wp:column -->

		<!-- wp:column {"width":"45%"} -->
		<div class="wp-block-column" style="flex-basis:45%">
			<!-- wp:heading {"level":4,"style":{"elements":{"link":{"color":{"text":"var:preset|color|white"}}}},"textColor":"white","fontSize":"md"} -->
			<h4 class="wp-block-heading has-white-color has-text-color has-link-color has-md-font-size">Visit Us</h4>
			<!-- /wp:heading -->
			<!-- wp:ekwa/address {"mode":"full"} /-->
			<!-- wp:ekwa/phone {"prefix":"Phone:"} /-->
			<!-- wp:ekwa/hours /-->
		</div>
		<!-- /wp:column -->

	</div>
	<!-- /wp:columns -->

	<!-- wp:separator {"backgroundColor":"foreground-light"} -->
	<hr class="wp-block-separator has-text-color has-foreground-light-color has-alpha-channel-opacity has-foreground-light-background-color has-background"/>
	<!-- /wp:separator -->

	<!-- wp:group {"layout":{"type":"flex","justifyContent":"space-between","flexWrap":"wrap"}} -->
	<div class="wp-block-group">
		<!-- wp:ekwa/copyright /-->
		<!-- wp:ekwa/social {"showShare":false} /-->
	</div>
	<!-- /wp:group -->

</footer>
<!-- /wp:group -->
