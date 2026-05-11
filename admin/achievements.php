<?php
/**
 * NeoWeaver Admin Panel — Achievements (cyber_achievements)
 *
 * Columns: id (text PK), title, description, icon_slug, bg_color,
 *          scope (account|character), goal, hidden_until_earned,
 *          category (system|exploration|social|progression|mission|loot|secret|null),
 *          is_active, created_at
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NeoWeaver_Achievements_Admin {

	private string $page_slug   = 'neoweaver-achievements';
	private string $parent_slug = 'neoweaver';

	private const SCOPES      = [ 'account', 'character' ];
	private const CATEGORIES  = [ 'system', 'exploration', 'social', 'progression', 'mission', 'loot', 'secret' ];

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_nw_achievements_get_all', [ $this, 'ajax_get_all' ] );
		add_action( 'wp_ajax_nw_achievements_save',    [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_achievements_toggle',  [ $this, 'ajax_toggle' ] );
		add_action( 'wp_ajax_nw_achievements_delete',  [ $this, 'ajax_delete' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			$this->parent_slug,
			'NeoWeaver — Achievements',
			'🏆 Achievements',
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ],
			11
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
			'nw-achievements-style',
			NW_PLUGIN_URL . 'assets/css/admin/achievements.css',
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
			'nw-achievements-script',
			NW_PLUGIN_URL . 'assets/js/admin/achievements.js',
			[ 'jquery', 'lucide' ],
			NW_VERSION,
			true
		);

		wp_localize_script(
			'nw-achievements-script',
			'NWAch',
			[
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'neoweaver_achievements' ),
			]
		);
	}

	/* ---------------------------------------------------------------- */
	/*  SUPABASE                                                         */
	/*                                                                   */
	/*  Zawsze zwraca: [ 'ok' => bool, 'code' => int, 'data' => mixed,  */
	/*                   'error' => string|null ]                        */
	/* ---------------------------------------------------------------- */

	private function supa( string $method, string $endpoint, array $body = [], array $extra_headers = [] ): array {
		$method = strtoupper( $method );

		/* --- GET: tw_supabase_get zwraca bezpośrednio tablicę danych --- */
		if ( 'GET' === $method && function_exists( 'tw_supabase_get' ) ) {
			$parts = explode( '?', $endpoint, 2 );
			$table = $parts[0];
			$qs    = $parts[1] ?? '';
			$query = [];

			if ( $qs ) {
				parse_str( $qs, $query );
			}

			$data = tw_supabase_get( $table, $query );

			if ( ! is_array( $data ) ) {
				return [ 'ok' => false, 'code' => 0, 'data' => null, 'error' => 'tw_supabase_get returned non-array' ];
			}

			if ( isset( $data['code'], $data['message'] ) ) {
				return [ 'ok' => false, 'code' => (int) $data['code'], 'data' => null, 'error' => $data['message'] ];
			}

			return [ 'ok' => true, 'code' => 200, 'data' => $data, 'error' => null ];
		}

		/* --- POST/PATCH/DELETE: tw_supabase_request zwraca ['ok','code','data'] --- */
		if ( function_exists( 'tw_supabase_request' ) ) {
			$parts = explode( '?', $endpoint, 2 );
			$table = $parts[0];
			$qs    = $parts[1] ?? '';
			$query = [];

			if ( $qs ) {
				parse_str( $qs, $query );
			}

			$extra_args = [];
			if ( in_array( $method, [ 'POST', 'PATCH' ], true ) ) {
				$extra_args['headers']['Prefer'] = 'return=representation';
			}
			if ( 'DELETE' === $method ) {
				$extra_args['headers']['Prefer'] = '';
			}
			if ( ! empty( $extra_headers ) ) {
				$extra_args['headers'] = array_merge( $extra_args['headers'] ?? [], $extra_headers );
			}

			$res = tw_supabase_request( $method, $table, $query, empty( $body ) ? null : $body, $extra_args );

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

	/* ---------------------------------------------------------------- */
	/*  AJAX                                                             */
	/* ---------------------------------------------------------------- */

	public function ajax_get_all(): void {
		check_ajax_referer( 'neoweaver_achievements', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$raw_cat = sanitize_text_field( wp_unslash( $_POST['filter_category'] ?? '' ) );
$raw_sc  = sanitize_text_field( wp_unslash( $_POST['filter_scope'] ?? '' ) );

		$cat = in_array( $raw_cat, self::CATEGORIES, true ) ? $raw_cat : '';
		$sc  = in_array( $raw_sc, self::SCOPES, true ) ? $raw_sc : '';

		$qs = 'cyber_achievements?select=id,title,description,icon_slug,bg_color,scope,goal,hidden_until_earned,category,is_active&order=category.asc,title.asc&limit=1000';

		if ( $cat ) {
			$qs .= '&category=eq.' . rawurlencode( $cat );
		}

		if ( $sc ) {
			$qs .= '&scope=eq.' . rawurlencode( $sc );
		}

		$res = $this->supa( 'GET', $qs );

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Unknown error' );
			return;
		}

		wp_send_json_success( $res['data'] ?? [] );
	}

	public function ajax_save(): void {
		check_ajax_referer( 'neoweaver_achievements', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$raw = $_POST['achievement'] ?? [];

$orig_id = sanitize_text_field( wp_unslash( $raw['original_id'] ?? '' ) );
$new_id  = sanitize_text_field( wp_unslash( $raw['id'] ?? '' ) );

if ( ! $new_id ) {
	wp_send_json_error( 'ID (slug) is required.' );
	return;
}

if ( $orig_id && $orig_id !== $new_id ) {
	wp_send_json_error( 'Changing achievement ID is not allowed.' );
	return;
}

		$scope = sanitize_text_field( $raw['scope'] ?? 'account' );
		$cat   = sanitize_text_field( $raw['category'] ?? '' );

		$payload = [
			'id'                  => $new_id,
			'title'               => sanitize_text_field( $raw['title'] ?? '' ),
			'description'         => sanitize_textarea_field( $raw['description'] ?? '' ) ?: null,
			'icon_slug'           => sanitize_text_field( $raw['icon_slug'] ?? 'trophy' ) ?: 'trophy',
			'bg_color'            => sanitize_hex_color( $raw['bg_color'] ?? '#2c3e50' ) ?: '#2c3e50',
			'scope'               => in_array( $scope, self::SCOPES, true ) ? $scope : 'account',
			'goal'                => max( 1, (int) ( $raw['goal'] ?? 1 ) ),
			'hidden_until_earned' => filter_var( $raw['hidden_until_earned'] ?? false, FILTER_VALIDATE_BOOLEAN ),
			'category'            => ( $cat && in_array( $cat, self::CATEGORIES, true ) ) ? $cat : null,
			'is_active'           => filter_var( $raw['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN ),
		];

		if ( empty( $payload['title'] ) ) {
			wp_send_json_error( 'Title is required.' );
			return;
		}

		$endpoint = $orig_id
			? 'cyber_achievements?id=eq.' . rawurlencode( $orig_id )
			: 'cyber_achievements';

		$res = $this->supa( $orig_id ? 'PATCH' : 'POST', $endpoint, $payload );

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Supabase error ' . ( $res['code'] ?? 0 ) );
			return;
		}

		$saved = $res['data'];
		wp_send_json_success( is_array( $saved ) ? ( $saved[0] ?? $saved ) : $saved );
	}

	public function ajax_toggle(): void {
		check_ajax_referer( 'neoweaver_achievements', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id    = sanitize_text_field( $_POST['achievement_id'] ?? '' );
		$state = filter_var( $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );

		if ( ! $id ) {
			wp_send_json_error( 'Missing ID' );
			return;
		}

		$res = $this->supa(
			'PATCH',
			'cyber_achievements?id=eq.' . rawurlencode( $id ),
			[ 'is_active' => $state ]
		);

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Toggle failed' );
			return;
		}

		wp_send_json_success( [ 'is_active' => $state ] );
	}

	public function ajax_delete(): void {
		check_ajax_referer( 'neoweaver_achievements', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id = sanitize_text_field( $_POST['achievement_id'] ?? '' );

		if ( ! $id ) {
			wp_send_json_error( 'Missing ID' );
			return;
		}

		$res = $this->supa(
			'DELETE',
			'cyber_achievements?id=eq.' . rawurlencode( $id )
		);

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Delete failed' );
			return;
		}

		wp_send_json_success( 'deleted' );
	}

	/* ---------------------------------------------------------------- */
	/*  RENDER                                                           */
	/* ---------------------------------------------------------------- */

	public function render_page(): void { ?>
		<div class="wrap nw-panel" id="nw-achievements-panel">
			<div class="nw-panel-header">
				<h1 class="nw-panel-title"><span class="nw-accent">Neo</span>Weaver <span class="nw-panel-subtitle">/ Achievements</span></h1>
				<div class="nw-header-actions">
					<select id="nw-filter-category" class="nw-select-filter">
						<option value="">All categories</option>
						<?php foreach ( self::CATEGORIES as $c ) : ?>
							<option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( ucfirst( $c ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<select id="nw-filter-scope" class="nw-select-filter">
						<option value="">All scopes</option>
						<?php foreach ( self::SCOPES as $s ) : ?>
							<option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( ucfirst( $s ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<select id="nw-filter-active" class="nw-select-filter">
						<option value="">Active &amp; Inactive</option>
						<option value="1">Active only</option>
						<option value="0">Inactive only</option>
					</select>
					<select id="nw-filter-hidden" class="nw-select-filter">
						<option value="">All visibility</option>
						<option value="1">Hidden until earned</option>
						<option value="0">Always visible</option>
					</select>
					<input type="text" id="nw-search" class="nw-search-input" placeholder="Search id or title&hellip;">
					<button class="nw-btn nw-btn-ghost" id="nw-refresh-btn">&#8635; Refresh</button>
					<button class="nw-btn nw-btn-primary" id="nw-add-btn">+ New Achievement</button>
				</div>
			</div>

			<div id="nw-notice" class="nw-notice" style="display:none;"></div>

			<div class="nw-stats-bar">
				<span class="nw-stat-pill">Total: <strong id="nw-total">&mdash;</strong></span>
				<span class="nw-stat-pill nw-pill-active">Active: <strong id="nw-active">&mdash;</strong></span>
				<span class="nw-stat-pill nw-pill-inactive">Inactive: <strong id="nw-inactive">&mdash;</strong></span>
				<span class="nw-stat-pill nw-pill-account">Account: <strong id="nw-count-account">&mdash;</strong></span>
				<span class="nw-stat-pill nw-pill-character">Character: <strong id="nw-count-character">&mdash;</strong></span>
				<span class="nw-stat-pill nw-pill-hidden">Hidden: <strong id="nw-count-hidden">&mdash;</strong></span>
			</div>

			<div class="nw-table-wrap">
				<table class="nw-table">
					<thead><tr>
						<th class="nw-col-icon">Icon</th>
						<th>ID / Title</th>
						<th>Category</th>
						<th>Scope</th>
						<th>Goal</th>
						<th>Hidden</th>
						<th>Active</th>
						<th>Actions</th>
					</tr></thead>
					<tbody id="nw-achievements-tbody">
						<tr><td colspan="8" style="text-align:center;padding:32px;color:#555;"><div class="nw-spinner"></div> Loading&hellip;</td></tr>
					</tbody>
				</table>
			</div>

			<div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
				<div class="nw-modal">
					<div class="nw-modal-header">
						<h2 id="nw-modal-title">Edit Achievement</h2>
						<button class="nw-modal-close" id="nw-modal-close">&#x2715;</button>
					</div>
					<div class="nw-modal-body">
						<form id="nw-achievement-form">
							<input type="hidden" id="nw-field-original_id" name="original_id">

							<div class="nw-section-label">Identity</div>
							<div class="nw-form-grid">
								<div class="nw-field">
									<label>ID (slug) <span class="nw-req">*</span> <span class="nw-hint">primary key</span></label>
									<input type="text" id="nw-field-id" name="id" required placeholder="e.g. first_login">
								</div>
								<div class="nw-field">
									<label>Title <span class="nw-req">*</span></label>
									<input type="text" id="nw-field-title" name="title" required placeholder="e.g. The Pioneer">
								</div>
								<div class="nw-field nw-field-full">
									<label>Description</label>
									<textarea id="nw-field-description" name="description" rows="3" placeholder="Shown to player when earned&hellip;"></textarea>
								</div>
							</div>

							<div class="nw-section-label">Appearance</div>
							<div class="nw-form-grid">
								<div class="nw-field">
									<label>Icon Slug <span class="nw-hint">(emoji or Lucide slug e.g. trophy, compass, zap)</span></label>
									<div class="nw-icon-input-row">
										<span id="nw-icon-preview" class="nw-icon-preview"><i data-lucide="trophy"></i></span>
										<input type="text" id="nw-field-icon_slug" name="icon_slug" placeholder="e.g. trophy">
									</div>
								</div>
								<div class="nw-field">
									<label>Background Color</label>
									<div class="nw-color-row">
										<input type="color" id="nw-field-bg_color_picker" class="nw-color-picker" value="#2c3e50">
										<input type="text" id="nw-field-bg_color" name="bg_color" placeholder="#2c3e50" class="nw-color-text">
									</div>
								</div>
							</div>

							<div class="nw-section-label">Classification</div>
							<div class="nw-form-grid">
								<div class="nw-field">
									<label>Scope <span class="nw-req">*</span></label>
									<select id="nw-field-scope" name="scope" class="nw-select">
										<?php foreach ( self::SCOPES as $s ) : ?>
											<option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( ucfirst( $s ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="nw-field">
									<label>Category</label>
									<select id="nw-field-category" name="category" class="nw-select">
										<option value="">&mdash; None &mdash;</option>
										<?php foreach ( self::CATEGORIES as $c ) : ?>
											<option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( ucfirst( $c ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="nw-field">
									<label>Goal <span class="nw-hint">(threshold count)</span></label>
									<input type="number" id="nw-field-goal" name="goal" min="1" value="1">
								</div>
							</div>

							<div class="nw-section-label">Visibility &amp; Status</div>
							<div class="nw-form-grid">
								<div class="nw-field nw-field-toggles">
									<div class="nw-toggle-row">
										<label class="nw-toggle-label">
											<span class="nw-toggle">
												<input type="checkbox" id="nw-field-hidden_until_earned" name="hidden_until_earned">
												<span class="nw-toggle-slider nw-toggle-orange"></span>
											</span>
											<span>Hidden until earned</span>
										</label>
										<label class="nw-toggle-label">
											<span class="nw-toggle">
												<input type="checkbox" id="nw-field-is_active" name="is_active" checked>
												<span class="nw-toggle-slider"></span>
											</span>
											<span>Active</span>
										</label>
									</div>
								</div>
							</div>

							<div class="nw-section-label">Badge Preview</div>
							<div class="nw-badge-preview">
								<div class="nw-badge-icon" id="nw-badge-icon"><i data-lucide="trophy"></i></div>
								<div>
									<div class="nw-badge-title" id="nw-preview-title">Achievement Title</div>
									<div class="nw-badge-desc" id="nw-preview-desc">Description&hellip;</div>
								</div>
							</div>
						</form>
					</div>
					<div class="nw-modal-footer">
						<button class="nw-btn nw-btn-danger" id="nw-delete-btn" style="display:none;margin-right:auto;">&#128465; Delete</button>
						<button class="nw-btn nw-btn-ghost" id="nw-cancel-btn">Cancel</button>
						<button class="nw-btn nw-btn-primary" id="nw-save-btn"><span id="nw-save-label">Save Achievement</span></button>
					</div>
				</div>
			</div>
		</div>
	<?php }
}
