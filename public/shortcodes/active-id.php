<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'render_active_id_shortcode' ) ) {
	function render_active_id_shortcode( $atts = array() ) {
		$file_rel  = 'assets/css/public/active-id.css';
		$file_path = trailingslashit( NEOWEAVER_PLUGIN_DIR ) . $file_rel;
		$file_url  = trailingslashit( NEOWEAVER_PLUGIN_URL ) . $file_rel;
		$version   = file_exists( $file_path ) ? (string) filemtime( $file_path ) : '1.0.0';

		wp_enqueue_style(
			'nw-active-id',
			$file_url,
			array( 'nw-font-chakra-petch' ),
			$version
		);

		if ( ! is_user_logged_in() ) {
			return 'AUTH_REQUIRED';
		}

		if ( ! defined( 'SUPABASE_URL' ) || ! defined( 'SUPABASE_API_KEY' ) ) {
			return 'CONFIG_MISSING';
		}

		$supabase_url = untrailingslashit( SUPABASE_URL );
		$supabase_key = SUPABASE_API_KEY;
		$user_id      = get_current_user_id();

		$character_uuid = get_user_meta( $user_id, 'active_character_id', true );
		if ( ! $character_uuid ) {
			return 'NO_AGENT_CONNECTED';
		}

		$url = $supabase_url . '/rest/v1/cyber_characters?' . http_build_query(
			array(
				'id'     => 'eq.' . $character_uuid,
				'select' => 'world_credentials,current_location_id,cyber_locations(area_id,cyber_areas(kingdom_id,cyber_kingdoms(name)))',
			)
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

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data ) || ! is_array( $data ) ) {
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
		<div id="neoweave-active-id" class="id-chit-terminal" data-kingdom="<?php echo esc_attr( $kingdom_id ); ?>">
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
		return ob_get_clean();
	}
}

add_shortcode( 'active_id', 'render_active_id_shortcode' );
