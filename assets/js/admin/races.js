/* NeoWeaver Races Admin JS */
/* globals NWRaces, lucide, jQuery */
(function ($) {
	'use strict';

	const A = NWRaces.ajaxurl;
	const N = NWRaces.nonce;

	let allRows = [];

	// ── Lucide ────────────────────────────────────────────────────────────────
	function icons() {
		if (window.lucide) lucide.createIcons();
	}

	// ── Notice ────────────────────────────────────────────────────────────────
	function notice(msg, type = 'success') {
		const n = $('#nw-notice');
		n.removeClass('nw-notice-success nw-notice-error')
			.addClass(type === 'error' ? 'nw-notice-error' : 'nw-notice-success')
			.html(msg).show();
		setTimeout(() => n.fadeOut(), 4000);
	}

	// ── Stats ─────────────────────────────────────────────────────────────────
	function updateStats(rows) {
		$('#nw-total').text(rows.length);
		$('#nw-active').text(rows.filter(r => r.is_active).length);
		$('#nw-inactive').text(rows.filter(r => !r.is_active).length);
		$('#nw-parented').text(rows.filter(r => r.parent_race).length);
	}

	// ── Populate conflict axis filter ─────────────────────────────────────────
	function populateConflictFilter(rows) {
		const axes = [...new Set(rows.map(r => r.conflict_axis).filter(Boolean))].sort();
		const sel = $('#nw-filter-conflict');
		sel.find('option:not(:first)').remove();
		axes.forEach(a => sel.append(`<option value="${a}">${a}</option>`));
	}

	// ── Pref bar mini-display ─────────────────────────────────────────────────
	const PREF_KEYS = ['preferred_tech','preferred_magic','preferred_gods',
	                   'preferred_wealth','preferred_threat','preferred_moral','preferred_social'];

	function prefMini(row) {
		return PREF_KEYS.map(k => {
			const v = row[k] ?? 0;
			const label = k.replace('preferred_', '').charAt(0).toUpperCase();
			const pct   = v * 10;
			return `<span class="nw-pref-mini" title="${k.replace('preferred_','')} ${v}/10">
				<span class="nw-pref-mini-label">${label}</span>
				<span class="nw-pref-mini-track"><span class="nw-pref-mini-fill" style="width:${pct}%"></span></span>
			</span>`;
		}).join('');
	}

	// ── Render table ──────────────────────────────────────────────────────────
	function renderTable(rows) {
		const tbody = $('#nw-races-tbody');
		if (!rows.length) {
			tbody.html(`<tr><td colspan="9" class="nw-empty-row">
				<i data-lucide="inbox" style="width:18px;height:18px;vertical-align:middle;margin-right:6px"></i>
				No races found. Create one!
			</td></tr>`);
			icons();
			return;
		}
		const html = rows.map(r => {
			const img = r.img_url
				? `<img src="${r.img_url}" alt="" style="width:36px;height:36px;object-fit:cover;border-radius:4px" loading="lazy">`
				: `<span class="nw-no-img"><i data-lucide="image-off" style="width:14px;height:14px"></i></span>`;
			const active = r.is_active
				? `<span class="nw-status-dot nw-dot-on"></span>`
				: `<span class="nw-status-dot nw-dot-off"></span>`;
			const parent = r.parent_race
				? `<span class="nw-parent-badge">${r.parent_race}</span>`
				: `<span class="nw-muted">—</span>`;
			const conflict = r.conflict_axis
				? `<span class="nw-conflict-pill">${r.conflict_axis}${r.conflict_side ? ' / ' + r.conflict_side : ''}</span>`
				: `<span class="nw-muted">—</span>`;

			return `<tr data-id="${r.id}">
				<td>${img}</td>
				<td>
					<strong>${r.name ?? '(no name)'}</strong>
					${r.description ? `<br><small class="nw-muted">${r.description.substring(0,60)}${r.description.length > 60 ? '…' : ''}</small>` : ''}
				</td>
				<td>${parent}</td>
				<td style="text-align:center"><span class="nw-stat-val">${r.race_base_hp}</span></td>
				<td style="text-align:center"><span class="nw-stat-val nw-stat-mp">${r.race_base_mp}</span></td>
				<td><div class="nw-prefs-mini-row">${prefMini(r)}</div></td>
				<td>${conflict}</td>
				<td style="text-align:center">${active}</td>
				<td class="nw-actions-cell">
					<button class="nw-btn nw-btn-ghost nw-btn-sm nw-edit-btn" data-id="${r.id}" title="Edit">
						<i data-lucide="pencil" style="width:13px;height:13px"></i>
					</button>
					<button class="nw-btn nw-btn-ghost nw-btn-sm nw-dup-btn" data-id="${r.id}" title="Duplicate">
						<i data-lucide="copy" style="width:13px;height:13px"></i>
					</button>
				</td>
			</tr>`;
		}).join('');
		tbody.html(html);
		icons();
	}

	// ── Filters ───────────────────────────────────────────────────────────────
	function applyFilters() {
		const q        = $('#nw-search').val().toLowerCase();
		const active   = $('#nw-filter-active').val();
		const conflict = $('#nw-filter-conflict').val();
		const hasFilter = q || active !== '' || conflict;
		$('#nw-clear-filters').toggle(!!hasFilter);

		const filtered = allRows.filter(r => {
			if (q && !(r.name || '').toLowerCase().includes(q) && !(r.description || '').toLowerCase().includes(q)) return false;
			if (active !== '') { if (r.is_active !== (active === '1')) return false; }
			if (conflict && r.conflict_axis !== conflict) return false;
			return true;
		});
		renderTable(filtered);
	}

	// ── Load ──────────────────────────────────────────────────────────────────
	function loadRaces() {
		$('#nw-races-tbody').html(`<tr class="nw-loading-row"><td colspan="9"><span class="nw-spinner"></span> Loading races…</td></tr>`);
		$.post(A, { action: 'nw_races_list', nonce: N }, res => {
			if (!res.success) { notice(res.data || 'Load failed.', 'error'); return; }
			allRows = res.data;
			updateStats(allRows);
			populateConflictFilter(allRows);
			applyFilters();
		});
	}

	// ── Modal ─────────────────────────────────────────────────────────────────
	function openModal(row) {
		const isNew = !row;
		$('#nw-modal-title').text(isNew ? 'New Race' : 'Edit Race');
		$('#nw-save-label').text(isNew ? 'Create Race' : 'Save Changes');

		// Reset
		$('#nw-field-id').val('');
		$('#nw-field-name').val('');
		$('#nw-field-parent-race').val('');
		$('#nw-field-description').val('');
		$('#nw-field-img-url').val('');
		$('#nw-field-hp').val(86);
		$('#nw-field-mp').val(67);
		$('#nw-field-conflict-axis').val('');
		$('#nw-field-conflict-side').val('');
		$('#nw-field-tags').val('[]');
		$('#nw-field-bonus').val('');
		$('#nw-field-gm-instructions').val('');
		$('#nw-field-is-active').prop('checked', true);
		$('#nw-img-preview-wrap').hide();
		$('#nw-delete-btn').hide();

		// Default pref values
		const DEFAULTS = { preferred_tech:3, preferred_magic:3, preferred_gods:3,
		                   preferred_wealth:3, preferred_threat:3, preferred_moral:2, preferred_social:3 };
		PREF_KEYS.forEach(k => {
			const v = DEFAULTS[k];
			$(`#nw-field-${k}`).val(v);
			$(`#nw-bar-${k}`).css('width', (v * 10) + '%');
		});

		if (!isNew) {
			$('#nw-field-id').val(row.id);
			$('#nw-field-name').val(row.name);
			$('#nw-field-parent-race').val(row.parent_race || '');
			$('#nw-field-description').val(row.description || '');
			$('#nw-field-img-url').val(row.img_url || '');
			$('#nw-field-hp').val(row.race_base_hp || 86);
			$('#nw-field-mp').val(row.race_base_mp || 67);
			$('#nw-field-conflict-axis').val(row.conflict_axis || '');
			$('#nw-field-conflict-side').val(row.conflict_side || '');
			$('#nw-field-tags').val(JSON.stringify(row.tags || []));
			$('#nw-field-bonus').val(row.bonus ? JSON.stringify(row.bonus) : '');
			$('#nw-field-gm-instructions').val(row.gm_instructions || '');
			$('#nw-field-is-active').prop('checked', !!row.is_active);

			PREF_KEYS.forEach(k => {
				const v = row[k] ?? DEFAULTS[k];
				$(`#nw-field-${k}`).val(v);
				$(`#nw-bar-${k}`).css('width', (v * 10) + '%');
			});

			if (row.img_url) {
				$('#nw-img-preview').attr('src', row.img_url);
				$('#nw-img-preview-wrap').show();
			}
			$('#nw-delete-btn').show().data('id', row.id);
		}

		$('#nw-modal-overlay').fadeIn(160);
		icons();
	}

	function closeModal() {
		$('#nw-modal-overlay').fadeOut(140);
	}

	// ── Save ──────────────────────────────────────────────────────────────────
	function saveRace() {
		const btn  = $('#nw-save-btn');
		const id   = $('#nw-field-id').val().trim();
		const name = $('#nw-field-name').val().trim();
		if (!name) { notice('Name is required.', 'error'); return; }

		// Validate JSON fields
		const tagsRaw  = $('#nw-field-tags').val().trim();
		const bonusRaw = $('#nw-field-bonus').val().trim();
		if (tagsRaw) { try { JSON.parse(tagsRaw); } catch(e) { notice('Tags must be a valid JSON array.', 'error'); return; } }
		if (bonusRaw) { try { JSON.parse(bonusRaw); } catch(e) { notice('Bonus must be a valid JSON object.', 'error'); return; } }

		btn.prop('disabled', true).html('<span class="nw-spinner" style="width:13px;height:13px"></span> Saving…');

		const data = {
			action:            'nw_races_save',
			nonce:             N,
			id:                id,
			name:              name,
			parent_race:       $('#nw-field-parent-race').val(),
			description:       $('#nw-field-description').val(),
			img_url:           $('#nw-field-img-url').val(),
			race_base_hp:      $('#nw-field-hp').val(),
			race_base_mp:      $('#nw-field-mp').val(),
			conflict_axis:     $('#nw-field-conflict-axis').val(),
			conflict_side:     $('#nw-field-conflict-side').val(),
			tags:              tagsRaw || '[]',
			bonus:             bonusRaw,
			gm_instructions:   $('#nw-field-gm-instructions').val(),
			is_active:         $('#nw-field-is-active').is(':checked') ? 1 : 0,
		};
		PREF_KEYS.forEach(k => { data[k] = $(`#nw-field-${k}`).val(); });

		$.post(A, data, res => {
			btn.prop('disabled', false)
				.html('<i data-lucide="save" style="width:13px;height:13px;vertical-align:middle;margin-right:4px"></i><span id="nw-save-label">' + (id ? 'Save Changes' : 'Create Race') + '</span>');
			icons();
			if (!res.success) { notice(res.data || 'Save failed.', 'error'); return; }
			notice(id ? 'Race updated.' : 'Race created!');
			closeModal();
			loadRaces();
		});
	}

	// ── Delete ────────────────────────────────────────────────────────────────
	function deleteRace(id) {
		if (!confirm('Delete this race? This cannot be undone.')) return;
		$.post(A, { action: 'nw_races_delete', nonce: N, id }, res => {
			if (!res.success) { notice(res.data || 'Delete failed.', 'error'); return; }
			notice('Race deleted.');
			closeModal();
			loadRaces();
		});
	}

	// ── Duplicate ─────────────────────────────────────────────────────────────
	function duplicateRace(id) {
		$.post(A, { action: 'nw_races_duplicate', nonce: N, id }, res => {
			if (!res.success) { notice(res.data || 'Duplicate failed.', 'error'); return; }
			notice('Race duplicated.');
			loadRaces();
		});
	}

	// ── Init ──────────────────────────────────────────────────────────────────
	icons();
	loadRaces();

	$('#nw-refresh-btn').on('click', loadRaces);
	$('#nw-add-btn').on('click', () => openModal(null));
	$('#nw-modal-close, #nw-cancel-btn').on('click', closeModal);
	$('#nw-modal-overlay').on('click', e => { if (e.target.id === 'nw-modal-overlay') closeModal(); });
	$('#nw-save-btn').on('click', saveRace);
	$('#nw-race-form').on('submit', e => { e.preventDefault(); saveRace(); });
	$('#nw-delete-btn').on('click', function () { deleteRace($(this).data('id')); });

	$('#nw-races-tbody')
		.on('click', '.nw-edit-btn', function () {
			const id  = $(this).data('id');
			const row = allRows.find(r => r.id === id);
			if (row) openModal(row);
		})
		.on('click', '.nw-dup-btn', function () { duplicateRace($(this).data('id')); });

	$('#nw-search, #nw-filter-active, #nw-filter-conflict').on('input change', applyFilters);
	$('#nw-clear-filters').on('click', () => {
		$('#nw-search').val('');
		$('#nw-filter-active, #nw-filter-conflict').val('');
		applyFilters();
	});

	// Live image preview
	$('#nw-field-img-url').on('input', function () {
		const url = $(this).val().trim();
		if (url) { $('#nw-img-preview').attr('src', url); $('#nw-img-preview-wrap').show(); }
		else { $('#nw-img-preview-wrap').hide(); }
	});

	// Live pref bars
	PREF_KEYS.forEach(k => {
		$(`#nw-field-${k}`).on('input', function () {
			const v = Math.min(10, Math.max(0, parseInt(this.value) || 0));
			$(`#nw-bar-${k}`).css('width', (v * 10) + '%');
		});
	});

}(jQuery));
