(function ($) {
	'use strict';

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
	 *  Location repeater — add / remove
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
	 *  Working hours sub-repeater — add / remove
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
	 *  Social repeater — add / remove
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

})(jQuery);
