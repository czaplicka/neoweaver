// admin/js/skills-admin.js
(function ($) {
    'use strict';

    const NW_Skills = {
        currentId: null,

        init() {
            console.log('NW_SK object:', window.NW_SK);
            this.bindEvents();
            this.loadSkills();
        },

        bindEvents() {
            $('#nw-add-skill-btn').on('click', () => this.showForm());
            $('#nw-save-skill-btn').on('click', () => this.saveSkill());
            $('#nw-cancel-skill-btn').on('click', () => this.hideForm());
            $(document).on('click', '.nw-edit-skill', (e) => this.editSkill(e));
            $(document).on('click', '.nw-delete-skill', (e) => this.deleteSkill(e));
        },

        getAjaxConfig() {
            const ajaxUrl = window.NW_SK && window.NW_SK.ajax_url ? window.NW_SK.ajax_url : '';
            const nonce = window.NW_SK && window.NW_SK.nonce ? window.NW_SK.nonce : '';

            console.log('Using ajaxUrl:', ajaxUrl);
            console.log('Using nonce:', nonce);

            return { ajaxUrl, nonce };
        },

        request(data, onSuccess, onErrorMessage = 'AJAX request failed.') {
            const { ajaxUrl, nonce } = this.getAjaxConfig();

            if (!ajaxUrl) {
                this.showNotice('Missing AJAX URL.', 'error');
                return;
            }

            if (!nonce) {
                this.showNotice('Missing security token. Refresh the page.', 'error');
                return;
            }

            const payload = Object.assign({}, data, { nonce: nonce });

            console.log('Sending payload:', payload);

            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: payload,
                success: (res) => {
                    console.log('AJAX success:', res);
                    if (typeof onSuccess === 'function') {
                        onSuccess(res);
                    }
                },
                error: (xhr) => {
                    console.log('AJAX error status:', xhr.status);
                    console.log('AJAX error response:', xhr.responseText);
                    this.showNotice(onErrorMessage, 'error');
                }
            });
        },

        showForm(skill = null) {
            this.currentId = skill ? skill.id : null;
            $('#nw-form-title').text(skill ? 'Edit Skill' : 'Add Skill');
            $('#nw-field-name').val(skill ? skill.name : '');
            $('#nw-field-description').val(skill ? (skill.description || '') : '');
            $('#nw-field-category').val(skill ? (skill.category || '') : '');
            $('#nw-field-stat').val(skill ? (skill.stat || '') : '');
            $('#nw-skill-form-wrap').slideDown(200);
        },

        hideForm() {
            this.currentId = null;
            $('#nw-skill-form-wrap').slideUp(200);
            $('#nw-field-name').val('');
            $('#nw-field-description').val('');
            $('#nw-field-category').val('');
            $('#nw-field-stat').val('');
            this.clearNotice();
        },

        loadSkills() {
            $('#nw-skill-table-wrap').html('<p>Loading…</p>');

            this.request(
                {
                    action: 'nw_skills_load'
                },
                (res) => {
                    if (!res || !res.success) {
                        this.showNotice('Error loading skills: ' + (res && res.data ? res.data : 'Unknown error'), 'error');
                        $('#nw-skill-table-wrap').html('<p style="color:#d63638;">Failed to load skills.</p>');
                        return;
                    }

                    const rows = Array.isArray(res.data) ? res.data : [];
                    this.renderTable(rows);
                },
                'Failed to load skills.'
            );
        },

        renderTable(skills) {
            if (!skills || skills.length === 0) {
                $('#nw-skill-table-wrap').html('<p>No skills found. Click "Add Skill" to create one.</p>');
                return;
            }

            let html = '<table class="wp-list-table widefat fixed striped"><thead><tr>';
            html += '<th>Name</th><th>Category</th><th>Linked Stat</th><th>Description</th><th>Actions</th>';
            html += '</tr></thead><tbody>';

            skills.forEach((skill) => {
                html += `<tr data-id="${this.esc(skill.id)}">
                    <td><strong>${this.esc(skill.name)}</strong></td>
                    <td>${this.esc(skill.category || '—')}</td>
                    <td>${this.esc(skill.stat || '—')}</td>
                    <td>${this.esc(skill.description || '—')}</td>
                    <td>
                        <button type="button" class="button button-small nw-edit-skill" data-id="${this.esc(skill.id)}">Edit</button>
                        <button type="button" class="button button-small button-link-delete nw-delete-skill" data-id="${this.esc(skill.id)}">Delete</button>
                    </td>
                </tr>`;
            });

            html += '</tbody></table>';
            $('#nw-skill-table-wrap').html(html);
        },

        editSkill(e) {
            const id = $(e.currentTarget).data('id');

            this.request(
                {
                    action: 'nw_skills_load'
                },
                (res) => {
                    if (!res || !res.success || !Array.isArray(res.data)) {
                        this.showNotice('Could not load skill data.', 'error');
                        return;
                    }

                    const skill = res.data.find((s) => String(s.id) === String(id));

                    if (skill) {
                        this.showForm(skill);
                    } else {
                        this.showNotice('Skill not found.', 'error');
                    }
                },
                'Failed to load skill details.'
            );
        },

        saveSkill() {
            const name = ($('#nw-field-name').val() || '').trim();

            if (!name) {
                this.showNotice('Skill name is required.', 'error');
                return;
            }

            $('#nw-save-skill-btn').prop('disabled', true).text('Saving…');

            this.request(
                {
                    action: 'nw_skills_save',
                    id: this.currentId || '',
                    name: name,
                    description: $('#nw-field-description').val() || '',
                    category: $('#nw-field-category').val() || '',
                    stat: $('#nw-field-stat').val() || ''
                },
                (res) => {
                    $('#nw-save-skill-btn').prop('disabled', false).text('Save Skill');

                    if (!res || !res.success) {
                        this.showNotice('Error: ' + (res && res.data ? res.data : 'Unknown error'), 'error');
                        return;
                    }

                    this.showNotice(this.currentId ? 'Skill updated.' : 'Skill created.', 'success');
                    this.hideForm();
                    this.loadSkills();
                },
                'Failed to save skill.'
            );
        },

        deleteSkill(e) {
            if (!confirm('Are you sure you want to delete this skill? This cannot be undone.')) {
                return;
            }

            const id = $(e.currentTarget).data('id');

            this.request(
                {
                    action: 'nw_skills_delete',
                    id: id
                },
                (res) => {
                    if (!res || !res.success) {
                        this.showNotice('Error: ' + (res && res.data ? res.data : 'Unknown error'), 'error');
                        return;
                    }

                    this.showNotice('Skill deleted.', 'success');
                    this.loadSkills();
                },
                'Failed to delete skill.'
            );
        },

        showNotice(msg, type) {
            const cls = type === 'error' ? 'notice-error' : 'notice-success';
            $('#nw-form-notice').html(`<div class="notice ${cls} is-dismissible"><p>${this.esc(msg)}</p></div>`);
            setTimeout(() => this.clearNotice(), 4000);
        },

        clearNotice() {
            $('#nw-form-notice').html('');
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
