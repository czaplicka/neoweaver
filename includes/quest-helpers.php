<?php
/**
 * Quest Helpers
 *
 * Standalone helper functions for quest assignment and management.
 * These are backend utilities – safe to call from REST endpoints,
 * AJAX handlers, cron jobs, etc.
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalize quest_tags value to a plain PHP array.
 *
 * $quest_data['tags'] may arrive as:
 *   - PHP array (from Supabase jsonb column decoded by wp_remote_get)
 *   - comma-separated string (from legacy data or manual entry)
 *   - JSON string ("[\"tag1\",\"tag2\"]" — sometimes from jsonb text casts)
 *   - null / false / empty
 *
 * Returns a numerically-indexed array of non-empty strings.
 *
 * @param mixed $raw Raw tags value.
 * @return array<int, string>
 */
function tw_normalize_quest_tags( $raw ): array {
	if ( empty( $raw ) ) {
		return array();
	}

	if ( is_array( $raw ) ) {
		return array_values( array_filter( array_map( 'strval', $raw ) ) );
	}

	$raw = (string) $raw;

	// Try JSON first (covers jsonb text-cast like ["a","b"]).
	$decoded = json_decode( $raw, true );
	if ( is_array( $decoded ) ) {
		return array_values( array_filter( array_map( 'strval', $decoded ) ) );
	}

	// Fallback: comma-separated string.
	return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
}

/**
 * Assign a quest to a character and write it to cyber_active_quests.
 *
 * @param string $character_id  UUID of the character (from cyber_characters).
 * @param array  $quest_data    Quest row. Expected keys: id, name, tags, description.
 * @param string $type          Quest type: 'main' | 'side' | 'personal'. Default 'side'.
 * @return array|false          Supabase row on success, false on failure.
 */
function tw_assign_quest_to_character( string $character_id, array $quest_data, string $type = 'side' ) {
	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_service_key' ) ) {
		error_log( '[NeoWeaver] tw_assign_quest_to_character: Supabase helpers not available.' );
		return false;
	}

	$service_key  = tw_supabase_service_key();
	$endpoint_url = trailingslashit( tw_supabase_url() ) . 'rest/v1/cyber_active_quests';

	/**
	 * quest_tags: normalizujemy do tablicy PHP przed wstawieniem.
	 *
	 * Kolumna cyber_active_quests.quest_tags to TEXT[], a nie jsonb.
	 * PostgREST akceptuje JSON array ("[\"a\",\"b\"]") przy Content-Type: application/json,
	 * ale tylko gdy typ kolumny to text[] lub jsonb.
	 * Przekazanie surowej tablicy PHP przez wp_json_encode działa poprawnie —
	 * tw_normalize_quest_tags() gwarantuje że zawsze dostaniemy array, nie string.
	 */
	$quest_tags = tw_normalize_quest_tags( $quest_data['tags'] ?? null );

	$payload = array(
		'character_id'    => $character_id,
		'quest_origin_id' => $quest_data['id'],
		'quest_type'      => $type,
		'quest_name'      => $quest_data['name'],
		'quest_tags'      => $quest_tags,
		'status'          => 'active',
		'progress_data'   => wp_json_encode(
			array(
				'assigned_at'          => gmdate( 'Y-m-d H:i:s' ),
				'current_step'         => 'Initial discovery',
				'original_description' => $quest_data['description'] ?? '',
			)
		),
	);

	$response = wp_remote_post(
		$endpoint_url,
		array(
			'method'  => 'POST',
			'headers' => array(
				'apikey'        => $service_key,
				'Authorization' => 'Bearer ' . $service_key,
				'Content-Type'  => 'application/json',
				'Prefer'        => 'return=representation',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 15,
		)
	);

	if ( is_wp_error( $response ) ) {
		error_log( '[NeoWeaver] tw_assign_quest_to_character error: ' . $response->get_error_message() );
		return false;
	}

	$http_code = wp_remote_retrieve_response_code( $response );
	$body      = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $http_code < 200 || $http_code >= 300 ) {
		error_log( '[NeoWeaver] tw_assign_quest_to_character: unexpected HTTP ' . $http_code . ' – ' . wp_remote_retrieve_body( $response ) );
		return false;
	}

	if ( empty( $body ) ) {
		error_log( '[NeoWeaver] tw_assign_quest_to_character: empty response body.' );
		return false;
	}

	return is_array( $body ) && isset( $body[0] ) ? $body[0] : $body;
}

