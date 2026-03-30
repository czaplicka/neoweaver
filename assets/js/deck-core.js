(function () {
    'use strict';

    // =========================================================================
    // 1. SYNCHRONIZACJA CZASU (REALTIME)
    // Słucha zmian w Supabase i kręci kołem czasu bez przeładowania strony
    // =========================================================================
    function initTimeRealtimeSync() {
        const supabase = window.twSupabase;
        const clockContainer = document.querySelector('#tw-clock-container');
        const campaignId = clockContainer?.dataset.campaignId;

        if (supabase && campaignId) {
            supabase
                .channel('public:cyber_world_state_sync')
                .on('postgres_changes', { 
                    event: 'UPDATE', 
                    schema: 'public', 
                    table: 'cyber_world_state',
                    filter: `campaign_id=eq.${campaignId}` 
                }, payload => {
                    console.log('🌍 Time sync triggered!', payload.new);
                    if (typeof window.refreshTwClock === 'function') {
                        window.refreshTwClock(); // Wywołuje funkcję odświeżającą koło czasu
                    }
                })
                .subscribe();
        }
    }

    // =========================================================================
    // 2. DECK CORE – LOGIKA KART I WALIDACJA
    // =========================================================================
    function initDeckCore() {
        const isAdventurePage = document.getElementById('adventure-shell') || document.querySelector('.chat-window');
        if (!isAdventurePage) return;

        function getState() {
            return {
                gameState: window.twGameState || {},
                supabaseClient: window.twSupabase || null,
                TABLES: window.twTables || { deck: 'cyber_character_deck' }
            };
        }

        // --- Walidacja kart (Tagi i Statusy) ---
        function validateCardPlay(card, activeTags = []) {
            let result = { canPlay: true, reason: "Ready to use", updatedCost: parseInt(card.cost_number) || 0 };

            if (card.requirement_tags) {
                const requirements = card.requirement_tags.split(' OR ').map(t => t.trim());
                const hasRequirement = requirements.some(req => activeTags.includes(req));
                if (!hasRequirement) {
                    return { canPlay: false, reason: `Requires: ${card.requirement_tags}`, updatedCost: result.updatedCost };
                }
            }
            // Logika statusów (Wounded / Exhausted)
            if (activeTags.includes('Wounded') && card.type === 'Attack') {
                result.updatedCost += 1;
                result.reason = "Increased effort due to wounds.";
            }
            if (activeTags.includes('Exhausted') && result.updatedCost >= 3) {
                return { canPlay: false, reason: "Too exhausted for risky maneuvers!", updatedCost: result.updatedCost };
            }
            return result;
        }

        // --- Ładowanie talii (Postać + Lokacja) ---
        async function loadPlayerDeck() {
            const { gameState, supabaseClient, TABLES } = getState();
            const allContainer = document.getElementById('hand-cards-all');
            if (!allContainer) return;

            const charId = gameState?.currentCharacterId;
            const locationTags = gameState.currentLocationTags || [];

            try {
                // 1. Karty z ekwipunku postaci
                const { data: charCards, error: err1 } = await supabaseClient
                    .from(TABLES.deck)
                    .select(`card:card_id (*)`)
                    .eq('character_id', charId);

                // 2. Karty Akcji (ActionDeck)
                const { data: actionCards, error: err2 } = await supabaseClient
                    .from('cyber_deck')
                    .select('*')
                    .eq('type', 'Action');

                if (err1 || err2) throw (err1 || err2);

                const personalCards = (charCards || []).map(row => row.card).filter(Boolean);
                
                // Filtrowanie akcji po tagach lokacji (np. Karczma)
                const availableActions = (actionCards || []).filter(card => {
                    if (!card.required_location_tags) return true;
                    const reqs = card.required_location_tags.split(',').map(t => t.trim());
                    return reqs.some(r => locationTags.includes(r));
                });

                const finalDeck = [...personalCards, ...availableActions];
                renderPlayerHand(finalDeck, gameState.characterTags || []);

            } catch (err) {
                console.error('Exception loading deck:', err);
            }
        }

        // --- Renderowanie kart na ekranie ---
        function renderPlayerHand(cards, activeTags = []) {
            const allContainer = document.getElementById('hand-cards-all');
            if (!allContainer) return;

            allContainer.innerHTML = '';

            const validatedCards = cards.map(cardData => {
                const validation = validateCardPlay(cardData, activeTags);
                return {
                    ...cardData,
                    validation,
                    typeClass: (cardData.type || '').toLowerCase(),
                    costLabel: `${Math.max(0, validation.updatedCost)}`,
                    cardTags: (cardData.tags || '').split(',').map(t => t.trim()).filter(Boolean)
                };
            });

            // Sortowanie: Grywalne przodem, potem alfabetycznie
            validatedCards.sort((a, b) => (b.validation.canPlay - a.validation.canPlay) || a.name.localeCompare(b.name));

            validatedCards.forEach(cardData => {
                const cardEl = document.createElement('div');
                cardEl.className = `deck-card type-${cardData.typeClass} ${cardData.validation.canPlay ? '' : 'card-locked'}`;
                cardEl.dataset.cardId = cardData.id;
                cardEl.dataset.cardType = cardData.typeClass;

                cardEl.innerHTML = `
                    <div class="deck-card-inner">
                        <header class="deck-card-header">
                             <h4 class="deck-card-title">${cardData.name || 'Unnamed'}</h4>
                             ${cardData.costLabel ? `<div class="deck-card-cost">${cardData.costLabel}</div>` : ''}
                             ${cardData.time_cost_minutes > 0 ? `
                                <div class="card-time-tag"><i class="fa-regular fa-clock"></i> ${cardData.time_cost_minutes}m</div>
                             ` : ''}
                        </header>
                        <div class="card-body">
                            <div class="deck-card-subtitle">
                                <span class="card-type">${cardData.type || ''}</span>
                                ${cardData.mechanic ? `<span class="attr-separator">//</span><span class="card-mechanic">${cardData.mechanic}</span>` : ''}
                            </div>
                            <div class="deck-card-desc">${cardData.description || ''}</div>
                            ${cardData.effect ? `<div class="deck-card-effect"><span class="effect-label">Effect</span><p>${cardData.effect}</p></div>` : ''}
                        </div>
                        <div class="card-tags">
                            ${cardData.cardTags.map(tag => `<span class="tag">#${tag}</span>`).join('')}
                        </div>
                        <div class="card-extra" ${cardData.validation.canPlay ? 'hidden' : ''}>
                            <p class="card-lock-reason">${cardData.requirement_description || cardData.validation.reason}</p>
                        </div>
                    </div>
                `;

                // --- KLIKNIĘCIE KARTY (Dźwięk + Czas + Chat) ---
                cardEl.addEventListener('click', async () => {
                    document.querySelectorAll('.deck-card').forEach(c => c.classList.remove('card-selected'));
                    cardEl.classList.add('card-selected');

                    if (!cardData.validation.canPlay) return;

                    // 1. Dźwięk
                    if (cardData.sound_effect) {
                        const audio = new Audio(`https://cyber.nieodparady.pl/wp-content/uploads/sounds/${cardData.sound_effect}`);
                        audio.volume = 0.3;
                        audio.play().catch(() => console.log("Audio blocked by browser"));
                    }

                    // 2. Czas (RPC do Supabase)
                    if (cardData.time_cost_minutes > 0) {
                        const { error } = await window.twSupabase.rpc('add_game_time', {
                            minutes_to_add: parseInt(cardData.time_cost_minutes),
                            campaign_id_param: parseInt(window.twAdventureData?.active_campaign_id || 1)
                        });
                        if (error) console.error("Supabase RPC Error:", error);
                    }

                    // 3. Informacja dla AI GM
                    if (typeof window.twSendToChat === 'function') {
                        window.twSendToChat(`I use: **${cardData.name}**`);
                    }
                });

                allContainer.appendChild(cardEl);
            });

            initCardZoom();
        }

        // --- Zoom karty (Double Click) ---
        function initCardZoom() {
            const overlay = document.getElementById('card-zoom-overlay');
            if (!overlay) return;
            const content = overlay.querySelector('.card-zoom-content');

            document.querySelectorAll('.deck-card').forEach(card => {
                card.addEventListener('dblclick', () => {
                    const title = card.querySelector('.deck-card-title')?.textContent || '';
                    const desc = card.querySelector('.deck-card-desc')?.innerHTML || '';
                    const effect = card.querySelector('.deck-card-effect')?.innerHTML || '';

                    content.innerHTML = `
                        <h3 class="zoom-title">${title}</h3>
                        <div class="zoom-section"><div class="zoom-body">${desc}</div></div>
                        ${effect ? `<div class="zoom-section">${effect}</div>` : ''}
                    `;
                    overlay.classList.add('is-open');
                });
            });

            overlay.onclick = (e) => { if (e.target.id === 'card-zoom-overlay') overlay.classList.remove('is-open'); };
        }

        // START SYSTEMU
        function startDeck() {
            if (window.twDeckCoreStarted) return;
            window.twDeckCoreStarted = true;
            loadPlayerDeck();
            initTimeRealtimeSync();
        }

        if (window.twGameReady) startDeck();
        else document.addEventListener('twGameStateHydrated', startDeck);

        window.twLoadPlayerDeck = loadPlayerDeck;
    }

    document.addEventListener('DOMContentLoaded', initDeckCore);
})();

