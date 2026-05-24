<?php
/**
 * NeoWeaver Admin Panel — Achievements (cyber_achievements)
 *
 * Columns: id, name, description, condition_type, condition_value,
 *          reward_xp, reward_items (jsonb), icon_url, is_active,
 *          created_at.
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'NeoWeaver_Achievements_Admin', false ) ) {
	return;
}

class NeoWeaver_Achievements_Admin extends NW_Base_Admin {

	private string $table     = 'cyber_achievements';
	private string $page_slug = 'neo-weaver-achievements';

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_submenu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'   ] );
		add_action( 'wp_ajax_nw_achievements_get_all',   [ $this, 'ajax_get'    ] );
		add_action( 'wp_ajax_nw_achievements_save',      [ $this, 'ajax_save'   ] );
		add_action( 'wp_ajax_nw_achievements_delete',    [ $this, 'ajax_delete' ] );
		add_action( 'wp_ajax_nw_achievements_toggle',    [ $this, 'ajax_toggle' ] );
	}

	public function register_submenu(): void {
		add_submenu_page(
			'neo-weaver',
			'NeoWeaver — Achievements',
			'🏆 Achievements',
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, $this->page_slug ) ) {
			return;
		}
		wp_enqueue_style(
			'nw-admin-core',
			NEOWEAVER_PLUGIN_URL . 'assets/css/admin/admin-core.css',
			[ 'nw-font-chakra-petch' ],
			(string) filemtime( NEOWEAVER_PLUGIN_DIR . 'assets/css/admin/admin-core.css' )
		);
		wp_enqueue_style(
			'nw-achievements-admin',
			NEOWEAVER_PLUGIN_URL . 'assets/css/admin/achievements.css',
			[ 'nw-admin-core' ],
			(string) filemtime( NEOWEAVER_PLUGIN_DIR . 'assets/css/admin/achievements.css' )
		);
		wp_enqueue_script(
			'nw-achievements-admin',
			NEOWEAVER_PLUGIN_URL . 'assets/js/admin/achievements.js',
			[ 'jquery' ],
			(string) filemtime( NEOWEAVER_PLUGIN_DIR . 'assets/js/admin/achievements.js' ),
			true
		);
		wp_localize_script(
			'nw-achievements-admin',
			'NWAchievements',
			[
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'nw_achievements' ),
			]
		);
	}
	/* ---------------------------------------------------------------- */
	/*  HELPERS                                                          */
	/* ---------------------------------------------------------------- */

	private function is_uuid( string $v ): bool {
		return (bool) preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$v
		);
	}

	private function normalize_tags( $raw ): array {
		if ( is_array( $raw ) ) {
			return array_values( array_filter( array_map( 'sanitize_text_field', $raw ) ) );
		}
		$raw = trim( (string) wp_unslash( $raw ) );
		if ( '' === $raw ) {
			return [];
		}
		$dec = json_decode( $raw, true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $dec ) ) {
			return array_values( array_filter( array_map( 'sanitize_text_field', $dec ) ) );
		}
		return array_values(
			array_filter(
				array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', $raw ) ) )
			)
		);
	}

	/* ---------------------------------------------------------------- */
	/*  AJAX                                                             */
	/* ---------------------------------------------------------------- */

	public function ajax_get(): void {
		check_ajax_referer( 'nw_achievements', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$res = $this->supa( 'GET', $this->table . '?select=*&order=created_at.desc' );
		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Fetch failed.' );
			return;
		}
		wp_send_json_success( $res['data'] ?? [] );
	}

	public function ajax_save(): void {
		check_ajax_referer( 'nw_achievements', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id          = sanitize_text_field( wp_unslash( $_POST['id']               ?? '' ) );
		$name        = sanitize_text_field( wp_unslash( $_POST['name']             ?? '' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['description']  ?? '' ) );
		$cond_type   = sanitize_text_field( wp_unslash( $_POST['condition_type']   ?? '' ) );
		$cond_value  = sanitize_text_field( wp_unslash( $_POST['condition_value']  ?? '' ) );
		$reward_xp   = intval( wp_unslash( $_POST['reward_xp']                     ?? 0  ) );
		$icon_url    = esc_url_raw( wp_unslash( $_POST['icon_url']                 ?? '' ) );
		$is_active   = (bool) intval( wp_unslash( $_POST['is_active']              ?? 1  ) );

		$reward_items_raw = wp_unslash( $_POST['reward_items'] ?? '[]' );
		$reward_items     = json_decode( (string) $reward_items_raw, true );
		if ( ! is_array( $reward_items ) ) {
			$reward_items = [];
		}

		if ( empty( $name ) ) {
			wp_send_json_error( 'Name is required.' );
			return;
		}

		$payload = [
			'name'            => $name,
			'description'     => $description ?: null,
			'condition_type'  => $cond_type   ?: null,
			'condition_value' => $cond_value  ?: null,
			'reward_xp'       => max( 0, $reward_xp ),
			'reward_items'    => $reward_items,
			'icon_url'        => $icon_url    ?: null,
			'is_active'       => $is_active,
		];

		if ( $id ) {
			if ( ! $this->is_uuid( $id ) ) {
				wp_send_json_error( 'Invalid UUID.' );
				return;
			}
			$res = $this->supa( 'PATCH', $this->table . '?id=eq.' . rawurlencode( $id ), $payload );
			if ( ! $res['ok'] ) {
				wp_send_json_error( $res['error'] ?? 'Update failed.' );
				return;
			}
			wp_send_json_success( [ 'action' => 'updated', 'id' => $id ] );
			return;
		}

		$res = $this->supa( 'POST', $this->table, $payload );
		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Insert failed.' );
			return;
		}
		$created = $res['data'][0] ?? $res['data'] ?? [];
		wp_send_json_success( [ 'action' => 'created', 'id' => $created['id'] ?? null ] );
	}

	public function ajax_delete(): void {
		check_ajax_referer( 'nw_achievements', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		if ( ! $id || ! $this->is_uuid( $id ) ) {
			wp_send_json_error( 'Invalid ID.' );
			return;
		}

		$res = $this->supa( 'DELETE', $this->table . '?id=eq.' . rawurlencode( $id ) );
		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Delete failed.' );
			return;
		}
		wp_send_json_success( [ 'deleted' => $id ] );
	}

	public function ajax_toggle(): void {
		check_ajax_referer( 'nw_achievements', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id        = sanitize_text_field( wp_unslash( $_POST['id']        ?? '' ) );
		$is_active = (bool) intval( wp_unslash( $_POST['is_active']       ?? 0  ) );

		if ( ! $id || ! $this->is_uuid( $id ) ) {
			wp_send_json_error( 'Invalid ID.' );
			return;
		}

		$res = $this->supa( 'PATCH', $this->table . '?id=eq.' . rawurlencode( $id ), [ 'is_active' => $is_active ] );
		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Toggle failed.' );
			return;
		}
		wp_send_json_success( [ 'id' => $id, 'is_active' => $is_active ] );
	}

	/* ---------------------------------------------------------------- */
	/*  RENDER                                                           */
	/* ---------------------------------------------------------------- */

	public function render_page(): void { ?>
	<div class="wrap nw-panel" id="nw-achievements-panel">
		<div class="nw-panel-header">
			<h1 class="nw-panel-title">
				<span class="nw-accent">Neo</span>Weaver
				<span class="nw-panel-subtitle">/ Achievements</span>
			</h1>
			<div class="nw-header-actions">
				<input type="text" id="nw-search" class="nw-search-input" placeholder="Search name or condition…" />
				<select id="nw-filter-active" class="nw-select-filter">
					<option value="">All statuses</option>
					<option value="1">Active</option>
					<option value="0">Inactive</option>
				</select>
				<button id="nw-add-achievement" class="button button-primary nw-btn-add">
					<i data-lucide="plus"></i> Add Achievement
				</button>
			</div>
		</div>

		<div id="nw-achievements-list" class="nw-items-grid"></div>

		<div id="nw-achievement-modal" class="nw-modal" style="display:none;">
			<div class="nw-modal-backdrop"></div>
			<div class="nw-modal-box">
				<div class="nw-modal-header">
					<h2 class="nw-modal-title" id="nw-modal-title">Add Achievement</h2>
					<button class="nw-modal-close" id="nw-modal-close"><i data-lucide="x"></i></button>
				</div>
				<form id="nw-achievement-form" class="nw-form">
					<input type="hidden" id="achievement-id" name="id" />
					<div class="nw-form-row">
						<label for="ach-name">Name *</label>
						<input type="text" id="ach-name" name="name" required />
					</div>
					<div class="nw-form-row">
						<label for="ach-description">Description</label>
						<textarea id="ach-description" name="description" rows="3"></textarea>
					</div>
					<div class="nw-form-row nw-form-row--half">
						<div>
							<label for="ach-cond-type">Condition Type</label>
							<input type="text" id="ach-cond-type" name="condition_type" placeholder="e.g. kills, quests_done" />
						</div>
						<div>
							<label for="ach-cond-value">Condition Value</label>
							<input type="text" id="ach-cond-value" name="condition_value" placeholder="e.g. 10" />
						</div>
					</div>
					<div class="nw-form-row nw-form-row--half">
						<div>
							<label for="ach-reward-xp">Reward XP</label>
							<input type="number" id="ach-reward-xp" name="reward_xp" min="0" value="0" />
						</div>
						<div>
							<label for="ach-icon">Icon URL</label>
							<input type="url" id="ach-icon" name="icon_url" />
						</div>
					</div>
					<div class="nw-form-row">
						<label for="ach-reward-items">Reward Items (JSON array)</label>
						<textarea id="ach-reward-items" name="reward_items" rows="2" placeholder='[{"item_id":"uuid","qty":1}]'></textarea>
					</div>
					<div class="nw-form-row nw-form-row--checkboxes">
						<label><input type="checkbox" name="is_active" value="1" id="ach-is-active" checked /> Active</label>
					</div>
					<div class="nw-form-actions">
						<button type="submit" class="button button-primary">Save</button>
						<button type="button" class="button nw-modal-cancel">Cancel</button>
					</div>
				</form>
			</div>
		</div>
	</div>
	<?php }
}
