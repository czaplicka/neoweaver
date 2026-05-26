/* NeoWeaver – Field Agents (Classes) Admin JS
   Handles: load, filter (search + active), create, edit, duplicate, delete
   Pattern identical to achievements.js / abilities.js
*/
jQuery(function ($) {
'use strict';

var cfg          = window.NWClasses || {};
var ajaxEndpoint = cfg.ajaxurl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
var nonce        = cfg.nonce  || '';
var uploadsUrl   = (cfg.uploads_url || '').replace(/\/+$/, '');
var noticeTimer  = null;

/* ── DOM refs ─────────────────────────────────────────── */
var $notice      = $('#nw-notice');
var $tbody       = $('#nw-classes-tbody');
var $search      = $('#nw-search');
var $filterActive = $('#nw-filter-active');
var $clearBtn    = $('#nw-clear-filters');
var $modalOverlay = $('#nw-modal-overlay');
var $form        = $('#nw-class-form');
var $saveBtn     = $('#nw-save-btn');
var $saveLabel   = $('#nw-save-label');
var $deleteBtn   = $('#nw-delete-btn');
var $fieldId     = $('#nw-field-id');

var $fieldName                 = $('#nw-field-name');
var $fieldDescription          = $('#nw-field-description');
var $fieldIconSlug             = $('#nw-field-icon_slug');
var $fieldStartingGold         = $('#nw-field-starting_gold');
var $fieldSkillLimit           = $('#nw-field-skill_limit');
var $fieldVulnerability        = $('#nw-field-vulnerability');
var $fieldTags                 = $('#nw-field-tags');
var $fieldIsActive             = $('#nw-field-is_active');
var $fieldMechanics            = $('#nw-field-mechanics');
var $fieldGmInstructions       = $('#nw-field-gm_instructions');
var $fieldAiPersonalityModifier = $('#nw-field-ai_personality_modifier');
var $fieldAttributeBonuses     = $('#nw-field-attribute_bonuses');
var $fieldImgUrl               = $('#nw-field-img_url');
var $imgPreview                = $('#nw-img-preview');
var $imgPreviewWrap            = $('#nw-img-preview-wrap');

/* ── State ────────────────────────────────────────────── */
var all       = [];
var activeXhr = null;

/* ── Helpers ──────────────────────────────────────────── */
function esc(s) { return $('<span>').text(s || '').html(); }

function clearNoticeTimer() {
    if (noticeTimer) { clearTimeout(noticeTimer); noticeTimer = null; }
}

function notice(msg, type) {
    var safeType = String(type || 'info').replace(/[^a-z-]/g, '');
    clearNoticeTimer();
    $notice.attr('class', 'nw-notice nw-notice-' + safeType).text(msg).stop(true, true).show();
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

function tagsStr(tags) {
    if (!tags) return '';
    if (Array.isArray(tags)) return tags.join(', ');
    if (typeof tags === 'string') return tags;
    return '';
}

function parseMaybeJson(value, fallback) {
    if (value == null || value === '') return fallback;
    if (typeof value === 'object') return value;
    try { return JSON.parse(value); } catch (e) { return fallback; }
}

function setIsActive(value) {
    $fieldIsActive.prop('checked', !!value);
}

function getIsActive() {
    return $fieldIsActive.is(':checked') ? '1' : '0';
}

function normalizeClasses(data) {
    var list = data;
    if (typeof list === 'string') { try { list = JSON.parse(list); } catch (e) { list = []; } }
    if (!Array.isArray(list)) { list = (list && typeof list === 'object') ? Object.values(list) : []; }

    return list.map(function (item) {
        var tags = item.tags;
        if (typeof tags === 'string') {
            try { tags = JSON.parse(tags); }
            catch (e) { tags = tags.split(',').map(function (t) { return t.trim(); }).filter(Boolean); }
        }
        return {
            id:                      item.id || '',
            name:                    item.name || '',
            description:             item.description || '',
            icon_slug:               item.icon_slug || '',
            vulnerability:           item.vulnerability || '',
            attribute_bonuses:       item.attribute_bonuses || {},
            mechanics:               item.mechanics || '',
            gm_instructions:         item.gm_instructions || '',
            ai_personality_modifier: item.ai_personality_modifier || '',
            img_url:                 item.img_url || '',
            starting_gold:           item.starting_gold != null ? item.starting_gold : '',
            skill_limit:             item.skill_limit   != null ? item.skill_limit   : '',
            is_active:               item.is_active != null ? !!item.is_active : true,
            tags:                    Array.isArray(tags) ? tags : []
        };
    });
}

function resolveImgUrl(raw) {
    if (!raw) return '';
    var value = String(raw).trim();
    if (!value) return '';
    if (/^https?:\/\//i.test(value) || /^\/\//.test(value)) return value;
    if (value.charAt(0) === '/') return value;
    if (!uploadsUrl) return '';
    return uploadsUrl + '/' + value.replace(/^\/+/, '');
}

function updateImgPreview(raw) {
    var url = resolveImgUrl(raw);
    if (url) { $imgPreview.attr('src', url); $imgPreviewWrap.show(); }
    else      { $imgPreview.attr('src', ''); $imgPreviewWrap.hide(); }
}

/* ── Stats ────────────────────────────────────────────── */
function updateStats(data) {
    var active = 0;
    (data || []).forEach(function (c) { if (c.is_active) active++; });
    $('#nw-total').text(data.length);
    $('#nw-active').text(active);
    $('#nw-inactive').text(data.length - active);
}

/* ── Filter helpers ───────────────────────────────────── */
function hasActiveFilters() {
    return $search.val().trim() !== '' || $filterActive.val() !== '';
}

function updateClearBtn() {
    $clearBtn.toggle(hasActiveFilters());
}

/* ── Table render ─────────────────────────────────────── */
function renderTable(data) {
    if (!data.length) {
        $tbody.html('<tr class="nw-loading-row"><td colspan="8">No field agents found.</td></tr>');
        return;
    }

    $tbody.html(data.map(function (c) {
        var safeId   = esc(c.id);
        var tags     = Array.isArray(c.tags) ? c.tags : [];
        var fullImg  = resolveImgUrl(c.img_url);

        var tagsH = tags.slice(0, 3).map(function (t) {
            return '<span class="nw-tag">' + esc(t) + '</span>';
        }).join('');
        if (tags.length > 3) tagsH += '<span class="nw-tag-more">+' + (tags.length - 3) + '</span>';

        var imgH = fullImg
            ? '<img src="' + esc(fullImg) + '" alt="" width="36" height="36" style="border-radius:4px;object-fit:cover;" data-fallback="1">'
            : '<span style="font-size:20px;">⚔️</span>';

        var activeH = c.is_active
            ? '<span class="nw-toggle-active">Yes</span>'
            : '<span class="nw-toggle-inactive">No</span>';

        var rowClass = c.is_active ? '' : ' class="nw-row-inactive"';

        return '<tr' + rowClass + '>'
            + '<td>' + imgH + '</td>'
            + '<td><strong>' + esc(c.name) + '</strong></td>'
            + '<td>' + tagsH + '</td>'
            + '<td class="nw-num">' + (c.starting_gold !== '' ? esc(String(c.starting_gold)) : '—') + '</td>'
            + '<td class="nw-num">' + (c.skill_limit   !== '' ? esc(String(c.skill_limit))   : '—') + '</td>'
            + '<td>' + esc(c.vulnerability || '—') + '</td>'
            + '<td>' + activeH + '</td>'
            + '<td><div class="nw-row-actions">'
            + '<button class="nw-action-btn nw-edit-btn" data-id="' + safeId + '">Edit</button>'
            + '<button class="nw-action-btn nw-dup-btn"  data-id="' + safeId + '">Dup</button>'
            + '</div></td>'
            + '</tr>';
    }).join(''));

    /* image fallbacks */
    $tbody.find('img[data-fallback]').off('error.nwFallback').on('error.nwFallback', function () {
        $(this).hide();
    });

    /* init Lucide icons if present */
    if (typeof lucide !== 'undefined') { lucide.createIcons(); }
}

/* ── Apply filters ────────────────────────────────────── */
function applyFilters() {
    var q      = $search.val().toLowerCase().trim();
    var active = $filterActive.val(); /* '' | '1' | '0' */

    var shown = all.filter(function (c) {
        /* active filter */
        if (active === '1' && !c.is_active) return false;
        if (active === '0' &&  c.is_active) return false;

        /* search */
        if (q) {
            var tagMatch = (Array.isArray(c.tags) ? c.tags : []).some(function (t) {
                return String(t).toLowerCase().indexOf(q) !== -1;
            });
            if (
                String(c.name         || '').toLowerCase().indexOf(q) === -1 &&
                String(c.description  || '').toLowerCase().indexOf(q) === -1 &&
                String(c.vulnerability|| '').toLowerCase().indexOf(q) === -1 &&
                !tagMatch
            ) return false;
        }
        return true;
    });

    renderTable(shown);
    updateClearBtn();
}

/* ── Load ─────────────────────────────────────────────── */
function loadAll() {
    if (!ajaxEndpoint) { notice('Missing AJAX endpoint.', 'error'); return; }
    if (!nonce)         { notice('Missing nonce.', 'error'); return; }
    if (activeXhr && activeXhr.readyState !== 4) activeXhr.abort();

    $tbody.html('<tr class="nw-loading-row"><td colspan="8"><span class="nw-spinner"></span> Loading…</td></tr>');

    activeXhr = $.post(ajaxEndpoint, { action: 'nw_classes_load', nonce: nonce }, function (res) {
        if (!res || !res.success) {
            notice('Error: ' + ((res && res.data) || 'Unknown error'), 'error');
            return;
        }
        var rows = Array.isArray(res.data) ? res.data
            : (res.data && typeof res.data === 'object' ? Object.values(res.data) : []);
        all = normalizeClasses(rows);
        updateStats(all);
        applyFilters();
    }).fail(function (xhr, status) {
        if (status !== 'abort') notice('Request failed (' + (xhr.status || status) + ').', 'error');
    }).always(function () { activeXhr = null; });
}

/* ── Confirm modal ────────────────────────────────────── */
function confirmModal(message, onConfirm) {
    if ($('.nw-confirm-overlay').length) return;
    var overlay = $(
        '<div class="nw-confirm-overlay nw-modal-overlay">'
        + '<div class="nw-modal" style="max-width:380px;">'
        + '<div class="nw-modal-body" style="padding:28px 24px 8px;">'
        + '<p style="color:#e0e0e0;margin:0 0 20px;">' + esc(message) + '</p>'
        + '</div>'
        + '<div class="nw-modal-footer">'
        + '<button class="nw-btn nw-btn-danger nw-confirm-yes">Delete</button>'
        + '<button class="nw-btn nw-btn-ghost nw-confirm-no">Cancel</button>'
        + '</div></div></div>'
    );
    $('body').append(overlay);
    overlay.find('.nw-confirm-yes').on('click', function () { overlay.remove(); onConfirm(); });
    overlay.find('.nw-confirm-no').on('click', function () { overlay.remove(); });
    overlay.on('click', function (e) { if ($(e.target).is(overlay)) overlay.remove(); });
}

/* ── Open modal ───────────────────────────────────────── */
function openModal(id) {
    if ($form.length && $form[0]) $form[0].reset();

    $fieldId.val('');
    updateImgPreview('');
    setIsActive(true);

    if (id) {
        var c = all.find(function (x) { return x.id === id; });
        if (!c) { notice('Class data not loaded.', 'error'); return; }

        $fieldId.val(c.id);
        $fieldName.val(c.name || '');
        $fieldDescription.val(c.description || '');
        $fieldIconSlug.val(c.icon_slug || '');
        $fieldStartingGold.val(c.starting_gold !== '' ? c.starting_gold : 100);
        $fieldSkillLimit.val(c.skill_limit !== '' ? c.skill_limit : 3);
        $fieldVulnerability.val(c.vulnerability || '');
        $fieldTags.val(tagsStr(c.tags));
        setIsActive(!!c.is_active);
        $fieldMechanics.val(c.mechanics || '');
        $fieldGmInstructions.val(c.gm_instructions || '');
        $fieldAiPersonalityModifier.val(c.ai_personality_modifier || '');
        $fieldAttributeBonuses.val(
            c.attribute_bonuses && typeof c.attribute_bonuses === 'object'
                ? JSON.stringify(c.attribute_bonuses, null, 2)
                : ''
        );
        $fieldImgUrl.val(c.img_url || '');
        updateImgPreview(c.img_url || '');

        $('#nw-modal-title').text('Edit Class');
        $saveLabel.text('Save Changes');
        $deleteBtn.show().data('id', id);
    } else {
        $fieldStartingGold.val(100);
        $fieldSkillLimit.val(3);
        setIsActive(true);
        $('#nw-modal-title').text('New Class');
        $saveLabel.text('Create Class');
        $deleteBtn.hide().removeData('id');
    }

    $modalOverlay.fadeIn(150);
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

/* ── Events ───────────────────────────────────────────── */
$fieldImgUrl.on('input change', function () { updateImgPreview($(this).val().trim()); });

$('#nw-modal-close, #nw-cancel-btn').on('click', function () { $modalOverlay.fadeOut(150); });
$modalOverlay.on('click', function (e) {
    if ($(e.target).is('#nw-modal-overlay')) $modalOverlay.fadeOut(150);
});

$(document).on('click', '.nw-edit-btn', function () { openModal($(this).data('id')); });
$('#nw-add-btn').on('click', function () { openModal(null); });
$('#nw-refresh-btn').on('click', loadAll);

$search.on('input', debounce(applyFilters, 150));
$filterActive.on('change', applyFilters);
$clearBtn.on('click', function () {
    $search.val('');
    $filterActive.val('');
    applyFilters();
});

/* ── Save ─────────────────────────────────────────────── */
$saveBtn.on('click', function () {
    var name = $fieldName.val().trim();
    if (!name) { notice('Name is required.', 'error'); return; }

    var attrRaw = $fieldAttributeBonuses.val().trim();
    if (attrRaw) {
        var parsed = parseMaybeJson(attrRaw, null);
        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
            notice('Attribute Bonuses must be a valid JSON object.', 'error');
            return;
        }
    }

    var btn = $(this), prevLabel = $saveLabel.text();
    btn.prop('disabled', true);
    $saveLabel.text('Saving…');

    var payload = {
        action:                   'nw_classes_save',
        nonce:                    nonce,
        id:                       $fieldId.val().trim(),
        name:                     name,
        description:              $fieldDescription.val().trim(),
        icon_slug:                $fieldIconSlug.val().trim(),
        starting_gold:            $fieldStartingGold.val(),
        skill_limit:              $fieldSkillLimit.val(),
        vulnerability:            $fieldVulnerability.val().trim(),
        tags:                     $fieldTags.val().trim(),
        is_active:                getIsActive(),
        mechanics:                $fieldMechanics.val().trim(),
        gm_instructions:          $fieldGmInstructions.val().trim(),
        ai_personality_modifier:  $fieldAiPersonalityModifier.val().trim(),
        attribute_bonuses:        attrRaw,
        img_url:                  $fieldImgUrl.val().trim()
    };

    $.post(ajaxEndpoint, payload, function (res) {
        btn.prop('disabled', false);
        $saveLabel.text(prevLabel);
        if (res && res.success) {
            notice('Class saved!', 'success');
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

/* ── Delete ───────────────────────────────────────────── */
$deleteBtn.on('click', function () {
    var id = $(this).data('id');
    if (!id) return;
    confirmModal('Delete this class permanently?', function () {
        $.post(ajaxEndpoint, { action: 'nw_classes_delete', nonce: nonce, id: id }, function (res) {
            if (res && res.success) {
                notice('Class deleted.', 'success');
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

/* ── Duplicate ────────────────────────────────────────── */
$(document).on('click', '.nw-dup-btn', function () {
    var id = $(this).data('id');
    if (!id) return;
    var btn = $(this);
    btn.prop('disabled', true).text('…');

    $.post(ajaxEndpoint, { action: 'nw_classes_duplicate', nonce: nonce, id: id }, function (res) {
        btn.prop('disabled', false).text('Dup');
        if (res && res.success) {
            notice('Class duplicated (inactive).', 'success');
            loadAll();
        } else {
            notice('Duplicate failed: ' + ((res && res.data) || 'Unknown'), 'error');
        }
    }).fail(function (xhr) {
        btn.prop('disabled', false).text('Dup');
        notice('Duplicate request failed (' + (xhr.status || 'network') + ').', 'error');
    });
});

/* ── Init ─────────────────────────────────────────────── */
loadAll();
if (typeof lucide !== 'undefined') lucide.createIcons();

});
