<?php
if ( ! function_exists( 'tw_loom_of_fate_shortcode' ) ) {
    function tw_loom_of_fate_shortcode() {
        if ( ! is_page_template( array(
            'templates/adventure.php',
            'templates/character-public-profile.php'
        ) ) ) {
            return '';
        }

        // Bug fix (1): cast 0 (integer) to '' so JS guard simplifies to !charId
        $char_id = function_exists( 'tw_get_current_character_id' ) ? tw_get_current_character_id() : '';
        $char_id = $char_id ?: '';

        // Bug fix (2): unique suffix per instance so multiple shortcodes on one page don't collide
        $uid = 'loom_' . uniqid();

        // Bug fix (7): Chart.js is enqueued once via wp_enqueue_script('chartjs') in the plugin bootstrap.
        wp_enqueue_script( 'chartjs' );

        ob_start(); ?>

        <!-- LOOM CONTAINER UI -->
        <div id="tw-loom-container-<?php echo esc_attr( $uid ); ?>" class="tw-loom-container">
            <!-- Background Grid Effect -->
            <div class="tw-loom-bg-grid"></div>

            <h2 class="tw-loom-title">THE LOOM OF FATE</h2>

            <div class="tw-loom-chart-wrapper">
                <canvas id="fateChart-<?php echo esc_attr( $uid ); ?>"></canvas>

                <div id="label-brutality-<?php echo esc_attr( $uid ); ?>" class="tw-loom-label tw-loom-label-brutality">
                    BRUTALITY <span>0</span>
                </div>
                <div id="label-cunning-<?php echo esc_attr( $uid ); ?>" class="tw-loom-label tw-loom-label-cunning">
                    CUNNING <span>0</span>
                </div>
                <div id="label-intellect-<?php echo esc_attr( $uid ); ?>" class="tw-loom-label tw-loom-label-intellect">
                    INTELLECT <span>0</span>
                </div>
                <div id="label-spirit-<?php echo esc_attr( $uid ); ?>" class="tw-loom-label tw-loom-label-spirit">
                    SPIRIT <span>0</span>
                </div>
                <div id="label-presence-<?php echo esc_attr( $uid ); ?>" class="tw-loom-label tw-loom-label-presence">
                    PRESENCE <span>0</span>
                </div>
            </div>

            <div id="archetype-container-<?php echo esc_attr( $uid ); ?>" class="tw-loom-archetype-container">
                <div id="archetype-name-<?php echo esc_attr( $uid ); ?>" class="tw-loom-archetype-name">
                    AWAITING SYNC...
                </div>
            </div>
        </div>

        <script>
        (function() {
            const LOOM_UID = "<?php echo esc_js( $uid ); ?>";
            const container = document.getElementById('tw-loom-container-' + LOOM_UID);
            if (!container) return;

            let loomChart = null;

            // Listen for tag updates
            document.addEventListener('twTagsUpdated', function(e) {
                console.log('Loom [' + LOOM_UID + ']: twTagsUpdated received, refreshing chart...');
                initLoom();
            });

            async function initLoom() {
                const client = window.twSupabase;
                window.twGameState = window.twGameState || {};

                // Bug fix (1): esc_js() + empty-string fallback
                const charId = window.twGameState?.currentCharacterId || "<?php echo esc_js( $char_id ); ?>";

                if (!client || !charId) {
                    console.log('Loom [' + LOOM_UID + ']: Waiting for data/charId...');
                    return;
                }

                const categories = {
                    brutality: ["Attack", "Fire", "Melee", "Physical", "Lethal", "Grit", "Determination"],
                    cunning: ["Stealth", "Reflex", "Glitch", "Escape", "Thievery", "Ambush"],
                    intellect: ["Technology", "Hacking", "EMP", "Logic", "Analysis", "Crafting"],
                    spirit: ["Magic", "Chaos", "Willpower", "Madness", "Void", "Active"],
                    presence: ["Persuasion", "Diplomacy", "Intimidation", "Social", "Fame"]
                };

                const { data: deckData, error } = await client
                    .from("cyber_character_deck")
                    .select("card_id, cyber_deck(tags)")
                    .eq("character_id", charId);

                if (error) {
                    console.error('Loom [' + LOOM_UID + '] Error:', error);
                    return;
                }

                let stats = { brutality: 0, cunning: 0, intellect: 0, spirit: 0, presence: 0 };

                if (deckData?.length > 0) {
                    deckData.forEach(entry => {
                        const rawTags = entry.cyber_deck?.tags || "";
                        const cleanTags = rawTags.replace(/#/g, '').toLowerCase();
                        const tagList = cleanTags.split(/[\s,]+/).filter(Boolean);

                        Object.keys(categories).forEach(cat => {
                            categories[cat].forEach(keyword => {
                                const key = keyword.toLowerCase();
                                if (tagList.some(tag => tag === key)) {
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
                const canvas = container.querySelector('#fateChart-' + LOOM_UID);
                if (!canvas) return;

                const ctx = canvas.getContext('2d');
                if (loomChart) loomChart.destroy();

                const colors = {
                    brutality: '#ff4444',
                    cunning: '#d946ef',
                    intellect: '#00d2ff',
                    spirit: '#8b5cf6',
                    presence: '#adff00'
                };

                loomChart = new Chart(ctx, {
                    type: 'radar',
                    data: {
                        labels: ['', '', '', '', ''],
                        datasets: [{
                            data: [
                                stats.brutality,
                                stats.cunning,
                                stats.intellect,
                                stats.spirit,
                                stats.presence
                            ],
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
                                max: Math.min(Math.max(...Object.values(stats), 3), 50),
                                ticks: { display: false },
                                angleLines: { color: 'rgba(0, 210, 255, 0.25)', lineWidth: 1 },
                                grid: { color: 'rgba(0, 210, 255, 0.15)', lineWidth: 1 },
                                pointLabels: { display: false }
                            }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: { enabled: false }
                        },
                        animation: { duration: 1000, easing: 'easeOutQuart' }
                    }
                });

                const setLabel = (id, value) => {
                    const el = container.querySelector('#' + id + '-' + LOOM_UID + ' span');
                    if (el) el.innerText = value;
                };
                setLabel('label-brutality', stats.brutality);
                setLabel('label-cunning',   stats.cunning);
                setLabel('label-intellect', stats.intellect);
                setLabel('label-spirit',    stats.spirit);
                setLabel('label-presence',  stats.presence);

                const sorted = Object.entries(stats).sort((a, b) => b[1] - a[1]);
                const titles = {
                    brutality: "JUGGERNAUT",
                    cunning: "GHOST",
                    intellect: "ARCHITECT",
                    spirit: "CONDUIT",
                    presence: "ICON"
                };

                let activeArchetype = "DEFAULT";
                const nameEl = container.querySelector('#archetype-name-' + LOOM_UID);

                if (hasData && sorted[0][1] > 0) {
                    activeArchetype = titles[sorted[0][0]];
                    if (nameEl) {
                        nameEl.innerText = activeArchetype;
                        nameEl.style.color = colors[sorted[0][0]];
                        nameEl.style.textShadow = `0 0 16px ${colors[sorted[0][0]]}`;
                    }
                } else {
                    activeArchetype = "DEFAULT";
                    if (nameEl) {
                        nameEl.innerText = "VOID SOUL";
                        nameEl.style.color = "#94a3b8";
                        nameEl.style.textShadow = "none";
                    }
                }

                const prevArchetype = window.twGameState.currentArchetype;
                window.twGameState.currentArchetype = activeArchetype;
                if (prevArchetype !== activeArchetype && typeof window.twLoadQuickActions === 'function') {
                    window.twLoadQuickActions();
                }

                console.log('Loom [' + LOOM_UID + ']: Archetype set to', activeArchetype);
            }

            if (window.twGameReady) {
                initLoom();
            } else {
                document.addEventListener('twGameStateHydrated', initLoom, { once: true });
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }
    add_shortcode( 'tw_loom_of_fate', 'tw_loom_of_fate_shortcode' );
}
