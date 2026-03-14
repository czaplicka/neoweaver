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
 * Returns true only for pages that actually need window.twAdventureData:
 *   - Pages using a NeoWeaver PHP template (templates/*.php)
 *   - The hard-coded game page (ID 2857)
 *   - Any page whose slug starts with one of the game prefixes
 *
 * All other WordPress pages (blog, shop, etc.) are excluded so we never
 * fire 3 Supabase HTTP requests on irrelevant page loads.
 */
if ( ! function_exists( 'tw_is_game_page' ) ) {
	function tw_is_game_page(): bool {
		if ( ! is_singular() && ! is_page() ) {
			return false;
		}

		// Hard-coded game page ID
		if ( is_page( 2857 ) ) {
			return true;
		}

		// Any page using a NeoWeaver PHP template
		$template = get_page_template_slug( get_queried_object_id() );
		if ( $template && str_starts_with( $template, 'templates/' ) ) {
			return true;
		}

		// Slug-based guard for game section pages
		$game_slugs = [ 'game', 'play', 'legend', 'deployments', 'field-agents', 'nodes', 'inventory' ];
		$slug = get_post_field( 'post_name', get_queried_object_id() );
		foreach ( $game_slugs as $prefix ) {
			if ( str_starts_with( $slug, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}

/**
 * Wstrzykujemy:
 * - Supabase JS
 * - window.twAdventureData (dane gry + konfiguracja)
 *
 * BUG-FIX (original): duplicate function declaration removed.
 * BUG-FIX #4: added two-layer guard:
 *   1. tw_is_game_page() — skips all non-game pages entirely.
 *   2. 60-second per-user transient — eliminates repeated Supabase calls
 *      on the same game page across multiple requests within one minute.
 *      The transient is invalidated on session/character change by calling
 *      tw_invalidate_game_data_cache( $user_id ) from any write handler.
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

		// Gate 2: transient cache (60 s) — avoids 3 Supabase calls per page hit
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

			// Cache for 60 seconds. Short enough that game state feels live;
			// long enough to collapse burst requests (e.g. multi-tab reload).
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

/**
 * Cache invalidation helper — call this whenever a user's session,
 * character, or campaign changes so the next page load fetches fresh data.
 *
 * Usage: tw_invalidate_game_data_cache( get_current_user_id() );
 */
if ( ! function_exists( 'tw_invalidate_game_data_cache' ) ) {
	function tw_invalidate_game_data_cache( int $user_id ): void {
		delete_transient( 'tw_game_data_' . $user_id );
	}
}

/**
 * Globalna inicjalizacja Supabase JS po załadowaniu DOM.
 */
add_action( 'wp_head', function () {
	?>
	<script>
	document.addEventListener('DOMContentLoaded', function () {
		(function () {
			if (window.twSupabase) return;

			if (!window.twAdventureData) {
				console.error('twAdventureData missing – cannot init Supabase');
				return;
			}

			const supabaseUrl = window.twAdventureData.supabase_url;
			const supabaseKey = window.twAdventureData.supabase_anon_key;

			if (!supabaseUrl || !supabaseKey) {
				console.error('Supabase config missing (url/key)');
				return;
			}

			if (!window.supabase) {
				console.error('Supabase JS library not loaded');
				return;
			}

			const client = window.supabase.createClient(supabaseUrl, supabaseKey);
			window.twSupabase = client;
			console.log('✅ Supabase client created globally for NeoWeaver');
			document.dispatchEvent(new Event('twSupabaseReady'));
		})();
	});
	</script>
	<?php
}, 5 );
