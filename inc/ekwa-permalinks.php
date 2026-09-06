<?php
/**
 * Permalink default — "Post name" on a newly built site.
 *
 * Every Ekwa site is built against URLs that are agreed up front, so
 * Settings → Permalinks is supposed to read "Post name" (/%postname%/) from the
 * first page onward. WordPress never gives a fresh install that structure: since
 * 4.2 the installer picks "Day and name" when its rewrite test passes and leaves
 * "Plain" when it does not (see wp_install_maybe_enable_pretty_permalinks()).
 * Somebody therefore has to remember to change it by hand, and when they forget
 * it is usually noticed after the wrong URLs are already in a proposal.
 *
 * So the structure is set once, at theme activation, and only where setting it
 * cannot cost anything:
 *
 *  - it runs on after_switch_theme — an explicit "Activate" click. Replacing
 *    this file through the GitHub auto-updater does not fire it, so a live site
 *    that updates the theme is never touched;
 *  - it only replaces a structure WordPress itself chose during installation.
 *    Once a human has picked one — including "Post name" already, and including
 *    a deliberate "Plain" — that choice stands and nothing happens;
 *  - it only runs on a site whose only published content is WordPress' own
 *    sample post and page, because changing the structure changes the URL of
 *    everything already published;
 *  - and it stamps an option, so re-activating the theme later never revisits
 *    the decision.
 *
 * Every case it declines is left to Settings → Permalinks, by hand. There is no
 * UI here and nothing to configure.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The permalink structure Ekwa sites are built against. */
const EKWA_PERMALINK_STRUCTURE = '/%postname%/';

/** Stamped once the activation-time decision has been made, either way. */
const EKWA_PERMALINK_DEFAULT_OPTION = 'ekwa_permalink_default_applied';

/**
 * The permalink structures WordPress picks for itself while installing.
 *
 * Anything outside this list is somebody's deliberate choice and is left alone.
 * The two pretty ones are the pair wp_install_maybe_enable_pretty_permalinks()
 * tries, in its order: mod_rewrite/nginx first, then the PATHINFO fallback for
 * servers without a rewrite module. '' is what is left when both tests fail.
 *
 * @return string[]
 */
function ekwa_permalink_install_defaults() {
	return array(
		'',                                               // "Plain".
		'/%year%/%monthnum%/%day%/%postname%/',           // "Day and name".
		'/index.php/%year%/%monthnum%/%day%/%postname%/', // PATHINFO fallback.
	);
}

/**
 * Whether the site has published content of its own yet.
 *
 * WordPress' installer publishes a sample post and a sample page on every new
 * site, so "no content at all" is never true and cannot be the test. Those two
 * are ignored by slug — through the same translation calls the installer used,
 * so a non-English install matches too — and anything else that already has a
 * live URL counts as real content.
 *
 * Drafts are deliberately not counted: they have no public URL yet, so changing
 * the structure underneath them costs nothing.
 *
 * @return bool True when something other than WordPress' samples is published.
 */
function ekwa_permalink_site_has_content() {
	$ids = get_posts(
		array(
			'post_type'              => array( 'post', 'page' ),
			'post_status'            => array( 'publish', 'future', 'private' ),
			'numberposts'            => 5,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'suppress_filters'       => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	$samples = array(
		'hello-world',
		'sample-page',
		/* translators: Default post slug. */
		sanitize_title( _x( 'hello-world', 'Default post slug' ) ),
		/* translators: Default page slug. */
		sanitize_title( __( 'sample-page' ) ),
	);

	foreach ( $ids as $id ) {
		if ( ! in_array( get_post_field( 'post_name', $id ), $samples, true ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Set "Post name" on activation, when there is nothing to lose by doing it.
 *
 * Runs on the load after the theme is switched, with the theme active. Core
 * flushes rewrite rules itself immediately after this hook, but that flush is
 * soft; the hard flush here also rewrites .htaccess, which is what makes the
 * new structure actually resolve on Apache. That mirrors what the installer
 * does when it enables pretty permalinks.
 *
 * @return void
 */
function ekwa_permalink_apply_default() {
	global $wp_rewrite;

	// Decided once, on the first activation. Never revisited.
	if ( get_option( EKWA_PERMALINK_DEFAULT_OPTION ) ) {
		return;
	}
	update_option( EKWA_PERMALINK_DEFAULT_OPTION, gmdate( 'c' ), false );

	// A structure somebody chose — "Post name" already included — stands.
	$current = (string) get_option( 'permalink_structure', '' );
	if ( ! in_array( $current, ekwa_permalink_install_defaults(), true ) ) {
		return;
	}

	// Published content already has URLs built on the current structure.
	if ( ekwa_permalink_site_has_content() ) {
		return;
	}

	if ( ! ( $wp_rewrite instanceof WP_Rewrite ) ) {
		return;
	}

	$wp_rewrite->set_permalink_structure( EKWA_PERMALINK_STRUCTURE );
	$wp_rewrite->flush_rules( true );
}
add_action( 'after_switch_theme', 'ekwa_permalink_apply_default' );
