jQuery(function ($) {
	'use strict';

	var cfg = window.NWAbilities || {};
	var ajaxEndpoint = cfg.ajaxurl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
	var nonce = cfg.nonce || '';
	var noticeTimer = null;

	var $notice = $('#nw-notice');
	var $tbody = $('#nw-abilities-tbody');
	var $filterType = $('#nw-filter-type');
	var $filterActive = $('#nw-filter-active');
	var $search = $('#nw-search');
	var $modalOverlay = $('#nw-ability-modal');
	var $form = $('#nw-ability-form');
	var $fieldId = $('#ability-id');

	var $fieldName = $('#ability-name');
	var $fieldDescription = $('#ability-description');
	var $fieldAbilityType = $('#ability-type');
	var $fieldTargetType = $('#ability-target');
	var $fieldCostType = $('#ability-cost-type');
	var $fieldCostValue = $('#ability-cost-value');
	var $fieldRangeTiles = $('#ability-range');
	var $fieldDurationTurns = $('#ability-duration');
	var $fieldTags = $('#ability-tags');
	var $fieldImgUrl = $('#ability-img');
	var $fieldSource = $('#ability-source');
	var $fieldGmNotes = $('#ability-gm-notes');
	var $fieldIsPassive = $('#ability-is-passive');
	var $fieldIsActive = $('#ability-is-active');

	var all = [];
	var activeXhr = null;

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
		if (!$notice.length) return;

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

	function parseTags(raw) {
		if (!raw) return [];
		if (Array.isArray(raw)) return raw;

		if (typeof raw === 'string') {
			try {
				var parsed = JSON.parse(raw);
				if (Array.isArray(parsed)) return parsed;
			} catch (e) {}

			return raw.split(',').map(function (t) {
				return t.trim();
			}).filter(Boolean);
		}

		return [];
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
			return {
				id: item.id || '',
				name: item.name || '',
				description: item.description || '',
				ability_type: item.ability_type || 'active',
				source: item.source || '',
				gm_notes: item.gm_notes || '',
				img_url: item.img_url || '',
				tags: parseTags(item.tags),
				cost_type: item.cost_type || 'none',
				cost_value: item.cost_value != null ? item.cost_value : 0,
				target_type: item.target_type || 'self',
				range_tiles: item.range_tiles != null ? item.range_tiles : 1,
				duration_turns: item.duration_turns != null ? item.duration_turns : 0,
				is_passive: !!item.is_passive,
				is_active: item.is_active != null ? !!item.is_active : true,
				sort_order: item.sort_order != null ? item.sort_order : 0
			};
		});
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
		$('#nw-passive').text(passive);
	}

	function bindImageFallbacks() {
		$tbody.find('img[data-fallback]')
			.off('error.nwFallback')
			.on('error.nwFallback', function () {
				$(this).hide();
			});
	}

	function renderTable(data) {
		if (!$tbody.length) return;

		if (!data.length) {
			$tbody.html('<tr><td colspan="10" style="text-align:center;padding:32px;color:#555;">No abilities found.</td></tr>');
			return;
		}

		$tbody.html(data.map(function (a) {
			var safeId = esc(a.id);
			var tags = Array.isArray(a.tags) ? a.tags : [];

			var tagsH = tags.slice(0, 3).map(function (t) {
				return '<span class="nw-tag">' + esc(t) + '</span>';
			}).join('');

			if (tags.length > 3) {
				tagsH += '<span class="nw-tag">+' + (tags.length - 3) + '</span>';
			}

			var imgH = a.img_url
				? '<img src="' + esc(a.img_url) + '" class="nw-ability-img" loading="lazy" data-fallback="1" alt="">'
				: '<div class="nw-ability-img-placeholder">✦</div>';

			var typeClass = 'nw-type-' + esc(a.ability_type);

			var passiveH = a.is_passive
				? '<span class="nw-state-pill is-passive">Passive</span>'
				: '<span class="nw-state-pill is-not-passive">Active Skill</span>';

			var activeH = a.is_active
				? '<span class="nw-state-pill is-active">Yes</span>'
				: '<span class="nw-state-pill is-inactive">No</span>';

			var costH = a.cost_type && a.cost_type !== 'none'
				? '<span class="nw-cost-value">' + esc(String(a.cost_value)) + ' ' + esc(a.cost_type) + '</span>'
				: '—';

			return '<tr data-id="' + safeId + '">'
				+ '<td>' + imgH + '</td>'
				+ '<td><div class="nw-ability-name">' + esc(a.name) + '</div>'
				+ '<div class="nw-ability-desc">' + esc(a.description || '') + '</div></td>'
				+ '<td><span class="nw-type-badge ' + typeClass + '">' + esc(a.ability_type) + '</span></td>'
				+ '<td>' + costH + '</td>'
				+ '<td><span class="nw-meta-pill">' + esc(a.target_type || '—') + '</span></td>'
				+ '<td><span class="nw-range-value">' + esc(String(a.range_tiles)) + '</span></td>'
				+ '<td><span class="nw-duration-value">' + esc(String(a.duration_turns)) + '</span></td>'
				+ '<td>' + passiveH + '</td>'
				+ '<td>' + activeH + '</td>'
				+ '<td><div class="nw-tags">' + tagsH + '</div></td>'
				+ '<td><div class="nw-row-actions"><button type="button" class="nw-action-btn nw-edit-btn" data-id="' + safeId + '">Edit</button></div></td>'
				+ '</tr>';
		}).join(''));

		bindImageFallbacks();
	}

	function applyFilters() {
		var q = String($search.val() || '').toLowerCase().trim();
		var type = String($filterType.val() || '').trim();
		var active = String($filterActive.val() || '').trim();

		var shown = all.filter(function (a) {
			var tagMatch = (Array.isArray(a.tags) ? a.tags : []).some(function (t) {
				return String(t).toLowerCase().indexOf(q) !== -1;
			});

			var matchesSearch = !q
				|| String(a.name || '').toLowerCase().indexOf(q) !== -1
				|| String(a.description || '').toLowerCase().indexOf(q) !== -1
				|| String(a.ability_type || '').toLowerCase().indexOf(q) !== -1
				|| String(a.target_type || '').toLowerCase().indexOf(q) !== -1
				|| tagMatch;

			var matchesType = !type || a.ability_type === type;
			var matchesActive = !active || String(a.is_active ? '1' : '0') === active;

			return matchesSearch && matchesType && matchesActive;
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

		if ($tbody.length) {
			$tbody.html('<tr class="nw-loading-row"><td colspan="10"><div class="nw-spinner"></div> Loading abilities…</td></tr>');
		}

		activeXhr = $.post(ajaxEndpoint, {
			action: 'nw_abilities_get_all',
			nonce: nonce
		}, function (res) {
			if (!res || !res.success) {
				notice('Error: ' + ((res && res.data) || 'Unknown error'), 'error');
				return;
			}

			var rows = Array.isArray(res.data)
				? res.data
				: (res.data && typeof res.data === 'object' ? Object.values(res.data) : []);

			all = normalizeAbilities(rows);
			updateStats(all);
			applyFilters();
		}).fail(function (xhr, status) {
			if (status !== 'abort') {
				notice('Request failed (' + (xhr.status || status) + ').', 'error');
			}
		}).always(function () {
			activeXhr = null;
		});
	}

	function openModal(id) {
		if ($form.length && $form[0]) {
			$form[0].reset();
		}

		$fieldId.val('');
		$fieldIsPassive.prop('checked', false);
		$fieldIsActive.prop('checked', true);

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
			$fieldAbilityType.val(a.ability_type || 'active');
			$fieldTargetType.val(a.target_type || 'self');
			$fieldCostType.val(a.cost_type || 'none');
			$fieldCostValue.val(a.cost_value != null ? a.cost_value : 0);
			$fieldRangeTiles.val(a.range_tiles != null ? a.range_tiles : 1);
			$fieldDurationTurns.val(a.duration_turns != null ? a.duration_turns : 0);
			$fieldTags.val(tagsStr(a.tags));
			$fieldImgUrl.val(a.img_url || '');
			$fieldSource.val(a.source || '');
			$fieldGmNotes.val(a.gm_notes || '');
			$fieldIsPassive.prop('checked', !!a.is_passive);
			$fieldIsActive.prop('checked', !!a.is_active);

			$('#nw-modal-title').text('Edit Ability');
		} else {
			$fieldCostValue.val(0);
			$fieldRangeTiles.val(1);
			$fieldDurationTurns.val(0);
			$('#nw-modal-title').text('New Ability');
		}

		$modalOverlay.fadeIn(150);
	}

	$('#nw-modal-close, .nw-modal-cancel').on('click', function () {
		$modalOverlay.fadeOut(150);
	});

	$modalOverlay.on('click', function (e) {
		if ($(e.target).is('#nw-ability-modal') || $(e.target).is('.nw-modal-backdrop')) {
			$modalOverlay.fadeOut(150);
		}
	});

	$(document).on('click', '.nw-edit-btn', function () {
		openModal($(this).data('id'));
	});

	$('#nw-add-ability').on('click', function () {
		openModal(null);
	});

	$search.on('input', debounce(applyFilters, 150));
	$filterType.on('change', applyFilters);
	$filterActive.on('change', applyFilters);

	loadAll();
});
