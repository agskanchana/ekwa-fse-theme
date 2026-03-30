<?php
/**
 * Ekwa Shortcode Builder Admin Page.
 *
 * Registers an "Appearance → Shortcodes" page that lets users configure
 * every [ekwa_*] shortcode through a visual form and then copy the
 * generated shortcode string to the clipboard.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * Register the page under Appearance.
 * ------------------------------------------------------------------ */
function ekwa_add_shortcode_builder_page() {
	add_theme_page(
		__( 'Shortcodes', 'ekwa' ),
		__( 'Shortcodes', 'ekwa' ),
		'manage_options',
		'ekwa-shortcodes',
		'ekwa_render_shortcode_builder_page'
	);
}
add_action( 'admin_menu', 'ekwa_add_shortcode_builder_page' );

/* ------------------------------------------------------------------
 * Enqueue Font Awesome + scoped styles on this page only.
 * ------------------------------------------------------------------ */
function ekwa_shortcode_builder_enqueue( $hook ) {
	if ( 'appearance_page_ekwa-shortcodes' !== $hook ) {
		return;
	}
	wp_enqueue_style(
		'font-awesome',
		get_template_directory_uri() . '/assets/fontawesome/css/all.min.css',
		array(),
		'6.5.1'
	);
}
add_action( 'admin_enqueue_scripts', 'ekwa_shortcode_builder_enqueue' );

/* ------------------------------------------------------------------
 * Render the page.
 * ------------------------------------------------------------------ */
