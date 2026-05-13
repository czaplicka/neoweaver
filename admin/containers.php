<?php
/**
 * NeoWeaver Admin Panel — Containers (cyber_containers)
 * Columns: id, name, description, total_slots, allowed_sizes,
 *          img_url, rarity, is_active, created_at, parent_id
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NeoWeaver_Containers_Admin {

	private string $page_slug     = 'neoweaver-containers';
	private string $menu_slug     = 'neoweaver';
	private string $table         = 'cyber_containers';
	private string $nonce_action  = 'neoweaver_containers';

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_nw_containers_get_all', [ $this, 'ajax_get_all' ] );
		add_action( 'wp_ajax_nw_containers_save',    [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_containers_toggle',  [ $this, 'ajax_toggle' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			$this->menu_slug,
			'NeoWeaver — Containers',
			'📦 Containers',
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, $this->page_slug ) ) {
			return;
		}

		wp_enqueue_style(
			'nw-admin-core',
			NEOWEAVER_PLUGIN_URL . 'assets/css/admin/admin-core.css',
			[ 'nw-font-chakra-petch' ],
			NEOWEAVER_VERSION
		);

		wp_enqueue_style(
			'nw-containers-style',
			NEOWEAVER_PLUGIN_URL . 'assets/css/admin/containers.css',
			[ 'nw-font-chakra-petch', 'nw-admin-core' ],
			NEOWEAVER_VERSION
		);

		wp_enqueue_script(
			'nw-containers-script',
			NEOWEAVER_PLUGIN_URL . 'assets/js/admin/containers.js',
			[ 'jquery', 'nw-lucide' ],
			NEOWEAVER_VERSION,
			true
		);

		wp_localize_script(
			'nw-containers-script',
			'NWContainers',
			[
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( $this->nonce_action ),
			]
		);
	}

	/* ---------------------------------------------------------------- */
	/*  RENDER                                                           */
	/* ---------------------------------------------------------------- */

	public function render_page(): void {
		?>
		<div class="wrap nw-panel" id="nw-containers-panel">
			<div class="nw-panel-header">
				<h1 class="nw-panel-title"><span class="nw-accent">Neo</span>Weaver <span class="nw-panel-subtitle">/ Containers</span></h1>
				<div class="nw-header-actions">
					<button class="nw-btn nw-btn-ghost" id="nw-refresh-btn">↻ Refresh</button>
					<button class="nw-btn nw-btn-primary" id="nw-add-btn">+ New Container</button>
				</div>
			</div>

			<div id="nw-notice" class="nw-notice" style="display:none;"></div>

			<div class="nw-stats-bar">
				<span class="nw-stat-pill">Total: <strong id="nw-total">—</strong></span>
				<span class="nw-stat-pill nw-pill-active">Active: <strong id="nw-active">—</strong></span>
				<span class="nw-stat-pill nw-pill-inactive">Inactive: <strong id="nw-inactive">—</strong></span>
			</div>

			<div class="nw-table-wrap">
				<table class="nw-table">
					<thead>
						<tr>
							<th class="nw-col-img"></th>
							<th>Name</th>
							<th>Rarity</th>
							<th>Slots</th>
							<th>Allowed Sizes</th>
							<th>Active</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody id="nw-containers-tbody">
						<tr class="nw-loading-row">
							<td colspan="7"><div class="nw-spinner"></div> Loading…</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
				<div class="nw-modal">
					<div class="nw-modal-header">
						<h2 id="nw-modal-title">Edit Container</h2>
						<button class="nw-modal-close" id="nw-modal-close">✕</button>
					</div>
					<div class="nw-modal-body">
						<form id="nw-container-form">
							<input type="hidden" id="nw-field-id" name="id">

							<div class="nw-form-grid">
								<div class="nw-field nw-field-full">
									<label>Name <span class="nw-req">*</span></label>
									<input type="text" id="nw-field-name" name="name" required placeholder="e.g. Tech Backpack">
								</div>

								<div class="nw-field nw-field-full">
									<label>Description</label>
									<textarea id="nw-field-description" name="description" rows="3"></textarea>
								</div>

								<div class="nw-field">
									<label>Image filename / URL</label>
									<input type="text" id="nw-field-img_url" name="img_url" placeholder="e.g. backpack.svg">
								</div>

								<div class="nw-field">
									<label>Rarity</label>
									<select id="nw-field-rarity" name="rarity">
										<option value="common">Common</option>
										<option value="uncommon">Uncommon</option>
										<option value="rare">Rare</option>
										<option value="epic">Epic</option>
										<option value="legendary">Legendary</option>
									</select>
								</div>

								<div class="nw-field">
									<label>Total Slots</label>
									<div class="nw-stat-slider-row">
										<input type="range" id="nw-field-total_slots" name="total_slots" min="1" max="50" value="5" class="nw-range">
										<span class="nw-range-val" id="nw-val-total_slots">5</span>
									</div>
								</div>

								<div class="nw-field">
									<label>Parent Item ID <span class="nw-hint">(UUID, optional)</span></label>
									<input type="text" id="nw-field-parent_id" name="parent_id" placeholder="UUID or leave empty">
								</div>

								<div class="nw-field nw-field-full">
									<label>Allowed Sizes <span class="nw-hint">(check all that apply)</span></label>
									<div class="nw-checkbox-group">
										<?php foreach ( [ 'tiny', 'small', 'medium', 'large' ] as $sz ) : ?>
											<label class="nw-check-label">
												<input type="checkbox" name="allowed_sizes[]" value="<?php echo esc_attr( $sz ); ?>" checked>
												<?php echo esc_html( ucfirst( $sz ) ); ?>
											</label>
										<?php endforeach; ?>
									</div>
								</div>

								<div class="nw-field nw-field-center">
									<label>Active</label>
									<label class="nw-toggle">
										<input type="checkbox" id="nw-field-is_active" name="is_active" checked>
										<span class="nw-toggle-slider"></span>
									</label>
								</div>
							</div>
						</form>
					</div>
					<div class="nw-modal-footer">
						<button class="nw-btn nw-btn-ghost" id="nw-cancel-btn">Cancel</button>
						<button class="nw-btn nw-btn-primary" id="nw-save-btn"><span id="nw-save-label">Save Container</span></button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- */
	/*  SUPABASE                                                         */
	/* ---------------------------------------------------------------- */

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

			$res = tw_supabase_request(
				$method,
				$table,
				$query,
				empty( $body ) ? null : $body,
				$extra_args
			);

			$ok   = $res['ok']   ?? false;
			$code = $res['code'] ?? 0;
			$data = $res['data'] ?? null;

			if ( ! $ok ) {
				$msg = is_array( $data )
					? ( $data['message'] ?? 'Supabase error ' . $code )
					: 'Supabase error ' . $code;

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

	/* ---------------------------------------------------------------- */
	/*  NORMALIZATION                                                    */
	/* ---------------------------------------------------------------- */

	private function valid_rarity( string $rarity ): string {
		$allowed = [ 'common', 'uncommon', 'rare', 'epic', 'legendary' ];
		return in_array( $rarity, $allowed, true ) ? $rarity : 'common';
	}

	private function valid_sizes( $sizes_raw ): array {
		$valid = [ 'tiny', 'small', 'medium', 'large' ];

		if ( is_string( $sizes_raw ) ) {
			$sizes = array_map( 'trim', explode( ',', $sizes_raw ) );
		} elseif ( is_array( $sizes_raw ) ) {
			$sizes = array_map( 'sanitize_text_field', $sizes_raw );
		} else {
			$sizes = [];
		}

		$sizes = array_values( array_intersect( $sizes, $valid ) );

		if ( empty( $sizes ) ) {
			$sizes = $valid;
		}

		return $sizes;
	}

	private function maybe_uuid( string $value ): ?string {
		$value = trim( $value );

		if ( '' === $value ) {
			return null;
		}

		return preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$value
		) ? $value : null;
	}

	/* ---------------------------------------------------------------- */
	/*  AJAX                                                             */
	/* ---------------------------------------------------------------- */

	public function ajax_get_all(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$res = $this->supa(
			'GET',
			$this->table . '?select=id,name,description,total_slots,allowed_sizes,img_url,rarity,is_active,created_at,parent_id&order=name.asc'
		);

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Failed to fetch containers.' );
			return;
		}

		wp_send_json_success( $res['data'] ?? [] );
	}

	public function ajax_save(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$raw = isset( $_POST['container'] ) && is_array( $_POST['container'] )
			? wp_unslash( $_POST['container'] )
			: [];

		$id          = sanitize_text_field( $raw['id'] ?? '' );
		$name        = sanitize_text_field( $raw['name'] ?? '' );
		$description = sanitize_textarea_field( $raw['description'] ?? '' );
		$img_url     = sanitize_text_field( $raw['img_url'] ?? '' );
		$rarity      = $this->valid_rarity( sanitize_text_field( $raw['rarity'] ?? 'common' ) );
		$total_slots = max( 1, (int) ( $raw['total_slots'] ?? 5 ) );
		$parent_id   = $this->maybe_uuid( sanitize_text_field( $raw['parent_id'] ?? '' ) );
		$is_active   = filter_var( $raw['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$allowed     = $this->valid_sizes( $raw['allowed_sizes'] ?? [] );

		if ( '' === $name ) {
			wp_send_json_error( 'Name is required.' );
			return;
		}

		$payload = [
			'name'          => $name,
			'description'   => '' !== $description ? $description : null,
			'img_url'       => '' !== $img_url ? $img_url : null,
			'rarity'        => $rarity,
			'total_slots'   => $total_slots,
			'allowed_sizes' => $allowed,
			'parent_id'     => $parent_id,
			'is_active'     => $is_active,
		];

		$res = $id
			? $this->supa( 'PATCH', $this->table . '?id=eq.' . rawurlencode( $id ), $payload )
			: $this->supa( 'POST', $this->table, $payload );

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Save failed.' );
			return;
		}

		wp_send_json_success( is_array( $res['data'] ) ? ( $res['data'][0] ?? $res['data'] ) : $res['data'] );
	}

	public function ajax_toggle(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id    = sanitize_text_field( wp_unslash( $_POST['container_id'] ?? '' ) );
		$state = filter_var( wp_unslash( $_POST['is_active'] ?? false ), FILTER_VALIDATE_BOOLEAN );

		if ( ! $id ) {
			wp_send_json_error( 'Missing ID' );
			return;
		}

		$res = $this->supa(
			'PATCH',
			$this->table . '?id=eq.' . rawurlencode( $id ),
			[ 'is_active' => $state ]
		);

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Toggle failed.' );
			return;
		}

		wp_send_json_success(
			[
				'id'        => $id,
				'is_active' => $state,
			]
		);
	}
}
