<?php
/**
 * Ekwa Theme Settings Page.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirect to settings page on theme activation.
 */
function ekwa_activation_redirect() {
	global $pagenow;
	if ( 'themes.php' === $pagenow && isset( $_GET['activated'] ) ) {
		wp_safe_redirect( admin_url( 'themes.php?page=ekwa-settings' ) );
		exit;
	}
}
add_action( 'after_switch_theme', 'ekwa_activation_redirect' );

/**
 * Register the settings page under Appearance.
 */
function ekwa_add_settings_page() {
	add_theme_page(
		__( 'Ekwa Settings', 'ekwa' ),
		__( 'Ekwa Settings', 'ekwa' ),
		'manage_options',
		'ekwa-settings',
		'ekwa_render_settings_page'
	);
}
add_action( 'admin_menu', 'ekwa_add_settings_page' );

/**
 * Enqueue admin assets only on the settings page.
 */
function ekwa_admin_enqueue( $hook ) {
	if ( 'appearance_page_ekwa-settings' !== $hook ) {
		return;
	}
	wp_enqueue_media();
	wp_enqueue_style( 'wp-color-picker' );
	// Font Awesome — needed so icon previews render inside the admin settings page.
	wp_enqueue_style(
		'font-awesome',
		get_template_directory_uri() . '/assets/fontawesome/css/all.min.css',
		array(),
		'6.5.1'
	);
	wp_enqueue_style(
		'ekwa-admin-css',
		get_template_directory_uri() . '/assets/css/ekwa-admin.css',
		array(),
		wp_get_theme()->get( 'Version' )
	);
	wp_enqueue_script(
		'ekwa-admin-js',
		get_template_directory_uri() . '/assets/js/ekwa-admin.js',
		array( 'jquery', 'wp-color-picker' ),
		wp_get_theme()->get( 'Version' ),
		true
	);
	wp_localize_script( 'ekwa-admin-js', 'ekwaAdmin', array(
		'mediaTitle'       => __( 'Select or Upload Image', 'ekwa' ),
		'mediaButton'      => __( 'Use this image', 'ekwa' ),
		'confirmRemove'    => __( 'Are you sure you want to remove this item?', 'ekwa' ),
		'noImage'          => __( 'No image selected', 'ekwa' ),
		'webpRegenUrl'     => esc_url_raw( rest_url( 'ekwa/v1/webp-regen-batch' ) ),
		'webpRestNonce'    => wp_create_nonce( 'wp_rest' ),
		'webpStrings'      => array(
			'starting'  => __( 'Starting…', 'ekwa' ),
			'progress'  => __( '%1$s of %2$s processed', 'ekwa' ),
			'done'      => __( 'Done. %s files generated.', 'ekwa' ),
			'error'     => __( 'Error during regeneration. Check console for details.', 'ekwa' ),
		),
	) );
}
add_action( 'admin_enqueue_scripts', 'ekwa_admin_enqueue' );

/**
 * Get all organization types.
 */
function ekwa_get_organization_types() {
	return array(
		'AccountingService'      => __( 'Accounting Service', 'ekwa' ),
		'MedicalClinic'          => __( 'Medical Clinic', 'ekwa' ),
		'Physician'              => __( 'Physician', 'ekwa' ),
		'VeterinaryCare'         => __( 'Veterinary Care', 'ekwa' ),
		'Store'                  => __( 'Store', 'ekwa' ),
		'Attorney'               => __( 'Attorney', 'ekwa' ),
		'HealthAndBeautyBusiness' => __( 'Health And Beauty Business', 'ekwa' ),
		'BeautySalon'            => __( 'Beauty Salon', 'ekwa' ),
		'Dentist'                => __( 'Dentist', 'ekwa' ),
		'DaySpa'                 => __( 'Day Spa', 'ekwa' ),
		'Hospital'               => __( 'Hospital', 'ekwa' ),
		'PetStore'               => __( 'Pet Store', 'ekwa' ),
		'SelfStorage'            => __( 'Self Storage', 'ekwa' ),
		'EmergencyService'       => __( 'Emergency Service', 'ekwa' ),
		'ProfessionalService'    => __( 'Professional Service', 'ekwa' ),
		'Winery'                 => __( 'Winery', 'ekwa' ),
	);
}

/**
 * Get country options.
 */
function ekwa_get_countries() {
	return array(
		'United States'  => __( 'United States', 'ekwa' ),
		'Canada'         => __( 'Canada', 'ekwa' ),
		'Australia'      => __( 'Australia', 'ekwa' ),
		'England'        => __( 'England', 'ekwa' ),
		'Online Based'   => __( 'Online Based', 'ekwa' ),
	);
}

/**
 * Get days of the week.
 */
function ekwa_get_days() {
	return array(
		'Monday', 'Tuesday', 'Wednesday', 'Thursday',
		'Friday', 'Saturday', 'Sunday',
	);
}

/**
 * Resolve the configured appointment URL.
 *
 * Reads `ekwa_appt_type` and returns either the chosen page's permalink
 * or the configured external URL. Returns an empty string when nothing
 * is configured. Caller is responsible for esc_url() on output.
 *
 * @return string
 */
function ekwa_get_appointment_url() {
	$type = get_option( 'ekwa_appt_type', 'page' );
	if ( 'url' === $type ) {
		return (string) get_option( 'ekwa_appt_url', '' );
	}
	$page_id = absint( get_option( 'ekwa_appt_page', 0 ) );
	if ( ! $page_id ) {
		return '';
	}
	$link = get_permalink( $page_id );
	return $link ? $link : '';
}

/**
 * Sanitize a color value — accepts hex (#abc/#aabbcc) or rgb()/rgba(); empty string allowed.
 */
function ekwa_sanitize_color( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}
	$hex = sanitize_hex_color( $value );
	if ( $hex ) {
		return $hex;
	}
	if ( preg_match( '/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d+)\s*)?\)$/i', $value ) ) {
		return $value;
	}
	return '';
}

/**
 * Sanitize locations data.
 */
