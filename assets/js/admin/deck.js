/* NeoWeaver — Deck Admin JS */
/* globals NWDeck, lucide, jQuery */

(function ($) {
    'use strict';

    const A = NWDeck.ajaxurl;
    const N = NWDeck.nonce;

    let allRows    = [];
    let allTypes   = [];
    let allClasses = [];

    // ── Lucide ──────────────────────────────────────────────────────────────
    function icons() {
        if (window.lucide) lucide.createIcons();
    }

    // ── Notice ──────────────────────────────────────────────────────────────
    function notice(msg, type = 'success') {
        const $n = $('#nw-notice');
        $n.removeClass('nw-notice-success nw-notice-error')
            .addClass(type === 'error' ? 'nw-notice-error' : 'nw-notice-success')
            .html(msg).show();
        setTimeout(() => $n.fadeOut(), 4000);
    }

    // ── Stats ────────────────────────────────────────────────────────────────
    function updateStats(rows) {
        $('#nw-total').text(rows.length);
        $('#nw-active').text(rows.filter(r => r.is_active).length);
        $('#nw-inactive').text(rows.filter(r => !r.is_active).length);
        $('#nw-rareplus').text(rows.filter(r => ['rare', 'epic', 'legendary'].includes(r.rarity)).length);
    }

    // ── Rarity badge ─────────────────────────────────────────────────────────
    const RARITY_CLASS = {
        common: 'nw-pill-common', uncommon: 'nw-pill-uncommon',
        rare: 'nw-pill-rare', epic: 'nw-pill-epic', legendary: 'nw-pill-legendary'
    };

    function rarityBadge(r) {
        return `<span class="nw-pill ${RARITY_CLASS[r] || 'nw-pill-common'}">${r || 'common'}</span>`;
    }

    // ── Category badge ────────────────────────────────────────────────────────
    const CAT_ICONS = {
        action: 'zap', combat: 'sword', magic: 'sparkles',
        social: 'users', equipment: 'package', tech: 'cpu'
    };

    function catBadge(cat) {
        const icon = CAT_ICONS[cat] || 'layers';
        return `<span class="nw-cat-badge nw-cat-${cat}"><i data-lucide="${icon}"></i>${cat}</span>`;
    }

    // ── Type label lookup ─────────────────────────────────────────────────────
    function typeName(typeId) {
        if (!typeId) return '—';
        const t = allTypes.find(x => x.id === typeId);
        return t ? `<span style="color:${t.color}">${t.label}</span>` : typeId;
    }

    // ── Render table ──────────────────────────────────────────────────────────
    function renderTable(rows) {
        const $tbody = $('#nw-deck-tbody');
        if (!rows.length) {
            $tbody.html('<tr><td colspan="11" class="nw-table-empty">No cards found.</td></tr>');
            icons();
            return;
        }
        const html = rows.map(r => {
            const img = r.img_url
                ? `<img src="${r.img_url}" alt="${r.name}" class="nw-thumb" loading="lazy">`
                : `<span class="nw-thumb-placeholder"><i data-lucide="image-off"></i></span>`;
            const active = r.is_active
                ? '<span class="nw-status nw-status-on"><i data-lucide="check-circle-2"></i></span>'
                : '<span class="nw-status nw-status-off"><i data-lucide="circle-dashed"></i></span>';
            return `<tr data-id="${r.id}">
                <td class="nw-col-img">${img}</td>
                <td class="nw-col-name">
                    <strong>${r.name}</strong>
                    ${r.is_disposable ? '<span class="nw-badge-disp" title="Disposable">1×</span>' : ''}
                </td>
                <td>${catBadge(r.deck_category)}</td>
                <td>${typeName(r.type)}</td>
                <td>${rarityBadge(r.rarity)}</td>
                <td class="nw-col-num">${r.level ?? 1}</td>
                <td class="nw-col-num nw-col-ap">${r.ap_cost ?? 1}</td>
                <td class="nw-col-num nw-col-mp">${r.mp_cost ?? 0}</td>
                <td class="nw-col-num nw-col-dmg">${r.base_damage ?? 0}</td>
                <td>${active}</td>
                <td class="nw-col-actions">
                    <button class="nw-icon-btn nw-btn-edit" data-id="${r.id}" title="Edit"><i data-lucide="pencil"></i></button>
                    <button class="nw-icon-btn nw-btn-dup"  data-id="${r.id}" title="Duplicate"><i data-lucide="copy"></i></button>
                    <button class="nw-icon-btn nw-btn-del"  data-id="${r.id}" data-name="${r.name}" title="Delete"><i data-lucide="trash-2"></i></button>
                </td>
            </tr>`;
        }).join('');
        $tbody.html(html);
        icons();
    }

    // ── Filter ────────────────────────────────────────────────────────────────
    function applyFilters() {
        const search = $('#nw-search').val().toLowerCase();
        const cat    = $('#nw-filter-cat').val();
        const rarity = $('#nw-filter-rarity').val();
        const active = $('#nw-filter-active').val();

        const filtered = allRows.filter(r => {
            if (search && !r.name.toLowerCase().includes(search)) return false;
            if (cat    && r.deck_category !== cat)                  return false;
            if (rarity && r.rarity        !== rarity)               return false;
            if (active === '1' && !r.is_active)                     return false;
            if (active === '0' && r.is_active)                      return false;
            return true;
        });
        renderTable(filtered);
        updateStats(filtered);
    }

    // ── Load data ─────────────────────────────────────────────────────────────
    function loadAll() {
        $('#nw-deck-tbody').html('<tr><td colspan="11" class="nw-table-loading">Loading…</td></tr>');
        $.post(A, { action: 'nw_deck_load', nonce: N }, res => {
            if (!res.success) { notice(res.data || 'Load failed.', 'error'); return; }
            allRows = res.data || [];
            applyFilters();
        });
    }

    function loadTypes() {
        $.post(A, { action: 'nw_deck_load_types', nonce: N }, res => {
            if (!res.success) return;
            allTypes = res.data || [];
            const $sel = $('#nw-field-type');
            $sel.empty().append('<option value="">— select type —</option>');
            allTypes.forEach(t => {
                $sel.append(`<option value="${t.id}">${t.label}</option>`);
            });
        });
    }

    function loadClasses() {
        $.post(A, { action: 'nw_deck_load_classes', nonce: N }, res => {
            if (!res.success) return;
            allClasses = res.data || [];
            const $sel = $('#nw-field-class-id');
            $sel.find('option:not(:first)').remove();
            allClasses.forEach(c => {
                $sel.append(`<option value="${c.id}">${c.name}</option>`);
            });
        });
    }

    // ── Tabs ──────────────────────────────────────────────────────────────────
    function initTabs() {
        $(document).on('click', '.nw-tab', function () {
            const tab = $(this).data('tab');
            $('.nw-tab').removeClass('nw-tab-active');
            $(this).addClass('nw-tab-active');
            $('.nw-tab-panel').hide();
            $(`#nw-tab-${tab}`).show();
            icons();
        });
    }

    // ── Tag input ─────────────────────────────────────────────────────────────
    const TAG_FIELDS = ['tags', 'requirement_tags', 'denied_tags', 'required_item_tags', 'required_location_tags', 'denied_location_tags'];

    function getTagsForField(field) {
        const tags = [];
        $(`[data-field="${field}"] .nw-tag-chip`).each(function () {
            tags.push($(this).data('value'));
        });
        return tags;
    }

    function syncHiddenTagField(field) {
        $(`#nw-hidden-${field}`).val(JSON.stringify(getTagsForField(field)));
    }

    function addTag(field, value) {
        value = value.trim().replace(/,/g, '');
        if (!value) return;
        const $container = $(`[data-field="${field}"] .nw-tag-input-inner`);
        if ($container.find(`.nw-tag-chip[data-value="${value}"]`).length) return;
        const chip = `<span class="nw-tag-chip" data-value="${value}">${value}<button type="button" class="nw-tag-remove" aria-label="Remove ${value}">×</button></span>`;
        $container.find('input').before(chip);
        syncHiddenTagField(field);
    }

    function loadTagsIntoField(field, values) {
        const $container = $(`[data-field="${field}"] .nw-tag-input-inner`);
        $container.find('.nw-tag-chip').remove();
        (values || []).forEach(v => addTag(field, v));
    }

    function clearAllTagFields() {
        TAG_FIELDS.forEach(field => {
            $(`[data-field="${field}"] .nw-tag-input-inner .nw-tag-chip`).remove();
            syncHiddenTagField(field);
        });
    }

    function initTagInputs() {
        $(document).on('keydown', '.nw-tag-input input', function (e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                const field = $(this).closest('.nw-tag-input').data('field');
                addTag(field, $(this).val());
                $(this).val('');
            }
        });
        $(document).on('blur', '.nw-tag-input input', function () {
            const val = $(this).val().trim();
            if (val) {
                const field = $(this).closest('.nw-tag-input').data('field');
                addTag(field, val);
                $(this).val('');
            }
        });
        $(document).on('click', '.nw-tag-remove', function () {
            const $chip = $(this).parent();
            const field = $chip.closest('.nw-tag-input').data('field');
            $chip.remove();
            syncHiddenTagField(field);
        });
    }

    // ── Modal ─────────────────────────────────────────────────────────────────
    function openModal(card) {
        const isEdit = !!card;
        $('#nw-modal-title').text(isEdit ? 'Edit Card' : 'Add Card');
        $('#nw-btn-save').html('<i data-lucide="save"></i> ' + (isEdit ? 'Update Card' : 'Save Card'));

        // reset tabs
        $('.nw-tab').removeClass('nw-tab-active').first().addClass('nw-tab-active');
        $('.nw-tab-panel').hide();
        $('#nw-tab-basic').show();

        // clear form
        $('#nw-form')[0].reset();
        clearAllTagFields();

        if (isEdit) {
            $('#nw-field-id').val(card.id);
            $('#nw-field-name').val(card.name || '');
            $('#nw-field-deck-category').val(card.deck_category || 'action');
            $('#nw-field-type').val(card.type || '');
            $('#nw-field-rarity').val(card.rarity || 'common');
            $('#nw-field-class-id').val(card.class_id || '');
            $('#nw-field-cost-number').val(card.cost_number ?? 0);
            $('#nw-field-description').val(card.description || '');
            $('#nw-field-effect').val(card.effect || '');
            $('#nw-field-mechanic').val(card.mechanic || '');
            $('#nw-field-mechanic-goal').val(card.mechanic_goal || '');
            $('#nw-field-img-url').val(card.img_url || '');
            $('#nw-field-sound-effect').val(card.sound_effect || '');
            $('#nw-field-is-active').prop('checked', !!card.is_active);
            $('#nw-field-is-leveling').prop('checked', !!card.is_leveling);
            $('#nw-field-is-disposable').prop('checked', !!card.is_disposable);
            $('#nw-field-counts-hand').prop('checked', card.counts_toward_hand_limit !== false);

            // combat
            $('#nw-field-base-damage').val(card.base_damage ?? 0);
            $('#nw-field-ap-cost').val(card.ap_cost ?? 1);
            $('#nw-field-mp-cost').val(card.mp_cost ?? 0);
            $('#nw-field-time-cost').val(card.time_cost_minutes ?? 0);
            $('#nw-field-cooldown').val(card.cooldown_messages ?? 0);
            $('#nw-field-entropy').val(card.entropy_on_fail ?? 0);
            $('#nw-field-level').val(card.level ?? 1);
            $('#nw-field-xp-current').val(card.xp_current ?? 0);
            $('#nw-field-xp-to-next').val(card.xp_to_next ?? 10);
            $('#nw-field-xp-per-level').val(card.xp_per_level ?? 10);

            // JSON fields
            $('#nw-field-bonus').val(card.bonus ? JSON.stringify(card.bonus, null, 2) : '{}');
            $('#nw-field-level-scaling').val(card.level_scaling ? JSON.stringify(card.level_scaling, null, 2) : '{}');
            $('#nw-field-asc-bonuses').val(card.asc_bonuses ? JSON.stringify(card.asc_bonuses, null, 2) : '{}');

            // tags
            TAG_FIELDS.forEach(field => loadTagsIntoField(field, card[field] || []));

            // advanced
            $('#nw-field-req-description').val(card.requirement_description || '');
            $('#nw-field-ai-instruction').val(card.ai_instruction || '');
            $('#nw-field-gm').val(card.gm || '');
        } else {
            $('#nw-field-id').val('');
            // defaults
            $('#nw-field-is-active').prop('checked', true);
            $('#nw-field-is-leveling').prop('checked', true);
            $('#nw-field-counts-hand').prop('checked', true);
            $('#nw-field-ap-cost').val(1);
            $('#nw-field-xp-to-next').val(10);
            $('#nw-field-xp-per-level').val(10);
            $('#nw-field-bonus').val('{}');
            $('#nw-field-level-scaling').val('{}');
            $('#nw-field-asc-bonuses').val('{}');
        }

        $('#nw-modal').show();
        icons();
    }

    function closeModal() {
        $('#nw-modal').hide();
    }

    // ── Expand textarea ───────────────────────────────────────────────────────
    $(document).on('click', '.nw-expand-btn', function () {
        const target = $(this).data('target');
        const $ta    = $(`#${target}`);
        if ($ta.hasClass('nw-textarea-expanded')) {
            $ta.removeClass('nw-textarea-expanded').attr('rows', 5);
        } else {
            $ta.addClass('nw-textarea-expanded').attr('rows', 18);
        }
    });

    // ── Save ──────────────────────────────────────────────────────────────────
    $('#nw-form').on('submit', function (e) {
        e.preventDefault();

        // Sync checkbox booleans manually (unchecked checkboxes don't submit)
        const checkboxMap = {
            'is_active': 'nw-field-is-active',
            'is_leveling': 'nw-field-is-leveling',
            'is_disposable': 'nw-field-is-disposable',
            'counts_toward_hand_limit': 'nw-field-counts-hand'
        };
        Object.entries(checkboxMap).forEach(([name, id]) => {
            if (!$('#nw-form input[name="' + name + '"]').length) {
                $('<input>').attr({ type: 'hidden', name, value: 0 }).appendTo('#nw-form');
            }
            $(`#${id}`).prop('checked')
                ? $(`input[name="${name}"]`).val(1)
                : $(`input[name="${name}"]`).val(0);
        });

        const data = $(this).serializeArray().reduce((acc, { name, value }) => {
            acc[name] = value;
            return acc;
        }, {});
        data.action = 'nw_deck_save';
        data.nonce  = N;

        const $btn = $('#nw-btn-save').prop('disabled', true).html('<i data-lucide="loader-2"></i> Saving…');
        icons();

        $.post(A, data, res => {
            $btn.prop('disabled', false).html('<i data-lucide="save"></i> Save Card');
            icons();
            if (!res.success) {
                notice(res.data || 'Save failed.', 'error');
                return;
            }
            closeModal();
            loadAll();
            notice(data.id ? 'Card updated.' : 'Card created.');
        });
    });

    // ── Delete ────────────────────────────────────────────────────────────────
    let pendingDeleteId = null;

    $(document).on('click', '.nw-btn-del', function () {
        pendingDeleteId = $(this).data('id');
        $('#nw-confirm-name').text($(this).data('name'));
        $('#nw-confirm-modal').show();
        icons();
    });

    $('#nw-btn-confirm-delete').on('click', function () {
        if (!pendingDeleteId) return;
        $.post(A, { action: 'nw_deck_delete', nonce: N, id: pendingDeleteId }, res => {
            $('#nw-confirm-modal').hide();
            if (!res.success) { notice(res.data || 'Delete failed.', 'error'); return; }
            notice('Card deleted.');
            loadAll();
            pendingDeleteId = null;
        });
    });

    // ── Duplicate ──────────────────────────────────────────────────────────────
    $(document).on('click', '.nw-btn-dup', function () {
        const id = $(this).data('id');
        $.post(A, { action: 'nw_deck_duplicate', nonce: N, id }, res => {
            if (!res.success) { notice(res.data || 'Duplicate failed.', 'error'); return; }
            loadAll();
            notice('Card duplicated (inactive).');
        });
    });

    // ── Edit ──────────────────────────────────────────────────────────────────
    $(document).on('click', '.nw-btn-edit', function () {
        const id   = $(this).data('id');
        const card = allRows.find(r => r.id == id);
        if (card) openModal(card);
    });

    // ── Add button ────────────────────────────────────────────────────────────
    $('#nw-btn-add').on('click', () => openModal(null));

    // ── Close modal ───────────────────────────────────────────────────────────
    $(document).on('click', '.nw-modal-close, .nw-modal-backdrop', function () {
        const modal = $(this).data('modal') || 'nw-modal';
        $(`#${modal}`).hide();
    });

    // ── Filters ───────────────────────────────────────────────────────────────
    $('#nw-search').on('input', applyFilters);
    $('#nw-filter-cat, #nw-filter-rarity, #nw-filter-active').on('change', applyFilters);

    // ── Init ──────────────────────────────────────────────────────────────────
    initTabs();
    initTagInputs();
    loadTypes();
    loadClasses();
    loadAll();
    icons();

}(jQuery));
