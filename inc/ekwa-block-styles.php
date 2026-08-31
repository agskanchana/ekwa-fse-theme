<?php
/**
 * Register block style variations for core blocks.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register all block style variations.
 */
function ekwa_register_block_styles() {

	/* ---------------------------------------------------------------
	 * core/button styles
	 * ------------------------------------------------------------- */

	// Ghost button: transparent bg + white border (for dark sections).
	register_block_style( 'core/button', array(
		'name'  => 'ghost',
		'label' => __( 'Ghost (Transparent)', 'ekwa' ),
	) );

	// Small button: compact padding + smaller font.
	register_block_style( 'core/button', array(
		'name'  => 'size-sm',
		'label' => __( 'Small', 'ekwa' ),
	) );

	// Large button: generous padding + larger font.
	register_block_style( 'core/button', array(
		'name'  => 'size-lg',
		'label' => __( 'Large', 'ekwa' ),
	) );

	/* ---------------------------------------------------------------
	 * core/group styles
	 * ------------------------------------------------------------- */

	// Service card: hover lift + shadow transition.
	register_block_style( 'core/group', array(
		'name'  => 'service-card',
		'label' => __( 'Service Card', 'ekwa' ),
	) );

	// Parallax background: fixed background-attachment.
	register_block_style( 'core/group', array(
		'name'  => 'parallax-bg',
		'label' => __( 'Parallax Background', 'ekwa' ),
	) );

	// Dark overlay via ::before pseudo-element.
	register_block_style( 'core/group', array(
		'name'  => 'has-overlay',
		'label' => __( 'Dark Overlay', 'ekwa' ),
	) );

	/* ---------------------------------------------------------------
	 * core/column styles
	 * ------------------------------------------------------------- */

	// Card column: hover lift effect.
	register_block_style( 'core/column', array(
		'name'  => 'card',
		'label' => __( 'Card', 'ekwa' ),
	) );

	/* ---------------------------------------------------------------
	 * ekwa/faq design variations
	 * ------------------------------------------------------------- */

	register_block_style( 'ekwa/faq', array(
		'name'         => 'default',
		'label'        => __( 'Underline (Animated)', 'ekwa' ),
		'is_default'   => true,
	) );

	register_block_style( 'ekwa/faq', array(
		'name'  => 'bordered',
		'label' => __( 'Bordered Box', 'ekwa' ),
	) );

	register_block_style( 'ekwa/faq', array(
		'name'  => 'boxed',
		'label' => __( 'Boxed Cards', 'ekwa' ),
	) );

	register_block_style( 'ekwa/faq', array(
		'name'  => 'filled',
		'label' => __( 'Filled', 'ekwa' ),
	) );

	register_block_style( 'ekwa/faq', array(
		'name'  => 'minimal',
		'label' => __( 'Minimal', 'ekwa' ),
	) );

	register_block_style( 'ekwa/faq', array(
		'name'  => 'accent',
		'label' => __( 'Accent Bar', 'ekwa' ),
	) );

	register_block_style( 'ekwa/faq', array(
		'name'  => 'plus',
		'label' => __( 'Plus / Cross', 'ekwa' ),
	) );

	register_block_style( 'ekwa/faq', array(
		'name'  => 'numbered',
		'label' => __( 'Numbered', 'ekwa' ),
	) );

	register_block_style( 'ekwa/faq', array(
		'name'  => 'glass',
		'label' => __( 'Glass', 'ekwa' ),
	) );

	register_block_style( 'ekwa/faq', array(
		'name'  => 'elevated',
		'label' => __( 'Elevated Soft', 'ekwa' ),
	) );

	// The escape hatch: the base FAQ styles are guarded with
	// :not(.is-style-custom), so picking this leaves the <details>/<summary>
	// markup with nothing but `cursor: pointer` and the disclosure-triangle
	// reset — at zero specificity, so child CSS wins without !important.
	// Selecting it in the editor also switches Accordion mode and Open-first
	// on (see assets/js/ekwa-faq-editor.js); both stay editable afterwards.
	register_block_style( 'ekwa/faq', array(
		'name'  => 'custom',
		'label' => __( 'Custom (No Styles)', 'ekwa' ),
	) );

	/* ---------------------------------------------------------------
	 * ekwa/inner-banner design variations
	 * ------------------------------------------------------------- */

	register_block_style( 'ekwa/inner-banner', array(
		'name'       => 'classic',
		'label'      => __( 'Classic (Centered)', 'ekwa' ),
		'is_default' => true,
	) );

	register_block_style( 'ekwa/inner-banner', array(
		'name'  => 'left',
		'label' => __( 'Left Aligned', 'ekwa' ),
	) );

	register_block_style( 'ekwa/inner-banner', array(
		'name'  => 'minimal',
		'label' => __( 'Minimal (Solid Band)', 'ekwa' ),
	) );

	register_block_style( 'ekwa/inner-banner', array(
		'name'  => 'gradient',
		'label' => __( 'Gradient', 'ekwa' ),
	) );

	register_block_style( 'ekwa/inner-banner', array(
		'name'  => 'wave',
		'label' => __( 'Wave (Curved Edge)', 'ekwa' ),
	) );

	register_block_style( 'ekwa/inner-banner', array(
		'name'  => 'glass',
		'label' => __( 'Glass Card', 'ekwa' ),
	) );

	register_block_style( 'ekwa/inner-banner', array(
		'name'  => 'duotone',
		'label' => __( 'Duotone', 'ekwa' ),
	) );

	register_block_style( 'ekwa/inner-banner', array(
		'name'  => 'framed',
		'label' => __( 'Framed Card', 'ekwa' ),
	) );

	register_block_style( 'ekwa/inner-banner', array(
		'name'  => 'split',
		'label' => __( 'Split (Side Accent)', 'ekwa' ),
	) );

	/* ---------------------------------------------------------------
	 * ekwa/page-banner
	 *
	 * WordPress allows only ONE is-style-* class at a time, so a variation
	 * cannot be combined with a separate alignment setting. Centered / Left /
	 * Right are therefore the plain "no opinion" choices, and each named look
	 * owns its own alignment as part of the design.
	 *
	 * None of these hardcode a palette — every practice site defines its own
	 * colors and faces, so the variations are built out of structure, rhythm
	 * and edge treatment and only ever reference preset tokens.
	 *
	 * Registered WITHOUT `inline_style`, like every other variation in this
	 * file. That argument makes WordPress append the CSS to its global
	 * wp-block-library-inline-css bundle, which then ships on every page
	 * whether or not the block renders — the opposite of this theme's
	 * render-time inlining. The rules live in blocks/ekwa-page-banner/style.css.
	 * ------------------------------------------------------------- */

	register_block_style( 'ekwa/page-banner', array(
		'name'       => 'center',
		'label'      => __( 'Centered', 'ekwa' ),
		'is_default' => true,
	) );

	register_block_style( 'ekwa/page-banner', array(
		'name'  => 'left',
		'label' => __( 'Left Aligned', 'ekwa' ),
	) );

	register_block_style( 'ekwa/page-banner', array(
		'name'  => 'right',
		'label' => __( 'Right Aligned', 'ekwa' ),
	) );

	register_block_style( 'ekwa/page-banner', array(
		'name'  => 'wayfinding',
		'label' => __( 'Wayfinding (Trail on Top)', 'ekwa' ),
	) );

	register_block_style( 'ekwa/page-banner', array(
		'name'  => 'stripe',
		'label' => __( 'Edge Stripe', 'ekwa' ),
	) );

	register_block_style( 'ekwa/page-banner', array(
		'name'  => 'label',
		'label' => __( 'Label (Panel on Photo)', 'ekwa' ),
	) );

	register_block_style( 'ekwa/page-banner', array(
		'name'  => 'rule',
		'label' => __( 'Rule (Text Only)', 'ekwa' ),
	) );

	register_block_style( 'ekwa/page-banner', array(
		'name'  => 'bar',
		'label' => __( 'Compact Bar', 'ekwa' ),
	) );

	register_block_style( 'ekwa/page-banner', array(
		'name'  => 'frame',
		'label' => __( 'Frame', 'ekwa' ),
	) );

	// The escape hatch: strips every default the theme applies and leaves the
	// markup, so an author can style the banner entirely from its Scoped CSS.
	// The background/overlay LAYERING survives — without it a featured image
	// would render full-size and push the content down the page, which is a bug
	// rather than a blank canvas. Everything visual goes.
	register_block_style( 'ekwa/page-banner', array(
		'name'  => 'custom',
		'label' => __( 'Custom (No Styles)', 'ekwa' ),
	) );
}
add_action( 'init', 'ekwa_register_block_styles' );
