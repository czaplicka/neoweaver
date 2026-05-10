<?php
/**
 * TALE WEAVER – AJAX: Save Player Notes
 * Updates the `notes` field in cyber_characters via Supabase REST PATCH.
 *
 * BUG-FIX (constants): Previously used TW_SUPABASE_URL and TW_SUPABASE_ANON_KEY
 * constants. Replaced with tw_supabase_url() / tw_supabase_anon_key() helpers
 * per project-wide convention.
 *
 * BUG-FIX (ownership): char_id was cast with (int) — UUID IDs collapse to 0.
 * The PATCH filter was only id=eq.{char_id} with no wp_user_id guard, so any
 * logged-in player could overwrite any character's notes by supplying a
 * different char_id. Fixed by:
 *   1. Sanitizing char_id as a UUID-safe string instead of (int).
 *   2. Adding wp_user_id=eq.{current_user} to the PATCH filter so Supabase
 *      only matches rows the current user owns.
 *
 * Actions registered:
 *   wp_ajax_save_player_notes  (logged-in users only; nopriv variant removed)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_ajax_save_player_notes', 'tw_save_player_notes' );

function tw_save_player_notes() {

    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        wp_send_json_error( [ 'message' => 'Invalid method' ], 405 );
        return;
    }

    if ( ! check_ajax_referer( 'tw_ajax_nonce', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => 'Security check failed' ], 403 );
        return;
    }

    $wp_user_id = get_current_user_id();
    if ( ! $wp_user_id ) {
        wp_send_json_error( [ 'message' => 'Not logged in' ], 401 );
        return;
    }

    // UUID-safe sanitization — never use (int) on a UUID primary key.
    $char_id = isset( $_POST['char_id'] )
        ? preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $_POST['char_id'] )
        : '';

    if ( empty( $char_id ) ) {
        wp_send_json_error( [ 'message' => 'Missing char_id' ], 400 );
        return;
    }

    $notes = isset( $_POST['notes'] ) ? wp_unslash( (string) $_POST['notes'] ) : '';

    if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
        wp_send_json_error( [ 'message' => 'Supabase not configured' ], 500 );
        return;
    }

    $anon_key     = tw_supabase_anon_key();
    $supabase_url = trailingslashit( tw_supabase_url() ) . 'rest/v1/';

    // Ownership guard: include wp_user_id=eq.{current_user} so the PATCH only
    // matches a row the current user actually owns.
    $url = add_query_arg(
        [
            'id'         => 'eq.' . $char_id,
            'wp_user_id' => 'eq.' . $wp_user_id,
        ],
        $supabase_url . 'cyber_characters'
    );

    $args = [
        'method'  => 'PATCH',
        'headers' => [
            'apikey'        => $anon_key,
            'Authorization' => 'Bearer ' . $anon_key,
            'Content-Type'  => 'application/json',
            'Prefer'        => 'return=representation',
        ],
        'body'    => wp_json_encode( [ 'notes' => $notes ] ),
        'timeout' => 15,
    ];

    $resp = wp_remote_request( $url, $args );

    if ( is_wp_error( $resp ) ) {
        wp_send_json_error( [
            'message' => 'HTTP error',
            'error'   => $resp->get_error_message(),
        ], 500 );
        return;
    }

    $code = wp_remote_retrieve_response_code( $resp );
    if ( $code < 200 || $code >= 300 ) {
        wp_send_json_error( [
            'message' => 'Supabase error',
            'status'  => $code,
            'body'    => wp_remote_retrieve_body( $resp ),
        ], $code );
        return;
    }

    wp_send_json_success( [
        'message' => 'Notes updated',
        'char_id' => $char_id,
    ] );
}
