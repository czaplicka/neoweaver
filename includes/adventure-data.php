<?php
/**
 * adventure-data.php
 * Funkcje pomocnicze do pobierania i przygotowania danych dla szablonu Adventure.
 * Nie zawiera HTML, echo ani include — tylko czyste funkcje PHP zwracające tablice.
 */

/**
 * Sanityzacja UUID — nigdy nie używaj intval() na UUID.
 */
function tw_sanitize_uuid( string $raw ): string {
    return preg_replace( '/[^a-f0-9\-]/i', '', $raw );
}

/**
 * Domyślne wartości game_data gdy Supabase niedostępny.
 */
function tw_game_data_defaults(): array {
    return [
        'active_session_id'   => '',
        'active_campaign_id'  => '',
        'active_character_id' => '',
        'active_world_id'     => '',
        'active_location_id'  => 0,
        'char_name'           => 'Unknown',
        'char_tags'           => [],
    ];
}

/**
 * Pobiera i przygotowuje dane postaci do character-card.php.
 * Zwraca tablicę ze wszystkimi zmiennymi, które szablon potrzebuje.
 */
function tw_prepare_character_data( array $game_data ): array {
    $char_id = tw_sanitize_uuid( $game_data['active_character_id'] ?? '' );

    // Wartości domyślne
    $result = [
        'char_id'              => $char_id,
        'char_data'            => [],
        'c_hp'                 => 10,
        'm_hp'                 => 10,
        'c_mp'                 => 10,
        'm_mp'                 => 10,
        'c_satiety'            => 100,
        'c_hydration'          => 100,
        'c_rest'               => 100,
        'sync_p'               => 100,
        'hp_p'                 => 100,
        'mp_p'                 => 100,
        'hp_class'             => 'hp-green',
        'sync_class'           => 'sync-stable',
        'skills_and_abilities' => [],
        'inventory'            => [],
        'logs_data'            => [],
        'total_mass'           => 0,
        'mass_limit'           => 50,
        'total_power'          => 0,
    ];

    if ( ! $char_id || ! function_exists( 'tw_supabase_get' ) ) {
        return $result;
    }

    // 1. Core character row — name, race, class, bio, notes, gold, lvl, stats.
    $char_rows = tw_supabase_get(
        'cyber_characters',
        [
            'id'     => 'eq.' . $char_id,
            'select' => 'name,race,class,bio,notes,gold,lvl,body,mind,reflex,spirit,avatar',
            'limit'  => 1,
        ]
    );
    $result['char_data'] = ( is_array( $char_rows ) && isset( $char_rows[0] ) )
        ? (array) $char_rows[0]
        : [];

    // 2. HUD state — HP, MP, satiety, hydration, rest, sync_rate.
    $hud_rows = tw_supabase_get(
        'cyber_state_of_the_campaign',
        [
            'character_id' => 'eq.' . $char_id,
            'select'       => 'hp,hp_max,mp,mp_max,satiety,hydration,rest,sync_rate',
            'limit'        => 1,
        ]
    );
    if ( is_array( $hud_rows ) && isset( $hud_rows[0] ) ) {
        $h = (array) $hud_rows[0];
        $result['c_hp']        = max( 0, (int) ( $h['hp']        ?? 10 ) );
        $result['m_hp']        = max( 1, (int) ( $h['hp_max']    ?? 10 ) );
        $result['c_mp']        = max( 0, (int) ( $h['mp']        ?? 10 ) );
        $result['m_mp']        = max( 1, (int) ( $h['mp_max']    ?? 10 ) );
        $result['c_satiety']   = max( 0, min( 100, (int) ( $h['satiety']   ?? 100 ) ) );
        $result['c_hydration'] = max( 0, min( 100, (int) ( $h['hydration'] ?? 100 ) ) );
        $result['c_rest']      = max( 0, min( 100, (int) ( $h['rest']      ?? 100 ) ) );
        $result['sync_p']      = max( 0, min( 100, (int) ( $h['sync_rate'] ?? 100 ) ) );
    }

    // 3. Derived bar widths (clamped 0–100).
    $result['hp_p'] = $result['m_hp'] > 0
        ? (int) min( 100, round( $result['c_hp'] / $result['m_hp'] * 100 ) )
        : 0;
    $result['mp_p'] = $result['m_mp'] > 0
        ? (int) min( 100, round( $result['c_mp'] / $result['m_mp'] * 100 ) )
        : 0;

    // 4. CSS class helpers.
    if ( $result['hp_p'] > 50 )     { $result['hp_class'] = 'hp-green'; }
    elseif ( $result['hp_p'] > 25 ) { $result['hp_class'] = 'hp-yellow'; }
    else                            { $result['hp_class'] = 'hp-red'; }

    if ( $result['sync_p'] >= 80 )     { $result['sync_class'] = 'sync-stable'; }
    elseif ( $result['sync_p'] >= 50 ) { $result['sync_class'] = 'sync-warning'; }
    else                               { $result['sync_class'] = 'sync-critical'; }

    // 5. Skills & abilities — join with cyber_actions_library for display info.
    $skills_raw = tw_supabase_get(
        'cyber_character_skills',
        [
            'character_id' => 'eq.' . $char_id,
            'select'       => 'id,skill_id,cyber_actions_library(name,description,cost)',
        ]
    );
    if ( is_array( $skills_raw ) ) {
        foreach ( $skills_raw as $row ) {
            $result['skills_and_abilities'][] = [
                'id'   => $row['id']                    ?? null,
                'info' => $row['cyber_actions_library'] ?? null,
            ];
        }
    }

    // 6. Inventory — items with details.
    $inv_raw = tw_supabase_get(
        'cyber_character_inventory',
        [
            'character_id' => 'eq.' . $char_id,
            'select'       => 'id,quantity,is_equipped,cyber_items(name,slot,mass,img_url)',
        ]
    );
    if ( is_array( $inv_raw ) ) {
        $total_mass = 0.0;
        foreach ( $inv_raw as $row ) {
            $item_info = $row['cyber_items'] ?? null;
            $result['inventory'][] = [
                'id'          => $row['id']       ?? null,
                'quantity'    => (int) ( $row['quantity']    ?? 1 ),
                'is_equipped' => ! empty( $row['is_equipped'] ),
                'info'        => $item_info,
            ];
            if ( $item_info && isset( $item_info['mass'] ) ) {
                $total_mass += (float) $item_info['mass'] * (int) ( $row['quantity'] ?? 1 );
            }
        }
        $result['total_mass'] = round( $total_mass, 2 );
    }

    // Mass limit: 30 + body * 4.
    $result['mass_limit'] = 30 + (int) ( $result['char_data']['body'] ?? 0 ) * 4;

    // 7. Logs — most recent 20, newest first.
    $logs_raw = tw_supabase_get(
        'cyber_character_logs',
        [
            'character_id' => 'eq.' . $char_id,
            'select'       => 'log,created_at',
            'order'        => 'created_at.desc',
            'limit'        => 20,
        ]
    );
    $result['logs_data'] = is_array( $logs_raw ) ? $logs_raw : [];

    return $result;
}

