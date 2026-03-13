<?php
/**
 * TALE WEAVER - Deck / Scenarios AJAX
 *
 * 1. tw_localize_deck_vars()  - localizes twGameConfig to JS on the terminal page.
 * 2. tw_get_scenarios_ajax()  - returns available (unplayed) scenarios for a campaign.
 *
 * Requires TW_SUPABASE_PROJECT_ID and TW_SUPABASE_ANON_KEY constants (wp-config.php).
 * JS handle expected: 'adventure-js'
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ---------------------------------------------------------------------------
// 1. Localize deck config vars on the terminal page
// ---------------------------------------------------------------------------

add_action( 'wp', function () {
    if ( is_page( 'terminal' ) ) {
        add_action( 'wp_enqueue_scripts', 'tw_localize_deck_vars', 11 );
    }
} );

function tw_localize_deck_vars() {

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        error_log( 'tw_localize_deck_vars: user not logged in' );
        return;
    }

    // campaign_id from query var or GET fallback
    $campaign_id = get_query_var( 'campaign_id' );
    if ( ! $campaign_id && isset( $_GET['campaign_id'] ) ) {
        $campaign_id = (int) $_GET['campaign_id'];
    }

    // Auto-detect last campaign for this user if not provided
    if (
        ! $campaign_id &&
        defined( 'TW_SUPABASE_PROJECT_ID' ) &&
        defined( 'TW_SUPABASE_ANON_KEY' )
    ) {
        $supabase_url = 'https://' . TW_SUPABASE_PROJECT_ID . '.supabase.co';
        $anon_key     = TW_SUPABASE_ANON_KEY;

        $campaign_url = add_query_arg(
            [
                'wp_user_id' => 'eq.' . (int) $user_id,
                'order'      => 'created_at.desc',
                'limit'      => 1,
            ],
            $supabase_url . '/rest/v1/cyber_campaign'
        );

        error_log( 'tw_localize_deck_vars: campaign lookup URL = ' . $campaign_url );

        $response = wp_remote_get( $campaign_url, [
            'headers' => [
                'apikey'        => $anon_key,
                'Authorization' => 'Bearer ' . $anon_key,
            ],
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'Supabase campaign lookup error: ' . $response->get_error_message() );
        } else {
            $code     = wp_remote_retrieve_response_code( $response );
            $body_raw = wp_remote_retrieve_body( $response );

            if ( $code >= 200 && $code < 300 ) {
                $body = json_decode( $body_raw, true );
                if ( ! empty( $body ) && isset( $body[0]['id'] ) ) {
                    $campaign_id = (int) $body[0]['id'];
                    error_log( 'tw_localize_deck_vars: found campaign_id=' . $campaign_id . ' for user ' . $user_id );
                } else {
                    error_log( 'Supabase campaign lookup: empty/invalid body for user ' . $user_id . ' body=' . $body_raw );
                }
            } else {
                error_log( 'Supabase campaign lookup HTTP ' . $code . ': ' . $body_raw );
            }
        }
    }

    $campaign_id = (int) $campaign_id;
    error_log( 'tw_localize_deck_vars fired, campaign_id=' . $campaign_id . ', user_id=' . $user_id );

    wp_localize_script(
        'adventure-js',
        'twGameConfig',
        [
            'ajaxurl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'tw_deck_nonce' ),
            'campaign_id' => $campaign_id,
            'user_id'     => (int) $user_id,
        ]
    );
}

// ---------------------------------------------------------------------------
// 2. AJAX: get available scenarios for a campaign
// ---------------------------------------------------------------------------

add_action( 'wp_ajax_tw_get_scenarios_ajax',        'tw_get_scenarios_ajax' );
add_action( 'wp_ajax_nopriv_tw_get_scenarios_ajax', 'tw_get_scenarios_ajax' );

function tw_get_scenarios_ajax() {

    // Uncomment once JS sends the nonce:
    // if ( ! check_ajax_referer( 'tw_deck_nonce', 'nonce', false ) ) {
    //     wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
    // }

    $campaign_id = isset( $_POST['campaign_id'] ) ? (int) $_POST['campaign_id'] : 0;
    error_log( 'tw_get_scenarios_ajax: received campaign_id=' . $campaign_id );

    if (
        ! $campaign_id ||
        ! defined( 'TW_SUPABASE_PROJECT_ID' ) ||
        ! defined( 'TW_SUPABASE_ANON_KEY' )
    ) {
        wp_send_json_error( [ 'message' => 'Missing campaign_id or Supabase config' ] );
    }

    $supabase_url = 'https://' . TW_SUPABASE_PROJECT_ID . '.supabase.co';
    $anon_key     = TW_SUPABASE_ANON_KEY;
    $headers      = [
        'apikey'        => $anon_key,
        'Authorization' => 'Bearer ' . $anon_key,
    ];

    // Helper - single Supabase GET with unified error handling
    $supa_get = function ( $url, $label ) use ( $headers ) {
        $resp = wp_remote_get( $url, [ 'headers' => $headers, 'timeout' => 10 ] );
        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( [ 'message' => $label . ' fetch failed', 'error' => $resp->get_error_message() ] );
        }
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 300 ) {
            wp_send_json_error( [ 'message' => 'Supabase error for ' . $label, 'status' => $code, 'body' => wp_remote_retrieve_body( $resp ) ] );
        }
        return json_decode( wp_remote_retrieve_body( $resp ), true );
    };

    // 1. Campaign
    $campaign_url = $supabase_url . '/rest/v1/cyber_campaign?id=eq.' . $campaign_id;
    error_log( 'tw_get_scenarios_ajax: campaign URL = ' . $campaign_url );

    $campaigns = $supa_get( $campaign_url, 'campaign' );
    if ( empty( $campaigns ) || ! is_array( $campaigns ) ) {
        wp_send_json_error( [ 'message' => 'Campaign not found' ] );
    }

    $campaign       = $campaigns[0];
    $world_type     = isset( $campaign['world_type'] ) ? (int) $campaign['world_type'] : 1;
    $difficulty_min = $world_type - 1;
    $difficulty_max = $world_type + 1;

    // 2. Played scenarios
    $played_url = $supabase_url . '/rest/v1/cyber_campaign_played_scenarios?campaign_id=eq.' . $campaign_id;
    error_log( 'tw_get_scenarios_ajax: played scenarios URL = ' . $played_url );

    $played     = $supa_get( $played_url, 'played scenarios' ) ?: [];
    $played_ids = ! empty( $played ) ? array_map( 'intval', array_column( $played, 'scenario_id' ) ) : [];

    error_log( 'tw_get_scenarios_ajax: played_ids=' . ( $played_ids ? implode( ',', $played_ids ) : 'none' ) );

    // 3. Difficulty range (min 1)
    $difficulty_values = array_unique( array_filter(
        [ $difficulty_min, $world_type, $difficulty_max ],
        fn( $v ) => $v >= 1
    ) );

    // 4. Scenarios query
    $url = add_query_arg(
        [
            'difficulty' => 'in.(' . implode( ',', $difficulty_values ) . ')',
            'type'       => 'eq.main',
            'order'      => 'id.desc',
            'limit'      => 3,
        ],
        $supabase_url . '/rest/v1/cyber_scenarios'
    );

    if ( ! empty( $played_ids ) ) {
        $url = add_query_arg( 'id', 'not.in.(' . implode( ',', $played_ids ) . ')', $url );
    }

    error_log( 'tw_get_scenarios_ajax: scenarios URL = ' . $url );

    $scenarios = $supa_get( $url, 'scenarios' );

    if ( empty( $scenarios ) || ! is_array( $scenarios ) ) {
        wp_send_json_error( [ 'message' => 'No scenarios available' ] );
    }

    wp_send_json_success( $scenarios );
}
