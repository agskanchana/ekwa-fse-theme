<?php
/**
 * Title: Call to Action
 * Slug: ekwa/cta
 * Categories: ekwa-patterns
 * Description: A full-width call-to-action section with heading, text, and action buttons.
 * Keywords: cta, call-to-action, banner, booking
 */
?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|2-xl","bottom":"var:preset|spacing|2-xl","left":"var:preset|spacing|md","right":"var:preset|spacing|md"}}},"backgroundColor":"primary","textColor":"white","layout":{"type":"constrained","contentSize":"800px"}} -->
<div class="wp-block-group alignfull has-white-color has-primary-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--2-xl);padding-right:var(--wp--preset--spacing--md);padding-bottom:var(--wp--preset--spacing--2-xl);padding-left:var(--wp--preset--spacing--md)">

	<!-- wp:heading {"textAlign":"center","level":2,"textColor":"white","fontSize":"2-xl"} -->
	<h2 class="wp-block-heading has-text-align-center has-white-color has-text-color has-2-xl-font-size">Ready to Transform Your Smile?</h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"align":"center","style":{"elements":{"link":{"color":{"text":"var:preset|color|surface"}}}},"textColor":"surface","fontSize":"md"} -->
	<p class="has-text-align-center has-surface-color has-text-color has-link-color has-md-font-size">Schedule your consultation today and take the first step towards a healthier, more confident smile. New patients are always welcome.</p>
	<!-- /wp:paragraph -->

	<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"},"style":{"spacing":{"margin":{"top":"var:preset|spacing|lg"}}}} -->
	<div class="wp-block-buttons" style="margin-top:var(--wp--preset--spacing--lg)">
		<!-- wp:button {"backgroundColor":"accent","textColor":"foreground","style":{"border":{"radius":"6px"},"spacing":{"padding":{"top":"var:preset|spacing|md","bottom":"var:preset|spacing|md","left":"var:preset|spacing|xl","right":"var:preset|spacing|xl"}},"typography":{"fontWeight":"700"}},"fontSize":"base"} -->
		<div class="wp-block-button has-custom-font-size has-base-font-size"><a class="wp-block-button__link has-foreground-color has-accent-background-color has-text-color has-background wp-element-button" style="border-radius:6px;padding-top:var(--wp--preset--spacing--md);padding-right:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--md);padding-left:var(--wp--preset--spacing--xl);font-weight:700">Book Your Appointment</a></div>
		<!-- /wp:button -->
		<!-- wp:button {"backgroundColor":"white","textColor":"primary","style":{"border":{"radius":"6px"},"spacing":{"padding":{"top":"var:preset|spacing|md","bottom":"var:preset|spacing|md","left":"var:preset|spacing|xl","right":"var:preset|spacing|xl"}}},"fontSize":"base"} -->
		<div class="wp-block-button has-custom-font-size has-base-font-size"><a class="wp-block-button__link has-primary-color has-white-background-color has-text-color has-background wp-element-button" style="border-radius:6px;padding-top:var(--wp--preset--spacing--md);padding-right:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--md);padding-left:var(--wp--preset--spacing--xl)">Call (555) 123-4567</a></div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->

</div>
<!-- /wp:group -->
