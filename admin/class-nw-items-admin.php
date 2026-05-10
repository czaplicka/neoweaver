<?php
/**
 * NeoWeaver Admin — Items (cyber_items)
 *
 * Full CRUD via Supabase PostgREST.
 * Columns: id, name, description, item_type, rarity, tags[],
 *          properties{}, is_active, sort_order, image_url, weight, value.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Items_Admin {

	private string $table = 'cyber_items';

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu'  ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_nw_items_get_all', [ $this, 'ajax_get_all' ] );
		add_action( 'wp_ajax_nw_items_get_one', [ $this, 'ajax_get_one' ] );
		add_action( 'wp_ajax_nw_items_save',    [ $this, 'ajax_save'    ] );
		add_action( 'wp_ajax_nw_items_toggle',  [ $this, 'ajax_toggle'  ] );
		add_action( 'wp_ajax_nw_items_delete',  [ $this, 'ajax_delete'  ] );
	}

	// ── menu ──────────────────────────────────────────────────────────────

	public function register_menu(): void {
		add_submenu_page(
			'neoweaver', 'Items', 'Items', 'manage_options',
			'nw-items', [ $this, 'render_page' ]
		);
	}

	// ── assets ────────────────────────────────────────────────────────────

	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'nw-items' ) ) return;

		// shared admin table styles
		wp_enqueue_style(
			'nw-admin-tables',
			plugin_dir_url( __FILE__ ) . '../assets/css/nw-admin-tables.css',
			[], '1.0'
		);

		// items-specific styles
		wp_enqueue_style(
			'nw-items-css',
			plugin_dir_url( __FILE__ ) . '../assets/css/items-admin.css',
			[ 'nw-admin-tables' ], '1.0'
		);

		// items JS
		wp_enqueue_script(
			'nw-items-js',
			plugin_dir_url( __FILE__ ) . '../assets/js/items-admin.js',
			[ 'jquery' ], '1.0', true
		);
		wp_localize_script( 'nw-items-js', 'NWItems', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'nw_items_nonce' ),
		] );
	}

	// ── page HTML ─────────────────────────────────────────────────────────

	public function render_page(): void {
		?>
		<div class="wrap nw-admin-wrap">
		<h1 class="nw-admin-heading">⚙️ Items</h1>

		<div id="nw-notice" class="nw-notice" style="display:none"></div>

		<div class="nw-toolbar">
			<button id="nw-add-btn" class="nw-action-btn">+ Add Item</button>
			<button id="nw-refresh-btn" class="nw-action-btn nw-action-btn--secondary">↺ Refresh</button>
			<select id="nw-filter-type">
				<option value="">All Types</option>
				<option value="weapon">Weapon</option>
				<option value="armor">Armor</option>
				<option value="consumable">Consumable</option>
				<option value="tool">Tool</option>
				<option value="implant">Implant</option>
				<option value="software">Software</option>
				<option value="misc">Misc</option>
			</select>
			<select id="nw-filter-rarity">
				<option value="">All Rarities</option>
				<option value="common">Common</option>
				<option value="uncommon">Uncommon</option>
				<option value="rare">Rare</option>
				<option value="epic">Epic</option>
				<option value="legendary">Legendary</option>
			</select>
			<input type="text" id="nw-search" placeholder="Search items…" />
		</div>

		<div class="nw-stats-bar">
			<span>Total: <strong id="nw-total">—</strong></span>
			<span>Active: <strong id="nw-active-count">—</strong></span>
		</div>

		<table class="nw-table" id="nw-items-table">
			<thead>
				<tr>
					<th>Image</th>
					<th>Name</th>
					<th>Type</th>
					<th>Rarity</th>
					<th>Tags</th>
					<th>Weight</th>
					<th>Value</th>
					<th>Active</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody id="nw-items-tbody"></tbody>
		</table>

		<!-- Modal -->
		<div id="nw-modal-overlay" class="nw-modal-overlay" style="display:none">
			<div class="nw-modal nw-modal--wide">
				<div class="nw-modal-header">
					<h2 id="nw-modal-title">Item</h2>
					<button id="nw-modal-close" class="nw-modal-close">✕</button>
				</div>
				<form id="nw-item-form">
				<input type="hidden" name="item_id" id="nw-field-id" />

				<div class="nw-tabs">
					<button type="button" class="nw-tab active" data-tab="basic">Basic</button>
					<button type="button" class="nw-tab" data-tab="properties">Properties</button>
				</div>

				<div class="nw-tab-panel active" id="nw-tab-basic">
				<div class="nw-form-grid">
					<label>Name *<input type="text" name="name" id="nw-field-name" required /></label>
					<label>Type
						<select name="item_type" id="nw-field-type">
							<option value="misc">Misc</option>
							<option value="weapon">Weapon</option>
							<option value="armor">Armor</option>
							<option value="consumable">Consumable</option>
							<option value="tool">Tool</option>
							<option value="implant">Implant</option>
							<option value="software">Software</option>
						</select>
					</label>
					<label>Rarity
						<select name="rarity" id="nw-field-rarity">
							<option value="common">Common</option>
							<option value="uncommon">Uncommon</option>
							<option value="rare">Rare</option>
							<option value="epic">Epic</option>
							<option value="legendary">Legendary</option>
						</select>
					</label>
					<label class="nw-span-2">Description<textarea name="description" id="nw-field-desc" rows="3"></textarea></label>
					<label>Weight<input type="number" name="weight" id="nw-field-weight" step="0.01" value="0" /></label>
					<label>Value (credits)<input type="number" name="value" id="nw-field-value" value="0" /></label>
					<label>Sort Order<input type="number" name="sort_order" id="nw-field-sort" value="0" /></label>
					<label>Image URL<input type="url" name="image_url" id="nw-field-image" /></label>
					<label>Tags (comma-separated)<input type="text" name="tags" id="nw-field-tags" /></label>
					<label class="nw-checkbox-label"><input type="checkbox" name="is_active" id="nw-field-active" value="1" checked /> Active</label>
				</div>
				</div>

				<div class="nw-tab-panel" id="nw-tab-properties" style="display:none">
					<p class="nw-help-text">Properties as JSON object, e.g. {"damage":"2d6","range":"30m"}.</p>
					<textarea name="properties" id="nw-field-properties" rows="10" style="width:100%"></textarea>
				</div>

				</form>
				<div class="nw-modal-footer">
					<button id="nw-save-btn" class="nw-action-btn">Save</button>
					<button id="nw-cancel-btn" class="nw-action-btn nw-action-btn--secondary">Cancel</button>
					<button id="nw-delete-btn" class="nw-action-btn nw-action-btn--danger" style="display:none">Delete</button>
				</div>
			</div>
		</div>
		</div>
		<?php
	}

	// ── helpers ───────────────────────────────────────────────────────────

	private function supa( string $method, string $path, array $body = [], array $extra = [] ): array {
		return nw_supabase_request( $method, $path, $body, $extra );
	}

	private function decode_json_field( string $raw ): mixed {
		$raw = trim( $raw );
		if ( $raw === '' ) return new stdClass();
		$decoded = json_decode( $raw, true );
		return json_last_error() === JSON_ERROR_NONE ? $decoded : new stdClass();
	}

	// ── AJAX: get all ─────────────────────────────────────────────────────

	public function ajax_get_all(): void {
		check_ajax_referer( 'nw_items_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$type   = sanitize_text_field( $_POST['filter_type']   ?? '' );
		$rarity = sanitize_text_field( $_POST['filter_rarity'] ?? '' );

		$qs = $this->table . '?order=sort_order.asc,name.asc&select=*';
		if ( $type )   $qs .= '&item_type=eq.' . rawurlencode( $type );
		if ( $rarity ) $qs .= '&rarity=eq.'    . rawurlencode( $rarity );

		$rows = $this->supa( 'GET', $qs );

		if ( isset( $rows['error'] ) ) {
			wp_send_json_error( $rows['error'] );
			return;
		}

		wp_send_json_success( $rows['data'] ?? [] );
	}

	// ── AJAX: get one ─────────────────────────────────────────────────────

	public function ajax_get_one(): void {
		check_ajax_referer( 'nw_items_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$id = sanitize_text_field( $_POST['item_id'] ?? '' );
		if ( ! $id ) { wp_send_json_error( 'Missing ID' ); return; }

		$res = $this->supa( 'GET', $this->table . '?id=eq.' . rawurlencode( $id ) . '&select=*' );

		if ( isset( $res['error'] ) ) { wp_send_json_error( $res['error'] ); return; }

		$data = $res['data'] ?? [];
		if ( empty( $data ) ) { wp_send_json_error( 'Not found' ); return; }

		wp_send_json_success( $data[0] );
	}

	// ── AJAX: save ────────────────────────────────────────────────────────

	public function ajax_save(): void {
		check_ajax_referer( 'nw_items_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$name = sanitize_text_field( $_POST['name'] ?? '' );
		if ( ! $name ) { wp_send_json_error( 'Name is required' ); return; }

		$tags = array_values( array_filter( array_map(
			'trim', explode( ',', sanitize_text_field( $_POST['tags'] ?? '' ) )
		) ) );

		$properties = $this->decode_json_field( wp_unslash( $_POST['properties'] ?? '' ) );

		$payload = [
			'name'        => $name,
			'description' => sanitize_textarea_field( $_POST['description'] ?? '' ),
			'item_type'   => sanitize_text_field( $_POST['item_type'] ?? 'misc' ),
			'rarity'      => sanitize_text_field( $_POST['rarity']    ?? 'common' ),
			'tags'        => $tags,
			'properties'  => $properties,
			'is_active'   => ! empty( $_POST['is_active'] ),
			'sort_order'  => (int) ( $_POST['sort_order'] ?? 0 ),
			'image_url'   => esc_url_raw( $_POST['image_url'] ?? '' ),
			'weight'      => (float) ( $_POST['weight'] ?? 0 ),
			'value'       => (int)   ( $_POST['value']  ?? 0 ),
		];

		$id = sanitize_text_field( $_POST['item_id'] ?? '' );

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

		if ( $code >= 200 && $code < 300 ) {
			wp_send_json_success( $item );
		} else {
			wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code );
			return;
		}
	}

	// ── AJAX: toggle active ───────────────────────────────────────────────

	public function ajax_toggle(): void {
		check_ajax_referer( 'nw_items_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$id    = sanitize_text_field( $_POST['item_id'] ?? '' );
		$state = filter_var( $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );
		if ( ! $id ) { wp_send_json_error( 'Missing ID' ); return; }

		$res = $this->supa( 'PATCH', $this->table . '?id=eq.' . rawurlencode( $id ), [ 'is_active' => $state ] );
		if ( isset( $res['error'] ) ) {
			wp_send_json_error( $res['error'] );
			return;
		}
		wp_send_json_success( [ 'is_active' => $state ] );
	}

	// ── AJAX: delete ──────────────────────────────────────────────────────

	public function ajax_delete(): void {
		check_ajax_referer( 'nw_items_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$id = sanitize_text_field( $_POST['item_id'] ?? '' );
		if ( ! $id ) { wp_send_json_error( 'Missing ID' ); return; }

		$res = $this->supa( 'DELETE', $this->table . '?id=eq.' . rawurlencode( $id ), [], [ 'Prefer' => '' ] );
		if ( isset( $res['error'] ) ) {
			wp_send_json_error( $res['error'] );
			return;
		}
		wp_send_json_success( 'deleted' );
	}
}

new NeoWeaver_Items_Admin();
