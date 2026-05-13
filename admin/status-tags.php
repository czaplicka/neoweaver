<?php
/**
 * NeoWeaver Admin Panel — Status Tags (cyber_status_tags)
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'NW_Status_Tags_Admin', false ) ) {
	return;
}

class NW_Status_Tags_Admin {

	private string $page_slug    = 'nw-status-tags';
	private string $table        = 'cyber_status_tags';
	private string $nonce_action = 'nw_status_tags_nonce';

	private const CATEGORIES = [ 'Physical', 'Condition', 'Tech', 'Buff', 'Glitch' ];
	private const DURATIONS  = [ 'permanent', 'scene', 'encounter', 'turn', 'custom' ];

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );

		add_action( 'wp_ajax_nw_status_tags_load', [ $this, 'ajax_load' ] );
		add_action( 'wp_ajax_nw_status_tags_save', [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_status_tags_toggle', [ $this, 'ajax_toggle' ] );
		add_action( 'wp_ajax_nw_status_tags_delete', [ $this, 'ajax_delete' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'neoweaver',
			'Status Tags',
			'🔖 Status Tags',
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, $this->page_slug ) ) {
			return;
		}

		$base = defined( 'NEOWEAVER_PLUGIN_URL' )
			? trailingslashit( NEOWEAVER_PLUGIN_URL )
			: plugin_dir_url( dirname( __FILE__ ) );

		$version = defined( 'NEOWEAVER_VERSION' ) ? NEOWEAVER_VERSION : '1.0.0';

		if ( ! wp_style_is( 'nw-font-chakra-petch', 'registered' ) && ! wp_style_is( 'nw-font-chakra-petch', 'enqueued' ) ) {
			wp_enqueue_style(
				'nw-font-chakra-petch',
				'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;500;600;700&display=swap',
				[],
				null
			);
		}

		wp_enqueue_style(
			'nw-admin-core',
			$base . 'assets/css/admin/admin-core.css',
			[ 'nw-font-chakra-petch' ],
			$version
		);

		wp_enqueue_style(
			'nw-status-tags-style',
			$base . 'assets/css/admin/status-tags.css',
			[ 'nw-font-chakra-petch', 'nw-admin-core' ],
			$version
		);

		wp_enqueue_script(
			'nw-status-tags',
			$base . 'assets/js/admin/status-tags.js',
			[ 'jquery' ],
			$version,
			true
		);

		wp_localize_script(
			'nw-status-tags',
			'NW_ST',
			[
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( $this->nonce_action ),
				'categories' => self::CATEGORIES,
				'durations'  => self::DURATIONS,
			]
		);
	}

	public function render_page(): void {
		?>
		<div class="wrap nw-admin-wrap nw-status-tags-admin">
			<h1>Status Tags</h1>
			<p class="nw-subtitle">Manage status tags used in NeoWeaver.</p>

			<div id="nw-notice" style="display:none;margin:12px 0;padding:10px 12px;border-radius:8px;"></div>

			<div class="nw-toolbar">
				<button id="nw-add-tag-btn" class="button button-primary">+ Add Status Tag</button>
			</div>

			<div id="nw-status-tag-form-wrap" style="display:none;" class="nw-card" aria-label="Status Tag Form">
				<h2 id="nw-form-title">Add Status Tag</h2>

				<table class="form-table" role="presentation">
					<tr>
						<th><label for="nw-field-label">Label *</label></th>
						<td><input type="text" id="nw-field-label" class="regular-text" placeholder="e.g. Poisoned" /></td>
					</tr>
					<tr>
						<th><label for="nw-field-category">Category</label></th>
						<td>
							<select id="nw-field-category">
								<option value="">— Select —</option>
								<?php foreach ( self::CATEGORIES as $category ) : ?>
									<option value="<?php echo esc_attr( $category ); ?>"><?php echo esc_html( $category ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="nw-field-effect_description">Effect Description</label></th>
						<td><textarea id="nw-field-effect_description" class="large-text" rows="3" placeholder="What does this status do?"></textarea></td>
					</tr>
					<tr>
						<th><label for="nw-field-mechanic_modifier">Mechanic Modifier</label></th>
						<td><input type="text" id="nw-field-mechanic_modifier" class="regular-text" placeholder="e.g. -2 accuracy" /></td>
					</tr>
					<tr>
						<th><label for="nw-field-duration">Duration</label></th>
						<td>
							<select id="nw-field-duration">
								<?php foreach ( self::DURATIONS as $duration ) : ?>
									<option value="<?php echo esc_attr( $duration ); ?>" <?php selected( 'scene', $duration ); ?>>
										<?php echo esc_html( ucfirst( $duration ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="nw-field-source">Source</label></th>
						<td><input type="text" id="nw-field-source" class="regular-text" placeholder="e.g. poison, implant, hack" /></td>
					</tr>
					<tr>
						<th><label for="nw-field-color_hex">Color</label></th>
						<td><input type="color" id="nw-field-color_hex" value="#ff0000" /></td>
					</tr>
					<tr>
						<th>Flags</th>
						<td>
							<label style="margin-right:16px;"><input type="checkbox" id="nw-field-is_stackable"> Stackable</label>
							<label style="margin-right:16px;"><input type="checkbox" id="nw-field-is_debuff" checked> Debuff</label>
							<label><input type="checkbox" id="nw-field-is_active" checked> Active</label>
						</td>
					</tr>
				</table>

				<p>
					<button id="nw-save-tag-btn" class="button button-primary">Save Tag</button>
					<button id="nw-cancel-tag-btn" class="button">Cancel</button>
					<button id="nw-delete-tag-btn" class="button button-link-delete" style="display:none;margin-left:10px;">Delete</button>
				</p>

				<div id="nw-form-notice" role="alert" aria-live="polite"></div>
			</div>

			<div id="nw-status-tag-table-wrap">
				<p>Loading…</p>
			</div>
		</div>
		<?php
	}

	private function supa( string $method, string $endpoint, array $body = [], array $extra_headers = [] ): array {
		$method = strtoupper( $method );

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

			$ok   = $res['ok'] ?? false;
			$code = (int) ( $res['code'] ?? 0 );
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

	private function is_uuid( string $value ): bool {
		return (bool) preg_match(
			'/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
			$value
		);
	}

	private function normalize_id( $value ): string {
		return sanitize_text_field( trim( (string) $value ) );
	}

	private function get_request_id(): string {
		return $this->normalize_id( wp_unslash( $_POST['id'] ?? '' ) );
	}

	private function is_valid_id( string $id ): bool {
		if ( '' === $id ) {
			return false;
		}

		if ( ctype_digit( $id ) ) {
			return true;
		}

		return $this->is_uuid( $id );
	}

	private function encode_id_filter( string $id ): string {
		return rawurlencode( $id );
	}

	private function normalize_row( array $row ): array {
		$row['id'] = isset( $row['id'] ) ? (string) $row['id'] : '';
		$row['label'] = (string) ( $row['label'] ?? '' );
		$row['category'] = (string) ( $row['category'] ?? '' );
		$row['effect_description'] = (string) ( $row['effect_description'] ?? '' );
		$row['mechanic_modifier'] = (string) ( $row['mechanic_modifier'] ?? '' );
		$row['duration'] = (string) ( $row['duration'] ?? 'scene' );
		$row['source'] = (string) ( $row['source'] ?? '' );
		$row['color_hex'] = sanitize_hex_color( (string) ( $row['color_hex'] ?? '#ff0000' ) ) ?: '#ff0000';
		$row['is_stackable'] = ! empty( $row['is_stackable'] );
		$row['is_debuff'] = ! empty( $row['is_debuff'] );
		$row['is_active'] = ! empty( $row['is_active'] );

		return $row;
	}

	public function ajax_load(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		$res = $this->supa(
			'GET',
			$this->table . '?select=*&order=label.asc&limit=1000'
		);

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Failed to load status tags.', 500 );
		}

		$rows = is_array( $res['data'] ) ? $res['data'] : [];
		$rows = array_map( [ $this, 'normalize_row' ], $rows );

		wp_send_json_success( $rows );
	}

	public function ajax_save(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		$id                 = $this->get_request_id();
		$label              = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
		$category           = sanitize_text_field( wp_unslash( $_POST['category'] ?? '' ) );
		$effect_description = sanitize_textarea_field( wp_unslash( $_POST['effect_description'] ?? '' ) );
		$mechanic_modifier  = sanitize_text_field( wp_unslash( $_POST['mechanic_modifier'] ?? '' ) );
		$duration           = sanitize_text_field( wp_unslash( $_POST['duration'] ?? 'scene' ) );
		$source             = sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) );
		$color_hex          = sanitize_hex_color( wp_unslash( $_POST['color_hex'] ?? '#ff0000' ) ) ?: '#ff0000';
		$is_stackable       = ! empty( $_POST['is_stackable'] );
		$is_debuff          = ! empty( $_POST['is_debuff'] );
		$is_active          = ! empty( $_POST['is_active'] );

		if ( '' === $label ) {
			wp_send_json_error( 'Label is required', 400 );
		}

		if ( '' !== $category && ! in_array( $category, self::CATEGORIES, true ) ) {
			wp_send_json_error( 'Invalid category', 400 );
		}

		if ( ! in_array( $duration, self::DURATIONS, true ) ) {
			wp_send_json_error( 'Invalid duration', 400 );
		}

		if ( '' !== $id && ! $this->is_valid_id( $id ) ) {
			wp_send_json_error( 'Invalid ID format', 400 );
		}

		$payload = [
			'label'              => $label,
			'category'           => $category ?: null,
			'effect_description' => $effect_description ?: null,
			'mechanic_modifier'  => $mechanic_modifier ?: null,
			'duration'           => $duration,
			'is_stackable'       => $is_stackable,
			'is_debuff'          => $is_debuff,
			'source'             => $source ?: null,
			'color_hex'          => $color_hex,
			'is_active'          => $is_active,
		];

		$res = '' !== $id
			? $this->supa( 'PATCH', $this->table . '?id=eq.' . $this->encode_id_filter( $id ), $payload )
			: $this->supa( 'POST', $this->table, $payload );

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Save failed.', 500 );
		}

		$item = is_array( $res['data'] ) ? ( $res['data'][0] ?? $res['data'] ) : $res['data'];

		if ( is_array( $item ) ) {
			$item = $this->normalize_row( $item );
		}

		wp_send_json_success( $item );
	}

	public function ajax_toggle(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		$id    = $this->get_request_id();
		$state = filter_var( wp_unslash( $_POST['value'] ?? false ), FILTER_VALIDATE_BOOLEAN );

		if ( ! $this->is_valid_id( $id ) ) {
			wp_send_json_error( 'Missing or invalid ID', 400 );
		}

		$res = $this->supa(
			'PATCH',
			$this->table . '?id=eq.' . $this->encode_id_filter( $id ),
			[ 'is_active' => $state ]
		);

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Toggle failed.', 500 );
		}

		wp_send_json_success(
			[
				'id'        => $id,
				'is_active' => $state,
			]
		);
	}

	public function ajax_delete(): void {
		check_ajax_referer( $this->nonce_action, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		$id = $this->get_request_id();

		if ( ! $this->is_valid_id( $id ) ) {
			wp_send_json_error( 'Missing or invalid ID', 400 );
		}

		$res = $this->supa(
			'DELETE',
			$this->table . '?id=eq.' . $this->encode_id_filter( $id )
		);

		if ( ! $res['ok'] ) {
			wp_send_json_error( $res['error'] ?? 'Delete failed.', 500 );
		}

		wp_send_json_success( 'deleted' );
	}
}
