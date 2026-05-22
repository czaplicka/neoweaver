<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_render_kingdom_info_shortcode' ) ) {
	function tw_render_kingdom_info_shortcode(): string {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return 'Log in Operator.';
		}

		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
			return '<p style="color:#f00;">Supabase error.</p>';
		}

		if ( function_exists( 'tw_enqueue_kingdom_info_assets' ) ) {
			tw_enqueue_kingdom_info_assets();
		}

		$endpoint = trailingslashit( tw_supabase_url() ) . 'rest/v1/rpc/get_kingdom_by_wp_user';

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'apikey'        => tw_supabase_anon_key(),
					'Authorization' => 'Bearer ' . tw_supabase_anon_key(),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'p_wp_user_id' => $user_id,
					)
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return '<p style="color:#f00;">Error connecting with base.</p>';
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '<p style="color:#f00;">Domain error.</p>';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body ) || ! is_array( $body ) || empty( $body[0] ) || ! is_array( $body[0] ) ) {
			return "<div class='kingdom-card' style='--status-color:#555; padding:20px; text-align:center;'><em style='color:#888;'>📡 No signal:  No signal about domain...</em></div>";
		}

		$data = (object) $body[0];

		switch ( strtolower( (string) ( $data->government_type ?? '' ) ) ) {
			case 'monarchy':
				$icon = '👑';
				break;
			case 'technocracy':
				$icon = '⚙️';
				break;
			case 'theocracy':
				$icon = '👁️';
				break;
			case 'republic':
				$icon = '🏛️';
				break;
			case 'anarchy':
				$icon = '💀';
				break;
			case 'corporatocracy':
				$icon = '🏙️';
				break;
			default:
				$icon = '🚩';
				break;
		}

		$climate           = strtoupper( (string) ( $data->political_climate ?? '' ) );
		$color             = 'STABLE' === $climate ? '#00f2ff' : ( 'UNSTABLE' === $climate ? '#ff0055' : '#ffaa00' );
		$stability_percent = max( 0, min( 100, (float) ( $data->stability_score ?? 0 ) ) );

		$kingdom_name      = (string) ( $data->kingdom_name ?? 'Unknown Domain' );
		$government_type   = (string) ( $data->government_type ?? 'Unknown' );
		$political_climate = (string) ( $data->political_climate ?? 'Unknown' );
		$stability_score   = (string) ( $data->stability_score ?? '0' );
		$population_alive  = (string) ( $data->population_alive ?? '0' );
		$territory_size    = (string) ( $data->territory_size ?? '0' );

		ob_start();
		?>
		<div class="kingdom-card" style="--status-color: <?php echo esc_attr( $color ); ?>;">
			<div style="padding:20px; position:relative; z-index:2;">
				<div style="display:flex; align-items:center; gap:10px; margin-bottom:15px;">
					<span style="font-size:1.5rem; text-shadow:0 0 10px <?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $icon ); ?></span>
					<h3 style="margin:0 !important;"><?php echo esc_html( $kingdom_name ); ?></h3>
				</div>

				<div class="kingdom-grid">
					<div class="stat-item">
						<span class="stat-label">Government</span>
						<span class="stat-value"><?php echo esc_html( $government_type ); ?></span>
					</div>

					<div class="stat-item">
						<span class="stat-label">Climate</span>
						<span class="stat-value" style="color:<?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $political_climate ); ?></span>
					</div>

					<div class="stat-item" style="grid-column: span 2;">
						<span class="stat-label">Stability Index</span>
						<div class="stability-bar-container">
							<div class="stability-bar-fill" style="width: <?php echo esc_attr( (string) $stability_percent ); ?>%;"></div>
						</div>
						<span class="stat-value" style="font-size:0.7rem; opacity:0.8;"><?php echo esc_html( $stability_score ); ?>% Cohesion</span>
					</div>

					<div class="stat-item">
						<span class="stat-label">Population</span>
						<span class="stat-value"><?php echo esc_html( $population_alive ); ?> souls</span>
					</div>

					<div class="stat-item">
						<span class="stat-label">Domain Size</span>
						<span class="stat-value"><?php echo esc_html( $territory_size ); ?> sectors</span>
					</div>
				</div>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}

	add_shortcode( 'kingdom_info', 'tw_render_kingdom_info_shortcode' );
}
