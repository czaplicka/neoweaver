/* NeoWeaver — Style Dictionary Admin JS */
/* globals NWStyleDic, lucide, jQuery */
(function ($) {
	'use strict';

	const A = NWStyleDic.ajaxurl;
	const N = NWStyleDic.nonce;

	let allRows = [];

	function icons() { if (window.lucide) lucide.createIcons(); }

	function notice(msg, type = 'success') {
		const $n = $('#nw-notice');
		$n.removeClass('nw-notice-success nw-notice-error')
			.addClass(type === 'error' ? 'nw-notice-error' : 'nw-notice-success')
			.html(msg).show();
		setTimeout(() => $n.fadeOut(), 4000);
	}

	function updateStats(rows) {
		$('#nw-total').text(rows.length);
		$('#nw-stat-behavior').text(rows.filter(r => r.category === 'behavior').length);
		$('#nw-stat-visuals').text(rows.filter(r => r.category === 'visuals').length);
		$('#nw-stat-vibe').text(rows.filter(r => r.category === 'vibe').length);
		$('#nw-inactive').text(rows.filter(r => !r.is_active).length);
	}

	// ── Category badge ───────────────────────────────────────────────────────
	const CAT_CLASS = {
		behavior: 'nw-cat-behavior',
		visuals:  'nw-cat-visuals',
		vibe:     'nw-cat-vibe',
		general:  'nw-cat-general',
	};
	function catBadge(cat) {
		if (!cat) return '<span class="nw-text-muted">—</span>';
		const cls = CAT_CLASS[cat] || '';
		return `<span class="nw-cat-badge ${cls}">${escHtml(cat)}</span>`;
	}

	// ── Render table ─────────────────────────────────────────────────────────
	function renderTable(rows) {
		const $tbody = $('#nw-styledic-tbody');
		if (!rows.length) {
			$tbody.html('<tr><td colspan="5" class="nw-empty">No style tags found.</td></tr>');
			icons(); return;
		}
		const html = rows.map(row => {
			const interp = row.interpretation_en || '';
			const preview = interp.length > 90 ? interp.slice(0, 90) + '…' : interp;
			return `
			<tr data-id="${row.id}">
				<td>
					<span class="nw-tag-pill">${escHtml(row.tag_name)}</span>
				</td>
				<td>${catBadge(row.category)}</td>
				<td class="nw-col-interp">${escHtml(preview)}</td>
				<td>${row.is_active
					? '<span class="nw-badge nw-badge-active">Active</span>'
					: '<span class="nw-badge nw-badge-inactive">Off</span>'}</td>
				<td class="nw-col-actions">
					<button class="nw-btn-icon nw-edit-btn" title="Edit"      data-id="${row.id}"><i data-lucide="pencil"></i></button>
					<button class="nw-btn-icon nw-dup-btn"  title="Duplicate" data-id="${row.id}"><i data-lucide="copy"></i></button>
					<button class="nw-btn-icon nw-del-btn nw-btn-danger" title="Delete" data-id="${row.id}"><i data-lucide="trash-2"></i></button>
				</td>
			</tr>`;
		}).join('');
		$tbody.html(html);
		icons();
	}

	// ── Filters ──────────────────────────────────────────────────────────────
	function applyFilters() {
		const search   = $('#nw-search').val().toLowerCase();
		const category = $('#nw-filter-category').val();
		const active   = $('#nw-filter-active').val();

		const filtered = allRows.filter(r => {
			if (search && !r.tag_name.toLowerCase().includes(search) &&
				!( r.interpretation_en || '' ).toLowerCase().includes(search)) return false;
			if (category && r.category !== category) return false;
			if (active === '1' && !r.is_active)  return false;
			if (active === '0' &&  r.is_active)  return false;
			return true;
		});
		renderTable(filtered);
	}

	// ── Load ─────────────────────────────────────────────────────────────────
	function loadTags() {
		$('#nw-styledic-tbody').html('<tr><td colspan="5" class="nw-loading"><i data-lucide="loader-2" class="nw-spin"></i> Loading…</td></tr>');
		icons();
		$.post(A, { action: 'nwstyledicload', nonce: N }, res => {
			if (!res.success) { notice(res.data || 'Load error', 'error'); return; }
			allRows = res.data || [];
			updateStats(allRows);
			applyFilters();
		});
	}

	// ── Modal ────────────────────────────────────────────────────────────────
	function openModal(row = null) {
		$('#nw-field-id').val(row ? row.id : '');
		$('#nw-modal-title').text(row ? 'Edit Style Tag' : 'New Style Tag');
		$('#nw-field-tag_name').val(row ? row.tag_name : '');
		$('#nw-field-category').val(row ? (row.category || 'general') : 'general');
		$('#nw-field-interpretation_en').val(row ? (row.interpretation_en || '') : '');
		$('#nw-field-is_active').prop('checked', row ? !!row.is_active : true);
		$('#nw-modal').show();
		$('#nw-field-tag_name').focus();
		icons();
	}

	function closeModal() { $('#nw-modal').hide(); }

	// ── Tag name — auto lowercase ─────────────────────────────────────────
	$('#nw-field-tag_name').on('input', function () {
		const val = $(this).val().replace(/\s+/g, '_').toLowerCase();
		$(this).val(val);
	});

	// ── Save ─────────────────────────────────────────────────────────────────
	function saveTag() {
		const tag_name = $('#nw-field-tag_name').val().trim();
		const interp   = $('#nw-field-interpretation_en').val().trim();
		if (!tag_name) { notice('Tag name is required.', 'error'); return; }
		if (!interp)   { notice('Interpretation is required.', 'error'); return; }

		const $btn = $('#nw-modal-save').prop('disabled', true).html('<i data-lucide="loader-2" class="nw-spin"></i> Saving…');
		icons();

		$.post(A, {
			action:             'nwstyledicssave',
			nonce:              N,
			id:                 $('#nw-field-id').val(),
			tag_name,
			category:           $('#nw-field-category').val(),
			interpretation_en:  interp,
			is_active:          $('#nw-field-is_active').is(':checked') ? 1 : 0,
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
		if (!confirm('Delete this style tag?')) return;
		$.post(A, { action: 'nwstyledicdelete', nonce: N, id }, res => {
			if (!res.success) { notice(res.data || 'Delete failed.', 'error'); return; }
			notice('Tag deleted.');
			loadTags();
		});
	}

	// ── Duplicate ────────────────────────────────────────────────────────────
	function duplicateTag(id) {
		$.post(A, { action: 'nwstyledicduplicate', nonce: N, id }, res => {
			if (!res.success) { notice(res.data || 'Duplicate failed.', 'error'); return; }
			notice('Tag duplicated.');
			loadTags();
		});
	}

	function escHtml(str) {
		return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}

	// ── Init ─────────────────────────────────────────────────────────────────
	$(document).ready(function () {
		loadTags();

		$('#nw-add-btn').on('click', () => openModal());

		$('#nw-styledic-tbody').on('click', '.nw-edit-btn', function () {
			const row = allRows.find(r => r.id === $(this).data('id'));
			if (row) openModal(row);
		});
		$('#nw-styledic-tbody').on('click', '.nw-dup-btn', function () { duplicateTag($(this).data('id')); });
		$('#nw-styledic-tbody').on('click', '.nw-del-btn', function () { deleteTag($(this).data('id')); });

		$('#nw-modal-close, #nw-modal-cancel').on('click', closeModal);
		$('#nw-modal').on('click', e => { if ($(e.target).is('#nw-modal')) closeModal(); });
		$('#nw-modal-save').on('click', saveTag);

		$('#nw-search, #nw-filter-category, #nw-filter-active').on('input change', applyFilters);
		$('#nw-clear-filters').on('click', () => {
			$('#nw-search').val('');
			$('#nw-filter-category, #nw-filter-active').val('');
			applyFilters();
		});

		$(document).on('keydown', e => { if (e.key === 'Escape') closeModal(); });
	});

}(jQuery));
