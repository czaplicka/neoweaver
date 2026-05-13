<?php
/**
 * NeoWeaver Admin Panel — World Tag Definitions (cyber_world_tag_defs)
 *
 * Columns: id, code, label, icon, color, description, category,
 *          source, sort_order, is_active, created_at, impact
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'NeoWeaver_World_Tag_Defs_Admin' ) ) {

	class NeoWeaver_World_Tag_Defs_Admin {

		private string $supabase_url;
		private string $supabase_key;
		private string $page_slug    = 'neoweaver-world-tag-defs';
		private string $parent_slug  = 'neoweaver';
		private string $nonce_action = 'neoweaver_wtd';

		private array $sources = [ 'system', 'custom', 'imported' ];

		public function __construct() {
			$this->supabase_url = rtrim( tw_supabase_url(), '/' );
			$this->supabase_key = tw_supabase_anon_key();

			add_action( 'admin_menu', [ $this, 'register_menu' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

			add_action( 'wp_ajax_nw_wtd_get_all', [ $this, 'ajax_get_all' ] );
			add_action( 'wp_ajax_nw_wtd_save', [ $this, 'ajax_save' ] );
			add_action( 'wp_ajax_nw_wtd_toggle', [ $this, 'ajax_toggle' ] );
			add_action( 'wp_ajax_nw_wtd_delete', [ $this, 'ajax_delete' ] );
		}

		public function register_menu(): void {
			add_submenu_page(
				$this->parent_slug,
				'NeoWeaver — World Tag Defs',
				'🏷️ World Tag Defs',
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
				'nw-world-tag-defs-style',
				$base . 'assets/css/admin/world-tag-defs.css',
				[ 'nw-font-chakra-petch', 'nw-admin-core' ],
				$ver
			);

			wp_enqueue_script(
				'nw-world-tag-defs-script',
				$base . 'assets/js/admin/world-tag-defs.js',
				[ 'jquery', 'nw-lucide' ],
				$ver,
				true
			);

			wp_localize_script(
				'nw-world-tag-defs-script',
				'NW_WTD',
				[
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( $this->nonce_action ),
					'sources'  => $this->sources,
				]
			);
		}

		public function render_page(): void { ?>
			<div class="wrap nw-panel" id="nw-wtd-panel">
				<div class="nw-panel-header">
					<h1 class="nw-panel-title"><span class="nw-accent">Neo</span>Weaver <span class="nw-panel-subtitle">/ World Tag Defs</span></h1>
					<div class="nw-header-actions">
						<button class="nw-btn nw-btn-ghost" id="nw-refresh-btn" type="button">↻ Refresh</button>
						<button class="nw-btn nw-btn-primary" id="nw-add-btn" type="button">+ New Tag Def</button>
					</div>
				</div>

				<div id="nw-notice" class="nw-notice" style="display:none;"></div>

				<div class="nw-stats-bar">
					<span class="nw-stat-pill">Total: <strong id="nw-total">—</strong></span>
					<span class="nw-stat-pill nw-pill-active">Active: <strong id="nw-active">—</strong></span>
					<span class="nw-stat-pill nw-pill-inactive">Inactive: <strong id="nw-inactive">—</strong></span>
					<span class="nw-stat-pill nw-pill-system">System: <strong id="nw-count-system">—</strong></span>
					<span class="nw-stat-pill nw-pill-custom">Custom: <strong id="nw-count-custom">—</strong></span>
				</div>

				<div class="nw-filter-bar">
					<select id="nw-filter-category" class="nw-select nw-filter-select">
						<option value="">All Categories</option>
					</select>

					<select id="nw-filter-source" class="nw-select nw-filter-select">
						<option value="">All Sources</option>
						<?php foreach ( $this->sources as $s ) : ?>
							<option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( ucfirst( $s ) ); ?></option>
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
						placeholder="Search code, label or description…"
					>
				</div>

				<div class="nw-table-wrap">
					<table class="nw-table">
						<thead>
							<tr>
								<th>Code</th>
								<th>Label</th>
								<th>Icon / Color</th>
								<th>Category</th>
								<th>Source</th>
								<th>Impact</th>
								<th>Order</th>
								<th>Active</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody id="nw-wtd-tbody">
							<tr class="nw-loading-row">
								<td colspan="9"><div class="nw-spinner"></div> Loading tag defs…</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="nw-modal-overlay" id="nw-modal-overlay" style="display:none;">
					<div class="nw-modal">
						<div class="nw-modal-header">
							<h2 id="nw-modal-title">Edit World Tag Def</h2>
							<button class="nw-modal-close" id="nw-modal-close" type="button">✕</button>
						</div>

						<div class="nw-modal-body">
							<form id="nw-wtd-form">
								<input type="hidden" id="nw-field-id" name="id">

								<div class="nw-section-label">Identity</div>
								<div class="nw-form-grid">
									<div class="nw-field">
										<label for="nw-field-code">Code <span class="nw-req">*</span></label>
										<input type="text" id="nw-field-code" name="code" required placeholder="e.g. URBAN_DECAY">
									</div>

									<div class="nw-field">
										<label for="nw-field-label">Label <span class="nw-req">*</span></label>
										<input type="text" id="nw-field-label" name="label" required placeholder="e.g. Urban Decay">
									</div>

									<div class="nw-field nw-field-full">
										<label for="nw-field-description">Description</label>
										<textarea id="nw-field-description" name="description" rows="3" placeholder="What this tag means in the world…"></textarea>
									</div>
								</div>

								<div class="nw-section-label">Appearance</div>
								<div class="nw-form-grid">
									<div class="nw-field">
										<label for="nw-field-icon">Icon (emoji or class)</label>
										<input type="text" id="nw-field-icon" name="icon" placeholder="e.g. 🏙️ or lucide:building">
									</div>

									<div class="nw-field">
										<label for="nw-field-color">Color</label>
										<div class="nw-color-row">
											<input type="color" id="nw-field-color-picker" value="#adff00">
											<input type="text" id="nw-field-color" name="color" value="#adff00" placeholder="#adff00" maxlength="20">
										</div>
									</div>
								</div>

								<div class="nw-section-label">Classification</div>
								<div class="nw-form-grid">
									<div class="nw-field">
										<label for="nw-field-category">Category</label>
										<input type="text" id="nw-field-category" name="category" placeholder="e.g. environment, social, tech">
									</div>

									<div class="nw-field">
										<label for="nw-field-source">Source</label>
										<select id="nw-field-source" name="source" class="nw-select">
											<?php foreach ( $this->sources as $s ) : ?>
												<option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( ucfirst( $s ) ); ?></option>
											<?php endforeach; ?>
										</select>
									</div>

									<div class="nw-field">
										<label for="nw-field-sort_order">Sort Order</label>
										<input type="number" id="nw-field-sort_order" name="sort_order" min="0" max="32767" placeholder="0">
									</div>

									<div class="nw-field">
										<label for="nw-field-impact">Impact (numeric)</label>
										<input type="number" id="nw-field-impact" name="impact" step="0.01" placeholder="0">
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
								<span id="nw-save-label">Save Tag Def</span>
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
			$tag['id']          = isset( $tag['id'] ) ? (int) $tag['id'] : 0;
			$tag['code']        = isset( $tag['code'] ) ? (string) $tag['code'] : '';
			$tag['label']       = isset( $tag['label'] ) ? (string) $tag['label'] : '';
			$tag['icon']        = isset( $tag['icon'] ) ? (string) $tag['icon'] : '';
			$tag['color']       = sanitize_hex_color( (string) ( $tag['color'] ?? '#adff00' ) ) ?: '#adff00';
			$tag['description'] = isset( $tag['description'] ) ? (string) $tag['description'] : '';
			$tag['category']    = isset( $tag['category'] ) ? (string) $tag['category'] : '';
			$tag['source']      = isset( $tag['source'] ) ? (string) $tag['source'] : 'system';
			$tag['sort_order']  = isset( $tag['sort_order'] ) ? (int) $tag['sort_order'] : null;
			$tag['is_active']   = ! empty( $tag['is_active'] );
			$tag['created_at']  = isset( $tag['created_at'] ) ? (string) $tag['created_at'] : '';
			$tag['impact']      = isset( $tag['impact'] ) ? (float) $tag['impact'] : 0;

			return $tag;
		}

		public function ajax_get_all(): void {
			check_ajax_referer( $this->nonce_action, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Forbidden', 403 );
			}

			$res = $this->supa(
				'GET',
				'cyber_world_tag_defs?select=id,code,label,icon,color,description,category,source,sort_order,is_active,created_at,impact&order=sort_order.asc.nullslast,code.asc'
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

			$id     = sanitize_text_field( $raw['id'] ?? '' );
			$source = sanitize_text_field( $raw['source'] ?? 'system' );

			$payload = [
				'code'        => strtoupper( sanitize_text_field( $raw['code'] ?? '' ) ),
				'label'       => sanitize_text_field( $raw['label'] ?? '' ),
				'icon'        => sanitize_text_field( $raw['icon'] ?? '' ),
				'color'       => sanitize_hex_color( $raw['color'] ?? '#adff00' ) ?: '#adff00',
				'description' => sanitize_textarea_field( $raw['description'] ?? '' ),
				'category'    => sanitize_text_field( $raw['category'] ?? '' ),
				'source'      => in_array( $source, $this->sources, true ) ? $source : 'system',
				'sort_order'  => is_numeric( $raw['sort_order'] ?? '' ) ? (int) $raw['sort_order'] : null,
				'impact'      => is_numeric( $raw['impact'] ?? '' ) ? (float) $raw['impact'] : 0,
				'is_active'   => filter_var( $raw['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN ),
			];

			foreach ( [ 'icon', 'description', 'category' ] as $field ) {
				if ( '' === $payload[ $field ] ) {
					$payload[ $field ] = null;
				}
			}

			if ( null === $payload['sort_order'] ) {
				unset( $payload['sort_order'] );
			}

			if ( '' === $payload['code'] ) {
				wp_send_json_error( 'Code is required.', 400 );
			}

			if ( '' === $payload['label'] ) {
				wp_send_json_error( 'Label is required.', 400 );
			}

			$res = $id
				? $this->supa( 'PATCH', 'cyber_world_tag_defs?id=eq.' . rawurlencode( $id ), $payload )
				: $this->supa( 'POST', 'cyber_world_tag_defs', $payload );

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
				'cyber_world_tag_defs?id=eq.' . rawurlencode( $id ),
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
				'cyber_world_tag_defs?id=eq.' . rawurlencode( $id ),
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
		new NeoWeaver_World_Tag_Defs_Admin();
	},
	20
);
