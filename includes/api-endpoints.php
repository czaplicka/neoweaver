<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Shared permission callback
// ---------------------------------------------------------------------------
if ( ! function_exists( 'neoweaver_user_can_play' ) ) {
	function neoweaver_user_can_play(): bool {
		return is_user_logged_in();
	}
}

// ---------------------------------------------------------------------------
// Shared Supabase header builders
// ---------------------------------------------------------------------------

/**
 * Anon key — use for READ operations (GET) only.
 */
function nw_supabase_headers( bool $with_prefer = false ): array {
	$key     = function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '';
	$headers = [
		'apikey'        => $key,
		'Authorization' => 'Bearer ' . $key,
		'Content-Type'  => 'application/json',
	];
	if ( $with_prefer ) {
		$headers['Prefer'] = 'return=representation';
	}
	return $headers;
}

/**
 * Service role key — use for all server-side WRITE operations (POST, PATCH, DELETE).
 * Bypasses RLS so mutations are never silently blocked.
 *
 * Uses TW_SUPABASE_SERVICE_KEY defined in wp-config.php.
 * $with_prefer adds 'return=representation' (needed when you need the row back).
 * Pass a custom $prefer string to override (e.g. 'resolution=merge-duplicates').
 */
function nw_supabase_service_headers( bool $with_prefer = false, string $prefer = '' ): array {
	$key     = defined( 'TW_SUPABASE_SERVICE_KEY' ) ? TW_SUPABASE_SERVICE_KEY : ''; // BUG4 FIX: was NW_SUPABASE_SERVICE_KEY
	$headers = [
		'apikey'        => $key,
		'Authorization' => 'Bearer ' . $key,
		'Content-Type'  => 'application/json',
	];
	if ( $prefer !== '' ) {
		$headers['Prefer'] = $prefer;
	} elseif ( $with_prefer ) {
		$headers['Prefer'] = 'return=representation';
	}
	return $headers;
}

function nw_supabase_base(): string {
	return function_exists( 'tw_supabase_url' ) ? trailingslashit( tw_supabase_url() ) . 'rest/v1/' : '';
}

// ===========================================================================
// WORLD / NODE ENDPOINT
// ===========================================================================

/**
 * @return WP_REST_Response|WP_Error
 */
