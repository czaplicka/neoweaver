/* NeoWeaver Admin — Items JS */
/* global NWItems, jQuery */

(function ($) {
	'use strict';

	const ajax = NWItems.ajaxurl;
	const nonce = NWItems.nonce;

	let allItems = [];
	let archetypeMap = {}; // uuid -> name

	// ── DOM refs ──────────────────────────────────────────────────────────────
	const $tbody        = $('#nw-items-tbody');
	const $search       = $('#nw-search');
	const $filterType   = $('#nw-filter-type');
	const $filterRarity = $('#nw-filter-rarity');
	const $filterSlot   = $('#nw-filter-slot');
	const $overlay      = $('#nw-modal-overlay');
	const $modalTitle   = $('#nw-modal-title');
	const $formNotice   = $('#nw-form-notice');
	const $deleteBtn    = $('#nw-delete-btn');
	const $archetypeSel = $('#nw-field-restricted-archetype');
	const $archLoading  = $('#nw-archetype-loading');

	// ── Rarity colour map ─────────────────────────────────────────────────────
	const rarityColor = {
		common:    '#9ca3af',
		uncommon:  '#4ade80',
		rare:      '#60a5fa',
		epic:      '#c084fc',
		legendary: '#fbbf24',
	};

	// ── Init ──────────────────────────────────────────────────────────────────
	loadItems();
	loadArchetypes();

	$('#nw-refresh-btn').on('click', () => { allItems = []; loadItems(); });
	$('#nw-add-btn').on('click', openAdd);
	$('#nw-modal-close, #nw-cancel-btn').on('click', closeModal);
	$('#nw-save-btn').on('click', saveItem);
	$('#nw-delete-btn').on('click', deleteItem);

	$search.on('input', renderTable);
	$filterType.on('change', renderTable);
	$filterRarity.on('change', renderTable);
	$filterSlot.on('change', renderTable);

	// Image preview
	$('#nw-field-img-url').on('input blur', function () {
		const url = $(this).val().trim();
		if (url) {
			$('#nw-item-image-preview').attr('src', url);
			$('#nw-item-image-preview-wrap').show();
		} else {
			$('#nw-item-image-preview-wrap').hide();
		}
	});

	// ── Load archetypes ───────────────────────────────────────────────────────
	function loadArchetypes() {
		$archLoading.show();
		$.post(ajax, { action: 'nw_items_get_archetypes', nonce })
			.done(function (res) {
				if (res.success && Array.isArray(res.data)) {
					archetypeMap = {};
					res.data.forEach(a => { archetypeMap[a.id] = a.name; });
					populateArchetypeSelect(res.data);
				}
			})
			.always(() => $archLoading.hide());
	}

	function populateArchetypeSelect(archetypes) {
		$archetypeSel.find('option:not(:first)').remove();
		archetypes.forEach(a => {
			$archetypeSel.append(
				$('<option>').val(a.id).text(a.name)
			);
		});
	}

	// ── Load items ────────────────────────────────────────────────────────────
	function loadItems() {
		$tbody.html('<tr><td colspan="10" style="text-align:center;padding:32px;">Loading…</td></tr>');

		$.post(ajax, { action: 'nw_items_load', nonce })
			.done(function (res) {
				if (!res.success) { showNotice(res.data || 'Load failed', 'error'); return; }
				allItems = res.data || [];
				buildTypeFilter();
				renderTable();
			})
			.fail(() => showNotice('Request failed', 'error'));
	}

	function buildTypeFilter() {
		const types = [...new Set(allItems.map(i => i.type).filter(Boolean))].sort();
		$filterType.find('option:not(:first)').remove();
		types.forEach(t => $filterType.append($('<option>').val(t).text(t)));
	}

	// ── Render table ──────────────────────────────────────────────────────────
	function renderTable() {
		const q       = $search.val().toLowerCase();
		const typeVal = $filterType.val();
		const rarVal  = $filterRarity.val();
		const slotVal = $filterSlot.val();

		const filtered = allItems.filter(item => {
			if (q       && !(item.name || '').toLowerCase().includes(q)) return false;
			if (typeVal && item.type !== typeVal)   return false;
			if (rarVal  && item.rarity !== rarVal)  return false;
			if (slotVal && item.slot !== slotVal)   return false;
			return true;
		});

		$('#nw-total').text(allItems.length);
		$('#nw-active-count').text(allItems.filter(i => i.is_active).length);
		$('#nw-restricted-count').text(allItems.filter(i => i.restricted_to_archetype).length);

		if (!filtered.length) {
			$tbody.html('<tr><td colspan="10" style="text-align:center;padding:32px;color:#888;">No items found.</td></tr>');
			return;
		}

		const rows = filtered.map(item => {
			const imgHtml = item.img_url
				? `<img src="${escHtml(item.img_url)}" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:6px;border:1px solid #2b2b2b;">`
				: '<span style="color:#555;font-size:10px;">—</span>';

			const rarColor = rarityColor[item.rarity] || '#888';
			const rarBadge = `<span style="color:${rarColor};font-weight:600;text-transform:capitalize;">${escHtml(item.rarity)}</span>`;

			const archName = item.restricted_to_archetype
				? (archetypeMap[item.restricted_to_archetype]
					? `<span class="nw-badge nw-badge-purple">${escHtml(archetypeMap[item.restricted_to_archetype])}</span>`
					: `<span class="nw-badge nw-badge-warn" title="${escHtml(item.restricted_to_archetype)}">unknown UUID</span>`)
				: '<span style="color:#555;font-size:11px;">all</span>';

			const activeToggle = `<label class="nw-toggle">
				<input type="checkbox" data-id="${escHtml(item.id)}" class="nw-toggle-active" ${item.is_active ? 'checked' : ''}>
				<span class="nw-toggle-slider"></span>
			</label>`;

			return `<tr>
				<td>${imgHtml}</td>
				<td><strong>${escHtml(item.name)}</strong></td>
				<td>${escHtml(item.type || '—')}</td>
				<td>${rarBadge}</td>
				<td><span class="nw-badge nw-badge-slot">${escHtml(item.slot || '—')}</span></td>
				<td>${escHtml(item.size || '—')}</td>
				<td>${item.price ?? 0}</td>
				<td>${archName}</td>
				<td>${activeToggle}</td>
				<td>
					<button class="button button-small nw-edit-btn" data-id="${escHtml(item.id)}">Edit</button>
				</td>
			</tr>`;
		});

		$tbody.html(rows.join(''));

		// Bind events
		$tbody.find('.nw-edit-btn').on('click', function () {
			openEdit($(this).data('id'));
		});
		$tbody.find('.nw-toggle-active').on('change', function () {
			toggleItem($(this).data('id'), this.checked);
		});
	}

	// ── Modal open/close ──────────────────────────────────────────────────────
	function openAdd() {
		resetForm();
		$modalTitle.text('Add Item');
		$deleteBtn.hide();
		$overlay.show();
	}

	function openEdit(id) {
		resetForm();
		$modalTitle.text('Edit Item');
		$deleteBtn.show().data('id', id);
		$formNotice.html('<em style="color:#888;">Loading…</em>');

		$.post(ajax, { action: 'nw_items_get', nonce, id })
			.done(function (res) {
				$formNotice.html('');
				if (!res.success || !res.data) { $formNotice.html('<span style="color:#f87171;">Item not found.</span>'); return; }
				fillForm(res.data);
			})
			.fail(() => $formNotice.html('<span style="color:#f87171;">Request failed.</span>'));

		$overlay.show();
	}

	function closeModal() {
		$overlay.hide();
		resetForm();
	}

	// ── Form helpers ──────────────────────────────────────────────────────────
	function resetForm() {
		$('#nw-field-id').val('');
		$('#nw-field-name').val('');
		$('#nw-field-description').val('');
		$('#nw-field-type').val('');
		$('#nw-field-rarity').val('common');
		$('#nw-field-slot').val('none');
		$('#nw-field-size').val('medium');
		$('#nw-field-price').val(0);
		$('#nw-field-power-value').val(0);
		$('#nw-field-mass').val(1);
		$('#nw-field-stack-limit').val(1);
		$('#nw-field-img-url').val('');
		$('#nw-field-sound-url').val('');
		$('#nw-field-tags').val('');
		$('#nw-field-min-tech').val(0);
		$('#nw-field-min-magic').val(0);
		$('#nw-field-min-wealth').val(0);
		$('#nw-field-restricted-archetype').val('');
		$('#nw-field-is-container').prop('checked', false);
		$('#nw-field-active').prop('checked', true);
		$('#nw-item-image-preview-wrap').hide();
		$formNotice.html('');
	}

	function fillForm(item) {
		$('#nw-field-id').val(item.id || '');
		$('#nw-field-name').val(item.name || '');
		$('#nw-field-description').val(item.description || '');
		$('#nw-field-type').val(item.type || '');
		$('#nw-field-rarity').val(item.rarity || 'common');
		$('#nw-field-slot').val(item.slot || 'none');
		$('#nw-field-size').val(item.size || 'medium');
		$('#nw-field-price').val(item.price ?? 0);
		$('#nw-field-power-value').val(item.power_value ?? 0);
		$('#nw-field-mass').val(item.mass ?? 1);
		$('#nw-field-stack-limit').val(item.stack_limit ?? 1);
		$('#nw-field-img-url').val(item.img_url || '');
		$('#nw-field-sound-url').val(item.sound_url || '');

		// Tags: jsonb array → comma string
		const tags = Array.isArray(item.tags) ? item.tags.join(', ') : (item.tags || '');
		$('#nw-field-tags').val(tags);

		$('#nw-field-min-tech').val(item.min_kingdom_tech ?? 0);
		$('#nw-field-min-magic').val(item.min_kingdom_magic ?? 0);
		$('#nw-field-min-wealth').val(item.min_kingdom_wealth ?? 0);
		$('#nw-field-is-container').prop('checked', !!item.is_container);
		$('#nw-field-active').prop('checked', item.is_active !== false);

		// Archetype dropdown
		const arch = item.restricted_to_archetype || '';
		if (arch && $archetypeSel.find(`option[value="${arch}"]`).length === 0) {
			// UUID exists but not in dropdown (e.g. archetype deleted) — add temp option
			$archetypeSel.append($('<option>').val(arch).text(`[${arch.substring(0, 8)}…]`));
		}
		$archetypeSel.val(arch);

		// Image preview
		if (item.img_url) {
			$('#nw-item-image-preview').attr('src', item.img_url);
			$('#nw-item-image-preview-wrap').show();
		}
	}

	// ── Save ──────────────────────────────────────────────────────────────────
	function saveItem() {
		const id = $('#nw-field-id').val().trim();

		const data = {
			action:                  'nw_items_save',
			nonce,
			id,
			name:                    $('#nw-field-name').val().trim(),
			description:             $('#nw-field-description').val().trim(),
			type:                    $('#nw-field-type').val().trim(),
			rarity:                  $('#nw-field-rarity').val(),
			slot:                    $('#nw-field-slot').val(),
			size:                    $('#nw-field-size').val(),
			price:                   $('#nw-field-price').val(),
			power_value:             $('#nw-field-power-value').val(),
			mass:                    $('#nw-field-mass').val(),
			stack_limit:             $('#nw-field-stack-limit').val(),
			img_url:                 $('#nw-field-img-url').val().trim(),
			sound_url:               $('#nw-field-sound-url').val().trim(),
			tags:                    $('#nw-field-tags').val().trim(),
			min_kingdom_tech:        $('#nw-field-min-tech').val(),
			min_kingdom_magic:       $('#nw-field-min-magic').val(),
			min_kingdom_wealth:      $('#nw-field-min-wealth').val(),
			restricted_to_archetype: $archetypeSel.val() || '',
			is_container:            $('#nw-field-is-container').is(':checked') ? '1' : '0',
			is_active:               $('#nw-field-active').is(':checked') ? '1' : '0',
		};

		if (!data.name) { $formNotice.html('<span style="color:#f87171;">Name is required.</span>'); return; }

		$('#nw-save-btn').prop('disabled', true).text('Saving…');

		$.post(ajax, data)
			.done(function (res) {
				if (!res.success) { $formNotice.html(`<span style="color:#f87171;">${escHtml(res.data || 'Save failed.')}</span>`); return; }
				showNotice(id ? 'Item updated.' : 'Item created.', 'success');
				closeModal();
				allItems = [];
				loadItems();
			})
			.fail(() => $formNotice.html('<span style="color:#f87171;">Request failed.</span>'))
			.always(() => $('#nw-save-btn').prop('disabled', false).text('Save Item'));
	}

	// ── Toggle ────────────────────────────────────────────────────────────────
	function toggleItem(id, is_active) {
		$.post(ajax, { action: 'nw_items_toggle', nonce, id, is_active: is_active ? '1' : '0' })
			.done(function (res) {
				if (!res.success) { showNotice(res.data || 'Toggle failed', 'error'); loadItems(); return; }
				const idx = allItems.findIndex(i => i.id === id);
				if (idx !== -1) allItems[idx].is_active = is_active;
				$('#nw-active-count').text(allItems.filter(i => i.is_active).length);
			})
			.fail(() => { showNotice('Request failed', 'error'); loadItems(); });
	}

	// ── Delete ────────────────────────────────────────────────────────────────
	function deleteItem() {
		const id = $deleteBtn.data('id');
		if (!id) return;
		if (!confirm('Delete this item? This cannot be undone.')) return;

		$.post(ajax, { action: 'nw_items_delete', nonce, id })
			.done(function (res) {
				if (!res.success) { $formNotice.html(`<span style="color:#f87171;">${escHtml(res.data || 'Delete failed.')}</span>`); return; }
				showNotice('Item deleted.', 'success');
				closeModal();
				allItems = [];
				loadItems();
			})
			.fail(() => $formNotice.html('<span style="color:#f87171;">Request failed.</span>'));
	}

	// ── Utils ─────────────────────────────────────────────────────────────────
	function showNotice(msg, type) {
		const $n = $('#nw-notice');
		$n.text(msg)
			.css({
				display:    'block',
				background: type === 'success' ? '#14532d' : '#7f1d1d',
				color:      '#fff',
				border:     type === 'success' ? '1px solid #16a34a' : '1px solid #dc2626',
			});
		setTimeout(() => $n.fadeOut(), 3500);
	}

	function escHtml(str) {
		return String(str ?? '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

})(jQuery);
