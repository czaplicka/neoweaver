<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/supabase-config.php';

/**
 * NeoWeaver Context Builder
 *
 * build( $char_id, $protocol ) zwraca:
 *   [
 *     'block_a'  => string,
 *     'block_b'  => string,
 *     'block_c'  => string,
 *     'world_id' => string|null,
 *   ]
 *
 * Kolumny FK w cyber_characters:
 *   worldid    (bez podkreślnika) — FK do cyber_worlds
 *   locationid (bez podkreślnika) — FK do cyber_world_map
 */
class NeoWeaver_Context_Builder {

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
	// CORE: dane zawsze potrzebne
	// ============================================================

	private function get_core_context( string $char_id ): array {
		$char = $this->query(
			'cyber_characters',
			[
				'id'     => 'eq.' . $char_id,
				'select' => 'id,name,currenthp,maxhp,mp,satiety,hydration,locationid,echo_tags,gold,worldid,archetype,level,mind,spirit',
				'limit'  => '1',
			]
		)[0] ?? [];

		$location = [];
		$world    = [];

		if ( ! empty( $char['locationid'] ) ) {
			$location = $this->query(
				'cyber_world_map',
				[
					'id'     => 'eq.' . $this->sanitize_uuid( $char['locationid'] ),
					'select' => 'id,locationname,instancetags,aiprompt,threatlevel,nid,eid,sid,wid,location_type',
					'limit'  => '1',
				]
			)[0] ?? [];
		}

		if ( ! empty( $char['worldid'] ) ) {
			$world = $this->query(
				'cyber_worlds',
				[
					'id'     => 'eq.' . $this->sanitize_uuid( $char['worldid'] ),
					'select' => 'id,worldname,entropy,globaltag1,globaltag2,globaltag3,difficulty,archetype,tech_vs_nature,chaos_vs_order,gold_vs_thief',
					'limit'  => '1',
				]
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
						[
							'locationid' => 'eq.' . $loc_id,
							'select'     => 'name,hp,attack,defense,tags',
							'limit'      => '3',
						]
					),
					// cyber_buffer = karty aktualnie w ręce gracza
					'hand' => $this->query(
						'cyber_buffer',
						[
							'char_id' => 'eq.' . $char_id,
							'zone'    => 'eq.hand',
							'select'  => 'name,category,tags,base_effect',
							'limit'   => '10',
						]
					),
				];

			case 'TRADE':
				return [
					'npc_inventory' => $this->query(
						'cyber_npc_inventory',
						[
							'locationid' => 'eq.' . $loc_id,
							'select'     => 'item_name,price,quantity',
							'limit'      => '10',
						]
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
							[
								'id'     => 'eq.' . $this->sanitize_uuid( $core['location'][ $col ] ),
								'select' => 'id,locationname,threatlevel,location_type',
								'limit'  => '1',
							]
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
						[
							'locationid' => 'eq.' . $loc_id,
							'select'     => 'name,role,ai_personality_prompt,relationship_tags',
							'limit'      => '3',
						]
					),
				];

			case 'LORE':
				// Złożone warunki (GIN arrays + wiele pól numerycznych) — używamy RPC.
				// FIX: p_location_id i p_archetype_id to UUID stringi — NIE castujemy do int.
				// Rzutowanie UUID na int dawało 0 i RPC zawsze zwracało pusty wynik.
				return [
					'lore_entries' => $this->rpc( 'get_relevant_lore', [
						'p_location_id'  => $loc_id,
						'p_archetype_id' => $core['world']['archetype'] ?? null,
						'p_entropy'      => (int) ( $core['world']['entropy']      ?? 0 ),
						'p_level'        => (int) ( $core['char']['level']          ?? 1 ),
						'p_mind'         => (int) ( $core['char']['mind']           ?? 0 ),
						'p_spirit'       => (int) ( $core['char']['spirit']         ?? 0 ),
						'p_tech_vs_nature'  => (int) ( $core['world']['tech_vs_nature']  ?? 0 ),
						'p_chaos_vs_order'  => (int) ( $core['world']['chaos_vs_order']  ?? 0 ),
						'p_gold_vs_thief'   => (int) ( $core['world']['gold_vs_thief']   ?? 0 ),
					] ),
				];

			case 'REST':
				return [
					'safe_zone'  => ! empty( $core['location']['instancetags'] ) && str_contains( $core['location']['instancetags'], 'safe' ),
					'hp_missing' => max( 0, (int) ( $core['char']['maxhp'] ?? 100 ) - (int) ( $core['char']['currenthp'] ?? 0 ) ),
				];

			case 'DECK':
				return [
					// Pełny stan talii gracza: ręka + odrzucone + do zagrania
					'deck_state' => $this->query(
						'cyber_buffer',
						[
							'char_id' => 'eq.' . $char_id,
							'select'  => 'zone,name,category,tags',
							'limit'   => '30',
						]
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
		// Sanitacja dla plain-text AI promptu — wp_strip_all_tags usuwa znaczniki HTML,
		// ale NIE konwertuje & na &amp; (jak robiłoby esc_html).
		$archetype = wp_strip_all_tags( $core['world']['archetype'] ?? 'EPIC' );
		return "You are the AI Game Master of NeoWeave \u2014 a dark, narrative RPG.\n"
			. "Archetype: {$archetype}\n"
			. "Rules: Respond in character as the world. Keep answers under 120 words unless combat demands more.\n"
			. "Embed system tags in your response using syntax #TAG or #TAG:value (e.g. #ENTROPY_UP:5, #LOC:42, #STATUS_POISONED, #HP_CHANGE:-10, #GOLD_CHANGE:-5).\n"
			. "Tags are parsed by the system \u2014 the player never sees them. Never explain tags to the player.\n"
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

		// FIX: używamy wp_strip_all_tags() zamiast esc_html().
		// Bloki są plain-text system promptem dla Claude — NIE HTML.
		// esc_html() konwertuje & → &amp;, < → &lt; itd., co zatruwało kontekst GM.
		return "WORLD: " . wp_strip_all_tags( $w['worldname'] ?? '' ) . " | Entropy: " . (int) ( $w['entropy'] ?? 0 ) . "/100 | Difficulty: " . wp_strip_all_tags( $w['difficulty'] ?? 'normal' ) . "\n"
			. "WORLD_TAGS: " . wp_strip_all_tags( implode( ', ', array_filter( [ $w['globaltag1'] ?? '', $w['globaltag2'] ?? '', $w['globaltag3'] ?? '' ] ) ) ) . "\n"
			. "AGENT: " . wp_strip_all_tags( $c['name'] ?? '' ) . " | HP: " . (int) ( $c['currenthp'] ?? 0 ) . "/" . (int) ( $c['maxhp'] ?? 0 ) . " | MP: " . (int) ( $c['mp'] ?? 0 ) . " | Gold: " . (int) ( $c['gold'] ?? 0 ) . "\n"
			. "BIOMETRICS: Satiety " . (int) ( $c['satiety'] ?? 0 ) . "% | Hydration " . (int) ( $c['hydration'] ?? 0 ) . "%\n"
			. "ECHO: " . wp_strip_all_tags( $tags ) . "\n"
			. "LOCATION: " . wp_strip_all_tags( $l['locationname'] ?? '' ) . " | Threat: " . (int) ( $l['threatlevel'] ?? 0 ) . " | Tags: " . wp_strip_all_tags( $l['instancetags'] ?? '' ) . "\n"
			. "GM_NOTE: " . wp_strip_all_tags( $l['aiprompt'] ?? '' );
	}

	private function build_block_c( string $protocol, array $extra ): string {
		$lines = [ "PROTOCOL: {$protocol}" ];

		switch ( $protocol ) {

			case 'TRAVEL':
				foreach ( $extra['exits'] ?? [] as $label => $dest ) {
					$lines[] = "EXIT_{$label}: " . wp_strip_all_tags( $dest['locationname'] ) . " (id:{$dest['id']}, threat:{$dest['threatlevel']})";
				}
				break;

			case 'COMBAT':
				foreach ( $extra['monsters'] ?? [] as $m ) {
					$lines[] = "ENEMY: " . wp_strip_all_tags( $m['name'] ) . " HP:{$m['hp']} ATK:{$m['attack']} DEF:{$m['defense']}";
				}
				$hand    = array_column( $extra['hand'] ?? [], 'name' );
				$lines[] = "PLAYER_HAND: " . implode( ', ', array_map( 'wp_strip_all_tags', $hand ) );
				break;

			case 'TRADE':
				$items   = array_map(
					fn( $i ) => wp_strip_all_tags( $i['item_name'] ) . "(" . (int) $i['price'] . "g x" . (int) ( $i['quantity'] ?? 1 ) . ")",
					$extra['npc_inventory'] ?? []
				);
				$lines[] = "SHOP: " . implode( ', ', $items );
				$lines[] = "PLAYER_GOLD: " . (int) ( $extra['player_gold'] ?? 0 );
				break;

			case 'DIALOG':
				foreach ( $extra['npcs'] ?? [] as $npc ) {
					$personality = mb_substr( wp_strip_all_tags( $npc['ai_personality_prompt'] ?? '' ), 0, 200 );
					$lines[]     = "NPC: " . wp_strip_all_tags( $npc['name'] ) . " | Role: " . wp_strip_all_tags( $npc['role'] ?? '' ) . " | Personality: {$personality}";
				}
				break;

			case 'LORE':
				foreach ( $extra['lore_entries'] ?? [] as $t ) {
					$lines[] = "LORE [" . wp_strip_all_tags( $t['lore_key'] ?? '' ) . "]: " . wp_strip_all_tags( mb_substr( $t['lore'] ?? '', 0, 300 ) );
				}
				break;

			case 'REST':
				$lines[] = "SAFE_ZONE: " . ( $extra['safe_zone'] ? 'yes' : 'no' );
				$lines[] = "HP_MISSING: " . (int) ( $extra['hp_missing'] ?? 0 );
				break;

			case 'DECK':
				$zones = [];
				foreach ( $extra['deck_state'] ?? [] as $r ) {
					$zone           = strtoupper( $r['zone'] ?? 'UNKNOWN' );
					$zones[ $zone ][] = wp_strip_all_tags( $r['name'] ?? '?' );
				}
				foreach ( $zones as $zone => $cards ) {
					$lines[] = "{$zone}: " . implode( ', ', $cards );
				}
				break;
		}

		return implode( "\n", $lines );
	}

	// ============================================================
	// HELPER: GET do Supabase REST
	//
	// FIX: $params zmienione z surowego stringa na array.
	// add_query_arg() obsługuje enkodowanie wartości — wartości zawierające
	// ampersandy (np. nazwy lokacji) nie psują już query stringa.
	// ============================================================
	private function query( string $table, array $params ): array {
		$base_url = trailingslashit( tw_supabase_url() ) . 'rest/v1/' . $table;
		$url      = add_query_arg( $params, $base_url );

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

	// ============================================================
	// HELPER: POST RPC do Supabase (złożone zapytania)
	// ============================================================

	private function rpc( string $function_name, array $params ): array {
		$url      = trailingslashit( tw_supabase_url() ) . 'rest/v1/rpc/' . $function_name;
		$response = wp_remote_post( $url, [
			'headers' => [
				'apikey'        => tw_supabase_service_key(),
				'Authorization' => 'Bearer ' . tw_supabase_service_key(),
				'Content-Type'  => 'application/json',
			],
			'body'    => json_encode( $params ),
			'timeout' => 8,
		] );

		if ( is_wp_error( $response ) ) {
			error_log( '[NeoWeaver ContextBuilder] Supabase RPC error: ' . $response->get_error_message() );
			return [];
		}

		return json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
	}

	// ============================================================
	// HELPER: sanitacja UUID
	// ============================================================

	private function sanitize_uuid( string $id ): string {
		return preg_replace( '/[^a-f0-9\-]/', '', strtolower( $id ) );
	}
}
