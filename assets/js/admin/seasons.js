/**
 * NeoWeaver Admin — Seasons Config
 * Works with the updated Seasons PHP backend.
 *
 * Expects:
 *   nwSeasonsData.nonce
 *   nwSeasonsData.ajax
 *   nwSeasonsData.weights
 */
/* global nwSeasonsData, jQuery */
(function ($) {
	'use strict';

	var cfg = window.nwSeasonsData || {};
	var NONCE = cfg.nonce || '';
	var AJAX = cfg.ajax || '';
	var WEIGHTS = cfg.weights || {};

	var WEIGHT_ORDER = [
		'weight_sun',
		'weight_cloudy',
		'weight_rain',
		'weight_fog',
		'weight_storm',
		'weight_snow'
	];

	var W_KEYS = WEIGHT_ORDER.filter(function (key) {
		return Object.prototype.hasOwnProperty.call(WEIGHTS, key) || $('#nw-' + key).length;
	});

	var DEFAULT_WEIGHT_VALUES = {
		weight_sun: 25,
		weight_cloudy: 25,
		weight_rain: 25,
		weight_fog: 25,
		weight_storm: 0,
		weight_snow: 0
	};

	var W_COLORS = {
		weight_sun: '#ffd700',
		weight_cloudy: '#9e9e9e',
		weight_rain: '#4fc3f7',
		weight_fog: '#b0bec5',
		weight_storm: '#7e57c2',
		weight_snow: '#e0f7fa'
	};

	function esc(s) {
		return $('<span>').text(s == null ? '' : String(s)).html();
	}

	function normalizeError(res, fallback) {
		if (res && typeof res.data === 'string' && res.data.trim()) {
			return res.data.trim();
		}
		if (res && res.data && typeof res.data.message === 'string' && res.data.message.trim()) {
			return res.data.message.trim();
		}
		return fallback || 'Request failed.';
	}

	function post(action, data) {
		return $.post(AJAX, $.extend({}, { action: action, nonce: NONCE }, data || {}));
	}

	function setNotice(msg, type) {
		var $el = $('#nw-notice');
		var isError = type === 'error';

		if (!$el.length) {
			return;
		}

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

	function setInlineError(msg) {
		$('#nw-season-save-error').text(msg || '');
	}

	function setSaveButtonState(isBusy, disabledBecauseInvalid) {
		var disabled = !!isBusy || !!disabledBecauseInvalid;

		$('#nw-season-save-btn')
			.prop('disabled', disabled)
			.text(isBusy ? 'Saving…' : 'Save Season');
	}

	function setSeasonNameLocked(locked) {
		$('#nw-season-name').prop('readonly', !!locked);
	}

	function getWeightValue(key) {
		var n = parseInt($('#nw-' + key).val(), 10);
		if (isNaN(n)) n = 0;
		return Math.max(0, Math.min(100, n));
	}

	function getWeightsSum() {
		var sum = 0;
		W_KEYS.forEach(function (k) {
			sum += getWeightValue(k);
		});
		return sum;
	}

	function getDefaultWeightValue(key) {
		if (cfg.defaultWeightValues && Object.prototype.hasOwnProperty.call(cfg.defaultWeightValues, key)) {
			var serverVal = parseInt(cfg.defaultWeightValues[key], 10);
			if (!isNaN(serverVal)) {
				return Math.max(0, Math.min(100, serverVal));
			}
		}

		if (Object.prototype.hasOwnProperty.call(DEFAULT_WEIGHT_VALUES, key)) {
			return DEFAULT_WEIGHT_VALUES[key];
		}

		return 0;
	}

	function validateFormState(showMessage) {
		var sum = getWeightsSum();
		var tempRaw = ($('#nw-season-temp').val() || '').trim();
		var tempVal = parseFloat(tempRaw);
		var error = '';

		if (!($('#nw-season-name').val() || '').trim()) {
			error = 'Season name is required.';
		} else if (sum !== 100) {
			error = 'Weather weights must sum to exactly 100.';
		} else if (!tempRaw || isNaN(tempVal) || tempVal <= 0) {
			error = 'Temp modifier must be greater than 0.';
		}

		if (showMessage) {
			setInlineError(error);
		} else if (!error) {
			setInlineError('');
		}

		setSaveButtonState(false, !!error);
		return !error;
	}

	function loadList() {
		$('#nw-season-table-wrap').html('<div class="nw-spinner" style="margin:40px auto;display:block;"></div>');

		post('nw_season_list')
			.done(function (res) {
				if (!res || !res.success) {
					$('#nw-season-table-wrap').html('<p style="color:#ff6b6b;">' + esc(normalizeError(res, 'Could not load seasons.')) + '</p>');
					return;
				}
				renderTable(Array.isArray(res.data) ? res.data : []);
			})
			.fail(function (xhr) {
				var msg = 'Request failed.';
				if (xhr && xhr.responseJSON) {
					msg = normalizeError(xhr.responseJSON, msg);
				}
				$('#nw-season-table-wrap').html('<p style="color:#ff6b6b;">' + esc(msg) + '</p>');
			});
	}

	function renderTable(rows) {
		if (!rows.length) {
			$('#nw-season-table-wrap').html('<div class="nw-empty"><p>No seasons configured yet.</p><p style="font-size:.8rem">Add one with the button above.</p></div>');
			return;
		}

		var html = ''
			+ '<table class="nw-season-table">'
			+ '<thead><tr>'
			+ '<th>Name</th><th>Icon</th><th>Color</th><th>Temp ×</th><th>Sort</th><th>Weather Distribution</th><th>Actions</th>'
			+ '</tr></thead><tbody>';

		rows.forEach(function (r) {
			var seasonName = r && r.season_name ? String(r.season_name) : '';
			var icon = r && r.icon ? '<span style="font-size:1.2rem">' + esc(r.icon) + '</span>' : '—';
			var colorText = r && r.color ? esc(r.color) : '<span style="color:var(--nw-muted)">—</span>';
			var dot = r && r.color ? '<span class="nw-color-dot" style="background:' + esc(r.color) + ';"></span>' : '';
			var sortOrder = (r && r.sort_order != null) ? r.sort_order : 0;

			var miniBar = '<div class="nw-mini-bar">';
			W_KEYS.forEach(function (k) {
				var w = parseInt(r[k], 10) || 0;
				if (w > 0) {
					miniBar += '<div class="nw-mini-seg" style="width:' + w + '%;background:' + W_COLORS[k] + ';" title="' + esc((WEIGHTS[k] || k) + ': ' + w + '%') + '"></div>';
				}
			});
			miniBar += '</div>';

			var sumLabel = W_KEYS.reduce(function (s, k) {
				return s + (parseInt(r[k], 10) || 0);
			}, 0);

			html += ''
				+ '<tr>'
				+ '<td><strong>' + esc(seasonName) + '</strong></td>'
				+ '<td>' + icon + '</td>'
				+ '<td>' + dot + colorText + '</td>'
				+ '<td>' + esc(r.temp_modifier) + '</td>'
				+ '<td style="color:var(--nw-muted)">' + esc(sortOrder) + '</td>'
				+ '<td><div style="display:flex;align-items:center;">' + miniBar + '<span style="font-size:.7rem;color:var(--nw-muted);margin-left:6px;">' + esc(sumLabel) + '%</span></div></td>'
				+ '<td><div class="nw-tbl-actions">'
				+ '<button type="button" class="nw-btn nw-btn-ghost nw-btn-xs nw-edit-btn" data-name="' + esc(seasonName) + '">Edit</button>'
				+ '<button type="button" class="nw-btn nw-btn-danger nw-btn-xs nw-delete-btn" data-name="' + esc(seasonName) + '">Delete</button>'
				+ '</div></td>'
				+ '</tr>';
		});

		html += '</tbody></table>';
		$('#nw-season-table-wrap').html(html);
	}

	function updateWeightUI(showValidation) {
		var sum = 0;

		W_KEYS.forEach(function (k) {
			var val = getWeightValue(k);
			sum += val;
			$('#nw-' + k).val(val);
			$('#nw-' + k + '-pct').text(val + '%');
			$('#nw-' + k + '-range').val(val);
			$('#nw-bar-' + k.replace('weight_', '')).css('width', val + '%');
		});

		$('#nw-weights-sum').text(sum);

		var $badge = $('#nw-weights-sum-badge');
		$badge.toggleClass('ok', sum === 100).toggleClass('bad', sum !== 100);

		validateFormState(!!showValidation);
	}

	function resetForm() {
		var form = $('#nw-season-form')[0];
		if (form) {
			form.reset();
		}

		$('#nw-season-is-edit').val('0');
		$('#nw-season-orig-name').val('');
		$('#nw-season-name').val('');
		$('#nw-season-desc').val('');
		$('#nw-season-temp').val('1.00');
		$('#nw-season-color').val('');
		$('#nw-season-color-picker').val('#adff00');
		$('#nw-season-icon').val('');
		$('#nw-season-sort').val('0');

		W_KEYS.forEach(function (k) {
			var def = getDefaultWeightValue(k);
			$('#nw-' + k).val(def);
			$('#nw-' + k + '-range').val(def);
		});

		setSeasonNameLocked(false);
		setInlineError('');
		setSaveButtonState(false, false);
		updateWeightUI(false);
	}

	function populateForm(r) {
		$('#nw-season-name').val(r.season_name || '');
		$('#nw-season-orig-name').val(r.season_name || '');
		$('#nw-season-is-edit').val('1');
		$('#nw-season-desc').val(r.description || '');
		$('#nw-season-temp').val(r.temp_modifier != null ? r.temp_modifier : '1.00');
		$('#nw-season-color').val(r.color || '');
		if (r.color && /^#[0-9a-fA-F]{6}$/.test(r.color)) {
			$('#nw-season-color-picker').val(r.color);
		} else {
			$('#nw-season-color-picker').val('#adff00');
		}
		$('#nw-season-icon').val(r.icon || '');
		$('#nw-season-sort').val(r.sort_order != null ? r.sort_order : 0);

		W_KEYS.forEach(function (k) {
			if (!Object.prototype.hasOwnProperty.call(r, k)) {
				setInlineError('Warning: season data is missing expected weight key "' + k + '".');
			}

			var val = parseInt(r[k], 10);
			if (isNaN(val)) val = 0;
			val = Math.max(0, Math.min(100, val));
			$('#nw-' + k).val(val);
			$('#nw-' + k + '-range').val(val);
		});

		setSeasonNameLocked(true);
		updateWeightUI(true);
	}

	function openModal(title) {
		$('#nw-season-modal-title').text(title || 'Season');
		setInlineError('');
		$('#nw-season-modal').show();
		updateWeightUI(false);
		$('#nw-season-name').trigger('focus');
	}

	function closeModal() {
		$('#nw-season-modal').hide();
		resetForm();
	}

	function formToData() {
		var data = {
			season_name: ($('#nw-season-name').val() || '').trim(),
			orig_season_name: ($('#nw-season-orig-name').val() || '').trim(),
			is_edit: ($('#nw-season-is-edit').val() || '0'),
			description: ($('#nw-season-desc').val() || '').trim(),
			temp_modifier: ($('#nw-season-temp').val() || '').trim(),
			color: ($('#nw-season-color').val() || '').trim(),
			icon: ($('#nw-season-icon').val() || '').trim(),
			sort_order: ($('#nw-season-sort').val() || '').trim()
		};

		W_KEYS.forEach(function (k) {
			data[k] = getWeightValue(k);
		});

		return data;
	}

	$(document).on('input', '.nw-weight-range', function () {
		var target = $(this).data('target');
		var val = parseInt($(this).val(), 10);
		if (isNaN(val)) val = 0;
		val = Math.max(0, Math.min(100, val));
		$('#' + target).val(val);
		updateWeightUI(true);
	});

	$(document).on('input', '.nw-weight-num', function () {
		var id = $(this).attr('id');
		var val = parseInt($(this).val(), 10);
		if (isNaN(val)) val = 0;
		val = Math.max(0, Math.min(100, val));
		$(this).val(val);
		$('#' + id + '-range').val(val);
		updateWeightUI(true);
	});

	$(document).on('input', '#nw-season-temp, #nw-season-name', function () {
		updateWeightUI(true);
	});

	$(document).on('input', '#nw-season-color-picker', function () {
		$('#nw-season-color').val($(this).val());
	});

	$(document).on('input', '#nw-season-color', function () {
		var v = ($(this).val() || '').trim();
		if (/^#[0-9a-fA-F]{6}$/.test(v)) {
			$('#nw-season-color-picker').val(v);
		}
	});

	$(document)
		.on('click', '#nw-season-add-btn', function () {
			resetForm();
			openModal('Add Season');
		})
		.on('click', '#nw-season-modal-close, #nw-season-cancel-btn', function () {
			closeModal();
		})
		.on('click', '#nw-season-modal', function (e) {
			if ($(e.target).is('#nw-season-modal')) {
				closeModal();
			}
		})
		.on('keydown', function (e) {
			if (e.key === 'Escape' && $('#nw-season-modal').is(':visible')) {
				closeModal();
			}
		})
		.on('click', '.nw-edit-btn', function () {
			var name = $(this).data('name');

			post('nw_season_get', { season_name: name })
				.done(function (res) {
					if (!res || !res.success) {
						setNotice(normalizeError(res, 'Could not load season.'), 'error');
						return;
					}
					resetForm();
					populateForm(res.data || {});
					openModal('Edit Season');
				})
				.fail(function (xhr) {
					var msg = 'Could not load season.';
					if (xhr && xhr.responseJSON) {
						msg = normalizeError(xhr.responseJSON, msg);
					}
					setNotice(msg, 'error');
				});
		})
		.on('click', '.nw-delete-btn', function () {
			var name = $(this).data('name');

			if (!window.confirm('Delete season "' + name + '"? This cannot be undone.')) {
				return;
			}

			post('nw_season_delete', { season_name: name })
				.done(function (res) {
					if (!res || !res.success) {
						setNotice(normalizeError(res, 'Delete failed.'), 'error');
						return;
					}
					setNotice('Season deleted.', 'success');
					loadList();
				})
				.fail(function (xhr) {
					var msg = 'Delete failed.';
					if (xhr && xhr.responseJSON) {
						msg = normalizeError(xhr.responseJSON, msg);
					}
					setNotice(msg, 'error');
				});
		})
		.on('submit', '#nw-season-form', function (e) {
			e.preventDefault();

			var data = formToData();
			var sum = getWeightsSum();
			var tempVal = parseFloat(data.temp_modifier);

			setInlineError('');

			if (!data.season_name) {
				setInlineError('Season name is required.');
				updateWeightUI(true);
				return;
			}

			if (sum !== 100) {
				setInlineError('Weather weights must sum to exactly 100.');
				updateWeightUI(true);
				return;
			}

			if (isNaN(tempVal) || tempVal <= 0) {
				setInlineError('Temp modifier must be greater than 0.');
				updateWeightUI(true);
				return;
			}

			setSaveButtonState(true, false);

			post('nw_season_save', data)
				.done(function (res) {
					if (!res || !res.success) {
						setInlineError(normalizeError(res, 'Save failed.'));
						setSaveButtonState(false, false);
						validateFormState(true);
						return;
					}
					closeModal();
					setNotice('Season saved.', 'success');
					loadList();
				})
				.fail(function (xhr) {
					var msg = 'Request failed.';
					if (xhr && xhr.responseJSON) {
						msg = normalizeError(xhr.responseJSON, msg);
					}
					setInlineError(msg);
					setSaveButtonState(false, false);
					validateFormState(true);
				});
		});

	$(document).ready(function () {
		if (!AJAX || !NONCE) {
			$('#nw-season-table-wrap').html('<p style="color:#ff6b6b;">Missing AJAX configuration.</p>');
			return;
		}

		resetForm();
		loadList();
	});

})(jQuery);
