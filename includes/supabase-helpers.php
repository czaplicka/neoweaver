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
 *   tw_supabase_request()      → service key (serwer-side writes, omija RLS)
 *   tw_supabase_rpc()          → anon key (SECURITY DEFINER w Postgres omija RLS samo w sobie)
 *   tw_user_owns_character()   → service key (security guard, MUSI być niezawodny)
 *   Frontend JS                → zawsze anon key + JWT usera
 */

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

// ============================================================
// tw_supabase_get() — GET zapytania (anon key, respektuje RLS)
// Zwraca: array przy sukcesie, WP_Error przy błędzie sieci lub HTTP ≥ 300
// ============================================================

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

		// Merge nagłówków: extra_args['headers'] nadpisuje/rozszerza domyślne.
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
			[ 'headers' => $merged_headers ] // headers zawsze po merge
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

// ============================================================
// tw_supabase_get_admin() — GET z SERVICE KEY (omija RLS)
// Używaj tylko po stronie serwera: security guards, ownership checks,
// admin lookups. Nigdy nie wysyłaj service key do przeglądarki.
// Zwraca: array przy sukcesie, WP_Error przy błędzie
// ============================================================

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

// ============================================================
// tw_supabase_request() — POST / PATCH / PUT / DELETE
// Domyślnie używa SERVICE KEY (server-side writes omijają RLS).
// Aby użyć anon key, przekaż headers w $extra_args.
// Zwraca: WP_Error przy błędzie sieci lub HTTP ≥ 300
//         ['ok'=>true, 'code'=>int, 'data'=>mixed] przy sukcesie
// ============================================================

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

		if ( ! defined( 'TW_SUPABASE_SERVICE_KEY' ) ) {
			error_log( 'TW: TW_SUPABASE_SERVICE_KEY not defined — falling back to anon key for ' . $method . ' ' . $endpoint );
			if ( ! function_exists( 'tw_supabase_anon_key' ) ) {
				return new WP_Error( 'tw_supabase_config', 'tw_supabase_anon_key() is not defined.' );
			}
			$write_key = tw_supabase_anon_key();
		} else {
			$write_key = TW_SUPABASE_SERVICE_KEY;
		}

		$url = $base . $endpoint;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$default_headers = [
			'apikey'        => $write_key,
			'Authorization' => 'Bearer ' . $write_key,
			'Content-Type'  => 'application/json',
		];

		// Merge nagłówków: extra_args['headers'] (np. Prefer) nadpisuje/rozszerza.
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
			[ 'headers' => $merged_headers ] // headers zawsze po merge, nie nadpisane przez extra_args
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

// ============================================================
// tw_supabase_rpc() — wywołanie funkcji Postgres przez RPC
// Używa anon key; jeśli funkcja nie ma SECURITY DEFINER i potrzeba
// service key, przekaż headers w $extra_args.
// Zwraca: array przy sukcesie, WP_Error przy błędzie
// ============================================================

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

// ============================================================
// tw_get_data() — stary helper kompatybilności (nie usuwamy, bo
// może być używany w zewnętrznych plikach szablonu)
// ============================================================

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

// ============================================================
// get_character_equipped_items()
// ============================================================

if ( ! function_exists( 'get_character_equipped_items' ) ) {
	function get_character_equipped_items( string $character_id ): array {
		$safe_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', $character_id );

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

		// Zwróć puste array przy WP_Error — caller nie musi sprawdzać.
		return is_wp_error( $result ) ? [] : $result;
	}
}

// ============================================================
// tw_save_user_setting() — SERVICE KEY (pomija RLS)
// ============================================================

if ( ! function_exists( 'tw_save_user_setting' ) ) {
	function tw_save_user_setting( int $wp_user_id, string $key, string $value ): bool {
		if ( ! defined( 'TW_SUPABASE_SERVICE_KEY' ) ) {
			error_log( 'TW: TW_SUPABASE_SERVICE_KEY not defined.' );
			return false;
		}

		// tw_supabase_request() domyślnie już używa service key,
		// ale przekazujemy Prefer header explicite dla upsert.
		$result = tw_supabase_request(
			'POST',
			'cyber_user_settings',
			[],
			[
				'wp_user_id' => $wp_user_id,
				'key'        => $key,
				'value'      => $value,
				'updated_at' => gmdate( 'c' ),
			],
			[
				'headers' => [
					'Prefer' => 'resolution=merge-duplicates',
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

// ============================================================
// tw_get_user_setting() — SERVICE KEY
// ============================================================

if ( ! function_exists( 'tw_get_user_setting' ) ) {
	function tw_get_user_setting( int $wp_user_id, string $key ): ?string {
		if ( ! defined( 'TW_SUPABASE_SERVICE_KEY' ) ) {
			error_log( 'TW: TW_SUPABASE_SERVICE_KEY not defined.' );
			return null;
		}

		$result = tw_supabase_get(
			'cyber_user_settings',
			[
				'wp_user_id' => 'eq.' . $wp_user_id,
				'key'        => 'eq.' . $key,
				'select'     => 'value',
				'limit'      => 1,
			],
			[
				'headers' => [
					'apikey'        => TW_SUPABASE_SERVICE_KEY,
					'Authorization' => 'Bearer ' . TW_SUPABASE_SERVICE_KEY,
				],
			]
		);

		if ( is_wp_error( $result ) ) {
			error_log( 'TW tw_get_user_setting error: ' . $result->get_error_message() );
			return null;
		}

		return $result[0]['value'] ?? null;
	}
}

// ============================================================
// AJAX: tw_ajax_save_user_setting
// ============================================================

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

		// Biała lista kluczy — rozszerzaj tu gdy dodajesz nowe preferencje.
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

/**
 * Sprawdza czy postać należy do zalogowanego usera.
 *
 * SECURITY: używa SERVICE KEY — anon key bez JWT byłby zablokowany przez RLS
 * i zawsze zwracałby false, czyniąc ten guard bezużytecznym.
 * tw_supabase_get_admin() nigdy nie wysyła service key do przeglądarki.
 */
if ( ! function_exists( 'tw_user_owns_character' ) ) {
	function tw_user_owns_character( string $char_id, int $user_id ): bool {
		if ( empty( $char_id ) || $user_id <= 0 ) {
			return false;
		}

		$safe_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', $char_id );
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
