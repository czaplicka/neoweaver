jQuery(function ($) {
'use strict';

var cfg = window.NWContainers || {};
var ajaxEndpoint = cfg.ajaxurl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
var nonce = cfg.nonce || '';
var uploadsUrl = (cfg.uploads_url || '').replace(/\/+$/, '');
var noticeTimer = null;

var $notice = $('#nw-notice');
var $tbody = $('#nw-containers-tbody');
var $search = $('#nw-search');
var $filterActive = $('#nw-filter-active');
var $filterRarity = $('#nw-filter-rarity');
var $filterSize = $('#nw-filter-size');
var $clearBtn = $('#nw-clear-filters');
var $modalOverlay = $('#nw-modal-overlay');
var $form = $('#nw-container-form');
var $saveBtn = $('#nw-save-btn');
var $saveLabel = $('#nw-save-label');
var $deleteBtn = $('#nw-delete-btn');

var $fieldId = $('#nw-field-id');
var $fieldName = $('#nw-field-name');
var $fieldDescription = $('#nw-field-description');
var $fieldTotalSlots = $('#nw-field-total_slots');
var $fieldAllowedSizes = $('#nw-field-allowed_sizes');
var $fieldImgUrl = $('#nw-field-img_url');
var $fieldRarity = $('#nw-field-rarity');
var $fieldIsActive = $('#nw-field-is_active');
var $fieldParentId = $('#nw-field-parent_id');
var $imgPreview = $('#nw-img-preview');
var $imgPreviewWrap = $('#nw-img-preview-wrap');

var all = [];
var activeXhr = null;

function esc(s) { return $('<span>').text(s || '').html(); }
function clearNoticeTimer() { if (noticeTimer) { clearTimeout(noticeTimer); noticeTimer = null; } }
function notice(msg, type) {
	var safeType = String(type || 'info').replace(/[^a-z-]/g, '');
	clearNoticeTimer();
	$notice.attr('class', 'nw-notice nw-notice-' + safeType).text(msg).stop(true, true).show();
	noticeTimer = setTimeout(function () { $notice.fadeOut(300); noticeTimer = null; }, 3500);
}
function debounce(fn, delay) {
	var timer;
	return function () {
		var args = arguments, ctx = this;
		clearTimeout(timer);
		timer = setTimeout(function () { fn.apply(ctx, args); }, delay);
	};
}
function setIsActive(value) { $fieldIsActive.prop('checked', !!value); }
function getIsActive() { return $fieldIsActive.is(':checked') ? '1' : '0'; }
function sizesStr(arr) {
	if (!arr) return '';
	if (Array.isArray(arr)) return arr.join(', ');
	if (typeof arr === 'string') return arr;
	return '';
}
function normalize(data) {
	var list = data;
	if (typeof list === 'string') {
		try { list = JSON.parse(list); } catch (e) { list = []; }
	}
	if (!Array.isArray(list)) list = (list && typeof list === 'object') ? Object.values(list) : [];

	return list.map(function (item) {
		var sizes = item.allowed_sizes;
		if (typeof sizes === 'string') {
			try { sizes = JSON.parse(sizes); }
			catch (e) { sizes = sizes.split(',').map(function (t) { return t.trim(); }).filter(Boolean); }
		}
		return {
			id: item.id || '',
			name: item.name || '',
			description: item.description || '',
			total_slots: item.total_slots != null ? item.total_slots : 5,
			allowed_sizes: Array.isArray(sizes) ? sizes : [],
			img_url: item.img_url || '',
			rarity: item.rarity || 'common',
			is_active: item.is_active != null ? !!item.is_active : true,
			parent_id: item.parent_id || ''
		};
	});
}
function resolveImgUrl(raw) {
	if (!raw) return '';
	var value = String(raw).trim();
	if (!value) return '';
	if (/^https?:\/\//i.test(value) || /^\/\//.test(value)) return value;
	if (value.charAt(0) === '/') return value;
	if (!uploadsUrl) return '';
	return uploadsUrl + '/' + value.replace(/^\/+/, '');
}
function updateImgPreview(raw) {
	var url = resolveImgUrl(raw);
	if (url) { $imgPreview.attr('src', url); $imgPreviewWrap.show(); }
	else { $imgPreview.attr('src', ''); $imgPreviewWrap.hide(); }
}
function rarityLabel(rarity) {
	return String(rarity || 'common').replace(/^./, function (m) { return m.toUpperCase(); });
}
function updateStats(data) {
	var active = 0, rarePlus = 0;
	(data || []).forEach(function (row) {
		if (row.is_active) active++;
		if (['rare', 'epic', 'legendary'].indexOf(String(row.rarity || '').toLowerCase()) !== -1) rarePlus++;
	});
	$('#nw-total').text(data.length);
	$('#nw-active').text(active);
	$('#nw-inactive').text(data.length - active);
	$('#nw-rareplus').text(rarePlus);
}
function hasActiveFilters() {
	return $search.val().trim() !== '' || $filterActive.val() !== '' || $filterRarity.val() !== '' || $filterSize.val() !== '';
}
function updateClearBtn() { $clearBtn.toggle(hasActiveFilters()); }
function renderTable(data) {
	if (!data.length) {
		$tbody.html('<tr class="nw-loading-row"><td colspan="8">No containers found.</td></tr>');
		return;
	}

	$tbody.html(data.map(function (c) {
		var sizes = Array.isArray(c.allowed_sizes) ? c.allowed_sizes : [];
		var sizesHtml = sizes.map(function (s) {
			return '<span class="nw-size-pill nw-size-' + esc(s) + '">' + esc(s) + '</span>';
		}).join('');

		var fullImg = resolveImgUrl(c.img_url);
		var imgH = fullImg
			? '<img src="' + esc(fullImg) + '" alt="" width="36" height="36" style="border-radius:4px;object-fit:cover;" data-fallback="1">'
			: '<span style="font-size:20px;">📦</span>';

		var activeH = c.is_active
			? '<span class="nw-toggle-active">Yes</span>'
			: '<span class="nw-toggle-inactive">No</span>';

		var rarityH = '<span class="nw-rarity-pill nw-rarity-' + esc(c.rarity) + '">' + esc(rarityLabel(c.rarity)) + '</span>';
		var rowClass = c.is_active ? '' : ' class="nw-row-inactive"';
		var parentShort = c.parent_id ? esc(c.parent_id.slice(0, 8) + '…') : '—';

		return '<tr' + rowClass + '>'
			+ '<td>' + imgH + '</td>'
			+ '<td><strong>' + esc(c.name) + '</strong><div class="nw-subcell">' + esc(c.description || '') + '</div></td>'
			+ '<td>' + sizesHtml + '</td>'
			+ '<td class="nw-num">' + esc(String(c.total_slots || 0)) + '</td>'
			+ '<td>' + rarityH + '</td>'
			+ '<td><code class="nw-inline-code">' + parentShort + '</code></td>'
			+ '<td>' + activeH + '</td>'
			+ '<td><div class="nw-row-actions"><button class="nw-action-btn nw-edit-btn" data-id="' + esc(c.id) + '">Edit</button><button class="nw-action-btn nw-dup-btn" data-id="' + esc(c.id) + '">Dup</button></div></td>'
			+ '</tr>';
	}).join(''));

	$tbody.find('img[data-fallback]').off('error.nwFallback').on('error.nwFallback', function () { $(this).hide(); });
	if (typeof lucide !== 'undefined') lucide.createIcons();
}
function applyFilters() {
	var q = $search.val().toLowerCase().trim();
	var active = $filterActive.val();
	var rarity = $filterRarity.val();
	var size = $filterSize.val();

	var shown = all.filter(function (c) {
		if (active === '1' && !c.is_active) return false;
		if (active === '0' && c.is_active) return false;
		if (rarity && String(c.rarity) !== rarity) return false;
		if (size && (!Array.isArray(c.allowed_sizes) || c.allowed_sizes.indexOf(size) === -1)) return false;
		if (q) {
			var sizesMatch = (Array.isArray(c.allowed_sizes) ? c.allowed_sizes : []).some(function (s) {
				return String(s).toLowerCase().indexOf(q) !== -1;
			});
			if (
				String(c.name || '').toLowerCase().indexOf(q) === -1 &&
				String(c.description || '').toLowerCase().indexOf(q) === -1 &&
				String(c.parent_id || '').toLowerCase().indexOf(q) === -1 &&
				!sizesMatch
			) return false;
		}
		return true;
	});

	renderTable(shown);
	updateClearBtn();
}
function loadAll() {
	if (!ajaxEndpoint) { notice('Missing AJAX endpoint.', 'error'); return; }
	if (!nonce) { notice('Missing nonce.', 'error'); return; }
	if (activeXhr && activeXhr.readyState !== 4) activeXhr.abort();

	$tbody.html('<tr class="nw-loading-row"><td colspan="8"><span class="nw-spinner"></span> Loading…</td></tr>');
	activeXhr = $.post(ajaxEndpoint, { action: 'nw_containers_load', nonce: nonce }, function (res) {
		if (!res || !res.success) {
			notice('Error: ' + ((res && res.data) || 'Unknown error'), 'error');
			return;
		}
		var rows = Array.isArray(res.data) ? res.data : (res.data && typeof res.data === 'object' ? Object.values(res.data) : []);
		all = normalize(rows);
		updateStats(all);
		applyFilters();
	}).fail(function (xhr, status) {
		if (status !== 'abort') notice('Request failed (' + (xhr.status || status) + ').', 'error');
	}).always(function () { activeXhr = null; });
}
function confirmModal(message, onConfirm) {
	if ($('.nw-confirm-overlay').length) return;
	var overlay = $('<div class="nw-confirm-overlay nw-modal-overlay"><div class="nw-modal" style="max-width:380px;"><div class="nw-modal-body" style="padding:28px 24px 8px;"><p style="color:#e0e0e0;margin:0 0 20px;">' + esc(message) + '</p></div><div class="nw-modal-footer"><button class="nw-btn nw-btn-danger nw-confirm-yes">Delete</button><button class="nw-btn nw-btn-ghost nw-confirm-no">Cancel</button></div></div></div>');
	$('body').append(overlay);
	overlay.find('.nw-confirm-yes').on('click', function () { overlay.remove(); onConfirm(); });
	overlay.find('.nw-confirm-no').on('click', function () { overlay.remove(); });
	overlay.on('click', function (e) { if ($(e.target).is(overlay)) overlay.remove(); });
}
function openModal(id) {
	if ($form.length && $form[0]) $form[0].reset();
	$fieldId.val('');
	$fieldTotalSlots.val(5);
	$fieldAllowedSizes.val('tiny, small, medium, large');
	$fieldRarity.val('common');
	$fieldParentId.val('');
	updateImgPreview('');
	setIsActive(true);

	if (id) {
		var c = all.find(function (x) { return x.id === id; });
		if (!c) { notice('Container data not loaded.', 'error'); return; }
		$fieldId.val(c.id);
		$fieldName.val(c.name || '');
		$fieldDescription.val(c.description || '');
		$fieldTotalSlots.val(c.total_slots != null ? c.total_slots : 5);
		$fieldAllowedSizes.val(sizesStr(c.allowed_sizes));
		$fieldImgUrl.val(c.img_url || '');
		$fieldRarity.val(c.rarity || 'common');
		$fieldParentId.val(c.parent_id || '');
		setIsActive(!!c.is_active);
		updateImgPreview(c.img_url || '');
		$('#nw-modal-title').text('Edit Container');
		$saveLabel.text('Save Changes');
		$deleteBtn.show().data('id', id);
	} else {
		$('#nw-modal-title').text('New Container');
		$saveLabel.text('Create Container');
		$deleteBtn.hide().removeData('id');
	}

	$modalOverlay.fadeIn(150);
}

$fieldImgUrl.on('input change', function () { updateImgPreview($(this).val().trim()); });
$('#nw-modal-close, #nw-cancel-btn').on('click', function () { $modalOverlay.fadeOut(150); });
$modalOverlay.on('click', function (e) { if ($(e.target).is('#nw-modal-overlay')) $modalOverlay.fadeOut(150); });
$(document).on('click', '.nw-edit-btn', function () { openModal($(this).data('id')); });
$('#nw-add-btn').on('click', function () { openModal(null); });
$('#nw-refresh-btn').on('click', loadAll);
$search.on('input', debounce(applyFilters, 150));
$filterActive.on('change', applyFilters);
$filterRarity.on('change', applyFilters);
$filterSize.on('change', applyFilters);
$clearBtn.on('click', function () {
	$search.val('');
	$filterActive.val('');
	$filterRarity.val('');
	$filterSize.val('');
	applyFilters();
});

$saveBtn.on('click', function () {
	var name = $fieldName.val().trim();
	if (!name) { notice('Name is required.', 'error'); return; }
	var parentId = $fieldParentId.val().trim();
	if (parentId && !/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(parentId)) {
		notice('Parent item ID must be a valid UUID.', 'error');
		return;
	}

	var btn = $(this), prevLabel = $saveLabel.text();
	btn.prop('disabled', true);
	$saveLabel.text('Saving…');

	$.post(ajaxEndpoint, {
		action: 'nw_containers_save',
		nonce: nonce,
		id: $fieldId.val().trim(),
		name: name,
		description: $fieldDescription.val().trim(),
		total_slots: $fieldTotalSlots.val(),
		allowed_sizes: $fieldAllowedSizes.val().trim(),
		img_url: $fieldImgUrl.val().trim(),
		rarity: $fieldRarity.val(),
		is_active: getIsActive(),
		parent_id: parentId
	}, function (res) {
		btn.prop('disabled', false);
		$saveLabel.text(prevLabel);
		if (res && res.success) {
			notice('Container saved!', 'success');
			$modalOverlay.fadeOut(150);
			loadAll();
		} else {
			notice('Error: ' + ((res && res.data) || 'Unknown'), 'error');
		}
	}).fail(function (xhr) {
		btn.prop('disabled', false);
		$saveLabel.text(prevLabel);
		notice('Request failed (' + (xhr.status || 'network') + ').', 'error');
	});
});

$deleteBtn.on('click', function () {
	var id = $(this).data('id');
	if (!id) return;
	confirmModal('Delete this container permanently?', function () {
		$.post(ajaxEndpoint, { action: 'nw_containers_delete', nonce: nonce, id: id }, function (res) {
			if (res && res.success) {
				notice('Container deleted.', 'success');
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

$(document).on('click', '.nw-dup-btn', function () {
	var id = $(this).data('id');
	if (!id) return;
	var btn = $(this);
	btn.prop('disabled', true).text('…');
	$.post(ajaxEndpoint, { action: 'nw_containers_duplicate', nonce: nonce, id: id }, function (res) {
		btn.prop('disabled', false).text('Dup');
		if (res && res.success) {
			notice('Container duplicated (inactive).', 'success');
			loadAll();
		} else {
			notice('Duplicate failed: ' + ((res && res.data) || 'Unknown'), 'error');
		}
	}).fail(function (xhr) {
		btn.prop('disabled', false).text('Dup');
		notice('Duplicate request failed (' + (xhr.status || 'network') + ').', 'error');
	});
});

loadAll();
if (typeof lucide !== 'undefined') lucide.createIcons();
});
