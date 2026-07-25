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
			$row.find('.ekwa-wh-times').toggle(!isClosed);
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

		// ---- Global CSS: CSS editor + background-image path detector ----
		if ( hasCM && CE.css ) {
			var cssTa = document.getElementById( 'ekwa-global-css' );
			if ( cssTa ) {
				var cssCm = wp.codeEditor.initialize( cssTa, CE.css ).codemirror;
				var panel = document.getElementById( 'ekwa-global-css-bg-warning' );
				trackCM( cssCm, cssTa );

				var scan = ekwaDebounce( function () {
					var lines = cssCm.getValue().split( /\r\n|\r|\n/ );
					var bad   = [];
					// url( ... ) literals. var(--x) never matches, so background
					// *variables* are treated as fine — exactly the intent.
					var re = /url\(\s*(['"]?)([^'")]+)\1\s*\)/gi;
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
					cssCm.operation( function () {
						for ( var ln = 0; ln < cssCm.lineCount(); ln++ ) {
							cssCm.removeLineClass( ln, 'background', 'ekwa-cm-error-line' );
						}
						bad.forEach( function ( o ) {
							cssCm.addLineClass( o.line, 'background', 'ekwa-cm-error-line' );
						} );
					} );
					renderBgPanel( panel, bad );
				}, 250 );

				cssCm.on( 'changes', scan );
				scan();
			}
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
	});

})(jQuery);
