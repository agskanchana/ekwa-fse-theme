<?php
/**
 * Title: Hero
 * Slug: ekwa/hero
 * Categories: ekwa-patterns
 * Description: A full-width hero section with heading, text, and call-to-action buttons.
 * Keywords: hero, banner, header, cta
 */
?>
<!-- wp:cover {"dimRatio":80,"overlayColor":"foreground","isUserOverlayColor":true,"minHeight":85,"minHeightUnit":"vh","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|2-xl","bottom":"var:preset|spacing|2-xl","left":"var:preset|spacing|md","right":"var:preset|spacing|md"}}}} -->
<div class="wp-block-cover alignfull" style="padding-top:var(--wp--preset--spacing--2-xl);padding-right:var(--wp--preset--spacing--md);padding-bottom:var(--wp--preset--spacing--2-xl);padding-left:var(--wp--preset--spacing--md);min-height:85vh">
	<span aria-hidden="true" class="wp-block-cover__background has-foreground-background-color has-background-dim-80 has-background-dim"></span>

	<!-- wp:group {"layout":{"type":"constrained","contentSize":"900px"}} -->
	<div class="wp-block-group">

		<!-- wp:heading {"textAlign":"center","level":6,"style":{"typography":{"textTransform":"uppercase","letterSpacing":"0.15em","fontWeight":"600"}},"textColor":"accent","fontSize":"sm"} -->
		<h6 class="wp-block-heading has-text-align-center has-accent-color has-text-color has-sm-font-size" style="font-weight:600;letter-spacing:0.15em;text-transform:uppercase">Welcome to Ekwa Dental</h6>
		<!-- /wp:heading -->

		<!-- wp:heading {"textAlign":"center","level":1,"textColor":"white","fontSize":"hero"} -->
		<h1 class="wp-block-heading has-text-align-center has-white-color has-text-color has-hero-font-size">Your Smile Deserves the Best Care</h1>
		<!-- /wp:heading -->

		<!-- wp:paragraph {"align":"center","textColor":"surface","fontSize":"md"} -->
		<p class="has-text-align-center has-surface-color has-text-color has-md-font-size">Experience comprehensive dental care with a patient-first approach. Our team of specialists combines cutting-edge technology with compassionate service to deliver exceptional results.</p>
		<!-- /wp:paragraph -->

		<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"},"style":{"spacing":{"margin":{"top":"var:preset|spacing|lg"}}}} -->
		<div class="wp-block-buttons" style="margin-top:var(--wp--preset--spacing--lg)">
			<!-- wp:button {"backgroundColor":"primary","textColor":"white","style":{"border":{"radius":"6px"},"spacing":{"padding":{"top":"var:preset|spacing|md","bottom":"var:preset|spacing|md","left":"var:preset|spacing|xl","right":"var:preset|spacing|xl"}}},"fontSize":"base"} -->
			<div class="wp-block-button has-custom-font-size has-base-font-size"><a class="wp-block-button__link has-white-color has-primary-background-color has-text-color has-background wp-element-button" style="border-radius:6px;padding-top:var(--wp--preset--spacing--md);padding-right:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--md);padding-left:var(--wp--preset--spacing--xl)">Book Appointment</a></div>
			<!-- /wp:button -->
			<!-- wp:button {"backgroundColor":"white","textColor":"foreground","style":{"border":{"radius":"6px"},"spacing":{"padding":{"top":"var:preset|spacing|md","bottom":"var:preset|spacing|md","left":"var:preset|spacing|xl","right":"var:preset|spacing|xl"}}},"className":"is-style-outline","fontSize":"base"} -->
			<div class="wp-block-button has-custom-font-size is-style-outline has-base-font-size"><a class="wp-block-button__link has-foreground-color has-white-background-color has-text-color has-background wp-element-button" style="border-radius:6px;padding-top:var(--wp--preset--spacing--md);padding-right:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--md);padding-left:var(--wp--preset--spacing--xl)">Our Services</a></div>
			<!-- /wp:button -->
		</div>
		<!-- /wp:buttons -->

	</div>
	<!-- /wp:group -->

</div>
<!-- /wp:cover -->
