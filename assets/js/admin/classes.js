jQuery(function ($) {
    'use strict';

    var cfg          = window.NWClasses || {};
    var ajaxEndpoint = cfg.ajaxurl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
    var nonce        = cfg.nonce  || '';
    var uploadsUrl   = (cfg.uploads_url || '').replace(/\/+$/, '');

    /* ── DOM refs ─────────────────────────────────────────── */
    var $notice       = $('#nw-notice');
    var $tbody        = $('#nw-classes-tbody');
    var $search       = $('#nw-search');
    var $modalOverlay = $('#nw-modal-overlay');
    var $form         = $('#nw-class-form');
    var $saveBtn      = $('#nw-save-btn');
    var $saveLabel    = $('#nw-save-label');
    var $deleteBtn    = $('#nw-delete-btn');
    var $fieldId      = $('#nw-field-id');

    /* img preview */
    var $fieldImgUrl     = $('#nw-field-img_url');
    var $imgPreview      = $('#nw-img-preview');
    var $imgPreviewWrap  = $('#nw-img-preview-wrap');

    /* state */
    var all      = [];
    var activeXhr = null;

    /* ── Helpers ──────────────────────────────────────────── */
    function esc(s) {
        return $('<span>').text(s || '').html();
    }

    /**
     * Resolves img_url stored in Supabase to a full URL.
     * - Already a full URL (http/https)? → return as-is.
     * - Just a filename (e.g. "psychic.svg")? → prepend uploads_url.
     */
    function resolveImgUrl(raw) {
        if (!raw) return '';
        raw = raw.trim();
        if (/^https?:\/\//i.test(raw)) return raw;
        return uploadsUrl + '/' + raw;
    }

    function notice(msg, type) {
        var safeType = String(type || 'info').replace(/[^a-z-]/g, '');
        $notice
            .attr('class', 'nw-notice nw-notice-' + safeType)
            .text(msg)
            .show();
        setTimeout(function () { $notice.fadeOut(300); }, 3500);
    }

    function tagsStr(t) {
        if (!t) return '';
        if (Array.isArray(t)) return t.join(', ');
        return String(t);
    }

    function debounce(fn, delay) {
        var timer;
        return function () {
            var args = arguments, ctx = this;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(ctx, args); }, delay);
        };
    }

    function cloneClasses(data) {
        var list = data;
        if (typeof list === 'string') {
            try { list = JSON.parse(list); } catch (e) { list = []; }
        }
        if (!Array.isArray(list)) {
            list = (list && typeof list === 'object') ? Object.values(list) : [];
        }
        return list.map(function (item) {
            return {
                id:                       item.id                       || '',
                name:                     item.name                     || '',
                description:              item.description              || '',
                icon_slug:                item.icon_slug                || '',
                vulnerability:            item.vulnerability            || '',
                attribute_bonuses:        item.attribute_bonuses        || null,
                mechanics:                item.mechanics                || '',
                gm_instructions:          item.gm_instructions          || '',
                ai_personality_modifier:  item.ai_personality_modifier  || '',
                img_url:                  item.img_url                  || '',
                starting_gold:            item.starting_gold            != null ? item.starting_gold : '',
                skill_limit:              item.skill_limit              != null ? item.skill_limit   : '',
                is_active:                item.is_active != null ? item.is_active : true,
                tags:                     Array.isArray(item.tags) ? item.tags.slice() : []
            };
        });
    }

    /* ── Stats bar ────────────────────────────────────────── */
    function updateStats(data) {
        var active = 0;
        (data || []).forEach(function (c) { if (c.is_active) active++; });
        $('#nw-total').text(data.length);
        $('#nw-active').text(active);
    }

    /* ── Image fallbacks ──────────────────────────────────── */
    function bindImageFallbacks() {
        $tbody.find('img[data-fallback]')
            .off('error.nwFallback')
            .on('error.nwFallback', function () { $(this).hide(); });
    }

    /* ── Table render ─────────────────────────────────────── */
    function renderTable(data) {
        if (!data.length) {
            $tbody.html('<tr><td colspan="8" style="text-align:center;padding:32px;color:#555;">No classes found.</td></tr>');
            return;
        }

        $tbody.html(data.map(function (c) {
            var safeId  = esc(c.id);
            var tags    = Array.isArray(c.tags) ? c.tags : [];
            var fullImg = resolveImgUrl(c.img_url);

            var tagsH = tags.slice(0, 3).map(function (t) {
                return '<span class="nw-tag">' + esc(t) + '</span>';
            }).join('') + (tags.length > 3 ? '<span class="nw-tag">+' + (tags.length - 3) + '</span>' : '');

            var imgH = fullImg
                ? '<img src="' + esc(fullImg) + '" class="nw-class-img" loading="lazy" data-fallback="1" alt="">'
                : '<div class="nw-class-img-placeholder">⚔️</div>';

            var activeH = c.is_active
                ? '<span class="nw-active-badge is-active">Yes</span>'
                : '<span class="nw-active-badge is-inactive">No</span>';

            return '<tr data-id="' + safeId + '">'
                + '<td>' + imgH + '</td>'
                + '<td><div class="nw-class-name">' + esc(c.name) + '</div>'
                +     '<div class="nw-class-desc">' + esc(c.description || '') + '</div></td>'
                + '<td><div class="nw-tags">' + tagsH + '</div></td>'
                + '<td><span class="nw-gold-value">' + (c.starting_gold !== '' ? esc(String(c.starting_gold)) : '—') + '</span></td>'
                + '<td>' + (c.skill_limit !== '' ? esc(String(c.skill_limit)) : '—') + '</td>'
                + '<td><span class="nw-vuln-value">' + esc(c.vulnerability || '—') + '</span></td>'
                + '<td>' + activeH + '</td>'
                + '<td><div class="nw-row-actions"><button class="nw-action-btn nw-edit-btn" data-id="' + safeId + '">Edit</button></div></td>'
                + '</tr>';
        }).join(''));

        bindImageFallbacks();
    }

    /* ── Search ───────────────────────────────────────────── */
    function applySearch() {
        var q = $search.val().toLowerCase().trim();
        var shown = q ? all.filter(function (c) {
            var tagMatch = (Array.isArray(c.tags) ? c.tags : []).some(function (t) {
                return String(t).toLowerCase().includes(q);
            });
            return String(c.name || '').toLowerCase().includes(q) || tagMatch
                || String(c.vulnerability || '').toLowerCase().includes(q);
        }) : all;
        renderTable(shown);
    }

    /* ── Load all ─────────────────────────────────────────── */
    function loadAll() {
        if (!ajaxEndpoint) { notice('Missing AJAX endpoint.', 'error'); return; }
        if (activeXhr && activeXhr.readyState !== 4) activeXhr.abort();

        $tbody.html('<tr class="nw-loading-row"><td colspan="8"><div class="nw-spinner"></div> Loading classes…</td></tr>');

        activeXhr = $.post(ajaxEndpoint, {
            action: 'nw_classes_get_all',
            nonce:  nonce
        }, function (res) {
            if (!res || !res.success) {
                notice('Error: ' + ((res && res.data) || 'Unknown error'), 'error');
                return;
            }
            var rows = Array.isArray(res.data)
                ? res.data
                : (res.data && typeof res.data === 'object' ? Object.values(res.data) : []);

            all = cloneClasses(rows);
            updateStats(all);
            applySearch();
        }).fail(function (xhr, status) {
            if (status !== 'abort') notice('Request failed.', 'error');
        }).always(function () { activeXhr = null; });
    }

    /* ── Confirm modal ────────────────────────────────────── */
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
        overlay.find('.nw-confirm-yes').on('click', function () { overlay.remove(); onConfirm(); });
        overlay.find('.nw-confirm-no').on('click', function () { overlay.remove(); });
        overlay.on('click', function (e) { if ($(e.target).is(overlay)) overlay.remove(); });
    }

    /* ── Open modal ───────────────────────────────────────── */
    function openModal(id) {
        $form[0].reset();
        $fieldId.val('');
        $imgPreviewWrap.hide();

        if (id) {
            var c = all.find(function (x) { return x.id === id; });
            if (!c) { notice('Class data not loaded yet.', 'error'); return; }

            $fieldId.val(c.id);
            $('#nw-field-name').val(c.name || '');
            $('#nw-field-description').val(c.description || '');
            $('#nw-field-icon_slug').val(c.icon_slug || '');
            $('#nw-field-starting_gold').val(c.starting_gold !== '' ? c.starting_gold : '');
            $('#nw-field-skill_limit').val(c.skill_limit !== '' ? c.skill_limit : '');
            $('#nw-field-vulnerability').val(c.vulnerability || '');
            $('#nw-field-tags').val(tagsStr(c.tags));
            $('#nw-field-is_active').val(c.is_active ? '1' : '0');
            $('#nw-field-mechanics').val(c.mechanics || '');
            $('#nw-field-gm_instructions').val(c.gm_instructions || '');
            $('#nw-field-ai_personality_modifier').val(c.ai_personality_modifier || '');
            $('#nw-field-attribute_bonuses').val(
                c.attribute_bonuses ? JSON.stringify(c.attribute_bonuses) : ''
            );

            // img_url: store raw value from DB, show resolved preview
            if (c.img_url) {
                $fieldImgUrl.val(c.img_url);
                $imgPreview.attr('src', resolveImgUrl(c.img_url));
                $imgPreviewWrap.show();
            }

            $('#nw-modal-title').text('Edit Class');
            $saveLabel.text('Save Changes');
            $deleteBtn.show().data('id', id);
        } else {
            $('#nw-modal-title').text('New Class');
            $saveLabel.text('Create Class');
            $deleteBtn.hide();
        }

        $modalOverlay.fadeIn(150);
    }

    /* ── Events ───────────────────────────────────────────── */
    $fieldImgUrl.on('input', function () {
        var v = $(this).val().trim();
        if (v) { $imgPreview.attr('src', resolveImgUrl(v)); $imgPreviewWrap.show(); }
        else   { $imgPreviewWrap.hide(); }
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

    $('#nw-add-btn').on('click', function () { openModal(null); });
    $('#nw-refresh-btn').on('click', loadAll);
    $search.on('input', debounce(applySearch, 150));

    /* ── Save ─────────────────────────────────────────────── */
    $saveBtn.on('click', function () {
        if (!$('#nw-field-name').val().trim()) {
            notice('Name is required.', 'error');
            return;
        }

        var btn = $(this);
        var previousLabel = $saveLabel.text();
        btn.prop('disabled', true);
        $saveLabel.text('Saving…');

        var fd = { action: 'nw_classes_save', nonce: nonce };
        $form.serializeArray().forEach(function (f) { fd['nw_class[' + f.name + ']'] = f.value; });

        $.post(ajaxEndpoint, fd, function (res) {
            btn.prop('disabled', false);
            $saveLabel.text(previousLabel);
            if (res.success) {
                notice('Class saved!', 'success');
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

    /* ── Delete ───────────────────────────────────────────── */
    $deleteBtn.on('click', function () {
        var id = $(this).data('id');
        if (!id) return;
        confirmModal('Delete this class permanently?', function () {
            $.post(ajaxEndpoint, {
                action:   'nw_classes_delete',
                nonce:    nonce,
                class_id: id
            }, function (res) {
                if (res.success) {
                    notice('Class deleted.', 'success');
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

    /* ── Init ─────────────────────────────────────────────── */
    loadAll();
});
