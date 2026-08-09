/**
 * "Import from URL" panel on the Media Library / Add New Media screens.
 *
 * Sends one URL per request to ekwa/v1/media-import so a long list can't hit
 * PHP's execution limit, and reports each result — imported, already present,
 * or failed with the reason — as its own row.
 *
 * Server side: inc/ekwa-media-import.php.
 */
(function ($) {
	'use strict';

	var cfg = window.ekwaMediaImport || {};
	var i18n = cfg.i18n || {};

	function t(key, fallback) {
		return i18n[key] || fallback;
	}

	/**
	 * Pull image URLs out of whatever was pasted.
	 *
	 * Handles one-per-line lists, but also survives a paste of markdown
	 * (![alt](url)), an <img src="…"> tag, or a comma-separated run — the
	 * shapes URLs actually arrive in.
	 *
	 * @param {string} text Raw textarea contents.
	 * @return {string[]} Unique http(s) URLs, in the order pasted.
	 */
	function parseUrls(text) {
		var out = [];
		var seen = {};
		var matches = String(text || '').match(/https?:\/\/[^\s"'<>()\[\]]+/gi) || [];

		matches.forEach(function (raw) {
			// Trailing punctuation from prose or a comma-separated list.
			var url = raw.replace(/[.,;:!?]+$/, '');
			if (!url || seen[url]) {
				return;
			}
			seen[url] = true;
			out.push(url);
		});

		return out;
	}

	/**
	 * Short display name for a URL — the filename, or the host if there is none.
	 *
	 * @param {string} url Source URL.
	 * @return {string}
	 */
	function shortName(url) {
		var path = url.split('?')[0].split('#')[0];
		var name = path.substring(path.lastIndexOf('/') + 1);
		return name || url;
	}

	function request(url, body) {
		return $.ajax({
			url: url,
			method: 'POST',
			data: JSON.stringify(body),
			contentType: 'application/json',
			headers: { 'X-WP-Nonce': cfg.nonce }
		});
	}

	/**
	 * Message out of a jqXHR — prefers the REST error, falls back to the status.
	 *
	 * @param {object} xhr jQuery XHR.
	 * @return {string}
	 */
	function errorMessage(xhr) {
		if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
			return xhr.responseJSON.message;
		}
		if (xhr && xhr.statusText && xhr.status) {
			return xhr.status + ' ' + xhr.statusText;
		}
		return t('requestFail', 'Request failed.');
	}

	$(function () {
		var $panel = $('#ekwa-media-import');
		if (!$panel.length) {
			return;
		}

		var $urls = $('#ekwa-mi-urls');
		var $skip = $('#ekwa-mi-skip');
		var $ai = $('#ekwa-mi-ai');
		var $insecure = $('#ekwa-mi-insecure');
		var $count = $panel.find('.ekwa-mi__count');
		var $start = $('#ekwa-mi-start');
		var $stop = $('#ekwa-mi-stop');
		var $status = $panel.find('.ekwa-mi__status');
		var $bar = $panel.find('.ekwa-mi__bar');
		var $results = $panel.find('.ekwa-mi__results');

		var running = false;
		var cancelled = false;

		/* ---- Toggle button in the page heading --------------------------- */

		var $heading = $('.wrap h1.wp-heading-inline').first();
		if (!$heading.length) {
			$heading = $('.wrap h1').first();
		}

		var $toggle = $('<button/>', {
			type: 'button',
			'class': 'page-title-action ekwa-mi__toggle',
			text: t('toggle', 'Import from URL'),
			'aria-expanded': 'false',
			'aria-controls': 'ekwa-media-import'
		});

		if ($heading.length) {
			var $lastAction = $heading.nextAll('.page-title-action').last();
			if ($lastAction.length) {
				$lastAction.after($toggle);
			} else {
				$heading.after($toggle);
			}
		} else {
			$panel.before($toggle);
		}

		function openPanel() {
			$panel.prop('hidden', false);
			$toggle.attr('aria-expanded', 'true');
			$urls.trigger('focus');
		}

		function closePanel() {
			$panel.prop('hidden', true);
			$toggle.attr('aria-expanded', 'false');
		}

		$toggle.on('click', function () {
			if ($panel.prop('hidden')) {
				openPanel();
			} else {
				closePanel();
			}
		});

		$panel.on('click', '.ekwa-mi__close', function () {
			closePanel();
			$toggle.trigger('focus');
		});

		// Add New Media has nothing else on the screen — start it open there.
		if ($('body').hasClass('media-new-php')) {
			openPanel();
		}

		/* ---- Live URL count ---------------------------------------------- */

		function refreshCount() {
			var n = parseUrls($urls.val()).length;
			if (!n) {
				$count.text('');
			} else if (1 === n) {
				$count.text(t('foundOne', '1 image URL ready'));
			} else {
				$count.text(t('found', '%d image URLs ready').replace('%d', n));
			}
		}

		$urls.on('input paste change', function () {
			// Paste fires before the value lands.
			window.setTimeout(refreshCount, 0);
		});

		/* ---- Result rows -------------------------------------------------- */

		function addRow(state, url) {
			var $row = $('<li/>', { 'class': 'ekwa-mi__item is-' + state });
			$row.append($('<div/>', { 'class': 'ekwa-mi__thumb' }));

			var $body = $('<div/>', { 'class': 'ekwa-mi__body' });
			$body.append($('<div/>', { 'class': 'ekwa-mi__name', text: shortName(url) }));
			$body.append($('<div/>', { 'class': 'ekwa-mi__meta' }));
			$body.append($('<div/>', { 'class': 'ekwa-mi__source', text: url }));
			$row.append($body);

			$results.append($row);
			return $row;
		}

		/**
		 * Fill a row in once the server has answered.
		 *
		 * @param {object} $row Row element.
		 * @param {object} data Attachment payload from the REST response.
		 */
		function fillRow($row, data) {
			var duplicate = !!data.duplicate;

			$row.removeClass('is-pending').addClass(duplicate ? 'is-duplicate' : 'is-ok');

			if (data.thumb) {
				$row.find('.ekwa-mi__thumb').append($('<img/>', { src: data.thumb, alt: '' }));
			}

			var $name = $row.find('.ekwa-mi__name').empty();
			var label = data.filename || data.title || shortName(data.source || '');
			if (data.edit_link) {
				$name.append($('<a/>', { href: data.edit_link, text: label, target: '_blank', rel: 'noopener' }));
			} else {
				$name.text(label);
			}
			$name.append($('<span/>', {
				'class': 'ekwa-mi__badge',
				text: duplicate ? t('duplicate', 'Already in library') : t('imported', 'Imported')
			}));

			// Alt text is the one thing worth fixing while the images are still
			// in front of you, so edit it here rather than one attachment page
			// at a time.
			var $meta = $row.find('.ekwa-mi__meta').empty();
			var $alt = $('<input/>', {
				type: 'text',
				'class': 'ekwa-mi__alt-input',
				value: data.alt || '',
				placeholder: t('altLabel', 'Alt text'),
				'aria-label': t('altLabel', 'Alt text')
			});
			var $save = $('<button/>', { type: 'button', 'class': 'button button-small', text: t('save', 'Save') });
			var $note = $('<span/>', { 'class': 'ekwa-mi__note' });

			$meta.append($alt, $save);

			if (cfg.hasAi && 'image/svg+xml' !== data.mime) {
				var $aiBtn = $('<button/>', {
					type: 'button',
					'class': 'button button-small ekwa-mi__ai-btn',
					text: t('aiAlt', 'Write with AI')
				});
				$aiBtn.on('click', function () {
					generateAlt(data.attachment_id, $alt, $aiBtn, $note);
				});
				$meta.append($aiBtn);
			}

			$meta.append($note);

			$save.on('click', function () {
				saveAlt(data.attachment_id, $alt.val(), $save, $note);
			});
		}

		function failRow($row, message) {
			$row.removeClass('is-pending').addClass('is-error');
			$row.find('.ekwa-mi__name').append($('<span/>', {
				'class': 'ekwa-mi__badge',
				text: t('failed', 'Failed')
			}));
			$row.find('.ekwa-mi__meta').text(message);
		}

		/* ---- Alt text ------------------------------------------------------ */

		function saveAlt(id, value, $btn, $note) {
			$btn.prop('disabled', true);
			$note.removeClass('is-error').text('');

			return request(cfg.mediaUrl + id, { alt_text: value })
				.done(function () {
					$note.text(t('saved', 'Saved'));
				})
				.fail(function (xhr) {
					$note.addClass('is-error').text(t('saveFail', 'Could not save alt text.') + ' ' + errorMessage(xhr));
				})
				.always(function () {
					$btn.prop('disabled', false);
				});
		}

		function generateAlt(id, $input, $btn, $note) {
			var original = $btn.text();
			$btn.prop('disabled', true).text(t('aiWorking', 'Writing…'));
			$note.removeClass('is-error').text('');

			return request(cfg.aiAltUrl, { attachment_id: id })
				.done(function (res) {
					if (res && res.alt) {
						$input.val(res.alt);
						// One click should leave the alt text stored, not just typed.
						saveAlt(id, res.alt, $btn, $note);
					}
				})
				.fail(function (xhr) {
					$note.addClass('is-error').text(t('aiFail', 'AI alt text failed.') + ' ' + errorMessage(xhr));
				})
				.always(function () {
					$btn.prop('disabled', false).text(original);
				});
		}

		/* ---- Import run ---------------------------------------------------- */

		function setProgress(done, total) {
			var pct = total ? Math.round((done / total) * 100) : 0;
			$bar.prop('hidden', false).find('span').css('width', pct + '%');
		}

		function finish(counts, total, stopped) {
			running = false;
			$start.prop('disabled', false);
			$stop.prop('hidden', true);

			var text = t('summary', 'Done — %1$d imported, %2$d already in the library, %3$d failed.')
				.replace('%1$d', counts.imported)
				.replace('%2$d', counts.duplicate)
				.replace('%3$d', counts.failed);

			if (stopped) {
				text = t('stopped', 'Stopped.') + ' ' + text;
			}
			$status.text(text);

			if (counts.imported > 0) {
				$status.append(
					' ',
					$('<a/>', { href: '#', text: t('reload', 'Reload library') }).on('click', function (e) {
						e.preventDefault();
						window.location.reload();
					})
				);
			}
			setProgress(total, total);
		}

		$start.on('click', function () {
			if (running) {
				return;
			}

			var urls = parseUrls($urls.val());
			if (!urls.length) {
				$status.text(t('noUrls', 'Paste at least one image URL first.'));
				return;
			}

			running = true;
			cancelled = false;
			$start.prop('disabled', true);
			$stop.prop('hidden', false);
			$results.empty();
			$insecure.closest('label').removeClass('ekwa-mi__hint');
			setProgress(0, urls.length);

			var counts = { imported: 0, duplicate: 0, failed: 0 };
			var index = 0;

			function next() {
				if (cancelled || index >= urls.length) {
					finish(counts, urls.length, cancelled);
					return;
				}

				var url = urls[index];
				var $row = addRow('pending', url);

				$status.text(
					t('progress', 'Importing %1$d of %2$d…')
						.replace('%1$d', index + 1)
						.replace('%2$d', urls.length)
				);

				request(cfg.importUrl, {
					url: url,
					skip_duplicates: $skip.is(':checked'),
					insecure: $insecure.is(':checked')
				}).done(function (data) {
					data = data || {};
					data.source = url;
					fillRow($row, data);

					if (data.duplicate) {
						counts.duplicate++;
					} else {
						counts.imported++;
					}

					// Only newly imported images need alt text written; a
					// duplicate already has whatever it was given last time.
					if (!data.duplicate && $ai.length && $ai.is(':checked') && cfg.hasAi &&
						'image/svg+xml' !== data.mime) {
						var $btn = $row.find('.ekwa-mi__ai-btn');
						if ($btn.length) {
							$btn.trigger('click');
						}
					}
				}).fail(function (xhr) {
					counts.failed++;
					failRow($row, errorMessage(xhr));

					// Tie the error to its remedy: the message tells them to
					// tick the box, so make the box obvious.
					if (xhr && xhr.responseJSON && 'ekwa_media_import_ssl' === xhr.responseJSON.code) {
						$insecure.closest('label').addClass('ekwa-mi__hint');
					}
				}).always(function () {
					index++;
					setProgress(index, urls.length);
					next();
				});
			}

			next();
		});

		$stop.on('click', function () {
			cancelled = true;
			$stop.prop('disabled', true);
			// Re-enabled for the next run once the in-flight request settles.
			window.setTimeout(function () {
				$stop.prop('disabled', false);
			}, 500);
		});

		refreshCount();
	});
})(jQuery);
