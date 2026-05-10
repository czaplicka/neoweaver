// admin/js/skills-admin.js
(function($) {
    'use strict';

    const NW_Skills = {
        currentId: null,

        init() {
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

        showForm(skill = null) {
            this.currentId = skill ? skill.id : null;
            $('#nw-form-title').text(skill ? 'Edit Skill' : 'Add Skill');
            $('#nw-field-name').val(skill ? skill.name : '');
            $('#nw-field-description').val(skill ? skill.description || '' : '');
            $('#nw-field-category').val(skill ? skill.category || '' : '');
            $('#nw-field-stat').val(skill ? skill.stat || '' : '');
            $('#nw-skill-form-wrap').slideDown(200);
        },

        hideForm() {
            this.currentId = null;
            $('#nw-skill-form-wrap').slideUp(200);
            $('#nw-field-name, #nw-field-description, #nw-field-category, #nw-field-stat').val('');
            this.clearNotice();
        },

        loadSkills() {
            $('#nw-skill-table-wrap').html('<p>Loading…</p>');
            
            $.post(NW_SK.ajax_url, {
                action: 'nw_skills_load',
                nonce: NW_SK.nonce
            }, (res) => {
                if (res.success) {
                    this.renderTable(res.data);
                } else {
                    this.showNotice('Error loading skills: ' + res.data, 'error');
                    $('#nw-skill-table-wrap').html('<p style="color:#d63638;">Failed to load skills.</p>');
                }
            });
        },

        renderTable(skills) {
            if (!skills || skills.length === 0) {
                $('#nw-skill-table-wrap').html('<p>No skills found. Click "Add Skill" to create one.</p>');
                return;
            }

            let html = '<table class="wp-list-table widefat fixed striped"><thead><tr>';
            html += '<th>Name</th><th>Category</th><th>Linked Stat</th><th>Description</th><th>Actions</th>';
            html += '</tr></thead><tbody>';

            skills.forEach(skill => {
                html += `<tr data-id="${this.esc(skill.id)}">
                    <td><strong>${this.esc(skill.name)}</strong></td>
                    <td>${this.esc(skill.category || '—')}</td>
                    <td>${this.esc(skill.stat || '—')}</td>
                    <td>${this.esc(skill.description || '—')}</td>
                    <td>
                        <button class="button button-small nw-edit-skill" data-id="${skill.id}">Edit</button>
                        <button class="button button-small button-link-delete nw-delete-skill" data-id="${skill.id}">Delete</button>
                    </td>
                </tr>`;
            });

            html += '</tbody></table>';
            $('#nw-skill-table-wrap').html(html);
        },

        editSkill(e) {
            const id = $(e.currentTarget).data('id');
            
            // Załaduj wszystkie skills i znajdź ten konkretny
            $.post(NW_SK.ajax_url, {
                action: 'nw_skills_load',
                nonce: NW_SK.nonce
            }, (res) => {
                if (res.success) {
                    const skill = res.data.find(s => s.id == id);
                    if (skill) {
                        this.showForm(skill);
                    }
                }
            });
        },

        saveSkill() {
            const name = $('#nw-field-name').val().trim();
            
            if (!name) {
                this.showNotice('Skill name is required.', 'error');
                return;
            }

            const data = {
                action: 'nw_skills_save',
                nonce: NW_SK.nonce,
                id: this.currentId || '',
                name: name,
                description: $('#nw-field-description').val(),
                category: $('#nw-field-category').val(),
                stat: $('#nw-field-stat').val()
            };

            $('#nw-save-skill-btn').prop('disabled', true).text('Saving…');

            $.post(NW_SK.ajax_url, data, (res) => {
                $('#nw-save-skill-btn').prop('disabled', false).text('Save Skill');
                
                if (res.success) {
                    this.showNotice(this.currentId ? 'Skill updated.' : 'Skill created.', 'success');
                    this.hideForm();
                    this.loadSkills();
                } else {
                    this.showNotice('Error: ' + res.data, 'error');
                }
            });
        },

        deleteSkill(e) {
            if (!confirm('Are you sure you want to delete this skill? This cannot be undone.')) {
                return;
            }

            const id = $(e.currentTarget).data('id');

            $.post(NW_SK.ajax_url, {
                action: 'nw_skills_delete',
                nonce: NW_SK.nonce,
                id: id
            }, (res) => {
                if (res.success) {
                    this.showNotice('Skill deleted.', 'success');
                    this.loadSkills();
                } else {
                    this.showNotice('Error: ' + res.data, 'error');
                }
            });
        },

        showNotice(msg, type) {
            const cls = type === 'error' ? 'notice-error' : 'notice-success';
            $('#nw-form-notice').html(`<div class="notice ${cls} is-dismissible"><p>${msg}</p></div>`);
            setTimeout(() => this.clearNotice(), 4000);
        },

        clearNotice() {
            $('#nw-form-notice').html('');
        },

        esc(str) {
            if (!str && str !== 0) return '';
            return $('<div>').text(String(str)).html();
        }
    };

    $(document).ready(() => NW_Skills.init());

})(jQuery);
