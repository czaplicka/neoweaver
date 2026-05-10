<?php
// ==========================================
// WORLD STATE: AUTO-INIT FOR CAMPAIGN
// ==========================================

if ( ! function_exists( 'tw_ensure_world_state' ) ) {
    add_action( 'wp_ajax_tw_ensure_world_state', 'tw_ensure_world_state' );

    function tw_ensure_world_state() {
        // 1. Security
        check_ajax_referer( 'tw_nonce', 'nonce' );

        // BUG-FIX: campaign_id is a UUID, not an int. intval() collapses any
        // UUID to 0, making the Supabase filter match nothing. Use string sanitize.
        $campaign_id = isset( $_POST['campaign_id'] )
            ? preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $_POST['campaign_id'] )
            : '';

        if ( ! $campaign_id ) {
            wp_send_json_error( 'Missing campaign_id' );
            return;
        }

        $supabase_url = tw_supabase_url();
        $anon_key     = tw_supabase_anon_key();

        if ( empty( $supabase_url ) || empty( $anon_key ) ) {
            wp_send_json_error( 'Supabase config missing' );
            return;
        }

        $base = trailingslashit( $supabase_url ) . 'rest/v1/cyber_world_state';

        // 2. Check if row already exists
        $check_url = add_query_arg( [
            'campaign_id' => 'eq.' . $campaign_id,
            'select'      => 'campaign_id',
            'limit'       => 1,
        ], $base );

        $check_resp = wp_remote_get( $check_url, [
            'headers'   => [
                'apikey'        => $anon_key,
                'Authorization' => 'Bearer ' . $anon_key,
            ],
            'timeout'   => 10,
            'sslverify' => true,
        ] );

        if ( is_wp_error( $check_resp ) ) {
            wp_send_json_error( 'Check error' );
            return;
        }

        $code = wp_remote_retrieve_response_code( $check_resp );
        $body = wp_remote_retrieve_body( $check_resp );
        $rows = json_decode( $body, true );

        if ( $code === 200 && is_array( $rows ) && ! empty( $rows ) ) {
            wp_send_json_success( [ 'status' => 'exists' ] );
            return;
        }

        // 3. Create default row
        $payload = [
            'campaign_id'     => $campaign_id,
            'current_hour'    => 8,
            'current_weather' => 'Sun',
            'next_weather'    => 'Cloudy',
            'current_season'  => 'Spring',
        ];

        $insert_resp = wp_remote_post( $base, [
            'headers'   => [
                'apikey'        => $anon_key,
                'Authorization' => 'Bearer ' . $anon_key,
                'Content-Type'  => 'application/json',
                'Prefer'        => 'return=representation',
            ],
            'body'      => wp_json_encode( $payload ),
            'timeout'   => 10,
            'sslverify' => true,
        ] );

        if ( is_wp_error( $insert_resp ) ) {
            wp_send_json_error( 'Insert error' );
            return;
        }

        $insert_code = wp_remote_retrieve_response_code( $insert_resp );
        $insert_body = wp_remote_retrieve_body( $insert_resp );
        $insert_rows = json_decode( $insert_body, true );

        if ( $insert_code < 200 || $insert_code >= 300 ) {
            wp_send_json_error( 'Insert failed' );
            return;
        }

        wp_send_json_success( [
            'status' => 'created',
            'row'    => $insert_rows[0] ?? null,
        ] );
    }
}
