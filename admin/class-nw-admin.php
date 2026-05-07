<?php
/**
 * NeoWeaver Admin — Main Menu & Dashboard
 *
 * Loaded FIRST (explicitly, before glob) so the top-level "neoweaver"
 * menu slug exists when all submenu files run add_submenu_page().
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Admin {

	private string $slug = 'neoweaver';

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu'        ] );
		add_action( 'admin_menu',            [ $this, 'rename_first_submenu' ], 999 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'       ] );
		add_action( 'wp_ajax_nw_dashboard_stats', [ $this, 'ajax_stats' ] );
	}

	/* ------------------------------------------------------------------ */
	/*  MENU                                                                */
	/* ------------------------------------------------------------------ */

	public function register_menu(): void {
		add_menu_page(
			'NeoWeaver',
			'⚡ NeoWeaver',
			'manage_options',
			$this->slug,
			[ $this, 'render_page' ],
			'data:image/svg+xml;base64,' . base64_encode( $this->logo_svg() ),
			30
		);
		// WordPress auto-creates a first submenu mirroring the parent.
		// We rename it in rename_first_submenu() — do NOT call
		// add_submenu_page() with the same slug here (causes duplicates).
	}

	public function rename_first_submenu(): void {
		global $submenu;
		if ( isset( $submenu[ $this->slug ][0][0] ) ) {
			$submenu[ $this->slug ][0][0] = '📊 Dashboard'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}
	}

	/* ------------------------------------------------------------------ */
	/*  ASSETS                                                              */
	/* ------------------------------------------------------------------ */

	public function enqueue_assets( string $hook ): void {
		$is_dashboard = ( $hook === 'toplevel_page_' . $this->slug );
		$is_any_nw    = $is_dashboard || str_contains( $hook, 'neoweaver' );
		if ( ! $is_any_nw ) return;

		wp_enqueue_style( 'chakra-petch',
			'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
			[], null );

		if ( $is_dashboard ) {
			wp_add_inline_style( 'chakra-petch', $this->get_css() );
			wp_add_inline_script( 'jquery', $this->get_js() );
		}
	}

	/* ------------------------------------------------------------------ */
	/*  AJAX: STATS                                                         */
	/* ------------------------------------------------------------------ */

	public function ajax_stats(): void {
		check_ajax_referer( 'neoweaver_dashboard', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

		$supa_url = function_exists( 'tw_supabase_url' )         ? tw_supabase_url()         : '';
		$supa_key = function_exists( 'tw_supabase_service_key' ) ? tw_supabase_service_key() :
		          ( function_exists( 'tw_supabase_anon_key' )    ? tw_supabase_anon_key()    : '' );

		if ( ! $supa_url || ! $supa_key ) {
			wp_send_json_error( 'Supabase not configured.' );
		}

		// table name => stat key
		$tables = [
			'worlds'    => 'cyber_worlds',
			'agents'    => 'cyber_characters',
			'campaigns' => 'cyber_campaigns',
		];

		$counts = [];
		foreach ( $tables as $stat_key => $table ) {
			$cached = get_transient( 'nw_count_' . $stat_key );
			if ( $cached !== false ) {
				$counts[ $stat_key ] = (int) $cached;
				continue;
			}
			$res = wp_remote_get(
				rtrim( $supa_url, '/' ) . '/rest/v1/' . $table . '?select=id',
				[
					'timeout' => 8,
					'headers' => [
						'apikey'        => $supa_key,
						'Authorization' => 'Bearer ' . $supa_key,
						'Range'         => '0-0',
						'Prefer'        => 'count=exact',
					],
				]
			);
			$count = 0;
			if ( ! is_wp_error( $res ) ) {
				$cr = wp_remote_retrieve_header( $res, 'content-range' );
				if ( $cr && preg_match( '/\/(\d+)$/', $cr, $m ) ) {
					$count = (int) $m[1];
				}
			}
			set_transient( 'nw_count_' . $stat_key, $count, 5 * MINUTE_IN_SECONDS );
			$counts[ $stat_key ] = $count;
		}

		wp_send_json_success( $counts );
	}

	/* ------------------------------------------------------------------ */
	/*  RENDER                                                              */
	/* ------------------------------------------------------------------ */

	public function render_page(): void {
		$supa_url = function_exists( 'tw_supabase_url' )         ? tw_supabase_url()         : '';
		$key_ok   = function_exists( 'tw_supabase_service_key' ) ? (bool) tw_supabase_service_key() :
		          ( function_exists( 'tw_supabase_anon_key' )    ? (bool) tw_supabase_anon_key()    : false );

		$sub_panels = [
			[ 'slug' => 'neoweaver-abilities',   'icon' => '✨', 'label' => 'Abilities',    'desc' => 'Cyberabilities — tags, costs, AI modifiers.',        'color' => '#adff00' ],
			[ 'slug' => 'neoweaver-achievements', 'icon' => '🏆', 'label' => 'Achievements', 'desc' => 'In-game achievements and rewards.',                  'color' => '#e8af34' ],
			[ 'slug' => 'neoweaver-classes',      'icon' => '⚔️', 'label' => 'Classes',      'desc' => 'Field Agent classes — bonuses, packages.',           'color' => '#00d4ff' ],
			[ 'slug' => 'neoweaver-items',        'icon' => '🎒', 'label' => 'Items',        'desc' => 'Equipment, weapons, consumables, cyber-implants.',   'color' => '#ff6b35' ],
			[ 'slug' => 'neoweaver-races',        'icon' => '🧬', 'label' => 'Races',        'desc' => 'Playable races — attributes, tags, starting traits.', 'color' => '#b96eff' ],
		];
		?>
		<div class="wrap nw-dash" id="nw-dashboard">

			<div class="nw-dash-header">
				<div class="nw-dash-logo">
					<?php echo $this->logo_svg( 44, '#adff00' ); ?>
					<div>
						<span class="nw-logo-name"><span class="nw-accent">Neo</span>Weaver</span>
						<span class="nw-logo-version">v<?php echo esc_html( NEOWEAVER_VERSION ); ?> &mdash; Admin Console</span>
					</div>
				</div>
				<button class="nw-btn nw-btn-ghost" id="nw-refresh-stats">↻ Refresh Stats</button>
			</div>

			<div class="nw-stat-grid">
				<div class="nw-stat-card">
					<div class="nw-stat-icon">🌐</div>
					<div class="nw-stat-body">
						<div class="nw-stat-value" id="nw-stat-worlds"><div class="nw-spinner"></div></div>
						<div class="nw-stat-label">Worlds / Nodes</div>
					</div>
				</div>
				<div class="nw-stat-card">
					<div class="nw-stat-icon">🧬</div>
					<div class="nw-stat-body">
						<div class="nw-stat-value" id="nw-stat-agents"><div class="nw-spinner"></div></div>
						<div class="nw-stat-label">Field Agents</div>
					</div>
				</div>
				<div class="nw-stat-card">
					<div class="nw-stat-icon">⚔️</div>
					<div class="nw-stat-body">
						<div class="nw-stat-value" id="nw-stat-campaigns"><div class="nw-spinner"></div></div>
						<div class="nw-stat-label">Deployments</div>
					</div>
				</div>
			</div>

			<h2 class="nw-section-title">Game Data Panels</h2>
			<div class="nw-panels-grid">
				<?php foreach ( $sub_panels as $p ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $p['slug'] ) ); ?>"
				   class="nw-panel-card"
				   style="--card-color:<?php echo esc_attr( $p['color'] ); ?>">
					<div class="nw-panel-card-icon"><?php echo $p['icon']; ?></div>
					<div class="nw-panel-card-body">
						<div class="nw-panel-card-title"><?php echo esc_html( $p['label'] ); ?></div>
						<div class="nw-panel-card-desc"><?php echo esc_html( $p['desc'] ); ?></div>
					</div>
					<div class="nw-panel-card-arrow">→</div>
				</a>
				<?php endforeach; ?>
			</div>

			<div class="nw-sysinfo">
				<div class="nw-sysinfo-row">
					<span class="nw-sysinfo-label">Plugin version</span>
					<span class="nw-sysinfo-val"><?php echo esc_html( NEOWEAVER_VERSION ); ?></span>
				</div>
				<div class="nw-sysinfo-row">
					<span class="nw-sysinfo-label">Supabase URL</span>
					<span class="nw-sysinfo-val"><?php echo $supa_url ? esc_html( $supa_url ) : '<span style="color:#ff4444">Not configured</span>'; ?></span>
				</div>
				<div class="nw-sysinfo-row">
					<span class="nw-sysinfo-label">Supabase Key</span>
					<span class="nw-sysinfo-val"><?php echo $key_ok ? '<span style="color:#adff00">✓ Configured</span>' : '<span style="color:#ff4444">✗ Missing</span>'; ?></span>
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

			<input type="hidden" id="nw-dash-nonce" value="<?php echo esc_attr( wp_create_nonce( 'neoweaver_dashboard' ) ); ?>">
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/*  SVG LOGO                                                            */
	/* ------------------------------------------------------------------ */

	private function logo_svg( int $size = 20, string $color = '#ffffff' ): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 40 40" fill="none" aria-label="NeoWeaver">'
			. '<polygon points="20,2 36,11 36,29 20,38 4,29 4,11" stroke="' . esc_attr( $color ) . '" stroke-width="2.5" fill="none"/>'
			. '<polyline points="11,27 11,13 20,24 29,13 29,27" stroke="' . esc_attr( $color ) . '" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" fill="none"/>'
			. '</svg>';
	}

	/* ------------------------------------------------------------------ */
	/*  CSS                                                                 */
	/* ------------------------------------------------------------------ */

	private function get_css(): string { return <<<'CSS'
.nw-dash{font-family:'Chakra Petch',monospace;color:#e0e0e0;max-width:1100px}.nw-dash *{box-sizing:border-box}
.nw-dash-header{display:flex;align-items:center;justify-content:space-between;padding:20px 0;border-bottom:1px solid #2a2a2a;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.nw-dash-logo{display:flex;align-items:center;gap:14px}
.nw-logo-name{display:block;font-size:26px;font-weight:700;color:#fff;line-height:1;font-family:'Chakra Petch',monospace}
.nw-accent{color:#adff00}
.nw-logo-version{display:block;font-size:11px;color:#555;margin-top:4px;letter-spacing:.5px}
.nw-btn{font-family:'Chakra Petch',monospace;font-size:12px;font-weight:600;padding:7px 16px;border-radius:5px;border:1px solid transparent;cursor:pointer;transition:all .15s;text-transform:uppercase;letter-spacing:.5px}
.nw-btn-ghost{background:transparent;color:#adff00;border-color:#2e2e2e}.nw-btn-ghost:hover{border-color:#adff00}
.nw-stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:32px}
@media(max-width:700px){.nw-stat-grid{grid-template-columns:1fr}}
.nw-stat-card{background:#111;border:1px solid #222;border-radius:10px;padding:20px 22px;display:flex;align-items:center;gap:18px;transition:border-color .2s}
.nw-stat-card:hover{border-color:#adff00}
.nw-stat-icon{font-size:28px;line-height:1}
.nw-stat-value{font-size:36px;font-weight:700;color:#adff00;font-variant-numeric:tabular-nums;min-height:44px;display:flex;align-items:center}
.nw-stat-label{font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#555;margin-top:2px}
.nw-spinner{display:inline-block;width:22px;height:22px;border:2px solid #333;border-top-color:#adff00;border-radius:50%;animation:nw-spin .6s linear infinite}
@keyframes nw-spin{to{transform:rotate(360deg)}}
.nw-section-title{font-family:'Chakra Petch',monospace;font-size:13px;text-transform:uppercase;letter-spacing:1px;color:#adff00;font-weight:700;margin:0 0 14px;padding-bottom:8px;border-bottom:1px solid #1e2e00}
.nw-panels-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;margin-bottom:32px}
.nw-panel-card{display:flex;align-items:center;gap:14px;background:#111;border:1px solid #222;border-radius:10px;padding:16px 20px;text-decoration:none;color:#e0e0e0;transition:border-color .18s,transform .18s,box-shadow .18s}
.nw-panel-card:hover{border-color:var(--card-color,#adff00);transform:translateY(-2px);box-shadow:0 6px 24px rgba(0,0,0,.4);color:#fff}
.nw-panel-card-icon{font-size:24px;line-height:1;flex-shrink:0}
.nw-panel-card-body{flex:1;min-width:0}
.nw-panel-card-title{font-size:14px;font-weight:700;color:#fff;font-family:'Chakra Petch',monospace}
.nw-panel-card-desc{font-size:11px;color:#555;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.nw-panel-card-arrow{color:var(--card-color,#adff00);font-size:18px;opacity:0;transition:opacity .18s,transform .18s;transform:translateX(-4px)}
.nw-panel-card:hover .nw-panel-card-arrow{opacity:1;transform:translateX(0)}
.nw-sysinfo{background:#111;border:1px solid #222;border-radius:10px;overflow:hidden;font-size:12px}
.nw-sysinfo-row{display:flex;align-items:center;padding:10px 18px;border-bottom:1px solid #1a1a1a}
.nw-sysinfo-row:last-child{border-bottom:none}
.nw-sysinfo-label{width:160px;flex-shrink:0;color:#555;text-transform:uppercase;letter-spacing:.5px;font-size:11px}
.nw-sysinfo-val{color:#aaa;font-family:monospace;font-size:12px}
CSS;
	}

	/* ------------------------------------------------------------------ */
	/*  JS                                                                  */
	/* ------------------------------------------------------------------ */

	private function get_js(): string { return <<<'JS'
jQuery(function($){
    function loadStats(){
        $('#nw-stat-worlds,#nw-stat-agents,#nw-stat-campaigns').html('<div class="nw-spinner"></div>');
        $.post(ajaxurl,{action:'nw_dashboard_stats',nonce:$('#nw-dash-nonce').val()},function(res){
            if(!res.success){
                $('#nw-stat-worlds,#nw-stat-agents,#nw-stat-campaigns').text('—');
                console.error('[NeoWeaver] ajax_stats error:',res.data);
                return;
            }
            var d=res.data;
            $('#nw-stat-worlds').text(d.worlds||0);
            $('#nw-stat-agents').text(d.agents||0);
            $('#nw-stat-campaigns').text(d.campaigns||0);
        }).fail(function(xhr){
            $('#nw-stat-worlds,#nw-stat-agents,#nw-stat-campaigns').text('err');
            console.error('[NeoWeaver] ajax_stats HTTP error:',xhr.status,xhr.responseText);
        });
    }
    $('#nw-refresh-stats').on('click',loadStats);
    loadStats();
});
JS;
	}
}

new NeoWeaver_Admin();
