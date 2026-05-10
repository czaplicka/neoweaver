/**
 * Logika Kompasu - Tale Weaver
 */
document.addEventListener('twGameStateHydrated', function() {
    console.log('🧭 Game State ready, refreshing compass...');
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
        // 1. Pobierz location_id z aktywnej sesji
        const { data: sessionData, error: sError } = await client
            .from('cyber_game_sessions')
            .select('location_id')
            .eq('id', session_id)
            .single();

        if (sError || !sessionData?.location_id) {
            document.getElementById('tw-current-loc-name').innerText = "Unknown Zone";
            return;
        }

        // 2. Pobierz detale lokacji i sąsiadów z widoku v_cyber_world_nodes
        const { data: node, error: nError } = await client
            .from('v_cyber_world_nodes')
            .select('location_name, n_id, e_id, s_id, w_id')
            .eq('id', sessionData.location_id)
            .single();

        if (nError || !node) return;

        // Ustaw nazwę aktualnej lokalizacji
        document.getElementById('tw-current-loc-name').innerText = node.location_name;

        // Przygotuj listę ID sąsiadów do pobrania ich nazw jednym zapytaniem (optymalizacja)
        const neighborIds = [node.n_id, node.e_id, node.s_id, node.w_id].filter(id => id !== null);
        
        let neighborMap = {};
        if (neighborIds.length > 0) {
            const { data: names } = await client
                .from('cyber_world_map')
                .select('id, location_name, is_discovered')
                .in('id', neighborIds);
            
            names.forEach(n => {
                neighborMap[n.id] = n.is_discovered ? n.location_name : "???";
            });
        }

        // 3. Mapowanie i aktualizacja komórek kompasu
        const directions = [
            { key: 'n', id: node.n_id },
            { key: 'e', id: node.e_id },
            { key: 's', id: node.s_id },
            { key: 'w', id: node.w_id }
        ];

        directions.forEach(dir => {
            const cell = document.querySelector(`.tw-compass-cell[data-dir="${dir.key}"]`);
            const label = cell.querySelector('.loc-name');

            if (dir.id && neighborMap[dir.id]) {
                cell.classList.add('active');
                label.innerText = neighborMap[dir.id];
            } else {
                cell.classList.remove('active');
                label.innerText = "Block"; // Lub "Void" / "Wall"
            }
        });

    } catch (err) {
        console.error('Compass Error:', err);
    }
}
document.addEventListener('DOMContentLoaded', function () {
  setTimeout(refreshCompass, 1000);
});
