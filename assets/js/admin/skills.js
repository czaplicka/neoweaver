/* NeoWeaver Skills Admin JS */
/* globals NWSkills, lucide, jQuery */
(function ($) {
	'use strict';

	const A = NWSkills.ajaxurl;
	const N = NWSkills.nonce;
	let allRows = [];

	const CAT_META = {
		Physical:    { color: '#f87171', icon: '<span data-lucide-menu="biceps-flexed"></span>' },
		Social:      { color: '#f472b6', icon: '<span data-lucide-menu="handknife"></span>' },
		Mental:      { color: '#a78bfa', icon: '<span data-lucide-menu="brain"></span>' },
		Exploration: { color: '#34d399', icon: '<span data-lucide-menu="globe"></span>' },
	};

	function icons() { if (window.lucide) lucide.createIcons(); }

	function notice(msg, type = 'success') {
		const n = $('#nw-notice');
		n.removeClass('nw-notice-success nw-notice-error')
			.addClass(type === 'error' ? 'nw-notice-error' : 'nw-notice-success')
			.html(msg).show();
		setTimeout(() => n.fadeOut(), 4000);
	}

	function updateStats(rows) {
		$('#nw-total').text(rows.length);
		$('#nw-active').text(rows.filter(r => r.is_active).length);
		Object.keys(CAT_META).forEach(c => {
			$('#nw-stat-' + c.toLowerCase()).find('span, .count').first().text(rows.filter(r => r.category === c).length);
			$('#nw-stat-' + c.toLowerCase()).contents().filter(function(){ return this.nodeType === 3; }).first().replaceWith(rows.filter(r => r.category === c).length + ' ');
		});
	}

	function catBadge(cat) {
		if (!cat) return '<span class="nw-muted">—</span>';
		const m = CAT_META[cat] || { color: '#adff00' };
		return `<span class="nw-cat-badge" style="border-color:${m.color}30;color:${m.color}">${m.icon || ''} ${cat}</span>`;
	}

	function tagsHtml(tags) {
		if (!tags || !tags.length) return '<span class="nw-muted">—</span>';
		return tags.slice(0, 3).map(t => `<span class="nw-tag-chip">${t}</span>`).join('') +
			(tags.length > 3 ? `<span class="nw-tag-chip nw-tag-more">+${tags.length - 3}</span>` : '');
	}

	function truncate(str, len) {
		if (!str) return '<span class="nw-muted">—</span>';
		return str.length > len ? str.substring(0, len) + '…' : str;
	}

	function renderTable(rows) {
		const tbody = $('#nw-skills-tbody');
		if (!rows.length) {
			tbody.html(`<tr><td colspan="8" class="nw-empty-row"><i data-lucide="inbox" style="width:18px;height:18px;vertical-align:middle;margin-right:6px"></i>No skills found.</td></tr>`);
			icons(); return;
		}
		const html = rows.map(r => {
			const img = r.img_url
				? `<img src="${r.img_url}" alt="" style="width:36px;height:36px;object-fit:cover;border-radius:6px" loading="lazy">`
				: `<span class="nw-no-img"><i data-lucide="zap" style="width:14px;height:14px"></i></span>`;
			const status = r.is_active
				? `<span class="nw-status-dot nw-dot-on"></span>`
				: `<span class="nw-status-dot nw-dot-off"></span>`;
			return `<tr data-id="${r.id}">
				<td>${img}</td>
				<td>
					<strong>${r.name}</strong>
					${r.description ? `<br><small class="nw-muted">${truncate(r.description, 60)}</small>` : ''}
				</td>
				<td>${catBadge(r.category)}</td>
				<td><small class="nw-muted">${truncate(r.application, 55)}</small></td>
				<td><small class="nw-muted">${truncate(r.card_effect, 55)}</small></td>
				<td>${tagsHtml(r.tags)}</td>
				<td style="text-align:center">${status}</td>
				<td class="nw-actions-cell">
					<button class="nw-btn nw-btn-ghost nw-btn-sm nw-edit-btn" data-id="${r.id}" title="Edit"><i data-lucide="pencil" style="width:13px;height:13px"></i></button>
					<button class="nw-btn nw-btn-ghost nw-btn-sm nw-dup-btn" data-id="${r.id}" title="Duplicate"><i data-lucide="copy" style="width:13px;height:13px"></i></button>
				</td>
			</tr>`;
		}).join('');
		tbody.html(html);
		icons();
	}

	function applyFilters() {
		const q   = $('#nw-search').val().toLowerCase();
		const cat = $('#nw-filter-category').val();
		const act = $('#nw-filter-active').val();
		const hasFilter = q || cat || act !== '';
		$('#nw-clear-filters').toggle(!!hasFilter);

		renderTable(allRows.filter(r => {
			if (q && !r.name.toLowerCase().includes(q) &&
				!(r.description || '').toLowerCase().includes(q) &&
				!(r.application || '').toLowerCase().includes(q)) return false;
			if (cat && r.category !== cat) return false;
			if (act !== '') { if (r.is_active !== (act === '1')) return false; }
			return true;
		}));
	}

	function loadSkills() {
		$('#nw-skills-tbody').html(`<tr class="nw-loading-row"><td colspan="8"><span class="nw-spinner"></span> Loading skills…</td></tr>`);
		$.post(A, { action: 'nw_skills_load', nonce: N }, res => {
			if (!res.success) { notice(res.data || 'Load failed.', 'error'); return; }
			allRows = res.data || [];
			updateStats(allRows);
			applyFilters();
		});
	}

	/* ---- tag-chip editor ---- */
	function buildTagEditor(wrapId, listId, inputId, hiddenId) {
		let items = [];

		function render() {
			const html = items.map((t, i) =>
				`<span class="nw-tag-chip nw-tag-removable">${t}<button type="button" class="nw-tag-remove" data-i="${i}">×</button></span>`
			).join('');
			$('#' + listId).html(html);
			$('#' + hiddenId).val(JSON.stringify(items));
		}

		$('#' + listId).on('click', '.nw-tag-remove', function () {
			items.splice(parseInt($(this).data('i'), 10), 1);
			render();
		});

		$('#' + inputId).on('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ',') {
				e.preventDefault();
				const val = $(this).val().trim().replace(/,$/, '');
				if (val && !items.includes(val)) { items.push(val); render(); }
				$(this).val('');
			}
		});

		return {
			setItems(arr) { items = Array.isArray(arr) ? [...arr] : []; render(); },
			getItems() { return items; }
		};
	}

	const tagsEditor = buildTagEditor('nw-tags-wrap', 'nw-tags-list', 'nw-tag-input', 'nw-field-tags');
	const attrsEditor = buildTagEditor('nw-attrs-wrap', 'nw-attrs-list', 'nw-attr-input', 'nw-field-linked-attributes');

	/* ---- modal ---- */
	function resetForm() {
		$('#nw-field-id').val('');
		$('#nw-field-name').val('');
		$('#nw-field-category').val('');
		$('#nw-field-description').val('');
		$('#nw-field-application').val('');
		$('#nw-field-card-effect').val('');
		$('#nw-field-img-url').val('');
		$('#nw-field-is-active').prop('checked', true);
		tagsEditor.setItems([]);
		attrsEditor.setItems([]);
		$('#nw-img-preview-wrap').hide();
		$('#nw-delete-btn').hide();
	}

	function openModal(row) {
		const isNew = !row;
		$('#nw-modal-title').text(isNew ? 'New Skill' : 'Edit Skill');
		$('#nw-save-label').text(isNew ? 'Create Skill' : 'Save Changes');
		resetForm();

		if (!isNew) {
			$('#nw-field-id').val(row.id);
			$('#nw-field-name').val(row.name || '');
			$('#nw-field-category').val(row.category || '');
			$('#nw-field-description').val(row.description || '');
			$('#nw-field-application').val(row.application || '');
			$('#nw-field-card-effect').val(row.card_effect || '');
			const uploadsBase = 'https://neoweaver.nieodparady.pl/wp-content/uploads/';
const rawImg = (row.img_url || '').replace(uploadsBase, '');
$('#nw-field-img-url').val(rawImg);
			$('#nw-field-is-active').prop('checked', !!row.is_active);
			tagsEditor.setItems(row.tags || []);
			attrsEditor.setItems(row.linked_attributes || []);
			if (row.img_url) {
				$('#nw-img-preview').attr('src', row.img_url);
				$('#nw-img-preview-wrap').show();
			}
			$('#nw-delete-btn').show().data('id', row.id);
		}
		$('#nw-modal-overlay').fadeIn(160);
		icons();
	}

	function closeModal() { $('#nw-modal-overlay').fadeOut(140); }

	function saveSkill() {
		const btn  = $('#nw-save-btn');
		const id   = $('#nw-field-id').val().trim();
		const name = $('#nw-field-name').val().trim();
		if (!name) { notice('Name is required.', 'error'); return; }

		btn.prop('disabled', true).html('<span class="nw-spinner" style="width:13px;height:13px"></span> Saving…');

		$.post(A, {
			action: 'nw_skills_save',
			nonce: N,
			id,
			name,
			category:           $('#nw-field-category').val(),
			description:        $('#nw-field-description').val(),
			application:        $('#nw-field-application').val(),
			card_effect:        $('#nw-field-card-effect').val(),
			img_url:            $('#nw-field-img-url').val(),
			tags:               $('#nw-field-tags').val(),
			linked_attributes:  $('#nw-field-linked-attributes').val(),
			is_active:          $('#nw-field-is-active').is(':checked') ? 1 : 0,
		}, res => {
			btn.prop('disabled', false)
				.html('<i data-lucide="save" style="width:13px;height:13px;vertical-align:middle;margin-right:4px"></i><span id="nw-save-label">' + (id ? 'Save Changes' : 'Create Skill') + '</span>');
			icons();
			if (!res.success) { notice(res.data || 'Save failed.', 'error'); return; }
			notice(id ? 'Skill updated.' : 'Skill created!');
			closeModal();
			loadSkills();
		});
	}

	function deleteSkill(id) {
		if (!confirm('Delete this skill? Cannot be undone.')) return;
		$.post(A, { action: 'nw_skills_delete', nonce: N, id }, res => {
			if (!res.success) { notice(res.data || 'Delete failed.', 'error'); return; }
			notice('Skill deleted.');
			closeModal();
			loadSkills();
		});
	}

	function duplicateSkill(id) {
		$.post(A, { action: 'nw_skills_duplicate', nonce: N, id }, res => {
			if (!res.success) { notice(res.data || 'Duplicate failed.', 'error'); return; }
			notice('Skill duplicated.');
			loadSkills();
		});
	}

	/* ---- events ---- */
	icons();
	loadSkills();

	$('#nw-refresh-btn').on('click', loadSkills);
	$('#nw-add-btn').on('click', () => openModal(null));
	$('#nw-modal-close, #nw-cancel-btn').on('click', closeModal);
	$('#nw-modal-overlay').on('click', e => { if (e.target.id === 'nw-modal-overlay') closeModal(); });
	$('#nw-save-btn').on('click', saveSkill);
	$('#nw-skill-form').on('submit', e => { e.preventDefault(); saveSkill(); });
	$('#nw-delete-btn').on('click', function () { deleteSkill($(this).data('id')); });

	$('#nw-skills-tbody')
		.on('click', '.nw-edit-btn', function () {
			openModal(allRows.find(r => r.id === $(this).data('id')));
		})
		.on('click', '.nw-dup-btn', function () { duplicateSkill($(this).data('id')); });

	$('#nw-search, #nw-filter-category, #nw-filter-active').on('input change', applyFilters);
	$('#nw-clear-filters').on('click', () => {
		$('#nw-search').val('');
		$('#nw-filter-category, #nw-filter-active').val('');
		applyFilters();
	});

	$('#nw-field-img-url').on('input', function () {
		const url = $(this).val().trim();
		if (url) { $('#nw-img-preview').attr('src', url); $('#nw-img-preview-wrap').show(); }
		else $('#nw-img-preview-wrap').hide();
	});

}(jQuery));
