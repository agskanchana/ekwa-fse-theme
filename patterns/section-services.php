<?php
/**
 * Title: Section — Services Grid
 * Slug: ekwa/section-services
 * Categories: ekwa-patterns
 * Description: Three-column service cards (icon, title, text, link) that collapse to one column on mobile. Scoped CSS, theme tokens.
 * Keywords: services, features, grid, cards, icons
 */

$bp  = function_exists( 'ekwa_responsive_breakpoints' ) ? ekwa_responsive_breakpoints() : array( 'tablet' => 1199, 'mobile' => 599 );
$css = "
.ekwa-p-services { padding:var(--wp--preset--spacing--2-xl) var(--wp--preset--spacing--md); max-width:1200px; margin-inline:auto; }
.ekwa-p-services__head { text-align:center; max-width:640px; margin:0 auto var(--wp--preset--spacing--xl); }
.ekwa-p-services__head h2 { margin:0 0 var(--wp--preset--spacing--xs); font-size:var(--wp--preset--font-size--xl); }
.ekwa-p-services__head p { margin:0; color:var(--wp--preset--color--foreground-light,#5b6b7c); }
.ekwa-p-services__grid { display:grid; grid-template-columns:repeat(3,1fr); gap:var(--wp--preset--spacing--lg); }
.ekwa-p-card { display:flex; flex-direction:column; gap:var(--wp--preset--spacing--xs); padding:var(--wp--preset--spacing--lg); background:var(--wp--preset--color--surface,#f4f7fb); border-radius:12px; }
.ekwa-p-card__icon { font-size:32px; color:var(--wp--preset--color--primary); }
.ekwa-p-card h3 { margin:0; font-size:var(--wp--preset--font-size--lg); }
.ekwa-p-card p { margin:0; color:var(--wp--preset--color--foreground-light,#5b6b7c); }
@media (max-width:{$bp['tablet']}px){ .ekwa-p-services__grid { grid-template-columns:repeat(2,1fr); } }
@media (max-width:{$bp['mobile']}px){ .ekwa-p-services__grid { grid-template-columns:1fr; } }
";
$attrs = wp_json_encode( array( 'className' => 'ekwa-p-services', 'scopedCss' => $css ) );

$cards = array(
	array( 'fa-solid fa-bolt',        'Fast & Reliable', 'A short line about why this service matters to the visitor.' ),
	array( 'fa-solid fa-shield-halved','Trusted Care',    'A short line about why this service matters to the visitor.' ),
	array( 'fa-solid fa-heart',        'Patient First',   'A short line about why this service matters to the visitor.' ),
);
?>
<!-- wp:ekwa/div <?php echo $attrs; // phpcs:ignore ?> -->
	<!-- wp:ekwa/div {"className":"ekwa-p-services__head"} -->
		<!-- wp:heading {"level":2} -->
		<h2 class="wp-block-heading">What we offer</h2>
		<!-- /wp:heading -->
		<!-- wp:paragraph -->
		<p>One sentence that frames the section and sets up the three cards below.</p>
		<!-- /wp:paragraph -->
	<!-- /wp:ekwa/div -->
	<!-- wp:ekwa/div {"className":"ekwa-p-services__grid"} -->
		<?php foreach ( $cards as $c ) : ?>
		<!-- wp:ekwa/div {"className":"ekwa-p-card"} -->
			<!-- wp:ekwa/icon {"iconClass":"<?php echo esc_attr( $c[0] ); ?>","wrapperClass":"ekwa-p-card__icon"} /-->
			<!-- wp:heading {"level":3} -->
			<h3 class="wp-block-heading"><?php echo esc_html( $c[1] ); ?></h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph -->
			<p><?php echo esc_html( $c[2] ); ?></p>
			<!-- /wp:paragraph -->
			<!-- wp:ekwa/link {"url":"#","text":"Learn more →"} /-->
		<!-- /wp:ekwa/div -->
		<?php endforeach; ?>
	<!-- /wp:ekwa/div -->
<!-- /wp:ekwa/div -->
