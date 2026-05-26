<?php
/**
 * NeoWeaver — Containers Admin
 * Table: cyber_containers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NW_Containers_Admin {

	private string $page_slug    = 'nw-containers';
	private string $menu_parent  = 'neoweaver';
	private string $table        = 'cyber_containers';
	private string $nonce_action = 'nw_containers_nonce';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_nw_containers_load', [ $this, 'ajax_load' ] );
		add_action( 'wp_ajax_nw_containers_save', [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_containers_delete', [ $this, 'ajax_delete' ] );
		add_action( 'wp_ajax_nw_containers_duplicate', [ $this, 'ajax_duplicate' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			$this->menu_parent,
			__( 'Containers', 'neoweaver' ),
			__( '<span data-lucide-menu="backpack"></span> Containers', 'neoweaver' ),
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, $this->page_slug ) ) {
			return;
		}

		if ( ! wp_style_is( 'chakra-petch', 'enqueued' ) ) {
			wp_enqueue_style(
				'chakra-petch',
				'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
				[],
				null
			);
		}

		wp_enqueue_style(
			'nw-admin-core',
			NW_PLUGIN_URL . 'assets/css/admin/admin-core.css',
			[ 'chakra-petch' ],
			NW_VERSION
		);

		wp_enqueue_style(
			'nw-containers-style',
			NW_PLUGIN_URL . 'assets/css/admin/containers.css',
			[ 'chakra-petch', 'nw-admin-core' ],
			NW_VERSION
		);

		wp_enqueue_script(
			'lucide',
			'https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js',
			[],
			'0.468.0',
			true
		);

		wp_enqueue_script(
			'nw-containers-script',
			NW_PLUGIN_URL . 'assets/js/admin/containers.js',
			[ 'jquery', 'lucide' ],
			NW_VERSION,
			true
		);

		$uploads = wp_upload_dir();
		wp_localize_script( 'nw-containers-script', 'NWContainers', [
			'ajaxurl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( $this->nonce_action ),
			'uploads_url' => isset( $uploads['baseurl'] ) ? untrailingslashit( $uploads['baseurl'] ) : '',
		] );
	}

	private function sk(): array {
		if ( ! defined( 'TW_SUPABASE_SERVICE_KEY' ) ) {
			return [];
		}
		return [
			'apikey'        => TW_SUPABASE_SERVICE_KEY,
			'Authorization' => 'Bearer ' . TW_SUPABASE_SERVICE_KEY,
		];
	}

	private function supa( string $method, string $endpoint, array $body = [], array $extra_headers = [] ): array {
		$method = strtoupper( $method );

		if ( 'GET' === $method && function_exists( 'tw_supabase_get' ) ) {
			[ $table, $qs ] = array_pad( explode( '?', $endpoint, 2 ), 2, '' );
			$query = [];
			if ( $qs ) {
				parse_str( $qs, $query );
			}
			$data = tw_supabase_get( $table, $query, [ 'headers' => $extra_headers ] );
			if ( ! is_array( $data ) ) {
				return [ 'ok' => false, 'code' => 0, 'data' => null, 'error' => 'tw_supabase_get returned non-array' ];
			}
			if ( isset( $data['code'], $data['message'] ) ) {
				return [ 'ok' => false, 'code' => (int) $data['code'], 'data' => null, 'error' => $data['message'] ];
			}
			return [ 'ok' => true, 'code' => 200, 'data' => $data, 'error' => null ];
		}

		if ( function_exists( 'tw_supabase_request' ) ) {
			[ $table, $qs ] = array_pad( explode( '?', $endpoint, 2 ), 2, '' );
			$query = [];
			if ( $qs ) {
				parse_str( $qs, $query );
			}
			$extra_args = [];
			if ( in_array( $method, [ 'POST', 'PATCH' ], true ) ) {
				$extra_args['headers']['Prefer'] = 'return=representation';
			}
			if ( ! empty( $extra_headers ) ) {
				$extra_args['headers'] = array_merge( $extra_args['headers'] ?? [], $extra_headers );
			}
			$res = tw_supabase_request( $method, $table, $query, empty( $body ) ? null : $body, $extra_args );
			$ok = $res['ok'] ?? false;
			$code = $res['code'] ?? 0;
			$data = $res['data'] ?? null;
			if ( ! $ok ) {
				$msg = is_array( $data ) ? ( $data['message'] ?? 'Supabase error ' . $code ) : 'Supabase error ' . $code;
				return [ 'ok' => false, 'code' => $code, 'data' => $data, 'error' => $msg ];
			}
			return [ 'ok' => true, 'code' => $code, 'data' => $data, 'error' => null ];
		}

		return [ 'ok' => false, 'code' => 0, 'data' => null, 'error' => 'Supabase helper functions not available.' ];
	}

	private function get_cache_key( string $suffix ): string {
		return 'nw_' . md5( $suffix );
	}

	private function bust_cache(): void {
		delete_transient( $this->get_cache_key( $this->table . '_all' ) );
	}

	private function cached_get_all(): array {
		$cache_key = $this->get_cache_key( $this->table . '_all' );
		$cached = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$res = $this->supa( 'GET', $this->table . '?select=*&order=created_at.desc', [], $this->sk() );
		if ( ! $res['ok'] ) {
			return [ 'error' => $res['error'] ?? 'Failed to fetch records.' ];
		}

		$rows = is_array( $res['data'] ) ? $res['data'] : [];
		set_transient( $cache_key, $rows, MINUTE_IN_SECONDS * 5 );
		return $rows;
	}

	private function is_uuid( string $value ): bool {
		return (bool) preg_match(
			'/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
			$value
		);
	}

	private function bool_from_post( string $key, bool $default = false ): bool {
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}
		return (bool) intval( wp_unslash( $_POST[ $key ] ) );
	}

	private function parse_allowed_sizes( string $raw ): array {
		$allowed = [ 'tiny', 'small', 'medium', 'large' ];
		$parts = array_map( 'trim', explode( ',', strtolower( $raw ) ) );
		$parts = array_values( array_filter( array_unique( $parts ) ) );
		$parts = array_values( array_filter( $parts, static fn( $v ) => in_array( $v, $allowed, true ) ) );
		return empty( $parts ) ? [ 'tiny', 'small', 'medium', 'large' ] : $parts;
	}

	private function sanitize_rarity( string $rarity ): string {
		$allowed = [ 'common', 'uncommon', 'rare', 'epic', 'legendary' ];
		$rarity = strtolower( trim( $rarity ) );
		return in_array( $rarity, $allowed, true ) ? $rarity : 'common';
	}

	public function ajax_load(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$rows = $this->cached_get_all();
		if ( isset( $rows['error'] ) ) {
			wp_send_json_error( $rows['error'] );
			return;
		}

		wp_send_json_success( $rows );
	}

	public function ajax_save(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id          = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$name        = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$img_url     = esc_url_raw( wp_unslash( $_POST['img_url'] ?? '' ) );
		$rarity      = $this->sanitize_rarity( sanitize_text_field( wp_unslash( $_POST['rarity'] ?? 'common' ) ) );
		$total_slots = max( 1, intval( wp_unslash( $_POST['total_slots'] ?? 5 ) ) );
		$is_active   = $this->bool_from_post( 'is_active', true );
		$parent_id   = sanitize_text_field( wp_unslash( $_POST['parent_id'] ?? '' ) );
		$sizes_raw   = sanitize_text_field( wp_unslash( $_POST['allowed_sizes'] ?? '' ) );

		if ( ! $name ) {
			wp_send_json_error( 'Name is required.' );
			return;
		}

		if ( $id && ! $this->is_uuid( $id ) ) {
			wp_send_json_error( 'Invalid container ID.' );
			return;
		}

		if ( $parent_id && ! $this->is_uuid( $parent_id ) ) {
			wp_send_json_error( 'Parent item ID must be a valid UUID.' );
			return;
		}

		$payload = [
			'name'          => $name,
			'description'   => '' !== $description ? $description : null,
			'total_slots'   => $total_slots,
			'allowed_sizes' => $this->parse_allowed_sizes( $sizes_raw ),
			'img_url'       => '' !== $img_url ? $img_url : null,
			'rarity'        => $rarity,
			'is_active'     => $is_active,
			'parent_id'     => '' !== $parent_id ? $parent_id : null,
		];

		if ( $id ) {
			$res = $this->supa( 'PATCH', $this->table . '?id=eq.' . rawurlencode( $id ), $payload, $this->sk() );
		} else {
			$res = $this->supa( 'POST', $this->table, $payload, $this->sk() );
		}

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Save failed.' );
			return;
		}

		$this->bust_cache();
		$item = is_array( $res['data'] ) ? ( $res['data'][0] ?? $res['data'] ) : $res['data'];
		wp_send_json_success( $item );
	}

	public function ajax_delete(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		if ( ! $id || ! $this->is_uuid( $id ) ) {
			wp_send_json_error( 'Invalid container ID.' );
			return;
		}

		$res = $this->supa( 'DELETE', $this->table . '?id=eq.' . rawurlencode( $id ), [], $this->sk() );
		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Delete failed.' );
			return;
		}

		$this->bust_cache();
		wp_send_json_success( 'deleted' );
	}

	public function ajax_duplicate(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		if ( ! $id || ! $this->is_uuid( $id ) ) {
			wp_send_json_error( 'Invalid container ID.' );
			return;
		}

		$res = $this->supa( 'GET', $this->table . '?id=eq.' . rawurlencode( $id ) . '&select=*', [], $this->sk() );
		if ( ! $res['ok'] || empty( $res['data'] ) ) {
			wp_send_json_error( 'Original container not found.' );
			return;
		}

		$original = is_array( $res['data'] ) ? ( $res['data'][0] ?? [] ) : [];
		if ( empty( $original ) ) {
			wp_send_json_error( 'Failed to read original container.' );
			return;
		}

		$payload = $original;
		unset( $payload['id'], $payload['created_at'] );
		$payload['name'] = $original['name'] . ' (Copy)';
		$payload['is_active'] = false;

		$res = $this->supa( 'POST', $this->table, $payload, $this->sk() );
		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Duplicate failed.' );
			return;
		}

		$this->bust_cache();
		$item = is_array( $res['data'] ) ? ( $res['data'][0] ?? $res['data'] ) : $res['data'];
		wp_send_json_success( $item );
	}

	public function render_page(): void {
		?>
<div class="nw-panel nw-containers-panel">
	<div class="nw-panel-header">
		<div>
			<h1 class="nw-panel-title">📦 Containers</h1>
			<p class="nw-panel-subtitle">Manage item containers and slot rules.</p>
		</div>
		<div class="nw-header-actions">
			<button id="nw-refresh-btn" class="nw-btn nw-btn-ghost" title="Refresh">
				<i data-lucide="refresh-cw" style="width:14px;height:14px;vertical-align:middle;"></i>
			</button>
			<button id="nw-add-btn" class="nw-btn nw-btn-primary">+ New Container</button>
		</div>
	</div>

	<div id="nw-notice" class="nw-notice" style="display:none;"></div>

	<div class="nw-stats-bar">
		<span class="nw-stat-pill">Total: <strong id="nw-total">…</strong></span>
		<span class="nw-stat-pill nw-pill-active">Active: <strong id="nw-active">…</strong></span>
		<span class="nw-stat-pill nw-pill-inactive">Inactive: <strong id="nw-inactive">…</strong></span>
		<span class="nw-stat-pill nw-pill-rare">Rare+: <strong id="nw-rareplus">…</strong></span>
	</div>

	<div class="nw-filters-bar">
		<input id="nw-search" type="text" class="nw-search-input" placeholder="Search name, description, parent ID…">
		<select id="nw-filter-active" class="nw-select-filter">
			<option value="">All status</option>
			<option value="1">Active</option>
			<option value="0">Inactive</option>
		</select>
		<select id="nw-filter-rarity" class="nw-select-filter">
			<option value="">All rarity</option>
			<option value="common">Common</option>
			<option value="uncommon">Uncommon</option>
			<option value="rare">Rare</option>
			<option value="epic">Epic</option>
			<option value="legendary">Legendary</option>
		</select>
		<select id="nw-filter-size" class="nw-select-filter">
			<option value="">All sizes</option>
			<option value="tiny">Tiny</option>
			<option value="small">Small</option>
			<option value="medium">Medium</option>
			<option value="large">Large</option>
		</select>
		<button id="nw-clear-filters" class="nw-btn nw-btn-ghost nw-btn-sm" style="display:none;">✕ Clear</button>
	</div>

	<div class="nw-table-wrap">
		<table class="nw-table">
			<thead>
				<tr>
					<th style="width:52px;">Img</th>
					<th>Name</th>
					<th>Sizes</th>
					<th style="width:70px;">Slots</th>
					<th style="width:110px;">Rarity</th>
					<th>Parent item</th>
					<th style="width:72px;">Active</th>
					<th style="width:150px;">Actions</th>
				</tr>
			</thead>
			<tbody id="nw-containers-tbody">
				<tr class="nw-loading-row"><td colspan="8"><span class="nw-spinner"></span> Loading containers…</td></tr>
			</tbody>
		</table>
	</div>
</div>

<div id="nw-modal-overlay" class="nw-modal-overlay" style="display:none;">
	<div class="nw-modal">
		<div class="nw-modal-header">
			<h2 id="nw-modal-title">New Container</h2>
			<button id="nw-modal-close" class="nw-modal-close" aria-label="Close">✕</button>
		</div>
		<div class="nw-modal-body">
			<form id="nw-container-form" autocomplete="off">
				<input type="hidden" id="nw-field-id">

				<div class="nw-section-label">Basic Info</div>
				<div class="nw-form-grid">
					<div class="nw-field nw-field-full">
						<label for="nw-field-name">Name <span class="nw-req">*</span></label>
						<input type="text" id="nw-field-name" maxlength="120">
					</div>
					<div class="nw-field nw-field-full">
						<label for="nw-field-description">Description</label>
						<textarea id="nw-field-description" rows="3"></textarea>
					</div>
				</div>

				<div class="nw-section-label">Container Rules</div>
				<div class="nw-form-grid">
					<div class="nw-field">
						<label for="nw-field-total_slots">Total Slots</label>
						<input type="number" id="nw-field-total_slots" min="1" value="5">
					</div>
					<div class="nw-field">
						<label for="nw-field-rarity">Rarity</label>
						<select id="nw-field-rarity" class="nw-select">
							<option value="common">Common</option>
							<option value="uncommon">Uncommon</option>
							<option value="rare">Rare</option>
							<option value="epic">Epic</option>
							<option value="legendary">Legendary</option>
						</select>
					</div>
					<div class="nw-field nw-field-full">
						<label for="nw-field-allowed_sizes">Allowed Sizes <span class="nw-hint">comma-separated: tiny, small, medium, large</span></label>
						<input type="text" id="nw-field-allowed_sizes" value="tiny, small, medium, large">
					</div>
					<div class="nw-field nw-field-full">
						<label for="nw-field-parent_id">Parent Item ID <span class="nw-hint">UUID from cyber_items, optional</span></label>
						<input type="text" id="nw-field-parent_id" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
					</div>
				</div>

				<div class="nw-section-label">Image</div>
				<div class="nw-form-grid">
					<div class="nw-field nw-field-full">
						<label for="nw-field-img_url">Image URL</label>
						<input type="url" id="nw-field-img_url" placeholder="https://…">
					</div>
					<div id="nw-img-preview-wrap" class="nw-field-full" style="display:none;">
						<img id="nw-img-preview" src="" alt="Preview" style="max-height:120px;border-radius:6px;border:1px solid #2a2a2a;">
					</div>
				</div>

				<div class="nw-section-label">Status</div>
				<div class="nw-toggle-row">
					<label class="nw-toggle-label">
						<span class="nw-toggle">
							<input type="checkbox" id="nw-field-is_active" checked>
							<span class="nw-toggle-slider"></span>
						</span>
						Active
					</label>
				</div>
			</form>
		</div>
		<div class="nw-modal-footer">
			<button id="nw-delete-btn" class="nw-btn nw-btn-danger" style="display:none;margin-right:auto;">Delete</button>
			<button id="nw-cancel-btn" class="nw-btn nw-btn-ghost">Cancel</button>
			<button id="nw-save-btn" class="nw-btn nw-btn-primary"><span id="nw-save-label">Create Container</span></button>
		</div>
	</div>
</div>
		<?php
	}
}
