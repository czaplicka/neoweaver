<?php
// Obsługa dla zalogowanych użytkowników
add_action('wp_ajax_save_cyber_deck', 'handle_save_cyber_deck');

function handle_save_cyber_deck() {
    // 1. Weryfikacja bezpieczeństwa (Nonce i Login)
    check_ajax_referer('cyber_deck_nonce', 'nonce');
    
    $user_id = get_current_user_id();
    if (!$user_id) {
        wp_send_json_error('Session expired. Re-log required.');
    }

    // 2. Pobranie danych
    $active_ids = json_decode(stripslashes($_POST['active_ids']), true);
    $library_ids = json_decode(stripslashes($_POST['library_ids']), true);

    if (!is_array($active_ids) || !is_array($library_ids)) {
        wp_send_json_error('Invalid data structure.');
    }

    // 3. Połączenie z Supabase (Przykład użycia cURL, jeśli nie masz gotowej klasy)
    $supabase_url = defined('SUPABASE_URL') ? SUPABASE_URL : '';
    $supabase_key = defined('SUPABASE_KEY') ? SUPABASE_KEY : '';

    if (empty($supabase_url) || empty($supabase_key)) {
        wp_send_json_error('Server configuration error: Supabase credentials missing.');
    }

    // Aktualizacja kart w Active Deck -> ustawiamy na 'pile' (talia do gry)
    foreach ($active_ids as $instance_id) {
        cyber_update_supabase_location($instance_id, 'pile', $supabase_url, $supabase_key);
    }

    // Aktualizacja kart w Library -> ustawiamy na 'library' (spoczynek)
    foreach ($library_ids as $instance_id) {
        cyber_update_supabase_location($instance_id, 'library', $supabase_url, $supabase_key);
    }

    wp_send_json_success('Terminal updated.');
}

// Funkcja pomocnicza do wysyłania PATCH do Supabase
function cyber_update_supabase_location($instance_id, $location, $url, $key) {
    $endpoint = $url . "/rest/v1/cyber_character_buffer?id=eq." . $instance_id;
    
    $data = array('location' => $location);
    $payload = json_encode($data);

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Prefer: return=minimal'
    ));

    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}
