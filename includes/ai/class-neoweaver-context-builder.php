<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/supabase-config.php';

/**
 * NeoWeaver Context Builder
 *
 * Buduje 3-blokowy kontekst promptu dla GM-a.
 * Używana przez NeoWeaver_Claude_Engine::process().
 *
 * build( $char_id, $protocol ) zwraca:
 *   [
 *     'block_a'  => string,
 *     'block_b'  => string,
 *     'block_c'  => string,
 *     'world_id' => string|null,
 *   ]
 *
 * Zweryfikowane nazwy tabel/kolumn (2026-05-22):
 *  - cyber_worlds:           id, name, difficulty, global_tag_1/2/3 (brak entropy i archetype w tabeli)
 *  - cyber_world_map:        id, locationname, instancetags, aiprompt, threatlevel, nid, eid, sid, wid, location_type
 *  - cyber_characters:       worldid (bez podkreślnika!), locationid, echo_tags, currenthp, maxhp, mp, satiety, hydration, gold
 *  - cyber_character_buffer: character_id, buffer_card_id, location, hand_order, instance_tags
 *  - cyber_lore:             id, lore_key, lore_type, lore, tags
 *  - cyber_world_tags:       world_id, tag_id  (join table — nie ma tag_name/tag_value bezpośrednio)
 */
class NeoWeaver_Context_Builder {

	public function __construct() {}

	// ============================================================
	// PUBLIC
	// ============================================================

	public function build( string $char_id, string $protocol ): array {
		$safe_id = $this->sanitize_uuid( $char_id );
		$core    = $this->get_core_context( $safe_id );
		$extra   = $this->get_protocol_context( $safe_id, $protocol, $core );

		return [
			'block_a'  => $this->build_block_a( $core ),
			'block_b'  => $this->build_block_b( $core ),
			'block_c'  => $this->build_block_c( $protocol, $extra ),
			'world_id' => $core['world']['id'] ?? null,
		];
	}

	// ============================================================
	// CORE
	// ============================================================

	private function get_core_context( string $char_id ): array {
		$char = $this->query(
			'cyber_characters',
			'id=eq.' . $char_id . '&select=id,name,currenthp,maxhp,mp,satiety,hydration,locationid,echo_tags,gold,worldid&limit=1'
		)[0] ?? [];

		$location = [];
		$world    = [];

		if ( ! empty( $char['locationid'] ) ) {
			$location = $this->query(
				'cyber_world_map',
				'id=eq.' . $this->sanitize_uuid( $char['locationid'] ) . '&select=id,locationname,instancetags,aiprompt,threatlevel,nid,eid,sid,wid,location_type&limit=1'
			)[0] ?? [];
		}

		if ( ! empty( $char['worldid'] ) ) {
			// cyber_worlds: kolumny to "name", "global_tag_1/2/3" (brak entropy i archetype)
			$world = $this->query(
				'cyber_worlds',
				'id=eq.' . $this->sanitize_uuid( $char['worldid'] ) . '&select=id,name,difficulty,global_tag_1,global_tag_2,global_tag_3&limit=1'
			)[0] ?? [];
		}

		return compact( 'char', 'location', 'world' );
	}

	// ============================================================
	// EXTRA: dane per protokół
	// ============================================================

