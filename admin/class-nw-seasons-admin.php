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
		wp_enqueue_style(
			'chakra-petch',
			'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
			[],
			null
		);
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
			. '?season_name=eq.' . rawurlencode( $season_name );
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
			. '?season_name=eq.' . rawurlencode( $season_name );
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

		$res = $this->supa_get( $this->table . '?season_name=eq.' . rawurlencode( $name ) . '&limit=1' );
		if ( ! $res['ok'] || empty( $res['body'][0] ) ) wp_send_json_error( 'Not found.' );
		wp_send_json_success( $res['body'][0] );
	}

	public function ajax_save() {
		check_ajax_referer( 'nw_seasons_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

		$is_edit     = ! empty( $_POST['is_edit'] );
		$orig_name   = sanitize_text_field( $_POST['orig_season_name'] ?? '' ); // original PK for edits
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
			// If name changed we delete + insert (PK is text, PATCH can't change PK)
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
		$nonce        = wp_create_nonce( 'nw_seasons_nonce' );
		$weights_json = wp_json_encode( $this->weights );
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

			<!-- ═══════════════════════════════════════════════ -->
			<!--  MODAL                                         -->
			<!-- ═══════════════════════════════════════════════ -->
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

						<!-- ── WEATHER WEIGHTS ── -->
						<div class="nw-weights-section">
							<div class="nw-weights-header">
								<span class="nw-label">Weather Weights</span>
								<span class="nw-weights-sum-badge" id="nw-weights-sum-badge">Sum: <strong id="nw-weights-sum">100</strong> / 100</span>
							</div>
							<p class="nw-weights-note">Must sum to exactly 100. Adjust sliders or inputs.</p>

							<div class="nw-weights-grid" id="nw-weights-grid">
								<?php foreach ( $this->weights as $key => $label ) :
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
								?>
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
						</div>

						<div class="nw-modal-footer">
							<span class="nw-save-error" id="nw-season-save-error"></span>
							<button type="button" class="nw-btn nw-btn-ghost" id="nw-season-cancel-btn">Cancel</button>
							<button type="submit" class="nw-btn nw-btn-primary" id="nw-season-save-btn">Save Season</button>
						</div>
					</form>
				</div>
			</div><!-- /#nw-season-modal -->

		</div><!-- /.wrap -->

		<?php $this->render_styles(); ?>
		<?php $this->render_scripts( $nonce, $weights_json ); ?>
		<?php
	}

	/* ================================================================ */
	/*  INLINE CSS                                                       */
	/* ================================================================ */

	private function render_styles() { ?>
		<style>
		:root { --nw-accent:#adff00; --nw-bg:#0d0d0d; --nw-surface:#141414; --nw-border:#2a2a2a; --nw-text:#e0e0e0; --nw-muted:#888; }
		.nw-seasons-wrap { font-family:'Chakra Petch',sans-serif; color:var(--nw-text); background:var(--nw-bg); padding:20px; min-height:80vh; }
		/* Header */
		.nw-page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; }
		.nw-page-title  { font-size:1.4rem; font-weight:700; color:var(--nw-accent); margin:0; }
		/* Inputs */
		.nw-input { background:#1a1a1a; border:1px solid var(--nw-border); color:var(--nw-text); padding:6px 10px; border-radius:4px; font-family:inherit; font-size:0.82rem; }
		.nw-input:focus { outline:none; border-color:var(--nw-accent); }
		.nw-textarea { width:100%; resize:vertical; min-height:55px; }
		/* Buttons */
		.nw-btn { padding:7px 16px; border-radius:4px; font-family:inherit; font-size:0.82rem; font-weight:600; cursor:pointer; border:none; transition:background .15s,color .15s; }
		.nw-btn-primary { background:var(--nw-accent); color:#0d0d0d; }
		.nw-btn-primary:hover { background:#c8ff1a; }
		.nw-btn-ghost { background:transparent; border:1px solid var(--nw-border); color:var(--nw-text); }
		.nw-btn-ghost:hover { border-color:var(--nw-accent); color:var(--nw-accent); }
		.nw-btn-danger { background:#c0392b; color:#fff; }
		.nw-btn-danger:hover { background:#e74c3c; }
		.nw-btn-xs { padding:3px 10px; font-size:0.75rem; }
		/* Spinner */
		.nw-spinner { width:28px; height:28px; border:3px solid var(--nw-border); border-top-color:var(--nw-accent); border-radius:50%; animation:nw-spin .7s linear infinite; }
		@keyframes nw-spin { to { transform:rotate(360deg); } }
		/* Table */
		.nw-season-table { width:100%; border-collapse:collapse; font-size:0.82rem; }
		.nw-season-table th { background:var(--nw-surface); color:var(--nw-muted); text-align:left; padding:8px 12px; border-bottom:1px solid var(--nw-border); font-weight:600; white-space:nowrap; }
		.nw-season-table td { padding:9px 12px; border-bottom:1px solid var(--nw-border); vertical-align:middle; }
		.nw-season-table tr:hover td { background:#1a1a1a; }
		.nw-tbl-actions { display:flex; gap:6px; }
		/* Mini weather bar in table */
		.nw-mini-bar { display:flex; height:8px; border-radius:4px; overflow:hidden; width:120px; }
		.nw-mini-seg { height:100%; transition:width .3s; }
		/* Modal */
		.nw-modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.72); z-index:99999; display:flex; align-items:center; justify-content:center; backdrop-filter:blur(2px); }
		.nw-season-modal-box { background:#141414; border:1px solid var(--nw-border); border-radius:8px; width:min(680px,95vw); max-height:92vh; overflow-y:auto; display:flex; flex-direction:column; }
		.nw-modal-header { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid var(--nw-border); position:sticky; top:0; background:#141414; z-index:2; }
		.nw-modal-title { margin:0; font-size:1.05rem; color:var(--nw-accent); }
		.nw-modal-close { background:none; border:none; color:var(--nw-muted); font-size:1.5rem; cursor:pointer; padding:0 4px; line-height:1; }
		.nw-modal-close:hover { color:var(--nw-text); }
		/* Form */
		#nw-season-form { padding:20px; }
		.nw-form-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:20px; }
		.nw-field { display:flex; flex-direction:column; gap:5px; }
		.nw-field-full { grid-column:1/-1; }
		.nw-label { font-size:0.78rem; color:var(--nw-muted); font-weight:600; text-transform:uppercase; letter-spacing:.05em; }
		.nw-required { color:var(--nw-accent); }
		.nw-field-hint { font-weight:400; text-transform:none; letter-spacing:0; color:#555; }
		/* Weights */
		.nw-weights-section { background:#1a1a1a; border:1px solid var(--nw-border); border-radius:6px; padding:16px; margin-bottom:20px; }
		.nw-weights-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:4px; }
		.nw-weights-note { font-size:0.75rem; color:var(--nw-muted); margin:0 0 14px; }
		.nw-weights-sum-badge { font-size:0.8rem; padding:3px 10px; border-radius:99px; background:#222; border:1px solid var(--nw-border); }
		.nw-weights-sum-badge.ok  strong { color:var(--nw-accent); }
		.nw-weights-sum-badge.bad strong { color:#ff6b6b; }
		.nw-weights-grid { display:flex; flex-direction:column; gap:8px; margin-bottom:14px; }
		.nw-weight-row { display:grid; grid-template-columns:28px 70px 1fr 64px 40px; align-items:center; gap:8px; }
		.nw-weight-icon { font-size:1.1rem; text-align:center; }
		.nw-weight-label { font-size:0.78rem; color:var(--nw-muted); }
		.nw-weight-range { -webkit-appearance:none; appearance:none; height:4px; background:var(--nw-border); border-radius:2px; cursor:pointer; outline:none; }
		.nw-weight-range::-webkit-slider-thumb { -webkit-appearance:none; width:14px; height:14px; border-radius:50%; background:var(--nw-accent); cursor:pointer; }
		.nw-weight-num { width:64px; text-align:center; padding:4px 6px; }
		.nw-weight-pct { font-size:0.75rem; color:var(--nw-muted); text-align:right; }
		/* Visual bar */
		.nw-weather-bar { display:flex; height:12px; border-radius:6px; overflow:hidden; margin-top:8px; }
		.nw-bar-seg { height:100%; transition:width .25s ease; }
		.nw-bar-sun    { background:#ffd700; }
		.nw-bar-cloudy { background:#9e9e9e; }
		.nw-bar-rain   { background:#4fc3f7; }
		.nw-bar-fog    { background:#b0bec5; }
		.nw-bar-storm  { background:#7e57c2; }
		.nw-bar-snow   { background:#e0f7fa; }
		/* Footer */
		.nw-modal-footer { display:flex; align-items:center; justify-content:flex-end; gap:10px; padding-top:16px; border-top:1px solid var(--nw-border); }
		.nw-save-error { color:#ff6b6b; font-size:0.8rem; flex:1; }
		/* Color swatch in table */
		.nw-color-dot { display:inline-block; width:12px; height:12px; border-radius:50%; vertical-align:middle; margin-right:5px; border:1px solid rgba(255,255,255,.15); }
		/* Empty */
		.nw-empty { text-align:center; padding:60px 20px; color:var(--nw-muted); }
		</style>
	<?php }

	/* ================================================================ */
	/*  INLINE JS                                                        */
	/* ================================================================ */

	private function render_scripts( $nonce, $weights_json ) {
		$ajax_url = admin_url( 'admin-ajax.php' );
		?>
		<script>
		(function($){
			const NONCE   = <?php echo wp_json_encode( $nonce ); ?>;
			const AJAX    = <?php echo wp_json_encode( $ajax_url ); ?>;
			const WEIGHTS = <?php echo $weights_json; ?>; // { weight_sun:"Sun", … }
			const W_KEYS  = Object.keys(WEIGHTS);

			/* colour map for mini table bar */
			const W_COLORS = {
				weight_sun:'#ffd700', weight_cloudy:'#9e9e9e',
				weight_rain:'#4fc3f7', weight_fog:'#b0bec5',
				weight_storm:'#7e57c2', weight_snow:'#e0f7fa'
			};

			function post(action, data){
				return $.post(AJAX, Object.assign({action, nonce:NONCE}, data));
			}
			const esc = s => $('<span>').text(s).html();

			/* ── load list ─────────────────────────────────── */
			function loadList(){
				$('#nw-season-table-wrap').html('<div class="nw-spinner" style="margin:40px auto;display:block;"></div>');
				post('nw_season_list').done(function(res){
					if(!res.success){ $('#nw-season-table-wrap').html('<p style="color:#ff6b6b;">'+esc(res.data)+'</p>'); return; }
					renderTable(res.data);
				}).fail(function(){
					$('#nw-season-table-wrap').html('<p style="color:#ff6b6b;">Request failed.</p>');
				});
			}

			function renderTable(rows){
				if(!rows.length){
					$('#nw-season-table-wrap').html('<div class="nw-empty"><p>No seasons configured yet.</p><p style="font-size:.8rem">Add one with the button above.</p></div>');
					return;
				}
				let html = '<table class="nw-season-table"><thead><tr>'
					+'<th>Name</th><th>Icon</th><th>Color</th><th>Temp ×</th><th>Sort</th><th>Weather Distribution</th><th>Actions</th>'
					+'</tr></thead><tbody>';
				rows.forEach(r => {
					const dot = r.color ? `<span class="nw-color-dot" style="background:${esc(r.color)};"></span>` : '';
					const icon = r.icon ? `<span style="font-size:1.2rem">${esc(r.icon)}</span>` : '—';

					/* mini bar */
					let miniBar = '<div class="nw-mini-bar">';
					W_KEYS.forEach(k => {
						const w = r[k] || 0;
						if(w > 0) miniBar += `<div class="nw-mini-seg" style="width:${w}%;background:${W_COLORS[k]};" title="${WEIGHTS[k]}: ${w}%"></div>`;
					});
					miniBar += '</div>';
					const sumLabel = W_KEYS.reduce((s,k)=>s+(r[k]||0),0);
					miniBar += `<span style="font-size:.7rem;color:var(--nw-muted);margin-left:6px;">${sumLabel}%</span>`;

					html += `<tr>
						<td><strong>${esc(r.season_name)}</strong></td>
						<td>${icon}</td>
						<td>${dot}${r.color ? esc(r.color) : '<span style="color:var(--nw-muted)">—</span>'}</td>
						<td>${r.temp_modifier}</td>
						<td style="color:var(--nw-muted)">${r.sort_order ?? 0}</td>
						<td><div style="display:flex;align-items:center;">${miniBar}</div></td>
						<td><div class="nw-tbl-actions">
							<button class="nw-btn nw-btn-ghost nw-btn-xs nw-edit-btn" data-name="${esc(r.season_name)}">Edit</button>
							<button class="nw-btn nw-btn-danger nw-btn-xs nw-delete-btn" data-name="${esc(r.season_name)}">Delete</button>
						</div></td>
					</tr>`;
				});
				html += '</tbody></table>';
				$('#nw-season-table-wrap').html(html);
			}

			/* ── weight live update ─────────────────────────── */
			function updateWeightUI(){
				let sum = 0;
				W_KEYS.forEach(k => {
					const val = parseInt($('#nw-'+k).val(),10)||0;
					sum += val;
					$('#nw-'+k+'-pct').text(val+'%');
					$('#nw-'+k+'-range').val(val);
					$('#nw-bar-'+k.replace('weight_','')).css('width', val+'%');
				});
				$('#nw-weights-sum').text(sum);
				const badge = $('#nw-weights-sum-badge');
				badge.toggleClass('ok', sum===100).toggleClass('bad', sum!==100);
				$('#nw-season-save-btn').prop('disabled', sum!==100);
			}

			/* sync range → number */
			$(document).on('input','.nw-weight-range',function(){
				const target = $(this).data('target');
				$('#'+target).val($(this).val());
				updateWeightUI();
			});
			$(document).on('input','.nw-weight-num',function(){
				const id = $(this).attr('id');
				$('#'+id+'-range').val($(this).val());
				updateWeightUI();
			});

			/* color picker sync */
			$(document).on('input','#nw-season-color-picker',function(){
				$('#nw-season-color').val($(this).val());
			});
			$(document).on('input','#nw-season-color',function(){
				const v = $(this).val();
				if(/^#[0-9a-fA-F]{6}$/.test(v)) $('#nw-season-color-picker').val(v);
			});

			/* ── modal helpers ──────────────────────────────── */
			function openModal(title){
				$('#nw-season-modal-title').text(title);
				$('#nw-season-save-error').text('');
				$('#nw-season-modal').show();
				$('#nw-season-name').focus();
				updateWeightUI();
			}
			function closeModal(){
				$('#nw-season-modal').hide();
				$('#nw-season-form')[0].reset();
				$('#nw-season-is-edit').val('0');
				$('#nw-season-orig-name').val('');
				updateWeightUI();
			}

			function populateForm(r){
				$('#nw-season-name').val(r.season_name);
				$('#nw-season-orig-name').val(r.season_name);
				$('#nw-season-is-edit').val('1');
				$('#nw-season-desc').val(r.description||'');
				$('#nw-season-temp').val(r.temp_modifier);
				$('#nw-season-color').val(r.color||'');
				if(r.color && /^#[0-9a-fA-F]{6}$/.test(r.color)) $('#nw-season-color-picker').val(r.color);
				$('#nw-season-icon').val(r.icon||'');
				$('#nw-season-sort').val(r.sort_order??0);
				W_KEYS.forEach(k => {
					$('#nw-'+k).val(r[k]??0);
					$('#nw-'+k+'-range').val(r[k]??0);
				});
				updateWeightUI();
			}

			function formToData(){
				const data = {
					season_name:   $('#nw-season-name').val(),
					orig_season_name: $('#nw-season-orig-name').val(),
					is_edit:       $('#nw-season-is-edit').val(),
					description:   $('#nw-season-desc').val(),
					temp_modifier: $('#nw-season-temp').val(),
					color:         $('#nw-season-color').val(),
					icon:          $('#nw-season-icon').val(),
					sort_order:    $('#nw-season-sort').val(),
				};
				W_KEYS.forEach(k => { data[k] = $('#nw-'+k).val(); });
				return data;
			}

			/* ── events ─────────────────────────────────────── */
			$(document)
				.on('click','#nw-season-add-btn',function(){
					closeModal();
					openModal('Add Season');
				})
				.on('click','#nw-season-modal-close, #nw-season-cancel-btn', closeModal)
				.on('click','#nw-season-modal',function(e){ if($(e.target).is('#nw-season-modal')) closeModal(); })
				.on('keydown',function(e){ if(e.key==='Escape') closeModal(); })

				.on('click','.nw-edit-btn',function(){
					const name = $(this).data('name');
					post('nw_season_get',{season_name:name}).done(function(res){
						if(!res.success){ alert('Could not load season.'); return; }
						populateForm(res.data);
						openModal('Edit Season');
					});
				})

				.on('click','.nw-delete-btn',function(){
					const name = $(this).data('name');
					if(!confirm('Delete season "'+name+'"? This cannot be undone.')) return;
					post('nw_season_delete',{season_name:name}).done(function(res){
						if(!res.success){ alert('Delete failed.'); return; }
						loadList();
					});
				})

				.on('submit','#nw-season-form',function(e){
					e.preventDefault();
					$('#nw-season-save-btn').prop('disabled',true).text('Saving…');
					$('#nw-season-save-error').text('');
					post('nw_season_save', formToData())
						.done(function(res){
							if(!res.success){
								$('#nw-season-save-error').text(res.data||'Save failed.');
								$('#nw-season-save-btn').prop('disabled',false).text('Save Season');
								return;
							}
							closeModal();
							loadList();
						})
						.fail(function(){
							$('#nw-season-save-error').text('Request failed.');
							$('#nw-season-save-btn').prop('disabled',false).text('Save Season');
						});
				});

			/* init */
			loadList();

		})(jQuery);
		</script>
		<?php
	}
}

new NeoWeaver_Seasons_Admin();
