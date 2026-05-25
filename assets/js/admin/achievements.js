/**
 * NeoWeaver Admin — Achievements JS
 * Wzorowany na classes.js — identyczny wzorzec AJAX + modal.
 */
jQuery(function ($) {
    'use strict';

    var cfg          = window.NWAchievements || {};
    var ajaxEndpoint = cfg.ajaxurl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
    var nonce        = cfg.nonce  || '';
    var noticeTimer  = null;

    /* ── DOM refs ──────────────────────────────────────── */
    var $notice       = $('#nw-notice');
    var $tbody        = $('#nw-achievements-tbody');
    var $search       = $('#nw-search');
    var $filterScope  = $('#nw-filter-scope');
    var $filterCat    = $('#nw-filter-category');
    var $modalOverlay = $('#nw-modal-overlay');
    var $form         = $('#nw-achievement-form');
    var $saveBtn      = $('#nw-save-btn');
    var $saveLabel    = $('#nw-save-label');
    var $deleteBtn    = $('#nw-delete-btn');

    var $fieldId        = $('#nw-field-id');
    var $fieldNewId     = $('#nw-field-new-id');
    var $fieldTitle     = $('#nw-field-title');
    var $fieldDesc      = $('#nw-field-description');
    var $fieldIconSlug  = $('#nw-field-icon-slug');
    var $fieldBgColor   = $('#nw-field-bg-color');
    var $fieldBgPicker  = $('#nw-field-bg-color-picker');
    var $fieldScope     = $('#nw-field-scope');
    var $fieldCategory  = $('#nw-field-category');
    var $fieldGoal      = $('#nw-field-goal');
    var $fieldHidden    = $('#nw-field-hidden');
    var $fieldIsActive  = $('#nw-field-is-active');

    /* ── state ─────────────────────────────────────────── */
    var all      = [];
    var activeXhr = null;

    /* ── Helpers ───────────────────────────────────────── */
    function esc(s) {
        return $('<div>').text(s || '').html();
    }

    function clearNoticeTimer() {
        if (noticeTimer) { clearTimeout(noticeTimer); noticeTimer = null; }
    }

    function notice(msg, type) {
        clearNoticeTimer();
        $notice
            .attr('class', 'nw-notice nw-notice-' + String(type || 'info').replace(/[^a-z-]/g, ''))
            .text(msg)
            .stop(true, true).show();
        noticeTimer = setTimeout(function () { $notice.fadeOut(300); noticeTimer = null; }, 3500);
    }

    function debounce(fn, delay) {
        var timer;
        return function () {
            var args = arguments, ctx = this;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(ctx, args); }, delay);
        };
    }

    /* ── Normalize ─────────────────────────────────────── */
    function normalizeList(data) {
        var list = data;
        if (typeof list === 'string') { try { list = JSON.parse(list); } catch(e) { list = []; } }
        if (!Array.isArray(list)) { list = (list && typeof list === 'object') ? Object.values(list) : []; }
        return list.map(function (item) {
            return {
                id:                 item.id                 || '',
                title:              item.title              || '',
                description:        item.description        || '',
                icon_slug:          item.icon_slug          || 'default_icon',
                bg_color:           item.bg_color           || '#2c3e50',
                scope:              item.scope              || '',
                goal:               item.goal               != null ? item.goal : 1,
                category:           item.category           || '',
                hidden_until_earned:!!item.hidden_until_earned,
                is_active:          item.is_active          != null ? !!item.is_active : true,
                created_at:         item.created_at         || ''
            };
        });
    }

    /* ── Stats ─────────────────────────────────────────── */
    function updateStats(data) {
        var active = 0, scopeAcc = 0, scopeChar = 0, hidden = 0;
        (data || []).forEach(function (a) {
            if (a.is_active)           active++;
            if (a.scope === 'account') scopeAcc++;
            if (a.scope === 'character') scopeChar++;
            if (a.hidden_until_earned) hidden++;
        });
        $('#nw-total').text(data.length);
        $('#nw-active').text(active);
        $('#nw-scope-account').text(scopeAcc);
        $('#nw-scope-character').text(scopeChar);
        $('#nw-hidden-count').text(hidden);
    }

    /* ── Category color map ────────────────────────────── */
    var catColors = {
        system:      '#44aaff',
        exploration: '#adff00',
        social:      '#cc88ff',
        progression: '#ff9f00',
        mission:     '#ff5050',
        loot:        '#ffd700',
        secret:      '#888'
    };

    /* ── Table render ──────────────────────────────────── */
    function renderTable(data) {
        if (!data.length) {
            $tbody.html('<tr><td colspan="9" style="text-align:center;padding:32px;color:#555;">No achievements found.</td></tr>');
            return;
        }

        $tbody.html(data.map(function (a) {
            var safeId   = esc(a.id);
            var catColor = catColors[a.category] || '#666';

            // Icon badge
            var iconH = '<div class="nw-ach-icon-badge" style="background:' + esc(a.bg_color) + ';">'
                      + '<i data-lucide="' + esc(a.icon_slug || 'trophy') + '" class="nw-ach-lucide-icon"></i>'
                      + '</div>';

            // Category pill
            var catH = a.category
                ? '<span class="nw-ach-cat-pill" style="border-color:' + catColor + ';color:' + catColor + ';">' + esc(a.category) + '</span>'
                : '<span style="color:#444;">—</span>';

            // Scope badge
            var scopeColor = a.scope === 'account' ? '#44aaff' : a.scope === 'character' ? '#cc88ff' : '#555';
            var scopeH = a.scope
                ? '<span class="nw-ach-scope-badge" style="border-color:' + scopeColor + ';color:' + scopeColor + ';">' + esc(a.scope) + '</span>'
                : '<span style="color:#444;">—</span>';

            var activeH = a.is_active
                ? '<span class="nw-toggle-active">Yes</span>'
                : '<span class="nw-toggle-inactive">No</span>';

            var hiddenH = a.hidden_until_earned
                ? '<span class="nw-toggle-active" style="background:rgba(255,159,0,.15);color:#ff9f00;border-color:rgba(255,159,0,.35);">Yes</span>'
                : '<span class="nw-toggle-inactive">No</span>';

            return '<tr class="' + (a.is_active ? '' : 'nw-row-inactive') + '" data-id="' + safeId + '">'
                + '<td>' + iconH + '</td>'
                + '<td class="nw-ach-id-cell"><code>' + safeId + '</code></td>'
                + '<td><div class="nw-ach-title">' + esc(a.title) + '</div>'
                +     '<div class="nw-ach-desc">' + esc(a.description || '') + '</div></td>'
                + '<td>' + catH + '</td>'
                + '<td>' + scopeH + '</td>'
                + '<td class="nw-ach-goal">' + esc(String(a.goal)) + '</td>'
                + '<td>' + hiddenH + '</td>'
                + '<td>' + activeH + '</td>'
                + '<td><div class="nw-row-actions">'
                +   '<button class="nw-action-btn nw-row-edit nw-edit-btn" data-id="' + safeId + '">Edit</button>'
                + '</div></td>'
                + '</tr>';
        }).join(''));

        // Re-init lucide icons inside tbody
        if (window.lucide) lucide.createIcons({ nameAttr: 'data-lucide', nodes: [$tbody[0]] });
    }

    /* ── Filter & search ───────────────────────────────── */
    function applyFilters() {
        var q    = String($search.val()      || '').toLowerCase().trim();
        var sc   = String($filterScope.val() || '').toLowerCase();
        var cat  = String($filterCat.val()   || '').toLowerCase();

        var shown = all.filter(function (a) {
            if (sc  && a.scope    !== sc)  return false;
            if (cat && a.category !== cat) return false;
            if (q) {
                return String(a.title       || '').toLowerCase().indexOf(q) !== -1
                    || String(a.description || '').toLowerCase().indexOf(q) !== -1
                    || String(a.id          || '').toLowerCase().indexOf(q) !== -1
                    || String(a.category    || '').toLowerCase().indexOf(q) !== -1;
            }
            return true;
        });

        renderTable(shown);
    }

    /* ── Load all ──────────────────────────────────────── */
    function loadAll() {
        if (!ajaxEndpoint) { notice('Missing AJAX endpoint.', 'error'); return; }
        if (!nonce)        { notice('Missing nonce.',          'error'); return; }

        if (activeXhr && activeXhr.readyState !== 4) activeXhr.abort();
        $tbody.html('<tr class="nw-loading-row"><td colspan="9"><span class="nw-spinner"></span> Loading achievements…</td></tr>');

        activeXhr = $.post(ajaxEndpoint, { action: 'nw_achievements_load', nonce: nonce }, function (res) {
            if (!res || !res.success) {
                notice('Error: ' + ((res && res.data) || 'Unknown error'), 'error');
                return;
            }
            var rows = Array.isArray(res.data) ? res.data
                     : (res.data && typeof res.data === 'object' ? Object.values(res.data) : []);
            all = normalizeList(rows);
            updateStats(all);
            applyFilters();
        }).fail(function (xhr, status) {
            if (status !== 'abort') notice('Request failed (' + (xhr.status || status) + ').', 'error');
        }).always(function () { activeXhr = null; });
    }

    /* ── Color picker sync ─────────────────────────────── */
    $fieldBgPicker.on('input change', function () {
        $fieldBgColor.val($(this).val());
    });
    $fieldBgColor.on('input change', function () {
        var v = $(this).val().trim();
        if (/^#[0-9a-fA-F]{6}$/.test(v)) $fieldBgPicker.val(v);
    });

    /* ── Confirm modal ─────────────────────────────────── */
    function confirmModal(message, onConfirm) {
        if ($('.nw-confirm-overlay').length) return;
        var overlay = $(
            '<div class="nw-confirm-overlay nw-modal-overlay" style="display:flex;">'
            + '<div class="nw-confirm-box nw-modal" style="max-width:360px;">'
            + '<div class="nw-modal-body" style="padding:24px;">'
            + '<p style="margin:0 0 20px;color:#e0e0e0;">' + esc(message) + '</p>'
            + '<div style="display:flex;gap:10px;justify-content:flex-end;">'
            + '<button class="nw-btn nw-btn-ghost nw-confirm-no">Cancel</button>'
            + '<button class="nw-btn nw-btn-danger nw-confirm-yes">Delete</button>'
            + '</div></div></div></div>'
        );
        $('body').append(overlay);
        overlay.find('.nw-confirm-yes').on('click', function () { overlay.remove(); onConfirm(); });
        overlay.find('.nw-confirm-no').on('click',  function () { overlay.remove(); });
        overlay.on('click', function (e) { if ($(e.target).is(overlay)) overlay.remove(); });
    }

    /* ── Open modal ────────────────────────────────────── */
    function openModal(id) {
        if ($form.length && $form[0]) $form[0].reset();

        $fieldId.val('');
        $fieldNewId.val('').prop('readonly', false).closest('.nw-field').show();
        $fieldGoal.val(1);
        $fieldBgColor.val('#2c3e50');
        $fieldBgPicker.val('#2c3e50');
        $fieldIsActive.prop('checked', true);
        $fieldHidden.prop('checked', false);

        if (id) {
            var a = all.find(function (x) { return x.id === id; });
            if (!a) { notice('Achievement data not loaded yet.', 'error'); return; }

            $fieldId.val(a.id);
            $fieldNewId.val(a.id).prop('readonly', true); // non-editable per edit
            $fieldTitle.val(a.title         || '');
            $fieldDesc.val(a.description    || '');
            $fieldIconSlug.val(a.icon_slug  || '');
            $fieldBgColor.val(a.bg_color    || '#2c3e50');
            $fieldBgPicker.val(a.bg_color   || '#2c3e50');
            $fieldScope.val(a.scope         || '');
            $fieldCategory.val(a.category   || '');
            $fieldGoal.val(a.goal           != null ? a.goal : 1);
            $fieldHidden.prop('checked',   !!a.hidden_until_earned);
            $fieldIsActive.prop('checked', !!a.is_active);

            $('#nw-modal-title').text('Edit Achievement');
            $saveLabel.text('Save Changes');
            $deleteBtn.show().data('id', id);
        } else {
            $('#nw-modal-title').text('New Achievement');
            $saveLabel.text('Create Achievement');
            $deleteBtn.hide().removeData('id');
        }

        $modalOverlay.fadeIn(150);
        if (window.lucide) lucide.createIcons();
    }

    /* ── Events ────────────────────────────────────────── */
    $('#nw-modal-close, #nw-cancel-btn').on('click', function () { $modalOverlay.fadeOut(150); });
    $modalOverlay.on('click', function (e) { if ($(e.target).is('#nw-modal-overlay')) $modalOverlay.fadeOut(150); });
    $(document).on('click', '.nw-edit-btn', function () { openModal($(this).data('id')); });
    $('#nw-add-btn').on('click', function () { openModal(null); });
    $('#nw-refresh-btn').on('click', loadAll);
    $search.on('input', debounce(applyFilters, 150));
    $filterScope.on('change', applyFilters);
    $filterCat.on('change', applyFilters);

    /* ── Save ──────────────────────────────────────────── */
    $saveBtn.on('click', function () {
        var title  = $fieldTitle.val().trim();
        var newId  = $fieldNewId.val().trim();
        var editId = $fieldId.val().trim();

        if (!title) { notice('Title is required.', 'error'); return; }
        if (!editId && !newId) { notice('ID is required.', 'error'); return; }

        var btn = $(this), prevLabel = $saveLabel.text();
        btn.prop('disabled', true);
        $saveLabel.text('Saving…');

        var payload = {
            action:              'nw_achievements_save',
            nonce:               nonce,
            id:                  editId,
            new_id:              newId,
            title:               title,
            description:         $fieldDesc.val().trim(),
            icon_slug:           $fieldIconSlug.val().trim() || 'trophy',
            bg_color:            $fieldBgColor.val().trim()  || '#2c3e50',
            scope:               $fieldScope.val(),
            category:            $fieldCategory.val(),
            goal:                $fieldGoal.val(),
            hidden_until_earned: $fieldHidden.is(':checked')   ? '1' : '0',
            is_active:           $fieldIsActive.is(':checked') ? '1' : '0'
        };

        $.post(ajaxEndpoint, payload, function (res) {
            btn.prop('disabled', false);
            $saveLabel.text(prevLabel);
            if (res && res.success) {
                notice('Achievement saved!', 'success');
                $modalOverlay.fadeOut(150);
                loadAll();
            } else {
                notice('Error: ' + ((res && res.data) || 'Unknown'), 'error');
            }
        }).fail(function (xhr) {
            btn.prop('disabled', false);
            $saveLabel.text(prevLabel);
            notice('Request failed (' + (xhr.status || 'network') + ').', 'error');
        });
    });

    /* ── Delete ────────────────────────────────────────── */
    $deleteBtn.on('click', function () {
        var id = $(this).data('id');
        if (!id) return;
        confirmModal('Delete achievement "' + id + '" permanently?', function () {
            $.post(ajaxEndpoint, { action: 'nw_achievements_delete', nonce: nonce, id: id }, function (res) {
                if (res && res.success) {
                    notice('Achievement deleted.', 'success');
                    $modalOverlay.fadeOut(150);
                    loadAll();
                } else {
                    notice('Delete failed: ' + ((res && res.data) || 'Unknown'), 'error');
                }
            }).fail(function (xhr) {
                notice('Delete request failed (' + (xhr.status || 'network') + ').', 'error');
            });
        });
    });

    /* ── Init ──────────────────────────────────────────── */
    loadAll();
});
