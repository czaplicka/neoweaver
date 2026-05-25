jQuery(function ($) {
	'use strict';

	var cfg = window.NWAbilities || {};
	var ajaxEndpoint = cfg.ajaxurl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
	var nonce = cfg.nonce || '';
	var uploadsUrl = (cfg.uploads_url || '').replace(/\/+$/, '');
	var noticeTimer = null;

	/* ── DOM refs ─────────────────────────────────────────── */
	var $notice = $('#nw-notice');
	var $tbody = $('#nw-abilities-tbody');
	var $search = $('#nw-search');

	var $filterAbilityType = $('#nw-filter-ability-type');
	var $filterCostType = $('#nw-filter-cost-type');
	var $filterTargetType = $('#nw-filter-target-type');
	var $filterStatus = $('#nw-filter-status');
	var $resetFiltersBtn = $('#nw-reset-filters-btn');

	var $modalOverlay = $('#nw-modal-overlay');
	var $form = $('#nw-ability-form');
	var $saveBtn = $('#nw-save-btn');
	var $saveLabel = $('#nw-save-label');
	var $deleteBtn = $('#nw-delete-btn');
	var $fieldId = $('#nw-field-id');

	var $fieldName = $('#nw-field-name');
	var $fieldDescription = $('#nw-field-description');
	var $fieldAbilityType = $('#nw-field-ability_type');
	var $fieldSource = $('#nw-field-source');
	var $fieldGmNotes = $('#nw-field-gm_notes');
	var $fieldCost = $('#nw-field-cost');
	var $fieldImgUrl = $('#nw-field-img_url');
	var $fieldTags = $('#nw-field-tags');
	var $fieldCostType = $('#nw-field-cost_type');
	var $fieldCostValue = $('#nw-field-cost_value');
	var $fieldTargetType = $('#nw-field-target_type');
	var $fieldRangeTiles = $('#nw-field-range_tiles');
	var $fieldDurationTurns = $('#nw-field-duration_turns');
	var $fieldIsPassive = $('#nw-field-is_passive');
	var $fieldIsActive = $('#nw-field-is_active');
	var $fieldSortOrder = $('#nw-field-sort_order');

	var $imgPreview = $('#nw-img-preview');
	var $imgPreviewWrap = $('#nw-img-preview-wrap');

	/* ── state ────────────────────────────────────────────── */
	var all = [];
	var activeXhr = null;

	/* ── Helpers ──────────────────────────────────────────── */
	function esc(s) {
		return $('<span>').text(s || '').html();
	}

	function clearNoticeTimer() {
		if (noticeTimer) {
			clearTimeout(noticeTimer);
			noticeTimer = null;
		}
	}

	function notice(msg, type) {
		var safeType = String(type || 'info').replace(/[^a-z-]/g, '');

		clearNoticeTimer();

		$notice
			.attr('class', 'nw-notice nw-notice-' + safeType)
			.text(msg)
			.stop(true, true)
			.show();

		noticeTimer = setTimeout(function () {
			$notice.fadeOut(300);
			noticeTimer = null;
		}, 3500);
	}

	function debounce(fn, delay) {
		var timer;
		return function () {
			var args = arguments;
			var ctx = this;
			clearTimeout(timer);
			timer = setTimeout(function () {
				fn.apply(ctx, args);
			}, delay);
		};
	}

	function tagsStr(tags) {
		if (!tags) return '';
		if (Array.isArray(tags)) return tags.join(', ');
		if (typeof tags === 'string') return tags;
		return '';
	}

	function normalizeBool(value, fallback) {
		if (value == null) return !!fallback;
		if (typeof value === 'boolean') return value;
		if (typeof value === 'number') return value === 1;
		if (typeof value === 'string') {
			var v = value.toLowerCase().trim();
			return v === '1' || v === 'true' || v === 't';
		}
		return !!fallback;
	}

	function normalizeAbilities(data) {
		var list = data;

		if (typeof list === 'string') {
			try {
				list = JSON.parse(list);
			} catch (e) {
				list = [];
			}
		}

		if (!Array.isArray(list)) {
			list = (list && typeof list === 'object') ? Object.values(list) : [];
		}

		return list.map(function (item) {
			var tags = item.tags;

			if (typeof tags === 'string') {
				try {
					tags = JSON.parse(tags);
				} catch (e) {
					tags = tags.split(',').map(function (t) {
						return t.trim();
					}).filter(Boolean);
				}
			}

			return {
				id: item.id || '',
				name: item.name || '',
				description: item.description || '',
				ability_type: item.ability_type || '',
				source: item.source || '',
				gm_notes: item.gm_notes || '',
				cost: item.cost || '',
				img_url: item.img_url || '',
				tags: Array.isArray(tags) ? tags : [],
				created_at: item.created_at || '',
				cost_type: item.cost_type || 'none',
				cost_value: item.cost_value != null ? parseInt(item.cost_value, 10) || 0 : 0,
				target_type: item.target_type || 'self',
				range_tiles: item.range_tiles != null ? parseInt(item.range_tiles, 10) || 0 : 1,
				duration_turns: item.duration_turns != null ? parseInt(item.duration_turns, 10) || 0 : 0,
				is_passive: normalizeBool(item.is_passive, false),
				is_active: normalizeBool(item.is_active, true),
				sort_order: item.sort_order != null ? parseInt(item.sort_order, 10) || 0 : 0
			};
		});
	}

	function resolveImgUrl(raw) {
		var value;

		if (!raw) return '';
		value = String(raw).trim();
		if (!value) return '';

		if (/^https?:\/\//i.test(value) || /^\/\//.test(value)) {
			return value;
		}

		if (value.charAt(0) === '/') {
			return value;
		}

		if (!uploadsUrl) {
			return '';
		}

		return uploadsUrl + '/' + value.replace(/^\/+/, '');
	}

	function updateImgPreview(raw) {
		var fullUrl = resolveImgUrl(raw);

		if (fullUrl) {
			$imgPreview.attr('src', fullUrl);
			$imgPreviewWrap.show();
		} else {
			$imgPreview.attr('src', '');
			$imgPreviewWrap.hide();
		}
	}

	function setCheckbox($el, value) {
		$el.prop('checked', !!value);
	}

	function getCheckboxValue($el) {
		return $el.is(':checked') ? '1' : '0';
	}

	function updateStats(data) {
		var active = 0;
		var passive = 0;

		(data || []).forEach(function (a) {
			if (a.is_active) active++;
			if (a.is_passive) passive++;
		});

		$('#nw-total').text(data.length);
		$('#nw-active').text(active);
		$('#nw-inactive').text(data.length - active);
		$('#nw-passive').text(passive);
	}

	function bindImageFallbacks() {
		$tbody.find('img[data-fallback]')
			.off('error.nwFallback')
			.on('error.nwFallback', function () {
				$(this).hide().siblings('.nw-ability-img-placeholder').show();
			});
	}

	function uniqueValues(key) {
		var set = {};
		all.forEach(function (item) {
			var value = String(item[key] || '').trim();
			if (value) set[value] = true;
		});
		return Object.keys(set).sort(function (a, b) {
			return a.localeCompare(b);
		});
	}

	function populateFilters() {
		function fill($select, values, placeholder) {
			var current = $select.val() || '';
			var html = '<option value="">' + esc(placeholder) + '</option>';

			values.forEach(function (value) {
				html += '<option value="' + esc(value) + '">' + esc(value) + '</option>';
			});

			$select.html(html);
			$select.val(current);
		}

		fill($filterAbilityType, uniqueValues('ability_type'), 'All types');
		fill($filterCostType, uniqueValues('cost_type'), 'All cost types');
		fill($filterTargetType, uniqueValues('target_type'), 'All targets');
	}

	function renderTable(data) {
		if (!data.length) {
			$tbody.html(
				'<tr><td colspan="9" style="text-align:center;padding:32px;color:#555;">No abilities found.</td></tr>'
			);
			return;
		}

		$tbody.html(data.map(function (a) {
			var safeId = esc(a.id);
			var tags = Array.isArray(a.tags) ? a.tags : [];
			var fullImg = resolveImgUrl(a.img_url);

			var imgH = fullImg
				? '<img src="' + esc(fullImg) + '" class="nw-ability-img" loading="lazy" data-fallback="1" alt="">' +
				  '<div class="nw-ability-img-placeholder" style="display:none;">⚡</div>'
				: '<div class="nw-ability-img-placeholder">⚡</div>';

			var statusBits = [];
			statusBits.push(
				a.is_active
					? '<span class="nw-badge nw-active-badge is-active">Active</span>'
					: '<span class="nw-badge nw-active-badge is-inactive">Inactive</span>'
			);

			if (a.is_passive) {
				statusBits.push('<span class="nw-badge nw-passive-badge">Passive</span>');
			}

			var costLabel = a.cost
				? esc(a.cost)
				: (esc(a.cost_type) + (a.cost_value > 0 ? ' ' + esc(String(a.cost_value)) : ''));

			return ''
				+ '<tr data-id="' + safeId + '">'
				+ '<td>' + imgH + '</td>'
				+ '<td>'
				+   '<div class="nw-ability-name">' + esc(a.name) + '</div>'
				+   '<div class="nw-ability-desc">' + esc(a.description || a.source || '') + '</div>'
				+ '</td>'
				+ '<td><span class="nw-badge nw-badge-type">' + esc(a.ability_type || '—') + '</span></td>'
				+ '<td><span class="nw-badge nw-badge-target">' + esc(a.target_type || 'self') + '</span></td>'
				+ '<td><span class="nw-cost-value">' + costLabel + '</span></td>'
				+ '<td><span class="nw-range-value">' + esc(String(a.range_tiles != null ? a.range_tiles : 1)) + '</span></td>'
				+ '<td><span class="nw-duration-value">' + esc(String(a.duration_turns != null ? a.duration_turns : 0)) + '</span></td>'
				+ '<td>' + statusBits.join(' ') + '</td>'
				+ '<td>'
				+   '<div class="nw-row-actions">'
				+     '<button type="button" class="nw-action-btn nw-edit-btn" data-id="' + safeId + '">Edit</button>'
				+   '</div>'
				+ '</td>'
				+ '</tr>';
		}).join(''));

		bindImageFallbacks();
	}

	function applyFilters() {
		var q = String($search.val() || '').toLowerCase().trim();
		var abilityType = String($filterAbilityType.val() || '').toLowerCase().trim();
		var costType = String($filterCostType.val() || '').toLowerCase().trim();
		var targetType = String($filterTargetType.val() || '').toLowerCase().trim();
		var status = String($filterStatus.val() || '').toLowerCase().trim();

		var shown = all.filter(function (a) {
			var tagMatch = (Array.isArray(a.tags) ? a.tags : []).some(function (t) {
				return String(t).toLowerCase().indexOf(q) !== -1;
			});

			var textMatch = !q
				|| String(a.name || '').toLowerCase().indexOf(q) !== -1
				|| String(a.description || '').toLowerCase().indexOf(q) !== -1
				|| String(a.source || '').toLowerCase().indexOf(q) !== -1
				|| String(a.ability_type || '').toLowerCase().indexOf(q) !== -1
				|| String(a.cost || '').toLowerCase().indexOf(q) !== -1
				|| tagMatch;

			var abilityTypeMatch = !abilityType || String(a.ability_type || '').toLowerCase() === abilityType;
			var costTypeMatch = !costType || String(a.cost_type || '').toLowerCase() === costType;
			var targetTypeMatch = !targetType || String(a.target_type || '').toLowerCase() === targetType;

			var statusMatch = true;
			if (status === 'active') statusMatch = a.is_active;
			if (status === 'inactive') statusMatch = !a.is_active;
			if (status === 'passive') statusMatch = a.is_passive;
			if (status === 'non-passive') statusMatch = !a.is_passive;

			return textMatch && abilityTypeMatch && costTypeMatch && targetTypeMatch && statusMatch;
		});

		renderTable(shown);
	}

	function loadAll() {
		if (!ajaxEndpoint) {
			notice('Missing AJAX endpoint.', 'error');
			return;
		}

		if (!nonce) {
			notice('Missing nonce.', 'error');
			return;
		}

		if (activeXhr && activeXhr.readyState !== 4) {
			activeXhr.abort();
		}

		$tbody.html(
			'<tr class="nw-loading-row"><td colspan="9"><div class="nw-spinner"></div>Loading abilities…</td></tr>'
		);

		activeXhr = $.post(ajaxEndpoint, {
			action: 'nw_abilities_load',
			nonce: nonce
		}, function (res) {
	console.log('nw_abilities_load response:', res);
	if (!res || !res.success) {
		notice('Error: ' + ((res && res.data) || 'Unknown error'), 'error');
		return;
	}

			var rows = Array.isArray(res.data)
				? res.data
				: (res.data && typeof res.data === 'object' ? Object.values(res.data) : []);

			all = normalizeAbilities(rows)
				.sort(function (a, b) {
					if ((a.sort_order || 0) !== (b.sort_order || 0)) {
						return (a.sort_order || 0) - (b.sort_order || 0);
					}
					return String(a.name || '').localeCompare(String(b.name || ''));
				});

			updateStats(all);
			populateFilters();
			applyFilters();
		}).fail(function (xhr, status) {
			if (status !== 'abort') {
				notice('Request failed (' + (xhr.status || status) + ').', 'error');
			}
		}).always(function () {
			activeXhr = null;
		});
	}

	function confirmModal(message, onConfirm) {
		if ($('.nw-confirm-overlay').length) return;

		var overlay = $(
			'<div class="nw-confirm-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99999;display:flex;align-items:center;justify-content:center;">' +
				'<div class="nw-confirm-box" style="background:#1a1a1a;border:1px solid #adff00;border-radius:8px;padding:32px 28px;min-width:320px;text-align:center;">' +
					'<p style="color:#fff;margin-bottom:24px;font-family:Chakra Petch,sans-serif;">' + esc(message) + '</p>' +
					'<button type="button" class="nw-confirm-yes nw-action-btn" style="margin-right:12px;">Delete</button>' +
					'<button type="button" class="nw-confirm-no nw-action-btn" style="background:#333;">Cancel</button>' +
				'</div>' +
			'</div>'
		);

		$('body').append(overlay);

		overlay.find('.nw-confirm-yes').on('click', function () {
			overlay.remove();
			onConfirm();
		});

		overlay.find('.nw-confirm-no').on('click', function () {
			overlay.remove();
		});

		overlay.on('click', function (e) {
			if ($(e.target).is(overlay)) overlay.remove();
		});
	}

	function openModal(id) {
		if ($form.length && $form[0]) {
			$form[0].reset();
		}

		$fieldId.val('');
		updateImgPreview('');
		setCheckbox($fieldIsPassive, false);
		setCheckbox($fieldIsActive, true);
		$fieldCostType.val('none');
		$fieldTargetType.val('self');
		$fieldCostValue.val(0);
		$fieldRangeTiles.val(1);
		$fieldDurationTurns.val(0);
		$fieldSortOrder.val(0);

		if (id) {
			var a = all.find(function (x) {
				return x.id === id;
			});

			if (!a) {
				notice('Ability data not loaded yet.', 'error');
				return;
			}

			$fieldId.val(a.id);
			$fieldName.val(a.name || '');
			$fieldDescription.val(a.description || '');
			$fieldAbilityType.val(a.ability_type || '');
			$fieldSource.val(a.source || '');
			$fieldGmNotes.val(a.gm_notes || '');
			$fieldCost.val(a.cost || '');
			$fieldImgUrl.val(a.img_url || '');
			$fieldTags.val(tagsStr(a.tags));
			$fieldCostType.val(a.cost_type || 'none');
			$fieldCostValue.val(a.cost_value != null ? a.cost_value : 0);
			$fieldTargetType.val(a.target_type || 'self');
			$fieldRangeTiles.val(a.range_tiles != null ? a.range_tiles : 1);
			$fieldDurationTurns.val(a.duration_turns != null ? a.duration_turns : 0);
			$fieldSortOrder.val(a.sort_order != null ? a.sort_order : 0);
			setCheckbox($fieldIsPassive, !!a.is_passive);
			setCheckbox($fieldIsActive, !!a.is_active);

			updateImgPreview(a.img_url || '');

			$('#nw-modal-title').text('Edit Ability');
			$saveLabel.text('Save Changes');
			$deleteBtn.show().data('id', id);
		} else {
			$('#nw-modal-title').text('New Ability');
			$saveLabel.text('Create Ability');
			$deleteBtn.hide().removeData('id');
		}

		$modalOverlay.fadeIn(150);
	}

	/* ── Events ───────────────────────────────────────────── */
	$fieldImgUrl.on('input change', function () {
		updateImgPreview($(this).val().trim());
	});

	$('#nw-modal-close, #nw-cancel-btn').on('click', function () {
		$modalOverlay.fadeOut(150);
	});

	$modalOverlay.on('click', function (e) {
		if ($(e.target).is('#nw-modal-overlay')) {
			$modalOverlay.fadeOut(150);
		}
	});

	$(document).on('click', '.nw-edit-btn', function () {
		openModal($(this).data('id'));
	});

	$('#nw-add-btn').on('click', function () {
		openModal(null);
	});

	$('#nw-refresh-btn').on('click', function () {
		loadAll();
	});

	$resetFiltersBtn.on('click', function () {
		$search.val('');
		$filterAbilityType.val('');
		$filterCostType.val('');
		$filterTargetType.val('');
		$filterStatus.val('');
		applyFilters();
	});

	$search.on('input', debounce(applyFilters, 150));
	$filterAbilityType.on('change', applyFilters);
	$filterCostType.on('change', applyFilters);
	$filterTargetType.on('change', applyFilters);
	$filterStatus.on('change', applyFilters);

	/* ── Save ─────────────────────────────────────────────── */
	$saveBtn.on('click', function () {
		var name = $fieldName.val().trim();

		if (!name) {
			notice('Name is required.', 'error');
			return;
		}

		var btn = $(this);
		var previousLabel = $saveLabel.text();

		btn.prop('disabled', true);
		$saveLabel.text('Saving…');

		var payload = {
			action: 'nw_abilities_save',
			nonce: nonce,
			id: $fieldId.val().trim(),
			name: name,
			description: $fieldDescription.val().trim(),
			ability_type: $fieldAbilityType.val().trim(),
			source: $fieldSource.val().trim(),
			gm_notes: $fieldGmNotes.val().trim(),
			cost: $fieldCost.val().trim(),
			img_url: $fieldImgUrl.val().trim(),
			tags: $fieldTags.val().trim(),
			cost_type: $fieldCostType.val(),
			cost_value: $fieldCostValue.val(),
			target_type: $fieldTargetType.val(),
			range_tiles: $fieldRangeTiles.val(),
			duration_turns: $fieldDurationTurns.val(),
			is_passive: getCheckboxValue($fieldIsPassive),
			is_active: getCheckboxValue($fieldIsActive),
			sort_order: $fieldSortOrder.val()
		};

		$.post(ajaxEndpoint, payload, function (res) {
			btn.prop('disabled', false);
			$saveLabel.text(previousLabel);

			if (res && res.success) {
				notice('Ability saved!', 'success');
				$modalOverlay.fadeOut(150);
				loadAll();
			} else {
				notice('Error: ' + ((res && res.data) || 'Unknown'), 'error');
			}
		}).fail(function (xhr) {
			btn.prop('disabled', false);
			$saveLabel.text(previousLabel);
			notice('Request failed (' + (xhr.status || 'network') + ').', 'error');
		});
	});

	/* ── Delete ───────────────────────────────────────────── */
	$deleteBtn.on('click', function () {
		var id = $(this).data('id');
		if (!id) return;

		confirmModal('Delete this ability permanently?', function () {
			$.post(ajaxEndpoint, {
				action: 'nw_abilities_delete',
				nonce: nonce,
				id: id
			}, function (res) {
				if (res && res.success) {
					notice('Ability deleted.', 'success');
					$modalOverlay.fadeOut(150);
					loadAll();
				} else {
					notice('Delete failed: ' + ((res && res.data) || 'Unknown'), 'error');
				}
			}).fail(function (xhr) {
				notice('Delete request failed (' + (xhr.status || 'network') + ').', 'error');
			});
		});
	});

	/* ── Init ─────────────────────────────────────────────── */
	loadAll();
});
