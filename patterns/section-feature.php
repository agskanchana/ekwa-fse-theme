<?php
/**
 * Title: Section — Feature Split
 * Slug: ekwa/section-feature
 * Categories: ekwa-patterns
 * Description: Two-column feature: image on one side, heading + text + checklist + CTA on the other. Stacks on mobile. Scoped CSS, theme tokens.
 * Keywords: feature, about, split, image, text
 */

$bp  = function_exists( 'ekwa_responsive_breakpoints' ) ? ekwa_responsive_breakpoints() : array( 'tablet' => 1199, 'mobile' => 599 );
$css = "
.ekwa-p-feature { display:grid; grid-template-columns:1fr 1fr; align-items:center; gap:var(--wp--preset--spacing--2-xl); padding:var(--wp--preset--spacing--2-xl) var(--wp--preset--spacing--md); max-width:1200px; margin-inline:auto; }
.ekwa-p-feature__media img { width:100%; height:auto; border-radius:14px; display:block; }
.ekwa-p-feature__body { display:flex; flex-direction:column; gap:var(--wp--preset--spacing--sm); }
.ekwa-p-feature__body h2 { margin:0; font-size:var(--wp--preset--font-size--xl); }
.ekwa-p-feature__body p { margin:0; color:var(--wp--preset--color--foreground-light,#5b6b7c); }
.ekwa-p-feature__list { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:8px; }
.ekwa-p-feature__list li { position:relative; padding-left:26px; }
.ekwa-p-feature__list li::before { content:'\\2713'; position:absolute; left:0; top:0; color:var(--wp--preset--color--primary); font-weight:700; }
@media (max-width:{$bp['mobile']}px){ .ekwa-p-feature { grid-template-columns:1fr; gap:var(--wp--preset--spacing--lg); } }
";
$attrs = wp_json_encode( array( 'className' => 'ekwa-p-feature', 'scopedCss' => $css ) );
?>
<!-- wp:ekwa/div <?php echo $attrs; // phpcs:ignore ?> -->
	<!-- wp:ekwa/div {"className":"ekwa-p-feature__media"} -->
		<!-- wp:ekwa/image {"src":"https://placehold.co/720x560","alt":"","width":"720","height":"560"} /-->
	<!-- /wp:ekwa/div -->
	<!-- wp:ekwa/div {"className":"ekwa-p-feature__body"} -->
		<!-- wp:heading {"level":2} -->
		<h2 class="wp-block-heading">A benefit-led section heading</h2>
		<!-- /wp:heading -->
		<!-- wp:paragraph -->
		<p>Two or three sentences that expand on the heading and lead into the supporting points below.</p>
		<!-- /wp:paragraph -->
		<!-- wp:list {"className":"ekwa-p-feature__list"} -->
		<ul class="wp-block-list ekwa-p-feature__list"><!-- wp:list-item --><li>First supporting point</li><!-- /wp:list-item --><!-- wp:list-item --><li>Second supporting point</li><!-- /wp:list-item --><!-- wp:list-item --><li>Third supporting point</li><!-- /wp:list-item --></ul>
		<!-- /wp:list -->
		<!-- wp:ekwa/button {"text":"Learn More","url":"#","variant":"filled"} /-->
	<!-- /wp:ekwa/div -->
<!-- /wp:ekwa/div -->
