function shortcode_neoweave_my_world_archive($atts) {
    // 1. Pobieramy ID aktualnie zalogowanego użytkownika WordPress
    $current_user_id = get_current_user_id();
    if (!$current_user_id) return "[ACCESS_DENIED]: No Operator signature detected.";

    // 2. Pobieramy world_id (z parametru shortcode lub z adresu URL)
    $a = shortcode_atts(['world' => ''], $atts);
    $world_id = !empty($a['world']) ? $a['world'] : (isset($_GET['world_id']) ? $_GET['world_id'] : null);

    if (!$world_id) return "[DATA_ERR]: World Node ID is missing.";

    $supa_url = defined('SUPABASE_URL') ? SUPABASE_URL : '';
    $supa_key = defined('SUPABASE_KEY') ? SUPABASE_KEY : '';

    // 3. Zapytanie: Moje postacie (wp_user_id), w tym świecie (world_id), które zginęły (DEAD)
    $query_params = http_build_query([
        'select' => 'name,notes,created_at,lvl',
        'wp_user_id' => 'eq.' . $current_user_id,
        'world_id' => 'eq.' . $world_id,
        'status' => 'eq.DEAD'
    ]);

    $query_url = $supa_url . "/rest/v1/cyber_characters?" . $query_params;

    $response = wp_remote_get($query_url, [
        'headers' => [
            'apikey' => $supa_key,
            'Authorization' => 'Bearer ' . $supa_key
        ]
    ]);

    if (is_wp_error($response)) return "[SIGNAL_FAILURE]: Unable to sync with Supabase.";

    $my_dead_agents = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($my_dead_agents)) {
        return "<div style='font-family: \"Chakra Petch\"; color: #adff00; opacity: 0.5;'>[ARCHIVE_EMPTY]: No personal casualties recorded in this Node.</div>";
    }

    // 4. Widok Terminala Archiwalnego
    $output = '<div class="operator-archive" style="font-family: \'Chakra Petch\', sans-serif; color: #adff00; background: #0b0b0b; border: 1px solid #adff00; padding: 20px; box-shadow: inset 0 0 10px #adff0033;">';
    $output .= '<h2 style="margin-top: 0; border-bottom: 1px solid #adff00; font-size: 1.2em;">> PERSONAL_DEATH_LOGS // NODE: ' . esc_html(substr($world_id, 0, 8)) . '</h2>';

    foreach ($my_dead_agents as $agent) {
        $timestamp = date("Y-m-d H:i", strtotime($agent['created_at']));
        
        $output .= '<div class="archive-entry" style="margin-top: 20px; padding: 10px; border-left: 2px solid #adff00; background: rgba(173, 255, 0, 0.03);">';
        $output .= '<div style="font-weight: bold; font-size: 0.9em; margin-bottom: 5px;">';
        $output .= 'AGENT: ' . esc_html($agent['name']) . ' | LVL: ' . esc_html($agent['lvl']) . ' | TERMINATED: ' . $timestamp;
        $output .= '</div>';
        
        $output .= '<div class="archive-notes" style="color: #ffffff; white-space: pre-wrap; font-size: 0.95em; line-height: 1.5;">';
        $output .= !empty($agent['notes']) ? esc_html($agent['notes']) : '[NO_LAST_WORDS_RECORDED]';
        $output .= '</div>';
        $output .= '</div>';
    }

    $output .= '<div style="margin-top: 20px; font-size: 0.7em; text-align: center; opacity: 0.4;">--- END OF ARCHIVE ENTRANCE ---</div>';
    $output .= '</div>';

    return $output;
}
add_shortcode('neoweave_my_world_archive', 'shortcode_neoweave_my_world_archive');
