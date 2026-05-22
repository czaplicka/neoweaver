<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_render_active_game_map' ) ) {
	function tw_render_active_game_map(): string {
		$wp_user_id = get_current_user_id();

		if ( ! $wp_user_id ) {
			return '<div class="tw-map-access-denied">[ACCESS DENIED]: Link not established.</div>';
		}

		if ( function_exists( 'tw_enqueue_map_assets' ) ) {
			tw_enqueue_map_assets(
				array(
					'wpUserId' => (int) $wp_user_id,
				)
			);
		}

		ob_start();
		?>
		<div id="tw-map-container" class="tw-map-container">
			<div class="tw-map-grid-overlay" aria-hidden="true"></div>

			<svg id="cyber-map" class="tw-cyber-map" aria-label="Active world map"></svg>

			<div id="map-legend-container" class="tw-map-legend"></div>

			<div id="location-card" class="tw-location-card" hidden>
				<h4 id="loc-title" class="tw-location-title"></h4>
				<div id="loc-kingdom" class="tw-location-kingdom"></div>
				<p id="loc-desc" class="tw-location-desc"></p>
				<div id="loc-status" class="tw-location-status"></div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	add_shortcode( 'cyber_active_map', 'tw_render_active_game_map' );
}
