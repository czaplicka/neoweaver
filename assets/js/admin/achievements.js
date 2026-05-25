jQuery(function ($) {
	'use strict';

	const cfg = window.NWAchievements || {};
	const ajaxurl = cfg.ajaxurl || '';
	const nonce = cfg.nonce || '';

	let rows = [];
	let currentId = null;

	function initIcons() {
	if (typeof window.lucide === 'undefined') {
		console.warn('Lucide not loaded');
		return;
	}
	if (typeof window.lucide.createIcons === 'function') {
		window.lucide.createIcons();
	}
}

	function esc(s) {
		return $('<div>').text(String(s || '')).html();
	}

	function openModal(item = null) {
		currentId = item && item.id ? item.id : null;

		$('#nw-modal-title').text(currentId ? 'Edit Achievement' : 'Add Achievement');
		$('#achievement-id').val(currentId || '');
		$('#ach-name').val(item?.name || '');
		$('#ach-description').val(item?.description || '');
		$('#ach-cond-type').val(item?.condition_type || '');
		$('#ach-cond-value').val(item?.condition_value || '');
		$('#ach-reward-xp').val(item?.reward_xp || 0);
		$('#ach-icon').val(item?.icon_url || '');
		$('#ach-reward-items').val(item?.reward_items ? JSON.stringify(item.reward_items) : '[]');
		$('#ach-is-active').prop('checked', item ? !!item.is_active : true);

		$('#nw-achievement-modal').show();
		initIcons();
	}

	function closeModal() {
		currentId = null;
		$('#nw-achievement-form')[0].reset();
		$('#achievement-id').val('');
		$('#ach-reward-items').val('[]');
		$('#ach-is-active').prop('checked', true);
		$('#nw-achievement-modal').hide();
	}

	function filteredRows() {
		const search = ($('#nw-search').val() || '').toLowerCase().trim();
		const activeFilter = $('#nw-filter-active').val();

		return rows.filter(function (row) {
			if (activeFilter === '1' && !row.is_active) return false;
			if (activeFilter === '0' && row.is_active) return false;

			if (search) {
				const hay = [
					row.name || '',
					row.description || '',
					row.condition_type || '',
					row.condition_value || ''
				].join(' ').toLowerCase();

				if (!hay.includes(search)) return false;
			}

			return true;
		});
	}

	function render() {
		const list = $('#nw-achievements-list');
		const data = filteredRows();

		if (!data.length) {
			list.html('<div class="nw-empty-state">No achievements found.</div>');
			return;
		}

		const html = data.map(function (row) {
			const rewardItems = Array.isArray(row.reward_items) ? row.reward_items.length : 0;

			return `
				<div class="nw-item-card ${row.is_active ? '' : 'is-inactive'}" data-id="${esc(row.id)}">
					<div class="nw-item-card-header">
						<div>
							<h3>${esc(row.name)}</h3>
							<div class="nw-item-meta">${esc(row.condition_type || '—')} / ${esc(row.condition_value || '—')}</div>
						</div>
						<div class="nw-item-status">${row.is_active ? 'Active' : 'Inactive'}</div>
					</div>

					<div class="nw-item-card-body">
						<p>${esc(row.description || 'No description')}</p>
						<div><strong>XP:</strong> ${esc(row.reward_xp || 0)}</div>
						<div><strong>Items:</strong> ${esc(rewardItems)}</div>
					</div>

					<div class="nw-item-card-actions">
						<button type="button" class="button nw-edit-achievement" data-id="${esc(row.id)}">Edit</button>
						<button type="button" class="button nw-toggle-achievement" data-id="${esc(row.id)}" data-active="${row.is_active ? 1 : 0}">
							${row.is_active ? 'Deactivate' : 'Activate'}
						</button>
						<button type="button" class="button button-link-delete nw-delete-achievement" data-id="${esc(row.id)}">Delete</button>
					</div>
				</div>
			`;
		}).join('');

		list.html(html);
		initIcons();
	}
	console.log('Rendered achievements:', rows.length);

	function loadAchievements() {
		if (!ajaxurl || !nonce) {
			console.error('Missing ajaxurl or nonce');
			return;
		}

		$('#nw-achievements-list').html('<div class="nw-loading">Loading achievements…</div>');

		$.post(ajaxurl, {
			action: 'nw_achievements_get_all',
			nonce: nonce
		}, function (res) {
			if (!res || !res.success) {
				console.error('Load failed', res);
				$('#nw-achievements-list').html('<div class="nw-error">Failed to load achievements.</div>');
				return;
			}

			rows = Array.isArray(res.data) ? res.data : [];
			render();
		}).fail(function (xhr) {
			console.error('AJAX fail', xhr.status, xhr.responseText);
			$('#nw-achievements-list').html('<div class="nw-error">AJAX request failed.</div>');
		});
	}

	$('#nw-add-achievement').on('click', function () {
		openModal();
	});

	$('#nw-modal-close, .nw-modal-cancel, .nw-modal-backdrop').on('click', function () {
		closeModal();
	});

	$('#nw-search, #nw-filter-active').on('input change', function () {
		render();
	});

	$(document).on('click', '.nw-edit-achievement', function () {
		const id = $(this).data('id');
		const item = rows.find(r => r.id === id);
		if (item) openModal(item);
	});

	$(document).on('click', '.nw-toggle-achievement', function () {
		const id = $(this).data('id');
		const current = String($(this).data('active')) === '1';

		$.post(ajaxurl, {
			action: 'nw_achievements_toggle',
			nonce: nonce,
			id: id,
			is_active: current ? 0 : 1
		}, function (res) {
			if (!res || !res.success) {
				console.error('Toggle failed', res);
				return;
			}
			loadAchievements();
		}).fail(function (xhr) {
			console.error('Toggle AJAX fail', xhr.status, xhr.responseText);
		});
	});

	$(document).on('click', '.nw-delete-achievement', function () {
		const id = $(this).data('id');
		if (!window.confirm('Delete this achievement?')) return;

		$.post(ajaxurl, {
			action: 'nw_achievements_delete',
			nonce: nonce,
			id: id
		}, function (res) {
			if (!res || !res.success) {
				console.error('Delete failed', res);
				return;
			}
			loadAchievements();
		}).fail(function (xhr) {
			console.error('Delete AJAX fail', xhr.status, xhr.responseText);
		});
	});

	$('#nw-achievement-form').on('submit', function (e) {
		e.preventDefault();

		$.post(ajaxurl, {
			action: 'nw_achievements_save',
			nonce: nonce,
			id: $('#achievement-id').val(),
			name: $('#ach-name').val(),
			description: $('#ach-description').val(),
			condition_type: $('#ach-cond-type').val(),
			condition_value: $('#ach-cond-value').val(),
			reward_xp: $('#ach-reward-xp').val(),
			icon_url: $('#ach-icon').val(),
			reward_items: $('#ach-reward-items').val(),
			is_active: $('#ach-is-active').is(':checked') ? 1 : 0
		}, function (res) {
			if (!res || !res.success) {
				console.error('Save failed', res);
				return;
			}
			closeModal();
			loadAchievements();
		}).fail(function (xhr) {
			console.error('Save AJAX fail', xhr.status, xhr.responseText);
		});
	});

	loadAchievements();
	initIcons();
});