function neoweaver_create_world( WP_REST_Request $request ) {
	// FIX: removed unconditional error_log('TW_ENDPOINT_WORLD: START (REST API)') —
	// it fired on every world creation request in production, spamming the error log.
	// Error-path logs below are kept (they fire only on actual failures).

	// SEC FIX: nonce must come from X-WP-Nonce header, not body param.
	// Body params are not part of WP REST nonce convention and break cookie auth.
	$nonce = $request->get_header( 'X-WP-Nonce' ) ?? '';
	if ( ! wp_verify_nonce( $nonce, 'tw_world_nonce' ) ) {
		return new WP_Error( 'nonce_failed', 'Nonce verification failed.', [ 'status' => 403 ] );
	}

	$wp_user_id = get_current_user_id();
	if ( ! $wp_user_id ) {
		return new WP_Error( 'unauthorized', 'Unauthorized.', [ 'status' => 401 ] );
	}

	$base    = nw_supabase_base();
	$anonkey = function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '';
	if ( ! $base || ! $anonkey ) {
		return new WP_Error( 'config_missing', 'Supabase config missing.', [ 'status' => 500 ] );
	}

	$payload = [
		'wp_user_id'        => $wp_user_id,
		'name'              => sanitize_text_field( $request->get_param( 'name' ) ?? '' ),
		'description'       => sanitize_textarea_field( $request->get_param( 'description' ) ?? '' ),
		'size'              => max( 1, min( 5, (int) ( $request->get_param( 'size' )       ?? 3 ) ) ),
		'wealth'            => max( 1, min( 5, (int) ( $request->get_param( 'wealth' )     ?? 3 ) ) ),
		'difficulty'        => max( 1, min( 5, (int) ( $request->get_param( 'difficulty' ) ?? 3 ) ) ),
		'magic'             => max( 1, min( 5, (int) ( $request->get_param( 'magic' )      ?? 3 ) ) ),
		'gods'              => max( 1, min( 5, (int) ( $request->get_param( 'gods' )       ?? 3 ) ) ),
		'technology'        => max( 1, min( 5, (int) ( $request->get_param( 'technology' ) ?? 3 ) ) ),
		'relations'         => max( 1, min( 5, (int) ( $request->get_param( 'relations' )  ?? 3 ) ) ),
		'moral'             => max( 1, min( 3, (int) ( $request->get_param( 'moral' )      ?? 2 ) ) ),
		'customize'         => sanitize_textarea_field( $request->get_param( 'customize' ) ?? '' ),
		'population_status' => 'empty',
	];

	if ( empty( $payload['name'] ) ) {
		return new WP_Error( 'missing_name', 'World name is required.', [ 'status' => 400 ] );
	}

	// WRITE — service key
	$insert = wp_remote_post( $base . 'cyber_worlds', [
		'headers' => nw_supabase_service_headers( true ),
		'body'    => wp_json_encode( $payload ),
		'timeout' => 15,
	] );

	if ( is_wp_error( $insert ) ) {
		error_log( 'TW_ENDPOINT_WORLD: Supabase insert error — ' . $insert->get_error_message() );
		return new WP_Error( 'supabase_error', 'Database error.', [ 'status' => 500 ] );
	}

	$code     = wp_remote_retrieve_response_code( $insert );
	$raw_body = wp_remote_retrieve_body( $insert );
	$body     = json_decode( $raw_body, true );

	if ( $code < 200 || $code >= 300 ) {
		error_log( 'TW_ENDPOINT_WORLD: Supabase HTTP ' . $code . ' — ' . $raw_body );
		return new WP_Error( 'http_error', 'Supabase HTTP ' . $code, [ 'status' => $code ] );
	}

	$world_id = $body[0]['id'] ?? null;
	if ( ! $world_id ) {
		return new WP_Error( 'no_id', 'World created but no ID returned.', [ 'status' => 500 ] );
	}

	$safe_world_id = preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) $world_id );

	$rpc_base  = nw_supabase_base() . 'rpc/';
	$world_arg = wp_json_encode( [ 'p_world_id' => $safe_world_id ] );

	// RPC calls are writes — service key
	foreach ( [ 'fn_seed_complete_world_rpc', 'fn_seed_world_tags', 'fn_world_tags_to_globals_random' ] as $rpc ) {
		$rpc_res = wp_remote_post( $rpc_base . $rpc, [
			'headers' => nw_supabase_service_headers(),
			'body'    => $world_arg,
			'timeout' => 45,
		] );
		if ( is_wp_error( $rpc_res ) ) {
			error_log( 'TW_ENDPOINT_WORLD: RPC ' . $rpc . ' error — ' . $rpc_res->get_error_message() );
		} else {
			$rpc_code = wp_remote_retrieve_response_code( $rpc_res );
			if ( $rpc_code < 200 || $rpc_code >= 300 ) {
				error_log( 'TW_ENDPOINT_WORLD: RPC ' . $rpc . ' HTTP ' . $rpc_code . ' — ' . wp_remote_retrieve_body( $rpc_res ) );
			}
		}
	}

	return rest_ensure_response( [
		'success' => true,
		'data'    => [
			'worldid' => $safe_world_id,
			'message' => 'World created and seeding launched.',
		],
	] );
}

// ===========================================================================
// WORLD LIST ENDPOINT
// ===========================================================================

/**
 * GET /wp-json/neoweaver/v1/worlds/list
 * Returns all worlds belonging to the logged-in user.
 * SEC FIX: uses anon key so RLS enforces ownership as a second layer of defence.
 * wp_user_id filter is the primary guard; RLS is the safety net.
 *
 * @return WP_REST_Response|WP_Error
 */
