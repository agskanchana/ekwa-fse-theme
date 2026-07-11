<?php
/**
 * Title: Section — Stats Row
 * Slug: ekwa/section-stats
 * Categories: ekwa-patterns
 * Description: A row of four headline numbers with labels. Two columns on tablet, one on mobile. Scoped CSS, theme tokens.
 * Keywords: stats, numbers, metrics, results, counters
 */

$bp  = function_exists( 'ekwa_responsive_breakpoints' ) ? ekwa_responsive_breakpoints() : array( 'tablet' => 1199, 'mobile' => 599 );
$css = "
.ekwa-p-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:var(--wp--preset--spacing--lg); padding:var(--wp--preset--spacing--xl) var(--wp--preset--spacing--md); max-width:1100px; margin-inline:auto; text-align:center; }
.ekwa-p-stat__num { display:block; font-size:var(--wp--preset--font-size--hero); line-height:1; font-weight:700; color:var(--wp--preset--color--primary); }
.ekwa-p-stat__label { display:block; margin-top:8px; color:var(--wp--preset--color--foreground-light,#5b6b7c); }
@media (max-width:{$bp['tablet']}px){ .ekwa-p-stats { grid-template-columns:repeat(2,1fr); } }
@media (max-width:{$bp['mobile']}px){ .ekwa-p-stats { grid-template-columns:1fr; } }
";
$attrs = wp_json_encode( array( 'className' => 'ekwa-p-stats', 'scopedCss' => $css ) );

$stats = array(
	array( '20+',  'Years of experience' ),
	array( '15k',  'Happy patients' ),
	array( '98%',  'Satisfaction rate' ),
	array( '24/7', 'Support available' ),
);
?>
<!-- wp:ekwa/div <?php echo $attrs; // phpcs:ignore ?> -->
	<?php foreach ( $stats as $s ) : ?>
	<!-- wp:ekwa/div {"className":"ekwa-p-stat"} -->
		<!-- wp:ekwa/text {"tagName":"span","text":"<?php echo esc_attr( $s[0] ); ?>","className":"ekwa-p-stat__num"} /-->
		<!-- wp:ekwa/text {"tagName":"span","text":"<?php echo esc_attr( $s[1] ); ?>","className":"ekwa-p-stat__label"} /-->
	<!-- /wp:ekwa/div -->
	<?php endforeach; ?>
<!-- /wp:ekwa/div -->
