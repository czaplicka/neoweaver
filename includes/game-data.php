<?php
// BUG-FIX: File was missing the PHP opening tag entirely — PHP parsed it
// as plain text so none of the functions below were ever defined, causing
// get_user_game_data_from_supabase() and tw_get_current_character_id() to
// silently not exist at runtime.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agregacja danych o sesji, postaci i świecie z Supabase.
 *
 * BUG-FIX: All IDs from cyber_game_sessions were coerced with (int), but
 * session.id, campaign_id, character_id, world_id, and scenario_id are all
 * UUID strings in Supabase. Casting them to int collapses every UUID to 0,
 * breaking every downstream Supabase query that filters on those values.
 *
 * Fix: IDs are now stored as raw strings sanitized with
 * preg_replace('/[^a-zA-Z0-9\-]/', '', ...) — safe for both UUID v4 and
 * any legacy integer IDs. The defaults array is also changed from 0 to ''
 * so callers can correctly detect "no ID" with empty() instead of !$id.
 *
 * location_id is a regular integer FK — it keeps (int) casting.
 */
if ( ! function_exists( 'get_user_game_data_from_supabase' ) ) {
    function get_user_game_data_from_supabase( $wp_user_id ) {
        $defaults = [
            'active_session_id'   => '',
            'active_campaign_id'  => '',
            'active_character_id' => '',
            'active_scenario_id'  => '',
            'active_world_id'     => '',
            'active_location_id'  => 0,
            'char_name'           => 'Nieznany Bohater',
            'char_class'          => '',
            'char_tags'           => [],
            'campaign_world_type' => 1,
            'wp_user_id'          => $wp_user_id,
        ];

        if ( ! $wp_user_id ) {
            return $defaults;
        }

        // Helper: UUID-safe ID sanitization — never use (int) on a UUID.
        $sanitize_id = function ( $raw ): string {
            return preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $raw );
        };

        // 1. Aktywna sesja (cyber_game_sessions)
        $sessions = tw_supabase_get(
            'cyber_game_sessions',
            [
                'wp_user_id' => 'eq.' . $wp_user_id,
                'status'     => 'eq.active',
                'order'      => 'created_at.desc',
                'limit'      => 1,
                'select'     => 'id,campaign_id,character_id,scenario_id,world_id,location_id',
            ]
        );

        if ( ! is_array( $sessions ) || empty( $sessions ) || ! isset( $sessions[0] ) || ! is_array( $sessions[0] ) ) {
            return $defaults;
        }

        $session = $sessions[0];

        // UUID columns — sanitize as strings, never cast to int.
        $defaults['active_session_id']   = isset( $session['id'] )           ? $sanitize_id( $session['id'] )           : '';
        $defaults['active_campaign_id']  = isset( $session['campaign_id'] )  ? $sanitize_id( $session['campaign_id'] )  : '';
        $defaults['active_character_id'] = isset( $session['character_id'] ) ? $sanitize_id( $session['character_id'] ) : '';
        $defaults['active_world_id']     = isset( $session['world_id'] )     ? $sanitize_id( $session['world_id'] )     : '';
        $defaults['active_scenario_id']  = ! empty( $session['scenario_id'] ) ? $sanitize_id( $session['scenario_id'] ) : '';

        // location_id is an integer FK — (int) is correct here.
        $defaults['active_location_id']  = isset( $session['location_id'] ) ? (int) $session['location_id'] : 0;

        // 2. Postać + TAGI
        if ( $defaults['active_character_id'] ) {
            $tags_data = tw_supabase_get(
                'cyber_character_complete_tags',
                [ 'character_id' => 'eq.' . $defaults['active_character_id'] ]
            );
            $defaults['char_tags'] = is_array( $tags_data ) ? $tags_data : [];

            $chars = tw_supabase_get(
                'cyber_characters',
                [
                    'id'     => 'eq.' . $defaults['active_character_id'],
                    'select' => 'name,class',
                    'limit'  => 1,
                ]
            );

            if ( is_array( $chars ) && isset( $chars[0] ) && is_array( $chars[0] ) ) {
                $defaults['char_name']  = $chars[0]['name'] ?? 'Bohater';
                $defaults['char_class'] = $chars[0]['class'] ?? '';
            }
        }

        // 3. Kampania (cyber_campaign)
        if ( $defaults['active_campaign_id'] ) {
            $campaigns = tw_supabase_get(
                'cyber_campaign',
                [
                    'id'     => 'eq.' . $defaults['active_campaign_id'],
                    'select' => 'world_type',
                    'limit'  => 1,
                ]
            );

            if ( is_array( $campaigns ) && isset( $campaigns[0] ) && is_array( $campaigns[0] ) ) {
                $defaults['campaign_world_type'] = $campaigns[0]['world_type'] ?? 1;
            }
        }

        return $defaults;
    }
}

/**
 * Skrót – pobiera active_character_id dla aktualnego użytkownika.
 * Returns the UUID string (or empty string if not found).
 */
if ( ! function_exists( 'tw_get_current_character_id' ) ) {
    function tw_get_current_character_id(): string {
        $wp_user_id = get_current_user_id();
        if ( ! $wp_user_id ) {
            return '';
        }

        $game_data = get_user_game_data_from_supabase( $wp_user_id );
        if ( ! is_array( $game_data ) ) {
            return '';
        }

        return (string) ( $game_data['active_character_id'] ?? '' );
    }
}
