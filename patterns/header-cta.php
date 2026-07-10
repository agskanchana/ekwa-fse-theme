<?php
/**
 * Title: Header — With Booking CTA
 * Slug: ekwa/header-cta
 * Categories: ekwa-headers-footers
 * Block Types: core/template-part/header
 * Description: Logo left, menu center, phone plus a prominent appointment button right.
 * Keywords: header, cta, book, appointment, menu
 */
?>
<!-- wp:group {"tagName":"header","className":"ekwa-desktop-header","style":{"position":{"type":"sticky","top":"0px"},"spacing":{"padding":{"top":"var:preset|spacing|sm","bottom":"var:preset|spacing|sm","left":"var:preset|spacing|md","right":"var:preset|spacing|md"}}},"backgroundColor":"background","layout":{"type":"constrained","contentSize":"1600px"}} -->
<header class="wp-block-group ekwa-desktop-header has-background-background-color has-background" style="padding-top:var(--wp--preset--spacing--sm);padding-right:var(--wp--preset--spacing--md);padding-bottom:var(--wp--preset--spacing--sm);padding-left:var(--wp--preset--spacing--md)">

	<!-- wp:group {"layout":{"type":"flex","justifyContent":"space-between","flexWrap":"nowrap"}} -->
	<div class="wp-block-group">

		<!-- wp:ekwa/svg-logo {"maxWidth":180} /-->

		<!-- wp:ekwa/header-menu /-->

		<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"},"style":{"spacing":{"blockGap":"var:preset|spacing|sm"}}} -->
		<div class="wp-block-group">
			<!-- wp:ekwa/phone {"showPrefix":false} /-->
			<!-- wp:ekwa/button {"text":"Book Appointment","linkType":"appointment","variant":"filled","iconClass":"fa-solid fa-calendar-check"} /-->
		</div>
		<!-- /wp:group -->

	</div>
	<!-- /wp:group -->

</header>
<!-- /wp:group -->
