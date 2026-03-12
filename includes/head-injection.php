<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ==========================================
// 5. GLOBAL DATA INJECTION (wp_head)
// ==========================================

/**
 * Wstrzykujemy:
 * - Supabase JS
 * - window.twAdventureData (dane gry + konfiguracja)
 *
 * BUG-FIX 1: The entire function + add_action block was duplicated in the
 * file (a second bare `<?php` tag began a copy-paste of the same code).
 * PHP would fatal with "Cannot redeclare tw_inject_global_data()" on the
 * second declaration. The duplicate block is removed.
 * The add_action call is also moved inside the function_exists guard so it
 * is only registered once even if something causes the file to be parsed
 * more than once.
 */
if ( ! function_exists( 'tw_inject_global_data' ) ) {
	function tw_inject_global_data() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$gm_avatar_url = get_option( 'gm_avatar_url', '' );

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
		console.log('🔗 twAdventureData injected:', window.twAdventureData);
		</script>
		<?php
	}

	add_action( 'wp_head', 'tw_inject_global_data', 1 );
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
