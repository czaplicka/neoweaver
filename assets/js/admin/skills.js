(function ($) {
	'use strict';

	const NW_Skills = {
		currentId: null,
		allRows: [],

		init() {
			if (!window.NW_SK || !window.NW_SK.ajax_url || !window.NW_SK.nonce) {
				this.showGlobalNotice('Missing AJAX configuration. Refresh the page.', 'error');
				return;
			}

			this.bindEvents();
			this.loadSkills();
		},

		bindEvents() {
			$('#nw-add-btn').on('click', () => this.openModal());
			$('#nw-refresh-btn').on('click', () => this.loadSkills());
			$('#nw-filter-category').on('change', () => this.loadSkills());
			$('#nw-search').on('input', () => this.renderTable(this.getFilteredRows()));

			$('#nw-save-btn').on('click', (e) => {
				e.preventDefault();
				this.saveSkill();
			});

			$('#nw-cancel-btn, #nw-modal-close').on('click', (e) => {
				e.preventDefault();
				this.closeModal();
			});

			$('#nw-modal-overlay').on('click', (e) => {
				if ($(e.target).is('#nw-modal-overlay')) {
					this.closeModal();
				}
			});

			$('#nw-field-img_url').on('input', () => {
				this.updateImgPreview($('#nw-field-img_url').val());
			});

			$(document).on('click', '.nw-edit-btn', (e) => this.handleEdit(e));
			$(document).on('click', '.nw-delete-btn', (e) => this.handleDelete(e));
			$(document).on('change', '.nw-toggle-active', (e) => this.handleToggle(e));

			$(document).on('keydown', (e) => {
				if (e.key === 'Escape' && $('#nw-modal-overlay').is(':visible')) {
					this.closeModal();
				}
			});
		},

		getAjaxConfig() {
			return {
				ajaxUrl: window.NW_SK.ajax_url || '',
				nonce: window.NW_SK.nonce || ''
			};
		},

		request(data, onSuccess, fallbackErrorMessage = 'Request failed.') {
			const cfg = this.getAjaxConfig();

			if (!cfg.ajaxUrl) {
				this.showFormNotice('Missing AJAX URL.', 'error');
				return;
			}

			if (!cfg.nonce) {
				this.showFormNotice('Missing security token. Refresh the page.', 'error');
				return;
			}

			const payload = Object.assign({}, data, { nonce: cfg.nonce });

			$.ajax({
				url: cfg.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: payload,
				success: (res) => {
					if (typeof onSuccess === 'function') {
						onSuccess(res);
					}
				},
				error: (xhr) => {
					const msg = this.extractError(xhr, fallbackErrorMessage);
					this.showFormNotice(msg, 'error');
				}
			});
		},

		extractError(xhr, fallbackMessage) {
			if (xhr && xhr.responseJSON) {
				const r = xhr.responseJSON;

				if (typeof r.data === 'string' && r.data.trim()) {
					return r.data.trim();
				}

				if (r.data && typeof r.data.message === 'string' && r.data.message.trim()) {
					return r.data.message.trim();
				}
			}

			if (xhr && typeof xhr.responseText === 'string' && xhr.responseText.trim()) {
				try {
					const parsed = JSON.parse(xhr.responseText);
					if (parsed && typeof parsed.data === 'string' && parsed.data.trim()) {
						return parsed.data.trim();
					}
				} catch (e) {
					// ignore JSON parse failure
				}
			}

			return fallbackMessage || 'Request failed.';
		},

		loadSkills() {
			$('#nw-skills-tbody').html(
				'<tr class="nw-loading-row"><td colspan="7" style="text-align:center;padding:32px;"><div class="nw-spinner"></div> Loading skills…</td></tr>'
			);

			this.request(
				{
					action: 'nw_skills_get_all',
					filter_category: $('#nw-filter-category').val() || ''
				},
				(res) => {
					if (!res || !res.success) {
						const msg = res && res.data ? res.data : 'Could not load skills.';
						this.showGlobalNotice(msg, 'error');
						$('#nw-skills-tbody').html('<tr><td colspan="7" style="text-align:center;padding:32px;color:#d63638;">Failed to load skills.</td></tr>');
						return;
					}

					this.allRows = Array.isArray(res.data) ? res.data : [];
					this.renderTable(this.getFilteredRows());
					this.updateStats(this.allRows);
				},
				'Failed to load skills.'
			);
		},

		getFilteredRows() {
			const q = ($('#nw-search').val() || '').trim().toLowerCase();

			if (!q) {
				return this.allRows.slice();
			}

			return this.allRows.filter((row) => {
				const haystack = [
					row.name,
					row.description,
					row.category,
					row.application,
					row.card_effect,
					Array.isArray(row.tags) ? row.tags.join(' ') : '',
					Array.isArray(row.linked_attributes) ? row.linked_attributes.join(' ') : ''
				]
					.join(' ')
					.toLowerCase();

				return haystack.indexOf(q) !== -1;
			});
		},

		updateStats(rows) {
			const total = rows.length;
			let active = 0;
			let inactive = 0;

			rows.forEach((row) => {
				if (row.is_active) {
					active++;
				} else {
					inactive++;
				}
			});

			$('#nw-total').text(total);
			$('#nw-active').text(active);
			$('#nw-inactive').text(inactive);
		},

		renderTable(rows) {
			if (!rows || !rows.length) {
				$('#nw-skills-tbody').html(
					'<tr><td colspan="7" style="text-align:center;padding:32px;color:#666;">No skills found.</td></tr>'
				);
				return;
			}

			let html = '';

			rows.forEach((s) => {
				const tags = Array.isArray(s.tags) ? s.tags : [];
				const img = s.img_url
					? '<img class="nw-skill-img" src="' + this.esc(s.img_url) + '" alt="" loading="lazy" style="max-width:48px;max-height:48px;border-radius:8px;">'
					: '<div class="nw-skill-img-placeholder" style="width:48px;height:48px;border-radius:8px;background:#111;display:flex;align-items:center;justify-content:center;">⚡</div>';

				const tagHtml = tags.length
					? tags.map((t) => '<span class="nw-tag" style="display:inline-block;padding:2px 8px;margin:2px;background:#111;border:1px solid #2b2b2b;border-radius:999px;">' + this.esc(t) + '</span>').join('')
					: '<span style="color:#666;">—</span>';

				html += ''
					+ '<tr class="' + (s.is_active ? '' : 'nw-row-inactive') + '" data-id="' + this.esc(s.id) + '">'
					+ '<td>' + img + '</td>'
					+ '<td>'
					+ '<div><strong>' + this.esc(s.name) + '</strong></div>'
					+ (s.description ? '<div style="color:#666;font-size:12px;margin-top:4px;">' + this.esc(this.truncate(s.description, 80)) + '</div>' : '')
					+ '</td>'
					+ '<td>' + (s.category ? this.esc(s.category) : '<span style="color:#666;">—</span>') + '</td>'
					+ '<td>' + (s.application ? this.esc(s.application) : '<span style="color:#666;">—</span>') + '</td>'
					+ '<td>' + tagHtml + '</td>'
					+ '<td>'
					+ '<label class="nw-toggle" style="display:inline-flex;align-items:center;gap:8px;">'
					+ '<input type="checkbox" class="nw-toggle-active" data-id="' + this.esc(s.id) + '"' + (s.is_active ? ' checked' : '') + '>'
					+ '<span>' + (s.is_active ? 'Yes' : 'No') + '</span>'
					+ '</label>'
					+ '</td>'
					+ '<td>'
					+ '<button type="button" class="button button-small nw-edit-btn" data-id="' + this.esc(s.id) + '">Edit</button> '
					+ '<button type="button" class="button button-small button-link-delete nw-delete-btn" data-id="' + this.esc(s.id) + '">Delete</button>'
					+ '</td>'
					+ '</tr>';
			});

			$('#nw-skills-tbody').html(html);
		},

		openModal(skill = null) {
			this.currentId = skill ? skill.id : null;

			$('#nw-modal-title').text(skill ? 'Edit Skill' : 'New Skill');
			$('#nw-save-label').text(skill ? 'Save Skill' : 'Create Skill');
			$('#nw-delete-btn').toggle(!!skill);

			$('#nw-field-id').val(skill ? skill.id : '');
			$('#nw-field-name').val(skill ? (skill.name || '') : '');
			$('#nw-field-description').val(skill ? (skill.description || '') : '');
			$('#nw-field-category').val(skill ? (skill.category || '') : '');
			$('#nw-field-application').val(skill ? (skill.application || '') : '');
			$('#nw-field-card_effect').val(skill ? (skill.card_effect || '') : '');
			$('#nw-field-img_url').val(skill ? (skill.img_url || '') : '');
			$('#nw-field-tags').val(skill && Array.isArray(skill.tags) ? skill.tags.join(', ') : '');
			$('#nw-field-linked_attributes').val(skill && Array.isArray(skill.linked_attributes) ? skill.linked_attributes.join(', ') : '');
			$('#nw-field-is_active').prop('checked', skill ? !!skill.is_active : true);

			this.clearFormNotice();
			this.updateImgPreview($('#nw-field-img_url').val());
			$('#nw-modal-overlay').show();

			setTimeout(() => $('#nw-field-name').trigger('focus'), 30);
		},

		closeModal() {
			this.currentId = null;
			$('#nw-skill-form')[0].reset();
			$('#nw-field-id').val('');
			$('#nw-field-is_active').prop('checked', true);
			this.updateImgPreview('');
			this.clearFormNotice();
			$('#nw-save-btn').prop('disabled', false);
			$('#nw-save-label').text('Save Skill');
			$('#nw-delete-btn').hide();
			$('#nw-modal-overlay').hide();
		},

		updateImgPreview(url) {
			const cleanUrl = (url || '').trim();

			if (cleanUrl) {
				$('#nw-img-preview').attr('src', cleanUrl);
				$('#nw-img-preview-wrap').show();
			} else {
				$('#nw-img-preview').attr('src', '');
				$('#nw-img-preview-wrap').hide();
			}
		},

		handleEdit(e) {
			const id = $(e.currentTarget).data('id');

			this.request(
				{
					action: 'nw_skills_get_one',
					id: id
				},
				(res) => {
					if (!res || !res.success || !res.data) {
						this.showGlobalNotice(res && res.data ? res.data : 'Could not load skill.', 'error');
						return;
					}

					this.openModal(res.data);
				},
				'Failed to load skill details.'
			);
		},

		saveSkill() {
			const name = ($('#nw-field-name').val() || '').trim();
			const category = ($('#nw-field-category').val() || '').trim();

			if (!name) {
				this.showFormNotice('Skill name is required.', 'error');
				return;
			}

			if (
				category &&
				Array.isArray(window.NW_SK.categories) &&
				window.NW_SK.categories.length &&
				window.NW_SK.categories.indexOf(category) === -1
			) {
				this.showFormNotice('Invalid category selected.', 'error');
				return;
			}

			$('#nw-save-btn').prop('disabled', true);
			$('#nw-save-label').text(this.currentId ? 'Saving…' : 'Creating…');

			this.request(
				{
					action: 'nw_skills_save',
					id: this.currentId || '',
					name: name,
					description: $('#nw-field-description').val() || '',
					category: category,
					application: $('#nw-field-application').val() || '',
					card_effect: $('#nw-field-card_effect').val() || '',
					img_url: $('#nw-field-img_url').val() || '',
					tags: $('#nw-field-tags').val() || '',
					linked_attributes: $('#nw-field-linked_attributes').val() || '',
					is_active: $('#nw-field-is_active').is(':checked') ? 1 : 0
				},
				(res) => {
					$('#nw-save-btn').prop('disabled', false);
					$('#nw-save-label').text(this.currentId ? 'Save Skill' : 'Create Skill');

					if (!res || !res.success) {
						this.showFormNotice(res && res.data ? res.data : 'Save failed.', 'error');
						return;
					}

					this.showGlobalNotice(this.currentId ? 'Skill updated.' : 'Skill created.', 'success');
					this.closeModal();
					this.loadSkills();
				},
				'Failed to save skill.'
			);
		},

		handleToggle(e) {
			const $checkbox = $(e.currentTarget);
			const id = $checkbox.data('id');
			const isActive = $checkbox.is(':checked') ? 1 : 0;

			this.request(
				{
					action: 'nw_skills_toggle',
					id: id,
					is_active: isActive
				},
				(res) => {
					if (!res || !res.success) {
						$checkbox.prop('checked', !$checkbox.is(':checked'));
						this.showGlobalNotice(res && res.data ? res.data : 'Toggle failed.', 'error');
						return;
					}

					this.showGlobalNotice('Skill status updated.', 'success');
					this.loadSkills();
				},
				'Failed to update skill status.'
			);
		},

		handleDelete(e) {
			const id = $(e.currentTarget).data('id');

			if (!window.confirm('Delete this skill? This cannot be undone.')) {
				return;
			}

			this.request(
				{
					action: 'nw_skills_delete',
					id: id
				},
				(res) => {
					if (!res || !res.success) {
						this.showGlobalNotice(res && res.data ? res.data : 'Delete failed.', 'error');
						return;
					}

					this.showGlobalNotice('Skill deleted.', 'success');
					this.closeModal();
					this.loadSkills();
				},
				'Failed to delete skill.'
			);
		},

		showGlobalNotice(msg, type) {
			const $n = $('#nw-notice');
			const bg = type === 'error' ? '#3a1111' : '#112b14';
			const color = type === 'error' ? '#ff8e8e' : '#9cff9c';
			const border = type === 'error' ? '#7a1f1f' : '#215c28';

			$n.stop(true, true)
				.css({
					background: bg,
					color: color,
					border: '1px solid ' + border
				})
				.text(msg || '')
				show();

			clearTimeout(this.noticeTimer);
			this.noticeTimer = setTimeout(() => {
				$n.fadeOut(200);
			}, 3500);
		},

		showFormNotice(msg, type) {
			const cls = type === 'error' ? 'notice-error' : 'notice-success';
			$('#nw-form-notice').html(
				'<div class="notice ' + cls + ' is-dismissible"><p>' + this.esc(msg || '') + '</p></div>'
			);
		},

		clearFormNotice() {
			$('#nw-form-notice').html('');
		},

		truncate(str, len) {
			const s = String(str || '');
			return s.length > len ? s.substring(0, len) + '…' : s;
		},

		esc(str) {
			if (str === null || typeof str === 'undefined') {
				return '';
			}
			return $('<div>').text(String(str)).html();
		}
	};

	$(document).ready(function () {
		NW_Skills.init();
	});

})(jQuery);
