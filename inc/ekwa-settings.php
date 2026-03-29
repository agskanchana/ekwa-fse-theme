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
		array( 'jquery' ),
		wp_get_theme()->get( 'Version' ),
		true
	);
	wp_localize_script( 'ekwa-admin-js', 'ekwaAdmin', array(
		'mediaTitle'       => __( 'Select or Upload Image', 'ekwa' ),
		'mediaButton'      => __( 'Use this image', 'ekwa' ),
		'confirmRemove'    => __( 'Are you sure you want to remove this item?', 'ekwa' ),
		'noImage'          => __( 'No image selected', 'ekwa' ),
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
	);

	foreach ( $fields as $key => $sanitize ) {
		$value = isset( $_POST[ $key ] ) ? call_user_func( $sanitize, wp_unslash( $_POST[ $key ] ) ) : '';
		update_option( $key, $value );
	}

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
	$locations     = get_option( 'ekwa_locations', array() );
	$social        = get_option( 'ekwa_social', array() );
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
