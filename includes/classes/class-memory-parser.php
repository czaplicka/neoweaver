<?php
/**
 * NeoWeaver Memory Parser
 *
 * Wyciąga blok ---SYSTEM--- z odpowiedzi GM,
 * parsuje tagi #MEMORY i zapisuje do cyber_memory w Supabase.
 *
 * Format tagu:
 *   #MEMORY:topic:content
 *   #MEMORY:topic:subject:content
 *
 * Przykłady:
 *   #MEMORY:character:fear:pająki intensywna fobia
 *   #MEMORY:npc:barman_aldric:jest koruptem, współpracuje z Czerwoną Łapą
 *   #MEMORY:location:tawerna_trzy_pióra:bezpieczna kryjówka, gracz był w sesji 3
 *   #MEMORY:faction:czerwona_lapa:kontroluje port, szuka gracza
 *   #MEMORY:campaign:gracz odkrył mapę do fortecy
 *
 * Refaktoring v2: używa tw_supabase_* helpers (jak reszta pluginu)
 * zamiast własnych wp_remote_post/get.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NW_Memory_Parser {

	// Mapa ważności dla poszczególnych topiców
	private const IMPORTANCE_MAP = [
		'character' => 4,
		'campaign'  => 3,
		'npc'       => 3,
		'location'  => 2,
		'faction'   => 3,
		'item'      => 2,
		'summary'   => 5,
	];

	// =========================================================
	// GŁÓWNA METODA — wywołaj po otrzymaniu odpowiedzi od GPT
	// =========================================================

	/**
	 * @param string $raw_gm_text  Surowa odpowiedź z GPT (może zawierać blok ---SYSTEM---)
	 * @param string $char_id      UUID postaci gracza
	 * @param string $world_id     UUID świata
	 * @param string $session_id   UUID sesji
	 *
	 * @return array {
	 *   'clean_text' => string,  // tekst bez bloku SYSTEM — do wyświetlenia graczowi
	 *   'tags'       => array,   // inne tagi (STATUS, ENTROPY, LOC…) do dalszej obsługi
	 *   'memories'   => array,   // zapisane fakty do cyber_memory
	 * }
	 */
	public function parse( string $raw_gm_text, string $char_id, string $world_id, string $session_id ): array {

		// 1. Wytnij blok SYSTEM z narracji
		[ $clean_text, $system_block ] = $this->extract_system_block( $raw_gm_text );

		// 2. Parsuj tagi
		$memory_entries = [];
		$other_tags     = [];

		if ( ! empty( $system_block ) ) {
			foreach ( $this->parse_tags( $system_block ) as $tag ) {
				if ( $tag['type'] === 'MEMORY' ) {
					$memory_entries[] = $this->build_memory_entry( $tag, $char_id, $world_id, $session_id );
				} else {
					$other_tags[] = $tag;
				}
			}
		}

		// 3. Zapisz memory do Supabase (batch upsert)
		$saved = [];
		if ( ! empty( $memory_entries ) ) {
			$saved = $this->save_memories( $memory_entries );
		}

		return [
			'clean_text' => trim( $clean_text ),
			'tags'       => $other_tags,
			'memories'   => $saved,
		];
	}

	// =========================================================
	// WYCIĄGANIE BLOKU SYSTEM
	// =========================================================

	private function extract_system_block( string $text ): array {
		$pattern = '/\n?---SYSTEM---\n(.*?)\n---END---\n?/s';
		if ( ! preg_match( $pattern, $text, $matches ) ) {
			return [ $text, '' ];
		}
		return [ preg_replace( $pattern, '', $text ), trim( $matches[1] ) ];
	}

	// =========================================================
	// PARSOWANIE TAGÓW
	// =========================================================

	private function parse_tags( string $system_block ): array {
		$tags  = [];
		$lines = explode( "\n", $system_block );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( empty( $line ) || $line[0] !== '#' ) continue;

			$raw   = ltrim( $line, '#' );
			$parts = explode( ':', $raw, 2 );
			$type  = strtoupper( trim( $parts[0] ) );
			$rest  = isset( $parts[1] ) ? trim( $parts[1] ) : '';

			if ( $type === 'MEMORY' ) {
				$mem_parts = explode( ':', $rest, 3 );
				$topic     = strtolower( trim( $mem_parts[0] ?? '' ) );
				$second    = trim( $mem_parts[1] ?? '' );
				$third     = trim( $mem_parts[2] ?? '' );

				$tags[] = [
					'type'    => 'MEMORY',
					'topic'   => $topic,
					'subject' => $third !== '' ? $second : null,
					'content' => $third !== '' ? $third  : $second,
				];
			} else {
				$tags[] = [ 'type' => $type, 'value' => $rest, 'raw' => $line ];
			}
		}

		return $tags;
	}

	// =========================================================
	// BUDOWANIE WPISU DO cyber_memory
	// =========================================================

	private function build_memory_entry( array $tag, string $char_id, string $world_id, string $session_id ): array {
		$topic = $tag['topic'];
		return [
			'world_id'   => $world_id,
			'char_id'    => ( $topic === 'character' ) ? $char_id : null,
			'session_id' => $session_id ?: null,
			'topic'      => $topic,
			'subject'    => $tag['subject'] ?? null,
			'content'    => $tag['content'],
			'importance' => self::IMPORTANCE_MAP[ $topic ] ?? 3,
			'source'     => 'gm_tag',
		];
	}

	// =========================================================
	// ZAPIS DO SUPABASE — przez tw_supabase_request
	// =========================================================

	private function save_memories( array $entries ): array {
		if ( ! function_exists( 'tw_supabase_request' ) ) {
			error_log( '[NW_Memory_Parser] tw_supabase_request() niedostępny.' );
			return [];
		}

		$key = function_exists( 'tw_supabase_service_key' ) ? tw_supabase_service_key() : '';

		$result = tw_supabase_request(
			'POST',
			'cyber_memory',
			[],
			$entries,
			[
				'headers' => [
					'apikey'        => $key,
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
					'Prefer'        => 'resolution=ignore-duplicates,return=representation',
				],
			]
		);

		if ( is_wp_error( $result ) ) {
			error_log( '[NW_Memory_Parser] Save error: ' . $result->get_error_message() );
			return [];
		}

		if ( isset( $result['ok'] ) && ! $result['ok'] ) {
			error_log( '[NW_Memory_Parser] Supabase error: ' . ( $result['error'] ?? 'unknown' ) );
			return [];
		}

		return is_array( $result['data'] ?? null ) ? $result['data'] : [];
	}

	// =========================================================
	// POBIERANIE MEMORY DO PROMPTU
	// =========================================================

	/**
	 * Buduje blok MEMORY do wklejenia w prompt systemowy GM-a.
	 *
	 * @param string $char_id
	 * @param string $world_id
	 * @param int    $char_limit   Ile wpisów character (domyślnie 5)
	 * @param int    $world_limit  Ile wpisów world/campaign (domyślnie 8)
	 */
	public function build_prompt_block( string $char_id, string $world_id, int $char_limit = 5, int $world_limit = 8 ): string {
		if ( ! function_exists( 'tw_supabase_get' ) ) {
			return '';
		}

		// Pamięć postaci
		$char_rows = tw_supabase_get( 'cyber_memory', [
			'char_id' => 'eq.' . $char_id,
			'order'   => 'importance.desc,updated_at.desc',
			'limit'   => $char_limit,
			'select'  => 'topic,subject,content',
		] );

		// Pamięć świata (wpisy globalne, bez przypisanej postaci)
		$world_rows = tw_supabase_get( 'cyber_memory', [
			'world_id' => 'eq.' . $world_id,
			'char_id'  => 'is.null',
			'order'    => 'importance.desc,updated_at.desc',
			'limit'    => $world_limit,
			'select'   => 'topic,subject,content',
		] );

		$lines = [];
		foreach ( [ $char_rows, $world_rows ] as $rows ) {
			if ( is_wp_error( $rows ) || ! is_array( $rows ) ) continue;
			foreach ( $rows as $m ) {
				if ( ! is_array( $m ) ) continue;
				$subj    = ! empty( $m['subject'] ) ? $m['subject'] . ': ' : '';
				$lines[] = "[{$m['topic']}] {$subj}{$m['content']}";
			}
		}

		return empty( $lines ) ? '' : "MEMORY:\n" . implode( "\n", $lines );
	}
}
