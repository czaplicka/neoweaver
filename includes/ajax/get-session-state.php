<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Tylko zalogowani użytkownicy — nie rejestrujemy nopriv.
add_action( 'wp_ajax_tw_get_session_state', 'tw_get_session_state_handler' );

// BUG 8 FIX: wrap in function_exists to prevent fatal "Cannot redeclare"
// on any double-include of this file.
if ( ! function_exists( 'tw_get_session_state_handler' ) ) {
	function tw_get_session_state_handler(): void {
		// nonce first — same pattern as get-char-state.php (BUG 6 lesson).
		check_ajax_referer( 'neoweaver_chat', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
			return;
		}

		if ( empty( $_POST['session_id'] ) ) {
			wp_send_json_error( [ 'message' => 'Missing session_id' ], 400 );
			return;
		}

		// FIX: replaced preg_replace('/[^a-zA-Z0-9\-]/', '', ...) with nw_sanitize_uuid().
		// The old sanitizer allowed uppercase letters, but Postgres stores UUIDs lowercase
		// and PostgREST eq. filter is case-sensitive against the stored form — an uppercase
		// UUID would silently return 0 rows. nw_sanitize_uuid() lowercases and validates
		// the full UUID format, consistent with every other UUID sanitization in the codebase.
		$session_id = function_exists( 'nw_sanitize_uuid' )
			? nw_sanitize_uuid( sanitize_text_field( wp_unslash( (string) $_POST['session_id'] ) ) )
			: preg_replace( '/[^a-f0-9\-]/', '', strtolower( (string) $_POST['session_id'] ) );

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
}

/**
 * BUG 9 FIX (updated): localize neoweaver_chat nonce using Object.assign pattern.
 *
 * wp_localize_script() replaces the entire named JS object on every call,
 * wiping any properties already set by other handlers on the same nwChat object.
 * We use wp_add_inline_script() with Object.assign instead so this handler
 * only adds/updates `session_nonce` without touching other nwChat properties.
 *
 * JS usage: nwChat.session_nonce  (send as `nonce` POST field)
 *
 * The inline snippet runs AFTER the target script (position 'after'), so
 * nwChat initialised by the script itself is already in scope.
 */
if ( ! function_exists( 'nw_localize_session_nonce' ) ) {
	function nw_localize_session_nonce(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$nonce  = wp_json_encode( wp_create_nonce( 'neoweaver_chat' ) );
		$handle = 'nw-chat-engine';

		// Merge-safe snippet: never overwrites the whole nwChat object.
		$snippet = 'window.nwChat = Object.assign( window.nwChat || {}, { session_nonce: ' . $nonce . ' } );';

		if ( wp_script_is( $handle, 'enqueued' ) || wp_script_is( $handle, 'registered' ) ) {
			// Attach after nw-chat-engine so the script's own nwChat init runs first.
			wp_add_inline_script( $handle, $snippet, 'after' );
		} else {
			// Fallback: attach after jquery-core when chat engine is not loaded.
			wp_add_inline_script( 'jquery-core', $snippet, 'after' );
		}
	}
}
add_action( 'wp_enqueue_scripts', 'nw_localize_session_nonce', 20 );
