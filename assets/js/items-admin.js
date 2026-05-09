/**
 * NeoWeaver Admin — Items (cyber_items)
 * Handles CRUD for the Items admin panel.
 * Depends on: jQuery, NWItems (wp_localize_script)
 */
/* global NWItems, jQuery */
(function ($) {
	'use strict';

	// ── state ──────────────────────────────────────────────────────────────
	let allItems     = [];
	let editingId    = null;
	let filterType   = '';
	let filterRarity = '';

	// ── helpers ────────────────────────────────────────────────────────────
	function notice (msg, isError) {
		$('#nw-notice')
			.text(msg)
			.css('background', isError ? '#5c0000' : '#1a3300')
			.css('color',      isError ? '#ff8080' : '#adff00')
			.show();
		setTimeout(() => $('#nw-notice').hide(), 3500);
	}

	function rarityBadge (r) {
		return `<span class="nw-rarity nw-rarity--${r}">${r}</span>`;
	}

	function typeBadge (t) {
		return `<span class="nw-item-type">${t}</span>`;
	}

	function tagsHtml (tags) {
		if (!tags || !tags.length) return '—';
		return '<div class="nw-tags">' +
			tags.map(t => `<span class="nw-tag">${t}</span>`).join('') +
			'</div>';
	}

	function thumbHtml (url) {
		if (url) return `<img class="nw-item-thumb" src="${url}" alt="" loading="lazy" />`;
		return '<div class="nw-item-thumb--empty">⚙</div>';
	}

	// ── render table ──────────────────────────────────────────────────────
	function renderTable (items) {
		const search = $('#nw-search').val().toLowerCase();
		const filtered = items.filter(item => {
			if (filterType   && item.item_type !== filterType)   return false;
			if (filterRarity && item.rarity    !== filterRarity) return false;
			if (search && !item.name.toLowerCase().includes(search) &&
				!(item.description || '').toLowerCase().includes(search)) return false;
			return true;
		});

		const activeCount = filtered.filter(i => i.is_active).length;
		$('#nw-total').text(filtered.length);
		$('#nw-active-count').text(activeCount);

		if (!filtered.length) {
			$('#nw-items-tbody').html('<tr><td colspan="9" style="text-align:center;color:#555;padding:32px;">No items found.</td></tr>');
			return;
		}

		const rows = filtered.map(item => `
			<tr data-id="${item.id}">
				<td>${thumbHtml(item.image_url)}</td>
				<td><strong>${item.name}</strong>${item.description ? `<br><small style="color:#666">${item.description.slice(0,60)}…</small>` : ''}</td>
				<td>${typeBadge(item.item_type)}</td>
				<td>${rarityBadge(item.rarity)}</td>
				<td>${tagsHtml(item.tags)}</td>
				<td>${item.weight || 0}</td>
				<td>${item.value || 0}</td>
				<td>
					<button class="nw-toggle nw-toggle--${item.is_active ? 'on' : 'off'}" data-id="${item.id}" data-active="${item.is_active ? '1' : '0'}" title="Toggle active">
						${item.is_active ? '✔' : '✖'}
					</button>
				</td>
				<td>
					<button class="nw-action-btn nw-edit-btn" data-id="${item.id}">Edit</button>
				</td>
			</tr>
		`).join('');

		$('#nw-items-tbody').html(rows);
	}

	// ── load all ───────────────────────────────────────────────────────────
	function loadItems () {
		$('#nw-items-tbody').html('<tr><td colspan="9" style="text-align:center;color:#555;padding:32px;">Loading…</td></tr>');
		$.post(NWItems.ajaxurl, {
			action:        'nw_items_get_all',
			nonce:         NWItems.nonce,
			filter_type:   filterType,
			filter_rarity: filterRarity,
		}, function (res) {
			if (!res.success) { notice(res.data || 'Load failed', true); return; }
			allItems = res.data || [];
			renderTable(allItems);
		});
	}

	// ── modal helpers ──────────────────────────────────────────────────────
	function openModal (title, item) {
		$('#nw-modal-title').text(title);
		$('#nw-field-id').val(item ? item.id : '');
		$('#nw-field-name').val(item ? item.name : '');
		$('#nw-field-type').val(item ? item.item_type : 'misc');
		$('#nw-field-rarity').val(item ? item.rarity : 'common');
		$('#nw-field-desc').val(item ? item.description : '');
		$('#nw-field-weight').val(item ? item.weight : 0);
		$('#nw-field-value').val(item ? item.value : 0);
		$('#nw-field-sort').val(item ? item.sort_order : 0);
		$('#nw-field-image').val(item ? (item.image_url || '') : '');
		$('#nw-field-tags').val(item && item.tags ? item.tags.join(', ') : '');
		$('#nw-field-active').prop('checked', item ? item.is_active : true);
		$('#nw-field-properties').val(
			item && item.properties && Object.keys(item.properties).length
				? JSON.stringify(item.properties, null, 2)
				: ''
		);
		$('#nw-delete-btn').toggle(!!item);
		editingId = item ? item.id : null;

		// reset to first tab
		$('.nw-tab').removeClass('active');
		$('.nw-tab[data-tab="basic"]').addClass('active');
		$('.nw-tab-panel').hide();
		$('#nw-tab-basic').show();

		$('#nw-modal-overlay').show();
		$('#nw-field-name').trigger('focus');
	}

	function closeModal () {
		$('#nw-modal-overlay').hide();
		editingId = null;
	}

	// ── events ─────────────────────────────────────────────────────────────
	$(document).on('click', '#nw-add-btn',     () => openModal('Add Item', null));
	$(document).on('click', '#nw-refresh-btn', loadItems);
	$(document).on('click', '#nw-modal-close, #nw-cancel-btn', closeModal);

	$(document).on('click', '#nw-modal-overlay', function (e) {
		if ($(e.target).is('#nw-modal-overlay')) closeModal();
	});

	// filter dropdowns
	$(document).on('change', '#nw-filter-type', function () {
		filterType = $(this).val();
		renderTable(allItems);
	});
	$(document).on('change', '#nw-filter-rarity', function () {
		filterRarity = $(this).val();
		renderTable(allItems);
	});
	$(document).on('input', '#nw-search', () => renderTable(allItems));

	// tab switching
	$(document).on('click', '.nw-tab', function () {
		$('.nw-tab').removeClass('active');
		$(this).addClass('active');
		$('.nw-tab-panel').hide();
		$('#nw-tab-' + $(this).data('tab')).show();
	});

	// edit button
	$(document).on('click', '.nw-edit-btn', function () {
		const id   = $(this).data('id');
		const item = allItems.find(i => i.id == id);
		if (item) { openModal('Edit Item', item); return; }

		// fallback: fetch from server
		$.post(NWItems.ajaxurl, { action: 'nw_items_get_one', nonce: NWItems.nonce, item_id: id },
			res => res.success ? openModal('Edit Item', res.data) : notice(res.data, true)
		);
	});

	// toggle active
	$(document).on('click', '.nw-toggle', function () {
		const $btn  = $(this);
		const id    = $btn.data('id');
		const cur   = $btn.data('active') === 1 || $btn.data('active') === '1';
		const next  = !cur;
		$.post(NWItems.ajaxurl, {
			action:    'nw_items_toggle',
			nonce:     NWItems.nonce,
			item_id:   id,
			is_active: next ? '1' : '0',
		}, function (res) {
			if (!res.success) { notice(res.data, true); return; }
			const item = allItems.find(i => i.id == id);
			if (item) item.is_active = next;
			renderTable(allItems);
		});
	});

	// save
	$(document).on('click', '#nw-save-btn', function () {
		const data = {
			action:     'nw_items_save',
			nonce:      NWItems.nonce,
			item_id:    $('#nw-field-id').val(),
			name:       $('#nw-field-name').val().trim(),
			item_type:  $('#nw-field-type').val(),
			rarity:     $('#nw-field-rarity').val(),
			description:$('#nw-field-desc').val(),
			weight:     $('#nw-field-weight').val(),
			value:      $('#nw-field-value').val(),
			sort_order: $('#nw-field-sort').val(),
			image_url:  $('#nw-field-image').val(),
			tags:       $('#nw-field-tags').val(),
			properties: $('#nw-field-properties').val(),
			is_active:  $('#nw-field-active').is(':checked') ? '1' : '',
		};
		if (!data.name) { notice('Name is required', true); return; }

		$('#nw-save-btn').prop('disabled', true).text('Saving…');
		$.post(NWItems.ajaxurl, data, function (res) {
			$('#nw-save-btn').prop('disabled', false).text('Save');
			if (!res.success) { notice(res.data || 'Save failed', true); return; }
			notice('Saved ✔');
			closeModal();
			loadItems();
		});
	});

	// delete
	$(document).on('click', '#nw-delete-btn', function () {
		if (!editingId) return;
		if (!confirm('Delete this item? This cannot be undone.')) return;
		$.post(NWItems.ajaxurl, {
			action:  'nw_items_delete',
			nonce:   NWItems.nonce,
			item_id: editingId,
		}, function (res) {
			if (!res.success) { notice(res.data, true); return; }
			notice('Deleted.');
			closeModal();
			loadItems();
		});
	});

	// ── init ───────────────────────────────────────────────────────────────
	$(function () { loadItems(); });

}(jQuery));