	private function get_protocol_context( string $char_id, string $protocol, array $core ): array {
		$loc_id   = isset( $core['location']['id'] ) ? $this->sanitize_uuid( $core['location']['id'] ) : '';
		$world_id = isset( $core['world']['id'] )    ? $this->sanitize_uuid( $core['world']['id'] )    : '';

		switch ( $protocol ) {

			case 'COMBAT':
				return [
					'monsters' => $this->query(
						'cyber_monsters',
						'locationid=eq.' . $loc_id . '&select=name,hp,attack,defense,tags&limit=3'
					),
					// cyber_character_buffer: location='hand', klucz to character_id
					'hand' => $this->query(
						'cyber_character_buffer',
						'character_id=eq.' . $char_id . '&location=eq.hand&select=buffer_card_id,instance_tags&limit=10'
					),
				];

			case 'TRADE':
				return [
					'npc_inventory' => $this->query(
						'cyber_npc_inventory',
						'locationid=eq.' . $loc_id . '&select=item_name,price,quantity&limit=10'
					),
					'player_gold' => (int) ( $core['char']['gold'] ?? 0 ),
				];

			case 'TRAVEL':
				$exits   = [];
				$dir_map = [ 'nid' => 'NORTH', 'eid' => 'EAST', 'sid' => 'SOUTH', 'wid' => 'WEST' ];
				foreach ( $dir_map as $col => $label ) {
					if ( ! empty( $core['location'][ $col ] ) ) {
						$dest = $this->query(
							'cyber_world_map',
							'id=eq.' . $this->sanitize_uuid( $core['location'][ $col ] ) . '&select=id,locationname,threatlevel,location_type&limit=1'
						)[0] ?? null;
						if ( $dest ) {
							$exits[ $label ] = $dest;
						}
					}
				}
				return [ 'exits' => $exits ];

			case 'DIALOG':
				return [
					'npcs' => $this->query(
						'cyber_npcs',
						'locationid=eq.' . $loc_id . '&select=name,role,ai_personality_prompt,relationship_tags&limit=3'
					),
				];

			case 'LORE':
				// cyber_lore: pobieramy fragmenty lore powiązane z aktualną lokacją
				// location_ids to tablica UUID w jsonb — filtrujemy po zawartości
				return [
					'lore' => $loc_id ? $this->query(
						'cyber_lore',
						'location_ids=cs.["' . $loc_id . '"]&select=lore_key,lore_type,lore,tags&limit=5'
					) : [],
				];

			case 'REST':
				return [
					'safe_zone'  => ! empty( $core['location']['instancetags'] ) && str_contains( $core['location']['instancetags'], 'safe' ),
					'hp_missing' => max( 0, (int) ( $core['char']['maxhp'] ?? 100 ) - (int) ( $core['char']['currenthp'] ?? 0 ) ),
				];

			case 'DECK':
				// cyber_character_buffer grupuje karty wg location: hand / draw_pile / discard_pile / library
				return [
					'buffer' => $this->query(
						'cyber_character_buffer',
						'character_id=eq.' . $char_id . '&select=location,buffer_card_id,instance_tags,hand_order&limit=40'
					),
				];

			default:
				return [];
		}
	}

	// ============================================================
	// BLOKI PROMPTU
	// ============================================================

	private function build_block_a( array $core ): string {
		// cyber_worlds nie ma kolumny archetype — pomijamy lub dajemy stałą
		return "You are the AI Game Master of NeoWeave — a dark, narrative RPG.\n"
			. "Rules: Respond in character as the world. Keep answers under 120 words unless combat demands more.\n"
			. "Embed system tags in your response using syntax #TAG or #TAG:value (e.g. #ENTROPY_UP:5, #LOC:42, #STATUS_POISONED, #HP_CHANGE:-10, #GOLD_CHANGE:-5).\n"
			. "Tags are parsed by the system — the player never sees them. Never explain tags to the player.\n"
			. "Respond in the same language the player uses.";
	}

	private function build_block_b( array $core ): string {
		$c = $core['char'];
		$l = $core['location'];
		$w = $core['world'];

		$echo_tags_raw = $c['echo_tags'] ?? [];
		if ( is_string( $echo_tags_raw ) ) {
			$echo_tags_raw = json_decode( $echo_tags_raw, true ) ?? [];
		}
		$tags = is_array( $echo_tags_raw ) && ! empty( $echo_tags_raw ) ? implode( ', ', $echo_tags_raw ) : 'none';

		// cyber_worlds: kolumna to "name" (nie worldname), brak entropy
		$world_tags = implode( ', ', array_filter( [
			$w['global_tag_1'] ?? '',
			$w['global_tag_2'] ?? '',
			$w['global_tag_3'] ?? '',
		] ) );

		return "WORLD: " . esc_html( $w['name'] ?? '' ) . " | Difficulty: " . esc_html( $w['difficulty'] ?? 'normal' ) . "\n"
			. "WORLD_TAGS: " . esc_html( $world_tags ) . "\n"
			. "AGENT: " . esc_html( $c['name'] ?? '' ) . " | HP: " . (int) ( $c['currenthp'] ?? 0 ) . "/" . (int) ( $c['maxhp'] ?? 0 ) . " | MP: " . (int) ( $c['mp'] ?? 0 ) . " | Gold: " . (int) ( $c['gold'] ?? 0 ) . "\n"
			. "BIOMETRICS: Satiety " . (int) ( $c['satiety'] ?? 0 ) . "% | Hydration " . (int) ( $c['hydration'] ?? 0 ) . "%\n"
			. "ECHO: " . esc_html( $tags ) . "\n"
			. "LOCATION: " . esc_html( $l['locationname'] ?? '' ) . " | Threat: " . (int) ( $l['threatlevel'] ?? 0 ) . " | Tags: " . esc_html( $l['instancetags'] ?? '' ) . "\n"
			. "GM_NOTE: " . esc_html( $l['aiprompt'] ?? '' );
	}

