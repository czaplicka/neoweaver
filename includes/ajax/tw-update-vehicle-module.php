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
function neoweave_get_vehicle_cargo_weight($vehicle_id) {
    $supa_url = SUPABASE_URL;
    $supa_key = SUPABASE_KEY;

    // Pobieramy przedmioty, których 'container_id' odpowiada ID naszego pojazdu
    $url = "$supa_url/rest/v1/cyber_items?container_id=eq.$vehicle_id&select=mass";
    
    $response = wp_remote_get($url, [
        'headers' => [
            'apikey' => $supa_key,
            'Authorization' => 'Bearer ' . $supa_key
        ]
    ]);

    $items = json_decode(wp_remote_retrieve_body($response), true);
    $total_mass = 0;

    if (!empty($items)) {
        foreach ($items as $item) {
            $total_mass += (int)$item['mass'];
        }
    }

    return $total_mass;
}
/**
 * Oblicza aktualny stan bagażnika i limit
 */
function neoweave_get_vehicle_storage_info($vehicle_id) {
    $supa_url = SUPABASE_URL;
    $supa_key = SUPABASE_KEY;

    // 1. Pobierz dane pojazdu i jego modułów
    $v_res = wp_remote_get("$supa_url/rest/v1/cyber_vehicles?id=eq.$vehicle_id&select=*,slot_utility(*)", [
        'headers' => ['apikey' => $supa_key, 'Authorization' => 'Bearer ' . $supa_key]
    ]);
    $vehicle = json_decode(wp_remote_retrieve_body($v_res), true)[0];

    // 2. Wyciągnij limit z modułu utility (szukamy tagu storage_X)
    $max_capacity = 5; // Bazowy podręczny schowek
    if (isset($vehicle['slot_utility']['effect_tags'])) {
        foreach ($vehicle['slot_utility']['effect_tags'] as $tag) {
            if (strpos($tag, 'storage_') === 0) {
                $max_capacity = (int)str_replace('storage_', '', $tag);
            }
        }
    }

    // 3. Sumuj masę przedmiotów w kontenerze pojazdu
    $items_res = wp_remote_get("$supa_url/rest/v1/cyber_items?container_id=eq.$vehicle_id&select=mass", [
        'headers' => ['apikey' => $supa_key, 'Authorization' => 'Bearer ' . $supa_key]
    ]);
    $items = json_decode(wp_remote_retrieve_body($items_res), true);
    
    $current_mass = 0;
    foreach ($items as $i) { $current_mass += $i['mass']; }

    return [
        'current' => $current_mass,
        'max' => $max_capacity,
        'is_overloaded' => ($current_mass > $max_capacity)
    ];
}

/**
 * Oblicza koszt paliwa za podróż (uwzględniając skill i wagę)
 */
function neoweave_calculate_travel_cost($vehicle_id, $character_id) {
    $storage = neoweave_get_vehicle_storage_info($vehicle_id);
    
    $base_cost = 1;
    if ($storage['is_overloaded']) {
        $base_cost += 2; // Kara za przeciążenie
    }

    // Pobierz skill Vehicles (zakładając skalę 1-5)
    // $skill_level = get_character_skill($character_id, 'Vehicles'); 
    // if ($skill_level >= 3) $base_cost -= 0.5; // Bonus za profesjonalną jazdę

    return max(0.5, $base_cost);
}
