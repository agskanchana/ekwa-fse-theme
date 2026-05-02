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
}
add_action( 'init', 'ekwa_register_block_styles' );
