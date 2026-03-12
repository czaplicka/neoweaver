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
        // BUG-FIX 4: The original code had a hardcoded service/secret key as a
        // plaintext fallback. Service keys have full DB access and must NEVER
        // appear in source code — they belong exclusively in wp-config.php (which
        // is outside the webroot and excluded from version control).
        // If TW_SUPABASE_SERVICE_KEY is not defined, we return an empty string so
        // any call that needs the service key fails loudly rather than silently
        // using a leaked credential.
        error_log( 'TW supabase-config.php: TW_SUPABASE_SERVICE_KEY is not defined in wp-config.php.' );
        return '';
    }
}

if ( ! function_exists( 'tw_supabase_key' ) ) {
    function tw_supabase_key() {
        return tw_supabase_anon_key();
    }
}
