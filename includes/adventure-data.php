<?php
/**
 * adventure-data.php
 * Helper functions for fetching and preparing data for the Adventure template.
 * No HTML, echo, or include — only pure PHP functions returning arrays.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
 * Normalize nested PostgREST relation.
 */
function tw_pick_relation_row( $value ): array {
	if ( ! is_array( $value ) ) {
		return [];
	}

	if ( isset( $value[0] ) && is_array( $value[0] ) ) {
		return $value[0];
	}

	$is_assoc = array_keys( $value ) !== range( 0, count( $value ) - 1 );

	return $is_assoc ? $value : [];
}

/**
 * Safe numeric cast.
 */
function tw_num( $value, $default = 0 ) {
	return is_numeric( $value ) ? $value + 0 : $default;
}

/**
 * Safely fetch first row from tw_supabase_get().
 */
function tw_supabase_first( string $table, array $params ): array {
	if ( ! function_exists( 'tw_supabase_get' ) ) {
		return [];
	}

	$rows = tw_supabase_get( $table, $params );

	if ( is_array( $rows ) && isset( $rows[0] ) && is_array( $rows[0] ) ) {
		return $rows[0];
	}

	return [];
}

/**
 * Returns the active campaign_id for a given WP user.
 *
 * Reads the most recently updated row in cyber_state_of_the_campaign
 * for the user. Used by shortcodes that need campaign context without
 * requiring a manual campaign_id attribute.
 *
 * @param int $wp_user_id WP user ID. Defaults to current user.
 * @return string UUID string, or empty string if not found.
 */
function nw_get_active_campaign_id( int $wp_user_id = 0 ): string {
	if ( ! $wp_user_id ) {
		$wp_user_id = get_current_user_id();
	}

	if ( ! $wp_user_id || ! function_exists( 'tw_supabase_first' ) ) {
		return '';
	}

	$row = tw_supabase_first(
		'cyber_state_of_the_campaign',
		[
			'wp_user_id' => 'eq.' . $wp_user_id,
			'select'     => 'campaign_id',
			'order'      => 'updated_at.desc',
			'limit'      => 1,
		]
	);

	return tw_sanitize_uuid( (string) ( $row['campaign_id'] ?? '' ) );
}

/**
 * Ensure a state row exists for the given campaign+character.
 * If missing, inserts defaults so the HUD always has something to show.
 * Uses service key — server-side write that must bypass RLS.
 */
function tw_ensure_state_row( string $campaign_id, string $character_id, int $wp_user_id ): void {
	if ( ! function_exists( 'tw_supabase_request' ) ) {
		return;
	}

	if ( ! $campaign_id || ! $character_id ) {
		return;
	}

	// Check first — avoid unnecessary write.
	$existing = tw_supabase_first(
		'cyber_state_of_the_campaign',
		[
			'campaign_id'  => 'eq.' . $campaign_id,
			'character_id' => 'eq.' . $character_id,
			'select'       => 'id',
			'limit'        => 1,
		]
	);

	if ( ! empty( $existing ) ) {
		return;
	}

	if ( ! defined( 'TW_SUPABASE_SERVICE_KEY' ) ) {
		error_log( 'TW tw_ensure_state_row: TW_SUPABASE_SERVICE_KEY not defined — skipping insert.' );
		return;
	}

	// Insert with all defaults from table definition.
	$result = tw_supabase_request(
		'POST',
		'cyber_state_of_the_campaign',
		[],
		[
			'campaign_id'  => $campaign_id,
			'character_id' => $character_id,
			'wp_user_id'   => $wp_user_id ?: null,
			'satiety'      => 100,
			'hydration'    => 100,
			'rest'         => 100,
			'sync_rate'    => 100,
			'xp'           => 0,
			'current_day'  => 0,
		],
		[
			'headers' => [
				'apikey'        => TW_SUPABASE_SERVICE_KEY,
				'Authorization' => 'Bearer ' . TW_SUPABASE_SERVICE_KEY,
				'Prefer'        => 'return=minimal',
			],
		]
	);

	if ( is_wp_error( $result ) ) {
		error_log( 'TW tw_ensure_state_row insert error: ' . $result->get_error_message() );
	}
}

