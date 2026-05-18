(function($) {
    'use strict';

    const NeoWeaverChat = {

        sessionId: null,
        charId: null,

        init(charId, sessionId) {
            this.charId    = charId;
            this.sessionId = sessionId || this.generateUUID();
            this.bindEvents();
        },

        bindEvents() {
            $('#neoweaver-chat-form').on('submit', (e) => {
                e.preventDefault();
                const msg = $('#neoweaver-chat-input').val().trim();
                if (!msg) return;
                $('#neoweaver-chat-input').val('');
                this.sendMessage(msg);
            });
        },

        async sendMessage(message) {
            this.appendMessage('player', message);
            this.showTyping();

            try {
                const res = await $.ajax({
                    url:    neoweaver_ajax.ajax_url,
                    method: 'POST',
                    data: {
                        action:     'neoweaver_ai_chat',
                        nonce:      neoweaver_ajax.nonce,
                        char_id:    this.charId,
                        session_id: this.sessionId,
                        message:    message,
                    },
                });

                this.hideTyping();

                if (res.success) {
                    const data = res.data;

                    // Wyświetl tekst GM-a
                    if (data.text) {
                        this.appendMessage('gm', data.text);
                    }

                    // Procesuj tagi systemowe
                    if (data.tags && data.tags.length > 0) {
                        this.processTags(data.tags);
                    }

                    // Aktualizuj licznik tokenów (tylko admin/debug)
                    if (neoweaver_ajax.is_admin && data.tokens) {
                        this.updateTokenCounter(data.tokens);
                    }

                } else {
                    this.appendMessage('error', res.data?.message || 'Błąd AI');
                }

            } catch (err) {
                this.hideTyping();
                this.appendMessage('error', 'Brak połączenia z serwerem.');
                console.error('[NeoWeaver AI]', err);
            }
        },

        // ------------------------------------------------
        // Parser tagów — aktualizuje stan gry
        // ------------------------------------------------
        processTags(tags) {
            tags.forEach(({ tag, val }) => {
                switch(tag) {

                    case 'LOC':
                    case 'LOCATION_CHANGE':
                        this.triggerEvent('neoweaver:location_change', { locationId: val });
                        break;

                    case 'ENTROPY_UP':
                        this.triggerEvent('neoweaver:entropy_change', { delta: +val });
                        break;

                    case 'ENTROPY_DOWN':
                        this.triggerEvent('neoweaver:entropy_change', { delta: -(+val) });
                        break;

                    case 'HP_CHANGE':
                        this.triggerEvent('neoweaver:hp_change', { delta: +val });
                        break;

                    case 'MP_CHANGE':
                        this.triggerEvent('neoweaver:mp_change', { delta: +val });
                        break;

                    case 'GOLD_CHANGE':
                        this.triggerEvent('neoweaver:gold_change', { delta: +val });
                        break;

                    case 'STATUS_POISONED':
                    case 'STATUS_STUNNED':
                    case 'STATUS_BLESSED':
                        this.triggerEvent('neoweaver:status_add', { status: tag.replace('STATUS_', '') });
                        break;

                    case 'HUD_REFRESH':
                        this.triggerEvent('neoweaver:hud_refresh', {});
                        break;

                    case 'COMBAT_START':
                        this.triggerEvent('neoweaver:combat_start', { enemyId: val });
                        break;

                    case 'COMBAT_END':
                        this.triggerEvent('neoweaver:combat_end', { result: val });
                        break;

                    case 'ITEM_GAINED':
                        this.triggerEvent('neoweaver:item_gained', { itemId: val });
                        break;

                    case 'QUEST_UPDATE':
                        this.triggerEvent('neoweaver:quest_update', { questId: val });
                        break;

                    default:
                        // Nieznany tag — loguj dla debugowania
                        console.debug('[NeoWeaver TAG]', tag, val);
                }
            });
        },

        // ------------------------------------------------
        // Helpers UI
        // ------------------------------------------------
        appendMessage(role, text) {
            const roleClass = {
                player: 'nw-msg--player',
                gm:     'nw-msg--gm',
                error:  'nw-msg--error',
            }[role] || 'nw-msg--gm';

            const $msg = $(`<div class="nw-message ${roleClass}">
                <span class="nw-msg-text">${this.escapeHtml(text)}</span>
            </div>`);

            $('#neoweaver-chat-messages').append($msg);
            this.scrollToBottom();
        },

        showTyping() {
            $('#neoweaver-chat-messages').append(
                '<div class="nw-message nw-msg--gm nw-typing" id="nw-typing-indicator">' +
                '<span></span><span></span><span></span></div>'
            );
            this.scrollToBottom();
        },

        hideTyping() {
            $('#nw-typing-indicator').remove();
        },

        updateTokenCounter(tokens) {
            const total = tokens.prompt + tokens.completion;
            $('#nw-token-counter').text(`${total} tokens`).show();
        },

        scrollToBottom() {
            const $msgs = $('#neoweaver-chat-messages');
            $msgs.scrollTop($msgs[0].scrollHeight);
        },

        triggerEvent(name, detail) {
            $(document).trigger(name, [detail]);
        },

        escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        },

        generateUUID() {
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
                const r = Math.random() * 16 | 0;
                return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
            });
        },
    };

    // ------------------------------------------------
    // AJAX handler — podepnij w PHP:
    //   add_action('wp_ajax_neoweaver_ai_chat', ...)
    // ------------------------------------------------
    $(document).ready(function() {
        if (typeof neoweaver_chat_config !== 'undefined') {
            NeoWeaverChat.init(
                neoweaver_chat_config.char_id,
                neoweaver_chat_config.session_id
            );
        }
    });

    window.NeoWeaverChat = NeoWeaverChat;

})(jQuery);
