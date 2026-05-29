<?php
/**
 * adventure-data.php
 * Helper functions for fetching and preparing data for the Adventure template.
 * No HTML, echo, or include — only pure PHP functions returning arrays.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// tw_sanitize_uuid() was a duplicate of nw_sanitize_uuid() (same regex, same logic).
// Removed — all callers in this file now use nw_sanitize_uuid() directly.
// If any external caller still references tw_sanitize_uuid(), add a shim in a
// backward-compat file, not here.

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

	return nw_sanitize_uuid( (string) ( $row['campaign_id'] ?? '' ) );
}

/**
 * Ensure a state row exists for the given campaign+character.
 * Uses service key — server-side write that must bypass RLS.
 */
function tw_ensure_state_row( string $campaign_id, string $character_id, int $wp_user_id ): void {
	// Bail early on obviously missing deps before any I/O.
	if ( ! function_exists( 'tw_supabase_request' ) ) {
		return;
	}

	if ( ! $campaign_id || ! $character_id ) {
		return;
	}

	// tw_supabase_request() guards TW_SUPABASE_SERVICE_KEY internally and returns
	// WP_Error when it is missing — no need for a redundant defined() check here.

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
				'apikey'        => defined( 'TW_SUPABASE_SERVICE_KEY' ) ? TW_SUPABASE_SERVICE_KEY : '',
				'Authorization' => 'Bearer ' . ( defined( 'TW_SUPABASE_SERVICE_KEY' ) ? TW_SUPABASE_SERVICE_KEY : '' ),
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
	$char_id     = nw_sanitize_uuid( (string) ( $game_data['active_character_id'] ?? '' ) );
	$campaign_id = nw_sanitize_uuid( (string) ( $game_data['active_campaign_id'] ?? '' ) );
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
	 * cyber_characters stores max HP/MP as `hp` and `mp` (verified in schema).
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

		// hp/mp in cyber_characters = max values (pool size at character creation/level-up).
		$result['m_hp'] = max( 1, (int) tw_num( $char_row['hp'] ?? 10, 10 ) );
		$result['m_mp'] = max( 1, (int) tw_num( $char_row['mp'] ?? 10, 10 ) );

		$race_id  = nw_sanitize_uuid( (string) ( $char_row['race_id'] ?? '' ) );
		$class_id = nw_sanitize_uuid( (string) ( $char_row['class_id'] ?? '' ) );

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
	 * 2. HUD current state.
	 * cyber_state_of_the_campaign stores current hp/mp (verified in schema).
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
	 * Table: cyber_character_abilities  (columns: character_id, ability_id)
	 * Join:  cyber_abilities            (column:  ability_type — not abilitytype)
	 * Old code used non-existent cyber_characterabilities / cyberabilities / characterid.
	 */
	$abilities_raw = tw_supabase_get(
		'cyber_character_abilities',
		[
			'character_id' => 'eq.' . $char_id,
			'select'       => 'id,ability_id,source,cyber_abilities(id,name,description,cost,ability_type)',
		]
	);

	if ( is_array( $abilities_raw ) ) {
		foreach ( $abilities_raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$ability_info = tw_pick_relation_row( $row['cyber_abilities'] ?? [] );

			if ( empty( $ability_info ) ) {
				continue;
			}

			$result['skills_and_abilities'][] = [
				'entry_type' => 'ability',
				'id'         => $row['id'] ?? null,
				'ability_id' => $row['ability_id'] ?? null,
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
	 * 6. Inventory — single batched query.
	 *
	 * OLD: foreach inventory row → tw_supabase_get('cyber_items', ['id'=>'eq.'.$item_id])
	 *      = N sequential HTTP requests (1 per item). Breaks on Hostinger with >~5 items.
	 *
	 * NEW: collect all item_ids → one request with id=in.(uuid1,uuid2,...)
	 *      then build a lookup map — O(n) total, 2 HTTP requests regardless of inventory size.
	 */
	$inv_raw = tw_supabase_get(
		'cyber_character_inventory',
		[
			'character_id' => 'eq.' . $char_id,
			'select'       => 'id,quantity,is_equipped,equipped_slot,custom_name,active_weaves,engraving_level,faction_alignment,total_slots,max_slots,used_slots,item_id',
		]
	);

	if ( is_array( $inv_raw ) && count( $inv_raw ) > 0 ) {
		// Collect unique, valid item UUIDs.
		$item_ids = [];
		foreach ( $inv_raw as $row ) {
			$iid = nw_sanitize_uuid( (string) ( $row['item_id'] ?? '' ) );
			if ( $iid ) {
				$item_ids[ $iid ] = true;
			}
		}

		// Single batched request for all items.
		$items_map = [];
		if ( ! empty( $item_ids ) ) {
			$ids_csv   = implode( ',', array_keys( $item_ids ) );
			$items_raw = tw_supabase_get(
				'cyber_items',
				[
					'id'     => 'in.(' . $ids_csv . ')',
					'select' => 'id,name,description,type,tags,slot,power_value,img_url,rarity,size,mass,stack_limit,is_container',
				]
			);

			if ( is_array( $items_raw ) ) {
				foreach ( $items_raw as $item ) {
					if ( is_array( $item ) && isset( $item['id'] ) ) {
						$items_map[ $item['id'] ] = $item;
					}
				}
			}
		}

		$total_mass  = 0.0;
		$total_power = 0.0;

		foreach ( $inv_raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$item_id = nw_sanitize_uuid( (string) ( $row['item_id'] ?? '' ) );
			$item    = $item_id ? ( $items_map[ $item_id ] ?? [] ) : [];

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

/**
 * Fetches and prepares tactical map / battle-grid data.
 */
function tw_prepare_tactical_data( array $game_data, int $userid ): array {
	$result = [
		'has_enemy' => false,
		'map_data'  => [],
		'grid_map'  => [],
	];

	$active_session_id = nw_sanitize_uuid( (string) ( $game_data['active_session_id'] ?? '' ) );

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

	// v_cyber_map_view — filter by wp_user_id, limit 1.
	$map_raw = tw_get_data(
		$supabase_base . 'v_cyber_map_view?wp_user_id=eq.' . rawurlencode( (string) $userid ) . '&limit=1',
		$auth_headers
	);

	if ( is_wp_error( $map_raw ) ) {
		error_log( 'TW tw_prepare_tactical_data map error: ' . $map_raw->get_error_message() );
	} elseif ( is_array( $map_raw ) && isset( $map_raw[0] ) && is_array( $map_raw[0] ) ) {
		$result['map_data'] = $map_raw[0];
	}

	// cyber_battle_grid — filter by session_id.
	$grid_raw = tw_get_data(
		$supabase_base . 'cyber_battle_grid?select=*&session_id=eq.' . rawurlencode( $active_session_id ),
		$auth_headers
	);

	if ( is_wp_error( $grid_raw ) ) {
		error_log( 'TW tw_prepare_tactical_data grid error: ' . $grid_raw->get_error_message() );
	} elseif ( is_array( $grid_raw ) ) {
		foreach ( $grid_raw as $u ) {
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
