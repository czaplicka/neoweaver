/* ==========================================================================
   NeoWeaver Admin — World Tag Defs
   Depends on: jQuery, NW_WTD (ajax_url, nonce)
   ========================================================================== */

(function ($) {
	'use strict';

	let allTags = [];
	let editingId = null;

	const $notice = $('#nw-notice');
	const $tbody = $('#nw-wtd-tbody');
	const $overlay = $('#nw-modal-overlay');
	const $modalTitle = $('#nw-modal-title');
	const $deleteBtn = $('#nw-delete-btn');
	const $saveLabel = $('#nw-save-label');
	const $saveBtn = $('#nw-save-btn');
	const $colorPicker = $('#nw-field-color-picker');
	const $colorText = $('#nw-field-color');

	const $filterCat = $('#nw-filter-category');
	const $filterSource = $('#nw-filter-source');
	const $filterActive = $('#nw-filter-active');
	const $filterSearch = $('#nw-filter-search');

	const $total = $('#nw-total');
	const $activeCount = $('#nw-active');
	const $inactiveCount = $('#nw-inactive');
	const $countSystem = $('#nw-count-system');
	const $countCustom = $('#nw-count-custom');

	if (!window.NW_WTD || !NW_WTD.ajax_url || !NW_WTD.nonce) {
		console.error('NeoWeaver World Tag Defs: missing AJAX config.');
		return;
	}

	function request(data, onSuccess, fallbackError) {
		return $.ajax({
			url: NW_WTD.ajax_url,
			method: 'POST',
			dataType: 'json',
			data: data
		})
		.done(function (res) {
			if (typeof onSuccess === 'function') {
				onSuccess(res);
			}
		})
		.fail(function (xhr) {
			showNotice(extractError(xhr, fallbackError || 'Request failed.'), 'error');
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
				const parsed = JSON.parse(xhr.responseText);
				if (parsed && typeof parsed.data === 'string' && parsed.data) {
					return parsed.data;
				}
			} catch (e) {}
		}

		return fallback || 'Request failed.';
	}

	function showNotice(msg, type) {
		$notice
			.removeClass('nw-notice-success nw-notice-error')
			.addClass('nw-notice-' + type)
			.text(msg || '')
			.fadeIn(200);

		clearTimeout(showNotice._timer);
		showNotice._timer = setTimeout(function () {
			$notice.fadeOut(400);
		}, 4000);
	}

	function loadAll() {
		$tbody.html('<tr class="nw-loading-row"><td colspan="9"><div class="nw-spinner"></div> Loading tag defs…</td></tr>');

		request(
			{
				action: 'nw_wtd_get_all',
				nonce: NW_WTD.nonce
			},
			function (res) {
				if (!res || !res.success) {
					showNotice((res && res.data) ? res.data : 'Load failed.', 'error');
					$tbody.html('<tr><td colspan="9" style="text-align:center;padding:32px;color:var(--nw-text-muted);">Failed to load tag defs.</td></tr>');
					return;
				}

				allTags = Array.isArray(res.data) ? res.data : [];
				updateStats();
				populateCategoryFilter();
				renderTable();
			},
			'AJAX error — could not load tags.'
		);
	}

	function updateStats() {
		const active = allTags.filter(function (t) { return !!t.is_active; }).length;
		const inactive = allTags.length - active;

		$total.text(allTags.length);
		$activeCount.text(active);
		$inactiveCount.text(inactive);
		$countSystem.text(allTags.filter(function (t) { return t.source === 'system'; }).length);
		$countCustom.text(allTags.filter(function (t) { return t.source === 'custom'; }).length);
	}

	function populateCategoryFilter() {
		const current = $filterCat.val();
		const cats = [...new Set(
			allTags
				.map(function (t) { return t.category || ''; })
				.filter(Boolean)
		)].sort();

		$filterCat.find('option:not(:first)').remove();

		cats.forEach(function (c) {
			$filterCat.append($('<option>').val(c).text(c));
		});

		if (current) {
			$filterCat.val(current);
		}
	}

	function renderTable() {
		const cat = $filterCat.val();
		const src = $filterSource.val();
		const active = $filterActive.val();
		const search = String($filterSearch.val() || '').toLowerCase().trim();

		const filtered = allTags.filter(function (t) {
			if (cat && t.category !== cat) {
				return false;
			}
			if (src && t.source !== src) {
				return false;
			}
			if (active !== '' && String(t.is_active ? 1 : 0) !== active) {
				return false;
			}

			if (search) {
				const haystack = [
					t.code || '',
					t.label || '',
					t.description || ''
				].join(' ').toLowerCase();

				if (!haystack.includes(search)) {
					return false;
				}
			}

			return true;
		});

		if (!filtered.length) {
			$tbody.html('<tr><td colspan="9" style="text-align:center;padding:32px;color:var(--nw-text-muted);">No tag defs found.</td></tr>');
			return;
		}

		$tbody.empty();
		filtered.forEach(function (t) {
			$tbody.append(buildRow(t));
		});
	}

	function buildRow(t) {
	const color = t.color || '#888888';
	const sourceMap = {
		system: 'nw-badge-system',
		custom: 'nw-badge-custom',
		imported: 'nw-badge-imported'
	};
	const badgeCls = sourceMap[t.source] || '';
	const inactiveClass = t.is_active ? '' : 'nw-inactive';

	const $tr = $('<tr>').attr('data-id', t.id);

	$tr.append(
		$('<td>').addClass(inactiveClass).append(
			$('<code>').text(t.code || '')
		)
	);

	$tr.append(
		$('<td>').addClass(inactiveClass).text(t.label || '')
	);

	const $iconCell = $('<div>').addClass('nw-icon-cell');
	$iconCell.append(
		$('<span>')
			.addClass('nw-color-dot')
			.css('background', color)
	);

	if (t.icon) {
		$iconCell.append(document.createTextNode(' ' + t.icon));
	} else {
		$iconCell.append(
			$('<span>').css('color', 'var(--nw-text-muted)').text('—')
		);
	}

	$tr.append($('<td>').append($iconCell));

	$tr.append(
		$('<td>').addClass(inactiveClass).text(t.category || '—')
	);

	$tr.append(
		$('<td>').append(
			$('<span>')
				.addClass('nw-badge')
				.addClass(badgeCls)
				.text(t.source || '—')
		)
	);

	$tr.append(
		$('<td>').addClass(inactiveClass).text(t.impact != null ? t.impact : '—')
	);

	$tr.append(
		$('<td>').addClass(inactiveClass).text(t.sort_order != null ? t.sort_order : '—')
	);

	$tr.append(
		$('<td>').append(
			$('<button>', {
				type: 'button',
				class: 'nw-row-toggle nw-toggle-active-btn',
				'data-id': t.id,
				'data-active': t.is_active ? 1 : 0,
				title: 'Toggle active',
				text: t.is_active ? '✅' : '⭕'
			})
		)
	);

	$tr.append(
		$('<td>').append(
			$('<div>').addClass('nw-row-actions').append(
				$('<button>', {
					type: 'button',
					class: 'nw-row-btn nw-edit-btn',
					'data-id': t.id,
					text: 'Edit'
				})
			)
		)
	);

	return $tr;
}

	function openModal(tag) {
		editingId = tag ? tag.id : null;

		$modalTitle.text(tag ? 'Edit World Tag Def' : 'New World Tag Def');
		$saveLabel.text(tag ? 'Save Tag Def' : 'Create Tag Def');
		$deleteBtn.toggle(!!tag);

		$('#nw-field-id').val(tag ? tag.id : '');
		$('#nw-field-code').val(tag ? (tag.code || '') : '');
		$('#nw-field-label').val(tag ? (tag.label || '') : '');
		$('#nw-field-description').val(tag ? (tag.description || '') : '');
		$('#nw-field-icon').val(tag ? (tag.icon || '') : '');
		$('#nw-field-category').val(tag ? (tag.category || '') : '');
		$('#nw-field-source').val(tag ? (tag.source || 'system') : 'system');
		$('#nw-field-sort_order').val(tag ? (tag.sort_order != null ? tag.sort_order : '') : '');
		$('#nw-field-impact').val(tag ? (tag.impact != null ? tag.impact : '') : '');
		$('#nw-field-is_active').prop('checked', tag ? !!tag.is_active : true);

		const colorVal = (tag && tag.color) ? tag.color : '#adff00';
		$colorText.val(colorVal);
		$colorPicker.val(colorVal);

		$overlay.fadeIn(160);
		$('#nw-field-code').trigger('focus');
	}

	function closeModal() {
		$overlay.fadeOut(160);
		editingId = null;
		$('#nw-wtd-form')[0].reset();
		$('#nw-field-id').val('');
		$deleteBtn.hide();
		$saveLabel.text('Save Tag Def');
		$saveBtn.prop('disabled', false);
		$colorText.val('#adff00');
		$colorPicker.val('#adff00');
	}

	function saveTag() {
		const payload = {
			id: $('#nw-field-id').val().trim(),
			code: $('#nw-field-code').val().trim(),
			label: $('#nw-field-label').val().trim(),
			description: $('#nw-field-description').val().trim(),
			icon: $('#nw-field-icon').val().trim(),
			color: $colorText.val().trim() || '#adff00',
			category: $('#nw-field-category').val().trim(),
			source: $('#nw-field-source').val(),
			sort_order: $('#nw-field-sort_order').val().trim(),
			impact: $('#nw-field-impact').val().trim(),
			is_active: $('#nw-field-is_active').is(':checked') ? 1 : 0
		};

		if (!payload.code) {
			showNotice('Code is required.', 'error');
			return;
		}

		if (!payload.label) {
			showNotice('Label is required.', 'error');
			return;
		}

		$saveBtn.prop('disabled', true);
		$saveLabel.text('Saving…');

		request(
			{
				action: 'nw_wtd_save',
				nonce: NW_WTD.nonce,
				tag: payload
			},
			function (res) {
				$saveBtn.prop('disabled', false);
				$saveLabel.text(editingId ? 'Save Tag Def' : 'Create Tag Def');

				if (!res || !res.success) {
					showNotice((res && res.data) ? res.data : 'Save failed.', 'error');
					return;
				}

				showNotice(editingId ? 'Tag def updated.' : 'Tag def created.', 'success');
				closeModal();
				loadAll();
			},
			'AJAX error — save failed.'
		);
	}

	function toggleActive(id, currentActive) {
		const newState = currentActive ? 0 : 1;

		request(
			{
				action: 'nw_wtd_toggle',
				nonce: NW_WTD.nonce,
				tag_id: id,
				is_active: newState
			},
			function (res) {
				if (!res || !res.success) {
					showNotice((res && res.data) ? res.data : 'Toggle failed.', 'error');
					return;
				}

				const tag = allTags.find(function (t) {
					return String(t.id) === String(id);
				});

				if (tag) {
					tag.is_active = !!newState;
				}

				updateStats();
				renderTable();
			},
			'AJAX error — toggle failed.'
		);
	}

	function deleteTag() {
		if (!editingId) {
			return;
		}

		if (!window.confirm('Delete this tag def? This cannot be undone.')) {
			return;
		}

		request(
			{
				action: 'nw_wtd_delete',
				nonce: NW_WTD.nonce,
				tag_id: editingId
			},
			function (res) {
				if (!res || !res.success) {
					showNotice((res && res.data) ? res.data : 'Delete failed.', 'error');
					return;
				}

				showNotice('Tag def deleted.', 'success');
				closeModal();
				loadAll();
			},
			'AJAX error — delete failed.'
		);
	}

	function esc(str) {
		return $('<span>').text(str || '').html();
	}

	$('#nw-add-btn').on('click', function () {
		openModal(null);
	});

	$('#nw-refresh-btn').on('click', function () {
		loadAll();
	});

	$('#nw-cancel-btn, #nw-modal-close').on('click', function () {
		closeModal();
	});

	$('#nw-save-btn').on('click', function () {
		saveTag();
	});

	$('#nw-delete-btn').on('click', function () {
		deleteTag();
	});

	$overlay.on('click', function (e) {
		if ($(e.target).is($overlay)) {
			closeModal();
		}
	});

	$colorPicker.on('input', function () {
		$colorText.val($colorPicker.val());
	});

	$colorText.on('input', function () {
		const v = $(this).val().trim();
		if (/^#[0-9a-fA-F]{6}$/.test(v)) {
			$colorPicker.val(v);
		}
	});

	$('#nw-filter-category, #nw-filter-source, #nw-filter-active').on('change', function () {
		renderTable();
	});

	$filterSearch.on('input', function () {
		renderTable();
	});

	$tbody.on('click', '.nw-edit-btn', function () {
		const id = $(this).data('id');
		const tag = allTags.find(function (t) {
			return String(t.id) === String(id);
		});

		if (tag) {
			openModal(tag);
		}
	});

	$tbody.on('click', '.nw-toggle-active-btn', function () {
		const id = $(this).data('id');
		const active = parseInt($(this).data('active'), 10);
		toggleActive(id, active);
	});

	$(document).on('keydown', function (e) {
		if (e.key === 'Escape' && $overlay.is(':visible')) {
			closeModal();
		}
	});

	loadAll();

}(jQuery));
