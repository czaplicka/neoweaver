<?php
add_shortcode('ACTIVE_ID', function($atts) {
    // 1. Konfiguracja Supabase (pobierana z wp-config.php zgodnie z Twoim opisem)
    $supabase_url = SUPABASE_URL; 
    $supabase_key = SUPABASE_API_KEY; // Service Role lub Anon Key

    $user_id = get_current_user_id();
    if (!$user_id) return 'AUTH_REQUIRED';

    // Pobieramy ID postaci (zakładam, że przechowujesz UUID z Supabase w usermeta WP)
    $character_uuid = get_user_meta($user_id, 'active_character_id', true);
    if (!$character_uuid) return 'NO_AGENT_CONNECTED';

    // 2. Zapytanie do Supabase: Pobieramy postać wraz z informacją o Kingdom
    // Wykorzystujemy "Select Embedding", aby jednym strzałem dostać się do Kingdom przez Area
    $url = $supabase_url . '/rest/v1/cyber_characters?' . http_build_query([
        'id' => 'eq.' . $character_uuid,
        'select' => 'world_credentials,current_location_id,cyber_locations(area_id,cyber_areas(kingdom_id,cyber_kingdoms(name)))'
    ]);

    $response = wp_remote_get($url, [
        'headers' => [
            'apikey' => $supabase_key,
            'Authorization' => 'Bearer ' . $supabase_key,
            'Content-Type' => 'application/json'
        ]
    ]);

    if (is_wp_error($response)) return 'CONNECTION_ERROR';

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($data)) return 'AGENT_NOT_FOUND';

    $char_data = $data[0];
    
    // Wyciąganie ID królestwa z zagnieżdżonej struktury
    $location = $char_data['cyber_locations'] ?? null;
    $area = $location['cyber_areas'] ?? null;
    $kingdom = $area['cyber_kingdoms'] ?? null;
    
    $kingdom_id = $area['kingdom_id'] ?? null;
    $kingdom_name = $kingdom['name'] ?? 'UNKNOWN_NODE';

    // 3. Sprawdzanie statusu w world_credentials (JSONB)
    $credentials = $char_data['world_credentials'] ?? [];
    
    // Domyślny status to CITIZEN w państwie startowym (lub każdym, gdzie nie ma wpisu)
    $status = 'CITIZEN';
    if ($kingdom_id && isset($credentials[$kingdom_id])) {
        $status = $credentials[$kingdom_id];
    }

    // 4. Renderowanie HTML (Analog Cyberpunk Style)
    ob_start();
    ?>
    <div id="neoweave-active-id" class="id-chit-terminal" data-kingdom="<?php echo esc_attr($kingdom_id); ?>">
        <div class="terminal-header">SCANNING_IDENTITY...</div>
        <div class="id-grid">
            <div class="id-row">
                <span class="id-label">NODE:</span>
                <span class="id-value"><?php echo esc_html(strtoupper($kingdom_name)); ?></span>
            </div>
            <div class="id-row">
                <span class="id-label">STATUS:</span>
                <span class="id-value status-<?php echo strtolower($status); ?>">
                    <?php echo esc_html(strtoupper($status)); ?>
                </span>
            </div>
        </div>
        <div class="id-flicker"></div>
    </div>

    <style>
        .id-chit-terminal {
            background: #050505;
            border: 1px solid #adff00;
            padding: 8px;
            font-family: 'Chakra Petch', sans-serif;
            color: #adff00;
            max-width: 250px;
            position: relative;
            box-shadow: 0 0 10px rgba(173, 255, 0, 0.2);
        }
        .terminal-header { font-size: 0.6em; margin-bottom: 5px; opacity: 0.6; }
        .id-grid { display: grid; gap: 4px; }
        .id-label { font-size: 0.7em; color: rgba(173, 255, 0, 0.5); margin-right: 10px; }
        .id-value { font-weight: bold; letter-spacing: 1px; }
        
        /* Statusy */
        .status-wanted { color: #ff003c; text-shadow: 0 0 8px #ff003c; }
        .status-citizen { color: #adff00; }
        .status-service { color: #00e5ff; }
        .status-foreigner { color: #e5e5e5; }

        .id-flicker {
            position: absolute; top:0; left:0; width:100%; height:100%;
            background: repeating-linear-gradient(0deg, transparent, transparent 1px, rgba(173, 255, 0, 0.03) 2px);
            pointer-events: none;
        }
    </style>
    <?php
    return ob_get_clean();
});
