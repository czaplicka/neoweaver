<?php
/**
 * NeoWeaver Admin — Seasons Config (cyber_seasons_config)
 *
 * Full CRUD for weather/season configuration per season.
 *
 * Constraints enforced both client- and server-side:
 * - temp_modifier > 0
 * - weight_sun + weight_cloudy + weight_rain + weight_fog + weight_storm + weight_snow = 100
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'NeoWeaver_Seasons_Admin', false ) ) {
	return;
}

class NeoWeaver_Seasons_Admin {

	private string $slug      = 'neoweaver';
	private string $page_slug = 'nw-seasons';
	private string $table     = 'cyber_seasons_config';

	/**
	 * Weather weight fields in order.
	 *
	 * @var array<string,string>
	 */
	private array $weights = [
		'weight_sun'    => 'Sun',
		'weight_cloudy' => 'Cloudy',
		'weight_rain'   => 'Rain',
		'weight_fog'    => 'Fog',
		'weight_storm'  => 'Storm',
		'weight_snow'   => 'Snow',
	];

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_submenu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_nw_season_list', [ $this, 'ajax_list' ] );
		add_action( 'wp_ajax_nw_season_get', [ $this, 'ajax_get' ] );
		add_action( 'wp_ajax_nw_season_save', [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_season_delete', [ $this, 'ajax_delete' ] );
	}

	/* ================================================================ */
	/* MENU                                                             */
	/* ================================================================ */

	public function register_submenu(): void {
		add_submenu_page(
			$this->slug,
			'Seasons Config',
			'🔄 Seasons Config',
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	/* ================================================================ */
	/* ASSETS                                                           */
	/* ================================================================ */

	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, $this->page_slug ) ) {
			return;
		}

		$ver = defined( 'NEOWEAVER_VERSION' ) ? NEOWEAVER_VERSION : '1.0.0';

		if ( ! wp_style_is( 'nw-font-chakra-petch', 'registered' ) && ! wp_style_is( 'nw-font-chakra-petch', 'enqueued' ) ) {
			wp_enqueue_style(
				'nw-font-chakra-petch',
				'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
				[],
				null
			);
		}

		wp_enqueue_style(
			'nw-admin-core',
			NEOWEAVER_PLUGIN_URL . 'assets/css/admin/admin-core.css',
			[ 'nw-font-chakra-petch' ],
			$ver
		);

		wp_enqueue_style(
			'nw-seasons-admin',
			NEOWEAVER_PLUGIN_URL . 'assets/css/admin/seasons.css',
			[ 'nw-font-chakra-petch', 'nw-admin-core' ],
			$ver
		);

		wp_enqueue_script(
			'nw-seasons-admin',
			NEOWEAVER_PLUGIN_URL . 'assets/js/admin/seasons.js',
			[ 'jquery' ],
			$ver,
			true
		);

		wp_localize_script(
			'nw-seasons-admin',
			'nwSeasonsData',
			[
				'nonce'   => wp_create_nonce( 'nw_seasons_nonce' ),
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'weights' => $this->weights,
			]
		);
	}

	/* ================================================================ */
	/* SUPABASE HELPERS                                                 */
	/* ================================================================ */

	private function supa_url(): string {
		return function_exists( 'tw_supabase_url' ) ? trim( (string) tw_supabase_url() ) : '';
	}

	private function supa_key(): string {
		if ( function_exists( 'tw_supabase_service_key' ) ) {
			$key = trim( (string) tw_supabase_service_key() );
			if ( '' !== $key ) {
				return $key;
			}
		}

		if ( function_exists( 'tw_supabase_anon_key' ) ) {
			$key = trim( (string) tw_supabase_anon_key() );
			if ( '' !== $key ) {
				return $key;
			}
		}

		return '';
	}

	private function headers( array $extra = [] ): array {
		$key = $this->supa_key();

		return array_merge(
			[
				'apikey'        => $key,
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
			$extra
		);
	}

	/**
	 * Unified Supabase request helper.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   PostgREST path.
	 * @param mixed  $body   Optional request body.
	 * @param array  $extra_headers Extra headers.
	 * @return array{ok:bool,status:int,body:mixed,error:?string,raw:string}
	 */
	private function supa_request( string $method, string $path, $body = null, array $extra_headers = [] ): array {
		$url_base = $this->supa_url();
		$key      = $this->supa_key();

		if ( '' === $url_base ) {
			return [
				'ok'     => false,
				'status' => 0,
				'body'   => null,
				'error'  => 'Supabase URL not configured.',
				'raw'    => '',
			];
		}

		if ( '' === $key ) {
			return [
				'ok'     => false,
				'status' => 0,
				'body'   => null,
				'error'  => 'Supabase key not configured.',
				'raw'    => '',
			];
		}

		$url  = rtrim( $url_base, '/' ) . '/rest/v1/' . ltrim( $path, '/' );
		$args = [
			'method'  => strtoupper( $method ),
			'timeout' => 20,
			'headers' => $this->headers( $extra_headers ),
		];

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$res = wp_remote_request( $url, $args );

		if ( is_wp_error( $res ) ) {
			return [
				'ok'     => false,
				'status' => 0,
				'body'   => null,
				'error'  => $res->get_error_message(),
				'raw'    => '',
			];
		}

		$status = (int) wp_remote_retrieve_response_code( $res );
		$raw    = (string) wp_remote_retrieve_body( $res );
		$body_d = json_decode( $raw, true );

		$error = null;

		if ( $status < 200 || $status >= 300 ) {
			if ( is_array( $body_d ) ) {
				if ( ! empty( $body_d['message'] ) ) {
					$error = (string) $body_d['message'];
				} elseif ( ! empty( $body_d['hint'] ) ) {
					$error = (string) $body_d['hint'];
				} elseif ( ! empty( $body_d['details'] ) ) {
					$error = (string) $body_d['details'];
				}
			}

			if ( ! $error ) {
				$error = $raw ? mb_substr( $raw, 0, 500 ) : 'Supabase request failed.';
			}
		}

		return [
			'ok'     => ( $status >= 200 && $status < 300 ),
			'status' => $status,
			'body'   => $body_d,
			'error'  => $error,
			'raw'    => $raw,
		];
	}

	private function supa_get( string $path ): array {
		return $this->supa_request( 'GET', $path );
	}

	private function supa_post( string $path, array $payload ): array {
		return $this->supa_request(
			'POST',
			$path,
			$payload,
			[ 'Prefer' => 'return=representation' ]
		);
	}

	private function supa_patch( string $season_name, array $payload ): array {
		return $this->supa_request(
			'PATCH',
			$this->table . '?season_name=eq.' . rawurlencode( $season_name ),
			$payload,
			[ 'Prefer' => 'return=representation' ]
		);
	}

	private function supa_delete( string $season_name ): array {
		return $this->supa_request(
			'DELETE',
			$this->table . '?season_name=eq.' . rawurlencode( $season_name )
		);
	}

	/* ================================================================ */
	/* VALIDATION                                                       */
	/* ================================================================ */

	private function validate_weights( array $data ): string {
		$sum = 0;

		foreach ( array_keys( $this->weights ) as $key ) {
			$sum += isset( $data[ $key ] ) ? (int) $data[ $key ] : 0;
		}

		if ( 100 !== $sum ) {
			return 'Weather weights must sum to exactly 100 (current sum: ' . $sum . ').';
		}

		return '';
	}

	private function sanitize_weight( string $key ): int {
		$value = wp_unslash( $_POST[ $key ] ?? 0 );
		return max( 0, min( 100, (int) $value ) );
	}

	private function build_payload_from_post( string $season_name ): array {
		$weights = [];
		foreach ( array_keys( $this->weights ) as $key ) {
			$weights[ $key ] = $this->sanitize_weight( $key );
		}

		$sort_order = isset( $_POST['sort_order'] ) && '' !== (string) $_POST['sort_order']
			? (int) wp_unslash( $_POST['sort_order'] )
			: 0;

		return array_merge(
			[
				'season_name'   => $season_name,
				'description'   => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ) ?: null,
				'temp_modifier' => (float) wp_unslash( $_POST['temp_modifier'] ?? 1.0 ),
				'color'         => sanitize_text_field( wp_unslash( $_POST['color'] ?? '' ) ) ?: null,
				'icon'          => sanitize_text_field( wp_unslash( $_POST['icon'] ?? '' ) ) ?: null,
				'sort_order'    => $sort_order,
			],
			$weights
		);
	}

	/* ================================================================ */
	/* AJAX                                                             */
	/* ================================================================ */

	public function ajax_list(): void {
		check_ajax_referer( 'nw_seasons_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$res = $this->supa_get( $this->table . '?select=*&order=sort_order.asc,season_name.asc' );

		if ( ! $res['ok'] ) {
			wp_send_json_error( 'Supabase error: ' . ( $res['error'] ?? 'Unknown error' ), 500 );
			return;
		}

		wp_send_json_success( is_array( $res['body'] ) ? $res['body'] : [] );
		return;
	}

	public function ajax_get(): void {
		check_ajax_referer( 'nw_seasons_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$name = sanitize_text_field( wp_unslash( $_POST['season_name'] ?? '' ) );

		if ( '' === $name ) {
			wp_send_json_error( 'Invalid name.', 400 );
			return;
		}

		$res = $this->supa_get(
			$this->table . '?season_name=eq.' . rawurlencode( $name ) . '&select=*&limit=1'
		);

		if ( ! $res['ok'] ) {
			wp_send_json_error( 'Load failed: ' . ( $res['error'] ?? 'Unknown error' ), 500 );
			return;
		}

		if ( empty( $res['body'][0] ) ) {
			wp_send_json_error( 'Not found.', 404 );
			return;
		}

		wp_send_json_success( $res['body'][0] );
		return;
	}

	public function ajax_save(): void {
		check_ajax_referer( 'nw_seasons_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$is_edit     = ! empty( wp_unslash( $_POST['is_edit'] ?? false ) );
		$orig_name   = sanitize_text_field( wp_unslash( $_POST['orig_season_name'] ?? '' ) );
		$season_name = sanitize_text_field( wp_unslash( $_POST['season_name'] ?? '' ) );

		if ( '' === $season_name ) {
			wp_send_json_error( 'Season name is required.', 400 );
			return;
		}

		if ( $is_edit && '' === $orig_name ) {
			wp_send_json_error( 'Original season name is missing.', 400 );
			return;
		}

		if ( $is_edit && $orig_name !== $season_name ) {
			wp_send_json_error( 'Changing season name during edit is disabled. Create a new season instead.', 400 );
			return;
		}

		$weights = [];
		foreach ( array_keys( $this->weights ) as $key ) {
			$weights[ $key ] = $this->sanitize_weight( $key );
		}

		$weight_err = $this->validate_weights( $weights );
		if ( '' !== $weight_err ) {
			wp_send_json_error( $weight_err, 400 );
			return;
		}

		$temp_mod = (float) wp_unslash( $_POST['temp_modifier'] ?? 1.0 );
		if ( $temp_mod <= 0 ) {
			wp_send_json_error( 'temp_modifier must be > 0.', 400 );
			return;
		}

		$payload = $this->build_payload_from_post( $season_name );

		if ( $is_edit ) {
			$res = $this->supa_patch( $orig_name, $payload );
		} else {
			$exists = $this->supa_get(
				$this->table . '?season_name=eq.' . rawurlencode( $season_name ) . '&select=season_name&limit=1'
			);

			if ( $exists['ok'] && ! empty( $exists['body'] ) ) {
				wp_send_json_error( 'Season with this name already exists.', 409 );
				return;
			}

			$res = $this->supa_post( $this->table, $payload );
		}

		if ( ! $res['ok'] ) {
			$msg = $res['error'] ?? ( 'Save failed (HTTP ' . ( $res['status'] ?? 0 ) . ').' );
			wp_send_json_error( $msg, 500 );
			return;
		}

		$item = null;
		if ( is_array( $res['body'] ) && isset( $res['body'][0] ) ) {
			$item = $res['body'][0];
		}

		wp_send_json_success(
			[
				'season_name' => $season_name,
				'item'        => $item,
			]
		);
		return;
	}

	public function ajax_delete(): void {
		check_ajax_referer( 'nw_seasons_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		$name = sanitize_text_field( wp_unslash( $_POST['season_name'] ?? '' ) );

		if ( '' === $name ) {
			wp_send_json_error( 'Invalid name.', 400 );
			return;
		}

		$res = $this->supa_delete( $name );

		if ( ! $res['ok'] ) {
			$msg = $res['error'] ?? ( 'Delete failed (HTTP ' . ( $res['status'] ?? 0 ) . ').' );
			wp_send_json_error( $msg, 500 );
			return;
		}

		wp_send_json_success( [ 'season_name' => $name ] );
		return;
	}

	/* ================================================================ */
	/* RENDER                                                           */
	/* ================================================================ */

	public function render_page(): void {
		?>
		<div class="wrap nw-seasons-wrap">

			<div class="nw-page-header">
				<h1 class="nw-page-title">
					<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="vertical-align:-4px;margin-right:6px;">
						<path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.36-6.36-.71.71M6.34 17.66l-.71.71M17.66 17.66l.71.71M6.34 6.34l-.71-.71M12 7a5 5 0 1 0 0 10A5 5 0 0 0 12 7z"/>
					</svg>
					Seasons Config
				</h1>
				<button class="nw-btn nw-btn-primary" id="nw-season-add-btn" type="button">+ Add Season</button>
			</div>

			<div id="nw-season-table-wrap">
				<div class="nw-spinner" style="margin:40px auto;display:block;"></div>
			</div>

			<div id="nw-season-modal" class="nw-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="nw-season-modal-title">
				<div class="nw-modal-box nw-season-modal-box">
					<div class="nw-modal-header">
						<h2 class="nw-modal-title" id="nw-season-modal-title">Add Season</h2>
						<button class="nw-modal-close" id="nw-season-modal-close" type="button" aria-label="Close">&times;</button>
					</div>

					<form id="nw-season-form" autocomplete="off">
						<input type="hidden" name="is_edit" id="nw-season-is-edit" value="0">
						<input type="hidden" name="orig_season_name" id="nw-season-orig-name">

						<div class="nw-form-grid-2">
							<div class="nw-field">
								<label class="nw-label" for="nw-season-name">Season Name <span class="nw-required">*</span> <span class="nw-field-hint">(primary key)</span></label>
								<input class="nw-input" type="text" id="nw-season-name" name="season_name" required placeholder="e.g. Spring, Winter, Monsoon">
							</div>

							<div class="nw-field">
								<label class="nw-label" for="nw-season-sort">Sort Order</label>
								<input class="nw-input" type="number" id="nw-season-sort" name="sort_order" value="0">
							</div>

							<div class="nw-field nw-field-full">
								<label class="nw-label" for="nw-season-desc">Description</label>
								<textarea class="nw-input nw-textarea" id="nw-season-desc" name="description" rows="2"></textarea>
							</div>

							<div class="nw-field">
								<label class="nw-label" for="nw-season-temp">Temp Modifier <span class="nw-field-hint">(> 0)</span></label>
								<input class="nw-input" type="number" id="nw-season-temp" name="temp_modifier" step="0.01" min="0.01" value="1.00">
							</div>

							<div class="nw-field">
								<label class="nw-label" for="nw-season-color">Color <span class="nw-field-hint">(hex / name)</span></label>
								<div style="display:flex;gap:8px;align-items:center;">
									<input class="nw-input" type="text" id="nw-season-color" name="color" placeholder="#adff00" style="flex:1;">
									<input type="color" id="nw-season-color-picker" value="#adff00" style="width:36px;height:34px;padding:2px;border:1px solid #2a2a2a;border-radius:4px;background:#1a1a1a;cursor:pointer;">
								</div>
							</div>

							<div class="nw-field">
								<label class="nw-label" for="nw-season-icon">Icon <span class="nw-field-hint">(emoji or slug)</span></label>
								<input class="nw-input" type="text" id="nw-season-icon" name="icon" placeholder="☀️">
							</div>
						</div>

						<div class="nw-weights-section">
							<div class="nw-weights-header">
								<span class="nw-label">Weather Weights</span>
								<span class="nw-weights-sum-badge" id="nw-weights-sum-badge">Sum: <strong id="nw-weights-sum">100</strong> / 100</span>
							</div>
							<p class="nw-weights-note">Must sum to exactly 100. Adjust sliders or inputs.</p>

							<div class="nw-weights-grid" id="nw-weights-grid">
								<?php
								$icon_map = [
									'weight_sun'    => '☀️',
									'weight_cloudy' => '☁️',
									'weight_rain'   => '🌧️',
									'weight_fog'    => '🌫️',
									'weight_storm'  => '⛈️',
									'weight_snow'   => '❄️',
								];

								$default_map = [
									'weight_sun'    => 25,
									'weight_cloudy' => 25,
									'weight_rain'   => 25,
									'weight_fog'    => 25,
									'weight_storm'  => 0,
									'weight_snow'   => 0,
								];

								foreach ( $this->weights as $key => $label ) :
									?>
									<div class="nw-weight-row">
										<span class="nw-weight-icon"><?php echo esc_html( $icon_map[ $key ] ); ?></span>
										<label class="nw-weight-label" for="nw-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
										<input
											type="range"
											class="nw-weight-range"
											id="nw-<?php echo esc_attr( $key ); ?>-range"
											data-target="nw-<?php echo esc_attr( $key ); ?>"
											min="0"
											max="100"
											value="<?php echo (int) $default_map[ $key ]; ?>"
										>
										<input
											class="nw-input nw-weight-num"
											type="number"
											id="nw-<?php echo esc_attr( $key ); ?>"
											name="<?php echo esc_attr( $key ); ?>"
											min="0"
											max="100"
											value="<?php echo (int) $default_map[ $key ]; ?>"
										>
										<span class="nw-weight-pct" id="nw-<?php echo esc_attr( $key ); ?>-pct"><?php echo (int) $default_map[ $key ]; ?>%</span>
									</div>
								<?php endforeach; ?>
							</div>

							<div class="nw-weather-bar" id="nw-weather-bar" title="Weather distribution">
								<div class="nw-bar-seg nw-bar-sun" id="nw-bar-sun" title="Sun"></div>
								<div class="nw-bar-seg nw-bar-cloudy" id="nw-bar-cloudy" title="Cloudy"></div>
								<div class="nw-bar-seg nw-bar-rain" id="nw-bar-rain" title="Rain"></div>
								<div class="nw-bar-seg nw-bar-fog" id="nw-bar-fog" title="Fog"></div>
								<div class="nw-bar-seg nw-bar-storm" id="nw-bar-storm" title="Storm"></div>
								<div class="nw-bar-seg nw-bar-snow" id="nw-bar-snow" title="Snow"></div>
							</div>
						</div>

						<div class="nw-modal-footer">
							<span class="nw-save-error" id="nw-season-save-error"></span>
							<button type="button" class="nw-btn nw-btn-ghost" id="nw-season-cancel-btn">Cancel</button>
							<button type="submit" class="nw-btn nw-btn-primary" id="nw-season-save-btn">Save Season</button>
						</div>
					</form>
				</div>
			</div>

		</div>
		<?php
	}
}
