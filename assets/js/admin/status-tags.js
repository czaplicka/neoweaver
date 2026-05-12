// assets/js/admin/status-tags.js
(function ($) {
	'use strict';

	const NWStatusTags = {
		currentId: 0,
		rows: [],

		init() {
			if (!window.NW_ST || !window.NW_ST.ajax_url || !window.NW_ST.nonce) {
				this.showGlobalNotice('Missing AJAX config. Refresh the page.', 'error');
				return;
			}

			this.bindEvents();
			this.load();
		},

		bindEvents() {
			$('#nw-add-tag-btn').on('click', () => this.showForm());
			$('#nw-save-tag-btn').on('click', (e) => {
				e.preventDefault();
				this.save();
			});
			$('#nw-cancel-tag-btn').on('click', (e) => {
				e.preventDefault();
				this.hideForm();
			});

			$(document).on('click', '.nw-edit-tag', (e) => this.edit(e));
			$(document).on('click', '.nw-delete-tag', (e) => this.delete(e));
			$(document).on('change', '.nw-toggle-tag', (e) => this.toggle(e));
			$('#nw-delete-tag-btn').on('click', (e) => {
				e.preventDefault();
				this.deleteCurrent();
			});
		},

		request(data, onSuccess, fallbackMessage = 'Request failed.') {
			$.ajax({
				url: window.NW_ST.ajax_url,
				method: 'POST',
				dataType: 'json',
				data: Object.assign({}, data, { nonce: window.NW_ST.nonce }),
				success: (res) => {
					if (typeof onSuccess === 'function') {
						onSuccess(res);
					}
				},
				error: (xhr) => {
					this.showFormNotice(this.extractError(xhr, fallbackMessage), 'error');
				}
			});
		},

		extractError(xhr, fallback) {
			if (xhr && xhr.responseJSON) {
				if (typeof xhr.responseJSON.data === 'string' && xhr.responseJSON.data.trim()) {
					return xhr.responseJSON.data.trim();
				}
				if (
					xhr.responseJSON.data &&
					typeof xhr.responseJSON.data.message === 'string' &&
					xhr.responseJSON.data.message.trim()
				) {
					return xhr.responseJSON.data.message.trim();
				}
			}
			return fallback;
		},

		load() {
			$('#nw-status-tag-table-wrap').html('<p>Loading…</p>');

			this.request(
				{ action: 'nw_status_tags_load' },
				(res) => {
					if (!res || !res.success) {
						this.showGlobalNotice(res && res.data ? res.data : 'Failed to load status tags.', 'error');
						$('#nw-status-tag-table-wrap').html('<p style="color:#d63638;">Failed to load status tags.</p>');
						return;
					}

					this.rows = Array.isArray(res.data) ? res.data : [];
					this.renderTable(this.rows);
				},
				'Failed to load status tags.'
			);
		},

		renderTable(rows) {
			if (!rows.length) {
				$('#nw-status-tag-table-wrap').html('<p>No status tags found. Click "Add Status Tag" to create one.</p>');
				return;
			}

			let html = '<table class="wp-list-table widefat fixed striped"><thead><tr>';
			html += '<th>Label</th><th>Category</th><th>Duration</th><th>Flags</th><th>Color</th><th>Active</th><th>Actions</th>';
			html += '</tr></thead><tbody>';

			rows.forEach((tag) => {
				const flags = [
					tag.is_stackable ? 'Stackable' : null,
					tag.is_debuff ? 'Debuff' : 'Buff'
				].filter(Boolean).join(' · ');

				html += `<tr data-id="${this.esc(tag.id)}">
					<td>
						<strong>${this.esc(tag.label)}</strong>
						${tag.effect_description ? `<div style="color:#666;margin-top:4px;">${this.esc(this.truncate(tag.effect_description, 80))}</div>` : ''}
					</td>
					<td>${this.esc(tag.category || '—')}</td>
					<td>${this.esc(tag.duration || 'scene')}</td>
					<td>${this.esc(flags || '—')}</td>
					<td>
						<span style="display:inline-flex;align-items:center;gap:8px;">
							<span style="width:14px;height:14px;border-radius:999px;background:${this.esc(tag.color_hex || '#ff0000')};border:1px solid #ccc;display:inline-block;"></span>
							${this.esc(tag.color_hex || '#ff0000')}
						</span>
					</td>
					<td>
						<label>
							<input type="checkbox" class="nw-toggle-tag" data-id="${this.esc(tag.id)}" ${tag.is_active ? 'checked' : ''}>
							${tag.is_active ? 'Active' : 'Inactive'}
						</label>
					</td>
					<td>
						<button type="button" class="button button-small nw-edit-tag" data-id="${this.esc(tag.id)}">Edit</button>
						<button type="button" class="button button-small button-link-delete nw-delete-tag" data-id="${this.esc(tag.id)}">Delete</button>
					</td>
				</tr>`;
			});

			html += '</tbody></table>';
			$('#nw-status-tag-table-wrap').html(html);
		},

		showForm(tag = null) {
			this.currentId = tag ? parseInt(tag.id, 10) : 0;

			$('#nw-form-title').text(tag ? 'Edit Status Tag' : 'Add Status Tag');
			$('#nw-save-tag-btn').text(tag ? 'Save Tag' : 'Create Tag');
			$('#nw-delete-tag-btn').toggle(!!tag);

			$('#nw-field-label').val(tag ? (tag.label || '') : '');
			$('#nw-field-category').val(tag ? (tag.category || '') : '');
			$('#nw-field-effect_description').val(tag ? (tag.effect_description || '') : '');
			$('#nw-field-mechanic_modifier').val(tag ? (tag.mechanic_modifier || '') : '');
			$('#nw-field-duration').val(tag ? (tag.duration || 'scene') : 'scene');
			$('#nw-field-source').val(tag ? (tag.source || '') : '');
			$('#nw-field-color_hex').val(tag ? (tag.color_hex || '#ff0000') : '#ff0000');
			$('#nw-field-is_stackable').prop('checked', tag ? !!tag.is_stackable : false);
			$('#nw-field-is_debuff').prop('checked', tag ? !!tag.is_debuff : true);
			$('#nw-field-is_active').prop('checked', tag ? !!tag.is_active : true);

			this.clearFormNotice();
			$('#nw-status-tag-form-wrap').slideDown(180);
		},

		hideForm() {
			this.currentId = 0;
			$('#nw-status-tag-form-wrap').slideUp(180);
			$('#nw-field-label').val('');
			$('#nw-field-category').val('');
			$('#nw-field-effect_description').val('');
			$('#nw-field-mechanic_modifier').val('');
			$('#nw-field-duration').val('scene');
			$('#nw-field-source').val('');
			$('#nw-field-color_hex').val('#ff0000');
			$('#nw-field-is_stackable').prop('checked', false);
			$('#nw-field-is_debuff').prop('checked', true);
			$('#nw-field-is_active').prop('checked', true);
			$('#nw-delete-tag-btn').hide();
			this.clearFormNotice();
		},

		edit(e) {
			const id = parseInt($(e.currentTarget).data('id'), 10);
			const tag = this.rows.find((row) => parseInt(row.id, 10) === id);

			if (!tag) {
				this.showGlobalNotice('Status tag not found.', 'error');
				return;
			}

			this.showForm(tag);
		},

		save() {
			const label = ($('#nw-field-label').val() || '').trim();

			if (!label) {
				this.showFormNotice('Label is required.', 'error');
				return;
			}

			$('#nw-save-tag-btn').prop('disabled', true).text('Saving…');

			this.request(
				{
					action: 'nw_status_tags_save',
					id: this.currentId || '',
					label: label,
					category: $('#nw-field-category').val() || '',
					effect_description: $('#nw-field-effect_description').val() || '',
					mechanic_modifier: $('#nw-field-mechanic_modifier').val() || '',
					duration: $('#nw-field-duration').val() || 'scene',
					source: $('#nw-field-source').val() || '',
					color_hex: $('#nw-field-color_hex').val() || '#ff0000',
					is_stackable: $('#nw-field-is_stackable').is(':checked') ? 1 : 0,
					is_debuff: $('#nw-field-is_debuff').is(':checked') ? 1 : 0,
					is_active: $('#nw-field-is_active').is(':checked') ? 1 : 0
				},
				(res) => {
					$('#nw-save-tag-btn').prop('disabled', false).text(this.currentId ? 'Save Tag' : 'Create Tag');

					if (!res || !res.success) {
						this.showFormNotice(res && res.data ? res.data : 'Save failed.', 'error');
						return;
					}

					this.showGlobalNotice(this.currentId ? 'Status tag updated.' : 'Status tag created.', 'success');
					this.hideForm();
					this.load();
				},
				'Failed to save status tag.'
			);
		},

		toggle(e) {
			const $el = $(e.currentTarget);
			const id = parseInt($el.data('id'), 10);
			const value = $el.is(':checked');

			this.request(
				{
					action: 'nw_status_tags_toggle',
					id: id,
					value: value ? 1 : 0
				},
				(res) => {
					if (!res || !res.success) {
						$el.prop('checked', !value);
						this.showGlobalNotice(res && res.data ? res.data : 'Toggle failed.', 'error');
						return;
					}
					this.showGlobalNotice('Status updated.', 'success');
					this.load();
				},
				'Failed to toggle status tag.'
			);
		},

		delete(e) {
			const id = parseInt($(e.currentTarget).data('id'), 10);
			this.deleteById(id);
		},

		deleteCurrent() {
			if (!this.currentId) {
				return;
			}
			this.deleteById(this.currentId);
		},

		deleteById(id) {
			if (!window.confirm('Delete this status tag? This cannot be undone.')) {
				return;
			}

			this.request(
				{
					action: 'nw_status_tags_delete',
					id: id
				},
				(res) => {
					if (!res || !res.success) {
						this.showGlobalNotice(res && res.data ? res.data : 'Delete failed.', 'error');
						return;
					}
					this.showGlobalNotice('Status tag deleted.', 'success');
					this.hideForm();
					this.load();
				},
				'Failed to delete status tag.'
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
				.show();

			clearTimeout(this.noticeTimer);
			this.noticeTimer = setTimeout(() => {
				$n.fadeOut(200);
			}, 3500);
		},

		showFormNotice(msg, type) {
			const cls = type === 'error' ? 'notice-error' : 'notice-success';
			$('#nw-form-notice').html(`<div class="notice ${cls} is-dismissible"><p>${this.esc(msg)}</p></div>`);
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
		NWStatusTags.init();
	});

})(jQuery);
