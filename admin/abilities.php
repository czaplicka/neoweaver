<?php
/**
 * NeoWeaver Admin Panel — Abilities
 * Table: cyber_abilities
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'NWAbilitiesAdmin', false ) ) {
	return;
}

class NWAbilitiesAdmin {

	private string $page_slug    = 'nw-abilities';
	private string $table        = 'cyber_abilities';
	private string $nonce_action = 'nw_abilities_nonce';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_nw_abilities_load', [ $this, 'ajax_load' ] );
		add_action( 'wp_ajax_nw_abilities_save', [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_abilities_delete', [ $this, 'ajax_delete' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'neoweaver',
			'NeoWeaver Abilities',
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
			NW_PLUGIN_URL . 'assets/css/admin/admin-core.css',
			[ 'chakra-petch' ],
			NW_VERSION
		);

		wp_enqueue_style(
			'nw-abilities-style',
			NW_PLUGIN_URL . 'assets/css/admin/abilities.css',
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
			'nw-abilities-script',
			NW_PLUGIN_URL . 'assets/js/admin/abilities.js',
			[ 'jquery', 'lucide' ],
			NW_VERSION,
			true
		);

		$uploads = wp_upload_dir();

		wp_localize_script(
			'nw-abilities-script',
			'NWAbilities',
			[
				'ajaxurl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( $this->nonce_action ),
				'uploads_url' => isset( $uploads['baseurl'] ) ? untrailingslashit( $uploads['baseurl'] ) : '',
			]
		);
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
				return [
					'ok'    => false,
					'code'  => 0,
					'data'  => null,
					'error' => 'tw_supabase_get returned non-array',
				];
			}

			if ( isset( $data['code'], $data['message'] ) ) {
				return [
					'ok'    => false,
					'code'  => (int) $data['code'],
					'data'  => null,
					'error' => $data['message'],
				];
			}

			return [
				'ok'    => true,
				'code'  => 200,
				'data'  => $data,
				'error' => null,
			];
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
			$ok   = $res['ok'] ?? false;
			$code = $res['code'] ?? 0;
			$data = $res['data'] ?? null;

			if ( ! $ok ) {
				$msg = is_array( $data ) ? ( $data['message'] ?? 'Supabase error ' . $code ) : 'Supabase error ' . $code;

				return [
					'ok'    => false,
					'code'  => $code,
					'data'  => $data,
					'error' => $msg,
				];
			}

			return [
				'ok'    => true,
				'code'  => $code,
				'data'  => $data,
				'error' => null,
			];
		}

		return [
			'ok'    => false,
			'code'  => 0,
			'data'  => null,
			'error' => 'Supabase helper functions not available.',
		];
	}

	private function get_cache_key( string $suffix ): string {
		return 'nw_' . md5( $suffix );
	}

	private function bust_cache( string $scope ): void {
		delete_transient( $this->get_cache_key( $scope . '_all' ) );
	}

	private function cached_get_all( string $table, string $order_by = 'sort_order' ): array {
		$cache_key = $this->get_cache_key( $table . '_all' );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$res = $this->supa(
			'GET',
			$table . '?select=*&order=' . rawurlencode( $order_by ) . '.asc',
			[],
			$this->sk()
		);

		if ( ! $res['ok'] ) {
			return [ 'error' => $res['error'] ?? 'Failed to fetch records.' ];
		}

		$rows = is_array( $res['data'] ) ? $res['data'] : [];
		set_transient( $cache_key, $rows, MINUTE_IN_SECONDS * 5 );

		return $rows;
	}

	private function is_uuid( string $value ): bool {
		return (bool) preg_match(
			'/^[0-9a-fA-F]{8}\-[0-9a-fA-F]{4}\-[1-5][0-9a-fA-F]{3}\-[89abAB][0-9a-fA-F]{3}\-[0-9a-fA-F]{12}$/',
			$value
		);
	}

	private function parse_tags( string $raw ): array {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return [];
		}

		$tags = array_map(
			static fn( $tag ) => sanitize_text_field( trim( $tag ) ),
			explode( ',', $raw )
		);

		return array_values(
			array_filter(
				array_unique( $tags ),
				static fn( $tag ) => '' !== $tag
			)
		);
	}

	private function bool_from_post( string $key, bool $default = false ): bool {
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}

		return (bool) intval( wp_unslash( $_POST[ $key ] ) );
	}

	public function ajax_load(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$rows = $this->cached_get_all( $this->table, 'sort_order' );

if ( isset( $rows['error'] ) ) {
	error_log( 'NW abilities load error: ' . print_r( $rows, true ) );
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

		$id            = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$name          = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$description   = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$ability_type  = sanitize_text_field( wp_unslash( $_POST['ability_type'] ?? '' ) );
		$source        = sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) );
		$gm_notes      = sanitize_textarea_field( wp_unslash( $_POST['gm_notes'] ?? '' ) );
		$cost          = sanitize_text_field( wp_unslash( $_POST['cost'] ?? '' ) );
		$img_url       = esc_url_raw( wp_unslash( $_POST['img_url'] ?? '' ) );
		$tags_raw      = sanitize_text_field( wp_unslash( $_POST['tags'] ?? '' ) );

		$cost_type      = sanitize_text_field( wp_unslash( $_POST['cost_type'] ?? 'none' ) );
		$cost_value     = max( 0, intval( wp_unslash( $_POST['cost_value'] ?? 0 ) ) );
		$target_type    = sanitize_text_field( wp_unslash( $_POST['target_type'] ?? 'self' ) );
		$range_tiles    = max( 0, intval( wp_unslash( $_POST['range_tiles'] ?? 1 ) ) );
		$duration_turns = max( 0, intval( wp_unslash( $_POST['duration_turns'] ?? 0 ) ) );
		$sort_order     = max( 0, intval( wp_unslash( $_POST['sort_order'] ?? 0 ) ) );

		$is_passive = $this->bool_from_post( 'is_passive', false );
		$is_active  = $this->bool_from_post( 'is_active', true );

		if ( ! $name ) {
			wp_send_json_error( 'Name is required.' );
			return;
		}

		if ( $id && ! $this->is_uuid( $id ) ) {
			wp_send_json_error( 'Invalid ability ID.' );
			return;
		}

		$allowed_cost_types   = [ 'none', 'mana', 'energy', 'hp', 'ap', 'charge', 'custom' ];
		$allowed_target_types = [ 'self', 'ally', 'enemy', 'area', 'object', 'global' ];

		if ( ! in_array( $cost_type, $allowed_cost_types, true ) ) {
			$cost_type = 'none';
		}

		if ( ! in_array( $target_type, $allowed_target_types, true ) ) {
			$target_type = 'self';
		}

		$payload = [
			'name'           => $name,
			'description'    => '' !== $description ? $description : null,
			'ability_type'   => '' !== $ability_type ? $ability_type : null,
			'source'         => '' !== $source ? $source : null,
			'gm_notes'       => '' !== $gm_notes ? $gm_notes : null,
			'cost'           => '' !== $cost ? $cost : null,
			'img_url'        => '' !== $img_url ? $img_url : null,
			'tags'           => $this->parse_tags( $tags_raw ),
			'cost_type'      => $cost_type,
			'cost_value'     => $cost_value,
			'target_type'    => $target_type,
			'range_tiles'    => $range_tiles,
			'duration_turns' => $duration_turns,
			'is_passive'     => $is_passive,
			'is_active'      => $is_active,
			'sort_order'     => $sort_order,
		];

		if ( $id ) {
			$res = $this->supa(
				'PATCH',
				$this->table . '?id=eq.' . rawurlencode( $id ),
				$payload,
				$this->sk()
			);
		} else {
			$res = $this->supa(
				'POST',
				$this->table,
				$payload,
				$this->sk()
			);
		}

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Save failed.' );
			return;
		}

		$item = is_array( $res['data'] ) ? ( $res['data'][0] ?? $res['data'] ) : $res['data'];

		$this->bust_cache( $this->table );

		wp_send_json_success( $item );
	}

	public function ajax_delete(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );

		if ( ! $id ) {
			wp_send_json_error( 'Missing ID.' );
			return;
		}

		if ( ! $this->is_uuid( $id ) ) {
			wp_send_json_error( 'Invalid ability ID.' );
			return;
		}

		$res = $this->supa(
			'DELETE',
			$this->table . '?id=eq.' . rawurlencode( $id ),
			[],
			$this->sk()
		);

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Delete failed.' );
			return;
		}

		$this->bust_cache( $this->table );

		wp_send_json_success( 'deleted' );
	}

	public function render_page(): void {
		?>
		<div class="wrap nw-panel" id="nw-abilities-panel">

			<div class="nw-panel-header">
				<h1 class="nw-panel-title">
					<span class="nw-accent">Neo</span>Weaver
					<span class="nw-panel-subtitle">Abilities</span>
				</h1>

				<div class="nw-header-actions">
					<input type="text" id="nw-search" class="nw-search-input" placeholder="Search abilities, tags, source...">

					<select id="nw-filter-ability-type" class="nw-select-filter">
						<option value="">All types</option>
					</select>

					<select id="nw-filter-cost-type" class="nw-select-filter">
						<option value="">All cost types</option>
					</select>

					<select id="nw-filter-target-type" class="nw-select-filter">
						<option value="">All targets</option>
					</select>

					<select id="nw-filter-status" class="nw-select-filter">
						<option value="">All statuses</option>
						<option value="active">Active only</option>
						<option value="inactive">Inactive only</option>
						<option value="passive">Passive only</option>
						<option value="non-passive">Non-passive only</option>
					</select>

					<button class="nw-btn nw-btn-ghost" id="nw-refresh-btn">↻ Refresh</button>
					<button class="nw-btn nw-btn-ghost" id="nw-reset-filters-btn">Reset</button>
					<button class="nw-btn nw-btn-primary" id="nw-add-btn">New Ability</button>
				</div>
			</div>

			<div id="nw-notice" class="nw-notice" style="display:none"></div>

			<div class="nw-stats-bar">
				<span class="nw-stat-pill">Total <strong id="nw-total">0</strong></span>
				<span class="nw-stat-pill nw-pill-active">Active <strong id="nw-active">0</strong></span>
				<span class="nw-stat-pill nw-pill-inactive">Inactive <strong id="nw-inactive">0</strong></span>
				<span class="nw-stat-pill nw-pill-passive">Passive <strong id="nw-passive">0</strong></span>
			</div>

			<div class="nw-table-wrap">
				<table class="nw-table">
					<thead>
						<tr>
							<th class="nw-col-img">Image</th>
							<th>Name</th>
							<th>Type</th>
							<th>Target</th>
							<th>Cost</th>
							<th>Range</th>
							<th>Duration</th>
							<th>Status</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody id="nw-abilities-tbody">
						<tr class="nw-loading-row">
							<td colspan="9">
								<div class="nw-spinner"></div>
								Loading abilities…
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
				<div class="nw-modal">
					<div class="nw-modal-header">
						<h2 id="nw-modal-title">Edit Ability</h2>
						<button class="nw-modal-close" id="nw-modal-close">✕</button>
					</div>

					<div class="nw-modal-body">
						<form id="nw-ability-form">
							<input type="hidden" id="nw-field-id" name="id">

							<div class="nw-section-label">Identity</div>
							<div class="nw-form-grid">
								<div class="nw-field">
									<label for="nw-field-name">Ability Name <span class="nw-req">*</span></label>
									<input type="text" id="nw-field-name" name="name" required placeholder="e.g. Chain Lightning">
								</div>

								<div class="nw-field">
									<label for="nw-field-ability_type">Ability Type</label>
									<input type="text" id="nw-field-ability_type" name="ability_type" placeholder="e.g. spell, hack, attack, support">
								</div>

								<div class="nw-field">
									<label for="nw-field-source">Source</label>
									<input type="text" id="nw-field-source" name="source" placeholder="e.g. mage, relic, faction">
								</div>

								<div class="nw-field">
									<label for="nw-field-sort_order">Sort Order</label>
									<input type="number" id="nw-field-sort_order" name="sort_order" min="0" step="1" value="0">
								</div>

								<div class="nw-field nw-field-full">
									<label for="nw-field-description">Description</label>
									<textarea id="nw-field-description" name="description" rows="3" placeholder="Short player-facing description"></textarea>
								</div>

								<div class="nw-field nw-field-full">
									<label for="nw-field-tags">Tags <span class="nw-hint">comma-separated</span></label>
									<input type="text" id="nw-field-tags" name="tags" placeholder="e.g. arcane, shock, control">
								</div>

								<div class="nw-field nw-field-full">
									<label for="nw-field-img_url">Image URL</label>
									<input type="url" id="nw-field-img_url" name="img_url" placeholder="https://... or relative filename">
								</div>

								<div class="nw-field nw-field-full" id="nw-img-preview-wrap" style="display:none;">
									<label>Preview</label>
									<img id="nw-img-preview" src="" alt="Ability image preview" style="max-height:120px;border-radius:8px;">
								</div>
							</div>

							<div class="nw-section-label">Mechanics</div>
							<div class="nw-form-grid">
								<div class="nw-field">
									<label for="nw-field-cost_type">Cost Type</label>
									<select id="nw-field-cost_type" name="cost_type" class="nw-select">
										<option value="none">None</option>
										<option value="mana">Mana</option>
										<option value="energy">Energy</option>
										<option value="hp">HP</option>
										<option value="ap">AP</option>
										<option value="charge">Charge</option>
										<option value="custom">Custom</option>
									</select>
								</div>

								<div class="nw-field">
									<label for="nw-field-cost_value">Cost Value</label>
									<input type="number" id="nw-field-cost_value" name="cost_value" min="0" step="1" value="0">
								</div>

								<div class="nw-field">
									<label for="nw-field-target_type">Target Type</label>
									<select id="nw-field-target_type" name="target_type" class="nw-select">
										<option value="self">Self</option>
										<option value="ally">Ally</option>
										<option value="enemy">Enemy</option>
										<option value="area">Area</option>
										<option value="object">Object</option>
										<option value="global">Global</option>
									</select>
								</div>

								<div class="nw-field">
									<label for="nw-field-range_tiles">Range Tiles</label>
									<input type="number" id="nw-field-range_tiles" name="range_tiles" min="0" step="1" value="1">
								</div>

								<div class="nw-field">
									<label for="nw-field-duration_turns">Duration Turns</label>
									<input type="number" id="nw-field-duration_turns" name="duration_turns" min="0" step="1" value="0">
								</div>

								<div class="nw-field">
									<label for="nw-field-cost">Cost Label</label>
									<input type="text" id="nw-field-cost" name="cost" placeholder="e.g. 2 mana + 1 charge">
								</div>

								<div class="nw-field nw-field-full">
									<label for="nw-field-gm_notes">GM Notes</label>
									<textarea id="nw-field-gm_notes" name="gm_notes" rows="4" placeholder="Private GM/admin notes"></textarea>
								</div>

								<div class="nw-field nw-field-toggles">
									<div class="nw-toggle-row">
										<label class="nw-toggle-label">
											<span>Passive</span>
											<span class="nw-toggle">
												<input type="checkbox" id="nw-field-is_passive" name="is_passive" value="1">
												<span class="nw-toggle-slider"></span>
											</span>
										</label>

										<label class="nw-toggle-label">
											<span>Active</span>
											<span class="nw-toggle">
												<input type="checkbox" id="nw-field-is_active" name="is_active" value="1" checked>
												<span class="nw-toggle-slider nw-toggle-orange"></span>
											</span>
										</label>
									</div>
								</div>
							</div>
						</form>
					</div>

					<div class="nw-modal-footer">
						<button class="nw-btn nw-btn-danger" id="nw-delete-btn" style="display:none;margin-right:auto;">🗑 Delete</button>
						<button class="nw-btn nw-btn-ghost" id="nw-cancel-btn">Cancel</button>
						<button class="nw-btn nw-btn-primary" id="nw-save-btn">
							<span id="nw-save-label">Save Ability</span>
						</button>
					</div>
				</div>
			</div>

		</div>
		<?php
	}
}
