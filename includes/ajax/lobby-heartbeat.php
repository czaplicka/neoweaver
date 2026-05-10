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
 *
 * neoweave_leave_lobby:
 *   BUG-FIX: this action was called from the lobby JS "LEAVE" button but had
 *   no registered handler anywhere in the plugin. Every leave attempt returned
 *   {success: false} from admin-ajax.php and the player was never removed from
 *   cyber_campaign_signups, leaving a ghost signup that blocked the slot and
 *   skewed the online count.
 *   Fixed: handler registered here (same file as heartbeat — both deal with
 *   cyber_campaign_signups for the current user).
 */

// ─── HEARTBEAT ────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_neoweave_lobby_heartbeat', 'neoweave_lobby_heartbeat' );

function neoweave_lobby_heartbeat(): void {
	check_ajax_referer( 'neoweave_heartbeat', 'nonce' );

	$campaign_id = isset( $_POST['campaign_id'] )
		? preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $_POST['campaign_id'] )
		: '';

	if ( empty( $campaign_id ) ) {
		wp_send_json_error( [ 'message' => 'invalid_campaign' ] );
		return;
	}

	$wp_user_id = get_current_user_id();
	if ( ! $wp_user_id ) {
		wp_send_json_error( [ 'message' => 'not_logged_in' ] );
		return;
	}

	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
		wp_send_json_error( [ 'message' => 'supabase_config_missing' ] );
		return;
	}

	$key = tw_supabase_anon_key();
	$url = trailingslashit( tw_supabase_url() )
		. 'rest/v1/cyber_campaign_signups'
		. '?campaign_id=eq.' . $campaign_id
		. '&wp_user_id=eq.'  . $wp_user_id;

	$res = wp_remote_request( $url, [
		'method'  => 'PATCH',
		'headers' => [
			'apikey'        => $key,
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => 'application/json',
		],
		'body'    => wp_json_encode( [ 'last_seen_at' => gmdate( 'Y-m-d\TH:i:s\Z' ) ] ),
		'timeout' => 5,
	] );

	if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) >= 300 ) {
		wp_send_json_error( [ 'message' => 'supabase_patch_failed' ] );
		return;
	}

	wp_send_json_success();
}

// ─── LEAVE LOBBY ─────────────────────────────────────────────────────────────

/**
 * BUG-FIX: neoweave_leave_lobby was called from the lobby JS but had no
 * registered PHP handler — admin-ajax.php returned {success:false,-1} and
 * the player was never removed from cyber_campaign_signups.
 *
 * This handler:
 *   1. Verifies the nonce.
 *   2. Resolves the current user server-side (never trusts JS-supplied user ID).
 *   3. DELETEs the signup row for this user + campaign from Supabase.
 *   4. Returns success so the JS can redirect to the homepage.
 */
add_action( 'wp_ajax_neoweave_leave_lobby', 'neoweave_leave_lobby' );

function neoweave_leave_lobby(): void {
	// The lobby injects data-nonce-heartbeat; reuse it here so no extra nonce
	// is needed on the client side — same session, same action family.
	check_ajax_referer( 'neoweave_heartbeat', 'nonce' );

	$wp_user_id = get_current_user_id();
	if ( ! $wp_user_id ) {
		wp_send_json_error( [ 'message' => 'not_logged_in' ] );
		return;
	}

	$campaign_id = isset( $_POST['campaign_id'] )
		? preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $_POST['campaign_id'] )
		: '';

	if ( empty( $campaign_id ) ) {
		wp_send_json_error( [ 'message' => 'invalid_campaign' ] );
		return;
	}

	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
		wp_send_json_error( [ 'message' => 'supabase_config_missing' ] );
		return;
	}

	$key = tw_supabase_anon_key();
	$url = trailingslashit( tw_supabase_url() )
		. 'rest/v1/cyber_campaign_signups'
		. '?campaign_id=eq.' . $campaign_id
		. '&wp_user_id=eq.'  . $wp_user_id;

	$res = wp_remote_request( $url, [
		'method'  => 'DELETE',
		'headers' => [
			'apikey'        => $key,
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => 'application/json',
		],
		'timeout' => 10,
	] );

	if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) >= 300 ) {
		error_log( 'TW neoweave_leave_lobby: Supabase DELETE failed for user=' . $wp_user_id . ' campaign=' . $campaign_id );
		wp_send_json_error( [ 'message' => 'supabase_delete_failed' ] );
		return;
	}

	wp_send_json_success( [ 'message' => 'left_lobby' ] );
}
