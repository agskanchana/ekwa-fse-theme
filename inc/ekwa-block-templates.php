<?php
/**
 * Templated dynamic blocks — render the mockup's own HTML with live data.
 *
 * The dynamic data blocks (phone, address, hours, social, copyright) normally
 * render fixed canonical markup. That keeps them reusable, but means a
 * converted mockup's CSS only matches when the mockup used the canonical
 * structures. This module adds an optional `customTemplate` attribute to those
 * blocks: when set, the block renders the template — typically the mockup's
 * original HTML — with {{placeholders}} filled from the SAME settings data the
 * canonical output uses. Dynamic ability unchanged; markup becomes yours.
 *
 *   {{var}}                     scalar placeholder
 *   {{#rows}} … {{/rows}}       repeated once per row (hours, social)
 *
 * Empty/unset template → canonical output, byte-identical to before (100%
 * backward compatible). ekwa/header-menu is deliberately NOT templatable —
 * its structure is owned by the menu walker.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blocks that accept a customTemplate, with their placeholder vocabulary
 * (used by the editor panel help and the AI Convert prompt).
 *
 * @return array<string,array{label:string,placeholders:string}>
 */
function ekwa_tpl_supported_blocks() {
	return array(
		'ekwa/phone'     => array(
			'label'        => __( 'Phone', 'ekwa' ),
			'placeholders' => '{{number}} {{tel}} {{prefix}} {{icon}}',
		),
		'ekwa/address'   => array(
			'label'        => __( 'Address', 'ekwa' ),
			'placeholders' => '{{street}} {{city}} {{state}} {{zip}} {{city_state}} {{full}} {{maps_url}}',
		),
		'ekwa/hours'     => array(
			'label'        => __( 'Hours', 'ekwa' ),
			'placeholders' => '{{#rows}} {{day}} {{time}} {{closed_class}} {{/rows}}',
		),
		'ekwa/social'    => array(
			'label'        => __( 'Social', 'ekwa' ),
			'placeholders' => '{{#links}} {{url}} {{name}} {{icon}} {{/links}} {{share_button}}',
		),
		'ekwa/copyright' => array(
			'label'        => __( 'Copyright', 'ekwa' ),
			'placeholders' => '{{year}} {{name}}',
		),
	);
}

/**
 * Register the customTemplate attribute server-side so the block-renderer
 * REST endpoint (ServerSideRender previews) accepts it.
 */
function ekwa_tpl_register_attribute( $args, $name ) {
	if ( ! array_key_exists( $name, ekwa_tpl_supported_blocks() ) ) {
		return $args;
	}
	if ( empty( $args['attributes'] ) || ! is_array( $args['attributes'] ) ) {
		$args['attributes'] = array();
	}
	$args['attributes']['customTemplate'] = array( 'type' => 'string', 'default' => '' );
	return $args;
}
add_filter( 'register_block_type_args', 'ekwa_tpl_register_attribute', 10, 2 );

/**
 * Sanitize an author-provided template: normal markup allowed, scripts and
 * event handlers stripped.
 *
 * @param string $template
 * @return string
 */
function ekwa_tpl_sanitize( $template ) {
	$common = array(
		'class' => true, 'id' => true, 'style' => true, 'title' => true,
		'aria-label' => true, 'aria-hidden' => true, 'role' => true,
		'data-*' => true,
	);
	$allowed = array(
		'div'    => $common,
		'span'   => $common,
		'p'      => $common,
		'a'      => array_merge( $common, array( 'href' => true, 'target' => true, 'rel' => true ) ),
		'i'      => $common,
		'em'     => $common,
		'strong' => $common,
		'small'  => $common,
		'b'      => $common,
		'u'      => $common,
		'br'     => array(),
		'ul'     => $common,
		'ol'     => $common,
		'li'     => $common,
		'h1'     => $common, 'h2' => $common, 'h3' => $common,
		'h4'     => $common, 'h5' => $common, 'h6' => $common,
		'img'    => array_merge( $common, array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'loading' => true ) ),
		'time'   => array_merge( $common, array( 'datetime' => true ) ),
		'address' => $common,
	);
	return wp_kses( $template, $allowed );
}

