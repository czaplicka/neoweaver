/**
 * NeoWeaver — Class Starting Cards Admin
 * assets/js/admin/class-starting-cards.js
 */
/* global NWCards, lucide, jQuery */
(function ($) {
    'use strict';

    /* ── Paleta kolorów dla klas (cykliczna) ─────────────────────────────── */
    const CLASS_COLORS = [
        '#adff00','#00c8ff','#ff6b35','#c084fc','#fb7185',
        '#34d399','#fbbf24','#60a5fa','#f472b6','#a78bfa'
    ];
    const classColorMap = {}; // class_id → color

    function classColor(id) {
        if (!classColorMap[id]) {
            const keys = Object.keys(classColorMap);
            classColorMap[id] = CLASS_COLORS[keys.length % CLASS_COLORS.length];
        }
        return classColorMap[id];
    }

    /* ── Stan ────────────────────────────────────────────────────────────── */
    let allRows    = [];
    let allClasses = [];
    let allDeck    = [];

    /* ── Selektory ───────────────────────────────────────────────────────── */
    const $tbody    = $('#nw-csc-tbody');
    const $notice   = $('#nw-notice');
    const $overlay  = $('#nw-modal-overlay');
    const $form     = $('#nw-csc-form');

    /* ── Notice ──────────────────────────────────────────────────────────── */
    function showNotice(msg, type = 'success') {
        $notice
            .removeClass('nw-notice-success nw-notice-error')
            .addClass('nw-notice-' + type)
            .text(msg)
            .show();
        clearTimeout(showNotice._t);
        showNotice._t = setTimeout(() => $notice.fadeOut(), 4000);
    }

    /* ── AJAX helper ─────────────────────────────────────────────────────── */
    function ajax(action, data, cb) {
        $.post(NWCards.ajaxurl, { action, nonce: NWCards.nonce, ...data }, cb, 'json')
            .fail(() => cb({ success: false, data: 'Network error.' }));
    }

    /* ── Załaduj dane ────────────────────────────────────────────────────── */
    function loadData() {
        $tbody.html('<tr class="nw-loading-row"><td colspan="9"><span class="nw-spinner"></span> Loading…</td></tr>');
        ajax('nw_csc_load', {}, function (res) {
            if (!res.success) { showNotice(res.data || 'Load error.', 'error'); return; }
            allRows    = res.data.rows    || [];
            allClasses = res.data.classes || [];
            allDeck    = res.data.deck    || [];
            populateClassFilter();
            populateModalSelects();
            renderTable();
            updateStats();
        });
    }

    /* ── Wypełnij filter klasy ───────────────────────────────────────────── */
    function populateClassFilter() {
        const $sel = $('#nw-filter-class');
        $sel.find('option:not(:first)').remove();
        allClasses.forEach(c => $sel.append(`<option value="${c.id}">${escHtml(c.name)}</option>`));
    }

    /* ── Wypełnij selekty w modalu ────────────────────────────────────────── */
    function populateModalSelects() {
        const $cls = $('#nw-field-class_id');
        const $crd = $('#nw-field-card_id');
        $cls.find('option:not(:first)').remove();
        $crd.find('option:not(:first)').remove();
        allClasses.forEach(c => $cls.append(`<option value="${c.id}">${escHtml(c.name)}</option>`));
        allDeck.forEach(d => $crd.append(`<option value="${d.id}">${escHtml(d.name)} (#${d.id})</option>`));
    }

    /* ── Statystyki ──────────────────────────────────────────────────────── */
    function updateStats() {
        const active   = allRows.filter(r => r.is_active).length;
        const groups   = new Set(allRows.filter(r => r.pick_group).map(r => r.pick_group + '::' + (r.cyber_classes?.id || r.class_id))).size;
        const optional = allRows.filter(r => r.is_optional).length;
        $('#nw-total').text(allRows.length);
        $('#nw-active').text(active);
        $('#nw-groups').text(groups);
        $('#nw-optional').text(optional);
    }

    /* ── Render tabeli ───────────────────────────────────────────────────── */
    function renderTable() {
        const search   = $('#nw-search').val().toLowerCase();
        const fClass   = $('#nw-filter-class').val();
        const fStatus  = $('#nw-filter-status').val();
        const fOptional= $('#nw-filter-optional').val();

        const filtered = allRows.filter(r => {
            const className = (r.cyber_classes?.name || r.class_id || '').toLowerCase();
            const cardName  = (r.cyber_deck?.name    || String(r.card_id)).toLowerCase();
            const group     = (r.pick_group || '').toLowerCase();
            if (search && !className.includes(search) && !cardName.includes(search) && !group.includes(search)) return false;
            if (fClass  && (r.cyber_classes?.id || r.class_id) !== fClass) return false;
            if (fStatus !== '') {
                if (fStatus === '1' && !r.is_active)  return false;
                if (fStatus === '0' && r.is_active)   return false;
            }
            if (fOptional !== '') {
                if (fOptional === '1' && !r.is_optional) return false;
                if (fOptional === '0' && r.is_optional)  return false;
            }
            return true;
        });

        const hasFilters = search || fClass || fStatus || fOptional;
        $('#nw-clear-filters').toggle(!!hasFilters);

        if (!filtered.length) {
            $tbody.html('<tr><td colspan="9" style="text-align:center;padding:2rem;color:rgba(255,255,255,.35);">Brak wyników.</td></tr>');
            return;
        }

        const rows = filtered.map(r => {
            const classId   = r.cyber_classes?.id   || r.class_id;
            const className = r.cyber_classes?.name || r.class_id;
            const cardName  = r.cyber_deck?.name    || `Card #${r.card_id}`;
            const color     = classColor(classId);

            const classCell = `<span class="nw-class-label">
                <span class="nw-class-dot" style="background:${color};"></span>
                ${escHtml(className)}
            </span>`;

            const qtyCell = `<span class="nw-qty-wrap" data-id="${r.id}">
                <button class="nw-qty-btn nw-qty-minus" data-id="${r.id}" ${r.qty <= 1 ? 'disabled' : ''}>−</button>
                <span class="nw-qty-val">${r.qty}</span>
                <button class="nw-qty-btn nw-qty-plus"  data-id="${r.id}" ${r.qty >= 10 ? 'disabled' : ''}>+</button>
            </span>`;

            const pickCell = r.pick_group
                ? `<span class="nw-pick-pill">${escHtml(r.pick_group)}</span>`
                : '<span style="color:rgba(255,255,255,.25);">—</span>';

            const pickCount = r.pick_count
                ? `<span class="nw-pick-count">${r.pick_count}</span>`
                : '<span style="color:rgba(255,255,255,.25);">—</span>';

            const typeCell = r.is_optional
                ? '<span class="nw-badge-optional">Optional</span>'
                : '<span class="nw-badge-required">Required</span>';

            const statusCell = `<span class="nw-dot ${r.is_active ? 'nw-dot-active' : 'nw-dot-inactive'}"></span>${r.is_active ? 'Active' : 'Inactive'}`;

            return `<tr data-id="${r.id}">
                <td>${classCell}</td>
                <td>${escHtml(cardName)}</td>
                <td>${qtyCell}</td>
                <td>${pickCell}</td>
                <td>${pickCount}</td>
                <td>${typeCell}</td>
                <td>${r.sort_order}</td>
                <td>${statusCell}</td>
                <td>
                    <button class="nw-btn nw-btn-sm nw-btn-ghost nw-edit-btn" data-id="${r.id}" title="Edit">
                        <i data-lucide="pencil" style="width:13px;height:13px;"></i>
                    </button>
                </td>
            </tr>`;
        });

        $tbody.html(rows.join(''));
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    /* ── Modal: otwórz (nowy) ─────────────────────────────────────────────── */
    function openModalNew() {
        $('#nw-modal-title').text('Add Starting Card');
        $('#nw-save-label').text('Add Card');
        $('#nw-delete-btn').hide();
        $form[0].reset();
        $('#nw-field-id').val('');
        $('#nw-field-is_active').prop('checked', true);
        $('#nw-field-is_optional').prop('checked', false);
        $overlay.show();
    }

    /* ── Modal: otwórz (edycja) ───────────────────────────────────────────── */
    function openModalEdit(id) {
        const r = allRows.find(x => x.id === id);
        if (!r) return;

        $('#nw-modal-title').text('Edit Starting Card');
        $('#nw-save-label').text('Save Changes');
        $('#nw-delete-btn').show();
        $('#nw-field-id').val(r.id);
        $('#nw-field-class_id').val(r.cyber_classes?.id || r.class_id);
        $('#nw-field-card_id').val(r.card_id);
        $('#nw-field-qty').val(r.qty);
        $('#nw-field-pick_group').val(r.pick_group || '');
        $('#nw-field-pick_count').val(r.pick_count || '');
        $('#nw-field-sort_order').val(r.sort_order);
        $('#nw-field-notes').val(r.notes || '');
        $('#nw-field-is_optional').prop('checked', !!r.is_optional);
        $('#nw-field-is_active').prop('checked', !!r.is_active);
        $overlay.show();
    }

    function closeModal() { $overlay.hide(); }

    /* ── Zapis ───────────────────────────────────────────────────────────── */
    function saveForm() {
        const $btn = $('#nw-save-btn');
        $btn.prop('disabled', true).find('#nw-save-label').text('Saving…');

        const data = {
            id:          $('#nw-field-id').val(),
            class_id:    $('#nw-field-class_id').val(),
            card_id:     $('#nw-field-card_id').val(),
            qty:         $('#nw-field-qty').val(),
            pick_group:  $('#nw-field-pick_group').val().trim(),
            pick_count:  $('#nw-field-pick_count').val().trim(),
            sort_order:  $('#nw-field-sort_order').val(),
            notes:       $('#nw-field-notes').val().trim(),
            is_optional: $('#nw-field-is_optional').is(':checked') ? 1 : 0,
            is_active:   $('#nw-field-is_active').is(':checked')   ? 1 : 0,
        };

        ajax('nw_csc_save', data, function (res) {
            $btn.prop('disabled', false).find('#nw-save-label').text(data.id ? 'Save Changes' : 'Add Card');
            if (!res.success) { showNotice(res.data || 'Error.', 'error'); return; }

            const saved = res.data;
            const idx   = allRows.findIndex(x => x.id === saved.id);

            // Zachowaj relacyjne nazwy jeśli supa nie zwraca joinów w PATCH/POST
            if (saved && !saved.cyber_classes) {
                const cls = allClasses.find(c => c.id === saved.class_id);
                const crd = allDeck.find(d => d.id === saved.card_id);
                if (cls) saved.cyber_classes = { id: cls.id, name: cls.name };
                if (crd) saved.cyber_deck    = { id: crd.id, name: crd.name };
            }

            if (idx >= 0) allRows[idx] = saved;
            else allRows.unshift(saved);

            renderTable();
            updateStats();
            closeModal();
            showNotice(data.id ? 'Zaktualizowano.' : 'Dodano kartę.');
        });
    }

    /* ── Usuwanie ────────────────────────────────────────────────────────── */
    function deleteRow(id) {
        if (!confirm('Usunąć to przypisanie? Tej operacji nie można cofnąć.')) return;
        ajax('nw_csc_delete', { id }, function (res) {
            if (!res.success) { showNotice(res.data || 'Delete failed.', 'error'); return; }
            allRows = allRows.filter(r => r.id !== id);
            renderTable();
            updateStats();
            closeModal();
            showNotice('Usunięto.');
        });
    }

    /* ── Qty inline ──────────────────────────────────────────────────────── */
    function changeQty(id, delta) {
        const row = allRows.find(r => r.id === id);
        if (!row) return;
        const newQty = row.qty + delta;
        if (newQty < 1 || newQty > 10) return;

        const $wrap = $(`[data-id="${id}"].nw-qty-wrap`);
        const $val  = $wrap.find('.nw-qty-val');
        $val.addClass('saving').text(newQty);

        ajax('nw_csc_qty', { id, qty: newQty }, function (res) {
            $val.removeClass('saving');
            if (!res.success) { $val.text(row.qty); showNotice(res.data || 'Qty update failed.', 'error'); return; }
            row.qty = newQty;
            // odśwież przyciski
            $wrap.find('.nw-qty-minus').prop('disabled', newQty <= 1);
            $wrap.find('.nw-qty-plus').prop('disabled', newQty >= 10);
        });
    }

    /* ── Escape HTML ─────────────────────────────────────────────────────── */
    function escHtml(str) {
        return String(str)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    /* ── Events ──────────────────────────────────────────────────────────── */
    $(function () {

        // Załaduj
        loadData();

        // Refresh
        $('#nw-refresh-btn').on('click', function () {
            // wyczyść cache transient po stronie PHP przez zwykły load (bust działa przy save/delete)
            loadData();
        });

        // Dodaj
        $('#nw-add-btn').on('click', openModalNew);

        // Zamknij modal
        $('#nw-modal-close, #nw-cancel-btn').on('click', closeModal);
        $overlay.on('click', function (e) { if ($(e.target).is($overlay)) closeModal(); });

        // Zapis
        $('#nw-save-btn').on('click', saveForm);
        $form.on('submit', function (e) { e.preventDefault(); saveForm(); });

        // Delete (delegacja)
        $('#nw-delete-btn').on('click', function () {
            const id = $('#nw-field-id').val();
            if (id) deleteRow(id);
        });

        // Edycja (delegacja)
        $tbody.on('click', '.nw-edit-btn', function () {
            openModalEdit($(this).data('id'));
        });

        // Qty ± (delegacja)
        $tbody.on('click', '.nw-qty-minus', function () {
            changeQty($(this).data('id'), -1);
        });
        $tbody.on('click', '.nw-qty-plus', function () {
            changeQty($(this).data('id'), +1);
        });

        // Filtry
        $('#nw-search, #nw-filter-class, #nw-filter-status, #nw-filter-optional')
            .on('input change', renderTable);

        // Clear filters
        $('#nw-clear-filters').on('click', function () {
            $('#nw-search').val('');
            $('#nw-filter-class, #nw-filter-status, #nw-filter-optional').val('');
            renderTable();
        });

        // Walidacja pick_group/pick_count na bieżąco
        $('#nw-field-pick_group').on('input', function () {
            if (!$(this).val().trim()) $('#nw-field-pick_count').val('');
        });
        $('#nw-field-pick_count').on('input', function () {
            if ($(this).val() && !$('#nw-field-pick_group').val().trim()) {
                showNotice('Ustaw najpierw Pick Group.', 'error');
            }
        });

    });

})(jQuery);
