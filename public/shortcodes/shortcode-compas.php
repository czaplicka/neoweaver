<?php
/**
 * Shortcode: [tw_compass]
 * Renderuje interaktywny kompas pobierajacy dane z cyber_world_map.
 * Skrypt JS jest enqueue'owany tylko na stronie adventure.php.
 */
add_shortcode('tw_compass', 'tw_compass_render');

function tw_compass_render() {
    $wp_user_id = get_current_user_id();
    if (!$wp_user_id) return '';

    // Bug 3 fix: only enqueue the compass script on the adventure page
    if (!is_page('adventure')) return '';

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

    <style>
        .tw-compass-wrapper {
            background: rgba(10, 15, 10, 0.85);
            border: 1px solid #adff00;
            padding: 15px;
            border-radius: 50%;
            width: 260px;
            height: 260px;
            margin: 20px auto;
            font-family: 'Chakra Petch', sans-serif;
            color: #fff;
            position: relative;
            box-shadow: 0 0 20px rgba(173, 255, 0, 0.15), inset 0 0 15px rgba(173, 255, 0, 0.05);
            border-style: double;
        }
        .tw-compass-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            grid-template-rows: 1fr 1fr 1fr;
            height: 100%;
            width: 100%;
            text-align: center;
        }
        .tw-compass-cell {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            opacity: 0.3;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .tw-compass-cell.active {
            opacity: 1;
            color: #adff00;
            text-shadow: 0 0 8px rgba(173, 255, 0, 0.6);
        }
        .tw-compass-cell .dir-label { font-weight: 700; font-size: 1.1rem; margin-bottom: 2px; }
        .tw-compass-cell .loc-name { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 1px; max-width: 70px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        .tw-n { grid-column: 2; grid-row: 1; }
        .tw-w { grid-column: 1; grid-row: 2; }
        .tw-e { grid-column: 3; grid-row: 2; }
        .tw-s { grid-column: 2; grid-row: 3; }

        .tw-compass-center {
            grid-column: 2; grid-row: 2;
            display: flex; flex-direction: column;
            justify-content: center; align-items: center;
            border: 1px solid rgba(173, 255, 0, 0.5);
            border-radius: 50%;
            background: radial-gradient(circle, rgba(173, 255, 0, 0.1) 0%, transparent 70%);
            z-index: 2;
        }
        .tw-compass-icon { font-size: 1.8rem; color: #adff00; animation: pulse 3s infinite; }
        #tw-current-loc-name { font-size: 0.75rem; font-weight: bold; color: #adff00; padding: 0 5px; }

        @keyframes pulse {
            0% { transform: scale(1); opacity: 0.8; }
            50% { transform: scale(1.1); opacity: 1; }
            100% { transform: scale(1); opacity: 0.8; }
        }
    </style>
<script>
/**
 * Compass Logic - NeoWeaver
 * Runs only on the adventure page (server-side guard above ensures this).
 */
document.addEventListener('twGameStateHydrated', function() {
    console.log('Compass: Game State ready, refreshing...');
    refreshCompass();
});

async function refreshCompass() {
    const client = window.twSupabase;
    const session_id = window.twGameState?.currentSessionId;

    if (!client || !session_id) {
        console.warn('Compass: Missing client or session ID');
        return;
    }

    try {
        // 1. Fetch location_id from active session
        const { data: sessionData, error: sError } = await client
            .from('cyber_game_sessions')
            .select('location_id')
            .eq('id', session_id)
            .single();

        if (sError || !sessionData?.location_id) {
            document.getElementById('tw-current-loc-name').innerText = "Unknown Zone";
            return;
        }

        // 2. Fetch node details and neighbours from v_cyber_world_nodes view
        const { data: node, error: nError } = await client
            .from('v_cyber_world_nodes')
            .select('location_name, n_id, e_id, s_id, w_id')
            .eq('id', sessionData.location_id)
            .single();

        if (nError || !node) return;

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
                    neighborMap[n.id] = n.is_discovered ? n.location_name : "???";
                });
            }
        }

        // 3. Map and update compass cells
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

            if (dir.id && neighborMap[dir.id]) {
                cell.classList.add('active');
                label.innerText = neighborMap[dir.id];
            } else {
                cell.classList.remove('active');
                label.innerText = "Block";
            }
        });

    } catch (err) {
        console.error('Compass Error:', err);
    }
}

document.addEventListener('DOMContentLoaded', function () {
    setTimeout(refreshCompass, 1000);
});
</script>
    <?php
    return ob_get_clean();
}
