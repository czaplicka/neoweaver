jQuery(function ($) {
    'use strict';

    const cfg = window.NWAchievements || {};
    const ajaxEndpoint = cfg.ajaxurl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
    const nonce = cfg.nonce || '';
    let editId = null;
    let allRows = [];
    let noticeTimer = null;

    /* ---------------------------------------------------------------- */
    /*  Lucide icons init                                                */
    /* ---------------------------------------------------------------- */

    function initIcons() {
        if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
            lucide.createIcons();
        }
    }

    /* ---------------------------------------------------------------- */
    /*  Helpers                                                          */
    /* ---------------------------------------------------------------- */

    function escH(s) {
        return $('<div>').text(String(s || '')).html();
    }

    function safeClassSuffix(value) {
        return String(value || '')
            .toLowerCase()
            .replace(/[^a-z0-9_-]/g, '');
    }

    function clearNoticeTimer() {
        if (noticeTimer) {
            clearTimeout(noticeTimer);
            noticeTimer = null;
        }
    }

    function showNotice(type, msg) {
        clearNoticeTimer();

        $('#nw-notice')
            .removeClass('nw-notice-success nw-notice-error')
            .addClass('nw-notice-' + safeClassSuffix(type))
            .text(msg || '')
            .show();

        noticeTimer = setTimeout(function () {
            $('#nw-notice').fadeOut();
            noticeTimer = null;
        }, 4000);
    }

    /* ---------------------------------------------------------------- */
    /*  Load                                                             */
    /* ---------------------------------------------------------------- */

    function load() {
        const cat = $('#nw-filter-category').val();
        const scope = $('#nw-filter-scope').val();

        if (!ajaxEndpoint) {
            showNotice('error', 'Missing AJAX endpoint.');
            return;
        }

        if (!nonce) {
            showNotice('error', 'Missing nonce.');
            return;
        }

        $('#nw-achievements-tbody').html(
            '<tr><td colspan="8" style="text-align:center;padding:32px;color:#555;">'
            + '<div class="nw-spinner"></div> Loading&hellip;</td></tr>'
        );

        $.post(ajaxEndpoint, {
            action: 'nw_achievements_get_all',
            nonce: nonce,
            filter_category: cat,
            filter_scope: scope
        }, function (r) {
            if (!r || !r.success) {
                showNotice('error', (r && r.data) || 'Load failed.');
                return;
            }

            allRows = r.data || [];
            renderTable(applyClientFilters(allRows));
        }).fail(function (xhr) {
            showNotice('error', 'Request failed (' + (xhr.status || 'network') + ').');
        });
    }

    /* ---------------------------------------------------------------- */
    /*  Client-side filters                                              */
    /* ---------------------------------------------------------------- */

    function applyClientFilters(rows) {
        const active = $('#nw-filter-active').val();
        const hidden = $('#nw-filter-hidden').val();
        const search = ($('#nw-search').val() || '').toLowerCase().trim();

        return rows.filter(function (a) {
            if (active === '1' && !a.is_active) return false;
            if (active === '0' && a.is_active) return false;
            if (hidden === '1' && !a.hidden_until_earned) return false;
            if (hidden === '0' && a.hidden_until_earned) return false;

            if (search) {
                const haystack = String((a.id || '') + ' ' + (a.title || '')).toLowerCase();
                if (haystack.indexOf(search) === -1) return false;
            }

            return true;
        });
    }

    /* ---------------------------------------------------------------- */
    /*  Render table                                                     */
    /* ---------------------------------------------------------------- */

    function renderTable(rows) {
        let total = 0;
        let active = 0;
        let inactive = 0;
        let account = 0;
        let character = 0;
        let hidden = 0;
        let html = '';

        if (!rows.length) {
            html = '<tr><td colspan="8" style="text-align:center;padding:32px;color:#555;">No achievements found.</td></tr>';
        }

        $.each(rows, function (_, a) {
            total++;
            if (a.is_active) {
                active++;
            } else {
                inactive++;
            }

            if (a.scope === 'account') account++;
            if (a.scope === 'character') character++;
            if (a.hidden_until_earned) hidden++;

            const catSafe = safeClassSuffix(a.category);
            const scopeSafe = safeClassSuffix(a.scope);
            const catCls = catSafe ? ' nw-cat-' + catSafe : '';
            const scopeCls = scopeSafe ? ' nw-scope-' + scopeSafe : '';
            const catLabel = a.category ? a.category.charAt(0).toUpperCase() + a.category.slice(1) : '—';
            const scpLabel = a.scope ? a.scope.charAt(0).toUpperCase() + a.scope.slice(1) : '—';

            const iconHtml = '<div class="nw-ach-icon" style="background:' + escH(a.bg_color || '#2c3e50') + '">'
                + '<i data-lucide="' + escH(a.icon_slug || 'trophy') + '"></i></div>';

            html += '<tr data-id="' + escH(a.id) + '" class="' + (a.is_active ? '' : 'nw-row-inactive') + '">'
                + '<td>' + iconHtml + '</td>'
                + '<td><div class="nw-ach-id">' + escH(a.id) + '</div>'
                + '<div class="nw-ach-title">' + escH(a.title) + '</div></td>'
                + '<td><span class="nw-cat-badge' + catCls + '">' + escH(catLabel) + '</span></td>'
                + '<td><span class="nw-scope-badge' + scopeCls + '">' + escH(scpLabel) + '</span></td>'
                + '<td>' + escH(a.goal || 1) + '</td>'
                + '<td>' + (a.hidden_until_earned ? '<span style="color:#ff9f43">&#128274;</span>' : '<span style="color:#333">—</span>') + '</td>'
                + '<td><label class="nw-toggle"><input type="checkbox" class="nw-toggle-active" data-id="' + escH(a.id) + '"' + (a.is_active ? ' checked' : '') + '>'
                + '<span class="nw-toggle-slider"></span></label></td>'
                + '<td><div class="nw-row-actions">'
                + '<button type="button" class="nw-action-btn nw-edit-btn" data-id="' + escH(a.id) + '">Edit</button>'
                + '</div></td>'
                + '</tr>';
        });

        $('#nw-achievements-tbody').html(html);
        $('#nw-total').text(total);
        $('#nw-active').text(active);
        $('#nw-inactive').text(inactive);
        $('#nw-count-account').text(account);
        $('#nw-count-character').text(character);
        $('#nw-count-hidden').text(hidden);

        initIcons();
    }

    /* ---------------------------------------------------------------- */
    /*  Modal                                                            */
    /* ---------------------------------------------------------------- */

    function openModal(ach) {
        editId = ach ? ach.id : null;
        $('#nw-modal-title').text(ach ? 'Edit Achievement' : 'New Achievement');
        $('#nw-save-label').text(ach ? 'Save Achievement' : 'Create Achievement');
        $('#nw-delete-btn').toggle(!!ach);

        $('#nw-field-original_id').val(ach ? ach.id : '');
        $('#nw-field-id').val(ach ? ach.id : '');
        $('#nw-field-title').val(ach ? ach.title : '');
        $('#nw-field-description').val(ach ? ach.description || '' : '');
        $('#nw-field-icon_slug').val(ach ? ach.icon_slug || 'trophy' : 'trophy');
        $('#nw-field-bg_color').val(ach ? ach.bg_color || '#2c3e50' : '#2c3e50');
        $('#nw-field-bg_color_picker').val(ach ? ach.bg_color || '#2c3e50' : '#2c3e50');
        $('#nw-field-scope').val(ach ? ach.scope || 'account' : 'account');
        $('#nw-field-category').val(ach ? ach.category || '' : '');
        $('#nw-field-goal').val(ach ? ach.goal || 1 : 1);
        $('#nw-field-hidden_until_earned').prop('checked', ach ? !!ach.hidden_until_earned : false);
        $('#nw-field-is_active').prop('checked', ach ? !!ach.is_active : true);

        updateBadgePreview();
        $('#nw-modal-overlay').show();
        initIcons();
    }

    function closeModal() {
        $('#nw-modal-overlay').hide();
        editId = null;
    }

    function updateBadgePreview() {
        const title = $('#nw-field-title').val() || 'Achievement Title';
        const desc = $('#nw-field-description').val() || 'Description…';
        const iconSlug = $('#nw-field-icon_slug').val() || 'trophy';
        const bgColor = $('#nw-field-bg_color').val() || '#2c3e50';

        $('#nw-preview-title').text(title);
        $('#nw-preview-desc').text(desc);
        $('#nw-badge-icon')
            .css('background', bgColor)
            .html('<i data-lucide="' + escH(iconSlug) + '"></i>');
        $('#nw-icon-preview').html('<i data-lucide="' + escH(iconSlug) + '"></i>');

        initIcons();
    }

    /* ---------------------------------------------------------------- */
    /*  Save                                                             */
    /* ---------------------------------------------------------------- */

    function save() {
        const isEditing = !!editId;
        const restoreLabel = isEditing ? 'Save Achievement' : 'Create Achievement';

        const payload = {
            action: 'nw_achievements_save',
            nonce: nonce,
            original_id: $('#nw-field-original_id').val() || '',
            id: $('#nw-field-id').val() || '',
            title: $('#nw-field-title').val() || '',
            description: $('#nw-field-description').val() || '',
            icon_slug: $('#nw-field-icon_slug').val() || 'trophy',
            bg_color: $('#nw-field-bg_color').val() || '#2c3e50',
            scope: $('#nw-field-scope').val() || 'account',
            category: $('#nw-field-category').val() || '',
            goal: $('#nw-field-goal').val() || '1',
            hidden_until_earned: $('#nw-field-hidden_until_earned').is(':checked') ? '1' : '0',
            is_active: $('#nw-field-is_active').is(':checked') ? '1' : '0'
        };

        $('#nw-save-btn').prop('disabled', true);
        $('#nw-save-label').text('Saving…');

        $.post(ajaxEndpoint, payload, function (r) {
            if (!r || !r.success) {
                showNotice('error', (r && r.data) || 'Save failed.');
                return;
            }

            showNotice('success', isEditing ? 'Achievement updated.' : 'Achievement created.');
            closeModal();
            load();
        }).fail(function (xhr) {
            showNotice('error', 'Request failed (' + (xhr.status || 'network') + ').');
        }).always(function () {
            $('#nw-save-btn').prop('disabled', false);
            $('#nw-save-label').text(restoreLabel);
        });
    }

    /* ---------------------------------------------------------------- */
    /*  Events                                                           */
    /* ---------------------------------------------------------------- */

    $('#nw-add-btn').on('click', function () { openModal(null); });
    $('#nw-refresh-btn').on('click', load);
    $('#nw-filter-category, #nw-filter-scope').on('change', load);
    $('#nw-filter-active, #nw-filter-hidden').on('change', function () {
        renderTable(applyClientFilters(allRows));
    });
    $('#nw-search').on('input', function () {
        renderTable(applyClientFilters(allRows));
    });

    $('#nw-modal-close, #nw-cancel-btn').on('click', closeModal);
    $('#nw-modal-overlay').on('click', function (e) {
        if ($(e.target).is('#nw-modal-overlay')) {
            closeModal();
        }
    });

    $('#nw-save-btn').on('click', save);

    $('#nw-field-title, #nw-field-description, #nw-field-icon_slug').on('input', updateBadgePreview);
    $('#nw-field-bg_color').on('input', function () {
        $('#nw-field-bg_color_picker').val($(this).val());
        updateBadgePreview();
    });
    $('#nw-field-bg_color_picker').on('input', function () {
        $('#nw-field-bg_color').val($(this).val());
        updateBadgePreview();
    });

    $(document).on('change', '.nw-toggle-active', function () {
        const id = $(this).data('id');
        const state = $(this).is(':checked');

        $.post(ajaxEndpoint, {
            action: 'nw_achievements_toggle',
            nonce: nonce,
            achievement_id: id,
            is_active: state ? 1 : 0
        }, function (r) {
            if (!r || !r.success) {
                showNotice('error', (r && r.data) || 'Toggle failed.');
                load();
                return;
            }

            $('tr[data-id="' + id + '"]').toggleClass('nw-row-inactive', !state);
        }).fail(function (xhr) {
            showNotice('error', 'Request failed (' + (xhr.status || 'network') + ').');
            load();
        });
    });

    $(document).on('click', '.nw-edit-btn', function () {
        const id = $(this).data('id');
        const ach = allRows.find(function (a) {
            return a.id === id;
        });

        if (ach) {
            openModal(ach);
        }
    });

    $('#nw-delete-btn').on('click', function () {
        if (!editId || !window.confirm('Delete this achievement? This cannot be undone.')) {
            return;
        }

        $.post(ajaxEndpoint, {
            action: 'nw_achievements_delete',
            nonce: nonce,
            achievement_id: editId
        }, function (r) {
            if (!r || !r.success) {
                showNotice('error', (r && r.data) || 'Delete failed.');
                return;
            }

            showNotice('success', 'Achievement deleted.');
            closeModal();
            load();
        }).fail(function (xhr) {
            showNotice('error', 'Request failed (' + (xhr.status || 'network') + ').');
        });
    });

    /* ---------------------------------------------------------------- */
    /*  Init                                                             */
    /* ---------------------------------------------------------------- */

    load();
});
