<?php
/**
 * NeoWeaver Admin — Races
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class NWRacesAdmin {
	private string $page_slug   = 'nw-races';
	private string $menu_parent = 'neoweaver';

	public function __construct() {
		add_action( 'admin_menu',                 [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts',      [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_nw_races_list',      [ $this, 'ajax_list' ] );
		add_action( 'wp_ajax_nw_races_save',      [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_races_delete',    [ $this, 'ajax_delete' ] );
		add_action( 'wp_ajax_nw_races_duplicate', [ $this, 'ajax_duplicate' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			$this->menu_parent,
			'Races',
			'<span data-lucide-menu="users-round"></span> Races',
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, $this->page_slug ) ) {
			return;
		}
		wp_enqueue_style( 'nw-admin-core' );
		wp_enqueue_style( 'nw-admin-races', NW_PLUGIN_URL . 'assets/css/admin/races.css', [], NW_VERSION );
		wp_enqueue_script( 'nw-lucide' );
		wp_enqueue_script( 'nw-admin-races', NW_PLUGIN_URL . 'assets/js/admin/races.js',  [ 'jquery', 'nw-lucide' ], NW_VERSION, true );
		wp_localize_script( 'nw-admin-races', 'NWRaces', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'nw_races_nonce' ),
		] );
	}

	public function render_page(): void {
		?>
		<div class="wrap nw-admin-wrap" id="nw-races-app">

			<div class="nw-admin-header">
				<h1 class="nw-admin-title">
					<i data-lucide="users-round" style="width:22px;height:22px;vertical-align:middle;margin-right:8px"></i>
					Races
				</h1>
				<div class="nw-admin-header-actions">
					<button id="nw-refresh-btn" class="nw-btn nw-btn-ghost nw-btn-sm" title="Refresh">
						<i data-lucide="refresh-cw" style="width:14px;height:14px"></i>
					</button>
					<button id="nw-add-btn" class="nw-btn nw-btn-primary">
						<i data-lucide="plus" style="width:14px;height:14px;vertical-align:middle;margin-right:4px"></i>
						New Race
					</button>
				</div>
			</div>

			<div id="nw-notice" style="display:none" class="nw-notice"></div>

			<!-- Stats bar -->
			<div class="nw-stats-bar">
				<span class="nw-stat-chip">Total: <strong id="nw-total">—</strong></span>
				<span class="nw-stat-chip nw-chip-on">Active: <strong id="nw-active">—</strong></span>
				<span class="nw-stat-chip nw-chip-off">Inactive: <strong id="nw-inactive">—</strong></span>
				<span class="nw-stat-chip">Has parent: <strong id="nw-parented">—</strong></span>
			</div>

			<!-- Filters -->
			<div class="nw-filters-bar">
				<input id="nw-search" type="search" class="nw-input nw-input-sm" placeholder="Search name / description…">
				<select id="nw-filter-active" class="nw-select nw-select-sm">
					<option value="">All statuses</option>
					<option value="1">Active</option>
					<option value="0">Inactive</option>
				</select>
				<select id="nw-filter-conflict" class="nw-select nw-select-sm">
					<option value="">All conflict axes</option>
				</select>
				<button id="nw-clear-filters" class="nw-btn nw-btn-ghost nw-btn-sm" style="display:none">
					<i data-lucide="x" style="width:12px;height:12px;vertical-align:middle;margin-right:3px"></i>
					Clear filters
				</button>
			</div>

			<!-- Table -->
			<div class="nw-table-wrap">
				<table class="nw-table">
					<thead>
						<tr>
							<th style="width:48px"></th>
							<th>Name</th>
							<th>Parent</th>
							<th style="width:56px;text-align:center">HP</th>
							<th style="width:56px;text-align:center">MP</th>
							<th>Preferences</th>
							<th>Conflict</th>
							<th style="width:56px;text-align:center">Active</th>
							<th style="width:72px"></th>
						</tr>
					</thead>
					<tbody id="nw-races-tbody">
						<tr class="nw-loading-row">
							<td colspan="9"><span class="nw-spinner"></span> Loading races…</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Modal overlay -->
			<div id="nw-modal-overlay" class="nw-modal-overlay" style="display:none">
				<div class="nw-modal">
					<div class="nw-modal-header">
						<h2 id="nw-modal-title" class="nw-modal-title">New Race</h2>
						<button id="nw-modal-close" class="nw-btn nw-btn-ghost nw-btn-sm" aria-label="Close">
							<i data-lucide="x" style="width:16px;height:16px"></i>
						</button>
					</div>

					<form id="nw-race-form" class="nw-modal-body" autocomplete="off">
						<input type="hidden" id="nw-field-id">

						<!-- Row 1: name + active -->
						<div class="nw-form-row">
							<div class="nw-form-group nw-form-group-grow">
								<label class="nw-label" for="nw-field-name">Name <span class="nw-required">*</span></label>
								<input id="nw-field-name" type="text" class="nw-input" required placeholder="e.g. Cyber-Elf">
							</div>
							<div class="nw-form-group">
								<label class="nw-label" for="nw-field-is-active">Active</label>
								<label class="nw-toggle">
									<input type="checkbox" id="nw-field-is-active" checked>
									<span class="nw-toggle-track"><span class="nw-toggle-thumb"></span></span>
								</label>
							</div>
						</div>

						<!-- Row 2: parent + conflict -->
						<div class="nw-form-row">
							<div class="nw-form-group nw-form-group-grow">
								<label class="nw-label" for="nw-field-parent-race">Parent race</label>
								<input id="nw-field-parent-race" type="text" class="nw-input" placeholder="Leave empty for top-level">
							</div>
							<div class="nw-form-group">
								<label class="nw-label" for="nw-field-conflict-axis">Conflict axis</label>
								<input id="nw-field-conflict-axis" type="text" class="nw-input" placeholder="e.g. Faction">
							</div>
							<div class="nw-form-group">
								<label class="nw-label" for="nw-field-conflict-side">Conflict side</label>
								<input id="nw-field-conflict-side" type="text" class="nw-input" placeholder="e.g. Corp">
							</div>
						</div>

						<!-- Row 3: HP + MP -->
						<div class="nw-form-row">
							<div class="nw-form-group">
								<label class="nw-label" for="nw-field-hp">Base HP</label>
								<input id="nw-field-hp" type="number" class="nw-input" min="0" max="999" value="86">
							</div>
							<div class="nw-form-group">
								<label class="nw-label" for="nw-field-mp">Base MP</label>
								<input id="nw-field-mp" type="number" class="nw-input" min="0" max="999" value="67">
							</div>
						</div>

						<!-- Description -->
						<div class="nw-form-group">
							<label class="nw-label" for="nw-field-description">Description</label>
							<textarea id="nw-field-description" class="nw-input nw-textarea" rows="3" placeholder="Short lore description…"></textarea>
						</div>

						<!-- GM instructions -->
						<div class="nw-form-group">
							<label class="nw-label" for="nw-field-gm-instructions">GM instructions</label>
							<textarea id="nw-field-gm-instructions" class="nw-input nw-textarea" rows="2" placeholder="Private notes for the Game Master…"></textarea>
						</div>

						<!-- Preferences -->
						<div class="nw-form-group">
							<label class="nw-label">Preferences <span class="nw-muted">(0–10)</span></label>
							<div class="nw-prefs-grid">
								<?php
								$prefs = [
									'preferred_tech'    => 'Tech',
									'preferred_magic'   => 'Magic',
									'preferred_gods'    => 'Gods',
									'preferred_wealth'  => 'Wealth',
									'preferred_threat'  => 'Threat',
									'preferred_moral'   => 'Moral',
									'preferred_social'  => 'Social',
								];
								foreach ( $prefs as $key => $label ) : ?>
									<div class="nw-pref-row">
										<span class="nw-pref-label"><?php echo esc_html( $label ); ?></span>
										<input id="nw-field-<?php echo esc_attr( $key ); ?>" type="number" class="nw-input nw-pref-input" min="0" max="10" value="3">
										<div class="nw-pref-track">
											<div id="nw-bar-<?php echo esc_attr( $key ); ?>" class="nw-pref-fill" style="width:30%"></div>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						</div>

						<!-- Tags -->
						<div class="nw-form-group">
							<label class="nw-label" for="nw-field-tags">Tags <span class="nw-muted">(JSON array)</span></label>
							<input id="nw-field-tags" type="text" class="nw-input" placeholder='["cyber","organic"]'>
						</div>

						<!-- Bonus -->
						<div class="nw-form-group">
							<label class="nw-label" for="nw-field-bonus">Bonus <span class="nw-muted">(JSON object, optional)</span></label>
							<input id="nw-field-bonus" type="text" class="nw-input" placeholder='{"hp":+10}'>
						</div>

						<!-- Image URL -->
						<div class="nw-form-group">
							<label class="nw-label" for="nw-field-img-url">Image URL</label>
							<input id="nw-field-img-url" type="url" class="nw-input" placeholder="https://…">
							<div id="nw-img-preview-wrap" style="display:none;margin-top:8px">
								<img id="nw-img-preview" src="" alt="" style="max-width:120px;max-height:120px;border-radius:6px;object-fit:cover" loading="lazy">
							</div>
						</div>

					</form>

					<div class="nw-modal-footer">
						<button id="nw-delete-btn" class="nw-btn nw-btn-danger nw-btn-sm" style="display:none">
							<i data-lucide="trash-2" style="width:13px;height:13px;vertical-align:middle;margin-right:4px"></i>
							Delete
						</button>
						<div class="nw-modal-footer-right">
							<button id="nw-cancel-btn" class="nw-btn nw-btn-ghost">Cancel</button>
							<button id="nw-save-btn" class="nw-btn nw-btn-primary">
								<i data-lucide="save" style="width:13px;height:13px;vertical-align:middle;margin-right:4px"></i>
								<span id="nw-save-label">Create Race</span>
							</button>
						</div>
					</div>
				</div>
			</div>

		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	public function ajax_list(): void {
		check_ajax_referer( 'nw_races_nonce', 'nonce' );

		if ( ! function_exists( 'tw_supabase_get_admin' ) ) {
			wp_send_json_error( 'Supabase helpers not loaded.', 500 );
			return;
		}

		$rows = tw_supabase_get_admin( 'cyber_races', [ 'order' => 'name.asc' ] );

		if ( is_wp_error( $rows ) ) {
			wp_send_json_error( $rows->get_error_message(), 500 );
			return;
		}

		wp_send_json_success( $rows );
	}

	public function ajax_save(): void {
		check_ajax_referer( 'nw_races_nonce', 'nonce' );

		if ( ! function_exists( 'tw_supabase_request' ) ) {
			wp_send_json_error( 'Supabase helpers not loaded.', 500 );
			return;
		}

		$id   = isset( $_POST['id'] ) ? sanitize_text_field( $_POST['id'] ) : '';
		$data = [
			'name'             => sanitize_text_field( $_POST['name'] ?? '' ),
			'description'      => sanitize_textarea_field( $_POST['description'] ?? '' ),
			'tags'             => json_decode( stripslashes( $_POST['tags'] ?? '[]' ) ),
			'is_active'        => filter_var( $_POST['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN ),
			'race_base_hp'     => intval( $_POST['race_base_hp'] ?? 86 ),
			'race_base_mp'     => intval( $_POST['race_base_mp'] ?? 67 ),
			'parent_race'      => sanitize_text_field( $_POST['parent_race'] ?? '' ) ?: null,
			'conflict_axis'    => sanitize_text_field( $_POST['conflict_axis'] ?? '' ) ?: null,
			'conflict_side'    => sanitize_text_field( $_POST['conflict_side'] ?? '' ) ?: null,
			'img_url'          => esc_url_raw( $_POST['img_url'] ?? '' ) ?: null,
			'gm_instructions'  => sanitize_textarea_field( $_POST['gm_instructions'] ?? '' ),
			'bonus'            => ! empty( $_POST['bonus'] ) ? json_decode( stripslashes( $_POST['bonus'] ) ) : null,
		];
		foreach ( [ 'preferred_tech','preferred_magic','preferred_gods','preferred_wealth',
		            'preferred_threat','preferred_moral','preferred_social' ] as $k ) {
			$data[ $k ] = intval( $_POST[ $k ] ?? 3 );
		}

		$endpoint = $id ? 'cyber_races?id=eq.' . $id : 'cyber_races';
		$method   = $id ? 'PATCH' : 'POST';

		$resp = tw_supabase_request( $method, $endpoint, $data );

		if ( is_wp_error( $resp ) ) {
			wp_send_json_error( $resp->get_error_message(), 500 );
			return;
		}

		wp_send_json_success( true );
	}

	public function ajax_delete(): void {
		check_ajax_referer( 'nw_races_nonce', 'nonce' );

		if ( ! function_exists( 'tw_supabase_request' ) ) {
			wp_send_json_error( 'Supabase helpers not loaded.', 500 );
			return;
		}

		$id   = sanitize_text_field( $_POST['id'] ?? '' );
		$resp = tw_supabase_request( 'DELETE', 'cyber_races?id=eq.' . $id );

		if ( is_wp_error( $resp ) ) {
			wp_send_json_error( $resp->get_error_message(), 500 );
			return;
		}

		wp_send_json_success( true );
	}

	public function ajax_duplicate(): void {
		check_ajax_referer( 'nw_races_nonce', 'nonce' );

		if ( ! function_exists( 'tw_supabase_get_admin' ) || ! function_exists( 'tw_supabase_request' ) ) {
			wp_send_json_error( 'Supabase helpers not loaded.', 500 );
			return;
		}

		$id   = sanitize_text_field( $_POST['id'] ?? '' );
		$rows = tw_supabase_get_admin( 'cyber_races', [ 'id' => 'eq.' . $id ] );

		if ( is_wp_error( $rows ) || empty( $rows[0] ) ) {
			wp_send_json_error( 'Race not found.' );
			return;
		}

		$row = $rows[0];
		unset( $row['id'], $row['created_at'], $row['updated_at'] );
		$row['name']      = $row['name'] . ' (copy)';
		$row['is_active'] = false;

		$resp = tw_supabase_request( 'POST', 'cyber_races', $row );

		if ( is_wp_error( $resp ) ) {
			wp_send_json_error( $resp->get_error_message(), 500 );
			return;
		}

		wp_send_json_success( true );
	}
}
