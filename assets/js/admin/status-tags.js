/* NeoWeaver — Status Tags Admin JS */
/* globals NWStatusTags, lucide, jQuery */
(function ($) {
	'use strict';

	const A = NWStatusTags.ajaxurl;
	const N = NWStatusTags.nonce;

	let allRows = [];

	// ── Lucide ───────────────────────────────────────────────────────────────
	function icons() { if (window.lucide) lucide.createIcons(); }

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
		$('#nw-debuffs').text(rows.filter(r => r.is_debuff).length);
		$('#nw-buffs').text(rows.filter(r => !r.is_debuff).length);
		$('#nw-inactive').text(rows.filter(r => !r.is_active).length);
	}

	// ── Category badge ───────────────────────────────────────────────────────
	const CAT_CLASS = {
		Physical:  'nw-cat-physical',
		Condition: 'nw-cat-condition',
		Tech:      'nw-cat-tech',
		Buff:      'nw-cat-buff',
		Glitch:    'nw-cat-glitch',
	};
	function catBadge(cat) {
		if (!cat) return '<span class="nw-text-muted">—</span>';
		const cls = CAT_CLASS[cat] || '';
		return `<span class="nw-cat-badge ${cls}">${escHtml(cat)}</span>`;
	}

	// ── Duration badge ───────────────────────────────────────────────────────
	function durBadge(dur) {
		return `<span class="nw-dur-badge nw-dur-${dur}">${escHtml(dur)}</span>`;
	}

	// ── Color dot ────────────────────────────────────────────────────────────
	function colorDot(hex) {
		return `<span class="nw-color-dot" style="background:${escHtml(hex)};" title="${escHtml(hex)}"></span>`;
	}

	// ── Render table ─────────────────────────────────────────────────────────
	function renderTable(rows) {
		const $tbody = $('#nw-tags-tbody');
		if (!rows.length) {
			$tbody.html('<tr><td colspan="8" class="nw-empty">No status tags found.</td></tr>');
			icons(); return;
		}
		const html = rows.map(row => `
			<tr data-id="${row.id}">
				<td class="nw-col-label">
					${colorDot(row.color_hex || '#ff0000')}
					<span class="nw-tag-label">${escHtml(row.label)}</span>
					${row.effect_description
						? `<div class="nw-row-desc">${escHtml(row.effect_description.slice(0,60))}${row.effect_description.length > 60 ? '…' : ''}</div>`
						: ''}
				</td>
				<td>${catBadge(row.category)}</td>
				<td>${durBadge(row.duration || 'scene')}</td>
				<td>${row.is_debuff
					? '<span class="nw-badge nw-badge-debuff">Debuff</span>'
					: '<span class="nw-badge nw-badge-buff">Buff</span>'}</td>
				<td>${row.is_stackable
					? '<span class="nw-badge nw-badge-stack">Stack</span>'
					: '<span class="nw-text-muted">—</span>'}</td>
				<td class="nw-col-source">${row.source ? escHtml(row.source) : '<span class="nw-text-muted">—</span>'}</td>
				<td>${row.is_active
					? '<span class="nw-badge nw-badge-active">Active</span>'
					: '<span class="nw-badge nw-badge-inactive">Off</span>'}</td>
				<td class="nw-col-actions">
					<button class="nw-btn-icon nw-edit-btn" title="Edit"      data-id="${row.id}"><i data-lucide="pencil"></i></button>
					<button class="nw-btn-icon nw-dup-btn"  title="Duplicate" data-id="${row.id}"><i data-lucide="copy"></i></button>
					<button class="nw-btn-icon nw-del-btn nw-btn-danger" title="Delete" data-id="${row.id}"><i data-lucide="trash-2"></i></button>
				</td>
			</tr>`).join('');
		$tbody.html(html);
		icons();
	}

	// ── Filters ──────────────────────────────────────────────────────────────
	function applyFilters() {
		const search   = $('#nw-search').val().toLowerCase();
		const category = $('#nw-filter-category').val();
		const duration = $('#nw-filter-duration').val();
		const type     = $('#nw-filter-type').val();
		const active   = $('#nw-filter-active').val();

		const filtered = allRows.filter(r => {
			if (search   && !r.label.toLowerCase().includes(search)) return false;
			if (category && r.category !== category) return false;
			if (duration && r.duration !== duration) return false;
			if (type === 'debuff' && !r.is_debuff)   return false;
			if (type === 'buff'   && r.is_debuff)     return false;
			if (active === '1'    && !r.is_active)    return false;
			if (active === '0'    && r.is_active)     return false;
			return true;
		});
		renderTable(filtered);
	}

	// ── Load ─────────────────────────────────────────────────────────────────
	function loadTags() {
		$('#nw-tags-tbody').html('<tr><td colspan="8" class="nw-loading"><i data-lucide="loader-2" class="nw-spin"></i> Loading…</td></tr>');
		icons();
		$.post(A, { action: 'nwstatustagsload', nonce: N }, res => {
			if (!res.success) { notice(res.data || 'Load error', 'error'); return; }
			allRows = res.data || [];
			updateStats(allRows);
			applyFilters();
		});
	}

	// ── Modal ────────────────────────────────────────────────────────────────
	function openModal(row = null) {
		$('#nw-field-id').val(row ? row.id : '');
		$('#nw-modal-title').text(row ? 'Edit Status Tag' : 'New Status Tag');

		const color = (row && row.color_hex) ? row.color_hex : '#ff0000';
		$('#nw-field-label').val(row ? row.label : '');
		$('#nw-field-color_hex').val(color);
		$('#nw-field-color_picker').val(color);
		$('#nw-field-category').val(row ? (row.category || '') : '');
		$('#nw-field-duration').val(row ? (row.duration || 'scene') : 'scene');
		$('#nw-field-effect_description').val(row ? (row.effect_description || '') : '');
		$('#nw-field-mechanic_modifier').val(row ? (row.mechanic_modifier || '') : '');
		$('#nw-field-source').val(row ? (row.source || '') : '');
		$('#nw-field-is_debuff').prop('checked',    row ? !!row.is_debuff    : true);
		$('#nw-field-is_stackable').prop('checked', row ? !!row.is_stackable : false);
		$('#nw-field-is_active').prop('checked',    row ? !!row.is_active    : true);

		updateColorPreview(color);
		$('#nw-modal').show();
		$('#nw-field-label').focus();
		icons();
	}

	function closeModal() { $('#nw-modal').hide(); }

	function updateColorPreview(hex) {
		$('#nw-field-color_picker').val(hex);
		$('#nw-field-color_hex').val(hex);
		$('#nw-color-preview').css('background', hex);
	}

	// ── Save ─────────────────────────────────────────────────────────────────
	function saveTag() {
		const label = $('#nw-field-label').val().trim();
		if (!label) { notice('Label is required.', 'error'); return; }

		const hex = $('#nw-field-color_hex').val().trim();
		if (!/^#[0-9a-fA-F]{6}$/.test(hex)) { notice('Color must be a valid hex (e.g. #ff0000).', 'error'); return; }

		const $btn = $('#nw-modal-save').prop('disabled', true).html('<i data-lucide="loader-2" class="nw-spin"></i> Saving…');
		icons();

		$.post(A, {
			action:             'nwstatustagssave',
			nonce:              N,
			id:                 $('#nw-field-id').val(),
			label,
			color_hex:          hex,
			category:           $('#nw-field-category').val(),
			duration:           $('#nw-field-duration').val(),
			effect_description: $('#nw-field-effect_description').val(),
			mechanic_modifier:  $('#nw-field-mechanic_modifier').val(),
			source:             $('#nw-field-source').val(),
			is_debuff:          $('#nw-field-is_debuff').is(':checked')    ? 1 : 0,
			is_stackable:       $('#nw-field-is_stackable').is(':checked') ? 1 : 0,
			is_active:          $('#nw-field-is_active').is(':checked')    ? 1 : 0,
		}, res => {
			$btn.prop('disabled', false).html('<i data-lucide="save"></i> Save Tag');
			icons();
			if (!res.success) { notice(res.data || 'Save failed.', 'error'); return; }
			notice('Tag saved!');
			closeModal();
			loadTags();
		});
	}

	// ── Delete ───────────────────────────────────────────────────────────────
	function deleteTag(id) {
		if (!confirm('Delete this status tag?')) return;
		$.post(A, { action: 'nwstatustagsdelete', nonce: N, id }, res => {
			if (!res.success) { notice(res.data || 'Delete failed.', 'error'); return; }
			notice('Tag deleted.');
			loadTags();
		});
	}

	// ── Duplicate ────────────────────────────────────────────────────────────
	function duplicateTag(id) {
		$.post(A, { action: 'nwstatustagsduplicate', nonce: N, id }, res => {
			if (!res.success) { notice(res.data || 'Duplicate failed.', 'error'); return; }
			notice('Tag duplicated.');
			loadTags();
		});
	}

	// ── Utils ────────────────────────────────────────────────────────────────
	function escHtml(str) {
		return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}

	// ── Init ─────────────────────────────────────────────────────────────────
	$(document).ready(function () {
		loadTags();

		$('#nw-add-btn').on('click', () => openModal());

		$('#nw-tags-tbody').on('click', '.nw-edit-btn', function () {
			const row = allRows.find(r => r.id == $(this).data('id'));
			if (row) openModal(row);
		});
		$('#nw-tags-tbody').on('click', '.nw-dup-btn', function () { duplicateTag($(this).data('id')); });
		$('#nw-tags-tbody').on('click', '.nw-del-btn', function () { deleteTag($(this).data('id')); });

		$('#nw-modal-close, #nw-modal-cancel').on('click', closeModal);
		$('#nw-modal').on('click', e => { if ($(e.target).is('#nw-modal')) closeModal(); });
		$('#nw-modal-save').on('click', saveTag);

		// Color picker sync
		$('#nw-field-color_picker').on('input', function () { updateColorPreview($(this).val()); });
		$('#nw-field-color_hex').on('input', function () {
			const v = $(this).val().trim();
			if (/^#[0-9a-fA-F]{6}$/.test(v)) updateColorPreview(v);
		});

		$('#nw-search, #nw-filter-category, #nw-filter-duration, #nw-filter-type, #nw-filter-active')
			.on('input change', applyFilters);

		$('#nw-clear-filters').on('click', () => {
			$('#nw-search').val('');
			$('#nw-filter-category, #nw-filter-duration, #nw-filter-type, #nw-filter-active').val('');
			applyFilters();
		});

		$(document).on('keydown', e => { if (e.key === 'Escape') closeModal(); });
	});

}(jQuery));
