<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Supabase config for NeoWeaver.
 * Używa stałych z wp-config.
 */

if ( ! function_exists( 'tw_supabase_url' ) ) {
    function tw_supabase_url() {
        if ( defined( 'TW_SUPABASE_PROJECT_ID' ) ) {
            return 'https://' . TW_SUPABASE_PROJECT_ID . '.supabase.co';
        }
        return '';
    }
}

if ( ! function_exists( 'tw_supabase_anon_key' ) ) {
    function tw_supabase_anon_key() {
        if ( defined( 'TW_SUPABASE_ANON_KEY' ) ) {
            return TW_SUPABASE_ANON_KEY;
        }
        return '';
    }
}

if ( ! function_exists( 'tw_supabase_service_key' ) ) {
    function tw_supabase_service_key() {
        if ( defined( 'TW_SUPABASE_SERVICE_KEY' ) ) {
            return TW_SUPABASE_SERVICE_KEY;
        }
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
