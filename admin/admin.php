<?php
/**
 * NeoWeaver Admin — Main Menu & Dashboard
 *
 * Reworked dashboard:
 * - Overview: Characters, Worlds, Campaigns, Active Sessions
 * - Messages: total, 7d, 30d
 * - Trends: Characters, Worlds, Campaigns with 7/30 day filter
 * - World Health
 * - Recent System Events
 *
 * IMPORTANT: Do NOT instantiate this class here.
 * Instantiation is handled exclusively by NW_Admin_Bootstrap in class-admin.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'NeoWeaver_Admin', false ) ) :

class NeoWeaver_Admin {

	private string $slug = 'neoweaver';

	private const DEBUG_LOGS_TABLE    = 'debug_log';
	private const CHAT_MESSAGES_TABLE = 'cyber_chat_messages';
	private const GAME_SESSIONS_TABLE = 'cyber_game_sessions';

	public function __construct() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_menu', [ $this, 'rename_first_submenu' ], 999 );
		add_action( 'admin_menu', [ $this, 'sort_submenu' ], 9999 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_nw_dashboard_data', [ $this, 'ajax_dashboard_data' ] );
	}

	/* ------------------------------------------------------------------ */
	/* MENU                                                               */
	/* ------------------------------------------------------------------ */

	public function register_menu(): void {
		$svg_icon = 'data:image/svg+xml;base64,' . base64_encode( $this->logo_svg( 20, '#a0a0a0' ) );

		add_menu_page(
			'NeoWeaver',
			'NeoWeaver',
			'manage_options',
			$this->slug,
			[ $this, 'render_page' ],
			$svg_icon,
			30
		);
	}

	public function rename_first_submenu(): void {
		global $submenu;
		if ( isset( $submenu[ $this->slug ][0][0] ) ) {
			$submenu[ $this->slug ][0][0] = 'Dashboard';
		}
	}

	public function sort_submenu(): void {
		global $submenu;

		if ( empty( $submenu[ $this->slug ] ) || count( $submenu[ $this->slug ] ) < 2 ) {
			return;
		}

		$desired_order = [
			$this->slug,
			'nw-abilities',
			'nw-achievements',
			'nw-classes',
			'nw-containers',
			'nw-deck',
			'nw-items',
			'nw-races',
			'nw-scenarios',
			'nw-seasons',
			'nw-skills',
			'nw-starting-packages',
			'nw-status-tags',
			'nw-style-dictionary',
			'nw-world-tags-def',
			'nw-settings',
		];

		$current = $submenu[ $this->slug ];
		$ordered = [];
		$rest    = [];

		foreach ( $desired_order as $slug ) {
			foreach ( $current as $idx => $item ) {
				if ( isset( $item[2] ) && $item[2] === $slug ) {
					$ordered[] = $item;
					unset( $current[ $idx ] );
					break;
				}
			}
		}

		if ( ! empty( $current ) ) {
			usort(
				$current,
				static function ( array $a, array $b ): int {
					return strcasecmp( (string) $a[0], (string) $b[0] );
				}
			);
			$rest = array_values( $current );
		}

		$submenu[ $this->slug ] = array_merge( $ordered, $rest );
	}

	/* ------------------------------------------------------------------ */
	/* ASSETS                                                             */
	/* ------------------------------------------------------------------ */

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== 'toplevel_page_' . $this->slug ) {
			return;
		}

		$plugin_url = plugin_dir_url( dirname( __FILE__ ) );

		$version = defined( 'NEOWEAVER_VERSION' ) ? NEOWEAVER_VERSION
			: ( defined( 'NW_VERSION' ) ? NW_VERSION : null );

		if ( ! wp_style_is( 'chakra-petch', 'registered' ) && ! wp_style_is( 'chakra-petch', 'enqueued' ) ) {
			wp_enqueue_style(
				'chakra-petch',
				'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
				[],
				null
			);
		}

		wp_enqueue_style(
			'nw-dashboard-style',
			$plugin_url . 'assets/css/admin/dashboard.css',
			[ 'chakra-petch' ],
			$version
		);

		wp_enqueue_script(
			'nw-dashboard-script',
			$plugin_url . 'assets/js/admin/dashboard.js',
			[ 'jquery' ],
			$version,
			true
		);

		wp_localize_script(
			'nw-dashboard-script',
			'NWDashData',
			[
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'neoweaver_dashboard' ),
				'debug'   => defined( 'WP_DEBUG' ) && WP_DEBUG ? '1' : '0',
			]
		);
	}

	/* ------------------------------------------------------------------ */
	/* HELPERS                                                            */
	/* ------------------------------------------------------------------ */

	private function get_supa_url(): string {
		return function_exists( 'tw_supabase_url' ) ? trim( (string) tw_supabase_url() ) : '';
	}

	private function get_supa_key(): string {
		if ( function_exists( 'tw_supabase_service_key' ) && tw_supabase_service_key() ) {
			return trim( (string) tw_supabase_service_key() );
		}
		if ( function_exists( 'tw_supabase_anon_key' ) && tw_supabase_anon_key() ) {
			return trim( (string) tw_supabase_anon_key() );
		}
		return '';
	}

	private function get_supa_key_type(): string {
		if ( function_exists( 'tw_supabase_service_key' ) && tw_supabase_service_key() ) {
			return 'service_role';
		}
		if ( function_exists( 'tw_supabase_anon_key' ) && tw_supabase_anon_key() ) {
			return 'anon';
		}
		return 'none';
	}

	private function supa_get( string $path ): array {
		if ( function_exists( 'tw_supabase_get' ) ) {
			$result = tw_supabase_get( $path );
			if ( is_array( $result ) && array_key_exists( 'ok', $result ) ) {
				return $result;
			}
			return [
				'ok'     => ! is_null( $result ),
				'status' => is_null( $result ) ? 0 : 200,
				'body'   => $result,
				'error'  => is_null( $result ) ? 'tw_supabase_get returned null.' : null,
				'raw'    => null,
			];
		}

		$supa_url = $this->get_supa_url();
		$supa_key = $this->get_supa_key();

		if ( ! $supa_url || ! $supa_key ) {
			return [
				'ok'     => false,
				'status' => 0,
				'body'   => null,
				'error'  => 'Supabase not configured.',
				'raw'    => null,
			];
		}

		$res = wp_remote_get(
			rtrim( $supa_url, '/' ) . '/rest/v1/' . ltrim( $path, '/' ),
			[
				'timeout' => 15,
				'headers' => [
					'apikey'        => $supa_key,
					'Authorization' => 'Bearer ' . $supa_key,
					'Accept'        => 'application/json',
				],
			]
		);

		if ( is_wp_error( $res ) ) {
			return [
				'ok'     => false,
				'status' => 0,
				'body'   => null,
				'error'  => $res->get_error_message(),
				'raw'    => null,
			];
		}

		$body = wp_remote_retrieve_body( $res );
		$data = json_decode( $body, true );
		$code = (int) wp_remote_retrieve_response_code( $res );

		return [
			'ok'     => ( $code >= 200 && $code < 300 ),
			'status' => $code,
			'body'   => $data,
			'error'  => null,
			'raw'    => ( $code < 200 || $code >= 300 ) ? substr( $body, 0, 500 ) : null,
		];
	}

	private function supa_count( string $table, string $filters = '' ): int {
		$supa_url = $this->get_supa_url();
		$supa_key = $this->get_supa_key();

		if ( ! $supa_url || ! $supa_key ) {
			return 0;
		}

		$url = rtrim( $supa_url, '/' ) . '/rest/v1/' . $table . '?select=id';
		if ( $filters ) {
			$url .= '&' . ltrim( $filters, '&' );
		}

		$res = wp_remote_get(
			$url,
			[
				'timeout' => 10,
				'headers' => [
					'apikey'        => $supa_key,
					'Authorization' => 'Bearer ' . $supa_key,
					'Range'         => '0-0',
					'Prefer'        => 'count=exact',
					'Accept'        => 'application/json',
				],
			]
		);

		if ( is_wp_error( $res ) ) {
			return 0;
		}

		$cr = wp_remote_retrieve_header( $res, 'content-range' );
		if ( $cr && preg_match( '/\\/(\\d+)$/', $cr, $m ) ) {
			return (int) $m[1];
		}

		return 0;
	}

	private function supa_recent_count( string $table, int $days = 7, string $date_col = 'created_at', string $extra_filters = '' ): int {
		$supa_url = $this->get_supa_url();
		$supa_key = $this->get_supa_key();

		if ( ! $supa_url || ! $supa_key ) {
			return 0;
		}

		$since = gmdate( 'Y-m-d\\TH:i:s\\Z', time() - ( $days * DAY_IN_SECONDS ) );

		$url = rtrim( $supa_url, '/' ) . '/rest/v1/' . $table . '?select=id&' . $date_col . '=gte.' . rawurlencode( $since );
		if ( $extra_filters ) {
			$url .= '&' . ltrim( $extra_filters, '&' );
		}

		$res = wp_remote_get(
			$url,
			[
				'timeout' => 10,
				'headers' => [
					'apikey'        => $supa_key,
					'Authorization' => 'Bearer ' . $supa_key,
					'Range'         => '0-0',
					'Prefer'        => 'count=exact',
					'Accept'        => 'application/json',
				],
			]
		);

		if ( is_wp_error( $res ) ) {
			return 0;
		}

		$cr = wp_remote_retrieve_header( $res, 'content-range' );
		if ( $cr && preg_match( '/\\/(\\d+)$/', $cr, $m ) ) {
			return (int) $m[1];
		}

		return 0;
	}

	private function supa_growth_series( string $table, int $days = 30, string $date_col = 'created_at' ): array {
		$days          = max( 1, min( 30, $days ) );
		$transient_key = 'nw_growth_' . md5( $table . '_' . $date_col . '_' . $days . '_' . gmdate( 'YmdHi', (int) ( time() / 300 ) * 300 ) );
		$cached        = get_transient( $transient_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$since = gmdate( 'Y-m-d\\TH:i:s\\Z', time() - ( ( $days - 1 ) * DAY_IN_SECONDS ) );

		$path = $table
			. '?select=' . rawurlencode( $date_col )
			. '&' . $date_col . '=gte.' . rawurlencode( $since )
			. '&order=' . rawurlencode( $date_col . '.asc' )
			. '&limit=5000';

		$res  = $this->supa_get( $path );
		$rows = ( $res['ok'] && is_array( $res['body'] ) ) ? $res['body'] : [];

		$series = [];
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$d            = gmdate( 'Y-m-d', time() - ( $i * DAY_IN_SECONDS ) );
			$series[ $d ] = 0;
		}

		foreach ( $rows as $row ) {
			if ( empty( $row[ $date_col ] ) ) {
				continue;
			}
			$key = gmdate( 'Y-m-d', strtotime( (string) $row[ $date_col ] ) );
			if ( isset( $series[ $key ] ) ) {
				$series[ $key ]++;
			}
		}

		$out = [];
		foreach ( $series as $date => $count ) {
			$out[] = [
				'date'  => $date,
				'value' => $count,
			];
		}

		$result = [
			'series'      => $out,
			'rows_found'  => count( $rows ),
			'query_ok'    => $res['ok'],
			'http_status' => $res['status'],
			'api_error'   => $res['raw'] ?? null,
		];

		set_transient( $transient_key, $result, 5 * MINUTE_IN_SECONDS );

		return $result;
	}

	private function supa_recent_logs( int $limit = 10 ): array {
		// Table: debug_log (columns: id, created_at, level, message, context, data).
		$path = self::DEBUG_LOGS_TABLE . '?select=id,created_at,level,message,context,data&order=created_at.desc&limit=' . $limit;
		$res  = $this->supa_get( $path );

		if ( ! $res['ok'] ) {
			return [];
		}

		return is_array( $res['body'] ) ? $res['body'] : [];
	}

	private function supa_campaigns_without_character(): int {
		// Fixed: correct junction table name is cyber_campaign_characters (not cyber_campaigncharacters).
		$path = 'cyber_campaign?select=id,cyber_campaign_characters!left(character_id)&cyber_campaign_characters.character_id=is.null&limit=1';
		$res  = wp_remote_get(
			rtrim( $this->get_supa_url(), '/' ) . '/rest/v1/' . $path,
			[
				'timeout' => 10,
				'headers' => [
					'apikey'        => $this->get_supa_key(),
					'Authorization' => 'Bearer ' . $this->get_supa_key(),
					'Range'         => '0-0',
					'Prefer'        => 'count=exact',
					'Accept'        => 'application/json',
				],
			]
		);

		if ( ! is_wp_error( $res ) ) {
			$cr = wp_remote_retrieve_header( $res, 'content-range' );
			if ( $cr && preg_match( '/\\/(\\d+)$/', $cr, $m ) ) {
				return (int) $m[1];
			}
		}

		$path_full = 'cyber_campaign?select=id,cyber_campaign_characters!left(character_id)&limit=5000';
		$r         = $this->supa_get( $path_full );
		$rows      = ( $r['ok'] && is_array( $r['body'] ) ) ? $r['body'] : [];

		$count = 0;
		foreach ( $rows as $row ) {
			if ( empty( $row['cyber_campaign_characters'] ) ) {
				$count++;
			}
		}
		return $count;
	}

	private function supa_worlds_without_campaigns(): int {
		// Fixed: disambiguate FK with explicit hint cyber_campaign!cyber_campaign_world_id_fkey.
		$path = 'cyber_worlds?select=id,cyber_campaign!cyber_campaign_world_id_fkey(id)&cyber_campaign.id=is.null&limit=1';
		$res  = wp_remote_get(
			rtrim( $this->get_supa_url(), '/' ) . '/rest/v1/' . $path,
			[
				'timeout' => 10,
				'headers' => [
					'apikey'        => $this->get_supa_key(),
					'Authorization' => 'Bearer ' . $this->get_supa_key(),
					'Range'         => '0-0',
					'Prefer'        => 'count=exact',
					'Accept'        => 'application/json',
				],
			]
		);

		if ( ! is_wp_error( $res ) ) {
			$cr = wp_remote_retrieve_header( $res, 'content-range' );
			if ( $cr && preg_match( '/\\/(\\d+)$/', $cr, $m ) ) {
				return (int) $m[1];
			}
		}

		$path_full = 'cyber_worlds?select=id,cyber_campaign!cyber_campaign_world_id_fkey(id)&limit=5000';
		$r         = $this->supa_get( $path_full );
		$rows      = ( $r['ok'] && is_array( $r['body'] ) ) ? $r['body'] : [];

		$count = 0;
		foreach ( $rows as $row ) {
			if ( empty( $row['cyber_campaign'] ) ) {
				$count++;
			}
		}
		return $count;
	}

	private function supa_active_sessions_count(): int {
		$supa_url = $this->get_supa_url();
		$supa_key = $this->get_supa_key();

		if ( ! $supa_url || ! $supa_key ) {
			return 0;
		}

		return $this->supa_count( self::GAME_SESSIONS_TABLE, 'status=eq.active' );
	}

	private function supa_campaigns_with_active_session(): int {
		$supa_url = $this->get_supa_url();
		$supa_key = $this->get_supa_key();

		if ( ! $supa_url || ! $supa_key ) {
			return 0;
		}

		$path = self::GAME_SESSIONS_TABLE
			. '?select=campaign_id'
			. '&status=eq.active'
			. '&campaign_id=not.is.null'
			. '&limit=5000';

		$res = $this->supa_get( $path );
		if ( ! $res['ok'] || ! is_array( $res['body'] ) ) {
			return 0;
		}

		$uniq = [];
		foreach ( $res['body'] as $row ) {
			if ( ! empty( $row['campaign_id'] ) ) {
				$uniq[ (string) $row['campaign_id'] ] = true;
			}
		}

		return count( $uniq );
	}

	private function supa_messages_total(): int {
		return $this->supa_count( self::CHAT_MESSAGES_TABLE );
	}

	private function supa_messages_recent( int $days ): int {
		return $this->supa_recent_count( self::CHAT_MESSAGES_TABLE, $days, 'created_at' );
	}

	private function get_range_days(): int {
		$range = isset( $_POST['range'] )
			? (int) wp_unslash( $_POST['range'] )
			: 30;

		return in_array( $range, [ 7, 30 ], true ) ? $range : 30;
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: DASHBOARD                                                    */
	/* ------------------------------------------------------------------ */

	public function ajax_dashboard_data(): void {
		check_ajax_referer( 'neoweaver_dashboard', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
			return;
		}

		if ( ! $this->get_supa_url() || ! $this->get_supa_key() ) {
			wp_send_json_error( 'Supabase not configured.' );
			return;
		}

		$range_days = $this->get_range_days();

		$counts = [
			'characters'                    => $this->supa_count( 'cyber_characters' ),
			'worlds'                        => $this->supa_count( 'cyber_worlds' ),
			'campaigns'                     => $this->supa_count( 'cyber_campaign' ),
			'active_sessions'               => $this->supa_active_sessions_count(),
			'campaigns_with_active_session' => $this->supa_campaigns_with_active_session(),
			'messages_total'                => $this->supa_messages_total(),
		];

		$recent = [
			'characters_7d' => $this->supa_recent_count( 'cyber_characters', 7 ),
			'worlds_7d'     => $this->supa_recent_count( 'cyber_worlds', 7 ),
			'campaigns_7d'  => $this->supa_recent_count( 'cyber_campaign', 7 ),
			'messages_7d'   => $this->supa_messages_recent( 7 ),
			'messages_30d'  => $this->supa_messages_recent( 30 ),
		];

		$growth_raw = [
			'characters' => $this->supa_growth_series( 'cyber_characters', $range_days ),
			'worlds'     => $this->supa_growth_series( 'cyber_worlds', $range_days ),
			'campaigns'  => $this->supa_growth_series( 'cyber_campaign', $range_days ),
		];

		$growth = [
			'characters' => $growth_raw['characters']['series'],
			'worlds'     => $growth_raw['worlds']['series'],
			'campaigns'  => $growth_raw['campaigns']['series'],
		];

		$health = [
			'worlds_without_campaigns'    => $this->supa_worlds_without_campaigns(),
			'campaigns_without_character' => $this->supa_campaigns_without_character(),
		];

		$logs       = $this->supa_recent_logs( 10 );
		$logs_table = self::DEBUG_LOGS_TABLE;

		$alerts = [];

		if ( 0 === $recent['characters_7d'] ) {
			$alerts[] = [ 'level' => 'warn', 'label' => 'Characters', 'text' => 'No new characters in the last 7 days.' ];
		}
		if ( 0 === $recent['worlds_7d'] ) {
			$alerts[] = [ 'level' => 'warn', 'label' => 'Worlds', 'text' => 'No new worlds in the last 7 days.' ];
		}
		if ( 0 === $recent['campaigns_7d'] ) {
			$alerts[] = [ 'level' => 'warn', 'label' => 'Campaigns', 'text' => 'No new campaigns in the last 7 days.' ];
		}
		if ( 0 === $counts['campaigns_with_active_session'] ) {
			$alerts[] = [ 'level' => 'info', 'label' => 'Sessions', 'text' => 'No campaigns currently have an active session.' ];
		}
		if ( $health['worlds_without_campaigns'] > 0 ) {
			$alerts[] = [
				'level' => 'info',
				'label' => 'World Coverage',
				'text'  => $health['worlds_without_campaigns'] . ' world(s) have no campaign yet.',
			];
		}
		if ( $health['campaigns_without_character'] > 0 ) {
			$alerts[] = [
				'level' => 'warn',
				'label' => 'Campaign Setup',
				'text'  => $health['campaigns_without_character'] . ' campaign(s) have no assigned character.',
			];
		}

		wp_send_json_success(
			[
				'range_days' => $range_days,
				'counts'     => $counts,
				'recent'     => $recent,
				'growth'     => $growth,
				'health'     => $health,
				'alerts'     => $alerts,
				'logs'       => $logs,
				'_debug'     => [
					'key_type'    => $this->get_supa_key_type(),
					'logs_table'  => $logs_table,
					'growth_meta' => [
						'characters' => [
							'rows_found'  => $growth_raw['characters']['rows_found'],
							'query_ok'    => $growth_raw['characters']['query_ok'],
							'http_status' => $growth_raw['characters']['http_status'],
							'api_error'   => $growth_raw['characters']['api_error'],
						],
						'worlds'     => [
							'rows_found'  => $growth_raw['worlds']['rows_found'],
							'query_ok'    => $growth_raw['worlds']['query_ok'],
							'http_status' => $growth_raw['worlds']['http_status'],
							'api_error'   => $growth_raw['worlds']['api_error'],
						],
						'campaigns'  => [
							'rows_found'  => $growth_raw['campaigns']['rows_found'],
							'query_ok'    => $growth_raw['campaigns']['query_ok'],
							'http_status' => $growth_raw['campaigns']['http_status'],
							'api_error'   => $growth_raw['campaigns']['api_error'],
						],
					],
				],
			]
		);
	}

	/* ------------------------------------------------------------------ */
	/* RENDER                                                             */
	/* ------------------------------------------------------------------ */

	public function render_page(): void {
		$supa_url = $this->get_supa_url();
		$key_ok   = (bool) $this->get_supa_key();
		$version  = defined( 'NEOWEAVER_VERSION' ) ? NEOWEAVER_VERSION
			: ( defined( 'NW_VERSION' ) ? NW_VERSION : '—' );
		?>
		<div class="wrap nw-dash" id="nw-dashboard">

			<div class="nw-dash-header">
				<div class="nw-dash-logo">
					<?php
					$svg = $this->logo_svg( 44, '#adff00' );
					if ( $svg ) {
						echo wp_kses_post( $svg );
					}
					?>
					<div>
						<span class="nw-logo-name"><span class="nw-accent">Neo</span>Weaver</span>
						<span class="nw-logo-version">v<?php echo esc_html( $version ); ?> &mdash; Game Ops Dashboard</span>
					</div>
				</div>

				<div class="nw-dash-actions">
					<div class="nw-range-switch" id="nw-range-switch" role="tablist" aria-label="Trend range">
						<button type="button" class="nw-btn nw-btn-ghost nw-range-btn" data-range="7">7d</button>
						<button type="button" class="nw-btn nw-btn-ghost nw-range-btn is-active" data-range="30">30d</button>
					</div>
					<button class="nw-btn nw-btn-ghost" id="nw-refresh-dashboard">&#8635; Refresh</button>
				</div>
			</div>

			<div class="nw-grid-main">

				<section class="nw-block">
					<div class="nw-block-head">
						<h2 class="nw-section-title">Overview</h2>
						<span class="nw-section-kicker">core entities</span>
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
						<div class="nw-stat-card">
							<div class="nw-stat-label-top">Active Sessions</div>
							<div class="nw-stat-value" id="nw-stat-active-sessions"><div class="nw-spinner"></div></div>
							<div class="nw-stat-sub" id="nw-recent-active-sessions">Campaigns live: &mdash;</div>
						</div>
					</div>
				</section>

				<section class="nw-block">
					<div class="nw-block-head">
						<h2 class="nw-section-title">Messages</h2>
						<span class="nw-section-kicker">cyber_chat_messages</span>
					</div>
					<div class="nw-stat-grid">
						<div class="nw-stat-card">
							<div class="nw-stat-label-top">Total Messages</div>
							<div class="nw-stat-value" id="nw-stat-messages-total"><div class="nw-spinner"></div></div>
							<div class="nw-stat-sub">All time</div>
						</div>
						<div class="nw-stat-card">
							<div class="nw-stat-label-top">Messages 7d</div>
							<div class="nw-stat-value" id="nw-stat-messages-7d"><div class="nw-spinner"></div></div>
							<div class="nw-stat-sub">Last 7 days</div>
						</div>
						<div class="nw-stat-card">
							<div class="nw-stat-label-top">Messages 30d</div>
							<div class="nw-stat-value" id="nw-stat-messages-30d"><div class="nw-spinner"></div></div>
							<div class="nw-stat-sub">Last 30 days</div>
						</div>
					</div>
				</section>

				<section class="nw-block">
					<div class="nw-block-head">
						<h2 class="nw-section-title">Trends</h2>
						<span class="nw-section-kicker">characters, worlds, campaigns</span>
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
							<h2 class="nw-section-title">World Health</h2>
							<span class="nw-section-kicker">structure integrity</span>
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
							<span class="nw-section-kicker"><?php echo esc_html( self::DEBUG_LOGS_TABLE ); ?></span>
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
							<span class="nw-sysinfo-val"><?php echo esc_html( $version ); ?></span>
						</div>
						<div class="nw-sysinfo-row">
							<span class="nw-sysinfo-label">Supabase URL</span>
							<span class="nw-sysinfo-val">
								<?php if ( $supa_url ) : ?>
									<?php echo esc_html( $supa_url ); ?>
								<?php else : ?>
									<span class="nw-text-danger">Not configured</span>
								<?php endif; ?>
							</span>
						</div>
						<div class="nw-sysinfo-row">
							<span class="nw-sysinfo-label">Supabase Key</span>
							<span class="nw-sysinfo-val">
								<?php if ( $key_ok ) : ?>
									<span class="nw-text-good">Configured (<?php echo esc_html( $this->get_supa_key_type() ); ?>)</span>
								<?php else : ?>
									<span class="nw-text-danger">Missing</span>
								<?php endif; ?>
							</span>
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
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* SVG LOGO                                                           */
	/* ------------------------------------------------------------------ */

	private function logo_svg( int $size = 20, string $color = '#ffffff' ): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 40 40" fill="none" aria-label="NeoWeaver">'
			. '<polygon points="20,2 36,11 36,29 20,38 4,29 4,11" stroke="' . esc_attr( $color ) . '" stroke-width="2.5" fill="none"/>'
			. '<polyline points="11,27 11,13 20,24 29,13 29,27" stroke="' . esc_attr( $color ) . '" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" fill="none"/>'
			. '</svg>';
	}
}

endif; // class_exists( 'NeoWeaver_Admin' )
