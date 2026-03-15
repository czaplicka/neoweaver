<?php
/**
 * Shortcode: [tw_compass]
 * Renderuje interaktywny kompas pobierajacy dane z cyber_world_map.
 * Style sa enqueue'owane z neoweaver-compass.css (Opt 2).
 * Skrypt JS odpala sie tylko na stronie adventure.php (Bug 3).
 */
add_shortcode('tw_compass', 'tw_compass_render');

function tw_compass_render() {
    $wp_user_id = get_current_user_id();
    if (!$wp_user_id) return '';

    // Bug 3: only render on the adventure page template
    if (!is_page_template('template/adventure.php')) return '';

    // Opt 2: enqueue styles once via WordPress, not inline on every render
    if (!wp_style_is('neoweaver-compass', 'enqueued')) {
        wp_enqueue_style(
            'neoweaver-compass',
            plugin_dir_url(__FILE__) . '../assets/css/neoweaver-compass.css',
            [],
            '1.0.0'
        );
    }

    ob_start();
    ?>
    <div id="tw-compass-container" class="tw-compass-wrapper">
        <div class="tw-compass-grid">
            <div class="tw-compass-cell tw-n" data-dir="n">
                <span class="dir-label">N</span>
                <div class="loc-name">-</div>
            </div>

            <div class="tw-compass-cell tw-w" data-dir="w">
                <span class="dir-label">W</span>
                <div class="loc-name">-</div>
            </div>

            <div class="tw-compass-center">
                <div class="tw-compass-icon">&#x27E1;</div>
                <div id="tw-current-loc-name">Scanning...</div>
            </div>

            <div class="tw-compass-cell tw-e" data-dir="e">
                <span class="dir-label">E</span>
                <div class="loc-name">-</div>
            </div>

            <div class="tw-compass-cell tw-s" data-dir="s">
                <span class="dir-label">S</span>
                <div class="loc-name">-</div>
            </div>
        </div>
    </div>

<script>
/**
 * Compass Logic - NeoWeaver
 * Wrapped in IIFE (Bug 4). Uses compassLoaded flag (Bug 5).
 * Split active/undiscovered states (Bug 6).
 * Reads location_id from twGameState (Opt 1).
 * Styles moved to neoweaver-compass.css (Opt 2).
 * Shows 'Awaiting sync...' when game state not ready (Opt 3).
 */
(function () {

    let compassLoaded = false;

    function onCompassReady() {
        if (compassLoaded) return;
        compassLoaded = true;
        refreshCompass();
    }

    async function refreshCompass() {
        const client = window.twSupabase;
        const location_id = window.twGameState?.currentLocationId;

        if (!client || !location_id) {
            // Opt 3: surface state to player instead of silent 'Scanning...'
            const label = document.getElementById('tw-current-loc-name');
            if (label) label.innerText = 'Awaiting sync...';
            console.warn('Compass: Missing Supabase client or location ID');
            return;
        }

        try {
            // 1. Fetch node details and neighbours from v_cyber_world_nodes view
            const { data: node, error: nError } = await client
                .from('v_cyber_world_nodes')
                .select('location_name, n_id, e_id, s_id, w_id')
                .eq('id', location_id)
                .single();

            if (nError || !node) {
                console.error('Compass: Failed to fetch node', nError);
                return;
            }

            document.getElementById('tw-current-loc-name').innerText = node.location_name;

            // Build neighbour ID list for a single batched query
            const neighborIds = [node.n_id, node.e_id, node.s_id, node.w_id].filter(id => id !== null);

            let neighborMap = {};
            if (neighborIds.length > 0) {
                const { data: names, error: namesError } = await client
                    .from('cyber_world_map')
                    .select('id, location_name, is_discovered')
                    .in('id', neighborIds);

                if (namesError) {
                    console.error('Compass: Failed to fetch neighbour names', namesError);
                } else if (Array.isArray(names)) {
                    names.forEach(n => {
                        neighborMap[n.id] = n.is_discovered ? n.location_name : '???';
                    });
                }
            }

            // 2. Map and update compass cells
            const directions = [
                { key: 'n', id: node.n_id },
                { key: 'e', id: node.e_id },
                { key: 's', id: node.s_id },
                { key: 'w', id: node.w_id }
            ];

            directions.forEach(dir => {
                const cell = document.querySelector(`.tw-compass-cell[data-dir="${dir.key}"]`);
                if (!cell) return;
                const label = cell.querySelector('.loc-name');
                const name = dir.id ? neighborMap[dir.id] : null;

                cell.classList.toggle('active',       !!name && name !== '???');
                cell.classList.toggle('undiscovered', !!name && name === '???');
                label.innerText = name ?? 'Block';
            });

        } catch (err) {
            console.error('Compass Error:', err);
        }
    }

    // Primary trigger: fires when game state is fully hydrated
    document.addEventListener('twGameStateHydrated', onCompassReady);

    // Fallback: in case twGameStateHydrated never fires (e.g. auth delay, slow init)
    document.addEventListener('DOMContentLoaded', function () {
        setTimeout(onCompassReady, 1500);
    });

})();
</script>
    <?php
    return ob_get_clean();
}
