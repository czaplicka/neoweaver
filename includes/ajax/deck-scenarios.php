<?php
/**
 * TALE WEAVER - Deck / Scenarios AJAX
 *
 * 1. tw_localize_deck_vars()  - localizes twGameConfig to JS on the terminal page.
 * 2. tw_get_scenarios_ajax()  - returns available (unplayed) scenarios for a campaign.
 *
 * Credentials are read exclusively via tw_supabase_url() / tw_supabase_anon_key()
 * (defined in supabase-config.php / wp-config.php), matching the project-wide
 * convention. Raw TW_SUPABASE_* constants are no longer referenced here.
 *
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

    // campaign_id from query var or GET fallback.
    // BUG-FIX: cyber_campaign.id is a UUID — do not cast with (int).
    // Sanitize by stripping non-alphanumeric/hyphen characters instead.
    $campaign_id_raw = get_query_var( 'campaign_id' );
    if ( ! $campaign_id_raw && isset( $_GET['campaign_id'] ) ) {
        $campaign_id_raw = $_GET['campaign_id'];
    }
    $campaign_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $campaign_id_raw );

    // Auto-detect last campaign for this user if not provided.
    if ( ! $campaign_id && function_exists( 'tw_supabase_url' ) && function_exists( 'tw_supabase_anon_key' ) ) {
        $anon_key = tw_supabase_anon_key();

        $campaign_url = add_query_arg(
            [
                'wp_user_id' => 'eq.' . (int) $user_id,
                'order'      => 'created_at.desc',
                'limit'      => 1,
            ],
            trailingslashit( tw_supabase_url() ) . 'rest/v1/cyber_campaign'
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
                    // Keep as string — UUID must not be cast to int.
                    $campaign_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $body[0]['id'] );
                    error_log( 'tw_localize_deck_vars: found campaign_id=' . $campaign_id . ' for user ' . $user_id );
                } else {
                    error_log( 'Supabase campaign lookup: empty/invalid body for user ' . $user_id . ' body=' . $body_raw );
                }
            } else {
                error_log( 'Supabase campaign lookup HTTP ' . $code . ': ' . $body_raw );
            }
        }
    }

    error_log( 'tw_localize_deck_vars fired, campaign_id=' . $campaign_id . ', user_id=' . $user_id );

    wp_localize_script(
        'adventure-js',
        'twGameConfig',
        [
            'ajaxurl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'tw_deck_nonce' ),
            'campaign_id' => $campaign_id,   // UUID string, not int
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

    if ( ! check_ajax_referer( 'tw_deck_nonce', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => 'Security check failed' ] );
        return;
    }

    // BUG-FIX: cyber_campaign.id is a UUID — (int) cast collapses it to 0.
    $campaign_id_raw = $_POST['campaign_id'] ?? '';
    $campaign_id     = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $campaign_id_raw );

    error_log( 'tw_get_scenarios_ajax: received campaign_id=' . $campaign_id );

    if ( ! $campaign_id || ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
        wp_send_json_error( [ 'message' => 'Missing campaign_id or Supabase config' ] );
        return;
    }

    $anon_key    = tw_supabase_anon_key();
    $rest_base   = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
    $headers     = [
        'apikey'        => $anon_key,
        'Authorization' => 'Bearer ' . $anon_key,
    ];

    // BUG-FIX: the closure previously called wp_send_json_error() without
    // returning, so execution continued into the next Supabase call after
    // an error response was already sent, producing malformed output and
    // PHP notices about headers already being sent.
    // Fix: add `return` after every wp_send_json_error() call inside the
    // closure. Because wp_send_json_error() calls wp_die() internally this
    // is now belt-and-suspenders, but it makes the intent explicit and
    // prevents issues if wp_die() is ever short-circuited in tests.
    $supa_get = function ( $url, $label ) use ( $headers ) {
        $resp = wp_remote_get( $url, [ 'headers' => $headers, 'timeout' => 10 ] );
        if ( is_wp_error( $resp ) ) {
            wp_send_json_error( [ 'message' => $label . ' fetch failed', 'error' => $resp->get_error_message() ] );
            return null;
        }
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 300 ) {
            wp_send_json_error( [ 'message' => 'Supabase error for ' . $label, 'status' => $code, 'body' => wp_remote_retrieve_body( $resp ) ] );
            return null;
        }
        return json_decode( wp_remote_retrieve_body( $resp ), true );
    };

    // 1. Campaign — use UUID string directly in the filter.
    $campaign_url = $rest_base . 'cyber_campaign?id=eq.' . $campaign_id;
    error_log( 'tw_get_scenarios_ajax: campaign URL = ' . $campaign_url );

    $campaigns = $supa_get( $campaign_url, 'campaign' );

if ( null === $campaigns ) {
    // Błąd został już odesłany w $supa_get().
    return;
}

if ( ! is_array( $campaigns ) ) {
    wp_send_json_error(
        [
            'message' => 'Invalid campaign response',
        ]
    );
    return;
}

if ( [] === $campaigns ) {
    wp_send_json_error(
        [
            'message' => 'Campaign not found',
        ]
    );
    return;
}

$campaign = $campaigns[0];

    $campaign       = $campaigns[0];
    $world_type     = isset( $campaign['world_type'] ) ? (int) $campaign['world_type'] : 1;
    $difficulty_min = $world_type - 1;
    $difficulty_max = $world_type + 1;

    // 2. Played scenarios — filter by UUID campaign_id.
    $played_url = $rest_base . 'cyber_campaign_played_scenarios?campaign_id=eq.' . $campaign_id;
    error_log( 'tw_get_scenarios_ajax: played scenarios URL = ' . $played_url );

    $played = $supa_get( $played_url, 'played scenarios' );
    if ( $played === null ) {
        return;
    }
    $played     = $played ?: [];
    $played_ids = ! empty( $played ) ? array_map( 'intval', array_column( $played, 'scenario_id' ) ) : [];

    error_log( 'tw_get_scenarios_ajax: played_ids=' . ( $played_ids ? implode( ',', $played_ids ) : 'none' ) );

    // 3. Difficulty range (min 1).
    $difficulty_values = array_unique( array_filter(
        [ $difficulty_min, $world_type, $difficulty_max ],
        fn( $v ) => $v >= 1
    ) );

    // 4. Scenarios query.
    $url = add_query_arg(
        [
            'difficulty' => 'in.(' . implode( ',', $difficulty_values ) . ')',
            'type'       => 'eq.main',
            'order'      => 'id.desc',
            'limit'      => 3,
        ],
        $rest_base . 'cyber_scenarios'
    );

    if ( ! empty( $played_ids ) ) {
        $url = add_query_arg( 'id', 'not.in.(' . implode( ',', $played_ids ) . ')', $url );
    }

    error_log( 'tw_get_scenarios_ajax: scenarios URL = ' . $url );

    $scenarios = $supa_get( $url, 'scenarios' );
    if ( $scenarios === null ) {
        return;
    }

    if ( empty( $scenarios ) || ! is_array( $scenarios ) ) {
        wp_send_json_error( [ 'message' => 'No scenarios available' ] );
        return;
    }

    wp_send_json_success( $scenarios );
}