function ekwa_sanitize_locations( $locations ) {
	if ( ! is_array( $locations ) ) {
		return array();
	}
	$clean = array();
	foreach ( $locations as $loc ) {
		if ( ! is_array( $loc ) ) {
			continue;
		}
		$clean_loc = array(
			'phone_new'      => sanitize_text_field( $loc['phone_new'] ?? '' ),
			'phone_existing' => sanitize_text_field( $loc['phone_existing'] ?? '' ),
			'direction'      => esc_url_raw( $loc['direction'] ?? '' ),
			'street'         => sanitize_text_field( $loc['street'] ?? '' ),
			'city'           => sanitize_text_field( $loc['city'] ?? '' ),
			'state'          => sanitize_text_field( $loc['state'] ?? '' ),
			'zip'            => sanitize_text_field( $loc['zip'] ?? '' ),
			'latitude'       => sanitize_text_field( $loc['latitude'] ?? '' ),
			'longitude'      => sanitize_text_field( $loc['longitude'] ?? '' ),
			'working_hours'  => array(),
		);
		if ( isset( $loc['working_hours'] ) && is_array( $loc['working_hours'] ) ) {
			foreach ( $loc['working_hours'] as $wh ) {
				if ( ! is_array( $wh ) ) {
					continue;
				}
				$clean_loc['working_hours'][] = array(
					'day'            => sanitize_text_field( $wh['day'] ?? 'Monday' ),
					'open_hour'      => sanitize_text_field( $wh['open_hour'] ?? '09' ),
					'open_min'       => sanitize_text_field( $wh['open_min'] ?? '00' ),
					'open_period'    => sanitize_text_field( $wh['open_period'] ?? 'AM' ),
					'close_hour'     => sanitize_text_field( $wh['close_hour'] ?? '05' ),
					'close_min'      => sanitize_text_field( $wh['close_min'] ?? '00' ),
					'close_period'   => sanitize_text_field( $wh['close_period'] ?? 'PM' ),
					'closed'         => ! empty( $wh['closed'] ) ? 1 : 0,
					'extra_note'     => sanitize_text_field( $wh['extra_note'] ?? '' ),
				);
			}
		}
		$clean[] = $clean_loc;
	}
	return $clean;
}

/**
 * Sanitize social media data.
 */
function ekwa_sanitize_social( $social ) {
	if ( ! is_array( $social ) ) {
		return array();
	}
	$clean = array();
	foreach ( $social as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$clean[] = array(
			'name'  => sanitize_text_field( $item['name'] ?? '' ),
			'link'  => esc_url_raw( $item['link'] ?? '' ),
			'icon'  => sanitize_text_field( $item['icon'] ?? '' ),
		);
	}
	return $clean;
}

/**
 * Handle form submission.
 */
