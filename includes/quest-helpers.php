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
 * Assign a quest to a character and write it to cyber_active_quests.
 *
 * @param string $character_id  UUID of the character (from cyber_characters).
 * @param array  $quest_data    Quest row fetched from the source quest table.
 *                              Expected keys: id, name, tags, description.
 * @param string $type          Quest type: 'main' | 'side' | 'personal'. Default 'side'.
 *
 * @return array|false          Supabase row on success, false on failure.
 */
function tw_assign_quest_to_character( string $character_id, array $quest_data, string $type = 'side' ) {
	$supabase_url = 'https://' . TW_SUPABASE_PROJECT_ID . '.supabase.co/rest/v1/cyber_active_quests';
	$supabase_key = TW_SUPABASE_ANON_KEY;

	$payload = [
		'character_id'    => $character_id,
		'quest_origin_id' => $quest_data['id'],
		'quest_type'      => $type,
		'quest_name'      => $quest_data['name'],
		'quest_tags'      => $quest_data['tags'],
		'status'          => 'active',
		'progress_data'   => wp_json_encode( [
			'assigned_at'          => gmdate( 'Y-m-d H:i:s' ),
			'current_step'         => 'Initial discovery',
			'original_description' => $quest_data['description'],
		] ),
	];

	$response = wp_remote_post( $supabase_url, [
		'method'  => 'POST',
		'headers' => [
			'apikey'        => $supabase_key,
			'Authorization' => 'Bearer ' . $supabase_key,
			'Content-Type'  => 'application/json',
			'Prefer'        => 'return=representation',
		],
		'body'    => wp_json_encode( $payload ),
	] );

	if ( is_wp_error( $response ) ) {
		error_log( '[NeoWeaver] tw_assign_quest_to_character error: ' . $response->get_error_message() );
		return false;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( empty( $body ) ) {
		error_log( '[NeoWeaver] tw_assign_quest_to_character: empty response body.' );
		return false;
	}

	// Supabase returns an array of inserted rows with Prefer: return=representation.
	return is_array( $body ) && isset( $body[0] ) ? $body[0] : $body;
}

/**
 * Resolve a quest outcome: apply success/failure tags to the character
 * and mark the active quest as completed or failed.
 *
 * @param string $character_id    UUID of the character (from cyber_characters).
 * @param string $active_quest_id UUID of the row in cyber_active_quests.
 * @param bool   $is_success      True = quest succeeded, false = quest failed.
 *
 * @return array|false  Array with 'status' and 'added_tags' on success, false on failure.
 */
function tw_resolve_quest_impact( string $character_id, string $active_quest_id, bool $is_success ) {
	$base_url     = 'https://' . TW_SUPABASE_PROJECT_ID . '.supabase.co/rest/v1/';
	$supabase_key = TW_SUPABASE_ANON_KEY;
	$headers      = [
		'apikey'        => $supabase_key,
		'Authorization' => 'Bearer ' . $supabase_key,
	];

	// 1. Fetch active quest + linked scenario via PostgREST foreign-key join.
	$q_url    = $base_url . 'cyber_active_quests?id=eq.' . rawurlencode( $active_quest_id )
		. '&select=*,cyber_scenarios:quest_origin_id(*)';
	$response = wp_remote_get( $q_url, [ 'headers' => $headers ] );

	if ( is_wp_error( $response ) ) {
		error_log( '[NeoWeaver] tw_resolve_quest_impact: fetch quest error – ' . $response->get_error_message() );
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

	if ( is_string( $raw_tags ) ) {
		$new_tags = array_filter( array_map( 'trim', explode( ',', $raw_tags ) ) );
	} else {
		$new_tags = is_array( $raw_tags ) ? array_filter( $raw_tags ) : [];
	}
	$new_tags = array_values( $new_tags );

	// 3. Fetch current character tags.
	$char_url  = $base_url . 'cyber_characters?id=eq.' . rawurlencode( $character_id ) . '&select=tags';
	$char_resp = wp_remote_get( $char_url, [ 'headers' => $headers ] );
	$char_data = json_decode( wp_remote_retrieve_body( $char_resp ), true );

	$current_tags = isset( $char_data[0]['tags'] ) && is_array( $char_data[0]['tags'] )
		? $char_data[0]['tags']
		: [];

	// 4. Merge, deduplicate.
	$final_tags = array_values( array_unique( array_merge( $current_tags, $new_tags ) ) );

	$json_headers = array_merge( $headers, [ 'Content-Type' => 'application/json' ] );

	// 5a. Update character tags.
	wp_remote_request(
		$base_url . 'cyber_characters?id=eq.' . rawurlencode( $character_id ),
		[
			'method'  => 'PATCH',
			'headers' => $json_headers,
			'body'    => wp_json_encode( [ 'tags' => $final_tags ] ),
		]
	);

	// 5b. Mark quest as completed / failed.
	$new_status = $is_success ? 'completed' : 'failed';
	wp_remote_request(
		$base_url . 'cyber_active_quests?id=eq.' . rawurlencode( $active_quest_id ),
		[
			'method'  => 'PATCH',
			'headers' => $json_headers,
			'body'    => wp_json_encode( [ 'status' => $new_status ] ),
		]
	);

	return [
		'status'     => $new_status,
		'added_tags' => $new_tags,
	];
}
