<?php
/**
 * NeoWeaver Admin — Skills (cyber_skills)
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'NW_Skills_Admin' ) ) {

	class NW_Skills_Admin {

		use NW_Transient_Cache;

		private string $page_slug    = 'nw-skills';
		private string $table        = 'cyber_skills';
		private string $nonce_action = 'nw_skills_nonce';
		private string $page_hook    = '';

		private const CATEGORIES = [ 'Physical', 'Social', 'Mental', 'Exploration' ];

		public function __construct() {
			add_action( 'admin_menu', [ $this, 'register_menu' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );

			add_action( 'wp_ajax_nw_skills_get_all', [ $this, 'ajax_get_all' ] );
			add_action( 'wp_ajax_nw_skills_get_one', [ $this, 'ajax_get_one' ] );
			add_action( 'wp_ajax_nw_skills_save', [ $this, 'ajax_save' ] );
			add_action( 'wp_ajax_nw_skills_toggle', [ $this, 'ajax_toggle' ] );
			add_action( 'wp_ajax_nw_skills_delete', [ $this, 'ajax_delete' ] );
		}

		public function register_menu(): void {
			$this->page_hook = add_submenu_page(
				'neoweaver',
				'Skills',
				'✨ Skills',
				'manage_options',
				$this->page_slug,
				[ $this, 'render_page' ]
			);
		}

		public function enqueue( string $hook ): void {
			if ( $hook !== $this->page_hook ) {
				return;
			}

			wp_enqueue_style(
				'nw-admin-core',
				NEOWEAVER_PLUGIN_URL . 'assets/css/admin/admin-core.css',
				[ 'nw-font-chakra-petch' ],
				NEOWEAVER_VERSION
			);

			wp_enqueue_style(
				'nw-skills-style',
				NEOWEAVER_PLUGIN_URL . 'assets/css/admin/skills.css',
				[ 'nw-font-chakra-petch', 'nw-admin-core' ],
				NEOWEAVER_VERSION
			);

			wp_enqueue_script(
				'nw-skills',
				NEOWEAVER_PLUGIN_URL . 'assets/js/admin/skills.js',
				[ 'jquery' ],
				NEOWEAVER_VERSION,
				true
			);

			wp_localize_script(
				'nw-skills',
				'NW_SK',
				[
					'ajax_url'   => admin_url( 'admin-ajax.php' ),
					'nonce'      => wp_create_nonce( $this->nonce_action ),
					'categories' => self::CATEGORIES,
				]
			);
		}

		public function render_page(): void {
			?>
			<div class="wrap nw-admin-wrap nw-skills-admin">
				<h1>Skills</h1>

				<div id="nw-notice" style="display:none;margin:12px 0;padding:10px 12px;border-radius:8px;"></div>

				<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0 18px;">
					<input type="text" id="nw-search" class="regular-text" placeholder="Search skills…">

					<select id="nw-filter-category">
						<option value="">All categories</option>
						<?php foreach ( self::CATEGORIES as $category ) : ?>
							<option value="<?php echo esc_attr( $category ); ?>"><?php echo esc_html( $category ); ?></option>
						<?php endforeach; ?>
					</select>

					<button id="nw-refresh-btn" class="button">Refresh</button>
					<button id="nw-add-btn" class="button button-primary">+ Add Skill</button>
				</div>

				<div style="display:flex;gap:16px;margin-bottom:16px;">
					<div><strong>Total:</strong> <span id="nw-total">0</span></div>
					<div><strong>Active:</strong> <span id="nw-active">0</span></div>
					<div><strong>Inactive:</strong> <span id="nw-inactive">0</span></div>
				</div>

				<table class="wp-list-table widefat striped" id="nw-skills-table">
					<thead>
						<tr>
							<th style="width:80px;">Image</th>
							<th>Name</th>
							<th>Category</th>
							<th>Application</th>
							<th>Tags</th>
							<th>Active</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody id="nw-skills-tbody">
						<tr><td colspan="7" style="text-align:center;padding:32px;">Loading…</td></tr>
					</tbody>
				</table>

				<div id="nw-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.74);z-index:9999;overflow-y:auto;padding:24px;">
					<div style="max-width:980px;margin:24px auto;background:#050505;color:#f2f2f2;border-radius:14px;border:1px solid #2b2b2b;padding:32px 28px;position:relative;">
						<button id="nw-modal-close" style="position:absolute;right:14px;top:14px;background:none;border:0;color:#fff;font-size:22px;cursor:pointer;line-height:1;">✕</button>
						<h2 id="nw-modal-title" style="margin-top:0;margin-bottom:20px;">New Skill</h2>

						<form id="nw-skill-form">
							<input type="hidden" id="nw-field-id" name="id">

							<table class="form-table" role="presentation">
								<tr>
									<th><label for="nw-field-name">Name *</label></th>
									<td><input type="text" id="nw-field-name" name="name" class="regular-text" required></td>
								</tr>

								<tr>
									<th><label for="nw-field-description">Description</label></th>
									<td><textarea id="nw-field-description" name="description" class="large-text" rows="3"></textarea></td>
								</tr>

								<tr>
									<th><label for="nw-field-category">Category</label></th>
									<td>
										<select id="nw-field-category" name="category">
											<option value="">— Select —</option>
											<?php foreach ( self::CATEGORIES as $category ) : ?>
												<option value="<?php echo esc_attr( $category ); ?>"><?php echo esc_html( $category ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>

								<tr>
									<th><label for="nw-field-application">Application</label></th>
									<td><input type="text" id="nw-field-application" name="application" class="regular-text"></td>
								</tr>

								<tr>
									<th><label for="nw-field-card_effect">Card Effect</label></th>
									<td><textarea id="nw-field-card_effect" name="card_effect" class="large-text" rows="2"></textarea></td>
								</tr>

								<tr>
									<th><label for="nw-field-img_url">Image URL</label></th>
									<td>
										<input type="url" id="nw-field-img_url" name="img_url" class="large-text">
										<div id="nw-img-preview-wrap" style="display:none;margin-top:10px;">
											<img id="nw-img-preview" src="" alt="" style="display:block;max-width:160px;max-height:160px;border-radius:10px;border:1px solid #2b2b2b;background:#111;padding:6px;">
										</div>
									</td>
								</tr>

								<tr>
									<th><label for="nw-field-tags">Tags</label></th>
									<td><input type="text" id="nw-field-tags" name="tags" class="large-text" placeholder="comma,separated,tags"></td>
								</tr>

								<tr>
									<th><label for="nw-field-linked_attributes">Linked Attributes</label></th>
									<td><input type="text" id="nw-field-linked_attributes" name="linked_attributes" class="large-text" placeholder="comma,separated,attributes"></td>
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
							<button id="nw-save-btn" class="button button-primary"><span id="nw-save-label">Save Skill</span></button>
							<button id="nw-cancel-btn" class="button" style="margin-left:8px;">Cancel</button>
							<button id="nw-delete-btn" class="button button-link-delete" style="display:none;margin-left:16px;">Delete</button>
						</p>

						<div id="nw-form-notice" role="alert" aria-live="polite"></div>
					</div>
				</div>
			</div>
			<?php
		}

		private function parse_csv_array( $value ): array {
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

			$items = array_map(
				static function ( $item ) {
					return sanitize_text_field( (string) $item );
				},
				$items
			);

			return array_values(
				array_filter(
					array_unique( $items ),
					static fn( $item ) => '' !== $item
				)
			);
		}

		private function normalize_row( array $row ): array {
			$row['tags'] = isset( $row['tags'] ) && is_array( $row['tags'] ) ? $row['tags'] : [];
			$row['linked_attributes'] = isset( $row['linked_attributes'] ) && is_array( $row['linked_attributes'] ) ? $row['linked_attributes'] : [];
			$row['is_active'] = ! empty( $row['is_active'] );
			return $row;
		}

		public function ajax_get_all(): void {
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

			$rows = array_map( [ $this, 'normalize_row' ], is_array( $rows ) ? $rows : [] );

			$filter_category = sanitize_text_field( wp_unslash( $_POST['filter_category'] ?? '' ) );
			if ( '' !== $filter_category ) {
				$rows = array_values(
					array_filter(
						$rows,
						static fn( $row ) => ( $row['category'] ?? '' ) === $filter_category
					)
				);
			}

			wp_send_json_success( $rows );
		}

		public function ajax_get_one(): void {
			check_ajax_referer( $this->nonce_action, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Forbidden', 403 );
				return;
			}

			$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );

			if ( '' === $id ) {
				wp_send_json_error( 'Missing ID' );
				return;
			}

			$rows = $this->cached_get_all( $this->table, 'name' );

			if ( isset( $rows['error'] ) ) {
				wp_send_json_error( $rows['error'] );
				return;
			}

			$item = null;

			foreach ( (array) $rows as $row ) {
				if ( isset( $row['id'] ) && (string) $row['id'] === $id ) {
					$item = $this->normalize_row( $row );
					break;
				}
			}

			if ( ! $item ) {
				wp_send_json_error( 'Skill not found' );
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

			$id                = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
			$name              = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
			$description       = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
			$category          = sanitize_text_field( wp_unslash( $_POST['category'] ?? '' ) );
			$application       = sanitize_text_field( wp_unslash( $_POST['application'] ?? '' ) );
			$card_effect       = sanitize_textarea_field( wp_unslash( $_POST['card_effect'] ?? '' ) );
			$img_url           = esc_url_raw( wp_unslash( $_POST['img_url'] ?? '' ) );
			$tags              = $this->parse_csv_array( wp_unslash( $_POST['tags'] ?? '' ) );
			$linked_attributes = $this->parse_csv_array( wp_unslash( $_POST['linked_attributes'] ?? '' ) );
			$is_active         = ! empty( $_POST['is_active'] );

			if ( '' === $name ) {
				wp_send_json_error( 'Name is required' );
				return;
			}

			if ( '' !== $category && ! in_array( $category, self::CATEGORIES, true ) ) {
				wp_send_json_error( 'Invalid category' );
				return;
			}

			$payload = [
				'name'              => $name,
				'description'       => $description ?: null,
				'category'          => $category ?: null,
				'application'       => $application ?: null,
				'card_effect'       => $card_effect ?: null,
				'img_url'           => $img_url ?: null,
				'tags'              => $tags,
				'linked_attributes' => $linked_attributes,
				'is_active'         => $is_active,
			];

			$res = $id
				? NW_Supabase::patch( $this->table, $id, $payload )
				: NW_Supabase::insert( $this->table, $payload );

			if ( isset( $res['error'] ) ) {
				wp_send_json_error( $res['error'] );
				return;
			}

			$code = $res['code'] ?? 0;
			if ( $code >= 400 ) {
				$message = $res['data']['message'] ?? 'Supabase error ' . $code;
				wp_send_json_error( $message );
				return;
			}

			$this->bust_cache( $this->table );

			$item = $res['data'][0] ?? null;
			if ( is_array( $item ) ) {
				$item = $this->normalize_row( $item );
			}

			wp_send_json_success( $item );
		}

		public function ajax_toggle(): void {
			check_ajax_referer( $this->nonce_action, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Forbidden', 403 );
				return;
			}

			$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
			$is_active = filter_var( $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );

			if ( '' === $id ) {
				wp_send_json_error( 'Missing ID' );
				return;
			}

			$res = NW_Supabase::patch(
				$this->table,
				$id,
				[ 'is_active' => $is_active ]
			);

			if ( isset( $res['error'] ) ) {
				wp_send_json_error( $res['error'] );
				return;
			}

			$code = $res['code'] ?? 0;
			if ( $code >= 400 ) {
				$message = $res['data']['message'] ?? 'Supabase error ' . $code;
				wp_send_json_error( $message );
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

			if ( '' === $id ) {
				wp_send_json_error( 'Missing ID' );
				return;
			}

			$res = NW_Supabase::delete( $this->table, $id );

			if ( isset( $res['error'] ) ) {
				wp_send_json_error( $res['error'] );
				return;
			}

			$code = $res['code'] ?? 0;
			if ( $code >= 400 ) {
				$message = $res['data']['message'] ?? 'Supabase error ' . $code;
				wp_send_json_error( $message );
				return;
			}

			$this->bust_cache( $this->table );

			wp_send_json_success( 'deleted' );
		}
	}
}

add_action(
	'plugins_loaded',
	static function () {
		new NW_Skills_Admin();
	},
	20
);
