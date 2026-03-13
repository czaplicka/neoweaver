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
