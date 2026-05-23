<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_ajax_save_deck' ) ) {

	add_action( 'wp_ajax_tw_save_deck', 'tw_ajax_save_deck' );

	function tw_ajax_save_deck(): void {

		if ( ! check_ajax_referer( 'cyber_deck_builder', 'nonce', false ) ) {
			wp_send_json_error( 'Security check failed', 403 );
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( 'Not logged in', 401 );
			return;
		}

		$char_id = preg_replace( '/[^a-fA-F0-9\-]/', '', (string) ( $_POST['character_id'] ?? '' ) );
		if ( empty( $char_id ) ) {
			wp_send_json_error( 'Missing character_id', 400 );
			return;
		}

		// Sprawdź czy postać należy do usera
		if ( ! function_exists( 'tw_get_user_characters' ) ) {
			wp_send_json_error( 'Helper missing', 500 );
			return;
		}
		$characters  = tw_get_user_characters( $user_id );
		$allowed_ids = array_map( fn( $c ) => (string) ( $c->id ?? '' ), (array) $characters );
		if ( ! in_array( $char_id, $allowed_ids, true ) ) {
			wp_send_json_error( 'Access denied', 403 );
			return;
		}

		// ── Parsuj dane z JS ───────────────────────────────────────────────────

		// active = cyber_character_deck.id kart nowo dodanych z library
		$raw_active = (string) ( $_POST['active'] ?? '' );
		$new_ids    = array_values( array_filter( array_map( 'intval', explode( ',', $raw_active ) ) ) );

		// keep_buffer_ids = cyber_buffer.id kart które gracz zostawił w active (już w bufferze)
		$raw_keep    = (string) ( $_POST['keep_buffer_ids'] ?? '' );
		$keep_buf_ids = array_values( array_filter( array_map( 'intval', explode( ',', $raw_keep ) ) ) );

		// ── Walidacja limitu ───────────────────────────────────────────────────
		$total = count( $new_ids ) + count( $keep_buf_ids );
		if ( $total < 20 || $total > 50 ) {
			wp_send_json_error(
				sprintf( 'Active deck must have 20–50 cards. You sent %d.', $total ),
				400
			);
			return;
		}

		// ── Pobierz wszystkie cyber_character_deck.id tej postaci ──────────────
		$all_assigned = tw_supabase_get(
			'cyber_character_deck',
			array(
				'character_id' => 'eq.' . $char_id,
				'select'       => 'id',
			)
		);
		if ( ! is_array( $all_assigned ) ) {
			wp_send_json_error( 'Could not load deck', 500 );
			return;
		}
		$all_deck_row_ids = array_map( fn( $r ) => (int) $r['id'], $all_assigned );

		// Upewnij się że nowe karty faktycznie należą do tej postaci
		$valid_new = array_values( array_intersect( $new_ids, $all_deck_row_ids ) );

		// ── Pobierz istniejący buffer tej postaci ──────────────────────────────
		$existing_buffer = tw_supabase_get(
			'cyber_buffer',
			array(
				'char_id' => 'eq.' . $char_id,
				'select'  => 'id,deck_card_id',
			)
		);
		if ( ! is_array( $existing_buffer ) ) {
			wp_send_json_error( 'Could not load buffer', 500 );
			return;
		}

		$existing_buf_ids      = array_map( fn( $r ) => (int) $r['id'], $existing_buffer );
		$existing_deck_card_ids = array_map( fn( $r ) => (int) $r['deck_card_id'], $existing_buffer );

		// Sprawdź keep_buf_ids — tylko te które faktycznie istnieją w bufferze tej postaci
		$valid_keep = array_values( array_intersect( $keep_buf_ids, $existing_buf_ids ) );

		// ── Usuń z buffera te których gracz NIE zostawił w active ─────────────
		$to_delete = array_values( array_diff( $existing_buf_ids, $valid_keep ) );
		if ( ! empty( $to_delete ) ) {
			foreach ( $to_delete as $buf_id ) {
				tw_supabase_delete(
					'cyber_buffer',
					array( 'id' => 'eq.' . $buf_id )
				);
			}
		}

		// ── Dodaj nowe karty do buffera (tylko te których jeszcze nie ma) ──────
		// Unikaj duplikatów: nie wstawiaj jeśli deck_card_id już jest w bufferze
		$already_in_buf_deck_ids = array_map(
			fn( $r ) => (int) $r['deck_card_id'],
			array_filter( $existing_buffer, fn( $r ) => in_array( (int) $r['id'], $valid_keep, true ) )
		);

		$to_insert = array_values( array_diff( $valid_new, $already_in_buf_deck_ids ) );

		$inserted = 0;
		if ( ! empty( $to_insert ) ) {
			$rows = array_map( fn( $deck_row_id ) => array(
				'char_id'      => $char_id,
				'deck_card_id' => $deck_row_id,
				'zone'         => 'hand',
				'name'         => 'card',
				'base_effect'  => '{}',
				'scaling'      => '{}',
			), $to_insert );

			$result = tw_supabase_post( 'cyber_buffer', $rows );
			if ( false === $result ) {
				wp_send_json_error( 'Could not insert new cards into buffer', 500 );
				return;
			}
			$inserted = count( $to_insert );
		}

		wp_send_json_success( array(
			'msg'      => 'Deck synced.',
			'kept'     => count( $valid_keep ),
			'added'    => $inserted,
			'removed'  => count( $to_delete ),
			'total'    => count( $valid_keep ) + $inserted,
		) );
	}
}
