<?php
/**
 * TALE WEAVER – AJAX: Save Player Notes
 * Updates the `notes` field in cyber_characters via Supabase REST PATCH.
 *
 * Actions registered:
 *   wp_ajax_save_player_notes
 *   wp_ajax_nopriv_save_player_notes  (requires login check inside)
 *
 * Requires TW_SUPABASE_URL and TW_SUPABASE_ANON_KEY constants (set in wp-config.php).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_ajax_save_player_notes',        'tw_save_player_notes' );
add_action( 'wp_ajax_nopriv_save_player_notes', 'tw_save_player_notes' );

function tw_save_player_notes() {

    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        wp_send_json_error( [ 'message' => 'Invalid method' ], 405 );
    }

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Not logged in' ], 401 );
    }

    $char_id = isset( $_POST['char_id'] ) ? (int) $_POST['char_id'] : 0;
    $notes   = isset( $_POST['notes'] )   ? wp_unslash( $_POST['notes'] ) : '';

    if ( ! $char_id ) {
        wp_send_json_error( [ 'message' => 'Missing char_id' ], 400 );
    }

    if ( ! defined( 'TW_SUPABASE_URL' ) || ! defined( 'TW_SUPABASE_ANON_KEY' ) ) {
        wp_send_json_error( [ 'message' => 'Supabase not configured' ], 500 );
    }

    $supabase_url = trailingslashit( TW_SUPABASE_URL ) . 'rest/v1/';
    $anon_key     = TW_SUPABASE_ANON_KEY;

    $url  = $supabase_url . 'cyber_characters?id=eq.' . $char_id;
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
    }

    $code = wp_remote_retrieve_response_code( $resp );
    if ( $code < 200 || $code >= 300 ) {
        wp_send_json_error( [
            'message' => 'Supabase error',
            'status'  => $code,
            'body'    => wp_remote_retrieve_body( $resp ),
        ], $code );
    }

    wp_send_json_success( [
        'message' => 'Notes updated',
        'char_id' => $char_id,
    ] );
}
