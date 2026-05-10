<?php
/**
 * NeoWeaver Admin Panel — Scenarios (cyber_scenarios)
 *
 * Fields: id, title, description, difficulty, setting, objectives[],
 *         rewards{}, prerequisites{}, tags[], is_active, sort_order,
 *         image_url, estimated_duration_minutes.
 *
 * PostgREST hints:
 *   GET  /cyber_scenarios?select=*  → array of rows
 *   POST /cyber_scenarios           → insert (Prefer: return=representation)
 *   PATCH /cyber_scenarios?id=eq.{id} → update
 *   DELETE /cyber_scenarios?id=eq.{id} → delete
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Scenarios_Admin {

	private string $table = 'cyber_scenarios';

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu'   ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'  ] );

		add_action( 'wp_ajax_nw_scenarios_get_all',    [ $this, 'ajax_get_all'    ] );
		add_action( 'wp_ajax_nw_scenarios_get_one',    [ $this, 'ajax_get_one'    ] );
		add_action( 'wp_ajax_nw_scenarios_save',       [ $this, 'ajax_save'       ] );
		add_action( 'wp_ajax_nw_scenarios_toggle',     [ $this, 'ajax_toggle'     ] );
		add_action( 'wp_ajax_nw_scenarios_delete',     [ $this, 'ajax_delete'     ] );
	}

	// ── menu ──────────────────────────────────────────────────────────────

	public function register_menu(): void {
		add_submenu_page(
			'neoweaver', 'Scenarios', 'Scenarios', 'manage_options',
			'nw-scenarios', [ $this, 'render_page' ]
		);
	}

	// ── assets ────────────────────────────────────────────────────────────

public function enqueue_assets( string $hook ): void {
    if ( ! str_contains( $hook, 'nw-scenarios' ) ) return;

    wp_enqueue_style(
        'chakra-petch',
        'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
        [], null
    );
    wp_enqueue_style(
        'nw-scenarios-css',
        plugin_dir_url( __FILE__ ) . '../assets/css/scenarios-admin.css',
        [ 'chakra-petch' ], '1.0.0'
    );
    wp_enqueue_script(
        'nw-scenarios-js',
        plugin_dir_url( __FILE__ ) . '../assets/js/scenarios-admin.js',
        [ 'jquery' ], '1.0.0', true
    );
    wp_localize_script( 'nw-scenarios-js', 'NWScenarios', [
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'nw_scenarios_nonce' ),
    ] );
}

	// ── page HTML ─────────────────────────────────────────────────────────

	public function render_page(): void {
		?>
		<div class="wrap nw-admin-wrap">
		<h1 class="nw-admin-heading">🎭 Scenarios</h1>

		<div id="nw-notice" class="nw-notice" style="display:none"></div>

		<div class="nw-toolbar">
			<button id="nw-add-btn" class="nw-action-btn">+ Add Scenario</button>
			<button id="nw-refresh-btn" class="nw-action-btn nw-action-btn--secondary">↺ Refresh</button>
			<select id="nw-filter-difficulty">
				<option value="">All Difficulties</option>
				<option value="trivial">Trivial</option>
				<option value="easy">Easy</option>
				<option value="medium">Medium</option>
				<option value="hard">Hard</option>
				<option value="deadly">Deadly</option>
			</select>
			<input type="text" id="nw-search" placeholder="Search scenarios…" />
		</div>

		<table class="nw-table" id="nw-scenarios-table">
			<thead>
				<tr>
					<th>Title</th>
					<th>Difficulty</th>
					<th>Setting</th>
					<th>Duration</th>
					<th>Tags</th>
					<th>Active</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody id="nw-scenarios-tbody"></tbody>
		</table>

		<!-- ─── Modal ─── -->
		<div id="nw-modal-overlay" class="nw-modal-overlay" style="display:none">
			<div class="nw-modal nw-modal--wide">
				<div class="nw-modal-header">
					<h2 id="nw-modal-title">Scenario</h2>
					<button id="nw-modal-close" class="nw-modal-close">✕</button>
				</div>
				<form id="nw-scenario-form">
				<input type="hidden" name="scenario_id" id="nw-field-id" />

				<!-- Tab nav -->
				<div class="nw-tabs">
					<button type="button" class="nw-tab active" data-tab="basic">Basic</button>
					<button type="button" class="nw-tab" data-tab="objectives">Objectives</button>
					<button type="button" class="nw-tab" data-tab="rewards">Rewards</button>
					<button type="button" class="nw-tab" data-tab="prerequisites">Prerequisites</button>
				</div>

				<!-- Tab: Basic -->
				<div class="nw-tab-panel active" id="nw-tab-basic">
				<div class="nw-form-grid">
					<label>Title *<input type="text" name="title" id="nw-field-title" required /></label>
					<label>Setting<input type="text" name="setting" id="nw-field-setting" /></label>
					<label class="nw-span-2">Description<textarea name="description" id="nw-field-desc" rows="4"></textarea></label>
					<label>Difficulty
						<select name="difficulty" id="nw-field-difficulty">
							<option value="trivial">Trivial</option>
							<option value="easy">Easy</option>
							<option value="medium" selected>Medium</option>
							<option value="hard">Hard</option>
							<option value="deadly">Deadly</option>
						</select>
					</label>
					<label>Duration (min)<input type="number" name="estimated_duration_minutes" id="nw-field-duration" value="60" min="0" /></label>
					<label>Sort Order<input type="number" name="sort_order" id="nw-field-sort" value="0" /></label>
					<label>Image URL<input type="url" name="image_url" id="nw-field-image" /></label>
					<label>Tags (comma-separated)<input type="text" name="tags" id="nw-field-tags" /></label>
					<label class="nw-checkbox-label"><input type="checkbox" name="is_active" id="nw-field-active" value="1" checked /> Active</label>
				</div>
				</div><!-- /tab-basic -->

				<!-- Tab: Objectives -->
				<div class="nw-tab-panel" id="nw-tab-objectives" style="display:none">
					<p class="nw-help-text">Add objectives (one per line or JSON array).</p>
					<textarea name="objectives" id="nw-field-objectives" rows="8" style="width:100%"></textarea>
				</div>

				<!-- Tab: Rewards -->
				<div class="nw-tab-panel" id="nw-tab-rewards" style="display:none">
					<p class="nw-help-text">Rewards as JSON object, e.g. {"xp":100,"credits":50}.</p>
					<textarea name="rewards" id="nw-field-rewards" rows="6" style="width:100%"></textarea>
				</div>

				<!-- Tab: Prerequisites -->
				<div class="nw-tab-panel" id="nw-tab-prerequisites" style="display:none">
					<p class="nw-help-text">Prerequisites as JSON object.</p>
					<textarea name="prerequisites" id="nw-field-prerequisites" rows="6" style="width:100%"></textarea>
				</div>

				</form><!-- /form -->

				<div class="nw-modal-footer">
					<button id="nw-save-btn" class="nw-action-btn">Save</button>
					<button id="nw-cancel-btn" class="nw-action-btn nw-action-btn--secondary">Cancel</button>
					<button id="nw-delete-btn" class="nw-action-btn nw-action-btn--danger" style="display:none">Delete</button>
				</div>
			</div>
		</div><!-- /modal-overlay -->
		</div><!-- /wrap -->
		<?php
	}

	// ── helpers ───────────────────────────────────────────────────────────

	private function supa( string $method, string $path, array $body = [], array $extra = [] ): array {
		return nw_supabase_request( $method, $path, $body, $extra );
	}

	/**
	 * Decode textarea input that may be either:
	 * - plain text (one item per line)  → convert to JSON array
	 * - JSON array already               → return as-is
	 * - JSON object                      → return as-is
	 */
	private function decode_textarea_field( string $raw ): mixed {
		$raw = trim( $raw );
		if ( $raw === '' ) return [];
		$decoded = json_decode( $raw, true );
		if ( json_last_error() === JSON_ERROR_NONE ) return $decoded;
		// Plain text → array of non-empty lines
		return array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) );
	}

	// ── AJAX: get all ─────────────────────────────────────────────────────

	public function ajax_get_all(): void {
		check_ajax_referer( 'nw_scenarios_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$difficulty = sanitize_text_field( $_POST['filter_difficulty'] ?? '' );
		$qs = $this->table . '?order=sort_order.asc,title.asc&select=*';
		if ( $difficulty ) $qs .= '&difficulty=eq.' . rawurlencode( $difficulty );

		$rows = $this->supa( 'GET', $qs );

		if ( isset( $rows['error'] ) ) {
			wp_send_json_error( $rows['error'] );
			return;
		}

		wp_send_json_success( $rows['data'] ?? [] );
	}

	// ── AJAX: get one (full record for editing) ───────────────────────────

	public function ajax_get_one(): void {
		check_ajax_referer( 'nw_scenarios_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$id = sanitize_text_field( $_POST['scenario_id'] ?? '' );
		if ( ! $id ) { wp_send_json_error( 'Missing ID' ); return; }

		$res = $this->supa( 'GET', $this->table . '?id=eq.' . rawurlencode( $id ) . '&select=*' );

		if ( isset( $res['error'] ) ) { wp_send_json_error( $res['error'] ); return; }

		$data = $res['data'] ?? [];
		if ( empty( $data ) ) { wp_send_json_error( 'Not found' ); return; }

		wp_send_json_success( $data[0] );
	}

	// ── AJAX: save ────────────────────────────────────────────────────────

	public function ajax_save(): void {
		check_ajax_referer( 'nw_scenarios_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$title = sanitize_text_field( $_POST['title'] ?? '' );
		if ( ! $title ) { wp_send_json_error( 'Title is required' ); return; }

		$tags = array_values( array_filter( array_map(
			'trim', explode( ',', sanitize_text_field( $_POST['tags'] ?? '' ) )
		) ) );

		$objectives    = $this->decode_textarea_field( wp_unslash( $_POST['objectives']    ?? '' ) );
		$rewards       = $this->decode_textarea_field( wp_unslash( $_POST['rewards']       ?? '' ) );
		$prerequisites = $this->decode_textarea_field( wp_unslash( $_POST['prerequisites'] ?? '' ) );

		$payload = [
			'title'                       => $title,
			'description'                 => sanitize_textarea_field( $_POST['description'] ?? '' ),
			'difficulty'                  => sanitize_text_field( $_POST['difficulty'] ?? 'medium' ),
			'setting'                     => sanitize_text_field( $_POST['setting'] ?? '' ),
			'objectives'                  => $objectives,
			'rewards'                     => $rewards,
			'prerequisites'               => $prerequisites,
			'tags'                        => $tags,
			'is_active'                   => ! empty( $_POST['is_active'] ),
			'sort_order'                  => (int) ( $_POST['sort_order'] ?? 0 ),
			'image_url'                   => esc_url_raw( $_POST['image_url'] ?? '' ),
			'estimated_duration_minutes'  => (int) ( $_POST['estimated_duration_minutes'] ?? 60 ),
		];

		$id = sanitize_text_field( $_POST['scenario_id'] ?? '' );

		if ( $id ) {
			$res = $this->supa( 'PATCH', $this->table . '?id=eq.' . rawurlencode( $id ), $payload,
				[ 'Prefer' => 'return=representation' ] );
		} else {
			$res = $this->supa( 'POST', $this->table, $payload,
				[ 'Prefer' => 'return=representation' ] );
		}

		if ( isset( $res['error'] ) ) { wp_send_json_error( $res['error'] ); return; }

		$code = $res['code'] ?? 0;
		$data = $res['data'] ?? [];
		$item = is_array( $data ) && isset( $data[0] ) ? $data[0] : $data;

		$code >= 200 && $code < 300
			? wp_send_json_success( $item )
			: wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code );
	}

	// ── AJAX: toggle active ───────────────────────────────────────────────

	public function ajax_toggle(): void {
		check_ajax_referer( 'nw_scenarios_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$id    = sanitize_text_field( $_POST['scenario_id'] ?? '' );
		$state = filter_var( $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );
		if ( ! $id ) { wp_send_json_error( 'Missing ID' ); return; }

		$res = $this->supa( 'PATCH', $this->table . '?id=eq.' . rawurlencode( $id ), [ 'is_active' => $state ] );
		isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( [ 'is_active' => $state ] );
	}

	// ── AJAX: delete ──────────────────────────────────────────────────────

	public function ajax_delete(): void {
		check_ajax_referer( 'nw_scenarios_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$id = sanitize_text_field( $_POST['scenario_id'] ?? '' );
		if ( ! $id ) { wp_send_json_error( 'Missing ID' ); return; }

		$res = $this->supa( 'DELETE', $this->table . '?id=eq.' . rawurlencode( $id ), [], [ 'Prefer' => '' ] );
		isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( 'deleted' );
	}
}

new NeoWeaver_Scenarios_Admin();
