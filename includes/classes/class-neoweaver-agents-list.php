<?php
/**
 * Neoweaver_Agents_List
 *
 * Provides data-preparation and rendering logic for any UI surface that
 * lets an Operator browse, filter, and select a Field Agent to play.
 *
 * @package Neoweaver
 */
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

		ob_start();
		?>
		<?php
		$shared_styles = ob_get_clean();

		if ( empty( $characters ) ) {
			return $shared_styles . '
			<div class="tw-agents-empty">
				<div class="tw-agents-empty-icon">⚠️</div>
				<p class="tw-agents-empty-main">NO OPERATIVES DETECTED IN YOUR GRID.</p>
				<small class="tw-agents-empty-sub">Initialize a new Field Agent to start the weaving process.</small>
				<div class="tw-agents-empty-actions">
					<a href="/new-agent/" class="tw-btn-sync">NEW FIELD AGENT</a>
				</div>
			</div>';
		}

		ob_start();
		echo $shared_styles; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div class="tw-grid">
			<?php foreach ( $characters as $char ) :
				$avatar       = ! empty( $char['avatar'] ) ? $char['avatar'] : 'https://neoweaver.nieodparady.pl/wp-content/uploads/Avatar.svg';
				$camp_data    = isset( $char['cyber_campaign_characters'][0]['cyber_campaign'] ) ? $char['cyber_campaign_characters'][0]['cyber_campaign'] : null;
				$camp_name    = isset( $camp_data['name'] ) ? $camp_data['name'] : 'Unassigned';
				$world_name   = isset( $camp_data['cyber_campaign_worlds'][0]['cyber_worlds']['name'] ) ? $camp_data['cyber_campaign_worlds'][0]['cyber_worlds']['name'] : 'Unknown World';
				$is_public    = ! empty( $char['is_public'] );
				$views        = isset( $char['view_count'] ) ? (int) $char['view_count'] : 0;
				$char_id_safe = esc_attr( (string) $char['id'] );
				$legend_url   = add_query_arg( 'char_id', $char_id_safe, home_url( '/legend/' ) );
				$char_json    = wp_json_encode( $char );
			?>
				<div class="tw-card">
					<div class="tw-top-meta">
						<span>Views: <?php echo esc_html( $views ); ?></span>
						<?php if ( $is_public ) : ?>
							<a href="<?php echo esc_url( $legend_url ); ?>" target="_blank" rel="noopener noreferrer">Agent public profile</a>
						<?php else : ?>
							<span style="opacity:0.5;">Not public</span>
						<?php endif; ?>
					</div>
					<div class="tw-card-header">
						<img src="<?php echo esc_url( $avatar ); ?>" class="tw-avatar" alt="">
						<div>
							<div style="display:flex; align-items:center;">
								<span class="tw-lvl-badge">LVL <?php echo (int) $char['lvl']; ?></span>
								<h3 style="margin:0; font-size:16px;"><?php echo esc_html( $char['name'] ); ?></h3>
							</div>
							<small style="opacity:0.7;"><?php echo esc_html( $char['cyber_races']['name'] ?? 'Human' ); ?> // <?php echo esc_html( $char['cyber_classes']['name'] ?? 'Operative' ); ?></small>
							<div class="tw-campaign-info">
								📍 <?php echo esc_html( $camp_name ); ?><br>
								🌍 <?php echo esc_html( $world_name ); ?>
							</div>
							<div style="margin-top:8px; font-size:11px; color:#aaa;">
								<label class="tw-toggle-label">
									<input type="checkbox" class="tw-toggle-public" data-char-id="<?php echo $char_id_safe; ?>" <?php checked( $is_public ); ?>>
									<span>Public on /legend</span>
								</label>
							</div>
						</div>
					</div>
					<div class="tw-card-actions">
						<button class="tw-btn" data-char="<?php echo esc_attr( $char_json ); ?>" onclick="twOpenModal(this)">
							Agent Dossier
						</button>
						<button class="tw-btn tw-btn-danger" onclick='twConfirmDeleteCharacter(<?php echo wp_json_encode( (string) $char['id'] ); ?>, this)'>
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

		<?php

		return ob_get_clean();
	}

	public function get_roster( int $wp_user_id ): array { return []; }
	public function get_selectable_agents( int $wp_user_id ): array { return []; }

	/**
	 * @param string|int $node_id
	 */
	public function get_agents_in_node( $node_id ): array { return []; }

	/**
	 * @param string|int $node_id
	 */
	public function get_data_ghosts_for_node( $node_id, int $wp_user_id ): array { return []; }

	public function render_agent_select( int $wp_user_id ): string { return ''; }
	public function render_active_agent_badge( int $wp_user_id ): string { return ''; }
	public function to_api_payload( int $wp_user_id ): array { return []; }
}
