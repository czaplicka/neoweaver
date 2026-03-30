(function () {
    'use strict';

    function initTimeRealtimeSync() {
        const supabase = window.twSupabase;
        const clockContainer = document.querySelector('#tw-clock-container');
        const campaignId = clockContainer?.dataset?.campaignId;

        if (!supabase || !campaignId) return;

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
                    window.refreshTwClock();
                }
            })
            .subscribe();
    }

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

        function validateCardPlay(card, activeTags = []) {
            const baseCost = parseInt(card.cost_number) || 0;
            let result = { canPlay: true, reason: 'Ready to use', updatedCost: baseCost };

            if (card.requirement_tags) {
                const requirements = card.requirement_tags.split(' OR ').map(t => t.trim()).filter(Boolean);
                const hasRequirement = requirements.some(req => activeTags.includes(req));
                if (!hasRequirement) {
                    return { canPlay: false, reason: `Requires: ${card.requirement_tags}`, updatedCost: baseCost };
                }
            }

            if (activeTags.includes('Wounded') && card.type === 'Attack') {
                result.updatedCost += 1;
                result.reason = 'Increased effort due to wounds.';
            }

            if (activeTags.includes('Exhausted') && result.updatedCost >= 3) {
                return { canPlay: false, reason: 'Too exhausted for risky maneuvers!', updatedCost: result.updatedCost };
            }

            return result;
        }

        async function loadPlayerDeck() {
            const { gameState, supabaseClient, TABLES } = getState();
            const allContainer = document.getElementById('hand-cards-all');
            if (!allContainer || !supabaseClient) return;

            const charId = gameState?.currentCharacterId;
            if (!charId) {
                console.warn('[NeoWeaver] loadPlayerDeck: no currentCharacterId in twGameState');
                return;
            }

            const locationTags = gameState.currentLocationTags || [];

            allContainer.innerHTML = Array(4).fill(
                '<div class="deck-card skeleton-card" aria-hidden="true"><div class="skeleton skeleton-heading"></div><div class="skeleton skeleton-text"></div><div class="skeleton skeleton-text"></div></div>'
            ).join('');

            try {
                const [charResult, actionResult] = await Promise.all([
                    supabaseClient.from(TABLES.deck).select('card:card_id (*)').eq('character_id', charId),
                    supabaseClient.from('cyber_deck').select('*').eq('type', 'Action')
                ]);

                if (charResult.error) throw charResult.error;
                if (actionResult.error) throw actionResult.error;

                const personalCards = (charResult.data || []).map(row => row.card).filter(Boolean);

                const availableActions = (actionResult.data || []).filter(card => {
                    if (!card.required_location_tags) return true;
                    const reqs = card.required_location_tags.split(',').map(t => t.trim()).filter(Boolean);
                    return reqs.some(r => locationTags.includes(r));
                });

                const finalDeck = [...personalCards, ...availableActions];
                renderPlayerHand(finalDeck, gameState.characterTags || []);

            } catch (err) {
                console.error('[NeoWeaver] Exception loading deck:', err);
                allContainer.innerHTML = `<div class="deck-error"><p>⚠️ Failed to load deck. <button onclick="window.twLoadPlayerDeck && window.twLoadPlayerDeck()">Retry</button></p></div>`;
            }
        }

        function renderPlayerHand(cards, activeTags = []) {
            const allContainer = document.getElementById('hand-cards-all');
            if (!allContainer) return;

            allContainer.innerHTML = '';

            if (!cards.length) {
                allContainer.innerHTML = '<div class="deck-empty"><p>No cards available in this location.</p></div>';
                return;
            }

            const safe = (str) => String(str || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');

            const validatedCards = cards.map(cardData => {
                const validation = validateCardPlay(cardData, activeTags);
                return {
                    ...cardData,
                    validation,
                    typeClass: (cardData.type || '').toLowerCase().replace(/\s+/g, '-'),
                    costLabel: `${Math.max(0, validation.updatedCost)}`,
                    cardTags: (cardData.tags || '').split(',').map(t => t.trim()).filter(Boolean)
                };
            });

            validatedCards.sort((a, b) =>
                (b.validation.canPlay ? 1 : 0) - (a.validation.canPlay ? 1 : 0) ||
                (a.name || '').localeCompare(b.name || '')
            );

            const fragment = document.createDocumentFragment();

            validatedCards.forEach(cardData => {
                const cardEl = document.createElement('div');
                cardEl.className = `deck-card type-${cardData.typeClass}${cardData.validation.canPlay ? '' : ' card-locked'}`;
                cardEl.dataset.cardId = cardData.id;
                cardEl.dataset.cardType = cardData.typeClass;
                cardEl.setAttribute('role', 'button');
                cardEl.setAttribute('tabindex', '0');
                cardEl.setAttribute('aria-disabled', cardData.validation.canPlay ? 'false' : 'true');
                cardEl.setAttribute('aria-label', cardData.name || 'Card');

                cardEl.innerHTML = `
                    <div class="deck-card-inner">
                        <header class="deck-card-header">
                            <h4 class="deck-card-title">${safe(cardData.name) || 'Unnamed'}</h4>
                            ${cardData.costLabel ? `<div class="deck-card-cost" aria-label="Cost: ${cardData.costLabel}">${safe(cardData.costLabel)}</div>` : ''}
                            ${cardData.time_cost_minutes > 0 ? `<div class="card-time-tag"><i class="fa-regular fa-clock" aria-hidden="true"></i> ${safe(cardData.time_cost_minutes)}m</div>` : ''}
                        </header>
                        <div class="card-body">
                            <div class="deck-card-subtitle">
                                <span class="card-type">${safe(cardData.type)}</span>
                                ${cardData.mechanic ? `<span class="attr-separator" aria-hidden="true">//</span><span class="card-mechanic">${safe(cardData.mechanic)}</span>` : ''}
                            </div>
                            <div class="deck-card-desc">${safe(cardData.description)}</div>
                            ${cardData.effect ? `<div class="deck-card-effect"><span class="effect-label">Effect</span><p>${safe(cardData.effect)}</p></div>` : ''}
                        </div>
                        <div class="card-tags" aria-label="Tags">
                            ${cardData.cardTags.map(tag => `<span class="tag">#${safe(tag)}</span>`).join('')}
                        </div>
                        ${!cardData.validation.canPlay ? `<div class="card-extra"><p class="card-lock-reason">${safe(cardData.requirement_description || cardData.validation.reason)}</p></div>` : ''}
                    </div>
                `;

                async function handleCardActivate() {
                    document.querySelectorAll('.deck-card').forEach(c => c.classList.remove('card-selected'));
                    cardEl.classList.add('card-selected');

                    if (!cardData.validation.canPlay) return;

                    if (cardData.sound_effect) {
                        try {
                            const audio = new Audio(`https://cyber.nieodparady.pl/wp-content/uploads/sounds/${encodeURIComponent(cardData.sound_effect)}`);
                            audio.volume = 0.3;
                            await audio.play();
                        } catch {
                            console.log('[NeoWeaver] Audio blocked by browser');
                        }
                    }

                    const timeCost = parseInt(cardData.time_cost_minutes) || 0;
                    if (timeCost > 0) {
                        const campaignId = parseInt(window.twAdventureData?.active_campaign_id) || 1;
                        const { error: rpcError } = await window.twSupabase.rpc('add_game_time', {
                            minutes_to_add: timeCost,
                            campaign_id_param: campaignId
                        });
                        if (rpcError) console.error('[NeoWeaver] Supabase RPC Error:', rpcError);
                    }

                    if (typeof window.twSendToChat === 'function') {
                        window.twSendToChat(`I use: **${cardData.name}**`);
                    }
                }

                cardEl.addEventListener('click', handleCardActivate);
                cardEl.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        handleCardActivate();
                    }
                });

                fragment.appendChild(cardEl);
            });

            allContainer.appendChild(fragment);
            initCardZoom();
        }

        function initCardZoom() {
            const overlay = document.getElementById('card-zoom-overlay');
            if (!overlay) return;
            const content = overlay.querySelector('.card-zoom-content');
            if (!content) return;

            const safe = (str) => String(str || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');

            document.querySelectorAll('.deck-card').forEach(card => {
                card.addEventListener('dblclick', () => {
                    const title = card.querySelector('.deck-card-title')?.textContent || '';
                    const desc = card.querySelector('.deck-card-desc')?.innerHTML || '';
                    const effect = card.querySelector('.deck-card-effect')?.innerHTML || '';
                    const tags = card.querySelector('.card-tags')?.innerHTML || '';

                    content.innerHTML = `
                        <h3 class="zoom-title">${safe(title)}</h3>
                        <div class="zoom-section"><div class="zoom-body">${desc}</div></div>
                        ${effect ? `<div class="zoom-section">${effect}</div>` : ''}
                        ${tags ? `<div class="zoom-tags">${tags}</div>` : ''}
                    `;
                    overlay.classList.add('is-open');
                    overlay.setAttribute('aria-hidden', 'false');
                    content.setAttribute('tabindex', '-1');
                    content.focus();
                });
            });

            const closeOverlay = () => {
                overlay.classList.remove('is-open');
                overlay.setAttribute('aria-hidden', 'true');
            };

            overlay.addEventListener('click', (e) => { if (e.target.id === 'card-zoom-overlay') closeOverlay(); });
            overlay.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeOverlay(); });
        }

        function startDeck() {
            if (window.twDeckCoreStarted) return;
            window.twDeckCoreStarted = true;
            loadPlayerDeck();
            initTimeRealtimeSync();
        }

        if (window.twGameReady) {
            startDeck();
        } else {
            document.addEventListener('twGameStateHydrated', startDeck, { once: true });
        }

        window.twLoadPlayerDeck = loadPlayerDeck;
    }

    document.addEventListener('DOMContentLoaded', initDeckCore);
})();

