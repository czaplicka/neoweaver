<?php
/**
 * NeoWeaver Admin Panel — Scenarios (cyber_scenarios)
 *
 * Real schema fields:
 * id, name, type, category, goal, gm_instruction, tags, required_tags,
 * success_tags, failure_tags, victory_condition, fail_conditions, difficulty,
 * min_entropy, max_entropy, is_boss, is_key_arc, is_active, area_id, img_url,
 * reward_credits, reward_items, kingdom_tech, kingdom_magic, kingdom_wealth,
 * required_archetype_id, giver_npc_tag, is_repeatable, created_at
 *
 * Instantiated exclusively by NW_Admin_Bootstrap.
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'NeoWeaver_Scenarios_Admin', false ) ) {
	return;
}

class NeoWeaver_Scenarios_Admin {

	private string $table        = 'cyber_scenarios';
	private string $page_slug    = 'nw-scenarios';
	private string $nonce_action = 'nw_scenarios_nonce';
	private string $supabase_url = '';
	private string $supabase_key = '';

	private const TYPES = [ 'main', 'personal', 'social', 'world' ];
	private const CATEGORIES = [ 'combat', 'social', 'magic', 'investigation', 'worlds', 'sidequest', 'family' ];

	public function __construct() {
		if ( function_exists( 'tw_supabase_url' ) ) {
			$this->supabase_url = rtrim( (string) tw_supabase_url(), '/' );
		}

		if ( function_exists( 'tw_supabase_anon_key' ) ) {
			$this->supabase_key = (string) tw_supabase_anon_key();
		}

		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_nw_scenarios_get_all', [ $this, 'ajax_get_all' ] );
		add_action( 'wp_ajax_nw_scenarios_get_one', [ $this, 'ajax_get_one' ] );
		add_action( 'wp_ajax_nw_scenarios_save', [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_scenarios_toggle', [ $this, 'ajax_toggle' ] );
		add_action( 'wp_ajax_nw_scenarios_delete', [ $this, 'ajax_delete' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'neoweaver',
			'Scenarios',
			'📋 Scenarios',
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
			'nw-scenarios-style',
			NEOWEAVER_PLUGIN_URL . 'assets/css/admin/scenarios.css',
			[ 'nw-font-chakra-petch', 'nw-admin-core' ],
			NEOWEAVER_VERSION
		);

		wp_enqueue_script(
			'nw-scenarios-script',
			NEOWEAVER_PLUGIN_URL . 'assets/js/admin/scenarios.js',
			[ 'jquery', 'nw-lucide' ],
			NEOWEAVER_VERSION,
			true
		);

		wp_localize_script(
			'nw-scenarios-script',
			'NWScenarios',
			[
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( $this->nonce_action ),
			]
		);
	}

	public function render_page(): void {
		?>
		<div class="wrap nw-admin-wrap nw-scenarios-admin">
			<h1>Scenarios</h1>

			<div id="nw-notice" style="display:none;margin:12px 0;padding:10px 12px;border-radius:8px;"></div>

			<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0 18px;">
				<input type="text" id="nw-search" class="regular-text" placeholder="Search scenarios…">

				<select id="nw-filter-type">
					<option value="">All types</option>
					<?php foreach ( self::TYPES as $type ) : ?>
						<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( ucfirst( $type ) ); ?></option>
					<?php endforeach; ?>
				</select>

				<select id="nw-filter-category">
					<option value="">All categories</option>
					<?php foreach ( self::CATEGORIES as $category ) : ?>
						<option value="<?php echo esc_attr( $category ); ?>"><?php echo esc_html( ucfirst( $category ) ); ?></option>
					<?php endforeach; ?>
				</select>

				<select id="nw-filter-difficulty">
					<option value="">All difficulties</option>
					<option value="1">1</option>
					<option value="2">2</option>
					<option value="3">3</option>
					<option value="4">4</option>
					<option value="5">5</option>
				</select>

				<button id="nw-refresh-btn" class="button">Refresh</button>
				<button id="nw-add-btn" class="button button-primary">+ Add Scenario</button>
			</div>

			<div style="display:flex;gap:16px;margin-bottom:16px;">
				<div><strong>Total:</strong> <span id="nw-total">0</span></div>
				<div><strong>Active:</strong> <span id="nw-active-count">0</span></div>
			</div>

			<table class="wp-list-table widefat striped" id="nw-scenarios-table">
				<thead>
					<tr>
						<th>Name</th>
						<th>Type</th>
						<th>Category</th>
						<th>Difficulty</th>
						<th>Rewards</th>
						<th>Active</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody id="nw-scenarios-tbody">
					<tr><td colspan="7" style="text-align:center;padding:32px;">Loading…</td></tr>
				</tbody>
			</table>

			<div id="nw-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.74);z-index:9999;overflow-y:auto;padding:24px;">
				<div style="max-width:1040px;margin:24px auto;background:#050505;color:#f2f2f2;border-radius:14px;border:1px solid #2b2b2b;padding:32px 28px;position:relative;">
					<button id="nw-modal-close" style="position:absolute;right:14px;top:14px;background:none;border:0;color:#fff;font-size:22px;cursor:pointer;line-height:1;">✕</button>
					<h2 id="nw-modal-title" style="margin-top:0;margin-bottom:20px;">New Scenario</h2>

					<form id="nw-scenario-form">
						<input type="hidden" id="nw-field-id" name="id">

						<table class="form-table" role="presentation">
							<tr>
								<th><label for="nw-field-name">Name *</label></th>
								<td><input type="text" id="nw-field-name" name="name" class="regular-text" required></td>
							</tr>

							<tr>
								<th><label for="nw-field-type">Type</label></th>
								<td>
									<select id="nw-field-type" name="type">
										<?php foreach ( self::TYPES as $type ) : ?>
											<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( ucfirst( $type ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>

							<tr>
								<th><label for="nw-field-category">Category</label></th>
								<td>
									<select id="nw-field-category" name="category">
										<?php foreach ( self::CATEGORIES as $category ) : ?>
											<option value="<?php echo esc_attr( $category ); ?>"><?php echo esc_html( ucfirst( $category ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>

							<tr>
								<th><label for="nw-field-difficulty">Difficulty (1–5)</label></th>
								<td><input type="number" id="nw-field-difficulty" name="difficulty" class="small-text" min="1" max="5" value="3"></td>
							</tr>

							<tr>
								<th><label for="nw-field-goal">Goal</label></th>
								<td><textarea id="nw-field-goal" name="goal" class="large-text" rows="3"></textarea></td>
							</tr>

							<tr>
								<th><label for="nw-field-gm_instruction">GM Instruction</label></th>
								<td><textarea id="nw-field-gm_instruction" name="gm_instruction" class="large-text" rows="3"></textarea></td>
							</tr>

							<tr>
								<th><label for="nw-field-victory_condition">Victory Condition</label></th>
								<td><textarea id="nw-field-victory_condition" name="victory_condition" class="large-text" rows="2"></textarea></td>
							</tr>

							<tr>
								<th><label for="nw-field-fail_conditions">Fail Conditions</label></th>
								<td><textarea id="nw-field-fail_conditions" name="fail_conditions" class="large-text" rows="2"></textarea></td>
							</tr>

							<tr>
								<th><label for="nw-field-tags">Tags</label></th>
								<td><input type="text" id="nw-field-tags" name="tags" class="large-text" placeholder="comma,separated,tags"></td>
							</tr>

							<tr>
								<th><label for="nw-field-required_tags">Required Tags</label></th>
								<td><textarea id="nw-field-required_tags" name="required_tags" class="large-text" rows="2" placeholder='["city","night"] or one per line'></textarea></td>
							</tr>

							<tr>
								<th><label for="nw-field-success_tags">Success Tags</label></th>
								<td><textarea id="nw-field-success_tags" name="success_tags" class="large-text" rows="2" placeholder='["saved","allied"] or one per line'></textarea></td>
							</tr>

							<tr>
								<th><label for="nw-field-failure_tags">Failure Tags</label></th>
								<td><textarea id="nw-field-failure_tags" name="failure_tags" class="large-text" rows="2" placeholder='["burned","enemy_alerted"] or one per line'></textarea></td>
							</tr>

							<tr>
								<th><label for="nw-field-reward_credits">Reward Credits</label></th>
								<td><input type="number" id="nw-field-reward_credits" name="reward_credits" class="small-text" min="0" value="100"></td>
							</tr>

							<tr>
								<th><label for="nw-field-reward_items">Reward Items</label></th>
								<td><textarea id="nw-field-reward_items" name="reward_items" class="large-text" rows="3" placeholder='["item_slug"] or JSON array'></textarea></td>
							</tr>

							<tr>
								<th><label for="nw-field-min_entropy">Min Entropy</label></th>
								<td><input type="number" id="nw-field-min_entropy" name="min_entropy" class="small-text" min="0"></td>
							</tr>

							<tr>
								<th><label for="nw-field-max_entropy">Max Entropy</label></th>
								<td><input type="number" id="nw-field-max_entropy" name="max_entropy" class="small-text" min="0"></td>
							</tr>

							<tr>
								<th><label for="nw-field-kingdom_tech">Kingdom Tech</label></th>
								<td><input type="number" id="nw-field-kingdom_tech" name="kingdom_tech" class="small-text" min="0" max="5"></td>
							</tr>

							<tr>
								<th><label for="nw-field-kingdom_magic">Kingdom Magic</label></th>
								<td><input type="number" id="nw-field-kingdom_magic" name="kingdom_magic" class="small-text" min="0" max="5"></td>
							</tr>

							<tr>
								<th><label for="nw-field-kingdom_wealth">Kingdom Wealth</label></th>
								<td><input type="number" id="nw-field-kingdom_wealth" name="kingdom_wealth" class="small-text" min="0" max="5"></td>
							</tr>

							<tr>
								<th><label for="nw-field-area_id">Area ID</label></th>
								<td><input type="text" id="nw-field-area_id" name="area_id" class="regular-text"></td>
							</tr>

							<tr>
								<th><label for="nw-field-required_archetype_id">Required Archetype ID</label></th>
								<td><input type="text" id="nw-field-required_archetype_id" name="required_archetype_id" class="regular-text"></td>
							</tr>

							<tr>
								<th><label for="nw-field-giver_npc_tag">Giver NPC Tag</label></th>
								<td><textarea id="nw-field-giver_npc_tag" name="giver_npc_tag" class="large-text" rows="2" placeholder='["merchant"] or JSON'></textarea></td>
							</tr>

							<tr>
								<th><label for="nw-field-img_url">Image URL</label></th>
								<td><input type="url" id="nw-field-img_url" name="img_url" class="large-text"></td>
							</tr>

							<tr>
								<th>Flags</th>
								<td>
									<label><input type="checkbox" id="nw-field-is_boss" name="is_boss"> Is boss</label><br>
									<label><input type="checkbox" id="nw-field-is_key_arc" name="is_key_arc"> Is key arc</label><br>
									<label><input type="checkbox" id="nw-field-is_repeatable" name="is_repeatable"> Is repeatable</label><br>
									<label><input type="checkbox" id="nw-field-is_active" name="is_active" checked> Is active</label>
								</td>
							</tr>
						</table>
					</form>

					<p style="margin-top:20px;">
						<button id="nw-save-btn" class="button button-primary">Save Scenario</button>
						<button id="nw-cancel-btn" class="button" style="margin-left:8px;">Cancel</button>
						<button id="nw-delete-btn" class="button button-link-delete" style="display:none;margin-left:16px;">Delete</button>
					</p>

					<div id="nw-form-notice" role="alert" aria-live="polite"></div>
				</div>
			</div>
		</div>
		<?php
	}

	private function supa( string $method, string $path, array $body = [], array $extra_headers = [] ): array {
		if ( empty( $this->supabase_url ) || empty( $this->supabase_key ) ) {
			return [
				'ok'    => false,
				'code'  => 0,
				'data'  => null,
				'error' => 'Supabase configuration not available.',
			];
		}

		$headers = array_merge(
			[
				'apikey'        => $this->supabase_key,
				'Authorization' => 'Bearer ' . $this->supabase_key,
				'Content-Type'  => 'application/json',
			],
			$extra_headers
		);

		$args = [
			'method'  => strtoupper( $method ),
			'timeout' => 20,
			'headers' => $headers,
		];

		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$url      = $this->supabase_url . '/rest/v1/' . ltrim( $path, '/' );
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return [
				'ok'    => false,
				'code'  => 0,
				'data'  => null,
				'error' => $response->get_error_message(),
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 400 ) {
			$msg = is_array( $data ) && isset( $data['message'] )
				? $data['message']
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

	private function is_uuid( string $value ): bool {
		return (bool) preg_match(
			'/^[0-9a-fA-F]{8}\-[0-9a-fA-F]{4}\-[1-5][0-9a-fA-F]{3}\-[89abAB][0-9a-fA-F]{3}\-[0-9a-fA-F]{12}$/',
			$value
		);
	}

	private function maybe_uuid( $value ): ?string {
		$value = trim( sanitize_text_field( (string) $value ) );

		if ( '' === $value ) {
			return null;
		}

		return $this->is_uuid( $value ) ? $value : null;
	}

	private function required_uuid_from_post( string $key = 'id' ): string {
		$value = sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) );

		if ( ! $value || ! $this->is_uuid( $value ) ) {
			return '';
		}

		return $value;
	}

	private function parse_csv_tags( $value ): array {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return [];
		}

		$items = array_map( 'trim', explode( ',', $value ) );
		$items = array_map( 'sanitize_text_field', $items );

		return array_values( array_filter( array_unique( $items ), static fn( $v ) => '' !== $v ) );
	}

	private function parse_json_or_lines( $raw ): array {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return [];
		}

		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
			return [];
		}

		return array_values(
			array_filter(
				array_map(
					'trim',
					preg_split( '/\\r\\n|\\r|\\n/', $raw )
				)
			)
		);
	}

	private function clamp_nullable_int( $value, int $min, int $max ): ?int {
		if ( '' === (string) $value || null === $value ) {
			return null;
		}

		$n = (int) $value;
		return max( $min, min( $max, $n ) );
	}

	public function ajax_get_all(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$type       = sanitize_text_field( wp_unslash( $_POST['filter_type'] ?? '' ) );
		$category   = sanitize_text_field( wp_unslash( $_POST['filter_category'] ?? '' ) );
		$difficulty = sanitize_text_field( wp_unslash( $_POST['filter_difficulty'] ?? '' ) );

		$qs = $this->table . '?select=*&order=created_at.desc';

		if ( $type && in_array( $type, self::TYPES, true ) ) {
			$qs .= '&type=eq.' . rawurlencode( $type );
		}

		if ( $category && in_array( $category, self::CATEGORIES, true ) ) {
			$qs .= '&category=eq.' . rawurlencode( $category );
		}

		if ( '' !== $difficulty ) {
			$qs .= '&difficulty=eq.' . rawurlencode( (string) max( 1, min( 5, (int) $difficulty ) ) );
		}

		$res = $this->supa( 'GET', $qs );

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Failed to load scenarios.' );
			return;
		}

		wp_send_json_success( is_array( $res['data'] ) ? $res['data'] : [] );
	}

	public function ajax_get_one(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id = $this->required_uuid_from_post( 'id' );

		if ( ! $id ) {
			wp_send_json_error( 'Invalid ID' );
			return;
		}

		$res = $this->supa(
			'GET',
			$this->table . '?id=eq.' . rawurlencode( $id ) . '&select=*'
		);

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Failed to fetch scenario.' );
			return;
		}

		$data = is_array( $res['data'] ) ? $res['data'] : [];
		$item = $data[0] ?? null;

		if ( ! $item ) {
			wp_send_json_error( 'Scenario not found' );
			return;
		}

		wp_send_json_success( $item );
	}

	public function ajax_save(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id       = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$name     = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$type     = sanitize_text_field( wp_unslash( $_POST['type'] ?? 'main' ) );
		$category = sanitize_text_field( wp_unslash( $_POST['category'] ?? 'combat' ) );

		if ( '' === $name ) {
			wp_send_json_error( 'Name is required' );
			return;
		}

		if ( $id && ! $this->is_uuid( $id ) ) {
			wp_send_json_error( 'Invalid ID' );
			return;
		}

		if ( ! in_array( $type, self::TYPES, true ) ) {
			wp_send_json_error( 'Invalid type' );
			return;
		}

		if ( ! in_array( $category, self::CATEGORIES, true ) ) {
			wp_send_json_error( 'Invalid category' );
			return;
		}

		$difficulty = max( 1, min( 5, (int) ( wp_unslash( $_POST['difficulty'] ?? 3 ) ) ) );

		$min_entropy = $this->clamp_nullable_int( wp_unslash( $_POST['min_entropy'] ?? null ), 0, 999 );
		$max_entropy = $this->clamp_nullable_int( wp_unslash( $_POST['max_entropy'] ?? null ), 0, 999 );

		if ( null !== $min_entropy && null !== $max_entropy && $min_entropy > $max_entropy ) {
			wp_send_json_error( 'Min entropy cannot be greater than max entropy' );
			return;
		}

		$payload = [
			'name'                  => $name,
			'type'                  => $type,
			'category'              => $category,
			'goal'                  => sanitize_textarea_field( wp_unslash( $_POST['goal'] ?? '' ) ) ?: null,
			'gm_instruction'        => sanitize_textarea_field( wp_unslash( $_POST['gm_instruction'] ?? '' ) ) ?: null,
			'tags'                  => $this->parse_csv_tags( wp_unslash( $_POST['tags'] ?? '' ) ),
			'required_tags'         => $this->parse_json_or_lines( wp_unslash( $_POST['required_tags'] ?? '' ) ),
			'success_tags'          => $this->parse_json_or_lines( wp_unslash( $_POST['success_tags'] ?? '' ) ),
			'failure_tags'          => $this->parse_json_or_lines( wp_unslash( $_POST['failure_tags'] ?? '' ) ),
			'victory_condition'     => sanitize_textarea_field( wp_unslash( $_POST['victory_condition'] ?? '' ) ) ?: null,
			'fail_conditions'       => sanitize_textarea_field( wp_unslash( $_POST['fail_conditions'] ?? '' ) ) ?: null,
			'difficulty'            => $difficulty,
			'min_entropy'           => $min_entropy,
			'max_entropy'           => $max_entropy,
			'is_boss'               => ! empty( $_POST['is_boss'] ),
			'is_key_arc'            => ! empty( $_POST['is_key_arc'] ),
			'is_active'             => ! empty( $_POST['is_active'] ),
			'area_id'               => sanitize_text_field( wp_unslash( $_POST['area_id'] ?? '' ) ) ?: null,
			'img_url'               => esc_url_raw( wp_unslash( $_POST['img_url'] ?? '' ) ) ?: null,
			'reward_credits'        => max( 0, (int) wp_unslash( $_POST['reward_credits'] ?? 100 ) ),
			'reward_items'          => $this->parse_json_or_lines( wp_unslash( $_POST['reward_items'] ?? '' ) ),
			'kingdom_tech'          => $this->clamp_nullable_int( wp_unslash( $_POST['kingdom_tech'] ?? null ), 0, 5 ),
			'kingdom_magic'         => $this->clamp_nullable_int( wp_unslash( $_POST['kingdom_magic'] ?? null ), 0, 5 ),
			'kingdom_wealth'        => $this->clamp_nullable_int( wp_unslash( $_POST['kingdom_wealth'] ?? null ), 0, 5 ),
			'required_archetype_id' => $this->maybe_uuid( wp_unslash( $_POST['required_archetype_id'] ?? '' ) ),
			'giver_npc_tag'         => $this->parse_json_or_lines( wp_unslash( $_POST['giver_npc_tag'] ?? '' ) ),
			'is_repeatable'         => ! empty( $_POST['is_repeatable'] ),
		];

		$res = $id
			? $this->supa(
				'PATCH',
				$this->table . '?id=eq.' . rawurlencode( $id ),
				$payload,
				[ 'Prefer' => 'return=representation' ]
			)
			: $this->supa(
				'POST',
				$this->table,
				$payload,
				[ 'Prefer' => 'return=representation' ]
			);

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Save failed.' );
			return;
		}

		$data = $res['data'];
		$item = is_array( $data ) && isset( $data[0] ) ? $data[0] : $data;

		wp_send_json_success( $item );
	}

	public function ajax_toggle(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id        = $this->required_uuid_from_post( 'id' );
		$is_active = filter_var( wp_unslash( $_POST['is_active'] ?? false ), FILTER_VALIDATE_BOOLEAN );

		if ( ! $id ) {
			wp_send_json_error( 'Invalid ID' );
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

		$id = $this->required_uuid_from_post( 'id' );

		if ( ! $id ) {
			wp_send_json_error( 'Invalid ID' );
			return;
		}

		$res = $this->supa(
			'DELETE',
			$this->table . '?id=eq.' . rawurlencode( $id ),
			[],
			[ 'Prefer' => '' ]
		);

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Delete failed.' );
			return;
		}

		wp_send_json_success( 'deleted' );
	}
}
