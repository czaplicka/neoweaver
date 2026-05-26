<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * NEOWEAVER - DEPLOYMENTS LIST
 * Shortcode: [tw_list_campaigns]
 */

if ( ! function_exists( 'tw_list_campaigns_final_v8_modes' ) ) {
	function tw_list_campaigns_final_v8_modes(): string {
		if ( is_admin() ) {
			return '';
		}

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return '<p class="tw-error">UPLINK REQUIRED. IDENTIFY YOURSELF, FIELD AGENT.</p>';
		}

		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'nw_supabase_service_headers' ) ) {
			return '<p class="tw-error">API Config missing.</p>';
		}

		if ( function_exists( 'tw_enqueue_list_campaigns_assets' ) ) {
			tw_enqueue_list_campaigns_assets(
				array(
					'nonce'       => wp_create_nonce( 'tw_game_nonce' ),
					'restNonce'   => wp_create_nonce( 'wp_rest' ),
					'sessionUrl'  => get_rest_url( null, 'neoweaver/v1/session/start' ),
					'terminalUrl' => home_url( '/game/' ),
					'agentsUrl'   => home_url( '/agents/?campaign_id=' ),
					'lobbyUrl'    => home_url( '/lobby/?campaign_id=' ),
				)
			);
		}

		$world_type_map = array(
			1 => array( 'label' => 'Easy',      'icon' => 'leaf',        'color' => '#4ade80' ),
			2 => array( 'label' => 'Casual',    'icon' => 'coffee',      'color' => '#86efac' ),
			3 => array( 'label' => 'Standard',  'icon' => 'crosshair',   'color' => '#adff00' ),
			4 => array( 'label' => 'Hardcore',  'icon' => 'skull',       'color' => '#f97316' ),
			5 => array( 'label' => 'Nightmare', 'icon' => 'skull',       'color' => '#ef4444' ),
		);

		$game_length_map = array(
			1 => array( 'label' => 'Short',    'icon' => 'zap' ),
			2 => array( 'label' => 'Medium',   'icon' => 'timer' ),
			3 => array( 'label' => 'Standard', 'icon' => 'radio-tower' ),
			4 => array( 'label' => 'Epic',     'icon' => 'globe' ),
			5 => array( 'label' => 'Endless',  'icon' => 'infinity' ),
		);

		$gm_style_map = array(
			'cinematic_heroic' => array( 'label' => 'Cinematic', 'icon' => 'clapperboard' ),
			'harsh_grounded'   => array( 'label' => 'Harsh',     'icon' => 'droplets' ),
			'fast_tactical'    => array( 'label' => 'Tactical',  'icon' => 'zap' ),
		);

		$priority_map = array(
			1 => array( 'label' => 'Combat',    'icon' => 'swords' ),
			2 => array( 'label' => 'Wealth',    'icon' => 'coins' ),
			3 => array( 'label' => 'Discovery', 'icon' => 'search' ),
			4 => array( 'label' => 'Relations', 'icon' => 'handshake' ),
			5 => array( 'label' => 'Mix',       'icon' => 'shuffle' ),
		);

		$url_base = trailingslashit( tw_supabase_url() ) . 'rest/v1/';

		$select = '*,cyber_campaign_worlds(world_id,cyber_worlds(name,difficulty)),cyber_campaign_characters(character_id,cyber_characters(name,cyber_races(name),cyber_classes(name)))';

		$url = add_query_arg(
			array(
				'wp_user_id' => 'eq.' . (int) $user_id,
				'select'     => $select,
				'order'      => 'created_at.desc',
			),
			$url_base . 'cyber_campaign'
		);

		$response = wp_remote_get(
			$url,
			array(
				'headers' => nw_supabase_service_headers(),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return '<p class="tw-error">CRITICAL ERROR: ' . esc_html( $response->get_error_message() ) . '</p>';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$body = wp_remote_retrieve_body( $response );
			error_log( '[NeoWeaver] Supabase HTTP ' . $code . ' URL: ' . $url . ' BODY: ' . $body );
			return '<p class="tw-error">CRITICAL ERROR: Matrix Synchronization Failed [HTTP ' . esc_html( (string) $code ) . ']. Check your Uplink.</p>';
		}

		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			error_log( '[NeoWeaver] JSON decode failed. Raw: ' . $raw );
			return '<p class="tw-error">CRITICAL ERROR: Invalid payload received from Matrix.</p>';
		}

		$active_campaigns = $decoded;

		if ( empty( $active_campaigns ) ) {
			return '
			<div class="tw-campaigns-empty">
				<div class="tw-campaigns-empty-icon">⚠️</div>
				<p class="tw-campaigns-empty-main">NO DEPLOYMENTS DETECTED IN YOUR GRID.</p>
				<small class="tw-campaigns-empty-sub">Create a new Deployment to begin the weaving process.</small>
				<div class="tw-campaigns-empty-actions">
					<a href="/new-deployment/" class="tw-btn-sync">NEW DEPLOYMENT</a>
					<a href="/new-node/" class="tw-btn-outline">NEW NODE</a>
				</div>
			</div>';
		}

		ob_start();
		?>
		<div class="tw-campaigns-wrap">
			<div class="tw-campaigns-grid">
				<?php foreach ( $active_campaigns as $c ) : ?>
					<?php
					$c_id_safe  = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) ( $c['id'] ?? '' ) );
					$c_name_raw = ! empty( $c['name'] ) ? (string) $c['name'] : 'UNNAMED_THREAD_' . $c_id_safe;
					$c_name     = esc_html( $c_name_raw );

					$cw_raw         = $c['cyber_campaign_worlds'] ?? null;
					$world_junction = null;
					if ( ! empty( $cw_raw ) ) {
						$world_junction = isset( $cw_raw[0] ) ? $cw_raw[0] : $cw_raw;
					}
					$world_rel = $world_junction ? ( $world_junction['cyber_worlds'] ?? null ) : null;
					$world_id  = $world_junction ? ( $world_junction['world_id'] ?? null ) : null;

					$cc_raw        = $c['cyber_campaign_characters'] ?? null;
					$char_junction = null;
					if ( ! empty( $cc_raw ) ) {
						$char_junction = isset( $cc_raw[0] ) ? $cc_raw[0] : $cc_raw;
					}
					$char_rel = $char_junction ? ( $char_junction['cyber_characters'] ?? null ) : null;
					$char_id  = $char_junction ? ( $char_junction['character_id'] ?? null ) : null;

					$game_mode = isset( $c['game_mode'] ) ? (int) $c['game_mode'] : 1;
					$is_team   = 2 === $game_mode;
					$mode_str  = $is_team ? 'TEAM' : 'SOLO';
					$join_code = isset( $c['join_code'] ) ? (string) $c['join_code'] : '';

					$world_type_val  = isset( $c['world_type'] ) ? (int) $c['world_type'] : 0;
					$game_length_val = isset( $c['game_length'] ) ? (int) $c['game_length'] : 0;
					$gm_style_val    = isset( $c['gm_style'] ) ? (string) $c['gm_style'] : '';
					$priority_val    = isset( $c['priority'] ) ? (int) $c['priority'] : 0;

					$length_data   = ( $game_length_val && isset( $game_length_map[ $game_length_val ] ) ) ? $game_length_map[ $game_length_val ] : null;
					$threat_data   = ( $world_type_val && isset( $world_type_map[ $world_type_val ] ) ) ? $world_type_map[ $world_type_val ] : null;
					$gm_data       = ( $gm_style_val && isset( $gm_style_map[ $gm_style_val ] ) ) ? $gm_style_map[ $gm_style_val ] : null;
					$priority_data = ( $priority_val && isset( $priority_map[ $priority_val ] ) ) ? $priority_map[ $priority_val ] : null;

					// Threat color for glow.
					$threat_color = $threat_data ? $threat_data['color'] : '#adff00';

					$operative_name  = 'PENDING ASSIGNMENT';
					$operative_class = '';
					$operative_race  = '';
					if ( is_array( $char_rel ) ) {
						$operative_race  = isset( $char_rel['cyber_races']['name'] ) ? (string) $char_rel['cyber_races']['name'] : '';
						$operative_class = isset( $char_rel['cyber_classes']['name'] ) ? (string) $char_rel['cyber_classes']['name'] : '';
						$operative_name  = isset( $char_rel['name'] ) ? (string) $char_rel['name'] : 'Unnamed';
					}

					$nodes_url  = '/nodes/?campaign_id=' . rawurlencode( $c_id_safe ) . '#tw-deployment-root';
					$agents_url = '/agents/?campaign_id=' . rawurlencode( $c_id_safe ) . '#tw-deployment-root';

					if ( ! $world_rel ) {
						$main_btn = '<a href="' . esc_url( $nodes_url ) . '" class="tw-btn">ANCHOR WORLD NODE</a>';
					} elseif ( ! $char_rel ) {
						$main_btn = '<a href="' . esc_url( $agents_url ) . '" class="tw-btn">INJECT FIELD AGENT</a>';
					} else {
						$main_btn = '<button class="tw-btn enter-matrix"'
							. ' type="button"'
							. ' data-id="' . esc_attr( $c_id_safe ) . '"'
							. ' data-character="' . esc_attr( (string) $char_id ) . '"'
							. ' data-mode="' . esc_attr( $mode_str ) . '"'
							. ' data-join="' . esc_attr( strtoupper( $join_code ) ) . '"'
							. ' data-world="' . esc_attr( (string) $world_id ) . '">'
							. 'ENTER MATRIX'
							. '</button>';
					}
					?>

					<div id="campaign-card-<?php echo esc_attr( $c_id_safe ); ?>" class="tw-campaign-card" style="--threat-color: <?php echo esc_attr( $threat_color ); ?>">
						<div class="tw-card-scan-line"></div>

						<!-- TOP ROW: mode icon + world name + length/threat badges -->
						<div class="tw-card-top">
							<div class="tw-card-mode-icon" title="<?php echo esc_attr( $mode_str ); ?>">
								<i data-lucide="<?php echo $is_team ? 'users' : 'user'; ?>" aria-label="<?php echo esc_attr( $mode_str ); ?>"></i>
							</div>
							<div class="tw-card-world-name"><?php echo $world_rel ? esc_html( (string) ( $world_rel['name'] ?? '' ) ) : '<span class="tw-card-unset">NO WORLD</span>'; ?></div>
							<div class="tw-card-top-badges">
								<?php if ( $length_data ) : ?>
									<span class="tw-pill tw-pill--length" title="Length">
										<i data-lucide="<?php echo esc_attr( $length_data['icon'] ); ?>"></i>
										<?php echo esc_html( $length_data['label'] ); ?>
									</span>
								<?php endif; ?>
								<?php if ( $threat_data ) : ?>
									<span class="tw-pill tw-pill--threat" style="color: <?php echo esc_attr( $threat_data['color'] ); ?>; border-color: <?php echo esc_attr( $threat_data['color'] ); ?>33;" title="Threat">
										<i data-lucide="<?php echo esc_attr( $threat_data['icon'] ); ?>"></i>
										<?php echo esc_html( $threat_data['label'] ); ?>
									</span>
								<?php endif; ?>
							</div>
						</div>

						<!-- CAMPAIGN NAME -->
						<h3 class="tw-card-title"><?php echo $c_name; ?></h3>

						<!-- OPERATIVE -->
						<div class="tw-card-operative">
							<i data-lucide="user-round" class="tw-card-operative-icon"></i>
							<div class="tw-card-operative-info">
								<?php if ( is_array( $char_rel ) ) : ?>
									<span class="tw-card-operative-name"><?php echo esc_html( $operative_name ); ?></span>
									<span class="tw-card-operative-meta"><?php echo esc_html( $operative_race ); ?><?php echo ( $operative_race && $operative_class ) ? ' · ' : ''; ?><?php echo esc_html( $operative_class ); ?></span>
								<?php else : ?>
									<span class="tw-card-operative-name tw-card-unset">PENDING ASSIGNMENT</span>
								<?php endif; ?>
							</div>
						</div>

						<!-- GM STYLE + PRIORITY -->
						<?php if ( $gm_data || $priority_data ) : ?>
							<div class="tw-card-tags">
								<?php if ( $gm_data ) : ?>
									<span class="tw-tag">
										<i data-lucide="<?php echo esc_attr( $gm_data['icon'] ); ?>"></i>
										<?php echo esc_html( $gm_data['label'] ); ?>
									</span>
								<?php endif; ?>
								<?php if ( $priority_data ) : ?>
									<span class="tw-tag">
										<i data-lucide="<?php echo esc_attr( $priority_data['icon'] ); ?>"></i>
										<?php echo esc_html( $priority_data['label'] ); ?>
									</span>
								<?php endif; ?>
							</div>
						<?php endif; ?>

						<!-- TEAM HASH -->
						<?php if ( $is_team && $join_code ) : ?>
							<div class="tw-campaign-hash">
								<span class="tw-campaign-hash-label">DEPLOYMENT HASH</span>
								<span class="tw-campaign-hash-code"><?php echo esc_html( strtoupper( $join_code ) ); ?></span>
							</div>
						<?php endif; ?>

						<!-- ACTIONS -->
						<div class="tw-campaign-actions">
							<?php echo $main_btn; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

							<?php if ( $is_team && $join_code ) : ?>
								<button type="button" class="tw-btn tw-btn-ghost tw-copy-join-btn" data-code="<?php echo esc_attr( strtoupper( $join_code ) ); ?>">
									COPY HASH
								</button>
							<?php endif; ?>

							<button type="button" class="tw-btn tw-btn-danger tw-delete-campaign-btn" data-id="<?php echo esc_attr( $c_id_safe ); ?>" data-name="<?php echo esc_attr( $c_name_raw ); ?>">
								TERMINATE
							</button>
						</div>

					</div><!-- .tw-campaign-card -->
				<?php endforeach; ?>
			</div>
		</div>
		<script>if(window.lucide&&typeof window.lucide.createIcons==='function'){window.lucide.createIcons();}</script>
		<?php

		return ob_get_clean();
	}
}

add_shortcode( 'tw_list_campaigns', 'tw_list_campaigns_final_v8_modes' );
