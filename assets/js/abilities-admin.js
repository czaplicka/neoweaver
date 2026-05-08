jQuery(function ($) {
    'use strict';
    var cfg = window.NWAbilities || {};
    var ajaxEndpoint = cfg.ajaxurl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
    var nonce = cfg.nonce || '';
    var $notice = $('#nw-notice');
    var $tbody = $('#nw-abilities-tbody');
    var $filterType = $('#nw-filter-type');
    var $search = $('#nw-search');
    var $modalOverlay = $('#nw-modal-overlay');
    var $form = $('#nw-ability-form');
    var $saveBtn = $('#nw-save-btn');
    var $saveLabel = $('#nw-save-label');
    var $deleteBtn = $('#nw-delete-btn');
    var $imgPreview = $('#nw-img-preview');
    var $imgPreviewWrap = $('#nw-img-preview-wrap');
    var $fieldId = $('#nw-field-id');
    var $fieldName = $('#nw-field-name');
    var $fieldDescription = $('#nw-field-description');
    var $fieldGMNotes = $('#nw-field-gm_notes');
    var $fieldAbilityType = $('#nw-field-ability_type');
    var $fieldSource = $('#nw-field-source');
    var $fieldCost = $('#nw-field-cost');
    var $fieldTags = $('#nw-field-tags');
    var $fieldImgUrl = $('#nw-field-img_url');
    var all = [];
    var filtered = [];
    var activeXhr = null;

    var typeClass = {
        'Active': 'nw-type-active',
        'Passive': 'nw-type-passive',
        'Reaction': 'nw-type-reaction',
        'Ultimate': 'nw-type-ultimate',
        'Racial': 'nw-type-racial',
        'Class': 'nw-type-class',
        'Item': 'nw-type-item',
        'Special': 'nw-type-special'
    };

    function esc(s) {
        return $('<span>').text(s || '').html();
    }

    function notice(msg, type) {
        var safeType = String(type || 'info').replace(/[^a-z-]/g, '');
        $notice
            .attr('class', 'nw-notice nw-notice-' + safeType)
            .text(msg)
            .show();

        setTimeout(function () {
            $notice.fadeOut(300);
        }, 3500);
    }

    function tagsStr(t) {
        if (!t) return '';
        if (Array.isArray(t)) return t.join(', ');
        return String(t);
    }

    function debounce(fn, delay) {
        var timer;
        return function () {
            var args = arguments;
            var ctx = this;
            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(ctx, args);
            }, delay);
        };
    }

   function cloneAbilities(data) {
    var list = data;

    if (typeof list === 'string') {
        try {
            list = JSON.parse(list);
        } catch (e) {
            list = [];
        }
    }

    if (Array.isArray(list)) {
        // ok
    } else if (list && typeof list === 'object') {
        list = Object.values(list);
    } else {
        list = [];
    }

    return list.map(function (item) {
        return {
            id: item.id || '',
            name: item.name || '',
            description: item.description || '',
            gm_notes: item.gm_notes || '',
            ability_type: item.ability_type || '',
            source: item.source || '',
            cost: item.cost || '',
            img_url: item.img_url || '',
            tags: Array.isArray(item.tags) ? item.tags.slice() : []
        };
    });
}
    function updateStats(data) {
        var active = 0;
        var passive = 0;

        (data || []).forEach(function (a) {
            if (a.ability_type === 'Active') active++;
            else if (a.ability_type === 'Passive') passive++;
        });

        $('#nw-total').text(data.length);
        $('#nw-active-count').text(active);
        $('#nw-passive-count').text(passive);
        $('#nw-other-count').text(data.length - active - passive);
    }

    function bindImageFallbacks() {
        $tbody.find('img[data-fallback]')
            .off('error.nwFallback')
            .on('error.nwFallback', function () {
                $(this).hide();
            });
    }

    function renderTable(data) {
        if (!data.length) {
            $tbody.html('<tr><td colspan="7" style="text-align:center;padding:32px;color:#555;">No abilities found.</td></tr>');
            return;
        }

        $tbody.html(data.map(function (a) {
            var tags = Array.isArray(a.tags) ? a.tags : [];
            var safeId = esc(a.id);

            var tagsH = tags.slice(0, 3).map(function (t) {
                return '<span class="nw-tag">' + esc(t) + '</span>';
            }).join('') + (tags.length > 3 ? '<span class="nw-tag">+' + (tags.length - 3) + '</span>' : '');

            var tc = typeClass[a.ability_type] || 'nw-type-special';
            var typeH = a.ability_type
                ? '<span class="nw-type-badge ' + tc + '">' + esc(a.ability_type) + '</span>'
                : '—';

            var imgH = a.img_url
                ? '<img src="' + esc(a.img_url) + '" class="nw-ability-img" loading="lazy" data-fallback="1" alt="">'
                : '<div class="nw-ability-img-placeholder">✨</div>';

            return '<tr data-id="' + safeId + '">'
                + '<td>' + imgH + '</td>'
                + '<td><div class="nw-ability-name">' + esc(a.name) + '</div><div class="nw-ability-desc">' + esc(a.description || '') + '</div></td>'
                + '<td>' + typeH + '</td>'
                + '<td><div class="nw-source">' + esc(a.source || '—') + '</div></td>'
                + '<td>' + (a.cost ? '<span class="nw-cost-badge">' + esc(a.cost) + '</span>' : '<span style="color:#444">—</span>') + '</td>'
                + '<td><div class="nw-tags">' + tagsH + '</div></td>'
                + '<td><div class="nw-row-actions"><button class="nw-action-btn nw-edit-btn" data-id="' + safeId + '">Edit</button></div></td>'
                + '</tr>';
        }).join(''));

        bindImageFallbacks();
    }

    function applySearch() {
        var q = $search.val().toLowerCase().trim();

        var shown = q ? filtered.filter(function (a) {
            var tagMatch = (Array.isArray(a.tags) ? a.tags : []).some(function (t) {
                return String(t).toLowerCase().includes(q);
            });

            return String(a.name || '').toLowerCase().includes(q)
                || String(a.source || '').toLowerCase().includes(q)
                || tagMatch;
        }) : filtered;

        renderTable(shown);
    }

    function loadAll() {
        var ft = $filterType.val();

        if (!ajaxEndpoint) {
            notice('Missing AJAX endpoint.', 'error');
            return;
        }

        if (activeXhr && activeXhr.readyState !== 4) {
            activeXhr.abort();
        }

        $tbody.html('<tr class="nw-loading-row"><td colspan="7"><div class="nw-spinner"></div> Loading…</td></tr>');

        activeXhr = $.post(ajaxEndpoint, {
    action: 'nw_abilities_get_all',
    nonce: nonce,
    filter_type: ft
}, function (res) {
    if (!res || !res.success) {
        notice('Error: ' + ((res && res.data) || 'Unknown error'), 'error');
        return;
    }
console.log('Abilities AJAX response:', res);
console.log('Abilities data type:', typeof res.data, Array.isArray(res.data), res.data);
    var rows = Array.isArray(res.data)
        ? res.data
        : (res.data && typeof res.data === 'object' ? Object.values(res.data) : []);

    all = cloneAbilities(rows);
    filtered = cloneAbilities(rows);
    updateStats(all);
    applySearch();
}).fail(function (xhr, status) {
    if (status !== 'abort') {
        notice('Request failed.', 'error');
    }
}).always(function () {
    activeXhr = null;
});
    }

    function confirmModal(message, onConfirm) {
        if ($('.nw-confirm-overlay').length) return;

        var overlay = $(
            '<div class="nw-confirm-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99999;display:flex;align-items:center;justify-content:center;">'
            + '<div class="nw-confirm-box" style="background:#1a1a2e;border:1px solid #adff00;border-radius:8px;padding:32px 28px;min-width:320px;text-align:center;">'
            + '<p style="color:#fff;margin-bottom:24px;font-family:\'Chakra Petch\',sans-serif;">' + esc(message) + '</p>'
            + '<button class="nw-confirm-yes nw-action-btn" style="margin-right:12px;">Delete</button>'
            + '<button class="nw-confirm-no nw-action-btn" style="background:#333;">Cancel</button>'
            + '</div></div>'
        );

        $('body').append(overlay);

        overlay.find('.nw-confirm-yes').on('click', function () {
            overlay.remove();
            onConfirm();
        });

        overlay.find('.nw-confirm-no').on('click', function () {
            overlay.remove();
        });

        overlay.on('click', function (e) {
            if ($(e.target).is(overlay)) overlay.remove();
        });
    }

    function openModal(id) {
        $form[0].reset();
        $fieldId.val('');
        $imgPreviewWrap.hide();

        if (id) {
            var a = all.find(function (x) {
                return x.id === id;
            });

            if (!a) {
                notice('Ability data not loaded yet.', 'error');
                return;
            }

            $fieldId.val(a.id);
            $fieldName.val(a.name || '');
            $fieldDescription.val(a.description || '');
            $fieldGMNotes.val(a.gm_notes || '');
            $fieldAbilityType.val(a.ability_type || '');
            $fieldSource.val(a.source || '');
            $fieldCost.val(a.cost || '');
            $fieldTags.val(tagsStr(a.tags));

            if (a.img_url) {
                $fieldImgUrl.val(a.img_url);
                $imgPreview.attr('src', a.img_url);
                $imgPreviewWrap.show();
            }

            $('#nw-modal-title').text('Edit Ability');
            $saveLabel.text('Save Changes');
            $deleteBtn.show().data('id', id);
        } else {
            $('#nw-modal-title').text('New Ability');
            $saveLabel.text('Create Ability');
            $deleteBtn.hide();
        }

        $modalOverlay.fadeIn(150);
    }

    $fieldImgUrl.on('input', function () {
        var v = $(this).val().trim();
        if (v) {
            $imgPreview.attr('src', v);
            $imgPreviewWrap.show();
        } else {
            $imgPreviewWrap.hide();
        }
    });

    $('#nw-modal-close,#nw-cancel-btn').on('click', function () {
        $modalOverlay.fadeOut(150);
    });

    $modalOverlay.on('click', function (e) {
        if ($(e.target).is('#nw-modal-overlay')) $modalOverlay.fadeOut(150);
    });

    $(document).on('click', '.nw-edit-btn', function () {
        openModal($(this).data('id'));
    });

    $('#nw-add-btn').on('click', function () {
        openModal(null);
    });

    $('#nw-refresh-btn').on('click', loadAll);
    $filterType.on('change', loadAll);
    $search.on('input', debounce(applySearch, 150));

    $saveBtn.on('click', function () {
        if (!$fieldName.val().trim()) {
            notice('Name is required.', 'error');
            return;
        }

        var btn = $(this);
        var previousLabel = $saveLabel.text();

        btn.prop('disabled', true);
        $saveLabel.text('Saving…');

        var fd = {
            action: 'nw_abilities_save',
            nonce: nonce
        };

        $form.serializeArray().forEach(function (f) {
            fd[f.name] = f.value;
        });

        $.post(ajaxEndpoint, fd, function (res) {
            btn.prop('disabled', false);
            $saveLabel.text(previousLabel);

            if (res.success) {
                notice('Ability saved!', 'success');
                $modalOverlay.fadeOut(150);
                loadAll();
            } else {
                notice('Error: ' + (res.data || 'Unknown'), 'error');
            }
        }).fail(function () {
            btn.prop('disabled', false);
            $saveLabel.text(previousLabel);
            notice('Request failed.', 'error');
        });
    });

    $deleteBtn.on('click', function () {
        var id = $(this).data('id');
        if (!id) return;

        confirmModal('Delete this ability permanently?', function () {
            $.post(ajaxEndpoint, {
                action: 'nw_abilities_delete',
                nonce: nonce,
                ability_id: id
            }, function (res) {
                if (res.success) {
                    notice('Ability deleted.', 'success');
                    $modalOverlay.fadeOut(150);
                    loadAll();
                } else {
                    notice('Delete failed: ' + (res.data || 'Unknown'), 'error');
                }
            }).fail(function () {
                notice('Delete request failed.', 'error');
            });
        });
    });

    loadAll();
});