function ekwa_save_settings() {
	if ( ! isset( $_POST['ekwa_settings_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( $_POST['ekwa_settings_nonce'], 'ekwa_save_settings' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$fields = array(
		'ekwa_client_name'      => 'sanitize_text_field',
		'ekwa_practice_name'    => 'sanitize_text_field',
		'ekwa_organization_type' => 'sanitize_text_field',
		'ekwa_adsense_number'   => 'sanitize_text_field',
		'ekwa_email'            => 'sanitize_email',
		'ekwa_contact_page'     => 'absint',
		'ekwa_author_page'      => 'absint',
		'ekwa_appt_type'        => 'sanitize_text_field',
		'ekwa_appt_page'        => 'absint',
		'ekwa_appt_url'         => 'esc_url_raw',
		'ekwa_publisher_logo'   => 'absint',
		'ekwa_share_image'      => 'absint',
		'ekwa_country'          => 'sanitize_text_field',
		'ekwa_country_custom'   => 'sanitize_text_field',
		'ekwa_gemini_api_key'   => 'sanitize_text_field',
		'ekwa_mmenu_bg'           => 'ekwa_sanitize_color',
		'ekwa_mmenu_text'         => 'ekwa_sanitize_color',
		'ekwa_mmenu_icon'         => 'ekwa_sanitize_color',
		'ekwa_mmenu_divider'      => 'ekwa_sanitize_color',
		'ekwa_mmenu_navbar_bg'    => 'ekwa_sanitize_color',
		'ekwa_mmenu_navbar_text'  => 'ekwa_sanitize_color',
		'ekwa_related_posts_date_format'    => 'sanitize_text_field',
		'ekwa_related_posts_excerpt_words'  => 'absint',
	);

	foreach ( $fields as $key => $sanitize ) {
		$value = isset( $_POST[ $key ] ) ? call_user_func( $sanitize, wp_unslash( $_POST[ $key ] ) ) : '';
		update_option( $key, $value );
	}

	// Related Posts template HTML — keep raw markup but normalize line endings.
	if ( isset( $_POST['ekwa_related_posts_template'] ) ) {
		$tpl = wp_unslash( $_POST['ekwa_related_posts_template'] );
		// Strip script/style tags as a safety net; keep everything else verbatim.
		$tpl = preg_replace( '#<\s*(script|style)[^>]*>.*?<\s*/\s*\1\s*>#is', '', $tpl );
		update_option( 'ekwa_related_posts_template', $tpl );
	}

	// WebP options — checkboxes need explicit handling so unchecked saves as 0.
	update_option( 'ekwa_webp_enabled', isset( $_POST['ekwa_webp_enabled'] ) ? 1 : 0 );
	update_option( 'ekwa_webp_apply_core_image', isset( $_POST['ekwa_webp_apply_core_image'] ) ? 1 : 0 );
	$quality = isset( $_POST['ekwa_webp_quality'] ) ? (int) $_POST['ekwa_webp_quality'] : 82;
	if ( $quality < 50 ) { $quality = 50; }
	if ( $quality > 100 ) { $quality = 100; }
	update_option( 'ekwa_webp_quality', $quality );

	// If custom country is entered, use that.
	$country = get_option( 'ekwa_country', '' );
	if ( 'custom' === $country ) {
		update_option( 'ekwa_country', get_option( 'ekwa_country_custom', '' ) );
	}
	delete_option( 'ekwa_country_custom' );

	// Site logo — stored as custom_logo theme mod so the core Site Logo block uses it.
	$site_logo_id = isset( $_POST['ekwa_site_logo'] ) ? absint( wp_unslash( $_POST['ekwa_site_logo'] ) ) : 0;
	if ( $site_logo_id ) {
		set_theme_mod( 'custom_logo', $site_logo_id );
	} else {
		remove_theme_mod( 'custom_logo' );
	}

	// Locations repeater.
	$locations = isset( $_POST['ekwa_locations'] ) && is_array( $_POST['ekwa_locations'] )
		? ekwa_sanitize_locations( wp_unslash( $_POST['ekwa_locations'] ) )
		: array();
	update_option( 'ekwa_locations', $locations );

	// Social media repeater.
	$social = isset( $_POST['ekwa_social'] ) && is_array( $_POST['ekwa_social'] )
		? ekwa_sanitize_social( wp_unslash( $_POST['ekwa_social'] ) )
		: array();
	update_option( 'ekwa_social', $social );

	add_settings_error( 'ekwa_settings', 'ekwa_saved', __( 'Settings saved.', 'ekwa' ), 'updated' );
}
add_action( 'admin_init', 'ekwa_save_settings' );

/**
 * Render the settings page.
 */
function ekwa_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$client_name   = get_option( 'ekwa_client_name', '' );
	$practice_name = get_option( 'ekwa_practice_name', '' );
	$org_type      = get_option( 'ekwa_organization_type', '' );
	$adsense       = get_option( 'ekwa_adsense_number', '' );
	$email         = get_option( 'ekwa_email', '' );
	$contact_page  = get_option( 'ekwa_contact_page', 0 );
	$author_page   = get_option( 'ekwa_author_page', 0 );
	$appt_type     = get_option( 'ekwa_appt_type', 'page' );
	$appt_page     = get_option( 'ekwa_appt_page', 0 );
	$appt_url      = get_option( 'ekwa_appt_url', '' );
	$site_logo     = get_theme_mod( 'custom_logo', 0 );
	$pub_logo      = get_option( 'ekwa_publisher_logo', 0 );
	$share_img     = get_option( 'ekwa_share_image', 0 );
	$country       = get_option( 'ekwa_country', '' );
	$mmenu_bg          = get_option( 'ekwa_mmenu_bg', '' );
	$mmenu_text        = get_option( 'ekwa_mmenu_text', '' );
	$mmenu_icon        = get_option( 'ekwa_mmenu_icon', '' );
	$mmenu_divider     = get_option( 'ekwa_mmenu_divider', '' );
	$mmenu_navbar_bg   = get_option( 'ekwa_mmenu_navbar_bg', '' );
	$mmenu_navbar_text = get_option( 'ekwa_mmenu_navbar_text', '' );
	$locations     = get_option( 'ekwa_locations', array() );
	$social        = get_option( 'ekwa_social', array() );
	$rp_template   = get_option( 'ekwa_related_posts_template', '' );
	if ( '' === trim( $rp_template ) ) {
		$rp_template = ekwa_related_posts_default_template();
	}
	$rp_date_fmt   = get_option( 'ekwa_related_posts_date_format', 'M j, Y' );
	$rp_words      = (int) get_option( 'ekwa_related_posts_excerpt_words', 22 );
	if ( $rp_words < 1 ) { $rp_words = 22; }
	$pages         = get_pages();
	$countries     = ekwa_get_countries();
	$org_types     = ekwa_get_organization_types();
	$days          = ekwa_get_days();

	$is_custom_country = ! empty( $country ) && ! array_key_exists( $country, $countries );

	settings_errors( 'ekwa_settings' );
	?>
	<div class="wrap ekwa-settings-wrap">
		<h1><?php esc_html_e( 'Ekwa Theme Settings', 'ekwa' ); ?></h1>
		<form method="post" action="" class="ekwa-settings-form">
			<?php wp_nonce_field( 'ekwa_save_settings', 'ekwa_settings_nonce' ); ?>

			<!-- ========== BUSINESS INFO ========== -->
			<div class="ekwa-section">
				<h2><?php esc_html_e( 'Business Information', 'ekwa' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="ekwa_client_name"><?php esc_html_e( 'Client Name', 'ekwa' ); ?></label></th>
						<td><input type="text" id="ekwa_client_name" name="ekwa_client_name" value="<?php echo esc_attr( $client_name ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="ekwa_practice_name"><?php esc_html_e( 'Practice Name', 'ekwa' ); ?></label></th>
						<td><input type="text" id="ekwa_practice_name" name="ekwa_practice_name" value="<?php echo esc_attr( $practice_name ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="ekwa_organization_type"><?php esc_html_e( 'Organization Type', 'ekwa' ); ?></label></th>
						<td>
							<select id="ekwa_organization_type" name="ekwa_organization_type">
								<option value=""><?php esc_html_e( '— Select —', 'ekwa' ); ?></option>
								<?php foreach ( $org_types as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $org_type, $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="ekwa_adsense_number"><?php esc_html_e( 'Adsense Number', 'ekwa' ); ?></label></th>
						<td><input type="text" id="ekwa_adsense_number" name="ekwa_adsense_number" value="<?php echo esc_attr( $adsense ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="ekwa_email"><?php esc_html_e( 'Email Address', 'ekwa' ); ?></label></th>
						<td><input type="email" id="ekwa_email" name="ekwa_email" value="<?php echo esc_attr( $email ); ?>" class="regular-text" /></td>
					</tr>
				</table>
			</div>

			<!-- ========== PAGE SELECTIONS ========== -->
			<div class="ekwa-section">
				<h2><?php esc_html_e( 'Page Settings', 'ekwa' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="ekwa_contact_page"><?php esc_html_e( 'Contact Page', 'ekwa' ); ?></label></th>
						<td>
							<select id="ekwa_contact_page" name="ekwa_contact_page">
								<option value="0"><?php esc_html_e( '— Select Page —', 'ekwa' ); ?></option>
								<?php foreach ( $pages as $p ) : ?>
									<option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( $contact_page, $p->ID ); ?>><?php echo esc_html( $p->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="ekwa_author_page"><?php esc_html_e( 'Author Page', 'ekwa' ); ?></label></th>
						<td>
							<select id="ekwa_author_page" name="ekwa_author_page">
								<option value="0"><?php esc_html_e( '— Select Page —', 'ekwa' ); ?></option>
								<?php foreach ( $pages as $p ) : ?>
									<option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( $author_page, $p->ID ); ?>><?php echo esc_html( $p->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>
			</div>

			<!-- ========== APPOINTMENT ========== -->
			<div class="ekwa-section">
				<h2><?php esc_html_e( 'Appointment Settings', 'ekwa' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Appointment Page Type', 'ekwa' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="ekwa_appt_type" value="page" <?php checked( $appt_type, 'page' ); ?> class="ekwa-appt-type-radio" /> <?php esc_html_e( 'Select Existing Page', 'ekwa' ); ?></label><br>
								<label><input type="radio" name="ekwa_appt_type" value="url" <?php checked( $appt_type, 'url' ); ?> class="ekwa-appt-type-radio" /> <?php esc_html_e( 'External URL', 'ekwa' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr class="ekwa-appt-page-row" <?php echo 'url' === $appt_type ? 'style="display:none;"' : ''; ?>>
						<th><label for="ekwa_appt_page"><?php esc_html_e( 'Appointment Page', 'ekwa' ); ?></label></th>
						<td>
							<select id="ekwa_appt_page" name="ekwa_appt_page">
								<option value="0"><?php esc_html_e( '— Select Page —', 'ekwa' ); ?></option>
								<?php foreach ( $pages as $p ) : ?>
									<option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( $appt_page, $p->ID ); ?>><?php echo esc_html( $p->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr class="ekwa-appt-url-row" <?php echo 'page' === $appt_type ? 'style="display:none;"' : ''; ?>>
						<th><label for="ekwa_appt_url"><?php esc_html_e( 'Appointment External URL', 'ekwa' ); ?></label></th>
						<td>
							<input type="url" id="ekwa_appt_url" name="ekwa_appt_url" value="<?php echo esc_url( $appt_url ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Enter the full URL including https://', 'ekwa' ); ?>" />
						</td>
					</tr>
				</table>
			</div>

			<!-- ========== MEDIA ========== -->
			<div class="ekwa-section">
				<h2><?php esc_html_e( 'Media', 'ekwa' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label><?php esc_html_e( 'Site Logo', 'ekwa' ); ?></label></th>
						<td>
							<div class="ekwa-media-field" data-width="300" data-height="100">
								<input type="hidden" name="ekwa_site_logo" value="<?php echo esc_attr( $site_logo ); ?>" class="ekwa-media-id" />
								<div class="ekwa-media-preview">
									<?php if ( $site_logo ) : ?>
										<?php echo wp_get_attachment_image( $site_logo, array( 150, 50 ) ); ?>
									<?php else : ?>
										<span class="ekwa-no-image"><?php esc_html_e( 'No image selected', 'ekwa' ); ?></span>
									<?php endif; ?>
								</div>
								<button type="button" class="button ekwa-media-upload"><?php esc_html_e( 'Select Image', 'ekwa' ); ?></button>
								<button type="button" class="button ekwa-media-remove" <?php echo ! $site_logo ? 'style="display:none;"' : ''; ?>><?php esc_html_e( 'Remove', 'ekwa' ); ?></button>
								<p class="description"><?php esc_html_e( 'This sets the site logo used by the core Site Logo block.', 'ekwa' ); ?></p>
							</div>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Publisher Logo', 'ekwa' ); ?></label></th>
						<td>
							<div class="ekwa-media-field" data-width="600" data-height="60">
								<input type="hidden" name="ekwa_publisher_logo" value="<?php echo esc_attr( $pub_logo ); ?>" class="ekwa-media-id" />
								<div class="ekwa-media-preview">
									<?php if ( $pub_logo ) : ?>
										<?php echo wp_get_attachment_image( $pub_logo, array( 300, 30 ) ); ?>
									<?php else : ?>
										<span class="ekwa-no-image"><?php esc_html_e( 'No image selected', 'ekwa' ); ?></span>
									<?php endif; ?>
								</div>
								<button type="button" class="button ekwa-media-upload"><?php esc_html_e( 'Select Image', 'ekwa' ); ?></button>
								<button type="button" class="button ekwa-media-remove" <?php echo ! $pub_logo ? 'style="display:none;"' : ''; ?>><?php esc_html_e( 'Remove', 'ekwa' ); ?></button>
								<p class="description"><?php esc_html_e( 'Recommended: 600px × 60px', 'ekwa' ); ?></p>
							</div>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Share Image', 'ekwa' ); ?></label></th>
						<td>
							<div class="ekwa-media-field" data-width="350" data-height="350">
								<input type="hidden" name="ekwa_share_image" value="<?php echo esc_attr( $share_img ); ?>" class="ekwa-media-id" />
								<div class="ekwa-media-preview">
									<?php if ( $share_img ) : ?>
										<?php echo wp_get_attachment_image( $share_img, array( 175, 175 ) ); ?>
									<?php else : ?>
										<span class="ekwa-no-image"><?php esc_html_e( 'No image selected', 'ekwa' ); ?></span>
									<?php endif; ?>
								</div>
								<button type="button" class="button ekwa-media-upload"><?php esc_html_e( 'Select Image', 'ekwa' ); ?></button>
								<button type="button" class="button ekwa-media-remove" <?php echo ! $share_img ? 'style="display:none;"' : ''; ?>><?php esc_html_e( 'Remove', 'ekwa' ); ?></button>
								<p class="description"><?php esc_html_e( 'Recommended: 350px × 350px', 'ekwa' ); ?></p>
							</div>
						</td>
					</tr>
				</table>
			</div>

			<!-- ========== COUNTRY ========== -->
			<div class="ekwa-section">
				<h2><?php esc_html_e( 'Country', 'ekwa' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="ekwa_country"><?php esc_html_e( 'Country', 'ekwa' ); ?></label></th>
						<td>
							<select id="ekwa_country" name="ekwa_country">
								<option value=""><?php esc_html_e( '— Select —', 'ekwa' ); ?></option>
								<?php foreach ( $countries as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $country, $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
								<option value="custom" <?php echo $is_custom_country ? 'selected' : ''; ?>><?php esc_html_e( 'Enter Manually', 'ekwa' ); ?></option>
							</select>
							<input type="text" id="ekwa_country_custom" name="ekwa_country_custom" value="<?php echo $is_custom_country ? esc_attr( $country ) : ''; ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Enter country name', 'ekwa' ); ?>" <?php echo ! $is_custom_country ? 'style="display:none;"' : ''; ?> />
						</td>
					</tr>
				</table>
			</div>

			<!-- ========== MOBILE MENU COLORS ========== -->
			<div class="ekwa-section">
				<h2><?php esc_html_e( 'Mobile Menu Colors', 'ekwa' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Overrides the off-canvas mobile menu (mmenu) colors. Leave a field empty to use the theme palette default.', 'ekwa' ); ?></p>
				<table class="form-table">
					<tr>
						<th><label for="ekwa_mmenu_bg"><?php esc_html_e( 'Panel Background', 'ekwa' ); ?></label></th>
						<td><input type="text" id="ekwa_mmenu_bg" name="ekwa_mmenu_bg" value="<?php echo esc_attr( $mmenu_bg ); ?>" class="ekwa-color-field" data-default-color="" /></td>
					</tr>
					<tr>
						<th><label for="ekwa_mmenu_text"><?php esc_html_e( 'Menu Item Text', 'ekwa' ); ?></label></th>
						<td><input type="text" id="ekwa_mmenu_text" name="ekwa_mmenu_text" value="<?php echo esc_attr( $mmenu_text ); ?>" class="ekwa-color-field" data-default-color="" /></td>
					</tr>
					<tr>
						<th><label for="ekwa_mmenu_icon"><?php esc_html_e( 'Menu Item Icon', 'ekwa' ); ?></label></th>
						<td><input type="text" id="ekwa_mmenu_icon" name="ekwa_mmenu_icon" value="<?php echo esc_attr( $mmenu_icon ); ?>" class="ekwa-color-field" data-default-color="" /></td>
					</tr>
					<tr>
						<th><label for="ekwa_mmenu_divider"><?php esc_html_e( 'Item Divider', 'ekwa' ); ?></label></th>
						<td><input type="text" id="ekwa_mmenu_divider" name="ekwa_mmenu_divider" value="<?php echo esc_attr( $mmenu_divider ); ?>" class="ekwa-color-field" data-default-color="" /></td>
					</tr>
					<tr>
						<th><label for="ekwa_mmenu_navbar_bg"><?php esc_html_e( 'Sub-page Header Background', 'ekwa' ); ?></label></th>
						<td><input type="text" id="ekwa_mmenu_navbar_bg" name="ekwa_mmenu_navbar_bg" value="<?php echo esc_attr( $mmenu_navbar_bg ); ?>" class="ekwa-color-field" data-default-color="" /></td>
					</tr>
					<tr>
						<th><label for="ekwa_mmenu_navbar_text"><?php esc_html_e( 'Sub-page Header Text', 'ekwa' ); ?></label></th>
						<td><input type="text" id="ekwa_mmenu_navbar_text" name="ekwa_mmenu_navbar_text" value="<?php echo esc_attr( $mmenu_navbar_text ); ?>" class="ekwa-color-field" data-default-color="" /></td>
					</tr>
				</table>
			</div>

			<!-- ========== LOCATIONS REPEATER ========== -->
			<div class="ekwa-section">
				<h2><?php esc_html_e( 'Locations', 'ekwa' ); ?></h2>
				<div id="ekwa-locations-repeater">
					<?php
					if ( ! empty( $locations ) ) :
						foreach ( $locations as $li => $loc ) :
							ekwa_render_location_row( $li, $loc, $days );
						endforeach;
					endif;
					?>
				</div>
				<button type="button" class="button button-primary" id="ekwa-add-location"><?php esc_html_e( '+ Add Location', 'ekwa' ); ?></button>

				<!-- Hidden template for JS cloning -->
				<script type="text/html" id="tmpl-ekwa-location">
					<?php ekwa_render_location_row( '__LOC_INDEX__', array(), $days ); ?>
				</script>
				<script type="text/html" id="tmpl-ekwa-working-hour">
					<?php ekwa_render_working_hour_row( '__LOC_INDEX__', '__WH_INDEX__', array(), $days ); ?>
				</script>
			</div>

			<!-- ========== SOCIAL MEDIA REPEATER ========== -->
			<div class="ekwa-section">
				<h2><?php esc_html_e( 'Social Media Links', 'ekwa' ); ?></h2>
				<div id="ekwa-social-repeater">
					<?php
					if ( ! empty( $social ) ) :
						foreach ( $social as $si => $item ) :
							ekwa_render_social_row( $si, $item );
						endforeach;
					endif;
					?>
				</div>
				<button type="button" class="button button-primary" id="ekwa-add-social"><?php esc_html_e( '+ Add Social Link', 'ekwa' ); ?></button>

				<script type="text/html" id="tmpl-ekwa-social">
					<?php ekwa_render_social_row( '__SOC_INDEX__', array() ); ?>
				</script>
			</div>

			<!-- AI Settings -->
			<h2 class="title" style="margin-top:2em;"><?php esc_html_e( 'AI Settings', 'ekwa' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="ekwa_gemini_api_key"><?php esc_html_e( 'Gemini API Key', 'ekwa' ); ?></label></th>
					<td>
						<?php $gemini_key = get_option( 'ekwa_gemini_api_key', '' ); ?>
						<input type="password" id="ekwa_gemini_api_key" name="ekwa_gemini_api_key" value="<?php echo esc_attr( $gemini_key ); ?>" class="regular-text" autocomplete="off" />
						<p class="description">
							<?php
							if ( defined( 'EKWA_GEMINI_API_KEY' ) && EKWA_GEMINI_API_KEY ) {
								esc_html_e( 'API key is set via wp-config.php (EKWA_GEMINI_API_KEY). This field is ignored.', 'ekwa' );
							} else {
								printf(
									/* translators: %s: link to Google AI Studio */
									esc_html__( 'Get a free API key from %s. Used by the Mockup Converter\'s "Refine with AI" feature.', 'ekwa' ),
									'<a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">Google AI Studio</a>'
								);
							}
							?>
						</p>
					</td>
				</tr>
			</table>

			<!-- ========== WEBP IMAGES ========== -->
			<div class="ekwa-section">
				<h2><?php esc_html_e( 'WebP Images', 'ekwa' ); ?></h2>
				<p class="description" style="margin-bottom:1em;">
					<?php esc_html_e( 'Generates smaller .webp companions for every uploaded image and serves them automatically to browsers that support WebP. The original JPG/PNG is delivered unchanged to browsers that don\'t support WebP — no markup or block changes needed.', 'ekwa' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Enable WebP', 'ekwa' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="ekwa_webp_enabled" value="1" <?php checked( get_option( 'ekwa_webp_enabled', 1 ), 1 ); ?> />
								<?php esc_html_e( 'Auto-generate WebP on upload and swap image URLs for compatible browsers', 'ekwa' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="ekwa_webp_quality"><?php esc_html_e( 'Quality', 'ekwa' ); ?></label></th>
						<td>
							<input type="number" id="ekwa_webp_quality" name="ekwa_webp_quality" min="50" max="100" step="1" value="<?php echo esc_attr( get_option( 'ekwa_webp_quality', 82 ) ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( '50–100. 82 is a good balance of size and visual quality.', 'ekwa' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Apply to core/image', 'ekwa' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="ekwa_webp_apply_core_image" value="1" <?php checked( get_option( 'ekwa_webp_apply_core_image', 1 ), 1 ); ?> />
								<?php esc_html_e( 'Also swap URLs in core WordPress image blocks (recommended)', 'ekwa' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Existing media', 'ekwa' ); ?></th>
						<td>
							<button type="button" class="button button-secondary" id="ekwa-webp-regen-btn"><?php esc_html_e( 'Regenerate WebP for All Images', 'ekwa' ); ?></button>
							<div id="ekwa-webp-regen-status" style="margin-top:8px;"></div>
							<div id="ekwa-webp-regen-progress" style="margin-top:8px;display:none;background:#eee;border-radius:4px;height:14px;overflow:hidden;max-width:400px;">
								<div id="ekwa-webp-regen-bar" style="background:#2271b1;height:100%;width:0;transition:width .15s ease;"></div>
							</div>
							<p class="description"><?php esc_html_e( 'Run this once to convert images uploaded before WebP was enabled. New uploads convert automatically.', 'ekwa' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- ========== RELATED POSTS ========== -->
			<div class="ekwa-section">
				<h2><?php esc_html_e( 'Related Posts', 'ekwa' ); ?></h2>
				<p class="description" style="margin-bottom:1em;">
					<?php esc_html_e( 'Controls the Ekwa Related Posts block that you place inside the footer template part. The block pulls posts whose category slug matches the current page slug, and falls back to a featured-articles category on the home page.', 'ekwa' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th><label for="ekwa_related_posts_template"><?php esc_html_e( 'Post item template', 'ekwa' ); ?></label></th>
						<td>
							<textarea
								id="ekwa_related_posts_template"
								name="ekwa_related_posts_template"
								rows="14"
								class="large-text code"
								spellcheck="false"
								style="font-family:Menlo,Consolas,monospace;font-size:12.5px;"
							><?php echo esc_textarea( $rp_template ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Raw HTML rendered once per post. Use the tokens listed below — they are replaced with the post\'s data at render time.', 'ekwa' ); ?></p>

							<details style="margin-top:10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:10px 14px;">
								<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'Available tokens', 'ekwa' ); ?></summary>
								<table style="width:100%;margin-top:10px;font-size:13px;">
									<tbody>
										<tr><td style="padding:4px 8px;width:240px;"><code>{{title}}</code></td><td>The post title (escaped).</td></tr>
										<tr><td style="padding:4px 8px;"><code>{{title_attr}}</code></td><td>The post title, attribute-safe (for <code>title=""</code>).</td></tr>
										<tr><td style="padding:4px 8px;"><code>{{permalink}}</code></td><td>Full URL to the post.</td></tr>
										<tr><td style="padding:4px 8px;"><code>{{featured_image}}</code></td><td>Full <code>&lt;img&gt;</code> tag at <em>medium_large</em> size.</td></tr>
										<tr><td style="padding:4px 8px;"><code>{{featured_image:size}}</code></td><td>Image at a named size (<code>thumbnail</code>, <code>medium</code>, <code>medium_large</code>, <code>large</code>, <code>full</code>).</td></tr>
										<tr><td style="padding:4px 8px;"><code>{{featured_image_url}}</code></td><td>URL of the featured image (large size).</td></tr>
										<tr><td style="padding:4px 8px;"><code>{{featured_image_url:size}}</code></td><td>URL of the featured image at a named size.</td></tr>
										<tr><td style="padding:4px 8px;"><code>{{date}}</code></td><td>Post date using the format below.</td></tr>
										<tr><td style="padding:4px 8px;"><code>{{date:F j, Y}}</code></td><td>Post date with a custom <a href="https://www.php.net/manual/en/datetime.format.php" target="_blank" rel="noopener">PHP date format</a> inline.</td></tr>
										<tr><td style="padding:4px 8px;"><code>{{excerpt}}</code></td><td>Trimmed excerpt at the word count below.</td></tr>
										<tr><td style="padding:4px 8px;"><code>{{excerpt:30}}</code></td><td>Excerpt trimmed to N words (overrides default).</td></tr>
										<tr><td style="padding:4px 8px;"><code>{{author}}</code></td><td>Author display name.</td></tr>
										<tr><td style="padding:4px 8px;"><code>{{author_url}}</code></td><td>Link to the author page.</td></tr>
										<tr><td style="padding:4px 8px;"><code>{{categories}}</code></td><td>Comma-separated linked category list.</td></tr>
										<tr><td style="padding:4px 8px;"><code>{{read_time}}</code></td><td>Estimated read time, e.g. <em>4 min read</em>.</td></tr>
									</tbody>
								</table>
							</details>

							<p style="margin-top:10px;">
								<button type="button" class="button" id="ekwa-rp-reset-template"><?php esc_html_e( 'Reset to default', 'ekwa' ); ?></button>
								<span id="ekwa-rp-reset-msg" style="margin-left:10px;color:#46b450;display:none;">✓ <?php esc_html_e( 'Reset — remember to save.', 'ekwa' ); ?></span>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="ekwa_related_posts_date_format"><?php esc_html_e( 'Date format', 'ekwa' ); ?></label></th>
						<td>
							<input type="text" id="ekwa_related_posts_date_format" name="ekwa_related_posts_date_format" value="<?php echo esc_attr( $rp_date_fmt ); ?>" class="regular-text" placeholder="M j, Y" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: PHP date format docs URL. */
									esc_html__( 'Format used by the %1$s token. Uses %2$s. Examples: %3$s, %4$s, %5$s.', 'ekwa' ),
									'<code>{{date}}</code>',
									'<a href="https://www.php.net/manual/en/datetime.format.php" target="_blank" rel="noopener">PHP date format</a>',
									'<code>M j, Y</code>',
									'<code>F j, Y</code>',
									'<code>j M Y</code>'
								);
								?>
								<br>
								<?php
								printf(
									/* translators: %s: today's date in the configured format. */
									esc_html__( 'Preview: %s', 'ekwa' ),
									'<strong>' . esc_html( date_i18n( $rp_date_fmt ?: 'M j, Y' ) ) . '</strong>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="ekwa_related_posts_excerpt_words"><?php esc_html_e( 'Excerpt word count', 'ekwa' ); ?></label></th>
						<td>
							<input type="number" id="ekwa_related_posts_excerpt_words" name="ekwa_related_posts_excerpt_words" value="<?php echo esc_attr( $rp_words ); ?>" min="5" max="100" class="small-text" />
							<p class="description"><?php esc_html_e( 'Default word count for the {{excerpt}} token. Per-instance override: {{excerpt:30}}.', 'ekwa' ); ?></p>
						</td>
					</tr>
				</table>
				<script type="text/html" id="tmpl-ekwa-rp-default"><?php echo esc_html( ekwa_related_posts_default_template() ); ?></script>
				<script>
					document.addEventListener( 'DOMContentLoaded', function () {
						var btn  = document.getElementById( 'ekwa-rp-reset-template' );
						var ta   = document.getElementById( 'ekwa_related_posts_template' );
						var tpl  = document.getElementById( 'tmpl-ekwa-rp-default' );
						var msg  = document.getElementById( 'ekwa-rp-reset-msg' );
						if ( btn && ta && tpl ) {
							btn.addEventListener( 'click', function () {
								ta.value = tpl.textContent.trim();
								if ( msg ) {
									msg.style.display = 'inline';
									setTimeout( function () { msg.style.display = 'none'; }, 3000 );
								}
							} );
						}
					} );
				</script>
			</div>

			<?php submit_button( __( 'Save Settings', 'ekwa' ) ); ?>
		</form>
	</div>
	<?php
}

/**
 * Render a single location repeater row.
 */
function ekwa_render_location_row( $index, $data, $days ) {
	$data = wp_parse_args( $data, array(
		'phone_new'      => '',
		'phone_existing' => '',
		'direction'      => '',
		'street'         => '',
		'city'           => '',
		'state'          => '',
		'zip'            => '',
		'latitude'       => '',
		'longitude'      => '',
		'working_hours'  => array(),
	) );
	$prefix = "ekwa_locations[{$index}]";
	?>
	<div class="ekwa-repeater-item ekwa-location-item" data-index="<?php echo esc_attr( $index ); ?>">
		<div class="ekwa-repeater-header">
			<strong><?php esc_html_e( 'Location', 'ekwa' ); ?></strong>
			<button type="button" class="button ekwa-remove-location"><?php esc_html_e( 'Remove', 'ekwa' ); ?></button>
		</div>
		<div class="ekwa-repeater-body">
			<div class="ekwa-field-grid">
				<div class="ekwa-field">
					<label><?php esc_html_e( 'Phone (New Patients)', 'ekwa' ); ?></label>
					<input type="text" name="<?php echo esc_attr( $prefix ); ?>[phone_new]" value="<?php echo esc_attr( $data['phone_new'] ); ?>" />
				</div>
				<div class="ekwa-field">
					<label><?php esc_html_e( 'Phone (Existing Patients)', 'ekwa' ); ?></label>
					<input type="text" name="<?php echo esc_attr( $prefix ); ?>[phone_existing]" value="<?php echo esc_attr( $data['phone_existing'] ); ?>" />
				</div>
				<div class="ekwa-field">
					<label><?php esc_html_e( 'Direction URL', 'ekwa' ); ?></label>
					<input type="url" name="<?php echo esc_attr( $prefix ); ?>[direction]" value="<?php echo esc_url( $data['direction'] ); ?>" />
				</div>
				<div class="ekwa-field">
					<label><?php esc_html_e( 'Street Address', 'ekwa' ); ?></label>
					<input type="text" name="<?php echo esc_attr( $prefix ); ?>[street]" value="<?php echo esc_attr( $data['street'] ); ?>" />
				</div>
				<div class="ekwa-field">
					<label><?php esc_html_e( 'City', 'ekwa' ); ?></label>
					<input type="text" name="<?php echo esc_attr( $prefix ); ?>[city]" value="<?php echo esc_attr( $data['city'] ); ?>" />
				</div>
				<div class="ekwa-field">
					<label><?php esc_html_e( 'State', 'ekwa' ); ?></label>
					<input type="text" name="<?php echo esc_attr( $prefix ); ?>[state]" value="<?php echo esc_attr( $data['state'] ); ?>" />
				</div>
				<div class="ekwa-field">
					<label><?php esc_html_e( 'Zip', 'ekwa' ); ?></label>
					<input type="text" name="<?php echo esc_attr( $prefix ); ?>[zip]" value="<?php echo esc_attr( $data['zip'] ); ?>" />
				</div>
				<div class="ekwa-field">
					<label><?php esc_html_e( 'Latitude', 'ekwa' ); ?></label>
					<input type="text" name="<?php echo esc_attr( $prefix ); ?>[latitude]" value="<?php echo esc_attr( $data['latitude'] ); ?>" />
				</div>
				<div class="ekwa-field">
					<label><?php esc_html_e( 'Longitude', 'ekwa' ); ?></label>
					<input type="text" name="<?php echo esc_attr( $prefix ); ?>[longitude]" value="<?php echo esc_attr( $data['longitude'] ); ?>" />
				</div>
			</div>

			<!-- Working Hours sub-repeater -->
			<div class="ekwa-working-hours-section">
				<h4><?php esc_html_e( 'Working Hours', 'ekwa' ); ?></h4>
				<div class="ekwa-wh-repeater" data-loc-index="<?php echo esc_attr( $index ); ?>">
					<?php
					if ( ! empty( $data['working_hours'] ) ) :
						foreach ( $data['working_hours'] as $wi => $wh ) :
							ekwa_render_working_hour_row( $index, $wi, $wh, $days );
						endforeach;
					endif;
					?>
				</div>
				<button type="button" class="button ekwa-add-wh"><?php esc_html_e( '+ Add Working Hours', 'ekwa' ); ?></button>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Render a single working hours row.
 */
function ekwa_render_working_hour_row( $loc_index, $wh_index, $data, $days ) {
	$data = wp_parse_args( $data, array(
		'day'          => 'Monday',
		'open_hour'    => '09',
		'open_min'     => '00',
		'open_period'  => 'AM',
		'close_hour'   => '05',
		'close_min'    => '00',
		'close_period' => 'PM',
		'closed'       => 0,
		'extra_note'   => '',
	) );
	$prefix = "ekwa_locations[{$loc_index}][working_hours][{$wh_index}]";
	$hours  = array( '01','02','03','04','05','06','07','08','09','10','11','12' );
	$mins   = array( '00','15','30','45' );
	?>
	<div class="ekwa-wh-item" data-wh-index="<?php echo esc_attr( $wh_index ); ?>">
		<div class="ekwa-wh-header">
			<select name="<?php echo esc_attr( $prefix ); ?>[day]" class="ekwa-wh-day">
				<?php foreach ( $days as $d ) : ?>
					<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $data['day'], $d ); ?>><?php echo esc_html( $d ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="ekwa-wh-closed-label">
				<input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[closed]" value="1" <?php checked( $data['closed'], 1 ); ?> class="ekwa-wh-closed-cb" />
				<?php esc_html_e( 'Closed', 'ekwa' ); ?>
			</label>
			<button type="button" class="button ekwa-remove-wh"><?php esc_html_e( 'Remove', 'ekwa' ); ?></button>
		</div>
		<div class="ekwa-wh-times" <?php echo $data['closed'] ? 'style="display:none;"' : ''; ?>>
			<div class="ekwa-wh-time-row">
				<span class="ekwa-wh-label"><?php esc_html_e( 'Opening:', 'ekwa' ); ?></span>
				<select name="<?php echo esc_attr( $prefix ); ?>[open_hour]">
					<?php foreach ( $hours as $h ) : ?>
						<option value="<?php echo esc_attr( $h ); ?>" <?php selected( $data['open_hour'], $h ); ?>><?php echo esc_html( $h ); ?></option>
					<?php endforeach; ?>
				</select>
				<span>:</span>
				<select name="<?php echo esc_attr( $prefix ); ?>[open_min]">
					<?php foreach ( $mins as $m ) : ?>
						<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $data['open_min'], $m ); ?>><?php echo esc_html( $m ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="<?php echo esc_attr( $prefix ); ?>[open_period]">
					<option value="AM" <?php selected( $data['open_period'], 'AM' ); ?>>AM</option>
					<option value="PM" <?php selected( $data['open_period'], 'PM' ); ?>>PM</option>
				</select>
			</div>
			<div class="ekwa-wh-time-row">
				<span class="ekwa-wh-label"><?php esc_html_e( 'Closing:', 'ekwa' ); ?></span>
				<select name="<?php echo esc_attr( $prefix ); ?>[close_hour]">
					<?php foreach ( $hours as $h ) : ?>
						<option value="<?php echo esc_attr( $h ); ?>" <?php selected( $data['close_hour'], $h ); ?>><?php echo esc_html( $h ); ?></option>
					<?php endforeach; ?>
				</select>
				<span>:</span>
				<select name="<?php echo esc_attr( $prefix ); ?>[close_min]">
					<?php foreach ( $mins as $m ) : ?>
						<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $data['close_min'], $m ); ?>><?php echo esc_html( $m ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="<?php echo esc_attr( $prefix ); ?>[close_period]">
					<option value="AM" <?php selected( $data['close_period'], 'AM' ); ?>>AM</option>
					<option value="PM" <?php selected( $data['close_period'], 'PM' ); ?>>PM</option>
				</select>
			</div>
			<div class="ekwa-wh-time-row">
				<span class="ekwa-wh-label"><?php esc_html_e( 'Extra Note:', 'ekwa' ); ?></span>
				<input type="text" name="<?php echo esc_attr( $prefix ); ?>[extra_note]" value="<?php echo esc_attr( $data['extra_note'] ); ?>" placeholder="<?php esc_attr_e( 'e.g., By appointment only', 'ekwa' ); ?>" class="regular-text" />
			</div>
		</div>
	</div>
	<?php
}

/**
 * Render a single social media row.
 */
function ekwa_render_social_row( $index, $data ) {
	$data = wp_parse_args( $data, array(
		'name' => '',
		'link' => '',
		'icon' => '',
	) );
	$prefix = "ekwa_social[{$index}]";
	?>
	<div class="ekwa-repeater-item ekwa-social-item" data-index="<?php echo esc_attr( $index ); ?>">
		<div class="ekwa-repeater-header">
			<strong><?php esc_html_e( 'Social Link', 'ekwa' ); ?></strong>
			<button type="button" class="button ekwa-remove-social"><?php esc_html_e( 'Remove', 'ekwa' ); ?></button>
		</div>
		<div class="ekwa-repeater-body">
			<div class="ekwa-field-grid ekwa-field-grid-3">
				<div class="ekwa-field">
					<label><?php esc_html_e( 'Profile Name', 'ekwa' ); ?></label>
					<input type="text" name="<?php echo esc_attr( $prefix ); ?>[name]" value="<?php echo esc_attr( $data['name'] ); ?>" placeholder="<?php esc_attr_e( 'e.g., Facebook', 'ekwa' ); ?>" />
				</div>
				<div class="ekwa-field">
					<label><?php esc_html_e( 'Link', 'ekwa' ); ?></label>
					<input type="url" name="<?php echo esc_attr( $prefix ); ?>[link]" value="<?php echo esc_url( $data['link'] ); ?>" placeholder="https://" />
				</div>
				<div class="ekwa-field">
					<label><?php esc_html_e( 'Icon Class', 'ekwa' ); ?></label>
					<div class="ekwa-icon-field">
						<div class="ekwa-icon-field__row">
							<span class="ekwa-icon-preview-wrap" title="<?php esc_attr_e( 'Icon preview', 'ekwa' ); ?>">
								<i class="<?php echo esc_attr( $data['icon'] ); ?>"></i>
							</span>
							<input type="text"
								name="<?php echo esc_attr( $prefix ); ?>[icon]"
								value="<?php echo esc_attr( $data['icon'] ); ?>"
								placeholder="<?php esc_attr_e( 'Search or paste icon class…', 'ekwa' ); ?>"
								class="ekwa-icon-input"
								autocomplete="off" />
						</div>
						<div class="ekwa-icon-picker-dropdown"></div>
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php
}
