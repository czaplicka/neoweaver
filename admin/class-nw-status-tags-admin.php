<?php
/**
 * NeoWeaver Admin Panel — Status Tags (cyber_status_tags)
 *
 * Columns: id, label, category, effect_description, mechanic_modifier,
 *          duration, is_stackable, is_debuff, source, color_hex, is_active
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NeoWeaver_Status_Tags_Admin {

	private string $page_slug   = 'neoweaver-status-tags';
	private string $parent_slug = 'neoweaver';

	/**
	 * Sanitise a UUID / slug ID from user input.
	 * Strips everything except hex digits and hyphens.
	 */
	private function sanitize_uuid( string $raw ): string {
		return preg_replace( '/[^a-f0-9\-]/i', '', sanitize_text_field( $raw ) );
	}

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_nw_st_get_all', [ $this, 'ajax_get_all' ] );
		add_action( 'wp_ajax_nw_st_save',    [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_st_toggle',  [ $this, 'ajax_toggle' ] );
		add_action( 'wp_ajax_nw_st_delete',  [ $this, 'ajax_delete' ] );
	}

	/* ------------------------------------------------------------------ */
	/*  Admin menu                                                          */
	/* ------------------------------------------------------------------ */

	public function register_menu(): void {
		add_submenu_page(
			$this->parent_slug,
			'Status Tags',
			'Status Tags',
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Assets                                                              */
	/* ------------------------------------------------------------------ */

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, $this->page_slug ) === false ) {
			return;
		}

		wp_enqueue_style(
			'nw-status-tags-admin',
			plugin_dir_url( __FILE__ ) . '../assets/css/nw-status-tags-admin.css',
			[],
			'1.0.0'
		);

		wp_enqueue_script(
			'nw-status-tags-admin',
			plugin_dir_url( __FILE__ ) . '../assets/js/nw-status-tags-admin.js',
			[ 'jquery' ],
			'1.0.0',
			true
		);

		wp_localize_script( 'nw-status-tags-admin', 'NWSt', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'neoweaver_st' ),
		] );
	}

	/* ------------------------------------------------------------------ */
	/*  Page render                                                         */
	/* ------------------------------------------------------------------ */

	public function render_page(): void {
		?>
		<div class="wrap nw-wrap">
			<h1 class="nw-page-title">⚡ Status Tags</h1>

			<div id="nw-notice" class="nw-notice" style="display:none;"></div>

			<div class="nw-stats-bar">
				<span>Total: <strong id="nw-total">—</strong></span>
				<span>Active: <strong id="nw-active-count">—</strong></span>
				<span>Inactive: <strong id="nw-inactive-count">—</strong></span>
			</div>

			<div class="nw-toolbar">
				<button id="nw-add-btn" class="nw-action-btn">＋ New Tag</button>
				<button id="nw-refresh-btn" class="nw-action-btn nw-btn-secondary">↺ Refresh</button>

				<select id="nw-filter-category">
					<option value="">All Categories</option>
					<option value="combat">Combat</option>
					<option value="magic">Magic</option>
					<option value="social">Social</option>
					<option value="environmental">Environmental</option>
					<option value="tech">Tech</option>
					<option value="mixed">Mixed</option>
				</select>

				<input id="nw-search" type="search" placeholder="Search tags…" />
			</div>

			<table class="nw-table">
				<thead>
					<tr>
						<th>Label</th>
						<th>Category</th>
						<th>Duration</th>
						<th>Flags</th>
						<th>Source</th>
						<th>Active</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody id="nw-st-tbody"></tbody>
			</table>

			<!-- Modal -->
			<div id="nw-modal-overlay" style="display:none;">
				<div class="nw-modal">
					<div class="nw-modal-header">
						<h2 id="nw-modal-title">New Status Tag</h2>
						<button id="nw-modal-close" class="nw-modal-close">✕</button>
					</div>
					<form id="nw-st-form">
						<input type="hidden" name="id" id="nw-field-id" />

						<label>Label *
							<input type="text" name="label" id="nw-field-label" required />
						</label>

						<label>Category
							<select name="category" id="nw-field-category">
								<option value="">— none —</option>
								<option value="combat">Combat</option>
								<option value="magic">Magic</option>
								<option value="social">Social</option>
								<option value="environmental">Environmental</option>
								<option value="tech">Tech</option>
								<option value="mixed">Mixed</option>
							</select>
						</label>

						<label>Effect Description
							<textarea name="effect_description" id="nw-field-effect_description" rows="3"></textarea>
						</label>

						<label>Mechanic Modifier
							<textarea name="mechanic_modifier" id="nw-field-mechanic_modifier" rows="2"></textarea>
						</label>

						<label>Duration
							<input type="text" name="duration" id="nw-field-duration" placeholder="e.g. 3 rounds" />
						</label>

						<label>Source
							<input type="text" name="source" id="nw-field-source" placeholder="e.g. spell, item" />
						</label>

						<label>Color Hex
							<input type="text" name="color_hex" id="nw-field-color_hex" placeholder="#adff00" maxlength="7" />
						</label>

						<div class="nw-checkbox-row">
							<label><input type="checkbox" name="is_stackable" id="nw-field-is_stackable" value="1" /> Stackable</label>
							<label><input type="checkbox" name="is_debuff" id="nw-field-is_debuff" value="1" /> Debuff</label>
							<label><input type="checkbox" name="is_active" id="nw-field-is_active" value="1" checked /> Active</label>
						</div>

						<div class="nw-modal-footer">
							<button type="button" id="nw-save-btn" class="nw-action-btn">
								<span id="nw-save-label">Create Tag</span>
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

	/* ------------------------------------------------------------------ */
	/*  Supabase helper                                                     */
	/* ------------------------------------------------------------------ */

	private function supa( string $method, string $endpoint, array $body = [], array $extra_headers = [] ): array {
		$cfg = function_exists( 'neoweaver_supabase_config' )
			? neoweaver_supabase_config()
			: [];

		$url     = rtrim( $cfg['url'] ?? '', '/' ) . '/rest/v1/' . ltrim( $endpoint, '/' );
		$headers = array_merge( [
			'apikey'        => $cfg['key'] ?? '',
			'Authorization' => 'Bearer ' . ( $cfg['key'] ?? '' ),
			'Content-Type'  => 'application/json',
			'Prefer'        => 'return=representation',
		], $extra_headers );

		$args = [
			'method'  => $method,
			'headers' => $headers,
			'timeout' => 15,
		];

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

	/* ------------------------------------------------------------------ */
	/*  AJAX: get all                                                       */
	/* ------------------------------------------------------------------ */

	public function ajax_get_all(): void {
		check_ajax_referer( 'neoweaver_st', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		$cat = sanitize_text_field( $_POST['filter_category'] ?? '' );
		$qs  = 'cyber_status_tags?order=label.asc&limit=500';
		if ( $cat ) {
			$qs .= '&category=eq.' . rawurlencode( $cat );
		}

		$rows = $this->supa( 'GET', $qs );

		if ( isset( $rows['error'] ) ) {
			wp_send_json_error( $rows['error'] );
		}

		wp_send_json_success( $rows );
	}

	/* ------------------------------------------------------------------ */
	/*  AJAX: save (create or update)                                       */
	/* ------------------------------------------------------------------ */

	public function ajax_save(): void {
		check_ajax_referer( 'neoweaver_st', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		// fix(B1): was (int)$_POST['id'] — UUIDs collapse to 0
		$id = $this->sanitize_uuid( $_POST['id'] ?? '' );

		$label = sanitize_text_field( $_POST['label'] ?? '' );
		if ( ! $label ) {
			wp_send_json_error( 'Label is required' );
		}

		$payload = [
			'label'              => $label,
			'category'           => sanitize_text_field( $_POST['category'] ?? '' ) ?: null,
			'effect_description' => sanitize_textarea_field( $_POST['effect_description'] ?? '' ) ?: null,
			'mechanic_modifier'  => sanitize_textarea_field( $_POST['mechanic_modifier'] ?? '' ) ?: null,
			'duration'           => sanitize_text_field( $_POST['duration'] ?? '' ) ?: null,
			'source'             => sanitize_text_field( $_POST['source'] ?? '' ) ?: null,
			'color_hex'          => preg_replace( '/[^a-f0-9#]/i', '', $_POST['color_hex'] ?? '' ) ?: null,
			'is_stackable'       => ! empty( $_POST['is_stackable'] ),
			'is_debuff'          => ! empty( $_POST['is_debuff'] ),
			'is_active'          => ! empty( $_POST['is_active'] ),
		];

		// fix(B1): rawurlencode($id) — was (int)$id collapsing UUID to 0
		$res = $id
			? $this->supa( 'PATCH', 'cyber_status_tags?id=eq.' . rawurlencode( $id ), $payload )
			: $this->supa( 'POST',  'cyber_status_tags', $payload );

		if ( isset( $res['error'] ) ) {
			wp_send_json_error( $res['error'] );
		}

		wp_send_json_success( $res[0] ?? $res );
	}

	/* ------------------------------------------------------------------ */
	/*  AJAX: toggle active                                                 */
	/* ------------------------------------------------------------------ */

	public function ajax_toggle(): void {
		check_ajax_referer( 'neoweaver_st', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

		// fix(B1): was (int)$_POST['tag_id'] — UUIDs collapse to 0
		$id    = $this->sanitize_uuid( $_POST['tag_id'] ?? '' );
		$state = filter_var( $_POST['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN );
		if ( ! $id ) wp_send_json_error( 'Missing ID' );

		// fix(B1): rawurlencode($id) — was bare $id (int 0)
		$res = $this->supa( 'PATCH', 'cyber_status_tags?id=eq.' . rawurlencode( $id ), [ 'is_active' => $state ] );
		isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( [ 'is_active' => $state ] );
	}

	/* ------------------------------------------------------------------ */
	/*  AJAX: delete                                                        */
	/* ------------------------------------------------------------------ */

	public function ajax_delete(): void {
		check_ajax_referer( 'neoweaver_st', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

		// fix(B1): was (int)$_POST['tag_id'] — UUIDs collapse to 0
		$id = $this->sanitize_uuid( $_POST['tag_id'] ?? '' );
		if ( ! $id ) wp_send_json_error( 'Missing ID' );

		// fix(B1): rawurlencode($id) — was bare $id (int 0)
		$res = $this->supa( 'DELETE', 'cyber_status_tags?id=eq.' . rawurlencode( $id ), [], [ 'Prefer' => '' ] );
		isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( 'deleted' );
	}
}

new NeoWeaver_Status_Tags_Admin();
