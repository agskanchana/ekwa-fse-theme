<?php
/**
 * Title: Header — With Topbar
 * Slug: ekwa/header-topbar
 * Categories: ekwa-headers-footers
 * Block Types: core/template-part/header
 * Description: Slim contact topbar (address, phone, social) above a logo + menu row.
 * Keywords: header, topbar, contact, social, menu
 */
?>
<!-- wp:group {"tagName":"header","className":"ekwa-desktop-header","style":{"position":{"type":"sticky","top":"0px"}},"backgroundColor":"background","layout":{"type":"default"}} -->
<header class="wp-block-group ekwa-desktop-header has-background-background-color has-background">

	<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|xs","bottom":"var:preset|spacing|xs","left":"var:preset|spacing|md","right":"var:preset|spacing|md"}}},"backgroundColor":"foreground","textColor":"white","layout":{"type":"constrained","contentSize":"1600px"}} -->
	<div class="wp-block-group has-white-color has-foreground-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--xs);padding-right:var(--wp--preset--spacing--md);padding-bottom:var(--wp--preset--spacing--xs);padding-left:var(--wp--preset--spacing--md)">

		<!-- wp:group {"layout":{"type":"flex","justifyContent":"space-between","flexWrap":"wrap"}} -->
		<div class="wp-block-group">

			<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"},"style":{"spacing":{"blockGap":"var:preset|spacing|md"}}} -->
			<div class="wp-block-group">
				<!-- wp:ekwa/address {"mode":"address"} /-->
				<!-- wp:ekwa/phone /-->
			</div>
			<!-- /wp:group -->

			<!-- wp:ekwa/social {"showShare":false} /-->

		</div>
		<!-- /wp:group -->

	</div>
	<!-- /wp:group -->

	<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|sm","bottom":"var:preset|spacing|sm","left":"var:preset|spacing|md","right":"var:preset|spacing|md"}}},"layout":{"type":"constrained","contentSize":"1600px"}} -->
	<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--sm);padding-right:var(--wp--preset--spacing--md);padding-bottom:var(--wp--preset--spacing--sm);padding-left:var(--wp--preset--spacing--md)">

		<!-- wp:group {"layout":{"type":"flex","justifyContent":"space-between","flexWrap":"nowrap"}} -->
		<div class="wp-block-group">

			<!-- wp:ekwa/svg-logo {"maxWidth":180} /-->

			<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"},"style":{"spacing":{"blockGap":"var:preset|spacing|sm"}}} -->
			<div class="wp-block-group">
				<!-- wp:ekwa/header-menu /-->
				<!-- wp:ekwa/search /-->
			</div>
			<!-- /wp:group -->

		</div>
		<!-- /wp:group -->

	</div>
	<!-- /wp:group -->

</header>
<!-- /wp:group -->
