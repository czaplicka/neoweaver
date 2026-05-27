<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NEOWEAVER — SUPABASE HELPERS
 *
 * Jedyne miejsce gdzie definiujemy niskpoziomowe helpery HTTP do Supabase.
 * Kontrakt zwracanych wartości:
 *
 *   tw_supabase_get()     → array (może być []) LUB WP_Error przy błędzie sieci/HTTP
 *   tw_supabase_request() → WP_Error przy błędzie sieci lub HTTP ≥ 300
 *                           array ['ok'=>true, 'code'=>int, 'data'=>mixed] przy sukcesie
 *   tw_supabase_rpc()     → array (może być []) LUB WP_Error przy błędzie
 *
 * Wszystkie wywołujące handlery sprawdzają is_wp_error() zanim użyją wyniku.
 *
 * KLUCZE:
 *   tw_supabase_get()          → anon key (odczyty, respektuje RLS z JWT usera)
 *   tw_supabase_get_admin()    → service key (server-side reads omijające RLS)
 *   tw_supabase_request()      → service key (serwer-side writes, omija RLS); fail-fast bez klucza
 *   tw_supabase_rpc()          → anon key (SECURITY DEFINER w Postgres omija RLS samo w sobie)
 *   tw_user_owns_character()   → service key (security guard, MUSI być niezawodny)
 *   Frontend JS                → zawsze anon key + JWT usera
 */

// ============================================================
// UUID SANITIZATION — single source of truth
// ============================================================

if ( ! function_exists( 'nw_sanitize_uuid' ) ) {
	/**
	 * Sanitize a UUID v4 string for safe use in Supabase query parameters.
	 *
	 * Lowercases the input, then strips every character that is not a
	 * lowercase hex digit (a-f, 0-9) or a hyphen. This guarantees:
	 *  - consistent casing (string comparisons are safe)
	 *  - only UUID-legal characters remain
	 *  - no SQL/URL injection via the ID field
	 *
	 * @param string $raw Raw UUID string from user input or DB.
	 * @return string     Sanitized UUID, or '' if nothing valid remains.
	 */
	function nw_sanitize_uuid( string $raw ): string {
		return preg_replace( '/[^a-f0-9\-]/', '', strtolower( $raw ) );
	}
}

// ============================================================
// BASE HELPERS
// ============================================================

if ( ! function_exists( 'tw_supabase_rest_base' ) ) {
	function tw_supabase_rest_base(): string {
		if ( ! function_exists( 'tw_supabase_url' ) ) {
			error_log( 'TW: tw_supabase_url() is not defined.' );
			return '';
		}
		return trailingslashit( tw_supabase_url() ) . 'rest/v1/';
	}
}