/**
 * Pobiera i przygotowuje dane mapy taktycznej i siatki bitwy.
 * Zwraca tablicę gotową do wp_localize_script oraz do tactical-overlay.php.
 */
function tw_prepare_tactical_data( array $game_data, int $userid ): array {
    $result = [
        'has_enemy' => false,
        'map_data'  => [],
        'grid_map'  => [],
    ];

    $active_session_id = (int) ( $game_data['active_session_id'] ?? 0 );

    if ( $active_session_id <= 0 ) {
        return $result;
    }

    if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
        return $result;
    }

    $supabase_base = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
    $anon_key      = tw_supabase_anon_key();
    $auth_headers  = [
        'headers' => [
            'apikey'        => $anon_key,
            'Authorization' => 'Bearer ' . $anon_key,
        ],
        'timeout' => 12,
    ];

    // Mapa lokacji gracza.
    $map_rows = tw_get_data(
        $supabase_base . 'v_cyber_map_view?wp_user_id=eq.' . $userid . '&limit=1',
        $auth_headers
    );

    // Siatka bitwy — tylko slot_index jako klucz.
    $grid_units = tw_get_data(
        $supabase_base . 'cyber_battle_grid'
            . '?select=*'
            . '&session_id=eq.' . rawurlencode( $active_session_id ),
        $auth_headers
    );

    $result['map_data'] = $map_rows[0] ?? [];

    if ( is_array( $grid_units ) ) {
        foreach ( $grid_units as $u ) {
            if ( is_array( $u ) && isset( $u['slot_index'], $u['unit_type'] ) ) {
                $slot = (int) $u['slot_index'];
                if ( $slot >= 1 && $slot <= 9 ) {
                    $result['grid_map'][ $slot ] = $u;
                    if ( $u['unit_type'] === 'enemy' || $u['unit_type'] === 'boss' ) {
                        $result['has_enemy'] = true;
                    }
                }
            }
        }
    }

    return $result;
}
