<?php
/**
 * Title: Header — Logo Center
 * Slug: ekwa/header-logo-center
 * Categories: ekwa-headers-footers
 * Block Types: core/template-part/header
 * Description: Phone left, centered logo, directions and search right, with the menu on its own row below.
 * Keywords: header, logo, centered, menu, phone
 */
?>
<!-- wp:group {"tagName":"header","className":"ekwa-desktop-header","style":{"position":{"type":"sticky","top":"0px"},"spacing":{"padding":{"top":"var:preset|spacing|sm","bottom":"var:preset|spacing|xs","left":"var:preset|spacing|md","right":"var:preset|spacing|md"},"blockGap":"var:preset|spacing|xs"}},"backgroundColor":"background","layout":{"type":"constrained","contentSize":"1600px"}} -->
<header class="wp-block-group ekwa-desktop-header has-background-background-color has-background" style="padding-top:var(--wp--preset--spacing--sm);padding-right:var(--wp--preset--spacing--md);padding-bottom:var(--wp--preset--spacing--xs);padding-left:var(--wp--preset--spacing--md)">

	<!-- wp:group {"layout":{"type":"flex","justifyContent":"space-between","flexWrap":"nowrap"}} -->
	<div class="wp-block-group">

		<!-- wp:ekwa/phone /-->

		<!-- wp:ekwa/svg-logo {"maxWidth":200} /-->

		<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"},"style":{"spacing":{"blockGap":"var:preset|spacing|sm"}}} -->
		<div class="wp-block-group">
			<!-- wp:ekwa/address {"mode":"text","label":"Get Directions"} /-->
			<!-- wp:ekwa/search /-->
		</div>
		<!-- /wp:group -->

	</div>
	<!-- /wp:group -->

	<!-- wp:group {"layout":{"type":"flex","justifyContent":"center"}} -->
	<div class="wp-block-group">
		<!-- wp:ekwa/header-menu /-->
	</div>
	<!-- /wp:group -->

</header>
<!-- /wp:group -->