function neoweaver_list_worlds( WP_REST_Request $request ) {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return new WP_Error( 'unauthorized', 'Unauthorized.', [ 'status' => 401 ] );
	}

	$base = nw_supabase_base();
	if ( ! $base ) {
		return new WP_Error( 'config_missing', 'Supabase config missing.', [ 'status' => 500 ] );
	}

	$url = add_query_arg( [
		'wp_user_id' => 'eq.' . $user_id,
		'select'     => 'id,name,description,size,wealth,difficulty,magic,technology,created_at',
		'order'      => 'created_at.desc',
	], $base . 'cyber_worlds' );

	// SEC FIX: READ with anon key — RLS enforces ownership as safety net.
	// Previously used service key which bypasses RLS entirely.
	$response = wp_remote_get( $url, [
		'headers' => nw_supabase_headers(),
		'timeout' => 15,
	] );

	if ( is_wp_error( $response ) ) {
		error_log( 'TW_ENDPOINT_WORLDS_LIST: Supabase error — ' . $response->get_error_message() );
		return new WP_Error( 'supabase_error', 'Database connection error.', [ 'status' => 500 ] );
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		error_log( 'TW_ENDPOINT_WORLDS_LIST: HTTP ' . $code . ' — ' . wp_remote_retrieve_body( $response ) );
		return new WP_Error( 'http_error', 'Supabase HTTP ' . $code, [ 'status' => $code ] );
	}

	$worlds = json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];

	return rest_ensure_response( [ 'success' => true, 'data' => $worlds ] );
}

// ===========================================================================
// WORLD DELETE ENDPOINT
// ===========================================================================

/**
 * POST /wp-json/neoweaver/v1/worlds/delete
 *
 * @return WP_REST_Response|WP_Error
 */
function neoweaver_delete_world( WP_REST_Request $request ) {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return new WP_Error( 'unauthorized', 'Unauthorized.', [ 'status' => 401 ] );
	}

	$world_id = preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) ( $request->get_param( 'world_id' ) ?? '' ) );
	if ( ! $world_id ) {
		return new WP_Error( 'missing_world_id', 'Missing world_id.', [ 'status' => 400 ] );
	}

	$base = nw_supabase_base();
	if ( ! $base ) {
		return new WP_Error( 'config_missing', 'Supabase config missing.', [ 'status' => 500 ] );
	}

	// BUG 26 FIX (applied here): ownership check uses service key, not anon key.
	// The anon-key ownership-check anti-pattern was identified and fixed in
	// class-deployments-creator.php — this function had the same bug: when RLS
	// blocks anon reads on cyber_worlds, the check returns empty and the endpoint
	// returns 404 for legitimate owners (false negative), even though the DELETE
	// itself would succeed. Service key bypasses RLS so the check is reliable.
	// This is safe: the wp_user_id filter is the ownership guard; the service key
	// only ensures the check is never silently blocked by RLS.
	$check_url = add_query_arg( [
		'id'         => 'eq.' . $world_id,
		'wp_user_id' => 'eq.' . $user_id,
		'select'     => 'id',
		'limit'      => 1,
	], $base . 'cyber_worlds' );

	$check      = wp_remote_get( $check_url, [ 'headers' => nw_supabase_service_headers(), 'timeout' => 10 ] );
	$check_code = wp_remote_retrieve_response_code( $check );
	// SEC FIX: verify HTTP status before trusting the response body.
	// Previously a non-2xx response (e.g. 500) with empty body would pass the ownership check.
	if ( is_wp_error( $check ) || $check_code < 200 || $check_code >= 300
		|| empty( json_decode( wp_remote_retrieve_body( $check ), true ) ) ) {
		return new WP_Error( 'not_found', 'World not found or access denied.', [ 'status' => 404 ] );
	}

	// Call RPC fn_delete_world (handles cascading deletes)
	$rpc_resp = wp_remote_post( $base . 'rpc/fn_delete_world', [
		'headers' => nw_supabase_service_headers(),
		'body'    => wp_json_encode( [ 'p_world_id' => $world_id ] ),
		'timeout' => 20,
	] );

	if ( is_wp_error( $rpc_resp ) ) {
		return new WP_Error( 'supabase_error', 'Database error.', [ 'status' => 500 ] );
	}

	$code = wp_remote_retrieve_response_code( $rpc_resp );
	if ( $code < 200 || $code >= 300 ) {
		error_log( 'TW_WORLD_DELETE: RPC HTTP ' . $code . ' — ' . wp_remote_retrieve_body( $rpc_resp ) );
		return new WP_Error( 'delete_failed', 'Delete failed. HTTP ' . $code, [ 'status' => $code ] );
	}

	return rest_ensure_response( [ 'success' => true, 'data' => [ 'world_id' => $world_id ] ] );
}

