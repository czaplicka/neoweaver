<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * TALE WEAVER - FIELD AGENT COMMAND CENTER
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

		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
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

		$url_base = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
		$anon_key = tw_supabase_anon_key();

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
				'headers' => array(
					'apikey'        => $anon_key,
					'Authorization' => 'Bearer ' . $anon_key,
				),
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
		<div class="tw-char-wrapper">
			<div class="tw-char-grid">
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

					$is_active = ! empty( $c['is_active'] );
					$game_mode = isset( $c['game_mode'] ) ? (int) $c['game_mode'] : 1;
					$mode_str  = 2 === $game_mode ? 'TEAM' : 'SOLO';
					$is_team   = 2 === $game_mode;
					$join_code = isset( $c['join_code'] ) ? (string) $c['join_code'] : '';

					$operative_name = 'PENDING ASSIGNMENT';

					if ( is_array( $char_rel ) ) {
						$race  = isset( $char_rel['cyber_races']['name'] ) ? (string) $char_rel['cyber_races']['name'] : 'Unknown';
						$class = isset( $char_rel['cyber_classes']['name'] ) ? (string) $char_rel['cyber_classes']['name'] : 'Agent';
						$name  = isset( $char_rel['name'] ) ? (string) $char_rel['name'] : 'Unnamed';

						$operative_name = esc_html( $name ) . " <small style='color:#666; font-size:0.7rem;'>[" . esc_html( $race ) . ' | ' . esc_html( $class ) . "]</small>";
					}

					$nodes_url  = '/nodes/?campaign_id=' . rawurlencode( $c_id_safe ) . '#tw-deployment-root';
					$agents_url = '/agents/?campaign_id=' . rawurlencode( $c_id_safe ) . '#tw-deployment-root';

					if ( ! $world_rel ) {
						$main_btn = '<a href="' . esc_url( $nodes_url ) . '" class="tw-action-btn pulse-red">ANCHOR WORLD NODE</a>';
					} elseif ( ! $char_rel ) {
						$main_btn = '<a href="' . esc_url( $agents_url ) . '" class="tw-action-btn">INJECT FIELD AGENT</a>';
					} else {
						$main_btn = '<button class="tw-action-btn enter-matrix"'
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

					<div id="campaign-card-<?php echo esc_attr( $c_id_safe ); ?>" class="tw-char-card<?php echo ! $is_active ? ' is-inactive' : ''; ?>">
						<div class="tw-card-header">
							<div>
								<div class="tw-id-tag">UPLINK_ID: <?php echo esc_html( $c_id_safe ); ?></div>
								<h3 class="tw-campaign-title"><?php echo $c_name; ?></h3>
								<div class="tw-card-status">
									<span class="status-dot <?php echo $is_active ? 'is-active' : 'is-inactive'; ?>"></span>
									<span class="tw-status-text"><?php echo $is_active ? 'CONNECTION STABLE' : 'LINK SEVERED'; ?></span>
								</div>
							</div>
							<div class="tw-card-mode">
								<span class="tw-badge-cyber"><?php echo esc_html( $mode_str ); ?></span>
							</div>
						</div>

						<div class="tw-card-body">
							<div class="tw-data-row">
								<span class="tw-data-label">REALITY_NODE:</span>
								<span class="tw-data-value <?php echo $world_rel ? 'has-value' : 'is-missing'; ?>">
									<?php echo $world_rel ? esc_html( (string) ( $world_rel['name'] ?? '' ) ) : 'MISSING ANCHOR'; ?>
									<?php if ( ! $world_rel ) : ?>
										<a href="<?php echo esc_url( $nodes_url ); ?>" class="tw-mini-btn">LINK NODE</a>
									<?php endif; ?>
								</span>
							</div>

							<div class="tw-data-row">
								<span class="tw-data-label">OPERATIVE_LINK:</span>
								<span class="tw-data-value has-value">
									<?php echo $operative_name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<?php if ( ! $char_rel ) : ?>
										<a href="<?php echo esc_url( $agents_url ); ?>" class="tw-mini-btn">ASSIGN AGENT</a>
									<?php endif; ?>
								</span>
							</div>

							<?php if ( $is_team ) : ?>
								<div class="tw-data-row">
									<span class="tw-data-label">DEPLOYMENT HASH:</span>
									<span class="tw-data-value has-value">
										<?php echo $join_code ? esc_html( strtoupper( $join_code ) ) : 'NOT INITIALIZED'; ?>
									</span>
								</div>
							<?php endif; ?>
						</div>

						<div class="tw-card-footer">
							<div class="tw-card-footer-main"><?php echo $main_btn; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>

							<?php if ( $is_team && $join_code ) : ?>
								<button
									type="button"
									class="tw-copy-join-btn"
									data-code="<?php echo esc_attr( strtoupper( $join_code ) ); ?>"
								>
									COPY HASH
								</button>
							<?php endif; ?>

							<button
								type="button"
								class="tw-delete-campaign-btn"
								data-id="<?php echo esc_attr( $c_id_safe ); ?>"
								data-name="<?php echo esc_attr( $c_name_raw ); ?>"
							>
								TERMINATE
							</button>
						</div>

						<?php if ( ! $world_rel && $is_active ) : ?>
							<div class="world-error-overlay"></div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}
}

add_shortcode( 'tw_list_campaigns', 'tw_list_campaigns_final_v8_modes' );