/**
 * Fetches and prepares character data for character-card.php.
 * Returns exactly the keys expected by the template.
 */
function tw_prepare_character_data( array $game_data ): array {
	$char_id     = tw_sanitize_uuid( (string) ( $game_data['active_character_id'] ?? '' ) );
	$campaign_id = tw_sanitize_uuid( (string) ( $game_data['active_campaign_id'] ?? '' ) );
	$wp_user_id  = (int) ( $game_data['wp_user_id'] ?? get_current_user_id() );

	$result = [
		'char_id'              => $char_id,
		'char_data'            => [
			'name'   => 'Unknown',
			'race'   => 'Human',
			'class'  => 'Mercenary',
			'bio'    => '',
			'notes'  => '',
			'gold'   => 0,
			'lvl'    => 1,
			'body'   => 0,
			'mind'   => 0,
			'reflex' => 0,
			'spirit' => 0,
			'avatar' => '',
		],
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
	 */
	$char_row = tw_supabase_first(
		'cyber_characters',
		[
			'id'     => 'eq.' . $char_id,
			'select' => 'id,name,race_id,class_id,bio,notes,gold,lvl,body,mind,reflex,spirit,avatar,hp,mp',
			'limit'  => 1,
		]
	);

	if ( ! empty( $char_row ) ) {
		$result['char_data']['name']   = (string) ( $char_row['name'] ?? 'Unknown' );
		$result['char_data']['bio']    = (string) ( $char_row['bio'] ?? '' );
		$result['char_data']['notes']  = (string) ( $char_row['notes'] ?? '' );
		$result['char_data']['gold']   = tw_num( $char_row['gold'] ?? 0, 0 );
		$result['char_data']['lvl']    = max( 1, (int) tw_num( $char_row['lvl'] ?? 1, 1 ) );
		$result['char_data']['body']   = (int) tw_num( $char_row['body'] ?? 0, 0 );
		$result['char_data']['mind']   = (int) tw_num( $char_row['mind'] ?? 0, 0 );
		$result['char_data']['reflex'] = (int) tw_num( $char_row['reflex'] ?? 0, 0 );
		$result['char_data']['spirit'] = (int) tw_num( $char_row['spirit'] ?? 0, 0 );
		$result['char_data']['avatar'] = (string) ( $char_row['avatar'] ?? '' );

		$result['m_hp'] = max( 1, (int) tw_num( $char_row['hp'] ?? 10, 10 ) );
		$result['m_mp'] = max( 1, (int) tw_num( $char_row['mp'] ?? 10, 10 ) );

		$race_id  = tw_sanitize_uuid( (string) ( $char_row['race_id'] ?? '' ) );
		$class_id = tw_sanitize_uuid( (string) ( $char_row['class_id'] ?? '' ) );

		if ( $race_id ) {
			$race_row = tw_supabase_first(
				'cyber_races',
				[
					'id'     => 'eq.' . $race_id,
					'select' => 'name',
					'limit'  => 1,
				]
			);

			if ( ! empty( $race_row['name'] ) ) {
				$result['char_data']['race'] = (string) $race_row['name'];
			}
		}

		if ( $class_id ) {
			$class_row = tw_supabase_first(
				'cyber_classes',
				[
					'id'     => 'eq.' . $class_id,
					'select' => 'name',
					'limit'  => 1,
				]
			);

			if ( ! empty( $class_row['name'] ) ) {
				$result['char_data']['class'] = (string) $class_row['name'];
			}
		}
	}

	/**
	 * 2. HUD current state — filtrujemy po OBU kluczach: campaign_id + character_id.
	 * Jeśli brak wiersza, tw_ensure_state_row() tworzy go z defaultami.
	 */
	if ( $campaign_id ) {
		tw_ensure_state_row( $campaign_id, $char_id, $wp_user_id );
	}

	$hud_params = [
		'character_id' => 'eq.' . $char_id,
		'select'       => 'hp,mp,satiety,hydration,rest,sync_rate',
		'limit'        => 1,
	];

	if ( $campaign_id ) {
		$hud_params['campaign_id'] = 'eq.' . $campaign_id;
	}

	$hud_row = tw_supabase_first( 'cyber_state_of_the_campaign', $hud_params );

	if ( ! empty( $hud_row ) ) {
		$result['c_hp']        = max( 0, min( $result['m_hp'], (int) tw_num( $hud_row['hp'] ?? $result['m_hp'], $result['m_hp'] ) ) );
		$result['c_mp']        = max( 0, min( $result['m_mp'], (int) tw_num( $hud_row['mp'] ?? $result['m_mp'], $result['m_mp'] ) ) );
		$result['c_satiety']   = max( 0, min( 100, (int) tw_num( $hud_row['satiety'] ?? 100, 100 ) ) );
		$result['c_hydration'] = max( 0, min( 100, (int) tw_num( $hud_row['hydration'] ?? 100, 100 ) ) );
		$result['c_rest']      = max( 0, min( 100, (int) tw_num( $hud_row['rest'] ?? 100, 100 ) ) );
		$result['sync_p']      = max( 0, min( 100, (int) tw_num( $hud_row['sync_rate'] ?? 100, 100 ) ) );
	} else {
		// Fallback: HP/MP z cyber_characters, reszta 100.
		$result['c_hp'] = $result['m_hp'];
		$result['c_mp'] = $result['m_mp'];
	}

	/**
	 * 3. Derived percentages.
	 */
	$result['hp_p'] = $result['m_hp'] > 0
		? (int) min( 100, round( ( $result['c_hp'] / $result['m_hp'] ) * 100 ) )
		: 0;

	$result['mp_p'] = $result['m_mp'] > 0
		? (int) min( 100, round( ( $result['c_mp'] / $result['m_mp'] ) * 100 ) )
		: 0;

	/**
	 * 4. CSS helper classes.
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
	 */
	$skills_raw = tw_supabase_get(
		'cyber_character_skills',
		[
			'character_id' => 'eq.' . $char_id,
			'select'       => 'id,skill_id,proficiency,source,cyber_skills(id,name,description,category,application)',
		]
	);

	if ( is_array( $skills_raw ) ) {
		foreach ( $skills_raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$skill_info = tw_pick_relation_row( $row['cyber_skills'] ?? [] );

			if ( empty( $skill_info ) ) {
				continue;
			}

			$result['skills_and_abilities'][] = [
				'entry_type'  => 'skill',
				'id'          => $row['id'] ?? null,
				'skill_id'    => $row['skill_id'] ?? null,
				'proficiency' => (int) tw_num( $row['proficiency'] ?? 0, 0 ),
				'source'      => (string) ( $row['source'] ?? '' ),
				'info'        => [
					'name'        => (string) ( $skill_info['name'] ?? '' ),
					'description' => (string) ( $skill_info['description'] ?? '' ),
					'cost'        => '',
				],
			];
		}
	}

	/**
	 * 5b. Abilities.
	 */
	$abilities_raw = tw_supabase_get(
		'cyber_characterabilities',
		[
			'characterid' => 'eq.' . $char_id,
			'select'      => 'id,abilityid,source,cyberabilities(id,name,description,cost,abilitytype)',
		]
	);

	if ( is_array( $abilities_raw ) ) {
		foreach ( $abilities_raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$ability_info = tw_pick_relation_row( $row['cyberabilities'] ?? [] );

			if ( empty( $ability_info ) ) {
				continue;
			}

			$result['skills_and_abilities'][] = [
				'entry_type' => 'ability',
				'id'         => $row['id'] ?? null,
				'ability_id' => $row['abilityid'] ?? null,
				'source'     => (string) ( $row['source'] ?? '' ),
				'info'       => [
					'name'        => (string) ( $ability_info['name'] ?? '' ),
					'description' => (string) ( $ability_info['description'] ?? '' ),
					'cost'        => (string) ( $ability_info['cost'] ?? '' ),
				],
			];
		}
	}

	/**
	 * 6. Inventory.
	 */
	$inv_raw = tw_supabase_get(
		'cyber_character_inventory',
		[
			'character_id' => 'eq.' . $char_id,
			'select'       => 'id,quantity,is_equipped,equipped_slot,custom_name,active_weaves,engraving_level,faction_alignment,total_slots,max_slots,used_slots,item_id',
		]
	);

	if ( is_array( $inv_raw ) ) {
		$total_mass  = 0.0;
		$total_power = 0.0;

		foreach ( $inv_raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$item_id = $row['item_id'] ?? null;
			$item    = [];

			if ( $item_id ) {
				$item_rows = tw_supabase_get(
					'cyber_items',
					[
						'id'     => 'eq.' . $item_id,
						'select' => 'id,name,description,type,tags,slot,power_value,img_url,rarity,size,mass,stack_limit,is_container',
						'limit'  => 1,
					]
				);

				if ( is_array( $item_rows ) && isset( $item_rows[0] ) && is_array( $item_rows[0] ) ) {
					$item = $item_rows[0];
				}
			}

			$quantity = max( 1, (int) tw_num( $row['quantity'] ?? 1, 1 ) );
			$mass     = (float) tw_num( $item['mass'] ?? 0, 0 );
			$power    = (float) tw_num( $item['power_value'] ?? 0, 0 );

			$result['inventory'][] = [
				'id'            => $row['id'] ?? null,
				'quantity'      => $quantity,
				'is_equipped'   => ! empty( $row['is_equipped'] ),
				'equipped_slot' => (string) ( $row['equipped_slot'] ?? '' ),
				'custom_name'   => (string) ( $row['custom_name'] ?? '' ),
				'info'          => [
					'id'          => $item['id'] ?? null,
					'name'        => (string) ( $item['name'] ?? '' ),
					'description' => (string) ( $item['description'] ?? '' ),
					'type'        => (string) ( $item['type'] ?? '' ),
					'tags'        => $item['tags'] ?? [],
					'slot'        => (string) ( $item['slot'] ?? '' ),
					'powervalue'  => tw_num( $item['power_value'] ?? 0, 0 ),
					'imgurl'      => (string) ( $item['img_url'] ?? '' ),
					'rarity'      => (string) ( $item['rarity'] ?? '' ),
					'size'        => (string) ( $item['size'] ?? '' ),
					'mass'        => tw_num( $item['mass'] ?? 0, 0 ),
					'stacklimit'  => (int) tw_num( $item['stack_limit'] ?? 1, 1 ),
					'iscontainer' => ! empty( $item['is_container'] ),
				],
			];

			$total_mass  += $mass * $quantity;
			$total_power += $power * $quantity;
		}

		$result['total_mass']  = round( $total_mass, 2 );
		$result['total_power'] = round( $total_power, 2 );
	}

	/**
	 * Mass limit: 30 + BODY * 4.
	 */
	$result['mass_limit'] = 30 + ( (int) tw_num( $result['char_data']['body'] ?? 0, 0 ) * 4 );

	/**
	 * 7. Logs.
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

/** active campaign */
/**
 * Returns active campaign_id for the current WP user.
 * Reads from cyber_state_of_the_campaign — last active row.
 */
function nw_get_active_campaign_id( int $wp_user_id = 0 ): string {
    if ( ! $wp_user_id ) {
        $wp_user_id = get_current_user_id();
    }
    if ( ! $wp_user_id || ! function_exists( 'tw_supabase_first' ) ) {
        return '';
    }
    $row = tw_supabase_first(
        'cyber_state_of_the_campaign',
        [
            'wp_user_id' => 'eq.' . $wp_user_id,
            'select'     => 'campaign_id',
            'order'      => 'updated_at.desc',
            'limit'      => 1,
        ]
    );
    return tw_sanitize_uuid( (string) ( $row['campaign_id'] ?? '' ) );
}

/**
 * Fetches and prepares tactical map / battle-grid data.
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

	$map_rows = tw_get_data(
		$supabase_base . 'v_cyber_map_view?wp_user_id=eq.' . rawurlencode( (string) $userid ) . '&limit=1',
		$auth_headers
	);

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
