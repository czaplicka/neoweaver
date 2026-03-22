<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Podstawa URL dla Supabase REST.
 */
if ( ! function_exists( 'tw_supabase_rest_base' ) ) {
	function tw_supabase_rest_base() {
		return trailingslashit( tw_supabase_url() ) . 'rest/v1/';
	}
}

/**
 * Ogólny helper GET: tw_supabase_get('cyber_characters', ['id' => 'eq.123', 'select' => '*']);
 */
if ( ! function_exists( 'tw_supabase_get' ) ) {
	function tw_supabase_get( $endpoint, $query = [], $extra_args = [] ) {
		$url = tw_supabase_rest_base() . ltrim( $endpoint, '/' );
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$default_args = [
			'headers' => [
				'apikey'        => tw_supabase_anon_key(),
				'Authorization' => 'Bearer ' . tw_supabase_anon_key(),
			],
			'timeout'   => 15,
			'sslverify' => true,
		];

		$args     = array_merge_recursive( $default_args, $extra_args );
		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			error_log( 'TW tw_supabase_get error: ' . print_r( $response, true ) );
			return [];
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		return is_array( $data ) ? $data : [];
	}
}

/**
 * Ogólny helper request (POST/PATCH/DELETE).
 */
if ( ! function_exists( 'tw_supabase_request' ) ) {
	function tw_supabase_request( $method, $endpoint, $query = [], $body = null, $extra_args = [] ) {
		$url = tw_supabase_rest_base() . ltrim( $endpoint, '/' );
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$default_args = [
			'method'  => strtoupper( $method ),
			'headers' => [
				'apikey'        => tw_supabase_anon_key(),
				'Authorization' => 'Bearer ' . tw_supabase_anon_key(),
				'Content-Type'  => 'application/json',
			],
			'timeout'   => 15,
			'sslverify' => true,
		];

		if ( ! is_null( $body ) ) {
			$default_args['body'] = wp_json_encode( $body );
		}

		$args     = array_merge_recursive( $default_args, $extra_args );
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			error_log( 'TW tw_supabase_request error: ' . print_r( $response, true ) );
			return [ 'ok' => false, 'code' => 0, 'data' => null ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return [
			'ok'   => ( $code >= 200 && $code < 300 ),
			'code' => $code,
			'data' => $data,
		];
	}
}

/**
 * Stary helper kompatybilności: tw_get_data($url, $args);
 */
if ( ! function_exists( 'tw_get_data' ) ) {
	function tw_get_data( $url, $args = [] ) {
		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return [];
		}
		$body = wp_remote_retrieve_body( $response );
		return json_decode( $body, true ) ?: [];
	}
}

/**
 * Pobiera założone (is_equipped=true) przedmioty postaci z cyber_character_inventory.
 * Używane np. w panelu postaci na stronie gry.
 *
 * BUG-FIX: character_id is a UUID string in Supabase (cyber_characters.id).
 * The previous code cast it with (int) / intval(), which collapses every UUID
 * to 0 and returns an empty array for every character. Fixed by using
 * UUID-safe string sanitization identical to the pattern in
 * Neoweaver_Agents_Repository::in_filter(): strip everything except
 * alphanumerics and hyphens, which covers UUID v4 and legacy integer IDs.
 *
 * @param string|int $character_id  UUID or integer primary key of cyber_characters.
 * @return array
 */
if ( ! function_exists( 'get_character_equipped_items' ) ) {
	function get_character_equipped_items( $character_id ) {
		// UUID-safe sanitization — never use (int) or intval() on a UUID.
		$safe_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $character_id );

		if ( empty( $safe_id ) ) {
			error_log( 'Invalid character_id in get_character_equipped_items' );
			return [];
		}

		return tw_supabase_get(
			'cyber_character_inventory',
			[
				'character_id' => 'eq.' . $safe_id,
				'is_equipped'  => 'eq.true',
				'select'       => 'quantity,cyber_items(name,img_url,slot,rarity,size)',
			]
		);
	}
}
