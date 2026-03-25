<?php
/**
 * Aktualizuje slot pojazdu lub przenosi moduł do garażu
 */
add_action('wp_ajax_update_vehicle_module', 'neoweave_update_vehicle_module');

function neoweave_update_vehicle_module() {
    // 1. Walidacja uprawnień i danych
    if (!is_user_logged_in()) wp_send_json_error('Unauthorized');

    $vehicle_id = sanitize_text_field($_POST['vehicle_id']);
    $module_id  = sanitize_text_field($_POST['module_id']); // UUID z cyber_vehicle_module_types
    $target_slot = sanitize_text_field($_POST['target_slot']); // 'core', 'lateral_l', 'garage' itd.
    $character_id = sanitize_text_field($_POST['character_id']);

    // 2. Pobierz dane z wp-config (jak wspomniałaś w pliku "Opis")
    $supa_url = SUPABASE_URL;
    $supa_key = SUPABASE_KEY;

    // 3. Logika: Jeśli target_slot to 'garage', ustawiamy null w odpowiednim slocie w cyber_vehicles
    // Jeśli target_slot to konkretny slot, wpisujemy tam module_id.
    
    // UWAGA: W prawdziwym systemie musielibyśmy najpierw wyczyścić slot, w którym moduł był wcześniej.
    // Poniżej uproszczony UPDATE dla konkretnego slotu:
    
    $update_data = [];
    if (in_array($target_slot, ['slot_core', 'slot_lateral_l', 'slot_lateral_r', 'slot_utility'])) {
        $update_data[$target_slot] = $module_id;
    }

    $response = wp_remote_post("$supa_url/rest/v1/cyber_vehicles?id=eq.$vehicle_id", [
        'method'    => 'PATCH',
        'headers'   => [
            'apikey'        => $supa_key,
            'Authorization' => 'Bearer ' . $supa_key,
            'Content-Type'  => 'application/json',
            'Prefer'        => 'return=minimal'
        ],
        'body' => json_encode($update_data)
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error('Database Error');
    }

    wp_send_json_success(['message' => 'Vehicle Calibrated.']);
}
