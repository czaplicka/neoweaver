<?php
/**
 * adventure-data.php
 * Helper functions for fetching and preparing data for the Adventure template.
 * No HTML, echo, or include — only pure PHP functions returning arrays.
 */

/**
 * Sanitize UUID — never use intval() on UUID.
 */
function tw_sanitize_uuid( string $raw ): string {
    return preg_replace( '/[^a-f0-9\-]/i', '', $raw );
}

/**
 * Default game_data values when Supabase is unavailable.
 */
function tw_game_data_defaults(): array {
    return [
        'active_session_id'   => '',
        'active_campaign_id'  => '',
        'active_character_id' => '',
        'active_world_id'     => '',
        'active_location_id'  => '',
        'char_name'           => 'Unknown',
        'char_tags'           => [],
    ];
}

/**
 * Normalize Supabase nested relation result.
 * PostgREST sometimes returns one object, sometimes an array with one object.
 */
function tw_pick_relation_row( $value ): array {
    if ( is_array( $value ) ) {
        if ( isset( $value[0] ) && is_array( $value[0] ) ) {
            return $value[0];
        }

        $is_assoc = array_keys( $value ) !== range( 0, count( $value ) - 1 );
        if ( $is_assoc ) {
            return $value;
        }
    }

    return [];
}

/**
 * Safely read numeric value.
 */
function tw_num( $value, $default = 0 ) {
    return is_numeric( $value ) ? $value + 0 : $default;
}

/**
 * Fetches and prepares character data for character-card.php.
 * Returns an array with all variables required by the template.
 */
