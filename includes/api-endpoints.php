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
	error_log( 'TW_ENDPOINT_WORLD: START (REST API)' );

	$nonce = $request->get_param( 'nonce' ) ?? '';
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

	error_log( 'TW_ENDPOINT_WORLD: SUCCESS world_id=' . $safe_world_id );

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
 * Uses service key to bypass RLS (WP auth handles access control).
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

	// READ with service key — bypasses RLS, WP login already verified above
	$response = wp_remote_get( $url, [
		'headers' => nw_supabase_service_headers(),
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

	// Verify ownership before deleting
	$check_url = add_query_arg( [
		'id'         => 'eq.' . $world_id,
		'wp_user_id' => 'eq.' . $user_id,
		'select'     => 'id',
		'limit'      => 1,
	], $base . 'cyber_worlds' );

	$check = wp_remote_get( $check_url, [ 'headers' => nw_supabase_service_headers(), 'timeout' => 10 ] );
	if ( is_wp_error( $check ) || empty( json_decode( wp_remote_retrieve_body( $check ), true ) ) ) {
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

	error_log( 'TW_WORLD_DELETE: SUCCESS world_id=' . $world_id );
	return rest_ensure_response( [ 'success' => true, 'data' => [ 'world_id' => $world_id ] ] );
}

/**
 * @return WP_REST_Response|WP_Error
 */
function neoweaver_create_character( WP_REST_Request $request ) {
	error_log( 'TW_ENDPOINT_CHARACTER: START (REST API)' );

	$nonce = $request->get_param( 'nonce' ) ?? '';
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
		'race'           => $race_id,
		'class'          => $class_id,
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

	error_log( 'TW_ENDPOINT_CHARACTER: SUCCESS agent_id=' . $agent_id );

	return rest_ensure_response( [
		'success' => true,
		'data'    => [
			'agent_id' => $agent_id,
			'message'  => 'Agent created successfully.',
		],
	] );
}

// ===========================================================================
// CHARACTER CHANGE ENDPOINT
// ===========================================================================

/**
 * POST /wp-json/neoweaver/v1/character/change
 * Swap the active character on a campaign (replaces cyber_campaign_characters row).
 * Uses PostgREST upsert (Prefer: resolution=merge-duplicates) to avoid the
 * partial-failure state that would result from a DELETE+INSERT sequence.
 * Fires tw_character_changed after a successful swap.
 *
 * @return WP_REST_Response|WP_Error
 */
function neoweaver_change_character( WP_REST_Request $request ) {
	error_log( 'TW_ENDPOINT_CHARACTER_CHANGE: START' );

	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return new WP_Error( 'unauthorized', 'Unauthorized.', [ 'status' => 401 ] );
	}

	$campaign_id      = preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) ( $request->get_param( 'campaign_id' )      ?? '' ) );
	$new_character_id = preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) ( $request->get_param( 'character_id' )     ?? '' ) );

	if ( ! $campaign_id || ! $new_character_id ) {
		return new WP_Error( 'missing_params', 'campaign_id and character_id are required.', [ 'status' => 400 ] );
	}

	$base = nw_supabase_base();
	if ( ! $base ) {
		return new WP_Error( 'config_missing', 'Supabase config missing.', [ 'status' => 500 ] );
	}

	// Verify campaign belongs to this user
	$camp_url   = add_query_arg( [
		'id'         => 'eq.' . $campaign_id,
		'wp_user_id' => 'eq.' . $user_id,
		'select'     => 'id',
		'limit'      => 1,
	], $base . 'cyber_campaign' );
	$camp_check = wp_remote_get( $camp_url, [ 'headers' => nw_supabase_service_headers(), 'timeout' => 10 ] );
	if ( is_wp_error( $camp_check ) || empty( json_decode( wp_remote_retrieve_body( $camp_check ), true ) ) ) {
		return new WP_Error( 'not_found', 'Campaign not found or access denied.', [ 'status' => 404 ] );
	}

	// Verify character belongs to this user
	$char_url   = add_query_arg( [
		'id'         => 'eq.' . $new_character_id,
		'wp_user_id' => 'eq.' . $user_id,
		'select'     => 'id',
		'limit'      => 1,
	], $base . 'cyber_characters' );
	$char_check = wp_remote_get( $char_url, [ 'headers' => nw_supabase_service_headers(), 'timeout' => 10 ] );
	if ( is_wp_error( $char_check ) || empty( json_decode( wp_remote_retrieve_body( $char_check ), true ) ) ) {
		return new WP_Error( 'not_found', 'Character not found or access denied.', [ 'status' => 404 ] );
	}

	// Read old character_id before the swap (for the hook payload)
	$old_char_id = null;
	$old_url     = add_query_arg( [
		'campaign_id' => 'eq.' . $campaign_id,
		'select'      => 'character_id',
		'limit'       => 1,
	], $base . 'cyber_campaign_characters' );
	$old_resp    = wp_remote_get( $old_url, [ 'headers' => nw_supabase_service_headers(), 'timeout' => 10 ] );
	if ( ! is_wp_error( $old_resp ) ) {
		$old_rows    = json_decode( wp_remote_retrieve_body( $old_resp ), true ) ?: [];
		$old_char_id = ! empty( $old_rows[0]['character_id'] )
			? preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) $old_rows[0]['character_id'] )
			: null;
	}

	// Upsert via PostgREST merge-duplicates — atomic, no partial-failure risk.
	// Requires a UNIQUE constraint on campaign_id in cyber_campaign_characters.
	$upsert_resp = wp_remote_post( $base . 'cyber_campaign_characters', [
		'headers' => nw_supabase_service_headers(
			false,
			'resolution=merge-duplicates,return=representation'
		),
		'body'    => wp_json_encode( [
			'campaign_id'  => $campaign_id,
			'character_id' => $new_character_id,
			'wp_user_id'   => $user_id,
		] ),
		'timeout' => 15,
	] );

	if ( is_wp_error( $upsert_resp ) ) {
		return new WP_Error( 'supabase_error', 'Database error.', [ 'status' => 500 ] );
	}

	$code = wp_remote_retrieve_response_code( $upsert_resp );
	if ( $code < 200 || $code >= 300 ) {
		error_log( 'TW_CHARACTER_CHANGE: HTTP ' . $code . ' — ' . wp_remote_retrieve_body( $upsert_resp ) );
		return new WP_Error( 'change_failed', 'Character change failed. HTTP ' . $code, [ 'status' => $code ] );
	}

	if ( function_exists( 'tw_invalidate_game_data_cache' ) ) {
		tw_invalidate_game_data_cache( $user_id );
	}

	/**
	 * Fires after the active character on a campaign is successfully changed.
	 *
	 * @param int    $user_id  WP user ID.
	 * @param array  $context {
	 *   @type string      $campaign_id       Campaign UUID.
	 *   @type string      $new_character_id  New character
	 */
	do_action( 'tw_character_changed', $user_id, [
		'campaign_id'      => $campaign_id,
		'new_character_id' => $new_character_id,
		'old_character_id' => $old_char_id,
	] );

	error_log( 'TW_CHARACTER_CHANGE: SUCCESS campaign=' . $campaign_id . ' new_char=' . $new_character_id );

	return rest_ensure_response( [
		'success' => true,
		'data'    => [
			'campaign_id'      => $campaign_id,
			'new_character_id' => $new_character_id,
			'old_character_id' => $old_char_id,
		],
	] );
}
