<?php
/**
 * NeoWeaver Admin Panel — Achievements (cyber_achievements)
 *
 * Columns: id (text PK), title, description, icon_slug, bg_color,
 *          scope (account|character), goal, hidden_until_earned,
 *          category (system|exploration|social|progression|mission|loot|secret|null),
 *          is_active, created_at
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NeoWeaver_Achievements_Admin {

	private string $page_slug   = 'neoweaver-achievements';
	private string $parent_slug = 'neoweaver';

	/** Exact values from DB constraint */
	private const SCOPES = [ 'account', 'character' ];
	private const CATEGORIES = [ 'system', 'exploration', 'social', 'progression', 'mission', 'loot', 'secret' ];

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_nw_achievements_get_all', [ $this, 'ajax_get_all' ] );
		add_action( 'wp_ajax_nw_achievements_save',    [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_achievements_toggle',  [ $this, 'ajax_toggle' ] );
		add_action( 'wp_ajax_nw_achievements_delete',  [ $this, 'ajax_delete' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			$this->parent_slug,
			'NeoWeaver — Achievements',
			'🏆 Achievements',
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ],
			11
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, $this->page_slug ) ) {
			return;
		}

		if ( ! wp_style_is( 'chakra-petch', 'enqueued' ) ) {
			wp_enqueue_style(
				'chakra-petch',
				'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;600;700&display=swap',
				[],
				null
			);
		}

		wp_enqueue_style(
			'nw-admin-core',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/nw-admin-core.css',
			[ 'chakra-petch' ],
			NEOWEAVER_VERSION
		);

		wp_enqueue_style(
			'nw-achievements-style',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/achievements-admin.css',
			[ 'chakra-petch', 'nw-admin-core' ],
			NEOWEAVER_VERSION
		);

		wp_enqueue_script(
			'lucide',
			'https://cdn.jsdelivr.net/npm/lucide@0.468.0/dist/umd/lucide.min.js',
			[],
			'0.468.0',
			true
		);

		wp_enqueue_script(
			'nw-achievements-script',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/achievements-admin.js',
			[ 'jquery', 'lucide' ],
			NEOWEAVER_VERSION,
			true
		);

		wp_localize_script(
			'nw-achievements-script',
			'NWAch',
			[
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'neoweaver_achievements' ),
			]
		);
	}