function tw_prepare_character_data( array $game_data ): array {
    $char_id = tw_sanitize_uuid( $game_data['active_character_id'] ?? '' );

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

    /**
     * 1. Core character row.
     * IMPORTANT:
     * - race_id / class_id are correct column names
     * - hp / mp here are treated as max values
     */
    $char_rows = tw_supabase_get(
        'cyber_characters',
        [
            'id'     => 'eq.' . $char_id,
            'select' => 'id,name,race_id,class_id,bio,notes,gold,lvl,body,mind,reflex,spirit,avatar,hp,mp',
            'limit'  => 1,
        ]
    );

    if ( is_array( $char_rows ) && isset( $char_rows[0] ) && is_array( $char_rows[0] ) ) {
        $result['char_data'] = (array) $char_rows[0];
    }

    $result['m_hp'] = max( 1, (int) tw_num( $result['char_data']['hp'] ?? 10, 10 ) );
    $result['m_mp'] = max( 1, (int) tw_num( $result['char_data']['mp'] ?? 10, 10 ) );

    /**
     * 2. HUD state — current HP, MP, satiety, hydration, rest, sync_rate.
     * IMPORTANT:
     * - no hp_max / mp_max here
     * - current values come from campaign state
     */
    $hud_rows = tw_supabase_get(
        'cyber_state_of_the_campaign',
        [
            'character_id' => 'eq.' . $char_id,
            'select'       => 'hp,mp,satiety,hydration,rest,sync_rate',
            'limit'        => 1,
        ]
    );

    if ( is_array( $hud_rows ) && isset( $hud_rows[0] ) && is_array( $hud_rows[0] ) ) {
        $h = (array) $hud_rows[0];

        $result['c_hp']        = max( 0, min( $result['m_hp'], (int) tw_num( $h['hp'] ?? $result['m_hp'], $result['m_hp'] ) ) );
        $result['c_mp']        = max( 0, min( $result['m_mp'], (int) tw_num( $h['mp'] ?? $result['m_mp'], $result['m_mp'] ) ) );
        $result['c_satiety']   = max( 0, min( 100, (int) tw_num( $h['satiety'] ?? 100, 100 ) ) );
        $result['c_hydration'] = max( 0, min( 100, (int) tw_num( $h['hydration'] ?? 100, 100 ) ) );
        $result['c_rest']      = max( 0, min( 100, (int) tw_num( $h['rest'] ?? 100, 100 ) ) );
        $result['sync_p']      = max( 0, min( 100, (int) tw_num( $h['sync_rate'] ?? 100, 100 ) ) );
    } else {
        $result['c_hp'] = $result['m_hp'];
        $result['c_mp'] = $result['m_mp'];
    }

    /**
     * 3. Derived bar widths.
     */
    $result['hp_p'] = $result['m_hp'] > 0
        ? (int) min( 100, round( ( $result['c_hp'] / $result['m_hp'] ) * 100 ) )
        : 0;

    $result['mp_p'] = $result['m_mp'] > 0
        ? (int) min( 100, round( ( $result['c_mp'] / $result['m_mp'] ) * 100 ) )
        : 0;

    /**
     * 4. CSS class helpers.
     */
    if ( $result['hp_p'] > 50 ) {
        $result['hp_class'] = 'hp-green';
    } elseif ( $result['hp_p'] > 25 ) {
        $result['hp_class'] = 'hp-yellow';
    } else {
        $result['hp_class'] = 'hp-red';
    }

    if ( $result['sync_p'] >= 80 ) {
        $result['sync_class'] = 'sync-stable';
    } elseif ( $result['sync_p'] >= 50 ) {
        $result['sync_class'] = 'sync-warning';
    } else {
        $result['sync_class'] = 'sync-critical';
    }

    /**
     * 5a. Skills.
     * Based on schema: cyber_character_skills + cyber_skills.
     */
    $skills_raw = tw_supabase_get(
        'cyber_character_skills',
        [
            'character_id' => 'eq.' . $char_id,
            'select'       => 'id,skill_id,proficiency,source,cyber_skills(id,name,description,category,application,img_url,tags,linked_attributes)',
        ]
    );

    if ( is_array( $skills_raw ) ) {
        foreach ( $skills_raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $skill_info = tw_pick_relation_row( $row['cyber_skills'] ?? [] );

            $result['skills_and_abilities'][] = [
                'entry_type'   => 'skill',
                'id'           => $row['id'] ?? null,
                'skill_id'     => $row['skill_id'] ?? null,
                'proficiency'  => (int) tw_num( $row['proficiency'] ?? 0, 0 ),
                'source'       => $row['source'] ?? '',
                'info'         => $skill_info,
            ];
        }
    }

    /**
     * 5b. Abilities.
     * Based on schema: cyber_characterabilities + cyberabilities.
     */
    $abilities_raw = tw_supabase_get(
        'cyber_characterabilities',
        [
            'characterid' => 'eq.' . $char_id,
            'select'      => 'id,abilityid,source,cyberabilities(id,name,description,abilitytype,source,gmnotes,cost,imgurl,tags)',
        ]
    );

    if ( is_array( $abilities_raw ) ) {
        foreach ( $abilities_raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $ability_info = tw_pick_relation_row( $row['cyberabilities'] ?? [] );

            $result['skills_and_abilities'][] = [
                'entry_type'   => 'ability',
                'id'           => $row['id'] ?? null,
                'ability_id'   => $row['abilityid'] ?? null,
                'source'       => $row['source'] ?? '',
                'info'         => $ability_info,
            ];
        }
    }

    /**
     * 6. Inventory.
     * Based on schema:
     * - cyber_character_inventory
     * - cyber_items
     * - column isequipped, not is_equipped
     */
    $inv_raw = tw_supabase_get(
        'cyber_character_inventory',
        [
            'characterid' => 'eq.' . $char_id,
            'select'      => 'id,quantity,isequipped,equippedslot,customname,activeweaves,engravinglevel,factionalignment,totalslots,maxslots,usedslots,cyber_items(id,name,description,type,tags,slot,powervalue,imgurl,rarity,size,mass,stacklimit,iscontainer)',
        ]
    );

    if ( is_array( $inv_raw ) ) {
        $total_mass  = 0.0;
        $total_power = 0.0;

        foreach ( $inv_raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $item_info = tw_pick_relation_row( $row['cyber_items'] ?? [] );
            $quantity  = max( 1, (int) tw_num( $row['quantity'] ?? 1, 1 ) );
            $mass      = (float) tw_num( $item_info['mass'] ?? 0, 0 );
            $power     = (float) tw_num( $item_info['powervalue'] ?? 0, 0 );

            $result['inventory'][] = [
                'id'               => $row['id'] ?? null,
                'quantity'         => $quantity,
                'is_equipped'      => ! empty( $row['isequipped'] ),
                'equipped_slot'    => $row['equippedslot'] ?? '',
                'custom_name'      => $row['customname'] ?? '',
                'active_weaves'    => $row['activeweaves'] ?? [],
                'engraving_level'  => (int) tw_num( $row['engravinglevel'] ?? 0, 0 ),
                'faction_alignment'=> $row['factionalignment'] ?? '',
                'total_slots'      => (int) tw_num( $row['totalslots'] ?? 1, 1 ),
                'max_slots'        => (int) tw_num( $row['maxslots'] ?? 1, 1 ),
                'used_slots'       => (int) tw_num( $row['usedslots'] ?? 0, 0 ),
                'info'             => $item_info,
            ];

            $total_mass  += $mass * $quantity;
            $total_power += $power * $quantity;
        }

        $result['total_mass']  = round( $total_mass, 2 );
        $result['total_power'] = round( $total_power, 2 );
    }

    /**
     * Mass limit: 30 + body * 4.
     */
    $result['mass_limit'] = 30 + ( (int) tw_num( $result['char_data']['body'] ?? 0, 0 ) * 4 );

    /**
     * 7. Logs — newest first.
     * Based on schema: cyber_logs(log, created_at, char_id, camp_id, ...)
     */
    $logs_raw = tw_supabase_get(
        'cyber_logs',
        [
            'char_id' => 'eq.' . $char_id,
            'select'  => 'id,log,created_at,camp_id,session_id,scenario_id',
            'order'   => 'created_at.desc',
            'limit'   => 20,
        ]
    );

    $result['logs_data'] = is_array( $logs_raw ) ? $logs_raw : [];

    return $result;
}

