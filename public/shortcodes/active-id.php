<?php
function id_scripts() {
	wp_enqueue_style(
		'nw-admin-core',
		NEOWEAVER_PLUGIN_URL . 'assets/css/public/active-id.css',
		array( 'nw-font-chakra-petch' ),
		(string) filemtime( NEOWEAVER_PLUGIN_DIR . 'assets/css/public/active-id.css' )
	);
}
add_action( 'admin_enqueue_scripts', 'id_scripts' );

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
    <?php
    return ob_get_clean();
});
