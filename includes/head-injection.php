<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ==========================================
// 5. GLOBAL DATA INJECTION (wp_head)
// ==========================================

/**
 * Game-page detection helper.
 *
 * Zwraca true tylko dla stron gry.
 */
if ( ! function_exists( 'tw_is_game_page' ) ) {

	// Domyślna wartość, ustawiana później na hooku 'wp'.
	$GLOBALS['tw_is_game_page_cache'] = false;

	function tw_is_game_page(): bool {
		return (bool) ( $GLOBALS['tw_is_game_page_cache'] ?? false );
	}

	/**
	 * Ustawiamy cache dopiero gdy główne zapytanie jest gotowe.
	 */
	add_action( 'wp', function () {
		// Na wszelki wypadek – jeśli nie ma globalnego query, wyłączamy.
		if ( ! function_exists( 'is_singular' ) || ! function_exists( 'is_page' ) ) {
			$GLOBALS['tw_is_game_page_cache'] = false;
			return;
		}

		if ( ! is_singular() && ! is_page() ) {
			$GLOBALS['tw_is_game_page_cache'] = false;
			return;
		}

		$template = get_page_template_slug( get_queried_object_id() );
		if ( $template && str_starts_with( (string) $template, 'templates/' ) ) {
			$GLOBALS['tw_is_game_page_cache'] = true;
			return;
		}

		$game_slugs = [ 'game', 'play', 'legend', 'deployments', 'field-agents', 'nodes', 'inventory' ];
		$slug       = get_post_field( 'post_name', get_queried_object_id() );
		foreach ( $game_slugs as $prefix ) {
			if ( str_starts_with( (string) $slug, $prefix ) ) {
				$GLOBALS['tw_is_game_page_cache'] = true;
				return;
			}
		}

		$GLOBALS['tw_is_game_page_cache'] = false;
	}, 5 );
}

/**
 * Wstrzykujemy:
 * - Supabase JS
 * - window.twAdventureData (dane gry + konfiguracja)
 */
if ( ! function_exists( 'tw_inject_global_data' ) ) {
	function tw_inject_global_data() {
		// Gate 1: only fire on game-related pages
		if ( ! tw_is_game_page() ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$gm_avatar_url = get_option( 'gm_avatar_url', '' );

		// Gate 2: transient cache (60 s)
		$cache_key = 'tw_game_data_' . $user_id;
		$game_data = get_transient( $cache_key );

		if ( false === $game_data ) {
			if ( function_exists( 'get_user_game_data_from_supabase' ) ) {
				$game_data = get_user_game_data_from_supabase( $user_id );
			} else {
				$game_data = [
					'active_session_id'   => null,
					'active_campaign_id'  => null,
					'active_character_id' => null,
					'active_scenario_id'  => null,
					'char_name'           => 'Unknown',
					'char_class'          => 'None',
					'char_tags'           => [],
					'campaign_world_type' => 1,
					'wp_user_id'          => $user_id,
				];
			}

			set_transient( $cache_key, $game_data, 60 );
		}

		$supabase_url = tw_supabase_url();
		?>
		<script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
		<script id="tw-global-config">
		window.twAdventureData = {
			supabase_url: '<?php echo esc_js( $supabase_url ); ?>',
			supabase_anon_key: '<?php echo esc_js( tw_supabase_anon_key() ); ?>',
			active_session_id: <?php echo isset( $game_data['active_session_id'] ) ? (int) $game_data['active_session_id'] : 'null'; ?>,
			active_campaign_id: <?php echo isset( $game_data['active_campaign_id'] ) ? (int) $game_data['active_campaign_id'] : 'null'; ?>,
			active_character_id: <?php echo isset( $game_data['active_character_id'] ) ? (int) $game_data['active_character_id'] : 'null'; ?>,
			active_scenario_id: <?php echo isset( $game_data['active_scenario_id'] ) ? (int) $game_data['active_scenario_id'] : 'null'; ?>,
			char_name: '<?php echo isset( $game_data['char_name'] ) ? esc_js( $game_data['char_name'] ) : 'Unknown'; ?>',
			char_class: '<?php echo isset( $game_data['char_class'] ) ? esc_js( $game_data['char_class'] ) : 'None'; ?>',
			char_tags: <?php echo isset( $game_data['char_tags'] ) ? wp_json_encode( $game_data['char_tags'] ) : '[]'; ?>,
			campaign_world_type: <?php echo isset( $game_data['campaign_world_type'] ) ? (int) $game_data['campaign_world_type'] : 1; ?>,
			wp_user_id: <?php echo (int) $user_id; ?>,
			ajax_url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
			nonce: '<?php echo esc_js( wp_create_nonce( 'tw_nonce' ) ); ?>',
			gm_avatar: '<?php echo esc_url( $gm_avatar_url ); ?>'
		};
		console.log('🔗 twAdventureData injected (cached):', window.twAdventureData);
		</script>
		<?php
	}

	add_action( 'wp_head', 'tw_inject_global_data', 1 );
}
