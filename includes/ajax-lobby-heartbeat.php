<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LOBBY HEARTBEAT
 *
 * Client calls this endpoint every HEARTBEAT_INTERVAL ms (default 20 s).
 * We update last_seen_at on cyber_campaign_signups so the lobby can show
 * a real online/offline dot instead of relying on created_at.
 *
 * Endpoint: POST admin-ajax.php
 *   action      = neoweave_lobby_heartbeat
 *   nonce       = wp_create_nonce('neoweave_heartbeat')  [injected by shortcode]
 *   campaign_id = UUID string
 *
 * Security:
 *   - check_ajax_referer() rejects requests without a valid nonce.
 *   - Only logged-in users (wp_ajax_ only, no nopriv).
 *   - campaign_id is UUID-sanitised; user is taken from server-side session.
 *
 * BUG-FIX: campaign_id was sanitised with intval() but cyber_campaign.id is a
 * UUID string — intval() on a UUID collapses it to 0, causing the PATCH filter
 * to match no rows and the heartbeat to silently do nothing.
 * Fixed: use UUID-safe preg_replace instead of intval().
 */

add_action( 'wp_ajax_neoweave_lobby_heartbeat', 'neoweave_lobby_heartbeat' );

function neoweave_lobby_heartbeat(): void {
	check_ajax_referer( 'neoweave_heartbeat', 'nonce' );

	// BUG-FIX: was intval($_POST['campaign_id']) — UUID → 0.
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

	$key  = tw_supabase_anon_key();
	$url  = trailingslashit( tw_supabase_url() )
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
