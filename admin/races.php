<?php
/**
 * NeoWeaver Admin — Races (cyber_races)
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * This file should only define the admin class.
 * Instantiation should happen in the central admin bootstrap.
 */
if ( class_exists( 'NeoWeaver_Races_Admin', false ) ) {
	return;
}

if ( ! trait_exists( 'NW_Transient_Cache', false ) ) {
	return;
}

class NeoWeaver_Races_Admin {

	use NW_Transient_Cache;

	private string $table        = 'cyber_races';
	private string $nonce_action = 'nw_races_nonce';
	private string $page_slug    = 'nw-races';

	private const UPLOADS_BASE = 'https://neoweaver.nieodparady.pl/wp-content/uploads/';

	/**
	 * Preferred scales are 0–5, base HP/MP > 0.
	 */
	private const SLIDER_DEFAULTS = [
		'race_base_hp'      => 8,
		'race_base_mp'      => 8,
		'preferred_tech'    => 3,
		'preferred_magic'   => 3,
		'preferred_gods'    => 3,
		'preferred_wealth'  => 3,
		'preferred_threat'  => 3,
		'preferred_moral'   => 2,
		'preferred_social'  => 3,
	];

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_nw_races_get_all', [ $this, 'ajax_get_all' ] );
		add_action( 'wp_ajax_nw_races_get_one', [ $this, 'ajax_get_one' ] );
		add_action( 'wp_ajax_nw_races_save', [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_races_toggle', [ $this, 'ajax_toggle' ] );
		add_action( 'wp_ajax_nw_races_delete', [ $this, 'ajax_delete' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'neoweaver',
			'Races',
			'👾 Races',
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
			'nw-races-style',
			NEOWEAVER_PLUGIN_URL . 'assets/css/admin/races.css',
			[ 'nw-font-chakra-petch', 'nw-admin-core' ],
			NEOWEAVER_VERSION
		);

		wp_enqueue_script(
			'nw-races-script',
			NEOWEAVER_PLUGIN_URL . 'assets/js/admin/races.js',
			[ 'jquery', 'nw-lucide' ],
			NEOWEAVER_VERSION,
			true
		);

		$upload_dir  = wp_upload_dir();
		$uploads_url = ! empty( $upload_dir['baseurl'] ) ? trailingslashit( $upload_dir['baseurl'] ) : self::UPLOADS_BASE;

		wp_localize_script(
			'nw-races-script',
			'NWRaces',
			[
				'ajaxurl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( $this->nonce_action ),
				'uploadsBase' => $uploads_url,
			]
		);
	}

	/* ---------- dependencies ---------- */

	private function supabase_ready(): bool {
		return class_exists( 'NW_Supabase', false )
			&& method_exists( 'NW_Supabase', 'insert' )
			&& method_exists( 'NW_Supabase', 'patch' )
			&& method_exists( 'NW_Supabase', 'delete' );
	}

	private function supabase_error_response( string $context = 'Supabase dependency missing.' ): void {
		wp_send_json_error( $context, 500 );
	}

	private function get_all_rows_cached(): array {
		if ( ! method_exists( $this, 'cached_get_all' ) ) {
			return [ 'error' => 'Transient cache dependency missing.' ];
		}

		return $this->cached_get_all( $this->table, 'name' );
	}

	/* ---------- img helpers ---------- */

	private function img_url( string $filename ): string {
		if ( '' === $filename ) {
			return '';
		}

		if ( str_starts_with( $filename, 'http://' ) || str_starts_with( $filename, 'https://' ) ) {
			return esc_url_raw( $filename );
		}

		return esc_url_raw( self::UPLOADS_BASE . ltrim( $filename, '/' ) );
	}

	private function img_filename( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$stripped   = str_replace( self::UPLOADS_BASE, '', $url );
		$upload_dir = wp_upload_dir();
		$stripped   = str_replace( trailingslashit( $upload_dir['baseurl'] ), '', $stripped );

		return sanitize_text_field( ltrim( $stripped, '/' ) );
	}

	/* ---------- render ---------- */

	public function render_page(): void {
		?>
		<div class="wrap nw-admin-wrap nw-races-admin">
			<h1>Races</h1>

			<div id="nw-notice" style="display:none;margin:12px 0;padding:10px 12px;border-radius:8px;"></div>

			<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:12px 0 18px;">
				<input type="text" id="nw-search" class="regular-text" placeholder="Search races…">
				<button id="nw-refresh-btn" class="button" type="button">Refresh</button>
				<button id="nw-add-btn" class="button button-primary" type="button">+ Add Race</button>
			</div>

			<div style="display:flex;gap:16px;margin-bottom:16px;">
				<div><strong>Total:</strong> <span id="nw-total">0</span></div>
				<div><strong>Active:</strong> <span id="nw-active">0</span></div>
				<div><strong>Inactive:</strong> <span id="nw-inactive">0</span></div>
			</div>

			<table class="wp-list-table widefat striped" id="nw-races-table">
				<thead>
					<tr>
						<th style="width:80px;">Image</th>
						<th>Name / Parent</th>
						<th>Tags</th>
						<th>HP / MP</th>
						<th>Active</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody id="nw-races-tbody">
					<tr><td colspan="6" style="text-align:center;padding:32px;">Loading…</td></tr>
				</tbody>
			</table>

			<div id="nw-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.74);z-index:9999;overflow-y:auto;padding:24px;">
				<div style="max-width:980px;margin:24px auto;background:#050505;color:#f2f2f2;border-radius:14px;border:1px solid #2b2b2b;padding:32px 28px;position:relative;">
					<button id="nw-modal-close" type="button" style="position:absolute;right:14px;top:14px;background:none;border:0;color:#fff;font-size:22px;cursor:pointer;line-height:1;">✕</button>
					<h2 id="nw-modal-title" style="margin-top:0;margin-bottom:20px;">New Race</h2>

					<form id="nw-race-form">
						<input type="hidden" id="nw-field-id" name="id">

						<table class="form-table" role="presentation">
							<tr>
								<th><label for="nw-field-name">Name *</label></th>
								<td><input type="text" id="nw-field-name" name="name" class="regular-text"></td>
							</tr>
							<tr>
								<th><label for="nw-field-parent_race">Parent race</label></th>
								<td><input type="text" id="nw-field-parent_race" name="parent_race" class="regular-text"></td>
							</tr>
							<tr>
								<th><label for="nw-field-description">Description</label></th>
								<td><textarea id="nw-field-description" name="description" class="large-text" rows="3"></textarea></td>
							</tr>
							<tr>
								<th><label for="nw-field-gm_instructions">GM Instructions</label></th>
								<td><textarea id="nw-field-gm_instructions" name="gm_instructions" class="large-text" rows="3"></textarea></td>
							</tr>
							<tr>
								<th><label for="nw-field-img_url">Image filename</label></th>
								<td>
									<input type="text" id="nw-field-img_url" name="img_url" class="large-text" placeholder="abomination.svg">
									<p class="description" style="color:#888;">Enter only the filename (e.g. <code>abomination.svg</code>). The uploads URL is added automatically.</p>
									<div id="nw-race-image-preview-wrap" style="display:none;margin-top:10px;">
										<img id="nw-race-image-preview" src="" alt="" style="display:block;max-width:160px;max-height:160px;border-radius:10px;border:1px solid #2b2b2b;background:#111;padding:6px;">
									</div>
								</td>
							</tr>
							<tr>
								<th><label for="nw-field-tags">Tags</label></th>
								<td><input type="text" id="nw-field-tags" name="tags" class="large-text" placeholder="comma,separated,tags"></td>
							</tr>
							<tr>
								<th><label for="nw-field-conflict_axis">Conflict axis</label></th>
								<td><input type="text" id="nw-field-conflict_axis" name="conflict_axis" class="regular-text" placeholder="e.g. tech-vs-magic"></td>
							</tr>
							<tr>
								<th><label for="nw-field-conflict_side">Conflict side</label></th>
								<td><input type="text" id="nw-field-conflict_side" name="conflict_side" class="regular-text" placeholder="e.g. pro-tech"></td>
							</tr>
							<tr>
								<th><label for="nw-field-bonus">Bonus (JSON)</label></th>
								<td><textarea id="nw-field-bonus" name="bonus" class="large-text" rows="3" placeholder='{"hp":2,"tech":1}'></textarea></td>
							</tr>
							<tr>
								<th>Base HP / MP</th>
								<td>
									<label>HP:
										<input type="number" id="nw-field-race_base_hp" name="race_base_hp" class="small-text" min="1" max="999" value="<?php echo esc_attr( self::SLIDER_DEFAULTS['race_base_hp'] ); ?>">
									</label>
									&nbsp;&nbsp;
									<label>MP:
										<input type="number" id="nw-field-race_base_mp" name="race_base_mp" class="small-text" min="0" max="999" value="<?php echo esc_attr( self::SLIDER_DEFAULTS['race_base_mp'] ); ?>">
									</label>
								</td>
							</tr>
							<tr>
								<th>Preferences (0–5)</th>
								<td>
									<div class="nw-pref-grid">
										<?php foreach ( self::SLIDER_DEFAULTS as $key => $def ) : ?>
											<?php if ( str_starts_with( $key, 'preferred_' ) ) : ?>
												<div class="nw-pref-row">
													<label for="nw-field-<?php echo esc_attr( $key ); ?>">
														<?php echo esc_html( ucwords( str_replace( [ 'preferred_', '_' ], [ '', ' ' ], $key ) ) ); ?>
													</label>
													<input
														type="range"
														class="nw-range"
														id="nw-field-<?php echo esc_attr( $key ); ?>"
														name="<?php echo esc_attr( $key ); ?>"
														min="0"
														max="5"
														value="<?php echo esc_attr( $def ); ?>"
													>
													<span id="nw-val-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $def ); ?></span>
												</div>
											<?php endif; ?>
										<?php endforeach; ?>
									</div>
								</td>
							</tr>
							<tr>
								<th>Active</th>
								<td>
									<label>
										<input type="checkbox" id="nw-field-is_active" name="is_active" checked>
										Is active
									</label>
								</td>
							</tr>
						</table>
					</form>

					<p style="margin-top:20px;">
						<button id="nw-save-btn" type="button" class="button button-primary">
							<span id="nw-save-label">Save Race</span>
						</button>
						<button id="nw-cancel-btn" type="button" class="button" style="margin-left:8px;">Cancel</button>
						<button id="nw-delete-btn" type="button" class="button button-link-delete" style="display:none;margin-left:16px;">Delete</button>
					</p>

					<div id="nw-form-notice" role="alert" aria-live="polite"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---------- helpers ---------- */

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

		return array_values(
			array_filter(
				array_unique( $items ),
				static fn( $v ) => '' !== $v
			)
		);
	}

	private function bust_cache_for_table(): void {
		$this->bust_cache( $this->table );
	}

	private function hydrate_rows( array $rows ): array {
		return array_map(
			function ( $row ) {
				if ( isset( $row['img_url'] ) ) {
					$row['img_url'] = $this->img_url( (string) $row['img_url'] );
				}
				if ( isset( $row['id'] ) ) {
					$row['id'] = (string) $row['id'];
				}
				return $row;
			},
			$rows
		);
	}

	/* ---------- AJAX ---------- */

	public function ajax_get_all(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$rows = $this->get_all_rows_cached();

		if ( isset( $rows['error'] ) ) {
			wp_send_json_error( $rows['error'], 500 );
			return;
		}

		wp_send_json_success( $this->hydrate_rows( $rows ) );
	}

	public function ajax_get_one(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );

		if ( ! $id ) {
			wp_send_json_error( 'Missing ID', 400 );
			return;
		}

		$rows = $this->get_all_rows_cached();

		if ( isset( $rows['error'] ) ) {
			wp_send_json_error( $rows['error'], 500 );
			return;
		}

		$item = null;

		foreach ( $rows as $row ) {
			if ( isset( $row['id'] ) && (string) $row['id'] === (string) $id ) {
				$item = $row;
				break;
			}
		}

		if ( ! $item ) {
			wp_send_json_error( 'Race not found', 404 );
			return;
		}

		if ( isset( $item['img_url'] ) ) {
			$item['img_url'] = $this->img_filename( (string) $item['img_url'] );
		}

		if ( isset( $item['id'] ) ) {
			$item['id'] = (string) $item['id'];
		}

		wp_send_json_success( $item );
	}

	public function ajax_save(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		if ( ! $this->supabase_ready() ) {
			$this->supabase_error_response( 'NW_Supabase is not available.' );
			return;
		}

		$id   = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

		if ( ! $name ) {
			wp_send_json_error( 'Name is required', 400 );
			return;
		}

		$description     = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$gm_instructions = sanitize_textarea_field( wp_unslash( $_POST['gm_instructions'] ?? '' ) );
		$parent_race     = sanitize_text_field( wp_unslash( $_POST['parent_race'] ?? '' ) );
		$tags            = $this->parse_tags( wp_unslash( $_POST['tags'] ?? '' ) );
		$conflict_axis   = sanitize_text_field( wp_unslash( $_POST['conflict_axis'] ?? '' ) );
		$conflict_side   = sanitize_text_field( wp_unslash( $_POST['conflict_side'] ?? '' ) );
		$bonus_raw       = wp_unslash( $_POST['bonus'] ?? '' );
		$is_active       = filter_var( wp_unslash( $_POST['is_active'] ?? false ), FILTER_VALIDATE_BOOLEAN );

		$img_raw      = sanitize_text_field( wp_unslash( $_POST['img_url'] ?? '' ) );
		$img_filename = $this->img_filename( $img_raw ) ?: null;

		$race_base_hp = max( 1, intval( wp_unslash( $_POST['race_base_hp'] ?? self::SLIDER_DEFAULTS['race_base_hp'] ) ) );
		$race_base_mp = max( 0, intval( wp_unslash( $_POST['race_base_mp'] ?? self::SLIDER_DEFAULTS['race_base_mp'] ) ) );

		$preferred_tech   = max( 0, min( 5, intval( wp_unslash( $_POST['preferred_tech'] ?? self::SLIDER_DEFAULTS['preferred_tech'] ) ) ) );
		$preferred_magic  = max( 0, min( 5, intval( wp_unslash( $_POST['preferred_magic'] ?? self::SLIDER_DEFAULTS['preferred_magic'] ) ) ) );
		$preferred_gods   = max( 0, min( 5, intval( wp_unslash( $_POST['preferred_gods'] ?? self::SLIDER_DEFAULTS['preferred_gods'] ) ) ) );
		$preferred_wealth = max( 0, min( 5, intval( wp_unslash( $_POST['preferred_wealth'] ?? self::SLIDER_DEFAULTS['preferred_wealth'] ) ) ) );
		$preferred_threat = max( 0, min( 5, intval( wp_unslash( $_POST['preferred_threat'] ?? self::SLIDER_DEFAULTS['preferred_threat'] ) ) ) );
		$preferred_moral  = max( 0, min( 5, intval( wp_unslash( $_POST['preferred_moral'] ?? self::SLIDER_DEFAULTS['preferred_moral'] ) ) ) );
		$preferred_social = max( 0, min( 5, intval( wp_unslash( $_POST['preferred_social'] ?? self::SLIDER_DEFAULTS['preferred_social'] ) ) ) );

		$bonus = null;
		if ( '' !== trim( (string) $bonus_raw ) ) {
			$decoded = json_decode( (string) $bonus_raw, true );

			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				wp_send_json_error( 'Bonus must be valid JSON object.', 400 );
				return;
			}

			$bonus = $decoded;
		}

		$payload = [
			'name'             => $name,
			'parent_race'      => $parent_race ?: null,
			'tags'             => $tags,
			'gm_instructions'  => $gm_instructions ?: null,
			'description'      => $description ?: null,
			'race_base_hp'     => $race_base_hp,
			'race_base_mp'     => $race_base_mp,
			'img_url'          => $img_filename,
			'preferred_tech'   => $preferred_tech,
			'preferred_magic'  => $preferred_magic,
			'preferred_gods'   => $preferred_gods,
			'preferred_wealth' => $preferred_wealth,
			'preferred_threat' => $preferred_threat,
			'preferred_moral'  => $preferred_moral,
			'preferred_social' => $preferred_social,
			'conflict_axis'    => $conflict_axis ?: null,
			'conflict_side'    => $conflict_side ?: null,
			'bonus'            => $bonus,
			'is_active'        => $is_active,
		];

		$res = $id
			? NW_Supabase::patch( $this->table, $id, $payload )
			: NW_Supabase::insert( $this->table, $payload );

		if ( isset( $res['error'] ) ) {
			wp_send_json_error( $res['error'], 500 );
			return;
		}

		$code = $res['code'] ?? 0;
		if ( $code >= 400 ) {
			wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code, 500 );
			return;
		}

