/* NeoWeaver — Containers Admin JS */
/* globals NWContainers, lucide, jQuery */
(function ($) {
	'use strict';

	const A    = NWContainers.ajaxurl;
	const N    = NWContainers.nonce;
	let allRows  = [];
	let allItems = []; // cyber_items with is_container=true

	// ── Lucide ────────────────────────────────────────────────────────────────
	function icons() {
		if (window.lucide) lucide.createIcons();
	}

	// ── Notice ────────────────────────────────────────────────────────────────
	function notice(msg, type = 'success') {
		const $n = $('#nw-notice');
		$n.removeClass('nw-notice-success nw-notice-error')
			.addClass(type === 'error' ? 'nw-notice-error' : 'nw-notice-success')
			.html(msg).show();
		setTimeout(() => $n.fadeOut(), 4000);
	}

	// ── Stats ─────────────────────────────────────────────────────────────────
	function updateStats(rows) {
		$('#nw-total').text(rows.length);
		$('#nw-active').text(rows.filter(r => r.is_active).length);
		$('#nw-inactive').text(rows.filter(r => !r.is_active).length);
		$('#nw-rareplus').text(rows.filter(r => ['rare','epic','legendary'].includes(r.rarity)).length);
	}

	// ── Rarity badge ─────────────────────────────────────────────────────────
	const RARITY_CLASS = { common:'nw-pill-common', uncommon:'nw-pill-uncommon', rare:'nw-pill-rare', epic:'nw-pill-epic', legendary:'nw-pill-legendary' };

	function rarityBadge(r) {
		return `<span class="nw-pill ${RARITY_CLASS[r] || ''}">${r || 'common'}</span>`;
	}

	// ── Item name lookup ──────────────────────────────────────────────────────
	function itemName(parentId) {
		if (!parentId) return '<span class="nw-muted">—</span>';
		const item = allItems.find(i => i.id === parentId);
		if (item) return `<span class="nw-linked-item"><i data-lucide="package" style="width:12px;height:12px;vertical-align:middle;margin-right:4px;"></i>${item.name}</span>`;
		return `<span class="nw-muted" title="${parentId}">item #${parentId.slice(0,8)}…</span>`;
	}

	// ── Render table ──────────────────────────────────────────────────────────
	function renderTable(rows) {
		const $tbody = $('#nw-containers-tbody');
		if (!rows.length) {
			$tbody.html('<tr><td colspan="8" class="nw-empty-row"><i data-lucide="inbox" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;"></i>No containers found. Create one!</td></tr>');
			icons();
			return;
		}
		const html = rows.map(r => {
			const sizes = Array.isArray(r.allowed_sizes) ? r.allowed_sizes.join(', ') : (r.allowed_sizes || '—');
			const img   = r.img_url
				? `<img src="${r.img_url}" alt="" style="width:36px;height:36px;object-fit:cover;border-radius:4px;">`
				: `<span class="nw-no-img"><i data-lucide="image-off" style="width:14px;height:14px;"></i></span>`;
			const active = r.is_active
				? '<span class="nw-status-dot nw-dot-on"></span>'
				: '<span class="nw-status-dot nw-dot-off"></span>';
			return `<tr data-id="${r.id}">
				<td>${img}</td>
				<td><strong>${r.name}</strong>${r.description ? `<br><small class="nw-muted">${r.description.substring(0,60)}${r.description.length>60?'…':''}</small>` : ''}</td>
				<td><span class="nw-sizes-text">${sizes}</span></td>
				<td style="text-align:center;">${r.total_slots}</td>
				<td>${rarityBadge(r.rarity)}</td>
				<td>${itemName(r.parent_id)}</td>
				<td style="text-align:center;">${active}</td>
				<td class="nw-actions-cell">
					<button class="nw-btn nw-btn-ghost nw-btn-sm nw-edit-btn" data-id="${r.id}" title="Edit">
						<i data-lucide="pencil" style="width:13px;height:13px;"></i>
					</button>
					<button class="nw-btn nw-btn-ghost nw-btn-sm nw-dup-btn" data-id="${r.id}" title="Duplicate">
						<i data-lucide="copy" style="width:13px;height:13px;"></i>
					</button>
				</td>
			</tr>`;
		}).join('');
		$tbody.html(html);
		icons();
	}

	// ── Filter ────────────────────────────────────────────────────────────────
	function applyFilters() {
		const q       = $('#nw-search').val().toLowerCase();
		const active  = $('#nw-filter-active').val();
		const rarity  = $('#nw-filter-rarity').val();
		const size    = $('#nw-filter-size').val();
		const hasFilter = q || active !== '' || rarity || size;
		$('#nw-clear-filters').toggle(!!hasFilter);

		const filtered = allRows.filter(r => {
			if (q && !((r.name||'').toLowerCase().includes(q) || (r.description||'').toLowerCase().includes(q))) return false;
			if (active !== '') {
				const a = (active === '1');
				if (r.is_active !== a) return false;
			}
			if (rarity && r.rarity !== rarity) return false;
			if (size) {
				const sizes = Array.isArray(r.allowed_sizes) ? r.allowed_sizes : [];
				if (!sizes.includes(size)) return false;
			}
			return true;
		});
		renderTable(filtered);
	}

	// ── Load data ─────────────────────────────────────────────────────────────
	function loadContainers() {
		$('#nw-containers-tbody').html('<tr class="nw-loading-row"><td colspan="8"><span class="nw-spinner"></span> Loading containers…</td></tr>');
		$.post(A, { action: 'nw_containers_load', nonce: N }, function (res) {
			if (!res.success) { notice(res.data || 'Load failed.', 'error'); return; }
			allRows = res.data || [];
			updateStats(allRows);
			applyFilters();
		});
	}

	function loadItems() {
		$('#nw-items-loading').show();
		$.post(A, { action: 'nw_containers_get_items', nonce: N }, function (res) {
			$('#nw-items-loading').hide();
			if (!res.success) return;
			allItems = res.data || [];
			const $sel = $('#nw-field-parent_id');
			const current = $sel.val();
			$sel.find('option:not(:first)').remove();
			allItems.forEach(item => {
				$sel.append(`<option value="${item.id}">${item.name} — ${item.rarity || 'common'}</option>`);
			});
			if (current) $sel.val(current);
			icons();
		});
	}

	// ── Modal ─────────────────────────────────────────────────────────────────
	function openModal(row) {
		const isNew = !row;
		$('#nw-modal-title').text(isNew ? 'New Container' : 'Edit Container');
		$('#nw-save-label').text(isNew ? 'Create Container' : 'Save Changes');

		// Reset form
		$('#nw-field-id').val('');
		$('#nw-field-name').val('');
		$('#nw-field-description').val('');
		$('#nw-field-total_slots').val(5);
		$('#nw-field-rarity').val('common');
		$('#nw-field-allowed_sizes').val('tiny, small, medium, large');
		$('#nw-field-img_url').val('');
		$('#nw-field-is_active').prop('checked', true);
		$('#nw-field-parent_id').val('');
		$('#nw-field-delete_item').prop('checked', false);
		$('#nw-img-preview-wrap').hide();
		$('#nw-delete-btn').hide();
		$('#nw-delete-item-row').hide();

		if (isNew) {
			// New: show info box, hide item dropdown
			$('#nw-create-item-wrap').show();
			$('#nw-existing-item-wrap').hide();
		} else {
			// Edit: hide info box, show item dropdown
			$('#nw-create-item-wrap').hide();
			$('#nw-existing-item-wrap').show();
			loadItems(); // refresh dropdown

			$('#nw-field-id').val(row.id);
			$('#nw-field-name').val(row.name || '');
			$('#nw-field-description').val(row.description || '');
			$('#nw-field-total_slots').val(row.total_slots || 5);
			$('#nw-field-rarity').val(row.rarity || 'common');
			const sizes = Array.isArray(row.allowed_sizes) ? row.allowed_sizes.join(', ') : (row.allowed_sizes || '');
			$('#nw-field-allowed_sizes').val(sizes);
			$('#nw-field-img_url').val(row.img_url || '');
			$('#nw-field-is_active').prop('checked', !!row.is_active);
			$('#nw-field-parent_id').val(row.parent_id || '');

			$('#nw-delete-btn').show().data('id', row.id);
			$('#nw-delete-item-row').show();

			if (row.img_url) {
				$('#nw-img-preview').attr('src', row.img_url);
				$('#nw-img-preview-wrap').show();
			}
		}

		$('#nw-modal-overlay').fadeIn(160);
		icons();
	}

	function closeModal() {
		$('#nw-modal-overlay').fadeOut(140);
	}

	// ── Save ──────────────────────────────────────────────────────────────────
	function saveContainer() {
		const $btn = $('#nw-save-btn');
		const id   = $('#nw-field-id').val().trim();
		const name = $('#nw-field-name').val().trim();
		if (!name) { notice('Name is required.', 'error'); return; }

		$btn.prop('disabled', true).html('<span class="nw-spinner" style="width:13px;height:13px;"></span> Saving…');

		$.post(A, {
			action        : 'nw_containers_save',
			nonce         : N,
			id            : id,
			name          : name,
			description   : $('#nw-field-description').val(),
			total_slots   : $('#nw-field-total_slots').val(),
			rarity        : $('#nw-field-rarity').val(),
			allowed_sizes : $('#nw-field-allowed_sizes').val(),
			img_url       : $('#nw-field-img_url').val(),
			is_active     : $('#nw-field-is_active').is(':checked') ? 1 : 0,
			parent_id     : id ? $('#nw-field-parent_id').val() : '',
		}, function (res) {
			$btn.prop('disabled', false).html('<i data-lucide="save" style="width:13px;height:13px;vertical-align:middle;margin-right:4px;"></i><span id="nw-save-label">' + (id ? 'Save Changes' : 'Create Container') + '</span>');
			icons();
			if (!res.success) { notice(res.data || 'Save failed.', 'error'); return; }
			notice(id ? 'Container updated.' : 'Container + linked item created!');
			closeModal();
			loadContainers();
		});
	}

	// ── Delete ────────────────────────────────────────────────────────────────
	function deleteContainer(id) {
		const deleteItem = $('#nw-field-delete_item').is(':checked') ? 1 : 0;
		if (!confirm('Delete this container?' + (deleteItem ? '\n\nThis will also delete the linked item.' : ''))) return;
		$.post(A, { action: 'nw_containers_delete', nonce: N, id: id, delete_item: deleteItem }, function (res) {
			if (!res.success) { notice(res.data || 'Delete failed.', 'error'); return; }
			notice('Container deleted.');
			closeModal();
			loadContainers();
		});
	}

	// ── Duplicate ─────────────────────────────────────────────────────────────
	function duplicateContainer(id) {
		$.post(A, { action: 'nw_containers_duplicate', nonce: N, id: id }, function (res) {
			if (!res.success) { notice(res.data || 'Duplicate failed.', 'error'); return; }
			notice('Container duplicated (+ new linked item created).');
			loadContainers();
		});
	}

	// ── Init ──────────────────────────────────────────────────────────────────
	$(function () {
		icons();
		loadContainers();

		$('#nw-refresh-btn').on('click', loadContainers);
		$('#nw-add-btn').on('click', () => openModal(null));
		$('#nw-modal-close, #nw-cancel-btn').on('click', closeModal);
		$('#nw-modal-overlay').on('click', function (e) { if ($(e.target).is('#nw-modal-overlay')) closeModal(); });

		$('#nw-save-btn').on('click', saveContainer);

		$('#nw-container-form').on('submit', function (e) { e.preventDefault(); saveContainer(); });

		$('#nw-delete-btn').on('click', function () { deleteContainer($(this).data('id')); });

		$('#nw-containers-tbody').on('click', '.nw-edit-btn', function () {
			const id  = $(this).data('id');
			const row = allRows.find(r => r.id === id);
			if (row) openModal(row);
		});

		$('#nw-containers-tbody').on('click', '.nw-dup-btn', function () {
			duplicateContainer($(this).data('id'));
		});

		$('#nw-search, #nw-filter-active, #nw-filter-rarity, #nw-filter-size').on('input change', applyFilters);
		$('#nw-clear-filters').on('click', function () {
			$('#nw-search').val('');
			$('#nw-filter-active, #nw-filter-rarity, #nw-filter-size').val('');
			applyFilters();
		});

		$('#nw-field-img_url').on('input', function () {
			const url = $(this).val().trim();
			if (url) {
				$('#nw-img-preview').attr('src', url);
				$('#nw-img-preview-wrap').show();
			} else {
				$('#nw-img-preview-wrap').hide();
			}
		});
	});
}(jQuery));