/**
 * @return WP_REST_Response|WP_Error
 */
function neoweaver_create_character( WP_REST_Request $request ) {
	// FIX: removed unconditional error_log('TW_ENDPOINT_CHARACTER: START (REST API)') —
	// same issue as neoweaver_create_world; fires on every request in production.

	// SEC FIX: nonce from X-WP-Nonce header (same fix as neoweaver_create_world).
	$nonce = $request->get_header( 'X-WP-Nonce' ) ?? '';
	if ( ! wp_verify_nonce( $nonce, 'tw_character_nonce' ) ) {
		return new WP_Error( 'nonce_failed', 'Nonce verification failed.', [ 'status' => 403 ] );
	}

	$wp_user_id = get_current_user_id();
	if ( ! $wp_user_id ) {
		return new WP_Error( 'unauthorized', 'Unauthorized.', [ 'status' => 401 ] );
	}

	$base    = nw_supabase_base();
	$anonkey = function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '';
	if ( ! $base || ! $anonkey ) {
		return new WP_Error( 'config_missing', 'Supabase config missing.', [ 'status' => 500 ] );
	}

	$character_name = sanitize_text_field( $request->get_param( 'character_name' ) ?? '' );
	if ( empty( $character_name ) ) {
		return new WP_Error( 'missing_name', 'Missing character name.', [ 'status' => 400 ] );
	}

	$race_id  = sanitize_text_field( $request->get_param( 'race' )  ?? '' );
	$class_id = sanitize_text_field( $request->get_param( 'class' ) ?? '' );
	if ( empty( $race_id ) || empty( $class_id ) ) {
		return new WP_Error( 'missing_race_class', 'Race and class are required.', [ 'status' => 400 ] );
	}

	// FK EXISTENCE CHECK — race_id
	// BUG FIX: previously race_id and class_id were passed straight to $creator->create()
	// without verifying the UUIDs exist in cyber_races / cyber_classes. A nonexistent UUID
	// triggers a Postgres FK violation that surfaces as an opaque 500. We now validate both
	// upfront and return a clear 400 so the client can surface a meaningful error.
	// Service key is used so RLS never blocks this server-side lookup.
	$safe_race_id = preg_replace( '/[^a-zA-Z0-9\\-]/', '', $race_id );
	$race_check   = wp_remote_get(
		add_query_arg( [ 'id' => 'eq.' . $safe_race_id, 'select' => 'id', 'limit' => 1 ], $base . 'cyber_races' ),
		[ 'headers' => nw_supabase_service_headers(), 'timeout' => 10 ]
	);
	if ( is_wp_error( $race_check )
		|| wp_remote_retrieve_response_code( $race_check ) < 200
		|| wp_remote_retrieve_response_code( $race_check ) >= 300
		|| empty( json_decode( wp_remote_retrieve_body( $race_check ), true ) ) ) {
		return new WP_Error( 'invalid_race', 'Race not found.', [ 'status' => 400 ] );
	}

	// FK EXISTENCE CHECK — class_id
	$safe_class_id = preg_replace( '/[^a-zA-Z0-9\\-]/', '', $class_id );
	$class_check   = wp_remote_get(
		add_query_arg( [ 'id' => 'eq.' . $safe_class_id, 'select' => 'id', 'limit' => 1 ], $base . 'cyber_classes' ),
		[ 'headers' => nw_supabase_service_headers(), 'timeout' => 10 ]
	);
	if ( is_wp_error( $class_check )
		|| wp_remote_retrieve_response_code( $class_check ) < 200
		|| wp_remote_retrieve_response_code( $class_check ) >= 300
		|| empty( json_decode( wp_remote_retrieve_body( $class_check ), true ) ) ) {
		return new WP_Error( 'invalid_class', 'Class not found.', [ 'status' => 400 ] );
	}

	$attr_keys = [ 'attr_body', 'attr_reflex', 'attr_mind', 'attr_spirit' ];

	foreach ( $attr_keys as $key ) {
		if ( null === $request->get_param( $key ) ) {
			return new WP_Error(
				'missing_attribute',
				sprintf( 'Missing required attribute: %s', $key ),
				[ 'status' => 400 ]
			);
		}
	}

	$attr_body   = (int) $request->get_param( 'attr_body' );
	$attr_reflex = (int) $request->get_param( 'attr_reflex' );
	$attr_mind   = (int) $request->get_param( 'attr_mind' );
	$attr_spirit = (int) $request->get_param( 'attr_spirit' );

	foreach (
		[ 'attr_body' => $attr_body, 'attr_reflex' => $attr_reflex, 'attr_mind' => $attr_mind, 'attr_spirit' => $attr_spirit ]
		as $key => $val
	) {
		if ( $val < 1 || $val > 5 ) {
			return new WP_Error(
				'attribute_out_of_range',
				sprintf( 'Attribute %s must be between 1 and 5, got %d.', $key, $val ),
				[ 'status' => 400 ]
			);
		}
	}

	$attr_total = $attr_body + $attr_reflex + $attr_mind + $attr_spirit;
	if ( 12 !== $attr_total ) {
		return new WP_Error(
			'invalid_attributes',
			sprintf( 'Attribute total must equal 12, got %d.', $attr_total ),
			[ 'status' => 400 ]
		);
	}

	$node_id_raw = $request->get_param( 'node_id' );
	$node_id     = $node_id_raw ? preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) $node_id_raw ) : null;

	// BUG5 FIX: correct fallback path is includes/classes/, not includes/agents/
	if ( ! class_exists( 'Neoweaver_Agents_Creator' ) ) {
		$creator_file = plugin_dir_path( __FILE__ ) . 'classes/class-agents-creator.php';
		if ( file_exists( $creator_file ) ) {
			require_once $creator_file;
		}
	}

	if ( ! class_exists( 'Neoweaver_Agents_Creator' ) ) {
		error_log( 'TW_ENDPOINT_CHARACTER: Neoweaver_Agents_Creator class not found — check classes/class-agents-creator.php' );
		return new WP_Error( 'class_missing', 'Character creator class not loaded. Check server logs.', [ 'status' => 500 ] );
	}

	$creator = new Neoweaver_Agents_Creator();
	$data    = [
		'character_name' => $character_name,
		'pronouns'       => sanitize_text_field( $request->get_param( 'pronouns' )  ?? '' ),
		'race'           => $safe_race_id,
		'class'          => $safe_class_id,
		'node_id'        => $node_id,
		'backstory'      => sanitize_textarea_field( $request->get_param( 'backstory' ) ?? '' ),
		'attr_body'      => $attr_body,
		'attr_reflex'    => $attr_reflex,
		'attr_mind'      => $attr_mind,
		'attr_spirit'    => $attr_spirit,
	];

	$agent_id = $creator->create( $data, $wp_user_id );

	if ( ! $agent_id ) {
		return new WP_Error( 'creation_failed', 'Character creation failed. Check server logs.', [ 'status' => 500 ] );
	}

	if ( ! empty( $_FILES['avatar']['tmp_name'] ) ) {
		if ( isset( $_FILES['avatar']['size'] ) && $_FILES['avatar']['size'] > 2 * MB_IN_BYTES ) {
			error_log( 'TW_ENDPOINT_CHARACTER: avatar upload skipped — file exceeds 2 MB' );
		} else {
			if ( ! function_exists( 'wp_handle_upload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$upload = wp_handle_upload( $_FILES['avatar'], [
				'test_form' => false,
				'mimes'     => [
					'jpg|jpeg|jpe' => 'image/jpeg',
					'png'          => 'image/png',
					'webp'         => 'image/webp',
				],
			] );
			if ( ! empty( $upload['url'] ) ) {
				$patch_url = add_query_arg( [ 'id' => 'eq.' . $agent_id ], $base . 'cyber_characters' );
				// WRITE — service key
				wp_remote_request( $patch_url, [
					'method'  => 'PATCH',
					'headers' => nw_supabase_service_headers(),
					'body'    => wp_json_encode( [ 'avatar' => $upload['url'] ] ),
					'timeout' => 10,
				] );
			} elseif ( ! empty( $upload['error'] ) ) {
				error_log( 'TW_ENDPOINT_CHARACTER: avatar upload error — ' . $upload['error'] );
			}
		}
	}

	return rest_ensure_response( [
		'success' => true,
		'data'    => [
			'agent_id' => $agent_id,
			'message'  => 'Character created successfully.',
		],
	] );
}
