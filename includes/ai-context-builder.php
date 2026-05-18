<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NEOWEAVER — AI CONTEXT BUILDER
 *
 * Pobiera z Supabase minimalny, specyficzny zestaw danych per protokół.
 * Zwraca tablicę gotową do przekazania do tw_ai_gm():
 *
 *   tw_ai_build_context( string $char_id, string $protocol ): array
 *
 * Struktura zwracanego array:
 *   [
 *     'char'     => [...],   // dane postaci
 *     'location' => [...],   // dane bieżącej lokacji
 *     'world'    => [...],   // dane świata
 *     'protocol' => string,  // przekazany protokół
 *     'extra'    => string,  // blok C — dane specyficzne dla protokołu
 *   ]
 *
 * Wymagane helpery (zdefiniowane w supabase-helpers.php):
 *   tw_supabase_get()
 */

if ( ! function_exists( 'tw_ai_build_context' ) ) {
	function tw_ai_build_context( string $char_id, string $protocol ): array {

		// --- Sanitacja ---
		$safe_char_id = preg_replace( '/[^a-f0-9\-]/', '', strtolower( $char_id ) );
		$allowed_protocols = [ 'TRAVEL', 'COMBAT', 'TRADE', 'DIALOG', 'LORE', 'REST', 'DECK', 'META', 'UNKNOWN' ];
		if ( ! in_array( $protocol, $allowed_protocols, true ) ) {
			$protocol = 'UNKNOWN';
		}

		// --- Core: dane postaci ---
		$char_rows = tw_supabase_get(
			'cyber_characters',
			[
				'id'     => 'eq.' . $safe_char_id,
				'select' => 'id,name,currenthp,maxhp,mp,satiety,hydration,locationid,echo_tags,gold,world_id,archetype',
				'limit'  => 1,
			]
		);
		if ( is_wp_error( $char_rows ) || empty( $char_rows ) ) {
			error_log( 'TW ai-context-builder: nie można pobrać postaci ' . $safe_char_id );
			return [ 'char' => [], 'location' => [], 'world' => [], 'protocol' => $protocol, 'extra' => '' ];
		}
		$char = $char_rows[0];

		// --- Core: dane lokacji ---
		$location = [];
		if ( ! empty( $char['locationid'] ) ) {
			$safe_loc_id = preg_replace( '/[^a-f0-9\-]/', '', strtolower( $char['locationid'] ) );
			$loc_rows = tw_supabase_get(
				'cyber_worldmap',
				[
					'id'     => 'eq.' . $safe_loc_id,
					'select' => 'id,locationname,instancetags,aiprompt,threatlevel,nid,eid,sid,wid,location_type',
					'limit'  => 1,
				]
			);
			if ( ! is_wp_error( $loc_rows ) && ! empty( $loc_rows ) ) {
				$location = $loc_rows[0];
			}
		}

		// --- Core: dane świata ---
		$world = [];
		if ( ! empty( $char['world_id'] ) ) {
			$safe_world_id = preg_replace( '/[^a-f0-9\-]/', '', strtolower( $char['world_id'] ) );
			$world_rows = tw_supabase_get(
				'cyber_worlds',
				[
					'id'     => 'eq.' . $safe_world_id,
					'select' => 'id,entropy,globaltag1,globaltag2,globaltag3,difficulty,archetype,world_name',
					'limit'  => 1,
				]
			);
			if ( ! is_wp_error( $world_rows ) && ! empty( $world_rows ) ) {
				$world = $world_rows[0];
			}
		}

		// --- Extra: dane specyficzne per protokół ---
		$extra = '';

		switch ( $protocol ) {

			case 'TRAVEL':
				$exits = [];
				$dirs  = [ 'nid' => 'north', 'eid' => 'east', 'sid' => 'south', 'wid' => 'west' ];
				foreach ( $dirs as $col => $dir ) {
					if ( ! empty( $location[ $col ] ) ) {
						$safe_exit = preg_replace( '/[^a-f0-9\-]/', '', strtolower( $location[ $col ] ) );
						$exit_rows = tw_supabase_get(
							'cyber_worldmap',
							[ 'id' => 'eq.' . $safe_exit, 'select' => 'id,locationname,threatlevel,location_type', 'limit' => 1 ]
						);
						if ( ! is_wp_error( $exit_rows ) && ! empty( $exit_rows ) ) {
							$e = $exit_rows[0];
							$exits[] = strtoupper( $dir ) . ': ' . esc_html( $e['locationname'] ) . ' [threat:' . (int) $e['threatlevel'] . ']';
						}
					}
				}
				$extra .= 'AVAILABLE_EXITS: ' . ( $exits ? implode( ' | ', $exits ) : 'none' ) . "\n";
				$extra .= 'SATIETY: ' . (int)( $char['satiety'] ?? 0 ) . ' | HYDRATION: ' . (int)( $char['hydration'] ?? 0 ) . "\n";
				$extra .= 'ENCOUNTER_RISK: ' . min( 100, (int)( $location['threatlevel'] ?? 0 ) * 10 ) . "%\n";
				break;

			case 'COMBAT':
				// Karty w ręce (jeśli tabela istnieje)
				$hand_rows = tw_supabase_get(
					'cyber_deck_state',
					[
						'character_id' => 'eq.' . $safe_char_id,
						'zone'         => 'eq.hand',
						'select'       => 'cyber_cards(name,type,cost,effect_summary)',
						'limit'        => 8,
					]
				);
				if ( ! is_wp_error( $hand_rows ) && ! empty( $hand_rows ) ) {
					$cards = array_filter( array_map( fn($r) => $r['cyber_cards']['name'] ?? null, $hand_rows ) );
					$extra .= 'HAND: ' . implode( ', ', $cards ) . "\n";
				}
				$extra .= 'LOCATION_THREAT: ' . (int)( $location['threatlevel'] ?? 0 ) . "\n";
				$extra .= 'MP: ' . (int)( $char['mp'] ?? 0 ) . "\n";
				break;

			case 'TRADE':
				// Inwentarz NPC/sklepu z bieżącej lokacji
				$shop_rows = tw_supabase_get(
					'cyber_npc_inventory',
					[
						'location_id' => 'eq.' . ( $location['id'] ?? '' ),
						'for_sale'    => 'eq.true',
						'select'      => 'cyber_items(name,rarity,slot),price,quantity',
						'limit'       => 10,
					]
				);
				if ( ! is_wp_error( $shop_rows ) && ! empty( $shop_rows ) ) {
					$shop_lines = [];
					foreach ( $shop_rows as $s ) {
						$name  = esc_html( $s['cyber_items']['name'] ?? '?' );
						$price = (int) ( $s['price'] ?? 0 );
						$qty   = (int) ( $s['quantity'] ?? 0 );
						$shop_lines[] = "{$name} ({$price}g x{$qty})";
					}
					$extra .= 'SHOP_INVENTORY: ' . implode( ', ', $shop_lines ) . "\n";
				}
				$extra .= 'PLAYER_GOLD: ' . (int)( $char['gold'] ?? 0 ) . "\n";
				$extra .= 'WORLD_DIFFICULTY: ' . esc_html( $world['difficulty'] ?? 'normal' ) . "\n";
				break;

			case 'DIALOG':
				// NPC w bieżącej lokacji
				$npc_rows = tw_supabase_get(
					'cyber_npcs',
					[
						'location_id' => 'eq.' . ( $location['id'] ?? '' ),
						'select'      => 'id,name,ai_personality_prompt,faction,disposition',
						'limit'       => 3,
					]
				);
				if ( ! is_wp_error( $npc_rows ) && ! empty( $npc_rows ) ) {
					foreach ( $npc_rows as $npc ) {
						$extra .= 'NPC: ' . esc_html( $npc['name'] ?? '?' );
						if ( ! empty( $npc['faction'] ) )      $extra .= ' [faction:' . esc_html( $npc['faction'] ) . ']';
						if ( ! empty( $npc['disposition'] ) )  $extra .= ' [disp:' . esc_html( $npc['disposition'] ) . ']';
						if ( ! empty( $npc['ai_personality_prompt'] ) ) {
							$extra .= "\nPERSONALITY: " . mb_substr( esc_html( $npc['ai_personality_prompt'] ), 0, 200 );
						}
						$extra .= "\n";
					}
				}
				break;

			case 'LORE':
				$tag_rows = tw_supabase_get(
					'cyber_world_tags',
					[
						'world_id' => 'eq.' . ( $world['id'] ?? '' ),
						'select'   => 'tag_name,tag_description',
						'limit'    => 10,
					]
				);
				if ( ! is_wp_error( $tag_rows ) && ! empty( $tag_rows ) ) {
					$lore = [];
					foreach ( $tag_rows as $t ) {
						$lore[] = esc_html( $t['tag_name'] ?? '' ) . ': ' . mb_substr( esc_html( $t['tag_description'] ?? '' ), 0, 100 );
					}
					$extra .= 'WORLD_LORE: ' . implode( ' | ', $lore ) . "\n";
				}
				$extra .= 'ECHO_TAGS: ' . esc_html( implode( ', ', (array)( $char['echo_tags'] ?? [] ) ) ) . "\n";
				break;

			case 'REST':
				$safe_zone = ! empty( $location['instancetags'] ) && str_contains( $location['instancetags'], 'safe' );
				$extra .= 'SAFE_ZONE: ' . ( $safe_zone ? 'yes' : 'no' ) . "\n";
				$extra .= 'SATIETY: ' . (int)( $char['satiety'] ?? 0 ) . ' | HYDRATION: ' . (int)( $char['hydration'] ?? 0 ) . "\n";
				$extra .= 'HP_MISSING: ' . max( 0, (int)( $char['maxhp'] ?? 100 ) - (int)( $char['currenthp'] ?? 0 ) ) . "\n";
				break;

			case 'DECK':
				$all_hand = tw_supabase_get(
					'cyber_deck_state',
					[
						'character_id' => 'eq.' . $safe_char_id,
						'select'       => 'zone,cyber_cards(name,type,cost)',
						'limit'        => 30,
					]
				);
				if ( ! is_wp_error( $all_hand ) ) {
					$zones = [];
					foreach ( $all_hand as $r ) {
						$zone  = $r['zone'] ?? 'unknown';
						$cname = $r['cyber_cards']['name'] ?? '?';
						$zones[ $zone ][] = $cname;
					}
					foreach ( $zones as $z => $cards ) {
						$extra .= strtoupper( $z ) . ': ' . implode( ', ', $cards ) . "\n";
					}
				}
				break;

			// META i UNKNOWN nie potrzebują extra danych po stronie AI
			default:
				break;
		}

		return [
			'char'     => $char,
			'location' => $location,
			'world'    => $world,
			'protocol' => $protocol,
			'extra'    => trim( $extra ),
		];
	}
}
