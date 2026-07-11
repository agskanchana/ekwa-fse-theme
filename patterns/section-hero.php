<?php
/**
 * Title: Section — Hero
 * Slug: ekwa/section-hero
 * Categories: ekwa-patterns
 * Description: Centered hero with eyebrow, headline, lead paragraph and two CTAs. Self-contained (scoped CSS, theme tokens).
 * Keywords: hero, banner, intro, cta, header
 */

$bp  = function_exists( 'ekwa_responsive_breakpoints' ) ? ekwa_responsive_breakpoints() : array( 'tablet' => 1199, 'mobile' => 599 );
$css = "
.ekwa-p-hero { display:flex; flex-direction:column; align-items:center; text-align:center; gap:var(--wp--preset--spacing--md); padding:var(--wp--preset--spacing--2-xl) var(--wp--preset--spacing--md); max-width:820px; margin-inline:auto; }
.ekwa-p-hero__eyebrow { text-transform:uppercase; letter-spacing:0.15em; font-weight:600; font-size:var(--wp--preset--font-size--sm); color:var(--wp--preset--color--primary); }
.ekwa-p-hero h1 { margin:0; font-size:var(--wp--preset--font-size--hero); line-height:1.1; }
.ekwa-p-hero p { margin:0; font-size:var(--wp--preset--font-size--md); color:var(--wp--preset--color--foreground-light,#5b6b7c); }
.ekwa-p-hero__cta { display:flex; flex-wrap:wrap; justify-content:center; gap:var(--wp--preset--spacing--sm); margin-top:var(--wp--preset--spacing--sm); }
@media (max-width:{$bp['mobile']}px){ .ekwa-p-hero { padding:var(--wp--preset--spacing--xl) var(--wp--preset--spacing--sm); } }
";
$attrs = wp_json_encode( array( 'className' => 'ekwa-p-hero', 'scopedCss' => $css ) );
?>
<!-- wp:ekwa/div <?php echo $attrs; // phpcs:ignore ?> -->
	<!-- wp:ekwa/text {"tagName":"span","text":"Welcome to Ekwa","className":"ekwa-p-hero__eyebrow"} /-->
	<!-- wp:heading {"level":1} -->
	<h1 class="wp-block-heading">A headline that sells the outcome</h1>
	<!-- /wp:heading -->
	<!-- wp:paragraph -->
	<p>Support the promise with one or two sentences of context. Keep it tight and benefit-led so the call to action feels earned.</p>
	<!-- /wp:paragraph -->
	<!-- wp:ekwa/div {"className":"ekwa-p-hero__cta"} -->
		<!-- wp:ekwa/button {"text":"Get Started","url":"#","variant":"filled"} /-->
		<!-- wp:ekwa/button {"text":"Learn More","url":"#","variant":"outline"} /-->
	<!-- /wp:ekwa/div -->
<!-- /wp:ekwa/div -->
