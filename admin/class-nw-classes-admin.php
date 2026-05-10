<?php
/**
 * NeoWeaver Admin Panel — Classes (cyber_classes)
 *
 * Handles the WP Admin page, AJAX save/delete, and Supabase sync
 * for character class definitions.
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NW_Classes_Admin {

	private string $page_slug    = 'nw-classes';
	private string $table        = 'cyber_classes';
	private string $nonce_action = 'nw_classes_nonce';

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'wp_ajax_nw_classes_load',   [ $this, 'ajax_load' ] );
		add_action( 'wp_ajax_nw_classes_save',   [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_classes_delete', [ $this, 'ajax_delete' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'neoweaver',
			'Classes',
			'Classes',
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, $this->page_slug ) ) return;
		wp_enqueue_style( 'nw-admin-shared', NW_PLUGIN_URL . 'admin/css/nw-admin-shared.css', [], NW_VERSION );
		wp_enqueue_script( 'nw-classes', NW_PLUGIN_URL . 'admin/js/nw-classes.js', [ 'jquery' ], NW_VERSION, true );
		wp_localize_script( 'nw-classes', 'NW_CL', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( $this->nonce_action ),
		] );
	}

	public function render_page(): void {
		?>
		<div class="wrap nw-admin-wrap">
			<h1>Character Classes</h1>
			<p class="nw-subtitle">Manage character classes for NeoWeaver. Classes are synced with Supabase.</p>

			<button id="nw-add-class-btn" class="button button-primary">+ Add Class</button>

			<div id="nw-class-form-wrap" style="display:none;" class="nw-card" aria-label="Class Form">
				<h2 id="nw-form-title">Add Class</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="nw-field-name">Class Name <span aria-hidden="true">*</span></label></th>
						<td><input type="text" id="nw-field-name" class="regular-text" placeholder="e.g. Netrunner" /></td>
					</tr>
					<tr>
						<th><label for="nw-field-description">Description</label></th>
						<td><textarea id="nw-field-description" class="large-text" rows="3" placeholder="Optional description"></textarea></td>
					</tr>
					<tr>
						<th><label for="nw-field-icon">Icon Slug</label></th>
						<td><input type="text" id="nw-field-icon" class="regular-text" placeholder="e.g. terminal" /></td>
					</tr>
				</table>
				<p>
					<button id="nw-save-class-btn" class="button button-primary">Save Class</button>
					<button id="nw-cancel-class-btn" class="button">Cancel</button>
				</p>
				<div id="nw-form-notice" role="alert" aria-live="polite"></div>
			</div>

			<div id="nw-class-table-wrap">
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
		$icon        = sanitize_text_field( $_POST['icon']        ?? '' );

		if ( ! $name ) { wp_send_json_error( 'Name is required' ); return; }

		$payload = [
			'name'        => $name,
			'description' => $description,
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
			: wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code );
			return;
        }
			wp_send_json_success( $item );
	}

	public function ajax_delete(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Forbidden', 403 ); return; }

		$id = sanitize_text_field( $_POST['id'] ?? '' );

		if ( ! $id ) { wp_send_json_error( 'Missing ID' ); return; }

		$res = NW_Supabase::delete( $this->table, $id );
		isset( $res['error'] ) ? wp_send_json_error( $res['error'] ) : wp_send_json_success( 'deleted' );
	}
}
