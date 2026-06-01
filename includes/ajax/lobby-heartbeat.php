<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LOBBY HEARTBEAT + LEAVE LOBBY
 *
 * neoweave_lobby_heartbeat:
 *   Client calls every 20 s. Updates last_seen_at on cyber_campaign_signups
 *   so the lobby online dot reflects real presence rather than created_at.
 *   Nonce: neoweave_heartbeat
 *
 * neoweave_leave_lobby:
 *   Removes the current user's signup row from cyber_campaign_signups.
 *   Nonce: neoweave_leave_lobby  (BUG 12 FIX: separate nonce from heartbeat)
 *
 * Localisation: both nonces are added to window.nwLobby by
 * neoweave_localize_lobby_nonces() below.
 * JS usage:
 *   heartbeat → nonce: nwLobby.heartbeat_nonce
 *   leave     → nonce: nwLobby.leave_nonce
 */

// ============================================================
// HELPERS
// ============================================================

if ( ! function_exists( 'neoweave_sanitize_campaign_id' ) ) {
	function neoweave_sanitize_campaign_id( $campaign_id ): string {
		return strtolower( preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) $campaign_id ) );
	}
}

if ( ! function_exists( 'neoweave_lobby_supabase_headers' ) ) {
	function neoweave_lobby_supabase_headers(): array {
		if ( defined( 'TW_SUPABASE_SERVICE_KEY' ) && TW_SUPABASE_SERVICE_KEY ) {
			$key = TW_SUPABASE_SERVICE_KEY;
		} elseif ( function_exists( 'tw_supabase_service_key' ) && tw_supabase_service_key() ) {
			$key = tw_supabase_service_key();
		} elseif ( function_exists( 'tw_supabase_anon_key' ) ) {
			error_log( 'NW lobby: service key missing, falling back to anon key.' );
			$key = tw_supabase_anon_key();
		} else {
			$key = '';
		}

		$headers = [
			'Content-Type' => 'application/json',
			'Prefer'       => 'return=minimal',
		];

		if ( '' !== $key ) {
			$headers['apikey']        = $key;
			$headers['Authorization'] = 'Bearer ' . $key;
		}

		return $headers;
	}
}

if ( ! function_exists( 'neoweave_lobby_signups_url' ) ) {
	function neoweave_lobby_signups_url( string $campaign_id, int $wp_user_id ): string {
		if ( ! function_exists( 'tw_supabase_url' ) ) {
			return '';
		}

		return trailingslashit( tw_supabase_url() )
			. 'rest/v1/cyber_campaign_signups'
			. '?campaign_id=eq.' . rawurlencode( $campaign_id )
			. '&wp_user_id=eq.' . $wp_user_id;
	}
}

// ─── NONCE LOCALISATION ───────────────────────────────────────────────────────
// BUG 12 FIX: expose both nonces to JS so each action uses its own token.
// Attaches to nw-lobby-js if registered; falls back to inline on jquery-core.

if ( ! function_exists( 'neoweave_localize_lobby_nonces' ) ) {
	function neoweave_localize_lobby_nonces(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$data = [
			'heartbeat_nonce' => wp_create_nonce( 'neoweave_heartbeat' ),
			'leave_nonce'     => wp_create_nonce( 'neoweave_leave_lobby' ),
		];

		$handle = 'nw-lobby-js';
		if ( wp_script_is( $handle, 'enqueued' ) || wp_script_is( $handle, 'registered' ) ) {
			wp_localize_script( $handle, 'nwLobby', $data );
		} else {
			wp_add_inline_script(
				'jquery-core',
				'window.nwLobby = window.nwLobby || {}; ' .
				'Object.assign(window.nwLobby, ' . wp_json_encode( $data ) . ');'
			);
		}
	}
}
add_action( 'wp_enqueue_scripts', 'neoweave_localize_lobby_nonces', 20 );

// ─── HEARTBEAT ────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_neoweave_lobby_heartbeat', 'neoweave_lobby_heartbeat' );

