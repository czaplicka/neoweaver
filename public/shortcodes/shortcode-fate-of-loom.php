if ( ! function_exists( 'tw_loom_of_fate_shortcode' ) ) {
    function tw_loom_of_fate_shortcode() {
        // Pobieramy ID po stronie PHP jako fallback (na wszelki wypadek)
        $char_id = function_exists('tw_get_current_character_id') ? tw_get_current_character_id() : '';

        ob_start(); ?>
        
        <!-- LOOM CONTAINER UI -->
        <div id="tw-loom-container" style="
            background: var(--tw-bg-ghost, rgba(3,7,18,0.2));
            backdrop-filter: blur(var(--tw-blur, 12px));
            border: 1px solid rgba(0,210,255,0.4);
            box-shadow: 
                0 12px 40px rgba(0,0,0,0.8),
                inset 0 0 20px rgba(0,210,255,0.1),
                0 0 24px rgba(0,210,255,0.2);
            padding: 20px;
            border-radius: 20px;
            font-family: 'Chakra Petch', sans-serif;
            max-width: 100%;
            margin: 20px auto;
            position: relative;
            overflow: hidden;
        ">
            <!-- Background Grid Effect -->
            <div style="
                position: absolute;
                inset: 0;
                pointer-events: none;
                opacity: 0.3;
                z-index: 0;
                background-image:
                    linear-gradient(rgba(18,16,16,0) 50%, rgba(0,0,0,0.15) 50%),
                    linear-gradient(90deg, rgba(0,210,255,0.06), rgba(173,255,0,0.02), rgba(0,210,255,0.06));
                background-size: 100% 3px, 6px 100%;
            "></div>
            
            <h2 style="
                color: var(--tw-monitor, #00d2ff);
                text-align: center;
                letter-spacing: 4px;
                margin: 0 0 10px 0;
                font-size: 1.2em;
                font-weight: 800;
                text-shadow: 0 0 16px var(--tw-monitor-glow, rgba(0,210,255,0.6));
                position: relative;
                z-index: 2;
            ">THE LOOM OF FATE</h2>
            
            <div style="
                position: relative;
                width: 260px;
                height: 260px;
                margin: 0 auto 10px;
                z-index: 2;
                left: 10px;
            ">
                <canvas id="fateChart" style="width: 100%; height: 100%;"></canvas>
                
                <div id="label-brutality" style="position: absolute; top: 5px; left: 50%; transform: translateX(-50%); font-size: 9px; font-weight: 700; white-space: nowrap; color: #ff4444; text-shadow: 0 0 5px rgba(0,0,0,0.8);">BRUTALITY <span style="color: #fff; font-size: 12px;">0</span></div>
                <div id="label-cunning" style="position: absolute; top: 75px; right: -5px; font-size: 9px; font-weight: 700; white-space: nowrap; color: #d946ef; text-shadow: 0 0 5px rgba(0,0,0,0.8);">CUNNING <span style="color: #fff; font-size: 12px;">0</span></div>
                <div id="label-intellect" style="position: absolute; bottom: 75px; right: -5px; font-size: 9px; font-weight: 700; white-space: nowrap; color: #00d2ff; text-shadow: 0 0 5px rgba(0,0,0,0.8);">INTELLECT <span style="color: #fff; font-size: 12px;">0</span></div>
                <div id="label-spirit" style="position: absolute; bottom: 75px; left: -15px; font-size: 9px; font-weight: 700; white-space: nowrap; color: #8b5cf6; text-shadow: 0 0 5px rgba(0,0,0,0.8);">SPIRIT <span style="color: #fff; font-size: 12px;">0</span></div>
                <div id="label-presence" style="position: absolute; top: 75px; left: -15px; font-size: 9px; font-weight: 700; white-space: nowrap; color: #adff00; text-shadow: 0 0 5px rgba(0,0,0,0.8);">PRESENCE <span style="color: #fff; font-size: 12px;">0</span></div>
            </div>

            <div id="archetype-container" style="
                text-align: center;
                border-top: 1px solid rgba(0,210,255,0.3);
                padding-top: 12px;
                position: relative;
                z-index: 2;
            ">
                <div id="archetype-name" style="
                    color: var(--tw-monitor, #00d2ff);
                    font-size: 1.3em;
                    font-weight: 800;
                    text-shadow: 0 0 12px var(--tw-monitor-glow, rgba(0,210,255,0.5));
                    letter-spacing: 2px;
                    text-transform: uppercase;
                ">AWAITING SYNC...</div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
        (function() {
            // 1. Odbiór tagów z zewnątrz (np. przy zagrywaniu karty)
            const originalUpdate = window.twUpdatePlayerTags;
            window.twUpdatePlayerTags = function(tags) {
                if (typeof originalUpdate === 'function') originalUpdate(tags);
                console.log("Loom: Tags updated, refreshing chart...");
                // Skoro tagi się zmieniły, system już działa - odświeżamy
                initLoom();
            };

            async function initLoom() {
                const client = window.twSupabase;
                window.twGameState = window.twGameState || {};
                
                // Pobieramy ID z GameState (priorytet) lub PHP (fallback)
                const charId = window.twGameState?.currentCharacterId || "<?php echo $char_id; ?>";

                // Jeśli nadal brak ID, przerywamy (czekamy na sygnał lub przeładowanie)
                if (!client || !charId || charId === '0') {
                    console.log("Loom: Waiting for data/charId...");
                    return; 
                }

                const categories = {
                    brutality: ["Attack", "Fire", "Melee", "Physical", "Lethal", "Grit", "Determination"],
                    cunning: ["Stealth", "Reflex", "Glitch", "Escape", "Thievery", "Ambush"],
                    intellect: ["Technology", "Hacking", "EMP", "Logic", "Analysis", "Crafting"],
                    spirit: ["Magic", "Chaos", "Willpower", "Madness", "Void", "Active"],
                    presence: ["Persuasion", "Diplomacy", "Intimidation", "Social", "Fame"]
                };

                // Pobieramy karty z bazy
                const { data: deckData, error } = await client
                    .from("cyber_character_deck")
                    .select("card_id, cyber_deck(tags)")
                    .eq("character_id", charId);

                if (error) {
                    console.error("Loom Error:", error);
                    // Mimo błędu, spróbuj odświeżyć QA (np. z domyślnym archetypem)
                    triggerQARefresh();
                    return;
                }

                // Analiza tagów
                let stats = { brutality: 0, cunning: 0, intellect: 0, spirit: 0, presence: 0 };

                if (deckData?.length > 0) {
                    deckData.forEach(entry => {
                        const rawTags = entry.cyber_deck?.tags || "";
                        const cleanTags = rawTags.replace(/#/g, '').toLowerCase();
                        
                        Object.keys(categories).forEach(cat => {
                            categories[cat].forEach(keyword => {
                                if (cleanTags.includes(keyword.toLowerCase())) {
                                    stats[cat]++;
                                }
                            });
                        });
                    });
                }

                const hasData = Object.values(stats).some(v => v > 0);
                renderChart(stats, hasData);
            }

            function renderChart(stats, hasData) {
                const canvas = document.getElementById('fateChart');
                if(!canvas) return;
                
                const ctx = canvas.getContext('2d');
                if (window.twLoomChart) window.twLoomChart.destroy();

                const colors = {
                    brutality: '#ff4444',
                    cunning: '#d946ef',
                    intellect: '#00d2ff',
                    spirit: '#8b5cf6',
                    presence: '#adff00'
                };

                window.twLoomChart = new Chart(ctx, {
                    type: 'radar',
                    data: {
                        labels: ['', '', '', '', ''],
                        datasets: [{
                            data: [stats.brutality, stats.cunning, stats.intellect, stats.spirit, stats.presence],
                            backgroundColor: 'rgba(0, 210, 255, 0.12)',
                            borderColor: 'rgba(0, 210, 255, 0.9)',
                            borderWidth: 2,
                            pointBackgroundColor: Object.values(colors),
                            pointBorderColor: 'rgba(255,255,255,0.9)',
                            pointBorderWidth: 1.5,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        aspectRatio: 1,
                        layout: { padding: 10 },
                        scales: {
                            r: {
                                min: 0,
                                max: Math.max(...Object.values(stats), 3),
                                ticks: { display: false },
                                angleLines: { color: 'rgba(0, 210, 255, 0.25)', lineWidth: 1 },
                                grid: { color: 'rgba(0, 210, 255, 0.15)', lineWidth: 1 },
                                pointLabels: { display: false }
                            }
                        },
                        plugins: { legend: { display: false }, tooltip: { enabled: false } },
                        animation: { duration: 1000, easing: 'easeOutQuart' }
                    }
                });

                // Aktualizacja etykiet liczbowych
                document.querySelector('#label-brutality span').innerText = stats.brutality;
                document.querySelector('#label-cunning span').innerText = stats.cunning;
                document.querySelector('#label-intellect span').innerText = stats.intellect;
                document.querySelector('#label-spirit span').innerText = stats.spirit;
                document.querySelector('#label-presence span').innerText = stats.presence;

                // Obliczanie Archetypu
                const sorted = Object.entries(stats).sort((a,b) => b[1] - a[1]);
                const titles = {
                    brutality: "JUGGERNAUT", cunning: "GHOST", 
                    intellect: "ARCHITECT", spirit: "CONDUIT", presence: "ICON"
                };
                
                let activeArchetype = "DEFAULT";
                const nameEl = document.getElementById('archetype-name');

                if (hasData && sorted[0][1] > 0) {
                    activeArchetype = titles[sorted[0][0]];
                    nameEl.innerText = activeArchetype;
                    nameEl.style.color = colors[sorted[0][0]];
                    nameEl.style.textShadow = `0 0 16px ${colors[sorted[0][0]]}`;
                } else {
                    activeArchetype = "DEFAULT";
                    nameEl.innerText = "VOID SOUL";
                    nameEl.style.color = "#94a3b8";
                    nameEl.style.textShadow = "none";
                }

                // Zapisz Archetyp do stanu gry i powiadom Quick Actions
                window.twGameState.currentArchetype = activeArchetype;
                console.log("Loom: Archetype set to", activeArchetype);
                triggerQARefresh();
            }

            function triggerQARefresh() {
                if (typeof window.twLoadQuickActions === 'function') {
                    window.twLoadQuickActions();
                }
            }

            // --- SYNCHRONIZACJA STARTU ---
            // To sprawia, że Loom czeka na Master Bootstrappera
            if (window.twGameReady) {
                initLoom();
            } else {
                document.addEventListener('twGameStateHydrated', initLoom);
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }
    add_shortcode('tw_loom_of_fate', 'tw_loom_of_fate_shortcode');
}