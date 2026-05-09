/**
 * NeoWeaver — World Tag Defs Admin JS
 * Depends on: jQuery, NW_WTD (ajax_url, nonce)
 */
/* global jQuery, NW_WTD */
(function ($) {
    'use strict';

    /* ---------------------------------------------------------------- */
    /*  State                                                             */
    /* ---------------------------------------------------------------- */
    var allTags = [];
    var editId  = null;

    /* ---------------------------------------------------------------- */
    /*  Helpers                                                           */
    /* ---------------------------------------------------------------- */
    function escH(s) {
        return $('<div>').text(String(s || '')).html();
    }

    function badgeHtml(source) {
        return '<span class="nw-badge nw-badge-' + escH(source) + '">' + escH(source) + '</span>';
    }

    function impactHtml(v) {
        v = parseFloat(v) || 0;
        var cls = v > 0 ? 'nw-impact-pos' : v < 0 ? 'nw-impact-neg' : 'nw-impact-zero';
        return '<span class="' + cls + '">' + (v > 0 ? '+' : '') + v + '</span>';
    }

    function showNotice(type, msg) {
        var $n = $('#nw-notice');
        $n.removeClass('nw-notice-success nw-notice-error')
          .addClass('nw-notice-' + type)
          .text(msg)
          .show();
        clearTimeout($n.data('timer'));
        $n.data('timer', setTimeout(function () { $n.fadeOut(); }, 4000));
    }

    /* ---------------------------------------------------------------- */
    /*  Load                                                              */
    /* ---------------------------------------------------------------- */
    function loadTags() {
        $('#nw-wtd-tbody').html(
            '<tr class="nw-loading-row"><td colspan="9"><div class="nw-spinner"></div> Loading tag defs…</td></tr>'
        );
        $.post(NW_WTD.ajax_url, { action: 'nw_wtd_get_all', nonce: NW_WTD.nonce }, function (r) {
            if (!r.success) { showNotice('error', r.data); return; }
            allTags = r.data || [];
            buildCategoryFilter();
            renderFiltered();
        }).fail(function () { showNotice('error', 'Network error.'); });
    }

    /* ---------------------------------------------------------------- */
    /*  Category filter                                                   */
    /* ---------------------------------------------------------------- */
    function buildCategoryFilter() {
        var cats = {};
        $.each(allTags, function (_, t) { if (t.category) cats[t.category] = true; });
        var $sel = $('#nw-filter-category');
        var cur  = $sel.val();
        $sel.find('option:not(:first)').remove();
        $.each(Object.keys(cats).sort(), function (_, c) {
            $sel.append('<option value="' + escH(c) + '">' + escH(c) + '</option>');
        });
        $sel.val(cur);
    }

    /* ---------------------------------------------------------------- */
    /*  Filter & render                                                   */
    /* ---------------------------------------------------------------- */
    function renderFiltered() {
        var catF   = $('#nw-filter-category').val();
        var srcF   = $('#nw-filter-source').val();
        var actF   = $('#nw-filter-active').val();
        var search = $('#nw-filter-search').val().toLowerCase();

        var rows = allTags.filter(function (t) {
            if (catF && t.category !== catF) return false;
            if (srcF && t.source   !== srcF) return false;
            if (actF === '1' && !t.is_active) return false;
            if (actF === '0' &&  t.is_active) return false;
            if (search) {
                var hay = (t.code + ' ' + (t.label || '') + ' ' + (t.description || '')).toLowerCase();
                if (!hay.includes(search)) return false;
            }
            return true;
        });
        renderTable(rows);
    }

    function renderTable(rows) {
        /* stats */
        var total = allTags.length, active = 0, inactive = 0, sys = 0, custom = 0;
        $.each(allTags, function (_, t) {
            if (t.is_active) active++; else inactive++;
            if (t.source === 'system') sys++;
            if (t.source === 'custom') custom++;
        });
        $('#nw-total').text(total);
        $('#nw-active').text(active);
        $('#nw-inactive').text(inactive);
        $('#nw-count-system').text(sys);
        $('#nw-count-custom').text(custom);

        /* rows */
        if (!rows.length) {
            $('#nw-wtd-tbody').html(
                '<tr><td colspan="9" style="text-align:center;padding:32px;color:#555;">No tag defs found.</td></tr>'
            );
            return;
        }
        var html = '';
        $.each(rows, function (_, t) {
            var colorDot = t.color
                ? '<span class="nw-color-dot" style="background:' + escH(t.color) + ';"></span>'
                : '';
            html += '<tr data-id="' + escH(t.id) + '">'
                + '<td><span class="nw-code">'   + escH(t.code)  + '</span></td>'
                + '<td><span class="nw-label">'  + escH(t.label) + '</span></td>'
                + '<td class="nw-icon-cell">'
                    + (t.icon ? escH(t.icon) : '—') + '<br>'
                    + colorDot + escH(t.color || '') + '</td>'
                + '<td>' + (t.category ? escH(t.category) : '<span style="color:#555">—</span>') + '</td>'
                + '<td>' + badgeHtml(t.source || 'system') + '</td>'
                + '<td>' + impactHtml(t.impact) + '</td>'
                + '<td style="color:#555">' + (t.sort_order !== null && t.sort_order !== undefined ? t.sort_order : '—') + '</td>'
                + '<td>'
                    + '<label class="nw-toggle">'
                    + '<input type="checkbox" class="nw-toggle-active" data-id="' + escH(t.id) + '"' + (t.is_active ? ' checked' : '') + '>'
                    + '<span class="nw-toggle-slider"></span>'
                    + '</label></td>'
                + '<td>'
                    + '<div class="nw-row-actions">'
                    + '<button class="nw-action-btn nw-edit-btn" data-id="' + escH(t.id) + '">Edit</button>'
                    + '</div></td>'
                + '</tr>';
        });
        $('#nw-wtd-tbody').html(html);
    }

    /* ---------------------------------------------------------------- */
    /*  Modal                                                             */
    /* ---------------------------------------------------------------- */
    function openModal(tag) {
        editId = tag ? tag.id : null;
        $('#nw-modal-title').text(tag ? 'Edit World Tag Def' : 'New World Tag Def');
        $('#nw-save-label').text(tag ? 'Save' : 'Create');
        $('#nw-delete-btn').toggle(!!tag);
        $('#nw-field-id').val(tag ? tag.id : '');
        $('#nw-field-code').val(tag ? tag.code : '');
        $('#nw-field-label').val(tag ? tag.label : '');
        $('#nw-field-icon').val(tag ? tag.icon || '' : '');
        var col = (tag && tag.color) ? tag.color : '#adff00';
        $('#nw-field-color').val(col);
        if (/^#[0-9a-fA-F]{6}$/.test(col)) $('#nw-field-color-picker').val(col);
        $('#nw-field-description').val(tag ? tag.description || '' : '');
        $('#nw-field-category').val(tag ? tag.category || '' : '');
        $('#nw-field-source').val(tag ? tag.source || 'system' : 'system');
        $('#nw-field-sort_order').val(tag && tag.sort_order !== null ? tag.sort_order : '');
        $('#nw-field-impact').val(tag && tag.impact !== null ? tag.impact : 0);
        $('#nw-field-is_active').prop('checked', tag ? tag.is_active : true);
        $('#nw-modal-overlay').show();
        $('#nw-field-code').focus();
    }

    function closeModal() {
        $('#nw-modal-overlay').hide();
        editId = null;
    }

    /* ---------------------------------------------------------------- */
    /*  Save                                                              */
    /* ---------------------------------------------------------------- */
    function saveTag() {
        var data = { action: 'nw_wtd_save', nonce: NW_WTD.nonce, tag: {} };
        $('#nw-wtd-form').serializeArray().forEach(function (f) { data.tag[f.name] = f.value; });
        data.tag.is_active = $('#nw-field-is_active').is(':checked') ? '1' : '0';
        $('#nw-save-btn').prop('disabled', true);
        $('#nw-save-label').text('Saving…');
        $.post(NW_WTD.ajax_url, data, function (r) {
            $('#nw-save-btn').prop('disabled', false);
            $('#nw-save-label').text(editId ? 'Save' : 'Create');
            if (!r.success) { showNotice('error', r.data); return; }
            showNotice('success', editId ? 'Tag def updated.' : 'Tag def created.');
            closeModal();
            loadTags();
        }).fail(function () {
            $('#nw-save-btn').prop('disabled', false);
            $('#nw-save-label').text(editId ? 'Save' : 'Create');
            showNotice('error', 'Network error.');
        });
    }

    /* ---------------------------------------------------------------- */
    /*  Events                                                            */
    /* ---------------------------------------------------------------- */

    /* color picker sync */
    $('#nw-field-color-picker').on('input', function () {
        $('#nw-field-color').val($(this).val());
    });
    $('#nw-field-color').on('input', function () {
        var v = $(this).val().trim();
        if (/^#[0-9a-fA-F]{6}$/.test(v)) $('#nw-field-color-picker').val(v);
    });

    /* filters */
    $('#nw-filter-category, #nw-filter-source, #nw-filter-active').on('change', renderFiltered);
    $('#nw-filter-search').on('input', renderFiltered);

    /* header */
    $('#nw-add-btn').on('click', function () { openModal(null); });
    $('#nw-refresh-btn').on('click', loadTags);

    /* modal close */
    $('#nw-modal-close, #nw-cancel-btn').on('click', closeModal);
    $('#nw-modal-overlay').on('click', function (e) {
        if ($(e.target).is('#nw-modal-overlay')) closeModal();
    });
    $(document).on('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

    /* save / delete */
    $('#nw-save-btn').on('click', saveTag);
    $('#nw-wtd-form').on('submit', function (e) { e.preventDefault(); saveTag(); });
    $('#nw-delete-btn').on('click', function () {
        if (!editId || !confirm('Delete this tag def? This cannot be undone.')) return;
        $.post(NW_WTD.ajax_url, { action: 'nw_wtd_delete', nonce: NW_WTD.nonce, tag_id: editId }, function (r) {
            if (!r.success) { showNotice('error', r.data); return; }
            showNotice('success', 'Tag def deleted.');
            closeModal();
            loadTags();
        }).fail(function () { showNotice('error', 'Network error.'); });
    });

    /* table: edit */
    $(document).on('click', '.nw-edit-btn', function () {
        var id  = parseInt($(this).data('id'), 10);
        var tag = null;
        $.each(allTags, function (_, t) { if (t.id === id) { tag = t; return false; } });
        if (tag) openModal(tag);
    });

    /* table: quick toggle */
    $(document).on('change', '.nw-toggle-active', function () {
        var id    = $(this).data('id');
        var state = $(this).is(':checked');
        $.post(
            NW_WTD.ajax_url,
            { action: 'nw_wtd_toggle', nonce: NW_WTD.nonce, tag_id: id, is_active: state ? 1 : 0 },
            function (r) {
                if (!r.success) { showNotice('error', r.data); loadTags(); }
                else {
                    var tag = allTags.find(function (t) { return t.id == id; });
                    if (tag) tag.is_active = state;
                }
            }
        ).fail(function () { showNotice('error', 'Network error.'); loadTags(); });
    });

    /* ---------------------------------------------------------------- */
    /*  Init                                                              */
    /* ---------------------------------------------------------------- */
    loadTags();

}(jQuery));
