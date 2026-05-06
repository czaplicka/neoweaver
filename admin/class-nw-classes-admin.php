<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Admin {

    private string $plugin_slug;
    private string $version;

    public function __construct( string $plugin_slug, string $version ) {
        $this->plugin_slug = $plugin_slug;
        $this->version     = $version;

        add_action( 'admin_menu',            [ $this, 'add_admin_menu' ], 9 ); // priorytet 9 = przed podmenu
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_styles'  ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    public function enqueue_styles(): void {
        wp_enqueue_style(
            $this->plugin_slug . '-admin',
            NEOWEAVER_PLUGIN_URL . 'assets/css/neoweaver-admin.css',
            [], $this->version
        );
    }

    public function enqueue_scripts(): void {
        wp_enqueue_script(
            $this->plugin_slug . '-admin',
            NEOWEAVER_PLUGIN_URL . 'assets/js/neoweaver-admin.js',
            [ 'jquery' ], $this->version, true
        );
    }

    public function add_admin_menu(): void {
        add_menu_page(
            __( 'NeoWeaver', 'neoweaver' ),
            __( 'NeoWeaver', 'neoweaver' ),
            'manage_options',
            'neoweaver',           // ← ten sam slug co parent_slug we wszystkich podmenu
            [ $this, 'render_main_page' ],
            'dashicons-superhero',
            30
        );
    }

    public function render_main_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        ?>
        <div class="wrap">
            <h1>NeoWeaver WP Core</h1>
            <p>Welcome to NeoWeaver. Select a section from the menu.</p>
        </div>
        <?php
    }
}