/**
 * Resolve a quest outcome: mark the active quest as completed or failed,
 * then emit the outcome tags into the AI chat as #Tag directives so the tag-driven pipeline processes them.
 *
 * @param string $character_id    UUID of the character (from cyber_characters).
 * @param string $active_quest_id UUID of the row in cyber_active_quests.
 * @param bool   $is_success      True = quest succeeded, false = quest failed.
 * @return array|false  Array with 'status' and 'pending_tags' on success, false on failure.
 */
function tw_resolve_quest_impact( string $character_id, string $active_quest_id, bool $is_success ) {
	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_service_key' ) ) {
		error_log( '[NeoWeaver] tw_resolve_quest_impact: Supabase helpers not available.' );
		return false;
	}

	$service_key = tw_supabase_service_key();
	$base_url    = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
	$headers     = array(
		'apikey'        => $service_key,
		'Authorization' => 'Bearer ' . $service_key,
	);

	/**
	 * 1. Fetch active quest + linked scenario via PostgREST FK join.
	 *
	 * Stara składnia:  cyber_scenarios:quest_origin_id(*)
	 *   ':' to hint dla nazwy relacji/aliasu — jeśli PostgREST nie znajdzie
	 *   pasującej nazwy, zwraca null dla cyber_scenarios.
	 *
	 * Poprawna składnia: cyber_scenarios!quest_origin_id(*)
	 *   '!' jawnie wskazuje kolumnę FK na cyber_active_quests,
	 *   która wskazuje na cyber_scenarios.id.
	 *
	 * add_query_arg() zamiast ręcznego sklejania stringów:
	 *   - rawurlencode() na UUID jest bezpieczne, ale add_query_arg()
	 *     jest standardem WordPress i obsługuje edge case'y.
	 */
	$q_url = add_query_arg(
		array(
			'id'     => 'eq.' . $active_quest_id,
			'select' => '*,cyber_scenarios!quest_origin_id(*)',
		),
		$base_url . 'cyber_active_quests'
	);

	$response = wp_remote_get( $q_url, array( 'headers' => $headers, 'timeout' => 15 ) );

	if ( is_wp_error( $response ) ) {
		error_log( '[NeoWeaver] tw_resolve_quest_impact: fetch quest error – ' . $response->get_error_message() );
		return false;
	}

	$http_code = wp_remote_retrieve_response_code( $response );
	if ( $http_code < 200 || $http_code >= 300 ) {
		error_log( '[NeoWeaver] tw_resolve_quest_impact: fetch quest HTTP ' . $http_code );
		return false;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( empty( $data ) || ! isset( $data[0]['cyber_scenarios'] ) ) {
		error_log( '[NeoWeaver] tw_resolve_quest_impact: quest or scenario link missing for ID: ' . $active_quest_id );
		return false;
	}

	$scenario = $data[0]['cyber_scenarios'];

	// 2. Pick success or failure tags from the scenario.
	$raw_tags = $is_success ? ( $scenario['success_tags'] ?? '' ) : ( $scenario['failure_tags'] ?? '' );
	$new_tags = tw_normalize_quest_tags( $raw_tags );

	$json_headers = array_merge( $headers, array( 'Content-Type' => 'application/json' ) );
	$new_status   = $is_success ? 'completed' : 'failed';

	/**
	 * 3. Mark quest as completed / failed.
	 *
	 * add_query_arg() buduje URL dla PATCH — ten sam pattern co GET wyżej.
	 */
	$patch_url = add_query_arg(
		array( 'id' => 'eq.' . $active_quest_id ),
		$base_url . 'cyber_active_quests'
	);

	$patch_response = wp_remote_request(
		$patch_url,
		array(
			'method'  => 'PATCH',
			'headers' => $json_headers,
			'timeout' => 15,
			'body'    => wp_json_encode(
				array(
					'status'        => $new_status,
					'progress_data' => wp_json_encode(
						array(
							'resolved_at'  => gmdate( 'Y-m-d H:i:s' ),
							'outcome'      => $new_status,
							'pending_tags' => $new_tags,
						)
					),
				)
			),
		)
	);

	if ( is_wp_error( $patch_response ) ) {
		error_log( '[NeoWeaver] tw_resolve_quest_impact: PATCH error – ' . $patch_response->get_error_message() );
		return false;
	}

	$patch_code = wp_remote_retrieve_response_code( $patch_response );
	if ( $patch_code < 200 || $patch_code >= 300 ) {
		error_log( '[NeoWeaver] tw_resolve_quest_impact: PATCH failed with HTTP ' . $patch_code . ' – ' . wp_remote_retrieve_body( $patch_response ) );
		return false;
	}

	return array(
		'status'       => $new_status,
		'pending_tags' => $new_tags,
	);
}
