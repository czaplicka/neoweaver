<?php
/**
 * NeoWeaver Admin Panel — Status Tags (cyber_status_tags)
 *
 * Handles the WP Admin page, AJAX save/delete/toggle, and Supabase
 * sync for status-tag definitions.
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NW_Status_Tags_Admin {

	private string $page_slug   = 'nw-status-tags';
	private string $table       = 'cyber_status_tags';
	private string $nonce_name  = 'nw_status_tags_nonce';
	private string $nonce_action = 'nw_status_tags_nonce';

	public function __construct() {
		add_action( 'admin_menu',             [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue' ] );
		add_action( 'wp_ajax_nw_status_tags_load',   [ $this, 'ajax_load' ] );
		add_action( 'wp_ajax_nw_status_tags_save',   [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_status_tags_toggle', [ $this, 'ajax_toggle' ] );
		add_action( 'wp_ajax_nw_status_tags_delete', [ $this, 'ajax_delete' ] );
	}

	/* ---------------------------------------------------------------- */
	/*  Menu & page                                                      */
	/* ---------------------------------------------------------------- */

	public function register_menu(): void {
		add_submenu_page(
			'neoweaver',
			'Status Tags',
			'Status Tags',
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, 'nw-status-tags' ) ) return;
		wp_enqueue_style( 'nw-admin-shared', NW_PLUGIN_URL . 'admin/css/nw-admin-shared.css', [], NW_VERSION );
		wp_enqueue_script( 'nw-status-tags', NW_PLUGIN_URL . 'admin/js/nw-status-tags.js', [ 'jquery' ], NW_VERSION, true );
		wp_localize_script( 'nw-status-tags', 'NW_ST', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( $this->nonce_action ),
		] );
	}

	public function render_page(): void {
		?>
		<div class="wrap nw-admin-wrap">
			<h1>Status Tags</h1>
			<p class="nw-subtitle">Manage status tags used throughout NeoWeaver. Tags are synced with Supabase.</p>

			<button id="nw-add-tag-btn" class="button button-primary">+ Add Status Tag</button>

			<div id="nw-status-tag-form-wrap" style="display:none;" class="nw-card" aria-label="Status Tag Form">
				<h2 id="nw-form-title">Add Status Tag</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="nw-field-name">Tag Name <span aria-hidden="true">*</span></label></th>
						<td><input type="text" id="nw-field-name" class="regular-text" placeholder="e.g. Poisoned" /></td>
					</tr>
					<tr>
						<th><label for="nw-field-description">Description</label></th>
						<td><textarea id="nw-field-description" class="large-text" rows="3" placeholder="Optional short description"></textarea></td>
					</tr>
					<tr>
						<th><label for="nw-field-color">Color</label></th>
						<td><input type="color" id="nw-field-color" value="#adff00" /></td>
					</tr>
					<tr>
						<th><label for="nw-field-icon">Icon Slug</label></th>
						<td><input type="text" id="nw-field-icon" class="regular-text" placeholder="e.g. skull" /></td>
					</tr>
				</table>
				<p>
					<button id="nw-save-tag-btn" class="button button-primary">Save Tag</button>
					<button id="nw-cancel-tag-btn" class="button">Cancel</button>
				</p>
				<div id="nw-form-notice" role="alert" aria-live="polite"></div>
			</div>

			<div id="nw-status-tag-table-wrap">
				<p>Loading…</p>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- */
	/*  AJAX handlers                                                    */
	/* ---------------------------------------------------------------- */

	public function ajax_load(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$rows = NW_Supabase::get_all( $this->table, 'created_at' );
		if ( isset( $rows['error'] ) ) { wp_send_json_error( $rows['error'] ); return; }

		wp_send_json_success( $rows );
	}

	public function ajax_save(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$id          = sanitize_text_field( $_POST['id']          ?? '' );
		$name        = sanitize_text_field( $_POST['name']        ?? '' );
		$description = sanitize_textarea_field( $_POST['description'] ?? '' );
		$color       = sanitize_hex_color(  $_POST['color']       ?? '#adff00' ) ?? '#adff00';
		$icon        = sanitize_text_field( $_POST['icon']        ?? '' );

		if ( ! $name ) { wp_send_json_error( 'Label is required' ); return; }

		$payload = [
			'name'        => $name,
			'description' => $description,
			'color'       => $color,
			'icon'        => $icon,
		];

		if ( $id ) {
			$res  = NW_Supabase::patch( $this->table, $id, $payload );
			$item = $res['data'][0] ?? null;
		} else {
			$res  = NW_Supabase::insert( $this->table, $payload );
			$item = $res['data'][0] ?? null;
		}

		if ( isset( $res['error'] ) ) { wp_send_json_error( $res['error'] ); return; }

		$code = $res['code'] ?? 0;
		if ( $code >= 400 )
			wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code );
		else
			wp_send_json_success( $item );
	}

	/* —— AJAX: toggle active ———————————————————————————————————— */

	public function ajax_toggle(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$id    = sanitize_text_field( $_POST['id']    ?? '' );
		$state = filter_var( $_POST['value'] ?? false, FILTER_VALIDATE_BOOLEAN );

		if ( ! $id ) { wp_send_json_error( 'Missing ID' ); return; }

		$res = NW_Supabase::patch( $this->table, $id, [ 'is_active' => $state ] );
		isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( [ 'is_active' => $state ] );
	}

	/* —— AJAX: delete ————————————————————————————————————————— */

	public function ajax_delete(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$id = sanitize_text_field( $_POST['id'] ?? '' );

		if ( ! $id ) { wp_send_json_error( 'Missing ID' ); return; }

		$res = NW_Supabase::delete( $this->table, $id );
		isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( 'deleted' );
	}
}
