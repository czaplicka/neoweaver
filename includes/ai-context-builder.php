<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NEOWEAVER — AI CONTEXT BUILDER
 *
 * Pobiera z Supabase minimalny, specyficzny zestaw danych per protokół.
 * Zwraca tablicę gotową do przekazania do tw_ai_gm():\n *
 *   tw_ai_build_context( string $char_id, string $protocol ): array
 *
 * Struktura zwracanego array:
 *   [
 *     'char'     => [...],   // dane postaci
 *     'location' => [...],   // dane bieżącej lokacji
 *     'world'    => [...],   // dane świata
 *     'world_id' => string,  // UUID świata (skrót dla tw_ai_gm)
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
			return [ 'char' => [], 'location' => [], 'world' => [], 'world_id' => null, 'protocol' => $protocol, 'extra' => '' ];
		}
		$char = $char_rows[0];

		// --- Core: dane lokacji ---
		$location = [];
		if ( ! empty( $char['locationid'] ) ) {
			$safe_loc_id = preg_replace( '/[^a-f0-9\-]/', '', strtolower( $char['locationid'] ) );
			$loc_rows = tw_supabase_get(
				'cyber_world_map',
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
		$world    = [];
		$world_id = isset( $char['world_id'] ) ? $char['world_id'] : null;
		if ( $world_id ) {
			$safe_world_id = preg_replace( '/[^a-f0-9\-]/', '', strtolower( $world_id ) );
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
							'cyber_world_map',
							[ 'id' => 'eq.' . $safe_exit, 'select' => 'id,locationname,threatlevel,location_type', 'limit' => 1 ]
						);
						if ( ! is_wp_error( $exit_rows ) && ! empty( $exit_rows ) ) {
							$e = $exit_rows[0];
							$exits[] = strtoupper( $dir ) . ': ' . sanitize_text_field( $e['locationname'] ) . ' [threat:' . (int) $e['threatlevel'] . ']';
						}
					}
				}
				$extra .= 'AVAILABLE_EXITS: ' . ( $exits ? implode( ' | ', $exits ) : 'none' ) . "\n";
				$extra .= 'SATIETY: ' . (int)( isset( $char['satiety'] ) ? $char['satiety'] : 0 ) . ' | HYDRATION: ' . (int)( isset( $char['hydration'] ) ? $char['hydration'] : 0 ) . "\n";
				$extra .= 'ENCOUNTER_RISK: ' . min( 100, (int)( isset( $location['threatlevel'] ) ? $location['threatlevel'] : 0 ) * 10 ) . "%\n";
				break;

			case 'COMBAT':
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
					$cards = [];
					foreach ( $hand_rows as $r ) {
						if ( ! empty( $r['cyber_cards']['name'] ) ) {
							$cards[] = $r['cyber_cards']['name'];
						}
					}
					if ( $cards ) {
						$extra .= 'HAND: ' . implode( ', ', $cards ) . "\n";
					}
				}
				$extra .= 'LOCATION_THREAT: ' . (int)( isset( $location['threatlevel'] ) ? $location['threatlevel'] : 0 ) . "\n";
				$extra .= 'MP: ' . (int)( isset( $char['mp'] ) ? $char['mp'] : 0 ) . "\n";
				break;

			case 'TRADE':
				$shop_rows = tw_supabase_get(
					'cyber_npc_inventory',
					[
						'location_id' => 'eq.' . ( isset( $location['id'] ) ? $location['id'] : '' ),
						'for_sale'    => 'eq.true',
						'select'      => 'cyber_items(name,rarity,slot),price,quantity',
						'limit'       => 10,
					]
				);
				if ( ! is_wp_error( $shop_rows ) && ! empty( $shop_rows ) ) {
					$shop_lines = [];
					foreach ( $shop_rows as $s ) {
						$name  = sanitize_text_field( isset( $s['cyber_items']['name'] ) ? $s['cyber_items']['name'] : '?' );
						$price = (int) ( isset( $s['price'] ) ? $s['price'] : 0 );
						$qty   = (int) ( isset( $s['quantity'] ) ? $s['quantity'] : 0 );
						$shop_lines[] = "{$name} ({$price}g x{$qty})";
					}
					$extra .= 'SHOP_INVENTORY: ' . implode( ', ', $shop_lines ) . "\n";
				}
				$extra .= 'PLAYER_GOLD: ' . (int)( isset( $char['gold'] ) ? $char['gold'] : 0 ) . "\n";
				$extra .= 'WORLD_DIFFICULTY: ' . sanitize_text_field( isset( $world['difficulty'] ) ? $world['difficulty'] : 'normal' ) . "\n";
				break;

			case 'DIALOG':
				$npc_rows = tw_supabase_get(
					'cyber_npcs',
					[
						'location_id' => 'eq.' . ( isset( $location['id'] ) ? $location['id'] : '' ),
						'select'      => 'id,name,ai_personality_prompt,faction,disposition',
						'limit'       => 3,
					]
				);
				if ( ! is_wp_error( $npc_rows ) && ! empty( $npc_rows ) ) {
					foreach ( $npc_rows as $npc ) {
						$extra .= 'NPC: ' . sanitize_text_field( isset( $npc['name'] ) ? $npc['name'] : '?' );
						if ( ! empty( $npc['faction'] ) )     $extra .= ' [faction:' . sanitize_text_field( $npc['faction'] ) . ']';
						if ( ! empty( $npc['disposition'] ) ) $extra .= ' [disp:' . sanitize_text_field( $npc['disposition'] ) . ']';
						if ( ! empty( $npc['ai_personality_prompt'] ) ) {
							$extra .= "\nPERSONALITY: " . mb_substr( sanitize_text_field( $npc['ai_personality_prompt'] ), 0, 200 );
						}
						$extra .= "\n";
					}
				}
				break;

			case 'LORE':
				$tag_rows = tw_supabase_get(
					'cyber_world_tags',
					[
						'world_id' => 'eq.' . ( isset( $world['id'] ) ? $world['id'] : '' ),
						'select'   => 'tag_name,tag_description',
						'limit'    => 10,
					]
				);
				if ( ! is_wp_error( $tag_rows ) && ! empty( $tag_rows ) ) {
					$lore = [];
					foreach ( $tag_rows as $t ) {
						$lore[] = sanitize_text_field( isset( $t['tag_name'] ) ? $t['tag_name'] : '' ) . ': ' . mb_substr( sanitize_text_field( isset( $t['tag_description'] ) ? $t['tag_description'] : '' ), 0, 100 );
					}
					$extra .= 'WORLD_LORE: ' . implode( ' | ', $lore ) . "\n";
				}
				$extra .= 'ECHO_TAGS: ' . sanitize_text_field( implode( ', ', (array)( isset( $char['echo_tags'] ) ? $char['echo_tags'] : [] ) ) ) . "\n";
				break;

			case 'REST':
				$instance_tags = isset( $location['instancetags'] ) ? $location['instancetags'] : '';
				$safe_zone     = ! empty( $instance_tags ) && ( strpos( $instance_tags, 'safe' ) !== false );
				$extra .= 'SAFE_ZONE: ' . ( $safe_zone ? 'yes' : 'no' ) . "\n";
				$extra .= 'SATIETY: ' . (int)( isset( $char['satiety'] ) ? $char['satiety'] : 0 ) . ' | HYDRATION: ' . (int)( isset( $char['hydration'] ) ? $char['hydration'] : 0 ) . "\n";
				$extra .= 'HP_MISSING: ' . max( 0, (int)( isset( $char['maxhp'] ) ? $char['maxhp'] : 100 ) - (int)( isset( $char['currenthp'] ) ? $char['currenthp'] : 0 ) ) . "\n";
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
						$zone  = isset( $r['zone'] ) ? $r['zone'] : 'unknown';
						$cname = isset( $r['cyber_cards']['name'] ) ? $r['cyber_cards']['name'] : '?';
						$zones[ $zone ][] = $cname;
					}
					foreach ( $zones as $z => $cards ) {
						$extra .= strtoupper( $z ) . ': ' . implode( ', ', $cards ) . "\n";
					}
				}
				break;

			default:
				break;
		}

		return [
			'char'     => $char,
			'location' => $location,
			'world'    => $world,
			'world_id' => $world_id,
			'protocol' => $protocol,
			'extra'    => trim( $extra ),
		];
	}
}
