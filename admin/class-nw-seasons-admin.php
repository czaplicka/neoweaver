<?php
/**
 * NeoWeaver Admin — Seasons Config (cyber_seasons_config)
 *
 * Full CRUD for weather/season configuration per season.
 * Auto-loaded by the glob() in neoweaver-wp-core.php.
 *
 * Constraints enforced both client- and server-side:
 *   - temp_modifier > 0
 *   - weight_sun + weight_cloudy + weight_rain + weight_fog + weight_storm + weight_snow = 100
 *
 * CSS: admin/css/seasons-admin.css
 * JS:  admin/js/seasons-admin.js
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Seasons_Admin {

	private $slug      = 'neoweaver';
	private $page_slug = 'nw-seasons';
	private $table     = 'cyber_seasons_config';

	/** Weather weight fields in order */
	private $weights = [
		'weight_sun'    => 'Sun',
		'weight_cloudy' => 'Cloudy',
		'weight_rain'   => 'Rain',
		'weight_fog'    => 'Fog',
		'weight_storm'  => 'Storm',
		'weight_snow'   => 'Snow',
	];

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_submenu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'   ] );
		add_action( 'wp_ajax_nw_season_list',   [ $this, 'ajax_list'   ] );
		add_action( 'wp_ajax_nw_season_get',    [ $this, 'ajax_get'    ] );
		add_action( 'wp_ajax_nw_season_save',   [ $this, 'ajax_save'   ] );
		add_action( 'wp_ajax_nw_season_delete', [ $this, 'ajax_delete' ] );
	}

	/* ================================================================ */
	/*  MENU                                                             */
	/* ================================================================ */

	public function register_submenu() {
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
	/*  ASSETS                                                           */
	/* ================================================================ */

	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, $this->page_slug ) === false ) return;

		$base = plugin_dir_url( dirname( __FILE__ ) ) . 'admin/';
		$ver  = '1.0.0';

		wp_enqueue_style(
			'chakra-petch',
			'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
			[],
			null
		);

		wp_enqueue_style(
			'nw-seasons-admin',
			$base . 'css/seasons-admin.css',
			[ 'chakra-petch' ],
			$ver
		);

		wp_enqueue_script(
			'nw-seasons-admin',
			$base . 'js/seasons-admin.js',
			[ 'jquery' ],
			$ver,
			true // load in footer
		);

		wp_localize_script( 'nw-seasons-admin', 'nwSeasonsData', [
			'nonce'   => wp_create_nonce( 'nw_seasons_nonce' ),
			'ajax'    => admin_url( 'admin-ajax.php' ),
			'weights' => $this->weights,
		] );
	}

	/* ================================================================ */
	/*  SUPABASE HELPERS (shared pattern)                               */
	/* ================================================================ */

	private function supa_url() {
		return function_exists( 'tw_supabase_url' ) ? trim( (string) tw_supabase_url() ) : '';
	}
	private function supa_key() {
		if ( function_exists( 'tw_supabase_service_key' ) && tw_supabase_service_key() ) {
			return trim( (string) tw_supabase_service_key() );
		}
		if ( function_exists( 'tw_supabase_anon_key' ) && tw_supabase_anon_key() ) {
			return trim( (string) tw_supabase_anon_key() );
		}
		return '';
	}
	private function headers() {
		return [
			'apikey'        => $this->supa_key(),
			'Authorization' => 'Bearer ' . $this->supa_key(),
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		];
	}

	private function supa_get( $path, $prefer = '' ) {
		$url  = rtrim( $this->supa_url(), '/' ) . '/rest/v1/' . ltrim( $path, '/' );
		$hdrs = $this->headers();
		if ( $prefer ) $hdrs['Prefer'] = $prefer;
		$res  = wp_remote_get( $url, [ 'timeout' => 15, 'headers' => $hdrs ] );
		if ( is_wp_error( $res ) ) {
			return [ 'ok' => false, 'body' => null, 'error' => $res->get_error_message() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		return [
			'ok'    => ( $code >= 200 && $code < 300 ),
			'status'=> $code,
			'body'  => $body,
			'error' => ( $code < 200 || $code >= 300 ) ? substr( wp_remote_retrieve_body( $res ), 0, 400 ) : null,
		];
	}

	private function supa_post( $path, $payload ) {
		$url = rtrim( $this->supa_url(), '/' ) . '/rest/v1/' . ltrim( $path, '/' );
		$res = wp_remote_post( $url, [
			'timeout' => 15,
			'headers' => array_merge( $this->headers(), [ 'Prefer' => 'return=representation' ] ),
			'body'    => wp_json_encode( $payload ),
		] );
		if ( is_wp_error( $res ) ) return [ 'ok' => false, 'error' => $res->get_error_message() ];
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		return [ 'ok' => ( $code >= 200 && $code < 300 ), 'status' => $code, 'body' => $body ];
	}

	/** PATCH by primary key (season_name is text PK) */
	private function supa_patch( $season_name, $payload ) {
		$url = rtrim( $this->supa_url(), '/' ) . '/rest/v1/' . $this->table
			. '?season_name=eq.' . rawrawurlencode( $season_name );
		$res = wp_remote_request( $url, [
			'method'  => 'PATCH',
			'timeout' => 15,
			'headers' => array_merge( $this->headers(), [ 'Prefer' => 'return=representation' ] ),
			'body'    => wp_json_encode( $payload ),
		] );
		if ( is_wp_error( $res ) ) return [ 'ok' => false, 'error' => $res->get_error_message() ];
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		return [ 'ok' => ( $code >= 200 && $code < 300 ), 'status' => $code, 'body' => $body ];
	}

	private function supa_delete( $season_name ) {
		$url = rtrim( $this->supa_url(), '/' ) . '/rest/v1/' . $this->table
			. '?season_name=eq.' . rawrawurlencode( $season_name );
		$res = wp_remote_request( $url, [
			'method'  => 'DELETE',
			'timeout' => 15,
			'headers' => $this->headers(),
		] );
		if ( is_wp_error( $res ) ) return [ 'ok' => false, 'error' => $res->get_error_message() ];
		$code = (int) wp_remote_retrieve_response_code( $res );
		return [ 'ok' => ( $code >= 200 && $code < 300 ), 'status' => $code ];
	}

	/* ================================================================ */
	/*  SERVER-SIDE VALIDATION                                          */
	/* ================================================================ */

	/** Returns error string or empty string if valid */
	private function validate_weights( $data ) {
		$sum = 0;
		foreach ( array_keys( $this->weights ) as $key ) {
			$sum += isset( $data[ $key ] ) ? (int) $data[ $key ] : 0;
		}
		if ( $sum !== 100 ) {
			return 'Weather weights must sum to exactly 100 (current sum: ' . $sum . ').';
		}
		return '';
	}

	/* ================================================================ */
	/*  AJAX                                                             */
	/* ================================================================ */

	public function ajax_list() {
		check_ajax_referer( 'nw_seasons_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

		$res = $this->supa_get( $this->table . '?select=*&order=sort_order.asc,season_name.asc' );
		if ( ! $res['ok'] ) wp_send_json_error( 'Supabase error: ' . $res['error'] );
		wp_send_json_success( is_array( $res['body'] ) ? $res['body'] : [] );
	}

	public function ajax_get() {
		check_ajax_referer( 'nw_seasons_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

		$name = sanitize_text_field( $_POST['season_name'] ?? '' );
		if ( ! $name ) wp_send_json_error( 'Invalid name.' );

		$res = $this->supa_get( $this->table . '?season_name=eq.' . rawrawurlencode( $name ) . '&limit=1' );
		if ( ! $res['ok'] || empty( $res['body'][0] ) ) wp_send_json_error( 'Not found.' );
		wp_send_json_success( $res['body'][0] );
	}

	public function ajax_save() {
		check_ajax_referer( 'nw_seasons_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

		$is_edit     = ! empty( $_POST['is_edit'] );
		$orig_name   = sanitize_text_field( $_POST['orig_season_name'] ?? '' );
		$season_name = sanitize_text_field( $_POST['season_name'] ?? '' );

		if ( ! $season_name ) wp_send_json_error( 'Season name is required.' );

		$weights = [];
		foreach ( array_keys( $this->weights ) as $key ) {
			$weights[ $key ] = max( 0, (int) ( $_POST[ $key ] ?? 0 ) );
		}

		$weight_err = $this->validate_weights( $weights );
		if ( $weight_err ) wp_send_json_error( $weight_err );

		$temp_mod = (float) ( $_POST['temp_modifier'] ?? 1.0 );
		if ( $temp_mod <= 0 ) wp_send_json_error( 'temp_modifier must be > 0.' );

		$payload = array_merge( [
			'season_name'    => $season_name,
			'description'    => sanitize_textarea_field( $_POST['description'] ?? '' ) ?: null,
			'temp_modifier'  => $temp_mod,
			'color'          => sanitize_text_field( $_POST['color']      ?? '' ) ?: null,
			'icon'           => sanitize_text_field( $_POST['icon']       ?? '' ) ?: null,
			'sort_order'     => strlen( $_POST['sort_order'] ?? '' ) ? (int) $_POST['sort_order'] : 0,
		], $weights );

		if ( $is_edit && $orig_name ) {
			if ( $orig_name !== $season_name ) {
				$del = $this->supa_delete( $orig_name );
				if ( ! $del['ok'] ) wp_send_json_error( 'Could not rename: delete old record failed (HTTP ' . $del['status'] . ').' );
				$res = $this->supa_post( $this->table, $payload );
			} else {
				$res = $this->supa_patch( $orig_name, $payload );
			}
		} else {
			$res = $this->supa_post( $this->table, $payload );
		}

		if ( ! $res['ok'] ) {
			wp_send_json_error( 'Save failed (HTTP ' . $res['status'] . ').' );
		}
		wp_send_json_success( [ 'season_name' => $season_name ] );
	}

	public function ajax_delete() {
		check_ajax_referer( 'nw_seasons_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

		$name = sanitize_text_field( $_POST['season_name'] ?? '' );
		if ( ! $name ) wp_send_json_error( 'Invalid name.' );

		$res = $this->supa_delete( $name );
		if ( ! $res['ok'] ) wp_send_json_error( 'Delete failed (HTTP ' . $res['status'] . ').' );
		wp_send_json_success();
	}

	/* ================================================================ */
	/*  RENDER                                                           */
	/* ================================================================ */

	public function render_page() {
		?>
		<div class="wrap nw-seasons-wrap">

			<!-- HEADER -->
			<div class="nw-page-header">
				<h1 class="nw-page-title">
					<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" viewBox="0 0 24 24"
						stroke="currentColor" stroke-width="2" style="vertical-align:-4px;margin-right:6px;">
						<path stroke-linecap="round" stroke-linejoin="round"
							d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.36-6.36-.71.71M6.34 17.66l-.71.71M17.66 17.66l.71.71M6.34 6.34l-.71-.71M12 7a5 5 0 1 0 0 10A5 5 0 0 0 12 7z"/>
					</svg>
					Seasons Config
				</h1>
				<button class="nw-btn nw-btn-primary" id="nw-season-add-btn">+ Add Season</button>
			</div>

			<!-- TABLE -->
			<div id="nw-season-table-wrap">
				<div class="nw-spinner" style="margin:40px auto;display:block;"></div>
			</div>

			<!-- MODAL -->
			<div id="nw-season-modal" class="nw-modal-overlay" style="display:none;"
				role="dialog" aria-modal="true" aria-labelledby="nw-season-modal-title">
				<div class="nw-modal-box nw-season-modal-box">
					<div class="nw-modal-header">
						<h2 class="nw-modal-title" id="nw-season-modal-title">Add Season</h2>
						<button class="nw-modal-close" id="nw-season-modal-close" aria-label="Close">&times;</button>
					</div>

					<form id="nw-season-form" autocomplete="off">
						<input type="hidden" name="is_edit"          id="nw-season-is-edit" value="0">
						<input type="hidden" name="orig_season_name" id="nw-season-orig-name">

						<div class="nw-form-grid-2">

							<!-- Season Name (PK) -->
							<div class="nw-field">
								<label class="nw-label" for="nw-season-name">Season Name <span class="nw-required">*</span>
									<span class="nw-field-hint">(primary key)</span></label>
								<input class="nw-input" type="text" id="nw-season-name" name="season_name"
									required placeholder="e.g. Spring, Winter, Monsoon">
							</div>

							<!-- Sort Order -->
							<div class="nw-field">
								<label class="nw-label" for="nw-season-sort">Sort Order</label>
								<input class="nw-input" type="number" id="nw-season-sort" name="sort_order" value="0">
							</div>

							<!-- Description -->
							<div class="nw-field nw-field-full">
								<label class="nw-label" for="nw-season-desc">Description</label>
								<textarea class="nw-input nw-textarea" id="nw-season-desc" name="description" rows="2"></textarea>
							</div>

							<!-- Temp Modifier -->
							<div class="nw-field">
								<label class="nw-label" for="nw-season-temp">Temp Modifier <span class="nw-field-hint">(> 0)</span></label>
								<input class="nw-input" type="number" id="nw-season-temp" name="temp_modifier"
									step="0.01" min="0.01" value="1.00">
							</div>

							<!-- Color -->
							<div class="nw-field">
								<label class="nw-label" for="nw-season-color">Color <span class="nw-field-hint">(hex / name)</span></label>
								<div style="display:flex;gap:8px;align-items:center;">
									<input class="nw-input" type="text" id="nw-season-color" name="color"
										placeholder="#adff00" style="flex:1;">
									<input type="color" id="nw-season-color-picker" value="#adff00"
										style="width:36px;height:34px;padding:2px;border:1px solid #2a2a2a;border-radius:4px;background:#1a1a1a;cursor:pointer;">
								</div>
							</div>

							<!-- Icon -->
							<div class="nw-field">
								<label class="nw-label" for="nw-season-icon">Icon <span class="nw-field-hint">(emoji or slug)</span></label>
								<input class="nw-input" type="text" id="nw-season-icon" name="icon" placeholder="☀️">
							</div>

						</div><!-- /.nw-form-grid-2 -->

						<!-- WEATHER WEIGHTS -->
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
								foreach ( $this->weights as $key => $label ) : ?>
								<div class="nw-weight-row">
									<span class="nw-weight-icon"><?php echo $icon_map[ $key ]; ?></span>
									<label class="nw-weight-label" for="nw-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
									<input type="range" class="nw-weight-range" id="nw-<?php echo esc_attr($key); ?>-range"
										data-target="nw-<?php echo esc_attr($key); ?>"
										min="0" max="100" value="<?php echo $default_map[$key]; ?>">
									<input class="nw-input nw-weight-num" type="number"
										id="nw-<?php echo esc_attr($key); ?>"
										name="<?php echo esc_attr($key); ?>"
										min="0" max="100"
										value="<?php echo $default_map[$key]; ?>">
									<span class="nw-weight-pct" id="nw-<?php echo esc_attr($key); ?>-pct"><?php echo $default_map[$key]; ?>%</span>
								</div>
								<?php endforeach; ?>
							</div>

							<!-- Visual bar -->
							<div class="nw-weather-bar" id="nw-weather-bar" title="Weather distribution">
								<div class="nw-bar-seg nw-bar-sun"    id="nw-bar-sun"    title="Sun"></div>
								<div class="nw-bar-seg nw-bar-cloudy" id="nw-bar-cloudy" title="Cloudy"></div>
								<div class="nw-bar-seg nw-bar-rain"   id="nw-bar-rain"   title="Rain"></div>
								<div class="nw-bar-seg nw-bar-fog"    id="nw-bar-fog"    title="Fog"></div>
								<div class="nw-bar-seg nw-bar-storm"  id="nw-bar-storm"  title="Storm"></div>
								<div class="nw-bar-seg nw-bar-snow"   id="nw-bar-snow"   title="Snow"></div>
							</div>
						</div><!-- /.nw-weights-section -->

						<div class="nw-modal-footer">
							<span class="nw-save-error" id="nw-season-save-error"></span>
							<button type="button" class="nw-btn nw-btn-ghost" id="nw-season-cancel-btn">Cancel</button>
							<button type="submit" class="nw-btn nw-btn-primary" id="nw-season-save-btn">Save Season</button>
						</div>
					</form>
				</div>
			</div><!-- /#nw-season-modal -->

		</div><!-- /.wrap -->
		<?php
	}
}
