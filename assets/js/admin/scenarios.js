/**
 * NeoWeaver Admin — Scenarios (cyber_scenarios)
 * Works with the rewritten scenarios PHP backend.
 */
/* global NWScenarios, jQuery, ajaxurl */
(function ($) {
	'use strict';

	var cfg = window.NWScenarios || {};
	var ajaxEndpoint = cfg.ajaxurl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
	var nonce = cfg.nonce || '';
	var all = [];
	var noticeTimer = null;

	var TYPES = ['main', 'personal', 'social', 'world'];
	var CATEGORIES = ['combat', 'social', 'magic', 'investigation', 'worlds', 'sidequest', 'family'];

	function esc(s) {
		return $('<span>').text(s == null ? '' : String(s)).html();
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

	function clampInt(v, min, max, fallback) {
		var n = parseInt(v, 10);
		if (isNaN(n)) return fallback;
		return Math.max(min, Math.min(max, n));
	}

	function nullableInt(v, min, max) {
		if (v === null || typeof v === 'undefined') return '';
		var s = String(v).trim();
		if (!s) return '';
		var n = parseInt(s, 10);
		if (isNaN(n)) return '';
		return Math.max(min, Math.min(max, n));
	}

	function clearNoticeTimer() {
		if (noticeTimer) {
			clearTimeout(noticeTimer);
			noticeTimer = null;
		}
	}

	function notice(msg, type) {
		var $el = $('#nw-notice');
		var isError = type === 'error';

		clearNoticeTimer();

		$el.stop(true, true)
			.attr('class', '')
			.css({
				display: 'block',
				background: isError ? '#5c0000' : '#1a3300',
				color: isError ? '#ff8080' : '#adff00'
			})
			.text(msg || '');

		noticeTimer = setTimeout(function () {
			$el.fadeOut(250);
			noticeTimer = null;
		}, 3200);
	}

	function arrayToCommaString(v) {
		if (!v) return '';
		if (Array.isArray(v)) return v.join(', ');
		if (typeof v === 'string') return v;
		return '';
	}

	function jsonOrLinesToTextarea(v) {
		if (!v) return '';
		if (Array.isArray(v)) return JSON.stringify(v, null, 2);
		if (typeof v === 'object') return JSON.stringify(v, null, 2);
		if (typeof v === 'string') return v;
		return '';
	}

	function rewardText(row) {
		var credits = row.reward_credits != null && row.reward_credits !== '' ? row.reward_credits : '—';
		var items = Array.isArray(row.reward_items) ? row.reward_items.length : 0;
		return '₵ ' + esc(credits) + (items ? ' + ' + esc(items) + ' item(s)' : '');
	}

	function updateStats(rows) {
		var data = rows || [];
		var active = data.filter(function (row) {
			return boolVal(row.is_active);
		}).length;

		$('#nw-total').text(data.length);
		$('#nw-active-count').text(active);
	}

	function getFilteredRows() {
		var term = ($('#nw-search').val() || '').toLowerCase().trim();
		var type = ($('#nw-filter-type').val() || '').trim();
		var category = ($('#nw-filter-category').val() || '').trim();
		var difficulty = ($('#nw-filter-difficulty').val() || '').trim();

		return all.filter(function (row) {
			if (type && row.type !== type) return false;
			if (category && row.category !== category) return false;
			if (difficulty && String(row.difficulty) !== String(difficulty)) return false;

			if (!term) return true;

			var haystack = [
				row.name || '',
				row.type || '',
				row.category || '',
				row.goal || '',
				row.gm_instruction || '',
				row.victory_condition || '',
				row.fail_conditions || '',
				arrayToCommaString(row.tags),
				arrayToCommaString(row.required_tags),
				arrayToCommaString(row.success_tags),
				arrayToCommaString(row.failure_tags),
				row.area_id || ''
			].join(' ').toLowerCase();

			return haystack.indexOf(term) !== -1;
		});
	}

	function rerenderCurrentView() {
		var filtered = getFilteredRows();
		renderTable(filtered);
		updateStats(filtered);
	}

	function renderTable(rows) {
		var data = rows || [];
		var $tbody = $('#nw-scenarios-tbody');

		if (!data.length) {
			$tbody.html('<tr><td colspan="7" style="text-align:center;padding:32px;color:#777;">No scenarios found.</td></tr>');
			return;
		}

		var html = data.map(function (row) {
			var active = boolVal(row.is_active);

			return ''
				+ '<tr data-id="' + esc(row.id) + '"' + (active ? '' : ' class="nw-row-inactive"') + '>'
				+ '<td>'
				+ '<div><strong>' + esc(row.name) + '</strong></div>'
				+ '<div style="color:#888;font-size:12px;">ID: ' + esc(row.id) + '</div>'
				+ '</td>'
				+ '<td>' + esc(row.type || '—') + '</td>'
				+ '<td>' + esc(row.category || '—') + '</td>'
				+ '<td>' + esc(row.difficulty || '—') + '</td>'
				+ '<td>' + rewardText(row) + '</td>'
				+ '<td><label class="nw-toggle"><input type="checkbox" class="nw-active-toggle" data-id="' + esc(row.id) + '"' + (active ? ' checked' : '') + '><span class="nw-toggle-slider"></span></label></td>'
				+ '<td><button type="button" class="button button-small nw-edit-btn" data-id="' + esc(row.id) + '">Edit</button></td>'
				+ '</tr>';
		}).join('');

		$tbody.html(html);
	}

	function resetForm() {
		if ($('#nw-scenario-form').length && $('#nw-scenario-form')[0]) {
			$('#nw-scenario-form')[0].reset();
		}

		$('#nw-field-id').val('');
		$('#nw-field-name').val('');
		$('#nw-field-type').val('main');
		$('#nw-field-category').val('combat');
		$('#nw-field-difficulty').val(3);
		$('#nw-field-goal').val('');
		$('#nw-field-gm_instruction').val('');
		$('#nw-field-victory_condition').val('');
		$('#nw-field-fail_conditions').val('');
		$('#nw-field-tags').val('');
		$('#nw-field-required_tags').val('');
		$('#nw-field-success_tags').val('');
		$('#nw-field-failure_tags').val('');
		$('#nw-field-reward_credits').val(100);
		$('#nw-field-reward_items').val('');
		$('#nw-field-min_entropy').val('');
		$('#nw-field-max_entropy').val('');
		$('#nw-field-kingdom_tech').val('');
		$('#nw-field-kingdom_magic').val('');
		$('#nw-field-kingdom_wealth').val('');
		$('#nw-field-area_id').val('');
		$('#nw-field-required_archetype_id').val('');
		$('#nw-field-giver_npc_tag').val('');
		$('#nw-field-img_url').val('');

		$('#nw-field-is_boss').prop('checked', false);
		$('#nw-field-is_key_arc').prop('checked', false);
		$('#nw-field-is_repeatable').prop('checked', false);
		$('#nw-field-is_active').prop('checked', true);

		$('#nw-delete-btn').hide();
		$('#nw-modal-title').text('New Scenario');
		$('#nw-save-btn').text('Save Scenario').prop('disabled', false);
	}

	function populateForm(row) {
		resetForm();

		if (!row) return;

		$('#nw-field-id').val(row.id || '');
		$('#nw-field-name').val(row.name || '');
		$('#nw-field-type').val(TYPES.indexOf(row.type) >= 0 ? row.type : 'main');
		$('#nw-field-category').val(CATEGORIES.indexOf(row.category) >= 0 ? row.category : 'combat');
		$('#nw-field-difficulty').val(clampInt(row.difficulty, 1, 5, 3));
		$('#nw-field-goal').val(row.goal || '');
		$('#nw-field-gm_instruction').val(row.gm_instruction || '');
		$('#nw-field-victory_condition').val(row.victory_condition || '');
		$('#nw-field-fail_conditions').val(row.fail_conditions || '');
		$('#nw-field-tags').val(arrayToCommaString(row.tags));
		$('#nw-field-required_tags').val(jsonOrLinesToTextarea(row.required_tags));
		$('#nw-field-success_tags').val(jsonOrLinesToTextarea(row.success_tags));
		$('#nw-field-failure_tags').val(jsonOrLinesToTextarea(row.failure_tags));
		$('#nw-field-reward_credits').val(row.reward_credits != null ? row.reward_credits : 100);
		$('#nw-field-reward_items').val(jsonOrLinesToTextarea(row.reward_items));
		$('#nw-field-min_entropy').val(row.min_entropy != null ? row.min_entropy : '');
		$('#nw-field-max_entropy').val(row.max_entropy != null ? row.max_entropy : '');
		$('#nw-field-kingdom_tech').val(row.kingdom_tech != null ? row.kingdom_tech : '');
		$('#nw-field-kingdom_magic').val(row.kingdom_magic != null ? row.kingdom_magic : '');
		$('#nw-field-kingdom_wealth').val(row.kingdom_wealth != null ? row.kingdom_wealth : '');
		$('#nw-field-area_id').val(row.area_id || '');
		$('#nw-field-required_archetype_id').val(row.required_archetype_id != null ? row.required_archetype_id : '');
		$('#nw-field-giver_npc_tag').val(jsonOrLinesToTextarea(row.giver_npc_tag));
		$('#nw-field-img_url').val(row.img_url || '');

		$('#nw-field-is_boss').prop('checked', boolVal(row.is_boss));
		$('#nw-field-is_key_arc').prop('checked', boolVal(row.is_key_arc));
		$('#nw-field-is_repeatable').prop('checked', boolVal(row.is_repeatable));
		$('#nw-field-is_active').prop('checked', boolVal(row.is_active));

		$('#nw-delete-btn').show();
		$('#nw-modal-title').text('Edit Scenario');
		$('#nw-save-btn').text('Save Changes').prop('disabled', false);
	}

	function openModal(id) {
		resetForm();

		if (!id) {
			$('#nw-modal-overlay').fadeIn(150);
			$('#nw-field-name').trigger('focus');
			return;
		}

		$.post(ajaxEndpoint, {
			action: 'nw_scenarios_get_one',
			nonce: nonce,
			id: id
		}, function (res) {
			if (!res || !res.success) {
				notice('Error: ' + ((res && res.data) || 'Could not load scenario.'), 'error');
				return;
			}

			populateForm(res.data || {});
			$('#nw-modal-overlay').fadeIn(150);
			$('#nw-field-name').trigger('focus');
		}).fail(function () {
			notice('Request failed.', 'error');
		});
	}

	function closeModal() {
		$('#nw-modal-overlay').fadeOut(150);
	}

	function collectPayload() {
		var data = {
			action: 'nw_scenarios_save',
			nonce: nonce
		};

		data.id = ($('#nw-field-id').val() || '').trim();
		data.name = ($('#nw-field-name').val() || '').trim();
		data.type = ($('#nw-field-type').val() || 'main').trim();
		data.category = ($('#nw-field-category').val() || 'combat').trim();
		data.difficulty = String(clampInt($('#nw-field-difficulty').val(), 1, 5, 3));
		data.goal = ($('#nw-field-goal').val() || '').trim();
		data.gm_instruction = ($('#nw-field-gm_instruction').val() || '').trim();
		data.victory_condition = ($('#nw-field-victory_condition').val() || '').trim();
		data.fail_conditions = ($('#nw-field-fail_conditions').val() || '').trim();
		data.tags = ($('#nw-field-tags').val() || '').trim();
		data.required_tags = ($('#nw-field-required_tags').val() || '').trim();
		data.success_tags = ($('#nw-field-success_tags').val() || '').trim();
		data.failure_tags = ($('#nw-field-failure_tags').val() || '').trim();
		data.reward_credits = String(Math.max(0, parseInt($('#nw-field-reward_credits').val(), 10) || 0));
		data.reward_items = ($('#nw-field-reward_items').val() || '').trim();
		data.min_entropy = nullableInt($('#nw-field-min_entropy').val(), 0, 999);
		data.max_entropy = nullableInt($('#nw-field-max_entropy').val(), 0, 999);
		data.kingdom_tech = nullableInt($('#nw-field-kingdom_tech').val(), 0, 5);
		data.kingdom_magic = nullableInt($('#nw-field-kingdom_magic').val(), 0, 5);
		data.kingdom_wealth = nullableInt($('#nw-field-kingdom_wealth').val(), 0, 5);
		data.area_id = ($('#nw-field-area_id').val() || '').trim();
		data.required_archetype_id = ($('#nw-field-required_archetype_id').val() || '').trim();
		data.giver_npc_tag = ($('#nw-field-giver_npc_tag').val() || '').trim();
		data.img_url = ($('#nw-field-img_url').val() || '').trim();

		data.is_boss = $('#nw-field-is_boss').is(':checked') ? '1' : '0';
		data.is_key_arc = $('#nw-field-is_key_arc').is(':checked') ? '1' : '0';
		data.is_repeatable = $('#nw-field-is_repeatable').is(':checked') ? '1' : '0';
		data.is_active = $('#nw-field-is_active').is(':checked') ? '1' : '0';

		return data;
	}

	function loadAll() {
		$('#nw-scenarios-tbody').html('<tr><td colspan="7" style="text-align:center;padding:32px;color:#777;">Loading…</td></tr>');

		$.post(ajaxEndpoint, {
			action: 'nw_scenarios_get_all',
			nonce: nonce,
			filter_type: ($('#nw-filter-type').val() || '').trim(),
			filter_category: ($('#nw-filter-category').val() || '').trim(),
			filter_difficulty: ($('#nw-filter-difficulty').val() || '').trim()
		}, function (res) {
			if (!res || !res.success) {
				notice('Error: ' + ((res && res.data) || 'Load failed.'), 'error');
				return;
			}

			all = Array.isArray(res.data) ? res.data : [];
			rerenderCurrentView();
		}).fail(function () {
			notice('Request failed.', 'error');
		});
	}

	var debouncedSearchRender = debounce(function () {
		rerenderCurrentView();
	}, 150);

	$(document).on('input', '#nw-search', debouncedSearchRender);

	$(document).on('change', '#nw-filter-type, #nw-filter-category, #nw-filter-difficulty', function () {
		loadAll();
	});

	$(document).on('click', '#nw-add-btn', function () {
		openModal(null);
	});

	$(document).on('click', '#nw-refresh-btn', function () {
		loadAll();
	});

	$(document).on('click', '.nw-edit-btn', function () {
		openModal($(this).data('id'));
	});

	$(document).on('click', '#nw-modal-close, #nw-cancel-btn', function () {
		closeModal();
	});

	$(document).on('click', '#nw-modal-overlay', function (e) {
		if ($(e.target).is('#nw-modal-overlay')) {
			closeModal();
		}
	});

	$(document).on('change', '.nw-active-toggle', function () {
		var $cb = $(this);
		var id = $cb.data('id');
		var val = $cb.is(':checked');

		$.post(ajaxEndpoint, {
			action: 'nw_scenarios_toggle',
			nonce: nonce,
			id: id,
			is_active: val ? '1' : '0'
		}, function (res) {
			if (!res || !res.success) {
				notice('Toggle failed: ' + ((res && res.data) || 'Unknown error'), 'error');
				$cb.prop('checked', !val);
				return;
			}

			all = all.map(function (scenario) {
				if (String(scenario.id) === String(id)) {
					return $.extend({}, scenario, {
						is_active: val
					});
				}
				return scenario;
			});

			rerenderCurrentView();
			notice(val ? 'Activated.' : 'Deactivated.', 'success');
		}).fail(function () {
			$cb.prop('checked', !val);
			notice('Request failed.', 'error');
		});
	});

	$(document).on('click', '#nw-save-btn', function () {
		var payload = collectPayload();
		var $btn = $(this);

		if (!payload.name) {
			notice('Name is required.', 'error');
			return;
		}

		if (TYPES.indexOf(payload.type) < 0) {
			notice('Invalid type.', 'error');
			return;
		}

		if (CATEGORIES.indexOf(payload.category) < 0) {
			notice('Invalid category.', 'error');
			return;
		}

		if (
			payload.min_entropy !== '' &&
			payload.max_entropy !== '' &&
			parseInt(payload.min_entropy, 10) > parseInt(payload.max_entropy, 10)
		) {
			notice('Min entropy cannot be greater than max entropy.', 'error');
			return;
		}

		$btn.prop('disabled', true).text('Saving…');

		$.post(ajaxEndpoint, payload, function (res) {
			$btn.prop('disabled', false).text(payload.id ? 'Save Changes' : 'Save Scenario');

			if (!res || !res.success) {
				notice('Error: ' + ((res && res.data) || 'Unknown error'), 'error');
				return;
			}

			notice('Scenario saved!', 'success');
			closeModal();
			loadAll();
		}).fail(function () {
			$btn.prop('disabled', false).text(payload.id ? 'Save Changes' : 'Save Scenario');
			notice('Request failed.', 'error');
		});
	});

	$(document).on('click', '#nw-delete-btn', function () {
		var id = ($('#nw-field-id').val() || '').trim();

		if (!id) return;

		if (!window.confirm('Delete this scenario? This cannot be undone.')) {
			return;
		}

		$.post(ajaxEndpoint, {
			action: 'nw_scenarios_delete',
			nonce: nonce,
			id: id
		}, function (res) {
			if (!res || !res.success) {
				notice('Delete failed: ' + ((res && res.data) || 'Unknown error'), 'error');
				return;
			}

			notice('Deleted.', 'success');
			closeModal();
			loadAll();
		}).fail(function () {
			notice('Request failed.', 'error');
		});
	});

	if (!ajaxEndpoint || !nonce) {
		notice('Missing AJAX config.', 'error');
		return;
	}

	$('#nw-field-difficulty').attr({ min: 1, max: 5 });

	loadAll();

})(jQuery);
