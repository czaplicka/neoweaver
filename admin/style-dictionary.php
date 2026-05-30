<?php
/**
 * NeoWeaver — Style Dictionary Admin
 * Table: cyber_style_dictionary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NWStyleDictionaryAdmin {

	private string $page_slug    = 'nw-style-dictionary';
	private string $nonce_action = 'nwstyledictionary nonce';
	private string $table        = 'cyber_style_dictionary';

	private const CATEGORIES = [ 'behavior', 'visuals', 'vibe', 'general' ];

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_nwstyledicload',      [ $this, 'ajax_load' ] );
		add_action( 'wp_ajax_nwstyledicssave',     [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nwstyledicdelete',    [ $this, 'ajax_delete' ] );
		add_action( 'wp_ajax_nwstyledicduplicate', [ $this, 'ajax_duplicate' ] );
	}

	// ── Menu ─────────────────────────────────────────────────────────────────
	public function register_menu(): void {
		add_submenu_page(
			'nw-dashboard',
			__( 'Style Dictionary', 'neoweaver' ),
			'<i data-lucide="book-marked" style="width:14px;height:14px;vertical-align:-2px;margin-right:4px;"></i> Style Dictionary',
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
		wp_enqueue_style( 'nw-admin-core',     NW_PLUGIN_URL . 'assets/css/admin/admin-core.css',        [ 'chakra-petch' ], NW_VERSION );
		wp_enqueue_style( 'nw-styledic-style', NW_PLUGIN_URL . 'assets/css/admin/style-dictionary.css',  [ 'chakra-petch', 'nw-admin-core' ], NW_VERSION );
		wp_enqueue_script( 'lucide', 'https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js', [], '0.468.0', true );
		wp_enqueue_script(
			'nw-styledic-script',
			NW_PLUGIN_URL . 'assets/js/admin/style-dictionary.js',
			[ 'jquery', 'lucide' ],
			NW_VERSION,
			true
		);
		wp_localize_script( 'nw-styledic-script', 'NWStyleDic', [
			'ajaxurl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( $this->nonce_action ),
			'categories' => self::CATEGORIES,
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
		$res = $this->supa( 'GET', $this->table . '?select=*&order=category.asc,tag_name.asc', [], $this->sk() );
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
		if ( ! isset( $_POST[ $key ] ) ) { return $default; }
		return (bool) intval( wp_unslash( $_POST[ $key ] ) );
	}

	private function sanitize_category( string $v ): string {
		return in_array( $v, self::CATEGORIES, true ) ? $v : 'general';
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

		$id                = sanitize_text_field( wp_unslash( $_POST['id']                ?? '' ) );
		$tag_name          = sanitize_text_field( wp_unslash( $_POST['tag_name']          ?? '' ) );
		$category          = sanitize_text_field( wp_unslash( $_POST['category']          ?? 'general' ) );
		$interpretation_en = sanitize_textarea_field( wp_unslash( $_POST['interpretation_en'] ?? '' ) );
		$is_active         = $this->bool_from_post( 'is_active', true );

		if ( ! $tag_name )          { wp_send_json_error( 'Tag name is required.' );          return; }
		if ( ! $interpretation_en ) { wp_send_json_error( 'Interpretation is required.' ); return; }
		if ( $id && ! $this->is_uuid( $id ) ) { wp_send_json_error( 'Invalid ID.' ); return; }

		$payload = [
			'tag_name'          => strtolower( trim( $tag_name ) ),
			'category'          => $this->sanitize_category( $category ),
			'interpretation_en' => $interpretation_en,
			'is_active'         => $is_active,
		];

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
		if ( ! $id || ! $this->is_uuid( $id ) ) { wp_send_json_error( 'Invalid ID.' ); return; }
		$res = $this->supa( 'DELETE', $this->table . '?id=eq.' . rawurlencode( $id ), [], $this->sk() );
		if ( ! $res['ok'] ) { wp_send_json_error( $res['error'] ?? 'Delete failed.' ); return; }
		$this->bust_cache();
		wp_send_json_success( 'deleted' );
	}

	public function ajax_duplicate(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }
		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		if ( ! $id || ! $this->is_uuid( $id ) ) { wp_send_json_error( 'Invalid ID.' ); return; }

		$res = $this->supa( 'GET', $this->table . '?id=eq.' . rawurlencode( $id ) . '&select=*', [], $this->sk() );
		if ( ! $res['ok'] || empty( $res['data'] ) ) { wp_send_json_error( 'Original not found.' ); return; }
		$original = is_array( $res['data'] ) ? ( $res['data'][0] ?? [] ) : [];

		$payload = $original;
		unset( $payload['id'], $payload['created_at'] );
		$payload['tag_name']  = $original['tag_name'] . '_copy';
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
		?>
<div class="wrap nw-styledic-panel">

	<div class="nw-admin-header">
		<div class="nw-admin-header-left">
			<i data-lucide="book-marked" class="nw-header-icon"></i>
			<div>
				<h1 class="nw-admin-title">Style Dictionary</h1>
				<p class="nw-admin-subtitle">Tags that define world aesthetics, vibes and visual interpretation for the AI GM</p>
			</div>
		</div>
		<button id="nw-add-btn" class="nw-btn nw-btn-primary">
			<i data-lucide="plus"></i> New Tag
		</button>
	</div>

	<div id="nw-notice" class="nw-notice" style="display:none;"></div>

	<div class="nw-stats-bar">
		<div class="nw-stat-card"><span class="nw-stat-value" id="nw-total">—</span><span class="nw-stat-label">Total</span></div>
		<div class="nw-stat-card"><span class="nw-stat-value nw-stat-behavior" id="nw-stat-behavior">—</span><span class="nw-stat-label">Behavior</span></div>
		<div class="nw-stat-card"><span class="nw-stat-value nw-stat-visuals"  id="nw-stat-visuals">—</span><span class="nw-stat-label">Visuals</span></div>
		<div class="nw-stat-card"><span class="nw-stat-value nw-stat-vibe"     id="nw-stat-vibe">—</span><span class="nw-stat-label">Vibe</span></div>
		<div class="nw-stat-card"><span class="nw-stat-value nw-stat-muted"    id="nw-inactive">—</span><span class="nw-stat-label">Inactive</span></div>
	</div>

	<div class="nw-filters-bar">
		<div class="nw-search-wrap">
			<i data-lucide="search" class="nw-search-icon"></i>
			<input type="text" id="nw-search" class="nw-input" placeholder="Search tag name or interpretation…">
		</div>
		<select id="nw-filter-category" class="nw-select">
			<option value="">All categories</option>
			<?php foreach ( $categories as $c ) : ?>
			<option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( ucfirst( $c ) ); ?></option>
			<?php endforeach; ?>
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
					<th>Tag Name</th>
					<th>Category</th>
					<th>Interpretation (EN)</th>
					<th>Active</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody id="nw-styledic-tbody">
				<tr><td colspan="5" class="nw-loading"><i data-lucide="loader-2" class="nw-spin"></i> Loading…</td></tr>
			</tbody>
		</table>
	</div>

</div>

<!-- ── MODAL ──────────────────────────────────────────────────────────── -->
<div id="nw-modal" class="nw-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="nw-modal-title">
	<div class="nw-modal">
		<div class="nw-modal-header">
			<h2 id="nw-modal-title">New Style Tag</h2>
			<button id="nw-modal-close" class="nw-modal-close" aria-label="Close"><i data-lucide="x"></i></button>
		</div>
		<div class="nw-modal-body">
			<input type="hidden" id="nw-field-id">

			<div class="nw-form-row nw-form-cols-2">
				<div class="nw-form-group">
					<label for="nw-field-tag_name">Tag Name <span class="nw-required">*</span>
						<span class="nw-field-hint">lowercase, no spaces</span>
					</label>
					<input type="text" id="nw-field-tag_name" class="nw-input nw-mono-input" placeholder="e.g. neo_punk">
				</div>
				<div class="nw-form-group">
					<label for="nw-field-category">Category</label>
					<select id="nw-field-category" class="nw-select">
						<?php foreach ( $categories as $c ) : ?>
						<option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( ucfirst( $c ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="nw-form-group">
				<label for="nw-field-interpretation_en">Interpretation (EN) <span class="nw-required">*</span></label>
				<textarea id="nw-field-interpretation_en" class="nw-textarea" rows="4"
					placeholder="Describe what this style tag means for the AI GM — appearance, atmosphere, behavioral cues…"></textarea>
			</div>

			<div class="nw-form-group nw-checkbox-row">
				<label class="nw-checkbox-label">
					<input type="checkbox" id="nw-field-is_active" checked>
					<span>Active (visible to AI GM)</span>
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
