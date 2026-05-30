/* NeoWeaver — World Tag Defs Admin JS */
/* globals NWWorldTagDefs, lucide, jQuery */
(function ($) {
	'use strict';

	const A = NWWorldTagDefs.ajaxurl;
	const N = NWWorldTagDefs.nonce;

	let allRows = [];

	function icons() { if (window.lucide) lucide.createIcons(); }

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
		$('#nw-stat-system').text(rows.filter(r => r.source === 'system').length);
		$('#nw-stat-custom').text(rows.filter(r => r.source !== 'system').length);
		$('#nw-stat-nonzero').text(rows.filter(r => r.impact && r.impact != 0).length);
		$('#nw-inactive').text(rows.filter(r => !r.is_active).length);
	}

	// ── Color dot ────────────────────────────────────────────────────────────
	function colorDot(hex) {
		return `<span class="nw-color-dot" style="background:${escHtml(hex||'#adff00')};"></span>`;
	}

	// ── Impact pill ──────────────────────────────────────────────────────────
	function impactPill(val) {
		if (!val || val == 0) return '<span class="nw-text-muted">0</span>';
		const n = parseFloat(val);
		const cls = n > 0 ? 'nw-impact-pos' : 'nw-impact-neg';
		return `<span class="nw-impact-pill ${cls}">${n > 0 ? '+' : ''}${n}</span>`;
	}

	// ── Source badge ─────────────────────────────────────────────────────────
	function sourceBadge(src) {
		return src === 'system'
			? '<span class="nw-source-badge nw-source-system">system</span>'
			: `<span class="nw-source-badge nw-source-custom">${escHtml(src||'custom')}</span>`;
	}

	// ── Render ───────────────────────────────────────────────────────────────
	function renderTable(rows) {
		const $tbody = $('#nw-wtagdefs-tbody');
		if (!rows.length) {
			$tbody.html('<tr><td colspan="8" class="nw-empty">No tag definitions found.</td></tr>');
			icons(); return;
		}
		const html = rows.map(row => `
			<tr data-id="${row.id}">
				<td>
					<div class="nw-code-cell">
						${colorDot(row.color)}
						<span class="nw-code-pill">${escHtml(row.code)}</span>
						${row.icon ? `<i data-lucide="${escHtml(row.icon)}" class="nw-row-icon" style="color:${escHtml(row.color||'#adff00')}"></i>` : ''}
					</div>
				</td>
				<td class="nw-col-label-text">${escHtml(row.label)}</td>
				<td>${row.category
					? `<span class="nw-cat-chip">${escHtml(row.category)}</span>`
					: '<span class="nw-text-muted">—</span>'}</td>
				<td>${impactPill(row.impact)}</td>
				<td class="nw-col-sort">${row.sort_order != null ? row.sort_order : '<span class="nw-text-muted">—</span>'}</td>
				<td>${sourceBadge(row.source)}</td>
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
		const search = $('#nw-search').val().toLowerCase();
		const source = $('#nw-filter-source').val();
		const active = $('#nw-filter-active').val();

		const filtered = allRows.filter(r => {
			if (search && !r.code.toLowerCase().includes(search) &&
				!r.label.toLowerCase().includes(search) &&
				!(r.category||'').toLowerCase().includes(search)) return false;
			if (source && r.source !== source) return false;
			if (active === '1' && !r.is_active) return false;
			if (active === '0' &&  r.is_active) return false;
			return true;
		});
		renderTable(filtered);
	}

	// ── Load ─────────────────────────────────────────────────────────────────
	function loadDefs() {
		$('#nw-wtagdefs-tbody').html('<tr><td colspan="8" class="nw-loading"><i data-lucide="loader-2" class="nw-spin"></i> Loading…</td></tr>');
		icons();
		$.post(A, { action: 'nwwtagdefsload', nonce: N }, res => {
			if (!res.success) { notice(res.data || 'Load error', 'error'); return; }
			allRows = res.data || [];
			updateStats(allRows);
			applyFilters();
		});
	}

	// ── Color sync ───────────────────────────────────────────────────────────
	function updateColorPreview(hex) {
		$('#nw-field-color_picker').val(hex);
		$('#nw-field-color').val(hex);
	}

	// ── Icon preview ─────────────────────────────────────────────────────────
	function updateIconPreview(name) {
		const $p = $('#nw-icon-preview');
		if (!name) { $p.html(''); return; }
		$p.html(`<i data-lucide="${escHtml(name)}"></i>`);
		icons();
	}

	// ── Modal ────────────────────────────────────────────────────────────────
	function openModal(row = null) {
		$('#nw-field-id').val(row ? row.id : '');
		$('#nw-modal-title').text(row ? 'Edit Tag Definition' : 'New Tag Definition');
		const color = (row && row.color) ? row.color : '#adff00';
		$('#nw-field-code').val(row ? row.code : '');
		$('#nw-field-label').val(row ? row.label : '');
		$('#nw-field-icon').val(row ? (row.icon || '') : '');
		$('#nw-field-category').val(row ? (row.category || '') : '');
		$('#nw-field-source').val(row ? (row.source || 'system') : 'system');
		$('#nw-field-sort_order').val(row && row.sort_order != null ? row.sort_order : '');
		$('#nw-field-impact').val(row ? (row.impact || 0) : 0);
		$('#nw-field-description').val(row ? (row.description || '') : '');
		$('#nw-field-is_active').prop('checked', row ? !!row.is_active : true);
		updateColorPreview(color);
		updateIconPreview(row ? (row.icon || '') : '');
		$('#nw-modal').show();
		$('#nw-field-code').focus();
		icons();
	}

	function closeModal() { $('#nw-modal').hide(); }

	// ── Save ─────────────────────────────────────────────────────────────────
	function saveDef() {
		const code  = $('#nw-field-code').val().trim();
		const label = $('#nw-field-label').val().trim();
		if (!code)  { notice('Code is required.', 'error');  return; }
		if (!label) { notice('Label is required.', 'error'); return; }

		const hex = $('#nw-field-color').val().trim();
		if (!/^#[0-9a-fA-F]{6}$/.test(hex)) { notice('Color must be a valid hex.', 'error'); return; }

		const $btn = $('#nw-modal-save').prop('disabled', true).html('<i data-lucide="loader-2" class="nw-spin"></i> Saving…');
		icons();

		$.post(A, {
			action:      'nwwtagdefssave',
			nonce:       N,
			id:          $('#nw-field-id').val(),
			code,
			label,
			icon:        $('#nw-field-icon').val().trim(),
			color:       hex,
			description: $('#nw-field-description').val(),
			category:    $('#nw-field-category').val().trim(),
			source:      $('#nw-field-source').val(),
			sort_order:  $('#nw-field-sort_order').val(),
			impact:      $('#nw-field-impact').val(),
			is_active:   $('#nw-field-is_active').is(':checked') ? 1 : 0,
		}, res => {
			$btn.prop('disabled', false).html('<i data-lucide="save"></i> Save Tag Def');
			icons();
			if (!res.success) { notice(res.data || 'Save failed.', 'error'); return; }
			notice('Tag definition saved!');
			closeModal();
			loadDefs();
		});
	}

	// ── Delete ───────────────────────────────────────────────────────────────
	function deleteDef(id) {
		if (!confirm('Delete this tag definition?')) return;
		$.post(A, { action: 'nwwtagdefsdelete', nonce: N, id }, res => {
			if (!res.success) { notice(res.data || 'Delete failed.', 'error'); return; }
			notice('Tag definition deleted.');
			loadDefs();
		});
	}

	// ── Duplicate ────────────────────────────────────────────────────────────
	function duplicateDef(id) {
		$.post(A, { action: 'nwwtagdefsduplicate', nonce: N, id }, res => {
			if (!res.success) { notice(res.data || 'Duplicate failed.', 'error'); return; }
			notice('Duplicated.');
			loadDefs();
		});
	}

	function escHtml(str) {
		return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}

	// ── Init ─────────────────────────────────────────────────────────────────
	$(document).ready(function () {
		loadDefs();

		$('#nw-add-btn').on('click', () => openModal());

		$('#nw-wtagdefs-tbody').on('click', '.nw-edit-btn', function () {
			const row = allRows.find(r => r.id == $(this).data('id'));
			if (row) openModal(row);
		});
		$('#nw-wtagdefs-tbody').on('click', '.nw-dup-btn',  function () { duplicateDef($(this).data('id')); });
		$('#nw-wtagdefs-tbody').on('click', '.nw-del-btn',  function () { deleteDef($(this).data('id')); });

		$('#nw-modal-close, #nw-modal-cancel').on('click', closeModal);
		$('#nw-modal').on('click', e => { if ($(e.target).is('#nw-modal')) closeModal(); });
		$('#nw-modal-save').on('click', saveDef);

		// Code auto-sanitize
		$('#nw-field-code').on('input', function () {
			$(this).val($(this).val().toLowerCase().replace(/[^a-z0-9_\-]/g, '_'));
		});

		// Color sync
		$('#nw-field-color_picker').on('input', function () { updateColorPreview($(this).val()); });
		$('#nw-field-color').on('input', function () {
			const v = $(this).val().trim();
			if (/^#[0-9a-fA-F]{6}$/.test(v)) $('#nw-field-color_picker').val(v);
		});

		// Icon live preview
		$('#nw-field-icon').on('input', function () { updateIconPreview($(this).val().trim()); });

		$('#nw-search, #nw-filter-source, #nw-filter-active').on('input change', applyFilters);
		$('#nw-clear-filters').on('click', () => {
			$('#nw-search').val('');
			$('#nw-filter-source, #nw-filter-active').val('');
			applyFilters();
		});

		$(document).on('keydown', e => { if (e.key === 'Escape') closeModal(); });
	});

}(jQuery));
