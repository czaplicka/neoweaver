/**
 * NeoWeaver — Style Dictionary Admin JS
 * Depends on: jQuery, NW_SD (ajax_url, nonce)
 */
/* global jQuery, NW_SD */
(function ($) {
    'use strict';

    /* ---------------------------------------------------------------- */
    /*  State                                                             */
    /* ---------------------------------------------------------------- */
    let allTags   = [];
    let editingId = null;

    /* ---------------------------------------------------------------- */
    /*  DOM refs                                                          */
    /* ---------------------------------------------------------------- */
    const $tbody      = $('#nw-sd-tbody');
    const $notice     = $('#nw-notice');
    const $overlay    = $('#nw-modal-overlay');
    const $modalTitle = $('#nw-modal-title');
    const $form       = $('#nw-sd-form');
    const $deleteBtn  = $('#nw-delete-btn');
    const $saveLabel  = $('#nw-save-label');

    /* filter refs */
    const $fCat    = $('#nw-filter-category');
    const $fActive = $('#nw-filter-active');
    const $fSearch = $('#nw-filter-search');

    /* stat refs */
    const $total    = $('#nw-total');
    const $active   = $('#nw-active');
    const $inactive = $('#nw-inactive');

    /* ---------------------------------------------------------------- */
    /*  Notice helper                                                     */
    /* ---------------------------------------------------------------- */
    function showNotice(msg, isError) {
        $notice
            .removeClass('is-error')
            .toggleClass('is-error', !!isError)
            .html(msg)
            .show();
        clearTimeout($notice.data('timer'));
        $notice.data('timer', setTimeout(function () { $notice.fadeOut(); }, 4000));
    }

    /* ---------------------------------------------------------------- */
    /*  Stats                                                             */
    /* ---------------------------------------------------------------- */
    function updateStats(tags) {
        var activeCount   = tags.filter(function (t) { return t.is_active; }).length;
        var inactiveCount = tags.length - activeCount;
        $total.text(tags.length);
        $active.text(activeCount);
        $inactive.text(inactiveCount);
        $('.nw-cat-count').each(function () {
            var cat = $(this).data('cat');
            $(this).text(tags.filter(function (t) { return t.category === cat; }).length);
        });
    }

    /* ---------------------------------------------------------------- */
    /*  Render table                                                      */
    /* ---------------------------------------------------------------- */
    function renderTable(tags) {
        $tbody.empty();
        if (!tags.length) {
            $tbody.append('<tr class="nw-empty-row"><td colspan="5">No tags found.</td></tr>');
            return;
        }
        tags.forEach(function (tag) {
            var activeChecked = tag.is_active ? 'checked' : '';
            var rowClass      = tag.is_active ? '' : 'nw-row-inactive';
            $tbody.append(
                '<tr class="' + rowClass + '" data-id="' + tag.id + '">' +
                '<td><strong>' + escHtml(tag.tag_name) + '</strong></td>' +
                '<td><span class="nw-cat-badge ' + escHtml(tag.category) + '">' + escHtml(tag.category) + '</span></td>' +
                '<td>' + escHtml(tag.interpretation_en) + '</td>' +
                '<td>' +
                    '<label class="nw-toggle nw-toggle-cell">' +
                    '<input type="checkbox" class="nw-quick-toggle" data-id="' + tag.id + '" ' + activeChecked + '>' +
                    '<span class="nw-toggle-slider"></span>' +
                    '</label>' +
                '</td>' +
                '<td><button class="nw-action-btn nw-edit-btn" data-id="' + tag.id + '">Edit</button></td>' +
                '</tr>'
            );
        });
    }

    /* ---------------------------------------------------------------- */
    /*  Filter & search                                                   */
    /* ---------------------------------------------------------------- */
    function applyFilters() {
        var cat    = $fCat.val();
        var active = $fActive.val();
        var search = $fSearch.val().toLowerCase();

        var filtered = allTags.filter(function (t) {
            if (cat    && t.category !== cat)              return false;
            if (active === '1' && !t.is_active)            return false;
            if (active === '0' && t.is_active)             return false;
            if (search && !t.tag_name.toLowerCase().includes(search) &&
                          !t.interpretation_en.toLowerCase().includes(search)) return false;
            return true;
        });
        renderTable(filtered);
    }

    $fCat.on('change', applyFilters);
    $fActive.on('change', applyFilters);
    $fSearch.on('input', applyFilters);

    /* ---------------------------------------------------------------- */
    /*  Load all tags                                                     */
    /* ---------------------------------------------------------------- */
    function loadTags() {
        $tbody.html('<tr class="nw-loading-row"><td colspan="5"><div class="nw-spinner"></div> Loading tags…</td></tr>');
        $.post(NW_SD.ajax_url, { action: 'nw_sd_get_all', nonce: NW_SD.nonce }, function (res) {
            if (!res.success) { showNotice(res.data || 'Load failed.', true); return; }
            allTags = res.data || [];
            updateStats(allTags);
            applyFilters();
        }).fail(function () { showNotice('Network error.', true); });
    }

    /* ---------------------------------------------------------------- */
    /*  Modal helpers                                                     */
    /* ---------------------------------------------------------------- */
    function openModal(tag) {
        editingId = tag ? tag.id : null;
        $form[0].reset();
        $modalTitle.text(tag ? 'Edit Style Tag' : 'New Style Tag');
        $saveLabel.text(tag ? 'Save Tag' : 'Create Tag');
        $deleteBtn.toggle(!!tag);

        if (tag) {
            $('#nw-field-id').val(tag.id);
            $('#nw-field-tag_name').val(tag.tag_name);
            $('#nw-field-category').val(tag.category);
            $('#nw-field-interpretation_en').val(tag.interpretation_en);
            $('#nw-field-is_active').prop('checked', !!tag.is_active);
        }
        $overlay.show();
    }

    function closeModal() {
        $overlay.hide();
        editingId = null;
    }

    /* ---------------------------------------------------------------- */
    /*  Save                                                              */
    /* ---------------------------------------------------------------- */
    function saveTag() {
        var payload = {
            action : 'nw_sd_save',
            nonce  : NW_SD.nonce,
            tag    : {
                id                : $('#nw-field-id').val(),
                tag_name          : $('#nw-field-tag_name').val(),
                category          : $('#nw-field-category').val(),
                interpretation_en : $('#nw-field-interpretation_en').val(),
                is_active         : $('#nw-field-is_active').is(':checked') ? '1' : '0',
            }
        };
        $saveLabel.text('Saving…');
        $.post(NW_SD.ajax_url, payload, function (res) {
            $saveLabel.text(editingId ? 'Save Tag' : 'Create Tag');
            if (!res.success) { showNotice(res.data || 'Save failed.', true); return; }
            showNotice(editingId ? 'Tag updated.' : 'Tag created.');
            closeModal();
            loadTags();
        }).fail(function () {
            $saveLabel.text(editingId ? 'Save Tag' : 'Create Tag');
            showNotice('Network error.', true);
        });
    }

    /* ---------------------------------------------------------------- */
    /*  Toggle is_active                                                  */
    /* ---------------------------------------------------------------- */
    function toggleTag(id, isActive) {
        $.post(NW_SD.ajax_url, { action: 'nw_sd_toggle', nonce: NW_SD.nonce, tag_id: id, is_active: isActive ? '1' : '0' },
            function (res) {
                if (!res.success) { showNotice(res.data || 'Toggle failed.', true); loadTags(); return; }
                /* update in-memory */
                var tag = allTags.find(function (t) { return t.id == id; });
                if (tag) tag.is_active = isActive;
                updateStats(allTags);
                var $row = $tbody.find('tr[data-id="' + id + '"]');
                $row.toggleClass('nw-row-inactive', !isActive);
            }
        ).fail(function () { showNotice('Network error.', true); loadTags(); });
    }

    /* ---------------------------------------------------------------- */
    /*  Delete                                                            */
    /* ---------------------------------------------------------------- */
    function deleteTag(id) {
        if (!confirm('Delete this style tag? This cannot be undone.')) return;
        $.post(NW_SD.ajax_url, { action: 'nw_sd_delete', nonce: NW_SD.nonce, tag_id: id }, function (res) {
            if (!res.success) { showNotice(res.data || 'Delete failed.', true); return; }
            showNotice('Tag deleted.');
            closeModal();
            loadTags();
        }).fail(function () { showNotice('Network error.', true); });
    }

    /* ---------------------------------------------------------------- */
    /*  Event bindings                                                    */
    /* ---------------------------------------------------------------- */
    $('#nw-refresh-btn').on('click', loadTags);
    $('#nw-add-btn').on('click', function () { openModal(null); });
    $('#nw-cancel-btn, #nw-modal-close').on('click', closeModal);
    $overlay.on('click', function (e) { if ($(e.target).is($overlay)) closeModal(); });
    $('#nw-save-btn').on('click', saveTag);
    $form.on('submit', function (e) { e.preventDefault(); saveTag(); });
    $('#nw-delete-btn').on('click', function () { if (editingId) deleteTag(editingId); });

    /* delegated: edit button */
    $tbody.on('click', '.nw-edit-btn', function () {
        var id  = $(this).data('id');
        var tag = allTags.find(function (t) { return t.id == id; });
        if (tag) openModal(tag);
    });

    /* delegated: quick toggle */
    $tbody.on('change', '.nw-quick-toggle', function () {
        toggleTag($(this).data('id'), this.checked);
    });

    /* keyboard close */
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') closeModal();
    });

    /* ---------------------------------------------------------------- */
    /*  Utility                                                           */
    /* ---------------------------------------------------------------- */
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* ---------------------------------------------------------------- */
    /*  Init                                                              */
    /* ---------------------------------------------------------------- */
    loadTags();

}(jQuery));
