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
	 *  Working-hours day state — open / closed / note only
	 *
	 *  Closed and Note only are mutually exclusive; either one hides the
	 *  opening/closing selects. The Extra Note field sits outside
	 *  .ekwa-wh-times, so it stays reachable in all three states.
	 * ============================================================ */
	function syncWorkingHourState($item) {
		var closed   = $item.find('.ekwa-wh-closed-cb').prop('checked');
		var noteOnly = $item.find('.ekwa-wh-note-only-cb').prop('checked');
		$item.find('.ekwa-wh-times').toggle(!closed && !noteOnly);
		$item.find('.ekwa-wh-note-hint').toggle(!!noteOnly);
	}

	$(document).on('change', '.ekwa-wh-closed-cb, .ekwa-wh-note-only-cb', function () {
		var $item = $(this).closest('.ekwa-wh-item');
		if (this.checked) {
			var other = $(this).hasClass('ekwa-wh-closed-cb')
				? '.ekwa-wh-note-only-cb'
				: '.ekwa-wh-closed-cb';
			$item.find(other).prop('checked', false);
		}
		syncWorkingHourState($item);
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
	 *  Location repeater â€” extract address from the Direction URL
	 * ============================================================ */
	$(document).on('click', '.ekwa-extract-location', function () {
		var $btn      = $(this);
		var $item     = $btn.closest('.ekwa-location-item');
		var $status   = $item.find('.ekwa-extract-location-status');
		var url       = $item.find('.ekwa-location-direction').val();
		var strings   = (window.ekwaAdmin && ekwaAdmin.locationStrings) || {};
		var endpoint  = window.ekwaAdmin && ekwaAdmin.locationGeocodeUrl;
		var nonce     = window.ekwaAdmin && ekwaAdmin.webpRestNonce;

		if (!url) {
			$status.text(strings.emptyUrl || 'Paste a Direction URL first.');
			return;
		}
		if (!endpoint) {
			$status.text('REST endpoint missing.');
			return;
		}

		$btn.prop('disabled', true);
		$status.text(strings.working || 'Looking up…');

		$.ajax({
			url: endpoint,
			method: 'POST',
			data: { url: url },
			headers: { 'X-WP-Nonce': nonce }
		}).done(function (res) {
			['street', 'city', 'state', 'zip', 'latitude', 'longitude'].forEach(function (field) {
				if (res[field]) {
					$item.find('[name$="[' + field + ']"]').val(res[field]);
				}
			});
			// Working hours only come back when the matched OSM place had them —
			// rebuild the sub-repeater from the response, but only then, so an
			// empty result never clobbers hours the admin entered by hand.
			var hoursAdded = fillWorkingHours($item, res.working_hours);

			var doneText = (strings.done || 'Filled in from: %s').replace('%s', res.formatted || url);
			if (hoursAdded) {
				doneText += ' ' + (strings.hoursAdded || '(working hours added)');
			}
			$status.text(doneText);
		}).fail(function (xhr) {
			var msg = strings.error || 'Couldn\'t extract an address from that link.';
			if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
				msg = xhr.responseJSON.message;
			}
			$status.text(msg);
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	/**
	 * Rebuild a location's working-hours sub-repeater from geocode results.
	 * Returns true when at least one row was written, false when the response
	 * carried no hours (leaving any existing manual rows untouched).
	 */
	function fillWorkingHours($item, hours) {
		if (!Array.isArray(hours) || !hours.length) {
			return false;
		}
		var $repeater = $item.find('.ekwa-wh-repeater');
		var locIdx    = $repeater.attr('data-loc-index');
		var tmpl      = $('#tmpl-ekwa-working-hour').html();
		$repeater.find('.ekwa-wh-item').remove();
		hours.forEach(function (wh, i) {
			var html = tmpl
				.replace(/__LOC_INDEX__/g, locIdx)
				.replace(/__WH_INDEX__/g, i);
			var $row     = $(html);
			var isClosed = String(wh.closed) === '1';
			$row.find('.ekwa-wh-day').val(wh.day);
			$row.find('.ekwa-wh-closed-cb').prop('checked', isClosed);
			$row.find('.ekwa-wh-note-only-cb').prop('checked', false);
			syncWorkingHourState($row);
			if (!isClosed) {
				$row.find('[name$="[open_hour]"]').val(wh.open_hour);
				$row.find('[name$="[open_min]"]').val(wh.open_min);
				$row.find('[name$="[open_period]"]').val(wh.open_period);
				$row.find('[name$="[close_hour]"]').val(wh.close_hour);
				$row.find('[name$="[close_min]"]').val(wh.close_min);
				$row.find('[name$="[close_period]"]').val(wh.close_period);
			}
			$repeater.append($row);
		});
		return true;
	}

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
		var totalErrors    = 0;
		var offset         = 0;
		// One image per HTTP request — each request gets fresh PHP memory.
		// Avoids the "runs a while then OOMs" failure mode on shared hosts
		// where decoding multiple large JPGs in one process busts memory_limit.
		var batchSize      = 1;

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

				if (res.errors && res.errors.length) {
					totalErrors += res.errors.length;
					if (window.console) {
						res.errors.forEach(function (err) {
							console.warn('WebP regen — attachment ' + err.attachment_id + ': ' + err.message);
						});
					}
				}

				var pct = totalImages ? Math.round((totalProcessed / totalImages) * 100) : 100;
				$bar.css('width', pct + '%');

				var progressText = (strings.progress || '%1$s of %2$s processed')
					.replace('%1$s', totalProcessed)
					.replace('%2$s', totalImages);
				if (totalErrors) { progressText += ' — ' + totalErrors + ' skipped (see console)'; }
				$status.text(progressText);

				if (res.done) {
					var doneText = (strings.done || 'Done. %s files generated.').replace('%s', totalGenerated);
					if (totalErrors) { doneText += ' — ' + totalErrors + ' image(s) failed (see console).'; }
					$status.text(doneText);
					$btn.prop('disabled', false);
				} else {
					tick();
				}
			}).fail(function (xhr) {
				// Try to surface the actual server error message rather than the generic one.
				var msg = strings.error || 'Error during regeneration.';
				if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
					msg += ' — ' + xhr.responseJSON.message;
				}
				$status.text(msg);
				$btn.prop('disabled', false);
				if (window.console && xhr) { console.error('WebP regen failed:', xhr.responseText); }
			});
		}

		tick();
	});

	/* ============================================================
	 *  Internal-link keywords — rebuild via Gemini
	 * ============================================================ */
	$(document).on('click', '#ekwa-interlink-rebuild-btn', function (e) {
		e.preventDefault();

		var $btn     = $(this);
		var $status  = $('#ekwa-interlink-rebuild-status');
		var endpoint = window.ekwaAdmin && ekwaAdmin.interlinkRebuildUrl;
		var nonce    = window.ekwaAdmin && ekwaAdmin.webpRestNonce;

		if (!endpoint) {
			$status.text('REST endpoint missing.');
			return;
		}

		$btn.prop('disabled', true);
		$status.text('Generating…');

		$.ajax({
			url: endpoint,
			method: 'POST',
			headers: { 'X-WP-Nonce': nonce }
		}).done(function (res) {
			$status.text((res && res.message) || 'Done.');
			$btn.prop('disabled', false);
		}).fail(function (xhr) {
			var msg = 'Error.';
			if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
				msg += ' — ' + xhr.responseJSON.message;
			}
			$status.text(msg);
			$btn.prop('disabled', false);
			if (window.console && xhr) { console.error('Interlink rebuild failed:', xhr.responseText); }
		});
	});

	/**
	 * The cPanel-level fix, ready to copy.
	 *
	 * LiteSpeed lets a SITE lift its own connection timeout for named scripts
	 * via env vars in .htaccess, which an ordinary cPanel user can edit — so
	 * this needs no root, no WHM and no support ticket.
	 *
	 * Scoped to the theme's slow routes rather than `.*` on purpose: LiteSpeed's
	 * own docs warn that applying noabort broadly can tie up an account that is
	 * hitting CloudLinux LVE limits. These routes are all admin-gated and all
	 * wait on Gemini.
	 *
	 * EKWA_HTACCESS_OK is ours, not LiteSpeed's. noconntimeout and noabort are
	 * consumed internally and may never be visible to PHP, so they cannot tell
	 * us whether the rule ran; a plain custom variable is passed straight
	 * through, which is what makes "did this rule match?" answerable at all.
	 *
	 * <IfModule Litespeed> makes the whole thing inert elsewhere, so it is safe
	 * to leave in place if the site ever moves off LiteSpeed.
	 *
	 * Shared by both handlers below — the timeout verdict and the rule check.
	 */
	function htaccessBlock() {
		var routes = '(ai-|edit-block-ai|apply-block-edit|generate-alt|ask-docs|interlink-|' +
			'mockup-check|convert-markup|dp-suggest|server-timeout-probe)';
		var vars   = '[E=noconntimeout:1,E=noabort:1,E=EKWA_HTACCESS_OK:1]';
		var lines  = [
			'&lt;IfModule Litespeed&gt;',
			'  RewriteEngine On',
			'  RewriteRule ^wp-json/ekwa/v1/' + routes + ' - ' + vars,
			'  RewriteCond %{QUERY_STRING} rest_route=/ekwa/v1/' + routes,
			'  RewriteRule ^index\\.php$ - ' + vars,
			'&lt;/IfModule&gt;'
		];
		return '<textarea readonly rows="7" onclick="this.select()" ' +
			'style="width:100%;font-family:monospace;font-size:11px;margin-top:8px;' +
			'white-space:pre;overflow-x:auto;">' + lines.join('\n') + '</textarea>' +
			'<p style="margin:4px 0 0;font-style:italic;">Back the file up first, and put it ABOVE ' +
			'<code># BEGIN WordPress</code>. The second rule is only needed on plain permalinks; ' +
			'it is harmless either way.</p>';
	}

	/* ============================================================
	 *  Server request limit — how long may a request run?
	 *
	 *  Measured from the BROWSER on purpose. The failing request the user is
	 *  chasing goes browser → web server → PHP, and whatever kills it lives in
	 *  the middle of that path; a loopback request from PHP would take a
	 *  different route and could easily pass while the real one fails.
	 *
	 *  The elapsed time of a FAILED probe is the answer. If the server cuts us
	 *  off at 60 seconds, the request dies at 60 seconds, and that number is
	 *  the limit — no ladder or bisection needed.
	 * ============================================================ */
	$(document).on('click', '#ekwa-timeout-probe-btn', function (e) {
		e.preventDefault();

		var $btn      = $(this);
		var $status   = $('#ekwa-timeout-probe-status');
		var $result   = $('#ekwa-timeout-probe-result');
		var endpoint  = window.ekwaAdmin && ekwaAdmin.timeoutProbeUrl;
		var nonce     = window.ekwaAdmin && ekwaAdmin.webpRestNonce;
		var strings   = (window.ekwaAdmin && ekwaAdmin.timeoutStrings) || {};
		var target    = parseInt($('#ekwa-timeout-target').val(), 10) || 120;
		var keepalive = $('#ekwa-timeout-keepalive').is(':checked');

		if (!endpoint) {
			$status.text('REST endpoint missing.');
			return;
		}

		target = Math.max(5, Math.min(300, target));

		var started = Date.now();
		var ticker  = null;

		// Appends, so the verdict lands UNDER the milestones the run collected
		// rather than erasing them — "it got past 60 and then died at 100" is a
		// more useful thing to be looking at than the death alone.
		function note(kind, title, body) {
			$result.append(
				'<div class="notice notice-' + kind + ' inline" style="margin:8px 0 0;padding:8px 12px;">' +
				'<p style="margin:0 0 4px;"><strong>' + title + '</strong></p>' +
				'<p style="margin:0;">' + body + '</p></div>'
			);
		}

		function stop() {
			if (ticker) { clearInterval(ticker); ticker = null; }
			$btn.prop('disabled', false);
		}

		$btn.prop('disabled', true);
		$result.empty();

		// Milestones worth calling out as they are PASSED, rather than only
		// reporting at the end. A test that is going to pass sits silent for
		// two minutes, which is indistinguishable from a hung page — and worse,
		// the single most useful fact (it got past 60s, so the common LiteSpeed
		// default is not the problem) is known at 61 seconds and was being
		// withheld until 120.
		var marks = [
			{ at: 30,  note: '' },
			{ at: 60,  note: ' — the usual LiteSpeed default, so that is not what is cutting you off' },
			{ at: 90,  note: '' },
			{ at: 120, note: '' }
		];
		var passed = [];

		function progress() {
			var secs = Math.round((Date.now() - started) / 1000);
			var pct  = Math.min(100, Math.round((secs / target) * 100));

			marks.forEach(function (m) {
				if (secs >= m.at && m.at <= target && passed.indexOf(m.at) === -1) {
					passed.push(m.at);
					$result.append(
						'<p style="margin:2px 0;color:#1a7f37;">&#10003; still alive past <strong>' +
						m.at + ' seconds</strong>' + m.note + '</p>'
					);
				}
			});

			$status.html(
				'<strong>' + secs + 's</strong> of ' + target + 's &nbsp;' +
				'<span style="display:inline-block;width:120px;height:8px;background:#dcdcde;' +
				'border-radius:4px;vertical-align:middle;overflow:hidden;">' +
				'<span style="display:block;height:100%;width:' + pct + '%;background:#2271b1;"></span>' +
				'</span> &nbsp;<em>still waiting — this is normal</em>'
			);
		}

		ticker = setInterval(progress, 1000);
		progress();

		$.ajax({
			url: endpoint,
			method: 'POST',
			headers: { 'X-WP-Nonce': nonce },
			data: { seconds: target, keepalive: keepalive ? 1 : 0 },
			// Longer than the server is being asked to wait, so that OUR
			// timeout can never be the thing that fires — otherwise the test
			// would measure the browser instead of the server.
			timeout: (target + 30) * 1000
		}).done(function (res) {
			stop();
			var lasted = Math.round((Date.now() - started) / 1000);
			$status.text('');

			if (keepalive) {
				// The decisive outcome: silent runs die, busy runs survive.
				// The server is counting idle time, not total time.
				note('success', 'It survived with the connection kept busy',
					'The server allowed <strong>' + lasted + ' seconds</strong> when bytes kept flowing, ' +
					'having cut off a silent request earlier. That means the limit counts <strong>idle</strong> ' +
					'time, not total time — so this is fixable in the theme without touching the server. ' +
					'Tell Claude “the keep-alive run survived” and the AI calls can be changed to do the same thing.');
				return;
			}

			note('success', strings.good || 'No server limit in the way',
				'The server allowed a request to run for <strong>' + lasted + ' seconds</strong>. ' +
				'That is at least as long as the AI features need, so a “Request Timeout” here is not the web server ' +
				'cutting the request off — check the PHP error log for a line starting <code>[ekwa-ai]</code>.');
		}).fail(function (xhr, textStatus) {
			stop();
			var lasted = Math.round((Date.now() - started) / 1000);
			$status.text('');

			// A permissions or routing failure comes back immediately and is
			// not a timeout — saying "your server cuts off at 0 seconds" would
			// be worse than useless.
			if (lasted < 3 && xhr && xhr.status && xhr.status !== 0) {
				note('warning', 'The test could not run',
					'The server answered HTTP ' + xhr.status + ' straight away, so nothing was measured. ' +
					(xhr.status === 403 ? 'That is a permissions or nonce failure — reload this page and try again.' :
					 'Something rejected the request before it could start waiting.'));
				return;
			}

			var isLite = !!(window.ekwaAdmin && ekwaAdmin.timeoutIsLiteSpeed);

			if (keepalive) {
				// Also decisive, the other way: a hard cap on the request, which
				// no amount of cleverness on our side gets around.
				note('error', 'Still cut off even with the connection kept busy',
					'Killed after <strong>' + lasted + ' seconds</strong> despite bytes flowing the whole time. ' +
					'So this is an absolute cap on how long a request may run, not an idle timeout, and ' +
					'<strong>the theme cannot work around it</strong> — the server limit has to be raised.<br><br>' +
					(isLite ? (strings.litespeed || '') : (strings.other || '')) +
					'<br><br>Until then, set <code>define( \'EKWA_AI_HTTP_TIMEOUT\', ' +
					Math.max(15, lasted - 10) + ' );</code> in <code>wp-config.php</code> and use a Flash model — ' +
					'it answers in a fraction of the time and will usually fit inside the limit.');
				return;
			}

			note('error', strings.bad || 'The server cut the request off',
				'The request was killed after <strong>' + lasted + ' seconds</strong>' +
				(textStatus === 'timeout' ? ' (the browser gave up — the server never answered)' : '') +
				'. Any AI generation that takes longer than that will fail the same way, with the ' +
				'“Request Timeout” page instead of a result.' +
				(isLite ? '<br><br><strong>Try this first — you can do it from cPanel, without your host.</strong> ' +
					'LiteSpeed lets a site lift this limit for named scripts. Put this at the <em>top</em> of ' +
					'<code>.htaccess</code> in <code>public_html</code>, above <code># BEGIN WordPress</code>, ' +
					'then run this test again — if it passes, generation is fixed too:' +
					htaccessBlock() : '') +
				'<br><br>' + (isLite ? 'If that does not shift it, the limit is set at server level: ' : '') +
				(isLite ? (strings.litespeed || '') : (strings.other || '')) +
				'<br><br>Meanwhile, set <code>define( \'EKWA_AI_HTTP_TIMEOUT\', ' +
				Math.max(15, lasted - 10) + ' );</code> in <code>wp-config.php</code> — the theme will then give up ' +
				'first and show a readable message instead of the server’s error page.');
		});
	});

	/* ============================================================
	 *  Did the .htaccess rule actually take effect?
	 *
	 *  Deliberately a 2-second probe. The marker travels in the probe's
	 *  RESPONSE, and a run that gets killed by the timeout never delivers one —
	 *  so asking this question with a long probe cannot work. A short one
	 *  always comes back, and answers it in two seconds instead of two minutes.
	 * ============================================================ */
	$(document).on('click', '#ekwa-htaccess-check-btn', function (e) {
		e.preventDefault();

		var $btn    = $(this);
		var $result = $('#ekwa-timeout-probe-result');
		var nonce   = window.ekwaAdmin && ekwaAdmin.webpRestNonce;

		$btn.prop('disabled', true);
		$result.html('<p style="margin:0;">Checking…</p>');

		$.ajax({
			url: (window.ekwaAdmin && ekwaAdmin.timeoutProbeUrl),
			method: 'POST',
			headers: { 'X-WP-Nonce': nonce },
			data: { seconds: 2, keepalive: 0 },
			timeout: 30000
		}).done(function (res) {
			$btn.prop('disabled', false);

			if (res && res.htaccess) {
				$result.html(
					'<div class="notice notice-success inline" style="margin:0;padding:8px 12px;">' +
					'<p style="margin:0 0 4px;"><strong>The .htaccess rule is working</strong></p>' +
					'<p style="margin:0;">The marker reached PHP, so the rule matched this request and LiteSpeed read it. ' +
					'If the 120-second test still gets cut off, then this host does not honour ' +
					'<code>noconntimeout</code> at site level and the limit has to be raised at server level — ' +
					'that part needs WHM or your host.</p></div>'
				);
			} else {
				$result.html(
					'<div class="notice notice-warning inline" style="margin:0;padding:8px 12px;">' +
					'<p style="margin:0 0 4px;"><strong>The rule did not reach this request</strong></p>' +
					'<p style="margin:0 0 6px;">No marker came back, so the block almost certainly is not being read. ' +
					'The usual reasons, in the order they catch people:</p>' +
					'<ol style="margin:0 0 6px 18px;">' +
					'<li><strong>Wrong folder.</strong> It has to be the <code>.htaccess</code> sitting next to ' +
					'<code>wp-config.php</code>. On an addon domain that is <em>not</em> <code>public_html</code>.</li>' +
					'<li><strong>Edited a different file.</strong> With “Show Hidden Files” off, File Manager will ' +
					'happily create a new visible <code>htaccess</code> with no dot.</li>' +
					'<li><strong>Placed below <code># END WordPress</code></strong> — WordPress’s catch-all rule ends ' +
					'processing before it gets there. It must go above <code># BEGIN WordPress</code>.</li>' +
					'<li><strong>Older snippet.</strong> The rule needs the <code>EKWA_HTACCESS_OK</code> marker — ' +
					'if you pasted an earlier version it has no marker to find. Re-copy the block below.</li>' +
					'</ol>' +
					'<p style="margin:0;"><em>One caveat: a server could strip the marker while still honouring the ' +
					'rest, so this is strong evidence rather than proof.</em></p>' +
					htaccessBlock() + '</div>'
				);
			}
		}).fail(function (xhr) {
			$btn.prop('disabled', false);
			$result.html(
				'<div class="notice notice-error inline" style="margin:0;padding:8px 12px;">' +
				'<p style="margin:0;">Could not reach the probe (HTTP ' + ((xhr && xhr.status) || 0) + '). ' +
				'If the site is returning 500 errors, rename <code>.htaccess-backup</code> back over ' +
				'<code>.htaccess</code> — the snippet has a typo.</p></div>'
			);
		});
	});

	/* ============================================================
	 *  Nav-menu item image picker (used by mega-menu columns)
	 * ============================================================ */
	$(document).on('click', '.ekwa-menu-image-pick', function (e) {
		e.preventDefault();
		if (typeof wp === 'undefined' || !wp.media) {
			window.console && console.error('Ekwa: wp.media is not loaded — cannot open image picker.');
			alert('Media library failed to load. Please refresh the page and try again.');
			return;
		}
		var $btn   = $(this);
		var $field = $btn.closest('.ekwa-menu-image-field');
		var frame  = wp.media({
			title: 'Select Menu Image',
			button: { text: 'Use this image' },
			multiple: false,
			library: { type: 'image' }
		});
		frame.on('select', function () {
			var att = frame.state().get('selection').first().toJSON();
			$field.find('.ekwa-menu-image-id').val(att.id);
			var thumbUrl = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
			$field.find('.ekwa-menu-image-preview').html(
				'<img src="' + thumbUrl + '" alt="" style="max-width:80px;height:auto;display:block;" />'
			);
			$btn.text('Change Image');
			$field.find('.ekwa-menu-image-remove').show();
		});
		frame.open();
	});

	$(document).on('click', '.ekwa-menu-image-remove', function (e) {
		e.preventDefault();
		var $field = $(this).closest('.ekwa-menu-image-field');
		$field.find('.ekwa-menu-image-id').val('');
		$field.find('.ekwa-menu-image-preview').empty();
		$field.find('.ekwa-menu-image-pick').text('Select Image');
		$(this).hide();
	});

	/* ============================================================
	 *  Design Setup — CodeMirror editors
	 *  - Global CSS: CSS editor + background-image path detector
	 *  - delayed-scripts.js / ekwa-child.js: JS editors + WAF-safe base64
	 * ============================================================ */
	$(function () {
		var CE    = window.ekwaCodeEditors || null;
		var hasCM = !!( CE && window.wp && wp.codeEditor );
		var cmInstances = []; // { cm, slug } — for refresh-on-show.

		function trackCM( cm, node ) {
			var pane = ( node && node.closest ) ? node.closest( '.ekwa-tab-pane' ) : null;
			cmInstances.push( { cm: cm, slug: pane ? pane.getAttribute( 'data-tab' ) : null } );
		}

		function refreshCM( slug ) {
			cmInstances.forEach( function ( it ) {
				if ( ! slug || ! it.slug || it.slug === slug ) {
					try { it.cm.refresh(); } catch ( e ) {}
				}
			} );
		}

		// ---- CSS editors: syntax highlighting + two live checks ----
		// Used by the Mockup stylesheet (the site's CSS) and, on sites still on
		// the legacy split model, the Global CSS pool. Same behaviour for both.
		function setupCssEditor( slug ) {
			var ta = document.getElementById( slug );
			if ( ! ta ) { return; }

			var cm       = wp.codeEditor.initialize( ta, CE.css ).codemirror;
			var bgPanel  = document.getElementById( slug + '-bg-warning' );
			var varPanel = document.getElementById( slug + '-var-warning' );
			var details  = document.getElementById( slug + '-details' );
			var meta     = document.getElementById( slug + '-meta' );
			trackCM( cm, ta );

			// ---- hard-coded image paths ----
			// Mockup-relative url(...) breaks on the live site; a var() never
			// matches, so background *variables* are treated as fine.
			var scanBg = ekwaDebounce( function () {
				var lines = cm.getValue().split( /\r\n|\r|\n/ );
				var bad   = [];
				var re    = /url\(\s*(['"]?)([^'")]+)\1\s*\)/gi;
				lines.forEach( function ( line, i ) {
					re.lastIndex = 0;
					var m;
					while ( ( m = re.exec( line ) ) ) {
						var t = ( m[2] || '' ).trim();
						if ( ! t ) { continue; }
						if ( /^data:/i.test( t ) ) { continue; }   // self-contained, won't break
						if ( t.charAt( 0 ) === '#' ) { continue; } // SVG fragment ref, not a path
						bad.push( { line: i, url: t } );
						break; // one flag per line is enough
					}
				} );
				cm.operation( function () {
					for ( var ln = 0; ln < cm.lineCount(); ln++ ) {
						cm.removeLineClass( ln, 'background', 'ekwa-cm-error-line' );
					}
					bad.forEach( function ( o ) {
						cm.addLineClass( o.line, 'background', 'ekwa-cm-error-line' );
					} );
				} );
				renderBgPanel( bgPanel, bad );
			}, 250 );

			// ---- var() references that resolve to nothing ----
			// A declaration reading an undefined custom property is thrown away
			// at computed-value time: no error, no warning, the rule just
			// doesn't apply. The quietest way for a mockup to "lose" styles.
			var scanVars = ekwaDebounce( function () {
				if ( ! varPanel ) { return; }
				var css     = cm.getValue();
				var defined = {};
				( CE.definedVars || [] ).forEach( function ( n ) {
					defined[ String( n ).toLowerCase() ] = true;
				} );
				// Anything this sheet declares itself counts as defined.
				var decl, dre = /(--[a-z0-9_-]+)\s*:/gi;
				while ( ( decl = dre.exec( css ) ) ) {
					defined[ decl[1].replace( /^--/, '' ).toLowerCase() ] = true;
				}
				// var(--name) with NO fallback — a comma means it degrades on purpose.
				var missing = [], seen = {}, ref, rre = /var\(\s*--([a-z0-9_-]+)\s*\)/gi;
				while ( ( ref = rre.exec( css ) ) ) {
					var name = ref[1].toLowerCase();
					if ( defined[ name ] || name.indexOf( 'wp--' ) === 0 || seen[ name ] ) { continue; }
					seen[ name ] = true;
					missing.push( name );
				}
				renderVarPanel( varPanel, missing );
			}, 250 );

			cm.on( 'changes', scanBg );
			cm.on( 'changes', scanVars );
			scanBg();
			scanVars();

			// Collapsing the <details> leaves CodeMirror measured against a
			// hidden element, so it renders blank until refreshed on re-open.
			if ( details ) {
				details.addEventListener( 'toggle', function () {
					if ( details.open ) {
						try { cm.refresh(); } catch ( e ) {}
					}
				} );
			}

			// Keep the summary's "N lines · N KB" honest while typing.
			if ( meta ) {
				var metaTpl = ( CE && CE.i18n && CE.i18n.cssMeta ) ? CE.i18n.cssMeta : '%1$s lines · %2$s';
				var updateMeta = ekwaDebounce( function () {
					var val = cm.getValue();
					meta.textContent = val.trim()
						? metaTpl
							.replace( '%1$s', cm.lineCount().toLocaleString() )
							.replace( '%2$s', formatBytes( val.length ) )
						: ( ( CE && CE.i18n && CE.i18n.cssEmpty ) || 'empty' );
				}, 300 );
				cm.on( 'changes', updateMeta );
			}
		}

		if ( hasCM && CE.css ) {
			setupCssEditor( 'ekwa-mockup-css' ); // the site's stylesheet
			setupCssEditor( 'ekwa-global-css' ); // legacy pool, when present
		}

		// ---- delayed-scripts.js / ekwa-child.js: JS editors + base64 mirror ----
		var jsTextareas = document.querySelectorAll( 'textarea.ekwa-code-js' );
		if ( jsTextareas.length ) {
			var jsPairs = [];
			if ( hasCM && CE.js ) {
				Array.prototype.forEach.call( jsTextareas, function ( ta ) {
					var inst = wp.codeEditor.initialize( ta, CE.js );
					if ( ta.disabled ) { inst.codemirror.setOption( 'readOnly', true ); }
					jsPairs.push( { ta: ta, cm: inst.codemirror } );
					trackCM( inst.codemirror, ta );
				} );
			}
			// Always mirror each JS field to its base64 twin on submit, so a WAF
			// that strips raw <script>/JS from the POST body can't wipe the file.
			var form = document.getElementById( 'ekwa-main-settings-form' );
			if ( form ) {
				form.addEventListener( 'submit', function () {
					jsPairs.forEach( function ( p ) { p.cm.save(); } ); // CodeMirror → textarea
					Array.prototype.forEach.call( jsTextareas, function ( ta ) {
						var b64 = form.querySelector( 'input[name="' + ta.name + '_b64"]' );
						if ( ! b64 ) { return; }
						try {
							b64.value = ta.disabled ? '' : btoa( unescape( encodeURIComponent( ta.value ) ) );
						} catch ( e ) {
							b64.value = '';
						}
					} );
				} );
			}
		}

		// Re-measure CodeMirror once its (initially hidden) tab is shown.
		if ( cmInstances.length ) {
			document.addEventListener( 'ekwa:tab-activated', function ( e ) {
				refreshCM( e.detail && e.detail.slug );
			} );
			setTimeout( function () { refreshCM(); }, 60 );
		}

		function renderBgPanel( panel, bad ) {
			if ( ! panel ) { return; }
			var i18n = ( CE && CE.i18n ) ? CE.i18n : {};
			if ( ! bad.length ) {
				panel.className   = 'ekwa-css-bg-warning is-clean';
				panel.textContent = i18n.bgClean || '';
				return;
			}
			panel.className = 'ekwa-css-bg-warning is-warning';
			var lineTpl = i18n.bgLine || 'Line %1$d: %2$s';
			var items = bad.map( function ( o ) {
				return '<li>' + ekwaEsc( lineTpl.replace( '%1$d', o.line + 1 ).replace( '%2$s', o.url ) ) + '</li>';
			} ).join( '' );
			panel.innerHTML =
				'<p><strong>⚠ ' + ekwaEsc( i18n.bgIntro || '' ) + '</strong></p>' +
				'<ul>' + items + '</ul>' +
				'<p>' + ekwaEsc( i18n.bgFix || '' ) + '</p>';
		}

		function renderVarPanel( panel, missing ) {
			var i18n = ( CE && CE.i18n ) ? CE.i18n : {};
			if ( ! missing.length ) {
				panel.className   = 'ekwa-css-bg-warning is-clean';
				panel.textContent = i18n.varClean || '';
				return;
			}
			panel.className = 'ekwa-css-bg-warning is-warning';
			var items = missing.map( function ( n ) {
				return '<li><code>var(--' + ekwaEsc( n ) + ')</code></li>';
			} ).join( '' );
			panel.innerHTML =
				'<p><strong>⚠ ' + ekwaEsc( i18n.varIntro || '' ) + '</strong></p>' +
				'<ul>' + items + '</ul>' +
				'<p>' + ekwaEsc( i18n.varFix || '' ) + '</p>';
		}

		function ekwaDebounce( fn, wait ) {
			var timer;
			return function () {
				clearTimeout( timer );
				timer = setTimeout( fn, wait );
			};
		}

		function ekwaEsc( s ) {
			var d = document.createElement( 'div' );
			d.textContent = ( s == null ) ? '' : String( s );
			return d.innerHTML;
		}

		// Matches WP's size_format() closely enough for the collapsed summary.
		function formatBytes( n ) {
			if ( n < 1024 ) { return n + ' B'; }
			if ( n < 1024 * 1024 ) { return ( n / 1024 ).toFixed( 1 ).replace( /\.0$/, '' ) + ' KB'; }
			return ( n / 1048576 ).toFixed( 1 ).replace( /\.0$/, '' ) + ' MB';
		}
	});

})(jQuery);
