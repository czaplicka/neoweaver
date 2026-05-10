<?php
/**
 * NeoWeaver Admin — Skills (cyber_skills)
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NW_Skills_Admin {

	use NW_Transient_Cache;

	private string $page_slug    = 'nw-skills';
	private string $table        = 'cyber_skills';
	private string $nonce_action = 'nw_skills_nonce';

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'wp_ajax_nw_skills_load',   [ $this, 'ajax_load' ] );
		add_action( 'wp_ajax_nw_skills_save',   [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_skills_delete', [ $this, 'ajax_delete' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'neoweaver',
			'Skills',
			'✨Skills',
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	private string $page_hook = '';

public function register_menu(): void {
	$this->page_hook = add_submenu_page(
		'neoweaver',
		'Skills',
		'✨Skills',
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
		'nw-admin-shared',
		NEOWEAVER_PLUGIN_URL . 'admin/css/skills-admin.css',
		[],
		defined( 'NW_VERSION' ) ? NW_VERSION : null
	);

	wp_enqueue_script(
		'nw-skills',
		NEOWEAVER_PLUGIN_URL . 'admin/js/skills-admin.js',
		[ 'jquery' ],
		defined( 'NW_VERSION' ) ? NW_VERSION : null,
		true
	);

	wp_localize_script(
		'nw-skills',
		'NW_SK',
		[
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( $this->nonce_action ),
		]
	);
}

	public function render_page(): void {
		?>
		<div class="wrap nw-admin-wrap">
			<h1>Skills</h1>
			<button id="nw-add-skill-btn" class="button button-primary">+ Add Skill</button>
			<div id="nw-skill-form-wrap" style="display:none;" class="nw-card">
				<h2 id="nw-form-title">Add Skill</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="nw-field-name">Skill Name *</label></th>
						<td><input type="text" id="nw-field-name" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="nw-field-description">Description</label></th>
						<td><textarea id="nw-field-description" class="large-text" rows="3"></textarea></td>
					</tr>
					<tr>
						<th><label for="nw-field-category">Category</label></th>
						<td><input type="text" id="nw-field-category" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="nw-field-stat">Linked Stat</label></th>
						<td><input type="text" id="nw-field-stat" class="regular-text" /></td>
					</tr>
				</table>
				<p>
					<button id="nw-save-skill-btn" class="button button-primary">Save Skill</button>
					<button id="nw-cancel-skill-btn" class="button">Cancel</button>
				</p>
				<div id="nw-form-notice" role="alert" aria-live="polite"></div>
			</div>
			<div id="nw-skill-table-wrap"><p>Loading…</p></div>
		</div>
		<?php
	}

	public function ajax_load(): void {
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

		wp_send_json_success( $rows );
	}

	public function ajax_save(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id          = sanitize_text_field( $_POST['id'] ?? '' );
		$name        = sanitize_text_field( $_POST['name'] ?? '' );
		$description = sanitize_textarea_field( $_POST['description'] ?? '' );
		$category    = sanitize_text_field( $_POST['category'] ?? '' );
		$stat        = sanitize_text_field( $_POST['stat'] ?? '' );

		if ( ! $name ) {
			wp_send_json_error( 'Name is required' );
			return;
		}

		$payload = compact( 'name', 'description', 'category', 'stat' );

		$res  = $id ? NW_Supabase::patch( $this->table, $id, $payload ) : NW_Supabase::insert( $this->table, $payload );
		$item = $res['data'][0] ?? null;

		if ( isset( $res['error'] ) ) {
			wp_send_json_error( $res['error'] );
			return;
		}

		$code = $res['code'] ?? 0;
		if ( $code >= 400 ) {
			wp_send_json_error( $res['data']['message'] ?? 'Supabase error ' . $code );
			return;
		}

		$this->bust_cache( $this->table );
		wp_send_json_success( $item );
	}

	public function ajax_delete(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id = sanitize_text_field( $_POST['id'] ?? '' );
		if ( ! $id ) {
			wp_send_json_error( 'Missing ID' );
			return;
		}

		$res = NW_Supabase::delete( $this->table, $id );
		if ( isset( $res['error'] ) ) {
			wp_send_json_error( $res['error'] );
			return;
		}

		$this->bust_cache( $this->table );
		wp_send_json_success( 'deleted' );
	}
}

add_action(
	'plugins_loaded',
	static function () {
		new NW_Skills_Admin();
	},
	20
);
