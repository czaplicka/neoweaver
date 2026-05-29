/* NeoWeaver — Starting Packages Admin JS */
/* globals NWPackages, lucide, jQuery */
(function ($) {
	'use strict';

	const A = NWPackages.ajaxurl;
	const N = NWPackages.nonce;

	let allRows  = [];
	let allItems = []; // cyber_items for slot dropdowns

	// ── Lucide ───────────────────────────────────────────────────────────────
	function icons() {
		if (window.lucide) lucide.createIcons();
	}

	// ── Notice ───────────────────────────────────────────────────────────────
	function notice(msg, type = 'success') {
		const $n = $('#nw-notice');
		$n.removeClass('nw-notice-success nw-notice-error')
			.addClass(type === 'error' ? 'nw-notice-error' : 'nw-notice-success')
			.html(msg).show();
		setTimeout(() => $n.fadeOut(), 4000);
	}

	// ── Stats ────────────────────────────────────────────────────────────────
	function updateStats(rows) {
		$('#nw-total').text(rows.length);
		$('#nw-selectable').text(rows.filter(r => r.is_player_selectable).length);
		$('#nw-with-slots').text(rows.filter(r =>
			r.head_item_id || r.torso_item_id || r.hand_r_item_id || r.hand_l_item_id || r.belt_item_id
		).length);
		$('#nw-with-classes').text(rows.filter(r =>
			Array.isArray(r.compatible_class_ids) && r.compatible_class_ids.length > 0
		).length);
	}

	// ── Item name lookup ─────────────────────────────────────────────────────
	function itemName(id) {
		if (!id) return '—';
		const item = allItems.find(i => i.id === id);
		return item ? `<span class="nw-item-chip">${item.name}</span>` : `<span class="nw-item-chip nw-chip-missing">unknown</span>`;
	}

	// ── Slot summary ─────────────────────────────────────────────────────────
	function slotSummary(row) {
		const slots = [
			{ icon: 'hard-hat', id: row.head_item_id   },
			{ icon: 'shirt',    id: row.torso_item_id  },
			{ icon: 'swords',   id: row.hand_r_item_id },
			{ icon: 'shield',   id: row.hand_l_item_id },
			{ icon: 'belt',     id: row.belt_item_id   },
		];
		const filled = slots.filter(s => s.id).length;
		if (!filled) return '<span class="nw-text-muted">None</span>';
		return `<span class="nw-slots-pill">${filled}/5 slots</span>`;
	}

	// ── Cards pool summary ───────────────────────────────────────────────────
	function cardsSummary(row) {
		const atk = Array.isArray(row.attack_cards_pool)  ? row.attack_cards_pool.length  : 0;
		const def = Array.isArray(row.defense_cards_pool) ? row.defense_cards_pool.length : 0;
		if (!atk && !def) return '<span class="nw-text-muted">—</span>';
		return `<span class="nw-pill nw-pill-atk">⚔ ${atk}</span> <span class="nw-pill nw-pill-def">🛡 ${def}</span>`;
	}

	// ── Classes summary ──────────────────────────────────────────────────────
	function classesSummary(row) {
		const ids = Array.isArray(row.compatible_class_ids) ? row.compatible_class_ids : [];
		if (!ids.length) return '<span class="nw-text-muted">All</span>';
		return `<span class="nw-pill nw-pill-class">${ids.length} class${ids.length > 1 ? 'es' : ''}</span>`;
	}

	// ── Render table ─────────────────────────────────────────────────────────
	function renderTable(rows) {
		const $tbody = $('#nw-packages-tbody');
		if (!rows.length) {
			$tbody.html('<tr><td colspan="7" class="nw-empty">No packages found.</td></tr>');
			icons();
			return;
		}
		const html = rows.map(row => `
			<tr data-id="${row.id}">
				<td class="nw-col-name">
					<div class="nw-row-title">${escHtml(row.package_name)}</div>
					${row.description ? `<div class="nw-row-desc">${escHtml(row.description.slice(0, 60))}${row.description.length > 60 ? '…' : ''}</div>` : ''}
				</td>
				<td><span class="nw-armor-val">${row.base_armor ?? 0}</span></td>
				<td>${slotSummary(row)}</td>
				<td>${cardsSummary(row)}</td>
				<td>${classesSummary(row)}</td>
				<td>${row.is_player_selectable
					? '<span class="nw-badge nw-badge-active">Yes</span>'
					: '<span class="nw-badge nw-badge-inactive">GM only</span>'}</td>
				<td class="nw-col-actions">
					<button class="nw-btn-icon nw-edit-btn" title="Edit" data-id="${row.id}"><i data-lucide="pencil"></i></button>
					<button class="nw-btn-icon nw-dup-btn"  title="Duplicate" data-id="${row.id}"><i data-lucide="copy"></i></button>
					<button class="nw-btn-icon nw-del-btn nw-btn-danger" title="Delete" data-id="${row.id}"><i data-lucide="trash-2"></i></button>
				</td>
			</tr>`).join('');
		$tbody.html(html);
		icons();
	}

	// ── Filters ──────────────────────────────────────────────────────────────
	function applyFilters() {
		const search     = $('#nw-search').val().toLowerCase();
		const selectable = $('#nw-filter-selectable').val();
		const armor      = $('#nw-filter-armor').val();

		const filtered = allRows.filter(r => {
			if (search && !r.package_name.toLowerCase().includes(search)) return false;
			if (selectable === '1' && !r.is_player_selectable) return false;
			if (selectable === '0' && r.is_player_selectable)  return false;
			if (armor === '0' && (r.base_armor ?? 0) !== 0)    return false;
			if (armor === '1' && (r.base_armor ?? 0) === 0)    return false;
			return true;
		});
		renderTable(filtered);
	}

	// ── Load ─────────────────────────────────────────────────────────────────
	function loadPackages() {
		$('#nw-packages-tbody').html('<tr><td colspan="7" class="nw-loading"><i data-lucide="loader-2" class="nw-spin"></i> Loading…</td></tr>');
		icons();

		$.post(A, { action: 'nwpackagesload', nonce: N }, res => {
			if (!res.success) { notice(res.data || 'Load error', 'error'); return; }
			allRows = res.data || [];
			updateStats(allRows);
			applyFilters();
		});
	}

	function loadItems(cb) {
		$.post(A, { action: 'nwpackagesloaditems', nonce: N }, res => {
			allItems = res.success ? (res.data || []) : [];
			if (cb) cb();
		});
	}

	// ── Build slot dropdowns ─────────────────────────────────────────────────
	function buildItemDropdowns() {
		const emptyOption = '<option value="">— none —</option>';
		const options = allItems.map(i =>
			`<option value="${i.id}">${escHtml(i.name)}${i.slot ? ' [' + i.slot + ']' : ''}</option>`
		).join('');
		$('.nw-item-select').html(emptyOption + options);
	}

	// ── Modal ────────────────────────────────────────────────────────────────
	function openModal(row = null) {
		$('#nw-field-id').val(row ? row.id : '');
		$('#nw-modal-title').text(row ? 'Edit Package' : 'New Package');

		$('#nw-field-package_name').val(row ? row.package_name : '');
		$('#nw-field-description').val(row ? (row.description || '') : '');
		$('#nw-field-base_armor').val(row ? (row.base_armor ?? 0) : 0);

		// Slot selects
		$('#nw-field-head_item_id').val(row ? (row.head_item_id   || '') : '');
		$('#nw-field-torso_item_id').val(row ? (row.torso_item_id || '') : '');
		$('#nw-field-hand_r_item_id').val(row ? (row.hand_r_item_id || '') : '');
		$('#nw-field-hand_l_item_id').val(row ? (row.hand_l_item_id || '') : '');
		$('#nw-field-belt_item_id').val(row ? (row.belt_item_id   || '') : '');

		// JSON fields
		$('#nw-field-items_list').val(row ? jsonPretty(row.items_list) : '[]');
		$('#nw-field-compatibility_tags').val(row ? jsonPretty(row.compatibility_tags) : '[]');
		$('#nw-field-attack_cards_pool').val(row ? jsonPretty(row.attack_cards_pool) : '[]');
		$('#nw-field-defense_cards_pool').val(row ? jsonPretty(row.defense_cards_pool) : '[]');
		$('#nw-field-compatible_class_ids').val(row ? jsonPretty(row.compatible_class_ids) : '[]');

		$('#nw-field-is_player_selectable').prop('checked', row ? !!row.is_player_selectable : false);

		$('#nw-modal').show();
		$('#nw-field-package_name').focus();
		icons();
	}

	function closeModal() {
		$('#nw-modal').hide();
	}

	// ── Save ─────────────────────────────────────────────────────────────────
	function savePackage() {
		const name = $('#nw-field-package_name').val().trim();
		if (!name) { notice('Package name is required.', 'error'); return; }

		// Validate JSON fields
		const jsonFields = ['items_list','compatibility_tags','attack_cards_pool','defense_cards_pool','compatible_class_ids'];
		for (const f of jsonFields) {
			const raw = $(`#nw-field-${f}`).val().trim();
			if (raw && raw !== '[]') {
				try { JSON.parse(raw); } catch(e) {
					notice(`Invalid JSON in "${f}".`, 'error'); return;
				}
			}
		}

		const $btn = $('#nw-modal-save').prop('disabled', true).html('<i data-lucide="loader-2" class="nw-spin"></i> Saving…');
		icons();

		$.post(A, {
			action:                 'nwpackagessave',
			nonce:                  N,
			id:                     $('#nw-field-id').val(),
			package_name:           name,
			description:            $('#nw-field-description').val(),
			base_armor:             $('#nw-field-base_armor').val(),
			head_item_id:           $('#nw-field-head_item_id').val(),
			torso_item_id:          $('#nw-field-torso_item_id').val(),
			hand_r_item_id:         $('#nw-field-hand_r_item_id').val(),
			hand_l_item_id:         $('#nw-field-hand_l_item_id').val(),
			belt_item_id:           $('#nw-field-belt_item_id').val(),
			items_list:             $('#nw-field-items_list').val(),
			compatibility_tags:     $('#nw-field-compatibility_tags').val(),
			attack_cards_pool:      $('#nw-field-attack_cards_pool').val(),
			defense_cards_pool:     $('#nw-field-defense_cards_pool').val(),
			compatible_class_ids:   $('#nw-field-compatible_class_ids').val(),
			is_player_selectable:   $('#nw-field-is_player_selectable').is(':checked') ? 1 : 0,
		}, res => {
			$btn.prop('disabled', false).html('<i data-lucide="save"></i> Save Package');
			icons();
			if (!res.success) { notice(res.data || 'Save failed.', 'error'); return; }
			notice('Package saved!');
			closeModal();
			loadPackages();
		});
	}

	// ── Delete ───────────────────────────────────────────────────────────────
	function deletePackage(id) {
		if (!confirm('Delete this package? This cannot be undone.')) return;
		$.post(A, { action: 'nwpackagesdelete', nonce: N, id }, res => {
			if (!res.success) { notice(res.data || 'Delete failed.', 'error'); return; }
			notice('Package deleted.');
			loadPackages();
		});
	}

	// ── Duplicate ────────────────────────────────────────────────────────────
	function duplicatePackage(id) {
		$.post(A, { action: 'nwpackagesduplicate', nonce: N, id }, res => {
			if (!res.success) { notice(res.data || 'Duplicate failed.', 'error'); return; }
			notice('Package duplicated.');
			loadPackages();
		});
	}

	// ── Utils ────────────────────────────────────────────────────────────────
	function escHtml(str) {
		return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}
	function jsonPretty(val) {
		if (!val) return '[]';
		if (typeof val === 'string') return val;
		return JSON.stringify(val, null, 2);
	}

	// ── Init ─────────────────────────────────────────────────────────────────
	$(document).ready(function () {
		// Load items first, then packages
		loadItems(() => {
			buildItemDropdowns();
			loadPackages();
		});

		// Add button
		$('#nw-add-btn').on('click', () => openModal());

		// Edit
		$('#nw-packages-tbody').on('click', '.nw-edit-btn', function () {
			const id  = $(this).data('id');
			const row = allRows.find(r => r.id === id);
			if (row) openModal(row);
		});

		// Duplicate
		$('#nw-packages-tbody').on('click', '.nw-dup-btn', function () {
			duplicatePackage($(this).data('id'));
		});

		// Delete
		$('#nw-packages-tbody').on('click', '.nw-del-btn', function () {
			deletePackage($(this).data('id'));
		});

		// Modal close
		$('#nw-modal-close, #nw-modal-cancel').on('click', closeModal);
		$('#nw-modal').on('click', function (e) {
			if ($(e.target).is('#nw-modal')) closeModal();
		});

		// Save
		$('#nw-modal-save').on('click', savePackage);

		// Filters
		$('#nw-search, #nw-filter-selectable, #nw-filter-armor').on('input change', applyFilters);
		$('#nw-clear-filters').on('click', () => {
			$('#nw-search').val('');
			$('#nw-filter-selectable').val('');
			$('#nw-filter-armor').val('');
			applyFilters();
		});

		// Keyboard: Esc closes modal
		$(document).on('keydown', e => {
			if (e.key === 'Escape') closeModal();
		});
	});

}(jQuery));
