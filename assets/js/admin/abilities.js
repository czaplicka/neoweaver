jQuery(function ($) {
    'use strict';

    var cfg = window.NWAbilities || {};
    var ajaxEndpoint = cfg.ajaxurl || '';
    var nonce = cfg.nonce || '';

    var $notice = $('#nw-notice');
    var $tbody = $('#nw-abilities-tbody');
    var $filterType = $('#nw-filter-type');
    var $filterActive = $('#nw-filter-active');
    var $search = $('#nw-search');
    var $modalOverlay = $('#nw-modal-overlay');
    var $form = $('#nw-ability-form');
    var $saveBtn = $('#nw-save-btn');
    var $saveLabel = $('#nw-save-label');
    var $deleteBtn = $('#nw-delete-btn');

    var $fieldOriginalId = $('#nw-field-original_id');
    var $fieldId = $('#nw-field-id');
    var $fieldTitle = $('#nw-field-title');
    var $fieldDescription = $('#nw-field-description');
    var $fieldAbilityType = $('#nw-field-ability_type');
    var $fieldCostType = $('#nw-field-cost_type');
    var $fieldCostValue = $('#nw-field-cost_value');
    var $fieldTargetType = $('#nw-field-target_type');
    var $fieldRangeTiles = $('#nw-field-range_tiles');
    var $fieldDurationTurns = $('#nw-field-duration_turns');
    var $fieldIsPassive = $('#nw-field-is_passive');
    var $fieldIsActive = $('#nw-field-is_active');
    var $fieldTags = $('#nw-field-tags');
    var $fieldImgUrl = $('#nw-field-img_url');
    var $fieldSource = $('#nw-field-source');
    var $fieldGmNotes = $('#nw-field-gm_notes');
    var $imgPreviewWrap = $('#nw-img-preview');
    var $imgPreviewImg = $('#nw-img-preview-img');

    var all = [];
    var filtered = [];
    var activeXhr = null;

    var ABILITY_TYPES = ['active', 'passive', 'reaction', 'aura'];

    var typeClass = {
        active: 'nw-type-active',
        passive: 'nw-type-passive',
        reaction: 'nw-type-reaction',
        aura: 'nw-type-aura'
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
        if (typeof t === 'string') return t;
        return '';
    }

    function tagsArr(str) {
        if (!str) return [];
        return String(str)
            .split(',')
            .map(function (s) { return s.trim(); })
            .filter(Boolean);
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

    function updateImgPreview(url) {
        var trimmed = (url || '').trim();
        if (!$imgPreviewWrap.length || !$imgPreviewImg.length) return;

        if (trimmed) {
            $imgPreviewImg.attr('src', trimmed);
            $imgPreviewWrap.show();
        } else {
            $imgPreviewImg.attr('src', '');
            $imgPreviewWrap.hide();
        }
    }

    function normalise(item) {
        var rawTags = item.tags;
        var tags = [];

        if (Array.isArray(rawTags)) {
            tags = rawTags;
        } else if (typeof rawTags === 'string' && rawTags) {
            try {
                var parsed = JSON.parse(rawTags);
                tags = Array.isArray(parsed) ? parsed : tagsArr(rawTags);
            } catch (e) {
                tags = tagsArr(rawTags);
            }
        }

        return {
            id: item.id || '',
            name: item.name || '',
            title: item.title || '',
            description: item.description || '',
            ability_type: item.ability_type || 'active',
            source: item.source || '',
            gm_notes: item.gm_notes || '',
            cost: item.cost || '',
            img_url: item.img_url || '',
            tags: tags,
            cost_type: item.cost_type || 'none',
            cost_value: item.cost_value != null ? Number(item.cost_value) : 0,
            target_type: item.target_type || 'self',
            range_tiles: item.range_tiles != null ? Number(item.range_tiles) : 1,
            duration_turns: item.duration_turns != null ? Number(item.duration_turns) : 0,
            is_passive: !!item.is_passive,
            is_active: item.is_active !== false && item.is_active !== 'false' && item.is_active !== 0 && item.is_active !== '0',
            sort_order: item.sort_order != null ? Number(item.sort_order) : 0
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
        if (!Array.isArray(list)) {
            list = (list && typeof list === 'object') ? Object.values(list) : [];
        }
        return list.map(normalise);
    }

    function updateStats(data) {
        var counts = { active: 0, passive: 0, reaction: 0, aura: 0 };
        var activeCount = 0;
        var inactiveCount = 0;

        (data || []).forEach(function (a) {
            if (counts.hasOwnProperty(a.ability_type)) counts[a.ability_type]++;
            if (a.is_active) activeCount++;
            else inactiveCount++;
        });

        $('#nw-total').text(data.length);
        $('#nw-active').text(activeCount);
        $('#nw-inactive').text(inactiveCount);

        ABILITY_TYPES.forEach(function (t) {
            $('#nw-count-' + t).text(counts[t] || 0);
        });
    }

    function renderTable(data) {
        if (!data.length) {
            $tbody.html('<tr><td colspan="9" style="text-align:center;padding:32px;color:#555;">No abilities found.</td></tr>');
            return;
        }

        $tbody.html(data.map(function (a) {
            var safeId = esc(a.id);
            var tags = Array.isArray(a.tags) ? a.tags : [];
            var tc = typeClass[a.ability_type] || 'nw-type-active';

            var typeH = a.ability_type
                ? '<span class="nw-type-badge ' + tc + '">' + esc(a.ability_type) + '</span>'
                : '&mdash;';

            var costH = (a.cost_value > 0 && a.cost_type)
                ? '<span class="nw-cost-badge">' + esc(a.cost_value) + ' ' + esc(a.cost_type) + '</span>'
                : '<span style="color:#666;">' + esc(a.cost_type || 'none') + '</span>';

            var passiveH = a.is_passive
                ? '<span class="nw-pill nw-pill-yes">Yes</span>'
                : '<span class="nw-pill nw-pill-no">No</span>';

            var activeH = a.is_active
                ? '<span class="nw-pill nw-pill-yes">Active</span>'
                : '<span class="nw-pill nw-pill-no">Inactive</span>';

            var imgThumb = a.img_url
                ? '<img src="' + esc(a.img_url) + '" alt="" width="32" height="32" style="object-fit:cover;border-radius:4px;vertical-align:middle;margin-right:6px;">'
                : '';

            return '<tr data-id="' + safeId + '">'
                + '<td>' + imgThumb + '<div class="nw-ability-id">' + safeId + '</div><div class="nw-ability-title">' + esc(a.title || a.name) + '</div></td>'
                + '<td>' + typeH + '</td>'
                + '<td>' + costH + '</td>'
                + '<td><span class="nw-target-badge">' + esc(a.target_type || '—') + '</span></td>'
                + '<td>' + esc(a.range_tiles) + '</td>'
                + '<td>' + (a.duration_turns ? esc(a.duration_turns) : '&mdash;') + '</td>'
                + '<td>' + passiveH + '</td>'
                + '<td>' + activeH + '</td>'
                + '<td><div class="nw-row-actions">'
                + '<button class="nw-action-btn nw-edit-btn" data-id="' + safeId + '">Edit</button>'
                + '<button class="nw-action-btn nw-toggle-btn" data-id="' + safeId + '" data-active="' + (a.is_active ? '1' : '0') + '">' + (a.is_active ? 'Disable' : 'Enable') + '</button>'
                + '</div></td>'
                + '</tr>';
        }).join(''));
    }

    function applyFilters() {
        var ft = $filterType.val();
        var fa = $filterActive.val();
        var q = ($search.val() || '').toLowerCase().trim();

        filtered = all.filter(function (a) {
            if (ft && a.ability_type !== ft) return false;
            if (fa === '1' && !a.is_active) return false;
            if (fa === '0' && a.is_active) return false;

            if (q) {
                var tagMatch = (a.tags || []).some(function (t) {
                    return String(t).toLowerCase().indexOf(q) !== -1;
                });

                var idMatch = String(a.id || '').toLowerCase().indexOf(q) !== -1;
                var titleMatch = String(a.title || a.name || '').toLowerCase().indexOf(q) !== -1;

                if (!idMatch && !titleMatch && !tagMatch) return false;
            }

            return true;
        });

        renderTable(filtered);
    }

    function loadAll() {
        if (!ajaxEndpoint || !nonce) {
            notice('Missing AJAX config or nonce.', 'error');
            console.error('NWAbilities config missing:', cfg);
            return;
        }

        if (activeXhr && activeXhr.readyState !== 4) {
            activeXhr.abort();
        }

        $tbody.html('<tr class="nw-loading-row"><td colspan="9"><div class="nw-spinner"></div> Loading…</td></tr>');

        activeXhr = $.post(ajaxEndpoint, {
            action: 'nw_abilities_get_all',
            nonce: nonce
        }, function (res) {
            if (!res || !res.success) {
                notice('Error: ' + ((res && res.data) || 'Unknown error'), 'error');
                return;
            }

            var rows = Array.isArray(res.data)
                ? res.data
                : (res.data && typeof res.data === 'object' ? Object.values(res.data) : []);

            all = cloneAbilities(rows);
            filtered = all.slice();

            updateStats(all);
            applyFilters();
        }).fail(function (xhr, status) {
            if (status !== 'abort') {
                notice('Request failed (' + (xhr.status || status) + ').', 'error');
                console.error('loadAll failed:', xhr.responseText);
            }
        }).always(function () {
            activeXhr = null;
        });
    }

    function toggleAbility(id, currentlyActive) {
        var newState = !currentlyActive;

        $.post(ajaxEndpoint, {
            action: 'nw_abilities_toggle',
            nonce: nonce,
            ability_id: id,
            is_active: newState ? '1' : '0'
        }, function (res) {
            if (res && res.success) {
                notice('Ability ' + (newState ? 'enabled' : 'disabled') + '.', 'success');
                loadAll();
            } else {
                notice('Toggle failed: ' + ((res && res.data) || 'Unknown'), 'error');
            }
        }).fail(function (xhr) {
            notice('Toggle request failed.', 'error');
            console.error('toggle failed:', xhr.responseText);
        });
    }

    function confirmModal(message, onConfirm) {
        if ($('.nw-confirm-overlay').length) return;

        var overlay = $(
            '<div class="nw-confirm-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:99999;display:flex;align-items:center;justify-content:center;">' +
                '<div class="nw-confirm-box" style="background:#1a1a2e;border:1px solid #adff00;border-radius:8px;padding:32px 28px;min-width:320px;text-align:center;">' +
                    '<p style="color:#fff;margin-bottom:24px;font-family:\'Chakra Petch\',sans-serif;">' + esc(message) + '</p>' +
                    '<button class="nw-confirm-yes nw-action-btn" style="margin-right:12px;">Delete</button>' +
                    '<button class="nw-confirm-no nw-action-btn" style="background:#333;">Cancel</button>' +
                '</div>' +
            '</div>'
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
        if ($form.length && $form[0]) $form[0].reset();

        $fieldOriginalId.val('');
        $fieldId.val('');
        $fieldIsActive.prop('checked', true);
        $fieldIsPassive.prop('checked', false);
        updateImgPreview('');

        if (id) {
            var a = all.find(function (x) { return x.id === id; });
            if (!a) {
                notice('Ability data not loaded yet.', 'error');
                return;
            }

            $fieldOriginalId.val(a.id);
            $fieldId.val(a.id);
            $fieldTitle.val(a.title || a.name);
            $fieldDescription.val(a.description);
            $fieldAbilityType.val(a.ability_type);
            $fieldCostType.val(a.cost_type);
            $fieldCostValue.val(a.cost_value);
            $fieldTargetType.val(a.target_type);
            $fieldRangeTiles.val(a.range_tiles);
            $fieldDurationTurns.val(a.duration_turns);
            $fieldIsPassive.prop('checked', a.is_passive);
            $fieldIsActive.prop('checked', a.is_active);
            $fieldTags.val(tagsStr(a.tags));
            $fieldImgUrl.val(a.img_url);
            $fieldSource.val(a.source);
            $fieldGmNotes.val(a.gm_notes);
            updateImgPreview(a.img_url);

            $('#nw-modal-title').text('Edit Ability');
            $saveLabel.text('Save Changes');
            $deleteBtn.show().data('id', id);
        } else {
            $('#nw-modal-title').text('New Ability');
            $saveLabel.text('Create Ability');
            $deleteBtn.hide().data('id', '');
        }

        $modalOverlay.fadeIn(150);
    }

    $saveBtn.on('click', function (e) {
        e.preventDefault();

        var id = ($fieldId.val() || '').trim();
        var title = ($fieldTitle.val() || '').trim();

        if (!id) {
            notice('ID is required.', 'error');
            return;
        }

        if (!title) {
            notice('Title is required.', 'error');
            return;
        }

        var btn = $(this);
        var previousLabel = $saveLabel.text();

        btn.prop('disabled', true);
        $saveLabel.text('Saving…');

        var payload = {
            action: 'nw_save_ability',
            nonce: nonce,
            id: id,
            name: title,
            title: title,
            description: ($fieldDescription.val() || '').trim(),
            ability_type: $fieldAbilityType.val() || 'active',
            cost_type: $fieldCostType.val() || 'none',
            cost_value: parseInt($fieldCostValue.val(), 10) || 0,
            target_type: $fieldTargetType.val() || 'self',
            range_tiles: parseInt($fieldRangeTiles.val(), 10) || 1,
            duration_turns: parseInt($fieldDurationTurns.val(), 10) || 0,
            is_passive: $fieldIsPassive.is(':checked') ? '1' : '0',
            is_active: $fieldIsActive.is(':checked') ? '1' : '0',
            tags: JSON.stringify(tagsArr($fieldTags.val())),
            img_url: $fieldImgUrl.length ? ($fieldImgUrl.val() || '').trim() : '',
            source: $fieldSource.length ? ($fieldSource.val() || '').trim() : '',
            gm_notes: $fieldGmNotes.length ? ($fieldGmNotes.val() || '').trim() : ''
        };

        $.post(ajaxEndpoint, payload, function (res) {
            btn.prop('disabled', false);
            $saveLabel.text(previousLabel);

            if (res && res.success) {
                notice('Ability saved!', 'success');
                $modalOverlay.fadeOut(150);
                loadAll();
            } else {
                notice('Error: ' + ((res && res.data) || 'Unknown'), 'error');
                console.error('save failed:', res);
            }
        }).fail(function (xhr) {
            btn.prop('disabled', false);
            $saveLabel.text(previousLabel);
            notice('Save request failed.', 'error');
            console.error('save xhr failed:', xhr.responseText);
        });
    });

    $deleteBtn.on('click', function (e) {
        e.preventDefault();

        var id = $(this).data('id');
        if (!id) return;

        confirmModal('Delete ability "' + id + '" permanently?', function () {
            $.post(ajaxEndpoint, {
                action: 'nw_delete_ability',
                nonce: nonce,
                id: id
            }, function (res) {
                if (res && res.success) {
                    notice('Ability deleted.', 'success');
                    $modalOverlay.fadeOut(150);
                    loadAll();
                } else {
                    notice('Delete failed: ' + ((res && res.data) || 'Unknown'), 'error');
                }
            }).fail(function (xhr) {
                notice('Delete request failed.', 'error');
                console.error('delete failed:', xhr.responseText);
            });
        });
    });

    $(document).on('input change', '#nw-field-img_url', function () {
        updateImgPreview($(this).val());
    });

    $('#nw-modal-close, #nw-cancel-btn').on('click', function (e) {
        e.preventDefault();
        $modalOverlay.fadeOut(150);
    });

    $modalOverlay.on('click', function (e) {
        if ($(e.target).is('#nw-modal-overlay')) {
            $modalOverlay.fadeOut(150);
        }
    });

    $(document).on('click', '.nw-edit-btn', function (e) {
        e.preventDefault();
        openModal($(this).data('id'));
    });

    $(document).on('click', '.nw-toggle-btn', function (e) {
        e.preventDefault();
        var id = $(this).data('id');
        var active = String($(this).data('active')) === '1';
        toggleAbility(id, active);
    });

    $('#nw-add-btn').on('click', function (e) {
        e.preventDefault();
        openModal(null);
    });

    $('#nw-refresh-btn').on('click', function (e) {
        e.preventDefault();
        loadAll();
    });

    $filterType.on('change', applyFilters);
    $filterActive.on('change', applyFilters);
    $search.on('input', debounce(applyFilters, 150));

    if (!ajaxEndpoint || !nonce) {
        console.error('NWAbilities missing:', cfg);
        notice('Missing AJAX config. Check wp_localize_script.', 'error');
        return;
    }

    loadAll();
});
