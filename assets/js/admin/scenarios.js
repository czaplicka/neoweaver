/* NeoWeaver Scenarios Admin JS */
/* globals NWScenarios, lucide, jQuery */
(function ($) {
	'use strict';

	const A = NWScenarios.ajaxurl;
	const N = NWScenarios.nonce;
	let allRows = [];

	const JSON_FIELDS = [
		'#nw-field-tags',
		'#nw-field-required-tags',
		'#nw-field-success-tags',
		'#nw-field-failure-tags',
		'#nw-field-reward-items',
		'#nw-field-giver-npc-tag'
	];

	function icons() {
		if (window.lucide) lucide.createIcons();
	}

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
		$('#nw-inactive').text(rows.filter(r => !r.is_active).length);
		$('#nw-boss').text(rows.filter(r => r.is_boss).length);
		$('#nw-arc').text(rows.filter(r => r.is_key_arc).length);
	}

	function typeBadge(v) {
		return `<span class="nw-type-badge nw-type-${v || 'main'}">${v || 'main'}</span>`;
	}

	function categoryBadge(v) {
		return `<span class="nw-cat-badge nw-cat-${v || 'combat'}">${v || 'combat'}</span>`;
	}

	function difficultyDots(v) {
		v = parseInt(v || 0, 10);
		let out = '<span class="nw-diff-dots">';
		for (let i = 1; i <= 5; i++) out += `<span class="nw-diff-dot ${i <= v ? 'is-on' : ''}"></span>`;
		out += ` <strong>${v}/5</strong></span>`;
		return out;
	}

	function entropyText(row) {
		const min = row.min_entropy;
		const max = row.max_entropy;
		if (min === null && max === null) return '<span class="nw-muted">—</span>';
		if (min !== null && max !== null) return `<span class="nw-entropy-pill">${min}–${max}</span>`;
		return `<span class="nw-entropy-pill">${min !== null ? min : '…'}–${max !== null ? max : '…'}</span>`;
	}

	function flagsHtml(row) {
		const flags = [];
		if (row.is_boss) flags.push('<span class="nw-flag-chip nw-chip-boss">Boss</span>');
		if (row.is_key_arc) flags.push('<span class="nw-flag-chip nw-chip-arc">Key Arc</span>');
		if (row.is_repeatable) flags.push('<span class="nw-flag-chip nw-chip-repeat">Repeatable</span>');
		return flags.length ? flags.join(' ') : '<span class="nw-muted">—</span>';
	}

	function renderTable(rows) {
		const tbody = $('#nw-scenarios-tbody');
		if (!rows.length) {
			tbody.html(`<tr><td colspan="9" class="nw-empty-row"><i data-lucide="inbox" style="width:18px;height:18px;vertical-align:middle;margin-right:6px"></i>No scenarios found. Create one!</td></tr>`);
			icons();
			return;
		}

		const html = rows.map(r => {
			const img = r.img_url
				? `<img src="${r.img_url}" alt="" style="width:36px;height:36px;object-fit:cover;border-radius:4px" loading="lazy">`
				: `<span class="nw-no-img"><i data-lucide="image-off" style="width:14px;height:14px"></i></span>`;
			const active = r.is_active ? '<span class="nw-status-dot nw-dot-on"></span>' : '<span class="nw-status-dot nw-dot-off"></span>';

			return `<tr data-id="${r.id}">
				<td>${img}</td>
				<td>
					<strong>${r.name}</strong>
					${r.goal ? `<br><small class="nw-muted">${r.goal.substring(0,70)}${r.goal.length > 70 ? '…' : ''}</small>` : ''}
				</td>
				<td>${typeBadge(r.type)}</td>
				<td>${categoryBadge(r.category)}</td>
				<td>${difficultyDots(r.difficulty)}</td>
				<td>${entropyText(r)}</td>
				<td>${flagsHtml(r)}</td>
				<td style="text-align:center">${active}</td>
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
		const q    = $('#nw-search').val().toLowerCase();
		const type = $('#nw-filter-type').val();
		const cat  = $('#nw-filter-category').val();
		const diff = $('#nw-filter-difficulty').val();
		const act  = $('#nw-filter-active').val();
		const hasFilter = q || type || cat || diff || act !== '';
		$('#nw-clear-filters').toggle(!!hasFilter);

		const filtered = allRows.filter(r => {
			if (q && !r.name.toLowerCase().includes(q) && !(r.goal || '').toLowerCase().includes(q)) return false;
			if (type && r.type !== type) return false;
			if (cat && r.category !== cat) return false;
			if (diff && String(r.difficulty) !== diff) return false;
			if (act !== '') { if (r.is_active !== (act === '1')) return false; }
			return true;
		});
		renderTable(filtered);
	}

	function loadScenarios() {
		$('#nw-scenarios-tbody').html(`<tr class="nw-loading-row"><td colspan="9"><span class="nw-spinner"></span> Loading scenarios…</td></tr>`);
		$.post(A, { action: 'nw_scenarios_load', nonce: N }, res => {
			if (!res.success) { notice(res.data || 'Load failed.', 'error'); return; }
			allRows = res.data;
			updateStats(allRows);
			applyFilters();
		});
	}

	function renderDifficultyStars(v) {
		v = parseInt(v || 0, 10);
		let html = '';
		for (let i = 1; i <= 5; i++) {
			html += `<span class="nw-star ${i <= v ? 'is-on' : ''}">◆</span>`;
		}
		$('#nw-diff-stars').html(html);
	}

	function resetForm() {
		$('#nw-field-id').val('');
		$('#nw-field-name').val('');
		$('#nw-field-type').val('main');
		$('#nw-field-category').val('combat');
		$('#nw-field-area-id').val('');
		$('#nw-field-img-url').val('');
		$('#nw-field-goal').val('');
		$('#nw-field-difficulty').val(3);
		$('#nw-field-min-entropy').val('');
		$('#nw-field-max-entropy').val('');
		$('#nw-field-victory').val('');
		$('#nw-field-fail').val('');
		$('#nw-field-tags').val('[]');
		$('#nw-field-required-tags').val('[]');
		$('#nw-field-success-tags').val('[]');
		$('#nw-field-failure-tags').val('[]');
		$('#nw-field-credits').val(100);
		$('#nw-field-kingdom-tech').val('');
		$('#nw-field-kingdom-magic').val('');
		$('#nw-field-kingdom-wealth').val('');
		$('#nw-field-reward-items').val('');
		$('#nw-field-archetype-id').val('');
		$('#nw-field-giver-npc-tag').val('');
		$('#nw-field-gm-instruction').val('');
		$('#nw-field-is-active').prop('checked', true);
		$('#nw-field-is-boss').prop('checked', false);
		$('#nw-field-is-key-arc').prop('checked', false);
		$('#nw-field-is-repeatable').prop('checked', false);
		$('#nw-img-preview-wrap').hide();
		$('#nw-delete-btn').hide();
		renderDifficultyStars(3);
	}

	function openModal(row) {
		const isNew = !row;
		$('#nw-modal-title').text(isNew ? 'New Scenario' : 'Edit Scenario');
		$('#nw-save-label').text(isNew ? 'Create Scenario' : 'Save Changes');
		resetForm();

		if (!isNew) {
			$('#nw-field-id').val(row.id);
			$('#nw-field-name').val(row.name || '');
			$('#nw-field-type').val(row.type || 'main');
			$('#nw-field-category').val(row.category || 'combat');
			$('#nw-field-area-id').val(row.area_id || '');
			$('#nw-field-img-url').val(row.img_url || '');
			$('#nw-field-goal').val(row.goal || '');
			$('#nw-field-difficulty').val(row.difficulty || 3);
			$('#nw-field-min-entropy').val(row.min_entropy ?? '');
			$('#nw-field-max-entropy').val(row.max_entropy ?? '');
			$('#nw-field-victory').val(row.victory_condition || '');
			$('#nw-field-fail').val(row.fail_conditions || '');
			$('#nw-field-tags').val(JSON.stringify(row.tags || []));
			$('#nw-field-required-tags').val(JSON.stringify(row.required_tags || []));
			$('#nw-field-success-tags').val(JSON.stringify(row.success_tags || []));
			$('#nw-field-failure-tags').val(JSON.stringify(row.failure_tags || []));
			$('#nw-field-credits').val(row.reward_credits ?? 100);
			$('#nw-field-kingdom-tech').val(row.kingdom_tech ?? '');
			$('#nw-field-kingdom-magic').val(row.kingdom_magic ?? '');
			$('#nw-field-kingdom-wealth').val(row.kingdom_wealth ?? '');
			$('#nw-field-reward-items').val(row.reward_items ? JSON.stringify(row.reward_items) : '');
			$('#nw-field-archetype-id').val(row.required_archetype_id ?? '');
			$('#nw-field-giver-npc-tag').val(row.giver_npc_tag ? JSON.stringify(row.giver_npc_tag) : '');
			$('#nw-field-gm-instruction').val(row.gm_instruction || '');
			$('#nw-field-is-active').prop('checked', !!row.is_active);
			$('#nw-field-is-boss').prop('checked', !!row.is_boss);
			$('#nw-field-is-key-arc').prop('checked', !!row.is_key_arc);
			$('#nw-field-is-repeatable').prop('checked', !!row.is_repeatable);
			if (row.img_url) {
				$('#nw-img-preview').attr('src', row.img_url);
				$('#nw-img-preview-wrap').show();
			}
			$('#nw-delete-btn').show().data('id', row.id);
			renderDifficultyStars(row.difficulty || 3);
		}

		$('#nw-modal-overlay').fadeIn(160);
		icons();
	}

	function closeModal() {
		$('#nw-modal-overlay').fadeOut(140);
	}

	function validateJsonField(selector, label) {
		const raw = $(selector).val().trim();
		if (!raw) return true;
		try { JSON.parse(raw); return true; }
		catch(e) { notice(label + ' must be valid JSON.', 'error'); return false; }
	}

	function saveScenario() {
		const btn  = $('#nw-save-btn');
		const id   = $('#nw-field-id').val().trim();
		const name = $('#nw-field-name').val().trim();
		if (!name) { notice('Name is required.', 'error'); return; }

		if (!validateJsonField('#nw-field-tags', 'Tags')) return;
		if (!validateJsonField('#nw-field-required-tags', 'Required Tags')) return;
		if (!validateJsonField('#nw-field-success-tags', 'Success Tags')) return;
		if (!validateJsonField('#nw-field-failure-tags', 'Failure Tags')) return;
		if (!validateJsonField('#nw-field-reward-items', 'Reward Items')) return;
		if (!validateJsonField('#nw-field-giver-npc-tag', 'Giver NPC Tag')) return;

		const minE = $('#nw-field-min-entropy').val();
		const maxE = $('#nw-field-max-entropy').val();
		if (minE !== '' && maxE !== '' && parseInt(minE, 10) > parseInt(maxE, 10)) {
			notice('Min entropy cannot be greater than max entropy.', 'error');
			return;
		}

		btn.prop('disabled', true).html('<span class="nw-spinner" style="width:13px;height:13px"></span> Saving…');

		$.post(A, {
			action: 'nw_scenarios_save',
			nonce: N,
			id: id,
			name: name,
			type: $('#nw-field-type').val(),
			category: $('#nw-field-category').val(),
			goal: $('#nw-field-goal').val(),
			gm_instruction: $('#nw-field-gm-instruction').val(),
			victory_condition: $('#nw-field-victory').val(),
			fail_conditions: $('#nw-field-fail').val(),
			difficulty: $('#nw-field-difficulty').val(),
			min_entropy: minE,
			max_entropy: maxE,
			area_id: $('#nw-field-area-id').val(),
			img_url: $('#nw-field-img-url').val(),
			reward_credits: $('#nw-field-credits').val(),
			reward_items: $('#nw-field-reward-items').val(),
			kingdom_tech: $('#nw-field-kingdom-tech').val(),
			kingdom_magic: $('#nw-field-kingdom-magic').val(),
			kingdom_wealth: $('#nw-field-kingdom-wealth').val(),
			required_archetype_id: $('#nw-field-archetype-id').val(),
			giver_npc_tag: $('#nw-field-giver-npc-tag').val(),
			tags: $('#nw-field-tags').val(),
			required_tags: $('#nw-field-required-tags').val(),
			success_tags: $('#nw-field-success-tags').val(),
			failure_tags: $('#nw-field-failure-tags').val(),
			is_boss: $('#nw-field-is-boss').is(':checked') ? 1 : 0,
			is_key_arc: $('#nw-field-is-key-arc').is(':checked') ? 1 : 0,
			is_active: $('#nw-field-is-active').is(':checked') ? 1 : 0,
			is_repeatable: $('#nw-field-is-repeatable').is(':checked') ? 1 : 0
		}, res => {
			btn.prop('disabled', false)
				.html('<i data-lucide="save" style="width:13px;height:13px;vertical-align:middle;margin-right:4px"></i><span id="nw-save-label">' + (id ? 'Save Changes' : 'Create Scenario') + '</span>');
			icons();
			if (!res.success) { notice(res.data || 'Save failed.', 'error'); return; }
			notice(id ? 'Scenario updated.' : 'Scenario created!');
			closeModal();
			loadScenarios();
		});
	}

	function deleteScenario(id) {
		if (!confirm('Delete this scenario? This cannot be undone.')) return;
		$.post(A, { action: 'nw_scenarios_delete', nonce: N, id }, res => {
			if (!res.success) { notice(res.data || 'Delete failed.', 'error'); return; }
			notice('Scenario deleted.');
			closeModal();
			loadScenarios();
		});
	}

	function duplicateScenario(id) {
		$.post(A, { action: 'nw_scenarios_duplicate', nonce: N, id }, res => {
			if (!res.success) { notice(res.data || 'Duplicate failed.', 'error'); return; }
			notice('Scenario duplicated.');
			loadScenarios();
		});
	}

	icons();
	loadScenarios();
	$('#nw-refresh-btn').on('click', loadScenarios);
	$('#nw-add-btn').on('click', () => openModal(null));
	$('#nw-modal-close, #nw-cancel-btn').on('click', closeModal);
	$('#nw-modal-overlay').on('click', e => { if (e.target.id === 'nw-modal-overlay') closeModal(); });
	$('#nw-save-btn').on('click', saveScenario);
	$('#nw-scenario-form').on('submit', e => { e.preventDefault(); saveScenario(); });
	$('#nw-delete-btn').on('click', function () { deleteScenario($(this).data('id')); });

	$('#nw-scenarios-tbody')
		.on('click', '.nw-edit-btn', function () {
			const id = $(this).data('id');
			const row = allRows.find(r => String(r.id) === String(id));
			if (row) openModal(row);
		})
		.on('click', '.nw-dup-btn', function () { duplicateScenario($(this).data('id')); });

	$('#nw-search, #nw-filter-type, #nw-filter-category, #nw-filter-difficulty, #nw-filter-active').on('input change', applyFilters);
	$('#nw-clear-filters').on('click', () => {
		$('#nw-search').val('');
		$('#nw-filter-type, #nw-filter-category, #nw-filter-difficulty, #nw-filter-active').val('');
		applyFilters();
	});

	$('#nw-field-img-url').on('input', function () {
		const url = $(this).val().trim();
		if (url) {
			$('#nw-img-preview').attr('src', url);
			$('#nw-img-preview-wrap').show();
		} else {
			$('#nw-img-preview-wrap').hide();
		}
	});

	$('#nw-field-difficulty').on('input change', function () {
		let v = parseInt($(this).val(), 10) || 1;
		v = Math.max(1, Math.min(5, v));
		$(this).val(v);
		renderDifficultyStars(v);
	});

}(jQuery));
