/**
 * NeoWeaver Admin — Scenarios (cyber_scenarios)
 * Handles list rendering, modal CRUD, tabs, toggle, delete.
 * Depends on: jQuery
 * Config injected via wp_localize_script as NWScenarios { ajaxurl, nonce }
 */
/* global NWScenarios, jQuery */
(function ($) {
	'use strict';

	var ajaxurl = NWScenarios.ajaxurl;
	var nonce   = NWScenarios.nonce;
	var all     = [];

	// ── helpers ──────────────────────────────────────────────────────────
	function esc(s) {
		return $('<span>').text(s || '').html();
	}

	function notice(msg, type) {
		var el = $('#nw-notice');
		el.attr('class', 'nw-notice nw-notice-' + type).html(esc(msg)).show();
		setTimeout(function () { el.fadeOut(300); }, 3500);
	}

	var DIFF_COLOR = {
		trivial: '#6daa45', easy: '#4f98a3', medium: '#d19900',
		hard: '#bb653b', deadly: '#a12c7b',
	};

	function diffBadge(d) {
		var c = DIFF_COLOR[d] || '#555';
		return '<span style="font-size:10px;padding:2px 8px;border-radius:3px;background:' + c + '20;color:' + c + ';border:1px solid ' + c + '40;text-transform:uppercase;letter-spacing:.5px">' + esc(d || '—') + '</span>';
	}

	function tagsHtml(tags) {
		if (!tags || !tags.length) return '<span style="color:#555">—</span>';
		var arr = Array.isArray(tags) ? tags : (tags + '').split(',').map(function (t) { return t.trim(); });
		return arr.slice(0, 4).map(function (t) {
			return '<span style="font-size:10px;padding:2px 7px;background:#1e1e1e;border:1px solid #2e2e2e;border-radius:3px;color:#888">' + esc(t) + '</span>';
		}).join(' ') + (arr.length > 4 ? ' <span style="font-size:10px;color:#555">+' + (arr.length - 4) + '</span>' : '');
	}

	function jsonPretty(v) {
		if (!v || (Array.isArray(v) && !v.length)) return '';
		if (typeof v === 'string') return v;
		return JSON.stringify(v, null, 2);
	}

	function objToLines(v) {
		if (!v) return '';
		if (Array.isArray(v)) return v.join('\n');
		if (typeof v === 'string') return v;
		return JSON.stringify(v, null, 2);
	}

	// ── render table ───────────────────────────────────────────────────
	function renderTable(data) {
		var tbody = $('#nw-scenarios-tbody');
		var search = $('#nw-search').val().toLowerCase().trim();
		var diff   = $('#nw-filter-difficulty').val();

		var rows = data.filter(function (r) {
			if (diff   && r.difficulty !== diff) return false;
			if (search && (r.title || '').toLowerCase().indexOf(search) < 0) return false;
			return true;
		});

		if (!rows.length) {
			tbody.html('<tr><td colspan="7" style="text-align:center;padding:32px;color:#555">No scenarios found.</td></tr>');
			return;
		}

		tbody.html(rows.map(function (r) {
			var active = r.is_active !== false;
			var dur    = r.estimated_duration_minutes ? r.estimated_duration_minutes + ' min' : '—';
			return '<tr data-id="' + r.id + '"' + (active ? '' : ' style="opacity:.45"') + '>'
				+ '<td><strong style="color:#fff">' + esc(r.title) + '</strong><div style="font-size:11px;color:#555">' + esc(r.setting || '') + '</div></td>'
				+ '<td>' + diffBadge(r.difficulty) + '</td>'
				+ '<td style="font-size:12px;color:#aaa">' + esc(r.setting || '—') + '</td>'
				+ '<td style="font-size:12px;color:#aaa;white-space:nowrap">' + esc(dur) + '</td>'
				+ '<td><div style="display:flex;flex-wrap:wrap;gap:4px">' + tagsHtml(r.tags) + '</div></td>'
				+ '<td><label class="nw-toggle"><input type="checkbox" class="nw-active-toggle" data-id="' + r.id + '" ' + (active ? 'checked' : '') + '><span class="nw-toggle-slider"></span></label></td>'
				+ '<td><div style="display:flex;gap:6px">'
				+ '<button class="nw-action-btn nw-action-btn--small nw-edit-btn" data-id="' + r.id + '">Edit</button>'
				+ '</div></td>'
				+ '</tr>';
		}).join(''));
	}

	// ── load all ────────────────────────────────────────────────────────
	function loadAll() {
		$('#nw-scenarios-tbody').html('<tr><td colspan="7" style="text-align:center;padding:32px;color:#555"><span class="nw-spinner"></span> Loading…</td></tr>');
		$.post(ajaxurl, {
			action:            'nw_scenarios_get_all',
			nonce:             nonce,
			filter_difficulty: $('#nw-filter-difficulty').val(),
		}, function (res) {
			if (!res.success) { notice('Error: ' + res.data, 'error'); return; }
			all = res.data || [];
			renderTable(all);
		}).fail(function () { notice('Request failed.', 'error'); });
	}

	// ── tabs ─────────────────────────────────────────────────────────────
	$(document).on('click', '.nw-tab', function () {
		var tab = $(this).data('tab');
		$('.nw-tab').removeClass('active');
		$(this).addClass('active');
		$('.nw-tab-panel').hide();
		$('#nw-tab-' + tab).show();
	});

	// ── modal open ─────────────────────────────────────────────────────
	function resetModal() {
		$('#nw-scenario-form')[0].reset();
		$('#nw-field-id').val('');
		$('#nw-field-active').prop('checked', true);
		$('#nw-field-duration').val(60);
		$('#nw-field-sort').val(0);
		$('#nw-field-difficulty').val('medium');
		$('.nw-tab').first().trigger('click');
	}

	function populateModal(r) {
		$('#nw-field-id').val(r.id);
		$('#nw-field-title').val(r.title || '');
		$('#nw-field-setting').val(r.setting || '');
		$('#nw-field-desc').val(r.description || '');
		$('#nw-field-difficulty').val(r.difficulty || 'medium');
		$('#nw-field-duration').val(r.estimated_duration_minutes || 60);
		$('#nw-field-sort').val(r.sort_order || 0);
		$('#nw-field-image').val(r.image_url || '');
		$('#nw-field-active').prop('checked', r.is_active !== false);

		var tagsVal = Array.isArray(r.tags) ? r.tags.join(', ') : (r.tags || '');
		$('#nw-field-tags').val(tagsVal);
		$('#nw-field-objectives').val(objToLines(r.objectives));
		$('#nw-field-rewards').val(jsonPretty(r.rewards));
		$('#nw-field-prerequisites').val(jsonPretty(r.prerequisites));
	}

	function openModal(id) {
		resetModal();
		if (id) {
			$('#nw-modal-title').text('Edit Scenario');
			$('#nw-delete-btn').show();
			$.post(ajaxurl, { action: 'nw_scenarios_get_one', nonce: nonce, scenario_id: id }, function (res) {
				if (!res.success) { notice('Load error: ' + res.data, 'error'); return; }
				populateModal(res.data);
			});
		} else {
			$('#nw-modal-title').text('New Scenario');
			$('#nw-delete-btn').hide();
		}
		$('#nw-modal-overlay').fadeIn(150);
	}

	function closeModal() { $('#nw-modal-overlay').fadeOut(150); }

	// ── events ───────────────────────────────────────────────────────────
	$(document).on('click', '#nw-add-btn',     function () { openModal(null); });
	$(document).on('click', '#nw-refresh-btn', loadAll);
	$(document).on('click', '.nw-edit-btn',    function () { openModal($(this).data('id')); });
	$(document).on('click', '#nw-modal-close, #nw-cancel-btn', closeModal);
	$(document).on('click', '#nw-modal-overlay', function (e) {
		if ($(e.target).is('#nw-modal-overlay')) closeModal();
	});

	// filter + search
	$(document).on('change', '#nw-filter-difficulty', function () { renderTable(all); });
	$(document).on('input',  '#nw-search',            function () { renderTable(all); });

	// toggle active
	$(document).on('change', '.nw-active-toggle', function () {
		var $cb = $(this), id = $cb.data('id'), val = $cb.is(':checked');
		$.post(ajaxurl, {
			action:      'nw_scenarios_toggle',
			nonce:       nonce,
			scenario_id: id,
			is_active:   val ? 1 : 0,
		}, function (res) {
			if (res.success) {
				all = all.map(function (r) { if (r.id === id) r.is_active = val; return r; });
				renderTable(all);
				notice((val ? 'Activated' : 'Deactivated') + '.', 'success');
			} else {
				notice('Toggle failed: ' + res.data, 'error');
				$cb.prop('checked', !val);
			}
		});
	});

	// save
	$(document).on('click', '#nw-save-btn', function () {
		if (!$('#nw-field-title').val().trim()) { notice('Title is required.', 'error'); return; }
		var btn = $(this).prop('disabled', true).text('Saving…');
		var fd  = $('#nw-scenario-form').serializeArray();
		var data = { action: 'nw_scenarios_save', nonce: nonce };
		fd.forEach(function (f) { data[f.name] = f.value; });
		// checkbox is_active — serializeArray skips unchecked
		data.is_active = $('#nw-field-active').is(':checked') ? 1 : 0;
		$.post(ajaxurl, data, function (res) {
			btn.prop('disabled', false).text('Save');
			if (res.success) {
				notice('Scenario saved!', 'success');
				closeModal();
				loadAll();
			} else {
				notice('Error: ' + (res.data || 'Unknown'), 'error');
			}
		}).fail(function () {
			btn.prop('disabled', false).text('Save');
			notice('Request failed.', 'error');
		});
	});

	// delete
	$(document).on('click', '#nw-delete-btn', function () {
		var id = $('#nw-field-id').val();
		if (!id || !window.confirm('Delete this scenario? This cannot be undone.')) return;
		$.post(ajaxurl, { action: 'nw_scenarios_delete', nonce: nonce, scenario_id: id }, function (res) {
			if (res.success) {
				notice('Scenario deleted.', 'success');
				closeModal();
				loadAll();
			} else {
				notice('Delete failed: ' + res.data, 'error');
			}
		});
	});

	// ── init ─────────────────────────────────────────────────────────────
	$(function () { loadAll(); });

}(jQuery));
