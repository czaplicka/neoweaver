<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TALE WEAVER – CORE AJAX HANDLERS
 *
 * All handlers are guarded with !function_exists() so this file is safe
 * to include multiple times or alongside other plugins.
 *
 * Sections:
 *   1. tw_start_scenario_generation  — triggers Make.com webhook
 *   2. tw_check_scenario_status      — polls session status
 *   3. tw_get_ai_message             — fetches latest GM/AI chat message
 *   4. tw_get_lore_tips              — returns random tips for weaving overlay
 *
 * Helpers (also guarded):
 *   tw_update_game_session_status()
 *   tw_get_game_session_status()
 *   tw_get_chat_channel_id_by_session()
 *
 * Supabase credentials are read from the constants tw_supabase_url() /
 * tw_supabase_anon_key() (defined in wp-config via the Hostinger Supabase
 * integration) rather than bare TW_SUPABASE_* constants, keeping the same
 * convention used everywhere else in the plugin.
 */

// ============================================================
// INTERNAL HELPERS — declared first so AJAX handlers can call them
// ============================================================

/**
 * Return a Supabase REST URL for the given table + query string.
 * Centralises URL construction so every handler stays DRY.
 */
if ( ! function_exists( 'tw_supa_url' ) ) {
	function tw_supa_url( string $table, string $query = '' ): string {
		$base = trailingslashit( tw_supabase_url() ) . 'rest/v1/' . $table;
		return $query ? $base . '?' . $query : $base;
	}
}

/**
 * Shared headers array for every Supabase REST call.
 */
if ( ! function_exists( 'tw_supa_headers' ) ) {
	function tw_supa_headers(): array {
		$key = tw_supabase_anon_key();
		return [
			'apikey'        => $key,
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => 'application/json',
		];
	}
}

/**
 * PATCH the scenario_status (and optionally active_scenario_id) on
 * the cyber_game_sessions row that matches $campaign_id.
 */
if ( ! function_exists( 'tw_update_game_session_status' ) ) {
	function tw_update_game_session_status( int $campaign_id, string $status, int $scenario_id = 0 ): bool {
		if ( ! function_exists( 'tw_supabase_url' ) ) {
			return false;
		}

		$body = [ 'scenario_status' => $status, 'updated_at' => current_time( 'mysql' ) ];
		if ( $scenario_id ) {
			$body['active_scenario_id'] = $scenario_id;
		}

		$response = wp_remote_request(
			tw_supa_url( 'cyber_game_sessions', 'campaign_id=eq.' . $campaign_id ),
			[
				'method'  => 'PATCH',
				'headers' => tw_supa_headers(),
				'body'    => wp_json_encode( $body ),
				'timeout' => 10,
			]
		);

		return ! is_wp_error( $response );
	}
}

/**
 * Return the scenario_status string for the most-recent session of
 * $campaign_id, or an empty string on any error.
 */
if ( ! function_exists( 'tw_get_game_session_status' ) ) {
	function tw_get_game_session_status( int $campaign_id ): string {
		if ( ! function_exists( 'tw_supabase_url' ) ) {
			return '';
		}

		$response = wp_remote_get(
			tw_supa_url( 'cyber_game_sessions', 'campaign_id=eq.' . $campaign_id . '&select=scenario_status&limit=1' ),
			[ 'headers' => tw_supa_headers(), 'timeout' => 10 ]
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return (string) ( $body[0]['scenario_status'] ?? '' );
	}
}

/**
 * Look up the chat_channel_id stored on the cyber_game_sessions row
 * for $session_id. Returns null if not found or on error.
 */
if ( ! function_exists( 'tw_get_chat_channel_id_by_session' ) ) {
	function tw_get_chat_channel_id_by_session( int $session_id ): ?int {
		if ( ! function_exists( 'tw_supabase_url' ) ) {
			return null;
		}

		$response = wp_remote_get(
			tw_supa_url( 'cyber_game_sessions', 'id=eq.' . $session_id . '&select=chat_channel_id&limit=1' ),
			[ 'headers' => tw_supa_headers(), 'timeout' => 10 ]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$raw  = $data[0]['chat_channel_id'] ?? null;

		return $raw !== null ? (int) $raw : null;
	}
}

// ============================================================
// 1. START SCENARIO GENERATION
// ============================================================

if ( ! function_exists( 'tw_start_scenario_generation' ) ) {

	add_action( 'wp_ajax_tw_start_scenario_generation',        'tw_start_scenario_generation' );
	add_action( 'wp_ajax_nopriv_tw_start_scenario_generation', 'tw_start_scenario_generation' );

	function tw_start_scenario_generation(): void {
		// Uncomment for debugging:
		// error_log( '🚀 SCENARIO START: ' . print_r( $_POST, true ) );

		$scenario_id = (int) ( $_POST['scenario_id'] ?? 0 );
		$campaign_id = (int) ( $_POST['campaign_id'] ?? 0 );

		if ( ! $scenario_id || ! $campaign_id ) {
			wp_send_json_error( 'Missing IDs' );
		}

		$wp_user_id = get_current_user_id();
		if ( ! $wp_user_id ) {
			wp_send_json_error( 'No WP user' );
		}

		if ( ! function_exists( 'get_user_game_data_from_supabase' ) ) {
			wp_send_json_error( 'Game data helper missing' );
		}

		$game_data  = get_user_game_data_from_supabase( $wp_user_id );
		$session_id = (int) ( $game_data['active_session_id'] ?? 0 );

		if ( ! $session_id ) {
			wp_send_json_error( 'No active session found' );
		}

		$chat_channel_id = tw_get_chat_channel_id_by_session( $session_id );

		// Update session status before firing the webhook so Make.com
		// always sees "generating" if it polls back immediately.
		tw_update_game_session_status( $campaign_id, 'generating', $scenario_id );

		$payload = [
			'campaign_id'     => $campaign_id,
			'scenario_id'     => $scenario_id,
			'session_id'      => $session_id,
			'user_id'         => $wp_user_id,
			'char_id'         => (int) ( $game_data['active_character_id'] ?? 0 ),
			'chat_channel_id' => $chat_channel_id,
		];

		wp_remote_post(
			'https://hook.eu2.make.com/hdu559keqoa53zfa17r4uacuy0hykztn',
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $payload ),
				'timeout' => 15,
			]
		);

		wp_send_json_success( 'Generation started' );
	}
}