// =========================================================================
// 3. UI TABS & SCENARIO (Zarządzanie widokami)
// =========================================================================
document.addEventListener('DOMContentLoaded', () => {
    // Filtrowanie kart (Attack, Tech, Social...)
    const typeTabs = document.querySelectorAll('.hand-type-tab');
    typeTabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const type = tab.dataset.typeTab;
            typeTabs.forEach(t => t.classList.remove('is-active'));
            tab.classList.add('is-active');
            document.querySelectorAll('.deck-card').forEach(card => {
                card.style.display = (type === 'all' || card.dataset.cardType === type) ? '' : 'none';
            });
        });
    });

    // Przełączanie czatów (Gracz / GM)
    const chatTabs = document.querySelectorAll('.chat-tab');
    chatTabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.chatTarget;
            chatTabs.forEach(t => t.classList.remove('is-active'));
            tab.classList.add('is-active');
            document.querySelectorAll('.chat-window').forEach(w => {
                w.style.display = (w.id === target) ? '' : 'none';
            });
        });
    });

    // Scenario Cards
    jQuery(document).on('click', '.scenario-card', function() {
        const $card = jQuery(this);
        const scenarioId = $card.data('scenario-id');
        const campaignId = window.twGameState?.currentCampaignId || 1;

        $card.addClass('is-loading').text('⏳ Generating...');
        jQuery.post(ajaxurl, {
            action: 'tw_start_scenario_generation',
            scenario_id: scenarioId,
            campaign_id: campaignId
        }).done(() => { 
            setTimeout(() => location.reload(), 1500); 
        });
    });
});
