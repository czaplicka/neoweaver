/* NeoWeaver Seasons Config Admin JS */
/* globals NWSeasons, lucide, jQuery */
(function ($) {
	'use strict';

	const A = NWSeasons.ajaxurl;
	const N = NWSeasons.nonce;
	let allRows = [];

	const WEATHERS = [
		{ key: 'sun',    label: 'Sun',    icon: '☀️', color: '#facc15' },
		{ key: 'cloudy', label: 'Cloudy', icon: '🌥️', color: '#94a3b8' },
		{ key: 'rain',   label: 'Rain',   icon: '🌧️', color: '#60a5fa' },
		{ key: 'fog',    label: 'Fog',    icon: '🌫️', color: '#a1a1aa' },
		{ key: 'storm',  label: 'Storm',  icon: '⛈️', color: '#f87171' },
		{ key: 'snow',   label: 'Snow',   icon: '❄️', color: '#bae6fd' },
	];

	function icons() { if (window.lucide) lucide.createIcons(); }

	function notice(msg, type = 'success') {
		const n = $('#nw-notice');
		n.removeClass('nw-notice-success nw-notice-error')
			.addClass(type === 'error' ? 'nw-notice-error' : 'nw-notice-success')
			.html(msg).show();
		setTimeout(() => n.fadeOut(), 4000);
	}

	function updateStats(rows) { $('#nw-total').text(rows.length); }

	function weightsBar(row) {
		return WEATHERS.map(w => {
			const v = parseInt(row['weight_' + w.key] || 0, 10);
			if (!v) return '';
			return `<span class="nw-wbar-seg" style="width:${v}%;background:${w.color};opacity:.75" title="${w.icon} ${w.label}: ${v}%"></span>`;
		}).join('');
	}

	function renderTable(rows) {
		const tbody = $('#nw-seasons-tbody');
		if (!rows.length) {
			tbody.html(`<tr><td colspan="6" class="nw-empty-row"><i data-lucide="inbox" style="width:18px;height:18px;vertical-align:middle;margin-right:6px"></i>No seasons yet.</td></tr>`);
			icons(); return;
		}
		const html = rows.map((r, i) => {
			const icon = r.icon || '';
			const nameLabel = `<span style="color:${r.color || '#adff00'}">${icon ? icon + ' ' : ''}${r.name || r.season_name}</span>`;
			const weightChips = WEATHERS.map(w => {
				const v = parseInt(r['weight_' + w.key] || 0, 10);
				if (!v) return '';
				return `<span class="nw-wchip" style="border-color:${w.color}30;color:${w.color}">${w.icon} ${v}%</span>`;
			}).join('');
			return `<tr data-id="${r.season_name}" draggable="true">
				<td class="nw-drag-handle" title="Drag to reorder"><i data-lucide="grip-vertical" style="width:13px;height:13px;color:#555"></i></td>
				<td>
					<strong>${nameLabel}</strong>
					${r.description ? `<br><small class="nw-muted">${r.description.substring(0,60)}…</small>` : ''}
				</td>
				<td>
					<div class="nw-wchips-row">${weightChips}</div>
					<div class="nw-wbar-wrap">${weightsBar(r)}</div>
				</td>
				<td><span class="nw-temp-pill">×${parseFloat(r.temp_modifier).toFixed(2)}</span></td>
				<td>${r.sort_order ?? 0}</td>
				<td class="nw-actions-cell">
					<button class="nw-btn nw-btn-ghost nw-btn-sm nw-edit-btn" data-id="${r.season_name}" title="Edit"><i data-lucide="pencil" style="width:13px;height:13px"></i></button>
					<button class="nw-btn nw-btn-ghost nw-btn-sm nw-delete-quick-btn" data-id="${r.season_name}" title="Delete"><i data-lucide="trash-2" style="width:13px;height:13px;color:#f87171"></i></button>
				</td>
			</tr>`;
		}).join('');
		tbody.html(html);
		icons();
		initDragDrop();
	}

	function applyFilters() {
		const q = $('#nw-search').val().toLowerCase();
		renderTable(q ? allRows.filter(r => r.season_name.toLowerCase().includes(q)) : allRows);
	}

	function loadSeasons() {
		$('#nw-seasons-tbody').html(`<tr class="nw-loading-row"><td colspan="6"><span class="nw-spinner"></span> Loading seasons…</td></tr>`);
		$.post(A, { action: 'nw_seasons_load', nonce: N }, res => {
			if (!res.success) { notice(res.data || 'Load failed.', 'error'); return; }
			allRows = res.data || [];
			updateStats(allRows);
			applyFilters();
		});
	}

	/* ---------- weights live feedback ---------- */
	function updateWeightsTotal() {
		let total = 0;
		WEATHERS.forEach(w => { total += parseInt($('#nw-field-' + w.key).val() || 0, 10); });
		$('#nw-weights-total').text(total);
		const ok = total === 100;
		$('#nw-weights-total').css('color', ok ? '#adff00' : '#f87171');
		$('#nw-weights-warning').toggle(!ok);
		/* bar preview */
		let html = '';
		WEATHERS.forEach(w => {
			const v = parseInt($('#nw-field-' + w.key).val() || 0, 10);
			if (v > 0) html += `<span class="nw-wbar-seg" style="width:${v}%;background:${w.color}" title="${w.label}: ${v}%"></span>`;
		});
		$('#nw-weights-bar').html(html);
		return ok;
	}

	/* ---------- modal ---------- */
	function resetForm() {
		$('#nw-field-original-name').val('');
		$('#nw-field-name').val('');
		$('#nw-field-description').val('');
		$('#nw-field-icon').val('');
		$('#nw-field-color').val('');
		$('#nw-field-color-picker').val('#adff00');
		$('#nw-field-temp').val('1.00');
		$('#nw-field-sort').val(0);
		$('#nw-field-sun').val(25);
		$('#nw-field-cloudy').val(25);
		$('#nw-field-rain').val(25);
		$('#nw-field-fog').val(25);
		$('#nw-field-storm').val(0);
		$('#nw-field-snow').val(0);
		updateWeightsTotal();
		$('#nw-delete-btn').hide();
	}

	function openModal(row) {
		const isNew = !row;
		$('#nw-modal-title').text(isNew ? 'New Season' : 'Edit Season');
		$('#nw-save-label').text(isNew ? 'Create Season' : 'Save Changes');
		resetForm();

		if (!isNew) {
			$('#nw-field-original-name').val(row.season_name);
			$('#nw-field-name').val(row.season_name);
			$('#nw-field-description').val(row.description || '');
			$('#nw-field-icon').val(row.icon || '');
			$('#nw-field-color').val(row.color || '');
			$('#nw-field-color-picker').val(row.color || '#adff00');
			$('#nw-field-temp').val(parseFloat(row.temp_modifier).toFixed(2));
			$('#nw-field-sort').val(row.sort_order ?? 0);
			WEATHERS.forEach(w => $('#nw-field-' + w.key).val(row['weight_' + w.key] ?? 0));
			updateWeightsTotal();
			$('#nw-delete-btn').show().data('id', row.season_name);
		}
		$('#nw-modal-overlay').fadeIn(160);
		icons();
	}

	function closeModal() { $('#nw-modal-overlay').fadeOut(140); }

	function saveSeason() {
		const btn = $('#nw-save-btn');
		const name = $('#nw-field-name').val().trim();
		if (!name) { notice('Season name is required.', 'error'); return; }
		if (!updateWeightsTotal()) { notice('Weather weights must sum to 100.', 'error'); return; }

		btn.prop('disabled', true).html('<span class="nw-spinner" style="width:13px;height:13px"></span> Saving…');

		const data = { action: 'nw_seasons_save', nonce: N,
			season_name: name,
			original_name: $('#nw-field-original-name').val(),
			description: $('#nw-field-description').val(),
			icon: $('#nw-field-icon').val(),
			color: $('#nw-field-color').val(),
			temp_modifier: $('#nw-field-temp').val(),
			sort_order: $('#nw-field-sort').val()
		};
		WEATHERS.forEach(w => { data['weight_' + w.key] = $('#nw-field-' + w.key).val(); });

		$.post(A, data, res => {
			btn.prop('disabled', false).html('<i data-lucide="save" style="width:13px;height:13px;vertical-align:middle;margin-right:4px"></i><span id="nw-save-label">Save Changes</span>');
			icons();
			if (!res.success) { notice(res.data || 'Save failed.', 'error'); return; }
			notice($('#nw-field-original-name').val() ? 'Season updated.' : 'Season created!');
			closeModal();
			loadSeasons();
		});
	}

	function deleteSeason(name) {
		if (!confirm('Delete "' + name + '"? Cannot be undone.')) return;
		$.post(A, { action: 'nw_seasons_delete', nonce: N, season_name: name }, res => {
			if (!res.success) { notice(res.data || 'Delete failed.', 'error'); return; }
			notice('Season deleted.');
			closeModal();
			loadSeasons();
		});
	}

	/* ---------- drag-drop reorder ---------- */
	let dragSrc = null;
	function initDragDrop() {
		const rows = document.querySelectorAll('#nw-seasons-tbody tr[data-id]');
		rows.forEach(row => {
			row.addEventListener('dragstart', e => { dragSrc = row; row.classList.add('is-dragging'); });
			row.addEventListener('dragend',   () => row.classList.remove('is-dragging'));
			row.addEventListener('dragover',  e => { e.preventDefault(); row.classList.add('drag-over'); });
			row.addEventListener('dragleave', () => row.classList.remove('drag-over'));
			row.addEventListener('drop', e => {
				e.preventDefault();
				row.classList.remove('drag-over');
				if (!dragSrc || dragSrc === row) return;
				const tbody = row.parentNode;
				const allTrs = [...tbody.querySelectorAll('tr[data-id]')];
				const srcIdx = allTrs.indexOf(dragSrc);
				const dstIdx = allTrs.indexOf(row);
				if (srcIdx < dstIdx) row.after(dragSrc); else row.before(dragSrc);
				/* send new order */
				const updated = [...tbody.querySelectorAll('tr[data-id]')].map((tr, i) => ({
					season_name: tr.dataset.id, sort_order: i
				}));
				$.post(A, { action: 'nw_seasons_reorder', nonce: N, items: JSON.stringify(updated) }, res => {
					if (res.success) loadSeasons();
				});
			});
		});
	}

	/* ---------- events ---------- */
	icons();
	loadSeasons();

	$('#nw-refresh-btn').on('click', loadSeasons);
	$('#nw-add-btn').on('click', () => openModal(null));
	$('#nw-modal-close, #nw-cancel-btn').on('click', closeModal);
	$('#nw-modal-overlay').on('click', e => { if (e.target.id === 'nw-modal-overlay') closeModal(); });
	$('#nw-save-btn').on('click', saveSeason);
	$('#nw-season-form').on('submit', e => { e.preventDefault(); saveSeason(); });
	$('#nw-delete-btn').on('click', function () { deleteSeason($(this).data('id')); });

	$('#nw-seasons-tbody')
		.on('click', '.nw-edit-btn', function () {
			const id = $(this).data('id');
			openModal(allRows.find(r => r.season_name === id));
		})
		.on('click', '.nw-delete-quick-btn', function () { deleteSeason($(this).data('id')); });

	$('#nw-search').on('input', applyFilters);

	$('.nw-weight-input').on('input', updateWeightsTotal);
	$(document).on('input', '.nw-weight-input', updateWeightsTotal);

	$('#nw-field-color-picker').on('input', function () { $('#nw-field-color').val($(this).val()); });
	$('#nw-field-color').on('input', function () {
		const v = $(this).val().trim();
		if (/^#[0-9a-fA-F]{6}$/.test(v)) $('#nw-field-color-picker').val(v);
	});

}(jQuery));
