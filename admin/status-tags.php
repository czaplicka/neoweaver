<?php
/**
 * NeoWeaver — Status Tags Admin
 * Table: cyber_status_tags
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NWStatusTagsAdmin {

	private string $page_slug    = 'nw-status-tags';
	private string $nonce_action = 'nwstatustagsnonce';
	private string $table        = 'cyber_status_tags';

	private const CATEGORIES = [ 'Physical', 'Condition', 'Tech', 'Buff', 'Glitch' ];
	private const DURATIONS  = [ 'permanent', 'scene', 'encounter', 'turn', 'custom' ];

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_nwstatustagsload',      [ $this, 'ajax_load' ] );
		add_action( 'wp_ajax_nwstatustagssave',      [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nwstatustagsdelete',    [ $this, 'ajax_delete' ] );
		add_action( 'wp_ajax_nwstatustagsduplicate', [ $this, 'ajax_duplicate' ] );
	}

	// ── Menu ─────────────────────────────────────────────────────────────────
	public function register_menu(): void {
		$menu_parent = 'nw-dashboard';
		add_submenu_page(
			$menu_parent,
			__( 'Status Tags', 'neoweaver' ),
			'<i data-lucide="tag" style="width:14px;height:14px;vertical-align:-2px;margin-right:4px;"></i> Status Tags',
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
		wp_enqueue_style( 'nw-admin-core',       NW_PLUGIN_URL . 'assets/css/admin/admin-core.css',     [ 'chakra-petch' ], NW_VERSION );
		wp_enqueue_style( 'nw-statustags-style', NW_PLUGIN_URL . 'assets/css/admin/status-tags.css',   [ 'chakra-petch', 'nw-admin-core' ], NW_VERSION );
		wp_enqueue_script( 'lucide', 'https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js', [], '0.468.0', true );
		wp_enqueue_script(
			'nw-statustags-script',
			NW_PLUGIN_URL . 'assets/js/admin/status-tags.js',
			[ 'jquery', 'lucide' ],
			NW_VERSION,
			true
		);
		wp_localize_script( 'nw-statustags-script', 'NWStatusTags', [
			'ajaxurl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( $this->nonce_action ),
			'categories' => self::CATEGORIES,
			'durations'  => self::DURATIONS,
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
	}

	private function cached_get_all(): array {
		$cache_key = $this->get_cache_key( $this->table . '_all' );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}
		$res = $this->supa( 'GET', $this->table . '?select=*&order=category.asc,label.asc', [], $this->sk() );
		if ( ! $res['ok'] ) {
			return [ 'error' => $res['error'] ?? 'Failed to fetch records.' ];
		}
		$rows = is_array( $res['data'] ) ? $res['data'] : [];
		set_transient( $cache_key, $rows, MINUTE_IN_SECONDS * 5 );
		return $rows;
	}

	private function bool_from_post( string $key, bool $default = false ): bool {
		if ( ! isset( $_POST[ $key ] ) ) { return $default; }
		return (bool) intval( wp_unslash( $_POST[ $key ] ) );
	}

	private function sanitize_category( string $v ): ?string {
		return in_array( $v, self::CATEGORIES, true ) ? $v : null;
	}

	private function sanitize_duration( string $v ): string {
		return in_array( $v, self::DURATIONS, true ) ? $v : 'scene';
	}

	private function sanitize_color( string $v ): string {
		$v = strtolower( trim( $v ) );
		return preg_match( '/^#[0-9a-f]{6}$/', $v ) ? $v : '#ff0000';
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

		$id                 = intval( wp_unslash( $_POST['id'] ?? 0 ) );
		$label              = sanitize_text_field( wp_unslash( $_POST['label']              ?? '' ) );
		$category           = sanitize_text_field( wp_unslash( $_POST['category']           ?? '' ) );
		$effect_description = sanitize_textarea_field( wp_unslash( $_POST['effect_description'] ?? '' ) );
		$mechanic_modifier  = sanitize_textarea_field( wp_unslash( $_POST['mechanic_modifier']  ?? '' ) );
		$duration           = sanitize_text_field( wp_unslash( $_POST['duration']           ?? 'scene' ) );
		$source             = sanitize_text_field( wp_unslash( $_POST['source']             ?? '' ) );
		$color_hex          = sanitize_text_field( wp_unslash( $_POST['color_hex']          ?? '#ff0000' ) );
		$is_stackable       = $this->bool_from_post( 'is_stackable', false );
		$is_debuff          = $this->bool_from_post( 'is_debuff',    true );
		$is_active          = $this->bool_from_post( 'is_active',    true );

		if ( ! $label ) { wp_send_json_error( 'Label is required.' ); return; }

		$payload = [
			'label'              => $label,
			'category'           => $this->sanitize_category( $category ),
			'effect_description' => '' !== $effect_description ? $effect_description : null,
			'mechanic_modifier'  => '' !== $mechanic_modifier  ? $mechanic_modifier  : null,
			'duration'           => $this->sanitize_duration( $duration ),
			'source'             => '' !== $source ? $source : null,
			'color_hex'          => $this->sanitize_color( $color_hex ),
			'is_stackable'       => $is_stackable,
			'is_debuff'          => $is_debuff,
			'is_active'          => $is_active,
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
		if ( empty( $original ) ) { wp_send_json_error( 'Failed to read original.' ); return; }

		$payload = $original;
		unset( $payload['id'] );
		$payload['label']     = $original['label'] . ' (Copy)';
		$payload['is_active'] = false;

		$dup_res = $this->supa( 'POST', $this->table, $payload, $this->sk() );
		if ( ! $dup_res['ok'] ) { wp_send_json_error( $dup_res['error'] ?? 'Duplicate failed.' ); return; }
		$this->bust_cache();
		$row = is_array( $dup_res['data'] ) ? ( $dup_res['data'][0] ?? $dup_res['data'] ) : $dup_res['data'];
		wp_send_json_success( $row );
	}

	// ── Render ───────────────────────────────────────────────────────────────
	public function render_page(): void {
		$categories = self::CATEGORIES;
		$durations  = self::DURATIONS;
		?>
<div class="wrap nw-statustags-panel">

	<div class="nw-admin-header">
		<div class="nw-admin-header-left">
			<i data-lucide="tag" class="nw-header-icon"></i>
			<div>
				<h1 class="nw-admin-title">Status Tags</h1>
				<p class="nw-admin-subtitle">Buffs, debuffs and conditions applied to characters during gameplay</p>
			</div>
		</div>
		<button id="nw-add-btn" class="nw-btn nw-btn-primary">
			<i data-lucide="plus"></i> New Tag
		</button>
	</div>

	<div id="nw-notice" class="nw-notice" style="display:none;"></div>

	<div class="nw-stats-bar">
		<div class="nw-stat-card"><span class="nw-stat-value" id="nw-total">—</span><span class="nw-stat-label">Total</span></div>
		<div class="nw-stat-card"><span class="nw-stat-value nw-stat-red"   id="nw-debuffs">—</span><span class="nw-stat-label">Debuffs</span></div>
		<div class="nw-stat-card"><span class="nw-stat-value nw-stat-green" id="nw-buffs">—</span><span class="nw-stat-label">Buffs</span></div>
		<div class="nw-stat-card"><span class="nw-stat-value nw-stat-muted" id="nw-inactive">—</span><span class="nw-stat-label">Inactive</span></div>
	</div>

	<div class="nw-filters-bar">
		<div class="nw-search-wrap">
			<i data-lucide="search" class="nw-search-icon"></i>
			<input type="text" id="nw-search" class="nw-input" placeholder="Search tags…">
		</div>
		<select id="nw-filter-category" class="nw-select">
			<option value="">All categories</option>
			<?php foreach ( $categories as $c ) : ?>
			<option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( $c ); ?></option>
			<?php endforeach; ?>
		</select>
		<select id="nw-filter-duration" class="nw-select">
			<option value="">All durations</option>
			<?php foreach ( $durations as $d ) : ?>
			<option value="<?php echo esc_attr( $d ); ?>"><?php echo esc_html( $d ); ?></option>
			<?php endforeach; ?>
		</select>
		<select id="nw-filter-type" class="nw-select">
			<option value="">Buff &amp; Debuff</option>
			<option value="debuff">Debuffs only</option>
			<option value="buff">Buffs only</option>
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
					<th>Label</th>
					<th>Category</th>
					<th>Duration</th>
					<th>Type</th>
					<th>Stackable</th>
					<th>Source</th>
					<th>Active</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody id="nw-tags-tbody">
				<tr><td colspan="8" class="nw-loading"><i data-lucide="loader-2" class="nw-spin"></i> Loading tags…</td></tr>
			</tbody>
		</table>
	</div>

</div>

<!-- ── MODAL ──────────────────────────────────────────────────────────── -->
<div id="nw-modal" class="nw-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="nw-modal-title">
	<div class="nw-modal">
		<div class="nw-modal-header">
			<h2 id="nw-modal-title">New Status Tag</h2>
			<button id="nw-modal-close" class="nw-modal-close" aria-label="Close"><i data-lucide="x"></i></button>
		</div>
		<div class="nw-modal-body">
			<input type="hidden" id="nw-field-id">

			<!-- Row 1 -->
			<div class="nw-form-row nw-form-cols-2">
				<div class="nw-form-group">
					<label for="nw-field-label">Label <span class="nw-required">*</span></label>
					<input type="text" id="nw-field-label" class="nw-input" placeholder="e.g. Stunned">
				</div>
				<div class="nw-form-group">
					<label for="nw-field-color_hex">Color</label>
					<div class="nw-color-row">
						<input type="color" id="nw-field-color_picker" class="nw-color-picker" value="#ff0000">
						<input type="text"  id="nw-field-color_hex"    class="nw-input nw-color-text" placeholder="#ff0000" maxlength="7">
					</div>
				</div>
			</div>

			<!-- Row 2 -->
			<div class="nw-form-row nw-form-cols-2">
				<div class="nw-form-group">
					<label for="nw-field-category">Category</label>
					<select id="nw-field-category" class="nw-select">
						<option value="">— none —</option>
						<?php foreach ( $categories as $c ) : ?>
						<option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( $c ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="nw-form-group">
					<label for="nw-field-duration">Duration</label>
					<select id="nw-field-duration" class="nw-select">
						<?php foreach ( $durations as $d ) : ?>
						<option value="<?php echo esc_attr( $d ); ?>"><?php echo esc_html( $d ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<!-- Effect & Mechanic -->
			<div class="nw-form-group">
				<label for="nw-field-effect_description">Effect Description</label>
				<textarea id="nw-field-effect_description" class="nw-textarea" rows="2" placeholder="Narrative description of the effect…"></textarea>
			</div>
			<div class="nw-form-group">
				<label for="nw-field-mechanic_modifier">Mechanic Modifier
					<span class="nw-field-hint">e.g. -2 to all attack rolls</span>
				</label>
				<textarea id="nw-field-mechanic_modifier" class="nw-textarea nw-mono" rows="2" placeholder="e.g. attack_modifier: -2, speed: 0"></textarea>
			</div>

			<!-- Source -->
			<div class="nw-form-group">
				<label for="nw-field-source">Source</label>
				<input type="text" id="nw-field-source" class="nw-input" placeholder="e.g. Taser, Virus, Spell">
			</div>

			<!-- Flags -->
			<div class="nw-flags-row">
				<label class="nw-checkbox-label">
					<input type="checkbox" id="nw-field-is_debuff" checked>
					<span>Debuff</span>
				</label>
				<label class="nw-checkbox-label">
					<input type="checkbox" id="nw-field-is_stackable">
					<span>Stackable</span>
				</label>
				<label class="nw-checkbox-label">
					<input type="checkbox" id="nw-field-is_active" checked>
					<span>Active</span>
				</label>
			</div>
		</div>
		<div class="nw-modal-footer">
			<button id="nw-modal-cancel" class="nw-btn nw-btn-ghost">Cancel</button>
			<button id="nw-modal-save"   class="nw-btn nw-btn-primary">
				<i data-lucide="save"></i> Save Tag
			</button>
		</div>
	</div>
</div>
		<?php
	}
}
