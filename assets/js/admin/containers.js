/* global ajaxurl, NWContainers */

jQuery(function ($) {
    'use strict';

    var cfg          = window.NWContainers || {};
    var ajaxEndpoint = cfg.ajaxurl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
    var nonce        = cfg.nonce || '';
    var all          = [];
    var activeXhr    = null;

    /* ---------------------------------------------------------------- */
    /*  Helpers                                                          */
    /* ---------------------------------------------------------------- */

    function esc(s) {
        return $('<span>').text(s || '').html();
    }

    function notice(msg, type) {
        var el = $('#nw-notice');
        var safeType = String(type || 'info').replace(/[^a-z-]/g, '');
        el.attr('class', 'nw-notice nw-notice-' + safeType).text(msg).stop(true, true).show();
        setTimeout(function () {
            el.fadeOut(300);
        }, 3500);
    }

    function updateStats(data) {
        var active = (data || []).filter(function (r) {
            return r.is_active !== false;
        }).length;

        $('#nw-total').text(data.length || 0);
        $('#nw-active').text(active);
        $('#nw-inactive').text((data.length || 0) - active);
    }

    function normaliseRow(r) {
        return {
            id: r.id || '',
            name: r.name || '',
            description: r.description || '',
            total_slots: r.total_slots != null ? parseInt(r.total_slots, 10) : 5,
            allowed_sizes: Array.isArray(r.allowed_sizes) ? r.allowed_sizes : [],
            img_url: r.img_url || '',
            rarity: r.rarity || 'common',
            is_active: r.is_active !== false,
            created_at: r.created_at || '',
            parent_id: r.parent_id || ''
        };
    }

    var rarityClass = {
        common: 'nw-rarity-common',
        uncommon: 'nw-rarity-uncommon',
        rare: 'nw-rarity-rare',
        epic: 'nw-rarity-epic',
        legendary: 'nw-rarity-legendary'
    };

    /* ---------------------------------------------------------------- */
    /*  Table rendering                                                  */
    /* ---------------------------------------------------------------- */

    function renderTable(data) {
        var tbody = $('#nw-containers-tbody');

        if (!data || !data.length) {
            tbody.html('<tr><td colspan="7" style="text-align:center;padding:32px;color:#555;">No containers found.</td></tr>');
            return;
        }

        tbody.html(data.map(function (r) {
            var active = r.is_active !== false;
            var sizes = Array.isArray(r.allowed_sizes) ? r.allowed_sizes : [];
            var sizesH = sizes.map(function (s) {
                return '<span class="nw-size-tag">' + esc(s) + '</span>';
            }).join('');

            var rarCls = rarityClass[r.rarity] || 'nw-rarity-common';

            var imgH = r.img_url
                ? '<img src="' + esc(r.img_url) + '" class="nw-cont-img" loading="lazy" data-fallback="1" alt="">'
                : '<div class="nw-cont-img-placeholder">🎒</div>';

            var descSnippet = r.description
                ? esc(r.description.substring(0, 50) + (r.description.length > 50 ? '…' : ''))
                : '';

            return '<tr data-id="' + esc(r.id) + '" class="' + (active ? '' : 'nw-row-inactive') + '">'
                + '<td>' + imgH + '</td>'
                + '<td><div class="nw-cont-name">' + esc(r.name) + '</div>'
                + '<div class="nw-cont-sub">' + descSnippet + '</div></td>'
                + '<td><span class="nw-rarity ' + rarCls + '">' + esc(r.rarity || 'common') + '</span></td>'
                + '<td><span class="nw-slots-badge">' + esc(String(r.total_slots || '?')) + '</span></td>'
                + '<td><div class="nw-sizes">' + sizesH + '</div></td>'
                + '<td><label class="nw-toggle">'
                + '<input type="checkbox" class="nw-active-toggle" data-id="' + esc(r.id) + '" ' + (active ? 'checked' : '') + '>'
                + '<span class="nw-toggle-slider"></span></label></td>'
                + '<td><div class="nw-row-actions">'
                + '<button type="button" class="nw-action-btn nw-edit-btn" data-id="' + esc(r.id) + '">Edit</button>'
                + '</div></td>'
                + '</tr>';
        }).join(''));

        tbody.find('img[data-fallback]')
            .off('error.nwFallback')
            .on('error.nwFallback', function () {
                $(this).hide();
            });
    }

    /* ---------------------------------------------------------------- */
    /*  Load                                                             */
    /* ---------------------------------------------------------------- */

    function loadAll() {
        if (!ajaxEndpoint) {
            notice('Missing AJAX endpoint.', 'error');
            return;
        }

        if (!nonce) {
            notice('Missing nonce.', 'error');
            return;
        }

        if (activeXhr && activeXhr.readyState !== 4) {
            activeXhr.abort();
        }

        $('#nw-containers-tbody').html(
            '<tr class="nw-loading-row"><td colspan="7"><div class="nw-spinner"></div> Loading…</td></tr>'
        );

        activeXhr = $.post(ajaxEndpoint, {
            action: 'nw_containers_get_all',
            nonce: nonce
        }, function (res) {
            if (!res || !res.success) {
                notice('Error: ' + ((res && res.data) || 'Unknown error'), 'error');
                return;
            }

            var rows = Array.isArray(res.data)
                ? res.data
                : (res.data && typeof res.data === 'object' ? Object.values(res.data) : []);

            all = rows.map(normaliseRow);
            renderTable(all);
            updateStats(all);
        }).fail(function (xhr, status) {
            if (status !== 'abort') {
                notice('Request failed (' + (xhr.status || status) + ').', 'error');
            }
        }).always(function () {
            activeXhr = null;
        });
    }

    /* ---------------------------------------------------------------- */
    /*  Toggle active                                                    */
    /* ---------------------------------------------------------------- */

    $(document).on('change', '.nw-active-toggle', function () {
        var checkbox = $(this);
        var id = checkbox.data('id');
        var val = checkbox.is(':checked');
        var row = checkbox.closest('tr');

        $.post(ajaxEndpoint, {
            action: 'nw_containers_toggle',
            nonce: nonce,
            container_id: id,
            is_active: val ? 1 : 0
        }, function (res) {
            if (res && res.success) {
                row.toggleClass('nw-row-inactive', !val);
                all = all.map(function (r) {
                    if (r.id === id) {
                        r.is_active = val;
                    }
                    return r;
                });
                updateStats(all);
                notice((val ? 'Activated' : 'Deactivated') + '.', 'success');
            } else {
                notice('Toggle failed: ' + ((res && res.data) || 'Unknown'), 'error');
                checkbox.prop('checked', !val);
            }
        }).fail(function (xhr) {
            checkbox.prop('checked', !val);
            notice('Toggle request failed (' + (xhr.status || 'network') + ').', 'error');
        });
    });

    /* ---------------------------------------------------------------- */
    /*  Modal                                                            */
    /* ---------------------------------------------------------------- */

    function openModal(id) {
        var form = $('#nw-container-form');

        if (form.length && form[0]) {
            form[0].reset();
        }

        $('#nw-field-id').val('');
        $('#nw-field-total_slots').val(5);
        $('#nw-val-total_slots').text(5);
        $("input[name='allowed_sizes[]']").prop('checked', true);
        $('#nw-field-is_active').prop('checked', true);

        if (id) {
            var r = all.find(function (x) {
                return x.id === id;
            });

            if (r) {
                $('#nw-field-id').val(r.id);
                $('#nw-field-name').val(r.name || '');
                $('#nw-field-description').val(r.description || '');
                $('#nw-field-img_url').val(r.img_url || '');
                $('#nw-field-rarity').val(r.rarity || 'common');
                $('#nw-field-total_slots').val(r.total_slots || 5);
                $('#nw-val-total_slots').text(r.total_slots || 5);
                $('#nw-field-parent_id').val(r.parent_id || '');
                $('#nw-field-is_active').prop('checked', r.is_active !== false);

                var sizes = Array.isArray(r.allowed_sizes) ? r.allowed_sizes : [];
                $("input[name='allowed_sizes[]']").each(function () {
                    $(this).prop('checked', sizes.indexOf($(this).val()) !== -1);
                });
            }

            $('#nw-modal-title').text('Edit Container');
            $('#nw-save-label').text('Save Changes');
        } else {
            $('#nw-modal-title').text('New Container');
            $('#nw-save-label').text('Create Container');
        }

        $('#nw-modal-overlay').fadeIn(150);
    }

    /* ---------------------------------------------------------------- */
    /*  Events                                                           */
    /* ---------------------------------------------------------------- */

    $(document).on('input', '#nw-field-total_slots', function () {
        $('#nw-val-total_slots').text($(this).val());
    });

    $('#nw-modal-close, #nw-cancel-btn').on('click', function () {
        $('#nw-modal-overlay').fadeOut(150);
    });

    $('#nw-modal-overlay').on('click', function (e) {
        if ($(e.target).is('#nw-modal-overlay')) {
            $('#nw-modal-overlay').fadeOut(150);
        }
    });

    $(document).on('click', '.nw-edit-btn', function () {
        openModal($(this).data('id'));
    });

    $('#nw-add-btn').on('click', function () {
        openModal(null);
    });

    $('#nw-refresh-btn').on('click', loadAll);

    /* ---------------------------------------------------------------- */
    /*  Save                                                             */
    /* ---------------------------------------------------------------- */

    $('#nw-save-btn').on('click', function () {
        var name = $('#nw-field-name').val().trim();
        if (!name) {
            notice('Name is required.', 'error');
            return;
        }

        var btn = $(this);
        var previousLabel = $('#nw-save-label').text();

        btn.prop('disabled', true);
        $('#nw-save-label').text('Saving…');

        var sizes = [];
        $("input[name='allowed_sizes[]']:checked").each(function () {
            sizes.push($(this).val());
        });

        if (!sizes.length) {
            sizes = ['tiny', 'small', 'medium', 'large'];
        }

        var fd = {
            action: 'nw_containers_save',
            nonce: nonce,
            container: {
                id: $('#nw-field-id').val().trim(),
                name: $('#nw-field-name').val().trim(),
                description: $('#nw-field-description').val().trim(),
                img_url: $('#nw-field-img_url').val().trim(),
                rarity: $('#nw-field-rarity').val(),
                total_slots: $('#nw-field-total_slots').val(),
                parent_id: $('#nw-field-parent_id').val().trim(),
                allowed_sizes: sizes,
                is_active: $('#nw-field-is_active').is(':checked') ? 1 : 0
            }
        };

        $.post(ajaxEndpoint, fd, function (res) {
            btn.prop('disabled', false);
            $('#nw-save-label').text(previousLabel);

            if (res && res.success) {
                notice('Container saved!', 'success');
                $('#nw-modal-overlay').fadeOut(150);
                loadAll();
            } else {
                notice('Error: ' + ((res && res.data) || 'Unknown'), 'error');
            }
        }).fail(function (xhr) {
            btn.prop('disabled', false);
            $('#nw-save-label').text(previousLabel);
            notice('Request failed (' + (xhr.status || 'network') + ').', 'error');
        });
    });

    /* ---------------------------------------------------------------- */
    /*  Init                                                             */
    /* ---------------------------------------------------------------- */

    loadAll();
});
