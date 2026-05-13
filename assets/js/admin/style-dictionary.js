/**
 * NeoWeaver — Style Dictionary Admin JS
 * Depends on: jQuery, NW_SD (ajax_url, nonce)
 */
/* global jQuery, NW_SD */
(function ($) {
	'use strict';

	var allTags = [];
	var editingId = null;

	var $tbody;
	var $notice;
	var $overlay;
	var $modalTitle;
	var $form;
	var $deleteBtn;
	var $saveLabel;

	var $fCat;
	var $fActive;
	var $fSearch;

	var $total;
	var $active;
	var $inactive;

	function init() {
		if (!window.NW_SD || !NW_SD.ajax_url || !NW_SD.nonce) {
			console.error('NeoWeaver Style Dictionary: missing AJAX config.');
			return;
		}

		$tbody = $('#nw-sd-tbody');
		$notice = $('#nw-notice');
		$overlay = $('#nw-modal-overlay');
		$modalTitle = $('#nw-modal-title');
		$form = $('#nw-sd-form');
		$deleteBtn = $('#nw-delete-btn');
		$saveLabel = $('#nw-save-label');

		$fCat = $('#nw-filter-category');
		$fActive = $('#nw-filter-active');
		$fSearch = $('#nw-filter-search');

		$total = $('#nw-total');
		$active = $('#nw-active');
		$inactive = $('#nw-inactive');

		if (
			!$tbody.length ||
			!$notice.length ||
			!$overlay.length ||
			!$modalTitle.length ||
			!$form.length ||
			!$deleteBtn.length ||
			!$saveLabel.length
		) {
			console.error('NeoWeaver Style Dictionary: required DOM elements are missing.');
			return;
		}

		bindEvents();
		loadTags();
	}

	function bindEvents() {
		$fCat.on('change', applyFilters);
		$fActive.on('change', applyFilters);
		$fSearch.on('input', applyFilters);

		$('#nw-refresh-btn').on('click', function () {
			loadTags();
		});

		$('#nw-add-btn').on('click', function () {
			openModal(null);
		});

		$('#nw-cancel-btn, #nw-modal-close').on('click', function () {
			closeModal();
		});

		$overlay.on('click', function (e) {
			if ($(e.target).is($overlay)) {
				closeModal();
			}
		});

		$('#nw-save-btn').on('click', function (e) {
			e.preventDefault();
			saveTag();
		});

		$form.on('submit', function (e) {
			e.preventDefault();
			saveTag();
		});

		$('#nw-delete-btn').on('click', function () {
			if (editingId) {
				deleteTag(editingId);
			}
		});

		$(document).on('click', '.nw-edit-btn', function () {
			var id = $(this).attr('data-id');
			var tag = allTags.find(function (t) {
				return String(t.id) === String(id);
			});

			if (tag) {
				openModal(tag);
			} else {
				showNotice('Tag not found.', true);
			}
		});

		$(document).on('change', '.nw-quick-toggle', function () {
			toggleTag($(this).attr('data-id'), this.checked);
		});

		$(document).on('keydown', function (e) {
			if (e.key === 'Escape' && $overlay.is(':visible')) {
				closeModal();
			}
		});
	}

	function request(data, onSuccess, fallbackError) {
		return $.ajax({
			url: NW_SD.ajax_url,
			method: 'POST',
			dataType: 'json',
			data: data
		})
		.done(function (res) {
			if (typeof onSuccess === 'function') {
				onSuccess(res);
			}
		})
		.fail(function (xhr, status) {
			if (status === 'abort') {
				return;
			}
			showNotice(extractError(xhr, fallbackError || 'Request failed.'), true);
		});
	}

	function extractError(xhr, fallback) {
		if (xhr && xhr.responseJSON) {
			if (typeof xhr.responseJSON.data === 'string' && xhr.responseJSON.data) {
				return xhr.responseJSON.data;
			}
			if (
				xhr.responseJSON.data &&
				typeof xhr.responseJSON.data.message === 'string' &&
				xhr.responseJSON.data.message
			) {
				return xhr.responseJSON.data.message;
			}
		}

		if (xhr && typeof xhr.responseText === 'string' && xhr.responseText.trim()) {
			try {
				var parsed = JSON.parse(xhr.responseText);
				if (parsed && typeof parsed.data === 'string' && parsed.data) {
					return parsed.data;
				}
			} catch (e) {}
		}

		return fallback || 'Request failed.';
	}

	function showNotice(msg, isError) {
		$notice
			.removeClass('is-error')
			.toggleClass('is-error', !!isError)
			.text(msg || '')
			.show();

		clearTimeout($notice.data('timer'));
		$notice.data('timer', setTimeout(function () {
			$notice.fadeOut();
		}, 4000));
	}

	function updateStats(tags) {
		var activeCount = tags.filter(function (t) {
			return !!t.is_active;
		}).length;

		var inactiveCount = tags.length - activeCount;

		$total.text(tags.length);
		$active.text(activeCount);
		$inactive.text(inactiveCount);

		$('.nw-cat-count').each(function () {
			var cat = $(this).data('cat');
			var count = tags.filter(function (t) {
				return String(t.category || '') === String(cat);
			}).length;
			$(this).text(count);
		});
	}

	function renderTable(tags) {
		$tbody.empty();

		if (!tags.length) {
			$tbody.append('<tr class="nw-empty-row"><td colspan="5">No tags found.</td></tr>');
			return;
		}

		tags.forEach(function (tag) {
			var activeChecked = tag.is_active ? 'checked' : '';
			var rowClass = tag.is_active ? '' : 'nw-row-inactive';

			$tbody.append(
				'<tr class="' + rowClass + '" data-id="' + escHtml(tag.id) + '">' +
					'<td><strong>' + escHtml(tag.tag_name || '') + '</strong></td>' +
					'<td><span class="nw-cat-badge ' + escHtml(tag.category || 'general') + '">' + escHtml(tag.category || 'general') + '</span></td>' +
					'<td>' + escHtml(tag.interpretation_en || '') + '</td>' +
					'<td>' +
						'<label class="nw-toggle nw-toggle-cell">' +
							'<input type="checkbox" class="nw-quick-toggle" data-id="' + escHtml(tag.id) + '" ' + activeChecked + '>' +
							'<span class="nw-toggle-slider"></span>' +
						'</label>' +
					'</td>' +
					'<td>' +
						'<button type="button" class="nw-action-btn nw-edit-btn" data-id="' + escHtml(tag.id) + '">Edit</button>' +
					'</td>' +
				'</tr>'
			);
		});
	}

	function applyFilters() {
		var cat = $fCat.val();
		var active = $fActive.val();
		var search = String($fSearch.val() || '').toLowerCase().trim();

		var filtered = allTags.filter(function (t) {
			var tagName = String(t.tag_name || '').toLowerCase();
			var interpretation = String(t.interpretation_en || '').toLowerCase();

			if (cat && t.category !== cat) {
				return false;
			}
			if (active === '1' && !t.is_active) {
				return false;
			}
			if (active === '0' && t.is_active) {
				return false;
			}
			if (search && tagName.indexOf(search) === -1 && interpretation.indexOf(search) === -1) {
				return false;
			}
			return true;
		});

		renderTable(filtered);
	}

	function loadTags() {
		$tbody.html('<tr class="nw-loading-row"><td colspan="5"><div class="nw-spinner"></div> Loading tags…</td></tr>');

		request(
			{
				action: 'nw_sd_get_all',
				nonce: NW_SD.nonce
			},
			function (res) {
				if (!res || !res.success) {
					showNotice((res && res.data) ? res.data : 'Load failed.', true);
					$tbody.html('<tr class="nw-empty-row"><td colspan="5">Failed to load tags.</td></tr>');
					return;
				}

				allTags = Array.isArray(res.data) ? res.data : [];
				updateStats(allTags);
				applyFilters();
			},
			'Network error while loading tags.'
		);
	}

	function openModal(tag) {
		editingId = tag ? tag.id : null;

		if ($form.length && $form[0]) {
			$form[0].reset();
		}

		$modalTitle.text(tag ? 'Edit Style Tag' : 'New Style Tag');
		$saveLabel.text(tag ? 'Save Tag' : 'Create Tag');
		$deleteBtn.toggle(!!tag);

		$('#nw-field-id').val(tag ? (tag.id || '') : '');
		$('#nw-field-tag_name').val(tag ? (tag.tag_name || '') : '');
		$('#nw-field-category').val(tag ? (tag.category || 'general') : 'general');
		$('#nw-field-interpretation_en').val(tag ? (tag.interpretation_en || '') : '');
		$('#nw-field-is_active').prop('checked', tag ? !!tag.is_active : true);

		$overlay.show();
	}

	function closeModal() {
		$overlay.hide();
		editingId = null;

		if ($form.length && $form[0]) {
			$form[0].reset();
		}

		$('#nw-field-id').val('');
		$deleteBtn.hide();
		$saveLabel.text('Save Tag');
		$('#nw-save-btn').prop('disabled', false);
	}

	function saveTag() {
		var payload = {
			action: 'nw_sd_save',
			nonce: NW_SD.nonce,
			tag: {
				id: $('#nw-field-id').val() || '',
				tag_name: $('#nw-field-tag_name').val() || '',
				category: $('#nw-field-category').val() || 'general',
				interpretation_en: $('#nw-field-interpretation_en').val() || '',
				is_active: $('#nw-field-is_active').is(':checked') ? '1' : '0'
			}
		};

		if (!String(payload.tag.tag_name).trim()) {
			showNotice('Tag name is required.', true);
			return;
		}

		if (!String(payload.tag.interpretation_en).trim()) {
			showNotice('Interpretation is required.', true);
			return;
		}

		$saveLabel.text('Saving…');
		$('#nw-save-btn').prop('disabled', true);

		request(
			payload,
			function (res) {
				$('#nw-save-btn').prop('disabled', false);
				$saveLabel.text(editingId ? 'Save Tag' : 'Create Tag');

				if (!res || !res.success) {
					showNotice((res && res.data) ? res.data : 'Save failed.', true);
					return;
				}

				showNotice(editingId ? 'Tag updated.' : 'Tag created.', false);
				closeModal();
				loadTags();
			},
			'Network error while saving tag.'
		);
	}

	function toggleTag(id, isActive) {
		request(
			{
				action: 'nw_sd_toggle',
				nonce: NW_SD.nonce,
				tag_id: id,
				is_active: isActive ? '1' : '0'
			},
			function (res) {
				if (!res || !res.success) {
					showNotice((res && res.data) ? res.data : 'Toggle failed.', true);
					loadTags();
					return;
				}

				var tag = allTags.find(function (t) {
					return String(t.id) === String(id);
				});

				if (tag) {
					tag.is_active = !!isActive;
				}

				updateStats(allTags);

				var safeId = escHtml(id);
				var $row = $tbody.find('tr[data-id="' + safeId + '"]');
				$row.toggleClass('nw-row-inactive', !isActive);
			},
			'Network error while updating tag.'
		);
	}

	function deleteTag(id) {
		if (!window.confirm('Delete this style tag? This cannot be undone.')) {
			return;
		}

		request(
			{
				action: 'nw_sd_delete',
				nonce: NW_SD.nonce,
				tag_id: id
			},
			function (res) {
				if (!res || !res.success) {
					showNotice((res && res.data) ? res.data : 'Delete failed.', true);
					return;
				}

				showNotice('Tag deleted.', false);
				closeModal();
				loadTags();
			},
			'Network error while deleting tag.'
		);
	}

	function escHtml(str) {
		return String(str == null ? '' : str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	$(document).ready(function () {
		init();
	});

}(jQuery));
