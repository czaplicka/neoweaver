<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_signal_quality_shortcode' ) ) {
	function tw_signal_quality_shortcode(): string {
		if ( ! is_singular() ) {
			return '';
		}

		$template = get_page_template_slug( get_queried_object_id() );

		if ( 'templates/adventure.php' !== $template ) {
			return '';
		}

		$wp_user_id = get_current_user_id();

		if ( ! $wp_user_id ) {
			return '';
		}

		if ( ! function_exists( 'tw_supabase_get' ) ) {
			return '';
		}

		$sessions = tw_supabase_get(
			'cyber_game_sessions',
			array(
				'wp_user_id' => 'eq.' . (int) $wp_user_id,
				'status'     => 'eq.active',
				'select'     => 'location_id,cyber_world_map(location_archetype_id,cyber_location_archetypes(base_tech))',
			)
		);

		if ( empty( $sessions ) || ! is_array( $sessions ) ) {
			return '';
		}

		$session  = $sessions[0];
		$location = $session['cyber_world_map'] ?? null;

		if ( ! is_array( $location ) ) {
			return '';
		}

		$archetype = $location['cyber_location_archetypes'] ?? null;
		$base_tech = is_array( $archetype ) && isset( $archetype['base_tech'] ) ? (int) $archetype['base_tech'] : 3;

		$world_tech_level = max( 1, min( 5, $base_tech ) );
		$signal_strength  = ( $world_tech_level / 5 ) * 100;

		if ( function_exists( 'tw_enqueue_signal_quality_assets' ) ) {
			tw_enqueue_signal_quality_assets();
		}

		$status_text = 'STATUS: QUANTUM-CLEAN LINK ESTABLISHED';

		if ( $world_tech_level <= 2 ) {
			$status_text = 'STATUS: UNSTABLE / ANALOG INTERFERENCE DETECTED';
		} elseif ( $world_tech_level <= 4 ) {
			$status_text = 'STATUS: HYBRID GRID – SIGNAL WITH NOISE';
		}

		ob_start();
		?>
		<div class="neoweave-signal-monitor" data-signal-level="<?php echo esc_attr( (string) $world_tech_level ); ?>">
			<div class="signal-label">
				SIGNAL INTEGRITY: <?php echo esc_html( (string) $world_tech_level ); ?>/5
			</div>

			<div class="signal-bar-container">
				<div class="signal-bar-fill" style="width: <?php echo esc_attr( (string) $signal_strength ); ?>%;"></div>
			</div>

			<div class="signal-status">
				<?php echo esc_html( $status_text ); ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	add_shortcode( 'SIGNAL_QUALITY', 'tw_signal_quality_shortcode' );
}
