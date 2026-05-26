<?php
/**
 * NeoWeaver Races Admin
 * Tabela: cyber_races
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NWRacesAdmin {

	private string $page_slug    = 'nw-races';
	private string $menu_parent  = 'neoweaver';
	private string $table        = 'cyber_races';
	private string $nonce_action = 'nw_races_nonce';

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_nw_races_load',      [ $this, 'ajax_load' ] );
		add_action( 'wp_ajax_nw_races_save',      [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_races_delete',    [ $this, 'ajax_delete' ] );
		add_action( 'wp_ajax_nw_races_duplicate', [ $this, 'ajax_duplicate' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			$this->menu_parent,
			'Races',
			'neoweaver',
			'<span data-lucide="users-round"></span> Races',
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, $this->page_slug ) ) return;

		if ( ! wp_style_is( 'chakra-petch', 'enqueued' ) ) {
			wp_enqueue_style( 'chakra-petch', 'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap', [], null );
		}

		wp_enqueue_style( 'nw-admin-core',   NW_PLUGIN_URL . 'assets/css/admin/admin-core.css',  [ 'chakra-petch' ],             NW_VERSION );
		wp_enqueue_style( 'nw-races-style',  NW_PLUGIN_URL . 'assets/css/admin/races.css',        [ 'chakra-petch', 'nw-admin-core' ], NW_VERSION );

		wp_enqueue_script( 'lucide',          'https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js', [], '0.468.0', true );
		wp_enqueue_script( 'nw-races-script', NW_PLUGIN_URL . 'assets/js/admin/races.js', [ 'jquery', 'lucide' ], NW_VERSION, true );

		wp_localize_script( 'nw-races-script', 'NWRaces', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( $this->nonce_action ),
		] );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                              */
	/* ------------------------------------------------------------------ */

	private function sk(): array {
		if ( ! defined( 'TW_SUPABASE_SERVICE_KEY' ) ) return [];
		return [
			'apikey'        => TW_SUPABASE_SERVICE_KEY,
			'Authorization' => 'Bearer ' . TW_SUPABASE_SERVICE_KEY,
		];
	}

	private function supa( string $method, string $endpoint, array $body = [], array $extra_headers = [] ): array {
		$method = strtoupper( $method );

		if ( $method === 'GET' ) {
			if ( function_exists( 'tw_supabase_get' ) ) {
				[ $table, $qs ] = array_pad( explode( '?', $endpoint, 2 ), 2, '' );
				$query = [];
				if ( $qs ) parse_str( $qs, $query );
				$data = tw_supabase_get( $table, $query, array_merge( $this->sk(), $extra_headers ) );
				if ( ! is_array( $data ) ) return [ 'ok' => false, 'code' => 0, 'data' => null, 'error' => 'tw_supabase_get returned non-array' ];
				if ( isset( $data['code'], $data['message'] ) ) return [ 'ok' => false, 'code' => (int) $data['code'], 'data' => null, 'error' => $data['message'] ];
				return [ 'ok' => true, 'code' => 200, 'data' => $data, 'error' => null ];
			}
		}

		if ( function_exists( 'tw_supabase_request' ) ) {
			[ $table, $qs ] = array_pad( explode( '?', $endpoint, 2 ), 2, '' );
			$query = [];
			if ( $qs ) parse_str( $qs, $query );
			$extra_args = [];
			if ( in_array( $method, [ 'POST', 'PATCH' ], true ) ) {
				$extra_args['headers']['Prefer'] = 'return=representation';
			}
			if ( ! empty( $extra_headers ) ) {
				$extra_args['headers'] = array_merge( $extra_args['headers'] ?? [], $extra_headers );
			}
			$res      = tw_supabase_request( $method, $table, $query, empty( $body ) ? null : $body, $extra_args );
			$ok       = $res['ok']   ?? false;
			$code     = $res['code'] ?? 0;
			$data     = $res['data'] ?? null;
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
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) return $cached;

		$res = $this->supa( 'GET', $this->table . '?select=*&order=name.asc', [], $this->sk() );
		if ( ! $res['ok'] ) return [ 'error' => $res['error'] ?? 'Failed to fetch records.' ];

		$rows = is_array( $res['data'] ) ? $res['data'] : [];
		set_transient( $cache_key, $rows, MINUTE_IN_SECONDS * 5 );
		return $rows;
	}

	private function is_uuid( string $value ): bool {
		return (bool) preg_match( '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $value );
	}

	private function bool_from_post( string $key, bool $default = false ): bool {
		if ( ! isset( $_POST[ $key ] ) ) return $default;
		return (bool) intval( wp_unslash( $_POST[ $key ] ) );
	}

	private function clamp_pref( $val ): int {
		return max( 0, min( 10, intval( $val ) ) );
	}

	private function sanitize_json( string $raw, $default ): mixed {
		$raw = trim( $raw );
		if ( $raw === '' ) return $default;
		$decoded = json_decode( $raw, true );
		return ( JSON_ERROR_NONE === json_last_error() ) ? $decoded : $default;
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                                 */
	/* ------------------------------------------------------------------ */

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

		$id            = sanitize_text_field( wp_unslash( $_POST['id']            ?? '' ) );
		$name          = sanitize_text_field( wp_unslash( $_POST['name']          ?? '' ) );
		$parent_race   = sanitize_text_field( wp_unslash( $_POST['parent_race']   ?? '' ) );
		$description   = sanitize_textarea_field( wp_unslash( $_POST['description']   ?? '' ) );
		$gm_instructions = sanitize_textarea_field( wp_unslash( $_POST['gm_instructions'] ?? '' ) );
		$img_url       = esc_url_raw( wp_unslash( $_POST['img_url'] ?? '' ) );
		$conflict_axis = sanitize_text_field( wp_unslash( $_POST['conflict_axis'] ?? '' ) );
		$conflict_side = sanitize_text_field( wp_unslash( $_POST['conflict_side'] ?? '' ) );

		$race_base_hp  = max( 1, intval( wp_unslash( $_POST['race_base_hp'] ?? 86 ) ) );
		$race_base_mp  = max( 1, intval( wp_unslash( $_POST['race_base_mp'] ?? 67 ) ) );

		$prefs = [
			'preferred_tech'   => $this->clamp_pref( $_POST['preferred_tech']   ?? 3 ),
			'preferred_magic'  => $this->clamp_pref( $_POST['preferred_magic']  ?? 3 ),
			'preferred_gods'   => $this->clamp_pref( $_POST['preferred_gods']   ?? 3 ),
			'preferred_wealth' => $this->clamp_pref( $_POST['preferred_wealth'] ?? 3 ),
			'preferred_threat' => $this->clamp_pref( $_POST['preferred_threat'] ?? 3 ),
			'preferred_moral'  => $this->clamp_pref( $_POST['preferred_moral']  ?? 2 ),
			'preferred_social' => $this->clamp_pref( $_POST['preferred_social'] ?? 3 ),
		];

		$tags_raw  = sanitize_textarea_field( wp_unslash( $_POST['tags']  ?? '[]' ) );
		$bonus_raw = sanitize_textarea_field( wp_unslash( $_POST['bonus'] ?? '' ) );

		$tags  = $this->sanitize_json( $tags_raw,  [] );
		$bonus = $this->sanitize_json( $bonus_raw, null );

		if ( ! is_array( $tags ) ) $tags = [];

		$is_active = $this->bool_from_post( 'is_active', true );

		if ( ! $name ) { wp_send_json_error( 'Name is required.' ); return; }
		if ( $id && ! $this->is_uuid( $id ) ) { wp_send_json_error( 'Invalid race ID.' ); return; }

		$payload = array_merge( [
			'name'             => $name,
			'parent_race'      => $parent_race ?: null,
			'description'      => $description ?: null,
			'gm_instructions'  => $gm_instructions ?: null,
			'img_url'          => $img_url ?: null,
			'conflict_axis'    => $conflict_axis ?: null,
			'conflict_side'    => $conflict_side ?: null,
			'race_base_hp'     => $race_base_hp,
			'race_base_mp'     => $race_base_mp,
			'tags'             => $tags,
			'bonus'            => $bonus,
			'is_active'        => $is_active,
		], $prefs );

		if ( ! $id ) {
			$res = $this->supa( 'POST', $this->table, $payload, $this->sk() );
		} else {
			$res = $this->supa( 'PATCH', $this->table . '?id=eq.' . rawurlencode( $id ), $payload, $this->sk() );
		}

		if ( ! $res['ok'] ) { wp_send_json_error( $res['error'] ?? 'Save failed.' ); return; }

		$this->bust_cache();
		$row = is_array( $res['data'] ) ? ( $res['data'][0] ?? $res['data'] ) : $res['data'];
		wp_send_json_success( $row );
	}

	public function ajax_delete(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		if ( ! $id || ! $this->is_uuid( $id ) ) { wp_send_json_error( 'Invalid race ID.' ); return; }

		$res = $this->supa( 'DELETE', $this->table . '?id=eq.' . rawurlencode( $id ), [], $this->sk() );
		if ( ! $res['ok'] ) { wp_send_json_error( $res['error'] ?? 'Delete failed.' ); return; }

		$this->bust_cache();
		wp_send_json_success( 'deleted' );
	}

	public function ajax_duplicate(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		if ( ! $id || ! $this->is_uuid( $id ) ) { wp_send_json_error( 'Invalid race ID.' ); return; }

		$res = $this->supa( 'GET', $this->table . '?id=eq.' . rawurlencode( $id ) . '&select=*', [], $this->sk() );
		if ( ! $res['ok'] || empty( $res['data'] ) ) { wp_send_json_error( 'Original race not found.' ); return; }

		$orig = is_array( $res['data'] ) ? ( $res['data'][0] ?? $res['data'] ) : $res['data'];
		if ( empty( $orig ) ) { wp_send_json_error( 'Failed to read original race.' ); return; }

		$payload = $orig;
		unset( $payload['id'], $payload['created_at'] );
		$payload['name']      = ( $orig['name'] ?? 'Race' ) . ' (Copy)';
		$payload['is_active'] = false;

		$dup = $this->supa( 'POST', $this->table, $payload, $this->sk() );
		if ( ! $dup['ok'] ) { wp_send_json_error( $dup['error'] ?? 'Duplicate failed.' ); return; }

		$this->bust_cache();
		$row = is_array( $dup['data'] ) ? ( $dup['data'][0] ?? $dup['data'] ) : $dup['data'];
		wp_send_json_success( $row );
	}

	/* ------------------------------------------------------------------ */
	/* Render                                                               */
	/* ------------------------------------------------------------------ */

	public function render_page(): void { ?>
<div class="nw-panel nw-races-panel">

	<!-- Header -->
	<div class="nw-panel-header">
		<div>
			<h1 class="nw-panel-title">
				<i data-lucide="users-round" style="width:22px;height:22px;vertical-align:middle;margin-right:6px"></i>
				Races
			</h1>
			<p class="nw-panel-subtitle">Manage playable races and their preference profiles.</p>
		</div>
		<div class="nw-header-actions">
			<button id="nw-refresh-btn" class="nw-btn nw-btn-ghost" title="Refresh">
				<i data-lucide="refresh-cw" style="width:14px;height:14px;vertical-align:middle"></i>
			</button>
			<button id="nw-add-btn" class="nw-btn nw-btn-primary">
				<i data-lucide="plus" style="width:14px;height:14px;vertical-align:middle;margin-right:4px"></i>
				New Race
			</button>
		</div>
	</div>

	<!-- Notice -->
	<div id="nw-notice" class="nw-notice" style="display:none"></div>

	<!-- Stats -->
	<div class="nw-stats-bar">
		<span class="nw-stat-pill">Total <strong id="nw-total"></strong></span>
		<span class="nw-stat-pill nw-pill-active">Active <strong id="nw-active"></strong></span>
		<span class="nw-stat-pill nw-pill-inactive">Inactive <strong id="nw-inactive"></strong></span>
		<span class="nw-stat-pill nw-pill-rare">With parent <strong id="nw-parented"></strong></span>
	</div>

	<!-- Filters -->
	<div class="nw-filters-bar">
		<input id="nw-search" type="text" class="nw-search-input" placeholder="Search name, description…">
		<select id="nw-filter-active" class="nw-select-filter">
			<option value="">All status</option>
			<option value="1">Active</option>
			<option value="0">Inactive</option>
		</select>
		<select id="nw-filter-conflict" class="nw-select-filter">
			<option value="">All conflict axes</option>
		</select>
		<button id="nw-clear-filters" class="nw-btn nw-btn-ghost nw-btn-sm" style="display:none">
			<i data-lucide="x" style="width:12px;height:12px;vertical-align:middle"></i> Clear
		</button>
	</div>

	<!-- Table -->
	<div class="nw-table-wrap">
		<table class="nw-table">
			<thead>
				<tr>
					<th style="width:52px">Img</th>
					<th>Name</th>
					<th>Parent Race</th>
					<th style="width:60px">HP</th>
					<th style="width:60px">MP</th>
					<th>Preferences</th>
					<th>Conflict</th>
					<th style="width:72px">Active</th>
					<th style="width:170px">Actions</th>
				</tr>
			</thead>
			<tbody id="nw-races-tbody">
				<tr class="nw-loading-row"><td colspan="9"><span class="nw-spinner"></span> Loading races…</td></tr>
			</tbody>
		</table>
	</div>

</div><!-- .nw-panel -->

<!-- ===================== MODAL ===================== -->
<div id="nw-modal-overlay" class="nw-modal-overlay" style="display:none">
<div class="nw-modal nw-modal-wide">

	<div class="nw-modal-header">
		<h2 id="nw-modal-title">New Race</h2>
		<button id="nw-modal-close" class="nw-modal-close" aria-label="Close">
			<i data-lucide="x" style="width:16px;height:16px"></i>
		</button>
	</div>

	<div class="nw-modal-body">
	<form id="nw-race-form" autocomplete="off">
		<input type="hidden" id="nw-field-id">

		<!-- Basic Info -->
		<div class="nw-section-label">Basic Info</div>
		<div class="nw-form-grid">
			<div class="nw-field nw-field-full">
				<label for="nw-field-name">Name <span class="nw-req">*</span></label>
				<input type="text" id="nw-field-name" maxlength="120">
			</div>
			<div class="nw-field">
				<label for="nw-field-parent-race">Parent Race</label>
				<input type="text" id="nw-field-parent-race" placeholder="e.g. Human">
			</div>
			<div class="nw-field">
				<label for="nw-field-img-url">Image URL</label>
				<input type="url" id="nw-field-img-url" placeholder="https://…">
			</div>
			<div id="nw-img-preview-wrap" class="nw-field-full" style="display:none">
				<img id="nw-img-preview" src="" alt="Preview" style="max-height:120px;border-radius:6px;border:1px solid #2a2a2a">
			</div>
			<div class="nw-field nw-field-full">
				<label for="nw-field-description">Description</label>
				<textarea id="nw-field-description" rows="3"></textarea>
			</div>
		</div>

		<!-- Base Stats -->
		<div class="nw-section-label">Base Stats</div>
		<div class="nw-form-grid">
			<div class="nw-field">
				<label for="nw-field-hp">Base HP <span class="nw-hint">(min 1)</span></label>
				<input type="number" id="nw-field-hp" min="1" value="86">
			</div>
			<div class="nw-field">
				<label for="nw-field-mp">Base MP <span class="nw-hint">(min 1)</span></label>
				<input type="number" id="nw-field-mp" min="1" value="67">
			</div>
		</div>

		<!-- Preferences -->
		<div class="nw-section-label">
			<i data-lucide="sliders-horizontal" style="width:13px;height:13px;vertical-align:middle;margin-right:4px"></i>
			Preference Scales <span class="nw-hint">(0 – 10)</span>
		</div>
		<div class="nw-prefs-grid">
			<?php
			$prefs = [
				'preferred_tech'   => [ 'Tech',   'cpu' ],
				'preferred_magic'  => [ 'Magic',  'sparkles' ],
				'preferred_gods'   => [ 'Gods',   'sun' ],
				'preferred_wealth' => [ 'Wealth', 'coins' ],
				'preferred_threat' => [ 'Threat', 'skull' ],
				'preferred_moral'  => [ 'Moral',  'scale' ],
				'preferred_social' => [ 'Social', 'users' ],
			];
			foreach ( $prefs as $key => [ $label, $icon ] ) :
				$default = $key === 'preferred_moral' ? 2 : 3;
			?>
			<div class="nw-pref-row">
				<label for="nw-field-<?= esc_attr( $key ) ?>">
					<i data-lucide="<?= esc_attr( $icon ) ?>" style="width:11px;height:11px;vertical-align:middle;margin-right:3px"></i>
					<?= esc_html( $label ) ?>
				</label>
				<input type="number" id="nw-field-<?= esc_attr( $key ) ?>" name="<?= esc_attr( $key ) ?>" min="0" max="10" value="<?= $default ?>">
				<div class="nw-pref-bar-wrap">
					<div class="nw-pref-bar" id="nw-bar-<?= esc_attr( $key ) ?>" style="width:<?= $default * 10 ?>%"></div>
				</div>
			</div>
			<?php endforeach; ?>
		</div>

		<!-- Conflict -->
		<div class="nw-section-label">Conflict</div>
		<div class="nw-form-grid">
			<div class="nw-field">
				<label for="nw-field-conflict-axis">Conflict Axis</label>
				<input type="text" id="nw-field-conflict-axis" placeholder="e.g. tech_vs_magic">
			</div>
			<div class="nw-field">
				<label for="nw-field-conflict-side">Conflict Side</label>
				<input type="text" id="nw-field-conflict-side" placeholder="e.g. tech">
			</div>
		</div>

		<!-- Advanced -->
		<div class="nw-section-label">Advanced</div>
		<div class="nw-form-grid">
			<div class="nw-field nw-field-full">
				<label for="nw-field-tags">Tags <span class="nw-hint">JSON array, e.g. ["stealth","bio-tech"]</span></label>
				<textarea id="nw-field-tags" rows="2" class="nw-code-field" placeholder='["tag1","tag2"]'>[]</textarea>
			</div>
			<div class="nw-field nw-field-full">
				<label for="nw-field-bonus">Bonus <span class="nw-hint">JSON object, e.g. {"hp":10,"magic_resist":2}</span></label>
				<textarea id="nw-field-bonus" rows="2" class="nw-code-field" placeholder='{"hp":10}'></textarea>
			</div>
			<div class="nw-field nw-field-full">
				<label for="nw-field-gm-instructions">GM Instructions</label>
				<textarea id="nw-field-gm-instructions" rows="3" placeholder="Notes for the Game Master…"></textarea>
			</div>
		</div>

		<!-- Status -->
		<div class="nw-section-label">Status</div>
		<div class="nw-toggle-row">
			<label class="nw-toggle-label">
				<span class="nw-toggle">
					<input type="checkbox" id="nw-field-is-active" checked>
					<span class="nw-toggle-slider"></span>
				</span>
				Active
			</label>
		</div>

	</form>
	</div><!-- .nw-modal-body -->

	<div class="nw-modal-footer">
		<button id="nw-delete-btn" class="nw-btn nw-btn-danger" style="display:none;margin-right:auto">
			<i data-lucide="trash-2" style="width:13px;height:13px;vertical-align:middle;margin-right:4px"></i>Delete
		</button>
		<button id="nw-cancel-btn" class="nw-btn nw-btn-ghost">Cancel</button>
		<button id="nw-save-btn" class="nw-btn nw-btn-primary">
			<i data-lucide="save" style="width:13px;height:13px;vertical-align:middle;margin-right:4px"></i>
			<span id="nw-save-label">Create Race</span>
		</button>
	</div>

</div><!-- .nw-modal -->
</div><!-- .nw-modal-overlay -->
<?php
	}
}
