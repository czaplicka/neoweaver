<?php
/**
 * NeoWeaver Admin Panel — Classes (cyber_classes)
 *
 * Handles the WP Admin page, AJAX load/save/delete, and Supabase sync
 * for character class definitions.
 *
 * Instantiated exclusively by NW_Admin_Bootstrap — do NOT add
 * `new NW_Classes_Admin()` or `add_action('plugins_loaded', ...)` here.
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'NW_Classes_Admin', false ) ) {
	return;
}

class NW_Classes_Admin {

	private string $page_slug    = 'nw-classes';
	private string $table        = 'cyber_classes';
	private string $nonce_action = 'nw_classes_nonce';

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_nw_classes_load',   [ $this, 'ajax_load' ] );
		add_action( 'wp_ajax_nw_classes_save',   [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_classes_delete', [ $this, 'ajax_delete' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'neoweaver',
			'NeoWeaver — Classes',
			'🧬 Classes',
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
			'nw-classes-style',
			NW_PLUGIN_URL . 'assets/css/admin/classes.css',
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
			'nw-classes-script',
			NW_PLUGIN_URL . 'assets/js/admin/classes.js',
			[ 'jquery', 'lucide' ],
			NW_VERSION,
			true
		);

		$uploads = wp_upload_dir();

		wp_localize_script(
			'nw-classes-script',
			'NWClasses',
			[
				'ajaxurl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( $this->nonce_action ),
				'uploads_url' => isset( $uploads['baseurl'] ) ? untrailingslashit( $uploads['baseurl'] ) : '',
			]
		);
	}

	/* ---------------------------------------------------------------- */
	/*  SERVICE KEY HEADERS (omija RLS — tylko dla admin PHP)           */
	/* ---------------------------------------------------------------- */

	private function sk(): array {
		if ( ! defined( 'TW_SUPABASE_SERVICE_KEY' ) ) {
			return [];
		}
		return [
			'apikey'        => TW_SUPABASE_SERVICE_KEY,
			'Authorization' => 'Bearer ' . TW_SUPABASE_SERVICE_KEY,
		];
	}

	/* ---------------------------------------------------------------- */
	/*  SUPABASE                                                         */
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
	/*  CACHE                                                            */
	/* ---------------------------------------------------------------- */

	private function get_cache_key( string $suffix ): string {
		return 'nw_' . md5( $suffix );
	}

	private function bust_cache( string $scope ): void {
		delete_transient( $this->get_cache_key( $scope . '_all' ) );
	}

	private function cached_get_all( string $table, string $order_by = 'created_at' ): array {
		$cache_key = $this->get_cache_key( $table . '_all' );

		$cached = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$res = $this->supa(
			'GET',
			$table . '?select=*&order=' . rawurlencode( $order_by ) . '.desc',
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

	/* ---------------------------------------------------------------- */
	/*  VALIDATE                                                         */
	/* ---------------------------------------------------------------- */

	/**
	 * Validates that a string is a well-formed UUID v1–v5.
	 * Identical to the pattern used in abilities.php.
	 */
	private function is_uuid( string $value ): bool {
		return (bool) preg_match(
			'/^[0-9a-fA-F]{8}\\-[0-9a-fA-F]{4}\\-[1-5][0-9a-fA-F]{3}\\-[89abAB][0-9a-fA-F]{3}\\-[0-9a-fA-F]{12}$/',
			$value
		);
	}

	/* ---------------------------------------------------------------- */
	/*  NORMALIZE                                                        */
	/* ---------------------------------------------------------------- */

	private function parse_tags( string $raw ): array {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return [];
		}

		$tags = array_map(
			static fn( $tag ) => sanitize_text_field( trim( $tag ) ),
			explode( ',', $raw )
		);

		$tags = array_values(
			array_filter(
				array_unique( $tags ),
				static fn( $tag ) => '' !== $tag
			)
		);

		return $tags;
	}

	private function parse_attribute_bonuses( string $raw ): array {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return [];
		}

		$decoded = json_decode( $raw, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return [];
		}

		return $decoded;
	}

	private function bool_from_post( string $key, bool $default = false ): bool {
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}

		return (bool) intval( wp_unslash( $_POST[ $key ] ) );
	}

	/* ---------------------------------------------------------------- */
	/*  AJAX                                                             */
	/* ---------------------------------------------------------------- */

	public function ajax_load(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$rows = $this->cached_get_all( $this->table, 'created_at' );

		if ( isset( $rows['error'] ) ) {
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

		$id                      = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$name                    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$description             = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$icon_slug               = sanitize_text_field( wp_unslash( $_POST['icon_slug'] ?? '' ) );
		$vulnerability           = sanitize_text_field( wp_unslash( $_POST['vulnerability'] ?? '' ) );
		$mechanics               = sanitize_textarea_field( wp_unslash( $_POST['mechanics'] ?? '' ) );
		$gm_instructions         = sanitize_textarea_field( wp_unslash( $_POST['gm_instructions'] ?? '' ) );
		$ai_personality_modifier = sanitize_textarea_field( wp_unslash( $_POST['ai_personality_modifier'] ?? '' ) );
		$img_url                 = esc_url_raw( wp_unslash( $_POST['img_url'] ?? '' ) );
		$starting_gold           = max( 0, intval( wp_unslash( $_POST['starting_gold'] ?? 100 ) ) );
		$skill_limit             = max( 0, intval( wp_unslash( $_POST['skill_limit'] ?? 3 ) ) );
		$is_active               = $this->bool_from_post( 'is_active', true );
		$tags_raw                = sanitize_text_field( wp_unslash( $_POST['tags'] ?? '' ) );
		$attribute_bonuses_raw   = wp_unslash( $_POST['attribute_bonuses'] ?? '' );

		if ( ! $name ) {
			wp_send_json_error( 'Name is required.' );
			return;
		}

		// Validate UUID when updating an existing record.
		if ( $id && ! $this->is_uuid( $id ) ) {
			wp_send_json_error( 'Invalid class ID.' );
			return;
		}

		$payload = [
			'name'                    => $name,
			'description'             => '' !== $description ? $description : null,
			'icon_slug'               => '' !== $icon_slug ? $icon_slug : null,
			'vulnerability'           => '' !== $vulnerability ? $vulnerability : null,
			'mechanics'               => '' !== $mechanics ? $mechanics : null,
			'gm_instructions'         => '' !== $gm_instructions ? $gm_instructions : null,
			'ai_personality_modifier' => '' !== $ai_personality_modifier ? $ai_personality_modifier : null,
			'img_url'                 => '' !== $img_url ? $img_url : null,
			'starting_gold'           => $starting_gold,
			'skill_limit'             => $skill_limit,
			'is_active'               => $is_active,
			'tags'                    => $this->parse_tags( $tags_raw ),
			'attribute_bonuses'       => $this->parse_attribute_bonuses( (string) $attribute_bonuses_raw ),
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
			wp_send_json_error( 'Invalid class ID.' );
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

	/* ---------------------------------------------------------------- */
	/*  RENDER                                                           */
	/* ---------------------------------------------------------------- */

	public function render_page(): void {
		?>
		<div class="wrap nw-panel" id="nw-classes-panel">
			<div class="nw-panel-header">
				<h1 class="nw-panel-title"><span class="nw-accent">Neo</span>Weaver <span class="nw-panel-subtitle">/ Classes</span></h1>
				<div class="nw-header-actions">
					<input type="text" id="nw-search" class="nw-search-input" placeholder="Search classes, tags or vulnerability…">
					<button class="nw-btn nw-btn-ghost" id="nw-refresh-btn">&#8635; Refresh</button>
					<button class="nw-btn nw-btn-primary" id="nw-add-btn">+ New Class</button>
				</div>
			</div>

			<div id="nw-notice" class="nw-notice" style="display:none;"></div>

			<div class="nw-stats-bar">
				<span class="nw-stat-pill">Total: <strong id="nw-total">—</strong></span>
				<span class="nw-stat-pill nw-pill-active">Active: <strong id="nw-active">—</strong></span>
			</div>

			<div class="nw-table-wrap">
				<table class="nw-table">
					<thead>
						<tr>
							<th>Image</th>
							<th>Name</th>
							<th>Tags</th>
							<th>Gold</th>
							<th>Skill limit</th>
							<th>Vulnerability</th>
							<th>Active</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody id="nw-classes-tbody">
						<tr>
							<td colspan="8" style="text-align:center;padding:32px;color:#555;">
								<div class="nw-spinner"></div> Loading…
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
				<div class="nw-modal">
					<div class="nw-modal-header">
						<h2 id="nw-modal-title">Edit Class</h2>
						<button class="nw-modal-close" id="nw-modal-close">&#x2715;</button>
					</div>

					<div class="nw-modal-body">
						<form id="nw-class-form">
							<input type="hidden" id="nw-field-id" name="id">

							<div class="nw-section-label">Identity</div>
							<div class="nw-form-grid">
								<div class="nw-field">
									<label for="nw-field-name">Class Name <span class="nw-req">*</span></label>
									<input type="text" id="nw-field-name" name="name" required placeholder="e.g. Netrunner">
								</div>

								<div class="nw-field">
									<label for="nw-field-icon_slug">Icon Slug</label>
									<input type="text" id="nw-field-icon_slug" name="icon_slug" placeholder="e.g. terminal">
								</div>

								<div class="nw-field nw-field-full">
									<label for="nw-field-description">Description</label>
									<textarea id="nw-field-description" name="description" rows="3" placeholder="Short class description…"></textarea>
								</div>

								<div class="nw-field nw-field-full">
									<label for="nw-field-tags">Tags <span class="nw-hint">(comma-separated)</span></label>
									<input type="text" id="nw-field-tags" name="tags" placeholder="e.g. stealth, hacking, support">
								</div>

								<div class="nw-field nw-field-full">
									<label for="nw-field-img_url">Image URL</label>
									<input type="url" id="nw-field-img_url" name="img_url" placeholder="https://… or relative filename">
								</div>

								<div class="nw-field nw-field-full" id="nw-img-preview-wrap" style="display:none;">
									<label>Preview</label>
									<img id="nw-img-preview" src="" alt="Class image preview" style="max-height:120px;border-radius:8px;">
								</div>
							</div>

							<div class="nw-section-label">Gameplay</div>
							<div class="nw-form-grid">
								<div class="nw-field">
									<label for="nw-field-starting_gold">Starting Gold</label>
									<input type="number" id="nw-field-starting_gold" name="starting_gold" min="0" step="1" value="100">
								</div>

								<div class="nw-field">
									<label for="nw-field-skill_limit">Skill Limit</label>
									<input type="number" id="nw-field-skill_limit" name="skill_limit" min="0" step="1" value="3">
								</div>

								<div class="nw-field">
									<label for="nw-field-is_active">Active</label>
									<select id="nw-field-is_active" name="is_active" class="nw-select">
										<option value="1">Yes</option>
										<option value="0">No</option>
									</select>
								</div>

								<div class="nw-field nw-field-full">
									<label for="nw-field-vulnerability">Vulnerability</label>
									<input type="text" id="nw-field-vulnerability" name="vulnerability" placeholder="e.g. EMP, fire, psychic bleed">
								</div>

								<div class="nw-field nw-field-full">
									<label for="nw-field-attribute_bonuses">Attribute Bonuses <span class="nw-hint">(JSON)</span></label>
									<textarea id="nw-field-attribute_bonuses" name="attribute_bonuses" rows="3" placeholder='{"intelligence": 2, "agility": 1}'></textarea>
								</div>

								<div class="nw-field nw-field-full">
									<label for="nw-field-mechanics">Mechanics</label>
									<textarea id="nw-field-mechanics" name="mechanics" rows="3" placeholder="Core gameplay rules for this class…"></textarea>
								</div>

								<div class="nw-field nw-field-full">
									<label for="nw-field-gm_instructions">GM Instructions</label>
									<textarea id="nw-field-gm_instructions" name="gm_instructions" rows="3" placeholder="Guidance for the Game Master…"></textarea>
								</div>

								<div class="nw-field nw-field-full">
									<label for="nw-field-ai_personality_modifier">AI Personality Modifier</label>
									<textarea id="nw-field-ai_personality_modifier" name="ai_personality_modifier" rows="3" placeholder="How this class should influence AI roleplay…"></textarea>
								</div>
							</div>
						</form>
					</div>

					<div class="nw-modal-footer">
						<button class="nw-btn nw-btn-danger" id="nw-delete-btn" style="display:none;margin-right:auto;">&#128465; Delete</button>
						<button class="nw-btn nw-btn-ghost" id="nw-cancel-btn">Cancel</button>
						<button class="nw-btn nw-btn-primary" id="nw-save-btn"><span id="nw-save-label">Save Class</span></button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
