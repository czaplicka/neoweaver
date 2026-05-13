/**
 * NeoWeaver Admin — Items (cyber_items)
 * Works with the updated items.php backend.
 */
/* global NWItems, jQuery, ajaxurl */
(function ($) {
	'use strict';

	var cfg = window.NWItems || {};
	var ajax = cfg.ajaxurl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
	var nonce = cfg.nonce || '';
	var allItems = [];
	var editingId = null;
	var filterType = '';
	var filterRarity = '';
	var noticeTimer = null;

	function esc(str) {
		return $('<div>').text(str == null ? '' : String(str)).html();
	}

	function boolVal(v) {
		return v === true || v === 1 || v === '1' || v === 'true';
	}

	function debounce(fn, delay) {
		var timer = null;
		return function () {
			var ctx = this;
			var args = arguments;
			clearTimeout(timer);
			timer = setTimeout(function () {
				fn.apply(ctx, args);
			}, delay || 150);
		};
	}

	function clearNoticeTimer() {
		if (noticeTimer) {
			clearTimeout(noticeTimer);
			noticeTimer = null;
		}
	}

	function notice(msg, type) {
		var safeType = String(type || 'info').replace(/[^a-z-]/g, '');
		var isError = safeType === 'error';

		clearNoticeTimer();

		$('#nw-notice')
			.stop(true, true)
			.text(msg || '')
			.css('background', isError ? '#5c0000' : '#1a3300')
			.css('color', isError ? '#ff8080' : '#adff00')
			.show();

		noticeTimer = setTimeout(function () {
			$('#nw-notice').fadeOut(200);
			noticeTimer = null;
		}, 3000);
	}

	function rarityBadge(r) {
		var rarity = r || 'common';
		return '<span class="nw-rarity nw-rarity--' + esc(rarity) + '">' + esc(rarity) + '</span>';
	}

	function typeBadge(t) {
		var type = t || '—';
		return '<span class="nw-item-type">' + esc(type) + '</span>';
	}

	function thumbHtml(url) {
		if (url) {
			return '<img class="nw-item-thumb" src="' + esc(url) + '" alt="" loading="lazy" style="width:44px;height:44px;object-fit:cover;border-radius:8px;background:#111;border:1px solid #2b2b2b;" />';
		}
		return '<div class="nw-item-thumb--empty" style="width:44px;height:44px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:#111;border:1px solid #2b2b2b;color:#666;">⚙</div>';
	}

	function syncFilterStateFromUi() {
		filterType = $('#nw-filter-type').val() || '';
		filterRarity = $('#nw-filter-rarity').val() || '';
	}

	function updateTypeFilterOptions(items) {
		var types = [];
		var html = '<option value="">All types</option>';

		(items || []).forEach(function (item) {
			var t = (item.type || '').trim();
			if (t && types.indexOf(t) === -1) {
				types.push(t);
			}
		});

		types.sort(function (a, b) {
			return a.localeCompare(b);
		});

		types.forEach(function (type) {
			html += '<option value="' + esc(type) + '">' + esc(type) + '</option>';
		});

		$('#nw-filter-type').html(html);

		if (filterType && types.indexOf(filterType) === -1) {
			filterType = '';
		}

		$('#nw-filter-type').val(filterType);
		$('#nw-filter-rarity').val(filterRarity);
	}

	function renderTable(items) {
		syncFilterStateFromUi();

		var search = ($('#nw-search').val() || '').toLowerCase().trim();

		var filtered = (items || []).filter(function (item) {
			var name = (item.name || '').toLowerCase();
			var desc = (item.description || '').toLowerCase();

			if (filterType && (item.type || '') !== filterType) {
				return false;
			}

			if (filterRarity && (item.rarity || '') !== filterRarity) {
				return false;
			}

			if (search && name.indexOf(search) === -1 && desc.indexOf(search) === -1) {
				return false;
			}

			return true;
		});

		var activeCount = filtered.filter(function (item) {
			return boolVal(item.is_active);
		}).length;

		$('#nw-total').text(filtered.length);
		$('#nw-active-count').text(activeCount);

		if (!filtered.length) {
			$('#nw-items-tbody').html('<tr><td colspan="9" style="text-align:center;color:#777;padding:32px;">No items found.</td></tr>');
			return;
		}

		var rows = filtered.map(function (item) {
			var active = boolVal(item.is_active);

			return ''
				+ '<tr data-id="' + esc(item.id) + '">'
				+ '<td>' + thumbHtml(item.img_url) + '</td>'
				+ '<td><strong>' + esc(item.name) + '</strong>'
				+ (item.description ? '<br><small style="color:#888;">' + esc(item.description.length > 80 ? item.description.slice(0, 80) + '…' : item.description) + '</small>' : '')
				+ '</td>'
				+ '<td>' + typeBadge(item.type) + '</td>'
				+ '<td>' + rarityBadge(item.rarity) + '</td>'
				+ '<td>' + esc(item.slot || 'none') + '</td>'
				+ '<td>' + esc(item.size || 'medium') + '</td>'
				+ '<td>' + esc(item.price || 0) + '</td>'
				+ '<td><button type="button" class="nw-toggle nw-toggle--' + (active ? 'on' : 'off') + '" data-id="' + esc(item.id) + '" data-active="' + (active ? '1' : '0') + '">' + (active ? '✔' : '✖') + '</button></td>'
				+ '<td><button type="button" class="button button-small nw-edit-btn" data-id="' + esc(item.id) + '">Edit</button></td>'
				+ '</tr>';
		}).join('');

		$('#nw-items-tbody').html(rows);
	}

	function updateImagePreview() {
		var url = ($('#nw-field-img-url').val() || '').trim();
		var $wrap = $('#nw-item-image-preview-wrap');
		var $img = $('#nw-item-image-preview');

		if (!url) {
			$img.attr('src', '');
			$wrap.hide();
			return;
		}

		$img.attr('src', url);
		$wrap.show();
	}

	function resetForm() {
		editingId = null;

		$('#nw-field-id').val('');
		$('#nw-field-name').val('');
		$('#nw-field-description').val('');
		$('#nw-field-type').val('');
		$('#nw-field-rarity').val('common');
		$('#nw-field-slot').val('none');
		$('#nw-field-size').val('medium');
		$('#nw-field-price').val(0);
		$('#nw-field-power-value').val(0);
		$('#nw-field-mass').val(1);
		$('#nw-field-stack-limit').val(1);
		$('#nw-field-img-url').val('');
		$('#nw-field-sound-url').val('');
		$('#nw-field-tags').val('');
		$('#nw-field-min-tech').val(0);
		$('#nw-field-min-magic').val(0);
		$('#nw-field-min-wealth').val(0);
		$('#nw-field-restricted-archetype').val('');
		$('#nw-field-is-container').prop('checked', false);
		$('#nw-field-active').prop('checked', true);

		$('#nw-item-image-preview').attr('src', '');
		$('#nw-item-image-preview-wrap').hide();

		$('#nw-modal-title').text('Add Item');
		$('#nw-delete-btn').hide();
		$('#nw-form-notice').empty();
	}

	function openModal(title, item) {
		resetForm();

		if (item) {
			editingId = item.id || null;

			$('#nw-field-id').val(item.id || '');
			$('#nw-field-name').val(item.name || '');
			$('#nw-field-description').val(item.description || '');
			$('#nw-field-type').val(item.type || '');
			$('#nw-field-rarity').val(item.rarity || 'common');
			$('#nw-field-slot').val(item.slot || 'none');
			$('#nw-field-size').val(item.size || 'medium');
			$('#nw-field-price').val(item.price || 0);
			$('#nw-field-power-value').val(item.power_value || 0);
			$('#nw-field-mass').val(item.mass || 1);
			$('#nw-field-stack-limit').val(item.stack_limit || 1);
			$('#nw-field-img-url').val(item.img_url || '');
			$('#nw-field-sound-url').val(item.sound_url || '');
			$('#nw-field-tags').val(Array.isArray(item.tags) ? item.tags.join(', ') : '');
			$('#nw-field-min-tech').val(item.min_kingdom_tech || 0);
			$('#nw-field-min-magic').val(item.min_kingdom_magic || 0);
			$('#nw-field-min-wealth').val(item.min_kingdom_wealth || 0);
			$('#nw-field-restricted-archetype').val(item.restricted_to_archetype || '');
			$('#nw-field-is-container').prop('checked', boolVal(item.is_container));
			$('#nw-field-active').prop('checked', boolVal(item.is_active));

			$('#nw-delete-btn').show();
		}

		$('#nw-modal-title').text(title || 'Item');
		updateImagePreview();
		$('#nw-modal-overlay').show();
		$('#nw-field-name').trigger('focus');
	}

	function closeModal() {
		$('#nw-modal-overlay').hide();
		editingId = null;
	}

	function loadItems() {
		if (!ajax || !nonce) {
			notice('Missing AJAX config.', 'error');
			return;
		}

		syncFilterStateFromUi();

		$('#nw-items-tbody').html('<tr><td colspan="9" style="text-align:center;color:#777;padding:32px;">Loading…</td></tr>');

		$.post(ajax, {
			action: 'nw_items_load',
			nonce: nonce
		}, function (res) {
			if (!res || !res.success) {
				notice((res && res.data) || 'Load failed', 'error');
				$('#nw-items-tbody').html('<tr><td colspan="9" style="text-align:center;color:#777;padding:32px;">Load failed.</td></tr>');
				return;
			}

			allItems = Array.isArray(res.data) ? res.data : [];
			updateTypeFilterOptions(allItems);
			renderTable(allItems);
		}).fail(function (xhr) {
			notice('Request failed (' + (xhr.status || 'network') + ').', 'error');
			$('#nw-items-tbody').html('<tr><td colspan="9" style="text-align:center;color:#777;padding:32px;">Request failed.</td></tr>');
		});
	}

	function loadSingle(id) {
		if (!id) {
			notice('Missing item ID.', 'error');
			return;
		}

		$.post(ajax, {
			action: 'nw_items_get',
			nonce: nonce,
			id: String(id)
		}, function (res) {
			if (!res || !res.success || !res.data) {
				notice((res && res.data) || 'Item not found.', 'error');
				return;
			}

			openModal('Edit Item', res.data);
		}).fail(function (xhr) {
			notice('Request failed (' + (xhr.status || 'network') + ').', 'error');
		});
	}

	function collectPayload() {
		return {
			action: 'nw_items_save',
			nonce: nonce,
			id: ($('#nw-field-id').val() || '').trim(),
			name: ($('#nw-field-name').val() || '').trim(),
			description: ($('#nw-field-description').val() || '').trim(),
			type: ($('#nw-field-type').val() || '').trim(),
			rarity: $('#nw-field-rarity').val(),
			slot: $('#nw-field-slot').val(),
			size: $('#nw-field-size').val(),
			price: $('#nw-field-price').val(),
			power_value: $('#nw-field-power-value').val(),
			mass: $('#nw-field-mass').val(),
			stack_limit: $('#nw-field-stack-limit').val(),
			img_url: ($('#nw-field-img-url').val() || '').trim(),
			sound_url: ($('#nw-field-sound-url').val() || '').trim(),
			tags: ($('#nw-field-tags').val() || '').trim(),
			min_kingdom_tech: $('#nw-field-min-tech').val(),
			min_kingdom_magic: $('#nw-field-min-magic').val(),
			min_kingdom_wealth: $('#nw-field-min-wealth').val(),
			restricted_to_archetype: ($('#nw-field-restricted-archetype').val() || '').trim(),
			is_container: $('#nw-field-is-container').is(':checked') ? '1' : '0',
			is_active: $('#nw-field-active').is(':checked') ? '1' : '0'
		};
	}

	var debouncedRender = debounce(function () {
		renderTable(allItems);
	}, 150);

	$(document).on('click', '#nw-add-btn', function () {
		openModal('Add Item', null);
	});

	$(document).on('click', '#nw-refresh-btn', function () {
		loadItems();
	});

	$(document).on('click', '#nw-modal-close, #nw-cancel-btn', function () {
		closeModal();
	});

	$(document).on('click', '#nw-modal-overlay', function (e) {
		if ($(e.target).is('#nw-modal-overlay')) {
			closeModal();
		}
	});

	$(document).on('input', '#nw-search', debouncedRender);

	$(document).on('change', '#nw-filter-type', function () {
		filterType = $(this).val() || '';
		renderTable(allItems);
	});

	$(document).on('change', '#nw-filter-rarity', function () {
		filterRarity = $(this).val() || '';
		renderTable(allItems);
	});

	$(document).on('input change blur', '#nw-field-img-url', function () {
		updateImagePreview();
	});

	$(document).on('click', '.nw-edit-btn', function () {
		var id = $(this).data('id');
		if (!id) return;
		loadSingle(id);
	});

	$(document).on('click', '.nw-toggle', function () {
		var $btn = $(this);
		var id = $btn.data('id');
		var current = String($btn.data('active')) === '1';
		var next = !current;

		$.post(ajax, {
			action: 'nw_items_toggle',
			nonce: nonce,
			id: String(id),
			is_active: next ? '1' : '0'
		}, function (res) {
			if (!res || !res.success) {
				notice((res && res.data) || 'Toggle failed.', 'error');
				return;
			}

			allItems = allItems.map(function (item) {
				if (String(item.id) === String(id)) {
					return $.extend({}, item, {
						is_active: next
					});
				}
				return item;
			});

			renderTable(allItems);
			notice('Item updated.', 'success');
		}).fail(function (xhr) {
			notice('Request failed (' + (xhr.status || 'network') + ').', 'error');
		});
	});

	$(document).on('click', '#nw-save-btn', function () {
		var payload = collectPayload();
		var $btn = $(this);
		var originalText = $btn.text();

		if (!ajax || !nonce) {
			notice('Missing AJAX config.', 'error');
			return;
		}

		if (!payload.name) {
			notice('Name is required.', 'error');
			return;
		}

		$btn.prop('disabled', true).text('Saving…');

		$.post(ajax, payload, function (res) {
			$btn.prop('disabled', false).text(originalText);

			if (!res || !res.success) {
				notice((res && res.data) || 'Save failed.', 'error');
				return;
			}

			notice('Saved.', 'success');
			closeModal();
			loadItems();
		}).fail(function (xhr) {
			$btn.prop('disabled', false).text(originalText);
			notice('Request failed (' + (xhr.status || 'network') + ').', 'error');
		});
	});

	$(document).on('click', '#nw-delete-btn', function () {
		var id = ($('#nw-field-id').val() || '').trim();

		if (!id) {
			return;
		}

		if (!window.confirm('Delete this item? This cannot be undone.')) {
			return;
		}

		$.post(ajax, {
			action: 'nw_items_delete',
			nonce: nonce,
			id: id
		}, function (res) {
			if (!res || !res.success) {
				notice((res && res.data) || 'Delete failed.', 'error');
				return;
			}

			notice('Deleted.', 'success');
			closeModal();
			loadItems();
		}).fail(function (xhr) {
			notice('Request failed (' + (xhr.status || 'network') + ').', 'error');
		});
	});

	loadItems();

})(jQuery);
