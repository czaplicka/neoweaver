<?php
/**
 * NeoWeave - AJAX & RPC Buffer Handlers
 */

// --- 1. SYNCHRONIZACJA TALII (DECK BUILDER) ---
add_action('wp_ajax_save_cyber_deck_rpc', 'handle_save_cyber_deck_rpc');
function handle_save_cyber_deck_rpc() {
    check_ajax_referer('cyber_deck_nonce', 'nonce');
    
    $user_id = get_current_user_id();
    $character_id = get_cyber_character_id_by_wp_id($user_id); 
    $active_ids = json_decode(stripslashes($_POST['active_ids']), true);

    if (!$character_id || !is_array($active_ids)) {
        wp_send_json_error('Invalid character or data.');
    }

    $response = cyber_call_rpc('cyber_sync_deck', [
        'p_character_id' => $character_id,
        'p_active_ids'   => $active_ids
    ]);

    wp_send_json_success(json_decode($response));
}

// --- 2. UŻYCIE KARTY I DOCIĄGNIĘCIE NOWEJ (HUD/SLIDER) ---
add_action('wp_ajax_use_buffer_card', 'handle_use_buffer_card');
function handle_use_buffer_card() {
    check_ajax_referer('use_card_nonce', 'nonce');
    
    $instance_id = sanitize_text_field($_POST['instance_id']);
    $user_id = get_current_user_id();
    $character_id = get_cyber_character_id_by_wp_id($user_id);

    // 1. Oznacz kartę jako zużytą (discard) przez PATCH
    cyber_update_supabase_location($instance_id, 'discard', SUPABASE_URL, SUPABASE_KEY);

    // 2. Wywołaj RPC, żeby dobrać nową i pobrać jej dane (z obsługą Reshuffle w SQL)
    $response = cyber_call_rpc('cyber_sync_draw', ['p_character_id' => $character_id]);
    $new_card_data = json_decode($response, true);

    if (!empty($new_card_data)) {
        wp_send_json_success($new_card_data[0]); 
    } else {
        wp_send_json_error('No cards left to draw even after reshuffle.');
    }
}

// --- 3. ULEPSZANIE KART (THE FOUNDRY) ---
add_action('wp_ajax_foundry_upgrade', 'handle_foundry_upgrade');
function handle_foundry_upgrade() {
    check_ajax_referer('foundry_nonce', 'nonce');
    
    $instance_id = sanitize_text_field($_POST['instance_id']);
    $user_id = get_current_user_id();
    $character_id = get_cyber_character_id_by_wp_id($user_id); 

    if (!$character_id) wp_send_json_error('Character not found.');

    $response = cyber_call_rpc('cyber_upgrade_buffer_card', [
        'p_character_id' => $character_id,
        'p_instance_id'  => $instance_id
    ]);

    $data = json_decode($response);

    if (isset($data->status) && $data->status === 'success') {
        wp_send_json_success([
            'message' => $data->message,
            'new_level' => $data->new_level
        ]);
    } else {
        wp_send_json_error($data->message ?? 'Upgrade failed.');
    }
}

// --- 4. FUNKCJE POMOCNICZE (SUPABASE CONNECTORS) ---

/**
 * Uniwersalny silnik do wywoływania funkcji RPC w Supabase
 */
function cyber_call_rpc($function_name, $params = []) {
    $endpoint = SUPABASE_URL . "/rest/v1/rpc/" . $function_name;
    $payload = json_encode($params);

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY
    ]);

    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

/**
 * Szybki update lokalizacji karty (PATCH)
 */
function cyber_update_supabase_location($instance_id, $location, $url, $key) {
    $endpoint = $url . "/rest/v1/cyber_character_buffer?id=eq." . $instance_id;
    $payload = json_encode(['location' => $location]);

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Prefer: return=minimal'
    ]);

    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}
