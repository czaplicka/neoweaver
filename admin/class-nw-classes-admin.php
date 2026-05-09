<?php
/**
 * NeoWeaver Admin Panel — Classes (cyber_classes)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NeoWeaver_Classes_Admin {

	private string $page_slug   = 'neoweaver-classes';
	private string $parent_slug = 'neoweaver';

	private array $ability_types = [ 'fighter', 'mage', 'rogue', 'tech', 'hybrid', 'support', 'tank', 'scout' ];

	private function sanitize_uuid( string $raw ): string {
		return preg_replace( '/[^a-f0-9\-]/i', '', sanitize_text_field( $raw ) );
	}

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_nw_classes_get_all', [ $this, 'ajax_get_all' ] );
		add_action( 'wp_ajax_nw_classes_save',    [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_classes_delete',  [ $this, 'ajax_delete' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			$this->parent_slug,
			'Classes',
			'Classes',
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, $this->page_slug ) === false ) {
			return;
		}

		wp_enqueue_style(
			'nw-classes-admin',
			plugin_dir_url( __FILE__ ) . '../assets/css/nw-classes-admin.css',
			[],
			'1.0.0'
		);

		wp_enqueue_script(
			'nw-classes-admin',
			plugin_dir_url( __FILE__ ) . '../assets/js/nw-classes-admin.js',
			[ 'jquery' ],
			'1.0.0',
			true
		);

		wp_localize_script( 'nw-classes-admin', 'NWClasses', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'neoweaver_classes' ),
		] );
	}

	public function render_page(): void {
		?>
		<div class="wrap nw-wrap">
			<h1 class="nw-page-title">⚔️ Classes</h1>
			<div id="nw-notice" class="nw-notice" style="display:none;"></div>
			<div class="nw-stats-bar">
				<span>Total: <strong id="nw-total">—</strong></span>
			</div>
			<div class="nw-toolbar">
				<button id="nw-add-btn" class="nw-action-btn">＋ New Class</button>
				<button id="nw-refresh-btn" class="nw-action-btn nw-btn-secondary">↺ Refresh</button>
				<select id="nw-filter-type">
					<option value="">All Types</option>
					<?php foreach ( $this->ability_types as $t ) : ?>
						<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( ucfirst( $t ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<input id="nw-search" type="search" placeholder="Search classes…" />
			</div>
			<table class="nw-table">
				<thead>
					<tr>
						<th>Image</th>
						<th>Name</th>
						<th>Type</th>
						<th>Base Stats</th>
						<th>Tags</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody id="nw-classes-tbody"></tbody>
			</table>
			<div id="nw-modal-overlay" style="display:none;">
				<div class="nw-modal nw-modal-wide">
					<div class="nw-modal-header">
						<h2 id="nw-modal-title">New Class</h2>
						<button id="nw-modal-close" class="nw-modal-close">✕</button>
					</div>
					<form id="nw-classes-form">
						<input type="hidden" name="id" id="nw-field-id" />
						<div class="nw-form-grid">
							<div class="nw-form-col">
								<label>Name *
									<input type="text" name="name" id="nw-field-name" required />
								</label>
								<label>Type
									<select name="class_type" id="nw-field-class_type">
										<option value="">— none —</option>
										<?php foreach ( $this->ability_types as $t ) : ?>
											<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( ucfirst( $t ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
								<label>Description
									<textarea name="description" id="nw-field-description" rows="3"></textarea>
								</label>
								<label>GM Notes
									<textarea name="gm_notes" id="nw-field-gm_notes" rows="2"></textarea>
								</label>
								<label>Tags (comma-separated)
									<input type="text" name="tags" id="nw-field-tags" placeholder="e.g. magic, combat" />
								</label>
								<label>Image URL
									<input type="url" name="img_url" id="nw-field-img_url" />
								</label>
								<div id="nw-img-preview-wrap" style="display:none;">
									<img id="nw-img-preview" src="" alt="Preview" style="max-width:120px;max-height:120px;border-radius:6px;" />
								</div>
							</div>
							<div class="nw-form-col">
								<fieldset class="nw-stats-fieldset">
									<legend>Base Stats</legend>
									<label>HP <input type="number" name="base_hp" id="nw-field-base_hp" min="0" /></label>
									<label>MP <input type="number" name="base_mp" id="nw-field-base_mp" min="0" /></label>
									<label>ATK <input type="number" name="base_atk" id="nw-field-base_atk" min="0" /></label>
									<label>DEF <input type="number" name="base_def" id="nw-field-base_def" min="0" /></label>
									<label>SPD <input type="number" name="base_spd" id="nw-field-base_spd" min="0" /></label>
									<label>INT <input type="number" name="base_int" id="nw-field-base_int" min="0" /></label>
									<label>CHA <input type="number" name="base_cha" id="nw-field-base_cha" min="0" /></label>
								</fieldset>
							</div>
						</div>
						<div class="nw-modal-footer">
							<button type="button" id="nw-save-btn" class="nw-action-btn">
								<span id="nw-save-label">Create Class</span>
							</button>
							<button type="button" id="nw-cancel-btn" class="nw-action-btn nw-btn-secondary">Cancel</button>
							<button type="button" id="nw-delete-btn" class="nw-action-btn nw-btn-danger" style="display:none;">Delete</button>
						</div>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	private function supa( string $method, string $endpoint, array $body = [], array $extra_headers = [] ): array {
		$cfg = function_exists( 'neoweaver_supabase_config' ) ? neoweaver_supabase_config() : [];
		$url = rtrim( $cfg['url'] ?? '', '/' ) . '/rest/v1/' . ltrim( $endpoint, '/' );
		$headers = array_merge( [
			'apikey'        => $cfg['key'] ?? '',
			'Authorization' => 'Bearer ' . ( $cfg['key'] ?? '' ),
			'Content-Type'  => 'application/json',
			'Prefer'        => 'return=representation',
		], $extra_headers );
		$args = [ 'method' => $method, 'headers' => $headers, 'timeout' => 15 ];
		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}
		$resp = wp_remote_request( $url, $args );
		if ( is_wp_error( $resp ) ) {
			return [ 'error' => $resp->get_error_message() ];
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$raw  = wp_remote_retrieve_body( $resp );
		$data = json_decode( $raw, true );
		if ( $code >= 400 ) {
			return [ 'error' => $data['message'] ?? $raw ];
		}
		return is_array( $data ) ? $data : [];
	}

	public function ajax_get_all(): void {
		check_ajax_referer( 'neoweaver_classes', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$type = sanitize_text_field( $_POST['filter_type'] ?? '' );
		$qs   = 'cyber_classes?order=name.asc&limit=500';
		if ( $type ) {
			$qs .= '&class_type=eq.' . rawurlencode( $type );
		}

		$rows = $this->supa( 'GET', $qs );
		if ( isset( $rows['error'] ) ) {
			wp_send_json_error( $rows['error'] );
			return;
		}
		wp_send_json_success( $rows );
	}

	public function ajax_save(): void {
		check_ajax_referer( 'neoweaver_classes', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id   = $this->sanitize_uuid( $_POST['id'] ?? '' );
		$name = sanitize_text_field( $_POST['name'] ?? '' );
		if ( ! $name ) {
			wp_send_json_error( 'Name is required' );
			return;
		}

		$tags_raw = sanitize_text_field( $_POST['tags'] ?? '' );
		$tags     = $tags_raw ? array_values( array_filter( array_map( 'trim', explode( ',', $tags_raw ) ) ) ) : [];

		$payload = [
			'name'        => $name,
			'class_type'  => sanitize_text_field( $_POST['class_type'] ?? '' ) ?: null,
			'description' => sanitize_textarea_field( $_POST['description'] ?? '' ) ?: null,
			'gm_notes'    => sanitize_textarea_field( $_POST['gm_notes'] ?? '' ) ?: null,
			'tags'        => $tags ?: null,
			'img_url'     => esc_url_raw( $_POST['img_url'] ?? '' ) ?: null,
			'base_hp'     => isset( $_POST['base_hp'] ) && $_POST['base_hp'] !== '' ? (int) $_POST['base_hp'] : null,
			'base_mp'     => isset( $_POST['base_mp'] ) && $_POST['base_mp'] !== '' ? (int) $_POST['base_mp'] : null,
			'base_atk'    => isset( $_POST['base_atk'] ) && $_POST['base_atk'] !== '' ? (int) $_POST['base_atk'] : null,
			'base_def'    => isset( $_POST['base_def'] ) && $_POST['base_def'] !== '' ? (int) $_POST['base_def'] : null,
			'base_spd'    => isset( $_POST['base_spd'] ) && $_POST['base_spd'] !== '' ? (int) $_POST['base_spd'] : null,
			'base_int'    => isset( $_POST['base_int'] ) && $_POST['base_int'] !== '' ? (int) $_POST['base_int'] : null,
			'base_cha'    => isset( $_POST['base_cha'] ) && $_POST['base_cha'] !== '' ? (int) $_POST['base_cha'] : null,
		];

		$res = $id
			? $this->supa( 'PATCH', 'cyber_classes?id=eq.' . rawurlencode( $id ), $payload )
			: $this->supa( 'POST', 'cyber_classes', $payload );

		if ( isset( $res['error'] ) ) { wp_send_json_error( $res['error'] ); return; }

		$code = $res['code'] ?? 0;
		( $code >= 200 && $code < 300 )
			? wp_send_json_success( $res['data'][0] ?? $res['data'] )
			: wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code );
	}

	public function ajax_delete(): void {
		check_ajax_referer( 'neoweaver_classes', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
		return;

		$id = $this->sanitize_uuid( $_POST['class_id'] ?? '' );
		if ( ! $id ) wp_send_json_error( 'Missing ID' );
		return;

		isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( 'deleted' );
	}
}

new NeoWeaver_Classes_Admin();
