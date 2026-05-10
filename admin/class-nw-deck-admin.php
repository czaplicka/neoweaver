<?php
/**
 * NeoWeaver — Deck Admin
 *
 * Manages cyber_deck table (cards):
 *   id, name, deck_category, type, rarity, description, effect,
 *   level, action_cost, time_cost, duration, target, range,
 *   hp_cost, mana_cost, stamina_cost, gold_cost, tags, img_url,
 *   gm_notes, is_active.
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NW_Deck_Admin {

	/* ---------------------------------------------------------------- */
	/*  Single source of truth for enum values                          */
	/* ---------------------------------------------------------------- */

	private const CATEGORIES = [ 'action', 'spell', 'trap', 'event', 'item', 'special' ];
	private const TYPES      = [ 'attack', 'defense', 'support', 'utility', 'movement', 'other' ];
	private const RARITIES   = [ 'common', 'uncommon', 'rare', 'epic', 'legendary' ];

	/* ---------------------------------------------------------------- */
	/*  Bootstrap                                                       */
	/* ---------------------------------------------------------------- */

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// AJAX — admin-only
		add_action( 'wp_ajax_nw_deck_list',   [ $this, 'ajax_list' ] );
		add_action( 'wp_ajax_nw_deck_get',    [ $this, 'ajax_get' ] );
		add_action( 'wp_ajax_nw_deck_save',   [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_deck_toggle', [ $this, 'ajax_toggle' ] );
		add_action( 'wp_ajax_nw_deck_delete', [ $this, 'ajax_delete' ] );
	}

	/* ---------------------------------------------------------------- */
	/*  Menu                                                            */
	/* ---------------------------------------------------------------- */

	public function register_menu(): void {
		add_submenu_page(
			'neoweaver',
			__( 'NeoWeaver — Deck', 'neoweaver' ),
			__( '🃏Deck', 'neoweaver' ),
			'manage_options',
			'neoweaver-deck',
			[ $this, 'render_page' ]
		);
	}

	/* ---------------------------------------------------------------- */
	/*  Assets                                                          */
	/* ---------------------------------------------------------------- */

	public function enqueue_assets( string $hook ): void {
		if ( 'neoweaver_page_neoweaver-deck' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'nw-admin-core',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/deck-admin.css',
			[ 'chakra-petch' ],
			NEOWEAVER_VERSION
		);

		wp_enqueue_style(
			'nw-abilities-style',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/deck-admin.css',
			[ 'chakra-petch', 'nw-admin-core' ],
			NEOWEAVER_VERSION
		);

		wp_localize_script(
			'nw-deck-admin',
			'NW_Deck',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'neoweaver_deck' ),
			]
		);
	}

	/* ---------------------------------------------------------------- */
	/*  Supabase helper (uses shared WP helpers)                        */
	/* ---------------------------------------------------------------- */

	/**
	 * Normalized Supabase wrapper.
	 *
	 * Returns:
	 * [
	 *   'ok'    => bool,
	 *   'code'  => int,
	 *   'data'  => mixed,
	 *   'error' => string|null,
	 * ]
	 */
	private function supa( string $method, string $endpoint, array $body = [], array $extra_headers = [] ): array {
		$method = strtoupper( $method );

		// GET via tw_supabase_get if available.
		if ( 'GET' === $method && function_exists( 'tw_supabase_get' ) ) {
			[ $table, $qs ] = array_pad( explode( '?', ltrim( $endpoint, '/' ), 2 ), 2, '' );
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

		// POST / PATCH / DELETE via tw_supabase_request if available.
		if ( function_exists( 'tw_supabase_request' ) ) {
			[ $table, $qs ] = array_pad( explode( '?', ltrim( $endpoint, '/' ), 2 ), 2, '' );
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

			$res  = tw_supabase_request(
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
	/*  Page render                                                      */
	/* ---------------------------------------------------------------- */

	public function render_page(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'NeoWeaver — Deck', 'neoweaver' ); ?></h1>

			<div style="display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap; align-items:center;">
				<select id="nw-deck-filter-category">
					<option value=""><?php esc_html_e( 'All categories', 'neoweaver' ); ?></option>
					<?php foreach ( self::CATEGORIES as $c ) : ?>
						<option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( ucfirst( $c ) ); ?></option>
					<?php endforeach; ?>
				</select>

				<select id="nw-deck-filter-type">
					<option value=""><?php esc_html_e( 'All types', 'neoweaver' ); ?></option>
					<?php foreach ( self::TYPES as $t ) : ?>
						<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( ucfirst( $t ) ); ?></option>
					<?php endforeach; ?>
				</select>

				<select id="nw-deck-filter-rarity">
					<option value=""><?php esc_html_e( 'All rarities', 'neoweaver' ); ?></option>
					<?php foreach ( self::RARITIES as $r ) : ?>
						<option value="<?php echo esc_attr( $r ); ?>"><?php echo esc_html( ucfirst( $r ) ); ?></option>
					<?php endforeach; ?>
				</select>

				<select id="nw-deck-filter-active">
					<option value=""><?php esc_html_e( 'All statuses', 'neoweaver' ); ?></option>
					<option value="1"><?php esc_html_e( 'Active', 'neoweaver' ); ?></option>
					<option value="0"><?php esc_html_e( 'Inactive', 'neoweaver' ); ?></option>
				</select>

				<input type="text" id="nw-deck-search" placeholder="<?php esc_attr_e( 'Search name…', 'neoweaver' ); ?>" style="width:200px;" class="regular-text">
				<button class="button" id="nw-deck-filter-btn"><?php esc_html_e( 'Filter', 'neoweaver' ); ?></button>
				<button class="button button-primary" id="nw-deck-add-btn"><?php esc_html_e( '+ Add Card', 'neoweaver' ); ?></button>
			</div>

			<table class="wp-list-table widefat fixed striped" id="nw-deck-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'neoweaver' ); ?></th>
						<th><?php esc_html_e( 'Category', 'neoweaver' ); ?></th>
						<th><?php esc_html_e( 'Type', 'neoweaver' ); ?></th>
						<th><?php esc_html_e( 'Rarity', 'neoweaver' ); ?></th>
						<th><?php esc_html_e( 'Level', 'neoweaver' ); ?></th>
						<th><?php esc_html_e( 'Active', 'neoweaver' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'neoweaver' ); ?></th>
					</tr>
				</thead>
				<tbody id="nw-deck-tbody">
					<tr><td colspan="7"><?php esc_html_e( 'Loading…', 'neoweaver' ); ?></td></tr>
				</tbody>
			</table>

			<!-- Modal -->
			<div id="nw-deck-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,.5); z-index:9999; overflow-y:auto;">
				<div style="background:#fff; margin:40px auto; padding:24px; max-width:700px; border-radius:6px; position:relative;">
					<button id="nw-deck-modal-close" style="position:absolute; top:12px; right:12px; background:none; border:none; font-size:20px; cursor:pointer;">✕</button>
					<h2 id="nw-deck-modal-title"><?php esc_html_e( 'Card', 'neoweaver' ); ?></h2>

					<input type="hidden" id="nw-deck-id">

					<table class="form-table">
						<tr>
							<th><label for="nw-deck-name"><?php esc_html_e( 'Name *', 'neoweaver' ); ?></label></th>
							<td><input type="text" id="nw-deck-name" class="regular-text" required></td>
						</tr>
						<tr>
							<th><label for="nw-deck-category"><?php esc_html_e( 'Category', 'neoweaver' ); ?></label></th>
							<td>
								<select id="nw-deck-category">
									<?php foreach ( self::CATEGORIES as $c ) : ?>
										<option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( ucfirst( $c ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="nw-deck-type"><?php esc_html_e( 'Type', 'neoweaver' ); ?></label></th>
							<td>
								<select id="nw-deck-type">
									<?php foreach ( self::TYPES as $t ) : ?>
										<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( ucfirst( $t ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="nw-deck-rarity"><?php esc_html_e( 'Rarity', 'neoweaver' ); ?></label></th>
							<td>
								<select id="nw-deck-rarity">
									<?php foreach ( self::RARITIES as $r ) : ?>
										<option value="<?php echo esc_attr( $r ); ?>"><?php echo esc_html( ucfirst( $r ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="nw-deck-description"><?php esc_html_e( 'Description', 'neoweaver' ); ?></label></th>
							<td><textarea id="nw-deck-description" rows="3" class="large-text"></textarea></td>
						</tr>
						<tr>
							<th><label for="nw-deck-effect"><?php esc_html_e( 'Effect', 'neoweaver' ); ?></label></th>
							<td><textarea id="nw-deck-effect" rows="3" class="large-text"></textarea></td>
						</tr>
						<tr>
							<th><label for="nw-deck-level"><?php esc_html_e( 'Level', 'neoweaver' ); ?></label></th>
							<td><input type="number" id="nw-deck-level" min="1" value="1" style="width:80px;"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Costs', 'neoweaver' ); ?></th>
							<td>
								<label><?php esc_html_e( 'Action:', 'neoweaver' ); ?> <input type="number" id="nw-deck-action-cost" min="0" value="0" style="width:60px;"></label>&nbsp;
								<label><?php esc_html_e( 'HP:', 'neoweaver' ); ?> <input type="number" id="nw-deck-hp-cost" min="0" value="0" style="width:60px;"></label>&nbsp;
								<label><?php esc_html_e( 'Mana:', 'neoweaver' ); ?> <input type="number" id="nw-deck-mana-cost" min="0" value="0" style="width:60px;"></label>&nbsp;
								<label><?php esc_html_e( 'Stamina:', 'neoweaver' ); ?> <input type="number" id="nw-deck-stamina-cost" min="0" value="0" style="width:60px;"></label>&nbsp;
								<label><?php esc_html_e( 'Gold:', 'neoweaver' ); ?> <input type="number" id="nw-deck-gold-cost" min="0" value="0" style="width:60px;"></label>
							</td>
						</tr>
						<tr>
							<th><label for="nw-deck-time-cost"><?php esc_html_e( 'Time Cost', 'neoweaver' ); ?></label></th>
							<td><input type="text" id="nw-deck-time-cost" class="regular-text"></td>
						</tr>
						<tr>
							<th><label for="nw-deck-duration"><?php esc_html_e( 'Duration', 'neoweaver' ); ?></label></th>
							<td><input type="text" id="nw-deck-duration" class="regular-text"></td>
						</tr>
						<tr>
							<th><label for="nw-deck-target"><?php esc_html_e( 'Target', 'neoweaver' ); ?></label></th>
							<td><input type="text" id="nw-deck-target" class="regular-text"></td>
						</tr>
						<tr>
							<th><label for="nw-deck-range"><?php esc_html_e( 'Range', 'neoweaver' ); ?></label></th>
							<td><input type="text" id="nw-deck-range" class="regular-text"></td>
						</tr>
						<tr>
							<th><label for="nw-deck-tags"><?php esc_html_e( 'Tags (comma-separated)', 'neoweaver' ); ?></label></th>
							<td><input type="text" id="nw-deck-tags" class="regular-text"></td>
						</tr>
						<tr>
							<th><label for="nw-deck-img-url"><?php esc_html_e( 'Image URL', 'neoweaver' ); ?></label></th>
							<td><input type="url" id="nw-deck-img-url" class="large-text"></td>
						</tr>
						<tr>
							<th><label for="nw-deck-gm-notes"><?php esc_html_e( 'GM Notes', 'neoweaver' ); ?></label></th>
							<td><textarea id="nw-deck-gm-notes" rows="3" class="large-text"></textarea></td>
						</tr>
						<tr>
							<th><label for="nw-deck-is-active"><?php esc_html_e( 'Active', 'neoweaver' ); ?></label></th>
							<td><input type="checkbox" id="nw-deck-is-active" checked></td>
						</tr>
					</table>

					<p>
						<button class="button button-primary" id="nw-deck-save-btn"><?php esc_html_e( 'Save', 'neoweaver' ); ?></button>
						<button class="button" id="nw-deck-cancel-btn"><?php esc_html_e( 'Cancel', 'neoweaver' ); ?></button>
						<span id="nw-deck-msg" style="margin-left:12px;"></span>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- */
	/*  AJAX — list                                                     */
	/* ---------------------------------------------------------------- */

	public function ajax_list(): void {
		check_ajax_referer( 'neoweaver_deck', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$category = sanitize_text_field( $_POST['category'] ?? '' );
		$type     = sanitize_text_field( $_POST['type']     ?? '' );
		$rarity   = sanitize_text_field( $_POST['rarity']   ?? '' );
		$search   = sanitize_text_field( $_POST['search']   ?? '' );
		$active   = sanitize_text_field( $_POST['active']   ?? '' );

		$endpoint = 'cyber_deck?select=*&order=name.asc';

		if ( $category ) {
			$endpoint .= '&deck_category=eq.' . rawurlencode( $category );
		}
		if ( $type ) {
			$endpoint .= '&type=eq.' . rawurlencode( $type );
		}
		if ( $rarity ) {
			$endpoint .= '&rarity=eq.' . rawurlencode( $rarity );
		}
		if ( '' !== $active ) {
			$endpoint .= '&is_active=eq.' . ( $active ? 'true' : 'false' );
		}
		if ( $search ) {
			$endpoint .= '&name=ilike.*' . rawurlencode( $search ) . '*';
		}

		$result = $this->supa(
			'GET',
			$endpoint,
			[],
			[ 'Range' => '0-199' ]
		);

		if ( ! $result['ok'] ) {
			wp_send_json_error( $result['error'] ?? 'Failed to load deck.' );
			return;
		}

		wp_send_json_success( $result['data'] ?? [] );
	}

	/* ---------------------------------------------------------------- */
	/*  AJAX — get single                                               */
	/* ---------------------------------------------------------------- */

	public function ajax_get(): void {
		check_ajax_referer( 'neoweaver_deck', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		// UUID as string, never intval().
		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		if ( ! $id ) {
			wp_send_json_error( 'Invalid ID.' );
			return;
		}

		$result = $this->supa(
			'GET',
			'cyber_deck?id=eq.' . rawurlencode( $id ) . '&select=*'
		);

		if ( ! $result['ok'] ) {
			wp_send_json_error( $result['error'] ?? 'Failed to fetch card.' );
			return;
		}

		$data = $result['data'] ?? [];
		wp_send_json_success( is_array( $data ) ? ( $data[0] ?? null ) : null );
	}

	/* ---------------------------------------------------------------- */
	/*  AJAX — save (create / update)                                   */
	/* ---------------------------------------------------------------- */

	public function ajax_save(): void {
		check_ajax_referer( 'neoweaver_deck', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		// UUID as string.
		$id   = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

		if ( ! $name ) {
			wp_send_json_error( 'Name is required.' );
			return;
		}

		$category = sanitize_text_field( wp_unslash( $_POST['deck_category'] ?? '' ) );
		$type     = sanitize_text_field( wp_unslash( $_POST['type']          ?? '' ) );
		$rarity   = sanitize_text_field( wp_unslash( $_POST['rarity']        ?? '' ) );

		if ( $category && ! in_array( $category, self::CATEGORIES, true ) ) {
			wp_send_json_error( 'Invalid category.' );
			return;
		}
		if ( $type && ! in_array( $type, self::TYPES, true ) ) {
			wp_send_json_error( 'Invalid type.' );
			return;
		}
		if ( $rarity && ! in_array( $rarity, self::RARITIES, true ) ) {
			wp_send_json_error( 'Invalid rarity.' );
			return;
		}

		$payload = [
			'name'          => $name,
			'deck_category' => $category ?: self::CATEGORIES[0],
			'type'          => $type,
			'rarity'        => $rarity ?: self::RARITIES[0],
			'description'   => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
			'effect'        => sanitize_textarea_field( wp_unslash( $_POST['effect']       ?? '' ) ),
			'level'         => intval( $_POST['level']        ?? 1 ),
			'action_cost'   => intval( $_POST['action_cost']  ?? 0 ),
			'time_cost'     => sanitize_text_field( wp_unslash( $_POST['time_cost']  ?? '' ) ),
			'duration'      => sanitize_text_field( wp_unslash( $_POST['duration']   ?? '' ) ),
			'target'        => sanitize_text_field( wp_unslash( $_POST['target']     ?? '' ) ),
			'range'         => sanitize_text_field( wp_unslash( $_POST['range']      ?? '' ) ),
			'hp_cost'       => intval( $_POST['hp_cost']      ?? 0 ),
			'mana_cost'     => intval( $_POST['mana_cost']    ?? 0 ),
			'stamina_cost'  => intval( $_POST['stamina_cost'] ?? 0 ),
			'gold_cost'     => intval( $_POST['gold_cost']    ?? 0 ),
			'tags'          => sanitize_text_field( wp_unslash( $_POST['tags']       ?? '' ) ),
			'img_url'       => esc_url_raw( $_POST['img_url'] ?? '' ),
			'gm_notes'      => sanitize_textarea_field( wp_unslash( $_POST['gm_notes'] ?? '' ) ),
			'is_active'     => filter_var( $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN ),
		];

		if ( $id ) {
			$result = $this->supa(
				'PATCH',
				'cyber_deck?id=eq.' . rawurlencode( $id ),
				$payload
			);
		} else {
			$result = $this->supa(
				'POST',
				'cyber_deck',
				$payload
			);
		}

		if ( ! $result['ok'] ) {
			wp_send_json_error( $result['error'] ?? 'Save failed.' );
			return;
		}

		$data = $result['data'] ?? [];
		wp_send_json_success( is_array( $data ) ? ( $data[0] ?? $data ) : $data );
	}

	/* ---------------------------------------------------------------- */
	/*  AJAX — toggle                                                   */
	/* ---------------------------------------------------------------- */

	public function ajax_toggle(): void {
		check_ajax_referer( 'neoweaver_deck', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id    = sanitize_text_field( wp_unslash( $_POST['id']    ?? '' ) );
		$state = filter_var( $_POST['state'] ?? false, FILTER_VALIDATE_BOOLEAN );

		if ( ! $id ) {
			wp_send_json_error( 'Invalid ID.' );
			return;
		}

		$result = $this->supa(
			'PATCH',
			'cyber_deck?id=eq.' . rawurlencode( $id ),
			[ 'is_active' => $state ]
		);

		if ( ! $result['ok'] ) {
			wp_send_json_error( $result['error'] ?? 'Toggle failed.' );
			return;
		}

		wp_send_json_success( [ 'toggled' => true, 'state' => $state ] );
	}

	/* ---------------------------------------------------------------- */
	/*  AJAX — delete                                                   */
	/* ---------------------------------------------------------------- */

	public function ajax_delete(): void {
		check_ajax_referer( 'neoweaver_deck', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );

		if ( ! $id ) {
			wp_send_json_error( 'Invalid ID.' );
			return;
		}

		$result = $this->supa(
			'DELETE',
			'cyber_deck?id=eq.' . rawurlencode( $id )
		);

		if ( ! $result['ok'] ) {
			wp_send_json_error( $result['error'] ?? 'Delete failed.' );
			return;
		}

		wp_send_json_success( [ 'deleted' => true ] );
	}
}

// BUG: wrong class instantiated — fix to NW_Deck_Admin.
add_action(
	'plugins_loaded',
	static function () {
		new NW_Deck_Admin();
	},
	20
);
