<?php
/**
 * Title: Section — FAQ
 * Slug: ekwa/section-faq
 * Categories: ekwa-patterns
 * Description: A heading with an Ekwa FAQ accordion (FAQPage schema included). Scoped CSS, theme tokens.
 * Keywords: faq, questions, accordion, support, schema
 */

$css = "
.ekwa-p-faq { padding:var(--wp--preset--spacing--2-xl) var(--wp--preset--spacing--md); max-width:820px; margin-inline:auto; }
.ekwa-p-faq__head { text-align:center; margin-bottom:var(--wp--preset--spacing--lg); }
.ekwa-p-faq__head h2 { margin:0 0 var(--wp--preset--spacing--xs); font-size:var(--wp--preset--font-size--xl); }
.ekwa-p-faq__head p { margin:0; color:var(--wp--preset--color--foreground-light,#5b6b7c); }
";
$attrs = wp_json_encode( array( 'className' => 'ekwa-p-faq', 'scopedCss' => $css ) );

$faqs = array(
	array( 'What should I expect at my first visit?', 'Answer the question plainly in one or two sentences.' ),
	array( 'Do you accept insurance?',                 'Answer the question plainly in one or two sentences.' ),
	array( 'How do I book an appointment?',            'Answer the question plainly in one or two sentences.' ),
);
?>
<!-- wp:ekwa/div <?php echo $attrs; // phpcs:ignore ?> -->
	<!-- wp:ekwa/div {"className":"ekwa-p-faq__head"} -->
		<!-- wp:heading {"level":2} -->
		<h2 class="wp-block-heading">Frequently asked questions</h2>
		<!-- /wp:heading -->
		<!-- wp:paragraph -->
		<p>Answer the questions that come up before someone books.</p>
		<!-- /wp:paragraph -->
	<!-- /wp:ekwa/div -->
	<!-- wp:ekwa/faq -->
		<?php foreach ( $faqs as $i => $f ) : ?>
		<!-- wp:ekwa/faq-item {"question":"<?php echo esc_attr( $f[0] ); ?>"<?php echo 0 === $i ? ',"defaultOpen":true' : ''; ?>} -->
			<!-- wp:paragraph -->
			<p><?php echo esc_html( $f[1] ); ?></p>
			<!-- /wp:paragraph -->
		<!-- /wp:ekwa/faq-item -->
		<?php endforeach; ?>
	<!-- /wp:ekwa/faq -->
<!-- /wp:ekwa/div -->
