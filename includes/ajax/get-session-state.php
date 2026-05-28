<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Tylko zalogowani użytkownicy — nie rejestrujemy nopriv.
add_action( 'wp_ajax_tw_get_session_state', 'tw_get_session_state_handler' );

function tw_get_session_state_handler(): void {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
		return;
	}

	check_ajax_referer( 'neoweaver_chat', 'nonce' );

	if ( empty( $_POST['session_id'] ) ) {
		wp_send_json_error( [ 'message' => 'Missing session_id' ], 400 );
		return;
	}

	$session_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $_POST['session_id'] );
	if ( empty( $session_id ) ) {
		wp_send_json_error( [ 'message' => 'Invalid session_id' ], 400 );
		return;
	}

	// Ownership enforced at query level: wp_user_id filter is part of the WHERE clause.
	// If the session belongs to another user, Supabase returns an empty result —
	// the PHP layer never sees the row, so no data can leak regardless of RLS state.
	$wp_user_id = get_current_user_id();

	$rows = tw_supabase_get(
		'cyber_game_sessions',
		[
			'id'          => 'eq.' . $session_id,
			'wp_user_id'  => 'eq.' . $wp_user_id,
			'select'      => 'id,campaign_id,character_id,wp_user_id,world_id,location_id,status,chat_channel_id',
			'limit'       => 1,
		]
	);

	if ( is_wp_error( $rows ) ) {
		wp_send_json_error( [ 'message' => 'Supabase error', 'error' => $rows->get_error_message() ], 502 );
		return;
	}

	// Empty result means either session doesn't exist or belongs to a different user.
	// Return 404 in both cases — don't reveal whether the session exists at all.
	if ( empty( $rows[0] ) ) {
		wp_send_json_error( [ 'message' => 'Session not found' ], 404 );
		return;
	}

	wp_send_json_success( $rows[0] );
}