/**
 * Fill a template: loops first ({{#key}}…{{/key}} repeated per row), then
 * scalars, then any leftover placeholders are removed.
 *
 * @param string $template Sanitized template.
 * @param array  $vars     Scalars (values pre-escaped).
 * @param array  $loops    key => array of row-var maps.
 * @return string
 */
function ekwa_tpl_render( $template, array $vars, array $loops = array() ) {
	foreach ( $loops as $key => $rows ) {
		$template = preg_replace_callback(
			'/\{\{#' . preg_quote( $key, '/' ) . '\}\}(.*?)\{\{\/' . preg_quote( $key, '/' ) . '\}\}/s',
			function ( $m ) use ( $rows ) {
				$out = '';
				foreach ( $rows as $row ) {
					$chunk = $m[1];
					foreach ( $row as $k => $v ) {
						$chunk = str_replace( '{{' . $k . '}}', $v, $chunk );
					}
					$out .= $chunk;
				}
				return $out;
			},
			$template
		);
	}

	foreach ( $vars as $k => $v ) {
		$template = str_replace( '{{' . $k . '}}', $v, $template );
	}

	// Drop any unreplaced placeholders/sections so they never reach the page.
	$template = preg_replace( '/\{\{[#\/]?[a-z0-9_-]+\}\}/i', '', $template );

	return $template;
}

// ═══════════════════════════════════════════════════════════════════════════════
// PER-BLOCK DATA (same sources as the canonical render callbacks)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Build vars/loops for a block, or null when the block should render nothing
 * (e.g. existing-patients phone during ad tracking).
 *
 * @param string $name  Block name.
 * @param array  $attrs Block attributes.
 * @return array{vars:array,loops:array}|null
 */
