jQuery(function ($) {
	'use strict';

	var cfg = window.NWAbilities || {};
	var ajaxurl = cfg.ajaxurl || (typeof window.ajaxurl !== 'undefined' ? window.ajaxurl : '');
	var nonce = cfg.nonce || '';

	var $list = $('#nw-abilities-list');
	var $filterType = $('#nw-filter-type');
	var $filterActive = $('#nw-filter-active');
	var $search = $('#nw-search');

	var $modal = $('#nw-ability-modal');
	var $form = $('#nw-ability-form');
	var $modalTitle = $('#nw-modal-title');

	var $id = $('#ability-id');
	var $name = $('#ability-name');
	var $description = $('#ability-description');
	var $abilityType = $('#ability-type');
	var $targetType = $('#ability-target');
	var $costType = $('#ability-cost-type');
	var $costValue = $('#ability-cost-value');
	var $rangeTiles = $('#ability-range');
	var $durationTurns = $('#ability-duration');
	var $tags = $('#ability-tags');
	var $imgUrl = $('#ability-img');
	var $source = $('#ability-source');
	var $gmNotes = $('#ability-gm-notes');
	var $isPassive = $('#ability-is-passive');
	var $isActive = $('#ability-is-active');

	var allAbilities = [];

	function esc(value) {
		return $('<div>').text(value == null ? '' : String(value)).html();
	}

	function normalizeTags(raw) {
		if (!raw) return [];
		if (Array.isArray(raw)) return raw;
		if (typeof raw === 'string') {
			return raw.split(',').map(function (t) {
				return t.trim();
			}).filter(Boolean);
		}
		return [];
	}

	function normalizeAbility(item) {
		item = item || {};
		return {
			id: item.id || '',
			name: item.name || '',
			description: item.description || '',
			ability_type: item.ability_type || 'active',
			cost_type: item.cost_type || 'none',
			cost_value: parseInt(item.cost_value || 0, 10) || 0,
			target_type: item.target_type || 'self',
			range_tiles: parseInt(item.range_tiles || 0, 10) || 0,
			duration_turns: parseInt(item.duration_turns || 0, 10) || 0,
			is_passive: item.is_passive === true || item.is_passive === 1 || item.is_passive === '1',
			is_active: item.is_active === true || item.is_active === 1 || item.is_active === '1',
			tags: normalizeTags(item.tags),
			img_url: item.img_url || '',
			source: item.source || '',
			gm_notes: item.gm_notes || ''
		};
	}

	function openModal(title) {
		$modalTitle.text(title || 'Ability');
		$modal.show();
	}

	function closeModal() {
		$modal.hide();
	}

	function resetForm() {
		$form[0].reset();
		$id.val('');
		$isActive.prop('checked', true);
		$isPassive.prop('checked', false);
	}

	function fillForm(item) {
		$id.val(item.id);
		$name.val(item.name);
		$description.val(item.description);
		$abilityType.val(item.ability_type);
		$targetType.val(item.target_type);
		$costType.val(item.cost_type);
		$costValue.val(item.cost_value);
		$rangeTiles.val(item.range_tiles);
		$durationTurns.val(item.duration_turns);
		$tags.val((item.tags || []).join(', '));
		$imgUrl.val(item.img_url);
		$source.val(item.source);
		$gmNotes.val(item.gm_notes);
		$isPassive.prop('checked', !!item.is_passive);
		$isActive.prop('checked', !!item.is_active);
	}

	function renderList(items) {
		if (!$list.length) {
			return;
		}

		if (!items.length) {
			$list.html('<div class="nw-empty">No abilities found.</div>');
			return;
		}

		var html = items.map(function (a) {
			var tagsHtml = (a.tags || []).map(function (tag) {
				return '<span class="nw-tag">' + esc(tag) + '</span>';
			}).join(' ');

			var thumb = a.img_url
				? '<div class="nw-item-thumb"><img src="' + esc(a.img_url) + '" alt=""></div>'
				: '';

			return ''
				+ '<article class="nw-item-card" data-id="' + esc(a.id) + '">'
				+   thumb
				+   '<div class="nw-item-body">'
				+     '<div class="nw-item-top">'
				+       '<h3 class="nw-item-title">' + esc(a.name || 'Untitled') + '</h3>'
				+       '<span class="nw-item-type">' + esc(a.ability_type) + '</span>'
				+     '</div>'
				+     '<div class="nw-item-meta">'
				+       '<span>Target: ' + esc(a.target_type) + '</span>'
				+       '<span>Cost: ' + esc(a.cost_type) + (a.cost_value ? ' ' + esc(a.cost_value) : '') + '</span>'
				+       '<span>Range: ' + esc(a.range_tiles) + '</span>'
				+     '</div>'
				+     '<p class="nw-item-desc">' + esc(a.description || '') + '</p>'
				+     '<div class="nw-item-tags">' + tagsHtml + '</div>'
				+     '<div class="nw-item-actions">'
				+       '<button type="button" class="button nw-edit-ability" data-id="' + esc(a.id) + '">Edit</button>'
				+       '<button type="button" class="button nw-toggle-ability" data-id="' + esc(a.id) + '" data-active="' + (a.is_active ? '1' : '0') + '">'
				+         (a.is_active ? 'Disable' : 'Enable')
				+       '</button>'
				+     '</div>'
				+   '</div>'
				+ '</article>';
		}).join('');

		$list.html(html);
	}

	function applyFilters() {
		var type = ($filterType.val() || '').trim();
		var active = ($filterActive.val() || '').trim();
		var q = ($search.val() || '').toLowerCase().trim();

		var filtered = allAbilities.filter(function (a) {
			if (type && a.ability_type !== type) return false;
			if (active === '1' && !a.is_active) return false;
			if (active === '0' && a.is_active) return false;

			if (q) {
				var haystack = [
					a.id,
					a.name,
					a.description,
					a.source,
					(a.tags || []).join(' ')
				].join(' ').toLowerCase();

				if (haystack.indexOf(q) === -1) return false;
			}

			return true;
		});

		renderList(filtered);
	}

	function loadAbilities() {
		if (!ajaxurl || !nonce || !$list.length) {
			return;
		}

		$list.html('<div class="nw-loading">Loading abilities...</div>');

		$.post(ajaxurl, {
			action: 'nw_abilities_get_all',
			nonce: nonce
		}).done(function (res) {
			if (!res || !res.success) {
				$list.html('<div class="nw-empty">Failed to load abilities.</div>');
				return;
			}

			allAbilities = (Array.isArray(res.data) ? res.data : []).map(normalizeAbility);
			applyFilters();
		}).fail(function () {
			$list.html('<div class="nw-empty">Request failed.</div>');
		});
	}

	function saveAbility() {
		$.post(ajaxurl, {
			action: 'nw_save_ability',
			nonce: nonce,
			id: $id.val(),
			name: $name.val(),
			description: $description.val(),
			ability_type: $abilityType.val(),
			target_type: $targetType.val(),
			cost_type: $costType.val(),
			cost_value: $costValue.val(),
			range_tiles: $rangeTiles.val(),
			duration_turns: $durationTurns.val(),
			tags: $tags.val(),
			img_url: $imgUrl.val(),
			source: $source.val(),
			gm_notes: $gmNotes.val(),
			is_passive: $isPassive.is(':checked') ? '1' : '0',
			is_active: $isActive.is(':checked') ? '1' : '0'
		}).done(function (res) {
			if (res && res.success) {
				closeModal();
				loadAbilities();
			} else {
				alert('Save failed');
			}
		}).fail(function () {
			alert('Save request failed');
		});
	}

	function toggleAbility(id, isActiveNow) {
		$.post(ajaxurl, {
			action: 'nw_abilities_toggle',
			nonce: nonce,
			ability_id: id,
			is_active: isActiveNow ? '0' : '1'
		}).done(function (res) {
			if (res && res.success) {
				loadAbilities();
			} else {
				alert('Toggle failed');
			}
		}).fail(function () {
			alert('Toggle request failed');
		});
	}

	$('#nw-add-ability').on('click', function () {
		resetForm();
		openModal('Add Ability');
	});

	$('#nw-modal-close, .nw-modal-cancel, .nw-modal-backdrop').on('click', function () {
		closeModal();
	});

	$form.on('submit', function (e) {
		e.preventDefault();
		saveAbility();
	});

	$(document).on('click', '.nw-edit-ability', function () {
		var id = $(this).data('id');
		var item = allAbilities.find(function (a) {
			return a.id === id;
		});

		if (!item) return;

		resetForm();
		fillForm(item);
		openModal('Edit Ability');
	});

	$(document).on('click', '.nw-toggle-ability', function () {
		var id = $(this).data('id');
		var isActiveNow = String($(this).data('active')) === '1';
		toggleAbility(id, isActiveNow);
	});

	$filterType.on('change', applyFilters);
	$filterActive.on('change', applyFilters);
	$search.on('input', applyFilters);

	loadAbilities();
});
