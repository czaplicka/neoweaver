<?php
/**
 * NeoWeaver Admin — Items (cyber_items)
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NW_Items_Admin {

	private string $page_slug    = 'nw-items';
	private string $table        = 'cyber_items';
	private string $nonce_action = 'nw_items_nonce';

	private const RARITIES = [ 'common', 'uncommon', 'rare', 'epic', 'legendary' ];
	private const SIZES    = [ 'tiny', 'small', 'medium', 'large' ];
	private const SLOTS    = [ 'head', 'torso', 'hand_r', 'hand_l', 'belt', 'legs', 'pouch', 'neck', 'finger', 'none' ];

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_nw_items_load',             [ $this, 'ajax_load' ] );
		add_action( 'wp_ajax_nw_items_get',              [ $this, 'ajax_get' ] );
		add_action( 'wp_ajax_nw_items_save',             [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_items_toggle',           [ $this, 'ajax_toggle' ] );
		add_action( 'wp_ajax_nw_items_delete',           [ $this, 'ajax_delete' ] );
		add_action( 'wp_ajax_nw_items_get_archetypes',   [ $this, 'ajax_get_archetypes' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'neoweaver',
			'Items',
			'<span data-lucide-menu="sword"></span> Items',
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
			'nw-items-style',
			NEOWEAVER_PLUGIN_URL . 'assets/css/admin/items.css',
			[ 'nw-font-chakra-petch', 'nw-admin-core' ],
			NEOWEAVER_VERSION
		);

		wp_enqueue_script(
			'nw-items-script',
			NEOWEAVER_PLUGIN_URL . 'assets/js/admin/items.js',
			[ 'jquery', 'nw-lucide' ],
			NEOWEAVER_VERSION,
			true
		);

		wp_localize_script(
			'nw-items-script',
			'NWItems',
			[
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( $this->nonce_action ),
			]
		);
	}

	public function render_page(): void {
		?>
		<div class="wrap nw-admin-wrap nw-items-admin">
			<h1>Items</h1>

			<div id="nw-notice" style="display:none;margin:12px 0;padding:10px 12px;border-radius:8px;"></div>

			<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0 18px;">
				<input type="text" id="nw-search" class="regular-text" placeholder="Search items…">
				<select id="nw-filter-type">
					<option value="">All types</option>
				</select>
				<select id="nw-filter-rarity">
					<option value="">All rarities</option>
					<?php foreach ( self::RARITIES as $rarity ) : ?>
						<option value="<?php echo esc_attr( $rarity ); ?>"><?php echo esc_html( ucfirst( $rarity ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<select id="nw-filter-slot">
					<option value="">All slots</option>
					<?php foreach ( self::SLOTS as $slot ) : ?>
						<option value="<?php echo esc_attr( $slot ); ?>"><?php echo esc_html( $slot ); ?></option>
					<?php endforeach; ?>
				</select>
				<button id="nw-refresh-btn" class="button">Refresh</button>
				<button id="nw-add-btn" class="button button-primary">+ Add Item</button>
			</div>

			<div style="display:flex;gap:16px;margin-bottom:16px;">
				<div><strong>Total:</strong> <span id="nw-total">0</span></div>
				<div><strong>Active:</strong> <span id="nw-active-count">0</span></div>
				<div><strong>Restricted:</strong> <span id="nw-restricted-count">0</span></div>
			</div>

			<table class="wp-list-table widefat striped" id="nw-items-table">
				<thead>
					<tr>
						<th>Image</th>
						<th>Name</th>
						<th>Type</th>
						<th>Rarity</th>
						<th>Slot</th>
						<th>Size</th>
						<th>Price</th>
						<th>Archetype</th>
						<th>Active</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody id="nw-items-tbody">
					<tr><td colspan="10" style="text-align:center;padding:32px;">Loading…</td></tr>
				</tbody>
			</table>

			<!-- MODAL -->
			<div id="nw-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:9999;overflow-y:auto;padding:24px;">
				<div style="max-width:980px;margin:24px auto;background:#050505;color:#f3f3f3;border:1px solid #2b2b2b;border-radius:14px;padding:24px;position:relative;">
					<button id="nw-modal-close" style="position:absolute;right:12px;top:12px;background:none;border:0;color:#fff;font-size:22px;cursor:pointer;">✕</button>
					<h2 id="nw-modal-title" style="color:#fff;margin-top:0;">Add Item</h2>

					<input type="hidden" id="nw-field-id">

					<table class="form-table" role="presentation">
						<tr>
							<th><label for="nw-field-name">Name *</label></th>
							<td><input type="text" id="nw-field-name" class="regular-text"></td>
						</tr>
						<tr>
							<th><label for="nw-field-description">Description</label></th>
							<td><textarea id="nw-field-description" class="large-text" rows="3"></textarea></td>
						</tr>
						<tr>
							<th><label for="nw-field-type">Type</label></th>
							<td><input type="text" id="nw-field-type" class="regular-text" placeholder="e.g. weapon, potion, relic"></td>
						</tr>
						<tr>
							<th><label for="nw-field-rarity">Rarity</label></th>
							<td>
								<select id="nw-field-rarity">
									<?php foreach ( self::RARITIES as $rarity ) : ?>
										<option value="<?php echo esc_attr( $rarity ); ?>"><?php echo esc_html( ucfirst( $rarity ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="nw-field-slot">Slot</label></th>
							<td>
								<select id="nw-field-slot">
									<?php foreach ( self::SLOTS as $slot ) : ?>
										<option value="<?php echo esc_attr( $slot ); ?>"><?php echo esc_html( $slot ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="nw-field-size">Size</label></th>
							<td>
								<select id="nw-field-size">
									<?php foreach ( self::SIZES as $size ) : ?>
										<option value="<?php echo esc_attr( $size ); ?>"><?php echo esc_html( ucfirst( $size ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="nw-field-price">Price</label></th>
							<td><input type="number" id="nw-field-price" class="small-text" min="0" value="0"></td>
						</tr>
						<tr>
							<th><label for="nw-field-power-value">Power Value</label></th>
							<td><input type="number" id="nw-field-power-value" class="small-text" min="0" value="0"></td>
						</tr>
						<tr>
							<th><label for="nw-field-mass">Mass</label></th>
							<td><input type="number" id="nw-field-mass" class="small-text" min="1" value="1"></td>
						</tr>
						<tr>
							<th><label for="nw-field-stack-limit">Stack Limit</label></th>
							<td><input type="number" id="nw-field-stack-limit" class="small-text" min="1" value="1"></td>
						</tr>
						<tr>
							<th><label for="nw-field-img-url">Image URL</label></th>
							<td><input type="url" id="nw-field-img-url" class="large-text"></td>
						</tr>
						<tr>
							<th>Preview</th>
							<td>
								<div id="nw-item-image-preview-wrap" style="display:none;">
									<img id="nw-item-image-preview" src="" alt="" style="display:block;max-width:220px;max-height:220px;border-radius:10px;border:1px solid #2b2b2b;background:#111;padding:6px;">
								</div>
							</td>
						</tr>
						<tr>
							<th><label for="nw-field-sound-url">Sound URL</label></th>
							<td><input type="url" id="nw-field-sound-url" class="large-text"></td>
						</tr>
						<tr>
							<th><label for="nw-field-tags">Tags</label></th>
							<td><input type="text" id="nw-field-tags" class="large-text" placeholder="comma,separated,tags"></td>
						</tr>

						<!-- Kingdom requirements -->
						<tr><th colspan="2"><hr style="border-color:#333;margin:4px 0;"><strong style="color:#adff00;font-size:12px;text-transform:uppercase;letter-spacing:.06em;">Kingdom Requirements</strong></th></tr>
						<tr>
							<th><label for="nw-field-min-tech">Min Tech</label></th>
							<td><input type="number" id="nw-field-min-tech" class="small-text" min="0" value="0"></td>
						</tr>
						<tr>
							<th><label for="nw-field-min-magic">Min Magic</label></th>
							<td><input type="number" id="nw-field-min-magic" class="small-text" min="0" value="0"></td>
						</tr>
						<tr>
							<th><label for="nw-field-min-wealth">Min Wealth</label></th>
							<td><input type="number" id="nw-field-min-wealth" class="small-text" min="0" value="0"></td>
						</tr>

						<!-- Archetype restriction -->
						<tr><th colspan="2"><hr style="border-color:#333;margin:4px 0;"><strong style="color:#adff00;font-size:12px;text-transform:uppercase;letter-spacing:.06em;">Archetype Restriction</strong></th></tr>
						<tr>
							<th><label for="nw-field-restricted-archetype">Restricted to Archetype</label></th>
							<td>
								<select id="nw-field-restricted-archetype">
									<option value="">— No restriction —</option>
									<!-- populated via JS -->
								</select>
								<p class="description" style="color:#aaa;margin-top:4px;">Leave empty = available to all archetypes.</p>
								<div id="nw-archetype-loading" style="display:none;color:#888;font-size:12px;margin-top:4px;">Loading archetypes…</div>
							</td>
						</tr>

						<!-- Flags -->
						<tr><th colspan="2"><hr style="border-color:#333;margin:4px 0;"><strong style="color:#adff00;font-size:12px;text-transform:uppercase;letter-spacing:.06em;">Flags</strong></th></tr>
						<tr>
							<th>Options</th>
							<td>
								<label><input type="checkbox" id="nw-field-is-container"> Is container</label><br>
								<label><input type="checkbox" id="nw-field-active" checked> Is active</label>
							</td>
						</tr>
					</table>

					<p>
						<button id="nw-save-btn" class="button button-primary">Save Item</button>
						<button id="nw-cancel-btn" class="button">Cancel</button>
						<button id="nw-delete-btn" class="button button-link-delete" style="display:none;">Delete</button>
					</p>

					<div id="nw-form-notice" role="alert" aria-live="polite"></div>
				</div>
			</div>
		</div>
		<?php
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	private function parse_tags( $value ): array {
		if ( is_array( $value ) ) {
			$items = $value;
		} else {
			$value = trim( (string) $value );

			if ( '' === $value ) {
				return [];
			}

			$decoded = json_decode( $value, true );

			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				$items = $decoded;
			} else {
				$items = array_map( 'trim', explode( ',', $value ) );
			}
		}

		$items = array_map( 'sanitize_text_field', $items );
		$items = array_values( array_filter( array_unique( $items ), static fn( $v ) => '' !== $v ) );

		return $items;
	}

	private function maybe_uuid( $value ): ?string {
		$value = trim( sanitize_text_field( (string) $value ) );

		if ( '' === $value ) {
			return null;
		}

		return preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$value
		) ? $value : null;
	}

	private function get_cache_key( string $suffix ): string {
		return 'nw_' . md5( $suffix );
	}

	private function bust_cache( string $scope ): void {
		delete_transient( $this->get_cache_key( $scope . '_all' ) );
	}

	private function cached_get_all( string $table, string $order_by = 'name' ): array {
		$cache_key = $this->get_cache_key( $table . '_all' );

		$cached = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$res = $this->supa(
			'GET',
			$table . '?select=*&order=' . rawurlencode( $order_by ) . '.asc'
		);

		if ( ! $res['ok'] ) {
			return [ 'error' => $res['error'] ?? 'Failed to fetch records.' ];
		}

		$rows = is_array( $res['data'] ) ? $res['data'] : [];
		set_transient( $cache_key, $rows, MINUTE_IN_SECONDS * 5 );

		return $rows;
	}

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

	// ── AJAX handlers ──────────────────────────────────────────────────────────

	public function ajax_load(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$rows = $this->cached_get_all( $this->table, 'name' );

		if ( isset( $rows['error'] ) ) {
			wp_send_json_error( $rows['error'] );
			return;
		}

		wp_send_json_success( $rows );
	}

	public function ajax_get(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );

		if ( ! $id ) {
			wp_send_json_error( 'Missing ID' );
			return;
		}

		$res = $this->supa(
			'GET',
			$this->table . '?id=eq.' . rawurlencode( $id ) . '&select=*'
		);

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Fetch failed.' );
			return;
		}

		$item = is_array( $res['data'] ) ? ( $res['data'][0] ?? null ) : null;
		wp_send_json_success( $item );
	}

	/**
	 * Fetch archetypes list for the dropdown.
	 * Returns id + name from cyber_archetypes (or cyber_classes depending on actual table).
	 */
	public function ajax_get_archetypes(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$res = $this->supa(
			'GET',
			'cyber_classes?select=id,name&order=name.asc'
		);

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Failed to fetch archetypes.' );
			return;
		}

		$rows = is_array( $res['data'] ) ? $res['data'] : [];
		wp_send_json_success( $rows );
	}

	public function ajax_save(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id                   = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$name                 = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$description          = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$type                 = sanitize_text_field( wp_unslash( $_POST['type'] ?? '' ) );
		$rarity               = sanitize_text_field( wp_unslash( $_POST['rarity'] ?? 'common' ) );
		$slot                 = sanitize_text_field( wp_unslash( $_POST['slot'] ?? 'none' ) );
		$size                 = sanitize_text_field( wp_unslash( $_POST['size'] ?? 'medium' ) );
		$price                = max( 0, intval( $_POST['price'] ?? 0 ) );
		$power_value          = max( 0, intval( $_POST['power_value'] ?? 0 ) );
		$mass                 = max( 1, intval( $_POST['mass'] ?? 1 ) );
		$stack_limit          = max( 1, intval( $_POST['stack_limit'] ?? 1 ) );
		$img_url              = esc_url_raw( wp_unslash( $_POST['img_url'] ?? '' ) ) ?: null;
		$sound_url            = esc_url_raw( wp_unslash( $_POST['sound_url'] ?? '' ) ) ?: null;
		$tags                 = $this->parse_tags( wp_unslash( $_POST['tags'] ?? '' ) );
		$is_container         = filter_var( $_POST['is_container'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$is_active            = filter_var( $_POST['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN );
		$min_kingdom_tech     = max( 0, intval( $_POST['min_kingdom_tech'] ?? 0 ) );
		$min_kingdom_magic    = max( 0, intval( $_POST['min_kingdom_magic'] ?? 0 ) );
		$min_kingdom_wealth   = max( 0, intval( $_POST['min_kingdom_wealth'] ?? 0 ) );
		$restricted_archetype = $this->maybe_uuid( wp_unslash( $_POST['restricted_to_archetype'] ?? '' ) );

		if ( ! $name ) {
			wp_send_json_error( 'Name is required' );
			return;
		}

		if ( ! in_array( $rarity, self::RARITIES, true ) ) {
			wp_send_json_error( 'Invalid rarity' );
			return;
		}

		if ( ! in_array( $size, self::SIZES, true ) ) {
			wp_send_json_error( 'Invalid size' );
			return;
		}

		if ( ! in_array( $slot, self::SLOTS, true ) ) {
			wp_send_json_error( 'Invalid slot' );
			return;
		}

		$payload = [
			'name'                    => $name,
			'description'             => $description ?: null,
			'type'                    => $type ?: null,
			'tags'                    => $tags,
			'slot'                    => $slot,
			'power_value'             => $power_value,
			'price'                   => $price,
			'img_url'                 => $img_url,
			'sound_url'               => $sound_url,
			'rarity'                  => $rarity,
			'size'                    => $size,
			'mass'                    => $mass,
			'stack_limit'             => $stack_limit,
			'is_container'            => $is_container,
			'is_active'               => $is_active,
			'min_kingdom_tech'        => $min_kingdom_tech,
			'min_kingdom_magic'       => $min_kingdom_magic,
			'min_kingdom_wealth'      => $min_kingdom_wealth,
			'restricted_to_archetype' => $restricted_archetype,
		];

		if ( $id ) {
			$res = $this->supa(
				'PATCH',
				$this->table . '?id=eq.' . rawurlencode( $id ),
				$payload
			);
		} else {
			$res = $this->supa(
				'POST',
				$this->table,
				$payload
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

	public function ajax_toggle(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id        = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$is_active = filter_var( $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );

		if ( ! $id ) {
			wp_send_json_error( 'Missing ID' );
			return;
		}

		$res = $this->supa(
			'PATCH',
			$this->table . '?id=eq.' . rawurlencode( $id ),
			[ 'is_active' => $is_active ]
		);

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Toggle failed.' );
			return;
		}

		$this->bust_cache( $this->table );
		wp_send_json_success(
			[
				'id'        => $id,
				'is_active' => $is_active,
			]
		);
	}

	public function ajax_delete(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );

		if ( ! $id ) {
			wp_send_json_error( 'Missing ID' );
			return;
		}

		$res = $this->supa(
			'DELETE',
			$this->table . '?id=eq.' . rawurlencode( $id )
		);

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Delete failed' );
			return;
		}

		$this->bust_cache( $this->table );
		wp_send_json_success( 'deleted' );
	}
}
