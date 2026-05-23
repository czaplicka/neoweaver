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

		$char_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) ( $_POST['character_id'] ?? '' ) );
		if ( empty( $char_id ) ) {
			wp_send_json_error( 'Missing character_id', 400 );
			return;
		}

		// Sprawdź czy postać należy do usera
		if ( ! function_exists( 'tw_get_user_characters' ) ) {
			wp_send_json_error( 'Helper missing', 500 );
			return;
		}

		$characters    = tw_get_user_characters( $user_id );
		$allowed_ids   = array_map( fn( $c ) => (string) ( $c->id ?? '' ), (array) $characters );
		if ( ! in_array( $char_id, $allowed_ids, true ) ) {
			wp_send_json_error( 'Access denied', 403 );
			return;
		}

		// active = lista instance_id kart które mają być w grze
		$raw_active = $_POST['active'] ?? '';
		$active_ids = array();
		if ( is_string( $raw_active ) && '' !== $raw_active ) {
			$active_ids = array_filter( array_map( 'intval', explode( ',', $raw_active ) ) );
		} elseif ( is_array( $raw_active ) ) {
			$active_ids = array_filter( array_map( 'intval', $raw_active ) );
		}
		$active_ids = array_values( array_unique( $active_ids ) );

		$count = count( $active_ids );
		if ( $count < 20 || $count > 50 ) {
			wp_send_json_error(
				sprintf( 'Active deck must have 20–50 cards. You have %d.', $count ),
				400
			);
			return;
		}

		// Pobierz wszystkie wiersze cyber_character_deck dla tej postaci
		$all_assigned = tw_supabase_get(
			'cyber_character_deck',
			array(
				'character_id' => 'eq.' . $char_id,
				'select'       => 'id',
			)
		);

		if ( ! is_array( $all_assigned ) ) {
			wp_send_json_error( 'Could not load deck from Supabase', 500 );
			return;
		}

		// Zbuduj mapę id → czy ma być w buffer
		$all_deck_row_ids = array_map( fn( $row ) => (int) $row['id'], $all_assigned );

		// Usuń stary buffer dla tej postaci
		$del = tw_supabase_delete(
			'cyber_buffer',
			array( 'char_id' => 'eq.' . $char_id )
		);

		if ( false === $del ) {
			wp_send_json_error( 'Could not clear old buffer', 500 );
			return;
		}

		// Wstaw nowy buffer — tylko karty z active_ids (to są cyber_character_deck.id)
		$valid_active = array_intersect( $active_ids, $all_deck_row_ids );

		if ( empty( $valid_active ) ) {
			wp_send_json_error( 'None of the provided IDs match your deck', 400 );
			return;
		}

		$rows = array_map( fn( $deck_row_id ) => array(
			'char_id'      => $char_id,
			'deck_card_id' => $deck_row_id,
			'zone'         => 'hand',
			'name'         => 'card',
			'base_effect'  => '{}',
			'scaling'      => '{}',
		), array_values( $valid_active ) );

		$insert = tw_supabase_post( 'cyber_buffer', $rows );

		if ( false === $insert ) {
			wp_send_json_error( 'Could not save buffer to Supabase', 500 );
			return;
		}

		wp_send_json_success( array(
			'saved' => count( $valid_active ),
			'msg'   => 'Deck synced.',
		) );
	}
}