function ekwa_tpl_block_data( $name, array $attrs ) {
	$loc_index = max( 1, absint( $attrs['location'] ?? 1 ) ) - 1;
	$locations = get_option( 'ekwa_locations', array() );
	$loc       = isset( $locations[ $loc_index ] ) ? $locations[ $loc_index ] : array();

	switch ( $name ) {

		case 'ekwa/phone':
			$type = ( 'existing' === ( $attrs['type'] ?? 'new' ) ) ? 'existing' : 'new';

			// Mirror the canonical ad-tracking behavior (inc/ekwa-shortcodes.php).
			$is_ad_tracking = isset( $_COOKIE['adward_number'] ) || isset( $_GET['ads'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $is_ad_tracking ) {
				if ( 'existing' === $type ) {
					return null; // Hidden entirely during ad tracking.
				}
				$number = get_option( 'ekwa_adsense_number', '' );
				$prefix = '';
			} else {
				$number = 'existing' === $type
					? ( $loc['phone_existing'] ?? '' )
					: ( $loc['phone_new'] ?? '' );
				$prefix = (string) ( $attrs['prefix'] ?? '' );
				if ( '' === $prefix ) {
					$prefix = 'existing' === $type ? __( 'Existing Patients:', 'ekwa' ) : __( 'New Patients:', 'ekwa' );
				}
			}
			if ( '' === $number ) {
				return null;
			}
			return array(
				'vars'  => array(
					'number' => esc_html( $number ),
					'tel'    => esc_attr( function_exists( 'ekwa_mobile_number' ) ? ekwa_mobile_number( $number ) : preg_replace( '/[^0-9+]/', '', $number ) ),
					'prefix' => esc_html( $prefix ),
					'icon'   => esc_attr( $attrs['iconClass'] ?? 'fa-solid fa-phone' ),
				),
				'loops' => array(),
			);

		case 'ekwa/address':
			$street = sanitize_text_field( $loc['street'] ?? '' );
			$city   = sanitize_text_field( $loc['city'] ?? '' );
			$state  = sanitize_text_field( $loc['state'] ?? '' );
			$zip    = sanitize_text_field( $loc['zip'] ?? '' );
			$maps   = esc_url( $loc['direction'] ?? '' );

			$city_state = trim( implode( ', ', array_filter( array( $city, $state ) ) ) );
			$city_line  = $zip ? trim( $city_state . ' ' . $zip ) : $city_state;
			$full       = trim( implode( ', ', array_filter( array( $street, $city_line ) ) ) );
			if ( '' === $full && '' === $maps ) {
				return null;
			}
			return array(
				'vars'  => array(
					'street'     => esc_html( $street ),
					'city'       => esc_html( $city ),
					'state'      => esc_html( $state ),
					'zip'        => esc_html( $zip ),
					'city_state' => esc_html( $city_state ),
					'full'       => esc_html( $full ),
					'maps_url'   => $maps ? $maps : '#',
				),
				'loops' => array(),
			);

		case 'ekwa/hours':
			$raw = isset( $loc['working_hours'] ) && is_array( $loc['working_hours'] ) ? $loc['working_hours'] : array();
			if ( empty( $raw ) ) {
				return null;
			}
			$closed_label = __( 'Closed', 'ekwa' );
			$rows         = array();
			// Rows are a NUMERIC list; the day name lives inside each row
			// ({"day":"Monday","open_hour":…}) — never use the array index.
			foreach ( $raw as $wh ) {
				if ( ! is_array( $wh ) ) {
					continue;
				}
				$is_closed = ! empty( $wh['closed'] );
				$time      = $is_closed
					? $closed_label
					: ( function_exists( 'ekwa_wh_format_time' )
						? ekwa_wh_format_time( $wh['open_hour'] ?? '', $wh['open_min'] ?? '', $wh['open_period'] ?? '' )
							. ' – '
							. ekwa_wh_format_time( $wh['close_hour'] ?? '', $wh['close_min'] ?? '', $wh['close_period'] ?? '' )
						: '' );
				$rows[] = array(
					'day'          => esc_html( ucfirst( (string) ( $wh['day'] ?? '' ) ) ),
					'time'         => esc_html( $time ),
					'closed_class' => $is_closed ? ' is-closed' : '',
				);
			}
			return array( 'vars' => array(), 'loops' => array( 'rows' => $rows ) );

		case 'ekwa/social':
			$links = get_option( 'ekwa_social', array() );
			if ( empty( $links ) || ! is_array( $links ) ) {
				return null;
			}
			$rows = array();
			foreach ( $links as $link ) {
				$url = esc_url( $link['link'] ?? '' );
				if ( '' === $url ) {
					continue;
				}
				$name = sanitize_text_field( $link['name'] ?? '' );
				$icon = sanitize_text_field( $link['icon'] ?? '' );
				if ( '' === $icon && '' !== $name ) {
					$icon = 'fa-brands fa-' . sanitize_title( $name );
				}
				$rows[] = array(
					'url'  => $url,
					'name' => esc_html( $name ),
					'icon' => esc_attr( $icon ),
				);
			}
			if ( empty( $rows ) ) {
				return null;
			}
			$show_share = ! isset( $attrs['showShare'] ) || (bool) $attrs['showShare'];
			return array(
				'vars'  => array( 'share_button' => $show_share ? ekwa_tpl_share_button_html() : '' ),
				'loops' => array( 'links' => $rows ),
			);

		case 'ekwa/copyright':
			$practice = get_option( 'ekwa_practice_name', '' );
			if ( empty( $practice ) ) {
				$practice = get_theme_mod( 'practise_name', get_bloginfo( 'name' ) );
			}
			return array(
				'vars'  => array(
					'year' => esc_html( wp_date( 'Y' ) ),
					'name' => esc_html( $practice ),
				),
				'loops' => array(),
			);
	}

	return null;
}

/**
 * The canonical share button (same classes and toggle behavior as the
 * ekwa/social block renders) for the {{share_button}} placeholder. Injected
 * as a variable AFTER template sanitization — it is trusted server output.
 *
 * @return string
 */
function ekwa_tpl_share_button_html() {
	static $uid = 0;
	$uid++;
	$id    = 'tpl-' . $uid;
	$js_fn = 'ekwaTplShareToggle' . $uid;

	$permalink = rawurlencode( (string) get_permalink() );
	$title     = rawurlencode( (string) get_the_title() );

	return '<button class="addthis" aria-label="' . esc_attr__( 'Toggle Share', 'ekwa' ) . '" onclick="' . esc_js( $js_fn ) . '()" type="button">'
		. '<i class="fa-solid fa-share-nodes"></i>'
		. '<span class="hide">' . esc_html__( 'Share', 'ekwa' ) . '</span>'
		. '<div id="share-toggle-' . esc_attr( $id ) . '" class="share-toggle">'
		. '<a aria-label="' . esc_attr__( 'Share on Facebook', 'ekwa' ) . '" class="share-facebook" rel="noopener noreferrer"'
		. ' href="https://www.facebook.com/sharer/sharer.php?u=' . $permalink . '&amp;t=' . $title . '"'
		. ' onclick="window.open(this.href,\'\',\'menubar=no,toolbar=no,resizable=yes,scrollbars=yes,height=300,width=600\');return false;"'
		. ' target="_blank"><i class="fa-brands fa-facebook-f"></i></a>'
		. '<a aria-label="' . esc_attr__( 'Share on X / Twitter', 'ekwa' ) . '" class="share-twit" rel="noopener noreferrer"'
		. ' href="https://twitter.com/share?url=' . $permalink . '&amp;text=' . $title . '"'
		. ' onclick="window.open(this.href,\'\',\'menubar=no,toolbar=no,resizable=yes,scrollbars=yes,height=300,width=600\');return false;"'
		. ' target="_blank"><i class="fa-brands fa-x-twitter"></i></a>'
		. '<a aria-label="' . esc_attr__( 'Share on Pinterest', 'ekwa' ) . '" class="share-pinterest" rel="noopener noreferrer"'
		. ' href="https://www.pinterest.com/pin/create/button/?url=' . $permalink . '"'
		. ' target="_blank"><i class="fa-brands fa-pinterest-p"></i></a>'
		. '</div>'
		. '</button>'
		. '<script>function ' . esc_js( $js_fn ) . '(){var el=document.getElementById("share-toggle-' . esc_js( $id ) . '");if(el){el.classList.toggle("active");}}</script>';
}

/**
 * render_block filter: when a supported block carries a customTemplate,
 * replace its canonical output with the rendered template. Runs at priority 5
 * so the inline-assets filter (10) still sees/prepends block CSS afterwards.
 *
 * @param string $content Canonical rendered output.
 * @param array  $block   Parsed block.
 * @return string
 */
function ekwa_tpl_render_block_filter( $content, $block ) {
	$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
	if ( ! array_key_exists( $name, ekwa_tpl_supported_blocks() ) ) {
		return $content;
	}

	$template = isset( $block['attrs']['customTemplate'] ) ? trim( (string) $block['attrs']['customTemplate'] ) : '';
	if ( '' === $template ) {
		return $content;
	}

	$data = ekwa_tpl_block_data( $name, is_array( $block['attrs'] ) ? $block['attrs'] : array() );
	if ( null === $data ) {
		return ''; // No data (or hidden, e.g. ad-tracking) — render nothing.
	}

	return ekwa_tpl_render( ekwa_tpl_sanitize( $template ), $data['vars'], $data['loops'] );
}
add_filter( 'render_block', 'ekwa_tpl_render_block_filter', 5, 2 );

/**
 * Editor: the "Custom HTML template" panel on the supported blocks.
 */
function ekwa_tpl_enqueue_editor() {
	wp_enqueue_script(
		'ekwa-block-templates',
		get_template_directory_uri() . '/assets/js/ekwa-block-templates.js',
		array( 'wp-hooks', 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-compose', 'wp-i18n' ),
		filemtime( get_template_directory() . '/assets/js/ekwa-block-templates.js' ),
		true
	);

	$docs = array();
	foreach ( ekwa_tpl_supported_blocks() as $block => $spec ) {
		$docs[ $block ] = $spec['placeholders'];
	}
	wp_localize_script( 'ekwa-block-templates', 'ekwaBlockTemplates', array( 'placeholders' => $docs ) );
}
add_action( 'enqueue_block_editor_assets', 'ekwa_tpl_enqueue_editor' );
