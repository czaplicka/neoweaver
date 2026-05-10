<?php
/**
 * NeoWeaver Admin Panel — Status Tags (cyber_status_tags)
 *
 * Handles the WP Admin page, AJAX save/delete/toggle, and Supabase
 * sync for status-tag definitions.
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NW_Status_Tags_Admin {

	private string $page_slug    = 'nw-status-tags';
	private string $table        = 'cyber_status_tags';
	private string $nonce_name   = 'nw_status_tags_nonce';
	private string $nonce_action = 'nw_status_tags_nonce';

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
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
			'🔖Status Tags',
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, 'nw-status-tags' ) ) {
			return;
		}

		$plugin_url = defined( 'NW_PLUGIN_URL' )
			? NW_PLUGIN_URL
			: plugin_dir_url( dirname( __FILE__ ) );

		$version = defined( 'NW_VERSION' )
			? NW_VERSION
			: ( defined( 'NEOWEAVER_VERSION' ) ? NEOWEAVER_VERSION : '1.0.0' );

		wp_enqueue_style(
			'nw-admin-shared',
			$plugin_url . 'admin/css/nw-admin-shared.css',
			[],
			$version
		);

		wp_enqueue_script(
			'nw-status-tags',
			$plugin_url . 'admin/js/nw-status-tags.js',
			[ 'jquery' ],
			$version,
			true
		);

		wp_localize_script(
			'nw-status-tags',
			'NW_ST',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( $this->nonce_action ),
			]
		);
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
	/*  Supabase helper                                                  */
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

			$data = tw_supabase_get( $table, $query );

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
			if ( 'DELETE' === $method ) {
				$extra_args['headers']['Prefer'] = '';
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
	/*  AJAX handlers                                                    */
	/* ---------------------------------------------------------------- */

	public function ajax_load(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$res = $this->supa(
			'GET',
			$this->table . '?select=*&order=created_at.desc&limit=1000'
		);

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Failed to load status tags.' );
			return;
		}

		wp_send_json_success( $res['data'] ?? [] );
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
		$color       = sanitize_hex_color( $_POST['color'] ?? '#adff00' ) ?: '#adff00';
		$icon        = sanitize_text_field( $_POST['icon'] ?? '' );

		if ( ! $name ) {
			wp_send_json_error( 'Label is required' );
			return;
		}

		$payload = [
			'name'        => $name,
			'description' => $description,
			'color'       => $color,
			'icon'        => $icon,
		];

		if ( $id ) {
			$res = $this->supa(
				'PATCH',
				$this->table . '?id=eq.' . rawurlencode( $id ),
				$payload
			);
		} else {
			$res = $this->supa(
				'POST',
				$this->table,
				$payload
			);
		}

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Save failed.' );
			return;
		}

		$item = is_array( $res['data'] ) ? ( $res['data'][0] ?? $res['data'] ) : $res['data'];
		wp_send_json_success( $item );
	}

	/* ---------------------------------------------------------------- */
	/*  AJAX: toggle active                                              */
	/* ---------------------------------------------------------------- */

	public function ajax_toggle(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id    = sanitize_text_field( $_POST['id'] ?? '' );
		$state = filter_var( $_POST['value'] ?? false, FILTER_VALIDATE_BOOLEAN );

		if ( ! $id ) {
			wp_send_json_error( 'Missing ID' );
			return;
		}

		$res = $this->supa(
			'PATCH',
			$this->table . '?id=eq.' . rawurlencode( $id ),
			[ 'is_active' => $state ]
		);

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Toggle failed.' );
			return;
		}

		wp_send_json_success( [ 'is_active' => $state ] );
	}

	/* ---------------------------------------------------------------- */
	/*  AJAX: delete                                                     */
	/* ---------------------------------------------------------------- */

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

		$res = $this->supa(
			'DELETE',
			$this->table . '?id=eq.' . rawurlencode( $id )
		);

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Delete failed.' );
			return;
		}

		wp_send_json_success( 'deleted' );
	}
}

add_action(
	'plugins_loaded',
	static function () {
		new NW_Status_Tags_Admin();
	},
	20
);