// =========================================================================
// 3. UI TABS & SCENARIO
// =========================================================================
document.addEventListener('DOMContentLoaded', () => {

    const typeTabs = document.querySelectorAll('.hand-type-tab');
    typeTabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const type = tab.dataset.typeTab;
            if (!type) return;
            typeTabs.forEach(t => { t.classList.remove('is-active'); t.setAttribute('aria-selected', 'false'); });
            tab.classList.add('is-active');
            tab.setAttribute('aria-selected', 'true');
            document.querySelectorAll('.deck-card').forEach(card => {
                card.style.display = (type === 'all' || card.dataset.cardType === type) ? '' : 'none';
            });
        });
    });

    const chatTabs = document.querySelectorAll('.chat-tab');
    chatTabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.chatTarget;
            if (!target) return;
            chatTabs.forEach(t => { t.classList.remove('is-active'); t.setAttribute('aria-selected', 'false'); });
            tab.classList.add('is-active');
            tab.setAttribute('aria-selected', 'true');
            document.querySelectorAll('.chat-window').forEach(w => {
                const isTarget = w.id === target;
                w.style.display = isTarget ? '' : 'none';
                w.setAttribute('aria-hidden', isTarget ? 'false' : 'true');
            });
        });
    });

    if (typeof jQuery !== 'undefined') {
        jQuery(document).on('click', '.scenario-card', function () {
            const $card = jQuery(this);
            if ($card.hasClass('is-loading')) return;
            const scenarioId = $card.data('scenario-id');
            const campaignId = window.twGameState?.currentCampaignId || 1;
            if (!scenarioId) { console.warn('[NeoWeaver] .scenario-card missing data-scenario-id'); return; }
            $card.addClass('is-loading').text('⏳ Generating...');
            jQuery.post(ajaxurl, {
                action: 'tw_start_scenario_generation',
                scenario_id: scenarioId,
                campaign_id: campaignId
            })
            .done(() => { setTimeout(() => location.reload(), 1500); })
            .fail((xhr, status, error) => {
                console.error('[NeoWeaver] Scenario generation failed:', status, error);
                $card.removeClass('is-loading').text('⚠️ Error – try again');
            });
        });
    }
});
