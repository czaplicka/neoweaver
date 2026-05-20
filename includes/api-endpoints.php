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
// Shared Supabase header builder
// ---------------------------------------------------------------------------
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

	$insert = wp_remote_post( $base . 'cyber_worlds', [
		'headers' => nw_supabase_headers( true ),
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
	$rpc_hdrs  = nw_supabase_headers();
	$world_arg = wp_json_encode( [ 'p_world_id' => $safe_world_id ] );

	foreach ( [ 'fn_seed_complete_world_rpc', 'fn_seed_world_tags', 'fn_world_tags_to_globals_random' ] as $rpc ) {
		$rpc_res = wp_remote_post( $rpc_base . $rpc, [
			'headers' => $rpc_hdrs,
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

	if ( ! class_exists( 'Neoweaver_Agents_Creator' ) ) {
		return new WP_Error( 'class_missing', 'Agents creator class not loaded.', [ 'status' => 500 ] );
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
				wp_remote_request( $patch_url, [
					'method'  => 'PATCH',
					'headers' => nw_supabase_headers(),
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
// CAMPAIGN / DEPLOYMENT ENDPOINT
// ===========================================================================

/**
 * @return WP_REST_Response|WP_Error
 */
function neoweaver_create_campaign( WP_REST_Request $request ) {
    error_log( 'TW_ENDPOINT_CAMPAIGN: START (REST API)' );
    error_log( 'TW_CAMPAIGN payload: ' . print_r( $request->get_params(), true ) );

    $nonce = $request->get_param( 'nonce' ) ?? '';
    if ( ! wp_verify_nonce( $nonce, 'tw_campaign_nonce' ) ) {
        return new WP_Error( 'nonce_failed', 'Nonce verification failed.', [ 'status' => 403 ] );
    }

    $wp_user_id = get_current_user_id();
    if ( ! $wp_user_id ) {
        return new WP_Error( 'unauthorized', 'Unauthorized.', [ 'status' => 401 ] );
    }

    if ( ! class_exists( 'Neoweaver_Deployments_Creator' ) ) {
        return new WP_Error( 'class_missing', 'Deployments creator class not loaded.', [ 'status' => 500 ] );
    }

    $creator = new Neoweaver_Deployments_Creator();

    $data = [
        'wp_user_id'  => $wp_user_id,
        'name'        => sanitize_text_field( $request->get_param( 'name' )        ?? '' ),
        'game_mode'   => (int) ( $request->get_param( 'game_mode' )                ?? 1 ),
        'world_type'  => (int) ( $request->get_param( 'world_type' )               ?? 1 ),
        'gm_style'    => sanitize_text_field( $request->get_param( 'gm_style' )    ?? '' ),
        'customize'   => sanitize_textarea_field( $request->get_param( 'customize' ) ?? '' ),
        'game_length' => (int) ( $request->get_param( 'game_length' )              ?? 1 ),
        'priority'    => (int) ( $request->get_param( 'priority' )                 ?? 1 ),
    ];

	if ( empty( $data['name'] ) ) {
		return new WP_Error( 'missing_name', 'Campaign name is required.', [ 'status' => 400 ] );
	}

	$world_id_raw     = $request->get_param( 'world_id' );
	$character_id_raw = $request->get_param( 'character_id' );
	$world_id         = $world_id_raw     ? preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) $world_id_raw )     : null;
	$character_id     = $character_id_raw ? preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) $character_id_raw ) : null;

	$campaign_id = $creator->create( $data, $world_id, $character_id );

	if ( ! $campaign_id ) {
		return new WP_Error( 'creation_failed', 'Campaign creation failed. Check server logs.', [ 'status' => 500 ] );
	}

	error_log( 'TW_ENDPOINT_CAMPAIGN: SUCCESS campaign_id=' . $campaign_id );

	return rest_ensure_response( [
		'success' => true,
		'data'    => [
			'campaign_id' => $campaign_id,
			'message'     => 'Campaign created successfully.',
		],
	] );
}

// ===========================================================================
// SESSION START ENDPOINT
// ===========================================================================

/**
 * @return WP_REST_Response|WP_Error
 */
function neoweaver_start_game_session( WP_REST_Request $request ) {
	error_log( 'TW_ENDPOINT_START_GAME_SESSION: START (REST API)' );

	$nonce = $request->get_param( 'security' ) ?? '';
	if ( ! wp_verify_nonce( $nonce, 'tw_game_nonce' ) ) {
		return new WP_Error( 'nonce_failed', 'Nonce check failed.', [ 'status' => 403 ] );
	}

	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return new WP_Error( 'unauthorized', 'You must be logged in.', [ 'status' => 401 ] );
	}

	$campaign_id_raw = $request->get_param( 'campaign_id' ) ?? '';
	$campaign_id     = preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) $campaign_id_raw );
	if ( ! $campaign_id ) {
		return new WP_Error( 'missing_campaign', 'Missing campaign ID.', [ 'status' => 400 ] );
	}

	$base     = nw_supabase_base();
	$anon_key = function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '';
	if ( ! $base || ! $anon_key ) {
		return new WP_Error( 'config_missing', 'Supabase config missing.', [ 'status' => 500 ] );
	}

	$pause_url = add_query_arg( [
		'wp_user_id'  => 'eq.' . $user_id,
		'status'      => 'eq.active',
		'campaign_id' => 'neq.' . $campaign_id,
	], $base . 'cyber_game_sessions' );

	$pause_resp = wp_remote_request( $pause_url, [
		'method'  => 'PATCH',
		'headers' => nw_supabase_headers(),
		'body'    => wp_json_encode( [ 'status' => 'paused' ] ),
		'timeout' => 10,
	] );

	if ( is_wp_error( $pause_resp ) ) {
		error_log( 'TW_ENDPOINT_START_GAME_SESSION: pause existing sessions failed — ' . $pause_resp->get_error_message() );
	} else {
		$pause_code = wp_remote_retrieve_response_code( $pause_resp );
		if ( $pause_code < 200 || $pause_code >= 300 ) {
			error_log( 'TW_ENDPOINT_START_GAME_SESSION: pause existing sessions HTTP ' . $pause_code . ' — ' . wp_remote_retrieve_body( $pause_resp ) );
		}
	}

	$resume_check_url = add_query_arg( [
		'wp_user_id'  => 'eq.' . $user_id,
		'campaign_id' => 'eq.' . $campaign_id,
		'status'      => 'eq.paused',
		'select'      => 'id,character_id,world_id',
		'order'       => 'updated_at.desc',
		'limit'       => 1,
	], $base . 'cyber_game_sessions' );

	$resume_resp = wp_remote_get( $resume_check_url, [ 'headers' => nw_supabase_headers(), 'timeout' => 10 ] );

	if ( ! is_wp_error( $resume_resp ) && wp_remote_retrieve_response_code( $resume_resp ) < 300 ) {
		$paused_rows = json_decode( wp_remote_retrieve_body( $resume_resp ), true ) ?: [];

		if ( ! empty( $paused_rows[0]['id'] ) ) {
			$session_id        = preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) $paused_rows[0]['id'] );
			$safe_character_id = preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) ( $paused_rows[0]['character_id'] ?? '' ) );
			$safe_world_id     = ! empty( $paused_rows[0]['world_id'] )
				? preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) $paused_rows[0]['world_id'] )
				: null;

			$reactivate_url = add_query_arg( [ 'id' => 'eq.' . $session_id ], $base . 'cyber_game_sessions' );
			$reactivate_resp = wp_remote_request( $reactivate_url, [
				'method'  => 'PATCH',
				'headers' => nw_supabase_headers(),
				'body'    => wp_json_encode( [ 'status' => 'active' ] ),
				'timeout' => 10,
			] );

			if ( is_wp_error( $reactivate_resp ) ) {
				return new WP_Error( 'reactivate_failed', 'Failed to reactivate paused session.', [ 'status' => 500 ] );
			}

			$reactivate_code = wp_remote_retrieve_response_code( $reactivate_resp );
			if ( $reactivate_code < 200 || $reactivate_code >= 300 ) {
				return new WP_Error( 'reactivate_failed', 'Supabase failed to reactivate paused session. HTTP ' . $reactivate_code, [ 'status' => $reactivate_code ] );
			}

			if ( function_exists( 'tw_invalidate_game_data_cache' ) ) {
				tw_invalidate_game_data_cache( $user_id );
			}

			do_action( 'tw_session_started', $user_id, [
				'session_id'   => $session_id,
				'campaign_id'  => $campaign_id,
				'character_id' => $safe_character_id,
				'world_id'     => $safe_world_id,
				'resumed'      => true,
			] );

			error_log( 'TW_ENDPOINT_START_GAME_SESSION: RESUMED session_id=' . $session_id );

			return rest_ensure_response( [
				'success' => true,
				'data'    => [
					'message'      => 'Session resumed.',
					'session_id'   => $session_id,
					'campaign_id'  => $campaign_id,
					'character_id' => $safe_character_id,
					'world_id'     => $safe_world_id,
					'resumed'      => true,
				],
			] );
		}
	}

	$query_url = add_query_arg( [
		'id'         => 'eq.' . $campaign_id,
		'wp_user_id' => 'eq.' . $user_id,
		'select'     => 'cyber_campaign_worlds(world_id),cyber_campaign_characters(character_id)',
	], $base . 'cyber_campaign' );

	$resp = wp_remote_get( $query_url, [ 'headers' => nw_supabase_headers(), 'timeout' => 10 ] );

	if ( is_wp_error( $resp ) ) {
		return new WP_Error( 'supabase_error', 'Connection error with Supabase.', [ 'status' => 500 ] );
	}
	$code = wp_remote_retrieve_response_code( $resp );
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'campaign_lookup', 'Supabase campaign lookup failed.', [ 'status' => $code ] );
	}

	$camp_data = json_decode( wp_remote_retrieve_body( $resp ), true );
	$campaign  = ! empty( $camp_data ) ? $camp_data[0] : null;
	if ( ! $campaign ) {
		return new WP_Error( 'campaign_not_found', 'Campaign not found.', [ 'status' => 404 ] );
	}

	$world_id     = $campaign['cyber_campaign_worlds'][0]['world_id']         ?? null;
	$character_id = $campaign['cyber_campaign_characters'][0]['character_id'] ?? null;

	if ( ! $character_id ) {
		return new WP_Error( 'no_character', 'No character linked to this campaign.', [ 'status' => 400 ] );
	}

	$safe_world_id     = $world_id ? preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) $world_id ) : null;
	$safe_character_id = preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) $character_id );

	$start_location_id = null;
	if ( $safe_world_id ) {
		$loc_url  = add_query_arg( [
			'world_id' => 'eq.' . $safe_world_id,
			'coord_x'  => 'eq.0',
			'coord_y'  => 'eq.0',
			'select'   => 'id',
			'limit'    => 1,
		], $base . 'cyber_world_map' );
		$loc_resp = wp_remote_get( $loc_url, [ 'headers' => nw_supabase_headers(), 'timeout' => 10 ] );
		if ( ! is_wp_error( $loc_resp ) && wp_remote_retrieve_response_code( $loc_resp ) < 300 ) {
			$loc_data          = json_decode( wp_remote_retrieve_body( $loc_resp ), true ) ?: [];
			$start_location_id = ! empty( $loc_data[0]['id'] ) ? (int) $loc_data[0]['id'] : null;
		}
	}

	if ( ! $start_location_id ) {
		return new WP_Error( 'no_start_location', 'No start location (0,0) for this world.', [ 'status' => 404 ] );
	}

	$session_body = [
		'campaign_id'  => $campaign_id,
		'character_id' => $safe_character_id,
		'wp_user_id'   => $user_id,
		'world_id'     => $safe_world_id,
		'location_id'  => $start_location_id,
		'status'       => 'active',
	];

	$session_resp = wp_remote_post( $base . 'cyber_game_sessions', [
		'headers' => nw_supabase_headers( true ),
		'body'    => wp_json_encode( $session_body ),
		'timeout' => 15,
	] );

	if ( is_wp_error( $session_resp ) ) {
		return new WP_Error( 'session_error', 'Database error creating session.', [ 'status' => 500 ] );
	}
	$session_code = wp_remote_retrieve_response_code( $session_resp );
	if ( $session_code < 200 || $session_code >= 300 ) {
		return new WP_Error( 'session_failed', 'Supabase failed to create session. HTTP ' . $session_code, [ 'status' => $session_code ] );
	}

	$session_data = json_decode( wp_remote_retrieve_body( $session_resp ), true );
	if ( empty( $session_data[0]['id'] ) ) {
		return new WP_Error( 'no_session_id', 'Session created but no ID returned.', [ 'status' => 500 ] );
	}

	$session_id = preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) $session_data[0]['id'] );

	if ( function_exists( 'tw_invalidate_game_data_cache' ) ) {
		tw_invalidate_game_data_cache( $user_id );
	}

	do_action( 'tw_session_started', $user_id, [
		'session_id'   => $session_id,
		'campaign_id'  => $campaign_id,
		'character_id' => $safe_character_id,
		'world_id'     => $safe_world_id,
		'location_id'  => $start_location_id,
		'resumed'      => false,
	] );

	error_log( 'TW_ENDPOINT_START_GAME_SESSION: SUCCESS session_id=' . $session_id );

	return rest_ensure_response( [
		'success' => true,
		'data'    => [
			'message'      => 'Session created.',
			'session_id'   => $session_id,
			'campaign_id'  => $campaign_id,
			'character_id' => $safe_character_id,
			'world_id'     => $safe_world_id,
			'resumed'      => false,
		],
	] );
}

