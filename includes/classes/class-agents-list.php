<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Neoweaver_Agents_List {

	private Neoweaver_Agents_Repository $repo;

	public function __construct( Neoweaver_Agents_Repository $repo ) {
		$this->repo = $repo;
	}

	public function render_roster( int $wp_user_id ): string {
		$supabase_url = function_exists( 'tw_supabase_url' ) ? tw_supabase_url() : '';
		$anon_key     = function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '';

		if ( empty( $supabase_url ) || empty( $anon_key ) ) {
			return '<p>System Error: Supabase configuration missing.</p>';
		}

		$characters = $this->repo->get_for_wp_user( $wp_user_id );

		if ( empty( $characters ) ) {
			$new_agent_url = esc_url( apply_filters( 'neoweaver_new_agent_url', home_url( '/new-agent/' ) ) );
			return '
			<div class="tw-agents-empty">
				<div class="tw-agents-empty-icon">⚠️</div>
				<p class="tw-agents-empty-main">NO OPERATIVES DETECTED IN YOUR GRID.</p>
				<small class="tw-agents-empty-sub">Initialize a new Field Agent to start the weaving process.</small>
				<div class="tw-agents-empty-actions">
					<a href="' . $new_agent_url . '" class="tw-btn-sync">NEW FIELD AGENT</a>
				</div>
			</div>';
		}

		$default_avatar = trailingslashit( NEOWEAVER_PLUGIN_URL ) . 'assets/images/Avatar.svg';

		$registry_entries = [];
		foreach ( $characters as $char ) {
			$cid = (string) ( $char['id'] ?? '' );
			if ( $cid !== '' ) {
				$registry_entries[] = wp_json_encode( $cid ) . ':' . wp_json_encode( $char );
			}
		}
		$registry_js = 'window.nwCharRegistry = window.nwCharRegistry || {};'
			. 'Object.assign(window.nwCharRegistry,{' . implode( ',', $registry_entries ) . '});';

		ob_start();
		// Emit registry before the card grid so twOpenModal() can read it immediately.
		echo '<script>' . $registry_js . '</script>';
		?>
		<div class="tw-grid">
			<?php foreach ( $characters as $char ) : ?>
				<?php
				$avatar       = ! empty( $char['avatar'] ) ? $char['avatar'] : $default_avatar;
				$camp_data    = $char['cyber_campaign_characters'][0]['cyber_campaign'] ?? null;
				$camp_name    = $camp_data['name'] ?? 'Unassigned';
				$world_name   = $camp_data['cyber_campaign_worlds'][0]['cyber_worlds']['name'] ?? 'Unknown World';
				$is_public    = ! empty( $char['is_public'] );
				$views        = isset( $char['view_count'] ) ? (int) $char['view_count'] : 0;
				$char_id      = (string) ( $char['id'] ?? '' );
				$char_id_attr = esc_attr( $char_id );
				$legend_url   = add_query_arg( 'char_id', rawurlencode( $char_id ), home_url( '/legend/' ) );
				?>
				<div class="tw-card">
					<div class="tw-top-meta" data-public-meta="<?php echo $char_id_attr; ?>">
						<span class="tw-views-count" style="<?php echo $is_public ? '' : 'display:none;'; ?>">
							Views: <span class="tw-views-number"><?php echo esc_html( $views ); ?></span>
						</span>

						<?php if ( $is_public ) : ?>
							<a
								href="<?php echo esc_url( $legend_url ); ?>"
								class="tw-legend-link"
								target="_blank"
								rel="noopener noreferrer"
							>
								Agent public profile
							</a>
						<?php else : ?>
							<a
								href="<?php echo esc_url( $legend_url ); ?>"
								class="tw-legend-link"
								target="_blank"
								rel="noopener noreferrer"
								data-legend-url="<?php echo esc_url( $legend_url ); ?>"
								style="display:none;"
							>
								Agent public profile
							</a>
							<span class="tw-not-public">Not public</span>
						<?php endif; ?>
					</div>

					<div class="tw-card-header">
						<img src="<?php echo esc_url( $avatar ); ?>" class="tw-avatar" alt="">

						<div>
							<div style="display:flex; align-items:center;">
								<span class="tw-lvl-badge">LVL <?php echo (int) ( $char['lvl'] ?? 1 ); ?></span>
								<h3 style="margin:0; font-size:16px;"><?php echo esc_html( $char['name'] ?? 'Unknown Agent' ); ?></h3>
							</div>

							<small style="opacity:0.7;">
								<?php echo esc_html( $char['cyber_races']['name'] ?? 'Human' ); ?> //
								<?php echo esc_html( $char['cyber_classes']['name'] ?? 'Operative' ); ?>
							</small>

							<div class="tw-campaign-info">
								📍 <?php echo esc_html( $camp_name ); ?><br>
								🌍 <?php echo esc_html( $world_name ); ?>
							</div>

							<div style="margin-top:8px; font-size:11px; color:#aaa;">
								<label class="tw-toggle-label">
									<input
										type="checkbox"
										class="tw-toggle-public"
										data-char-id="<?php echo $char_id_attr; ?>"
										data-legend-url="<?php echo esc_url( $legend_url ); ?>"
										<?php checked( $is_public ); ?>
									>
									<span>Public on /legend</span>
								</label>
							</div>
						</div>
					</div>

					<div class="tw-card-actions">
						<button
							class="tw-btn"
							data-char-id="<?php echo $char_id_attr; ?>"
							onclick="twOpenModal(this)"
						>
							Agent Dossier
						</button>
						<button
							class="tw-btn tw-btn-danger tw-btn-delete-agent"
							data-char-id="<?php echo $char_id_attr; ?>"
						>
							Delete Operative
						</button>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<div id="twCharModal" class="tw-modal">
			<div class="tw-modal-content">
				<span class="tw-close" onclick="twCloseModal()" role="button" aria-label="Close">&times;</span>
				<div id="twModalBody"></div>
			</div>
		</div>

		<script>
		(function () {
			document.addEventListener('click', function (e) {
				var btn = e.target.closest('.tw-btn-delete-agent');
				if (!btn) { return; }
				var charId = btn.dataset.charId;
				if (typeof twConfirmDeleteCharacter === 'function') {
					twConfirmDeleteCharacter(charId, btn);
				}
			});
		}());
		</script>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Return all agents for a WP user (living + dead), with tags and inventory.
	 */
	public function get_roster( int $wp_user_id ): array {
		return $this->repo->get_for_wp_user( $wp_user_id );
	}

	/**
	 * Return living (non-STATUS_DEAD) agents for a WP user.
	 */
	public function get_selectable_agents( int $wp_user_id ): array {
		return $this->repo->get_living_for_wp_user( $wp_user_id );
	}

	/**
	 * Return all agents bound to a specific Node (world), regardless of owner.
	 */
	public function get_agents_in_node( $node_id ): array {
		if ( empty( $node_id ) ) {
			error_log( 'Neoweaver_Agents_List::get_agents_in_node — empty node_id' );
			return [];
		}
		return $this->repo->get_by_node( $node_id );
	}

	/**
	 * Return living agents belonging to a WP user that are bound to a specific Node.
	 */
	public function get_data_ghosts_for_node( $node_id, int $wp_user_id ): array {
		if ( empty( $node_id ) || empty( $wp_user_id ) ) {
			error_log( 'Neoweaver_Agents_List::get_data_ghosts_for_node — empty node_id or wp_user_id' );
			return [];
		}

		$in_node  = $this->repo->get_by_node( $node_id );
		$living   = $this->repo->get_living_for_wp_user( $wp_user_id );

		$living_ids = array_column( $living, 'id' );
		$living_set = array_flip( array_map( 'strval', $living_ids ) );

		return array_values( array_filter(
			$in_node,
			function ( $agent ) use ( $living_set ) {
				return isset( $living_set[ (string) ( $agent['id'] ?? '' ) ] );
			}
		) );
	}

	/**
	 * Render an HTML <select> of living agents for a WP user.
	 */
	public function render_agent_select( int $wp_user_id ): string {
		$agents = $this->repo->get_living_for_wp_user( $wp_user_id );

		if ( empty( $agents ) ) {
			return '<p class="tw-helper-text">No Field Agents available. <a href="' . esc_url( apply_filters( 'neoweaver_new_agent_url', home_url( '/new-agent/' ) ) ) . '" class="tw-link">Create one first &rarr;</a></p>';
		}

		$html = '<select name="character_id" id="tw-agent-select" class="tw-select">';
		$html .= '<option value="">— Select Field Agent —</option>';
		foreach ( $agents as $agent ) {
			$id    = esc_attr( (string) ( $agent['id'] ?? '' ) );
			$label = esc_html( ( $agent['name'] ?? 'Unknown Agent' ) . ' (LVL ' . (int) ( $agent['lvl'] ?? 1 ) . ')' );
			$html .= '<option value="' . $id . '">' . $label . '</option>';
		}
		$html .= '</select>';

		return $html;
	}

	/**
	 * Render a small badge showing the currently active agent for a WP user.
	 */
	public function render_active_agent_badge( int $wp_user_id ): string {
		$agent = $this->repo->get_active_for_wp_user( $wp_user_id );

		if ( ! $agent ) {
			return '';
		}

		$name  = esc_html( $agent['name'] ?? 'Unknown Agent' );
		$lvl   = (int) ( $agent['lvl'] ?? 1 );
		$race  = esc_html( $agent['cyber_races']['name'] ?? '' );
		$class = esc_html( $agent['cyber_classes']['name'] ?? '' );
		$id    = esc_attr( (string) ( $agent['id'] ?? '' ) );

		return '<span class="tw-active-agent-badge" data-char-id="' . $id . '">' .
				'<span class="tw-lvl-badge">LVL ' . $lvl . '</span> ' .
				$name .
				( $race || $class ? ' <small>' . implode( ' // ', array_filter( [ $race, $class ] ) ) . '</small>' : '' ) .
				'</span>';
	}

	/**
	 * Return a minimal array payload for each living agent, ready for JS / REST responses.
	 */
	public function to_api_payload( int $wp_user_id ): array {
		$agents  = $this->repo->get_living_for_wp_user( $wp_user_id );
		$default = trailingslashit( NEOWEAVER_PLUGIN_URL ) . 'assets/images/Avatar.svg';
		$out     = [];

		foreach ( $agents as $agent ) {
			$out[] = [
				'id'       => $agent['id'] ?? '',
				'name'     => $agent['name'] ?? 'Unknown Agent',
				'lvl'      => (int) ( $agent['lvl'] ?? 1 ),
				'race'     => $agent['cyber_races']['name'] ?? '',
				'class'    => $agent['cyber_classes']['name'] ?? '',
				'avatar'   => ! empty( $agent['avatar'] ) ? $agent['avatar'] : $default,
				'world_id' => $agent['world_id'] ?? '',
			];
		}

		return $out;
	}
}
