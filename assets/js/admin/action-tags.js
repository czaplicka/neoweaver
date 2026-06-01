jQuery(function ($) {
    'use strict';

    if (typeof NWActionTags === 'undefined') {
        return;
    }

    const state = {
        activeTab: 'tags',
        tags: [],
        cats: [],
        hud: [],
        filters: {
            tagsSearch: '',
            tagsCat: '',
            tagsSentiment: '',
            catsSearch: '',
            hudSearch: ''
        }
    };

    const els = {
        notice: $('#nw-at-notice'),

        tabButtons: $('.nw-tab-btn'),
        tabPanels: $('.nw-tab-panel'),

        tagsTbody: $('#nw-tags-tbody'),
        catsTbody: $('#nw-cats-tbody'),
        hudTbody: $('#nw-hud-tbody'),

        tagsSearch: $('#nw-tags-search'),
        tagsFilterCat: $('#nw-tags-filter-cat'),
        tagsFilterSentiment: $('#nw-tags-filter-sentiment'),
        catsSearch: $('#nw-cats-search'),
        hudSearch: $('#nw-hud-search'),

        tagsRefresh: $('#nw-tags-refresh-btn'),
        catsRefresh: $('#nw-cats-refresh-btn'),
        hudRefresh: $('#nw-hud-refresh-btn'),

        tagModal: $('#nw-tag-modal-overlay'),
        catModal: $('#nw-cat-modal-overlay'),
        hudModal: $('#nw-hud-modal-overlay')
    };

    function createIconsSafe() {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function debounce(fn, delay) {
        let timer = null;
        return function () {
            const context = this;
            const args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(context, args);
            }, delay);
        };
    }

    function normalizeHex(value, fallback) {
        let v = String(value || '').trim();
        if (!v) return fallback || '#adff00';
        if (v.charAt(0) !== '#') v = '#' + v;
        if (/^#[0-9a-fA-F]{6}$/.test(v)) return v.toLowerCase();
        return fallback || '#adff00';
    }

    function normalizeSlug(value) {
        return String(value || '')
            .toLowerCase()
            .replace(/[\s\-]+/g, '_')
            .replace(/[^a-z0-9_]/g, '')
            .replace(/_+/g, '_')
            .replace(/^_+|_+$/g, '');
    }

    function showNotice(message, type) {
        const cls = type === 'error' ? 'nw-notice-error' : 'nw-notice-success';
        els.notice
            .stop(true, true)
            .removeClass('nw-notice-error nw-notice-success')
            .addClass(cls)
            .html(escapeHtml(message))
            .fadeIn(150);

        setTimeout(function () {
            els.notice.fadeOut(250);
        }, 2800);
    }

    function ajax(action, data) {
        return $.ajax({
            url: NWActionTags.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: $.extend({}, data || {}, {
                action: action,
                nonce: NWActionTags.nonce
            })
        });
    }

    function openModal($modal) {
        $modal.fadeIn(120);
        $('body').addClass('nw-modal-open');
        createIconsSafe();
    }

    function closeModal($modal) {
        $modal.fadeOut(120);
        $('body').removeClass('nw-modal-open');
    }

    function closeAllModals() {
        $('.nw-modal-overlay').fadeOut(120);
        $('body').removeClass('nw-modal-open');
    }

    function setActiveTab(tab) {
        state.activeTab = tab;
        els.tabButtons.removeClass('nw-tab-active');
        $('.nw-tab-btn[data-tab="' + tab + '"]').addClass('nw-tab-active');

        els.tabPanels.addClass('nw-hidden');
        $('#nw-tab-' + tab).removeClass('nw-hidden');
        createIconsSafe();
    }

    function getCatById(id) {
        id = parseInt(id, 10);
        return state.cats.find(function (item) {
            return parseInt(item.id, 10) === id;
        }) || null;
    }

    function getHudById(id) {
        id = parseInt(id, 10);
        return state.hud.find(function (item) {
            return parseInt(item.id, 10) === id;
        }) || null;
    }

    function getTagById(id) {
        id = parseInt(id, 10);
        return state.tags.find(function (item) {
            return parseInt(item.id, 10) === id;
        }) || null;
    }

    function sentimentClass(sentiment) {
        if (sentiment === 'positive') return 'style="color:#adff00;"';
        if (sentiment === 'negative') return 'style="color:#ff5050;"';
        return 'style="color:#888;"';
    }

    function renderStats() {
        const totalTags = state.tags.length;
        const activeTags = state.tags.filter(function (t) { return parseInt(t.is_active, 10) === 1; }).length;
        const positiveTags = state.tags.filter(function (t) { return t.sentiment === 'positive'; }).length;
        const negativeTags = state.tags.filter(function (t) { return t.sentiment === 'negative'; }).length;
        const neutralTags = state.tags.filter(function (t) { return t.sentiment === 'neutral'; }).length;

        $('#nw-tags-total').text(totalTags);
        $('#nw-tags-active').text(activeTags);
        $('#nw-tags-pos').text(positiveTags);
        $('#nw-tags-neg').text(negativeTags);
        $('#nw-tags-neu').text(neutralTags);
        $('#nw-cats-total').text(state.cats.length);
        $('#nw-hud-total').text(state.hud.length);

        $('#nw-tab-count-tags').text(totalTags);
        $('#nw-tab-count-cats').text(state.cats.length);
        $('#nw-tab-count-hud').text(state.hud.length);
    }

    function populateCategoryFilters() {
        const current = els.tagsFilterCat.val() || '';
        const opts = ['<option value="">All categories</option>'];

        state.cats.forEach(function (cat) {
            opts.push(
                '<option value="' + parseInt(cat.id, 10) + '">' +
                escapeHtml(cat.display_name || cat.internal_name || '—') +
                '</option>'
            );
        });

        els.tagsFilterCat.html(opts.join(''));
        els.tagsFilterCat.val(current);
    }

    function populateTagCategorySelect(selectedId) {
        const $select = $('#nw-tag-field-category');
        const opts = ['<option value="">— select —</option>'];

        state.cats.forEach(function (cat) {
            opts.push(
                '<option value="' + parseInt(cat.id, 10) + '">' +
                escapeHtml(cat.display_name || cat.internal_name || '—') +
                '</option>'
            );
        });

        $select.html(opts.join(''));
        if (selectedId) $select.val(String(selectedId));
    }

    function populateCatHudSelect(selectedId) {
        const $select = $('#nw-cat-field-hud');
        const opts = ['<option value="">— select —</option>'];

        state.hud.forEach(function (group) {
            opts.push(
                '<option value="' + parseInt(group.id, 10) + '">' +
                escapeHtml(group.display_label || group.slug || '—') +
                '</option>'
            );
        });

        $select.html(opts.join(''));
        if (selectedId) $select.val(String(selectedId));
    }

    function renderTags() {
        const q = state.filters.tagsSearch.trim().toLowerCase();
        const catFilter = state.filters.tagsCat;
        const sentimentFilter = state.filters.tagsSentiment;

        const filtered = state.tags.filter(function (tag) {
            const cat = getCatById(tag.category_id);
            const catName = cat ? (cat.display_name || cat.internal_name || '') : '';
            const haystack = [
                tag.name,
                tag.description,
                tag.sentiment,
                catName
            ].join(' ').toLowerCase();

            const matchSearch = !q || haystack.indexOf(q) !== -1;
            const matchCat = !catFilter || String(tag.category_id) === String(catFilter);
            const matchSentiment = !sentimentFilter || String(tag.sentiment) === String(sentimentFilter);

            return matchSearch && matchCat && matchSentiment;
        });

        if (!filtered.length) {
            els.tagsTbody.html('<tr><td colspan="7">No tags found.</td></tr>');
            createIconsSafe();
            return;
        }

        const rows = filtered.map(function (tag) {
            const color = normalizeHex(tag.color, '#adff00');
            const impact = parseFloat(tag.impact || 0);
            const impactClass = impact > 0 ? 'nw-at-impact nw-at-impact-pos' : (impact < 0 ? 'nw-at-impact nw-at-impact-neg' : 'nw-at-impact');
            const cat = getCatById(tag.category_id);
            const active = parseInt(tag.is_active, 10) === 1;
            const desc = tag.description ? '<div class="nw-at-tag-desc">' + escapeHtml(tag.description) + '</div>' : '';

            return '' +
                '<tr>' +
                    '<td><span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:' + escapeHtml(color) + ';border:1px solid rgba(255,255,255,.12);"></span></td>' +
                    '<td>' +
                        '<div class="nw-at-tag-name">' + escapeHtml(tag.name) + '</div>' +
                        desc +
                    '</td>' +
                    '<td>' + escapeHtml(cat ? (cat.display_name || cat.internal_name) : '—') + '</td>' +
                    '<td><span class="nw-at-sentiment" ' + sentimentClass(tag.sentiment) + '>' + escapeHtml(tag.sentiment || 'neutral') + '</span></td>' +
                    '<td><span class="' + impactClass + '">' + (impact > 0 ? '+' : '') + impact.toFixed(2) + '</span></td>' +
                    '<td>' + (active ? '<span style="color:#adff00;">Yes</span>' : '<span style="color:#777;">No</span>') + '</td>' +
                    '<td>' +
                        '<button class="nw-btn nw-btn-ghost nw-btn-sm nw-tags-edit-btn" data-id="' + parseInt(tag.id, 10) + '"><i data-lucide="pencil" style="width:12px;height:12px;"></i></button> ' +
                        '<button class="nw-btn nw-btn-ghost nw-btn-sm nw-tags-dup-btn" data-id="' + parseInt(tag.id, 10) + '"><i data-lucide="copy" style="width:12px;height:12px;"></i></button>' +
                    '</td>' +
                '</tr>';
        });

        els.tagsTbody.html(rows.join(''));
        createIconsSafe();
    }

    function renderCats() {
        const q = state.filters.catsSearch.trim().toLowerCase();

        const filtered = state.cats.filter(function (cat) {
            const hud = getHudById(cat.hud_group_id);
            const hudName = hud ? (hud.display_label || hud.slug || '') : '';
            const haystack = [
                cat.internal_name,
                cat.display_name,
                cat.description,
                hudName
            ].join(' ').toLowerCase();

            return !q || haystack.indexOf(q) !== -1;
        });

        if (!filtered.length) {
            els.catsTbody.html('<tr><td colspan="6">No categories found.</td></tr>');
            createIconsSafe();
            return;
        }

        const rows = filtered.map(function (cat) {
            const color = normalizeHex(cat.ui_color, '#adff00');
            const hud = getHudById(cat.hud_group_id);
            return '' +
                '<tr>' +
                    '<td><span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:' + escapeHtml(color) + ';border:1px solid rgba(255,255,255,.12);"></span></td>' +
                    '<td><span class="nw-at-code">' + escapeHtml(cat.internal_name) + '</span></td>' +
                    '<td>' +
                        '<div class="nw-at-tag-name">' + escapeHtml(cat.display_name) + '</div>' +
                        (cat.description ? '<div class="nw-at-tag-desc">' + escapeHtml(cat.description) + '</div>' : '') +
                    '</td>' +
                    '<td>' + parseInt(cat.sort_order || 0, 10) + '</td>' +
                    '<td>' + (hud ? '<span class="nw-at-hud-badge">' + escapeHtml(hud.display_label || hud.slug) + '</span>' : '—') + '</td>' +
                    '<td>' +
                        '<button class="nw-btn nw-btn-ghost nw-btn-sm nw-cats-edit-btn" data-id="' + parseInt(cat.id, 10) + '"><i data-lucide="pencil" style="width:12px;height:12px;"></i></button> ' +
                        '<button class="nw-btn nw-btn-ghost nw-btn-sm nw-cats-dup-btn" data-id="' + parseInt(cat.id, 10) + '"><i data-lucide="copy" style="width:12px;height:12px;"></i></button>' +
                    '</td>' +
                '</tr>';
        });

        els.catsTbody.html(rows.join(''));
        createIconsSafe();
    }

    function renderHud() {
        const q = state.filters.hudSearch.trim().toLowerCase();

        const filtered = state.hud.filter(function (group) {
            const haystack = [
                group.slug,
                group.display_label,
                group.icon
            ].join(' ').toLowerCase();

            return !q || haystack.indexOf(q) !== -1;
        });

        if (!filtered.length) {
            els.hudTbody.html('<tr><td colspan="6">No HUD groups found.</td></tr>');
            createIconsSafe();
            return;
        }

        const rows = filtered.map(function (group) {
            const color = normalizeHex(group.base_color, '#adff00');
            const icon = group.icon
                ? '<i data-lucide="' + escapeHtml(group.icon) + '" style="width:14px;height:14px;color:' + escapeHtml(color) + ';"></i>'
                : '—';

            return '' +
                '<tr>' +
                    '<td><span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:' + escapeHtml(color) + ';border:1px solid rgba(255,255,255,.12);"></span></td>' +
                    '<td>' + icon + '</td>' +
                    '<td><span class="nw-at-code">' + escapeHtml(group.slug) + '</span></td>' +
                    '<td><div class="nw-at-tag-name">' + escapeHtml(group.display_label) + '</div></td>' +
                    '<td>' + parseInt(group.sort_order || 0, 10) + '</td>' +
                    '<td>' +
                        '<button class="nw-btn nw-btn-ghost nw-btn-sm nw-hud-edit-btn" data-id="' + parseInt(group.id, 10) + '"><i data-lucide="pencil" style="width:12px;height:12px;"></i></button> ' +
                        '<button class="nw-btn nw-btn-ghost nw-btn-sm nw-hud-dup-btn" data-id="' + parseInt(group.id, 10) + '"><i data-lucide="copy" style="width:12px;height:12px;"></i></button>' +
                    '</td>' +
                '</tr>';
        });

        els.hudTbody.html(rows.join(''));
        createIconsSafe();
    }

    function renderAll() {
        populateCategoryFilters();
        renderStats();
        renderTags();
        renderCats();
        renderHud();
    }

    function loadCats() {
        return ajax('nw_acats_load').done(function (res) {
            if (res && res.success) {
                state.cats = Array.isArray(res.data) ? res.data : [];
                populateCategoryFilters();
                populateTagCategorySelect();
                populateCatHudSelect();
                renderCats();
                renderTags();
                renderStats();
            } else {
                showNotice((res && res.data) ? res.data : 'Could not load categories.', 'error');
            }
        }).fail(function () {
            showNotice('Could not load categories.', 'error');
        });
    }

    function loadHud() {
        return ajax('nw_hud_groups_load').done(function (res) {
            if (res && res.success) {
                state.hud = Array.isArray(res.data) ? res.data : [];
                populateCatHudSelect();
                renderHud();
                renderCats();
                renderStats();
            } else {
                showNotice((res && res.data) ? res.data : 'Could not load HUD groups.', 'error');
            }
        }).fail(function () {
            showNotice('Could not load HUD groups.', 'error');
        });
    }

    function loadTags() {
        return ajax('nw_atags_load').done(function (res) {
            if (res && res.success) {
                state.tags = Array.isArray(res.data) ? res.data : [];
                renderTags();
                renderStats();
            } else {
                showNotice((res && res.data) ? res.data : 'Could not load tags.', 'error');
            }
        }).fail(function () {
            showNotice('Could not load tags.', 'error');
        });
    }

    function loadAll() {
        els.tagsTbody.html('<tr class="nw-loading-row"><td colspan="7"><span class="nw-spinner"></span> Loading…</td></tr>');
        els.catsTbody.html('<tr class="nw-loading-row"><td colspan="6"><span class="nw-spinner"></span> Loading…</td></tr>');
        els.hudTbody.html('<tr class="nw-loading-row"><td colspan="6"><span class="nw-spinner"></span> Loading…</td></tr>');

        $.when(loadHud(), loadCats(), loadTags()).done(function () {
            renderAll();
        });
    }

    function resetTagForm() {
        $('#nw-tag-modal-title').text('New Tag');
        $('#nw-tag-save-label').text('Create Tag');
        $('#nw-tag-delete-btn').hide();

        $('#nw-tag-field-id').val('');
        $('#nw-tag-field-name').val('');
        $('#nw-tag-field-category').val('');
        $('#nw-tag-field-sentiment').val('neutral');
        $('#nw-tag-field-impact').val('0.00');
        $('#nw-tag-field-description').val('');
        $('#nw-tag-field-color').val('#adff00');
        $('#nw-tag-field-color-picker').val('#adff00');
        $('#nw-tag-field-is-active').prop('checked', true);

        populateTagCategorySelect();
    }

    function resetCatForm() {
        $('#nw-cat-modal-title').text('New Category');
        $('#nw-cat-save-label').text('Create Category');
        $('#nw-cat-delete-btn').hide();

        $('#nw-cat-field-id').val('');
        $('#nw-cat-field-internal').val('');
        $('#nw-cat-field-display').val('');
        $('#nw-cat-field-description').val('');
        $('#nw-cat-field-ui-color').val('#adff00');
        $('#nw-cat-field-color-picker').val('#adff00');
        $('#nw-cat-field-sort').val('0');
        $('#nw-cat-field-hud').val('');

        populateCatHudSelect();
    }

    function resetHudForm() {
        $('#nw-hud-modal-title').text('New HUD Group');
        $('#nw-hud-save-label').text('Create HUD Group');
        $('#nw-hud-delete-btn').hide();

        $('#nw-hud-field-id').val('');
        $('#nw-hud-field-slug').val('');
        $('#nw-hud-field-label').val('');
        $('#nw-hud-field-color').val('#adff00');
        $('#nw-hud-field-color-picker').val('#adff00');
        $('#nw-hud-field-icon').val('');
        $('#nw-hud-field-sort').val('0');
        renderHudIconPreview('');
    }

    function openTagCreate() {
        resetTagForm();
        openModal(els.tagModal);
    }

    function openCatCreate() {
        resetCatForm();
        openModal(els.catModal);
    }

    function openHudCreate() {
        resetHudForm();
        openModal(els.hudModal);
    }

    function openTagEdit(id) {
        const row = getTagById(id);
        if (!row) return;

        resetTagForm();
        $('#nw-tag-modal-title').text('Edit Tag');
        $('#nw-tag-save-label').text('Save Tag');
        $('#nw-tag-delete-btn').show();

        $('#nw-tag-field-id').val(row.id);
        $('#nw-tag-field-name').val(row.name || '');
        populateTagCategorySelect(row.category_id);
        $('#nw-tag-field-sentiment').val(row.sentiment || 'neutral');
        $('#nw-tag-field-impact').val(parseFloat(row.impact || 0).toFixed(2));
        $('#nw-tag-field-description').val(row.description || '');

        const color = normalizeHex(row.color, '#adff00');
        $('#nw-tag-field-color').val(color);
        $('#nw-tag-field-color-picker').val(color);
        $('#nw-tag-field-is-active').prop('checked', parseInt(row.is_active, 10) === 1);

        openModal(els.tagModal);
    }

    function openCatEdit(id) {
        const row = getCatById(id);
        if (!row) return;

        resetCatForm();
        $('#nw-cat-modal-title').text('Edit Category');
        $('#nw-cat-save-label').text('Save Category');
        $('#nw-cat-delete-btn').show();

        $('#nw-cat-field-id').val(row.id);
        $('#nw-cat-field-internal').val(row.internal_name || '');
        $('#nw-cat-field-display').val(row.display_name || '');
        $('#nw-cat-field-description').val(row.description || '');

        const color = normalizeHex(row.ui_color, '#adff00');
        $('#nw-cat-field-ui-color').val(color);
        $('#nw-cat-field-color-picker').val(color);
        $('#nw-cat-field-sort').val(parseInt(row.sort_order || 0, 10));

        populateCatHudSelect(row.hud_group_id);

        openModal(els.catModal);
    }

    function openHudEdit(id) {
        const row = getHudById(id);
        if (!row) return;

        resetHudForm();
        $('#nw-hud-modal-title').text('Edit HUD Group');
        $('#nw-hud-save-label').text('Save HUD Group');
        $('#nw-hud-delete-btn').show();

        $('#nw-hud-field-id').val(row.id);
        $('#nw-hud-field-slug').val(row.slug || '');
        $('#nw-hud-field-label').val(row.display_label || '');

        const color = normalizeHex(row.base_color, '#adff00');
        $('#nw-hud-field-color').val(color);
        $('#nw-hud-field-color-picker').val(color);
        $('#nw-hud-field-icon').val(row.icon || '');
        $('#nw-hud-field-sort').val(parseInt(row.sort_order || 0, 10));
        renderHudIconPreview(row.icon || '');

        openModal(els.hudModal);
    }

    function renderHudIconPreview(iconName) {
        const $preview = $('#nw-hud-icon-preview');
        const icon = normalizeSlug(iconName);

        if (!icon) {
            $preview.html('<i data-lucide="sparkles" style="width:14px;height:14px;"></i>');
            createIconsSafe();
            return;
        }

        $preview.html('<i data-lucide="' + escapeHtml(icon) + '" style="width:14px;height:14px;"></i>');
        createIconsSafe();
    }

    function saveTag() {
        const payload = {
            id: $('#nw-tag-field-id').val(),
            name: $('#nw-tag-field-name').val().trim(),
            category_id: $('#nw-tag-field-category').val(),
            sentiment: $('#nw-tag-field-sentiment').val(),
            impact: $('#nw-tag-field-impact').val(),
            color: normalizeHex($('#nw-tag-field-color').val(), '#adff00'),
            description: $('#nw-tag-field-description').val().trim(),
            is_active: $('#nw-tag-field-is-active').is(':checked') ? 1 : 0
        };

        if (!payload.name) {
            showNotice('Tag name is required.', 'error');
            return;
        }
        if (!payload.category_id) {
            showNotice('Category is required.', 'error');
            return;
        }

        ajax('nw_atags_save', payload).done(function (res) {
            if (res && res.success) {
                closeModal(els.tagModal);
                loadTags();
                showNotice('Tag saved.', 'success');
            } else {
                showNotice((res && res.data) ? res.data : 'Could not save tag.', 'error');
            }
        }).fail(function () {
            showNotice('Could not save tag.', 'error');
        });
    }

    function saveCat() {
        const payload = {
            id: $('#nw-cat-field-id').val(),
            internal_name: normalizeSlug($('#nw-cat-field-internal').val()),
            display_name: $('#nw-cat-field-display').val().trim(),
            description: $('#nw-cat-field-description').val().trim(),
            ui_color: normalizeHex($('#nw-cat-field-ui-color').val(), '#adff00'),
            sort_order: $('#nw-cat-field-sort').val() || 0,
            hud_group_id: $('#nw-cat-field-hud').val()
        };

        $('#nw-cat-field-internal').val(payload.internal_name);

        if (!payload.internal_name || !payload.display_name || !payload.hud_group_id) {
            showNotice('Internal name, display name and HUD group are required.', 'error');
            return;
        }

        ajax('nw_acats_save', payload).done(function (res) {
            if (res && res.success) {
                closeModal(els.catModal);
                $.when(loadCats(), loadTags()).done(function () {
                    showNotice('Category saved.', 'success');
                });
            } else {
                showNotice((res && res.data) ? res.data : 'Could not save category.', 'error');
            }
        }).fail(function () {
            showNotice('Could not save category.', 'error');
        });
    }

    function saveHud() {
        const payload = {
            id: $('#nw-hud-field-id').val(),
            slug: normalizeSlug($('#nw-hud-field-slug').val()),
            display_label: $('#nw-hud-field-label').val().trim(),
            base_color: normalizeHex($('#nw-hud-field-color').val(), '#adff00'),
            icon: normalizeSlug($('#nw-hud-field-icon').val()),
            sort_order: $('#nw-hud-field-sort').val() || 0
        };

        $('#nw-hud-field-slug').val(payload.slug);
        $('#nw-hud-field-icon').val(payload.icon);

        if (!payload.slug || !payload.display_label) {
            showNotice('Slug and label are required.', 'error');
            return;
        }

        ajax('nw_hud_save', payload).done(function (res) {
            if (res && res.success) {
                closeModal(els.hudModal);
                $.when(loadHud(), loadCats()).done(function () {
                    showNotice('HUD group saved.', 'success');
                });
            } else {
                showNotice((res && res.data) ? res.data : 'Could not save HUD group.', 'error');
            }
        }).fail(function () {
            showNotice('Could not save HUD group.', 'error');
        });
    }

    function deleteTag() {
        const id = $('#nw-tag-field-id').val();
        if (!id) return;
        if (!window.confirm('Delete this tag?')) return;

        ajax('nw_atags_delete', { id: id }).done(function (res) {
            if (res && res.success) {
                closeModal(els.tagModal);
                loadTags();
                showNotice('Tag deleted.', 'success');
            } else {
                showNotice((res && res.data) ? res.data : 'Could not delete tag.', 'error');
            }
        }).fail(function () {
            showNotice('Could not delete tag.', 'error');
        });
    }

    function deleteCat() {
        const id = $('#nw-cat-field-id').val();
        if (!id) return;
        if (!window.confirm('Delete this category?')) return;

        ajax('nw_acats_delete', { id: id }).done(function (res) {
            if (res && res.success) {
                closeModal(els.catModal);
                $.when(loadCats(), loadTags()).done(function () {
                    showNotice('Category deleted.', 'success');
                });
            } else {
                showNotice((res && res.data) ? res.data : 'Could not delete category.', 'error');
            }
        }).fail(function () {
            showNotice('Could not delete category.', 'error');
        });
    }

    function deleteHud() {
        const id = $('#nw-hud-field-id').val();
        if (!id) return;
        if (!window.confirm('Delete this HUD group?')) return;

        ajax('nw_hud_delete', { id: id }).done(function (res) {
            if (res && res.success) {
                closeModal(els.hudModal);
                $.when(loadHud(), loadCats()).done(function () {
                    showNotice('HUD group deleted.', 'success');
                });
            } else {
                showNotice((res && res.data) ? res.data : 'Could not delete HUD group.', 'error');
            }
        }).fail(function () {
            showNotice('Could not delete HUD group.', 'error');
        });
    }

    function duplicateTag(id) {
        ajax('nw_atags_duplicate', { id: id }).done(function (res) {
            if (res && res.success) {
                loadTags();
                showNotice('Tag duplicated.', 'success');
            } else {
                showNotice((res && res.data) ? res.data : 'Could not duplicate tag.', 'error');
            }
        }).fail(function () {
            showNotice('Could not duplicate tag.', 'error');
        });
    }

    function duplicateCat(id) {
        ajax('nw_acats_duplicate', { id: id }).done(function (res) {
            if (res && res.success) {
                $.when(loadCats(), loadTags()).done(function () {
                    showNotice('Category duplicated.', 'success');
                });
            } else {
                showNotice((res && res.data) ? res.data : 'Could not duplicate category.', 'error');
            }
        }).fail(function () {
            showNotice('Could not duplicate category.', 'error');
        });
    }

    function duplicateHud(id) {
        ajax('nw_hud_duplicate', { id: id }).done(function (res) {
            if (res && res.success) {
                $.when(loadHud(), loadCats()).done(function () {
                    showNotice('HUD group duplicated.', 'success');
                });
            } else {
                showNotice((res && res.data) ? res.data : 'Could not duplicate HUD group.', 'error');
            }
        }).fail(function () {
            showNotice('Could not duplicate HUD group.', 'error');
        });
    }

    function bindColorPair(textSelector, pickerSelector) {
        const $text = $(textSelector);
        const $picker = $(pickerSelector);

        $picker.on('input change', function () {
            $text.val(normalizeHex($(this).val(), '#adff00'));
        });

        $text.on('input blur', function () {
            const val = normalizeHex($(this).val(), '#adff00');
            $(this).val(val);
            $picker.val(val);
        });
    }

    function bindEvents() {
        els.tabButtons.on('click', function () {
            setActiveTab($(this).data('tab'));
        });

        $('#nw-tags-add-btn').on('click', function () {
            setActiveTab('tags');
            openTagCreate();
        });

        $('#nw-cats-add-btn').on('click', function () {
            setActiveTab('cats');
            openCatCreate();
        });

        $('#nw-hud-add-btn').on('click', function () {
            setActiveTab('hud');
            openHudCreate();
        });

        $(document).on('click', '[data-modal-close], .nw-modal-close', function () {
            const target = $(this).data('modal-close') || $(this).data('modal');
            if (target) closeModal($('#' + target));
        });

        $(document).on('click', '.nw-modal-overlay', function (e) {
            if ($(e.target).is('.nw-modal-overlay')) {
                closeModal($(this));
            }
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape') {
                closeAllModals();
            }
        });

        bindColorPair('#nw-tag-field-color', '#nw-tag-field-color-picker');
        bindColorPair('#nw-cat-field-ui-color', '#nw-cat-field-color-picker');
        bindColorPair('#nw-hud-field-color', '#nw-hud-field-color-picker');

        $('#nw-cat-field-internal').on('blur', function () {
            $(this).val(normalizeSlug($(this).val()));
        });

        $('#nw-hud-field-slug').on('blur', function () {
            $(this).val(normalizeSlug($(this).val()));
        });

        $('#nw-hud-field-icon').on('input blur', function () {
            const icon = normalizeSlug($(this).val());
            $(this).val(icon);
            renderHudIconPreview(icon);
        });

        $('#nw-tag-save-btn').on('click', function (e) {
            e.preventDefault();
            saveTag();
        });

        $('#nw-cat-save-btn').on('click', function (e) {
            e.preventDefault();
            saveCat();
        });

        $('#nw-hud-save-btn').on('click', function (e) {
            e.preventDefault();
            saveHud();
        });

        $('#nw-tag-delete-btn').on('click', function (e) {
            e.preventDefault();
            deleteTag();
        });

        $('#nw-cat-delete-btn').on('click', function (e) {
            e.preventDefault();
            deleteCat();
        });

        $('#nw-hud-delete-btn').on('click', function (e) {
            e.preventDefault();
            deleteHud();
        });

        $(document).on('click', '.nw-tags-edit-btn', function () {
            openTagEdit($(this).data('id'));
        });

        $(document).on('click', '.nw-cats-edit-btn', function () {
            openCatEdit($(this).data('id'));
        });

        $(document).on('click', '.nw-hud-edit-btn', function () {
            openHudEdit($(this).data('id'));
        });

        $(document).on('click', '.nw-tags-dup-btn', function () {
            duplicateTag($(this).data('id'));
        });

        $(document).on('click', '.nw-cats-dup-btn', function () {
            duplicateCat($(this).data('id'));
        });

        $(document).on('click', '.nw-hud-dup-btn', function () {
            duplicateHud($(this).data('id'));
        });

        els.tagsRefresh.on('click', function () {
            loadTags();
        });

        els.catsRefresh.on('click', function () {
            loadCats();
        });

        els.hudRefresh.on('click', function () {
            loadHud();
        });

        els.tagsSearch.on('input', debounce(function () {
            state.filters.tagsSearch = $(this).val();
            renderTags();
        }, 180));

        els.tagsFilterCat.on('change', function () {
            state.filters.tagsCat = $(this).val();
            renderTags();
        });

        els.tagsFilterSentiment.on('change', function () {
            state.filters.tagsSentiment = $(this).val();
            renderTags();
        });

        els.catsSearch.on('input', debounce(function () {
            state.filters.catsSearch = $(this).val();
            renderCats();
        }, 180));

        els.hudSearch.on('input', debounce(function () {
            state.filters.hudSearch = $(this).val();
            renderHud();
        }, 180));
    }

    bindEvents();
    setActiveTab('tags');
    loadAll();
});
