<?php
/**
 * Admin-area functionality.
 *
 * @package NeoWeaver_WP_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NeoWeaver_Admin
 */
class NeoWeaver_Admin {

	private string $plugin_slug;
	private string $version;

	public function __construct( string $plugin_slug, string $version ) {
		$this->plugin_slug = $plugin_slug;
		$this->version     = $version;
	}

	/** Enqueue admin stylesheets. */
	public function enqueue_styles(): void {
		wp_enqueue_style(
			$this->plugin_slug . '-admin',
			NEOWEAVER_PLUGIN_URL . 'assets/css/neoweaver-admin.css',
			[],
			$this->version
		);
	}

	/** Enqueue admin scripts. */
	public function enqueue_scripts(): void {
		wp_enqueue_script(
			$this->plugin_slug . '-admin',
			NEOWEAVER_PLUGIN_URL . 'assets/js/neoweaver-admin.js',
			[ 'jquery' ],
			$this->version,
			true
		);
	}

	/** Register the top-level admin menu page. */
	public function add_admin_menu(): void {
		add_menu_page(
			__( 'NeoWeaver', 'neoweaver-wp-core' ),
			__( 'NeoWeaver', 'neoweaver-wp-core' ),
			'manage_options',
			$this->plugin_slug,
			[ $this, 'render_main_page' ],
			'dashicons-superhero',
			80
		);
	}

	/** Render the main settings / dashboard page. */
	public function render_main_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'NeoWeaver WP Core', 'neoweaver-wp-core' ); ?></h1>
			<p><?php esc_html_e( 'Welcome to NeoWeaver. Configure your settings below.', 'neoweaver-wp-core' ); ?></p>
		</div>
		<?php
	}
}
add_action( 'admin_menu', function() {
    add_menu_page(
        'NeoWeaver',           // page title
        'NeoWeaver',           // menu title
        'manage_options',      // capability
        'neoweaver',           // menu slug — musi być dokładnie tym co parent_slug w podmenu
        '__return_null',       // callback — null bo przekieruje do pierwszego podmenu
        'dashicons-superhero', // ikona
        30                     // pozycja
    );
}, 9 ); // priorytet 9 — PRZED podmenu które mają priorytet 10 (domyślny)
