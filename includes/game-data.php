<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Per-request in-memory memoization cache.
 * Żyje tylko przez czas życia jednego PHP request — 0 kosztu dla prywatności danych.
 */
$GLOBALS['_tw_game_data_memo'] = [];

/**
 * Transient TTL w sekundach.
 * 60s = sensowny kompromis między świeżością a liczbą zapytań do Supabase.
 * Zmień na niższą wartość jeśli game state zmienia się bardzo szybko.
 */
define( 'TW_GAME_DATA_TTL', 60 );

/**
 * Klucz transient — per user, by avoid cross-user leaks.
 */
function tw_game_data_transient_key( int $wp_user_id ): string {
    return 'tw_gd_' . $wp_user_id;
}

/**
 * Unieważnia cache dla użytkownika — wywołaj to przy:
 * - zmianie sesji (nowa kampania, zmiana postaci)
 * - zakończeniu sesji
 * - zmianie lokalizacji / scenario
 */
function tw_invalidate_game_data_cache( int $wp_user_id ): void {
    // 1. Usuń memo
    unset( $GLOBALS['_tw_game_data_memo'][ $wp_user_id ] );

    // 2. Usuń transient
    delete_transient( tw_game_data_transient_key( $wp_user_id ) );
}

/**
 * Agregacja danych o sesji, postaci i świecie z Supabase.
 *
 * Warstwa 1 — per-request memoization ($GLOBALS):
 *   Jeśli ta sama funkcja jest wywoływana wielokrotnie w tym samym PHP request
 *   (np. przez tw_get_current_character_id() i potem przez AJAX handler),
 *   zwraca wynik z pamięci bez żadnego query.
 *
 * Warstwa 2 — WordPress Transients (domyślnie WP Object Cache lub DB):
 *   TTL = TW_GAME_DATA_TTL sekund. Przy Redis/Memcached — ultra-szybkie.
 *   Przy braku zewnętrznego cache — zapis w wp_options, nadal ~10x szybszy
 *   niż 3 round-tripy do Supabase.
 *
 * Warstwa 3 — Supabase query (tylko gdy cache miss).
 *
 * INVALIDATION: wywołaj tw_invalidate_game_data_cache($wp_user_id) przy
 * każdej zmianie stanu sesji (nowa kampania, teleport, koniec sesji).
 */