if ( ! function_exists( 'tw_supabase_get' ) ) {
	function tw_supabase_get( string $endpoint, array $query = [], array $extra_args = [] ) {
		$base = tw_supabase_rest_base();
		if ( empty( $base ) ) {
			return new WP_Error( 'tw_supabase_config', 'Supabase REST base URL not configured.' );
		}

		$endpoint = ltrim( $endpoint, '/' );
		if ( $endpoint === '' ) {
			return new WP_Error( 'tw_supabase_args', 'tw_supabase_get: empty endpoint.' );
		}

		if ( ! function_exists( 'tw_supabase_anon_key' ) ) {
			return new WP_Error( 'tw_supabase_config', 'tw_supabase_anon_key() is not defined.' );
		}

		$url = $base . $endpoint;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$anon_key = tw_supabase_anon_key();
		$default_headers = [
			'apikey'        => $anon_key,
			'Authorization' => 'Bearer ' . $anon_key,
		];

		$merged_headers = array_merge(
			$default_headers,
			(array) ( $extra_args['headers'] ?? [] )
		);

		$args = array_merge(
			[
				'timeout'   => 15,
				'sslverify' => true,
			],
			$extra_args,
			[ 'headers' => $merged_headers ]
		);

		if ( ! is_numeric( $args['timeout'] ?? null ) ) {
			$args['timeout'] = 15;
		}

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			error_log( 'TW tw_supabase_get network error: ' . $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$body = wp_remote_retrieve_body( $response );
			error_log( 'TW tw_supabase_get HTTP ' . $code . ' on ' . $endpoint . ': ' . $body );
			return new WP_Error(
				'tw_supabase_http_' . $code,
				'Supabase HTTP error ' . $code,
				[ 'status' => $code, 'body' => $body ]
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : [];
	}
}

if ( ! function_exists( 'tw_supabase_get_admin' ) ) {
	function tw_supabase_get_admin( string $endpoint, array $query = [] ) {
		if ( ! defined( 'TW_SUPABASE_SERVICE_KEY' ) || ! TW_SUPABASE_SERVICE_KEY ) {
			error_log( 'TW tw_supabase_get_admin: TW_SUPABASE_SERVICE_KEY not defined.' );
			return new WP_Error( 'tw_supabase_config', 'TW_SUPABASE_SERVICE_KEY not configured.' );
		}

		return tw_supabase_get(
			$endpoint,
			$query,
			[
				'headers' => [
					'apikey'        => TW_SUPABASE_SERVICE_KEY,
					'Authorization' => 'Bearer ' . TW_SUPABASE_SERVICE_KEY,
				],
			]
		);
	}
}

if ( ! function_exists( 'tw_supabase_request' ) ) {
	function tw_supabase_request( string $method, string $endpoint, array $query = [], $body = null, array $extra_args = [] ) {
		$base = tw_supabase_rest_base();
		if ( empty( $base ) ) {
			return new WP_Error( 'tw_supabase_config', 'Supabase REST base URL not configured.' );
		}

		$endpoint = ltrim( $endpoint, '/' );
		if ( $endpoint === '' ) {
			return new WP_Error( 'tw_supabase_args', 'tw_supabase_request: empty endpoint.' );
		}

		if ( ! defined( 'TW_SUPABASE_SERVICE_KEY' ) || ! TW_SUPABASE_SERVICE_KEY ) {
			error_log( 'TW tw_supabase_request: TW_SUPABASE_SERVICE_KEY not configured. Refusing ' . $method . ' ' . $endpoint . '.' );
			return new WP_Error( 'tw_supabase_config', 'TW_SUPABASE_SERVICE_KEY not configured.' );
		}

		$write_key = TW_SUPABASE_SERVICE_KEY;

		$url = $base . $endpoint;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$default_headers = [
			'apikey'        => $write_key,
			'Authorization' => 'Bearer ' . $write_key,
			'Content-Type'  => 'application/json',
		];

		$merged_headers = array_merge(
			$default_headers,
			(array) ( $extra_args['headers'] ?? [] )
		);

		$args = array_merge(
			[
				'method'    => strtoupper( $method ),
				'timeout'   => 15,
				'sslverify' => true,
			],
			$extra_args,
			[ 'headers' => $merged_headers ]
		);

		if ( ! is_numeric( $args['timeout'] ?? null ) ) {
			$args['timeout'] = 15;
		}

		if ( ! is_null( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			error_log( 'TW tw_supabase_request network error (' . $method . ' ' . $endpoint . '): ' . $response->get_error_message() );
			return $response;
		}

		$code      = wp_remote_retrieve_response_code( $response );
		$body_raw  = wp_remote_retrieve_body( $response );
		$data      = json_decode( $body_raw, true );

		if ( $code < 200 || $code >= 300 ) {
			error_log( 'TW tw_supabase_request HTTP ' . $code . ' (' . $method . ' ' . $endpoint . '): ' . $body_raw );
			return new WP_Error(
				'tw_supabase_http_' . $code,
				'Supabase HTTP error ' . $code,
				[ 'status' => $code, 'body' => $body_raw, 'data' => $data ]
			);
		}

		return [
			'ok'   => true,
			'code' => $code,
			'data' => $data,
		];
	}
}

if ( ! function_exists( 'tw_supabase_rpc' ) ) {
	function tw_supabase_rpc( string $function_name, array $params = [], array $extra_args = [] ) {
		$base = tw_supabase_rest_base();
		if ( empty( $base ) ) {
			return new WP_Error( 'tw_supabase_config', 'Supabase REST base URL not configured.' );
		}

		$function_name = preg_replace( '/[^a-zA-Z0-9_]/', '', $function_name );
		if ( $function_name === '' ) {
			return new WP_Error( 'tw_supabase_args', 'tw_supabase_rpc: empty function name.' );
		}

		if ( ! function_exists( 'tw_supabase_anon_key' ) ) {
			return new WP_Error( 'tw_supabase_config', 'tw_supabase_anon_key() is not defined.' );
		}

		$url = $base . 'rpc/' . $function_name;

		$anon_key = tw_supabase_anon_key();
		$default_headers = [
			'apikey'        => $anon_key,
			'Authorization' => 'Bearer ' . $anon_key,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		];

		$merged_headers = array_merge(
			$default_headers,
			(array) ( $extra_args['headers'] ?? [] )
		);

		$args = array_merge(
			[
				'method'    => 'POST',
				'timeout'   => 15,
				'sslverify' => true,
			],
			$extra_args,
			[
				'headers' => $merged_headers,
				'body'    => wp_json_encode( (object) $params ),
			]
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			error_log( 'TW tw_supabase_rpc network error (' . $function_name . '): ' . $response->get_error_message() );
			return $response;
		}

		$code     = wp_remote_retrieve_response_code( $response );
		$body_raw = wp_remote_retrieve_body( $response );
		$data     = json_decode( $body_raw, true );

		if ( $code < 200 || $code >= 300 ) {
			error_log( 'TW tw_supabase_rpc HTTP ' . $code . ' (' . $function_name . '): ' . $body_raw );
			return new WP_Error(
				'tw_supabase_http_' . $code,
				'Supabase RPC error ' . $code,
				[ 'status' => $code, 'body' => $body_raw, 'data' => $data ]
			);
		}

		return is_array( $data ) ? $data : [];
	}
}

if ( ! function_exists( 'tw_get_data' ) ) {
	function tw_get_data( string $url, array $args = [] ): array {
		$args = array_merge(
			[ 'timeout' => 15, 'sslverify' => true ],
			$args
		);

		if ( ! is_numeric( $args['timeout'] ?? null ) ) {
			$args['timeout'] = 15;
		}

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			error_log( 'TW tw_get_data error: ' . $response->get_error_message() );
			return [];
		}

		return json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];
	}
}

if ( ! function_exists( 'get_character_equipped_items' ) ) {
	function get_character_equipped_items( string $character_id ): array {
		$safe_id = nw_sanitize_uuid( $character_id );

		if ( empty( $safe_id ) ) {
			error_log( 'TW get_character_equipped_items: invalid character_id.' );
			return [];
		}

		$result = tw_supabase_get(
			'cyber_character_inventory',
			[
				'character_id' => 'eq.' . $safe_id,
				'is_equipped'  => 'eq.true',
				'select'       => 'quantity,cyber_items(name,img_url,slot,rarity,size)',
			]
		);

		return is_wp_error( $result ) ? [] : $result;
	}
}

if ( ! function_exists( 'tw_save_user_setting' ) ) {
	/**
	 * Upsert a user setting row in cyber_user_settings.
	 *
	 * Uses PATCH on an exact (wp_user_id, key) match so PostgREST updates
	 * the existing row, and falls back to POST with on_conflict when the row
	 * doesn't yet exist. The simpler approach is a true upsert via POST with
	 * Prefer: resolution=merge-duplicates AND on_conflict=wp_user_id,key —
	 * which requires a UNIQUE constraint on (wp_user_id, key) in Supabase.
	 * That constraint is assumed to exist; if it doesn't, add it:
	 *   ALTER TABLE cyber_user_settings
	 *     ADD CONSTRAINT uq_user_settings_user_key UNIQUE (wp_user_id, key);
	 */
	function tw_save_user_setting( int $wp_user_id, string $key, string $value ): bool {
		$result = tw_supabase_request(
			'POST',
			'cyber_user_settings',
			[ 'on_conflict' => 'wp_user_id,key' ],
			[
				'wp_user_id' => $wp_user_id,
				'key'        => $key,
				'value'      => $value,
				'updated_at' => gmdate( 'c' ),
			],
			[
				'headers' => [
					'Prefer' => 'resolution=merge-duplicates,return=minimal',
				],
			]
		);

		if ( is_wp_error( $result ) ) {
			error_log( 'TW tw_save_user_setting error: ' . $result->get_error_message() );
			return false;
		}

		return true;
	}
}

if ( ! function_exists( 'tw_get_user_setting' ) ) {
	function tw_get_user_setting( int $wp_user_id, string $key ): ?string {
		$result = tw_supabase_get_admin(
			'cyber_user_settings',
			[
				'wp_user_id' => 'eq.' . $wp_user_id,
				'key'        => 'eq.' . $key,
				'select'     => 'value',
				'limit'      => 1,
			]
		);

		if ( is_wp_error( $result ) ) {
			error_log( 'TW tw_get_user_setting error: ' . $result->get_error_message() );
			return null;
		}

		return $result[0]['value'] ?? null;
	}
}

add_action( 'wp_ajax_tw_save_user_setting', 'tw_ajax_save_user_setting' );

if ( ! function_exists( 'tw_ajax_save_user_setting' ) ) {
	function tw_ajax_save_user_setting(): void {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
			return;
		}

		check_ajax_referer( 'tw_user_setting', 'nonce' );

		$key   = sanitize_key( $_POST['key'] ?? '' );
		$value = sanitize_text_field( $_POST['value'] ?? '' );

		$allowed_keys = [ 'onboarding_dismissed' ];

		if ( empty( $key ) || ! in_array( $key, $allowed_keys, true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid key' ], 400 );
			return;
		}

		$success = tw_save_user_setting( get_current_user_id(), $key, $value );

		if ( $success ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( [ 'message' => 'Supabase error' ], 500 );
		}
	}
}

if ( ! function_exists( 'tw_user_owns_character' ) ) {
	function tw_user_owns_character( string $char_id, int $user_id ): bool {
		if ( empty( $char_id ) || $user_id <= 0 ) {
			return false;
		}

		$safe_id = nw_sanitize_uuid( $char_id );
		if ( empty( $safe_id ) ) {
			return false;
		}

		$result = tw_supabase_get_admin(
			'cyber_characters',
			[
				'id'         => 'eq.' . $safe_id,
				'wp_user_id' => 'eq.' . $user_id,
				'select'     => 'id',
				'limit'      => 1,
			]
		);

		return ! is_wp_error( $result ) && ! empty( $result );
	}
}

// ============================================================
// get_cyber_character_id_by_wp_id()
// ============================================================

if ( ! function_exists( 'get_cyber_character_id_by_wp_id' ) ) {
	function get_cyber_character_id_by_wp_id( int $wp_user_id ): string {
		if ( $wp_user_id <= 0 ) {
			return '';
		}

		$result = tw_supabase_get_admin(
			'cyber_characters',
			[
				'wp_user_id' => 'eq.' . $wp_user_id,
				'select'     => 'id',
				'limit'      => 1,
			]
		);

		if ( is_wp_error( $result ) || empty( $result ) ) {
			return '';
		}

		$id = $result[0]['id'] ?? '';
		return is_string( $id ) ? $id : '';
	}
}

// ============================================================
// fetch_foundry_data()
// ============================================================

if ( ! function_exists( 'fetch_foundry_data' ) ) {
	function fetch_foundry_data( string $character_id ) {
		$safe_id = nw_sanitize_uuid( $character_id );

		if ( empty( $safe_id ) ) {
			return new WP_Error( 'tw_foundry', 'Invalid character_id.' );
		}

		$rows = tw_supabase_get_admin(
			'cyber_character_deck',
			[
				'character_id' => 'eq.' . $safe_id,
				'select'       => 'id,deck_id,current_level,cyber_deck(name)',
				'order'        => 'deck_id.asc',
			]
		);

		if ( is_wp_error( $rows ) ) {
			error_log( 'TW fetch_foundry_data error: ' . $rows->get_error_message() );
			return $rows;
		}

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$grouped = [];
		foreach ( $rows as $row ) {
			// deck_id is a UUID — keep it as string, never cast to int.
			$deck_id = (string) ( $row['deck_id'] ?? '' );
			if ( '' === $deck_id ) {
				continue;
			}
			if ( ! isset( $grouped[ $deck_id ] ) ) {
				$grouped[ $deck_id ] = [
					'instance_id'     => (string) ( $row['id'] ?? '' ),
					'name'            => (string) ( $row['cyber_deck']['name'] ?? '[UNKNOWN]' ),
					'level'           => (int) ( $row['current_level'] ?? 1 ),
					'duplicate_count' => 0,
				];
			} else {
				$grouped[ $deck_id ]['duplicate_count']++;
			}
		}

		$result = [];
		foreach ( $grouped as $item ) {
			$obj                  = new stdClass();
			$obj->instance_id     = $item['instance_id'];
			$obj->name            = $item['name'];
			$obj->level           = $item['level'];
			$obj->duplicate_count = $item['duplicate_count'];
			$result[]             = $obj;
		}

		return $result;
	}
}

// ============================================================
// get_cyber_player_credits()
// Zwraca gold gracza z cyber_characters.
// ============================================================

if ( ! function_exists( 'get_cyber_player_credits' ) ) {
	function get_cyber_player_credits( string $character_id ): int {
		$safe_id = nw_sanitize_uuid( $character_id );

		if ( empty( $safe_id ) ) {
			return 0;
		}

		$result = tw_supabase_get_admin(
			'cyber_characters',
			[
				'id'     => 'eq.' . $safe_id,
				'select' => 'gold',
				'limit'  => 1,
			]
		);

		if ( is_wp_error( $result ) || empty( $result ) ) {
			return 0;
		}

		return (int) ( $result[0]['gold'] ?? 0 );
	}
}
