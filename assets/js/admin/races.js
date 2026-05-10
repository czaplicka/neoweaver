/**
 * NeoWeaver Admin — Races (cyber_races)
 * Handles list rendering, modal CRUD, toggle active.
 * Depends on: jQuery, ajaxurl (WP global)
 * Nonce: read from #nw-nonce hidden field.
 */
/* global ajaxurl, jQuery */
(function ($) {
	'use strict';

	// ── state ────────────────────────────────────────────────────────────
	var nonce = $('#nw-nonce').val();
	var all   = [];

	var SLIDER_KEYS = [
		'race_base_hp', 'race_base_mp',
		'preferred_tech', 'preferred_magic', 'preferred_gods',
		'preferred_wealth', 'preferred_threat', 'preferred_moral', 'preferred_social',
	];
	var SLIDER_DEFS = {
		race_base_hp: 8, race_base_mp: 8,
		preferred_tech: 3, preferred_magic: 3, preferred_gods: 3,
		preferred_wealth: 3, preferred_threat: 2, preferred_moral: 3, preferred_social: 2,
	};

	// ── helpers ──────────────────────────────────────────────────────────
	function esc(s) {
		return $('<span>').text(s || '').html();
	}

	function notice(msg, type) {
		var el = $('#nw-notice');
		el.attr('class', 'nw-notice nw-notice-' + type).text(msg).show();
		setTimeout(function () { el.fadeOut(300); }, 3500);
	}

	function tagsStr(t) {
		if (!t) return '';
		if (Array.isArray(t)) return t.join(', ');
		try {
			var a = JSON.parse(t);
			return Array.isArray(a) ? a.join(', ') : t;
		} catch (e) { return t; }
	}

	function updateStats(data) {
		var active = data.filter(function (r) { return r.is_active !== false; }).length;
		$('#nw-total').text(data.length);
		$('#nw-active').text(active);
		$('#nw-inactive').text(data.length - active);
	}

	// ── render table ─────────────────────────────────────────────────────
	function renderTable(data) {
		var tbody = $('#nw-races-tbody');
		if (!data.length) {
			tbody.html('<tr><td colspan="7" style="text-align:center;padding:32px;color:#555;">No races found.</td></tr>');
			return;
		}
		tbody.html(data.map(function (r) {
			var tags   = Array.isArray(r.tags) ? r.tags : [];
			var tagsH  = tags.slice(0, 4).map(function (t) {
				return '<span class="nw-tag">' + esc(t) + '</span>';
			}).join('') + (tags.length > 4 ? '<span class="nw-tag">+' + (tags.length - 4) + '</span>' : '');
			var active = r.is_active !== false;
			var imgH   = r.img_url
				? '<img src="' + esc(r.img_url) + '" class="nw-race-img" loading="lazy" onerror="this.style.display=\'none\'">'
				: '<div class="nw-race-img-placeholder">🧬</div>';
			return '<tr data-id="' + r.id + '" class="' + (active ? '' : 'nw-row-inactive') + '">'
				+ '<td>' + imgH + '</td>'
				+ '<td><div class="nw-race-name">' + esc(r.name) + '</div><div class="nw-race-sub">' + esc(r.parent_race || '') + '</div></td>'
				+ '<td>' + esc(r.conflict_axis || '—') + '</td>'
				+ '<td><div class="nw-tags">' + tagsH + '</div></td>'
				+ '<td class="nw-hp-mp"><span class="nw-hp">HP ' + (r.race_base_hp || '?') + '</span> / <span class="nw-mp">MP ' + (r.race_base_mp || '?') + '</span></td>'
				+ '<td><label class="nw-toggle"><input type="checkbox" class="nw-active-toggle" data-id="' + r.id + '" ' + (active ? 'checked' : '') + '><span class="nw-toggle-slider"></span></label></td>'
				+ '<td><div class="nw-row-actions"><button class="nw-action-btn nw-edit-btn" data-id="' + r.id + '">Edit</button></div></td>'
				+ '</tr>';
		}).join(''));
	}

	// ── load all ─────────────────────────────────────────────────────────
	function loadAll() {
		$('#nw-races-tbody').html('<tr class="nw-loading-row"><td colspan="7"><div class="nw-spinner"></div> Loading…</td></tr>');
		$.post(ajaxurl, { action: 'nw_races_get_all', nonce: nonce }, function (res) {
			if (!res.success) { notice('Error: ' + res.data, 'error'); return; }
			all = res.data || [];
			renderTable(all);
			updateStats(all);
		}).fail(function () { notice('Request failed.', 'error'); });
	}

	// ── modal ────────────────────────────────────────────────────────────
	function openModal(id) {
		$('#nw-race-form')[0].reset();
		$('#nw-field-id').val('');
		SLIDER_KEYS.forEach(function (k) {
			var d = SLIDER_DEFS[k];
			$('#nw-field-' + k).val(d);
			$('#nw-val-' + k).text(d);
		});

		if (id) {
			var r = all.find(function (x) { return x.id === id; });
			if (r) {
				$('#nw-field-id').val(r.id);
				$('#nw-field-name').val(r.name || '');
				$('#nw-field-parent_race').val(r.parent_race || '');
				$('#nw-field-description').val(r.description || '');
				$('#nw-field-gm_instructions').val(r.gm_instructions || '');
				$('#nw-field-img_url').val(r.img_url || '');
				$('#nw-field-bonus').val(r.bonus || '');
				$('#nw-field-conflict_axis').val(r.conflict_axis || '');
				$('#nw-field-conflict_side').val(r.conflict_side || '');
				$('#nw-field-tags').val(tagsStr(r.tags));
				$('#nw-field-is_active').prop('checked', r.is_active !== false);
				SLIDER_KEYS.forEach(function (k) {
					var v = r[k] !== undefined ? r[k] : SLIDER_DEFS[k];
					$('#nw-field-' + k).val(v);
					$('#nw-val-' + k).text(v);
				});
			}
			$('#nw-modal-title').text('Edit Race');
			$('#nw-save-label').text('Save Changes');
		} else {
			$('#nw-modal-title').text('New Race');
			$('#nw-save-label').text('Create Race');
		}
		$('#nw-modal-overlay').fadeIn(150);
	}

	function closeModal() {
		$('#nw-modal-overlay').fadeOut(150);
	}

	// ── events ───────────────────────────────────────────────────────────
	$(document).on('input', '.nw-range', function () {
		$('#nw-val-' + $(this).attr('id').replace('nw-field-', '')).text($(this).val());
	});

	$(document).on('click', '#nw-modal-close, #nw-cancel-btn', closeModal);

	$(document).on('click', '#nw-modal-overlay', function (e) {
		if ($(e.target).is('#nw-modal-overlay')) closeModal();
	});

	$(document).on('click', '.nw-edit-btn', function () {
		openModal($(this).data('id'));
	});

	$(document).on('click', '#nw-add-btn',     function () { openModal(null); });
	$(document).on('click', '#nw-refresh-btn', loadAll);

	// toggle active
	$(document).on('change', '.nw-active-toggle', function () {
		var $cb  = $(this);
		var id   = $cb.data('id');
		var val  = $cb.is(':checked');
		var row  = $cb.closest('tr');
		$.post(ajaxurl, {
			action:    'nw_races_toggle',
			nonce:     nonce,
			race_id:   id,
			is_active: val ? 1 : 0,
		}, function (res) {
			if (res.success) {
				row.toggleClass('nw-row-inactive', !val);
				all = all.map(function (r) { if (r.id === id) r.is_active = val; return r; });
				updateStats(all);
				notice((val ? 'Activated' : 'Deactivated') + '.', 'success');
			} else {
				notice('Toggle failed: ' + res.data, 'error');
				$cb.prop('checked', !val);
			}
		});
	});

	// save
	$(document).on('click', '#nw-save-btn', function () {
		if (!$('#nw-field-name').val().trim()) { notice('Name is required.', 'error'); return; }
		var btn = $(this);
		btn.prop('disabled', true);
		$('#nw-save-label').text('Saving…');
		var fd = { action: 'nw_races_save', nonce: nonce, race: {} };
		$('#nw-race-form').serializeArray().forEach(function (f) {
			if (f.name !== 'is_active') fd.race[f.name] = f.value;
		});
		fd.race.is_active = $('#nw-field-is_active').is(':checked') ? 1 : 0;
		$.post(ajaxurl, fd, function (res) {
			btn.prop('disabled', false);
			$('#nw-save-label').text('Save Changes');
			if (res.success) {
				notice('Race saved!', 'success');
				closeModal();
				loadAll();
			} else {
				notice('Error: ' + (res.data || 'Unknown'), 'error');
			}
		}).fail(function () {
			btn.prop('disabled', false);
			$('#nw-save-label').text('Save Changes');
			notice('Request failed.', 'error');
		});
	});

	// ── init ─────────────────────────────────────────────────────────────
	$(function () { loadAll(); });

}(jQuery));