if ( ! function_exists( 'get_user_game_data_from_supabase' ) ) {
    function get_user_game_data_from_supabase( int $wp_user_id ): array {
        $defaults = [
            'active_session_id'   => '',
            'active_campaign_id'  => '',
            'active_character_id' => '',
            'active_scenario_id'  => '',
            'active_world_id'     => '',
            'active_location_id'  => 0,
            'char_name'           => 'Nieznany Bohater',
            'char_class_id'       => '',
            'char_race_id'        => '',
            'char_tags'           => [],
            'campaign_world_type' => 1,
            'wp_user_id'          => $wp_user_id,
        ];

        if ( ! $wp_user_id ) {
            return $defaults;
        }

        // ── Warstwa 1: per-request memo ─────────────────────────────────────
        if ( isset( $GLOBALS['_tw_game_data_memo'][ $wp_user_id ] ) ) {
            return $GLOBALS['_tw_game_data_memo'][ $wp_user_id ];
        }

        // ── Warstwa 2: WordPress Transient ──────────────────────────────────
        $cache_key = tw_game_data_transient_key( $wp_user_id );
        $cached    = get_transient( $cache_key );

        if ( false !== $cached && is_array( $cached ) ) {
            $GLOBALS['_tw_game_data_memo'][ $wp_user_id ] = $cached;
            return $cached;
        }

        // ── Warstwa 3: Supabase query ────────────────────────────────────────
        $sanitize_id = static function ( $raw ): string {
            return preg_replace( '/[^a-zA-Z0-9\\-]/', '', (string) $raw );
        };

        // 1. Aktywna sesja
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
            set_transient( $cache_key, $defaults, 15 );
            $GLOBALS['_tw_game_data_memo'][ $wp_user_id ] = $defaults;
            return $defaults;
        }

        $session = $sessions[0];

        $defaults['active_session_id']   = isset( $session['id'] )            ? $sanitize_id( $session['id'] )           : '';
        $defaults['active_campaign_id']  = isset( $session['campaign_id'] )   ? $sanitize_id( $session['campaign_id'] )  : '';
        $defaults['active_character_id'] = isset( $session['character_id'] )  ? $sanitize_id( $session['character_id'] ) : '';
        $defaults['active_world_id']     = isset( $session['world_id'] )      ? $sanitize_id( $session['world_id'] )     : '';
        $defaults['active_scenario_id']  = ! empty( $session['scenario_id'] ) ? $sanitize_id( $session['scenario_id'] )  : '';
        $defaults['active_location_id']  = isset( $session['location_id'] )   ? (int) $session['location_id']            : 0;

        // 2. Postać + tagi (tylko gdy mamy character_id)
        if ( $defaults['active_character_id'] ) {
            $tags_data = tw_supabase_get(
                'cyber_character_complete_tags',
                [ 'character_id' => 'eq.' . $defaults['active_character_id'] ]
            );
            $defaults['char_tags'] = is_array( $tags_data ) ? $tags_data : [];

            // POPRAWKA: cyber_characters nie ma kolumn 'race' ani 'class'
            // — poprawne nazwy to 'race_id' i 'class_id' (uuid)
            $chars = tw_supabase_get(
                'cyber_characters',
                [
                    'id'     => 'eq.' . $defaults['active_character_id'],
                    'select' => 'name,class_id,race_id',
                    'limit'  => 1,
                ]
            );

            if ( is_array( $chars ) && isset( $chars[0] ) && is_array( $chars[0] ) ) {
                $defaults['char_name']     = $chars[0]['name']     ?? 'Bohater';
                $defaults['char_class_id'] = $chars[0]['class_id'] ?? '';
                $defaults['char_race_id']  = $chars[0]['race_id']  ?? '';
            }
        }

        // 3. Kampania (tylko gdy mamy campaign_id)
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

        // Zapisz do Transient i memo
        set_transient( $cache_key, $defaults, TW_GAME_DATA_TTL );
        $GLOBALS['_tw_game_data_memo'][ $wp_user_id ] = $defaults;

        return $defaults;
    }
}

/**
 * Skrót — pobiera active_character_id dla aktualnego użytkownika.
 */
if ( ! function_exists( 'tw_get_current_character_id' ) ) {
    function tw_get_current_character_id(): string {
        $wp_user_id = get_current_user_id();
        if ( ! $wp_user_id ) {
            return '';
        }

        $game_data = get_user_game_data_from_supabase( $wp_user_id );

        return (string) ( $game_data['active_character_id'] ?? '' );
    }
}
/**
 * AUTO-INVALIDATION: podpina tw_invalidate_game_data_cache() do zdarzeń gry.
 */

// 1. Zmiana sesji — gdy gracz dołącza / opuszcza kampanię
add_action( 'tw_session_started',   'tw_auto_invalidate_cache_hook' );
add_action( 'tw_session_ended',     'tw_auto_invalidate_cache_hook' );

// 2. Zmiana lokalizacji (teleport, przejście)
add_action( 'tw_location_changed',  'tw_auto_invalidate_cache_hook' );

// 3. Zmiana aktywnej postaci
add_action( 'tw_character_changed', 'tw_auto_invalidate_cache_hook' );

// 4. Dołączenie do kampanii
add_action( 'tw_campaign_joined',   'tw_auto_invalidate_cache_hook' );

/**
 * Callback wspólny dla wszystkich hooków.
 * Każdy hook musi przekazać wp_user_id jako pierwszy argument.
 */
function tw_auto_invalidate_cache_hook( int $wp_user_id ): void {
    if ( $wp_user_id > 0 ) {
        tw_invalidate_game_data_cache( $wp_user_id );
    }
}
