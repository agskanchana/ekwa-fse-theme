<?php
/**
 * Title: Section — CTA Band
 * Slug: ekwa/section-cta
 * Categories: ekwa-patterns
 * Description: Full-width call-to-action band with heading, supporting line and a button. Scoped CSS, theme tokens.
 * Keywords: cta, call to action, banner, contact, book
 */

$css = "
.ekwa-p-cta { display:flex; flex-direction:column; align-items:center; text-align:center; gap:var(--wp--preset--spacing--sm); padding:var(--wp--preset--spacing--2-xl) var(--wp--preset--spacing--md); background:var(--wp--preset--color--primary); border-radius:16px; }
.ekwa-p-cta h2 { margin:0; font-size:var(--wp--preset--font-size--xl); color:#fff; }
.ekwa-p-cta p { margin:0; font-size:var(--wp--preset--font-size--md); color:rgba(255,255,255,0.9); max-width:620px; }
.ekwa-p-cta .ekwa-btn { margin-top:var(--wp--preset--spacing--xs); background:#fff; color:var(--wp--preset--color--primary); }
";
$attrs = wp_json_encode( array( 'className' => 'ekwa-p-cta', 'scopedCss' => $css ) );
?>
<!-- wp:ekwa/div <?php echo $attrs; // phpcs:ignore ?> -->
	<!-- wp:heading {"level":2} -->
	<h2 class="wp-block-heading">Ready to get started?</h2>
	<!-- /wp:heading -->
	<!-- wp:paragraph -->
	<p>One line that reduces friction and tells the visitor exactly what happens when they click.</p>
	<!-- /wp:paragraph -->
	<!-- wp:ekwa/button {"text":"Book an Appointment","linkType":"appointment","variant":"filled"} /-->
<!-- /wp:ekwa/div -->