// ===========================================================================
// SESSION END ENDPOINT
// ===========================================================================

/**
 * @return WP_REST_Response|WP_Error
 */
function neoweaver_end_game_session( WP_REST_Request $request ) {
	error_log( 'TW_ENDPOINT_END_GAME_SESSION: START (REST API)' );

	$nonce = $request->get_param( 'security' ) ?? '';
	if ( ! wp_verify_nonce( $nonce, 'tw_game_nonce' ) ) {
		return new WP_Error( 'nonce_failed', 'Nonce check failed.', [ 'status' => 403 ] );
	}

	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return new WP_Error( 'unauthorized', 'You are not logged in.', [ 'status' => 401 ] );
	}

	$campaign_id_raw = $request->get_param( 'campaign_id' ) ?? '';
	$campaign_id     = preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) $campaign_id_raw );
	if ( ! $campaign_id ) {
		return new WP_Error( 'missing_campaign', 'Missing campaign ID.', [ 'status' => 400 ] );
	}

	$base     = nw_supabase_base();
	$anon_key = function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '';
	if ( ! $base || ! $anon_key ) {
		return new WP_Error( 'config_missing', 'Supabase config missing.', [ 'status' => 500 ] );
	}

	$lookup_url = add_query_arg( [
		'wp_user_id'  => 'eq.' . $user_id,
		'campaign_id' => 'eq.' . $campaign_id,
		'status'      => 'eq.active',
		'select'      => 'id',
		'order'       => 'created_at.desc',
		'limit'       => 1,
	], $base . 'cyber_game_sessions' );

	$lookup = wp_remote_get( $lookup_url, [ 'headers' => nw_supabase_headers(), 'timeout' => 10 ] );

	if ( is_wp_error( $lookup ) ) {
		return new WP_Error( 'supabase_error', 'Communication error with Supabase.', [ 'status' => 500 ] );
	}
	$lookup_code = wp_remote_retrieve_response_code( $lookup );
	if ( $lookup_code < 200 || $lookup_code >= 300 ) {
		return new WP_Error( 'lookup_failed', 'Supabase session lookup failed. HTTP ' . $lookup_code, [ 'status' => $lookup_code ] );
	}

	$rows = json_decode( wp_remote_retrieve_body( $lookup ), true ) ?: [];
	if ( empty( $rows[0]['id'] ) ) {
		return new WP_Error( 'no_session', 'No active session found for this campaign.', [ 'status' => 404 ] );
	}

	$safe_session_id = preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) $rows[0]['id'] );

	$patch_url = add_query_arg( [ 'id' => 'eq.' . $safe_session_id ], $base . 'cyber_game_sessions' );

	$response = wp_remote_request( $patch_url, [
		'method'  => 'PATCH',
		'headers' => nw_supabase_headers(),
		'body'    => wp_json_encode( [ 'status' => 'finished', 'scenario_status' => 'completed' ] ),
		'timeout' => 10,
	] );

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'supabase_error', 'Communication error with Supabase.', [ 'status' => 500 ] );
	}
	$code = wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'patch_failed', 'Supabase returned HTTP ' . $code, [ 'status' => $code ] );
	}

	if ( function_exists( 'tw_invalidate_game_data_cache' ) ) {
		tw_invalidate_game_data_cache( $user_id );
	}

	do_action( 'tw_session_ended', $user_id, [
		'session_id'  => $safe_session_id,
		'campaign_id' => $campaign_id,
	] );

	error_log( 'TW_ENDPOINT_END_GAME_SESSION: SUCCESS session_id=' . $safe_session_id );

	return rest_ensure_response( [
		'success' => true,
		'data'    => [
			'message'    => 'Session ended successfully.',
			'session_id' => $safe_session_id,
			'user_id'    => (int) $user_id,
		],
	] );
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'neoweaver/v1', '/world/create', [
		'methods'             => 'POST',
		'callback'            => 'neoweaver_create_world',
		'permission_callback' => 'neoweaver_user_can_play',
	] );

	register_rest_route( 'neoweaver/v1', '/character/create', [
		'methods'             => 'POST',
		'callback'            => 'neoweaver_create_character',
		'permission_callback' => 'neoweaver_user_can_play',
	] );

	register_rest_route( 'neoweaver/v1', '/campaign/create', [
		'methods'             => 'POST',
		'callback'            => 'neoweaver_create_campaign',
		'permission_callback' => 'neoweaver_user_can_play',
	] );

	register_rest_route( 'neoweaver/v1', '/session/start', [
		'methods'             => 'POST',
		'callback'            => 'neoweaver_start_game_session',
		'permission_callback' => 'neoweaver_user_can_play',
	] );

	register_rest_route( 'neoweaver/v1', '/session/end', [
		'methods'             => 'POST',
		'callback'            => 'neoweaver_end_game_session',
		'permission_callback' => 'neoweaver_user_can_play',
	] );
} );
