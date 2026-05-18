(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // FIX #3: Guard against DOMContentLoaded already having fired when this
    // inline script runs (which is common for scripts at the bottom of body).
    // -------------------------------------------------------------------------
    function onDOMReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn, { once: true });
        } else {
            fn();
        }
    }

    // =========================================================================
    // SCENARIOS
    // FIX #9: scenarios fetch now includes a nonce so the AJAX handler can
    // verify the request via check_ajax_referer().
    // PERF #12: limit=3 moved to the server-side AJAX handler; slice removed.
    // =========================================================================
    async function loadScenarios() {
        const shell = document.getElementById('adventure-shell');
        if (!shell) return;
        const list = document.getElementById('scenarios-list');
        if (!list) {
            console.warn('⚠️ scenarios-list not found in DOM');
            return;
        }
        list.innerHTML = '<p class="empty-msg">Scanning network for missions...</p>';
        try {
            const campaignId = window.twGameState?.currentCampaignId || window.twAdventureData?.active_campaign_id;
            console.log('🔍 Scenarios: Resolved campaignId:', campaignId);
            if (!campaignId) {
                list.innerHTML = '<p class="empty-msg">No active campaign detected.</p>';
                return;
            }
            const formData = new URLSearchParams({
                action:      'tw_get_scenarios_ajax',
                campaign_id: campaignId,
                nonce:       window.twAdventureData?.nonce ?? '', // FIX #9
            });
            const response = await fetch(window.twAdventureData?.ajax_url ?? '/wp-admin/admin-ajax.php', {
                method:      'POST',
                body:        formData,
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error('AJAX HTTP error ' + response.status);
            }
            const json = await response.json();
            if (!json.success || !Array.isArray(json.data)) {
                list.innerHTML = '<p class="empty-msg">No missions available for this campaign yet.</p>';
                return;
            }
            // PERF #12: server should now return max 3 — no client-side slice needed.
            const scenarios = json.data;
            if (!scenarios.length) {
                list.innerHTML = '<p class="empty-msg">No missions available. Ask your GM to sync the campaign.</p>';
                return;
            }
            list.innerHTML = '';
            // XSS FIX: build cards via DOM API (textContent / setAttribute) instead
            // of innerHTML interpolation so scenario data from the server can never
            // inject arbitrary HTML or script into the page.
            scenarios.forEach((s) => {
                const tags = (s.tags || '').split(',').map((t) => t.trim()).filter(Boolean);

                const card  = document.createElement('article');
                card.className = 'deck-card scenario-card';
                card.dataset.scenarioId = s.id;

                const inner = document.createElement('div');
                inner.className = 'deck-card-inner';

                // Optional image
                if (s.img_url) {
                    const wrap = document.createElement('div');
                    wrap.className = 'scenario-image-wrap';
                    const img = document.createElement('img');
                    img.setAttribute('src', s.img_url);
                    img.setAttribute('alt', s.name || '');
                    img.className = 'scenario-image';
                    wrap.appendChild(img);
                    inner.appendChild(wrap);
                }

                // Header
                const header = document.createElement('header');
                header.className = 'scenario-header';
                const diff = document.createElement('span');
                diff.className = 'scenario-difficulty';
                diff.textContent = s.difficulty || '';
                const title = document.createElement('h4');
                title.className = 'scenario-title';
                title.textContent = s.name || 'Untitled mission';
                header.appendChild(diff);
                header.appendChild(title);

                // Body
                const body = document.createElement('div');
                body.className = 'scenario-body';
                const goal = document.createElement('p');
                goal.className = 'scenario-goal';
                goal.textContent = s.goal || '';
                const tagsP = document.createElement('p');
                tagsP.className = 'scenario-tags';
                tags.forEach((t) => {
                    const span = document.createElement('span');
                    span.className = 'scenario-tag';
                    span.textContent = '#' + t;
                    tagsP.appendChild(span);
                });
                if (s.is_boss) {
                    const span = document.createElement('span');
                    span.className = 'scenario-tag';
                    span.textContent = '#boss';
                    tagsP.appendChild(span);
                }
                if (s.is_key_arc) {
                    const span = document.createElement('span');
                    span.className = 'scenario-tag';
                    span.textContent = '#key_arc';
                    tagsP.appendChild(span);
                }
                body.appendChild(goal);
                body.appendChild(tagsP);

                // Footer
                const footer = document.createElement('footer');
                footer.className = 'scenario-footer';
                const type = document.createElement('span');
                type.className = 'scenario-type';
                type.textContent = s.type || '';
                const cat = document.createElement('span');
                cat.className = 'scenario-category';
                cat.textContent = s.category || '';
                footer.appendChild(type);
                footer.appendChild(cat);

                inner.appendChild(header);
                inner.appendChild(body);
                inner.appendChild(footer);
                card.appendChild(inner);
                list.appendChild(card);
            });
            console.log('✅ Loaded', scenarios.length, 'scenario cards');
        } catch (error) {
            console.error('❌ Error loading scenarios:', error);
            list.innerHTML = '<p class="empty-msg">Mission panel offline. Please refresh the terminal.</p>';
        }
    }
    window.twLoadScenarios = loadScenarios;

    // =========================================================================
    // CHAT CHANNEL
    // FIX #4 & #5: fetchChatChannelId now uses strict null/undefined check so
    // a session ID of 0 is correctly detected as "no active session" and
    // waitForChatChannel is only started after twGameState is fully hydrated.
    // PERF #13: exponential backoff replaces fixed 2 s × 10 poll loop.
    // =========================================================================
    const playerChatEl = document.getElementById('player-chat');

    async function fetchChatChannelId() {
        const sessionId = window.twGameState?.currentSessionId;

        // FIX #5: strict check — 0, null, and undefined all mean "no session".
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
            if (!resp.ok) {
                console.error('Session state HTTP error', resp.status);
                return null;
            }
            const json = await resp.json();
            if (!json.success || !json.data) return null;
            return json.data.chat_channel_id || null;
        } catch (e) {
            console.error('Session state fetch error', e);
            return null;
        }
    }

    // PERF #13: exponential backoff — starts at 1 s, caps at 8 s, max 6 tries.
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
                method:      'POST',
                body:        formData,
                credentials: 'same-origin',
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
    // FIX #3: Use onDOMReady to safely handle already-fired DOMContentLoaded.
    // PERF #14: All three twGameStateHydrated consumers consolidated into one
    // listener so initialisation order is explicit and handlers run in parallel.
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
        console.log('✓ twGameState hydrated from twAdventureData', window.twGameState);
        document.dispatchEvent(new Event('twGameStateHydrated'));
    }

    // PERF #14: single consolidated listener replaces three separate handlers.
    document.addEventListener('twGameStateHydrated', function onGameStateReady() {
        // Run all post-hydration tasks in parallel; failures are isolated.
        Promise.allSettled([
            loadScenarios(),
            // FIX #4: waitForChatChannel is now called only after hydration,
            // so twGameState.currentSessionId is guaranteed to be set.
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

    // Kick off hydration once the DOM is ready.
    onDOMReady(hydrateTwGameState);

    console.log('🎮 Tale Weaver Scenarios Loader - Ready & Waiting');
})();
