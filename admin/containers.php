<?php
/**
 * NeoWeaver — Containers Admin
 * Table: cyber_containers
 *
 * Tworzenie kontenera automatycznie tworzy powiązany item (cyber_items)
 * z is_container=true. parent_id kontenera = id created item.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NW_Containers_Admin {

	private string $page_slug    = 'nw-containers';
	private string $menu_parent  = 'neoweaver';
	private string $table        = 'cyber_containers';
	private string $table_items  = 'cyber_items';
	private string $nonce_action = 'nw_containers_nonce';

	private const RARITIES = [ 'common', 'uncommon', 'rare', 'epic', 'legendary' ];
	private const SIZES    = [ 'tiny', 'small', 'medium', 'large' ];

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_nw_containers_load',           [ $this, 'ajax_load' ] );
		add_action( 'wp_ajax_nw_containers_save',           [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_containers_delete',         [ $this, 'ajax_delete' ] );
		add_action( 'wp_ajax_nw_containers_duplicate',      [ $this, 'ajax_duplicate' ] );
		add_action( 'wp_ajax_nw_containers_get_items',      [ $this, 'ajax_get_items' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			$this->menu_parent,
			__( 'Containers', 'neoweaver' ),
			'<span data-lucide-menu="backpack"></span> Containers',
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

		wp_enqueue_style( 'nw-admin-core', NW_PLUGIN_URL . 'assets/css/admin/admin-core.css', [ 'chakra-petch' ], NW_VERSION );
		wp_enqueue_style( 'nw-containers-style', NW_PLUGIN_URL . 'assets/css/admin/containers.css', [ 'chakra-petch', 'nw-admin-core' ], NW_VERSION );

		wp_enqueue_script( 'lucide', 'https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js', [], '0.468.0', true );

		wp_enqueue_script(
			'nw-containers-script',
			NW_PLUGIN_URL . 'assets/js/admin/containers.js',
			[ 'jquery', 'lucide' ],
			NW_VERSION,
			true
		);

		wp_localize_script( 'nw-containers-script', 'NWContainers', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( $this->nonce_action ),
		] );
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

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
		$parts = array_map( 'trim', explode( ',', strtolower( $raw ) ) );
		$parts = array_values( array_filter( array_unique( $parts ) ) );
		$parts = array_values( array_filter( $parts, static fn( $v ) => in_array( $v, self::SIZES, true ) ) );
		return empty( $parts ) ? self::SIZES : $parts;
	}

	private function sanitize_rarity( string $rarity ): string {
		$rarity = strtolower( trim( $rarity ) );
		return in_array( $rarity, self::RARITIES, true ) ? $rarity : 'common';
	}

	// ── AJAX ───────────────────────────────────────────────────────────────────

	public function ajax_load(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$rows = $this->cached_get_all();
		if ( isset( $rows['error'] ) ) { wp_send_json_error( $rows['error'] ); return; }
		wp_send_json_success( $rows );
	}

	/**
	 * Returns cyber_items where is_container = true OR is_container IS null
	 * (items eligible to be a parent of a container).
	 * Also returns ALL container-items so the dropdown is pre-populated.
	 */
	public function ajax_get_items(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$res = $this->supa(
			'GET',
			$this->table_items . '?select=id,name,rarity,img_url&is_container=eq.true&order=name.asc',
			[],
			$this->sk()
		);

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Failed to fetch items.' );
			return;
		}

		wp_send_json_success( is_array( $res['data'] ) ? $res['data'] : [] );
	}

	/**
	 * Save container.
	 *
	 * Tryb tworzenia (brak $id):
	 *   1. Utwórz item w cyber_items (is_container=true) — name, description, rarity, img_url, is_active
	 *   2. Użyj zwróconego item.id jako parent_id kontenera
	 *   3. Utwórz rekord w cyber_containers
	 *
	 * Tryb edycji ($id podany):
	 *   - Aktualizuj cyber_containers
	 *   - Jeśli parent_id istnieje → zsynchronizuj name/description/rarity/img_url/is_active do cyber_items
	 */
	public function ajax_save(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$id          = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$name        = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$img_url     = esc_url_raw( wp_unslash( $_POST['img_url'] ?? '' ) );
		$rarity      = $this->sanitize_rarity( sanitize_text_field( wp_unslash( $_POST['rarity'] ?? 'common' ) ) );
		$total_slots = max( 1, intval( wp_unslash( $_POST['total_slots'] ?? 5 ) ) );
		$is_active   = $this->bool_from_post( 'is_active', true );
		$sizes_raw   = sanitize_text_field( wp_unslash( $_POST['allowed_sizes'] ?? '' ) );
		// parent_id: może przyjść z dropdownu (istniejący item) lub zostanie auto-created
		$parent_id   = sanitize_text_field( wp_unslash( $_POST['parent_id'] ?? '' ) );

		if ( ! $name ) { wp_send_json_error( 'Name is required.' ); return; }
		if ( $id && ! $this->is_uuid( $id ) ) { wp_send_json_error( 'Invalid container ID.' ); return; }
		if ( $parent_id && ! $this->is_uuid( $parent_id ) ) { wp_send_json_error( 'Parent item ID must be a valid UUID.' ); return; }

		$container_payload = [
			'name'          => $name,
			'description'   => '' !== $description ? $description : null,
			'total_slots'   => $total_slots,
			'allowed_sizes' => $this->parse_allowed_sizes( $sizes_raw ),
			'img_url'       => '' !== $img_url ? $img_url : null,
			'rarity'        => $rarity,
			'is_active'     => $is_active,
		];

		$item_payload = [
			'name'         => $name,
			'description'  => '' !== $description ? $description : null,
			'type'         => 'container',
			'rarity'       => $rarity,
			'img_url'      => '' !== $img_url ? $img_url : null,
			'is_container' => true,
			'is_active'    => $is_active,
			'slot'         => 'none',
			'tags'         => [],
		];

		if ( ! $id ) {
			// ── TWORZENIE: najpierw item, potem container ──────────────────────

			// Jeśli admin wybrał istniejący item z dropdownu — nie tworzymy nowego
			if ( '' === $parent_id ) {
				$item_res = $this->supa( 'POST', $this->table_items, $item_payload, $this->sk() );
				if ( ! $item_res['ok'] ) {
					wp_send_json_error( 'Failed to create item: ' . ( $item_res['error'] ?? 'unknown error' ) );
					return;
				}
				$created_item      = is_array( $item_res['data'] ) ? ( $item_res['data'][0] ?? $item_res['data'] ) : $item_res['data'];
				$parent_id         = $created_item['id'] ?? '';
				if ( ! $parent_id ) {
					wp_send_json_error( 'Item created but ID not returned.' );
					return;
				}
			}

			$container_payload['parent_id'] = $parent_id;

			$res = $this->supa( 'POST', $this->table, $container_payload, $this->sk() );

		} else {
			// ── EDYCJA ────────────────────────────────────────────────────────

			if ( '' !== $parent_id ) {
				$container_payload['parent_id'] = $parent_id;
				// Sync item
				$this->supa( 'PATCH', $this->table_items . '?id=eq.' . rawurlencode( $parent_id ), $item_payload, $this->sk() );
			}

			$res = $this->supa( 'PATCH', $this->table . '?id=eq.' . rawurlencode( $id ), $container_payload, $this->sk() );
		}

		if ( ! $res['ok'] ) { wp_send_json_error( $res['error'] ?? 'Save failed.' ); return; }

		$this->bust_cache();
		$row = is_array( $res['data'] ) ? ( $res['data'][0] ?? $res['data'] ) : $res['data'];
		// Dołącz parent_id do odpowiedzi żeby JS mógł zaktualizować wiersz
		if ( is_array( $row ) && ! isset( $row['parent_id'] ) && '' !== $parent_id ) {
			$row['parent_id'] = $parent_id;
		}
		wp_send_json_success( $row );
	}

	public function ajax_delete(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$id          = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$delete_item = $this->bool_from_post( 'delete_item', false );

		if ( ! $id || ! $this->is_uuid( $id ) ) { wp_send_json_error( 'Invalid container ID.' ); return; }

		// Opcjonalnie: usuń też powiązany item
		if ( $delete_item ) {
			$get = $this->supa( 'GET', $this->table . '?id=eq.' . rawurlencode( $id ) . '&select=parent_id', [], $this->sk() );
			if ( $get['ok'] && ! empty( $get['data'][0]['parent_id'] ) ) {
				$this->supa( 'DELETE', $this->table_items . '?id=eq.' . rawurlencode( $get['data'][0]['parent_id'] ), [], $this->sk() );
			}
		}

		$res = $this->supa( 'DELETE', $this->table . '?id=eq.' . rawurlencode( $id ), [], $this->sk() );
		if ( ! $res['ok'] ) { wp_send_json_error( $res['error'] ?? 'Delete failed.' ); return; }

		$this->bust_cache();
		wp_send_json_success( 'deleted' );
	}

	public function ajax_duplicate(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		if ( ! $id || ! $this->is_uuid( $id ) ) { wp_send_json_error( 'Invalid container ID.' ); return; }

		$res = $this->supa( 'GET', $this->table . '?id=eq.' . rawurlencode( $id ) . '&select=*', [], $this->sk() );
		if ( ! $res['ok'] || empty( $res['data'] ) ) { wp_send_json_error( 'Original container not found.' ); return; }

		$original = is_array( $res['data'] ) ? ( $res['data'][0] ?? [] ) : [];
		if ( empty( $original ) ) { wp_send_json_error( 'Failed to read original container.' ); return; }

		// Nowy item dla kopii
		$item_res = $this->supa( 'POST', $this->table_items, [
			'name'         => $original['name'] . ' (Copy)',
			'description'  => $original['description'] ?? null,
			'type'         => 'container',
			'rarity'       => $original['rarity'] ?? 'common',
			'img_url'      => $original['img_url'] ?? null,
			'is_container' => true,
			'is_active'    => false,
			'slot'         => 'none',
			'tags'         => [],
		], $this->sk() );

		$new_parent_id = null;
		if ( $item_res['ok'] ) {
			$new_item      = is_array( $item_res['data'] ) ? ( $item_res['data'][0] ?? $item_res['data'] ) : $item_res['data'];
			$new_parent_id = $new_item['id'] ?? null;
		}

		$payload = $original;
		unset( $payload['id'], $payload['created_at'] );
		$payload['name']      = $original['name'] . ' (Copy)';
		$payload['is_active'] = false;
		if ( $new_parent_id ) {
			$payload['parent_id'] = $new_parent_id;
		}

		$dup_res = $this->supa( 'POST', $this->table, $payload, $this->sk() );
		if ( ! $dup_res['ok'] ) { wp_send_json_error( $dup_res['error'] ?? 'Duplicate failed.' ); return; }

		$this->bust_cache();
		$row = is_array( $dup_res['data'] ) ? ( $dup_res['data'][0] ?? $dup_res['data'] ) : $dup_res['data'];
		wp_send_json_success( $row );
	}

	// ── Render ─────────────────────────────────────────────────────────────────

	public function render_page(): void {
		?>
<div class="nw-panel nw-containers-panel">
	<div class="nw-panel-header">
		<div>
			<h1 class="nw-panel-title">
				<i data-lucide="backpack" style="width:22px;height:22px;vertical-align:middle;margin-right:6px;"></i>
				Containers
			</h1>
			<p class="nw-panel-subtitle">Each container automatically creates a linked item in the Items table.</p>
		</div>
		<div class="nw-header-actions">
			<button id="nw-refresh-btn" class="nw-btn nw-btn-ghost" title="Refresh">
				<i data-lucide="refresh-cw" style="width:14px;height:14px;vertical-align:middle;"></i>
			</button>
			<button id="nw-add-btn" class="nw-btn nw-btn-primary">
				<i data-lucide="plus" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"></i>
				New Container
			</button>
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
		<input id="nw-search" type="text" class="nw-search-input" placeholder="Search name, description…">
		<select id="nw-filter-active" class="nw-select-filter">
			<option value="">All status</option>
			<option value="1">Active</option>
			<option value="0">Inactive</option>
		</select>
		<select id="nw-filter-rarity" class="nw-select-filter">
			<option value="">All rarity</option>
			<?php foreach ( self::RARITIES as $r ) : ?>
				<option value="<?php echo esc_attr( $r ); ?>"><?php echo esc_html( ucfirst( $r ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<select id="nw-filter-size" class="nw-select-filter">
			<option value="">All sizes</option>
			<?php foreach ( self::SIZES as $s ) : ?>
				<option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( ucfirst( $s ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<button id="nw-clear-filters" class="nw-btn nw-btn-ghost nw-btn-sm" style="display:none;">
			<i data-lucide="x" style="width:12px;height:12px;vertical-align:middle;"></i> Clear
		</button>
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
					<th>Linked item</th>
					<th style="width:72px;">Active</th>
					<th style="width:170px;">Actions</th>
				</tr>
			</thead>
			<tbody id="nw-containers-tbody">
				<tr class="nw-loading-row"><td colspan="8"><span class="nw-spinner"></span> Loading containers…</td></tr>
			</tbody>
		</table>
	</div>
</div>

<!-- MODAL -->
<div id="nw-modal-overlay" class="nw-modal-overlay" style="display:none;">
	<div class="nw-modal">
		<div class="nw-modal-header">
			<h2 id="nw-modal-title">New Container</h2>
			<button id="nw-modal-close" class="nw-modal-close" aria-label="Close">
				<i data-lucide="x" style="width:16px;height:16px;"></i>
			</button>
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
							<?php foreach ( self::RARITIES as $r ) : ?>
								<option value="<?php echo esc_attr( $r ); ?>"><?php echo esc_html( ucfirst( $r ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="nw-field nw-field-full">
						<label for="nw-field-allowed_sizes">
							Allowed Sizes
							<span class="nw-hint">comma-separated: tiny, small, medium, large</span>
						</label>
						<input type="text" id="nw-field-allowed_sizes" value="tiny, small, medium, large">
					</div>
				</div>

				<div class="nw-section-label">
					<i data-lucide="link" style="width:13px;height:13px;vertical-align:middle;margin-right:4px;"></i>
					Linked Item
				</div>
				<div class="nw-form-grid">
					<div class="nw-field nw-field-full" id="nw-create-item-wrap">
						<div class="nw-info-box">
							<i data-lucide="info" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;color:#adff00;"></i>
							<strong>New container</strong> — an item will be automatically created in the Items table with <code>is_container = true</code>.
						</div>
					</div>
					<div class="nw-field nw-field-full" id="nw-existing-item-wrap" style="display:none;">
						<label for="nw-field-parent_id">Linked Item</label>
						<select id="nw-field-parent_id" class="nw-select">
							<option value="">— No linked item —</option>
							<!-- populated via JS -->
						</select>
						<p class="nw-hint" style="margin-top:4px;">
							Changing this re-syncs name/description/rarity/image to the selected item.
						</p>
						<div id="nw-items-loading" style="display:none;color:#888;font-size:12px;margin-top:4px;">
							<i data-lucide="loader" style="width:12px;height:12px;vertical-align:middle;margin-right:4px;"></i>
							Loading items…
						</div>
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

				<div id="nw-delete-item-row" style="display:none;margin-top:16px;">
					<label style="display:flex;align-items:center;gap:8px;color:#f87171;font-size:13px;cursor:pointer;">
						<input type="checkbox" id="nw-field-delete_item">
						Also delete the linked item from the Items table
					</label>
				</div>
			</form>
		</div>
		<div class="nw-modal-footer">
			<button id="nw-delete-btn" class="nw-btn nw-btn-danger" style="display:none;margin-right:auto;">
				<i data-lucide="trash-2" style="width:13px;height:13px;vertical-align:middle;margin-right:4px;"></i>
				Delete
			</button>
			<button id="nw-cancel-btn" class="nw-btn nw-btn-ghost">Cancel</button>
			<button id="nw-save-btn" class="nw-btn nw-btn-primary">
				<i data-lucide="save" style="width:13px;height:13px;vertical-align:middle;margin-right:4px;"></i>
				<span id="nw-save-label">Create Container</span>
			</button>
		</div>
	</div>
</div>
		<?php
	}
}
