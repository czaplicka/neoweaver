<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * tw_is_game_page() zachowana dla kompatybilności wstecznej.
 * Używana przez inne pliki pluginu do warunkowego ładowania
 * skryptów specyficznych dla adventure template.
 */
if ( ! function_exists( 'tw_is_game_page' ) ) {
	function tw_is_game_page(): bool {
		$template = get_page_template_slug( get_queried_object_id() );
		if ( $template && str_starts_with( (string) $template, 'templates/' ) ) {
			return true;
		}

		$game_slugs = [ 'game', 'play', 'legend', 'deployments', 'field-agents', 'nodes', 'inventory' ];
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
 * Wstrzykujemy globalnie (dla każdego zalogowanego użytkownika):
 * - Supabase JS CDN
 * - window.twAdventureData (dane gry + konfiguracja)
 * - window.twSupabase (klient Supabase gotowy do użycia)
 *
 * Dzięki temu shortcode'y, widgety i inne elementy pluginu
 * mogą używać Supabase na dowolnej stronie WordPressa.
 */
if ( ! function_exists( 'tw_inject_global_data' ) ) {
	function tw_inject_global_data() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$gm_avatar_url = get_option( 'gm_avatar_url', '' );

		// Transient cache (60 s) — działa też poza game page
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
			active_session_id: <?php echo isset( $game_data['active_session_id'] ) ? wp_json_encode( $game_data['active_session_id'] ) : 'null'; ?>,
			active_campaign_id: <?php echo isset( $game_data['active_campaign_id'] ) ? wp_json_encode( $game_data['active_campaign_id'] ) : 'null'; ?>,
			active_character_id: <?php echo isset( $game_data['active_character_id'] ) ? wp_json_encode( $game_data['active_character_id'] ) : 'null'; ?>,
			active_scenario_id: <?php echo isset( $game_data['active_scenario_id'] ) ? wp_json_encode( $game_data['active_scenario_id'] ) : 'null'; ?>,
			char_name: '<?php echo isset( $game_data['char_name'] ) ? esc_js( $game_data['char_name'] ) : 'Unknown'; ?>',
			char_class: '<?php echo isset( $game_data['char_class'] ) ? esc_js( $game_data['char_class'] ) : 'None'; ?>',
			char_tags: <?php echo isset( $game_data['char_tags'] ) ? wp_json_encode( $game_data['char_tags'] ) : '[]'; ?>,
			campaign_world_type: <?php echo isset( $game_data['campaign_world_type'] ) ? (int) $game_data['campaign_world_type'] : 1; ?>,
			wp_user_id: <?php echo (int) $user_id; ?>,
			ajax_url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
			nonce: '<?php echo esc_js( wp_create_nonce( 'tw_nonce' ) ); ?>',
			gm_avatar: '<?php echo esc_url( $gm_avatar_url ); ?>'
		};
		// Inicjalizacja klienta Supabase — dostępna globalnie na każdej stronie
		if (window.supabase && !window.twSupabase) {
			window.twSupabase = window.supabase.createClient(
				window.twAdventureData.supabase_url,
				window.twAdventureData.supabase_anon_key
			);
		}
		console.log('🔗 twAdventureData injected (cached):', window.twAdventureData);
		</script>
		<?php
	}

	add_action( 'wp_head', 'tw_inject_global_data', 10 );
}
