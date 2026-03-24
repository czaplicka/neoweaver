<?php
/**
 * SHORTCODE: [tw_list_worlds]
 */
function tw_list_worlds_v14() {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return '<p class="tw-error">TERMINAL ERROR: No User Sync Detected.</p>';
	}

	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
		return '<p class="tw-error">API Config missing.</p>';
	}

	$url_base = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
	$anon_key = tw_supabase_anon_key();
	$headers  = [
		'apikey'        => $anon_key,
		'Authorization' => 'Bearer ' . $anon_key,
	];

	/**
	 * Tiny helper: GET a Supabase REST endpoint, return decoded array or [].
	 *
	 * @param string $endpoint  Path after rest/v1/ (no leading slash).
	 * @param array  $params    Query-string parameters.
	 * @param int    $timeout   wp_remote_get timeout in seconds.
	 * @return array
	 */
	$supa_get = function ( string $endpoint, array $params, int $timeout = 15 ) use ( $url_base, $headers ): array {
		$url      = add_query_arg( $params, $url_base . $endpoint );
		$response = wp_remote_get( $url, [ 'headers' => $headers, 'timeout' => $timeout ] );
		if ( is_wp_error( $response ) ) {
			return [];
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : [];
	};

	// --- STATUS INITIALIZING (po stworzeniu świata) ---
	$status         = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
	$world_id_param = isset( $_GET['world_id'] ) ? sanitize_text_field( $_GET['world_id'] ) : '';
	$init_banner    = '';

	if ( 'initializing' === $status && ! empty( $world_id_param ) ) {
		$init_banner = '
		<div class="tw-world-init-wrap">
			<div class="tw-world-init-card">
				<div class="tw-world-init-ring tw-world-init-ring-outer"></div>
				<div class="tw-world-init-ring tw-world-init-ring-inner"></div>
				<div class="tw-world-init-core">
					<div class="tw-world-init-title">SYSTEM: WORLD ARCHITECT // STATUS: INITIALIZING</div>
					<div class="tw-world-init-text">
						New Simulation #' . esc_html( $world_id_param ) . ' is booting in the background.<br>
						This may take up to ~20 seconds. The Archives will auto-refresh.
					</div>
				</div>
			</div>
		</div>
		<script>
		document.addEventListener("DOMContentLoaded", function() {
			setTimeout(function() {
				const url = new URL(window.location.href);
				url.searchParams.delete("status");
				url.searchParams.delete("world_id");
				window.location.href = url.toString();
			}, 20000);
		});
		</script>';
	}

	// --- VALUE MAPS ---
	$m_map = [ 1 => 'Void-Dry', 2 => 'Flickering', 3 => 'Resonant', 4 => 'Overloaded', 5 => 'Source-Leaking' ];
	$t_map = [ 1 => 'Primitive', 2 => 'Stable', 3 => 'High-Tech', 4 => 'Transhuman', 5 => 'Singularity' ];
	$v_map = [ 1 => 'Chaos', 2 => 'Neutral', 3 => 'Lawful' ];
	$w_map = [ 1 => 'Destitute', 2 => 'Struggling', 3 => 'Stable', 4 => 'Prosperous', 5 => 'Post-Scarcity' ];
	$s_map = [ 1 => 'Local', 2 => 'Regional', 3 => 'Planetary', 4 => 'Galactic', 5 => 'Infinite' ];

	// ==========================================================
	// Q1: worlds (with embedded campaign join)
	// ==========================================================
	$worlds = $supa_get(
		'cyber_worlds',
		[
			'wp_user_id' => 'eq.' . $user_id,
			'select'     => '*,cyber_campaign_worlds(campaign_id, cyber_campaign(name))',
			'order'      => 'id.desc',
		]
	);

	$no_data      = empty( $worlds );
	$delete_nonce = wp_create_nonce( 'tw_world_delete_nonce' );

	// lookup tables
	$agents_by_campaign = [];
	$sessions_by_world  = [];
	$char_names         = [];

	if ( ! $no_data ) {
		// ----------------------------------------------------------
		// Collect IDs needed for the two batch queries.
		// ----------------------------------------------------------
		$campaign_ids = [];
		$world_ids    = [];

		foreach ( $worlds as $w ) {
			if ( ! empty( $w['id'] ) ) {
				$world_ids[] = (string) $w['id']; // uuid-safe
			}
			$cd = $w['cyber_campaign_worlds'][0] ?? null;
			if ( $cd && ! empty( $cd['campaign_id'] ) ) {
				$campaign_ids[] = (string) $cd['campaign_id']; // uuid-safe
			}
		}
		$campaign_ids = array_values( array_unique( $campaign_ids ) );
		$world_ids    = array_values( array_unique( $world_ids ) );

		// ----------------------------------------------------------
		// Q2: ONE query for all campaign-characters across all worlds.
		// Keyed: $agents_by_campaign[ campaign_id(uuid) ] = [ character_id(uuid), char_name ]
		// ----------------------------------------------------------
		if ( ! empty( $campaign_ids ) ) {
			$camp_char_rows = $supa_get(
				'cyber_campaign_characters',
				[
					'campaign_id'   => 'in.(' . implode( ',', $campaign_ids ) . ')',
					'creator_wp_id' => 'eq.' . $user_id,
					'select'        => 'campaign_id,character_id,cyber_characters(name)',
					'order'         => 'id.asc',
				]
			);

			foreach ( $camp_char_rows as $row ) {
				if ( empty( $row['campaign_id'] ) ) {
					continue;
				}
				$cid = (string) $row['campaign_id'];

				// Keep only the first (oldest) entry per campaign.
				if ( isset( $agents_by_campaign[ $cid ] ) ) {
					continue;
				}

				$char_id   = ! empty( $row['character_id'] ) ? (string) $row['character_id'] : '';
				$char_name = $row['cyber_characters']['name'] ?? null;

				$agents_by_campaign[ $cid ] = [
					'character_id' => $char_id,
					'char_name'    => $char_name,
				];
			}
		}

		// ----------------------------------------------------------
		// Q3: ONE query for latest sessions across all worlds.
		// Keyed: $sessions_by_world[ world_id(uuid) ] = [ character_id(uuid), status ]
		// ----------------------------------------------------------
		if ( ! empty( $world_ids ) ) {
			$session_rows = $supa_get(
				'cyber_game_sessions',
				[
					'wp_user_id' => 'eq.' . $user_id,
					'world_id'   => 'in.(' . implode( ',', $world_ids ) . ')',
					'select'     => 'world_id,character_id,status',
					'order'      => 'created_at.desc',
				]
			);

			foreach ( $session_rows as $row ) {
				if ( empty( $row['world_id'] ) ) {
					continue;
				}
				$wid = (string) $row['world_id'];

				if ( isset( $sessions_by_world[ $wid ] ) ) {
					continue;
				}

				$sessions_by_world[ $wid ] = [
					'character_id' => ! empty( $row['character_id'] ) ? (string) $row['character_id'] : '',
					'status'       => $row['status'] ?? null,
				];
			}
		}

		// ----------------------------------------------------------
		// Q4: ONE query for all character names that were NOT already
		// resolved via the embedded join in Q2.
		// ----------------------------------------------------------
		$char_ids_needed = [];

		foreach ( $agents_by_campaign as $agent ) {
			// Only needed when the embedded join returned null (FK not configured).
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
				[
					'id'     => 'in.(' . implode( ',', $char_ids_needed ) . ')',
					'select' => 'id,name',
				]
			);

			foreach ( $char_rows as $row ) {
				if ( empty( $row['id'] ) ) {
					continue;
				}
				$char_id = (string) $row['id'];
				$char_names[ $char_id ] = $row['name'];
			}
		}
	} // end if ! $no_data

	ob_start();
	?>
	<div class="tw-terminal-interface">

		<?php
		if ( $init_banner ) {
			echo $init_banner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>

		<?php if ( $no_data ) : ?>
			<div class="tw-no-worlds">
				<div class="tw-alert-icon">⚠️</div>
				<p>NO REALITIES DETECTED IN YOUR GRID.</p>
				<small>Create a new Node to begin the weaving process.</small>
			</div>
		<?php else : ?>
			<div class="tw-world-grid">
				<?php foreach ( $worlds as $w ) :

					$world_id = ! empty( $w['id'] ) ? (string) $w['id'] : '';

					// Campaign data (embedded in Q1)
					$campaign_data        = $w['cyber_campaign_worlds'][0] ?? null;
					$active_campaign_id   = $campaign_data && ! empty( $campaign_data['campaign_id'] ) ? (string) $campaign_data['campaign_id'] : '';
					$active_campaign_name = ( $campaign_data && isset( $campaign_data['cyber_campaign']['name'] ) )
						? $campaign_data['cyber_campaign']['name']
						: 'UNBOUND REALITY';

					$field_agent_name = 'NO AGENT';
					$world_status     = 'NOT DEPLOYED';

					if ( $active_campaign_id ) {
						$world_status = 'WAITING';

						// ---- Resolve agent from Q2 lookup (O(1), no HTTP) ----
						if ( ! empty( $agents_by_campaign[ $active_campaign_id ] ) ) {
							$agent   = $agents_by_campaign[ $active_campaign_id ];
							$char_id = $agent['character_id'];

							if ( $char_id ) {
								$field_agent_name = $agent['char_name']
									?? ( $char_names[ $char_id ] ?? 'AGENT #' . $char_id );
								$world_status = 'READY';
							}
						}

						// ---- Resolve session from Q3 lookup (O(1), no HTTP) ----
						if ( $world_id && ! empty( $sessions_by_world[ $world_id ] ) ) {
							$sess         = $sessions_by_world[ $world_id ];
							$sess_char_id = $sess['character_id'];
							$sess_status  = $sess['status'];

							if ( $sess_char_id ) {
								$field_agent_name = $char_names[ $sess_char_id ] ?? 'AGENT #' . $sess_char_id;
							}
							// Session status always wins over campaign-agent status.
							if ( ! empty( $sess_status ) ) {
								$world_status = strtoupper( $sess_status );
							}
						}
					}

					// AI World Soul – description
					$p1 = trim( $w['world_overview_p1'] ?? '' );
					$p2 = trim( $w['world_overview_p2'] ?? '' );
					$p3 = trim( $w['world_overview_p3'] ?? '' );

					if ( $p1 || $p2 || $p3 ) {
						$full_desc = trim( $p1 . ' ' . $p2 . ' ' . $p3 );
					} else {
						$full_desc = trim( $w['world_ai_description'] ?? $w['description'] ?? '' );
					}

					$short_desc_source = $p1 ?: $full_desc;

					$excerpt = $short_desc_source
						? wp_trim_words( $short_desc_source, 18 )
						: 'LORE DATA ENCRYPTED — ACCESS RESTRICTED.';

					$modal_payload = [
						'name'         => $w['name'],
						'campaign'     => $active_campaign_name,
						'desc'         => $full_desc,
						'magic'        => $m_map[ (int) $w['magic'] ]       ?? $w['magic'],
						'tech'         => $t_map[ (int) $w['technology'] ]  ?? $w['technology'],
						'vibe'         => $v_map[ (int) $w['moral'] ]       ?? $w['moral'],
						'wealth'       => $w_map[ (int) $w['wealth'] ]      ?? $w['wealth'],
						'size'         => $s_map[ (int) $w['size'] ]        ?? $w['size'],
						'diff'         => (int) $w['difficulty'],
						'gods'         => $w['gods'] ?? 'Unknown / None',
						'relations'    => $w['relations'] ?? 'No data on world conflict.',
						'tag1'         => $w['global_tag_1'] ?? '',
						'tag2'         => $w['global_tag_2'] ?? '',
						'tag3'         => $w['global_tag_3'] ?? '',
						'conf_title'   => $w['conflict_title'] ?? '',
						'conf_summary' => $w['conflict_summary'] ?? '',
						'conf_side_1'  => $w['conflict_race_1_name'] ?? '',
						'conf_side_2'  => $w['conflict_race_2_name'] ?? '',
					};
					?>
					<div class="tw-world-card"
						 id="tw-world-card-<?php echo esc_attr( $world_id ); ?>"
						 onclick='openWorldModal(<?php echo esc_attr( wp_json_encode( $modal_payload ) ); ?>)'>
						<div class="tw-card-glow"></div>
						<div class="tw-card-content">
							<div class="tw-card-top">
								<span class="tw-status-tag <?php echo $active_campaign_id ? 'status-online' : 'status-idle'; ?>">
									<?php echo $active_campaign_id ? '• MULTIPLAYER SYNC' : '• STANDBY'; ?>
								</span>
								<span class="tw-id-tag">
									#<?php echo esc_html( substr( $world_id, 0, 8 ) ); ?>
									&nbsp;|&nbsp;LVL <?php echo (int) $w['difficulty']; ?>
								</span>
							</div>

							<h3 class="tw-world-title"><?php echo esc_html( $w['name'] ); ?></h3>

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
									<button class="tw-btn-sync"
											onclick="event.stopPropagation(); window.location.href='/game/?world_id=<?php echo esc_attr( $world_id ); ?>'">
										ENTER SPLOT
									</button>
								<?php else : ?>
									<button class="tw-btn-setup"
											onclick="event.stopPropagation(); window.location.href='#connector'">
										BIND CAMPAIGN
									</button>
								<?php endif; ?>

								<button class="tw-btn-delete"
										onclick="event.stopPropagation(); twDeleteWorld('<?php echo esc_attr( $world_id ); ?>');">
									ERASE WORLD
								</button>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

	</div>

	<!-- MODAL -->
	<div id="tw-world-pop" class="tw-modal-overlay" onclick="closeWorldModal()">
		<div class="tw-modal-box" onclick="event.stopPropagation()">
			<div class="tw-modal-head">
				<div>
					<h2 id="m-name" style="color:#adff00; margin:0; text-transform:uppercase;"></h2>
					<div style="font-size:0.7rem; color:#00e5ff; margin-top:5px;">
						ACTIVE CAMPAIGN:
						<span id="m-campaign" style="font-weight:bold; letter-spacing:1px;"></span>
					</div>
				</div>
				<span class="tw-modal-close" onclick="closeWorldModal()">&times;</span>
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
					<h4><i class="dashicons dashicons-rest-api"></i> GODS & BELIEFS</h4>
					<p id="m-gods"></p>
				</div>
				<div class="lore-section">
					<h4><i class="dashicons dashicons-groups"></i> FACTIONS & RELATIONS</h4>
					<p id="m-relations"></p>
				</div>
			</div>

			<div class="tw-modal-body-title">ENCRYPTED LORE DATA</div>
			<div id="m-desc" class="tw-modal-body"></div>
		</div>
	</div>

	<style>
		.tw-terminal-interface { padding: 20px; min-height: 400px; font-family: 'Chakra Petch', sans-serif; color: #fff; }
		.tw-world-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 25px; }
		.tw-world-init-wrap { margin-bottom: 25px; display: flex; justify-content: center; }
		.tw-world-init-card { position: relative; width: 100%; max-width: 520px; padding: 20px; background: radial-gradient(circle at top, rgba(173,255,0,0.08), rgba(0,0,0,0.85)); border: 1px solid rgba(173,255,0,0.5); border-radius: 8px; overflow: hidden; }
		.tw-world-init-ring { position: absolute; border-radius: 50%; border: 2px solid rgba(173,255,0,0.25); box-shadow: 0 0 25px rgba(173,255,0,0.3); animation: tw-world-spin 3s linear infinite; pointer-events: none; }
		.tw-world-init-ring-outer { width: 180px; height: 180px; top: -40px; right: -40px; border-top-color: #adff00; border-bottom-color: transparent; }
		.tw-world-init-ring-inner { width: 110px; height: 110px; top: 0; right: 0; border-top-color: #00e5ff; border-left-color: transparent; animation-duration: 2s; animation-direction: reverse; }
		.tw-world-init-core { position: relative; z-index: 2; }
		.tw-world-init-title { font-size: 0.8rem; color: #adff00; letter-spacing: 0.15em; text-transform: uppercase; margin-bottom: 6px; }
		.tw-world-init-text { font-size: 0.8rem; color: #ccffcc; }
		@keyframes tw-world-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
		.tw-world-card { position: relative; background: #0a0a0a; border: 1px solid #1a1a1a; border-radius: 8px; overflow: hidden; transition: 0.4s cubic-bezier(0.2, 1, 0.3, 1); cursor: pointer; min-height: 220px; }
		.tw-world-card:hover { border-color: #adff00; transform: translateY(-5px); }
		.tw-card-glow { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: radial-gradient(circle at top right, rgba(173,255,0,0.05), transparent); pointer-events: none; }
		.tw-card-content { padding: 20px; display: flex; flex-direction: column; height: 100%; }
		.tw-card-top { display: flex; justify-content: space-between; margin-bottom: 15px; }
		.tw-status-tag { font-size: 10px; font-weight: bold; letter-spacing: 1px; }
		.status-online { color: #adff00; text-shadow: 0 0 5px #adff00; }
		.status-idle  { color: #555; }
		.tw-id-tag { color: #333; font-size: 10px; font-family: monospace; }
		.tw-world-title { color: #fff; margin: 0 0 10px 0; font-size: 1.4rem; text-transform: uppercase; }
		.tw-campaign-link, .tw-agent-line, .tw-status-line { font-size: 11px; margin-bottom: 4px; }
		.tw-campaign-link .label, .tw-agent-line .label, .tw-status-line .label { color: #00e5ff; opacity: 0.6; margin-right: 5px; }
		.tw-campaign-link .value { color: #00e5ff; font-weight: bold; }
		.tw-agent-line .label, .tw-status-line .label { color: #adff00; }
		.tw-agent-line .value, .tw-status-line .value { color: #fff; font-weight: 600; }
		.tw-world-excerpt { color: #888; font-size: 13px; line-height: 1.5; flex-grow: 1; }
		.tw-card-footer { margin-top: 15px; display: flex; gap: 8px; flex-wrap: wrap; }
		.tw-btn-sync { background: #adff00; color: #000; border: none; padding: 10px; flex: 1 1 55%; font-weight: 900; border-radius: 4px; cursor: pointer; text-transform: uppercase; transition: 0.2s; font-size: 11px; }
		.tw-btn-sync:hover { background: #fff; box-shadow: 0 0 15px #adff00; }
		.tw-btn-setup { background: transparent; border: 1px dashed #444; color: #888; flex: 1 1 55%; padding: 10px; font-size: 11px; border-radius: 4px; cursor: pointer; text-transform: uppercase; }
		.tw-btn-setup:hover { border-color: #00e5ff; color: #00e5ff; }
		.tw-btn-delete { background: transparent; border: 1px solid #441111; color: #ff6666; flex: 1 1 40%; padding: 8px; font-size: 10px; border-radius: 4px; cursor: pointer; text-transform: uppercase; letter-spacing: 1px; }
		.tw-btn-delete:hover { border-color: #ff0000; color: #ff0000; }
		.tw-no-worlds { text-align: center; padding: 100px 0; border: 1px dashed #222; border-radius: 10px; }
		.tw-alert-icon { font-size: 40px; margin-bottom: 20px; opacity: 0.3; }
		.tw-modal-overlay { display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.95); backdrop-filter: blur(8px); }
		.tw-modal-box { background: #0d1117; margin: 40px auto; padding: 30px; border: 1px solid #adff00; width: 90%; max-width: 850px; border-radius: 8px; max-height: 90vh; overflow-y: auto; box-shadow: 0 0 50px rgba(0,0,0,1); }
		.tw-modal-head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #222; padding-bottom: 15px; margin-bottom: 20px; }
		.tw-modal-grid-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; background: rgba(173,255,0,0.03); padding: 15px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #222; }
		.m-stat-item { font-size: 0.8rem; }
		.m-stat-item strong { color: #adff00; margin-right: 5px; font-size: 0.7rem; opacity: 0.8; }
		.tw-modal-conflict { margin: 0 0 25px 0; padding: 12px 15px; border-radius: 5px; border: 1px solid #333; background: rgba(0,0,0,0.35); }
		.conflict-tags { font-size: 0.75rem; margin-bottom: 8px; color: #adff00; }
		.conflict-tags span { display: inline-block; margin-right: 6px; font-family: 'Chakra Petch', sans-serif; font-size: 0.7rem; opacity: 0.9; }
		.conflict-main h4 { font-size: 0.9rem; margin: 4px 0; color: #00e5ff; text-transform: uppercase; letter-spacing: 0.08em; }
		.tw-conf-sides { font-size: 0.8rem; color: #ccc; margin: 4px 0 0 0; }
		.tw-modal-lore { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 25px; }
		.lore-section h4 { font-size: 0.75rem; color: #00e5ff; margin: 0 0 10px 0; letter-spacing: 1px; text-transform: uppercase; border-bottom: 1px solid #333; padding-bottom: 5px; }
		.lore-section p { font-size: 0.85rem; color: #bbb; margin: 0; line-height: 1.5; }
		.tw-modal-body-title { font-size: 0.7rem; color: #adff00; margin-bottom: 8px; font-weight: bold; letter-spacing: 2px; }
		.tw-modal-body { line-height: 1.7; font-size: 1rem; color: #ddd; background: rgba(0,0,0,0.4); padding: 20px; border-radius: 5px; border-left: 2px solid #adff00; }
		.tw-modal-close { color: #adff00; font-size: 35px; cursor: pointer; line-height: 0.5; }
		@media (max-width: 768px) { .tw-modal-grid-stats { grid-template-columns: repeat(2, 1fr); } .tw-modal-lore { grid-template-columns: 1fr; } .tw-card-footer { flex-direction: column; } }
	</style>

	<script>
	function openWorldModal(data) {
		document.getElementById('m-name').innerText       = data.name || '';
		document.getElementById('m-campaign').innerText   = data.campaign || '';
		document.getElementById('m-desc').innerText       = data.desc || '';
		document.getElementById('m-magic').innerText      = data.magic || '';
		document.getElementById('m-tech').innerText       = data.tech || '';
		document.getElementById('m-vibe').innerText       = data.vibe || '';
		document.getElementById('m-wealth').innerText     = data.wealth || '';
		document.getElementById('m-size').innerText       = data.size || '';
		document.getElementById('m-diff').innerText       = data.diff || '';
		document.getElementById('m-gods').innerText       = data.gods || '';
		document.getElementById('m-relations').innerText  = data.relations || '';
		document.getElementById('m-tag1').innerText       = data.tag1 || '';
		document.getElementById('m-tag2').innerText       = data.tag2 || '';
		document.getElementById('m-tag3').innerText       = data.tag3 || '';
		document.getElementById('m-conf-title').innerText   = data.conf_title || '';
		document.getElementById('m-conf-summary').innerText = data.conf_summary || '';

		if (data.conf_side_1 || data.conf_side_2) {
			document.getElementById('m-conf-sides').innerText =
				(data.conf_side_1 || 'Side A') + ' vs ' + (data.conf_side_2 || 'Side B');
		} else {
			document.getElementById('m-conf-sides').innerText = '';
		}

		document.getElementById('tw-world-pop').style.display = 'block';
	}

	function closeWorldModal() {
		document.getElementById('tw-world-pop').style.display = 'none';
	}

	function twDeleteWorld(worldId) {
		if (!confirm('This will erase the world from the grid (and all linked data via cascade). Proceed?')) {
			return;
		}

		if (!window.twSupabase) {
			alert('SUPABASE CLIENT OFFLINE. CANNOT ERASE WORLD.');
			return;
		}

		const client  = window.twSupabase;
		const btnCard = document.getElementById('tw-world-card-' + worldId);
		if (btnCard) btnCard.style.opacity = '0.5';

		(async () => {
			try {
				const { data, error } = await client.rpc('fn_delete_world', { p_world_id: worldId });

				if (error) {
					console.error('SUPABASE RPC WORLD DELETE ERROR', error);
					alert('Deletion failed: ' + (error.message || 'Grid denied execution.'));
					if (btnCard) btnCard.style.opacity = '1';
					return;
				}

				if (btnCard) {
					btnCard.style.opacity      = '0.3';
					btnCard.style.pointerEvents = 'none';
				}
				setTimeout(() => window.location.reload(), 500);
			} catch (e) {
				console.error('WORLD DELETE EXCEPTION', e);
				alert('Deletion failed: client exception.');
				if (btnCard) btnCard.style.opacity = '1';
			}
		})();
	}
	</script>
	<?php
	return ob_get_clean();
}
add_shortcode( 'tw_list_worlds', 'tw_list_worlds_v14' );
