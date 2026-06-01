<?php
/**
 * NeoWeaver — Starting Packages Admin
 * Table: cyber_starting_packages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NWStartingPackagesAdmin {

	private string $page_slug    = 'nw-starting-packages';
	private string $nonce_action = 'nwpackagesnonce';
	private string $table        = 'cyber_starting_packages';
	private string $table_items  = 'cyber_items';

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_nwpackagesload',      [ $this, 'ajax_load' ] );
		add_action( 'wp_ajax_nwpackagesloaditems', [ $this, 'ajax_load_items' ] );
		add_action( 'wp_ajax_nwpackagessave',      [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nwpackagesdelete',    [ $this, 'ajax_delete' ] );
		add_action( 'wp_ajax_nwpackagesduplicate', [ $this, 'ajax_duplicate' ] );
	}

	// ── Menu ─────────────────────────────────────────────────────────────────
	public function register_menu(): void {
		$menu_parent = 'neoweaver';
		add_submenu_page(
			$menu_parent,
			__( 'Starting Packages', 'neoweaver' ),
			'<i data-lucide="package-check" style="width:14px;height:14px;vertical-align:-2px;margin-right:4px;"></i> Starting Packages',
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
		wp_enqueue_style( 'nw-admin-core',      NW_PLUGIN_URL . 'assets/css/admin/admin-core.css',      [ 'chakra-petch' ], NW_VERSION );
		wp_enqueue_style( 'nw-packages-style',  NW_PLUGIN_URL . 'assets/css/admin/starting-packages.css', [ 'chakra-petch', 'nw-admin-core' ], NW_VERSION );
		wp_enqueue_script( 'lucide', 'https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js', [], '0.468.0', true );
		wp_enqueue_script(
			'nw-packages-script',
			NW_PLUGIN_URL . 'assets/js/admin/starting-packages.js',
			[ 'jquery', 'lucide' ],
			NW_VERSION,
			true
		);
		wp_localize_script( 'nw-packages-script', 'NWPackages', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( $this->nonce_action ),
		] );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────
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

	private function get_cache_key( string $suffix ): string {
		return 'nw_' . md5( $suffix );
	}

	private function bust_cache(): void {
		delete_transient( $this->get_cache_key( $this->table . '_all' ) );
		delete_transient( $this->get_cache_key( $this->table_items . '_all' ) );
	}

	private function cached_get_all(): array {
		$cache_key = $this->get_cache_key( $this->table . '_all' );
		$cached    = get_transient( $cache_key );
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
		return (bool) preg_match( '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $value );
	}

	private function bool_from_post( string $key, bool $default = false ): bool {
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}
		return (bool) intval( wp_unslash( $_POST[ $key ] ) );
	}

	private function parse_json_field( string $raw ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	// ── AJAX ─────────────────────────────────────────────────────────────────
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

	public function ajax_load_items(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}
		$res = $this->supa( 'GET', $this->table_items . '?select=id,name,img_url,slot,type&order=name.asc', [], $this->sk() );
		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Failed to fetch items.' );
			return;
		}
		wp_send_json_success( is_array( $res['data'] ) ? $res['data'] : [] );
	}

	public function ajax_save(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id           = sanitize_text_field( wp_unslash( $_POST['id']           ?? '' ) );
		$package_name = sanitize_text_field( wp_unslash( $_POST['package_name'] ?? '' ) );
		$description  = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$base_armor   = max( 0, intval( wp_unslash( $_POST['base_armor'] ?? 0 ) ) );

		// UUID foreign keys (equipment slots)
		$slot_fields = [ 'head_item_id', 'torso_item_id', 'hand_r_item_id', 'hand_l_item_id', 'belt_item_id' ];
		$slots = [];
		foreach ( $slot_fields as $field ) {
			$val = sanitize_text_field( wp_unslash( $_POST[ $field ] ?? '' ) );
			$slots[ $field ] = ( '' !== $val && $this->is_uuid( $val ) ) ? $val : null;
		}

		// JSON fields
		$items_list        = $this->parse_json_field( wp_unslash( $_POST['items_list']        ?? '' ) );
		$compatibility_tags = $this->parse_json_field( wp_unslash( $_POST['compatibility_tags'] ?? '' ) );
		$attack_cards_pool  = $this->parse_json_field( wp_unslash( $_POST['attack_cards_pool']  ?? '' ) );
		$defense_cards_pool = $this->parse_json_field( wp_unslash( $_POST['defense_cards_pool'] ?? '' ) );
		$compatible_class_ids = $this->parse_json_field( wp_unslash( $_POST['compatible_class_ids'] ?? '' ) );

		$is_player_selectable = $this->bool_from_post( 'is_player_selectable', false );

		if ( ! $package_name ) {
			wp_send_json_error( 'Package name is required.' );
			return;
		}
		if ( $id && ! $this->is_uuid( $id ) ) {
			wp_send_json_error( 'Invalid package ID.' );
			return;
		}

		$payload = array_merge( [
			'package_name'          => $package_name,
			'description'           => '' !== $description ? $description : null,
			'base_armor'            => $base_armor,
			'items_list'            => $items_list,
			'compatibility_tags'    => $compatibility_tags,
			'attack_cards_pool'     => $attack_cards_pool,
			'defense_cards_pool'    => $defense_cards_pool,
			'compatible_class_ids'  => $compatible_class_ids,
			'is_player_selectable'  => $is_player_selectable,
		], $slots );

		if ( ! $id ) {
			$res = $this->supa( 'POST', $this->table, $payload, $this->sk() );
		} else {
			$res = $this->supa( 'PATCH', $this->table . '?id=eq.' . rawurlencode( $id ), $payload, $this->sk() );
		}

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Save failed.' );
			return;
		}
		$this->bust_cache();
		$row = is_array( $res['data'] ) ? ( $res['data'][0] ?? $res['data'] ) : $res['data'];
		wp_send_json_success( $row );
	}

	public function ajax_delete(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}
		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		if ( ! $id || ! $this->is_uuid( $id ) ) {
			wp_send_json_error( 'Invalid package ID.' );
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
			wp_send_json_error( 'Invalid package ID.' );
			return;
		}
		$res = $this->supa( 'GET', $this->table . '?id=eq.' . rawurlencode( $id ) . '&select=*', [], $this->sk() );
		if ( ! $res['ok'] || empty( $res['data'] ) ) {
			wp_send_json_error( 'Original package not found.' );
			return;
		}
		$original = is_array( $res['data'] ) ? ( $res['data'][0] ?? [] ) : [];
		if ( empty( $original ) ) {
			wp_send_json_error( 'Failed to read original package.' );
			return;
		}
		$payload = $original;
		unset( $payload['id'], $payload['created_at'] );
		$payload['package_name']         = $original['package_name'] . ' (Copy)';
		$payload['is_player_selectable'] = false;

		$dup_res = $this->supa( 'POST', $this->table, $payload, $this->sk() );
		if ( ! $dup_res['ok'] ) {
			wp_send_json_error( $dup_res['error'] ?? 'Duplicate failed.' );
			return;
		}
		$this->bust_cache();
		$row = is_array( $dup_res['data'] ) ? ( $dup_res['data'][0] ?? $dup_res['data'] ) : $dup_res['data'];
		wp_send_json_success( $row );
	}

	// ── Render ───────────────────────────────────────────────────────────────
	public function render_page(): void {
		?>
<div class="wrap nw-packages-panel">

	<div class="nw-admin-header">
		<div class="nw-admin-header-left">
			<i data-lucide="package-check" class="nw-header-icon"></i>
			<div>
				<h1 class="nw-admin-title">Starting Packages</h1>
				<p class="nw-admin-subtitle">Equipment sets assigned to characters at game start</p>
			</div>
		</div>
		<button id="nw-add-btn" class="nw-btn nw-btn-primary">
			<i data-lucide="plus"></i> New Package
		</button>
	</div>

	<div id="nw-notice" class="nw-notice" style="display:none;"></div>

	<div class="nw-stats-bar">
		<div class="nw-stat-card"><span class="nw-stat-value" id="nw-total">—</span><span class="nw-stat-label">Total</span></div>
		<div class="nw-stat-card"><span class="nw-stat-value nw-stat-green" id="nw-selectable">—</span><span class="nw-stat-label">Player Selectable</span></div>
		<div class="nw-stat-card"><span class="nw-stat-value" id="nw-with-slots">—</span><span class="nw-stat-label">With Equipment</span></div>
		<div class="nw-stat-card"><span class="nw-stat-value" id="nw-with-classes">—</span><span class="nw-stat-label">Class-locked</span></div>
	</div>

	<div class="nw-filters-bar">
		<div class="nw-search-wrap">
			<i data-lucide="search" class="nw-search-icon"></i>
			<input type="text" id="nw-search" class="nw-input" placeholder="Search packages…">
		</div>
		<select id="nw-filter-selectable" class="nw-select">
			<option value="">All visibility</option>
			<option value="1">Player selectable</option>
			<option value="0">GM only</option>
		</select>
		<select id="nw-filter-armor" class="nw-select">
			<option value="">All armor</option>
			<option value="0">No armor (0)</option>
			<option value="1">Has armor (>0)</option>
		</select>
		<button id="nw-clear-filters" class="nw-btn nw-btn-ghost">
			<i data-lucide="x"></i> Clear
		</button>
	</div>

	<div class="nw-table-wrap">
		<table class="nw-table">
			<thead>
				<tr>
					<th>Package</th>
					<th>Armor</th>
					<th>Equipment Slots</th>
					<th>Cards Pool</th>
					<th>Classes</th>
					<th>Player?</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody id="nw-packages-tbody">
				<tr><td colspan="7" class="nw-loading"><i data-lucide="loader-2" class="nw-spin"></i> Loading packages…</td></tr>
			</tbody>
		</table>
	</div>

</div>

<!-- ── MODAL ──────────────────────────────────────────────────────────── -->
<div id="nw-modal" class="nw-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="nw-modal-title">
	<div class="nw-modal nw-modal-wide">
		<div class="nw-modal-header">
			<h2 id="nw-modal-title">New Package</h2>
			<button id="nw-modal-close" class="nw-modal-close" aria-label="Close"><i data-lucide="x"></i></button>
		</div>
		<div class="nw-modal-body">
			<input type="hidden" id="nw-field-id">

			<!-- Row 1 -->
			<div class="nw-form-row nw-form-cols-2">
				<div class="nw-form-group">
					<label for="nw-field-package_name">Package Name <span class="nw-required">*</span></label>
					<input type="text" id="nw-field-package_name" class="nw-input" placeholder="e.g. Hacker Starter Kit">
				</div>
				<div class="nw-form-group">
					<label for="nw-field-base_armor">Base Armor</label>
					<input type="number" id="nw-field-base_armor" class="nw-input" min="0" value="0">
				</div>
			</div>

			<!-- Description -->
			<div class="nw-form-group">
				<label for="nw-field-description">Description</label>
				<textarea id="nw-field-description" class="nw-textarea" rows="2" placeholder="Short description of this package…"></textarea>
			</div>

			<!-- Equipment slots -->
			<div class="nw-section-label"><i data-lucide="shield"></i> Equipment Slots</div>
			<div class="nw-form-row nw-form-cols-3">
				<div class="nw-form-group">
					<label for="nw-field-head_item_id">Head</label>
					<select id="nw-field-head_item_id" class="nw-select nw-item-select" data-slot="head"></select>
				</div>
				<div class="nw-form-group">
					<label for="nw-field-torso_item_id">Torso</label>
					<select id="nw-field-torso_item_id" class="nw-select nw-item-select" data-slot="torso"></select>
				</div>
				<div class="nw-form-group">
					<label for="nw-field-belt_item_id">Belt</label>
					<select id="nw-field-belt_item_id" class="nw-select nw-item-select" data-slot="belt"></select>
				</div>
			</div>
			<div class="nw-form-row nw-form-cols-2">
				<div class="nw-form-group">
					<label for="nw-field-hand_r_item_id">Hand Right</label>
					<select id="nw-field-hand_r_item_id" class="nw-select nw-item-select" data-slot="hand_r"></select>
				</div>
				<div class="nw-form-group">
					<label for="nw-field-hand_l_item_id">Hand Left</label>
					<select id="nw-field-hand_l_item_id" class="nw-select nw-item-select" data-slot="hand_l"></select>
				</div>
			</div>

			<!-- JSON fields -->
			<div class="nw-section-label"><i data-lucide="list"></i> JSON Fields</div>
			<div class="nw-form-row nw-form-cols-2">
				<div class="nw-form-group">
					<label for="nw-field-items_list">Items List
						<span class="nw-field-hint">JSON array of item IDs / objects</span>
					</label>
					<textarea id="nw-field-items_list" class="nw-textarea nw-json-field" rows="3" placeholder='["uuid1","uuid2"]'></textarea>
				</div>
				<div class="nw-form-group">
					<label for="nw-field-compatibility_tags">Compatibility Tags
						<span class="nw-field-hint">JSON array of tag strings</span>
					</label>
					<textarea id="nw-field-compatibility_tags" class="nw-textarea nw-json-field" rows="3" placeholder='["stealth","hacker"]'></textarea>
				</div>
			</div>
			<div class="nw-form-row nw-form-cols-2">
				<div class="nw-form-group">
					<label for="nw-field-attack_cards_pool">Attack Cards Pool
						<span class="nw-field-hint">JSON array</span>
					</label>
					<textarea id="nw-field-attack_cards_pool" class="nw-textarea nw-json-field" rows="3" placeholder='["card_id_1"]'></textarea>
				</div>
				<div class="nw-form-group">
					<label for="nw-field-defense_cards_pool">Defense Cards Pool
						<span class="nw-field-hint">JSON array</span>
					</label>
					<textarea id="nw-field-defense_cards_pool" class="nw-textarea nw-json-field" rows="3" placeholder='["card_id_2"]'></textarea>
				</div>
			</div>
			<div class="nw-form-group">
				<label for="nw-field-compatible_class_ids">Compatible Class IDs
					<span class="nw-field-hint">JSON array of class UUIDs — empty = all classes</span>
				</label>
				<textarea id="nw-field-compatible_class_ids" class="nw-textarea nw-json-field" rows="2" placeholder='["uuid-class-1","uuid-class-2"]'></textarea>
			</div>

			<!-- Flags -->
			<div class="nw-form-group nw-checkbox-row">
				<label class="nw-checkbox-label">
					<input type="checkbox" id="nw-field-is_player_selectable">
					<span>Player selectable (visible in character creation)</span>
				</label>
			</div>
		</div>
		<div class="nw-modal-footer">
			<button id="nw-modal-cancel" class="nw-btn nw-btn-ghost">Cancel</button>
			<button id="nw-modal-save"   class="nw-btn nw-btn-primary">
				<i data-lucide="save"></i> Save Package
			</button>
		</div>
	</div>
</div>
		<?php
	}
}
