<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Podstawa URL dla Supabase REST.
 */
if ( ! function_exists( 'tw_supabase_rest_base' ) ) {
	function tw_supabase_rest_base() {
		if ( ! function_exists( 'tw_supabase_url' ) ) {
			error_log( 'TW: tw_supabase_url() is not defined.' );
			return '';
		}

		return trailingslashit( tw_supabase_url() ) . 'rest/v1/';
	}
}

/**
 * Ogólny helper GET: tw_supabase_get('cyber_characters', ['id' => 'eq.123', 'select' => '*']);
 */
if ( ! function_exists( 'tw_supabase_get' ) ) {
	function tw_supabase_get( $endpoint, $query = [], $extra_args = [] ) {
		$base = tw_supabase_rest_base();
		if ( empty( $base ) ) {
			return [];
		}

		$endpoint = ltrim( (string) $endpoint, '/' );
		if ( $endpoint === '' ) {
			error_log( 'TW tw_supabase_get error: empty endpoint' );
			return [];
		}

		$url = $base . $endpoint;

		if ( ! empty( $query ) && is_array( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		if ( ! function_exists( 'tw_supabase_anon_key' ) ) {
			error_log( 'TW: tw_supabase_anon_key() is not defined.' );
			return [];
		}

		$default_args = [
			'headers'  => [
				'apikey'        => tw_supabase_anon_key(),
				'Authorization' => 'Bearer ' . tw_supabase_anon_key(),
			],
			'timeout'   => 15,
			'sslverify' => true,
		];

		// Zamiast array_merge_recursive – zwykły merge.
		$args = array_merge( $default_args, (array) $extra_args );

		// Safeguard: timeout MUSI być liczbą, nie tablicą.
		if ( isset( $args['timeout'] ) && ! is_numeric( $args['timeout'] ) ) {
			$args['timeout'] = 15;
		}

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
		$base = tw_supabase_rest_base();
		if ( empty( $base ) ) {
			return [ 'ok' => false, 'code' => 0, 'data' => null ];
		}

		$endpoint = ltrim( (string) $endpoint, '/' );
		if ( $endpoint === '' ) {
			error_log( 'TW tw_supabase_request error: empty endpoint' );
			return [ 'ok' => false, 'code' => 0, 'data' => null ];
		}

		$url = $base . $endpoint;

		if ( ! empty( $query ) && is_array( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		if ( ! function_exists( 'tw_supabase_anon_key' ) ) {
			error_log( 'TW: tw_supabase_anon_key() is not defined.' );
			return [ 'ok' => false, 'code' => 0, 'data' => null ];
		}

		$default_args = [
			'method'  => strtoupper( (string) $method ),
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

		$args = array_merge( $default_args, (array) $extra_args );

		if ( isset( $args['timeout'] ) && ! is_numeric( $args['timeout'] ) ) {
			$args['timeout'] = 15;
		}

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
		$defaults = [
			'timeout'   => 15,
			'sslverify' => true,
		];

		$args = array_merge( $defaults, (array) $args );

		if ( isset( $args['timeout'] ) && ! is_numeric( $args['timeout'] ) ) {
			$args['timeout'] = 15;
		}

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			error_log( 'TW tw_get_data error: ' . print_r( $response, true ) );
			return [];
		}
		$body = wp_remote_retrieve_body( $response );
		return json_decode( $body, true ) ?: [];
	}
}

/**
 * Pobiera założone (is_equipped=true) przedmioty postaci z cyber_character_inventory.
 */
if ( ! function_exists( 'get_character_equipped_items' ) ) {
	function get_character_equipped_items( $character_id ) {
		$safe_id = preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) $character_id );

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
/**
 * Helper RPC: wywołuje funkcję Postgres przez POST /rest/v1/rpc/{function_name}
 *
 * Użycie:
 *   $rows = tw_supabase_rpc( 'get_player_achievements', [
 *       'p_user_id'      => 5,
 *       'p_character_id' => null,
 *       'p_type'         => 'all',
 *   ] );
 *
 * Zwraca tablicę wyników albo [] przy błędzie.
 */
if ( ! function_exists( 'tw_supabase_rpc' ) ) {
    function tw_supabase_rpc( $function_name, $params = [], $extra_args = [] ) {
        $base = tw_supabase_rest_base();
        if ( empty( $base ) ) {
            error_log( 'TW RPC error: empty rest base' );
            return [];
        }

        $function_name = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $function_name );
        if ( $function_name === '' ) {
            error_log( 'TW RPC error: empty function name' );
            return [];
        }

        if ( ! function_exists( 'tw_supabase_anon_key' ) ) {
            error_log( 'TW RPC error: anon key helper missing' );
            return [];
        }

        $url = $base . 'rpc/' . $function_name;

        $default_args = [
            'method'  => 'POST',
            'headers' => [
                'apikey'        => tw_supabase_anon_key(),
                'Authorization' => 'Bearer ' . tw_supabase_anon_key(),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'      => wp_json_encode( (object) $params ),
            'timeout'   => 15,
            'sslverify' => true,
        ];

        $args = array_merge( $default_args, (array) $extra_args );

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            error_log( 'TW RPC wp_error (' . $function_name . '): ' . print_r( $response, true ) );
            return [];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        error_log( 'TW RPC ' . $function_name . ' HTTP ' . $code . ': ' . $body );

        $data = json_decode( $body, true );

        if ( $code < 200 || $code >= 300 ) {
            return [];
        }

        return is_array( $data ) ? $data : [];
    }
}
