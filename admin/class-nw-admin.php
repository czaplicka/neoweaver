<?php
/**
 * NeoWeaver Admin — Main Menu & Dashboard
 *
 * Loaded FIRST (explicitly, before glob) so the top-level "neoweaver"
 * menu slug exists when all submenu files run add_submenu_page().
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Admin {

	private $slug = 'neoweaver';

	public function __construct() {
		add_action( 'admin_menu',            array( $this, 'register_menu'        ) );
		add_action( 'admin_menu',            array( $this, 'rename_first_submenu' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets'       ) );
		add_action( 'wp_ajax_nw_dashboard_data', array( $this, 'ajax_dashboard_data' ) );
	}

	/* ------------------------------------------------------------------ */
	/*  MENU                                                               */
	/* ------------------------------------------------------------------ */

	public function register_menu() {
		add_menu_page(
			'NeoWeaver',
			'NeoWeaver',
			'manage_options',
			$this->slug,
			array( $this, 'render_page' ),
			'data:image/svg+xml;base64,' . base64_encode( $this->logo_svg() ),
			30
		);
	}

	public function rename_first_submenu() {
		global $submenu;
		if ( isset( $submenu[ $this->slug ][0][0] ) ) {
			$submenu[ $this->slug ][0][0] = 'Dashboard';
		}
	}

	/* ------------------------------------------------------------------ */
	/*  ASSETS                                                             */
	/* ------------------------------------------------------------------ */

	public function enqueue_assets( $hook ) {
		$is_dashboard = ( $hook === 'toplevel_page_' . $this->slug );
		$is_any_nw    = $is_dashboard || ( strpos( $hook, 'neoweaver' ) !== false );
		if ( ! $is_any_nw ) return;

		wp_enqueue_style(
			'chakra-petch',
			'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
			array(),
			null
		);

		if ( $is_dashboard ) {
			wp_enqueue_script( 'jquery' );
			wp_add_inline_style( 'chakra-petch', $this->get_css() );
			wp_add_inline_script( 'jquery', $this->get_js() );
		}
	}

	/* ------------------------------------------------------------------ */
	/*  HELPERS                                                            */
	/* ------------------------------------------------------------------ */

	private function get_supa_url() {
		return function_exists( 'tw_supabase_url' ) ? trim( (string) tw_supabase_url() ) : '';
	}

	private function get_supa_key() {
		if ( function_exists( 'tw_supabase_service_key' ) && tw_supabase_service_key() ) {
			return trim( (string) tw_supabase_service_key() );
		}
		if ( function_exists( 'tw_supabase_anon_key' ) && tw_supabase_anon_key() ) {
			return trim( (string) tw_supabase_anon_key() );
		}
		return '';
	}

	private function get_supa_key_type() {
		if ( function_exists( 'tw_supabase_service_key' ) && tw_supabase_service_key() ) {
			return 'service_role';
		}
		if ( function_exists( 'tw_supabase_anon_key' ) && tw_supabase_anon_key() ) {
			return 'anon';
		}
		return 'none';
	}

	private function supa_get( $path ) {
		$supa_url = $this->get_supa_url();
		$supa_key = $this->get_supa_key();

		if ( ! $supa_url || ! $supa_key ) {
			return array( 'ok' => false, 'status' => 0, 'body' => null, 'error' => 'Supabase not configured.' );
		}

		$res = wp_remote_get(
			rtrim( $supa_url, '/' ) . '/rest/v1/' . ltrim( $path, '/' ),
			array(
				'timeout' => 12,
				'headers' => array(
					'apikey'        => $supa_key,
					'Authorization' => 'Bearer ' . $supa_key,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'status' => 0, 'body' => null, 'error' => $res->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $res );
		$data = json_decode( $body, true );
		$code = (int) wp_remote_retrieve_response_code( $res );

		return array(
			'ok'     => ( $code >= 200 && $code < 300 ),
			'status' => $code,
			'body'   => $data,
			'error'  => null,
			'raw'    => ( $code < 200 || $code >= 300 ) ? substr( $body, 0, 300 ) : null,
		);
	}

	private function supa_count( $table ) {
		$supa_url = $this->get_supa_url();
		$supa_key = $this->get_supa_key();

		if ( ! $supa_url || ! $supa_key ) return 0;

		$res = wp_remote_get(
			rtrim( $supa_url, '/' ) . '/rest/v1/' . $table . '?select=id',
			array(
				'timeout' => 10,
				'headers' => array(
					'apikey'        => $supa_key,
					'Authorization' => 'Bearer ' . $supa_key,
					'Range'         => '0-0',
					'Prefer'        => 'count=exact',
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $res ) ) return 0;

		$cr = wp_remote_retrieve_header( $res, 'content-range' );
		if ( $cr && preg_match( '/\/(\d+)$/', $cr, $m ) ) {
			return (int) $m[1];
		}

		return 0;
	}

	private function supa_recent_count( $table, $days = 7 ) {
		$supa_url = $this->get_supa_url();
		$supa_key = $this->get_supa_key();

		if ( ! $supa_url || ! $supa_key ) return 0;

		$since = gmdate( 'Y-m-d\TH:i:s\Z', time() - ( $days * DAY_IN_SECONDS ) );

		$res = wp_remote_get(
			rtrim( $supa_url, '/' ) . '/rest/v1/' . $table . '?select=id&created_at=gte.' . rawurlencode( $since ),
			array(
				'timeout' => 10,
				'headers' => array(
					'apikey'        => $supa_key,
					'Authorization' => 'Bearer ' . $supa_key,
					'Range'         => '0-0',
					'Prefer'        => 'count=exact',
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $res ) ) return 0;

		$cr = wp_remote_retrieve_header( $res, 'content-range' );
		if ( $cr && preg_match( '/\/(\d+)$/', $cr, $m ) ) {
			return (int) $m[1];
		}

		return 0;
	}

	private function supa_growth_series( $table, $days = 30 ) {
		$since = gmdate( 'Y-m-d\TH:i:s\Z', time() - ( ( $days - 1 ) * DAY_IN_SECONDS ) );

		$path = $table
			. '?select=created_at'
			. '&created_at=gte.' . rawurlencode( $since )
			. '&order=created_at.asc'
			. '&limit=5000';

		$res  = $this->supa_get( $path );
		$rows = ( $res['ok'] && is_array( $res['body'] ) ) ? $res['body'] : array();

		$series = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$d            = gmdate( 'Y-m-d', time() - ( $i * DAY_IN_SECONDS ) );
			$series[ $d ] = 0;
		}

		foreach ( $rows as $row ) {
			if ( empty( $row['created_at'] ) ) continue;
			$key = gmdate( 'Y-m-d', strtotime( $row['created_at'] ) );
			if ( isset( $series[ $key ] ) ) {
				$series[ $key ]++;
			}
		}

		$out = array();
		foreach ( $series as $date => $count ) {
			$out[] = array( 'date' => $date, 'value' => $count );
		}

		return array(
			'series'     => $out,
			'rows_found' => count( $rows ),
			'query_ok'   => $res['ok'],
			'http_status'=> $res['status'],
			'api_error'  => $res['raw'] ?? null,
		);
	}

	private function supa_recent_logs( $limit = 10 ) {
		$path = 'cyber_debug_logs?select=id,created_at,level,message,context,data&order=created_at.desc&limit=' . (int) $limit;
		$res  = $this->supa_get( $path );
		return ( $res['ok'] && is_array( $res['body'] ) ) ? $res['body'] : array();
	}

	private function supa_campaigns_without_character() {
		$path = 'cyber_campaign?select=id,cyber_campaigncharacters!left(character_id)&limit=5000';
		$res  = $this->supa_get( $path );
		$rows = ( $res['ok'] && is_array( $res['body'] ) ) ? $res['body'] : array();

		$count = 0;
		foreach ( $rows as $row ) {
			if ( empty( $row['cyber_campaigncharacters'] ) ) {
				$count++;
			}
		}
		return $count;
	}

	private function supa_worlds_without_campaigns() {
		$path = 'cyber_worlds?select=id,cyber_campaign!left(id)&limit=5000';
		$res  = $this->supa_get( $path );
		$rows = ( $res['ok'] && is_array( $res['body'] ) ) ? $res['body'] : array();

		$count = 0;
		foreach ( $rows as $row ) {
			if ( empty( $row['cyber_campaign'] ) ) {
				$count++;
			}
		}
		return $count;
	}

	/* ------------------------------------------------------------------ */
	/*  AJAX: DASHBOARD                                                    */
	/* ------------------------------------------------------------------ */

	public function ajax_dashboard_data() {
		check_ajax_referer( 'neoweaver_dashboard', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		if ( ! $this->get_supa_url() || ! $this->get_supa_key() ) {
			wp_send_json_error( 'Supabase not configured.' );
		}

		$counts = array(
			'characters' => $this->supa_count( 'cyber_characters' ),
			'worlds'     => $this->supa_count( 'cyber_worlds' ),
			'campaigns'  => $this->supa_count( 'cyber_campaign' ),
		);

		$recent = array(
			'characters_7d' => $this->supa_recent_count( 'cyber_characters', 7 ),
			'worlds_7d'     => $this->supa_recent_count( 'cyber_worlds', 7 ),
			'campaigns_7d'  => $this->supa_recent_count( 'cyber_campaign', 7 ),
		);

		$growth_raw = array(
			'characters' => $this->supa_growth_series( 'cyber_characters', 30 ),
			'worlds'     => $this->supa_growth_series( 'cyber_worlds', 30 ),
			'campaigns'  => $this->supa_growth_series( 'cyber_campaign', 30 ),
		);

		// Flatten series for JS (keep debug info separately)
		$growth = array(
			'characters' => $growth_raw['characters']['series'],
			'worlds'     => $growth_raw['worlds']['series'],
			'campaigns'  => $growth_raw['campaigns']['series'],
		);

		$health = array(
			'worlds_without_campaigns'    => $this->supa_worlds_without_campaigns(),
			'campaigns_without_character' => $this->supa_campaigns_without_character(),
		);

		$alerts = array();

		if ( $recent['characters_7d'] === 0 ) {
			$alerts[] = array( 'level' => 'warn', 'label' => 'Characters', 'text' => 'No new characters in the last 7 days.' );
		}
		if ( $recent['worlds_7d'] === 0 ) {
			$alerts[] = array( 'level' => 'warn', 'label' => 'Worlds', 'text' => 'No new worlds in the last 7 days.' );
		}
		if ( $recent['campaigns_7d'] === 0 ) {
			$alerts[] = array( 'level' => 'warn', 'label' => 'Campaigns', 'text' => 'No new campaigns in the last 7 days.' );
		}
		if ( $health['worlds_without_campaigns'] > 0 ) {
			$alerts[] = array(
				'level' => 'info',
				'label' => 'World Coverage',
				'text'  => $health['worlds_without_campaigns'] . ' world(s) have no campaign yet.',
			);
		}
		if ( $health['campaigns_without_character'] > 0 ) {
			$alerts[] = array(
				'level' => 'warn',
				'label' => 'Campaign Setup',
				'text'  => $health['campaigns_without_character'] . ' campaign(s) have no assigned character.',
			);
		}

		wp_send_json_success( array(
			'counts'    => $counts,
			'recent'    => $recent,
			'growth'    => $growth,
			'health'    => $health,
			'alerts'    => $alerts,
			'logs'      => $this->supa_recent_logs( 10 ),
			'_debug'    => array(
				'key_type'   => $this->get_supa_key_type(),
				'growth_meta'=> array(
					'characters' => array(
						'rows_found'  => $growth_raw['characters']['rows_found'],
						'query_ok'    => $growth_raw['characters']['query_ok'],
						'http_status' => $growth_raw['characters']['http_status'],
						'api_error'   => $growth_raw['characters']['api_error'],
					),
					'worlds' => array(
						'rows_found'  => $growth_raw['worlds']['rows_found'],
						'query_ok'    => $growth_raw['worlds']['query_ok'],
						'http_status' => $growth_raw['worlds']['http_status'],
						'api_error'   => $growth_raw['worlds']['api_error'],
					),
					'campaigns' => array(
						'rows_found'  => $growth_raw['campaigns']['rows_found'],
						'query_ok'    => $growth_raw['campaigns']['query_ok'],
						'http_status' => $growth_raw['campaigns']['http_status'],
						'api_error'   => $growth_raw['campaigns']['api_error'],
					),
				),
			),
		) );
	}

	/* ------------------------------------------------------------------ */
	/*  RENDER                                                             */
	/* ------------------------------------------------------------------ */

	public function render_page() {
		$supa_url = $this->get_supa_url();
		$key_ok   = (bool) $this->get_supa_key();
		?>
		<div class="wrap nw-dash" id="nw-dashboard">

			<div class="nw-dash-header">
				<div class="nw-dash-logo">
					<?php echo $this->logo_svg( 44, '#adff00' ); ?>
					<div>
						<span class="nw-logo-name"><span class="nw-accent">Neo</span>Weaver</span>
						<span class="nw-logo-version">v<?php echo esc_html( NEOWEAVER_VERSION ); ?> &mdash; Game Ops Dashboard</span>
					</div>
				</div>
				<button class="nw-btn nw-btn-ghost" id="nw-refresh-dashboard">&#8635; Refresh</button>
			</div>

			<div class="nw-grid-main">

				<section class="nw-block">
					<div class="nw-block-head">
						<h2 class="nw-section-title">Overview</h2>
						<span class="nw-section-kicker">product activity</span>
					</div>
					<div class="nw-stat-grid">
						<div class="nw-stat-card">
							<div class="nw-stat-label-top">Characters</div>
							<div class="nw-stat-value" id="nw-stat-characters"><div class="nw-spinner"></div></div>
							<div class="nw-stat-sub" id="nw-recent-characters">Last 7d: &mdash;</div>
						</div>
						<div class="nw-stat-card">
							<div class="nw-stat-label-top">Worlds</div>
							<div class="nw-stat-value" id="nw-stat-worlds"><div class="nw-spinner"></div></div>
							<div class="nw-stat-sub" id="nw-recent-worlds">Last 7d: &mdash;</div>
						</div>
						<div class="nw-stat-card">
							<div class="nw-stat-label-top">Campaigns</div>
							<div class="nw-stat-value" id="nw-stat-campaigns"><div class="nw-spinner"></div></div>
							<div class="nw-stat-sub" id="nw-recent-campaigns">Last 7d: &mdash;</div>
						</div>
					</div>
				</section>

				<section class="nw-block">
					<div class="nw-block-head">
						<h2 class="nw-section-title">Growth</h2>
						<span class="nw-section-kicker">last 30 days</span>
					</div>
					<div class="nw-chart-grid">
						<div class="nw-chart-card">
							<div class="nw-chart-title">Characters</div>
							<div class="nw-chart" id="nw-chart-characters"></div>
						</div>
						<div class="nw-chart-card">
							<div class="nw-chart-title">Worlds</div>
							<div class="nw-chart" id="nw-chart-worlds"></div>
						</div>
						<div class="nw-chart-card">
							<div class="nw-chart-title">Campaigns</div>
							<div class="nw-chart" id="nw-chart-campaigns"></div>
						</div>
					</div>
				</section>

				<div class="nw-grid-2">
					<section class="nw-block">
						<div class="nw-block-head">
							<h2 class="nw-section-title">Needs Attention</h2>
							<span class="nw-section-kicker">exceptions first</span>
						</div>
						<div id="nw-alerts" class="nw-alerts-list">
							<div class="nw-alert-card nw-alert-card-loading">
								<div class="nw-spinner"></div>
								<span>Checking system state&hellip;</span>
							</div>
						</div>
						<div class="nw-health-grid">
							<div class="nw-health-card">
								<div class="nw-health-label">Worlds without campaigns</div>
								<div class="nw-health-value" id="nw-health-worlds-without-campaigns">&mdash;</div>
							</div>
							<div class="nw-health-card">
								<div class="nw-health-label">Campaigns without character</div>
								<div class="nw-health-value" id="nw-health-campaigns-without-character">&mdash;</div>
							</div>
						</div>
					</section>

					<section class="nw-block">
						<div class="nw-block-head">
							<h2 class="nw-section-title">Recent System Events</h2>
							<span class="nw-section-kicker">cyber_debug_logs</span>
						</div>
						<div id="nw-logs" class="nw-logs-list">
							<div class="nw-empty-state">Loading recent events&hellip;</div>
						</div>
					</section>
				</div>

				<section class="nw-block">
					<div class="nw-block-head">
						<h2 class="nw-section-title">System</h2>
						<span class="nw-section-kicker">environment</span>
					</div>
					<div class="nw-sysinfo">
						<div class="nw-sysinfo-row">
							<span class="nw-sysinfo-label">Plugin version</span>
							<span class="nw-sysinfo-val"><?php echo esc_html( NEOWEAVER_VERSION ); ?></span>
						</div>
						<div class="nw-sysinfo-row">
							<span class="nw-sysinfo-label">Supabase URL</span>
							<span class="nw-sysinfo-val"><?php echo $supa_url ? esc_html( $supa_url ) : '<span class="nw-text-danger">Not configured</span>'; ?></span>
						</div>
						<div class="nw-sysinfo-row">
							<span class="nw-sysinfo-label">Supabase Key</span>
							<span class="nw-sysinfo-val"><?php echo $key_ok ? '<span class="nw-text-good">Configured (' . esc_html( $this->get_supa_key_type() ) . ')</span>' : '<span class="nw-text-danger">Missing</span>'; ?></span>
						</div>
						<div class="nw-sysinfo-row">
							<span class="nw-sysinfo-label">PHP</span>
							<span class="nw-sysinfo-val"><?php echo esc_html( PHP_VERSION ); ?></span>
						</div>
						<div class="nw-sysinfo-row">
							<span class="nw-sysinfo-label">WordPress</span>
							<span class="nw-sysinfo-val"><?php echo esc_html( get_bloginfo( 'version' ) ); ?></span>
						</div>
					</div>
				</section>

			</div>

			<input type="hidden" id="nw-dash-nonce" value="<?php echo esc_attr( wp_create_nonce( 'neoweaver_dashboard' ) ); ?>">
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/*  SVG LOGO                                                           */
	/* ------------------------------------------------------------------ */

	private function logo_svg( $size = 20, $color = '#ffffff' ) {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 40 40" fill="none" aria-label="NeoWeaver">'
			. '<polygon points="20,2 36,11 36,29 20,38 4,29 4,11" stroke="' . esc_attr( $color ) . '" stroke-width="2.5" fill="none"/>'
			. '<polyline points="11,27 11,13 20,24 29,13 29,27" stroke="' . esc_attr( $color ) . '" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" fill="none"/>'
			. '</svg>';
	}

	/* ------------------------------------------------------------------ */
	/*  CSS                                                                */
	/* ------------------------------------------------------------------ */

	private function get_css() {
		return '
.nw-dash{font-family:\'Chakra Petch\',monospace;color:#e0e0e0;max-width:1280px}
.nw-dash *{box-sizing:border-box}
.nw-dash-header{display:flex;align-items:center;justify-content:space-between;padding:20px 0;border-bottom:1px solid #2a2a2a;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.nw-dash-logo{display:flex;align-items:center;gap:14px}
.nw-logo-name{display:block;font-size:26px;font-weight:700;color:#fff;line-height:1}
.nw-accent{color:#adff00}
.nw-logo-version{display:block;font-size:11px;color:#555;margin-top:4px;letter-spacing:.5px}
.nw-btn{font-family:\'Chakra Petch\',monospace;font-size:12px;font-weight:600;padding:7px 16px;border-radius:5px;border:1px solid transparent;cursor:pointer;transition:all .15s;text-transform:uppercase;letter-spacing:.5px}
.nw-btn-ghost{background:transparent;color:#adff00;border-color:#2e2e2e}
.nw-btn-ghost:hover{border-color:#adff00;background:#141414}
.nw-grid-main{display:flex;flex-direction:column;gap:18px}
.nw-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px}
@media(max-width:980px){.nw-grid-2{grid-template-columns:1fr}}
.nw-block{background:#101010;border:1px solid #1f1f1f;border-radius:14px;padding:18px 18px 16px;box-shadow:0 8px 24px rgba(0,0,0,.18)}
.nw-block-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.nw-section-title{font-size:13px;text-transform:uppercase;letter-spacing:1px;color:#adff00;font-weight:700;margin:0}
.nw-section-kicker{font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:#555}
.nw-stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
@media(max-width:800px){.nw-stat-grid{grid-template-columns:1fr}}
.nw-stat-card{background:#141414;border:1px solid #242424;border-radius:12px;padding:16px;transition:border-color .2s,transform .2s}
.nw-stat-card:hover{border-color:#adff00;transform:translateY(-1px)}
.nw-stat-label-top{font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#666}
.nw-stat-value{font-size:36px;font-weight:700;color:#adff00;font-variant-numeric:tabular-nums;min-height:44px;display:flex;align-items:center;margin-top:8px}
.nw-stat-sub{font-size:11px;color:#8a8a8a}
.nw-chart-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
@media(max-width:980px){.nw-chart-grid{grid-template-columns:1fr}}
.nw-chart-card{background:#141414;border:1px solid #232323;border-radius:12px;padding:14px}
.nw-chart-title{font-size:12px;color:#fff;text-transform:uppercase;letter-spacing:.8px;margin-bottom:10px}
.nw-chart{height:120px}
.nw-chart svg{width:100%;height:120px;display:block}
.nw-chart-empty{height:120px;display:flex;align-items:center;justify-content:center;color:#666;font-size:12px;border:1px dashed #2a2a2a;border-radius:10px}
.nw-alerts-list{display:flex;flex-direction:column;gap:10px;margin-bottom:14px}
.nw-alert-card{display:flex;align-items:flex-start;gap:12px;padding:12px 14px;border-radius:10px;border:1px solid #262626;background:#151515}
.nw-alert-card-loading{align-items:center}
.nw-alert-dot{width:10px;height:10px;border-radius:999px;flex-shrink:0;margin-top:5px}
.nw-alert-warn .nw-alert-dot{background:#ffb703}
.nw-alert-info .nw-alert-dot{background:#00d4ff}
.nw-alert-ok .nw-alert-dot{background:#adff00}
.nw-alert-body{display:flex;flex-direction:column;gap:3px}
.nw-alert-label{font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:#777}
.nw-alert-text{font-size:13px;color:#ddd;line-height:1.45}
.nw-health-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
@media(max-width:560px){.nw-health-grid{grid-template-columns:1fr}}
.nw-health-card{background:#141414;border:1px solid #232323;border-radius:10px;padding:14px}
.nw-health-label{font-size:11px;text-transform:uppercase;letter-spacing:.7px;color:#666}
.nw-health-value{font-size:28px;color:#fff;font-weight:700;margin-top:8px}
.nw-logs-list{display:flex;flex-direction:column;gap:10px}
.nw-log-item{background:#141414;border:1px solid #232323;border-radius:10px;padding:12px 14px}
.nw-log-top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:6px;flex-wrap:wrap}
.nw-log-level{font-size:10px;text-transform:uppercase;letter-spacing:.8px;padding:4px 8px;border-radius:999px;border:1px solid #333;color:#ccc}
.nw-log-level-info{color:#00d4ff;border-color:#004e60;background:#07191d}
.nw-log-level-warn{color:#ffb703;border-color:#5c4300;background:#211800}
.nw-log-level-error{color:#ff5c5c;border-color:#5b1f1f;background:#1f1010}
.nw-log-date{font-size:11px;color:#777}
.nw-log-message{font-size:13px;color:#fff;line-height:1.4}
.nw-log-meta{margin-top:6px;font-size:11px;color:#777;word-break:break-word}
.nw-empty-state{background:#141414;border:1px dashed #2a2a2a;border-radius:10px;padding:18px;color:#777;font-size:12px;text-align:center}
.nw-spinner{display:inline-block;width:20px;height:20px;border:2px solid #333;border-top-color:#adff00;border-radius:50%;animation:nw-spin .6s linear infinite}
@keyframes nw-spin{to{transform:rotate(360deg)}}
.nw-sysinfo{background:#141414;border:1px solid #232323;border-radius:12px;overflow:hidden;font-size:12px}
.nw-sysinfo-row{display:flex;align-items:center;padding:10px 14px;border-bottom:1px solid #1a1a1a;gap:10px}
.nw-sysinfo-row:last-child{border-bottom:none}
.nw-sysinfo-label{width:130px;flex-shrink:0;color:#666;text-transform:uppercase;letter-spacing:.5px;font-size:10px}
.nw-sysinfo-val{color:#aaa;font-family:monospace;font-size:12px;word-break:break-word}
.nw-text-good{color:#adff00}
.nw-text-danger{color:#ff4444}
';
	}

	/* ------------------------------------------------------------------ */
	/*  JS                                                                 */
	/* ------------------------------------------------------------------ */

	private function get_js() {
		return '
jQuery(function($){

function escapeHtml(str){
	return String(str||"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/\'/g,"&#039;");
}

function drawSparkline(el,series,color){
	var $el=$(el);
	if(!series||!series.length){$el.html(\'<div class="nw-chart-empty">No data yet</div>\');return;}
	var values=series.map(function(p){return parseInt(p.value||0,10);});
	var max=Math.max.apply(null,values);
	if(max<=0){$el.html(\'<div class="nw-chart-empty">No growth yet</div>\');return;}
	var W=320,H=120,pad=10;
	var stepX=(W-pad*2)/Math.max(values.length-1,1);
	var pts=values.map(function(v,i){return[pad+i*stepX,H-pad-(v/max)*(H-pad*2)];});
	var line=pts.map(function(p){return p[0].toFixed(2)+","+p[1].toFixed(2);}).join(" ");
	var bars=values.map(function(v,i){
		var x=pad+i*stepX-3,h=max?(v/max)*(H-pad*2):0,y=H-pad-h;
		return\'<rect x="\'+x.toFixed(2)+\'" y="\'+y.toFixed(2)+\'" width="6" height="\'+h.toFixed(2)+\'" rx="2" fill="\'+color+\'" opacity="0.22"></rect>\';
	}).join("");
	var last=pts[pts.length-1];
	var svg=\'<svg viewBox="0 0 \'+W+" "+H+\'" preserveAspectRatio="none" aria-hidden="true">\'
		+\'<line x1="0" y1="\'+( H-pad)+\'" x2="\'+W+\'" y2="\'+( H-pad)+\'" stroke="#2c2c2c" stroke-width="1"></line>\'
		+bars
		+\'<polyline fill="none" stroke="\'+color+\'" stroke-width="3" points="\'+line+\'"></polyline>\'
		+\'<circle cx="\'+last[0].toFixed(2)+\'" cy="\'+last[1].toFixed(2)+\'" r="4" fill="\'+color+\'"></circle>\'
		+\'</svg>\';
	$el.html(svg);
}

function renderAlerts(alerts){
	var w=$("#nw-alerts");
	if(!alerts||!alerts.length){
		w.html(\'<div class="nw-alert-card nw-alert-ok"><div class="nw-alert-dot"></div><div class="nw-alert-body"><div class="nw-alert-label">System</div><div class="nw-alert-text">No immediate operational issues detected.</div></div></div>\');
		return;
	}
	w.html(alerts.map(function(a){
		return\'<div class="nw-alert-card nw-alert-\'+escapeHtml(a.level||"info")+\'"><div class="nw-alert-dot"></div><div class="nw-alert-body"><div class="nw-alert-label">\'+escapeHtml(a.label||"Notice")+\'</div><div class="nw-alert-text">\'+escapeHtml(a.text||"")+\'</div></div></div>\';
	}).join(""));
}

function renderLogs(logs){
	var w=$("#nw-logs");
	if(!logs||!logs.length){w.html(\'<div class="nw-empty-state">No recent system events.</div>\');return;}
	w.html(logs.map(function(log){
		var lvl=String(log.level||"info").toLowerCase();
		if(["info","warn","error"].indexOf(lvl)===-1)lvl="info";
		var dataText="";
		if(log.data!==null&&typeof log.data!=="undefined"&&String(log.data).length){
			dataText=typeof log.data==="object"?JSON.stringify(log.data):String(log.data);
		}
		return\'<div class="nw-log-item">\'
			+\'<div class="nw-log-top"><span class="nw-log-level nw-log-level-\'+escapeHtml(lvl)+\'">\'+escapeHtml(lvl)+\'</span><span class="nw-log-date">\'+escapeHtml(log.created_at||"")+\'</span></div>\'
			+\'<div class="nw-log-message">\'+escapeHtml(log.message||"(no message)")+\'</div>\'
			+\'<div class="nw-log-meta">context: \'+escapeHtml(log.context||"&#8212;")+(dataText?" | data: "+escapeHtml(dataText):"")+\'</div>\'
			+\'</div>\';
	}).join(""));
}

function loadDashboard(){
	$("#nw-stat-characters,#nw-stat-worlds,#nw-stat-campaigns").html(\'<div class="nw-spinner"></div>\');
	$("#nw-recent-characters,#nw-recent-worlds,#nw-recent-campaigns").text("Last 7d: \u2014");
	$("#nw-health-worlds-without-campaigns,#nw-health-campaigns-without-character").text("\u2014");
	$("#nw-alerts").html(\'<div class="nw-alert-card nw-alert-card-loading"><div class="nw-spinner"></div><span>Refreshing\u2026</span></div>\');
	$("#nw-logs").html(\'<div class="nw-empty-state">Loading recent events\u2026</div>\');
	$("#nw-chart-characters,#nw-chart-worlds,#nw-chart-campaigns").html(\'<div class="nw-chart-empty">Loading\u2026</div>\');

	$.post(ajaxurl,{action:"nw_dashboard_data",nonce:$("#nw-dash-nonce").val()},function(res){
		if(!res.success){
			$("#nw-stat-characters,#nw-stat-worlds,#nw-stat-campaigns").text("\u2014");
			renderAlerts([{level:"warn",label:"Dashboard",text:(res.data||"Could not load dashboard data.")}]);
			$("#nw-logs").html(\'<div class="nw-empty-state">Could not load logs.</div>\');
			return;
		}
		var d=res.data||{},c=d.counts||{},r=d.recent||{},g=d.growth||{},h=d.health||{};

		// Debug info in console
		if(d._debug){console.group("[NeoWeaver] Dashboard debug");console.log("Key type:",d._debug.key_type);console.table(d._debug.growth_meta);console.groupEnd();}

		$("#nw-stat-characters").text(c.characters||0);
		$("#nw-stat-worlds").text(c.worlds||0);
		$("#nw-stat-campaigns").text(c.campaigns||0);

		$("#nw-recent-characters").text("Last 7d: +"+(r.characters_7d||0));
		$("#nw-recent-worlds").text("Last 7d: +"+(r.worlds_7d||0));
		$("#nw-recent-campaigns").text("Last 7d: +"+(r.campaigns_7d||0));

		$("#nw-health-worlds-without-campaigns").text(h.worlds_without_campaigns||0);
		$("#nw-health-campaigns-without-character").text(h.campaigns_without_character||0);

		drawSparkline("#nw-chart-characters",g.characters,"#adff00");
		drawSparkline("#nw-chart-worlds",g.worlds,"#00d4ff");
		drawSparkline("#nw-chart-campaigns",g.campaigns,"#ffb703");

		renderAlerts(d.alerts||[]);
		renderLogs(d.logs||[]);
	});
}

loadDashboard();
$("#nw-refresh-dashboard").on("click",function(){loadDashboard();});

});
';
	}

}

new NeoWeaver_Admin();
