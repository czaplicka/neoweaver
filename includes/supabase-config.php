<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Supabase config for NeoWeaver.
 * Używa stałych z wp-config, a poniżej ma bezpieczne fallbacki dev/test.
 */
if ( ! function_exists( 'tw_supabase_url' ) ) {
    function tw_supabase_url() {
        if ( defined( 'TW_SUPABASE_PROJECT_ID' ) ) {
            return 'https://' . TW_SUPABASE_PROJECT_ID . '.supabase.co';
        }
        return 'https://kkccgwbywkxlhtvfxekm.supabase.co';
    }
}

if ( ! function_exists( 'tw_supabase_anon_key' ) ) {
    function tw_supabase_anon_key() {
        if ( defined( 'TW_SUPABASE_ANON_KEY' ) ) {
            return TW_SUPABASE_ANON_KEY;
        }
        return 'sb_publishable_13RdJpqcXalg1L6nedytVQ_7MlyKSsY';
    }
}

if ( ! function_exists( 'tw_supabase_service_key' ) ) {
    function tw_supabase_service_key() {
        if ( defined( 'TW_SUPABASE_SERVICE_KEY' ) ) {
            return TW_SUPABASE_SERVICE_KEY;
        }
        error_log( 'TW supabase-config.php: TW_SUPABASE_SERVICE_KEY is not defined in wp-config.php.' );
        return '';
    }
}

if ( ! function_exists( 'tw_supabase_key' ) ) {
    function tw_supabase_key() {
        return tw_supabase_anon_key();
    }
}

// ---------------------------------------------------------------------------
// Backward-compatibility aliases
// Old admin files (pre-refactor) used nw_supabase_url() / nw_supabase_anon_key().
// These shims prevent Fatal errors when a stale file is still on the server.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'nw_supabase_url' ) ) {
    function nw_supabase_url() {
        return tw_supabase_url();
    }
}

if ( ! function_exists( 'nw_supabase_anon_key' ) ) {
    function nw_supabase_anon_key() {
        return tw_supabase_anon_key();
    }
}
