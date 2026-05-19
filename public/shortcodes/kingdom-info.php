<?php
function id_scripts() {
	$file_rel  = 'assets/css/public/kingdom-info.css';
	$file_path = trailingslashit( NEOWEAVER_PLUGIN_DIR ) . $file_rel;
	$file_url  = trailingslashit( NEOWEAVER_PLUGIN_URL ) . $file_rel;
	$version   = file_exists( $file_path ) ? (string) filemtime( $file_path ) : '1.0.0';

	wp_enqueue_style(
		'nw-kingdom-info',
		$file_url,
		array( 'nw-font-chakra-petch' ),
		$version
	);
}
add_action( 'wp_enqueue_scripts', 'id_scripts' );

add_shortcode( 'kingdom_info', function() {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return 'Zaloguj się.';
	}

	// Pobierz dane Supabase z wp-config
	$supabase_url = 'https://' . TW_SUPABASE_PROJECT_ID . '.supabase.co';
$supabase_key = TW_SUPABASE_ANON_KEY;

	if ( ! $supabase_url || ! $supabase_key ) {
		return '<p style="color:#f00;">Brak konfiguracji Supabase.</p>';
	}

	// Wywołanie widoku przez REST API Supabase
	$endpoint = trailingslashit( $supabase_url ) . 'rest/v1/rpc/get_kingdom_by_wp_user';

	$response = wp_remote_post( $endpoint, array(
		'headers' => array(
			'apikey'        => $supabase_key,
			'Authorization' => 'Bearer ' . $supabase_key,
			'Content-Type'  => 'application/json',
		),
		'body'    => wp_json_encode( array( 'p_wp_user_id' => $user_id ) ),
		'timeout' => 10,
	) );

	if ( is_wp_error( $response ) ) {
		return '<p style="color:#f00;">Błąd połączenia z bazą danych.</p>';
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( empty( $body ) || ! is_array( $body ) ) {
		return "<div class='kingdom-card' style='--status-color:#555; padding:20px; text-align:center;'><em style='color:#888;'>📡 Sygnał utracony: Brak danych o domenie...</em></div>";
	}

	$data = (object) $body[0];

	if ( ! $data ) {
		return "<div class='kingdom-card' style='--status-color:#555; padding:20px; text-align:center;'><em style='color:#888;'>📡 Sygnał utracony: Brak danych o domenie...</em></div>";
	}

	switch ( strtolower( (string) $data->government_type ) ) {
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

	$climate = strtoupper( (string) $data->political_climate );
	$color   = ( 'STABLE' === $climate ) ? '#00f2ff' : ( ( 'UNSTABLE' === $climate ) ? '#ff0055' : '#ffaa00' );
	$stability_percent = max( 0, min( 100, (float) $data->stability_score ) );

	ob_start();
	?>
	<div class="kingdom-card" style="--status-color: <?php echo esc_attr( $color ); ?>;">
		<div style="padding:20px; position:relative; z-index:2;">
			<div style="display:flex; align-items:center; gap:10px; margin-bottom:15px;">
				<span style="font-size:1.5rem; text-shadow:0 0 10px <?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $icon ); ?></span>
				<h3 style="margin:0 !important;"><?php echo esc_html( $data->kingdom_name ); ?></h3>
			</div>

			<div class="kingdom-grid">
				<div class="stat-item">
					<span class="stat-label">Government</span>
					<span class="stat-value"><?php echo esc_html( $data->government_type ); ?></span>
				</div>
				<div class="stat-item">
					<span class="stat-label">Climate</span>
					<span class="stat-value" style="color:<?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $data->political_climate ); ?></span>
				</div>
				<div class="stat-item" style="grid-column: span 2;">
					<span class="stat-label">Stability Index</span>
					<div class="stability-bar-container">
						<div class="stability-bar-fill" style="width: <?php echo esc_attr( $stability_percent ); ?>%;"></div>
					</div>
					<span class="stat-value" style="font-size:0.7rem; opacity:0.8;"><?php echo esc_html( $data->stability_score ); ?>% Cohesion</span>
				</div>
				<div class="stat-item">
					<span class="stat-label">Population</span>
					<span class="stat-value"><?php echo esc_html( $data->population_alive ); ?> souls</span>
				</div>
				<div class="stat-item">
					<span class="stat-label">Domain Size</span>
					<span class="stat-value"><?php echo esc_html( $data->territory_size ); ?> sectors</span>
				</div>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
} );
