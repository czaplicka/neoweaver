<?php
/**
 * SHORTCODE: [tw_list_worlds]
 */

// Enqueue CSS/JS tylko tam, gdzie shortcode
function neoweaver_enqueue_worlds_assets() {
	// Ładuj tylko na front-endzie
	if ( is_admin() ) {
		return;
	}

	// Opcjonalnie: tylko jeśli jest shortcode na stronie
	if ( is_singular() ) {
		global $post;
		if ( empty( $post ) || false === strpos( $post->post_content, '[tw_list_worlds' ) ) {
			return;
		}
	}

	// Dostosuj w zależności od miejsca pliku shortcode (tu: /public/shortcodes/)
	$plugin_url = plugin_dir_url( dirname( __FILE__,1 ) );

	wp_enqueue_style(
		'tw-list-worlds',
		$plugin_url . 'assets/css/tw-list-worlds.css',
		[],
		'1.0.0'
	);

	wp_enqueue_script(
		'tw-list-worlds',
		$plugin_url . 'assets/js/tw-list-worlds.js',
		[ 'jquery' ],
		'1.0.0',
		true
	);
}
add_action( 'wp_enqueue_scripts', 'neoweaver_enqueue_worlds_assets' );

/**
 * SHORTCODE LOGIKA
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

			$cd_raw = $w['cyber_campaign_worlds'] ?? null;
			$cd     = null;

			// Supabase zwykle zwraca tablicę rekordów, ale zabezpieczamy się
			if ( is_array( $cd_raw ) && ! empty( $cd_raw ) ) {
				$cd = $cd_raw[0];
			}

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

				$char_name = null;
				if ( isset( $row['cyber_characters'] ) && is_array( $row['cyber_characters'] ) ) {
					$char_name = $row['cyber_characters']['name'] ?? null;
				}

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

					// Campaign data (embedded in Q1) — zabezpieczenie na PHP 8.1
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
						$campaign_data
						&& isset( $campaign_data['cyber_campaign'] )
						&& is_array( $campaign_data['cyber_campaign'] )
						&& isset( $campaign_data['cyber_campaign']['name'] )
					) {
						$active_campaign_name = $campaign_data['cyber_campaign']['name'];
					}

					$field_agent_name = 'NO AGENT';
					$world_status     = 'NOT DEPLOYED';
					$has_agent        = false;

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
								$has_agent    = true;
							}
						}

						// ---- Resolve session from Q3 lookup (O(1), no HTTP) ----
						if ( $world_id && ! empty( $sessions_by_world[ $world_id ] ) ) {
							$sess         = $sessions_by_world[ $world_id ];
							$sess_char_id = $sess['character_id'];
							$sess_status  = $sess['status'];

							if ( $sess_char_id ) {
								$field_agent_name = $char_names[ $sess_char_id ] ?? 'AGENT #' . $sess_char_id;
								$has_agent        = true;
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
					];
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
									<?php if ( $has_agent ) : ?>
										<button class="tw-btn-sync"
												onclick="event.stopPropagation(); window.location.href='/game/?world_id=<?php echo esc_attr( $world_id ); ?>'">
											ENTER THE NODE
										</button>
									<?php else : ?>
										<button class="tw-btn-setup"
												onclick="event.stopPropagation(); window.location.href='/agents/?world_id=<?php echo esc_attr( $world_id ); ?>&campaign_id=<?php echo esc_attr( $active_campaign_id ); ?>'">
											ASSIGN FIELD AGENT
										</button>
									<?php endif; ?>
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
	<?php
	return ob_get_clean();
}
add_shortcode( 'tw_list_worlds', 'tw_list_worlds_v14' );