// ============================================================
// 2. CHECK SCENARIO STATUS
// ============================================================

if ( ! function_exists( 'tw_check_scenario_status' ) ) {

	add_action( 'wp_ajax_tw_check_scenario_status',        'tw_check_scenario_status' );
	add_action( 'wp_ajax_nopriv_tw_check_scenario_status', 'tw_check_scenario_status' );

	function tw_check_scenario_status(): void {
		$campaign_id = (int) ( $_GET['campaign_id'] ?? 0 );

		if ( ! $campaign_id ) {
			wp_send_json_error( 'No campaign' );
		}

		$status = tw_get_game_session_status( $campaign_id );
		wp_send_json_success( [ 'status' => $status ?: 'generating' ] );
	}
}

// ============================================================
// 3. GET AI / GM MESSAGE  (filters by chat_channel_id)
// ============================================================

if ( ! function_exists( 'tw_get_ai_message' ) ) {

	add_action( 'wp_ajax_tw_get_ai_message',        'tw_get_ai_message' );
	add_action( 'wp_ajax_nopriv_tw_get_ai_message', 'tw_get_ai_message' );

	function tw_get_ai_message(): void {
		if ( ! function_exists( 'tw_supabase_url' ) ) {
			wp_send_json_error( 'Config missing' );
			return;
		}

		$wp_user_id = get_current_user_id();
		if ( ! $wp_user_id ) {
			wp_send_json_error( 'No user logged in' );
			return;
		}

		if ( ! function_exists( 'get_user_game_data_from_supabase' ) ) {
			wp_send_json_error( 'Game data helper missing' );
			return;
		}

		// A. Resolve active session
		$game_data  = get_user_game_data_from_supabase( $wp_user_id );
		$session_id = (int) ( $game_data['active_session_id'] ?? 0 );

		if ( ! $session_id ) {
			wp_send_json_error( 'No active session' );
			return;
		}

		// B. Resolve chat channel for this session
		$chat_channel_id = tw_get_chat_channel_id_by_session( $session_id );

		if ( ! $chat_channel_id ) {
			wp_send_json_error( 'Chat channel not found' );
			return;
		}

		// C. Fetch latest AI/GM message from the correct channel
		$response = wp_remote_get(
			tw_supa_url(
				'cyber_chat_messages',
				'chat_channel_id=eq.' . $chat_channel_id
				. '&sender_type=in.(AI,GM)'
				. '&order=created_at.desc'
				. '&limit=1'
			),
			[ 'headers' => tw_supa_headers(), 'timeout' => 10 ]
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'Supabase conn error' );
			return;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $data ) && isset( $data[0]['message'] ) ) {
			wp_send_json_success( [ 'message' => $data[0]['message'] ] );
		} else {
			wp_send_json_error( 'No message yet' );
		}
	}
}

// ============================================================
// 4. LORE TIPS (for weaving overlay)
// ============================================================

if ( ! function_exists( 'tw_get_lore_tips' ) ) {

	add_action( 'wp_ajax_tw_get_lore_tips',        'tw_get_lore_tips' );
	add_action( 'wp_ajax_nopriv_tw_get_lore_tips', 'tw_get_lore_tips' );

	function tw_get_lore_tips(): void {
		if ( ! function_exists( 'tw_supabase_url' ) ) {
			wp_send_json_error( 'Config missing' );
			return;
		}

		$response = wp_remote_get(
			tw_supa_url( 'cyber_tips', 'select=tip&order=random()&limit=20' ),
			[ 'headers' => tw_supa_headers(), 'timeout' => 10 ]
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'Supabase conn error' );
			return;
		}

		$tips = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
		wp_send_json_success( array_column( $tips, 'tip' ) );
	}
}
