<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * SHORTCODE: [tw_list_worlds]
 */

if ( ! function_exists( 'tw_list_worlds_v14' ) ) {
	function tw_list_worlds_v14(): string {

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return '<p class="tw-error">TERMINAL ERROR: No User Sync Detected.</p>';
		}

		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_service_key' ) ) {
			return '<p class="tw-error">API Config missing.</p>';
		}

		if ( function_exists( 'tw_enqueue_list_worlds_assets' ) ) {
			tw_enqueue_list_worlds_assets(
				array(
					'deleteConfirm'   => 'This will erase the world from the grid (and all linked data via cascade). Proceed?',
					'supabaseOffline' => 'SUPABASE CLIENT OFFLINE. CANNOT ERASE WORLD.',
					'deleteFailed'    => 'Deletion failed:',
					'deleteException' => 'Deletion failed: client exception.',
					'erasingLabel'    => 'ERASING…',
					'erasedLabel'     => 'NODE ERASED',
					'refreshDelayMs'  => 20000,
					'reloadDelayMs'   => 700,
				)
			);
		}

		$url_base    = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
		$service_key = tw_supabase_service_key();

		$headers = array(
			'apikey'        => $service_key,
			'Authorization' => 'Bearer ' . $service_key,
		);

		$supa_get = static function ( string $endpoint, array $params, int $timeout = 15 ) use ( $url_base, $headers ): array {
			$url      = add_query_arg( $params, $url_base . $endpoint );
			$response = wp_remote_get(
				$url,
				array(
					'headers' => $headers,
					'timeout' => $timeout,
				)
			);

			if ( is_wp_error( $response ) ) {
				return array();
			}

			if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return array();
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			return is_array( $data ) ? $data : array();
		};

		$status           = isset( $_GET['status'] ) && is_scalar( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$world_id_param   = isset( $_GET['world_id'] ) && is_scalar( $_GET['world_id'] ) ? sanitize_text_field( wp_unslash( $_GET['world_id'] ) ) : '';
		$show_init_banner = ( 'initializing' === $status && '' !== $world_id_param );

		$m_map = array( 1 => 'Void-Dry', 2 => 'Flickering', 3 => 'Resonant', 4 => 'Overloaded', 5 => 'Source-Leaking' );
		$t_map = array( 1 => 'Primitive', 2 => 'Stable', 3 => 'High-Tech', 4 => 'Transhuman', 5 => 'Singularity' );
		$v_map = array( 1 => 'Chaos', 2 => 'Neutral', 3 => 'Lawful' );
		$w_map = array( 1 => 'Destitute', 2 => 'Struggling', 3 => 'Stable', 4 => 'Prosperous', 5 => 'Post-Scarcity' );
		$s_map = array( 1 => 'Local', 2 => 'Regional', 3 => 'Planetary', 4 => 'Galactic', 5 => 'Infinite' );

		$worlds = $supa_get(
			'cyber_worlds',
			array(
				'wp_user_id' => 'eq.' . $user_id,
				'select'     => '*,cyber_campaign_worlds(campaign_id,cyber_campaign(name))',
				'order'      => 'created_at.desc',
			)
		);

		$no_data = empty( $worlds );

		$agents_by_campaign = array();
		$sessions_by_world  = array();
		$char_names         = array();

		if ( ! $no_data ) {
			$campaign_ids = array();
			$world_ids    = array();

			foreach ( $worlds as $w ) {
				if ( ! empty( $w['id'] ) ) {
					$world_ids[] = (string) $w['id'];
				}

				$cd_raw = $w['cyber_campaign_worlds'] ?? null;
				$cd     = null;

				if ( is_array( $cd_raw ) && ! empty( $cd_raw ) ) {
					$cd = $cd_raw[0];
				}

				if ( $cd && ! empty( $cd['campaign_id'] ) ) {
					$campaign_ids[] = (string) $cd['campaign_id'];
				}
			}

			$campaign_ids = array_values( array_unique( $campaign_ids ) );
			$world_ids    = array_values( array_unique( $world_ids ) );

			$camp_char_rows = array();

			if ( ! empty( $campaign_ids ) ) {
				$camp_char_rows = $supa_get(
					'cyber_campaign_characters',
					array(
						'campaign_id' => 'in.(' . implode( ',', $campaign_ids ) . ')',
						'wp_user_id'  => 'eq.' . $user_id,
						'select'      => 'campaign_id,character_id,cyber_characters(name)',
						'order'       => 'id.asc',
					)
				);
			}

			foreach ( $camp_char_rows as $row ) {
				if ( empty( $row['campaign_id'] ) ) {
					continue;
				}

				$cid = (string) $row['campaign_id'];

				if ( isset( $agents_by_campaign[ $cid ] ) ) {
					continue;
				}

				$char_id   = ! empty( $row['character_id'] ) ? (string) $row['character_id'] : '';
				$char_name = null;

				if ( isset( $row['cyber_characters'] ) && is_array( $row['cyber_characters'] ) ) {
					$char_name = $row['cyber_characters']['name'] ?? null;
				}

				$agents_by_campaign[ $cid ] = array(
					'character_id' => $char_id,
					'char_name'    => $char_name,
				);
			}

			if ( ! empty( $world_ids ) ) {
				$session_rows = $supa_get(
					'cyber_game_sessions',
					array(
						'wp_user_id' => 'eq.' . $user_id,
						'world_id'   => 'in.(' . implode( ',', $world_ids ) . ')',
						'select'     => 'world_id,character_id,status',
						'order'      => 'created_at.desc',
					)
				);

				foreach ( $session_rows as $row ) {
					if ( empty( $row['world_id'] ) ) {
						continue;
					}

					$wid = (string) $row['world_id'];

					if ( isset( $sessions_by_world[ $wid ] ) ) {
						continue;
					}

					$sessions_by_world[ $wid ] = array(
						'character_id' => ! empty( $row['character_id'] ) ? (string) $row['character_id'] : '',
						'status'       => $row['status'] ?? null,
					);
				}
			}

			$char_ids_needed = array();

			foreach ( $agents_by_campaign as $agent ) {
				if ( ! empty( $agent['character_id'] ) && null === $agent['char_name'] ) {
					$char_ids_needed[] = (string) $agent['character_id'];
				}
			}

			foreach ( $sessions_by_world as $sess ) {
				if ( ! empty( $sess['character_id'] ) ) {
					$char_ids_needed[] = (string) $sess['character_id'];
				}
			}

			$char_ids_needed = array_values( array_unique( $char_ids_needed ) );

			if ( ! empty( $char_ids_needed ) ) {
				$char_rows = $supa_get(
					'cyber_characters',
					array(
						'id'     => 'in.(' . implode( ',', $char_ids_needed ) . ')',
						'select' => 'id,name',
					)
				);

				foreach ( $char_rows as $row ) {
					if ( empty( $row['id'] ) ) {
						continue;
					}

					$char_names[ (string) $row['id'] ] = $row['name'];
				}
			}
		}

		ob_start();
		?>
		<div class="tw-terminal-interface">

			<?php if ( $show_init_banner ) : ?>
				<div class="tw-world-init-wrap" data-tw-init-banner="1">
					<div class="tw-world-init-card">
						<div class="tw-world-init-ring tw-world-init-ring-outer"></div>
						<div class="tw-world-init-ring tw-world-init-ring-inner"></div>
						<div class="tw-world-init-core">
							<div class="tw-world-init-title">SYSTEM: WORLD ARCHITECT // STATUS: INITIALIZING</div>
							<div class="tw-world-init-text">
								New Simulation #<?php echo esc_html( $world_id_param ); ?> is booting in the background.<br>
								This may take up to ~20 seconds. The Archives will auto-refresh.
							</div>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $no_data ) : ?>
				<div class="tw-no-worlds">
					<div class="tw-alert-icon">⚠️</div>
					<p>NO REALITIES DETECTED IN YOUR GRID.</p>
					<small>Create a new Node to begin the weaving process.</small>
				</div>
			<?php else : ?>
				<div class="tw-world-grid">
					<?php foreach ( $worlds as $w ) : ?>
						<?php
						$world_id = ! empty( $w['id'] ) ? (string) $w['id'] : '';

						$cd_raw        = $w['cyber_campaign_worlds'] ?? null;
						$campaign_data = null;

						if ( is_array( $cd_raw ) && ! empty( $cd_raw ) ) {
							$campaign_data = $cd_raw[0];
						}

						$active_campaign_id = ( $campaign_data && ! empty( $campaign_data['campaign_id'] ) )
							? (string) $campaign_data['campaign_id']
							: '';

						$active_campaign_name = 'UNBOUND REALITY';

						if (
							$campaign_data &&
							isset( $campaign_data['cyber_campaign'] ) &&
							is_array( $campaign_data['cyber_campaign'] ) &&
							isset( $campaign_data['cyber_campaign']['name'] )
						) {
							$active_campaign_name = (string) $campaign_data['cyber_campaign']['name'];
						}

						$field_agent_name = 'NO AGENT';
						$world_status     = 'NOT DEPLOYED';
						$has_agent        = false;

						if ( $active_campaign_id ) {
							$world_status = 'WAITING';

							if ( ! empty( $agents_by_campaign[ $active_campaign_id ] ) ) {
								$agent   = $agents_by_campaign[ $active_campaign_id ];
								$char_id = $agent['character_id'];

								if ( $char_id ) {
									$field_agent_name = $agent['char_name'] ?? ( $char_names[ $char_id ] ?? 'AGENT #' . $char_id );
									$world_status     = 'READY';
									$has_agent        = true;
								}
							}

							if ( $world_id && ! empty( $sessions_by_world[ $world_id ] ) ) {
								$sess         = $sessions_by_world[ $world_id ];
								$sess_char_id = $sess['character_id'];
								$sess_status  = $sess['status'];

								if ( $sess_char_id ) {
									$field_agent_name = $char_names[ $sess_char_id ] ?? 'AGENT #' . $sess_char_id;
									$has_agent        = true;
								}

								if ( ! empty( $sess_status ) ) {
									$world_status = strtoupper( (string) $sess_status );
								}
							}
						}

						$p1        = trim( (string) ( $w['world_overview_p1'] ?? '' ) );
						$p2        = trim( (string) ( $w['world_overview_p2'] ?? '' ) );
						$p3        = trim( (string) ( $w['world_overview_p3'] ?? '' ) );
						$full_desc = ( $p1 || $p2 || $p3 )
							? trim( $p1 . ' ' . $p2 . ' ' . $p3 )
							: trim( (string) ( $w['world_ai_description'] ?? $w['description'] ?? '' ) );

						$short_desc_source = $p1 ?: $full_desc;
						$excerpt           = $short_desc_source ? wp_trim_words( $short_desc_source, 18 ) : 'LORE DATA ENCRYPTED — ACCESS RESTRICTED.';

						$modal_payload = array(
							'name'         => (string) ( $w['name'] ?? '' ),
							'campaign'     => $active_campaign_name,
							'desc'         => $full_desc,
							'magic'        => $m_map[ (int) ( $w['magic'] ?? 0 ) ] ?? (string) ( $w['magic'] ?? '' ),
							'tech'         => $t_map[ (int) ( $w['technology'] ?? 0 ) ] ?? (string) ( $w['technology'] ?? '' ),
							'vibe'         => $v_map[ (int) ( $w['moral'] ?? 0 ) ] ?? (string) ( $w['moral'] ?? '' ),
							'wealth'       => $w_map[ (int) ( $w['wealth'] ?? 0 ) ] ?? (string) ( $w['wealth'] ?? '' ),
							'size'         => $s_map[ (int) ( $w['size'] ?? 0 ) ] ?? (string) ( $w['size'] ?? '' ),
							'diff'         => (int) ( $w['difficulty'] ?? 0 ),
							'gods'         => (string) ( $w['gods'] ?? 'Unknown / None' ),
							'relations'    => (string) ( $w['relations'] ?? 'No data on world conflict.' ),
							'tag1'         => (string) ( $w['global_tag_1'] ?? '' ),
							'tag2'         => (string) ( $w['global_tag_2'] ?? '' ),
							'tag3'         => (string) ( $w['global_tag_3'] ?? '' ),
							'conf_title'   => (string) ( $w['conflict_title'] ?? '' ),
							'conf_summary' => (string) ( $w['conflict_summary'] ?? '' ),
							'conf_side_1'  => (string) ( $w['conflict_race_1_name'] ?? '' ),
							'conf_side_2'  => (string) ( $w['conflict_race_2_name'] ?? '' ),
						);
						?>
						<div
							class="tw-world-card"
							id="tw-world-card-<?php echo esc_attr( $world_id ); ?>"
							data-world-card="1"
							data-world-id="<?php echo esc_attr( $world_id ); ?>"
							data-world-modal="<?php echo esc_attr( wp_json_encode( $modal_payload ) ); ?>"
						>
							<div class="tw-card-glow"></div>

							<div class="tw-card-content">
								<div class="tw-card-top">
									<span class="tw-status-tag <?php echo $active_campaign_id ? 'status-online' : 'status-idle'; ?>">
										<?php echo $active_campaign_id ? '• MULTIPLAYER SYNC' : '• STANDBY'; ?>
									</span>
									<span class="tw-id-tag">
										#<?php echo esc_html( substr( $world_id, 0, 8 ) ); ?>
										&nbsp;|&nbsp;LVL <?php echo esc_html( (string) ( (int) ( $w['difficulty'] ?? 0 ) ) ); ?>
									</span>
								</div>

								<h3 class="tw-world-title"><?php echo esc_html( (string) ( $w['name'] ?? '' ) ); ?></h3>

								<div class="tw-campaign-link">
									<span class="label">HOST SECTOR:</span>
									<span class="value"><?php echo esc_html( $active_campaign_name ); ?></span>
								</div>

								<div class="tw-agent-line">
									<span class="label">FIELD AGENT:</span>
									<span class="value"><?php echo esc_html( $field_agent_name ); ?></span>
								</div>

								<div class="tw-status-line">
									<span class="label">STATUS:</span>
									<span class="value"><?php echo esc_html( $world_status ); ?></span>
								</div>

								<p class="tw-world-excerpt"><?php echo esc_html( $excerpt ); ?></p>

								<div class="tw-card-footer">
									<?php if ( $active_campaign_id ) : ?>
										<?php if ( $has_agent ) : ?>
											<button
												type="button"
												class="tw-btn-sync"
												data-world-action="enter"
												data-world-id="<?php echo esc_attr( $world_id ); ?>"
											>
												ENTER THE NODE
											</button>
										<?php else : ?>
											<button
												type="button"
												class="tw-btn-setup"
												data-world-action="assign-agent"
												data-world-id="<?php echo esc_attr( $world_id ); ?>"
												data-campaign-id="<?php echo esc_attr( $active_campaign_id ); ?>"
											>
												ASSIGN FIELD AGENT
											</button>
										<?php endif; ?>
									<?php else : ?>
										<button
											type="button"
											class="tw-btn-setup"
											data-world-action="bind-campaign"
											data-world-id="<?php echo esc_attr( $world_id ); ?>"
										>
											BIND CAMPAIGN
										</button>
									<?php endif; ?>

									<button
										type="button"
										class="tw-btn-delete"
										data-world-action="delete"
										data-world-id="<?php echo esc_attr( $world_id ); ?>"
									>
										ERASE WORLD
									</button>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

		</div>

		<div id="tw-world-pop" class="tw-modal-overlay" data-world-modal-overlay="1" hidden>
			<div class="tw-modal-box" data-world-modal-box="1">
				<div class="tw-modal-head">
					<div>
						<h2 id="m-name" class="tw-modal-title"></h2>
						<div class="tw-modal-campaign-line">
							ACTIVE CAMPAIGN:
							<span id="m-campaign" class="tw-modal-campaign-value"></span>
						</div>
					</div>
					<button type="button" class="tw-modal-close" data-world-modal-close="1" aria-label="Close modal">&times;</button>
				</div>

				<div class="tw-modal-grid-stats">
					<div class="m-stat-item"><strong>MAGIC:</strong> <span id="m-magic"></span></div>
					<div class="m-stat-item"><strong>TECH:</strong> <span id="m-tech"></span></div>
					<div class="m-stat-item"><strong>VIBE:</strong> <span id="m-vibe"></span></div>
					<div class="m-stat-item"><strong>WEALTH:</strong> <span id="m-wealth"></span></div>
					<div class="m-stat-item"><strong>SIZE:</strong> <span id="m-size"></span></div>
					<div class="m-stat-item"><strong>DANGER:</strong> <span id="m-diff"></span>/5</div>
				</div>

				<div class="tw-modal-conflict">
					<div class="conflict-tags">
						<strong>GLOBAL TAGS:</strong>
						<span id="m-tag1"></span>
						<span id="m-tag2"></span>
						<span id="m-tag3"></span>
					</div>

					<div class="conflict-main">
						<h4 id="m-conf-title"></h4>
						<p id="m-conf-summary"></p>
						<p id="m-conf-sides" class="tw-conf-sides"></p>
					</div>
				</div>

				<div class="tw-modal-lore">
					<div class="lore-section">
						<h4>GODS &amp; BELIEFS</h4>
						<p id="m-gods"></p>
					</div>
					<div class="lore-section">
						<h4>FACTIONS &amp; RELATIONS</h4>
						<p id="m-relations"></p>
					</div>
				</div>

				<div class="tw-modal-body-title">ENCRYPTED LORE DATA</div>
				<div id="m-desc" class="tw-modal-body"></div>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}
}

add_shortcode( 'tw_list_worlds', 'tw_list_worlds_v14' );
