<?php
/**
 * NeoWeaver Admin — Main Menu & Dashboard
 *
 * Loaded FIRST (explicitly, before glob) so the top-level "neoweaver"
 * menu slug exists when all submenu files run add_submenu_page().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NeoWeaver_Admin {

	private string $slug = 'neoweaver';

	public function __construct() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu',            [ $this, 'register_menu'        ] );
		add_action( 'admin_menu',            [ $this, 'rename_first_submenu' ], 999 );
		add_action( 'admin_menu',            [ $this, 'sort_submenu'         ], 9999 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'       ] );
		add_action( 'wp_ajax_nw_dashboard_data', [ $this, 'ajax_dashboard_data' ] );
	}

	/* ------------------------------------------------------------------ */
	/*  MENU                                                               */
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

		$dashboard = array_shift( $submenu[ $this->slug ] );

		usort( $submenu[ $this->slug ], static function ( array $a, array $b ): int {
			return strcasecmp( $a[0], $b[0] );
		} );

		array_unshift( $submenu[ $this->slug ], $dashboard );
	}

	/* ------------------------------------------------------------------ */
	/*  ASSETS                                                             */
	/* ------------------------------------------------------------------ */

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== 'toplevel_page_' . $this->slug ) {
			return;
		}

		/*
		 * Use plugin_dir_url() relative to this file instead of relying on
		 * NEOWEAVER_PLUGIN_URL / NW_PLUGIN_URL being defined — avoids the
		 * "Undefined constant" fatal that hits when the bootstrap file hasn't
		 * run yet or the constant name doesn't match.
		 *
		 * dirname(__FILE__) = .../neoweaver-wp-core-main/admin
		 * dirname(dirname(__FILE__)) = .../neoweaver-wp-core-main/
		 */
		$plugin_url = plugin_dir_url( dirname( __FILE__ ) );

		/*
		 * Version string: use the constant if available, otherwise null
		 * (WordPress will omit the ?ver= query string).
		 */
		$version = defined( 'NEOWEAVER_VERSION' ) ? NEOWEAVER_VERSION
				 : ( defined( 'NW_VERSION' )      ? NW_VERSION
				 : null );

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
			$plugin_url . 'assets/css/admin-dashboard.css',
			[ 'chakra-petch' ],
			$version
		);

		wp_enqueue_script(
			'nw-dashboard-script',
			$plugin_url . 'assets/js/admin-dashboard.js',
			[ 'jquery' ],
			$version,
			true
		);

		wp_localize_script( 'nw-dashboard-script', 'NWDashData', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'neoweaver_dashboard' ),
		] );
	}

	/* ------------------------------------------------------------------ */
	/*  HELPERS                                                            */
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
			return [ 'ok' => false, 'status' => 0, 'body' => null, 'error' => 'Supabase not configured.', 'raw' => null ];
		}

		$res = wp_remote_get(
			rtrim( $supa_url, '/' ) . '/rest/v1/' . ltrim( $path, '/' ),
			[
				'timeout' => 12,
				'headers' => [
					'apikey'        => $supa_key,
					'Authorization' => 'Bearer ' . $supa_key,
					'Accept'        => 'application/json',
				],
			]
		);

		if ( is_wp_error( $res ) ) {
			return [ 'ok' => false, 'status' => 0, 'body' => null, 'error' => $res->get_error_message(), 'raw' => null ];
		}

		$body = wp_remote_retrieve_body( $res );
		$data = json_decode( $body, true );
		$code = (int) wp_remote_retrieve_response_code( $res );

		return [
			'ok'     => ( $code >= 200 && $code < 300 ),
			'status' => $code,
			'body'   => $data,
			'error'  => null,
			'raw'    => ( $code < 200 || $code >= 300 ) ? substr( $body, 0, 300 ) : null,
		];
	}

	private function supa_count( string $table ): int {
		$supa_url = $this->get_supa_url();
		$supa_key = $this->get_supa_key();

		if ( ! $supa_url || ! $supa_key ) {
			return 0;
		}

		$res = wp_remote_get(
			rtrim( $supa_url, '/' ) . '/rest/v1/' . $table . '?select=id',
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
		if ( $cr && preg_match( '/\\/(\d+)$/', $cr, $m ) ) {
			return (int) $m[1];
		}

		return 0;
	}

	private function supa_recent_count( string $table, int $days = 7 ): int {
		$supa_url = $this->get_supa_url();
		$supa_key = $this->get_supa_key();

		if ( ! $supa_url || ! $supa_key ) {
			return 0;
		}

		$since = gmdate( 'Y-m-d\\TH:i:s\\Z', time() - ( $days * DAY_IN_SECONDS ) );

		$res = wp_remote_get(
			rtrim( $supa_url, '/' ) . '/rest/v1/' . $table . '?select=id&created_at=gte.' . rawurlencode( $since ),
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
		if ( $cr && preg_match( '/\\/(\d+)$/', $cr, $m ) ) {
			return (int) $m[1];
		}

		return 0;
	}

	private function supa_growth_series( string $table, int $days = 30 ): array {
		$transient_key = 'nw_growth_' . md5( $table . '_' . $days . '_' . gmdate( 'YmdHi', (int) ( time() / 300 ) * 300 ) );
		$cached        = get_transient( $transient_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$since = gmdate( 'Y-m-d\\TH:i:s\\Z', time() - ( ( $days - 1 ) * DAY_IN_SECONDS ) );

		$path = $table
			. '?select=created_at'
			. '&created_at=gte.' . rawurlencode( $since )
			. '&order=created_at.asc'
			. '&limit=5000';

		$res  = $this->supa_get( $path );
		$rows = ( $res['ok'] && is_array( $res['body'] ) ) ? $res['body'] : [];

		$series = [];
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$d            = gmdate( 'Y-m-d', time() - ( $i * DAY_IN_SECONDS ) );
			$series[ $d ] = 0;
		}

		foreach ( $rows as $row ) {
			if ( empty( $row['created_at'] ) ) {
				continue;
			}
			$key = gmdate( 'Y-m-d', strtotime( $row['created_at'] ) );
			if ( isset( $series[ $key ] ) ) {
				$series[ $key ]++;
			}
		}

		$out = [];
		foreach ( $series as $date => $count ) {
			$out[] = [ 'date' => $date, 'value' => $count ];
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

	private const DEBUG_LOGS_TABLE = 'cyber_logs';

	private function supa_recent_logs( int $limit = 10 ): array {
		$path = self::DEBUG_LOGS_TABLE . '?select=id,created_at,level,message,context,data&order=created_at.desc&limit=' . $limit;
		$res  = $this->supa_get( $path );

		if ( ! $res['ok'] ) {
			return [];
		}

		return is_array( $res['body'] ) ? $res['body'] : [];
	}

	private function supa_campaigns_without_character(): int {
		$path = 'cyber_campaign?select=id,cyber_campaigncharacters!left(character_id)&cyber_campaigncharacters.character_id=is.null&limit=1';
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
			if ( $cr && preg_match( '/\\/(\d+)$/', $cr, $m ) ) {
				return (int) $m[1];
			}
		}

		$path_full = 'cyber_campaign?select=id,cyber_campaigncharacters!left(character_id)&limit=5000';
		$r         = $this->supa_get( $path_full );
		$rows      = ( $r['ok'] && is_array( $r['body'] ) ) ? $r['body'] : [];

		$count = 0;
		foreach ( $rows as $row ) {
			if ( empty( $row['cyber_campaigncharacters'] ) ) {
				$count++;
			}
		}
		return $count;
	}

	private function supa_worlds_without_campaigns(): int {
		$path = 'cyber_worlds?select=id,cyber_campaign!left(id)&cyber_campaign.id=is.null&limit=1';
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
			if ( $cr && preg_match( '/\\/(\d+)$/', $cr, $m ) ) {
				return (int) $m[1];
			}
		}

		$path_full = 'cyber_worlds?select=id,cyber_campaign!left(id)&limit=5000';
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

	private function supa_deck_breakdown(): array {
		$transient_key = 'nw_deck_breakdown_' . gmdate( 'YmdHi', (int) ( time() / 300 ) * 300 );
		$cached        = get_transient( $transient_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$path = 'cyber_deck?select=deck_category,rarity,is_active&limit=5000';
		$res  = $this->supa_get( $path );
		$rows = ( $res['ok'] && is_array( $res['body'] ) ) ? $res['body'] : [];

		$categories = [ 'action' => 0, 'magic' => 0, 'equipment' => 0 ];
		$rarities   = [ 'common' => 0, 'uncommon' => 0, 'rare' => 0, 'epic' => 0, 'legendary' => 0 ];
		$active_count   = 0;
		$inactive_count = 0;

		foreach ( $rows as $row ) {
			$cat = $row['deck_category'] ?? '';
			$rar = $row['rarity']        ?? '';

			if ( isset( $categories[ $cat ] ) ) {
				$categories[ $cat ]++;
			}
			if ( isset( $rarities[ $rar ] ) ) {
				$rarities[ $rar ]++;
			}
			if ( ! empty( $row['is_active'] ) ) {
				$active_count++;
			} else {
				$inactive_count++;
			}
		}

		$result = [
			'categories'     => $categories,
			'rarities'       => $rarities,
			'active_count'   => $active_count,
			'inactive_count' => $inactive_count,
			'total'          => count( $rows ),
		];

		set_transient( $transient_key, $result, 5 * MINUTE_IN_SECONDS );

		return $result;
	}

	/* ------------------------------------------------------------------ */
	/*  AJAX: DASHBOARD                                                    */
	/* ------------------------------------------------------------------ */

	private function supa_all_counts(): ?array {
		if ( ! function_exists( 'tw_supabase_rpc' ) ) {
			return null;
		}
		$result = tw_supabase_rpc( 'nw_dashboard_counts', [] );
		if ( is_array( $result ) && isset( $result['characters'] ) ) {
			return $result;
		}
		return null;
	}

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

		$rpc = $this->supa_all_counts();

		if ( $rpc ) {
			$counts = [
				'characters' => (int) ( $rpc['characters'] ?? 0 ),
				'worlds'     => (int) ( $rpc['worlds']     ?? 0 ),
				'campaigns'  => (int) ( $rpc['campaigns']  ?? 0 ),
				'deck_cards' => (int) ( $rpc['deck_cards'] ?? 0 ),
			];
			$recent = [
				'characters_7d' => (int) ( $rpc['chars_7d']  ?? 0 ),
				'worlds_7d'     => (int) ( $rpc['worlds_7d'] ?? 0 ),
				'campaigns_7d'  => (int) ( $rpc['camps_7d']  ?? 0 ),
				'deck_cards_7d' => (int) ( $rpc['deck_7d']   ?? 0 ),
			];
		} else {
			$counts = [
				'characters' => $this->supa_count( 'cyber_characters' ),
				'worlds'     => $this->supa_count( 'cyber_worlds' ),
				'campaigns'  => $this->supa_count( 'cyber_campaign' ),
				'deck_cards' => $this->supa_count( 'cyber_deck' ),
			];
			$recent = [
				'characters_7d' => $this->supa_recent_count( 'cyber_characters', 7 ),
				'worlds_7d'     => $this->supa_recent_count( 'cyber_worlds', 7 ),
				'campaigns_7d'  => $this->supa_recent_count( 'cyber_campaign', 7 ),
				'deck_cards_7d' => $this->supa_recent_count( 'cyber_deck', 7 ),
			];
		}

		$growth_raw = [
			'characters' => $this->supa_growth_series( 'cyber_characters', 30 ),
			'worlds'     => $this->supa_growth_series( 'cyber_worlds', 30 ),
			'campaigns'  => $this->supa_growth_series( 'cyber_campaign', 30 ),
			'deck_cards' => $this->supa_growth_series( 'cyber_deck', 30 ),
		];

		$growth = [
			'characters' => $growth_raw['characters']['series'],
			'worlds'     => $growth_raw['worlds']['series'],
			'campaigns'  => $growth_raw['campaigns']['series'],
			'deck_cards' => $growth_raw['deck_cards']['series'],
		];

		$health = [
			'worlds_without_campaigns'    => $this->supa_worlds_without_campaigns(),
			'campaigns_without_character' => $this->supa_campaigns_without_character(),
		];

		$deck_breakdown = $this->supa_deck_breakdown();

		$logs       = $this->supa_recent_logs( 10 );
		$logs_table = self::DEBUG_LOGS_TABLE;

		$alerts = [];

		if ( $recent['characters_7d'] === 0 ) {
			$alerts[] = [ 'level' => 'warn', 'label' => 'Characters', 'text' => 'No new characters in the last 7 days.' ];
		}
		if ( $recent['worlds_7d'] === 0 ) {
			$alerts[] = [ 'level' => 'warn', 'label' => 'Worlds', 'text' => 'No new worlds in the last 7 days.' ];
		}
		if ( $recent['campaigns_7d'] === 0 ) {
			$alerts[] = [ 'level' => 'warn', 'label' => 'Campaigns', 'text' => 'No new campaigns in the last 7 days.' ];
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

		wp_send_json_success( [
			'counts'         => $counts,
			'recent'         => $recent,
			'growth'         => $growth,
			'health'         => $health,
			'deck_breakdown' => $deck_breakdown,
			'alerts'         => $alerts,
			'logs'           => $logs,
			'_debug'         => [
				'key_type'    => $this->get_supa_key_type(),
				'rpc_used'    => ! is_null( $rpc ),
				'logs_table'  => $logs_table,
				'growth_meta' => [
					'characters' => [
						'rows_found'  => $growth_raw['characters']['rows_found'],
						'query_ok'    => $growth_raw['characters']['query_ok'],
						'http_status' => $growth_raw['characters']['http_status'],
						'api_error'   => $growth_raw['characters']['api_error'],
					],
					'worlds' => [
						'rows_found'  => $growth_raw['worlds']['rows_found'],
						'query_ok'    => $growth_raw['worlds']['query_ok'],
						'http_status' => $growth_raw['worlds']['http_status'],
						'api_error'   => $growth_raw['worlds']['api_error'],
					],
					'campaigns' => [
						'rows_found'  => $growth_raw['campaigns']['rows_found'],
						'query_ok'    => $growth_raw['campaigns']['query_ok'],
						'http_status' => $growth_raw['campaigns']['http_status'],
						'api_error'   => $growth_raw['campaigns']['api_error'],
					],
					'deck_cards' => [
						'rows_found'  => $growth_raw['deck_cards']['rows_found'],
						'query_ok'    => $growth_raw['deck_cards']['query_ok'],
						'http_status' => $growth_raw['deck_cards']['http_status'],
						'api_error'   => $growth_raw['deck_cards']['api_error'],
					],
				],
			],
		] );
	}

	/* ------------------------------------------------------------------ */
	/*  RENDER                                                             */
	/* ------------------------------------------------------------------ */

	public function render_page(): void {
		$supa_url = $this->get_supa_url();
		$key_ok   = (bool) $this->get_supa_key();
		$version  = defined( 'NEOWEAVER_VERSION' ) ? NEOWEAVER_VERSION
				  : ( defined( 'NW_VERSION' )      ? NW_VERSION : '—' );

		$allowed_html = [
			'span' => [ 'class' => [] ],
		];
		?>
		<div class="wrap nw-dash" id="nw-dashboard">

			<div class="nw-dash-header">
				<div class="nw-dash-logo">
					<?php echo wp_kses_post( $this->logo_svg( 44, '#adff00' ) ); ?>
					<div>
						<span class="nw-logo-name"><span class="nw-accent">Neo</span>Weaver</span>
						<span class="nw-logo-version">v<?php echo esc_html( $version ); ?> &mdash; Game Ops Dashboard</span>
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
						<div class="nw-stat-card">
							<div class="nw-stat-label-top">Deck Cards</div>
							<div class="nw-stat-value" id="nw-stat-deck-cards"><div class="nw-spinner"></div></div>
							<div class="nw-stat-sub" id="nw-recent-deck-cards">Last 7d: &mdash;</div>
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
						<div class="nw-chart-card">
							<div class="nw-chart-title">Deck Cards</div>
							<div class="nw-chart" id="nw-chart-deck-cards"></div>
						</div>
					</div>
				</section>

				<section class="nw-block">
					<div class="nw-block-head">
						<h2 class="nw-section-title">Deck Library</h2>
						<span class="nw-section-kicker">cyber_deck breakdown</span>
					</div>
					<div class="nw-deck-grid">
						<div class="nw-deck-col">
							<div class="nw-deck-col-title">By Category</div>
							<div class="nw-deck-bars" id="nw-deck-categories">
								<div class="nw-spinner" style="margin:20px auto;display:block;"></div>
							</div>
						</div>
						<div class="nw-deck-col">
							<div class="nw-deck-col-title">By Rarity</div>
							<div class="nw-deck-bars" id="nw-deck-rarities">
								<div class="nw-spinner" style="margin:20px auto;display:block;"></div>
							</div>
						</div>
						<div class="nw-deck-col nw-deck-col-sm">
							<div class="nw-deck-col-title">Status</div>
							<div id="nw-deck-status">
								<div class="nw-deck-status-row">
									<span class="nw-deck-status-dot nw-deck-dot-active"></span>
									<span class="nw-deck-status-label">Active</span>
									<span class="nw-deck-status-val" id="nw-deck-active">&mdash;</span>
								</div>
								<div class="nw-deck-status-row">
									<span class="nw-deck-status-dot nw-deck-dot-inactive"></span>
									<span class="nw-deck-status-label">Inactive</span>
									<span class="nw-deck-status-val" id="nw-deck-inactive">&mdash;</span>
								</div>
							</div>
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
	/*  SVG LOGO                                                           */
	/* ------------------------------------------------------------------ */

	private function logo_svg( int $size = 20, string $color = '#ffffff' ): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 40 40" fill="none" aria-label="NeoWeaver">'
			. '<polygon points="20,2 36,11 36,29 20,38 4,29 4,11" stroke="' . esc_attr( $color ) . '" stroke-width="2.5" fill="none"/>'
			. '<polyline points="11,27 11,13 20,24 29,13 29,27" stroke="' . esc_attr( $color ) . '" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" fill="none"/>'
			. '</svg>';
	}

}

/*
 * Instantiate after plugins_loaded (priority 10) so that:
 * - NEOWEAVER_VERSION / NW_VERSION are already defined by the main plugin file
 * - supabase-helpers functions (tw_supabase_*) are available
 * - is_admin() is reliable
 *
 * Priority 10 (default) — submenu files that call add_submenu_page() should
 * use priority >= 20 so the parent menu slug already exists when they run.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		new NeoWeaver_Admin();
	},
	10
);
