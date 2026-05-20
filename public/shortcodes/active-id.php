<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'render_active_id_shortcode' ) ) {
	function render_active_id_shortcode( $atts = array() ) {
		if ( ! is_user_logged_in() ) {
			return 'AUTH_REQUIRED';
		}

		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
			return 'CONFIG_MISSING';
		}

		$supabase_url = tw_supabase_url();
		$supabase_key = tw_supabase_anon_key();
		$user_id      = get_current_user_id();

		if ( empty( $supabase_url ) || empty( $supabase_key ) ) {
			return 'CONFIG_MISSING';
		}

		$character_uuid = get_user_meta( $user_id, 'active_character_id', true );
		if ( ! $character_uuid ) {
			return 'NO_AGENT_CONNECTED';
		}

		$url = add_query_arg(
			array(
				'id'     => 'eq.' . $character_uuid,
				'select' => 'world_credentials,current_location_id,cyber_locations(area_id,cyber_areas(kingdom_id,cyber_kingdoms(name)))',
			),
			trailingslashit( $supabase_url ) . 'rest/v1/cyber_characters'
		);

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'apikey'        => $supabase_key,
					'Authorization' => 'Bearer ' . $supabase_key,
					'Content-Type'  => 'application/json',
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return 'CONNECTION_ERROR';
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return 'AGENT_NOT_FOUND';
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data ) || ! is_array( $data ) || empty( $data[0] ) ) {
			return 'AGENT_NOT_FOUND';
		}

		$char_data = $data[0];

		$location = $char_data['cyber_locations'] ?? null;
		$area     = is_array( $location ) ? ( $location['cyber_areas'] ?? null ) : null;
		$kingdom  = is_array( $area ) ? ( $area['cyber_kingdoms'] ?? null ) : null;

		$kingdom_id   = is_array( $area ) ? ( $area['kingdom_id'] ?? null ) : null;
		$kingdom_name = is_array( $kingdom ) && ! empty( $kingdom['name'] ) ? $kingdom['name'] : 'UNKNOWN_NODE';

		$credentials = $char_data['world_credentials'] ?? array();
		$credentials = is_array( $credentials ) ? $credentials : array();

		$status = 'CITIZEN';
		if ( $kingdom_id && isset( $credentials[ $kingdom_id ] ) ) {
			$status = (string) $credentials[ $kingdom_id ];
		}

		ob_start();
		?>
		<div id="neoweave-active-id" class="id-chit-terminal" data-kingdom="<?php echo esc_attr( (string) $kingdom_id ); ?>">
			<div class="terminal-header">SCANNING_IDENTITY...</div>
			<div class="id-grid">
				<div class="id-row">
					<span class="id-label">NODE:</span>
					<span class="id-value"><?php echo esc_html( strtoupper( $kingdom_name ) ); ?></span>
				</div>
				<div class="id-row">
					<span class="id-label">STATUS:</span>
					<span class="id-value status-<?php echo esc_attr( strtolower( $status ) ); ?>">
						<?php echo esc_html( strtoupper( $status ) ); ?>
					</span>
				</div>
			</div>
			<div class="id-flicker"></div>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}

add_shortcode( 'active_id', 'render_active_id_shortcode' );
