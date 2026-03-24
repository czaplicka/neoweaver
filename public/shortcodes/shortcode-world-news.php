function get_cyber_world_news_ajax() {
    // 1. Sprawdzenie bezpieczeństwa i parametrów
    $world_id = isset($_POST['world_id']) ? sanitize_text_field($_POST['world_id']) : null;
    $player_clearance = isset($_POST['clearance']) ? intval($_POST['clearance']) : 0;

    if (!$world_id) {
        wp_send_json_error('Missing World ID');
    }

    // 2. Pobranie danych z wp-config (zgodnie z Twoim opisem)
    $supa_url = SUPABASE_URL; // Załóżmy, że masz te stałe zdefiniowane
    $supa_key = SUPABASE_KEY;

    // 3. Budowa zapytania do Supabase
    // Filtry: ten konkretny świat, news aktywny, clearance mniejszy lub równy graczowi
    $url = $supa_url . "/rest/v1/cyber_world_news?world_id=eq." . $world_id . "&is_active=eq.true&clearance_level=lte." . $player_clearance . "&order=created_at.desc";

    $response = wp_remote_get($url, array(
        'headers' => array(
            'apikey'    => $supa_key,
            'Authorization' => 'Bearer ' . $supa_key
        )
    ));

    if (is_wp_error($response)) {
        wp_send_json_error('Connection Error');
    }

    $body = wp_remote_retrieve_body($response);
    wp_send_json(json_decode($body));
}

add_action('wp_ajax_get_cyber_news', 'get_cyber_world_news_ajax');
add_action('wp_ajax_nopriv_get_cyber_news', 'get_cyber_world_news_ajax');
