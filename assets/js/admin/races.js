/**
 * NeoWeaver Admin — Races (cyber_races)
 * Works with the updated races.php backend.
 */
/* global NWRaces, jQuery */
(function ($) {
	'use strict';

	var cfg         = window.NWRaces || {};
	var ajax        = cfg.ajaxurl || '';
	var nonce       = cfg.nonce || '';
	var uploadsBase = (cfg.uploadsBase || '').replace(/\/?$/, '/');

	var all = [];

	var PREF_KEYS = [
		'preferred_tech',
		'preferred_magic',
		'preferred_gods',
		'preferred_wealth',
		'preferred_threat',
		'preferred_moral',
		'preferred_social'
	];

	var DEFAULTS = {
		race_base_hp: 8,
		race_base_mp: 8,
		preferred_tech: 3,
		preferred_magic: 3,
		preferred_gods: 3,
		preferred_wealth: 3,
		preferred_threat: 3,
		preferred_moral: 2,
		preferred_social: 3
	};

	function esc(s) {
		return $('<span>').text(s == null ? '' : String(s)).html();
	}

	function boolVal(v) {
		return v === true || v === 1 || v === '1' || v === 'true';
	}

	function clampPref(v) {
		var n = parseInt(v, 10);
		if (isNaN(n)) return 0;
		return Math.max(0, Math.min(5, n));
	}

	function toImgUrl(filename) {
		if (!filename) return '';
		filename = String(filename).trim();

		if (filename.indexOf('http://') === 0 || filename.indexOf('https://') === 0) {
			return filename;
		}

		return uploadsBase + filename.replace(/^\//, '');
	}

	function notice(msg, type) {
		var $el = $('#nw-notice');
		var isError = type === 'error';

		$el.stop(true, true)
			.attr('class', '')
			.css({
				display: 'block',
				background: isError ? '#5c0000' : '#1a3300',
				color: isError ? '#ff8080' : '#adff00'
			})
			.text(msg || '');

		setTimeout(function () {
			$el.fadeOut(250);
		}, 3200);
	}

	function tagsToString(tags) {
		if (!tags) return '';
		if (Array.isArray(tags)) return tags.join(', ');
		if (typeof tags === 'string') return tags;
		return '';
	}

	function renderTags(tags) {
		var arr = Array.isArray(tags) ? tags : [];
		if (!arr.length) {
			return '—';
		}

		var html = arr.slice(0, 4).map(function (tag) {
			return '<span class="nw-tag">' + esc(tag) + '</span>';
		}).join('');

		if (arr.length > 4) {
			html += '<span class="nw-tag">+' + (arr.length - 4) + '</span>';
		}

		return '<div class="nw-tags">' + html + '</div>';
	}

	function renderImage(filename) {
		var url = toImgUrl(filename);

		if (!url) {
			return '<div class="nw-race-img-placeholder" style="width:64px;height:64px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:#111;border:1px solid #2b2b2b;color:#555;font-size:22px;">🧬</div>';
		}

		return '<img src="' + esc(url) + '" class="nw-race-img" loading="lazy" alt="" style="width:64px;height:64px;object-fit:contain;border-radius:8px;background:#111;border:1px solid #2b2b2b;padding:4px;">';
	}

	function updateStats(data) {
		var active = (data || []).filter(function (row) {
			return boolVal(row.is_active);
		}).length;

		$('#nw-total').text((data || []).length);
		$('#nw-active').text(active);
		$('#nw-inactive').text((data || []).length - active);
	}

	function getFilteredRows() {
		var term = ($('#nw-search').val() || '').toLowerCase().trim();

		if (!term) {
			return all;
		}

		return all.filter(function (row) {
			var haystack = [
				row.name || '',
				row.parent_race || '',
				row.description || '',
				row.conflict_axis || '',
				row.conflict_side || '',
				tagsToString(row.tags)
			].join(' ').toLowerCase();

			return haystack.indexOf(term) !== -1;
		});
	}

	function renderTable(rows) {
		var data = rows || [];
		var $tbody = $('#nw-races-tbody');

		if (!data.length) {
			$tbody.html('<tr><td colspan="6" style="text-align:center;padding:32px;color:#777;">No races found.</td></tr>');
			return;
		}

		var html = data.map(function (row) {
			var active = boolVal(row.is_active);

			return ''
				+ '<tr data-id="' + esc(row.id) + '"' + (active ? '' : ' class="nw-row-inactive"') + '>'
				+ '<td style="width:80px;">' + renderImage(row.img_url) + '</td>'
				+ '<td>'
				+ '<div class="nw-race-name"><strong>' + esc(row.name) + '</strong></div>'
				+ '<div class="nw-race-sub" style="color:#888;">' + esc(row.parent_race || '') + '</div>'
				+ '</td>'
				+ '<td>' + renderTags(row.tags) + '</td>'
				+ '<td><span class="nw-hp">HP ' + esc(row.race_base_hp || '?') + '</span> / <span class="nw-mp">MP ' + esc(row.race_base_mp || '?') + '</span></td>'
				+ '<td><label class="nw-toggle"><input type="checkbox" class="nw-active-toggle" data-id="' + esc(row.id) + '"' + (active ? ' checked' : '') + '><span class="nw-toggle-slider"></span></label></td>'
				+ '<td><button type="button" class="button button-small nw-edit-btn" data-id="' + esc(row.id) + '">Edit</button></td>'
				+ '</tr>';
		}).join('');

		$tbody.html(html);
	}

	function updateImagePreview() {
		var filename = ($('#nw-field-img_url').val() || '').trim();
		var $wrap = $('#nw-race-image-preview-wrap');
		var $img = $('#nw-race-image-preview');
		var url = toImgUrl(filename);

		if (!url) {
			$img.attr('src', '');
			$wrap.hide();
			return;
		}

		$img.attr('src', url);
		$wrap.show();
	}

	function resetForm() {
		$('#nw-race-form')[0].reset();
		$('#nw-field-id').val('');
		$('#nw-field-name').val('');
		$('#nw-field-parent_race').val('');
		$('#nw-field-description').val('');
		$('#nw-field-gm_instructions').val('');
		$('#nw-field-img_url').val('');
		$('#nw-field-tags').val('');
		$('#nw-field-conflict_axis').val('');
		$('#nw-field-conflict_side').val('');
		$('#nw-field-bonus').val('');
		$('#nw-field-race_base_hp').val(DEFAULTS.race_base_hp);
		$('#nw-field-race_base_mp').val(DEFAULTS.race_base_mp);
		$('#nw-field-is_active').prop('checked', true);

		PREF_KEYS.forEach(function (key) {
			var val = clampPref(DEFAULTS[key]);
			$('#nw-field-' + key).attr('max', 5).val(val);
			$('#nw-val-' + key).text(val);
		});

		$('#nw-race-image-preview').attr('src', '');
		$('#nw-race-image-preview-wrap').hide();

		$('#nw-delete-btn').hide();
		$('#nw-save-label').text('Save Race');
		$('#nw-modal-title').text('New Race');
	}

	function fillForm(row) {
		resetForm();

		if (!row) return;

		$('#nw-field-id').val(row.id || '');
		$('#nw-field-name').val(row.name || '');
		$('#nw-field-parent_race').val(row.parent_race || '');
		$('#nw-field-description').val(row.description || '');
		$('#nw-field-gm_instructions').val(row.gm_instructions || '');
		$('#nw-field-img_url').val(row.img_url || '');
		$('#nw-field-tags').val(tagsToString(row.tags));
		$('#nw-field-conflict_axis').val(row.conflict_axis || '');
		$('#nw-field-conflict_side').val(row.conflict_side || '');

		if (row.bonus) {
			try {
				$('#nw-field-bonus').val(JSON.stringify(row.bonus, null, 2));
			} catch (e) {
				$('#nw-field-bonus').val('');
			}
		}

		$('#nw-field-race_base_hp').val(row.race_base_hp || DEFAULTS.race_base_hp);
		$('#nw-field-race_base_mp').val(row.race_base_mp || DEFAULTS.race_base_mp);
		$('#nw-field-is_active').prop('checked', boolVal(row.is_active));

		PREF_KEYS.forEach(function (key) {
			var val = (typeof row[key] !== 'undefined' && row[key] !== null) ? row[key] : DEFAULTS[key];
			val = clampPref(val);
			$('#nw-field-' + key).attr('max', 5).val(val);
			$('#nw-val-' + key).text(val);
		});

		$('#nw-delete-btn').show();
		$('#nw-save-label').text('Save Changes');
		$('#nw-modal-title').text('Edit Race');

		updateImagePreview();
	}

	function openModal(id) {
		resetForm();

		if (!id) {
			$('#nw-modal-overlay').fadeIn(150);
			$('#nw-field-name').trigger('focus');
			return;
		}

		$.post(ajax, {
			action: 'nw_races_get_one',
			nonce: nonce,
			id: id
		}, function (res) {
			if (!res || !res.success) {
				notice('Error: ' + ((res && res.data) || 'Could not load race.'), 'error');
				return;
			}

			fillForm(res.data || {});
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
		return {
			action: 'nw_races_save',
			nonce: nonce,
			id: ($('#nw-field-id').val() || '').trim(),
			name: ($('#nw-field-name').val() || '').trim(),
			parent_race: ($('#nw-field-parent_race').val() || '').trim(),
			description: ($('#nw-field-description').val() || '').trim(),
			gm_instructions: ($('#nw-field-gm_instructions').val() || '').trim(),
			img_url: ($('#nw-field-img_url').val() || '').trim(),
			tags: ($('#nw-field-tags').val() || '').trim(),
			conflict_axis: ($('#nw-field-conflict_axis').val() || '').trim(),
			conflict_side: ($('#nw-field-conflict_side').val() || '').trim(),
			bonus: ($('#nw-field-bonus').val() || '').trim(),
			race_base_hp: $('#nw-field-race_base_hp').val(),
			race_base_mp: $('#nw-field-race_base_mp').val(),
			preferred_tech: clampPref($('#nw-field-preferred_tech').val()),
			preferred_magic: clampPref($('#nw-field-preferred_magic').val()),
			preferred_gods: clampPref($('#nw-field-preferred_gods').val()),
			preferred_wealth: clampPref($('#nw-field-preferred_wealth').val()),
			preferred_threat: clampPref($('#nw-field-preferred_threat').val()),
			preferred_moral: clampPref($('#nw-field-preferred_moral').val()),
			preferred_social: clampPref($('#nw-field-preferred_social').val()),
			is_active: $('#nw-field-is_active').is(':checked') ? '1' : '0'
		};
	}

	function loadAll() {
		$('#nw-races-tbody').html('<tr><td colspan="6" style="text-align:center;padding:32px;color:#777;">Loading…</td></tr>');

		$.post(ajax, {
			action: 'nw_races_get_all',
			nonce: nonce
		}, function (res) {
			if (!res || !res.success) {
				notice('Error: ' + ((res && res.data) || 'Load failed.'), 'error');
				return;
			}

			all = Array.isArray(res.data) ? res.data : [];
			var filtered = getFilteredRows();
			renderTable(filtered);
			updateStats(filtered);
		}).fail(function () {
			notice('Request failed.', 'error');
		});
	}

	$(document).on('input', '#nw-search', function () {
		var filtered = getFilteredRows();
		renderTable(filtered);
		updateStats(filtered);
	});

	$(document).on('input', '.nw-range', function () {
		var id = $(this).attr('id').replace('nw-field-', '');
		var val = clampPref($(this).val());
		$(this).val(val).attr('max', 5);
		$('#nw-val-' + id).text(val);
	});

	$(document).on('input change blur', '#nw-field-img_url', function () {
		updateImagePreview();
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
		var $row = $cb.closest('tr');

		$.post(ajax, {
			action: 'nw_races_toggle',
			nonce: nonce,
			id: id,
			is_active: val ? '1' : '0'
		}, function (res) {
			if (!res || !res.success) {
				notice('Toggle failed: ' + ((res && res.data) || 'Unknown error'), 'error');
				$cb.prop('checked', !val);
				return;
			}

			all = all.map(function (race) {
				if (String(race.id) === String(id)) {
					race.is_active = val;
				}
				return race;
			});

			$row.toggleClass('nw-row-inactive', !val);
			updateStats(getFilteredRows());
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

		$btn.prop('disabled', true);
		$('#nw-save-label').text('Saving…');

		$.post(ajax, payload, function (res) {
			$btn.prop('disabled', false);
			$('#nw-save-label').text(payload.id ? 'Save Changes' : 'Save Race');

			if (!res || !res.success) {
				notice('Error: ' + ((res && res.data) || 'Unknown error'), 'error');
				return;
			}

			notice('Race saved!', 'success');
			closeModal();
			loadAll();
		}).fail(function () {
			$btn.prop('disabled', false);
			$('#nw-save-label').text(payload.id ? 'Save Changes' : 'Save Race');
			notice('Request failed.', 'error');
		});
	});

	$(document).on('click', '#nw-delete-btn', function () {
		var id = ($('#nw-field-id').val() || '').trim();

		if (!id) {
			return;
		}

		if (!window.confirm('Delete this race? This cannot be undone.')) {
			return;
		}

		$.post(ajax, {
			action: 'nw_races_delete',
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

	$(function () {
		if (!ajax || !nonce) {
			notice('Missing AJAX config.', 'error');
			return;
		}

		PREF_KEYS.forEach(function (key) {
			$('#nw-field-' + key).attr({
				min: 0,
				max: 5
			});
		});

		loadAll();
	});

})(jQuery);
