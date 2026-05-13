/* NeoWeaver Admin — Starting Packages Panel JS */
jQuery(function ($) {
	'use strict';

	var ajaxUrl = (window.NW_SP && window.NW_SP.ajax_url) ? window.NW_SP.ajax_url : '';
	var nonce = (window.NW_SP && window.NW_SP.nonce) ? window.NW_SP.nonce : ($('#nw-nonce').val() || '');
	var editId = null;
	var allItems = [];
	var itemsCacheLoaded = false;
	var itemsCacheXhr = null;

	if (!ajaxUrl || !nonce) {
		console.error('NeoWeaver Starting Packages: missing AJAX config.');
		return;
	}

	function request(data, onSuccess, fallbackError) {
		return $.ajax({
			url: ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: data
		})
		.done(function (res) {
			if (typeof onSuccess === 'function') {
				onSuccess(res);
			}
		})
		.fail(function (xhr, status) {
			if (status === 'abort') {
				return;
			}
			showNotice('error', extractError(xhr, fallbackError || 'Request failed.'));
		});
	}

	function extractError(xhr, fallback) {
		if (xhr && xhr.responseJSON) {
			if (typeof xhr.responseJSON.data === 'string' && xhr.responseJSON.data) {
				return xhr.responseJSON.data;
			}
			if (
				xhr.responseJSON.data &&
				typeof xhr.responseJSON.data.message === 'string' &&
				xhr.responseJSON.data.message
			) {
				return xhr.responseJSON.data.message;
			}
		}

		if (xhr && typeof xhr.responseText === 'string' && xhr.responseText.trim()) {
			try {
				var parsed = JSON.parse(xhr.responseText);
				if (parsed && typeof parsed.data === 'string' && parsed.data) {
					return parsed.data;
				}
			} catch (e) {}
		}

		return fallback || 'Request failed.';
	}

	function invalidateItemsCache() {
		allItems = [];
		itemsCacheLoaded = false;
		if (itemsCacheXhr && itemsCacheXhr.readyState !== 4) {
			itemsCacheXhr.abort();
		}
		itemsCacheXhr = null;
	}

	function loadItemsCache(cb, forceRefresh) {
		if (itemsCacheLoaded && !forceRefresh) {
			if (typeof cb === 'function') {
				cb();
			}
			return;
		}

		if (itemsCacheXhr && itemsCacheXhr.readyState !== 4) {
			itemsCacheXhr.abort();
		}

		itemsCacheXhr = request(
			{
				action: 'nw_sp_get_items',
				nonce: nonce
			},
			function (r) {
				itemsCacheXhr = null;

				if (r && r.success) {
					allItems = Array.isArray(r.data) ? r.data : [];
					itemsCacheLoaded = true;
				} else {
					allItems = [];
					itemsCacheLoaded = false;
				}

				if (typeof cb === 'function') {
					cb();
				}
			},
			'Failed to load items.'
		);
	}

	function populateItemSelects(pkg) {
		var selects = [
			'nw-field-head_item_id',
			'nw-field-torso_item_id',
			'nw-field-hand_r_item_id',
			'nw-field-hand_l_item_id',
			'nw-field-belt_item_id'
		];

		selects.forEach(function (selId) {
			var $sel = $('#' + selId);
			var grouped = {};

			$sel.empty().append('<option value="">— none —</option>');

			$.each(allItems, function (_, it) {
				var groupName = (it.slot || it.type || 'other').toString();
				if (!grouped[groupName]) {
					grouped[groupName] = [];
				}
				grouped[groupName].push(it);
			});

			$.each(grouped, function (grpName, items) {
				var $og = $('<optgroup>').attr('label', String(grpName).toUpperCase());

				$.each(items, function (_, it) {
					var label = it.name || '(unnamed item)';
					if (it.slot) {
						label += ' [' + it.slot + ']';
					}
					$og.append(
						$('<option>').val(it.id).text(label)
					);
				});

				$sel.append($og);
			});

			var fieldName = selId.replace('nw-field-', '');
			var curVal = pkg && pkg[fieldName] ? pkg[fieldName] : '';
			$sel.val(curVal);
		});
	}

	function loadPackages() {
		$('#nw-sp-tbody').html(
			'<tr class="nw-loading-row"><td colspan="7"><div class="nw-spinner"></div> Loading packages…</td></tr>'
		);

		request(
			{
				action: 'nw_sp_get_all',
				nonce: nonce
			},
			function (r) {
				if (!r || !r.success) {
					showNotice('error', r && r.data ? r.data : 'Could not load packages.');
					$('#nw-sp-tbody').html('<tr><td colspan="7" style="text-align:center;padding:32px;color:#d63638;">Failed to load packages.</td></tr>');
					return;
				}
				renderTable(Array.isArray(r.data) ? r.data : []);
			},
			'Failed to load packages.'
		);
	}

	function renderTable(rows) {
		var total = rows.length;
		var sel = 0;
		var hidden = 0;
		var html = '';

		if (!rows.length) {
			html = '<tr><td colspan="7" style="text-align:center;padding:32px;color:#555;">No packages found.</td></tr>';
		}

		$.each(rows, function (_, p) {
			if (p.is_player_selectable) {
				sel++;
			} else {
				hidden++;
			}

			var slots = '';
			var slotFields = ['head_item_id', 'torso_item_id', 'hand_r_item_id', 'hand_l_item_id', 'belt_item_id'];
			var slotLabels = {
				head_item_id: 'Head',
				torso_item_id: 'Torso',
				hand_r_item_id: 'R-Hand',
				hand_l_item_id: 'L-Hand',
				belt_item_id: 'Belt'
			};

			$.each(slotFields, function (_, f) {
				if (p[f]) {
					slots += '<span class="nw-slot-chip">' + escH(slotLabels[f]) + '</span>';
				}
			});

			if (!slots) {
				slots = '<span style="color:#333">—</span>';
			}

			var tags = '';
			if (Array.isArray(p.compatibility_tags) && p.compatibility_tags.length) {
				$.each(p.compatibility_tags, function (_, t) {
					tags += '<span class="nw-tag">' + escH(t) + '</span>';
				});
			}
			if (!tags) {
				tags = '<span style="color:#333">—</span>';
			}

			var classCount = (Array.isArray(p.compatible_class_ids) && p.compatible_class_ids.length)
				? p.compatible_class_ids.length
				: 0;

			html += ''
				+ '<tr data-id="' + escH(p.id) + '">'
				+ '<td><div class="nw-pkg-name">' + escH(p.package_name) + '</div>'
				+ (p.description ? '<div class="nw-pkg-sub">' + escH(truncate(p.description, 60)) + '</div>' : '')
				+ '</td>'
				+ '<td><span class="nw-armor-val">' + escH(p.base_armor) + '</span></td>'
				+ '<td>' + slots + '</td>'
				+ '<td><div class="nw-tags">' + tags + '</div></td>'
				+ '<td>' + (classCount ? '<span class="nw-tag">' + escH(classCount) + ' class' + (classCount > 1 ? 'es' : '') + '</span>' : '<span style="color:#333">—</span>') + '</td>'
				+ '<td><label class="nw-toggle"><input type="checkbox" class="nw-toggle-sel" data-id="' + escH(p.id) + '"' + (p.is_player_selectable ? ' checked' : '') + '><span class="nw-toggle-slider"></span></label></td>'
				+ '<td><div class="nw-row-actions"><button type="button" class="nw-action-btn nw-edit-btn" data-id="' + escH(p.id) + '">Edit</button></div></td>'
				+ '</tr>';
		});

		$('#nw-sp-tbody').html(html);
		$('#nw-total').text(total);
		$('#nw-selectable').text(sel);
		$('#nw-hidden').text(hidden);
	}

	function openModal(pkg) {
		editId = pkg ? pkg.id : null;

		$('#nw-modal-title').text(pkg ? 'Edit Package' : 'New Package');
		$('#nw-save-label').text(pkg ? 'Save Package' : 'Create Package');
		$('#nw-delete-btn').toggle(!!pkg);

		$('#nw-field-id').val(pkg ? pkg.id : '');
		$('#nw-field-package_name').val(pkg ? (pkg.package_name || '') : '');
		$('#nw-field-description').val(pkg ? (pkg.description || '') : '');
		$('#nw-field-base_armor').val(pkg ? (parseInt(pkg.base_armor, 10) || 0) : 0);
		$('#nw-field-is_player_selectable').prop('checked', pkg ? !!pkg.is_player_selectable : false);

		var arrToStr = function (a) {
			return (Array.isArray(a) && a.length) ? a.join(', ') : '';
		};

		$('#nw-field-items_list').val(arrToStr(pkg && pkg.items_list));
		$('#nw-field-attack_cards_pool').val(arrToStr(pkg && pkg.attack_cards_pool));
		$('#nw-field-defense_cards_pool').val(arrToStr(pkg && pkg.defense_cards_pool));
		$('#nw-field-compatibility_tags').val(arrToStr(pkg && pkg.compatibility_tags));
		$('#nw-field-compatible_class_ids').val(arrToStr(pkg && pkg.compatible_class_ids));

		loadItemsCache(function () {
			populateItemSelects(pkg);
		}, true);

		$('#nw-modal-overlay').show();
	}

	function closeModal() {
		$('#nw-modal-overlay').hide();

		if ($('#nw-sp-form').length && $('#nw-sp-form')[0]) {
			$('#nw-sp-form')[0].reset();
		}

		$('#nw-field-id').val('');
		editId = null;
		$('#nw-delete-btn').hide();
		$('#nw-save-btn').prop('disabled', false);
		$('#nw-save-label').text('Save Package');
	}

	function savePkg() {
		var packageName = ($('#nw-field-package_name').val() || '').trim();

		if (!packageName) {
			showNotice('error', 'Package name is required.');
			return;
		}

		var data = {
			action: 'nw_sp_save',
			nonce: nonce,
			pkg: {
				id: ($('#nw-field-id').val() || '').trim(),
				package_name: packageName,
				description: ($('#nw-field-description').val() || '').trim(),
				base_armor: ($('#nw-field-base_armor').val() || '0').trim(),
				items_list: ($('#nw-field-items_list').val() || '').trim(),
				attack_cards_pool: ($('#nw-field-attack_cards_pool').val() || '').trim(),
				defense_cards_pool: ($('#nw-field-defense_cards_pool').val() || '').trim(),
				compatibility_tags: ($('#nw-field-compatibility_tags').val() || '').trim(),
				compatible_class_ids: ($('#nw-field-compatible_class_ids').val() || '').trim(),
				is_player_selectable: $('#nw-field-is_player_selectable').is(':checked') ? '1' : '0',
				head_item_id: $('#nw-field-head_item_id').val() || '',
				torso_item_id: $('#nw-field-torso_item_id').val() || '',
				hand_r_item_id: $('#nw-field-hand_r_item_id').val() || '',
				hand_l_item_id: $('#nw-field-hand_l_item_id').val() || '',
				belt_item_id: $('#nw-field-belt_item_id').val() || ''
			}
		};

		$('#nw-save-btn').prop('disabled', true);
		$('#nw-save-label').text(editId ? 'Saving…' : 'Creating…');

		request(
			data,
			function (r) {
				$('#nw-save-btn').prop('disabled', false);
				$('#nw-save-label').text(editId ? 'Save Package' : 'Create Package');

				if (!r || !r.success) {
					showNotice('error', r && r.data ? r.data : 'Could not save package.');
					return;
				}

				showNotice('success', editId ? 'Package updated.' : 'Package created.');
				closeModal();
				loadPackages();
			},
			'Failed to save package.'
		);
	}

	function loadPackageById(id) {
		request(
			{
				action: 'nw_sp_get_one',
				nonce: nonce,
				pkg_id: id
			},
			function (r) {
				if (!r || !r.success || !r.data) {
					showNotice('error', r && r.data ? r.data : 'Could not load package details.');
					return;
				}

				openModal(r.data);
			},
			'Failed to load package details.'
		);
	}

	$(document).on('change', '.nw-toggle-sel', function () {
		var $el = $(this);
		var id = $el.data('id');
		var state = $el.is(':checked');

		request(
			{
				action: 'nw_sp_toggle',
				nonce: nonce,
				pkg_id: id,
				is_player_selectable: state ? 1 : 0
			},
			function (r) {
				if (!r || !r.success) {
					$el.prop('checked', !state);
					showNotice('error', r && r.data ? r.data : 'Could not update package visibility.');
					return;
				}
				loadPackages();
			},
			'Failed to update package visibility.'
		);
	});

	$('#nw-delete-btn').on('click', function () {
		if (!editId || !window.confirm('Delete this package? This cannot be undone.')) {
			return;
		}

		request(
			{
				action: 'nw_sp_delete',
				nonce: nonce,
				pkg_id: editId
			},
			function (r) {
				if (!r || !r.success) {
					showNotice('error', r && r.data ? r.data : 'Could not delete package.');
					return;
				}
				showNotice('success', 'Package deleted.');
				closeModal();
				loadPackages();
			},
			'Failed to delete package.'
		);
	});

	$('#nw-add-btn').on('click', function () {
		invalidateItemsCache();
		openModal(null);
	});

	$('#nw-refresh-btn').on('click', function () {
		invalidateItemsCache();
		loadPackages();
	});

	$('#nw-modal-close, #nw-cancel-btn').on('click', function () {
		closeModal();
	});

	$('#nw-modal-overlay').on('click', function (e) {
		if ($(e.target).is('#nw-modal-overlay')) {
			closeModal();
		}
	});

	$('#nw-save-btn').on('click', function (e) {
		e.preventDefault();
		savePkg();
	});

	$(document).on('click', '.nw-edit-btn', function () {
		var id = $(this).data('id');
		loadPackageById(id);
	});

	function showNotice(type, msg) {
		var $n = $('#nw-notice');
		$n.removeClass('nw-notice-success nw-notice-error')
			.addClass('nw-notice-' + type)
			.text(msg)
			.show();

		setTimeout(function () {
			$n.fadeOut();
		}, 4000);
	}

	function truncate(str, len) {
		str = String(str || '');
		return str.length > len ? str.substring(0, len) + '…' : str;
	}

	function escH(s) {
		return $('<div>').text(String(s || '')).html();
	}

	loadPackages();
});