function ekwa_render_shortcode_builder_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	/* ---- Shortcode definitions ----------------------------------------- */
	$shortcodes = array(

		/* ------------------------------------------------------------------ */
		'ekwa_phone' => array(
			'label'       => __( 'Phone Number', 'ekwa' ),
			'icon'        => 'fa-solid fa-phone',
			'description' => __( 'Renders a clickable tel: link with optional ad-tracking support.', 'ekwa' ),
			'attrs'       => array(
				array(
					'key'     => 'type',
					'label'   => __( 'Patient Type', 'ekwa' ),
					'type'    => 'select',
					'options' => array( 'new' => 'New Patients', 'existing' => 'Existing Patients' ),
					'default' => 'new',
					'help'    => __( 'Which phone number to display.', 'ekwa' ),
				),
				array(
					'key'     => 'location',
					'label'   => __( 'Location #', 'ekwa' ),
					'type'    => 'number',
					'default' => '1',
					'min'     => 1,
					'max'     => 10,
					'help'    => __( '1-based index of the saved location.', 'ekwa' ),
				),
				array(
					'key'     => 'prefix',
					'label'   => __( 'Prefix Label', 'ekwa' ),
					'type'    => 'text',
					'default' => '',
					'placeholder' => 'e.g. Call us:',
					'help'    => __( 'Text shown before the number. Leave blank for the default label.', 'ekwa' ),
				),
				array(
					'key'     => 'show_icon',
					'label'   => __( 'Show Icon', 'ekwa' ),
					'type'    => 'toggle',
					'default' => 'true',
					'help'    => __( 'Display the phone icon next to the number.', 'ekwa' ),
				),
				array(
					'key'     => 'icon_class',
					'label'   => __( 'Icon Class', 'ekwa' ),
					'type'    => 'text',
					'default' => 'fa-solid fa-phone',
					'placeholder' => 'fa-solid fa-phone',
					'help'    => __( 'Font Awesome class string for the icon.', 'ekwa' ),
				),
				array(
					'key'     => 'country_code',
					'label'   => __( 'Country Code Override', 'ekwa' ),
					'type'    => 'text',
					'default' => '',
					'placeholder' => 'e.g. 44  |  none',
					'help'    => __( 'Digits only (e.g. 44 for UK). Leave blank for auto-detect. Use "none" to suppress prefix.', 'ekwa' ),
				),
			),
		),

		/* ------------------------------------------------------------------ */
		'ekwa_address' => array(
			'label'       => __( 'Address', 'ekwa' ),
			'icon'        => 'fa-solid fa-location-dot',
			'description' => __( 'Renders a location address with a Google Maps directions link.', 'ekwa' ),
			'attrs'       => array(
				array(
					'key'     => 'location',
					'label'   => __( 'Location #', 'ekwa' ),
					'type'    => 'number',
					'default' => '1',
					'min'     => 1,
					'max'     => 10,
					'help'    => __( '1-based index of the saved location.', 'ekwa' ),
				),
				array(
					'key'     => 'mode',
					'label'   => __( 'Display Mode', 'ekwa' ),
					'type'    => 'select',
					'options' => array(
						'full'    => 'Full Address (Street + City/State/Zip)',
						'address' => 'Address (City, State)',
						'text'    => 'Directions Label',
						'icon'    => 'Icon Only',
					),
					'default' => 'full',
					'help'    => __( 'Controls what text appears in the link.', 'ekwa' ),
				),
				array(
					'key'     => 'label',
					'label'   => __( 'Custom Label', 'ekwa' ),
					'type'    => 'text',
					'default' => '',
					'placeholder' => 'e.g. Get Directions',
					'help'    => __( 'Used when mode="text". Leave blank for "Directions".', 'ekwa' ),
					'show_if_key' => 'mode',
					'show_if_val' => 'text',
				),
				array(
					'key'     => 'show_icon',
					'label'   => __( 'Show Icon', 'ekwa' ),
					'type'    => 'toggle',
					'default' => 'true',
					'help'    => __( 'Display the map-pin icon.', 'ekwa' ),
				),
				array(
					'key'     => 'icon_class',
					'label'   => __( 'Icon Class', 'ekwa' ),
					'type'    => 'text',
					'default' => 'fa-solid fa-location-dot',
					'placeholder' => 'fa-solid fa-location-dot',
					'help'    => __( 'Font Awesome class string for the icon.', 'ekwa' ),
				),
				array(
					'key'     => 'new_tab',
					'label'   => __( 'Open in New Tab', 'ekwa' ),
					'type'    => 'toggle',
					'default' => 'true',
					'help'    => __( 'When checked the directions link opens in a new tab.', 'ekwa' ),
				),
			),
		),

		/* ------------------------------------------------------------------ */
		'ekwa_hours' => array(
			'label'       => __( 'Working Hours', 'ekwa' ),
			'icon'        => 'fa-solid fa-clock',
			'description' => __( 'Displays a formatted working-hours table for a saved location.', 'ekwa' ),
			'attrs'       => array(
				array(
					'key'     => 'location',
					'label'   => __( 'Location #', 'ekwa' ),
					'type'    => 'number',
					'default' => '1',
					'min'     => 1,
					'max'     => 10,
					'help'    => __( '1-based index of the saved location.', 'ekwa' ),
				),
				array(
					'key'     => 'group',
					'label'   => __( 'Day Grouping', 'ekwa' ),
					'type'    => 'select',
					'options' => array(
						'none'        => 'None — one row per day',
						'consecutive' => 'Consecutive — e.g. Mon – Fri: 9:00 AM – 5:00 PM',
						'all'         => 'All — e.g. Mon, Wed, Fri: 9:00 AM – 5:00 PM',
					),
					'default' => 'none',
					'help'    => __( 'How days with identical hours are combined.', 'ekwa' ),
				),
				array(
					'key'     => 'show_closed',
					'label'   => __( 'Show Closed Days', 'ekwa' ),
					'type'    => 'toggle',
					'default' => 'true',
					'help'    => __( 'Include closed days in the displayed table.', 'ekwa' ),
				),
				array(
					'key'     => 'short_days',
					'label'   => __( 'Abbreviate Day Names', 'ekwa' ),
					'type'    => 'toggle',
					'default' => 'false',
					'help'    => __( 'Show Mon/Tue… instead of Monday/Tuesday…', 'ekwa' ),
				),
				array(
					'key'     => 'show_notes',
					'label'   => __( 'Show Extra Notes', 'ekwa' ),
					'type'    => 'toggle',
					'default' => 'true',
					'help'    => __( 'Append the extra_note field to each row.', 'ekwa' ),
				),
				array(
					'key'     => 'closed_label',
					'label'   => __( 'Closed Day Label', 'ekwa' ),
					'type'    => 'text',
					'default' => 'Closed',
					'placeholder' => 'Closed',
					'help'    => __( 'Text displayed for closed days.', 'ekwa' ),
				),
			),
		),

		/* ------------------------------------------------------------------ */
		'ekwa_social' => array(
			'label'       => __( 'Social Icons', 'ekwa' ),
			'icon'        => 'fa-solid fa-share-nodes',
			'description' => __( 'Renders a row of social media icons with an optional share button.', 'ekwa' ),
			'attrs'       => array(
				array(
					'key'     => 'show_share',
					'label'   => __( 'Show Share Button', 'ekwa' ),
					'type'    => 'toggle',
					'default' => 'true',
					'help'    => __( 'Adds a share pop-up with Facebook, X, and Pinterest links.', 'ekwa' ),
				),
			),
		),

		/* ------------------------------------------------------------------ */
		'ekwa_copyright' => array(
			'label'       => __( 'Copyright', 'ekwa' ),
			'icon'        => 'fa-regular fa-copyright',
			'description' => __( 'Renders the site copyright line — no attributes needed.', 'ekwa' ),
			'attrs'       => array(),
		),
	);
	?>
	<div class="wrap ekwa-sc-builder-wrap">
		<h1><i class="fa-solid fa-code" style="margin-right:8px;color:#2271b1;"></i><?php esc_html_e( 'Shortcode Builder', 'ekwa' ); ?></h1>
		<p class="description" style="font-size:14px;margin-bottom:20px;">
			<?php esc_html_e( 'Configure a shortcode using the controls below. The shortcode string updates live and can be copied to the clipboard.', 'ekwa' ); ?>
		</p>

		<!-- Tabs -->
		<div class="ekwa-sc-tabs" role="tablist">
			<?php $first = true; foreach ( $shortcodes as $sc_key => $sc ) : ?>
				<button
					class="ekwa-sc-tab<?php echo $first ? ' ekwa-sc-tab--active' : ''; ?>"
					data-tab="<?php echo esc_attr( $sc_key ); ?>"
					role="tab"
					aria-selected="<?php echo $first ? 'true' : 'false'; ?>"
					type="button">
					<i class="<?php echo esc_attr( $sc['icon'] ); ?>"></i>
					<?php echo esc_html( $sc['label'] ); ?>
				</button>
			<?php $first = false; endforeach; ?>
		</div>

		<!-- Tab panels -->
		<?php $first = true; foreach ( $shortcodes as $sc_key => $sc ) : ?>
		<div
			id="ekwa-sc-panel-<?php echo esc_attr( $sc_key ); ?>"
			class="ekwa-sc-panel<?php echo $first ? ' ekwa-sc-panel--active' : ''; ?>"
			role="tabpanel">

			<div class="ekwa-sc-panel__inner">

				<!-- LEFT: controls -->
				<div class="ekwa-sc-controls">
					<p class="ekwa-sc-desc"><?php echo esc_html( $sc['description'] ); ?></p>

					<?php if ( empty( $sc['attrs'] ) ) : ?>
						<p style="color:#646970;font-style:italic;"><?php esc_html_e( 'This shortcode takes no attributes.', 'ekwa' ); ?></p>
					<?php else : ?>
					<table class="form-table ekwa-sc-form-table">
						<tbody>
						<?php foreach ( $sc['attrs'] as $attr ) :
							$field_id  = 'ekwa-sc-' . $sc_key . '-' . $attr['key'];
							$is_toggle = 'toggle' === $attr['type'];
							$show_if   = isset( $attr['show_if_key'] ) ? $attr['show_if_key'] . ':' . $attr['show_if_val'] : '';
						?>
						<tr class="ekwa-sc-row"
							data-sc="<?php echo esc_attr( $sc_key ); ?>"
							<?php if ( $show_if ) : ?>data-show-if="<?php echo esc_attr( $show_if ); ?>"<?php endif; ?>
							>
							<th scope="row">
								<label for="<?php echo esc_attr( $field_id ); ?>">
									<?php echo esc_html( $attr['label'] ); ?>
								</label>
							</th>
							<td>
								<?php if ( 'select' === $attr['type'] ) : ?>
									<select
										id="<?php echo esc_attr( $field_id ); ?>"
										class="ekwa-sc-input regular-text"
										data-sc="<?php echo esc_attr( $sc_key ); ?>"
										data-attr="<?php echo esc_attr( $attr['key'] ); ?>"
										data-default="<?php echo esc_attr( $attr['default'] ); ?>">
										<?php foreach ( $attr['options'] as $val => $text ) : ?>
											<option value="<?php echo esc_attr( $val ); ?>"
												<?php selected( $val, $attr['default'] ); ?>>
												<?php echo esc_html( $text ); ?>
											</option>
										<?php endforeach; ?>
									</select>

								<?php elseif ( 'toggle' === $attr['type'] ) : ?>
									<label class="ekwa-sc-toggle">
										<input
											type="checkbox"
											id="<?php echo esc_attr( $field_id ); ?>"
											class="ekwa-sc-input ekwa-sc-checkbox"
											data-sc="<?php echo esc_attr( $sc_key ); ?>"
											data-attr="<?php echo esc_attr( $attr['key'] ); ?>"
											data-default="<?php echo esc_attr( $attr['default'] ); ?>"
											<?php checked( 'true', $attr['default'] ); ?>>
										<span class="ekwa-sc-toggle__slider"></span>
									</label>

								<?php elseif ( 'number' === $attr['type'] ) : ?>
									<input
										type="number"
										id="<?php echo esc_attr( $field_id ); ?>"
										class="ekwa-sc-input small-text"
										data-sc="<?php echo esc_attr( $sc_key ); ?>"
										data-attr="<?php echo esc_attr( $attr['key'] ); ?>"
										data-default="<?php echo esc_attr( $attr['default'] ); ?>"
										value="<?php echo esc_attr( $attr['default'] ); ?>"
										min="<?php echo esc_attr( $attr['min'] ?? 1 ); ?>"
										max="<?php echo esc_attr( $attr['max'] ?? 99 ); ?>">

								<?php else : // text ?>
									<input
										type="text"
										id="<?php echo esc_attr( $field_id ); ?>"
										class="ekwa-sc-input regular-text"
										data-sc="<?php echo esc_attr( $sc_key ); ?>"
										data-attr="<?php echo esc_attr( $attr['key'] ); ?>"
										data-default="<?php echo esc_attr( $attr['default'] ); ?>"
										value="<?php echo esc_attr( $attr['default'] ); ?>"
										placeholder="<?php echo esc_attr( $attr['placeholder'] ?? '' ); ?>">
								<?php endif; ?>

								<?php if ( ! empty( $attr['help'] ) ) : ?>
									<p class="description"><?php echo esc_html( $attr['help'] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php endif; ?>
				</div>

				<!-- RIGHT: output -->
				<div class="ekwa-sc-output">
					<div class="ekwa-sc-output__label">
						<?php esc_html_e( 'Generated Shortcode', 'ekwa' ); ?>
					</div>
					<div
						id="ekwa-sc-preview-<?php echo esc_attr( $sc_key ); ?>"
						class="ekwa-sc-preview"
						aria-label="<?php esc_attr_e( 'Generated shortcode', 'ekwa' ); ?>"
						>[<?php echo esc_html( $sc_key ); ?>]</div>

					<div class="ekwa-sc-output__actions">
						<button
							type="button"
							class="button button-primary ekwa-sc-copy"
							data-target="ekwa-sc-preview-<?php echo esc_attr( $sc_key ); ?>">
							<i class="fa-regular fa-copy" style="margin-right:5px;"></i>
							<?php esc_html_e( 'Copy to Clipboard', 'ekwa' ); ?>
						</button>
						<button
							type="button"
							class="button ekwa-sc-reset"
							data-sc="<?php echo esc_attr( $sc_key ); ?>">
							<i class="fa-solid fa-rotate-left" style="margin-right:5px;"></i>
							<?php esc_html_e( 'Reset', 'ekwa' ); ?>
						</button>
						<span class="ekwa-sc-copy-feedback" aria-live="polite"></span>
					</div>

					<!-- Attribute reference table -->
					<?php if ( ! empty( $sc['attrs'] ) ) : ?>
					<details class="ekwa-sc-reference">
						<summary><?php esc_html_e( 'Attribute Reference', 'ekwa' ); ?></summary>
						<table class="ekwa-sc-ref-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Attribute', 'ekwa' ); ?></th>
									<th><?php esc_html_e( 'Default', 'ekwa' ); ?></th>
									<th><?php esc_html_e( 'Description', 'ekwa' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $sc['attrs'] as $attr ) : ?>
								<tr>
									<td><code><?php echo esc_html( $attr['key'] ); ?></code></td>
									<td><code><?php echo '' !== $attr['default'] ? esc_html( $attr['default'] ) : '<em>empty</em>'; ?></code></td>
									<td><?php echo esc_html( $attr['help'] ?? '' ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</details>
					<?php endif; ?>
				</div>

			</div><!-- .ekwa-sc-panel__inner -->
		</div><!-- .ekwa-sc-panel -->
		<?php $first = false; endforeach; ?>
	</div><!-- .ekwa-sc-builder-wrap -->

	<?php
	/* ------------------------------------------------------------------ */
	/* Inline styles                                                        */
	/* ------------------------------------------------------------------ */
	?>
	<style>
	.ekwa-sc-builder-wrap { max-width: 1100px; }

	/* ----- Tabs ----- */
	.ekwa-sc-tabs {
		display: flex;
		flex-wrap: wrap;
		gap: 4px;
		margin: 0 0 0 0;
		border-bottom: 2px solid #c3c4c7;
		padding-bottom: 0;
	}
	.ekwa-sc-tab {
		background: #f6f7f7;
		border: 2px solid #c3c4c7;
		border-bottom: none;
		border-radius: 4px 4px 0 0;
		padding: 8px 18px;
		cursor: pointer;
		font-size: 13px;
		font-weight: 500;
		color: #3c434a;
		display: inline-flex;
		align-items: center;
		gap: 7px;
		transition: background .15s, color .15s;
		position: relative;
		bottom: -2px;
	}
	.ekwa-sc-tab:hover { background: #e8f0fe; color: #2271b1; }
	.ekwa-sc-tab--active {
		background: #fff;
		color: #2271b1;
		border-color: #c3c4c7;
		border-bottom-color: #fff;
		font-weight: 600;
	}

	/* ----- Panel ----- */
	.ekwa-sc-panel { display: none; background: #fff; border: 2px solid #c3c4c7; border-top: none; padding: 24px; }
	.ekwa-sc-panel--active { display: block; }

	.ekwa-sc-panel__inner {
		display: grid;
		grid-template-columns: 1fr 340px;
		gap: 32px;
	}
	@media (max-width: 900px) {
		.ekwa-sc-panel__inner { grid-template-columns: 1fr; }
	}

	.ekwa-sc-desc { color: #646970; margin: 0 0 16px; font-size: 13px; }

	/* ----- Form table ----- */
	.ekwa-sc-form-table th { padding: 10px 20px 10px 0; width: 180px; font-weight: 500; }
	.ekwa-sc-form-table td { padding: 8px 0; }
	.ekwa-sc-form-table .description { margin-top: 5px; color: #646970; font-size: 12px; }
	.ekwa-sc-row[style*="display:none"],
	.ekwa-sc-row.ekwa-hidden { display: none; }

	/* ----- Toggle switch ----- */
	.ekwa-sc-toggle {
		position: relative;
		display: inline-flex;
		align-items: center;
		cursor: pointer;
		height: 24px;
	}
	.ekwa-sc-toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
	.ekwa-sc-toggle__slider {
		display: inline-block;
		width: 44px;
		height: 24px;
		background: #c3c4c7;
		border-radius: 24px;
		transition: background .2s;
		flex-shrink: 0;
	}
	.ekwa-sc-toggle__slider::after {
		content: '';
		display: block;
		width: 18px;
		height: 18px;
		border-radius: 50%;
		background: #fff;
		margin: 3px;
		transition: transform .2s;
		box-shadow: 0 1px 3px rgba(0,0,0,.25);
	}
	.ekwa-sc-toggle input:checked + .ekwa-sc-toggle__slider { background: #2271b1; }
	.ekwa-sc-toggle input:checked + .ekwa-sc-toggle__slider::after { transform: translateX(20px); }

	/* ----- Output panel ----- */
	.ekwa-sc-output {
		position: sticky;
		top: 32px;
		align-self: start;
	}
	.ekwa-sc-output__label {
		font-size: 11px;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: .05em;
		color: #646970;
		margin-bottom: 6px;
	}
	.ekwa-sc-preview {
		background: #1e1e2e;
		color: #cdd6f4;
		font-family: Consolas, 'Courier New', monospace;
		font-size: 14px;
		padding: 16px 18px;
		border-radius: 6px;
		word-break: break-all;
		min-height: 52px;
		white-space: pre-wrap;
		line-height: 1.6;
		user-select: all;
	}
	.ekwa-sc-output__actions {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-top: 12px;
		flex-wrap: wrap;
	}
	.ekwa-sc-copy-feedback {
		font-size: 12px;
		color: #00a32a;
		font-weight: 500;
	}

	/* ----- Attribute reference ----- */
	.ekwa-sc-reference {
		margin-top: 20px;
		border: 1px solid #e0e0e0;
		border-radius: 6px;
		overflow: hidden;
	}
	.ekwa-sc-reference summary {
		background: #f6f7f7;
		padding: 10px 14px;
		cursor: pointer;
		font-size: 12px;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: .05em;
		color: #646970;
		list-style: none;
		user-select: none;
	}
	.ekwa-sc-reference summary::-webkit-details-marker { display: none; }
	.ekwa-sc-reference summary::before {
		content: '\276F';
		display: inline-block;
		margin-right: 8px;
		transition: transform .2s;
	}
	.ekwa-sc-reference[open] summary::before { transform: rotate(90deg); }
	.ekwa-sc-ref-table {
		width: 100%;
		border-collapse: collapse;
		font-size: 12px;
	}
	.ekwa-sc-ref-table th,
	.ekwa-sc-ref-table td {
		padding: 8px 12px;
		border-top: 1px solid #e0e0e0;
		text-align: left;
		vertical-align: top;
	}
	.ekwa-sc-ref-table th { background: #f9f9f9; font-weight: 600; }
	.ekwa-sc-ref-table code {
		background: #f0f0f1;
		padding: 1px 5px;
		border-radius: 3px;
		font-size: 11px;
	}
	</style>

	<?php
	/* ------------------------------------------------------------------ */
	/* Inline JS — tabs + dynamic shortcode builder                        */
	/* ------------------------------------------------------------------ */
	?>
	<script>
	( function () {
		'use strict';

		/* ---- Tab switching -------------------------------------------- */
		document.querySelectorAll( '.ekwa-sc-tab' ).forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				var target = this.dataset.tab;
				document.querySelectorAll( '.ekwa-sc-tab' ).forEach( function ( t ) {
					t.classList.remove( 'ekwa-sc-tab--active' );
					t.setAttribute( 'aria-selected', 'false' );
				} );
				document.querySelectorAll( '.ekwa-sc-panel' ).forEach( function ( p ) {
					p.classList.remove( 'ekwa-sc-panel--active' );
				} );
				this.classList.add( 'ekwa-sc-tab--active' );
				this.setAttribute( 'aria-selected', 'true' );
				var panel = document.getElementById( 'ekwa-sc-panel-' + target );
				if ( panel ) { panel.classList.add( 'ekwa-sc-panel--active' ); }
			} );
		} );

		/* ---- Build shortcode string from current field values ----------- */
		function buildShortcode( scKey ) {
			var parts = [];
			document.querySelectorAll( '.ekwa-sc-input[data-sc="' + scKey + '"]' ).forEach( function ( el ) {
				var attrKey  = el.dataset.attr;
				var defVal   = el.dataset.default;
				var val;

				if ( el.type === 'checkbox' ) {
					val = el.checked ? 'true' : 'false';
				} else {
					val = el.value.trim();
				}

				// Only include the attribute if it differs from the default.
				if ( val !== defVal ) {
					parts.push( attrKey + '="' + val + '"' );
				}
			} );

			return parts.length
				? '[' + scKey + ' ' + parts.join( ' ' ) + ']'
				: '[' + scKey + ']';
		}

		/* ---- Refresh the preview box ------------------------------------ */
		function refreshPreview( scKey ) {
			var preview = document.getElementById( 'ekwa-sc-preview-' + scKey );
			if ( preview ) {
				preview.textContent = buildShortcode( scKey );
			}
		}

		/* ---- Conditional row visibility (show_if_key:val logic) --------- */
		function updateConditionalRows( scKey ) {
			document.querySelectorAll( '.ekwa-sc-row[data-sc="' + scKey + '"][data-show-if]' ).forEach( function ( row ) {
				var cond      = row.dataset.showIf;    // e.g. "mode:text"
				var colonIdx  = cond.indexOf( ':' );
				var watchKey  = cond.slice( 0, colonIdx );
				var watchVal  = cond.slice( colonIdx + 1 );
				var watchEl   = document.querySelector( '.ekwa-sc-input[data-sc="' + scKey + '"][data-attr="' + watchKey + '"]' );
				
				var currentVal = '';
				if ( watchEl ) {
					currentVal = ( watchEl.type === 'checkbox' )
						? ( watchEl.checked ? 'true' : 'false' )
						: watchEl.value;
				}
				row.classList.toggle( 'ekwa-hidden', currentVal !== watchVal );
			} );
		}

		/* ---- Wire up all inputs ---------------------------------------- */
		document.querySelectorAll( '.ekwa-sc-input' ).forEach( function ( el ) {
			var scKey = el.dataset.sc;
			var eventName = ( el.type === 'checkbox' ) ? 'change' : 'input';
			el.addEventListener( eventName, function () {
				updateConditionalRows( scKey );
				refreshPreview( scKey );
			} );
		} );

		/* ---- Initial render: conditionals + previews ------------------- */
		var seen = {};
		document.querySelectorAll( '.ekwa-sc-input' ).forEach( function ( el ) {
			var scKey = el.dataset.sc;
			if ( ! seen[ scKey ] ) {
				seen[ scKey ] = true;
				updateConditionalRows( scKey );
				refreshPreview( scKey );
			}
		} );

		/* ---- Copy to clipboard ---------------------------------------- */
		document.querySelectorAll( '.ekwa-sc-copy' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var targetId  = btn.dataset.target;
				var previewEl = document.getElementById( targetId );
				if ( ! previewEl ) { return; }
				var text     = previewEl.textContent;
				var feedback = btn.closest( '.ekwa-sc-output__actions' ).querySelector( '.ekwa-sc-copy-feedback' );

				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( text ).then( function () {
						showFeedback( feedback );
					} ).catch( function () {
						fallbackCopy( text, feedback );
					} );
				} else {
					fallbackCopy( text, feedback );
				}
			} );
		} );

		function fallbackCopy( text, feedback ) {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.style.position = 'fixed';
			ta.style.opacity  = '0';
			document.body.appendChild( ta );
			ta.focus();
			ta.select();
			try {
				document.execCommand( 'copy' );
				showFeedback( feedback );
			} catch ( e ) {
				if ( feedback ) { feedback.textContent = 'Copy failed — please select and copy manually.'; }
			}
			document.body.removeChild( ta );
		}

		function showFeedback( el ) {
			if ( ! el ) { return; }
			el.textContent = '\u2713 Copied!';
			clearTimeout( el._ekwaTimer );
			el._ekwaTimer = setTimeout( function () { el.textContent = ''; }, 2500 );
		}

		/* ---- Reset button ---------------------------------------------- */
		document.querySelectorAll( '.ekwa-sc-reset' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var scKey = btn.dataset.sc;
				document.querySelectorAll( '.ekwa-sc-input[data-sc="' + scKey + '"]' ).forEach( function ( el ) {
					var def = el.dataset.default;
					if ( el.type === 'checkbox' ) {
						el.checked = ( def === 'true' );
					} else {
						el.value = def;
					}
				} );
				updateConditionalRows( scKey );
				refreshPreview( scKey );
			} );
		} );

	} )();
	</script>
	<?php
}
