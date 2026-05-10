<?php
/**
 * NeoWeaver Admin Panel — Classes (cyber_classes)
 *
 * Handles the WP Admin page, AJAX save/delete, and Supabase sync
 * for character class definitions.
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
		if ( ! str_contains( $hook, $this->page_slug ) ) {
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
			'nw-classes',
			$plugin_url . 'admin/js/nw-classes.js',
			[ 'jquery' ],
			$version,
			true
		);

		wp_localize_script(
			'nw-classes',
			'NW_CL',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( $this->nonce_action ),
			]
		);
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
	/*  SUPABASE                                                         */
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

			if ( ! empty( $extra_headers ) ) {
				$extra_args['headers'] = array_merge( $extra_args['headers'] ?? [], $extra_headers );
			}

			$res  = tw_supabase_request(
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
	/*  CACHE FALLBACK                                                   */
	/* ---------------------------------------------------------------- */

	private function get_cache_key( string $suffix ): string {
		return 'nw_' . md5( $suffix );
	}

	private function bust_cache( string $scope ): void {
		delete_transient( $this->get_cache_key( $scope . '_all' ) );
	}

	private function cached_get_all( string $table, string $order_by = 'created_at' ): array {
		$cache_key = $this->get_cache_key( $table . '_all' );

		$cached = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$res = $this->supa(
			'GET',
			$table . '?select=*&order=' . rawurlencode( $order_by ) . '.desc'
		);

		if ( ! $res['ok'] ) {
			return [ 'error' => $res['error'] ?? 'Failed to fetch records.' ];
		}

		$rows = is_array( $res['data'] ) ? $res['data'] : [];
		set_transient( $cache_key, $rows, MINUTE_IN_SECONDS * 5 );

		return $rows;
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

		$rows = $this->cached_get_all( $this->table, 'created_at' );

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

		$id          = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$name        = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$icon        = sanitize_text_field( wp_unslash( $_POST['icon'] ?? '' ) );

		if ( ! $name ) {
			wp_send_json_error( 'Name is required' );
			return;
		}

		$payload = [
			'name'        => $name,
			'description' => $description ?: null,
			'iconslug'    => $icon ?: null,
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

		$this->bust_cache( $this->table );
		wp_send_json_success( $item );
	}

	public function ajax_delete(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );

		if ( ! $id ) {
			wp_send_json_error( 'Missing ID' );
			return;
		}

		$res = $this->supa(
			'DELETE',
			$this->table . '?id=eq.' . rawurlencode( $id )
		);

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Delete failed' );
			return;
		}

		$this->bust_cache( $this->table );
		wp_send_json_success( 'deleted' );
	}
}

add_action(
	'plugins_loaded',
	static function () {
		new NW_Classes_Admin();
	},
	20
);
