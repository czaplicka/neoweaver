<?php
/**
 * NeoWeaver Admin — Main Menu & Dashboard
 *
 * Registers the top-level "NeoWeaver" menu entry and renders the
 * dashboard page: live Supabase counters + quick-navigation cards
 * to every sub-panel.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Admin {

	private string $supabase_url;
	private string $supabase_key;
	private string $slug = 'neoweaver';

	public function __construct() {
		$this->supabase_url = defined( 'SUPABASE_URL' ) ? rtrim( SUPABASE_URL, '/' ) : '';
		$this->supabase_key = defined( 'SUPABASE_KEY' ) ? SUPABASE_KEY : '';

		add_action( 'admin_menu',            [ $this, 'register_menu'  ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
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

		// First submenu item mirrors the parent (Dashboard).
		add_submenu_page(
			$this->slug,
			'NeoWeaver — Dashboard',
			'📊 Dashboard',
			'manage_options',
			$this->slug,
			[ $this, 'render_page' ]
		);
	}

	/* ------------------------------------------------------------------ */
	/*  ASSETS                                                              */
	/* ------------------------------------------------------------------ */

	public function enqueue_assets( string $hook ): void {
		// Load on all NeoWeaver admin pages for shared base styles.
		if ( ! str_contains( $hook, $this->slug ) && ! str_contains( $hook, 'neoweaver' ) ) return;

		wp_enqueue_style( 'chakra-petch',
			'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap', [], null );

		// Dashboard-specific inline styles & script — only on the main page.
		if ( str_contains( $hook, 'toplevel_page_neoweaver' ) || $hook === 'toplevel_page_' . $this->slug ) {
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

		$tables = [
			'worlds'    => 'cyberworlds',
			'agents'    => 'cybercharacters',
			'campaigns' => 'cybercampaign',
		];

		$counts = [];
		foreach ( $tables as $key => $table ) {
			$cached = get_transient( 'nw_count_' . $key );
			if ( $cached !== false ) {
				$counts[ $key ] = (int) $cached;
				continue;
			}
			$res = wp_remote_get(
				$this->supabase_url . '/rest/v1/' . $table . '?select=id',
				[
					'timeout' => 8,
					'headers' => [
						'apikey'        => $this->supabase_key,
						'Authorization' => 'Bearer ' . $this->supabase_key,
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
			set_transient( 'nw_count_' . $key, $count, 5 * MINUTE_IN_SECONDS );
			$counts[ $key ] = $count;
		}

		wp_send_json_success( $counts );
	}

	/* ------------------------------------------------------------------ */
	/*  RENDER                                                              */
	/* ------------------------------------------------------------------ */

	public function render_page(): void {
		$sub_panels = [
			[
				'slug'  => 'neoweaver-abilities',
				'icon'  => '⚡',
				'label' => 'Abilities',
				'desc'  => 'Manage cyberabilities — tags, costs, AI modifiers.',
				'color' => '#adff00',
			],
			[
				'slug'  => 'neoweaver-achievements',
				'icon'  => '🏆',
				'label' => 'Achievements',
				'desc'  => 'Define in-game achievements and rewards.',
				'color' => '#e8af34',
			],
			[
				'slug'  => 'neoweaver-classes',
				'icon'  => '⚔️',
				'label' => 'Classes',
				'desc'  => 'Field Agent classes — bonuses, vulnerabilities, packages.',
				'color' => '#00d4ff',
			],
			[
				'slug'  => 'neoweaver-items',
				'icon'  => '🎒',
				'label' => 'Items',
				'desc'  => 'Equipment, weapons, consumables and cyber-implants.',
				'color' => '#ff6b35',
			],
			[
				'slug'  => 'neoweaver-races',
				'icon'  => '🧬',
				'label' => 'Races',
				'desc'  => 'Playable races — attributes, tags, starting traits.',
				'color' => '#b96eff',
			],
		];
		?>
		<div class="wrap nw-dash" id="nw-dashboard">

			<!-- ===== HEADER ===== -->
			<div class="nw-dash-header">
				<div class="nw-dash-logo">
					<?php echo $this->logo_svg( 48, '#adff00' ); ?>
					<div>
						<span class="nw-logo-name"><span class="nw-accent">Neo</span>Weaver</span>
						<span class="nw-logo-version">v<?php echo esc_html( NEOWEAVER_VERSION ); ?> &mdash; Admin Console</span>
					</div>
				</div>
				<button class="nw-btn nw-btn-ghost" id="nw-refresh-stats">↻ Refresh Stats</button>
			</div>

			<!-- ===== STATS ===== -->
			<div class="nw-stat-grid" id="nw-stat-grid">
				<div class="nw-stat-card" data-key="worlds">
					<div class="nw-stat-icon">🌐</div>
					<div class="nw-stat-body">
						<div class="nw-stat-value" id="nw-stat-worlds"><div class="nw-spinner"></div></div>
						<div class="nw-stat-label">Worlds / Nodes</div>
					</div>
				</div>
				<div class="nw-stat-card" data-key="agents">
					<div class="nw-stat-icon">🧬</div>
					<div class="nw-stat-body">
						<div class="nw-stat-value" id="nw-stat-agents"><div class="nw-spinner"></div></div>
						<div class="nw-stat-label">Field Agents</div>
					</div>
				</div>
				<div class="nw-stat-card" data-key="campaigns">
					<div class="nw-stat-icon">⚔️</div>
					<div class="nw-stat-body">
						<div class="nw-stat-value" id="nw-stat-campaigns"><div class="nw-spinner"></div></div>
						<div class="nw-stat-label">Deployments</div>
					</div>
				</div>
			</div>

			<!-- ===== QUICK NAV ===== -->
			<h2 class="nw-section-title">Game Data Panels</h2>
			<div class="nw-panels-grid">
				<?php foreach ( $sub_panels as $p ) :
					$url = admin_url( 'admin.php?page=' . $p['slug'] );
				?>
				<a href="<?php echo esc_url( $url ); ?>" class="nw-panel-card" style="--card-color:<?php echo esc_attr( $p['color'] ); ?>">
					<div class="nw-panel-card-icon"><?php echo $p['icon']; ?></div>
					<div class="nw-panel-card-body">
						<div class="nw-panel-card-title"><?php echo esc_html( $p['label'] ); ?></div>
						<div class="nw-panel-card-desc"><?php echo esc_html( $p['desc'] ); ?></div>
					</div>
					<div class="nw-panel-card-arrow">→</div>
				</a>
				<?php endforeach; ?>
			</div>

			<!-- ===== SYSTEM INFO ===== -->
			<div class="nw-sysinfo">
				<div class="nw-sysinfo-row">
					<span class="nw-sysinfo-label">Plugin version</span>
					<span class="nw-sysinfo-val"><?php echo esc_html( NEOWEAVER_VERSION ); ?></span>
				</div>
				<div class="nw-sysinfo-row">
					<span class="nw-sysinfo-label">Supabase URL</span>
					<span class="nw-sysinfo-val"><?php
						$u = $this->supabase_url;
						echo $u ? esc_html( $u ) : '<span style="color:#ff4444">Not configured</span>';
					?></span>
				</div>
				<div class="nw-sysinfo-row">
					<span class="nw-sysinfo-label">Supabase Key</span>
					<span class="nw-sysinfo-val"><?php
						echo $this->supabase_key
							? '<span style="color:#adff00">✓ Configured</span>'
							: '<span style="color:#ff4444">✗ Missing</span>';
					?></span>
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
		// Stylised "NW" hexagon mark
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
/* header */
.nw-dash-header{display:flex;align-items:center;justify-content:space-between;padding:20px 0 20px;border-bottom:1px solid #2a2a2a;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.nw-dash-logo{display:flex;align-items:center;gap:14px}
.nw-logo-name{display:block;font-size:26px;font-weight:700;color:#fff;line-height:1;font-family:'Chakra Petch',monospace}
.nw-accent{color:#adff00}
.nw-logo-version{display:block;font-size:11px;color:#555;margin-top:4px;letter-spacing:.5px}
/* buttons */
.nw-btn{font-family:'Chakra Petch',monospace;font-size:12px;font-weight:600;padding:7px 16px;border-radius:5px;border:1px solid transparent;cursor:pointer;transition:all .15s;text-transform:uppercase;letter-spacing:.5px}
.nw-btn-ghost{background:transparent;color:#adff00;border-color:#2e2e2e}.nw-btn-ghost:hover{border-color:#adff00}
/* stat cards */
.nw-stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:32px}
@media(max-width:700px){.nw-stat-grid{grid-template-columns:1fr}}
.nw-stat-card{background:#111;border:1px solid #222;border-radius:10px;padding:20px 22px;display:flex;align-items:center;gap:18px;transition:border-color .2s}
.nw-stat-card:hover{border-color:#adff00}
.nw-stat-icon{font-size:28px;line-height:1}
.nw-stat-value{font-size:36px;font-weight:700;color:#adff00;font-variant-numeric:tabular-nums;min-height:44px;display:flex;align-items:center}
.nw-stat-label{font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:#555;margin-top:2px}
/* spinner */
.nw-spinner{display:inline-block;width:22px;height:22px;border:2px solid #333;border-top-color:#adff00;border-radius:50%;animation:nw-spin .6s linear infinite}
@keyframes nw-spin{to{transform:rotate(360deg)}}
/* section title */
.nw-section-title{font-family:'Chakra Petch',monospace;font-size:13px;text-transform:uppercase;letter-spacing:1px;color:#adff00;font-weight:700;margin:0 0 14px;padding-bottom:8px;border-bottom:1px solid #1e2e00}
/* panels grid */
.nw-panels-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;margin-bottom:32px}
.nw-panel-card{display:flex;align-items:center;gap:14px;background:#111;border:1px solid #222;border-radius:10px;padding:16px 20px;text-decoration:none;color:#e0e0e0;transition:border-color .18s,transform .18s,box-shadow .18s;cursor:pointer}
.nw-panel-card:hover{border-color:var(--card-color,#adff00);transform:translateY(-2px);box-shadow:0 6px 24px rgba(0,0,0,.4);color:#fff}
.nw-panel-card-icon{font-size:24px;line-height:1;flex-shrink:0}
.nw-panel-card-body{flex:1;min-width:0}
.nw-panel-card-title{font-size:14px;font-weight:700;color:#fff;font-family:'Chakra Petch',monospace}
.nw-panel-card-desc{font-size:11px;color:#555;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.nw-panel-card-arrow{color:var(--card-color,#adff00);font-size:18px;opacity:0;transition:opacity .18s,transform .18s;transform:translateX(-4px)}
.nw-panel-card:hover .nw-panel-card-arrow{opacity:1;transform:translateX(0)}
/* sysinfo */
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
            if(!res.success)return;
            var d=res.data;
            $('#nw-stat-worlds').text(d.worlds||0);
            $('#nw-stat-agents').text(d.agents||0);
            $('#nw-stat-campaigns').text(d.campaigns||0);
        });
    }
    $('#nw-refresh-stats').on('click',loadStats);
    loadStats();
});
JS;
	}
}

new NeoWeaver_Admin();