		$this->bust_cache_for_table();

		$item = $res['data'][0] ?? null;
		if ( is_array( $item ) ) {
			$item = $this->hydrate_rows( [ $item ] )[0];
		}

		wp_send_json_success( $item );
	}

	public function ajax_toggle(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		if ( ! $this->supabase_ready() ) {
			$this->supabase_error_response( 'NW_Supabase is not available.' );
			return;
		}

		$id        = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$is_active = filter_var( wp_unslash( $_POST['is_active'] ?? false ), FILTER_VALIDATE_BOOLEAN );

		if ( ! $id ) {
			wp_send_json_error( 'Missing ID', 400 );
			return;
		}

		$res = NW_Supabase::patch( $this->table, $id, [ 'is_active' => $is_active ] );

		if ( isset( $res['error'] ) ) {
			wp_send_json_error( $res['error'], 500 );
			return;
		}

		$this->bust_cache_for_table();

		wp_send_json_success(
			[
				'id'        => (string) $id,
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

		if ( ! $this->supabase_ready() ) {
			$this->supabase_error_response( 'NW_Supabase is not available.' );
			return;
		}

		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );

		if ( ! $id ) {
			wp_send_json_error( 'Missing ID', 400 );
			return;
		}

		$res = NW_Supabase::delete( $this->table, $id );

		if ( isset( $res['error'] ) ) {
			wp_send_json_error( $res['error'], 500 );
			return;
		}

		$this->bust_cache_for_table();
		wp_send_json_success( 'deleted' );
	}
}
