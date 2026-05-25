<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'wp_ajax_tw_join_campaign', 'tw_ajax_join_campaign' );

function tw_ajax_join_campaign(): void {
	check_ajax_referer( 'tw_join_nonce', 'nonce' );

	$user_id      = get_current_user_id();
	$code         = strtoupper( sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ) );
	$character_id = sanitize_text_field( wp_unslash( $_POST['character_id'] ?? '' ) );

	if ( ! $user_id ) {
		wp_send_json_error( [ 'message' => 'ACCESS DENIED: OPERATOR NOT AUTHENTICATED.' ] );
	}
	if ( empty( $code ) || empty( $character_id ) ) {
		wp_send_json_error( [ 'message' => 'ERROR: MISSING DEPLOYMENT CODE OR AGENT ID.' ] );
	}

	$rest        = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
	$anon_key    = tw_supabase_anon_key();
	$service_key = tw_supabase_service_key(); // tylko do INSERT

	// 1. Znajdź aktywną kampanię po join_code
	$camp_resp = wp_remote_get(
		add_query_arg( [
			'join_code'  => 'eq.' . $code,
			'is_active'  => 'eq.true',
			'select'     => 'id,world_id,max_players',
		], $rest . 'cyber_campaign' ),
		[ 'headers' => [ 'apikey' => $anon_key, 'Authorization' => 'Bearer ' . $anon_key ], 'timeout' => 10 ]
	);

	$campaigns = json_decode( wp_remote_retrieve_body( $camp_resp ), true ) ?: [];
	if ( empty( $campaigns ) ) {
		wp_send_json_error( [ 'message' => 'ERROR: INVALID OR EXPIRED DEPLOYMENT CODE.' ] );
	}

	$campaign    = $campaigns[0];
	$campaign_id = $campaign['id'];
	$world_id    = $campaign['world_id'];
	$max_players = (int) $campaign['max_players'];

	// 2. Sprawdź czy postać należy do tego gracza i do właściwego świata
	$char_resp = wp_remote_get(
		add_query_arg( [
			'id'         => 'eq.' . $character_id,
			'wp_user_id' => 'eq.' . $user_id,
			'world_id'   => 'eq.' . $world_id,
			'select'     => 'id',
		], $rest . 'cyber_characters' ),
		[ 'headers' => [ 'apikey' => $anon_key, 'Authorization' => 'Bearer ' . $anon_key ], 'timeout' => 10 ]
	);

	$chars = json_decode( wp_remote_retrieve_body( $char_resp ), true ) ?: [];
	if ( empty( $chars ) ) {
		wp_send_json_error( [ 'message' => 'ERROR: AGENT NOT AUTHORIZED FOR THIS WORLD.' ] );
	}

	// 3. Sprawdź aktualną liczbę graczy (tylko active/pending, nie rejected)
$players_resp = wp_remote_get(
    add_query_arg( [
        'campaign_id' => 'eq.' . $campaign_id,
        'status'      => 'neq.rejected',
        'select'      => 'id',
    ], $rest . 'cyber_campaign_signups' ),
    [ 'headers' => [ 'apikey' => $anon_key, 'Authorization' => 'Bearer ' . $anon_key ], 'timeout' => 10 ]
);

$existing = json_decode( wp_remote_retrieve_body( $players_resp ), true ) ?: [];
if ( count( $existing ) >= $max_players ) {
    wp_send_json_error( [ 'message' => 'ERROR: SQUAD CAPACITY REACHED. MAX ' . $max_players . ' OPERATORS.' ] );
}

// 4. Zapisz signup — service key omija RLS
$join_resp = wp_remote_post(
    $rest . 'cyber_campaign_signups',
    [
        'headers' => [
            'apikey'        => $service_key,
            'Authorization' => 'Bearer ' . $service_key,
            'Content-Type'  => 'application/json',
            'Prefer'        => 'return=minimal',
        ],
        'body' => wp_json_encode( [
            'campaign_id'  => $campaign_id,
            'character_id' => $character_id,
            'wp_user_id'   => $user_id,
            'status'       => 'pending',
        ] ),
        'timeout' => 10,
    ]
);
