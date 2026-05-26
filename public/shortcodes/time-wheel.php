<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'neoweaver_render_time_wheel' ) ) {
	function neoweaver_render_time_wheel(): string {
		$wp_user_id = get_current_user_id();

		if ( ! $wp_user_id ) {
			return '';
		}

		if ( ! function_exists( 'tw_supabase_url' ) || ! tw_supabase_url() ) {
			return '';
		}

		if ( ! function_exists( 'tw_supabase_service_key' ) || ! tw_supabase_service_key() ) {
			return '';
		}

		if ( ! function_exists( 'get_user_game_data_from_supabase' ) ) {
			return '';
		}

		$supabase_url = tw_supabase_url();
		$service_key  = tw_supabase_service_key();
		$game_data    = get_user_game_data_from_supabase( $wp_user_id );

		// active_campaign_id is a UUID — keep it as a string, never cast to (int)
		$campaign_id = ( is_array( $game_data ) && ! empty( $game_data['active_campaign_id'] ) )
			? (string) $game_data['active_campaign_id']
			: '';

		if ( '' === $campaign_id ) {
			return '';
		}

		$url = trailingslashit( $supabase_url ) . 'rest/v1/cyber_world_state?campaign_id=eq.' . rawurlencode( $campaign_id );

		// Server-side read: use service key so RLS does not block the request
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'apikey'        => $service_key,
					'Authorization' => 'Bearer ' . $service_key,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body ) || ! is_array( $body ) || ! isset( $body[0] ) || ! is_array( $body[0] ) ) {
			return sprintf(
				'<div id="tw-clock-container" data-campaign-id="%s">No time data for campaign %s</div>',
				esc_attr( $campaign_id ),
				esc_html( $campaign_id )
			);
		}

		$data            = $body[0];
		$hour            = (int) ( $data['current_hour'] ?? 0 );
		$current_weather = (string) ( $data['current_weather'] ?? 'Sun' );
		$next_weather    = (string) ( $data['next_weather'] ?? 'Sun' );
		$season          = (string) ( $data['current_season'] ?? 'Spring' );

		$season_colors = array(
			'Spring' => '#adff00',
			'Summer' => '#ffcc00',
			'Autumn' => '#ff5500',
			'Winter' => '#00ffff',
		);

		$weather_icons = array(
			'Sun'    => '☀️',
			'Cloudy' => '☁️',
			'Rain'   => '🌧️',
			'Fog'    => '🌫️',
		);

		$season_color = $season_colors[ $season ] ?? '#adff00';
		$weather_icon = $weather_icons[ $current_weather ] ?? '☀️';
		$next_icon    = $weather_icons[ $next_weather ] ?? '☀️';

		if ( function_exists( 'tw_enqueue_time_wheel_assets' ) ) {
			tw_enqueue_time_wheel_assets(
				array(
					'supabaseUrl' => $supabase_url,
					'anonKey'     => function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '',
					'campaignId'  => $campaign_id,
					'initialHour' => $hour,
					'season'      => $season,
					'seasonColor' => $season_color,
					'weather'     => $current_weather,
					'nextWeather' => $next_weather,
					'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
					'nonce'       => wp_create_nonce( 'tw_time_wheel' ),
				)
			);
		}

		ob_start();
		?>
		<div id="tw-clock-container" data-campaign-id="<?php echo esc_attr( $campaign_id ); ?>">
			<div class="tw-clock-wrapper" style="--season-color: <?php echo esc_attr( $season_color ); ?>;">
				<div class="tw-pointer">▼</div>

				<div
					class="tw-main-disk"
					id="tw-time-disk"
					data-hour="<?php echo esc_attr( (string) $hour ); ?>"
				></div>

				<div class="tw-center-hub">
					<span
						id="tw-weather-icon"
						class="tw-weather-icon"
						aria-label="<?php echo esc_attr( $current_weather ); ?>"
					><?php echo esc_html( $weather_icon ); ?></span>

					<span id="tw-weather-label" class="tw-weather-label">
						<?php echo esc_html( strtoupper( $current_weather ) ); ?>
					</span>
				</div>

				<div class="tw-forecast-bubble">
					<span class="tw-forecast-label">Next</span>
					<span
						id="tw-next-weather"
						class="tw-next-weather"
						aria-label="<?php echo esc_attr( $next_weather ); ?>"
					><?php echo esc_html( $next_icon ); ?></span>
				</div>

				<div class="tw-season-tag" id="tw-season-name">
					<?php echo esc_html( $season ); ?>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	add_shortcode( 'tw_time_wheel', 'neoweaver_render_time_wheel' );
}
