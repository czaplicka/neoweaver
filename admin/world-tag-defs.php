<?php
/**
 * NeoWeaver — World Tag Definitions Admin
 * Table: cyber_world_tag_defs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NWWorldTagDefsAdmin {

	private string $page_slug    = 'nw-world-tag-defs';
	private string $nonce_action = 'nwworldtagdefs nonce';
	private string $table        = 'cyber_world_tag_defs';

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_nwwtagdefsload',      [ $this, 'ajax_load' ] );
		add_action( 'wp_ajax_nwwtagdefssave',      [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nwwtagdefsdelete',    [ $this, 'ajax_delete' ] );
		add_action( 'wp_ajax_nwwtagdefsduplicate', [ $this, 'ajax_duplicate' ] );
	}

	// ── Menu ─────────────────────────────────────────────────────────────────
	public function register_menu(): void {
		add_submenu_page(
			'nw-dashboard',
			__( 'World Tag Defs', 'neoweaver' ),
			'<i data-lucide="tag" style="width:14px;height:14px;vertical-align:-2px;margin-right:4px;"></i> World Tag Defs',
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	// ── Assets ───────────────────────────────────────────────────────────────
	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, $this->page_slug ) ) {
			return;
		}
		if ( ! wp_style_is( 'chakra-petch', 'enqueued' ) ) {
			wp_enqueue_style( 'chakra-petch', 'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap', [], null );
		}
		wp_enqueue_style( 'nw-admin-core',      NW_PLUGIN_URL . 'assets/css/admin/admin-core.css',       [ 'chakra-petch' ], NW_VERSION );
		wp_enqueue_style( 'nw-wtagdefs-style',  NW_PLUGIN_URL . 'assets/css/admin/world-tag-defs.css',   [ 'chakra-petch', 'nw-admin-core' ], NW_VERSION );
		wp_enqueue_script( 'lucide', 'https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js', [], '0.468.0', true );
		wp_enqueue_script(
			'nw-wtagdefs-script',
			NW_PLUGIN_URL . 'assets/js/admin/world-tag-defs.js',
			[ 'jquery', 'lucide' ],
			NW_VERSION,
			true
		);
		wp_localize_script( 'nw-wtagdefs-script', 'NWWorldTagDefs', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( $this->nonce_action ),
		] );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────
	private function sk(): array {
		if ( ! defined( 'TW_SUPABASE_SERVICE_KEY' ) ) { return []; }
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
			if ( $qs ) { parse_str( $qs, $query ); }
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
			if ( $qs ) { parse_str( $qs, $query ); }
			$extra_args = [];
			if ( in_array( $method, [ 'POST', 'PATCH' ], true ) ) {
				$extra_args['headers']['Prefer'] = 'return=representation';
			}
			if ( ! empty( $extra_headers ) ) {
				$extra_args['headers'] = array_merge( $extra_args['headers'] ?? [], $extra_headers );
			}
			$res  = tw_supabase_request( $method, $table, $query, empty( $body ) ? null : $body, $extra_args );
			$ok   = $res['ok']   ?? false;
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

	private function get_cache_key(): string {
		return 'nw_' . md5( $this->table . '_all' );
	}

	private function bust_cache(): void {
		delete_transient( $this->get_cache_key() );
	}

	private function cached_get_all(): array {
		$cached = get_transient( $this->get_cache_key() );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}
		$res = $this->supa( 'GET', $this->table . '?select=*&order=sort_order.asc.nullslast,code.asc', [], $this->sk() );
		if ( ! $res['ok'] ) {
			return [ 'error' => $res['error'] ?? 'Failed to fetch records.' ];
		}
		$rows = is_array( $res['data'] ) ? $res['data'] : [];
		set_transient( $this->get_cache_key(), $rows, MINUTE_IN_SECONDS * 5 );
		return $rows;
	}

	private function bool_from_post( string $key, bool $default = false ): bool {
		if ( ! isset( $_POST[ $key ] ) ) { return $default; }
		return (bool) intval( wp_unslash( $_POST[ $key ] ) );
	}

	private function sanitize_hex( string $val ): string {
		$val = strtolower( trim( $val ) );
		return preg_match( '/^#[0-9a-f]{6}$/', $val ) ? $val : '#adff00';
	}

	// ── AJAX ─────────────────────────────────────────────────────────────────
	public function ajax_load(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }
		$rows = $this->cached_get_all();
		if ( isset( $rows['error'] ) ) { wp_send_json_error( $rows['error'] ); return; }
		wp_send_json_success( $rows );
	}

	public function ajax_save(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$id          = intval( wp_unslash( $_POST['id'] ?? 0 ) );
		$code        = sanitize_text_field( wp_unslash( $_POST['code']        ?? '' ) );
		$label       = sanitize_text_field( wp_unslash( $_POST['label']       ?? '' ) );
		$icon        = sanitize_text_field( wp_unslash( $_POST['icon']        ?? '' ) );
		$color       = sanitize_text_field( wp_unslash( $_POST['color']       ?? '#adff00' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$category    = sanitize_text_field( wp_unslash( $_POST['category']    ?? '' ) );
		$source      = sanitize_text_field( wp_unslash( $_POST['source']      ?? 'system' ) );
		$sort_order  = $_POST['sort_order'] !== '' ? intval( wp_unslash( $_POST['sort_order'] ?? 0 ) ) : null;
		$impact      = $_POST['impact'] !== ''     ? floatval( wp_unslash( $_POST['impact']  ?? 0 ) ) : 0;
		$is_active   = $this->bool_from_post( 'is_active', true );

		if ( ! $code )  { wp_send_json_error( 'Code is required.' );  return; }
		if ( ! $label ) { wp_send_json_error( 'Label is required.' ); return; }

		$payload = [
			'code'        => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '_', trim( $code ) ) ),
			'label'       => $label,
			'icon'        => $icon ?: null,
			'color'       => $this->sanitize_hex( $color ),
			'description' => $description ?: null,
			'category'    => $category ?: null,
			'source'      => $source ?: 'system',
			'sort_order'  => $sort_order,
			'impact'      => $impact,
			'is_active'   => $is_active,
		];

		if ( ! $id ) {
			$res = $this->supa( 'POST', $this->table, $payload, $this->sk() );
		} else {
			$res = $this->supa( 'PATCH', $this->table . '?id=eq.' . $id, $payload, $this->sk() );
		}

		if ( ! $res['ok'] ) { wp_send_json_error( $res['error'] ?? 'Save failed.' ); return; }
		$this->bust_cache();
		$row = is_array( $res['data'] ) ? ( $res['data'][0] ?? $res['data'] ) : $res['data'];
		wp_send_json_success( $row );
	}

	public function ajax_delete(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }
		$id = intval( wp_unslash( $_POST['id'] ?? 0 ) );
		if ( ! $id ) { wp_send_json_error( 'Invalid ID.' ); return; }
		$res = $this->supa( 'DELETE', $this->table . '?id=eq.' . $id, [], $this->sk() );
		if ( ! $res['ok'] ) { wp_send_json_error( $res['error'] ?? 'Delete failed.' ); return; }
		$this->bust_cache();
		wp_send_json_success( 'deleted' );
	}

	public function ajax_duplicate(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }
		$id = intval( wp_unslash( $_POST['id'] ?? 0 ) );
		if ( ! $id ) { wp_send_json_error( 'Invalid ID.' ); return; }

		$res = $this->supa( 'GET', $this->table . '?id=eq.' . $id . '&select=*', [], $this->sk() );
		if ( ! $res['ok'] || empty( $res['data'] ) ) { wp_send_json_error( 'Original not found.' ); return; }
		$original = is_array( $res['data'] ) ? ( $res['data'][0] ?? [] ) : [];

		$payload = $original;
		unset( $payload['id'], $payload['created_at'] );
		$payload['code']      = $original['code'] . '_copy';
		$payload['label']     = $original['label'] . ' (Copy)';
		$payload['is_active'] = false;

		$dup = $this->supa( 'POST', $this->table, $payload, $this->sk() );
		if ( ! $dup['ok'] ) { wp_send_json_error( $dup['error'] ?? 'Duplicate failed.' ); return; }
		$this->bust_cache();
		$row = is_array( $dup['data'] ) ? ( $dup['data'][0] ?? $dup['data'] ) : $dup['data'];
		wp_send_json_success( $row );
	}

	// ── Render ───────────────────────────────────────────────────────────────
	public function render_page(): void {
		?>
<div class="wrap nw-wtagdefs-panel">

	<div class="nw-admin-header">
		<div class="nw-admin-header-left">
			<i data-lucide="tag" class="nw-header-icon"></i>
			<div>
				<h1 class="nw-admin-title">World Tag Definitions</h1>
				<p class="nw-admin-subtitle">Define tags that shape world nodes — tech level, atmosphere, threats and more</p>
			</div>
		</div>
		<button id="nw-add-btn" class="nw-btn nw-btn-primary">
			<i data-lucide="plus"></i> New Tag Def
		</button>
	</div>

	<div id="nw-notice" class="nw-notice" style="display:none;"></div>

	<div class="nw-stats-bar">
		<div class="nw-stat-card"><span class="nw-stat-value" id="nw-total">—</span><span class="nw-stat-label">Total</span></div>
		<div class="nw-stat-card"><span class="nw-stat-value nw-stat-system" id="nw-stat-system">—</span><span class="nw-stat-label">System</span></div>
		<div class="nw-stat-card"><span class="nw-stat-value nw-stat-custom" id="nw-stat-custom">—</span><span class="nw-stat-label">Custom</span></div>
		<div class="nw-stat-card"><span class="nw-stat-value nw-stat-impact" id="nw-stat-nonzero">—</span><span class="nw-stat-label">With Impact</span></div>
		<div class="nw-stat-card"><span class="nw-stat-value nw-stat-muted" id="nw-inactive">—</span><span class="nw-stat-label">Inactive</span></div>
	</div>

	<div class="nw-filters-bar">
		<div class="nw-search-wrap">
			<i data-lucide="search" class="nw-search-icon"></i>
			<input type="text" id="nw-search" class="nw-input" placeholder="Search code, label or category…">
		</div>
		<select id="nw-filter-source" class="nw-select">
			<option value="">All sources</option>
			<option value="system">System</option>
			<option value="custom">Custom</option>
		</select>
		<select id="nw-filter-active" class="nw-select">
			<option value="">All status</option>
			<option value="1">Active</option>
			<option value="0">Inactive</option>
		</select>
		<button id="nw-clear-filters" class="nw-btn nw-btn-ghost">
			<i data-lucide="x"></i> Clear
		</button>
	</div>

	<div class="nw-table-wrap">
		<table class="nw-table">
			<thead>
				<tr>
					<th>Code</th>
					<th>Label</th>
					<th>Category</th>
					<th>Impact</th>
					<th>Sort</th>
					<th>Source</th>
					<th>Active</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody id="nw-wtagdefs-tbody">
				<tr><td colspan="8" class="nw-loading"><i data-lucide="loader-2" class="nw-spin"></i> Loading…</td></tr>
			</tbody>
		</table>
	</div>

</div>

<!-- ── MODAL ──────────────────────────────────────────────────────────── -->
<div id="nw-modal" class="nw-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="nw-modal-title">
	<div class="nw-modal nw-modal-wide">
		<div class="nw-modal-header">
			<h2 id="nw-modal-title">New Tag Definition</h2>
			<button id="nw-modal-close" class="nw-modal-close" aria-label="Close"><i data-lucide="x"></i></button>
		</div>
		<div class="nw-modal-body">
			<input type="hidden" id="nw-field-id">

			<div class="nw-form-row nw-form-cols-2">
				<div class="nw-form-group">
					<label for="nw-field-code">Code <span class="nw-required">*</span>
						<span class="nw-field-hint">lowercase, unique</span></label>
					<input type="text" id="nw-field-code" class="nw-input nw-mono-input" placeholder="e.g. high_tech">
				</div>
				<div class="nw-form-group">
					<label for="nw-field-label">Label <span class="nw-required">*</span></label>
					<input type="text" id="nw-field-label" class="nw-input" placeholder="e.g. High Technology">
				</div>
			</div>

			<div class="nw-form-row nw-form-cols-3">
				<div class="nw-form-group">
					<label for="nw-field-category">Category</label>
					<input type="text" id="nw-field-category" class="nw-input" placeholder="e.g. tech, social, threat">
				</div>
				<div class="nw-form-group">
					<label for="nw-field-source">Source</label>
					<select id="nw-field-source" class="nw-select">
						<option value="system">system</option>
						<option value="custom">custom</option>
					</select>
				</div>
				<div class="nw-form-group">
					<label for="nw-field-sort_order">Sort Order</label>
					<input type="number" id="nw-field-sort_order" class="nw-input" placeholder="e.g. 10" min="0" step="1">
				</div>
			</div>

			<div class="nw-form-row nw-form-cols-3">
				<div class="nw-form-group">
					<label for="nw-field-icon">Icon <span class="nw-field-hint">Lucide name</span></label>
					<div class="nw-icon-row">
						<input type="text" id="nw-field-icon" class="nw-input nw-mono-input" placeholder="e.g. zap">
						<span id="nw-icon-preview" class="nw-icon-preview" title="Icon preview"></span>
					</div>
				</div>
				<div class="nw-form-group">
					<label for="nw-field-color">Color</label>
					<div class="nw-color-row">
						<input type="color" id="nw-field-color_picker" class="nw-color-picker" value="#adff00">
						<input type="text"  id="nw-field-color" class="nw-input nw-mono-input nw-color-text" value="#adff00" maxlength="7" placeholder="#adff00">
					</div>
				</div>
				<div class="nw-form-group">
					<label for="nw-field-impact">Impact <span class="nw-field-hint">numeric modifier</span></label>
					<input type="number" id="nw-field-impact" class="nw-input" value="0" step="0.1">
				</div>
			</div>

			<div class="nw-form-group">
				<label for="nw-field-description">Description</label>
				<textarea id="nw-field-description" class="nw-textarea" rows="3"
					placeholder="What does this tag mean for the world node? How does it affect gameplay, atmosphere, encounters?"></textarea>
			</div>

			<div class="nw-form-group nw-checkbox-row">
				<label class="nw-checkbox-label">
					<input type="checkbox" id="nw-field-is_active" checked>
					<span>Active (available for world nodes)</span>
				</label>
			</div>
		</div>
		<div class="nw-modal-footer">
			<button id="nw-modal-cancel" class="nw-btn nw-btn-ghost">Cancel</button>
			<button id="nw-modal-save"   class="nw-btn nw-btn-primary">
				<i data-lucide="save"></i> Save Tag Def
			</button>
		</div>
	</div>
</div>
		<?php
	}
}
