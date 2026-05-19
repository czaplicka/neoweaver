<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NEOWEAVER — HELPER: tw_user_owns_character()
 *
 * Sprawdza czy postać o podanym UUID należy do wp_user_id.
 * Używa Supabase REST przez tw_supabase_get().
 *
 * @param string $char_id    UUID postaci.
 * @param int    $wp_user_id ID zalogowanego użytkownika WordPress.
 * @return bool              true jeśli postać istnieje i należy do usera.
 */

if ( ! function_exists( 'tw_user_owns_character' ) ) {
	function tw_user_owns_character( string $char_id, int $wp_user_id ): bool {
		if ( empty( $char_id ) || $wp_user_id <= 0 ) {
			return false;
		}

		if ( ! function_exists( 'tw_supabase_get' ) ) {
			error_log( 'TW tw_user_owns_character: tw_supabase_get() niedostępne.' );
			return false;
		}

		$safe_char_id = preg_replace( '/[^a-f0-9\-]/', '', strtolower( $char_id ) );
		if ( empty( $safe_char_id ) ) {
			return false;
		}

		$rows = tw_supabase_get(
			'cyber_characters',
			[
				'id'         => 'eq.' . $safe_char_id,
				'wp_user_id' => 'eq.' . (int) $wp_user_id,
				'select'     => 'id',
				'limit'      => 1,
			]
		);

		if ( is_wp_error( $rows ) ) {
			error_log( 'TW tw_user_owns_character Supabase error: ' . $rows->get_error_message() );
			return false;
		}

		return ! empty( $rows );
	}
}
