<?php
/**
 * NeoWeaver Admin Panel — Style Dictionary (cyber_style_dictionary)
 *
 * Columns: id, tag_name, category, interpretation_en, is_active, created_at
 * Categories: behavior | visuals | vibe | general
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'NeoWeaver_Style_Dictionary_Admin' ) ) {

	class NeoWeaver_Style_Dictionary_Admin {

		private string $supabase_url;
		private string $supabase_key;
		private string $page_slug   = 'neoweaver-style-dictionary';
		private string $parent_slug = 'neoweaver';
		private string $nonce_action = 'neoweaver_sd';

		private array $categories = [ 'behavior', 'visuals', 'vibe', 'general' ];

		public function __construct() {
			$this->supabase_url = rtrim( tw_supabase_url(), '/' );
			$this->supabase_key = tw_supabase_anon_key();

			add_action( 'admin_menu', [ $this, 'register_menu' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

			add_action( 'wp_ajax_nw_sd_get_all', [ $this, 'ajax_get_all' ] );
			add_action( 'wp_ajax_nw_sd_save', [ $this, 'ajax_save' ] );
			add_action( 'wp_ajax_nw_sd_toggle', [ $this, 'ajax_toggle' ] );
			add_action( 'wp_ajax_nw_sd_delete', [ $this, 'ajax_delete' ] );
		}

		public function register_menu(): void {
			add_submenu_page(
				$this->parent_slug,
				'NeoWeaver — Style Dictionary',
				'🔤 Style Dictionary',
				'manage_options',
				$this->page_slug,
				[ $this, 'render_page' ]
			);
		}

		public function enqueue_assets( string $hook ): void {
			if ( ! str_contains( $hook, $this->page_slug ) ) {
				return;
			}

			$ver  = defined( 'NEOWEAVER_VERSION' ) ? NEOWEAVER_VERSION : '1.0.0';
			$base = trailingslashit( NEOWEAVER_PLUGIN_URL );

			wp_enqueue_style(
				'nw-font-chakra-petch',
				'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;500;600;700&display=swap',
				[],
				null
			);

			wp_enqueue_style(
				'nw-admin-core',
				$base . 'assets/css/admin/admin-core.css',
				[ 'nw-font-chakra-petch' ],
				$ver
			);

			wp_enqueue_style(
				'nw-style-dictionary-style',
				$base . 'assets/css/admin/style-dictionary.css',
				[ 'nw-font-chakra-petch', 'nw-admin-core' ],
				$ver
			);

			wp_enqueue_script(
				'nw-style-dictionary-script',
				$base . 'assets/js/admin/style-dictionary.js',
				[ 'jquery', 'nw-lucide' ],
				$ver,
				true
			);

			wp_localize_script(
				'nw-style-dictionary-script',
				'NW_SD',
				[
					'ajax_url'   => admin_url( 'admin-ajax.php' ),
					'nonce'      => wp_create_nonce( $this->nonce_action ),
					'categories' => $this->categories,
				]
			);
		}

		public function render_page(): void { ?>
			<div class="wrap nw-panel" id="nw-sd-panel">
				<div class="nw-panel-header">
					<h1 class="nw-panel-title"><span class="nw-accent">Neo</span>Weaver <span class="nw-panel-subtitle">/ Style Dictionary</span></h1>
					<div class="nw-header-actions">
						<button class="nw-btn nw-btn-ghost" id="nw-refresh-btn" type="button">↻ Refresh</button>
						<button class="nw-btn nw-btn-primary" id="nw-add-btn" type="button">+ New Tag</button>
					</div>
				</div>

				<div id="nw-notice" class="nw-notice" style="display:none;"></div>

				<div class="nw-stats-bar">
					<span class="nw-stat-pill">Total: <strong id="nw-total">—</strong></span>
					<span class="nw-stat-pill nw-pill-active">Active: <strong id="nw-active">—</strong></span>
					<span class="nw-stat-pill nw-pill-inactive">Inactive: <strong id="nw-inactive">—</strong></span>
					<?php foreach ( $this->categories as $cat ) : ?>
						<span class="nw-stat-pill nw-pill-cat-<?php echo esc_attr( $cat ); ?>">
							<?php echo esc_html( ucfirst( $cat ) ); ?>:
							<strong class="nw-cat-count" data-cat="<?php echo esc_attr( $cat ); ?>">—</strong>
						</span>
					<?php endforeach; ?>
				</div>

				<div class="nw-filter-bar">
					<select id="nw-filter-category" class="nw-select nw-filter-select">
						<option value="">All Categories</option>
						<?php foreach ( $this->categories as $c ) : ?>
							<option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( ucfirst( $c ) ); ?></option>
						<?php endforeach; ?>
					</select>

					<select id="nw-filter-active" class="nw-select nw-filter-select">
						<option value="">Active &amp; Inactive</option>
						<option value="1">Active only</option>
						<option value="0">Inactive only</option>
					</select>

					<input
						type="text"
						id="nw-filter-search"
						class="nw-filter-input"
						placeholder="Search tag name or interpretation…"
					>
				</div>

				<div class="nw-table-wrap">
					<table class="nw-table">
						<thead>
							<tr>
								<th>Tag Name</th>
								<th>Category</th>
								<th>Interpretation (EN)</th>
								<th>Active</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody id="nw-sd-tbody">
							<tr class="nw-loading-row">
								<td colspan="5"><div class="nw-spinner"></div> Loading tags…</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
					<div class="nw-modal">
						<div class="nw-modal-header">
							<h2 id="nw-modal-title">Edit Style Tag</h2>
							<button class="nw-modal-close" id="nw-modal-close" type="button">✕</button>
						</div>

						<div class="nw-modal-body">
							<form id="nw-sd-form">
								<input type="hidden" id="nw-field-id" name="id">

								<div class="nw-section-label">Identity</div>
								<div class="nw-form-grid">
									<div class="nw-field">
										<label for="nw-field-tag_name">Tag Name <span class="nw-req">*</span></label>
										<input
											type="text"
											id="nw-field-tag_name"
											name="tag_name"
											required
											placeholder="e.g. neon-shadow"
										>
									</div>

									<div class="nw-field">
										<label for="nw-field-category">Category <span class="nw-req">*</span></label>
										<select id="nw-field-category" name="category" class="nw-select">
											<?php foreach ( $this->categories as $c ) : ?>
												<option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( ucfirst( $c ) ); ?></option>
											<?php endforeach; ?>
										</select>
									</div>

									<div class="nw-field nw-field-full">
										<label for="nw-field-interpretation_en">Interpretation (EN) <span class="nw-req">*</span></label>
										<textarea
											id="nw-field-interpretation_en"
											name="interpretation_en"
											rows="4"
											required
											placeholder="Describe what this style tag means in the game world…"
										></textarea>
									</div>
								</div>

								<div class="nw-section-label">Visibility</div>
								<div class="nw-form-grid">
									<div class="nw-field nw-field-center">
										<label for="nw-field-is_active">Active</label>
										<label class="nw-toggle">
											<input type="checkbox" id="nw-field-is_active" name="is_active">
											<span class="nw-toggle-slider"></span>
										</label>
									</div>
								</div>
							</form>
						</div>

						<div class="nw-modal-footer">
							<button class="nw-btn nw-btn-danger" id="nw-delete-btn" type="button" style="display:none;margin-right:auto;">🗑 Delete</button>
							<button class="nw-btn nw-btn-ghost" id="nw-cancel-btn" type="button">Cancel</button>
							<button class="nw-btn nw-btn-primary" id="nw-save-btn" type="button">
								<span id="nw-save-label">Save Tag</span>
							</button>
						</div>
					</div>
				</div>
			</div>
		<?php }

		private function supa( string $method, string $endpoint, array $body = [], array $extra_headers = [] ): array {
			$headers = array_merge(
				[
					'apikey'        => $this->supabase_key,
					'Authorization' => 'Bearer ' . $this->supabase_key,
					'Content-Type'  => 'application/json',
					'Prefer'        => 'return=representation',
				],
				$extra_headers
			);

			if ( array_key_exists( 'Prefer', $headers ) && '' === $headers['Prefer'] ) {
				unset( $headers['Prefer'] );
			}

			$args = [
				'method'  => strtoupper( $method ),
				'timeout' => 15,
				'headers' => $headers,
			];

			if ( ! empty( $body ) && ! in_array( strtoupper( $method ), [ 'GET', 'DELETE' ], true ) ) {
				$args['body'] = wp_json_encode( $body );
			}

			$res = wp_remote_request(
				$this->supabase_url . '/rest/v1/' . ltrim( $endpoint, '/' ),
				$args
			);

			if ( is_wp_error( $res ) ) {
				return [
					'code'  => 0,
					'data'  => null,
					'error' => $res->get_error_message(),
				];
			}

			$body_raw = wp_remote_retrieve_body( $res );
			$data     = '' !== $body_raw ? json_decode( $body_raw, true ) : null;

			return [
				'code'  => (int) wp_remote_retrieve_response_code( $res ),
				'data'  => $data,
				'error' => null,
			];
		}

		private function normalize_tag( array $tag ): array {
			$tag['id']                = isset( $tag['id'] ) ? (string) $tag['id'] : '';
			$tag['tag_name']          = isset( $tag['tag_name'] ) ? (string) $tag['tag_name'] : '';
			$tag['category']          = isset( $tag['category'] ) ? (string) $tag['category'] : 'general';
			$tag['interpretation_en'] = isset( $tag['interpretation_en'] ) ? (string) $tag['interpretation_en'] : '';
			$tag['is_active']         = ! empty( $tag['is_active'] );
			$tag['created_at']        = isset( $tag['created_at'] ) ? (string) $tag['created_at'] : '';

			return $tag;
		}

		public function ajax_get_all(): void {
			check_ajax_referer( $this->nonce_action, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Forbidden', 403 );
			}

			$res = $this->supa(
				'GET',
				'cyber_style_dictionary?select=id,tag_name,category,interpretation_en,is_active,created_at&order=tag_name.asc'
			);

			if ( $res['error'] ) {
				wp_send_json_error( $res['error'], 500 );
			}

			$code = $res['code'] ?? 0;

			if ( $code < 200 || $code >= 300 ) {
				$msg = is_array( $res['data'] ) && isset( $res['data']['message'] )
					? $res['data']['message']
					: 'Supabase error ' . $code;
				wp_send_json_error( $msg, 500 );
			}

			$rows = is_array( $res['data'] ) ? $res['data'] : [];
			$rows = array_map( [ $this, 'normalize_tag' ], $rows );

			wp_send_json_success( $rows );
		}

		public function ajax_save(): void {
			check_ajax_referer( $this->nonce_action, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Forbidden', 403 );
			}

			$raw = wp_unslash( $_POST['tag'] ?? [] );

			if ( ! is_array( $raw ) ) {
				wp_send_json_error( 'Invalid payload', 400 );
			}

			$id       = sanitize_text_field( $raw['id'] ?? '' );
			$category = sanitize_text_field( $raw['category'] ?? 'general' );

			$payload = [
				'tag_name'          => sanitize_text_field( $raw['tag_name'] ?? '' ),
				'category'          => in_array( $category, $this->categories, true ) ? $category : 'general',
				'interpretation_en' => sanitize_textarea_field( $raw['interpretation_en'] ?? '' ),
				'is_active'         => filter_var( $raw['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN ),
			];

			if ( '' === $payload['tag_name'] ) {
				wp_send_json_error( 'Tag name is required.', 400 );
			}

			if ( '' === $payload['interpretation_en'] ) {
				wp_send_json_error( 'Interpretation is required.', 400 );
			}

			$res = $id
				? $this->supa( 'PATCH', 'cyber_style_dictionary?id=eq.' . rawurlencode( $id ), $payload )
				: $this->supa( 'POST', 'cyber_style_dictionary', $payload );

			if ( $res['error'] ) {
				wp_send_json_error( $res['error'], 500 );
			}

			$code = $res['code'] ?? 0;

			if ( $code < 200 || $code >= 300 ) {
				$msg = is_array( $res['data'] ) && isset( $res['data']['message'] )
					? $res['data']['message']
					: 'Supabase error ' . $code;
				wp_send_json_error( $msg, 500 );
			}

			$item = is_array( $res['data'] ) ? ( $res['data'][0] ?? $res['data'] ) : $res['data'];

			if ( is_array( $item ) ) {
				$item = $this->normalize_tag( $item );
			}

			wp_send_json_success( $item );
		}

		public function ajax_toggle(): void {
			check_ajax_referer( $this->nonce_action, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Forbidden', 403 );
			}

			$id    = sanitize_text_field( wp_unslash( $_POST['tag_id'] ?? '' ) );
			$state = filter_var( wp_unslash( $_POST['is_active'] ?? false ), FILTER_VALIDATE_BOOLEAN );

			if ( '' === $id ) {
				wp_send_json_error( 'Missing ID', 400 );
			}

			$res = $this->supa(
				'PATCH',
				'cyber_style_dictionary?id=eq.' . rawurlencode( $id ),
				[ 'is_active' => $state ]
			);

			if ( $res['error'] ) {
				wp_send_json_error( $res['error'], 500 );
			}

			$code = $res['code'] ?? 0;

			if ( $code < 200 || $code >= 300 ) {
				$msg = is_array( $res['data'] ) && isset( $res['data']['message'] )
					? $res['data']['message']
					: 'Supabase error ' . $code;
				wp_send_json_error( $msg, 500 );
			}

			wp_send_json_success(
				[
					'tag_id'    => $id,
					'is_active' => $state,
				]
			);
		}

		public function ajax_delete(): void {
			check_ajax_referer( $this->nonce_action, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Forbidden', 403 );
			}

			$id = sanitize_text_field( wp_unslash( $_POST['tag_id'] ?? '' ) );

			if ( '' === $id ) {
				wp_send_json_error( 'Missing ID', 400 );
			}

			$res = $this->supa(
				'DELETE',
				'cyber_style_dictionary?id=eq.' . rawurlencode( $id ),
				[],
				[ 'Prefer' => '' ]
			);

			if ( $res['error'] ) {
				wp_send_json_error( $res['error'], 500 );
			}

			$code = $res['code'] ?? 0;

			if ( $code < 200 || $code >= 300 ) {
				$msg = is_array( $res['data'] ) && isset( $res['data']['message'] )
					? $res['data']['message']
					: 'Supabase error ' . $code;
				wp_send_json_error( $msg, 500 );
			}

			wp_send_json_success( 'deleted' );
		}
	}
}

add_action(
	'plugins_loaded',
	static function () {
		new NeoWeaver_Style_Dictionary_Admin();
	},
	20
);
