<?php
/**
 * NeoWeaver Admin Panel — Status Tags (cyber_status_tags)
 *
 * Columns: id, label, category, effect_description, mechanic_modifier,
 *          is_positive, is_active, sort_order, icon_url, tags[].
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Status_Tags_Admin {

	private string $table = 'cyber_status_tags';

	public function __construct() {
		add_action( 'admin_menu',  [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_nw_status_tags_get_all', [ $this, 'ajax_get_all' ] );
		add_action( 'wp_ajax_nw_status_tags_save',    [ $this, 'ajax_save'    ] );
		add_action( 'wp_ajax_nw_status_tags_toggle',  [ $this, 'ajax_toggle'  ] );
		add_action( 'wp_ajax_nw_status_tags_delete',  [ $this, 'ajax_delete'  ] );
	}

	/* ── menu ─────────────────────────────────────────────── */

	public function register_menu(): void {
		add_submenu_page(
			'neoweaver',
			'Status Tags',
			'Status Tags',
			'manage_options',
			'nw-status-tags',
			[ $this, 'render_page' ]
		);
	}

	/* ── assets ────────────────────────────────────────────── */

	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'nw-status-tags' ) ) return;

		wp_enqueue_style(
			'nw-status-tags-css',
			plugin_dir_url( __FILE__ ) . '../assets/css/nw-admin-tables.css',
			[], '1.0'
		);
		wp_enqueue_script(
			'nw-status-tags-js',
			plugin_dir_url( __FILE__ ) . '../assets/js/status-tags-admin.js',
			[ 'jquery' ], '1.0', true
		);
		wp_localize_script( 'nw-status-tags-js', 'NWStatusTags', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'nw_status_tags_nonce' ),
		] );
	}

	/* ── page HTML ───────────────────────────────────────────── */

	public function render_page(): void {
		?>
		<div class="wrap nw-admin-wrap">
		<h1 class="nw-admin-heading">⚡ Status Tags</h1>

		<div id="nw-notice" class="nw-notice" style="display:none"></div>

		<div class="nw-toolbar">
			<button id="nw-add-btn" class="nw-action-btn">+ Add Tag</button>
			<button id="nw-refresh-btn" class="nw-action-btn nw-action-btn--secondary">↺ Refresh</button>
			<select id="nw-filter-category">
				<option value="">All Categories</option>
				<option value="buff">Buff</option>
				<option value="debuff">Debuff</option>
				<option value="neutral">Neutral</option>
				<option value="environmental">Environmental</option>
				<option value="tech">Tech</option>
				<option value="magic">Magic</option>
			</select>
			<input type="text" id="nw-search" placeholder="Search tags…" />
		</div>

		<div class="nw-stats-bar">
			<span>Total: <strong id="nw-total">—</strong></span>
			<span>Active: <strong id="nw-active-count">—</strong></span>
			<span>Buffs: <strong id="nw-buff-count">—</strong></span>
			<span>Debuffs: <strong id="nw-debuff-count">—</strong></span>
		</div>

		<table class="nw-table" id="nw-status-tags-table">
			<thead>
				<tr>
					<th>Icon</th>
					<th>Label</th>
					<th>Category</th>
					<th>Effect</th>
					<th>Modifier</th>
					<th>Tags</th>
					<th>Active</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody id="nw-status-tags-tbody"></tbody>
		</table>

		<!-- Modal -->
		<div id="nw-modal-overlay" class="nw-modal-overlay" style="display:none">
			<div class="nw-modal">
				<div class="nw-modal-header">
					<h2 id="nw-modal-title">Status Tag</h2>
					<button id="nw-modal-close" class="nw-modal-close">✕</button>
				</div>
				<form id="nw-tag-form">
				<input type="hidden" name="tag_id" id="nw-field-id" />
				<div class="nw-form-grid">
					<label>Label *<input type="text" name="label" id="nw-field-label" required /></label>
					<label>Category
						<select name="category" id="nw-field-category">
							<option value="buff">Buff</option>
							<option value="debuff">Debuff</option>
							<option value="neutral">Neutral</option>
							<option value="environmental">Environmental</option>
							<option value="tech">Tech</option>
							<option value="magic">Magic</option>
						</select>
					</label>
					<label class="nw-span-2">Effect Description<textarea name="effect_description" id="nw-field-effect" rows="3"></textarea></label>
					<label>Mechanic Modifier<input type="text" name="mechanic_modifier" id="nw-field-modifier" /></label>
					<label>Sort Order<input type="number" name="sort_order" id="nw-field-sort" value="0" /></label>
					<label>Icon URL<input type="url" name="icon_url" id="nw-field-icon" /></label>
					<label>Tags (comma-separated)<input type="text" name="tags" id="nw-field-tags" /></label>
					<label class="nw-checkbox-label"><input type="checkbox" name="is_positive" id="nw-field-positive" value="1" /> Positive effect</label>
					<label class="nw-checkbox-label"><input type="checkbox" name="is_active" id="nw-field-active" value="1" checked /> Active</label>
				</div>
				</form>
				<div class="nw-modal-footer">
					<button id="nw-save-btn" class="nw-action-btn"><span id="nw-save-label">Save</span></button>
					<button id="nw-cancel-btn" class="nw-action-btn nw-action-btn--secondary">Cancel</button>
					<button id="nw-delete-btn" class="nw-action-btn nw-action-btn--danger" style="display:none">Delete</button>
				</div>
			</div>
		</div>
		</div>
		<?php
	}

	/* ── helpers ────────────────────────────────────────────── */

	private function supa( string $method, string $path, array $body = [], array $extra_headers = [] ): array {
		return nw_supabase_request( $method, $path, $body, $extra_headers );
	}

	/* ── AJAX: get all ───────────────────────────────────────────── */

	public function ajax_get_all(): void {
		check_ajax_referer( 'nw_status_tags_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$category = sanitize_text_field( $_POST['filter_category'] ?? '' );
		$qs = $this->table . '?order=sort_order.asc,label.asc&select=*';
		if ( $category ) $qs .= '&category=eq.' . rawurlencode( $category );

		$rows = $this->supa( 'GET', $qs );

		if ( isset( $rows['error'] ) ) {
			wp_send_json_error( $rows['error'] );
			return;
		}

		wp_send_json_success( $rows['data'] ?? [] );
	}

	/* ── AJAX: save ─────────────────────────────────────────────── */

	public function ajax_save(): void {
		check_ajax_referer( 'nw_status_tags_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$label = sanitize_text_field( $_POST['label'] ?? '' );
		if ( ! $label ) {
			wp_send_json_error( 'Label is required' );
			return;
		}

		$raw_tags = sanitize_text_field( $_POST['tags'] ?? '' );
		$tags = array_values( array_filter( array_map( 'trim', explode( ',', $raw_tags ) ) ) );

		$payload = [
			'label'               => $label,
			'category'            => sanitize_text_field( $_POST['category'] ?? 'neutral' ),
			'effect_description'  => sanitize_textarea_field( $_POST['effect_description'] ?? '' ),
			'mechanic_modifier'   => sanitize_text_field( $_POST['mechanic_modifier'] ?? '' ),
			'sort_order'          => (int) ( $_POST['sort_order'] ?? 0 ),
			'icon_url'            => esc_url_raw( $_POST['icon_url'] ?? '' ),
			'tags'                => $tags,
			'is_positive'         => ! empty( $_POST['is_positive'] ),
			'is_active'           => ! empty( $_POST['is_active'] ),
		];

		$id = sanitize_text_field( $_POST['tag_id'] ?? '' );

		if ( $id ) {
			$res = $this->supa( 'PATCH', $this->table . '?id=eq.' . rawurlencode( $id ), $payload,
				[ 'Prefer' => 'return=representation' ] );
		} else {
			$res = $this->supa( 'POST', $this->table, $payload,
				[ 'Prefer' => 'return=representation' ] );
		}

		if ( isset( $res['error'] ) ) {
			wp_send_json_error( $res['error'] );
			return;
		}

		$code = $res['code'] ?? 0;
		$data = $res['data'] ?? [];
		$item = is_array( $data ) && isset( $data[0] ) ? $data[0] : $data;

		$code >= 200 && $code < 300
			? wp_send_json_success( $item )
			: wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code );
	}

	/* ── AJAX: toggle active ────────────────────────────────────────────── */

	public function ajax_toggle(): void {
		check_ajax_referer( 'nw_status_tags_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$id    = sanitize_text_field( $_POST['tag_id'] ?? '' );
		$state = filter_var( $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );

		if ( ! $id ) { wp_send_json_error( 'Missing ID' ); return; }

		$res = $this->supa( 'PATCH', $this->table . '?id=eq.' . rawurlencode( $id ), [ 'is_active' => $state ] );
		isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( [ 'is_active' => $state ] );
	}

	/* ── AJAX: delete ─────────────────────────────────────────────── */

	public function ajax_delete(): void {
		check_ajax_referer( 'nw_status_tags_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$id = sanitize_text_field( $_POST['tag_id'] ?? '' );

		if ( ! $id ) { wp_send_json_error( 'Missing ID' ); return; }

		$res = $this->supa( 'DELETE', $this->table . '?id=eq.' . rawurlencode( $id ), [], [ 'Prefer' => '' ] );
		isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( 'deleted' );
	}
}

new NeoWeaver_Status_Tags_Admin();