/**
 * Fetches and prepares tactical map / battle-grid data.
 * Returns an array ready for wp_localize_script and tactical-overlay.php.
 */
function tw_prepare_tactical_data( array $game_data, int $userid ): array {
    $result = [
        'has_enemy' => false,
        'map_data'  => [],
        'grid_map'  => [],
    ];

    $active_session_id = tw_sanitize_uuid( (string) ( $game_data['active_session_id'] ?? '' ) );

    if ( ! $active_session_id ) {
        return $result;
    }

    if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) || ! function_exists( 'tw_get_data' ) ) {
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

    /**
     * Player map view.
     * Kept as-is structurally, but with encoded values.
     */
    $map_rows = tw_get_data(
        $supabase_base . 'v_cyber_map_view?wp_user_id=eq.' . rawurlencode( (string) $userid ) . '&limit=1',
        $auth_headers
    );

    /**
     * Battle grid.
     * Schema names in your dump point to cyber_battle_grid with slot_index and unit_type.
     * Session relation may differ per final view/table, so keep session_id only if that endpoint uses it.
     */
    $grid_units = tw_get_data(
        $supabase_base . 'cyber_battle_grid'
            . '?select=*'
            . '&session_id=eq.' . rawurlencode( $active_session_id ),
        $auth_headers
    );

    $result['map_data'] = ( is_array( $map_rows ) && isset( $map_rows[0] ) && is_array( $map_rows[0] ) )
        ? $map_rows[0]
        : [];

    if ( is_array( $grid_units ) ) {
        foreach ( $grid_units as $u ) {
            if ( ! is_array( $u ) || ! isset( $u['slot_index'], $u['unit_type'] ) ) {
                continue;
            }

            $slot = (int) $u['slot_index'];

            if ( $slot >= 1 && $slot <= 9 ) {
                $result['grid_map'][ $slot ] = $u;

                if ( in_array( $u['unit_type'], [ 'enemy', 'boss' ], true ) ) {
                    $result['has_enemy'] = true;
                }
            }
        }
    }

    return $result;
}
