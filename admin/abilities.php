<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class NW_Abilities_Admin {

	private string $page_slug = 'nw-abilities';

	private const ABILITY_TYPES = [ 'active', 'passive', 'reaction', 'aura' ];
	private const COST_TYPES    = [ 'none', 'mana', 'stamina', 'hp', 'gold', 'action' ];
	private const TARGET_TYPES  = [ 'self', 'single', 'aoe', 'line', 'cone', 'all' ];

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_nw_abilities_get_all', [ $this, 'ajax_get_abilities' ] );
		add_action( 'wp_ajax_nw_abilities_toggle', [ $this, 'ajax_toggle_ability' ] );
		add_action( 'wp_ajax_nw_save_ability',      [ $this, 'ajax_save_ability'      ] );
		add_action( 'wp_ajax_nw_delete_ability',    [ $this, 'ajax_delete_ability'    ] );
		add_action( 'wp_ajax_nw_reorder_abilities', [ $this, 'ajax_reorder_abilities' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'neoweaver',
			'NeoWeaver — Abilities',
			'⚡ Abilities',
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
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/nw-admin-core.css',
			[ 'chakra-petch' ],
			NEOWEAVER_VERSION
		);

		wp_enqueue_style(
			'nw-abilities-style',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/abilities-admin.css',
			[ 'chakra-petch', 'nw-admin-core' ],
			NEOWEAVER_VERSION
		);

		wp_enqueue_script(
			'lucide',
			'https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js',
			[],
			'0.468.0',
			true
		);

		wp_enqueue_script(
			'nw-abilities-script',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/abilities-admin.js',
			[ 'jquery', 'lucide' ],
			NEOWEAVER_VERSION,
			true
		);

		wp_localize_script( 'nw-abilities-script', 'NWAbilities', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'neoweaver_abilities' ),
		] );
	}

	// ── Supabase helper ───────────────────────────────────────────────────

	private function supa( string $method, string $endpoint, array $body = [], array $extra_headers = [] ): array {
		$method = strtoupper( $method );

		if ( 'GET' === $method && function_exists( 'tw_supabase_get' ) ) {
			[ $table, $qs ] = array_pad( explode( '?', $endpoint, 2 ), 2, '' );
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

	// ── AJAX ──────────────────────────────────────────────────────────────

	public function ajax_get_abilities(): void {
		check_ajax_referer( 'neoweaver_abilities', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$res = $this->supa( 'GET', 'cyber_abilities?select=*&order=sort_order.asc,id.asc' );

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Failed to fetch abilities.' );
			return;
		}

		wp_send_json_success( $res['data'] ?? [] );
	}

	public function ajax_save_ability(): void {
		check_ajax_referer( 'neoweaver_abilities', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id             = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$name           = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$title          = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$description    = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$ability_type   = sanitize_text_field( wp_unslash( $_POST['ability_type'] ?? 'active' ) );
		$cost_type      = sanitize_text_field( wp_unslash( $_POST['cost_type'] ?? 'none' ) );
		$cost_value     = intval( $_POST['cost_value'] ?? 0 );
		$target_type    = sanitize_text_field( wp_unslash( $_POST['target_type'] ?? 'self' ) );
		$range_tiles    = intval( $_POST['range_tiles'] ?? 1 );
		$duration_turns = intval( $_POST['duration_turns'] ?? 0 );
		$is_passive     = (bool) intval( $_POST['is_passive'] ?? 0 );
		$is_active      = (bool) intval( $_POST['is_active'] ?? 1 );
		$tags           = sanitize_text_field( wp_unslash( $_POST['tags'] ?? '' ) );
		$img_url        = esc_url_raw( wp_unslash( $_POST['img_url'] ?? '' ) );
		$source         = sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) );
		$gm_notes       = sanitize_textarea_field( wp_unslash( $_POST['gm_notes'] ?? '' ) );

		if ( empty( $name ) && empty( $title ) ) {
			wp_send_json_error( 'Name or Title is required.' );
			return;
		}

		// Use title as name fallback and vice-versa.
		if ( empty( $name ) ) $name = $title;
		if ( empty( $title ) ) $title = $name;

		$payload = [
			'name'           => $name,
			'title'          => $title,
			'description'    => $description,
			'ability_type'   => $ability_type,
			'cost_type'      => $cost_type,
			'cost_value'     => $cost_value,
			'target_type'    => $target_type,
			'range_tiles'    => $range_tiles,
			'duration_turns' => $duration_turns,
			'is_passive'     => $is_passive,
			'is_active'      => $is_active,
			'tags'           => $tags,
			'img_url'        => $img_url ?: null,
			'source'         => $source ?: null,
			'gm_notes'       => $gm_notes ?: null,
		];

		if ( $id ) {
			$res = $this->supa( 'PATCH', 'cyber_abilities?id=eq.' . rawurlencode( $id ), $payload );
			if ( ! $res['ok'] ) {
				wp_send_json_error( $res['error'] ?? 'Update failed.' );
				return;
			}
			wp_send_json_success( [ 'action' => 'updated', 'id' => $id ] );
		} else {
			$res = $this->supa( 'POST', 'cyber_abilities', $payload );
			if ( ! $res['ok'] ) {
				wp_send_json_error( $res['error'] ?? 'Insert failed.' );
				return;
			}
			$created = $res['data'][0] ?? $res['data'];
			wp_send_json_success( [ 'action' => 'created', 'id' => $created['id'] ?? null ] );
		}
	}

	public function ajax_toggle_ability(): void {
		check_ajax_referer( 'neoweaver_abilities', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id        = sanitize_text_field( wp_unslash( $_POST['ability_id'] ?? '' ) );
		$is_active = (bool) intval( $_POST['is_active'] ?? 0 );

		if ( ! $id ) {
			wp_send_json_error( 'Invalid ID.' );
			return;
		}

		$res = $this->supa( 'PATCH', 'cyber_abilities?id=eq.' . rawurlencode( $id ), [ 'is_active' => $is_active ] );
		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Toggle failed.' );
			return;
		}

		wp_send_json_success( [ 'id' => $id, 'is_active' => $is_active ] );
	}

	public function ajax_delete_ability(): void {
		check_ajax_referer( 'neoweaver_abilities', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		if ( ! $id ) {
			wp_send_json_error( 'Invalid ID.' );
			return;
		}

		$res = $this->supa( 'DELETE', 'cyber_abilities?id=eq.' . rawurlencode( $id ) );
		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Delete failed.' );
			return;
		}

		wp_send_json_success( [ 'deleted' => $id ] );
	}

	public function ajax_reorder_abilities(): void {
		check_ajax_referer( 'neoweaver_abilities', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$order = isset( $_POST['order'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['order'] ) ) : [];
		if ( empty( $order ) ) {
			wp_send_json_error( 'No order data.' );
			return;
		}

		$errors = [];
		foreach ( $order as $position => $id ) {
			if ( ! $id ) continue;
			$res = $this->supa( 'PATCH', 'cyber_abilities?id=eq.' . rawurlencode( $id ), [ 'sort_order' => (int) $position ] );
			if ( ! $res['ok'] ) $errors[] = $id;
		}

		if ( $errors ) {
			wp_send_json_error( 'Reorder partially failed for IDs: ' . implode( ', ', $errors ) );
			return;
		}

		wp_send_json_success( 'Reordered.' );
	}

	/* ---------------------------------------------------------------- */
	/*  RENDER                                                           */
	/* ---------------------------------------------------------------- */

	public function render_page(): void { ?>
		<div class="wrap nw-panel" id="nw-abilities-panel">
			<div class="nw-panel-header">
				<h1 class="nw-panel-title"><span class="nw-accent">Neo</span>Weaver <span class="nw-panel-subtitle">/ Abilities</span></h1>
				<div class="nw-header-actions">
					<select id="nw-filter-type" class="nw-select-filter">
						<option value="">All types</option>
						<?php foreach ( self::ABILITY_TYPES as $t ) : ?>
							<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( ucfirst( $t ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<select id="nw-filter-active" class="nw-select-filter">
						<option value="">Active &amp; Inactive</option>
						<option value="1">Active only</option>
						<option value="0">Inactive only</option>
					</select>
					<input type="text" id="nw-search" class="nw-search-input" placeholder="Search id or title&hellip;">
					<button class="nw-btn nw-btn-ghost" id="nw-refresh-btn">&#8635; Refresh</button>
					<button class="nw-btn nw-btn-primary" id="nw-add-btn">+ New Ability</button>
				</div>
			</div>

			<div id="nw-notice" class="nw-notice" style="display:none;"></div>

			<div class="nw-stats-bar">
				<span class="nw-stat-pill">Total: <strong id="nw-total">&mdash;</strong></span>
				<span class="nw-stat-pill nw-pill-active">Active: <strong id="nw-active">&mdash;</strong></span>
				<span class="nw-stat-pill nw-pill-inactive">Inactive: <strong id="nw-inactive">&mdash;</strong></span>
				<?php foreach ( self::ABILITY_TYPES as $t ) : ?>
					<span class="nw-stat-pill"><?php echo esc_html( ucfirst( $t ) ); ?>: <strong id="nw-count-<?php echo esc_attr( $t ); ?>">&mdash;</strong></span>
				<?php endforeach; ?>
			</div>

			<div class="nw-table-wrap">
				<table class="nw-table">
					<thead><tr>
						<th>ID / Title</th>
						<th>Type</th>
						<th>Cost</th>
						<th>Target</th>
						<th>Range</th>
						<th>Duration</th>
						<th>Passive</th>
						<th>Active</th>
						<th>Actions</th>
					</tr></thead>
					<tbody id="nw-abilities-tbody">
						<tr><td colspan="9" style="text-align:center;padding:32px;color:#555;"><div class="nw-spinner"></div> Loading&hellip;</td></tr>
					</tbody>
				</table>
			</div>

			<div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
				<div class="nw-modal">
					<div class="nw-modal-header">
						<h2 id="nw-modal-title">Edit Ability</h2>
						<button class="nw-modal-close" id="nw-modal-close">&#x2715;</button>
					</div>
					<div class="nw-modal-body">
						<form id="nw-ability-form">
							<input type="hidden" id="nw-field-original_id" name="original_id">

							<div class="nw-section-label">Identity</div>
							<div class="nw-form-grid">
								<div class="nw-field">
									<label>ID (slug) <span class="nw-req">*</span></label>
									<input type="text" id="nw-field-id" name="id" required placeholder="e.g. fireball">
								</div>
								<div class="nw-field">
									<label>Title <span class="nw-req">*</span></label>
									<input type="text" id="nw-field-title" name="title" required placeholder="e.g. Fireball">
								</div>
								<div class="nw-field nw-field-full">
									<label>Description</label>
									<textarea id="nw-field-description" name="description" rows="3" placeholder="Ability description&hellip;"></textarea>
								</div>
								<div class="nw-field nw-field-full">
									<label>Image URL <span class="nw-hint">(optional)</span></label>
									<div class="nw-img-url-wrap">
										<input type="url" id="nw-field-img_url" name="img_url" placeholder="https://&hellip;" class="nw-img-url-input">
										<div class="nw-img-preview" id="nw-img-preview" style="display:none;">
											<img id="nw-img-preview-img" src="" alt="Preview" style="max-height:80px;border-radius:4px;margin-top:6px;">
										</div>
									</div>
								</div>
								<div class="nw-field nw-field-full">
									<label>Tags <span class="nw-hint">(comma-separated slugs)</span></label>
									<input type="text" id="nw-field-tags" name="tags" placeholder="e.g. fire,aoe,damage">
								</div>
								<div class="nw-field">
									<label>Source</label>
									<input type="text" id="nw-field-source" name="source" placeholder="e.g. Core Rulebook">
								</div>
								<div class="nw-field nw-field-full">
									<label>GM Notes <span class="nw-hint">(not visible to players)</span></label>
									<textarea id="nw-field-gm_notes" name="gm_notes" rows="2" placeholder="Internal notes&hellip;"></textarea>
								</div>
							</div>

							<div class="nw-section-label">Mechanics</div>
							<div class="nw-form-grid">
								<div class="nw-field">
									<label>Ability Type <span class="nw-req">*</span></label>
									<select id="nw-field-ability_type" name="ability_type" class="nw-select">
										<?php foreach ( self::ABILITY_TYPES as $t ) : ?>
											<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( ucfirst( $t ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="nw-field">
									<label>Cost Type</label>
									<select id="nw-field-cost_type" name="cost_type" class="nw-select">
										<?php foreach ( self::COST_TYPES as $c ) : ?>
											<option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( ucfirst( $c ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="nw-field">
									<label>Cost Value</label>
									<input type="number" id="nw-field-cost_value" name="cost_value" min="0" value="0">
								</div>
								<div class="nw-field">
									<label>Target Type</label>
									<select id="nw-field-target_type" name="target_type" class="nw-select">
										<?php foreach ( self::TARGET_TYPES as $tt ) : ?>
											<option value="<?php echo esc_attr( $tt ); ?>"><?php echo esc_html( ucfirst( $tt ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="nw-field">
									<label>Range (tiles)</label>
									<input type="number" id="nw-field-range_tiles" name="range_tiles" min="0" value="1">
								</div>
								<div class="nw-field">
									<label>Duration (turns)</label>
									<input type="number" id="nw-field-duration_turns" name="duration_turns" min="0" value="0">
								</div>
							</div>

							<div class="nw-section-label">Status</div>
							<div class="nw-form-grid">
								<div class="nw-field nw-field-toggles">
									<div class="nw-toggle-row">
										<label class="nw-toggle-label">
											<span class="nw-toggle">
												<input type="checkbox" id="nw-field-is_passive" name="is_passive">
												<span class="nw-toggle-slider nw-toggle-orange"></span>
											</span>
											<span>Passive ability</span>
										</label>
										<label class="nw-toggle-label">
											<span class="nw-toggle">
												<input type="checkbox" id="nw-field-is_active" name="is_active" checked>
												<span class="nw-toggle-slider"></span>
											</span>
											<span>Active (available in game)</span>
										</label>
									</div>
								</div>
							</div>
						</form>
					</div>
					<div class="nw-modal-footer">
						<button class="nw-btn nw-btn-danger" id="nw-delete-btn" style="display:none;margin-right:auto;">&#128465; Delete</button>
						<button class="nw-btn nw-btn-ghost" id="nw-cancel-btn">Cancel</button>
						<button class="nw-btn nw-btn-primary" id="nw-save-btn"><span id="nw-save-label">Save Ability</span></button>
					</div>
				</div>
			</div>
		</div>
	<?php }
}

add_action(
	'plugins_loaded',
	static function () {
		new NW_Abilities_Admin();
	},
	20
);
