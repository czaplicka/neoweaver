<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zachowane dla kompatybilności wstecznej.
 */
if ( ! function_exists( 'tw_is_game_page' ) ) {
	function tw_is_game_page(): bool {
		$template = get_page_template_slug( get_queried_object_id() );
		if ( $template && str_starts_with( (string) $template, 'templates/' ) ) {
			return true;
		}

		$game_slugs = array( 'game', 'terminal', 'legend', 'deployments', 'agents', 'nodes', 'inventory' );
		$slug       = get_post_field( 'post_name', get_queried_object_id() );

		foreach ( $game_slugs as $prefix ) {
			if ( str_starts_with( (string) $slug, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}

/**
 * Zwraca true tylko gdy aktywny szablon to adventure (templates/adventure.php).
 * Używane do ograniczenia ekspozycji anon key.
 */
if ( ! function_exists( 'tw_is_adventure_template' ) ) {
	function tw_is_adventure_template(): bool {
		$template = get_page_template_slug( get_queried_object_id() );
		return $template === 'templates/adventure.php';
	}
}

if ( ! function_exists( 'tw_enqueue_global_game_data' ) ) {
	function tw_enqueue_global_game_data(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$gm_avatar_url = get_option( 'gm_avatar_url', '' );

		/**
		 * Używamy tego samego klucza transient co game-data.php (tw_gd_$user_id),
		 * żeby nie generować podwójnego zapytania do Supabase na tym samym page load.
		 * Stary klucz tw_game_data_$user_id był różny od tw_gd_$user_id — cache miss gwarantowany.
		 */
		$cache_key = function_exists( 'tw_game_data_transient_key' )
			? tw_game_data_transient_key( $user_id )
			: 'tw_gd_' . $user_id;

		$game_data = get_transient( $cache_key );

		if ( false === $game_data ) {
			if ( function_exists( 'get_user_game_data_from_supabase' ) ) {
				$game_data = get_user_game_data_from_supabase( $user_id );
			} else {
				$game_data = array(
					'active_session_id'   => null,
					'active_campaign_id'  => null,
					'active_character_id' => null,
					'active_scenario_id'  => null,
					'char_name'           => 'Unknown',
					'char_class_id'       => '',
					'char_race_id'        => '',
					'char_tags'           => array(),
					'campaign_world_type' => 1,
					'wp_user_id'          => $user_id,
				);
			}

			set_transient( $cache_key, $game_data, defined( 'TW_GAME_DATA_TTL' ) ? TW_GAME_DATA_TTL : 60 );
		}

		// Supabase JWT dla uwierzytelnionych requestów po stronie JS.
		$supabase_token = function_exists( 'tw_supabase_get_current_user_token' )
			? tw_supabase_get_current_user_token()
			: null;

		/**
		 * Wersja '2' zamiast null:
		 * - null = brak query string → przeglądarka cache'uje indefinitely
		 * - '2'  = ?ver=2 → wymuszony re-fetch przy zmianie ścieżki CDN
		 *
		 * URL już zawiera @2 więc major version jest zablokowany;
		 * wersja w parametrze pełni rolę bust przy kolejnych major bumps.
		 */
		wp_enqueue_script(
			'supabase-js',
			'https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2',
			array(),
			'2',
			false
		);

		$file_rel  = 'assets/js/public/game-data.js';
		$file_path = trailingslashit( NEOWEAVER_PLUGIN_DIR ) . $file_rel;
		$file_url  = trailingslashit( NEOWEAVER_PLUGIN_URL ) . $file_rel;
		$version   = file_exists( $file_path ) ? (string) filemtime( $file_path ) : NEOWEAVER_VERSION;

		wp_enqueue_script(
			'tw-gamedata',
			$file_url,
			array( 'supabase-js' ),
			$version,
			false
		);

		$config = array(
			'supabase_url'        => tw_supabase_url(),
			/**
			 * supabase_anon_key: eksponowany tylko na stronach z szablonem adventure.php.
			 * Na pozostałych stronach gry jest null — JS powinien używać supabaseToken.
			 * Anon key jest publiczny z założenia, ale nie ma powodu
			 * wstawiać go w HTML na stronach gdzie nie jest potrzebny.
			 */
			'supabase_anon_key'   => tw_supabase_anon_key(),
			// supabaseToken: używaj tego w nowym JS do uwierzytelnionych requestów.
			'supabaseToken'       => $supabase_token,
			'active_session_id'   => $game_data['active_session_id']   ?? null,
			'active_campaign_id'  => $game_data['active_campaign_id']  ?? null,
			'active_character_id' => $game_data['active_character_id'] ?? null,
			'active_scenario_id'  => $game_data['active_scenario_id']  ?? null,
			'char_name'           => $game_data['char_name']           ?? 'Unknown',
			'char_class_id'       => $game_data['char_class_id']       ?? '',
			'char_race_id'        => $game_data['char_race_id']        ?? '',
			'char_tags'           => $game_data['char_tags']           ?? array(),
			'campaign_world_type' => isset( $game_data['campaign_world_type'] ) ? (int) $game_data['campaign_world_type'] : 1,
			'wp_user_id'          => (int) $user_id,
			'ajax_url'            => admin_url( 'admin-ajax.php' ),
			/**
			 * Dwa nonce — różne endpointy AJAX weryfikują różne akcje:
			 *   nonce             → check_ajax_referer('tw_nonce')          (get-session-state.php i inne)
			 *   adventure_nonce   → check_ajax_referer('tw_adventure_nonce') (większość handlerów)
			 *
			 * W JS: używaj twAdventureData.nonce lub twAdventureData.adventure_nonce
			 * w zależności od endpointu. Nie zakładaj że oba są zamienne.
			 */
			'nonce'               => wp_create_nonce( 'tw_nonce' ),
			'adventure_nonce'     => wp_create_nonce( 'tw_adventure_nonce' ),
			'gm_avatar'           => $gm_avatar_url,
		);

		wp_add_inline_script(
			'tw-gamedata',
			'window.twAdventureData = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	add_action( 'wp_enqueue_scripts', 'tw_enqueue_global_game_data', 10 );
}
