jQuery(function ($) {
    'use strict';

    var cfg = window.NWAbilities || {};
    var ajaxEndpoint = cfg.ajaxurl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
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
    var $fieldId = $('#nw-field-id');
    var $fieldName = $('#nw-field-name');
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
    var noticeTimer = null;

    var ABILITY_TYPES = ['active', 'passive', 'reaction', 'aura'];

    var typeClass = {
        active: 'nw-type-active',
        passive: 'nw-type-passive',
        reaction: 'nw-type-reaction',
        aura: 'nw-type-aura'
    };

    function esc(value) {
        return $('<span>').text(value == null ? '' : String(value)).html();
    }

    function clearNoticeTimer() {
        if (noticeTimer) {
            clearTimeout(noticeTimer);
            noticeTimer = null;
        }
    }

    function notice(message, type) {
        var safeType = String(type || 'info').replace(/[^a-z-]/g, '');

        clearNoticeTimer();

        $notice
            .stop(true, true)
            .removeClass()
            .addClass('nw-notice nw-notice-' + safeType)
            .text(message || '')
            .fadeIn(120);

        noticeTimer = setTimeout(function () {
            $notice.fadeOut(220);
            noticeTimer = null;
        }, 3500);
    }

    function debounce(fn, delay) {
        var timer = null;
        return function () {
            var ctx = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(ctx, args);
            }, delay || 150);
        };
    }

    function tagsStr(tags) {
        if (!tags) return '';
        if (Array.isArray(tags)) return tags.join(', ');
        if (typeof tags === 'string') return tags;
        return '';
    }

    function parseTags(raw) {
        if (!raw) return [];
        if (Array.isArray(raw)) {
            return raw.map(function (t) { return String(t).trim(); }).filter(Boolean);
        }
        if (typeof raw === 'string') {
            var trimmed = raw.trim();
            if (!trimmed) return [];
            try {
                var parsed = JSON.parse(trimmed);
                if (Array.isArray(parsed)) {
                    return parsed.map(function (t) { return String(t).trim(); }).filter(Boolean);
                }
            } catch (e) {}
            return trimmed.split(',').map(function (t) { return t.trim(); }).filter(Boolean);
        }
        return [];
    }

    function updateImgPreview(url) {
        var value = String(url || '').trim();
        if (!$imgPreviewWrap.length || !$imgPreviewImg.length) return;

        if (value) {
            $imgPreviewImg.attr('src', value);
            $imgPreviewWrap.show();
        } else {
            $imgPreviewImg.attr('src', '');
            $imgPreviewWrap.hide();
        }
    }

    function normalise(item) {
        item = item || {};
        return {
            id: item.id || '',
            name: item.name || '',
            title: item.title || '',
            description: item.description || '',
            ability_type: item.ability_type || 'active',
            cost_type: item.cost_type || 'none',
            cost_value: item.cost_value != null ? parseInt(item.cost_value, 10) || 0 : 0,
            target_type: item.target_type || 'self',
            range_tiles: item.range_tiles != null ? parseInt(item.range_tiles, 10) || 0 : 0,
            duration_turns: item.duration_turns != null ? parseInt(item.duration_turns, 10) || 0 : 0,
            is_passive: item.is_passive === true || item.is_passive === 1 || item.is_passive === '1',
            is_active: item.is_active === true || item.is_active === 1 || item.is_active === '1',
            tags: parseTags(item.tags),
            img_url: item.img_url || '',
            source: item.source || '',
            gm_notes: item.gm_notes || ''
        };
    }

    function updateStats(data) {
        var counts = {
            active: 0,
            passive: 0,
            reaction: 0,
            aura: 0
        };
        var activeCount = 0;
        var inactiveCount = 0;

        (data || []).forEach(function (a) {
            if (counts.hasOwnProperty(a.ability_type)) {
                counts[a.ability_type] += 1;
            }
            if (a.is_active) {
                activeCount += 1;
            } else {
                inactiveCount += 1;
            }
        });

        $('#nw-total').text((data || []).length);
        $('#nw-active').text(activeCount);
        $('#nw-inactive').text(inactiveCount);

        ABILITY_TYPES.forEach(function (type) {
            $('#nw-count-' + type).text(counts[type] || 0);
        });
    }

    function renderTable(data) {
        if (!data || !data.length) {
            $tbody.html('<tr><td colspan="9" style="text-align:center;padding:32px;color:#555;">No abilities found.</td></tr>');
            return;
        }

        var html = data.map(function (a) {
            var safeUuid = esc(a.id);
            var safeName = esc(a.name || '—');
            var safeTitle = esc(a.title || 'Untitled');
            var tc = typeClass[a.ability_type] || 'nw-type-active';

            var imgThumb = a.img_url
                ? '<img src="' + esc(a.img_url) + '" alt="" width="32" height="32" style="object-fit:cover;border-radius:4px;vertical-align:middle;margin-right:6px;">'
                : '';

            var costHtml = a.cost_type && a.cost_type !== 'none'
                ? '<span class="nw-cost-badge">' + esc(a.cost_value) + ' ' + esc(a.cost_type) + '</span>'
                : '<span style="color:#666;">—</span>';

            var passiveHtml = a.is_passive
                ? '<span class="nw-pill nw-pill-yes">Yes</span>'
                : '<span class="nw-pill nw-pill-no">No</span>';

            var activeHtml = a.is_active
                ? '<span class="nw-pill nw-pill-yes">Active</span>'
                : '<span class="nw-pill nw-pill-no">Inactive</span>';

            return ''
                + '<tr data-id="' + safeUuid + '">'
                +   '<td>'
                +       imgThumb
                +       '<div class="nw-ability-id">' + safeName + '</div>'
                +       '<div class="nw-ability-title">' + safeTitle + '</div>'
                +       '<div class="nw-ability-uuid" style="font-size:11px;opacity:.7;margin-top:4px;">' + safeUuid + '</div>'
                +   '</td>'
                +   '<td><span class="nw-type-badge ' + tc + '">' + esc(a.ability_type) + '</span></td>'
                +   '<td>' + costHtml + '</td>'
                +   '<td><span class="nw-target-badge">' + esc(a.target_type || '—') + '</span></td>'
                +   '<td>' + esc(a.range_tiles) + '</td>'
                +   '<td>' + (a.duration_turns ? esc(a.duration_turns) : '—') + '</td>'
                +   '<td>' + passiveHtml + '</td>'
                +   '<td>' + activeHtml + '</td>'
                +   '<td>'
                +       '<div class="nw-row-actions">'
                +           '<button type="button" class="nw-action-btn nw-edit-btn" data-id="' + safeUuid + '">Edit</button>'
                +           '<button type="button" class="nw-action-btn nw-toggle-btn" data-id="' + safeUuid + '" data-active="' + (a.is_active ? '1' : '0') + '">'
                +               (a.is_active ? 'Disable' : 'Enable')
                +           '</button>'
                +       '</div>'
                +   '</td>'
                + '</tr>';
        }).join('');

        $tbody.html(html);
    }

    function applyFilters() {
        var ft = ($filterType.val() || '').trim();
        var fa = ($filterActive.val() || '').trim();
        var q = ($search.val() || '').toLowerCase().trim();

        filtered = all.filter(function (a) {
            if (ft && a.ability_type !== ft) return false;
            if (fa === '1' && !a.is_active) return false;
            if (fa === '0' && a.is_active) return false;

            if (q) {
                var haystack = [
                    a.id,
                    a.name,
                    a.title,
                    a.description,
                    a.source
                ].join(' ').toLowerCase();

                var tagMatch = (a.tags || []).some(function (tag) {
                    return String(tag).toLowerCase().indexOf(q) !== -1;
                });

                if (haystack.indexOf(q) === -1 && !tagMatch) {
                    return false;
                }
            }

            return true;
        });

        renderTable(filtered);
    }

    var debouncedApplyFilters = debounce(applyFilters, 150);

    function loadAll() {
        if (!ajaxEndpoint) {
            notice('Missing AJAX endpoint (NWAbilities not loaded).', 'error');
            return;
        }

        if (!nonce) {
            notice('Missing nonce (NWAbilities.nonce).', 'error');
            return;
        }

        if (activeXhr && activeXhr.readyState !== 4) {
            activeXhr.abort();
        }

        $tbody.html('<tr><td colspan="9" style="text-align:center;padding:32px;color:#555;"><div class="nw-spinner"></div> Loading…</td></tr>');

        activeXhr = $.post(ajaxEndpoint, {
            action: 'nw_abilities_get_all',
            nonce: nonce
        })
        .done(function (res) {
            if (!res || !res.success) {
                notice('Load failed: ' + ((res && res.data) || 'Unknown error'), 'error');
                return;
            }

            var rows = Array.isArray(res.data) ? res.data : [];
            all = rows.map(normalise);
            updateStats(all);
            applyFilters();
        })
        .fail(function (xhr, status) {
            if (status !== 'abort') {
                notice('Request failed (' + (xhr.status || status) + ').', 'error');
            }
        })
        .always(function () {
            activeXhr = null;
        });
    }

    function resetForm() {
        if ($form.length && $form[0]) {
            $form[0].reset();
        }

        $fieldId.val('');
        $fieldName.val('');
        $fieldTitle.val('');
        $fieldDescription.val('');
        $fieldAbilityType.val('active');
        $fieldCostType.val('none');
        $fieldCostValue.val('0');
        $fieldTargetType.val('self');
        $fieldRangeTiles.val('1');
        $fieldDurationTurns.val('0');
        $fieldIsPassive.prop('checked', false);
        $fieldIsActive.prop('checked', true);
        $fieldTags.val('');
        $fieldImgUrl.val('');
        $fieldSource.val('');
        $fieldGmNotes.val('');
        $deleteBtn.hide().data('id', '');
        updateImgPreview('');
    }

    function openModal(id) {
        resetForm();

        if (id) {
            var ability = all.find(function (x) {
                return x.id === id;
            });

            if (!ability) {
                notice('Ability data not found.', 'error');
                return;
            }

            $fieldId.val(ability.id);
            $fieldName.val(ability.name);
            $fieldTitle.val(ability.title);
            $fieldDescription.val(ability.description);
            $fieldAbilityType.val(ability.ability_type);
            $fieldCostType.val(ability.cost_type);
            $fieldCostValue.val(ability.cost_value);
            $fieldTargetType.val(ability.target_type);
            $fieldRangeTiles.val(ability.range_tiles);
            $fieldDurationTurns.val(ability.duration_turns);
            $fieldIsPassive.prop('checked', !!ability.is_passive);
            $fieldIsActive.prop('checked', !!ability.is_active);
            $fieldTags.val(tagsStr(ability.tags));
            $fieldImgUrl.val(ability.img_url || '');
            $fieldSource.val(ability.source || '');
            $fieldGmNotes.val(ability.gm_notes || '');
            $deleteBtn.show().data('id', ability.id);
            updateImgPreview(ability.img_url || '');

            $('#nw-modal-title').text('Edit Ability');
            $saveLabel.text('Save Changes');
        } else {
            $('#nw-modal-title').text('New Ability');
            $saveLabel.text('Create Ability');
        }

        $modalOverlay.fadeIn(150);
    }

    function closeModal() {
        $modalOverlay.fadeOut(150);
    }

    function toggleAbility(id, currentlyActive) {
        if (!id) return;

        $.post(ajaxEndpoint, {
            action: 'nw_abilities_toggle',
            nonce: nonce,
            ability_id: id,
            is_active: currentlyActive ? '0' : '1'
        })
        .done(function (res) {
            if (res && res.success) {
                notice('Ability status updated.', 'success');
                loadAll();
            } else {
                notice('Toggle failed: ' + ((res && res.data) || 'Unknown error'), 'error');
            }
        })
        .fail(function (xhr) {
            notice('Toggle request failed (' + (xhr.status || 0) + ').', 'error');
        });
    }

    function deleteAbility(id) {
        if (!id) return;

        $.post(ajaxEndpoint, {
            action: 'nw_delete_ability',
            nonce: nonce,
            id: id
        })
        .done(function (res) {
            if (res && res.success) {
                notice('Ability deleted.', 'success');
                closeModal();
                loadAll();
            } else {
                notice('Delete failed: ' + ((res && res.data) || 'Unknown error'), 'error');
            }
        })
        .fail(function (xhr) {
            notice('Delete request failed (' + (xhr.status || 0) + ').', 'error');
        });
    }

    $saveBtn.on('click', function () {
        var uuid = String($fieldId.val() || '').trim();
        var name = String($fieldName.val() || '').trim();
        var title = String($fieldTitle.val() || '').trim();

        if (!name) {
            notice('Name / slug is required.', 'error');
            return;
        }

        if (!title) {
            notice('Title is required.', 'error');
            return;
        }

        var previousLabel = $saveLabel.text();
        $saveBtn.prop('disabled', true);
        $saveLabel.text('Saving…');

        $.post(ajaxEndpoint, {
            action: 'nw_save_ability',
            nonce: nonce,
            id: uuid,
            name: name,
            title: title,
            description: String($fieldDescription.val() || '').trim(),
            ability_type: String($fieldAbilityType.val() || 'active'),
            cost_type: String($fieldCostType.val() || 'none'),
            cost_value: String($fieldCostValue.val() || '0'),
            target_type: String($fieldTargetType.val() || 'self'),
            range_tiles: String($fieldRangeTiles.val() || '0'),
            duration_turns: String($fieldDurationTurns.val() || '0'),
            is_passive: $fieldIsPassive.is(':checked') ? '1' : '0',
            is_active: $fieldIsActive.is(':checked') ? '1' : '0',
            tags: String($fieldTags.val() || '').trim(),
            img_url: String($fieldImgUrl.val() || '').trim(),
            source: String($fieldSource.val() || '').trim(),
            gm_notes: String($fieldGmNotes.val() || '').trim()
        })
        .done(function (res) {
            if (res && res.success) {
                notice(uuid ? 'Ability updated.' : 'Ability created.', 'success');
                closeModal();
                loadAll();
            } else {
                notice('Save failed: ' + ((res && res.data) || 'Unknown error'), 'error');
            }
        })
        .fail(function (xhr) {
            notice('Save request failed (' + (xhr.status || 0) + ').', 'error');
        })
        .always(function () {
            $saveBtn.prop('disabled', false);
            $saveLabel.text(previousLabel);
        });
    });

    $deleteBtn.on('click', function () {
        var id = $(this).data('id');
        if (!id) return;

        var ok = window.confirm('Delete this ability permanently?');
        if (!ok) return;

        deleteAbility(id);
    });

    $('#nw-modal-close, #nw-cancel-btn').on('click', function () {
        closeModal();
    });

    $modalOverlay.on('click', function (e) {
        if ($(e.target).is('#nw-modal-overlay')) {
            closeModal();
        }
    });

    $(document).on('click', '.nw-edit-btn', function () {
        openModal($(this).data('id'));
    });

    $(document).on('click', '.nw-toggle-btn', function () {
        var id = $(this).data('id');
        var active = String($(this).data('active')) === '1';
        toggleAbility(id, active);
    });

    $('#nw-add-btn').on('click', function () {
        openModal(null);
    });

    $('#nw-refresh-btn').on('click', function () {
        loadAll();
    });

    $filterType.on('change', debouncedApplyFilters);
    $filterActive.on('change', debouncedApplyFilters);
    $search.on('input', debouncedApplyFilters);

    $(document).on('input change', '#nw-field-img_url', function () {
        updateImgPreview($(this).val());
    });

    loadAll();
});
