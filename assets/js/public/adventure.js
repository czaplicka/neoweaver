(function () {
    'use strict';

    function onDOMReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn, { once: true });
        } else {
            fn();
        }
    }

    // =========================================================================
    // CHAT CHANNEL
    // =========================================================================
    const playerChatEl = document.getElementById('player-chat');

    async function fetchChatChannelId() {
        const sessionId = window.twGameState?.currentSessionId;
        if (sessionId === null || sessionId === undefined || sessionId === 0) {
            console.warn('No valid currentSessionId – cannot resolve chat channel');
            return null;
        }
        try {
            const params = new URLSearchParams({
                action:     'tw_get_session_state',
                session_id: sessionId,
                nonce:      window.twAdventureData?.nonce ?? '',
            });
            const resp = await fetch(window.twAdventureData?.ajax_url ?? '/wp-admin/admin-ajax.php', {
                method:      'POST',
                body:        params,
                credentials: 'same-origin',
            });
            if (!resp.ok) { console.error('Session state HTTP error', resp.status); return null; }
            const json = await resp.json();
            if (!json.success || !json.data) return null;
            return json.data.chat_channel_id || null;
        } catch (e) {
            console.error('Session state fetch error', e);
            return null;
        }
    }

    async function waitForChatChannel(maxTries = 6) {
        if (!playerChatEl) return;
        playerChatEl.innerHTML = '<p class="empty-msg">Channel syncing, please wait…</p>';
        let delay = 1000;
        for (let i = 0; i < maxTries; i++) {
            const chan = await fetchChatChannelId();
            if (chan) {
                window.twGameState.chatChannelId = chan;
                console.log('✓ Chat channel ready:', chan);
                if (window.twInitMissionChat) {
                    window.twInitMissionChat(chan);
                } else {
                    playerChatEl.innerHTML = '<p class="empty-msg">Channel ready. Messages will appear here.</p>';
                }
                return;
            }
            await new Promise(r => setTimeout(r, delay));
            delay = Math.min(delay * 1.5, 8000);
        }
        playerChatEl.innerHTML = '<p class="empty-msg">Channel sync timeout. Try refreshing the terminal.</p>';
    }

    // =========================================================================
    // WORLD STATE
    // =========================================================================
    async function ensureWorldState() {
        const campaignId = window.twGameState?.currentCampaignId;
        const nonce      = window.twAdventureData?.nonce;
        if (!campaignId || !nonce) return;
        try {
            const formData = new URLSearchParams({
                action:      'tw_ensure_world_state',
                campaign_id: String(campaignId),
                nonce:       nonce,
            });
            const resp = await fetch(window.twAdventureData.ajax_url, {
                method: 'POST', body: formData, credentials: 'same-origin',
            });
            const json = await resp.json();
            console.log('🌍 World state ensure:', json);
            if (json.success && window.refreshTwClock) {
                setTimeout(window.refreshTwClock, 500);
            }
        } catch (e) {
            console.error('World state ensure error', e);
        }
    }

    // =========================================================================
    // HYDRATION
    // =========================================================================
    function hydrateTwGameState() {
        if (!window.twAdventureData) {
            console.warn('No twAdventureData – cannot bootstrap game');
            return;
        }
        window.twGameState = window.twGameState || {};
        const d = window.twAdventureData;
        window.twGameState.currentSessionId   = d.active_session_id   ?? null;
        window.twGameState.currentCampaignId  = d.active_campaign_id  ?? null;
        window.twGameState.currentCharacterId = d.active_character_id ?? null;
        window.twGameState.currentWorldId     = d.active_world_id     ?? null;
        window.twGameState.currentLocationId  = d.active_location_id  ?? null;
        console.log('✓ twGameState hydrated', window.twGameState);
        document.dispatchEvent(new Event('twGameStateHydrated'));
    }

    document.addEventListener('twGameStateHydrated', function onGameStateReady() {
        // scenarios-loader.js listens to twGameStateHydrated independently
        Promise.allSettled([
            window.twGameState?.chatChannelId
                ? Promise.resolve()
                : waitForChatChannel(),
            ensureWorldState(),
        ]).then(results => {
            results.forEach((r, i) => {
                if (r.status === 'rejected') {
                    console.error('Post-hydration task', i, 'failed:', r.reason);
                }
            });
        });
    }, { once: true });

    onDOMReady(hydrateTwGameState);

    console.log('🎮 NeoWeaver Adventure - Ready & Waiting');
})();
