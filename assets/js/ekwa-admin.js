(function ($) {
	'use strict';

	/* ============================================================
	 *  Color pickers
	 * ============================================================ */
	$(function () {
		if ($.fn.wpColorPicker) {
			$('.ekwa-color-field').wpColorPicker();
		}
	});

	/* ============================================================
	 *  Appointment type toggle
	 * ============================================================ */
	$(document).on('change', '.ekwa-appt-type-radio', function () {
		var val = $(this).val();
		if (val === 'page') {
			$('.ekwa-appt-page-row').show();
			$('.ekwa-appt-url-row').hide();
		} else {
			$('.ekwa-appt-page-row').hide();
			$('.ekwa-appt-url-row').show();
		}
	});

	/* ============================================================
	 *  Country custom field toggle
	 * ============================================================ */
	$(document).on('change', '#ekwa_country', function () {
		if ($(this).val() === 'custom') {
			$('#ekwa_country_custom').show().focus();
		} else {
			$('#ekwa_country_custom').hide();
		}
	});

	/* ============================================================
	 *  Working-hours closed checkbox toggle
	 * ============================================================ */
	$(document).on('change', '.ekwa-wh-closed-cb', function () {
		$(this).closest('.ekwa-wh-item').find('.ekwa-wh-times').toggle(!this.checked);
	});

	/* ============================================================
	 *  Media uploader
	 * ============================================================ */
	$(document).on('click', '.ekwa-media-upload', function (e) {
		e.preventDefault();
		var wrap = $(this).closest('.ekwa-media-field');
		var frame = wp.media({
			title: ekwaAdmin.mediaTitle,
			button: { text: ekwaAdmin.mediaButton },
			multiple: false
		});
		frame.on('select', function () {
			var attachment = frame.state().get('selection').first().toJSON();
			wrap.find('.ekwa-media-id').val(attachment.id);
			var thumb = attachment.sizes && attachment.sizes.thumbnail
				? attachment.sizes.thumbnail.url
				: attachment.url;
			wrap.find('.ekwa-media-preview').html(
				'<img src="' + thumb + '" style="max-width:300px;height:auto;" />'
			);
			wrap.find('.ekwa-media-remove').show();
		});
		frame.open();
	});

	$(document).on('click', '.ekwa-media-remove', function (e) {
		e.preventDefault();
		var wrap = $(this).closest('.ekwa-media-field');
		wrap.find('.ekwa-media-id').val('');
		wrap.find('.ekwa-media-preview').html(
			'<span class="ekwa-no-image">' + ekwaAdmin.noImage + '</span>'
		);
		$(this).hide();
	});

	/* ============================================================
	 *  Helper: reindex names inside a container
	 * ============================================================ */
	function reindexLocations() {
		$('#ekwa-locations-repeater .ekwa-location-item').each(function (i) {
			$(this).attr('data-index', i);
			$(this).find('[name]').each(function () {
				this.name = this.name.replace(
					/ekwa_locations\[\d+\]/,
					'ekwa_locations[' + i + ']'
				);
			});
			$(this).find('.ekwa-wh-repeater').attr('data-loc-index', i);
		});
	}

	function reindexWorkingHours($repeater) {
		var locIdx = $repeater.attr('data-loc-index');
		$repeater.find('.ekwa-wh-item').each(function (j) {
			$(this).attr('data-wh-index', j);
			$(this).find('[name]').each(function () {
				this.name = this.name.replace(
					/ekwa_locations\[\d+\]\[working_hours\]\[\d+\]/,
					'ekwa_locations[' + locIdx + '][working_hours][' + j + ']'
				);
			});
		});
	}

	function reindexSocial() {
		$('#ekwa-social-repeater .ekwa-social-item').each(function (i) {
			$(this).attr('data-index', i);
			$(this).find('[name]').each(function () {
				this.name = this.name.replace(
					/ekwa_social\[\d+\]/,
					'ekwa_social[' + i + ']'
				);
			});
		});
	}

	/* ============================================================
	 *  Location repeater â€” add / remove
	 * ============================================================ */
	$('#ekwa-add-location').on('click', function () {
		var count = $('#ekwa-locations-repeater .ekwa-location-item').length;
		var html = $('#tmpl-ekwa-location').html();
		html = html.replace(/__LOC_INDEX__/g, count);
		$('#ekwa-locations-repeater').append(html);
	});

	$(document).on('click', '.ekwa-remove-location', function () {
		if (!confirm(ekwaAdmin.confirmRemove)) return;
		$(this).closest('.ekwa-location-item').remove();
		reindexLocations();
	});

	/* ============================================================
	 *  Working hours sub-repeater â€” add / remove
	 * ============================================================ */
	$(document).on('click', '.ekwa-add-wh', function () {
		var $repeater = $(this).siblings('.ekwa-wh-repeater');
		var locIdx = $repeater.attr('data-loc-index');
		var whCount = $repeater.find('.ekwa-wh-item').length;
		var html = $('#tmpl-ekwa-working-hour').html();
		html = html.replace(/__LOC_INDEX__/g, locIdx);
		html = html.replace(/__WH_INDEX__/g, whCount);
		$repeater.append(html);
	});

	$(document).on('click', '.ekwa-remove-wh', function () {
		if (!confirm(ekwaAdmin.confirmRemove)) return;
		var $repeater = $(this).closest('.ekwa-wh-repeater');
		$(this).closest('.ekwa-wh-item').remove();
		reindexWorkingHours($repeater);
	});

	/* ============================================================
	 *  Social repeater â€” add / remove
	 * ============================================================ */
	$('#ekwa-add-social').on('click', function () {
		var count = $('#ekwa-social-repeater .ekwa-social-item').length;
		var html = $('#tmpl-ekwa-social').html();
		html = html.replace(/__SOC_INDEX__/g, count);
		$('#ekwa-social-repeater').append(html);
	});

	$(document).on('click', '.ekwa-remove-social', function () {
		if (!confirm(ekwaAdmin.confirmRemove)) return;
		$(this).closest('.ekwa-social-item').remove();
		reindexSocial();
	});

	/* ============================================================
	 *  Icon picker for social media icon class fields
	 * ============================================================ */

	var EKWA_ICONS = [
		// Brands
		{ name: 'Facebook',         cls: 'fa-brands fa-facebook' },
		{ name: 'Facebook F',       cls: 'fa-brands fa-facebook-f' },
		{ name: 'X / Twitter',      cls: 'fa-brands fa-x-twitter' },
		{ name: 'Instagram',        cls: 'fa-brands fa-instagram' },
		{ name: 'LinkedIn',         cls: 'fa-brands fa-linkedin' },
		{ name: 'LinkedIn In',      cls: 'fa-brands fa-linkedin-in' },
		{ name: 'YouTube',          cls: 'fa-brands fa-youtube' },
		{ name: 'TikTok',           cls: 'fa-brands fa-tiktok' },
		{ name: 'Pinterest',        cls: 'fa-brands fa-pinterest' },
		{ name: 'Pinterest P',      cls: 'fa-brands fa-pinterest-p' },
		{ name: 'Snapchat',         cls: 'fa-brands fa-snapchat' },
		{ name: 'WhatsApp',         cls: 'fa-brands fa-whatsapp' },
		{ name: 'Google',           cls: 'fa-brands fa-google' },
		{ name: 'Yelp',             cls: 'fa-brands fa-yelp' },
		{ name: 'Tripadvisor',      cls: 'fa-brands fa-tripadvisor' },
		{ name: 'Reddit',           cls: 'fa-brands fa-reddit' },
		{ name: 'Tumblr',           cls: 'fa-brands fa-tumblr' },
		{ name: 'Vimeo',            cls: 'fa-brands fa-vimeo' },
		{ name: 'Vimeo V',          cls: 'fa-brands fa-vimeo-v' },
		{ name: 'Twitch',           cls: 'fa-brands fa-twitch' },
		{ name: 'Discord',          cls: 'fa-brands fa-discord' },
		{ name: 'Slack',            cls: 'fa-brands fa-slack' },
		{ name: 'GitHub',           cls: 'fa-brands fa-github' },
		{ name: 'Spotify',          cls: 'fa-brands fa-spotify' },
		{ name: 'Threads',          cls: 'fa-brands fa-threads' },
		{ name: 'Bluesky',          cls: 'fa-brands fa-bluesky' },
		{ name: 'Mastodon',         cls: 'fa-brands fa-mastodon' },
		{ name: 'Medium',           cls: 'fa-brands fa-medium' },
		{ name: 'Behance',          cls: 'fa-brands fa-behance' },
		{ name: 'Dribbble',         cls: 'fa-brands fa-dribbble' },
		{ name: 'Flickr',           cls: 'fa-brands fa-flickr' },
		{ name: 'SoundCloud',       cls: 'fa-brands fa-soundcloud' },
		{ name: 'Google Play',      cls: 'fa-brands fa-google-play' },
		{ name: 'App Store',        cls: 'fa-brands fa-app-store-ios' },
		// Solid
		{ name: 'Phone',            cls: 'fa-solid fa-phone' },
		{ name: 'Email',            cls: 'fa-solid fa-envelope' },
		{ name: 'Location',         cls: 'fa-solid fa-location-dot' },
		{ name: 'Globe',            cls: 'fa-solid fa-globe' },
		{ name: 'Clock',            cls: 'fa-solid fa-clock' },
		{ name: 'Star',             cls: 'fa-solid fa-star' },
		{ name: 'Heart',            cls: 'fa-solid fa-heart' },
		{ name: 'Share',            cls: 'fa-solid fa-share-nodes' },
		{ name: 'Link',             cls: 'fa-solid fa-link' },
		{ name: 'RSS',              cls: 'fa-solid fa-rss' },
		{ name: 'Camera',           cls: 'fa-solid fa-camera' },
		{ name: 'Video',            cls: 'fa-solid fa-video' },
		{ name: 'Microphone',       cls: 'fa-solid fa-microphone' },
		{ name: 'Podcast',          cls: 'fa-solid fa-podcast' },
	];

	function ekwaIconSearch(query) {
		var q = query.toLowerCase().trim();
		if (!q) return EKWA_ICONS;
		return EKWA_ICONS.filter(function (icon) {
			return icon.name.toLowerCase().indexOf(q) > -1 ||
			       icon.cls.toLowerCase().indexOf(q) > -1;
		});
	}

	function ekwaOpenPicker($input) {
		var $field    = $input.closest('.ekwa-icon-field');
		var $dropdown = $field.find('.ekwa-icon-picker-dropdown');
		var results   = ekwaIconSearch($input.val());
		var html      = '<div class="ekwa-icon-grid">';

		if (results.length) {
			$.each(results, function (i, icon) {
				html += '<div class="ekwa-icon-option" data-cls="' + icon.cls + '" title="' + icon.name + '">' +
				        '<i class="' + icon.cls + '"></i>' +
				        '<span>' + icon.name + '</span>' +
				        '</div>';
			});
		} else {
			html += '<div class="ekwa-icon-no-results">No icons found. The class you typed will be used as-is.</div>';
		}

		html += '</div>';
		$dropdown.html(html).addClass('is-open');
	}

	function ekwaClosePicker($field) {
		$field.find('.ekwa-icon-picker-dropdown').removeClass('is-open').empty();
	}

	// Open on focus
	$(document).on('focusin', '.ekwa-icon-input', function () {
		ekwaOpenPicker($(this));
	});

	// Filter while typing + live preview
	$(document).on('input', '.ekwa-icon-input', function () {
		var $input   = $(this);
		var $field   = $input.closest('.ekwa-icon-field');
		$field.find('.ekwa-icon-preview-wrap i').attr('class', $input.val().trim());
		ekwaOpenPicker($input);
	});

	// Select icon â€” mousedown + preventDefault keeps the input focused
	// so blur doesn't fire and close the dropdown before click registers.
	$(document).on('mousedown', '.ekwa-icon-option', function (e) {
		e.preventDefault();
		var cls    = $(this).data('cls');
		var $field = $(this).closest('.ekwa-icon-field');
		$field.find('.ekwa-icon-input').val(cls);
		$field.find('.ekwa-icon-preview-wrap i').attr('class', cls);
		ekwaClosePicker($field);
	});

	// Escape closes picker
	$(document).on('keydown', '.ekwa-icon-input', function (e) {
		if (e.key === 'Escape') {
			ekwaClosePicker($(this).closest('.ekwa-icon-field'));
			$(this).blur();
		}
	});

	// Close on blur (with tiny delay so mousedown on option fires first)
	$(document).on('blur', '.ekwa-icon-input', function () {
		var $field = $(this).closest('.ekwa-icon-field');
		setTimeout(function () { ekwaClosePicker($field); }, 120);
	});

	/* ============================================================
	 *  WebP bulk regeneration
	 * ============================================================ */
	$(document).on('click', '#ekwa-webp-regen-btn', function (e) {
		e.preventDefault();

		var $btn      = $(this);
		var $status   = $('#ekwa-webp-regen-status');
		var $progress = $('#ekwa-webp-regen-progress');
		var $bar      = $('#ekwa-webp-regen-bar');
		var strings   = (window.ekwaAdmin && ekwaAdmin.webpStrings) || {};
		var endpoint  = window.ekwaAdmin && ekwaAdmin.webpRegenUrl;
		var nonce     = window.ekwaAdmin && ekwaAdmin.webpRestNonce;

		if (!endpoint) {
			$status.text('REST endpoint missing.');
			return;
		}

		$btn.prop('disabled', true);
		$progress.show();
		$bar.css('width', '0%');
		$status.text(strings.starting || 'Starting…');

		var totalProcessed = 0;
		var totalGenerated = 0;
		var totalImages    = 0;
		var offset         = 0;
		var batchSize      = 10;

		function tick() {
			$.ajax({
				url: endpoint,
				method: 'POST',
				data: { offset: offset, batch_size: batchSize },
				headers: { 'X-WP-Nonce': nonce }
			}).done(function (res) {
				totalProcessed += (res.processed || 0);
				totalGenerated += (res.generated || 0);
				totalImages     = res.total || totalImages;
				offset          = res.next_offset || (offset + batchSize);

				var pct = totalImages ? Math.round((totalProcessed / totalImages) * 100) : 100;
				$bar.css('width', pct + '%');

				var progressText = (strings.progress || '%1$s of %2$s processed')
					.replace('%1$s', totalProcessed)
					.replace('%2$s', totalImages);
				$status.text(progressText);

				if (res.done) {
					$status.text((strings.done || 'Done. %s files generated.').replace('%s', totalGenerated));
					$btn.prop('disabled', false);
				} else {
					tick();
				}
			}).fail(function (xhr) {
				$status.text(strings.error || 'Error during regeneration.');
				$btn.prop('disabled', false);
				if (window.console && xhr) { console.error('WebP regen failed:', xhr.responseText); }
			});
		}

		tick();
	});

})(jQuery);
