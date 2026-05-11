/* global ajaxurl, NWDeck */
(function ($) {
    'use strict';

    var cfg = window.NWDeck || {};
    var ajax = cfg.ajaxurl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
    var nonce = cfg.nonce || '';

    var cards = [];
    var currentId = 0;
    var activeXhr = null;

    function notice(msg, type) {
        var $msg = $('#nw-deck-msg');
        var color = (type === 'error') ? '#b42318' : '#067647';
        $msg.stop(true, true).css('color', color).text(msg).show();

        if (type !== 'error') {
            setTimeout(function () {
                $msg.fadeOut(250);
            }, 2500);
        }
    }

    function esc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    function tagsToString(val) {
        if (!val) {
            return '';
        }
        if (Array.isArray(val)) {
            return val.join(', ');
        }
        if (typeof val === 'string') {
            return val;
        }
        return '';
    }

    function boolVal(v) {
        return v === true || v === 1 || v === '1' || v === 'true';
    }

    function rarityBadge(rarity) {
        return '<span class="nw-rarity nw-rarity-' + esc(rarity || 'common') + '">' + esc(rarity || 'common') + '</span>';
    }

    function openModal() {
        $('#nw-deck-modal').show();
    }

    function closeModal() {
        $('#nw-deck-modal').hide();
        $('#nw-deck-msg').hide().text('');
        currentId = 0;
    }

    function resetForm() {
        currentId = 0;

        $('#nw-deck-id').val('');
        $('#nw-deck-name').val('');
        $('#nw-deck-category').val('action');
        $('#nw-deck-type').val('');
        $('#nw-deck-rarity').val('common');
        $('#nw-deck-description').val('');
        $('#nw-deck-effect').val('');
        $('#nw-deck-mechanic').val('');
        $('#nw-deck-mechanic-goal').val('');
        $('#nw-deck-cost-label').val('');
        $('#nw-deck-cost-number').val(0);
        $('#nw-deck-time-cost-minutes').val(0);
        $('#nw-deck-cooldown-messages').val(0);
        $('#nw-deck-entropy-on-fail').val(0);
        $('#nw-deck-level').val(1);
        $('#nw-deck-xp-current').val(0);
        $('#nw-deck-xp-to-next').val(10);
        $('#nw-deck-bonus').val('');
        $('#nw-deck-tags').val('');
        $('#nw-deck-requirement-tags').val('');
        $('#nw-deck-denied-tags').val('');
        $('#nw-deck-required-item-tags').val('');
        $('#nw-deck-required-location-tags').val('');
        $('#nw-deck-denied-location-tags').val('');
        $('#nw-deck-requirement-description').val('');
        $('#nw-deck-ai-instruction').val('');
        $('#nw-deck-gm').val('');
        $('#nw-deck-sound-effect').val('');
        $('#nw-deck-img-url').val('');
        $('#nw-deck-class-id').val('');
        $('#nw-deck-is-leveling').prop('checked', true);
        $('#nw-deck-is-disposable').prop('checked', false);
        $('#nw-deck-is-active').prop('checked', true);

        $('#nw-deck-modal-title').text('Add Card');
        $('#nw-deck-delete-btn').hide();
        $('#nw-deck-msg').hide().text('');
    }

    function fillForm(card) {
        currentId = parseInt(card.id, 10) || 0;

        $('#nw-deck-id').val(currentId);
        $('#nw-deck-name').val(card.name || '');
        $('#nw-deck-category').val(card.deck_category || 'action');
        $('#nw-deck-type').val(card.type || '');
        $('#nw-deck-rarity').val(card.rarity || 'common');
        $('#nw-deck-description').val(card.description || '');
        $('#nw-deck-effect').val(card.effect || '');
        $('#nw-deck-mechanic').val(card.mechanic || '');
        $('#nw-deck-mechanic-goal').val(card.mechanic_goal || '');
        $('#nw-deck-cost-label').val(card.cost_label || '');
        $('#nw-deck-cost-number').val(card.cost_number || 0);
        $('#nw-deck-time-cost-minutes').val(card.time_cost_minutes || 0);
        $('#nw-deck-cooldown-messages').val(card.cooldown_messages || 0);
        $('#nw-deck-entropy-on-fail').val(card.entropy_on_fail || 0);
        $('#nw-deck-level').val(card.level || 1);
        $('#nw-deck-xp-current').val(card.xp_current || 0);
        $('#nw-deck-xp-to-next').val(card.xp_to_next || 10);
        $('#nw-deck-bonus').val(card.bonus && Object.keys(card.bonus).length ? JSON.stringify(card.bonus) : '');
        $('#nw-deck-tags').val(tagsToString(card.tags));
        $('#nw-deck-requirement-tags').val(tagsToString(card.requirement_tags));
        $('#nw-deck-denied-tags').val(tagsToString(card.denied_tags));
        $('#nw-deck-required-item-tags').val(tagsToString(card.required_item_tags));
        $('#nw-deck-required-location-tags').val(tagsToString(card.required_location_tags));
        $('#nw-deck-denied-location-tags').val(tagsToString(card.denied_location_tags));
        $('#nw-deck-requirement-description').val(card.requirement_description || '');
        $('#nw-deck-ai-instruction').val(card.ai_instruction || '');
        $('#nw-deck-gm').val(card.gm || '');
        $('#nw-deck-sound-effect').val(card.sound_effect || '');
        $('#nw-deck-img-url').val(card.img_url || '');
        $('#nw-deck-class-id').val(card.class_id || '');
        $('#nw-deck-is-leveling').prop('checked', boolVal(card.is_leveling));
        $('#nw-deck-is-disposable').prop('checked', boolVal(card.is_disposable));
        $('#nw-deck-is-active').prop('checked', boolVal(card.is_active));

        $('#nw-deck-modal-title').text('Edit Card');
        $('#nw-deck-delete-btn').show();
        $('#nw-deck-msg').hide().text('');
    }

    function renderTable(rows) {
        var $tbody = $('#nw-deck-tbody');

        if (!rows || !rows.length) {
            $tbody.html('<tr><td colspan="7">No cards found.</td></tr>');
            return;
        }

        var html = rows.map(function (card) {
            var active = boolVal(card.is_active);
            return ''
                + '<tr data-id="' + esc(card.id) + '">'
                + '<td>' + esc(card.name || '') + '</td>'
                + '<td>' + esc(card.deck_category || '') + '</td>'
                + '<td>' + esc(card.type || '') + '</td>'
                + '<td>' + rarityBadge(card.rarity) + '</td>'
                + '<td>' + esc(card.level || 1) + '</td>'
                + '<td><input type="checkbox" class="nw-deck-toggle" data-id="' + esc(card.id) + '"' + (active ? ' checked' : '') + '></td>'
                + '<td>'
                + '<button type="button" class="button button-small nw-deck-edit" data-id="' + esc(card.id) + '">Edit</button> '
                + '<button type="button" class="button button-small nw-deck-delete-row" data-id="' + esc(card.id) + '">Delete</button>'
                + '</td>'
                + '</tr>';
        }).join('');

        $tbody.html(html);
    }

    function loadCards() {
        if (!ajax || !nonce) {
            notice('Missing AJAX config.', 'error');
            return;
        }

        var data = {
            action: 'nw_deck_list',
            nonce: nonce,
            category: $('#nw-deck-filter-category').val() || '',
            rarity: $('#nw-deck-filter-rarity').val() || '',
            active: $('#nw-deck-filter-active').val() || '',
            search: $('#nw-deck-search').val().trim() || ''
        };

        if (activeXhr && activeXhr.readyState !== 4) {
            activeXhr.abort();
        }

        $('#nw-deck-tbody').html('<tr><td colspan="7">Loading…</td></tr>');

        activeXhr = $.post(ajax, data, function (res) {
            if (!res || !res.success) {
                $('#nw-deck-tbody').html('<tr><td colspan="7">Error loading cards.</td></tr>');
                notice((res && res.data) || 'Load error', 'error');
                return;
            }

            cards = Array.isArray(res.data) ? res.data : [];
            renderTable(cards);
        }).fail(function (xhr, status) {
            if (status !== 'abort') {
                $('#nw-deck-tbody').html('<tr><td colspan="7">Request failed.</td></tr>');
                notice('Request failed (' + (xhr.status || 'network') + ').', 'error');
            }
        }).always(function () {
            activeXhr = null;
        });
    }

    function loadSingle(id) {
        $.post(ajax, {
            action: 'nw_deck_get',
            nonce: nonce,
            id: id
        }, function (res) {
            if (!res || !res.success || !res.data) {
                notice((res && res.data) || 'Cannot load card.', 'error');
                return;
            }

            fillForm(res.data);
            openModal();
        }).fail(function (xhr) {
            notice('Request failed (' + (xhr.status || 'network') + ').', 'error');
        });
    }

    function collectPayload() {
        return {
            action: 'nw_deck_save',
            nonce: nonce,
            id: $('#nw-deck-id').val() || '',
            name: $('#nw-deck-name').val().trim(),
            deck_category: $('#nw-deck-category').val(),
            type: $('#nw-deck-type').val().trim(),
            rarity: $('#nw-deck-rarity').val(),
            description: $('#nw-deck-description').val().trim(),
            effect: $('#nw-deck-effect').val().trim(),
            mechanic: $('#nw-deck-mechanic').val().trim(),
            mechanic_goal: $('#nw-deck-mechanic-goal').val().trim(),
            cost_label: $('#nw-deck-cost-label').val().trim(),
            cost_number: $('#nw-deck-cost-number').val(),
            time_cost_minutes: $('#nw-deck-time-cost-minutes').val(),
            cooldown_messages: $('#nw-deck-cooldown-messages').val(),
            entropy_on_fail: $('#nw-deck-entropy-on-fail').val(),
            level: $('#nw-deck-level').val(),
            xp_current: $('#nw-deck-xp-current').val(),
            xp_to_next: $('#nw-deck-xp-to-next').val(),
            bonus: $('#nw-deck-bonus').val().trim(),
            tags: $('#nw-deck-tags').val().trim(),
            requirement_tags: $('#nw-deck-requirement-tags').val().trim(),
            denied_tags: $('#nw-deck-denied-tags').val().trim(),
            required_item_tags: $('#nw-deck-required-item-tags').val().trim(),
            required_location_tags: $('#nw-deck-required-location-tags').val().trim(),
            denied_location_tags: $('#nw-deck-denied-location-tags').val().trim(),
            requirement_description: $('#nw-deck-requirement-description').val().trim(),
            ai_instruction: $('#nw-deck-ai-instruction').val().trim(),
            gm: $('#nw-deck-gm').val().trim(),
            sound_effect: $('#nw-deck-sound-effect').val().trim(),
            img_url: $('#nw-deck-img-url').val().trim(),
            class_id: $('#nw-deck-class-id').val().trim(),
            is_leveling: $('#nw-deck-is-leveling').is(':checked') ? 1 : 0,
            is_disposable: $('#nw-deck-is-disposable').is(':checked') ? 1 : 0,
            is_active: $('#nw-deck-is-active').is(':checked') ? 1 : 0
        };
    }

    $('#nw-deck-add-btn').on('click', function (e) {
        e.preventDefault();
        resetForm();
        openModal();
    });

    $('#nw-deck-filter-btn').on('click', function (e) {
        e.preventDefault();
        loadCards();
    });

    $('#nw-deck-search').on('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            loadCards();
        }
    });

    $('#nw-deck-cancel-btn, #nw-deck-modal-close').on('click', function (e) {
        e.preventDefault();
        closeModal();
    });

    $('#nw-deck-modal').on('click', function (e) {
        if (e.target === this) {
            closeModal();
        }
    });

    $(document).on('click', '.nw-deck-edit', function () {
        var id = parseInt($(this).data('id'), 10) || 0;
        if (id) {
            loadSingle(id);
        }
    });

    $(document).on('change', '.nw-deck-toggle', function () {
        var id = parseInt($(this).data('id'), 10) || 0;
        var state = $(this).is(':checked') ? 1 : 0;
        var $checkbox = $(this);

        $.post(ajax, {
            action: 'nw_deck_toggle',
            nonce: nonce,
            id: id,
            state: state
        }, function (res) {
            if (!res || !res.success) {
                $checkbox.prop('checked', !$checkbox.is(':checked'));
                notice((res && res.data) || 'Toggle failed.', 'error');
                return;
            }

            notice('Card updated.', 'success');
        }).fail(function (xhr) {
            $checkbox.prop('checked', !$checkbox.is(':checked'));
            notice('Request failed (' + (xhr.status || 'network') + ').', 'error');
        });
    });

    $('#nw-deck-save-btn').on('click', function (e) {
        e.preventDefault();

        var payload = collectPayload();
        var $btn = $(this);
        var originalText = $btn.text();

        if (!payload.name) {
            notice('Name is required.', 'error');
            return;
        }

        $btn.prop('disabled', true).text('Saving…');

        $.post(ajax, payload, function (res) {
            $btn.prop('disabled', false).text(originalText);

            if (!res || !res.success) {
                notice((res && res.data) || 'Save failed.', 'error');
                return;
            }

            notice('Card saved.', 'success');
            closeModal();
            loadCards();
        }).fail(function (xhr) {
            $btn.prop('disabled', false).text(originalText);
            notice('Request failed (' + (xhr.status || 'network') + ').', 'error');
        });
    });

    $('#nw-deck-delete-btn').on('click', function (e) {
        e.preventDefault();

        var id = parseInt($('#nw-deck-id').val(), 10) || 0;
        if (!id) {
            return;
        }

        if (!window.confirm('Delete this card? This cannot be undone.')) {
            return;
        }

        $.post(ajax, {
            action: 'nw_deck_delete',
            nonce: nonce,
            id: id
        }, function (res) {
            if (!res || !res.success) {
                notice((res && res.data) || 'Delete failed.', 'error');
                return;
            }

            notice('Card deleted.', 'success');
            closeModal();
            loadCards();
        }).fail(function (xhr) {
            notice('Request failed (' + (xhr.status || 'network') + ').', 'error');
        });
    });

    $(document).on('click', '.nw-deck-delete-row', function () {
        var id = parseInt($(this).data('id'), 10) || 0;
        if (!id) {
            return;
        }

        if (!window.confirm('Delete this card? This cannot be undone.')) {
            return;
        }

        $.post(ajax, {
            action: 'nw_deck_delete',
            nonce: nonce,
            id: id
        }, function (res) {
            if (!res || !res.success) {
                notice((res && res.data) || 'Delete failed.', 'error');
                return;
            }

            notice('Card deleted.', 'success');
            loadCards();
        }).fail(function (xhr) {
            notice('Request failed (' + (xhr.status || 'network') + ').', 'error');
        });
    });

    $(function () {
        loadCards();
    });

})(jQuery);