	private function build_block_c( string $protocol, array $extra ): string {
		$lines = [ "PROTOCOL: {$protocol}" ];

		switch ( $protocol ) {

			case 'TRAVEL':
				foreach ( $extra['exits'] ?? [] as $label => $dest ) {
					$lines[] = "EXIT_{$label}: " . esc_html( $dest['locationname'] ) . " (id:{$dest['id']}, threat:{$dest['threatlevel']})";
				}
				break;

			case 'COMBAT':
				foreach ( $extra['monsters'] ?? [] as $m ) {
					$lines[] = "ENEMY: " . esc_html( $m['name'] ) . " HP:{$m['hp']} ATK:{$m['attack']} DEF:{$m['defense']}";
				}
				$hand_ids = array_column( $extra['hand'] ?? [], 'buffer_card_id' );
				$lines[]  = "PLAYER_HAND_IDS: " . implode( ', ', $hand_ids );
				break;

			case 'TRADE':
				$items   = array_map(
					fn( $i ) => esc_html( $i['item_name'] ) . "(" . (int) $i['price'] . "g x" . (int) ( $i['quantity'] ?? 1 ) . ")",
					$extra['npc_inventory'] ?? []
				);
				$lines[] = "SHOP: " . implode( ', ', $items );
				$lines[] = "PLAYER_GOLD: " . (int) ( $extra['player_gold'] ?? 0 );
				break;

			case 'DIALOG':
				foreach ( $extra['npcs'] ?? [] as $npc ) {
					$personality = mb_substr( esc_html( $npc['ai_personality_prompt'] ?? '' ), 0, 200 );
					$lines[]     = "NPC: " . esc_html( $npc['name'] ) . " | Role: " . esc_html( $npc['role'] ?? '' ) . " | Personality: {$personality}";
				}
				break;

			case 'LORE':
				foreach ( $extra['lore'] ?? [] as $l ) {
					$lines[] = "LORE[" . esc_html( $l['lore_type'] ?? '' ) . "]: " . esc_html( $l['lore_key'] ?? '' ) . " — " . mb_substr( esc_html( $l['lore'] ?? '' ), 0, 300 );
				}
				break;

			case 'REST':
				$lines[] = "SAFE_ZONE: " . ( $extra['safe_zone'] ? 'yes' : 'no' );
				$lines[] = "HP_MISSING: " . (int) ( $extra['hp_missing'] ?? 0 );
				break;

			case 'DECK':
				$zones = [];
				foreach ( $extra['buffer'] ?? [] as $r ) {
					$zone           = strtoupper( $r['location'] ?? 'LIBRARY' );
					$zones[ $zone ][] = $r['buffer_card_id'] ?? '?';
				}
				foreach ( $zones as $zone => $ids ) {
					$lines[] = "{$zone}: " . implode( ', ', $ids ) . ' (' . count( $ids ) . ' cards)';
				}
				break;
		}

		return implode( "\n", $lines );
	}

	// ============================================================
	// HELPER: Supabase REST
	// ============================================================

	private function query( string $table, string $params ): array {
		$url      = trailingslashit( tw_supabase_url() ) . 'rest/v1/' . $table . '?' . $params;
		$response = wp_remote_get( $url, [
			'headers' => [
				'apikey'        => tw_supabase_service_key(),
				'Authorization' => 'Bearer ' . tw_supabase_service_key(),
				'Content-Type'  => 'application/json',
			],
			'timeout' => 8,
		] );

		if ( is_wp_error( $response ) ) {
			error_log( '[NeoWeaver ContextBuilder] Supabase error: ' . $response->get_error_message() );
			return [];
		}

		return json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
	}

	private function sanitize_uuid( string $id ): string {
		return preg_replace( '/[^a-f0-9\-]/', '', strtolower( $id ) );
	}
}