if ( ! function_exists( 'neoweave_lobby_heartbeat' ) ) {
	function neoweave_lobby_heartbeat(): void {
		check_ajax_referer( 'neoweave_heartbeat', 'nonce' );

		$campaign_id = neoweave_sanitize_campaign_id( $_POST['campaign_id'] ?? '' );
		if ( '' === $campaign_id ) {
			wp_send_json_error( [ 'message' => 'invalid_campaign' ], 400 );
			return;
		}

		$wp_user_id = get_current_user_id();
		if ( ! $wp_user_id ) {
			wp_send_json_error( [ 'message' => 'not_logged_in' ], 401 );
			return;
		}

		if ( ! function_exists( 'tw_supabase_url' ) ) {
			wp_send_json_error( [ 'message' => 'supabase_config_missing' ], 500 );
			return;
		}

		$url = neoweave_lobby_signups_url( $campaign_id, $wp_user_id );
		if ( '' === $url ) {
			wp_send_json_error( [ 'message' => 'supabase_url_missing' ], 500 );
			return;
		}

		$res = wp_remote_request(
			$url,
			[
				'method'    => 'PATCH',
				'headers'   => neoweave_lobby_supabase_headers(),
				'body'      => wp_json_encode( [ 'last_seen_at' => gmdate( 'Y-m-d\\TH:i:s\\Z' ) ] ),
				'timeout'   => 5,
				'sslverify' => true,
			]
		);

		if ( is_wp_error( $res ) ) {
			error_log( 'TW neoweave_lobby_heartbeat network error: ' . $res->get_error_message() );
			wp_send_json_error( [ 'message' => 'supabase_patch_failed' ], 502 );
			return;
		}

		$code = wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			error_log( 'TW neoweave_lobby_heartbeat HTTP ' . $code . ': ' . wp_remote_retrieve_body( $res ) );
			wp_send_json_error( [ 'message' => 'supabase_patch_failed' ], 502 );
			return;
		}

		wp_send_json_success( [ 'message' => 'heartbeat_ok' ] );
	}
}

// ─── LEAVE LOBBY ─────────────────────────────────────────────────────────────

add_action( 'wp_ajax_neoweave_leave_lobby', 'neoweave_leave_lobby' );

if ( ! function_exists( 'neoweave_leave_lobby' ) ) {
	function neoweave_leave_lobby(): void {
		// BUG 12 FIX: use dedicated nonce 'neoweave_leave_lobby' instead of
		// reusing 'neoweave_heartbeat'. Separate nonces ensure a replayed
		// heartbeat token cannot authorise a destructive leave/DELETE action,
		// and removes timing conflicts on non-cached sites where heartbeat
		// fires at 20 s intervals and may consume the shared token first.
		check_ajax_referer( 'neoweave_leave_lobby', 'nonce' );

		$wp_user_id = get_current_user_id();
		if ( ! $wp_user_id ) {
			wp_send_json_error( [ 'message' => 'not_logged_in' ], 401 );
			return;
		}

		$campaign_id = neoweave_sanitize_campaign_id( $_POST['campaign_id'] ?? '' );
		if ( '' === $campaign_id ) {
			wp_send_json_error( [ 'message' => 'invalid_campaign' ], 400 );
			return;
		}

		if ( ! function_exists( 'tw_supabase_url' ) ) {
			wp_send_json_error( [ 'message' => 'supabase_config_missing' ], 500 );
			return;
		}

		$url = neoweave_lobby_signups_url( $campaign_id, $wp_user_id );
		if ( '' === $url ) {
			wp_send_json_error( [ 'message' => 'supabase_url_missing' ], 500 );
			return;
		}

		$res = wp_remote_request(
			$url,
			[
				'method'    => 'DELETE',
				'headers'   => neoweave_lobby_supabase_headers(),
				'timeout'   => 10,
				'sslverify' => true,
			]
		);

		if ( is_wp_error( $res ) ) {
			error_log( 'TW neoweave_leave_lobby network error: ' . $res->get_error_message() . ' user=' . $wp_user_id . ' campaign=' . $campaign_id );
			wp_send_json_error( [ 'message' => 'supabase_delete_failed' ], 502 );
			return;
		}

		$code = wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			error_log( 'TW neoweave_leave_lobby HTTP ' . $code . ': ' . wp_remote_retrieve_body( $res ) . ' user=' . $wp_user_id . ' campaign=' . $campaign_id );
			wp_send_json_error( [ 'message' => 'supabase_delete_failed' ], 502 );
			return;
		}

		wp_send_json_success( [ 'message' => 'left_lobby' ] );
	}
}
